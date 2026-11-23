#!/usr/bin/env python3
"""CI-only ZIP byte stability, member type, and refusal regression for G004."""
from __future__ import annotations

import hashlib
import io
import os
import stat
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from v2_build_setup_zip import SetupBuildError, _copy_exact_member, build_setup_zip  # noqa: E402

SOURCE_DATE_EPOCH = 1731758400


class DeterministicSetupZipTest(unittest.TestCase):
    def test_secret_bearing_env_templates_rejected_before_zip_creation(self) -> None:
        # Rejection must happen before mkstemp or public ZIP publication; the
        # verifier cannot unpublish bytes that were already staged.
        cases = (
            ("backend/.env.production.example", "APP_KEY=base64:secret\\n"),
            ("backend/.env.example", "DB_PASSWORD='private-secret'\\n"),
            ("backend/.env.production.example", "APP_KEY=\\nAPP_KEY=base64:second-secret\\n"),
            ("backend/.env.production.example", "REDIS_PASSWORD=\"secret\"\\n"),
        )
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            for index, (member, payload) in enumerate(cases):
                with self.subTest(member=member, case=index):
                    root = base / f"source-{index}"
                    env_file = root / member
                    env_file.parent.mkdir(parents=True)
                    (root / "VERSION").write_text("1.0.1\\n")
                    env_file.write_text(payload, encoding="utf-8")
                    output = base / f"setup-{index}.zip"

                    def opened_regular_member(path, meta, name, source_root):
                        return path.open("rb")

                    with mock.patch("v2_build_setup_zip._open_verified_member", side_effect=opened_regular_member):
                        with mock.patch("v2_build_setup_zip.tempfile.mkstemp", side_effect=AssertionError("ZIP temp created before env rejection")):
                            with self.assertRaisesRegex(SetupBuildError, "non-empty secret fields"):
                                build_setup_zip(root, output, SOURCE_DATE_EPOCH)
                    self.assertFalse(output.exists())
                    self.assertEqual(list(base.glob(".v2-setup-*.zip")), [])

    def test_empty_env_secrets_and_redis_null_are_allowed_in_setup_staging(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            root = base / "source"
            env = root / "backend/.env.production.example"
            env.parent.mkdir(parents=True)
            (root / "VERSION").write_text("1.0.1\\n")
            env.write_text("APP_KEY=\\nDB_PASSWORD=\\nREDIS_PASSWORD=null\\nMAIL_PASSWORD=\"\"\\n")
            output = base / "setup.zip"

            def opened_regular_member(path, meta, name, source_root):
                return path.open("rb")

            with mock.patch("v2_build_setup_zip._open_verified_member", side_effect=opened_regular_member):
                digest = build_setup_zip(root, output, SOURCE_DATE_EPOCH)
            self.assertEqual(len(digest), 64)
            with zipfile.ZipFile(output) as archive:
                self.assertEqual(archive.read("backend/.env.production.example"), env.read_bytes())

    def test_exact_source_copy_rejects_growth_and_truncation_without_overreading(self) -> None:
        for payload, expected_size, error in (
            (b"abcd", 4, None),
            (b"abc", 4, "shrank during packaging"),
            (b"abcdef", 4, "grew during packaging"),
            (b"", 0, None),
            (b"extra", 0, "grew during packaging"),
        ):
            with self.subTest(size=len(payload), expected=expected_size):
                source = io.BytesIO(payload)
                destination = io.BytesIO()
                if error:
                    with self.assertRaisesRegex(SetupBuildError, error):
                        _copy_exact_member(source, destination, expected_size, "VERSION")
                    self.assertLessEqual(source.tell(), expected_size + 1)
                else:
                    _copy_exact_member(source, destination, expected_size, "VERSION")
                    self.assertEqual(destination.getvalue(), payload)
                    self.assertEqual(source.tell(), len(payload))

    def test_same_payload_has_identical_bytes_across_mtime_and_creation_order(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            first, second = base / "first", base / "second"
            files = {
                "VERSION": b"1.0.1\n",
                "backend/public/index.php": b"<?php echo 'ready';\n",
                "backend/vendor/autoload.php": b"<?php // production deps\n",
                "backend/public/assets/admin/test.css": b"body{display:block}\n",
            }
            for root, order in ((first, list(files)), (second, list(reversed(list(files))))):
                root.mkdir()
                (root / "backend/storage/framework/cache/data").mkdir(parents=True)
                for name in order:
                    path = root / name
                    path.parent.mkdir(parents=True, exist_ok=True)
                    path.write_bytes(files[name])
                    os.utime(path, (1700000000 if root == first else 1750000000,) * 2)
                (root / "backend/public/index.php").chmod(0o755)
            one, two = base / "one.zip", base / "two.zip"
            self.assertEqual(
                build_setup_zip(first, one, SOURCE_DATE_EPOCH),
                build_setup_zip(second, two, SOURCE_DATE_EPOCH),
            )
            self.assertEqual(one.read_bytes(), two.read_bytes())
            with zipfile.ZipFile(one) as archive:
                self.assertIsNone(archive.testzip())
                members = archive.infolist()
                self.assertEqual([m.filename for m in members], sorted(m.filename for m in members))
                self.assertIn("backend/storage/framework/cache/data/", archive.namelist())
                self.assertEqual(archive.read("backend/public/index.php"), files["backend/public/index.php"])
                for info in members:
                    self.assertEqual(info.date_time, (2024, 11, 16, 12, 0, 0))
                    self.assertFalse(stat.S_ISLNK(info.external_attr >> 16))
                self.assertEqual(
                    (archive.getinfo("backend/public/index.php").external_attr >> 16) & 0o777,
                    0o755,
                )

    def test_existing_output_and_output_under_source_are_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "source"
            root.mkdir()
            (root / "VERSION").write_text("1.0.1\n", encoding="utf-8")
            existing = Path(temporary) / "already.zip"
            existing.write_bytes(b"preserve-me")
            with self.assertRaisesRegex(SetupBuildError, "existing Setup ZIP"):
                build_setup_zip(root, existing, SOURCE_DATE_EPOCH)
            self.assertEqual(existing.read_bytes(), b"preserve-me")
            with self.assertRaisesRegex(SetupBuildError, "inside its staging root"):
                build_setup_zip(root, root / "embedded.zip", SOURCE_DATE_EPOCH)

    def test_destination_created_after_preflight_is_never_overwritten(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            root = base / "source"
            root.mkdir()
            (root / "VERSION").write_bytes(b"1.0.1\\n")
            output = base / "setup.zip"
            original_link = os.link

            def competing_publisher(source, destination):
                # Simulate another publisher winning the race between the
                # initial output.exists() check and final ZIP publication.
                output.write_bytes(b"other-worker-zip")
                return original_link(source, destination)

            with mock.patch("v2_build_setup_zip.os.link", side_effect=competing_publisher):
                with self.assertRaisesRegex(SetupBuildError, "existing Setup ZIP"):
                    build_setup_zip(root, output, SOURCE_DATE_EPOCH)
            self.assertEqual(output.read_bytes(), b"other-worker-zip")
            self.assertEqual(list(base.glob(".v2-setup-*.zip")), [])

    def test_destination_symlink_created_after_preflight_is_never_followed(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            root = base / "source"
            root.mkdir()
            (root / "VERSION").write_bytes(b"1.0.1\\n")
            outside = base / "outside"
            outside.write_bytes(b"preserve-secret")
            output = base / "setup.zip"
            original_link = os.link

            def competing_symlink(source, destination):
                try:
                    output.symlink_to(outside)
                except (OSError, NotImplementedError) as exc:
                    self.skipTest(f"Symlinks unavailable: {exc}")
                return original_link(source, destination)

            with mock.patch("v2_build_setup_zip.os.link", side_effect=competing_symlink):
                with self.assertRaisesRegex(SetupBuildError, "existing Setup ZIP"):
                    build_setup_zip(root, output, SOURCE_DATE_EPOCH)
            self.assertTrue(output.is_symlink())
            self.assertEqual(outside.read_bytes(), b"preserve-secret")
            self.assertEqual(list(base.glob(".v2-setup-*.zip")), [])

    def test_posthash_same_inode_mutation_cannot_attest_published_zip(self) -> None:
        # Even a mutation that restores the original mtime and size cannot
        # pass as the SHA-256 of the original staged archive. os.link updates
        # ctime, which makes a pre/post ctime-only test insufficient.
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            root = base / "source"
            root.mkdir()
            (root / "VERSION").write_bytes(b"1.0.1\\n")
            output = base / "setup.zip"
            original_link = os.link

            def mutate_original_inode(source, destination):
                initial = os.stat(source)
                with open(source, "r+b") as target:
                    target.seek(0)
                    first = target.read(1)
                    target.seek(0)
                    target.write(b"X" if first != b"X" else b"Y")
                os.utime(source, ns=(initial.st_atime_ns, initial.st_mtime_ns))
                return original_link(source, destination)

            # Test the publication integrity invariant on Windows too.
            # The unrelated POSIX directory traversal has its own regression.
            def opened_regular_member(path, meta, name, source_root):
                return path.open("rb")

            with mock.patch("v2_build_setup_zip._open_verified_member", side_effect=opened_regular_member):
                with mock.patch("v2_build_setup_zip.os.link", side_effect=mutate_original_inode):
                    with self.assertRaisesRegex(SetupBuildError, "contents changed after checksum"):
                        build_setup_zip(root, output, SOURCE_DATE_EPOCH)
            # The builder published the original archive inode but then
            # rejected its changed contents. No unverified ZIP may remain.
            self.assertFalse(output.exists())
            self.assertEqual(list(base.glob(".v2-setup-*.zip")), [])

    def test_public_output_swap_during_final_digest_does_not_attest_rival(self) -> None:
        # The first published-path identity check passes, but an attacker
        # replaces output while we digest the still-pinned original inode.
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            source = base / "source"
            source.mkdir()
            (source / "VERSION").write_bytes(b"1.0.1\\n")
            output = base / "setup.zip"
            rival = base / "rival"
            rival.write_bytes(b"rival-payload")
            actual_sha256 = hashlib.sha256
            hash_calls = 0

            class Digest:
                def __init__(self, replace_on_digest: bool):
                    self._hash = actual_sha256()
                    self._replace = replace_on_digest

                def update(self, data):
                    self._hash.update(data)

                def digest(self):
                    if self._replace:
                        os.replace(rival, output)
                        self._replace = False
                    return self._hash.digest()

                def hexdigest(self):
                    return self._hash.hexdigest()

            def new_digest():
                nonlocal hash_calls
                hash_calls += 1
                return Digest(replace_on_digest=hash_calls == 2)

            def opened_regular_member(path, meta, name, source_root):
                return path.open("rb")

            with mock.patch("v2_build_setup_zip._open_verified_member", side_effect=opened_regular_member):
                with mock.patch("v2_build_setup_zip.hashlib.sha256", side_effect=new_digest):
                    with self.assertRaisesRegex(SetupBuildError, "path changed after checksum"):
                        build_setup_zip(source, output, SOURCE_DATE_EPOCH)
            self.assertEqual(output.read_bytes(), b"rival-payload")
            self.assertEqual(hash_calls, 2)
            self.assertEqual(list(base.glob(".v2-setup-*.zip")), [])

    def test_checksum_refuses_output_replaced_after_exclusive_publish(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            root = base / "source"
            root.mkdir()
            (root / "VERSION").write_bytes(b"1.0.1\\n")
            output = base / "setup.zip"
            rival = base / "rival.zip"
            rival.write_bytes(b"not-the-built-archive")
            original_link = os.link

            def replace_after_link(source, destination):
                original_link(source, destination)
                os.replace(rival, destination)

            # Only the POSIX-specific secure source opener is mocked. Test
            # final publication/replacement semantics on both platforms.
            def opened_regular_member(path, meta, name, source_root):
                return path.open("rb")

            with mock.patch("v2_build_setup_zip._open_verified_member", side_effect=opened_regular_member):
                with mock.patch("v2_build_setup_zip.os.link", side_effect=replace_after_link):
                    with self.assertRaisesRegex(SetupBuildError, "changed during checksum verification"):
                        build_setup_zip(root, output, SOURCE_DATE_EPOCH)
            # Cleanup must never delete the rival's replacement inode.
            self.assertEqual(output.read_bytes(), b"not-the-built-archive")
            self.assertEqual(list(base.glob(".v2-setup-*.zip")), [])

    def test_member_enumeration_stops_at_limit_before_reading_entire_tree(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            source = base / "source"
            source.mkdir()
            for name in ("a", "b", "c"):
                (source / name).write_bytes(b"small")
            output = base / "setup.zip"
            scanned = []

            def bounded_walk(_root, pattern):
                self.assertEqual(pattern, "*")
                for name in ("a", "b", "c"):
                    scanned.append(name)
                    yield source / name
                self.fail("Setup builder must stop enumeration at the limit")

            with mock.patch("v2_build_setup_zip.MAX_ENTRIES", 2), mock.patch.object(
                Path, "rglob", autospec=True, side_effect=bounded_walk,
            ):
                with self.assertRaisesRegex(SetupBuildError, "member count exceeds budget"):
                    build_setup_zip(source, output, SOURCE_DATE_EPOCH)
            self.assertEqual(scanned, ["a", "b", "c"])
            self.assertFalse(output.exists())
            self.assertEqual(list(base.glob(".v2-setup-*.zip")), [])

    def test_unsafe_stage_is_rejected_before_creating_temp_archive(self) -> None:
        # A dirty staging checkout must be rejected before any ZIP exists,
        # even when the invalid entry sorts after a legitimate file.
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            source = base / "source"
            source.mkdir()
            (source / "VERSION").write_bytes(b"1.0.1\\n")
            output = base / "setup.zip"
            for unsafe, reason in (
                (".env", "Secret environment configuration"),
                (".git/config", "Source control metadata"),
            ):
                with self.subTest(unsafe=unsafe):
                    candidate = source / unsafe
                    candidate.parent.mkdir(parents=True, exist_ok=True)
                    candidate.write_bytes(b"APP_KEY=private")
                    with mock.patch("v2_build_setup_zip.tempfile.mkstemp") as create:
                        with self.assertRaisesRegex(SetupBuildError, reason):
                            build_setup_zip(source, output, SOURCE_DATE_EPOCH)
                        create.assert_not_called()
                    self.assertFalse(output.exists())
                    candidate.unlink()
                    if candidate.parent != source:
                        candidate.parent.rmdir()
            # A hard-link must not be copied into a staging archive either.
            outside = base / "outside"
            outside.write_bytes(b"private")
            linked = source / "hardlinked"
            try:
                os.link(outside, linked)
            except OSError as exc:
                self.skipTest(f"Hardlinks unavailable: {exc}")
            with mock.patch("v2_build_setup_zip.tempfile.mkstemp") as create:
                with self.assertRaisesRegex(SetupBuildError, "Hard-linked"):
                    build_setup_zip(source, output, SOURCE_DATE_EPOCH)
                create.assert_not_called()
            self.assertFalse(output.exists())

    def test_nonportable_zip_names_fail_before_temp_archive_creation(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            source = base / "source"
            source.mkdir()
            output = base / "setup.zip"
            # Mock enumeration: Windows cannot create some invalid names,
            # but a Linux runner could otherwise package them for cPanel.
            for bad_name in ("CON", "COM1.txt", "trailing.", "bad:colon", "bad<name"):
                with self.subTest(name=bad_name), mock.patch.object(
                    Path, "rglob", autospec=True, return_value=iter([source / bad_name]),
                ), mock.patch("v2_build_setup_zip.tempfile.mkstemp") as create:
                    with self.assertRaisesRegex(SetupBuildError, "Unsafe cross-platform"):
                        build_setup_zip(source, output, SOURCE_DATE_EPOCH)
                    create.assert_not_called()
                self.assertFalse(output.exists())

    def test_runtime_uploads_and_private_cache_are_denied_before_temp_zip(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            source = base / "source"
            source.mkdir()
            (source / "VERSION").write_bytes(b"1.0.1\\n")
            output = base / "setup.zip"
            for unsafe in (
                "backend/storage/app/private/export.json",
                "backend/storage/framework/sessions/session-1",
                "backend/storage/exports/ledger.csv",
                "backend/bootstrap/cache/config.php",
                "backend/public/uploads/customer/photo.jpg",
                "backend/public/storage",
                "public/uploads",
                "backend/uploads/tenant/receipt.pdf",
            ):
                with self.subTest(path=unsafe):
                    target = source / unsafe
                    target.parent.mkdir(parents=True, exist_ok=True)
                    target.write_bytes(b"PRIVATE LIVE DATA")
                    with mock.patch("v2_build_setup_zip.tempfile.mkstemp") as create:
                        with self.assertRaisesRegex(SetupBuildError, "Runtime storage/cache|Public uploaded/runtime"):
                            build_setup_zip(source, output, SOURCE_DATE_EPOCH)
                        create.assert_not_called()
                    self.assertFalse(output.exists())
                    target.unlink()
                    for parent in target.parents:
                        if parent == source:
                            break
                        if any(parent.iterdir()):
                            break
                        parent.rmdir()

    def test_empty_laravel_runtime_directories_not_classified_as_private_files(self) -> None:
        # Preflight must preserve the directory-only structure Laravel needs.
        with tempfile.TemporaryDirectory() as temporary:
            source = Path(temporary) / "source"
            source.mkdir()
            for name in ("backend/storage/app/public", "backend/storage/framework/cache/data", "backend/bootstrap/cache"):
                (source / name).mkdir(parents=True)
            output = Path(temporary) / "setup.zip"
            # Secure POSIX directory-relative opens are unavailable on Windows.
            # Patch the file reader only to observe whether preflight accepts
            # safe empty directories; packaging itself remains POSIX-gated.
            with mock.patch("v2_build_setup_zip.tempfile.mkstemp", side_effect=RuntimeError("preflight reached archive creation")):
                with self.assertRaisesRegex(RuntimeError, "preflight reached archive creation"):
                    build_setup_zip(source, output, SOURCE_DATE_EPOCH)
            self.assertFalse(output.exists())

    def test_staging_member_count_and_expanded_size_budgets_fail_closed(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            source = base / "source"
            source.mkdir()
            (source / "VERSION").write_bytes(b"1234")
            (source / "README").write_bytes(b"5678")
            output = base / "setup.zip"
            for name, limit, message in (
                ("MAX_ENTRIES", 1, "member count exceeds budget"),
                ("MAX_ENTRY_SIZE", 3, "entry exceeds size budget"),
                ("MAX_TOTAL_SIZE", 7, "expanded size exceeds budget"),
            ):
                with self.subTest(limit=name), mock.patch("v2_build_setup_zip." + name, limit):
                    with self.assertRaisesRegex(SetupBuildError, message):
                        build_setup_zip(source, output, SOURCE_DATE_EPOCH)
                    self.assertFalse(output.exists(), "Over-budget source must not publish ZIP")
                    self.assertEqual(list(base.glob(".v2-setup-*.zip")), [])
    def test_staging_within_budgets_builds_valid_zip(self) -> None:
        # Positive packaging requires the secure POSIX dirfd walk. Budget
        # denials above remain independently testable on Windows.
        if not hasattr(os, "O_DIRECTORY") or not hasattr(os, "O_NOFOLLOW") or os.open not in os.supports_dir_fd:
            self.skipTest("Secure ZIP staging opens require POSIX dirfd support")
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            source = base / "source"
            source.mkdir()
            (source / "VERSION").write_bytes(b"1234")
            (source / "README").write_bytes(b"5678")
            output = base / "setup.zip"
            digest = build_setup_zip(source, output, SOURCE_DATE_EPOCH)
            self.assertEqual(len(digest), 64)
            with zipfile.ZipFile(output) as archive:
                self.assertEqual(archive.read("VERSION"), b"1234")
                self.assertEqual(archive.read("README"), b"5678")

    def test_live_dotenv_is_rejected_before_archive_publication(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            root = base / "source"
            root.mkdir()
            (root / "VERSION").write_bytes(b"1.0.1\\n")
            output = base / "setup.zip"
            # Match the canonical verifier's exact allowed example templates.
            examples = ["backend/.env.example", "backend/.env.production.example"]
            for name in examples:
                file = root / name
                file.parent.mkdir(parents=True, exist_ok=True)
                file.write_bytes(b"APP_KEY=\\n")
            for name in (".env", "backend/.env", "backend/.ENV.local", "backend/.env.testing"):
                file = root / name
                file.parent.mkdir(parents=True, exist_ok=True)
                file.write_bytes(b"APP_KEY=base64:live-secret\\n")
                with self.assertRaisesRegex(SetupBuildError, "Secret environment configuration"):
                    build_setup_zip(root, output, SOURCE_DATE_EPOCH)
                self.assertFalse(output.exists(), name)
                self.assertEqual(list(base.glob(".v2-setup-*.zip")), [], name)
                file.unlink()

            digest = build_setup_zip(root, output, SOURCE_DATE_EPOCH)
            self.assertEqual(len(digest), 64)
            with zipfile.ZipFile(output) as archive:
                self.assertIn("backend/.env.example", archive.namelist())
                self.assertIn("backend/.env.production.example", archive.namelist())

    def test_git_control_metadata_is_rejected_before_archive_publication(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "source"
            root.mkdir()
            (root / "VERSION").write_text("1.0.1\\n", encoding="utf-8")
            output = Path(temporary) / "setup.zip"
            for name in (".git/config", "backend/.GIT/packed-refs", "backend/nested/.git/config"):
                file = root / name
                file.parent.mkdir(parents=True, exist_ok=True)
                file.write_bytes(b"confidential-git-config")
                with self.assertRaisesRegex(SetupBuildError, "Source control metadata"):
                    build_setup_zip(root, output, SOURCE_DATE_EPOCH)
                self.assertFalse(output.exists())
                self.assertEqual(list(Path(temporary).glob(".v2-setup-*.zip")), [])
                file.unlink()
                for parent in file.parents:
                    if parent == root:
                        break
                    parent.rmdir()

    def test_symlink_and_hardlink_cannot_smuggle_data(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "source"
            root.mkdir()
            outside = Path(temporary) / "secret"
            outside.write_text("do-not-package", encoding="utf-8")
            linked = root / "linked"
            linked.symlink_to(outside)
            with self.assertRaisesRegex(SetupBuildError, "non-regular"):
                build_setup_zip(root, Path(temporary) / "output.zip", SOURCE_DATE_EPOCH)
            self.assertFalse((Path(temporary) / "output.zip").exists())
            linked.unlink()
            try:
                os.link(outside, linked)
            except OSError as exc:
                self.skipTest(f"Hardlinks unavailable: {exc}")
            with self.assertRaisesRegex(SetupBuildError, "Hard-linked"):
                build_setup_zip(root, Path(temporary) / "output.zip", SOURCE_DATE_EPOCH)

    def test_file_replacement_between_scan_and_open_never_publishes_zip(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            stage = base / "source"
            stage.mkdir()
            (stage / "VERSION").write_bytes(b"1.0.1\\n")
            replacement_file = base / "replacement"
            replacement_file.write_bytes(b"not-original")
            output = base / "setup.zip"

            from v2_build_setup_zip import _open_verified_member

            def replace_before_open(path, meta, name, source_root):
                if name == "VERSION":
                    os.replace(replacement_file, path)
                return _open_verified_member(path, meta, name, source_root)

            with mock.patch("v2_build_setup_zip._open_verified_member", side_effect=replace_before_open):
                with self.assertRaisesRegex(SetupBuildError, "changed before packaging"):
                    build_setup_zip(stage, output, SOURCE_DATE_EPOCH)
            self.assertFalse(output.exists(), "An unsafe staging snapshot cannot publish a ZIP")

    def test_symlink_swap_between_scan_and_open_never_publishes_zip(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            stage = base / "source"
            stage.mkdir()
            original = stage / "VERSION"
            original.write_bytes(b"1.0.1\\n")
            secret = base / "outside-secret"
            secret.write_bytes(b"should-not-appear")
            output = base / "setup.zip"

            from v2_build_setup_zip import _open_verified_member

            def swap_to_symlink(path, meta, name, source_root):
                if name == "VERSION":
                    path.unlink()
                    try:
                        path.symlink_to(secret)
                    except (OSError, NotImplementedError) as exc:
                        self.skipTest(f"Symlinks unavailable: {exc}")
                return _open_verified_member(path, meta, name, source_root)

            with mock.patch("v2_build_setup_zip._open_verified_member", side_effect=swap_to_symlink):
                with self.assertRaisesRegex(SetupBuildError, "changed before packaging"):
                    build_setup_zip(stage, output, SOURCE_DATE_EPOCH)
            self.assertFalse(output.exists(), "Symlinked secrets must not be archived")

    def test_parent_directory_symlink_swap_never_packages_external_file(self) -> None:
        if not hasattr(os, "O_DIRECTORY") or not hasattr(os, "O_NOFOLLOW") or os.open not in os.supports_dir_fd:
            self.skipTest("Safe dirfd-relative opens unavailable on this platform")
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            stage = base / "source"
            subdir = stage / "backend"
            subdir.mkdir(parents=True)
            (subdir / "VERSION").write_bytes(b"1.0.1\\n")
            moved = base / "original-backend"
            outside = base / "outside"
            outside.mkdir()
            (outside / "VERSION").write_bytes(b"external-sensitive-data")
            output = base / "setup.zip"

            from v2_build_setup_zip import _open_verified_member

            def swap_parent_before_open(path, meta, name, source_root):
                if name == "backend/VERSION":
                    subdir.rename(moved)
                    try:
                        subdir.symlink_to(outside, target_is_directory=True)
                    except (OSError, NotImplementedError) as exc:
                        self.skipTest(f"Symlinks unavailable: {exc}")
                return _open_verified_member(path, meta, name, source_root)

            with mock.patch("v2_build_setup_zip._open_verified_member", side_effect=swap_parent_before_open):
                with self.assertRaisesRegex(SetupBuildError, "changed before packaging"):
                    build_setup_zip(stage, output, SOURCE_DATE_EPOCH)
            self.assertFalse(output.exists(), "Replaced ancestor must never publish a ZIP")
            self.assertEqual((outside / "VERSION").read_bytes(), b"external-sensitive-data")
            self.assertEqual(list(base.glob(".v2-setup-*.zip")), [])

    def test_canonical_release_factory_reuses_the_verified_zip_writer(self) -> None:
        # Regression for G004: a green CI-only staging ZIP is not evidence
        # for a differently serialized canonical release-factory ZIP.
        repo = Path(__file__).resolve().parents[2]
        release = (repo / ".github/workflows/release-factory.yml").read_text(encoding="utf-8")
        staging = (repo / ".github/workflows/v2-setup-staging-acceptance.yml").read_text(encoding="utf-8")
        setup_job = release.split("\n  setup:\n", 1)[1].split("\n  fresh-install:\n", 1)[0]
        for workflow in (setup_job, staging):
            self.assertIn("python3 scripts/v2_build_setup_zip.py", workflow)
            self.assertIn("--source-root", workflow)
            self.assertIn("--source-date-epoch", workflow)
            self.assertIn("cmp ", workflow)
            self.assertIn("scripts/v2_setup_staging_verify.py", workflow)
        self.assertNotIn("zip -qr", setup_job)

    def test_empty_stage_and_nonportable_timestamps_fail_closed(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "source"
            root.mkdir()
            target = Path(temporary) / "output.zip"
            with self.assertRaisesRegex(SetupBuildError, "empty"):
                build_setup_zip(root, target, SOURCE_DATE_EPOCH)
            (root / "VERSION").write_text("1.0.1\n", encoding="utf-8")
            with self.assertRaisesRegex(SetupBuildError, "date range"):
                build_setup_zip(root, target, 0)
            self.assertFalse(target.exists())


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""CI-only ZIP byte stability, member type, and refusal regression for G004."""
from __future__ import annotations

import os
import stat
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from v2_build_setup_zip import SetupBuildError, build_setup_zip  # noqa: E402

SOURCE_DATE_EPOCH = 1731758400


class DeterministicSetupZipTest(unittest.TestCase):
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

            def replace_before_open(path, meta, name):
                if name == "VERSION":
                    os.replace(replacement_file, path)
                return _open_verified_member(path, meta, name)

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

            def swap_to_symlink(path, meta, name):
                if name == "VERSION":
                    path.unlink()
                    try:
                        path.symlink_to(secret)
                    except (OSError, NotImplementedError) as exc:
                        self.skipTest(f"Symlinks unavailable: {exc}")
                return _open_verified_member(path, meta, name)

            with mock.patch("v2_build_setup_zip._open_verified_member", side_effect=swap_to_symlink):
                with self.assertRaisesRegex(SetupBuildError, "changed before packaging"):
                    build_setup_zip(stage, output, SOURCE_DATE_EPOCH)
            self.assertFalse(output.exists(), "Symlinked secrets must not be archived")

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

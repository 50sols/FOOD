#!/usr/bin/env python3
"""Regression for G005 signed update build inputs: no secret or symlink leakage."""
from __future__ import annotations

import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest import mock
import zipfile


_SCRIPT = Path(__file__).resolve().parents[1] / "build-update-bundle.py"
_SPEC = importlib.util.spec_from_file_location("foodex_update_bundle_security", _SCRIPT)
assert _SPEC is not None and _SPEC.loader is not None
builder = importlib.util.module_from_spec(_SPEC)
_SPEC.loader.exec_module(builder)


class BuildUpdateBundleSourceSafetyTest(unittest.TestCase):
    def test_update_release_versions_require_canonical_advancing_semver(self) -> None:
        for target, minimum in (
            ("1.0.128", "1.0.125"),
            ("1.0.128", "1.0.127"),
            ("2.0.0", "1.999.999"),
        ):
            with self.subTest(target=target, minimum=minimum):
                builder.validate_update_version_range(target, minimum)

        for target, minimum, error in (
            ("1.0", "0.9.0", "Invalid target_version"),
            ("1.0.01", "1.0.0", "Invalid target_version"),
            ("v1.0.128", "1.0.125", "Invalid target_version"),
            ("1.0.128+unsigned", "1.0.125", "Invalid target_version"),
            ("1.0.128-rc.1", "1.0.125", "Invalid target_version"),
            ("1.0.128 ", "1.0.125", "Invalid target_version"),
            ("1.0.128", "01.0.125", "Invalid minimum_current_version"),
            ("1.0.128", "1.0.127\n", "Invalid minimum_current_version"),
            ("1.0.127", "1.0.127", "must be greater"),
            ("1.0.126", "1.0.127", "must be greater"),
            ("1.9.99", "2.0.0", "must be greater"),
        ):
            with self.subTest(target=target, minimum=minimum):
                with self.assertRaisesRegex(RuntimeError, error):
                    builder.validate_update_version_range(target, minimum)

    def test_invalid_update_version_fails_before_git_or_any_staging(self) -> None:
        arguments = type("Args", (), {
            "target_version": "1.0.127",
            "minimum_current_version": "1.0.127",
        })()
        with mock.patch.object(builder, "parse_args", return_value=arguments):
            with mock.patch.object(builder, "run_git") as git:
                with mock.patch.object(builder.tempfile, "mkstemp") as staged:
                    with self.assertRaisesRegex(RuntimeError, "must be greater"):
                        builder.main()
                    git.assert_not_called()
                    staged.assert_not_called()

    def test_canonical_source_and_example_template_remain_valid(self) -> None:
        for name in (
            "VERSION",
            "backend/config/foodex.php",
            "backend/.env.example",
            "backend/.env.testing.example",
            "backend/public/index.php",
            "backend/public/.htaccess",
            "backend/public/assets/admin/app.css",
            "assets/admin/app.css",
        ):
            with self.subTest(name=name):
                self.assertEqual(builder.safe_path(name), name)

    def test_refuse_protected_secret_aliases_and_nonportable_members(self) -> None:
        invalid = (
            "backend/.env",
            "backend/.env.local",
            "backend/public/.ENV.production",
            "backend/config/.env.prod",
            "backend/public/assets/.env.staging",
            "backend/public/assets/app.php",
            "backend/public/preview/dropper.php5.jpg",
            "preview/.htaccess",
            "backend/public/assets/.user.ini",
            "backend/public/assets/web.config",
            "backend/VENDOR/package.php",
            "backend/Storage/data.json",
            "backend/.GIT/config",
            "backend/public/assets/con",
            "backend/public/assets/CONIN$",
            "backend/public/assets/ConOut$.log",
            "backend/config/conin$.txt",
            "backend/public/assets/trailing.",
            "backend/public/assets/../secrets.json",
            "backend/public/assets/./secret.json",
            "backend//public/app.css",
            "backend\\public\\assets\\app.css",
            "/backend/public/app.css",
            "backend/public/assets/تجربة.png",
            "backend/public/assets/file\tname",
        )
        for name in invalid:
            with self.subTest(name=name), self.assertRaises(RuntimeError):
                builder.safe_path(name)

    def test_cloud_and_client_credential_homes_are_never_update_members(self) -> None:
        # Generic names must be blocked through their credential-bearing parent.
        for member in (
            "backend/public/assets/admin/.aws/credentials",
            "backend/public/preview/customer/.AWS/config",
            "backend/app/ThirdParty/.ssh/id_ed25519.pub",
            "backend/public/assets/.docker/config.json",
            "backend/public/assets/.KUBE/config",
            "backend/public/assets/.gnupg/pubring.kbx",
            "backend/public/assets/.git-credentials",
            "backend/config/.GIT-CREDENTIALS",
            "backend/public/assets/.azure/accessTokens.json",
            "backend/public/assets/.TERRAFORM/terraform.tfstate",
            "backend/public/assets/.config/gcloud/credentials.db",
            "backend/public/assets/.CONFIG/GCLOUD/configurations/config_default",
            "backend/public/preview/driver/application_default_credentials.json",
            "backend/public/assets/admin/azureProfile.json",
            "backend/public/assets/admin/ACCESSTOKENS.JSON",
            "backend/public/assets/admin/terraform.tfstate",
            "backend/public/assets/admin/TERRAFORM.TFSTATE.BACKUP",
        ):
            with self.subTest(member=member):
                with self.assertRaisesRegex(RuntimeError, "Credential storage"):
                    builder.safe_path(member)

        self.assertEqual(
            builder.safe_path("backend/public/assets/admin/styles.css"),
            "backend/public/assets/admin/styles.css",
        )

    def test_credential_archive_member_is_rejected_before_zip_staging(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            output = root / "FOODEX-Update.zip"
            with mock.patch.object(builder.tempfile, "mkstemp") as staged:
                with self.assertRaisesRegex(RuntimeError, "Credential storage"):
                    builder.build_zip(
                        root,
                        [("backend/public/assets/terraform.tfstate",
                          "backend/public/assets/terraform.tfstate")],
                        output,
                    )
                staged.assert_not_called()
            self.assertFalse(output.exists())

    def test_windows_console_device_member_is_rejected_before_zip_staging(self) -> None:
        # ASCII CONIN$/CONOUT$ are reserved on Windows even when suffixed
        # or nested in an otherwise portable Laravel runtime path.
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "repo"
            source = root / "backend/public/assets/CONOUT$.log"
            source.parent.mkdir(parents=True)
            source.write_bytes(b"device-name-not-an-asset")
            output = root / "dist/FOODEX-Update.zip"
            with mock.patch.object(builder.tempfile, "mkstemp") as stage:
                with self.assertRaisesRegex(RuntimeError, "Nonportable update path"):
                    builder.build_zip(
                        root,
                        [("backend/public/assets/CONOUT$.log", "backend/public/assets/CONOUT$.log")],
                        output,
                    )
                stage.assert_not_called()
            self.assertFalse(os.path.lexists(output))
            self.assertEqual(source.read_bytes(), b"device-name-not-an-asset")

        self.assertEqual(
            builder.safe_path("backend/public/assets/admin/console.css"),
            "backend/public/assets/admin/console.css",
        )

    def test_private_keys_and_service_credentials_are_never_update_members(self) -> None:
        for member in (
            "backend/config/private.key",
            "backend/config/CLIENT.P12",
            "backend/resources/admin/client.PFX",
            "backend/config/id_rsa",
            "backend/config/SERVICE-ACCOUNT.JSON",
            "backend/resources/admin/client_secret.json",
            "assets/admin/private_certificate.pem",
        ):
            with self.subTest(member=member):
                with self.assertRaisesRegex(RuntimeError, "Private key or service credentials"):
                    builder.safe_path(member)

        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "repo"
            secret = root / "backend/resources/admin/private.key"
            secret.parent.mkdir(parents=True)
            secret.write_bytes(b"DO-NOT-PUBLISH-PRIVATE-MATERIAL")
            output = root / "dist/FOODEX-Update.zip"
            with mock.patch.object(builder.tempfile, "mkstemp") as stage:
                with self.assertRaisesRegex(RuntimeError, "Private key or service credentials"):
                    builder.complete_runtime_files(root)
                stage.assert_not_called()
            self.assertFalse(output.exists())
            self.assertEqual(secret.read_bytes(), b"DO-NOT-PUBLISH-PRIVATE-MATERIAL")

        for public_asset in (
            "backend/public/assets/cacert.pem",
            "backend/public/assets/id_ed25519.pub",
        ):
            self.assertEqual(builder.safe_path(public_asset), public_asset)

    def test_refuse_package_manager_credentials_from_dirty_runtime_tree(self) -> None:
        # Shared-host staging can accidentally contain developer credential
        # files in a recursively included resources/public asset directory.
        for filename in ("auth.json", ".npmrc", ".netrc", ".pypirc", ".yarnrc.yml"):
            for path in (
                f"backend/resources/admin/{filename}",
                f"backend/public/assets/admin/{filename.upper()}",
                f"assets/admin/{filename}",
            ):
                with self.subTest(path=path), self.assertRaisesRegex(
                    RuntimeError, "Package-manager credentials"
                ):
                    builder.safe_path(path)

        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "repo"
            credential = root / "backend/resources/admin/.npmrc"
            credential.parent.mkdir(parents=True)
            credential.write_bytes(b"//registry.example.invalid/:_authToken=PRIVATE")
            output = root / "dist/FOODEX-Update.zip"
            with mock.patch.object(builder.tempfile, "mkstemp") as temporary_zip:
                with self.assertRaisesRegex(RuntimeError, "Package-manager credentials"):
                    builder.complete_runtime_files(root)
                temporary_zip.assert_not_called()
            self.assertFalse(output.exists())
            self.assertEqual(credential.read_bytes(), b"//registry.example.invalid/:_authToken=PRIVATE")

        # The policy is filename-based, not an arbitrary denial of frontend
        # assets; standard compiled files retain their canonical selection.
        self.assertEqual(
            builder.safe_path("backend/public/assets/admin/app.css"),
            "backend/public/assets/admin/app.css",
        )

    def test_public_uploaded_content_is_excluded_before_update_zip_creation(self) -> None:
        # A dirty shared-host staging copy may contain customer uploads inside
        # backend/public/. These must never become authenticated release bytes.
        # Case aliases are dangerous on cPanel and desktop ZIP extractors.
        for name in (
            "backend/public/uploads/customer/invoice.pdf",
            "backend/public/UPLOADS/photo.jpg",
            "public/uploads/customer/private.png",
            "PUBLIC/Uploads/private.txt",
        ):
            with self.subTest(name=name):
                with self.assertRaisesRegex(RuntimeError, "Public uploaded/runtime content"):
                    builder.safe_path(name)

        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "repo"
            upload = root / "backend/public/uploads/customer/receipt.pdf"
            upload.parent.mkdir(parents=True)
            upload.write_bytes(b"PRIVATE-CUSTOMER-UPLOAD")
            output = root / "dist/FOODEX-Update.zip"
            with mock.patch.object(builder.tempfile, "mkstemp") as create:
                with self.assertRaisesRegex(RuntimeError, "Public uploaded/runtime content"):
                    builder.complete_runtime_files(root)
                create.assert_not_called()
            self.assertFalse(output.exists())
            self.assertEqual(upload.read_bytes(), b"PRIVATE-CUSTOMER-UPLOAD")

        # Canonical compiled/static assets remain valid runtime package inputs.
        for name in ("backend/public/assets/admin/app.css", "assets/admin/app.css"):
            self.assertEqual(builder.safe_path(name), name)

    def test_public_storage_mirror_cannot_package_user_uploads(self) -> None:
        # cPanel hosts may materialize Laravel's public/storage symlink as a
        # real directory containing customer attachments. It is not an asset.
        for name in (
            "backend/public/storage/invoices/private.pdf",
            "backend/public/STORAGE/customer/photo.jpg",
            "public/storage/private.txt",
            "PUBLIC/Storage/customer/receipt.png",
            "backend/public/storage",
        ):
            with self.subTest(name=name):
                with self.assertRaisesRegex(RuntimeError, "Public uploaded/runtime content"):
                    builder.safe_path(name)

        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "repo"
            private = root / "backend/public/storage/customer/receipt.pdf"
            private.parent.mkdir(parents=True)
            private.write_bytes(b"PRIVATE-CUSTOMER-STORAGE-CONTENT")
            output = root / "dist/FOODEX-Update.zip"
            with mock.patch.object(builder.tempfile, "mkstemp") as create:
                with self.assertRaisesRegex(RuntimeError, "Public uploaded/runtime content"):
                    builder.complete_runtime_files(root)
                create.assert_not_called()
            self.assertFalse(output.exists())
            self.assertEqual(private.read_bytes(), b"PRIVATE-CUSTOMER-STORAGE-CONTENT")

        # Canonical compiled assets outside runtime storage remain eligible.
        self.assertEqual(
            builder.safe_path("backend/public/assets/admin/app.css"),
            "backend/public/assets/admin/app.css",
        )

    def test_regular_files_create_the_expected_deterministic_zip(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "repo"
            source = root / "backend/public/assets/admin/app.css"
            source.parent.mkdir(parents=True)
            source.write_bytes(b"body { color: green; }")
            output = root / "dist" / "FOODEX-Update.zip"
            entries = [
                ("assets/admin/app.css", "backend/public/assets/admin/app.css"),
                ("backend/public/assets/admin/app.css", "backend/public/assets/admin/app.css"),
            ]
            builder.build_zip(root, entries, output)
            with zipfile.ZipFile(output) as archive:
                self.assertEqual(archive.namelist(), [entry[0] for entry in entries])
                for name, _ in entries:
                    self.assertEqual(archive.read(name), source.read_bytes())

    def test_update_release_sidecars_refuse_existing_manifest_and_dangling_symlink(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            existing = root / "FOODEX-Update.json"
            existing.write_text('{"signed":true}', encoding="utf-8")
            with mock.patch.object(builder.tempfile, "mkstemp") as stage:
                with self.assertRaisesRegex(RuntimeError, "already exists"):
                    builder.reject_existing_release_outputs(
                        [root / "FOODEX-Update.zip", existing, root / "FOODEX-Update.sha256.txt"]
                    )
                with self.assertRaisesRegex(RuntimeError, "already exists"):
                    builder.publish_release_sidecar(existing, '{"signed":false}')
                stage.assert_not_called()
            self.assertEqual(existing.read_text(encoding="utf-8"), '{"signed":true}')

            dangling = root / "FOODEX-Update.sha256.txt"
            try:
                dangling.symlink_to(root / "missing-signature.txt")
            except (OSError, NotImplementedError):
                self.skipTest("Symbolic links unavailable on this host")
            with mock.patch.object(builder.tempfile, "mkstemp") as stage:
                with self.assertRaisesRegex(RuntimeError, "already exists"):
                    builder.reject_existing_release_outputs([dangling])
                stage.assert_not_called()
            self.assertTrue(dangling.is_symlink())

    def test_update_release_sidecar_exclusive_creation_preserves_exact_bytes(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            text = '{"sha256":"abcdef","source_commit":"12345"}\n'
            manifest = root / "FOODEX-Update.json"
            builder.publish_release_sidecar(manifest, text)
            self.assertEqual(manifest.read_bytes(), text.encode("utf-8"))
            self.assertEqual(list(root.glob(".*.staging")), [])
            with self.assertRaisesRegex(RuntimeError, "already exists"):
                builder.publish_release_sidecar(manifest, "REPLACED")
            self.assertEqual(manifest.read_bytes(), text.encode("utf-8"))

    def test_update_release_sidecar_refuses_late_competing_publisher(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            hash_file = root / "FOODEX-Update.sha256.txt"
            real_link = os.link

            def competing_publisher(staging: Path, destination: Path) -> None:
                destination.write_text("winning-signed-hash\n", encoding="utf-8")
                real_link(staging, destination)

            with mock.patch.object(builder.os, "link", side_effect=competing_publisher) as link:
                with self.assertRaisesRegex(RuntimeError, "already exists"):
                    builder.publish_release_sidecar(hash_file, "losing-hash\n")
                link.assert_called_once()
            self.assertEqual(hash_file.read_bytes(), b"winning-signed-hash\n")
            self.assertEqual(list(root.glob(".*.staging")), [])

    def test_update_release_sidecar_fails_closed_without_exclusive_links(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            file_list = root / "FOODEX-Update.files.txt"
            with mock.patch.object(builder.os, "link", side_effect=OSError("hardlink blocked")):
                with self.assertRaisesRegex(RuntimeError, "sidecar publication unavailable"):
                    builder.publish_release_sidecar(file_list, "backend/artisan\n")
            self.assertFalse(os.path.lexists(file_list))
            self.assertEqual(list(root.glob(".*.staging")), [])

    def _staged_release_fixture(self, output_dir: Path) -> tuple[Path, dict[str, bytes]]:
        staging = output_dir / "private-build"
        staging.mkdir(mode=0o700)
        contents = {
            "FOODEX-Update.json": b'{"sha256":"abc","source_commit":"123"}\n',
            "FOODEX-Update.files.txt": b"backend/artisan\n",
            "FOODEX-Update.sha256.txt": b"abc  FOODEX-Update.zip\n",
            "FOODEX-Update.zip": b"PK\x03\x04release-bytes",
        }
        for name, data in contents.items():
            (staging / name).write_bytes(data)
        return staging, contents

    def test_complete_update_release_publishes_zip_last_under_exclusive_lock(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            output_dir = Path(directory)
            staging, contents = self._staged_release_fixture(output_dir)
            order: list[str] = []
            actual_link = os.link

            def observe_link(source: Path, destination: Path) -> None:
                if destination.name == "FOODEX-Update.zip":
                    for sidecar in list(contents)[:-1]:
                        self.assertTrue((output_dir / sidecar).is_file())
                else:
                    self.assertFalse(os.path.lexists(output_dir / "FOODEX-Update.zip"))
                order.append(destination.name)
                actual_link(source, destination)

            with mock.patch.object(builder.os, "link", side_effect=observe_link):
                builder.publish_release_bundle(staging, output_dir)

            self.assertEqual(list(contents), order)
            for name, data in contents.items():
                self.assertEqual(data, (output_dir / name).read_bytes())
            self.assertFalse((output_dir / ".FOODEX-Update.publish.lock").exists())

    def test_complete_update_release_rolls_back_midpublication_failure(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            output_dir = Path(directory)
            staging, contents = self._staged_release_fixture(output_dir)
            actual_link = os.link
            count = 0

            def fail_third_link(source: Path, destination: Path) -> None:
                nonlocal count
                count += 1
                if count == 3:
                    raise OSError("filesystem refused third artifact")
                actual_link(source, destination)

            with mock.patch.object(builder.os, "link", side_effect=fail_third_link):
                with self.assertRaisesRegex(RuntimeError, "group publication unavailable"):
                    builder.publish_release_bundle(staging, output_dir)
            self.assertEqual(3, count)
            for name, content in contents.items():
                self.assertFalse(os.path.lexists(output_dir / name), name)
                self.assertEqual(content, (staging / name).read_bytes())
            self.assertFalse((output_dir / ".FOODEX-Update.publish.lock").exists())

    def test_complete_update_release_preserves_competing_and_existing_signed_outputs(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            output_dir = Path(directory)
            staging, contents = self._staged_release_fixture(output_dir)
            signed = output_dir / "FOODEX-Update.json"
            signed.write_bytes(b"EXISTING-SIGNED-MANIFEST")
            with self.assertRaisesRegex(RuntimeError, "already exists"):
                builder.publish_release_bundle(staging, output_dir)
            self.assertEqual(b"EXISTING-SIGNED-MANIFEST", signed.read_bytes())
            self.assertFalse((output_dir / "FOODEX-Update.zip").exists())
            signed.unlink()

            actual_link = os.link
            count = 0

            def competing_publisher(source: Path, destination: Path) -> None:
                nonlocal count
                count += 1
                if count == 2:
                    destination.write_bytes(b"WINNING-INDEPENDENT-PUBLISHER")
                actual_link(source, destination)

            with mock.patch.object(builder.os, "link", side_effect=competing_publisher):
                with self.assertRaisesRegex(RuntimeError, "already exists"):
                    builder.publish_release_bundle(staging, output_dir)
            self.assertEqual(b"WINNING-INDEPENDENT-PUBLISHER",
                             (output_dir / "FOODEX-Update.files.txt").read_bytes())
            self.assertFalse((output_dir / "FOODEX-Update.json").exists())
            self.assertFalse((output_dir / "FOODEX-Update.zip").exists())
            self.assertFalse((output_dir / ".FOODEX-Update.publish.lock").exists())
            self.assertEqual(contents["FOODEX-Update.zip"],
                             (staging / "FOODEX-Update.zip").read_bytes())

    def test_complete_update_release_rejects_busy_lock_and_symlinked_staging(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            output_dir = Path(directory)
            staging, contents = self._staged_release_fixture(output_dir)
            lock = output_dir / ".FOODEX-Update.publish.lock"
            lock.mkdir()
            with self.assertRaisesRegex(RuntimeError, "publication lock unavailable"):
                builder.publish_release_bundle(staging, output_dir)
            self.assertTrue(lock.is_dir())
            lock.rmdir()

            candidate = staging / "FOODEX-Update.sha256.txt"
            candidate.unlink()
            try:
                candidate.symlink_to(output_dir / "missing")
            except (OSError, NotImplementedError):
                self.skipTest("Symlinks unavailable")
            with self.assertRaisesRegex(RuntimeError, "Unsafe staged Update release member"):
                builder.publish_release_bundle(staging, output_dir)
            for name in contents:
                self.assertFalse(os.path.lexists(output_dir / name))
            self.assertFalse(lock.exists())

    def test_update_zip_refuses_existing_output_without_staging(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "repo"
            source = root / "backend/public/assets/admin/app.css"
            source.parent.mkdir(parents=True)
            source.write_bytes(b"reviewed-css")
            output = root / "dist/FOODEX-Update.zip"
            output.parent.mkdir(parents=True)
            output.write_bytes(b"previously-signed-release")
            member = "backend/public/assets/admin/app.css"
            with mock.patch.object(builder.tempfile, "mkstemp") as staging:
                with self.assertRaisesRegex(RuntimeError, "already exists"):
                    builder.build_zip(root, [(member, member)], output)
                staging.assert_not_called()
            self.assertEqual(output.read_bytes(), b"previously-signed-release")

            output.unlink()
            try:
                output.symlink_to(root / "nonexistent-private-target.zip")
            except (OSError, NotImplementedError):
                self.skipTest("Symbolic links are unavailable on this host")
            with mock.patch.object(builder.tempfile, "mkstemp") as staging:
                with self.assertRaisesRegex(RuntimeError, "already exists"):
                    builder.build_zip(root, [(member, member)], output)
                staging.assert_not_called()
            self.assertTrue(output.is_symlink())

    def test_update_zip_refuses_late_competing_publish_without_clobbering(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "repo"
            source = root / "backend/public/assets/admin/app.css"
            source.parent.mkdir(parents=True)
            source.write_bytes(b"reviewed-css")
            output = root / "dist/FOODEX-Update.zip"
            member = "backend/public/assets/admin/app.css"
            real_link = os.link

            def competing_publish(staging: Path, destination: Path) -> None:
                # A different publisher wins AFTER this builder validates its
                # ZIP but BEFORE exclusive publication.
                destination.write_bytes(b"other-builder-verified-zip")
                real_link(staging, destination)

            with mock.patch.object(builder.os, "link", side_effect=competing_publish) as link:
                with self.assertRaisesRegex(RuntimeError, "already exists"):
                    builder.build_zip(root, [(member, member)], output)
                link.assert_called_once()
            self.assertEqual(output.read_bytes(), b"other-builder-verified-zip")
            self.assertEqual(list(output.parent.glob(".*.staging")), [])

    def test_update_zip_fails_closed_when_exclusive_publish_unsupported(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "repo"
            source = root / "backend/public/assets/admin/app.css"
            source.parent.mkdir(parents=True)
            source.write_bytes(b"reviewed-css")
            output = root / "dist/FOODEX-Update.zip"
            member = "backend/public/assets/admin/app.css"
            with mock.patch.object(builder.os, "link", side_effect=OSError("hardlinks denied")):
                with self.assertRaisesRegex(RuntimeError, "exclusive Update ZIP publication unavailable"):
                    builder.build_zip(root, [(member, member)], output)
            self.assertFalse(os.path.lexists(output))
            self.assertEqual(list(output.parent.glob(".*.staging")), [])

    def test_bad_archive_alias_fails_before_writing_output(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "repo"
            source = root / "backend/public/assets/admin/app.css"
            source.parent.mkdir(parents=True)
            source.write_text("ok", encoding="utf-8")
            output = root / "dist/FOODEX-Update.zip"
            entries = [
                ("backend/public/assets/admin/app.css", "backend/public/assets/admin/app.css"),
                ("backend/public/assets/admin/APP.CSS", "backend/public/assets/admin/APP.CSS"),
            ]
            with self.assertRaisesRegex(RuntimeError, "Ambiguous"):
                builder.build_zip(root, entries, output)
            self.assertFalse(output.exists())

    def test_refuse_linked_release_file_before_zip_creation(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            root = base / "repo"
            path = root / "backend/public/assets/admin/app.css"
            path.parent.mkdir(parents=True)
            secret = base / "unrelated-secret.txt"
            secret.write_text("not distributable", encoding="utf-8")
            try:
                path.symlink_to(secret)
            except (OSError, NotImplementedError):
                self.skipTest("Symbolic link creation unavailable on this host")
            output = root / "dist/FOODEX-Update.zip"
            with self.assertRaisesRegex(RuntimeError, "symbolic links"):
                builder.build_zip(root, [(path.relative_to(root).as_posix(), path.relative_to(root).as_posix())], output)
            self.assertFalse(output.exists())

    def test_replace_checked_file_with_secret_symlink_during_zip_open_fails_closed(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            root = base / "repo"
            source = root / "backend/public/assets/admin/app.css"
            source.parent.mkdir(parents=True)
            source.write_bytes(b"safe-reviewed-css")
            secret = base / "private-publisher-key.txt"
            secret.write_bytes(b"DO-NOT-PACKAGE-PRIVATE-KEY")
            output = root / "dist/FOODEX-Update.zip"
            member = "backend/public/assets/admin/app.css"

            original_zip = zipfile.ZipFile
            swapped = False

            def swap_after_preflight(*args, **kwargs):
                nonlocal swapped
                if not swapped and len(args) > 1 and args[1] == "w":
                    swapped = True
                    source.rename(source.with_suffix(".reviewed"))
                    source.symlink_to(secret)
                return original_zip(*args, **kwargs)

            with mock.patch.object(builder.zipfile, "ZipFile", side_effect=swap_after_preflight):
                with self.assertRaisesRegex(RuntimeError, "changed or linked"):
                    builder.build_zip(root, [(member, member)], output)

            self.assertTrue(swapped, "The attack must execute AFTER source preflight.")
            self.assertFalse(output.exists(), "Never publish a partially replaced ZIP.")
            self.assertEqual(secret.read_bytes(), b"DO-NOT-PACKAGE-PRIVATE-KEY")
            self.assertEqual(list(output.parent.glob("*.staging")), [])

    def test_replace_checked_file_with_fifo_after_preflight_does_not_block(self) -> None:
        # A FIFO is NOT a symlink. Blocking O_RDONLY open would wait forever
        # for a writer before the source could be rejected by fstat.
        if not hasattr(os, "mkfifo"):
            self.skipTest("Named pipes are unavailable on this host")
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "repo"
            source = root / "backend/public/assets/admin/app.css"
            source.parent.mkdir(parents=True)
            source.write_bytes(b"reviewed-style")
            output = root / "dist/FOODEX-Update.zip"
            member = "backend/public/assets/admin/app.css"
            original_zip = zipfile.ZipFile
            swapped = False

            def swap_for_fifo_after_preflight(*args, **kwargs):
                nonlocal swapped
                if not swapped and len(args) > 1 and args[1] == "w":
                    swapped = True
                    source.rename(source.with_suffix(".reviewed"))
                    os.mkfifo(source)
                return original_zip(*args, **kwargs)

            with mock.patch.object(builder.zipfile, "ZipFile", side_effect=swap_for_fifo_after_preflight):
                with self.assertRaisesRegex(RuntimeError, "changed after preflight"):
                    builder.build_zip(root, [(member, member)], output)

            self.assertTrue(swapped, "FIFO replacement must happen after preflight.")
            self.assertFalse(output.exists(), "Do not publish an invalid ZIP.")
            self.assertEqual(source.with_suffix(".reviewed").read_bytes(), b"reviewed-style")
            self.assertEqual(list(output.parent.glob("*.staging")), [])

    def test_replace_checked_parent_with_offtree_symlink_during_zip_open_fails_closed(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            root = base / "repo"
            source = root / "backend/public/assets/admin/app.css"
            source.parent.mkdir(parents=True)
            source.write_bytes(b"reviewed-asset")
            off_tree = base / "secret-parent"
            off_tree.mkdir()
            (off_tree / "app.css").write_bytes(b"SECRET-OFF-TREE")
            output = root / "dist/FOODEX-Update.zip"
            member = "backend/public/assets/admin/app.css"

            original_zip = zipfile.ZipFile
            swapped = False

            def swap_parent_after_preflight(*args, **kwargs):
                nonlocal swapped
                if not swapped and len(args) > 1 and args[1] == "w":
                    swapped = True
                    source.parent.rename(source.parent.with_name("admin.reviewed"))
                    source.parent.symlink_to(off_tree, target_is_directory=True)
                return original_zip(*args, **kwargs)

            with mock.patch.object(builder.zipfile, "ZipFile", side_effect=swap_parent_after_preflight):
                with self.assertRaisesRegex(RuntimeError, "changed or linked"):
                    builder.build_zip(root, [(member, member)], output)

            self.assertTrue(swapped)
            self.assertFalse(output.exists())
            self.assertEqual((off_tree / "app.css").read_bytes(), b"SECRET-OFF-TREE")
            self.assertEqual(list(output.parent.glob("*.staging")), [])

    def test_refuse_hardlinked_external_release_input_before_zip_creation(self) -> None:
        # A hard link passes pathlib is_symlink() and resolve() confinement.
        # It aliases the exact inode of a file outside the reviewed worktree.
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            root = base / "repo"
            source = root / "backend/public/assets/admin/app.css"
            source.parent.mkdir(parents=True)
            outside = base / "outside-signing-tree-secret.txt"
            outside.write_bytes(b"outside-secret-must-not-ship")
            try:
                os.link(outside, source)
            except OSError as exc:
                self.skipTest(f"Hard links unavailable: {exc}")

            self.assertFalse(source.is_symlink())
            self.assertTrue(source.is_file())
            self.assertEqual(source.resolve(), source)
            self.assertGreater(source.stat().st_nlink, 1)
            output = root / "dist/FOODEX-Update.zip"
            entry = "backend/public/assets/admin/app.css"
            with self.assertRaisesRegex(RuntimeError, "single-link regular file"):
                builder.build_zip(root, [(entry, entry)], output)
            self.assertFalse(output.exists())
            self.assertEqual(outside.read_bytes(), b"outside-secret-must-not-ship")

    def test_refuse_linked_parent_directory_before_zip_creation(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            root = base / "repo"
            root.mkdir()
            off_tree = base / "unrelated-runtime"
            off_tree.mkdir()
            (off_tree / "payload.css").write_text("not distributable", encoding="utf-8")
            directory = root / "backend/public/assets"
            directory.parent.mkdir(parents=True)
            try:
                directory.symlink_to(off_tree, target_is_directory=True)
            except (OSError, NotImplementedError):
                self.skipTest("Symbolic link creation unavailable on this host")
            output = root / "dist/FOODEX-Update.zip"
            source = "backend/public/assets/payload.css"
            with self.assertRaisesRegex(RuntimeError, "symbolic links"):
                builder.build_zip(root, [(source, source)], output)
            self.assertFalse(output.exists())


    def test_update_zip_rejects_changed_composer_production_runtime_contract(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / "backend").mkdir()
            previous = {
                "require": {"php": "^8.2", "laravel/framework": "^12.0"},
                "autoload": {"psr-4": {"App\\\\": "app/"}},
                "repositories": [{"type": "path", "url": "./local"}],
                "config": {"preferred-install": "dist"},
            }
            for changed_section, replacement in (
                ("require", {"php": "^8.2", "laravel/framework": "^13.0"}),
                ("autoload", {"psr-4": {"New\\\\": "app/"}}),
                ("repositories", [{"type": "vcs", "url": "https://example.invalid/pkg"}]),
                ("replace", {"illuminate/contracts": "*"}),
                ("provide", {"virtual/queue": "*"}),
                ("conflict", {"laravel/framework": "<12.0"}),
                ("extra", {"laravel": {"dont-discover": ["acme/package"]}}),
                ("bin", ["bin/custom-command"]),
                ("scripts", {"post-autoload-dump": ["@php artisan package:discover"]}),
            ):
                with self.subTest(section=changed_section):
                    current = dict(previous)
                    current[changed_section] = replacement
                    (root / "backend/composer.json").write_text(
                        json.dumps(current), encoding="utf-8"
                    )
                    with mock.patch.object(builder, "run_git", return_value=json.dumps(previous)):
                        with self.assertRaisesRegex(RuntimeError, "excludes vendor"):
                            builder.verify_composer_runtime_contract(root, "v1.0.125")

    def test_update_zip_rejects_changed_composer_plugin_permission(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / "backend").mkdir()
            previous = {
                "require": {"php": "^8.2"},
                "config": {"allow-plugins": {"trusted/composer-plugin": True}},
            }
            for changed in (
                {"allow-plugins": {"trusted/composer-plugin": False}},
                {"allow-plugins": {"trusted/composer-plugin": True, "untrusted/plugin": True}},
            ):
                with self.subTest(config=changed):
                    current = {**previous, "config": changed}
                    (root / "backend/composer.json").write_text(
                        json.dumps(current), encoding="utf-8"
                    )
                    with mock.patch.object(builder, "run_git", return_value=json.dumps(previous)):
                        with self.assertRaisesRegex(RuntimeError, "config.allow-plugins"):
                            builder.verify_composer_runtime_contract(root, "v1.0.125")

    def test_update_zip_allows_unchanged_composer_runtime_metadata(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / "backend").mkdir()
            previous = {
                "require": {"php": "^8.2"},
                "scripts": {"post-autoload-dump": ["@php artisan package:discover"]},
                "extra": {"laravel": {"dont-discover": []}},
                "config": {"allow-plugins": {"trusted/plugin": True}, "preferred-install": "dist"},
            }
            current = {**previous, "config": {**previous["config"], "sort-packages": True}}
            (root / "backend/composer.json").write_text(
                json.dumps(current), encoding="utf-8"
            )
            with mock.patch.object(builder, "run_git", return_value=json.dumps(previous)):
                builder.verify_composer_runtime_contract(root, "v1.0.125")

    def test_update_zip_rejects_changed_locked_production_packages_and_platform(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / "backend").mkdir()
            previous = {
                "packages": [
                    {"name": "laravel/framework", "version": "v12.0.0", "dist": {"reference": "old"}},
                ],
                "packages-dev": [{"name": "phpunit/phpunit", "version": "11.5.0"}],
                "platform": {"php": "^8.2"},
                "plugin-api-version": "2.6.0",
            }

            def read_base(*args):
                if args[0] == "ls-tree":
                    return "backend/composer.lock\n"
                if args[0] == "show":
                    return json.dumps(previous)
                raise AssertionError(f"Unexpected Git command: {args}")

            for changed_section, changed_value in (
                ("packages", [{"name": "laravel/framework", "version": "v12.1.0"}]),
                ("platform", {"php": "^8.4"}),
                ("plugin-api-version", "2.7.0"),
            ):
                with self.subTest(section=changed_section):
                    current = dict(previous)
                    current[changed_section] = changed_value
                    (root / "backend/composer.lock").write_text(
                        json.dumps(current), encoding="utf-8"
                    )
                    with mock.patch.object(builder, "run_git", side_effect=read_base):
                        with self.assertRaisesRegex(RuntimeError, f"production {changed_section}"):
                            builder.verify_composer_lock_contract(root, "v1.0.128")

    def test_update_zip_allows_dev_only_lock_changes_with_same_production_resolution(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / "backend").mkdir()
            previous = {
                "packages": [{"name": "laravel/framework", "version": "v12.0.0"}],
                "platform": {"php": "^8.2"},
                "packages-dev": [{"name": "phpunit/phpunit", "version": "11.5.0"}],
                "content-hash": "old-dev-hash",
            }
            current = {
                **previous,
                "packages-dev": [{"name": "phpunit/phpunit", "version": "11.6.0"}],
                "content-hash": "new-dev-hash",
            }
            (root / "backend/composer.lock").write_text(json.dumps(current), encoding="utf-8")

            def read_base(*args):
                return "backend/composer.lock\n" if args[0] == "ls-tree" else json.dumps(previous)

            with mock.patch.object(builder, "run_git", side_effect=read_base):
                self.assertTrue(builder.verify_composer_lock_contract(root, "v1.0.128"))

    def test_update_zip_lock_verification_handles_legacy_and_missing_target_fail_closed(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / "backend").mkdir()
            with mock.patch.object(builder, "run_git", return_value=""):
                # Historical base v1.0.125 contains no tracked composer.lock.
                # This is UNVERIFIED, never a claim of compatibility.
                self.assertFalse(builder.verify_composer_lock_contract(root, "v1.0.125"))

            def read_base(*args):
                return "backend/composer.lock\n" if args[0] == "ls-tree" else json.dumps(
                    {"packages": [{"name": "laravel/framework", "version": "v12.0.0"}]}
                )

            with mock.patch.object(builder, "run_git", side_effect=read_base):
                with self.assertRaisesRegex(RuntimeError, "Unable to verify"):
                    builder.verify_composer_lock_contract(root, "v1.0.128")
                (root / "backend/composer.lock").write_text('{"packages":{}}', encoding="utf-8")
                with self.assertRaisesRegex(RuntimeError, "production packages arrays"):
                    builder.verify_composer_lock_contract(root, "v1.0.128")

    def test_update_zip_allows_composer_nonruntime_config_change(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / "backend").mkdir()
            previous = {"require": {"php": "^8.2"}, "autoload": {"psr-4": {"App\\\\": "app/"}}}
            current = {**previous, "config": {"platform": {"php": "8.2.0"}}}
            (root / "backend/composer.json").write_text(json.dumps(current), encoding="utf-8")
            with mock.patch.object(builder, "run_git", return_value=json.dumps(previous)):
                builder.verify_composer_runtime_contract(root, "v1.0.125")

    def test_update_zip_refuses_invalid_or_unverifiable_composer_contract(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / "backend").mkdir()
            (root / "backend/composer.json").write_text('{"require": {}}', encoding="utf-8")
            with mock.patch.object(builder, "run_git", return_value="not json"):
                with self.assertRaisesRegex(RuntimeError, "Unable to verify"):
                    builder.verify_composer_runtime_contract(root, "v1.0.125")
            with mock.patch.object(builder, "run_git", return_value="[]"):
                with self.assertRaisesRegex(RuntimeError, "must be a JSON object"):
                    builder.verify_composer_runtime_contract(root, "v1.0.125")


if __name__ == "__main__":
    unittest.main()

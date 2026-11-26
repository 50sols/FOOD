#!/usr/bin/env python3
"""Regression for G005 signed update build inputs: no secret or symlink leakage."""
from __future__ import annotations

import importlib.util
import hashlib
import io
import json
import os
import stat
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
            "backend/public/assets/.config/gh/hosts.yml",
            "backend/public/assets/.CONFIG/GH/hosts.yml",
            "backend/public/assets/.config/git/credentials",
            "backend/public/assets/.CONFIG/GIT/config",
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
        binary = io.BytesIO()
        with zipfile.ZipFile(binary, "w", compression=zipfile.ZIP_DEFLATED) as archive:
            archive.writestr("backend/artisan", b"<?php // staged reviewed Laravel CLI")
        zipped = binary.getvalue()
        digest = hashlib.sha256(zipped).hexdigest()
        contents = {
            "FOODEX-Update.json": (
                json.dumps({
                    "package": "FOODEX-Update.zip",
                    "sha256": digest,
                    "schema_version": 1,
                    "available": True,
                    "requires_full_redeploy": False,
                    "reason": None,
                    "release_notes": "Canonical updater regression fixture",
                    "target_version": "1.0.127",
                    "minimum_current_version": "1.0.126",
                    "source_base": "0" * 40,
                    "source_commit": "1" * 40,
                    "contains_migrations": False,
                    "migrations": [],
                    "delta": {
                        "files_manifest": "FOODEX-Update.files.txt",
                        "file_count": 1,
                        "source_file_count": 1,
                    },
                }) + "\n"
            ).encode("utf-8"),
            "FOODEX-Update.files.txt": b"backend/artisan\n",
            "FOODEX-Update.sha256.txt": f"{digest}  FOODEX-Update.zip\n".encode("utf-8"),
            "FOODEX-Update.zip": zipped,
        }
        for name, data in contents.items():
            (staging / name).write_bytes(data)
        return staging, contents

    def test_update_release_group_rejects_duplicate_manifest_json_keys(self) -> None:
        # SHA-consistent artifacts must not depend on last-key-wins JSON
        # parsing, including nested metadata used for update planning.
        cases = (
            (
                "top_level_version",
                b'"target_version": "1.0.127"',
                b'"target_version": "9.9.9", "target_version": "1.0.127"',
            ),
            (
                "nested_file_count",
                b'"file_count": 1',
                b'"file_count": 999, "file_count": 1',
            ),
        )
        for attack, original_key, duplicate_keys in cases:
            with self.subTest(attack=attack):
                with tempfile.TemporaryDirectory() as directory:
                    output_dir = Path(directory)
                    staged, original = self._staged_release_fixture(output_dir)
                    original_manifest = original["FOODEX-Update.json"]
                    self.assertIn(original_key, original_manifest)
                    altered_manifest = original_manifest.replace(original_key, duplicate_keys, 1)
                    (staged / "FOODEX-Update.json").write_bytes(altered_manifest)
                    with self.assertRaisesRegex(RuntimeError, "Duplicate key"):
                        builder.publish_release_bundle(staged, output_dir)
                    for name in original:
                        self.assertFalse(os.path.lexists(output_dir / name), name)
                    self.assertFalse((output_dir / ".FOODEX-Update.publish.lock").exists())


    def test_update_release_group_rejects_noncanonical_file_list_encoding(self) -> None:
        # All other staged sidecars and the ZIP remain valid. The inventory
        # must not be normalized silently by Python's universal newlines.
        variants = {
            "crlf": b"backend/artisan\r\n",
            "cr_only": b"backend/artisan\r",
            "no_final_lf": b"backend/artisan",
            "extra_blank_line": b"backend/artisan\n\n",
            "invalid_utf8": b"backend/artisan\xff\n",
        }
        for variant, file_list in variants.items():
            with self.subTest(variant=variant):
                with tempfile.TemporaryDirectory() as directory:
                    output_dir = Path(directory)
                    staging, original = self._staged_release_fixture(output_dir)
                    (staging / "FOODEX-Update.files.txt").write_bytes(file_list)
                    with self.assertRaisesRegex(RuntimeError, "file list"):
                        builder.publish_release_bundle(staging, output_dir)
                    for name in original:
                        self.assertFalse(os.path.lexists(output_dir / name), name)
                    self.assertEqual(
                        (staging / "FOODEX-Update.files.txt").read_bytes(), file_list
                    )
                    self.assertFalse((output_dir / ".FOODEX-Update.publish.lock").exists())

    def test_update_release_group_refuses_cross_artifact_drift_before_publish(self) -> None:
        alterations = {
            "manifest_hash": lambda p: p.__setitem__(
                "FOODEX-Update.json",
                p["FOODEX-Update.json"].replace(b'"sha256": "', b'"sha256": "0')
            ),
            "checksum_sidecar": lambda p: p.__setitem__(
                "FOODEX-Update.sha256.txt", b"0" * 64 + b"  FOODEX-Update.zip\n"
            ),
            "file_list": lambda p: p.__setitem__(
                "FOODEX-Update.files.txt", b"backend/incorrect-artisan\n"
            ),
            "zip_bytes": lambda p: p.__setitem__(
                "FOODEX-Update.zip", p["FOODEX-Update.zip"] + b"ATTACKER-BYTES"
            ),
        }
        for attack, mutate in alterations.items():
            with self.subTest(attack=attack):
                with tempfile.TemporaryDirectory() as directory:
                    output_dir = Path(directory)
                    staged, original = self._staged_release_fixture(output_dir)
                    modified = dict(original)
                    mutate(modified)
                    for name, value in modified.items():
                        (staged / name).write_bytes(value)

                    with self.assertRaisesRegex(RuntimeError, "manifest|checksum|ZIP digest|file list"):
                        builder.publish_release_bundle(staged, output_dir)

                    for name in modified:
                        self.assertFalse(os.path.lexists(output_dir / name), name)
                        self.assertEqual((staged / name).read_bytes(), modified[name])
                    self.assertFalse((output_dir / ".FOODEX-Update.publish.lock").exists())

    def test_update_release_group_rejects_coherent_invalid_manifest_metadata(self) -> None:
        # The archive, file list, and ZIP digest remain valid; changing only
        # release provenance, range or claimed file counts must block publish.
        attacks = {
            "missing_schema": lambda m: m.pop("schema_version"),
            "future_schema": lambda m: m.__setitem__("schema_version", 2),
            "string_schema": lambda m: m.__setitem__("schema_version", "1"),
            "boolean_schema": lambda m: m.__setitem__("schema_version", True),
            "missing_reason": lambda m: m.pop("reason"),
            "nonempty_reason": lambda m: m.__setitem__("reason", "Manual redeploy required"),
            "missing_notes": lambda m: m.pop("release_notes"),
            "empty_notes": lambda m: m.__setitem__("release_notes", ""),
            "blank_notes": lambda m: m.__setitem__("release_notes", "   "),
            "nonstring_notes": lambda m: m.__setitem__("release_notes", ["not plain text"]),
            "not_available": lambda m: m.__setitem__("available", False),
            "requires_redeploy": lambda m: m.__setitem__("requires_full_redeploy", True),
            "missing_target": lambda m: m.pop("target_version"),
            "invalid_version": lambda m: m.__setitem__("target_version", "1.0.127-beta"),
            "nonadvancing_version": lambda m: m.__setitem__("target_version", "1.0.126"),
            "downgrade": lambda m: m.__setitem__("target_version", "1.0.125"),
            "invalid_source_sha": lambda m: m.__setitem__("source_commit", "untrusted"),
            "missing_base_sha": lambda m: m.pop("source_base"),
            "bad_file_count": lambda m: m["delta"].__setitem__("file_count", 2),
            "bad_source_count": lambda m: m["delta"].__setitem__("source_file_count", 2),
            "boolean_file_count": lambda m: m["delta"].__setitem__("file_count", True),
        }
        for attack, mutate in attacks.items():
            with self.subTest(attack=attack):
                with tempfile.TemporaryDirectory() as directory:
                    output_dir = Path(directory)
                    staging, original = self._staged_release_fixture(output_dir)
                    manifest = json.loads(original["FOODEX-Update.json"])
                    mutate(manifest)
                    modified = dict(original)
                    modified["FOODEX-Update.json"] = (
                        json.dumps(manifest) + "\n"
                    ).encode("utf-8")
                    (staging / "FOODEX-Update.json").write_bytes(
                        modified["FOODEX-Update.json"]
                    )
                    with self.assertRaises(RuntimeError):
                        builder.publish_release_bundle(staging, output_dir)
                    for name, data in modified.items():
                        self.assertFalse(os.path.lexists(output_dir / name), name)
                        self.assertEqual((staging / name).read_bytes(), data)
                    self.assertFalse(
                        (output_dir / ".FOODEX-Update.publish.lock").exists()
                    )

    def test_update_release_group_dashboard_summary_matches_canonical_manifest(self) -> None:
        # The historical minimal staged ZIP fixture omits dashboard_fields;
        # real publisher output includes it. Any present summary must reflect
        # the authoritative version, digest, migration and release metadata.
        def prepare(staging: Path, original: dict[str, bytes]) -> dict[str, bytes]:
            manifest = json.loads(original["FOODEX-Update.json"])
            manifest["release_notes"] = "Approved upgrade notes"
            fields = (
                "target_version",
                "minimum_current_version",
                "source_commit",
                "sha256",
                "contains_migrations",
                "release_notes",
            )
            manifest["dashboard_fields"] = {field: manifest[field] for field in fields}
            adjusted = dict(original)
            adjusted["FOODEX-Update.json"] = (
                json.dumps(manifest) + "\n"
            ).encode("utf-8")
            (staging / "FOODEX-Update.json").write_bytes(adjusted["FOODEX-Update.json"])
            return adjusted

        with tempfile.TemporaryDirectory() as directory:
            output_dir = Path(directory)
            staging, original = self._staged_release_fixture(output_dir)
            adjusted = prepare(staging, original)
            builder.publish_release_bundle(staging, output_dir)
            for name, content in adjusted.items():
                self.assertEqual((output_dir / name).read_bytes(), content)

        attacks = {
            "target_version": ("target_version", "1.0.126"),
            "minimum_current_version": ("minimum_current_version", "1.0.124"),
            "source_commit": ("source_commit", "2" * 40),
            "sha256": ("sha256", "f" * 64),
            "contains_migrations": ("contains_migrations", True),
            "release_notes": ("release_notes", "Forged release notes"),
            "boolean_as_int": ("contains_migrations", 0),
            "missing_digest": ("sha256", None),
            "malformed_summary": (None, None),
            "null_summary": (None, None),
        }
        for attack, (field, value) in attacks.items():
            with self.subTest(attack=attack):
                with tempfile.TemporaryDirectory() as directory:
                    output_dir = Path(directory)
                    staging, original = self._staged_release_fixture(output_dir)
                    adjusted = prepare(staging, original)
                    manifest = json.loads(adjusted["FOODEX-Update.json"])
                    if attack == "malformed_summary":
                        manifest["dashboard_fields"] = []
                    elif attack == "null_summary":
                        manifest["dashboard_fields"] = None
                    elif attack == "missing_digest":
                        manifest["dashboard_fields"].pop("sha256")
                    else:
                        manifest["dashboard_fields"][field] = value
                    adjusted["FOODEX-Update.json"] = (
                        json.dumps(manifest) + "\n"
                    ).encode("utf-8")
                    (staging / "FOODEX-Update.json").write_bytes(adjusted["FOODEX-Update.json"])

                    with self.assertRaisesRegex(RuntimeError, "dashboard summary"):
                        builder.publish_release_bundle(staging, output_dir)
                    for name, content in adjusted.items():
                        self.assertFalse(os.path.lexists(output_dir / name), name)
                        self.assertEqual((staging / name).read_bytes(), content)
                    self.assertFalse((output_dir / ".FOODEX-Update.publish.lock").exists())

    def test_update_release_group_embedded_version_matches_manifest_target(self) -> None:
        # Entire ZIP/manifest/file-list/SHA256 sidecar groups agree with each
        # other. A different embedded VERSION must still fail before publish.
        versions = (
            ("canonical", b"1.0.127", True),
            ("lf", b"1.0.127\n", True),
            ("crlf", b"1.0.127\r\n", True),
            ("older", b"1.0.126\n", False),
            ("newer", b"1.0.128\n", False),
            ("blank", b"", False),
            ("extra_line", b"1.0.127\n1.0.128", False),
            ("oversized", b"1.0.127" + b" " * 130, False),
        )
        for variant, embedded, allowed in versions:
            with self.subTest(variant=variant):
                with tempfile.TemporaryDirectory() as directory:
                    output_dir = Path(directory)
                    staged, originals = self._staged_release_fixture(output_dir)
                    binary = io.BytesIO()
                    with zipfile.ZipFile(binary, "w", compression=zipfile.ZIP_DEFLATED) as archive:
                        archive.writestr("backend/artisan", b"<?php // reviewed artisan")
                        archive.writestr("VERSION", embedded)
                    zipped = binary.getvalue()
                    digest = hashlib.sha256(zipped).hexdigest()
                    manifest = json.loads(originals["FOODEX-Update.json"])
                    manifest["sha256"] = digest
                    manifest["delta"]["file_count"] = 2
                    manifest["delta"]["source_file_count"] = 2
                    contents = dict(originals)
                    contents["FOODEX-Update.json"] = (json.dumps(manifest) + "\n").encode("utf-8")
                    contents["FOODEX-Update.files.txt"] = b"backend/artisan\nVERSION\n"
                    contents["FOODEX-Update.sha256.txt"] = (
                        f"{digest}  FOODEX-Update.zip\n"
                    ).encode("utf-8")
                    contents["FOODEX-Update.zip"] = zipped
                    for name, body in contents.items():
                        (staged / name).write_bytes(body)

                    if allowed:
                        builder.publish_release_bundle(staged, output_dir)
                        for name, body in contents.items():
                            self.assertEqual((output_dir / name).read_bytes(), body)
                    else:
                        with self.assertRaisesRegex(RuntimeError, "embedded VERSION"):
                            builder.publish_release_bundle(staged, output_dir)
                        for name, body in contents.items():
                            self.assertFalse(os.path.lexists(output_dir / name), name)
                            self.assertEqual((staged / name).read_bytes(), body)
                    self.assertFalse(
                        (output_dir / ".FOODEX-Update.publish.lock").exists()
                    )

    def test_update_release_group_binds_migration_manifest_to_actual_zip_members(self) -> None:
        # An attacker can replace the manifest, ZIP and both sidecars as a
        # coherent group. Only actual migration member names, not file count
        # or the outer ZIP SHA, prove migration/backup manifest honesty.
        migration_a = "backend/database/migrations/2026_01_01_000001_first.php"
        migration_b = "backend/database/migrations/2026_01_01_000002_second.php"
        expected = [migration_a, migration_b]
        cases = (
            ("accurate", True, expected, True),
            ("hidden", False, [], False),
            ("false_flag", False, expected, False),
            ("missing_flag", None, expected, False),
            ("missing_list", True, None, False),
            ("partial_list", True, [migration_a], False),
            ("reversed_list", True, [migration_b, migration_a], False),
            ("foreign_path", True, [migration_a, "backend/database/migrations/foreign.php"], False),
            ("duplicates", True, [migration_a, migration_a, migration_b], False),
            ("numeric_flag", 1, expected, False),
        )
        for variant, flag, migrations, allowed in cases:
            with self.subTest(variant=variant):
                with tempfile.TemporaryDirectory() as directory:
                    output_dir = Path(directory)
                    staging, originals = self._staged_release_fixture(output_dir)
                    binary = io.BytesIO()
                    members = ["backend/artisan", *expected]
                    with zipfile.ZipFile(binary, "w", compression=zipfile.ZIP_DEFLATED) as archive:
                        for member in members:
                            archive.writestr(member, b"<?php // reviewed migration runtime")
                    zipped = binary.getvalue()
                    digest = hashlib.sha256(zipped).hexdigest()
                    manifest = json.loads(originals["FOODEX-Update.json"])
                    manifest["sha256"] = digest
                    manifest["delta"]["file_count"] = len(members)
                    manifest["delta"]["source_file_count"] = len(members)
                    if flag is None:
                        manifest.pop("contains_migrations", None)
                    else:
                        manifest["contains_migrations"] = flag
                    if migrations is None:
                        manifest.pop("migrations", None)
                    else:
                        manifest["migrations"] = migrations
                    modified = dict(originals)
                    modified["FOODEX-Update.json"] = (json.dumps(manifest) + "\n").encode("utf-8")
                    modified["FOODEX-Update.files.txt"] = (
                        "\n".join(members) + "\n"
                    ).encode("utf-8")
                    modified["FOODEX-Update.sha256.txt"] = (
                        f"{digest}  FOODEX-Update.zip\n"
                    ).encode("utf-8")
                    modified["FOODEX-Update.zip"] = zipped
                    for name, data in modified.items():
                        (staging / name).write_bytes(data)

                    if allowed:
                        builder.publish_release_bundle(staging, output_dir)
                        for name, data in modified.items():
                            self.assertEqual((output_dir / name).read_bytes(), data)
                    else:
                        with self.assertRaisesRegex(RuntimeError, "migration inventory mismatch"):
                            builder.publish_release_bundle(staging, output_dir)
                        for name, data in modified.items():
                            self.assertFalse(os.path.lexists(output_dir / name), name)
                            self.assertEqual((staging / name).read_bytes(), data)
                    self.assertFalse(
                        (output_dir / ".FOODEX-Update.publish.lock").exists()
                    )

    def test_update_release_group_rejects_coherent_unsafe_zip_members(self) -> None:
        # All four sidecars are internally consistent (including SHA-256).
        # Publication must independently reject dangerous ZIP contents even
        # if the staged archive and manifest/file list were replaced together.
        variants = (
            ("traversal", "backend/public/assets/../secrets.txt"),
            ("public_executable", "backend/public/assets/admin/shell.php"),
            ("case_alias", "backend/ARTISAN"),
            ("outside_runtime", "backend/private/undisclosed.txt"),
            ("symlink", "backend/app/unsafe-link.txt"),
        )
        for attack, member_name in variants:
            with self.subTest(attack=attack):
                with tempfile.TemporaryDirectory() as temporary:
                    output_dir = Path(temporary)
                    staging, contents = self._staged_release_fixture(output_dir)
                    binary = io.BytesIO()
                    with zipfile.ZipFile(binary, "w", compression=zipfile.ZIP_DEFLATED) as archive:
                        archive.writestr("backend/artisan", b"reviewed-cli")
                        if attack == "symlink":
                            link = zipfile.ZipInfo(member_name)
                            link.create_system = 3
                            link.external_attr = (stat.S_IFLNK | 0o777) << 16
                            archive.writestr(link, b"../../outside")
                        else:
                            archive.writestr(member_name, b"unreviewed-payload")
                    zipped = binary.getvalue()
                    digest = hashlib.sha256(zipped).hexdigest()
                    manifest = json.loads(contents["FOODEX-Update.json"])
                    manifest["sha256"] = digest
                    manifest["delta"]["file_count"] = 2
                    manifest["delta"]["source_file_count"] = 2
                    contents["FOODEX-Update.json"] = (
                        json.dumps(manifest) + "\n"
                    ).encode("utf-8")
                    contents["FOODEX-Update.files.txt"] = (
                        "backend/artisan\n" + member_name + "\n"
                    ).encode("utf-8")
                    contents["FOODEX-Update.sha256.txt"] = (
                        f"{digest}  FOODEX-Update.zip\n"
                    ).encode("utf-8")
                    contents["FOODEX-Update.zip"] = zipped
                    for name, body in contents.items():
                        (staging / name).write_bytes(body)

                    with self.assertRaisesRegex(
                        RuntimeError,
                        "Unsafe|Non-runtime|Ambiguous|Executable",
                    ):
                        builder.publish_release_bundle(staging, output_dir)
                    for name, body in contents.items():
                        self.assertFalse(os.path.lexists(output_dir / name), name)
                        self.assertEqual((staging / name).read_bytes(), body)
                    self.assertFalse(
                        (output_dir / ".FOODEX-Update.publish.lock").exists()
                    )

    def test_update_release_group_requires_portable_zip_compression(self) -> None:
        # ZIP members and SHA/manifest/sidecars agree, but Python accepts
        # compression methods that shared-host PHP ZipArchive cannot reliably
        # extract. Reject before the ZIP-last publish marker is created.
        cases = (
            ("stored", zipfile.ZIP_STORED, True),
            ("deflated", zipfile.ZIP_DEFLATED, True),
            ("bzip2", zipfile.ZIP_BZIP2, False),
            ("lzma", zipfile.ZIP_LZMA, False),
        )
        for method, compression, allowed in cases:
            with self.subTest(method=method):
                with tempfile.TemporaryDirectory() as temporary:
                    output_dir = Path(temporary)
                    staging, contents = self._staged_release_fixture(output_dir)
                    binary = io.BytesIO()
                    with zipfile.ZipFile(binary, "w", compression=compression) as archive:
                        archive.writestr("backend/artisan", b"<?php // reviewed updater runtime")
                    zipped = binary.getvalue()
                    digest = hashlib.sha256(zipped).hexdigest()
                    manifest = json.loads(contents["FOODEX-Update.json"])
                    manifest["sha256"] = digest
                    contents["FOODEX-Update.json"] = (
                        json.dumps(manifest) + "\n"
                    ).encode("utf-8")
                    contents["FOODEX-Update.sha256.txt"] = (
                        f"{digest}  FOODEX-Update.zip\n"
                    ).encode("utf-8")
                    contents["FOODEX-Update.zip"] = zipped
                    for name, content in contents.items():
                        (staging / name).write_bytes(content)

                    if allowed:
                        builder.publish_release_bundle(staging, output_dir)
                        for name, content in contents.items():
                            self.assertEqual((output_dir / name).read_bytes(), content)
                    else:
                        with self.assertRaisesRegex(RuntimeError, "Unsupported.*compression"):
                            builder.publish_release_bundle(staging, output_dir)
                        for name, content in contents.items():
                            self.assertFalse(os.path.lexists(output_dir / name), name)
                            self.assertEqual((staging / name).read_bytes(), content)
                    self.assertFalse(
                        (output_dir / ".FOODEX-Update.publish.lock").exists()
                    )

    def test_update_release_group_rejects_unlisted_zip_metadata_before_publish(self) -> None:
        # A rehashed ZIP + coherent manifest/SHA-256 sidecars can carry
        # unlisted payload in archive comments, member comments, or extra
        # fields. The first-party builder emits none; reject at ZIP-last
        # publication while retaining an ordinary canonical ZIP positive.
        variants = ("clean", "archive_comment", "member_comment", "member_extra")
        for variant in variants:
            with self.subTest(variant=variant):
                with tempfile.TemporaryDirectory() as temporary:
                    output_dir = Path(temporary)
                    staging, contents = self._staged_release_fixture(output_dir)
                    binary = io.BytesIO()
                    with zipfile.ZipFile(binary, "w", compression=zipfile.ZIP_DEFLATED) as archive:
                        member = zipfile.ZipInfo("backend/artisan")
                        member.compress_type = zipfile.ZIP_DEFLATED
                        if variant == "member_comment":
                            member.comment = b"undeclared release metadata"
                        if variant == "member_extra":
                            # One structurally valid vendor extension field.
                            member.extra = b"\xfe\xca\x04\x00HIDE"
                        archive.writestr(member, b"<?php // staged reviewed Laravel CLI")
                        if variant == "archive_comment":
                            archive.comment = b"undeclared release metadata"

                    zipped = binary.getvalue()
                    digest = hashlib.sha256(zipped).hexdigest()
                    manifest = json.loads(contents["FOODEX-Update.json"])
                    manifest["sha256"] = digest
                    contents["FOODEX-Update.json"] = (
                        json.dumps(manifest) + "\n"
                    ).encode("utf-8")
                    contents["FOODEX-Update.sha256.txt"] = (
                        f"{digest}  FOODEX-Update.zip\n"
                    ).encode("utf-8")
                    contents["FOODEX-Update.zip"] = zipped
                    for name, payload in contents.items():
                        (staging / name).write_bytes(payload)

                    if variant == "clean":
                        builder.publish_release_bundle(staging, output_dir)
                        for name, payload in contents.items():
                            self.assertEqual((output_dir / name).read_bytes(), payload)
                    else:
                        with self.assertRaisesRegex(RuntimeError, "Undeclared Update release ZIP"):
                            builder.publish_release_bundle(staging, output_dir)
                        for name, payload in contents.items():
                            self.assertFalse(os.path.lexists(output_dir / name), name)
                            self.assertEqual((staging / name).read_bytes(), payload)
                    self.assertFalse(
                        (output_dir / ".FOODEX-Update.publish.lock").exists()
                    )

    def test_update_release_group_rejects_unlisted_local_zip_bytes(self) -> None:
        # Rehash every altered ZIP and its manifest/sidecars. Both local-only
        # extra data and a central-directory gap are readable by Python while
        # the central directory lists no extra fields or additional members.
        for variant in ("canonical", "local_only_extra", "central_directory_gap"):
            with self.subTest(variant=variant):
                with tempfile.TemporaryDirectory() as directory:
                    output_dir = Path(directory)
                    staging, contents = self._staged_release_fixture(output_dir)
                    physical = bytearray(contents["FOODEX-Update.zip"])
                    original_central_offset = int.from_bytes(physical[-6:-2], "little")
                    injected = b""
                    if variant == "local_only_extra":
                        injected = b"\xfe\xca\x04\x00HIDE"
                        local_filename_length = int.from_bytes(physical[26:28], "little")
                        insertion_at = 30 + local_filename_length
                        physical[insertion_at:insertion_at] = injected
                        physical[28:30] = len(injected).to_bytes(2, "little")
                    elif variant == "central_directory_gap":
                        injected = b"UNLISTED-CENTRAL-DIRECTORY-GAP"
                        physical[original_central_offset:original_central_offset] = injected

                    if injected:
                        eocd_offset = len(physical) - 22
                        physical[eocd_offset + 16:eocd_offset + 20] = (
                            original_central_offset + len(injected)
                        ).to_bytes(4, "little")
                    changed_archive = bytes(physical)
                    with zipfile.ZipFile(io.BytesIO(changed_archive)) as archive:
                        self.assertEqual(archive.namelist(), ["backend/artisan"])
                        self.assertEqual(
                            archive.read("backend/artisan"),
                            b"<?php // staged reviewed Laravel CLI",
                        )
                        self.assertEqual(archive.infolist()[0].extra, b"")

                    digest = hashlib.sha256(changed_archive).hexdigest()
                    manifest = json.loads(contents["FOODEX-Update.json"])
                    manifest["sha256"] = digest
                    contents["FOODEX-Update.json"] = (
                        json.dumps(manifest) + "\n"
                    ).encode("utf-8")
                    contents["FOODEX-Update.sha256.txt"] = (
                        f"{digest}  FOODEX-Update.zip\n"
                    ).encode("utf-8")
                    contents["FOODEX-Update.zip"] = changed_archive
                    for name, value in contents.items():
                        (staging / name).write_bytes(value)

                    if variant == "canonical":
                        builder.publish_release_bundle(staging, output_dir)
                        for name, value in contents.items():
                            self.assertEqual((output_dir / name).read_bytes(), value)
                    else:
                        with self.assertRaisesRegex(RuntimeError, "ZIP interior payload"):
                            builder.publish_release_bundle(staging, output_dir)
                        for name, value in contents.items():
                            self.assertFalse(os.path.lexists(output_dir / name), name)
                            self.assertEqual((staging / name).read_bytes(), value)
                    self.assertFalse(
                        (output_dir / ".FOODEX-Update.publish.lock").exists()
                    )

    def test_update_release_group_refuses_undeclared_zip_prefix(self) -> None:
        # zipfile deliberately supports ZIPs with an executable/data prefix,
        # adjusting member offsets so all declared members still read. The
        # trusted first-party ZIP builder emits no such unlisted bytes.
        for variant in ("canonical", "hidden_prefix"):
            with self.subTest(variant=variant):
                with tempfile.TemporaryDirectory() as temporary:
                    output_dir = Path(temporary)
                    staging, contents = self._staged_release_fixture(output_dir)
                    if variant == "hidden_prefix":
                        payload = b"UNDECLARED-PRIVATE-PREFIX\x00"
                        archive_bytes = payload + contents["FOODEX-Update.zip"]
                        # Confirm this is a readable ZIP with the same
                        # declared contents, not simply a corrupt archive.
                        with zipfile.ZipFile(io.BytesIO(archive_bytes)) as archive:
                            self.assertEqual(archive.namelist(), ["backend/artisan"])
                            self.assertEqual(
                                archive.read("backend/artisan"),
                                b"<?php // staged reviewed Laravel CLI",
                            )
                            self.assertEqual(
                                archive.infolist()[0].header_offset, len(payload)
                            )
                        digest = hashlib.sha256(archive_bytes).hexdigest()
                        manifest = json.loads(contents["FOODEX-Update.json"])
                        manifest["sha256"] = digest
                        contents["FOODEX-Update.zip"] = archive_bytes
                        contents["FOODEX-Update.json"] = (
                            json.dumps(manifest) + "\n"
                        ).encode("utf-8")
                        contents["FOODEX-Update.sha256.txt"] = (
                            f"{digest}  FOODEX-Update.zip\n"
                        ).encode("utf-8")
                        for name, value in contents.items():
                            (staging / name).write_bytes(value)

                    if variant == "canonical":
                        builder.publish_release_bundle(staging, output_dir)
                        for name, value in contents.items():
                            self.assertEqual((output_dir / name).read_bytes(), value)
                    else:
                        with self.assertRaisesRegex(RuntimeError, "ZIP leading payload"):
                            builder.publish_release_bundle(staging, output_dir)
                        for name, value in contents.items():
                            self.assertFalse(os.path.lexists(output_dir / name), name)
                            self.assertEqual((staging / name).read_bytes(), value)
                    self.assertFalse(
                        (output_dir / ".FOODEX-Update.publish.lock").exists()
                    )

    def test_update_release_group_rejects_undeclared_zip_trailing_payload(self) -> None:
        # Python's archive reader ignores trailing bytes after EOCD even if
        # every member reads correctly and the ZIP + sidecars are coherently
        # rehashed. Only the canonical, comment-free producer envelope is
        # allowed at the ZIP-last publication boundary.
        for variant, suffix in (
            ("canonical", b""),
            ("single_byte", b"\x00"),
            ("opaque_trailer", b"UNLISTED-ARCHIVE-TRAILER\x00"),
        ):
            with self.subTest(variant=variant):
                with tempfile.TemporaryDirectory() as temporary:
                    output_dir = Path(temporary)
                    staging, contents = self._staged_release_fixture(output_dir)
                    archive_bytes = contents["FOODEX-Update.zip"] + suffix
                    with zipfile.ZipFile(io.BytesIO(archive_bytes)) as archive:
                        self.assertEqual(archive.namelist(), ["backend/artisan"])
                        self.assertEqual(
                            archive.read("backend/artisan"),
                            b"<?php // staged reviewed Laravel CLI",
                        )
                        self.assertEqual(archive.comment, b"")
                        self.assertEqual(archive.infolist()[0].header_offset, 0)

                    digest = hashlib.sha256(archive_bytes).hexdigest()
                    manifest = json.loads(contents["FOODEX-Update.json"])
                    manifest["sha256"] = digest
                    contents["FOODEX-Update.zip"] = archive_bytes
                    contents["FOODEX-Update.json"] = (
                        json.dumps(manifest) + "\n"
                    ).encode("utf-8")
                    contents["FOODEX-Update.sha256.txt"] = (
                        f"{digest}  FOODEX-Update.zip\n"
                    ).encode("utf-8")
                    for name, value in contents.items():
                        (staging / name).write_bytes(value)

                    if not suffix:
                        builder.publish_release_bundle(staging, output_dir)
                        for name, value in contents.items():
                            self.assertEqual((output_dir / name).read_bytes(), value)
                    else:
                        with self.assertRaisesRegex(RuntimeError, "ZIP trailing payload"):
                            builder.publish_release_bundle(staging, output_dir)
                        for name, value in contents.items():
                            self.assertFalse(os.path.lexists(output_dir / name), name)
                            self.assertEqual((staging / name).read_bytes(), value)
                    self.assertFalse(
                        (output_dir / ".FOODEX-Update.publish.lock").exists()
                    )

    def test_update_release_group_rejects_file_directory_collisions_before_publish(self) -> None:
        # zipfile can read both members of an impossible on-disk file tree,
        # but Laravel Verify Package correctly rejects their parent alias.
        # Coherent ZIP, manifest, file inventory and digest are NOT enough.
        for names in (
            ("backend/app/Module.php", "backend/app/Module.php/child.php"),
            ("backend/app/Module.php/child.php", "backend/app/Module.php"),
            ("backend/app/Module.php", "backend/app/module.php/child.php"),
        ):
            with self.subTest(names=names):
                with tempfile.TemporaryDirectory() as temporary:
                    output_dir = Path(temporary)
                    staging, contents = self._staged_release_fixture(output_dir)
                    binary = io.BytesIO()
                    with zipfile.ZipFile(binary, "w", compression=zipfile.ZIP_DEFLATED) as archive:
                        for name in names:
                            archive.writestr(name, b"<?php // valid individual file")
                    zipped = binary.getvalue()
                    with zipfile.ZipFile(io.BytesIO(zipped)) as archive:
                        self.assertEqual(archive.namelist(), list(names))
                        for name in names:
                            self.assertEqual(archive.read(name), b"<?php // valid individual file")

                    digest = hashlib.sha256(zipped).hexdigest()
                    manifest = json.loads(contents["FOODEX-Update.json"])
                    manifest["sha256"] = digest
                    manifest["delta"]["file_count"] = 2
                    manifest["delta"]["source_file_count"] = 2
                    contents["FOODEX-Update.json"] = (json.dumps(manifest) + "\n").encode("utf-8")
                    contents["FOODEX-Update.files.txt"] = ("\n".join(names) + "\n").encode("utf-8")
                    contents["FOODEX-Update.sha256.txt"] = (
                        f"{digest}  FOODEX-Update.zip\n"
                    ).encode("utf-8")
                    contents["FOODEX-Update.zip"] = zipped
                    for name, data in contents.items():
                        (staging / name).write_bytes(data)

                    with self.assertRaisesRegex(RuntimeError, "file/directory collision"):
                        builder.publish_release_bundle(staging, output_dir)
                    for name, data in contents.items():
                        self.assertFalse(os.path.lexists(output_dir / name), name)
                        self.assertEqual((staging / name).read_bytes(), data)
                    self.assertFalse((output_dir / ".FOODEX-Update.publish.lock").exists())

    def test_update_zip_builder_rejects_file_parent_alias_before_source_scan(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            output = root / "out" / "update.zip"
            entries = [
                ("backend/app/File.php", "backend/app/File.php"),
                ("backend/app/file.php/child.php", "backend/app/file.php/child.php"),
            ]
            with mock.patch.object(builder, "trusted_source_file") as trusted:
                with mock.patch.object(builder.tempfile, "mkstemp") as staged:
                    with self.assertRaisesRegex(RuntimeError, "file/directory collision"):
                        builder.build_zip(root, entries, output)
                    trusted.assert_not_called()
                    staged.assert_not_called()
            self.assertFalse(output.exists())

        # Legitimate sibling files remain eligible; canonical releases
        # are still published by test_complete_update_release_publishes_zip_last.
        builder.assert_no_file_directory_collisions([
            "backend/app/File.php",
            "backend/app/File.php.bak",
            "backend/app/Folder/child.php",
        ])

    def test_update_release_group_enforces_runtime_expansion_budgets_before_publish(self) -> None:
        # Real Laravel Verify Package defaults reject >50k ZIP entries, >256 MiB
        # per entry or >1 GiB cumulative expanded data. A coherent ZIP and
        # SHA/manifest must fail BEFORE being advertised as installable.
        # Tiny patched budgets exercise every limit without a decompression bomb.
        variants = (
            ("too_many_members", "MAX_UPDATE_ZIP_MEMBERS", 1),
            ("oversized_member", "MAX_UPDATE_ENTRY_UNCOMPRESSED_BYTES", 20),
            ("oversized_aggregate", "MAX_UPDATE_TOTAL_UNCOMPRESSED_BYTES", 45),
        )
        for attack, limit_name, limit in variants:
            with self.subTest(attack=attack):
                with tempfile.TemporaryDirectory() as temporary:
                    output_dir = Path(temporary)
                    staging, contents = self._staged_release_fixture(output_dir)
                    items = (
                        ("backend/artisan", b"<?php // staged reviewed Laravel CLI"),
                        ("backend/config/foodex.php", b"<?php return [];"),
                    )
                    archive_bytes = io.BytesIO()
                    with zipfile.ZipFile(archive_bytes, "w", compression=zipfile.ZIP_DEFLATED) as archive:
                        for name, payload in items:
                            archive.writestr(name, payload)
                    zipped = archive_bytes.getvalue()
                    digest = hashlib.sha256(zipped).hexdigest()
                    manifest = json.loads(contents["FOODEX-Update.json"])
                    manifest["sha256"] = digest
                    manifest["delta"]["file_count"] = len(items)
                    manifest["delta"]["source_file_count"] = len(items)
                    contents["FOODEX-Update.json"] = (json.dumps(manifest) + "\n").encode("utf-8")
                    contents["FOODEX-Update.files.txt"] = (
                        "\n".join(name for name, _ in items) + "\n"
                    ).encode("utf-8")
                    contents["FOODEX-Update.sha256.txt"] = (
                        f"{digest}  FOODEX-Update.zip\n"
                    ).encode("utf-8")
                    contents["FOODEX-Update.zip"] = zipped
                    for name, data in contents.items():
                        (staging / name).write_bytes(data)

                    with mock.patch.object(builder, limit_name, limit):
                        with self.assertRaisesRegex(RuntimeError, "runtime extraction budget"):
                            builder.publish_release_bundle(staging, output_dir)
                    for name, data in contents.items():
                        self.assertFalse(os.path.lexists(output_dir / name), name)
                        self.assertEqual((staging / name).read_bytes(), data)
                    self.assertFalse((output_dir / ".FOODEX-Update.publish.lock").exists())

    def test_update_release_group_rejects_coherent_corrupt_nonmirror_payload(self) -> None:
        # Manifest, SHA256 sidecar and filename list all match the modified
        # archive. Only its internal stored entry bytes are corrupt: the
        # central-directory CRC still represents the original payload.
        with tempfile.TemporaryDirectory() as directory:
            output_dir = Path(directory)
            staging, contents = self._staged_release_fixture(output_dir)
            binary = io.BytesIO()
            original_payload = b"reviewed-artisan-runtime"
            with zipfile.ZipFile(binary, "w", compression=zipfile.ZIP_STORED) as archive:
                archive.writestr("backend/artisan", original_payload)
            archive_bytes = bytearray(binary.getvalue())
            payload_offset = archive_bytes.index(original_payload)
            archive_bytes[payload_offset] ^= 1
            corrupted_zip = bytes(archive_bytes)
            digest = hashlib.sha256(corrupted_zip).hexdigest()

            manifest = json.loads(contents["FOODEX-Update.json"])
            manifest["sha256"] = digest
            contents["FOODEX-Update.json"] = (json.dumps(manifest) + "\n").encode("utf-8")
            contents["FOODEX-Update.sha256.txt"] = (
                f"{digest}  FOODEX-Update.zip\n"
            ).encode("utf-8")
            contents["FOODEX-Update.zip"] = corrupted_zip
            for name, body in contents.items():
                (staging / name).write_bytes(body)

            with self.assertRaisesRegex(RuntimeError, "consistency check failed"):
                builder.publish_release_bundle(staging, output_dir)
            for name, body in contents.items():
                self.assertFalse(os.path.lexists(output_dir / name), name)
                self.assertEqual((staging / name).read_bytes(), body)
            self.assertFalse((output_dir / ".FOODEX-Update.publish.lock").exists())

    def test_update_release_group_requires_byte_identical_public_mirrors(self) -> None:
        # A substituted ZIP can carry matching sidecars and a valid SHA-256,
        # yet omit the canonical Laravel asset or alter the root alias.
        variants = (
            ("missing_alias", (("backend/public/assets/admin/status.css", b"safe-css"),), False),
            ("missing_source", (("assets/admin/status.css", b"root-only-css"),), False),
            ("different_content", (
                ("backend/public/assets/admin/status.css", b"safe-css"),
                ("assets/admin/status.css", b"evil-css"),
            ), False),
            ("same_size_different_content", (
                ("backend/public/assets/admin/status.css", b"safe-css"),
                ("assets/admin/status.css", b"evil-css"),
            ), False),
            ("matching_pair", (
                ("backend/public/assets/admin/status.css", b"safe-css"),
                ("assets/admin/status.css", b"safe-css"),
            ), True),
            ("wrong_source_count", (
                ("backend/public/assets/admin/status.css", b"safe-css"),
                ("assets/admin/status.css", b"safe-css"),
            ), False),
        )
        for attack, assets, valid in variants:
            with self.subTest(attack=attack):
                with tempfile.TemporaryDirectory() as directory:
                    output_dir = Path(directory)
                    staging, contents = self._staged_release_fixture(output_dir)
                    binary = io.BytesIO()
                    with zipfile.ZipFile(binary, "w", compression=zipfile.ZIP_DEFLATED) as archive:
                        archive.writestr("backend/artisan", b"reviewed-cli")
                        for name, payload in assets:
                            archive.writestr(name, payload)
                    zipped = binary.getvalue()
                    digest = hashlib.sha256(zipped).hexdigest()
                    manifest = json.loads(contents["FOODEX-Update.json"])
                    manifest["sha256"] = digest
                    manifest["delta"]["file_count"] = 1 + len(assets)
                    manifest["delta"]["source_file_count"] = 1 + sum(
                        name.startswith("backend/public/") for name, _ in assets
                    )
                    if attack == "wrong_source_count":
                        # Internally coherent archive and checksum with an
                        # in-range but dishonest source inventory.
                        manifest["delta"]["source_file_count"] = 1
                    contents["FOODEX-Update.json"] = (json.dumps(manifest) + "\n").encode("utf-8")
                    contents["FOODEX-Update.files.txt"] = (
                        "backend/artisan\n" + "".join(name + "\n" for name, _ in assets)
                    ).encode("utf-8")
                    contents["FOODEX-Update.sha256.txt"] = (
                        f"{digest}  FOODEX-Update.zip\n"
                    ).encode("utf-8")
                    contents["FOODEX-Update.zip"] = zipped
                    for name, body in contents.items():
                        (staging / name).write_bytes(body)

                    if valid:
                        builder.publish_release_bundle(staging, output_dir)
                        for name, body in contents.items():
                            self.assertEqual((output_dir / name).read_bytes(), body)
                    else:
                        with self.assertRaisesRegex(RuntimeError, "public mirror|source file count"):
                            builder.publish_release_bundle(staging, output_dir)
                        for name, body in contents.items():
                            self.assertFalse(os.path.lexists(output_dir / name), name)
                            self.assertEqual((staging / name).read_bytes(), body)
                    self.assertFalse((output_dir / ".FOODEX-Update.publish.lock").exists())

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

    def test_complete_update_release_rejects_prelink_staging_replacement(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            output_dir = Path(directory)
            staging, contents = self._staged_release_fixture(output_dir)
            target = staging / "FOODEX-Update.files.txt"
            original_reject = builder.reject_existing_release_outputs

            def swap_after_initial_scan(paths: list[Path]) -> None:
                original_reject(paths)
                target.rename(target.with_suffix(".reviewed"))
                target.write_bytes(b"ATTACKER-SWAPPED-FILE-LIST")

            with mock.patch.object(
                builder, "reject_existing_release_outputs", side_effect=swap_after_initial_scan
            ):
                with self.assertRaisesRegex(RuntimeError, "changed during publication"):
                    builder.publish_release_bundle(staging, output_dir)

            for name in contents:
                self.assertFalse(os.path.lexists(output_dir / name), name)
            self.assertEqual(
                contents["FOODEX-Update.files.txt"],
                target.with_suffix(".reviewed").read_bytes(),
            )
            self.assertFalse((output_dir / ".FOODEX-Update.publish.lock").exists())

    def test_complete_update_release_retracts_postcheck_stage_swaps(self) -> None:
        for attacked in ("FOODEX-Update.json", "FOODEX-Update.zip"):
            with self.subTest(attacked=attacked):
                with tempfile.TemporaryDirectory() as directory:
                    output_dir = Path(directory)
                    staging, contents = self._staged_release_fixture(output_dir)
                    real_link = os.link
                    attack_seen = False

                    def swap_inside_link(source: Path, destination: Path) -> None:
                        nonlocal attack_seen
                        if destination.name == attacked:
                            attack_seen = True
                            source.rename(source.with_suffix(".reviewed"))
                            source.write_bytes(b"ATTACKER-SWAPPED-PAYLOAD")
                        real_link(source, destination)

                    with mock.patch.object(builder.os, "link", side_effect=swap_inside_link):
                        with self.assertRaisesRegex(RuntimeError, "changed during publication"):
                            builder.publish_release_bundle(staging, output_dir)

                    self.assertTrue(attack_seen)
                    for name in contents:
                        self.assertFalse(os.path.lexists(output_dir / name), name)
                    self.assertEqual(
                        contents[attacked],
                        (staging / attacked).with_suffix(".reviewed").read_bytes(),
                    )
                    self.assertFalse((output_dir / ".FOODEX-Update.publish.lock").exists())

    def test_complete_update_release_retracts_inplace_mutation_during_link(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            output_dir = Path(directory)
            staging, contents = self._staged_release_fixture(output_dir)
            real_link = os.link

            def rewrite_before_postcheck(source: Path, destination: Path) -> None:
                real_link(source, destination)
                if destination.name == "FOODEX-Update.zip":
                    source.write_bytes(b"INPLACE-UNREVIEWED-DATA")

            with mock.patch.object(builder.os, "link", side_effect=rewrite_before_postcheck):
                with self.assertRaisesRegex(RuntimeError, "changed during publication"):
                    builder.publish_release_bundle(staging, output_dir)

            for name in contents:
                self.assertFalse(os.path.lexists(output_dir / name), name)
            self.assertFalse((output_dir / ".FOODEX-Update.publish.lock").exists())

    def test_release_group_retracts_same_size_restored_mtime_postlink_tampering(self) -> None:
        # An os.link call updates ctime. Once the file is linked, changing
        # the bytes and restoring original mtime/size passes metadata-only
        # postchecks; it must fail the final reviewed-content digest instead.
        for altered in ("FOODEX-Update.zip", "FOODEX-Update.files.txt"):
            with self.subTest(altered=altered):
                with tempfile.TemporaryDirectory() as directory:
                    output_dir = Path(directory)
                    staging, originals = self._staged_release_fixture(output_dir)
                    real_link = os.link
                    changed = None

                    def mutate_on_final_link(source: Path, destination: Path) -> None:
                        nonlocal changed
                        real_link(source, destination)
                        if destination.name == "FOODEX-Update.zip":
                            target = staging / altered
                            original_stat = target.stat()
                            current = target.read_bytes()
                            changed = bytes([current[0] ^ 1]) + current[1:]
                            target.write_bytes(changed)
                            os.utime(
                                target, ns=(original_stat.st_atime_ns, original_stat.st_mtime_ns)
                            )

                    with mock.patch.object(builder.os, "link", side_effect=mutate_on_final_link):
                        with self.assertRaisesRegex(RuntimeError, "contents changed"):
                            builder.publish_release_bundle(staging, output_dir)

                    self.assertIsNotNone(changed)
                    self.assertEqual((staging / altered).read_bytes(), changed)
                    self.assertEqual((staging / altered).stat().st_size, len(originals[altered]))
                    for name in originals:
                        self.assertFalse(os.path.lexists(output_dir / name), name)
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

    def test_complete_update_release_rejects_linked_output_and_parent_aliases(self) -> None:
        # A canonical staging leaf must not make a symlinked *destination*
        # safe. Without this fence the four release hardlinks can be written
        # into the alias target instead of the selected output directory.
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            real_parent = root / "real-parent"
            output_dir = real_parent / "releases"
            output_dir.mkdir(parents=True)
            staging, original = self._staged_release_fixture(output_dir)
            aliased_output = root / "linked-output"
            aliased_parent = root / "linked-parent"
            try:
                aliased_output.symlink_to(output_dir, target_is_directory=True)
                aliased_parent.symlink_to(real_parent, target_is_directory=True)
            except (OSError, NotImplementedError):
                self.skipTest("Directory symlinks unavailable")

            for candidate in (aliased_output, aliased_parent / "releases"):
                with self.subTest(destination=str(candidate)):
                    with self.assertRaisesRegex(RuntimeError, "Unsafe Update release output directory"):
                        builder.publish_release_bundle(staging, candidate)
                    for name in original:
                        self.assertFalse(os.path.lexists(output_dir / name), name)
                    self.assertFalse((output_dir / ".FOODEX-Update.publish.lock").exists())

            # A linked *staging parent* is also unsafe even if the staging
            # leaf itself is a normal directory and the output is canonical.
            with self.assertRaisesRegex(RuntimeError, "Unsafe Update release staging directory"):
                builder.publish_release_bundle(
                    aliased_parent / "releases" / "private-build", output_dir
                )
            self.assertFalse((output_dir / ".FOODEX-Update.publish.lock").exists())
            for name in original:
                self.assertFalse(os.path.lexists(output_dir / name), name)

            # Normal canonical release publication must remain functional.
            builder.publish_release_bundle(staging, output_dir)
            for name, content in original.items():
                self.assertEqual((output_dir / name).read_bytes(), content)
            self.assertFalse((output_dir / ".FOODEX-Update.publish.lock").exists())

    def test_update_zip_builder_enforces_expansion_limits_before_staging(self) -> None:
        # ZIP-last publication is too late to guard a hosted build worker:
        # refuse excessive selected files before mkstemp/compression. Small
        # real sources exercise member-count, entry and aggregate limits.
        # The existing canonical builder must still accept exact boundaries.
        cases = (
            ("member_count", 1, 20, 40, False),
            ("single_entry", 2, 19, 40, False),
            ("expanded_total", 2, 20, 39, False),
            ("at_exact_budget", 2, 20, 40, True),
        )
        for name, count, entry_size, total_size, allowed in cases:
            with self.subTest(name=name):
                with tempfile.TemporaryDirectory() as temporary:
                    root = Path(temporary) / "source"
                    first = root / "backend/artisan"
                    second = root / "backend/config/foodex.php"
                    for candidate, value in ((first, b"A" * 20), (second, b"B" * 20)):
                        candidate.parent.mkdir(parents=True, exist_ok=True)
                        candidate.write_bytes(value)
                    output = root / "dist" / "FOODEX-Update.zip"
                    entries = [
                        ("backend/artisan", "backend/artisan"),
                        ("backend/config/foodex.php", "backend/config/foodex.php"),
                    ]
                    with mock.patch.object(builder, "MAX_UPDATE_ZIP_MEMBERS", count), \
                         mock.patch.object(builder, "MAX_UPDATE_ENTRY_UNCOMPRESSED_BYTES", entry_size), \
                         mock.patch.object(builder, "MAX_UPDATE_TOTAL_UNCOMPRESSED_BYTES", total_size):
                        if allowed:
                            builder.build_zip(root, entries, output)
                            with zipfile.ZipFile(output) as archive:
                                self.assertEqual(archive.namelist(), [name for name, _ in entries])
                                self.assertEqual(archive.read("backend/artisan"), b"A" * 20)
                                self.assertEqual(archive.read("backend/config/foodex.php"), b"B" * 20)
                        else:
                            with mock.patch.object(builder.tempfile, "mkstemp") as staging:
                                with self.assertRaisesRegex(RuntimeError, "runtime extraction budget"):
                                    builder.build_zip(root, entries, output)
                                staging.assert_not_called()
                            self.assertFalse(os.path.lexists(output))
                            self.assertFalse(output.parent.exists())
                    self.assertEqual(first.read_bytes(), b"A" * 20)
                    self.assertEqual(second.read_bytes(), b"B" * 20)

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

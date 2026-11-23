"""Executable G004 build-time Setup ZIP signed provenance tests.

Only disposable Ed25519 keys are generated here. No release signing secret is
committed to the repository, passed in CLI arguments or embedded in ZIP data.
"""
from __future__ import annotations

import hashlib
import json
import os
import shutil
import stat
import subprocess
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path

from test_v2_setup_staging_verify import BASE_ENTRIES

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "v2_setup_signature.php"
SHA = "a" * 40


@unittest.skipUnless(shutil.which("php"), "PHP with sodium is required for Setup signing tests")
class SetupEd25519SignatureTest(unittest.TestCase):
    def setUp(self) -> None:
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.folder = Path(tmp.name)
        self.archive = self.folder / "FOODEX-Setup-v1.0.125.zip"
        self.version = self.folder / "VERSION"
        self.version.write_text("1.0.125\n", encoding="utf-8")
        self.sidecar = self.folder / "FOODEX-Setup-v1.0.125.sig.json"
        self.source_sha = SHA
        with zipfile.ZipFile(self.archive, "w") as output:
            for name, data in BASE_ENTRIES.items():
                info = zipfile.ZipInfo(name)
                info.external_attr = (stat.S_IFREG | 0o644) << 16
                output.writestr(info, data)
        generated = subprocess.run(
            [
                "php", "-r",
                '$pair=sodium_crypto_sign_keypair();echo json_encode(['
                '"private"=>base64_encode(sodium_crypto_sign_secretkey($pair)),'
                '"public"=>base64_encode(sodium_crypto_sign_publickey($pair))]);',
            ],
            text=True, capture_output=True,
        )
        if generated.returncode != 0:
            self.skipTest("PHP sodium unavailable")
        self.key = json.loads(generated.stdout)
        self.env = {
            **os.environ,
            "FOODEX_SETUP_SIGNING_KEY_ID": "ci-test-key",
            "FOODEX_SETUP_SIGNING_SECRET_KEY_B64": self.key["private"],
            "FOODEX_SETUP_TRUSTED_PUBLIC_KEYS_JSON": json.dumps(
                {"ci-test-key": self.key["public"]}
            ),
            "FOODEX_SETUP_VERIFIER_PYTHON": sys.executable,
        }

    def run_sign(self, output_override: str | None = None, **env_overrides: str) -> subprocess.CompletedProcess:
        return subprocess.run(
            [
                "php", str(SCRIPT), "--mode=sign",
                "--package=" + str(self.archive),
                "--version-file=" + str(self.version),
                "--source-commit=" + self.source_sha,
                "--output=" + (output_override if output_override is not None else str(self.sidecar)),
            ],
            text=True, capture_output=True,
            env={**self.env, **env_overrides},
        )

    def run_verify(self, **env_overrides: str) -> subprocess.CompletedProcess:
        return subprocess.run(
            [
                "php", str(SCRIPT), "--mode=verify",
                "--package=" + str(self.archive),
                "--sidecar=" + str(self.sidecar),
            ],
            text=True, capture_output=True,
            env={**self.env, **env_overrides},
        )

    def test_exact_ci_generated_setup_archive_signs_without_publishing_key(self) -> None:
        # CI acceptance uses the REAL ZIP just assembled from the PR HEAD.
        # Its disposable test signature is not a trusted release key or
        # downloadable release signature artifact.
        real_zip = os.environ.get("FOODEX_SETUP_STAGING_ZIP_PATH")
        exact_sha = os.environ.get("FOODEX_SETUP_SOURCE_SHA")
        if not real_zip or not exact_sha:
            self.skipTest("Only the Linux G004 ZIP staging job has actual ZIP inputs.")
        self.archive = Path(real_zip).resolve(strict=True)
        self.version = ROOT / "VERSION"
        self.source_sha = exact_sha
        self.assertEqual(40, len(exact_sha))
        signed = self.run_sign()
        self.assertEqual(0, signed.returncode, signed.stderr)
        self.assertEqual(0, self.run_verify().returncode)
        self.assertEqual(
            hashlib.sha256(self.archive.read_bytes()).hexdigest(),
            json.loads(self.sidecar.read_text(encoding="utf-8"))["archive_sha256"],
        )

    def test_exact_verified_archive_is_signed_and_trusted_by_public_key(self) -> None:
        signed = self.run_sign()
        self.assertEqual(0, signed.returncode, signed.stderr)
        self.assertTrue(self.sidecar.is_file())
        envelope = json.loads(self.sidecar.read_text(encoding="utf-8"))
        self.assertEqual("foodex-signed-setup-v1", envelope["format"])
        self.assertEqual("1.0.125", envelope["version"])
        self.assertEqual(SHA, envelope["source_commit"])
        self.assertEqual(hashlib.sha256(self.archive.read_bytes()).hexdigest(), envelope["archive_sha256"])
        verified = self.run_verify()
        self.assertEqual(0, verified.returncode, verified.stderr)

    def test_noncanonical_sidecar_and_duplicate_json_keys_fail_closed(self) -> None:
        self.assertEqual(0, self.run_sign().returncode)
        canonical = self.sidecar.read_text(encoding="utf-8")
        self.assertEqual(0, self.run_verify().returncode)

        # PHP json_decode normally keeps the last duplicate JSON property.
        # A second parser may instead keep the first, so reject the bytes
        # even when the decoded last value and signature are valid.
        duplicate = canonical.replace(
            '"archive_sha256":',
            '"archive_sha256":"0" , "archive_sha256":',
            1,
        )
        escaped = canonical.replace('"signer_key_id"', '"signer\\u005fkey_id"', 1)
        for altered in (duplicate, " " + canonical, canonical + "\n", escaped):
            with self.subTest(altered=altered[:48]):
                self.sidecar.write_text(altered, encoding="utf-8")
                result = self.run_verify()
                self.assertNotEqual(0, result.returncode)
                self.assertNotIn(self.key["private"], result.stderr)

        self.sidecar.write_text(canonical, encoding="utf-8")
        self.assertEqual(0, self.run_verify().returncode)

    def test_modified_archive_and_untrusted_publisher_fail_closed(self) -> None:
        self.assertEqual(0, self.run_sign().returncode)
        with zipfile.ZipFile(self.archive, "a") as archive:
            archive.writestr("backend/public/new-file.php", "<?php // modified")
        self.assertNotEqual(0, self.run_verify().returncode)
        self.assertNotIn(self.key["private"], self.run_verify().stderr)
        with zipfile.ZipFile(self.archive, "w") as archive:
            for name, data in BASE_ENTRIES.items():
                archive.writestr(name, data)
        wrong_keys = json.dumps({"different-id": self.key["public"]})
        self.assertNotEqual(
            0,
            self.run_verify(FOODEX_SETUP_TRUSTED_PUBLIC_KEYS_JSON=wrong_keys).returncode,
        )

    def test_invalid_signing_key_and_unsafe_zip_cannot_publish_sidecar(self) -> None:
        invalid = self.run_sign(FOODEX_SETUP_SIGNING_SECRET_KEY_B64="not-a-key")
        self.assertNotEqual(0, invalid.returncode)
        self.assertFalse(self.sidecar.exists())
        self.archive.write_bytes(b"not a ZIP")
        unsafe = self.run_sign()
        self.assertNotEqual(0, unsafe.returncode)
        self.assertFalse(self.sidecar.exists())

    def test_symlinked_archive_and_version_are_rejected_without_signature(self) -> None:
        original_archive = self.archive
        linked_archive = self.folder / "archive-link.zip"
        linked_archive.symlink_to(original_archive)
        self.archive = linked_archive
        rejected_archive = self.run_sign()
        self.assertNotEqual(0, rejected_archive.returncode)
        self.assertFalse(self.sidecar.exists())

        self.archive = original_archive
        original_version = self.version
        linked_version = self.folder / "version-link"
        linked_version.symlink_to(original_version)
        self.version = linked_version
        rejected_version = self.run_sign()
        self.assertNotEqual(0, rejected_version.returncode)
        self.assertFalse(self.sidecar.exists())

    def test_symlinked_signature_output_and_verification_input_fail_closed(self) -> None:
        existing = self.folder / "existing-sidecar"
        existing.write_text("preserve-me", encoding="utf-8")
        self.sidecar.symlink_to(existing)
        refused = self.run_sign()
        self.assertNotEqual(0, refused.returncode)
        self.assertEqual("preserve-me", existing.read_text(encoding="utf-8"))
        self.sidecar.unlink()

        self.assertEqual(0, self.run_sign().returncode)
        genuine = self.sidecar
        linked = self.folder / "linked-sidecar"
        linked.symlink_to(genuine)
        self.sidecar = linked
        self.assertNotEqual(0, self.run_verify().returncode)

    def test_php_stream_wrapper_cannot_claim_signed_output_without_a_file(self) -> None:
        # PHP accepts fopen("php://temp", "x"), but that stream is not a
        # signature artifact. Never report a successful publication for it.
        result = self.run_sign(output_override="php://temp")
        self.assertNotEqual(0, result.returncode)
        self.assertFalse(self.sidecar.exists())
        self.assertNotIn(self.key["private"], result.stderr)

    def test_existing_signature_is_never_overwritten(self) -> None:
        self.sidecar.write_text("do-not-replace", encoding="utf-8")
        failed = self.run_sign()
        self.assertNotEqual(0, failed.returncode)
        self.assertEqual("do-not-replace", self.sidecar.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()

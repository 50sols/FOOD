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

    def run_sign(self, **env_overrides: str) -> subprocess.CompletedProcess:
        return subprocess.run(
            [
                "php", str(SCRIPT), "--mode=sign",
                "--package=" + str(self.archive),
                "--version-file=" + str(self.version),
                "--source-commit=" + SHA,
                "--output=" + str(self.sidecar),
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

    def test_existing_signature_is_never_overwritten(self) -> None:
        self.sidecar.write_text("do-not-replace", encoding="utf-8")
        failed = self.run_sign()
        self.assertNotEqual(0, failed.returncode)
        self.assertEqual("do-not-replace", self.sidecar.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()

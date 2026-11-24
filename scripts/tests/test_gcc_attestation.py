from __future__ import annotations

import base64
import datetime as dt
import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location(
    "gcc_local_attestation",
    ROOT / "scripts/local-ci/verify-attestation.py",
)
assert spec and spec.loader
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
HEAD = "0123456789abcdef0123456789abcdef01234567"
TOOLKIT = "fedcba9876543210fedcba9876543210fedcba98"
NOW = dt.datetime(2026, 11, 23, 20, 0, tzinfo=dt.timezone.utc)


@unittest.skipUnless(shutil.which("openssl"), "OpenSSL is required for Ed25519 verification")
class GccAttestationVerifierTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        root = Path(self.tmp.name)
        self.private = root / "ci-private.pem"
        self.public = root / "ci-public.pem"
        subprocess.run(
            ["openssl", "genpkey", "-algorithm", "ED25519", "-out", str(self.private)],
            check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        subprocess.run(
            ["openssl", "pkey", "-in", str(self.private), "-pubout", "-out", str(self.public)],
            check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        self.report = {
            "schema": 1,
            "repository": "50sols/FOOD",
            "base": "Foodex_V2",
            "pr": 4406,
            "source_sha": HEAD,
            "profile": "backend",
            "result": "PASS",
            "toolkit_sha": TOOLKIT,
            "trusted_release": False,
            "signed_release": False,
            "artifacts_sha256": {},
            "issued_at": NOW.isoformat().replace("+00:00", "Z"),
        }

    def encode(self, change=None):
        data = self.report.copy()
        if change:
            data.update(change)
        return json.dumps(data, sort_keys=True, separators=(",", ":")).encode("utf-8")

    def sign(self, payload):
        data = Path(self.tmp.name) / "in.json"
        sig = Path(self.tmp.name) / "sig.bin"
        data.write_bytes(payload)
        subprocess.run(
            ["openssl", "pkeyutl", "-sign", "-inkey", str(self.private),
             "-rawin", "-in", str(data), "-out", str(sig)],
            check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        return base64.b64encode(sig.read_bytes()).decode("ascii")

    def test_valid_scoped_attestation(self):
        payload = self.encode()
        verified = mod.verify(payload, self.sign(payload), self.public, 4406, HEAD, NOW)
        self.assertEqual(HEAD, verified["source_sha"])
        self.assertEqual("50sols/FOOD", verified["repository"])

    def test_modified_sha_is_rejected_even_with_real_signature(self):
        payload = self.encode({"source_sha": "a" * 40})
        with self.assertRaisesRegex(ValueError, "exact PR head"):
            mod.verify(payload, self.sign(payload), self.public, 4406, HEAD, NOW)

    def test_wrong_pr_and_base_are_rejected(self):
        for changes in ({"pr": 4407}, {"base": "main"},
                        {"repository": "other/FOOD"},
                        {"repository": "walidatiyaai2025-gif/FOOD"}):
            with self.subTest(changes=changes):
                payload = self.encode(changes)
                with self.assertRaises(ValueError):
                    mod.verify(payload, self.sign(payload), self.public, 4406, HEAD, NOW)

    def test_tampering_after_signing_is_rejected(self):
        signed = self.encode()
        tampered = self.encode({"toolkit_sha": "a" * 40})
        with self.assertRaisesRegex(ValueError, "signature"):
            mod.verify(tampered, self.sign(signed), self.public, 4406, HEAD, NOW)

    def test_expired_or_future_attestations_are_rejected(self):
        for shifted in (-2, 2):
            moment = NOW + dt.timedelta(hours=shifted)
            payload = self.encode({"issued_at": moment.isoformat().replace("+00:00", "Z")})
            with self.assertRaisesRegex(ValueError, "Expired or future"):
                mod.verify(payload, self.sign(payload), self.public, 4406, HEAD, NOW)

    def test_cannot_forge_prod_release_certification(self):
        for changes in (
            {"trusted_release": True}, {"signed_release": True},
            {"profile": "all", "artifacts_sha256": {}},
        ):
            with self.subTest(changes=changes):
                payload = self.encode(changes)
                with self.assertRaises(ValueError):
                    mod.verify(payload, self.sign(payload), self.public, 4406, HEAD, NOW)

    def test_noncanonical_json_rejected(self):
        payload = json.dumps(self.report).encode("utf-8")
        with self.assertRaisesRegex(ValueError, "Noncanonical"):
            mod.verify(payload, self.sign(payload), self.public, 4406, HEAD, NOW)


if __name__ == "__main__":
    unittest.main()

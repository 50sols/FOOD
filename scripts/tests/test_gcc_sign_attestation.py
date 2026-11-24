from __future__ import annotations

import datetime as dt
import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[2]

def load(name,filename):
    spec=importlib.util.spec_from_file_location(name,ROOT/"scripts/local-ci"/filename)
    assert spec and spec.loader
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

signer=load("gcc_ci_signer","sign-attestation.py")
verifier=load("gcc_ci_verifier","verify-attestation.py")
HEAD="0123456789abcdef0123456789abcdef01234567"
TOOLKIT="a" * 40


@unittest.skipUnless(shutil.which("openssl"), "OpenSSL required for exact Ed25519 attestation")
class GccSignerTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name)
        self.key=self.root/"ci_private.pem"
        self.pub=self.root/"ci_public.pem"
        subprocess.run(["openssl","genpkey","-algorithm","ED25519","-out",str(self.key)],
                       check=True,stdout=subprocess.DEVNULL)
        subprocess.run(["openssl","pkey","-in",str(self.key),"-pubout","-out",str(self.pub)],
                       check=True,stdout=subprocess.DEVNULL)
        self.evidence=self.root/"evidence"
        self.evidence.mkdir()
        self.result=self.evidence/"result.json"
        self.result.write_text(json.dumps({
            "schema":1,"result":"PASS","source_sha":HEAD,"profile":"backend",
            "signed":False,"trusted_release":False,"artifacts_sha256":{},
        }))
        for name in [*(f"laravel-shard-{i}.xml" for i in range(4)),"deployment.xml"]:
            (self.evidence/name).write_text('<testsuites><testsuite tests="2" failures="0" errors="0"/></testsuites>')

    def build(self,profile="backend"):
        return signer.build_payload(
            self.result,self.evidence,"50sols/FOOD",
            4406,HEAD,TOOLKIT,profile,12345,
        )

    def test_real_signer_and_verifier_roundtrip(self):
        payload=self.build()
        signature=signer.sign(payload,self.key)
        now=dt.datetime.now(dt.timezone.utc)
        signed=__import__("base64").b64encode(signature).decode("ascii")
        result=verifier.verify(payload,signed,self.pub,4406,HEAD,now)
        self.assertEqual("PASS",result["result"])
        self.assertEqual(12345,result["request_id"])
        self.assertEqual("50sols/FOOD",result["repository"])

    def test_rejects_legacy_repository_even_with_valid_local_results(self):
        with self.assertRaisesRegex(ValueError, "Untrusted repository"):
            signer.build_payload(
                self.result,self.evidence,"walidatiyaai2025-gif/FOOD",
                4406,HEAD,TOOLKIT,"backend",12345,
            )

    def test_rejects_missing_or_failed_junit(self):
        (self.evidence/"laravel-shard-3.xml").unlink()
        with self.assertRaisesRegex(ValueError,"Missing local PHPUnit"):
            self.build()
        (self.evidence/"laravel-shard-3.xml").write_text(
            '<testsuites><testsuite tests="1"/><failure>bad</failure></testsuites>')
        with self.assertRaisesRegex(ValueError,"Failing tests"):
            self.build()

    def test_rejects_tampered_package_hash(self):
        zip_path=self.evidence/"FOODEX-Setup.zip"
        zip_path.write_bytes(b"not equal to claimed digest")
        with self.assertRaisesRegex(ValueError,"Discrepant"):
            self.build()
        self.result.write_text(json.dumps({
            "schema":1,"result":"PASS","source_sha":HEAD,"profile":"backend",
            "signed":False,"trusted_release":False,"artifacts_sha256":{"FOODEX-Setup.zip":"0"*64},
        }))
        with self.assertRaisesRegex(ValueError,"SHA-256 mismatch"):
            self.build()

    def test_refuses_unsigned_release_claims(self):
        report=json.loads(self.result.read_text())
        report["trusted_release"]=True
        self.result.write_text(json.dumps(report))
        with self.assertRaisesRegex(ValueError,"trusted release"):
            self.build()


if __name__=="__main__":
    unittest.main()

#!/usr/bin/env python3
"""Sign reviewed GCC local CI evidence, never a production release artifact.

This tool must run ON THE TRUSTED WINDOWS HOST after inspecting disposable
container results. Its private Ed25519 key is never put into a container.
"""
from __future__ import annotations

import argparse
import base64
import datetime as dt
import hashlib
import json
from pathlib import Path
import re
import subprocess
import tempfile
import xml.etree.ElementTree as ET

SHA = re.compile(r"^[a-f0-9]{40}$")
PROFILE = {"backend", "setup", "packages", "all"}


def build_payload(result_file: Path, evidence_dir: Path, repo: str, pr: int,
                  head_sha: str, toolkit_sha: str, profile: str, request_id: int) -> bytes:
    if repo != "walidatiyaai2025-gif/FOOD" or pr <= 0 or request_id <= 0:
        raise ValueError("Untrusted repository or request")
    if not SHA.fullmatch(head_sha) or not SHA.fullmatch(toolkit_sha) or profile not in PROFILE:
        raise ValueError("Invalid exact SHA/toolkit/profile")
    if evidence_dir.is_symlink() or result_file.is_symlink():
        raise ValueError("Local attestation source cannot be a symlink")
    report = json.loads(result_file.read_text(encoding="utf-8"))
    if not isinstance(report, dict) or report.get("result") != "PASS":
        raise ValueError("Container did not complete successfully")
    if report.get("source_sha") != head_sha or report.get("profile") != profile:
        raise ValueError("Container result has stale or mismatched SHA/profile")
    if report.get("signed") is not False or report.get("trusted_release") is not False:
        raise ValueError("Container may never assert trusted release")
    digests = report.get("artifacts_sha256")
    if not isinstance(digests, dict):
        raise ValueError("Missing package digest map")
    paths = [p for p in evidence_dir.rglob("*.zip") if p.is_file()]
    if len(paths) != len(digests) or len(paths) > 10 or len({p.name for p in paths}) != len(paths):
        raise ValueError("Discrepant ZIP evidence set")
    for path in paths:
        if path.is_symlink() or not path.resolve().is_relative_to(evidence_dir.resolve()):
            raise ValueError("Unsafe ZIP path")
        actual = hashlib.sha256(path.read_bytes()).hexdigest()
        if digests.get(path.name) != actual:
            raise ValueError("ZIP SHA-256 mismatch")
    if profile in {"setup", "packages", "all"}:
        if not paths:
            raise ValueError("Setup ZIP missing")
        if not (evidence_dir / "fresh-install-evidence.json").is_file():
            raise ValueError("Fresh-install evidence missing")
    if profile in {"packages", "all"} and len(paths) < 2:
        raise ValueError("Setup and Update ZIP are both required")
    if profile in {"backend", "all"}:
        for name in [*(f"laravel-shard-{i}.xml" for i in range(4)), "deployment.xml"]:
            path = evidence_dir / name
            if not path.is_file() or path.is_symlink():
                raise ValueError(f"Missing local PHPUnit evidence: {name}")
            root = ET.parse(path).getroot()
            if any(True for _ in root.iter("failure")) or any(True for _ in root.iter("error")):
                raise ValueError(f"Failing tests in {name}")
            tests = sum(int(x.get("tests", "0")) for x in root.iter("testsuite"))
            if tests <= 0:
                raise ValueError(f"Empty JUnit suite: {name}")
    issued_at = dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")
    attestation = {
        "schema": 1, "repository": repo, "base": "Foodex_V2",
        "pr": pr, "source_sha": head_sha, "toolkit_sha": toolkit_sha,
        "profile": profile, "request_id": request_id, "issued_at": issued_at,
        "result": "PASS", "trusted_release": False, "signed_release": False,
        "artifacts_sha256": digests,
    }
    return json.dumps(attestation, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode()


def sign(payload: bytes, private_key: Path) -> bytes:
    if not private_key.is_file() or private_key.is_symlink():
        raise ValueError("Trusted local CI private key missing")
    with tempfile.TemporaryDirectory() as tmp:
        input_file = Path(tmp) / "message"
        output_file = Path(tmp) / "signature"
        input_file.write_bytes(payload)
        subprocess.run([
            "openssl", "pkeyutl", "-sign", "-inkey", str(private_key),
            "-rawin", "-in", str(input_file), "-out", str(output_file),
        ], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
        signature = output_file.read_bytes()
    if len(signature) != 64:
        raise ValueError("Invalid Ed25519 signer output")
    return signature


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--result", type=Path, required=True)
    ap.add_argument("--evidence-dir", type=Path, required=True)
    ap.add_argument("--private-key", type=Path, required=True)
    ap.add_argument("--repository", required=True)
    ap.add_argument("--pr", type=int, required=True)
    ap.add_argument("--source-sha", required=True)
    ap.add_argument("--toolkit-sha", required=True)
    ap.add_argument("--profile", required=True)
    ap.add_argument("--request-id", type=int, required=True)
    args = ap.parse_args()
    payload = build_payload(
        args.result, args.evidence_dir, args.repository, args.pr,
        args.source_sha, args.toolkit_sha, args.profile, args.request_id,
    )
    signature = sign(payload, args.private_key)
    print(json.dumps({
        "payload_b64": base64.b64encode(payload).decode("ascii"),
        "signature_b64": base64.b64encode(signature).decode("ascii"),
    }, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

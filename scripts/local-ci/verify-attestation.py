#!/usr/bin/env python3
"""Verify a signed GCC test attestation. Advisory-only until a trusted public
key and protected-branch GitHub verification path are separately activated.

Never execute code from a PR, accept unsigned comments, or turn this
verification into a release certification.
"""
from __future__ import annotations

import argparse
import base64
import datetime as dt
import json
import pathlib
import re
import subprocess
import tempfile

SHA = re.compile(r"^[a-f0-9]{40}$")
DIGEST = re.compile(r"^[a-f0-9]{64}$")
REPOSITORY = "50sols/FOOD"
BASE = "Foodex_V2"


def verify(
    report_bytes: bytes,
    signature_b64: str,
    public_key: pathlib.Path,
    expected_pr: int,
    expected_sha: str,
    now: dt.datetime | None = None,
    max_age_seconds: int = 3600,
) -> dict:
    if not SHA.fullmatch(expected_sha) or expected_pr <= 0:
        raise ValueError("Invalid trusted expected PR/SHA")
    if not public_key.is_file() or public_key.is_symlink():
        raise ValueError("Trusted public key is unavailable")
    if len(report_bytes) > 32768:
        raise ValueError("Oversized attestation")
    try:
        data = json.loads(report_bytes.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise ValueError("Malformed signed attestation") from exc
    if not isinstance(data, dict):
        raise ValueError("Signed attestation must be an object")
    canonical = json.dumps(data, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode()
    if canonical != report_bytes:
        raise ValueError("Noncanonical attestation")
    if data.get("schema") != 1 or data.get("repository") != REPOSITORY or data.get("base") != BASE:
        raise ValueError("Wrong attestation schema/repository/base")
    if data.get("pr") != expected_pr or data.get("source_sha") != expected_sha:
        raise ValueError("Attestation does not match exact PR head")
    if data.get("profile") not in {"backend", "setup", "packages", "all"}:
        raise ValueError("Unrecognized CI profile")
    if data.get("result") != "PASS":
        raise ValueError("Only successful exact-head local CI can be verified")
    if not SHA.fullmatch(str(data.get("toolkit_sha", ""))):
        raise ValueError("Toolkit lineage missing")
    if data.get("trusted_release") is not False or data.get("signed_release") is not False:
        raise ValueError("Local CI evidence cannot assert a trusted release")
    digests = data.get("artifacts_sha256")
    if not isinstance(digests, dict) or len(digests) > 10:
        raise ValueError("Invalid artifact digest map")
    if any(
        not isinstance(k, str) or "/" in k or "\\" in k
        or not DIGEST.fullmatch(str(v))
        for k, v in digests.items()
    ):
        raise ValueError("Invalid artifact SHA256")
    if data["profile"] in {"setup", "packages", "all"} and not digests:
        raise ValueError("Missing package digests")
    issued = data.get("issued_at")
    if not isinstance(issued, str) or not issued.endswith("Z"):
        raise ValueError("Missing UTC attestation timestamp")
    try:
        issued_at = dt.datetime.fromisoformat(issued.replace("Z", "+00:00"))
    except ValueError as exc:
        raise ValueError("Malformed timestamp") from exc
    now = now or dt.datetime.now(dt.timezone.utc)
    delta = (now - issued_at).total_seconds()
    if delta < -120 or delta > max_age_seconds:
        raise ValueError("Expired or future attestation")
    try:
        signature = base64.b64decode(signature_b64, validate=True)
    except ValueError as exc:
        raise ValueError("Malformed attestation signature") from exc
    if len(signature) != 64:
        raise ValueError("Wrong Ed25519 signature length")
    with tempfile.TemporaryDirectory() as directory:
        root = pathlib.Path(directory)
        payload = root / "attestation.json"
        sigfile = root / "signature.bin"
        payload.write_bytes(report_bytes)
        sigfile.write_bytes(signature)
        completed = subprocess.run(
            ["openssl", "pkeyutl", "-verify", "-pubin", "-inkey", str(public_key),
             "-rawin", "-in", str(payload), "-sigfile", str(sigfile)],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=False, timeout=15,
        )
        if completed.returncode != 0:
            raise ValueError("Invalid Ed25519 signature")
    return data


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--report", type=pathlib.Path, required=True)
    parser.add_argument("--signature-base64", required=True)
    parser.add_argument("--public-key", type=pathlib.Path, required=True)
    parser.add_argument("--expected-pr", type=int, required=True)
    parser.add_argument("--expected-sha", required=True)
    args = parser.parse_args()
    verify(
        args.report.read_bytes(), args.signature_base64, args.public_key,
        args.expected_pr, args.expected_sha,
    )
    print("GCC exact-head Ed25519 attestation verified — advisory only, not a release")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

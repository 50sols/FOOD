# G005 / #2746 — Trusted publisher signature foundation (partial, no release)

## Scope

The original PR #3968 adds an isolated verifier TrustedUpdateSignatureVerifier and positive/adversarial Ed25519 tests. It does not change the live update action, installer, dashboard route, package upload semantics, producer, database or migrations. This is a source increment, NOT full G005 acceptance.

The signing payload is an exact domain separator FOODEX-UPDATE-SIGNATURE-V1 followed by a newline and UTF-8 JSON with fixed field insertion order, using JSON_UNESCAPED_SLASHES and JSON_UNESCAPED_UNICODE. It binds target version, minimum version, archive SHA-256, migration flag, full release notes, and publisher key ID. Only exact canonical base64 Ed25519 public keys explicitly pinned by key ID may verify. An unknown key, missing sodium, malformed signature/key, key-ID substitution, or altered manifest fails closed. No private key, credential, or production trust default is distributed.

## Security acceptance still NOT satisfied

- LIVE INTEGRATION MISSING: SystemUpdateController::store, UpdatePackageValidator, UpdateManager and release factory do not yet obtain one authenticated signed-manifest source or invoke this verifier before any executable ZIP mutation. SHA-256 typed by the uploader is not signer authentication.
- ZIP bytes must be independently SHA-256 hashed and compared with the *signed* digest before apply. A signature over metadata alone does not verify the bytes; the current request fields must not be treated as signed authority.
- Require proper authenticated publisher identity, off-web-root trusted public key registry, key rotation/revocation policy, ext-sodium host readiness, no trust-on-first-use and accurate bilingual blocked/readiness actions on the existing System Updates page. Business/host values cannot be guessed.
- Require actual current signed versioned Update ZIP and positive/negative full Dashboard upgrade CI, Composer/vendor compatibility, files/DB/uploads backup and real restore/failure injection, together with final signed Setup ZIP and cPanel host prerequisites.
- Full G005 remains SOURCE_COMPLETE: NO, TECHNICAL_REVIEW: FAIL, REVIEW_RISK: HIGH, PR DRAFT. Do not merge or close on this helper alone.

## Scope test matrix

Positive: a valid detached signature issued by an explicitly pinned publisher over canonical fields.

Negative: forged target/minimum versions, forged digest, migration flag, Arabic/English release notes; unknown/replaced key, key-ID substitution, foreign domain, empty/malformed/noncanonical signature, bad public key length, unsafe key ID, malformed SemVer/checksum and invalid UTF-8.

Customer NO_DIRECT_IMPACT; Driver NO_DIRECT_IMPACT; Van NO_DIRECT_IMPACT (pure backend helper and tests; no API/Flutter/route/dispatch/commerce changes). No Flutter job PASS implied.

Never push to main, create tag or release, publish or deploy, or touch production. Track final manual UAT under #2503 only after complete source and exact-head CI.

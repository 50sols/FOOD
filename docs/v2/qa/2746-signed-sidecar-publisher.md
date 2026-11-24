# G005 #2746 — build-host Ed25519 update sidecar issuer

This is a delivery prerequisite on original draft PR #3968, **not a production release**. The existing updater verifies a detached Ed25519 sidecar using host-pinned trusted public keys. This increment supplies the matching **publisher**, without embedding any private key into source, archive, manifest, or application configuration.

## Build-only usage

On a protected CI/build host with PHP ext-sodium and production Composer dependencies already available, inject `FOODEX_UPDATE_SIGNING_KEY_ID` and `FOODEX_UPDATE_SIGNING_SECRET_KEY_B64` through protected CI secret storage. Run:

```sh
php scripts/v2_sign_update_manifest.php \
  --package=/trusted/staging/FOODEX-Update-v2.3.4.zip \
  --target-version=2.3.4 \
  --minimum-current-version=2.2.0 \
  --release-notes-file=/trusted/staging/RELEASE_NOTES.txt \
  --contains-migrations=1 \
  --output=/trusted/staging/FOODEX-Update-v2.3.4.signed.json
```

The example paths and versions are illustrative, **not proof of an actual distributable artifact**. Do not supply signing secrets as command-line arguments or use this script on customer hosting. The release build system must ensure exclusive private ownership of staging directories, stable archive bytes, no late ZIP substitutions, trusted public-key provisioning and no secret injection into artifacts. The CLI only refuses symlink aliases and existing sidecars; it does not guarantee tamper-proof source tree ownership in a hostile shared directory.

`SignedUpdatePublisher` reuses the trusted installer's domain-separated `canonicalPayload()` and emits exactly the strict `foodex-signed-update-v1` fields. Unit regression `SignedUpdatePublisherTest` uses an ephemeral Ed25519 keypair to verify installer parity and refuses altered version, hash, notes, migration declaration, signer identity or malformed key material. No production keys are created or stored.

## Acceptance remaining

- Real signed Update ZIP and detached sidecar from the same reviewed final SHA, with manifest/hash, signer trust-chain and release notes
- Updated admin center upload with valid signature, incompatible/replayed/tampered signature rejection before mutation
- Actual cPanel-safe supported upgrade using the signed artifact, retained DB/.env/uploads/settings, verified backup/restore and fail-safe recovery
- Exact-head Repository Policy, V2 Isolation, Required CI, signed-package acceptance and independent review; final #2503 owner UAT/signoff

**SOURCE_COMPLETE: NO for full G005.** Keep #3968 DRAFT and #2746 OPEN until all conditions hold. Customer, Driver and Van: NO_DIRECT_SOURCE_IMPACT. No app/Flutter, main, tag, release or production changes.

# G004 #2745 — Detached Ed25519 Setup ZIP provenance prerequisite

## Scope and trust boundary
Original PR #3444, branch `fix/2745-v2-deterministic-setup-composer-lock`, targets `Foodex_V2` only. The build-side CLI `scripts/v2_setup_signature.php` supports `--mode=sign` and `--mode=verify`. It uses PHP sodium detached Ed25519 signatures over a domain-separated canonical JSON payload with `signer_key_id`, canonical `version`, exact 40-character `source_commit`, and lowercase SHA-256 digest of the Setup ZIP. Signing credentials are read ONLY from build-host protected environment variables `FOODEX_SETUP_SIGNING_KEY_ID` and `FOODEX_SETUP_SIGNING_SECRET_KEY_B64`; never command-line arguments, repository source, or release ZIP files. Verification requires an independently configured trusted key ID/public-key map in `FOODEX_SETUP_TRUSTED_PUBLIC_KEYS_JSON`. No default trust key exists.

A publisher **cannot sign arbitrary ZIP bytes**: before using any private key it runs the existing canonical `v2_setup_staging_verify.py` over the actual package, VERSION file, and exact source SHA, using `FOODEX_SETUP_VERIFIER_PYTHON` or `python3` on a build host. It independently checks returned staging evidence, version and archive digest. The CLI emits a detached sidecar using exclusive create, fails closed on pre-existing output and symlink paths, and never prints a signing secret or exception details. The PHP verification mode checks key ID, canonical payload, actual file digest and trusted Ed25519 public key.

**Important:** the Python verifier labels the staged ZIP `UNTRUSTED_CI_STAGING_ONLY`. The new detached signature capability does not turn that CI artifact into a production release: the actual release process still needs protected production signing-key provisioning, pinned/rotatable trusted public keys at the installer, same-commit signed Setup/Update packaging, reproducible artifact publication, complete cPanel web installer and real fresh-MySQL/upgrade/backup/recovery acceptance. The ONE ZIP installation requirement remains open until packaging/trust are integrated inside the deployable ZIP and tested without SSH.

## Runnable tests
`scripts/tests/test_v2_setup_signature.py` generates disposable per-test Ed25519 keypairs using PHP sodium. It verifies:
- accepted staged fixture ZIP: signature, deterministic provenance fields, and public-key verification;
- modified archive SHA or untrusted signer-key ID: rejection;
- malformed signing key / non-ZIP package: no signature file;
- output-sidecar collision: never overwrite;
- optional real G004 CI ZIP, identified by `FOODEX_SETUP_STAGING_ZIP_PATH` and `FOODEX_SETUP_SOURCE_SHA`, signs and verifies the actual freshly built ZIP with **ephemeral test keys only**; resulting sidecar stays in a disposable test directory and is never a downloadable release artifact.

G004 PR workflow runs `php -l` and these tests after building and checking the exact ZIP, alongside existing builder and verifier suites. This is a source/CI contract, **not a claim that GitHub has run the newly edited workflow or that a genuine signed production Setup ZIP exists**.

## Executed development evidence
On authorized GCC Windows, disposable exact Git checkout:
- HEAD `a4ad0d7ec143eb8a09d70b02be38de05fef3ea1b`: PHP 8.3 syntax PASS; Python/PHP signature suite 4/4 PASS, 10.129 seconds.
- HEAD `60140cc0929ed3507cf85c9f6df462fdf5e69d7f`: syntax PASS; signature suite 4 PASS + 1 CI-only integration SKIP when full staged artifact environment is absent, 6.266 seconds.
- Same HEAD: tested the actual-CI-ZIP test mode with a constructed staging ZIP fixture whose VERSION matches repo 1.0.127 and source SHA matches exact HEAD; 1/1 PASS, 5.076 seconds. This is NOT the full Laravel/vendor Setup production artifact.

## G004 signed-archive TOCTOU boundary (original PR #3444)

A valid Ed25519 signature authenticates exactly the digest supplied by the canonical staging verifier. Formerly `v2_setup_staging_verify.py` opened the ZIP pathname once to check the envelope/member paths/CRC and **again** to calculate the digest. A concurrent path replacement, or in-place mutation between those operations, could attach successful validation evidence to bytes never safety-checked.

The verifier now pins a regular ZIP file descriptor for the entire envelope check, member validation, CRC and SHA-256 stream; before accepting evidence it compares device, inode, size, mtime and ctime with both the original descriptor and the actual final pathname. The published evidence length is taken from that pinned file, not a third pathname lookup. Two deterministic adversarial tests intercept the verified CRC boundary and (1) replace the pathname with a ZIP containing `../escape-from-setup.txt` and (2) append unvalidated bytes to the same inode. Either case must fail without a success digest. These tests use disposable inputs; they do not provision a production signing key or prove actual cPanel deployment, nor can they prevent a future external publisher from changing files **after** this validation process exits.

## Acceptance still required
Full Linux GitHub checks on exact final PR head (Policy, Isolation, Required and G004), trusted signature/public-key provisioning, real one-ZIP release package, no-SSH cPanel fresh MySQL install and installer lockdown, signed upgrade plus backup/recovery, authorization/SoD and owner UAT. Original PR #3444 is DRAFT and must not merge or close #2745 before all applicable gates pass. No changes to `main`, release tags, production or Customer/Driver/Van Flutter.

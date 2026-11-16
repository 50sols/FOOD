# V2 G006 / #2747 — exact Update ZIP historical compatibility integrity

**Source:** Unified RBAC / ERP / Sidebar P112, G006. **Parent:** #2503. **Merge base:** `Foodex_V2` only. The source is the archive/manifest contract of `scripts/build-update-bundle.py`, not an invented release build.

## Delivery and limitation

`scripts/v2_upgrade_package_provenance.py` verifies the *actual bytes supplied* for both historical edges: v1.0.124 → v1.0.125 and v1.0.125 → v1.0.127. It requires one `FOODEX-Update.zip`, its `FOODEX-Update.json`, `FOODEX-Update.sha256.txt` and exact `FOODEX-Update.files.txt` per edge. The two edge sources have separate immutable SHA identities.

The verifier rejects a missing archive, a symlinked package directory or symlinked ancestor directory (even if the bundle leaf itself is real), or any symlinked ZIP/JSON/checksum/file-list artifact, mismatched SHA-256, invalid or non-exact version edge, wrong `VERSION` inside ZIP, inconsistent file manifest, omitted/duplicate/foreign migration declaration, missing migration member, duplicate/aliased normalized path, archive traversal, symlink, `.env`/storage or non-runtime content, full-redeploy-required delta, inconsistent source SHA and corrupt ZIP data. It does **not** extract, apply or execute untrusted archives.

**Important:** SHA-256 establishes consistency, **not publisher authenticity**. A trusted digital signature/approved signing key and version trust must still be verified by the actual installer/updater release authority. An archive integrity PASS is **not** evidence that migrations ran successfully, that existing records or secrets were preserved, or that recovery worked. Never mark this Issue complete on this test alone.

## Historical Git source-chain continuity (v124 → v125 → v127)

The two independently checksum-valid ZIPs must also be **one contiguous source lineage**. For the 1.0.124 → 1.0.125 and 1.0.125 → 1.0.127 pair, the latter manifest's `source_base` must exactly equal the former manifest's `source_commit` (40-character immutable Git SHA). Valid source SHAs that do **not** join are rejected before any `archive_integrity_only` success response. Both individual archive checks, exact version edges, checksum/file lists, and distinct target source commits remain mandatory.

The focused regression first verifies both archives independently, then supplies a second archive whose `source_base` is a valid but *different* Git SHA; the CLI must fail with a source-lineage error. The positive synthetic pair uses the first package's `source_commit` as the second package's base. This matches the archived historical G006 source-commit chain exercised by the real-artifact integrity workflow.

**Evidence boundary:** source-lineage equality is necessary for historical binary provenance but insufficient to authenticate a publisher or prove an executable data-preserving MySQL upgrade, ZIP signature, recovery, final Setup/Update package or production deployability. Those G006/G004/G005/P112 release acceptance gates remain OPEN.

## Strict manifest JSON decoding (ambiguous keys and non-finite numbers)

The offline verifier rejects repeated JSON object keys at **all nesting levels** rather than silently selecting the last value (Python's default `json.loads` behavior). This prevents an ambiguous manifest containing repeated `available`, `source_commit`, `delta.file_count` or other authority/provenance fields from passing interpretation-dependent validation. Non-standard unquoted JSON numeric constants `NaN`, `Infinity` and `-Infinity` are also rejected, including inside otherwise unused nested metadata. The parser additionally rejects valid-looking scientific-notation numbers such as `1e999` and `-1e999` when Python would overflow them into infinity. Dedicated synthetic negative regressions plus revalidation of a valid manifest prove fail-closed parsing. The change is restricted to this **read-only archive integrity checker**, not the Laravel installer/updater, signature trust, database migration or rollback. The G006 release acceptance blockers remain open.

## Strict numeric manifest field types

The offline verifier now requires JSON **integer** types for both `schema_version` and `delta.file_count`, not merely equal numeric values. Python otherwise treats `true == 1` and `1.0 == 1`, so a manifest could claim `"schema_version": true` or a one-file ZIP could claim `"file_count": true` and pass weak equality checks. Synthetic regressions reject booleans, numeric floats and string/null schema types, while valid integer manifests still pass. This only strengthens offline archive consistency; it does **not** establish signature trust or an executable historical database upgrade.

## Migration manifest completeness regression

The ZIP's `backend/database/migrations/` members and manifest `migrations` list must have **exactly the same unique paths**, not merely a valid subset. A listing omitting one ZIP migration or repeating another can otherwise pass checksum integrity when the metadata was changed alongside the archive. The negative test exercises a real second ZIP migration, an omitted declaration and a duplicated declaration; the complete valid list still passes. This is offline integrity verification, **not** signed-publisher provenance or a database-upgrade success claim.

## Canonical SemVer parity with the Laravel updater

The G006 offline checker previously used Python `\\d` for version digits. In Python, `\\d` and `int()` accept Unicode decimal numerals, and the previous regex accepted leading-zero components (for example `01.0.125`). Such input can be passed through the offline CLI while Laravel's signed-manifest `UpdateVersion::isCanonical` requires ASCII `[0-9]`, no leading zeros and byte-for-byte matching. The verifier now uses `(?:0|[1-9][0-9]*)` per component, matching that canonical PHP contract and preserving the valid historical edges `1.0.124 → 1.0.125 → 1.0.127`.

The issue-scoped regression tests reject non-ASCII Arabic/full-width decimal digits, leading zeros and padded/newline version arguments for both installed and target edges, while a canonical checksum-validated update still passes. This changes only offline input preflight; it does **not** alter live stored installed-version normalization, ZIP extraction, Laravel runtime, Dashboard controls or public APIs. The historical real-archive integrity job must still pass on the new exact PR head. Publisher digital-signature trust, actual MySQL upgrade, uploads/config preservation and rollback remain outside this bounded source fix and **G006 is still not certified complete**.

## Symlinked artifact rejection

A ZIP, JSON manifest, checksum text, file-list text or bundle directory must be a direct regular file/directory, not a symbolic link into an unrelated directory. Directory ancestors are also checked; an ordinary bundle directory reached through a linked parent must not bypass the direct-link guard. The offline verifier rejects these references before reading any artifact bytes; regression tests cover the directory and all four asset types. This prevents the read-only integrity check from silently following aliases to unexpected local files. It is **not** a substitute for digital signature authentication or for the updater's separate filesystem trust and race protections.

## Reproduction

```bash
python3 -m unittest scripts.tests.test_v2_upgrade_package_provenance -v
python3 scripts/v2_upgrade_package_provenance.py \
  --upgrade-124-125 /path/to/v125-exact-update-bundle \
  --upgrade-125-127 /path/to/v127-exact-update-bundle \
  --source-125 <immutable-git-sha-for-125> \
  --source-127 <immutable-git-sha-for-127>
```

The issue-specific [G006 unit-test workflow](../../../.github/workflows/v2-g006-package-provenance.yml) runs these synthetic ZIP regressions against the exact PR head under read-only GitHub permissions. It is an auxiliary check: the existing Required CI Gate still controls integration and the actual MySQL/package tests below still control source acceptance.

Each path must contain all four genuine outputs from the same underlying build. The script prints `"status": "archive_integrity_only"`, not `upgrade_passed`. Tests synthesize private in-memory ZIPs; no real release assets, customer uploads, server credentials, MySQL contents or personal accounts are accessed.

## Full G006 acceptance still pending

- Obtain separately authorized original, signed historic 124→125 and 125→127 packages and inspect their immutable source/base SHA, signatures, version compatibility and actual SHA-256 manifest.
- Run *those exact ZIPs* through clean MySQL/MariaDB installations with a preserved non-empty user/order/ledger, `.env`, storage upload marker and approved migration state; prove successful upgrade and idempotent retry, including interrupted 125-recovery paths and rollback/recovery evidence.
- Run fresh installation and supported V2 upgrade against the **actual signed final isolated V2 Setup/Update ZIP** built from the frozen exact `Foodex_V2` SHA. Do not treat conditional historical workflows `release-update-bundle.yml` or `release-factory.yml` as current V2 evidence when their conditions do not trigger on V2 PRs.
- Verify `G004` #2745, `G005` #2746 and overarching P112 #2846 source/installation/update/recovery gates independently. CI must include required exact-head Repository Policy, V2 isolation, backend/security, AR/EN, real MySQL and all relevant mobile gates. A failing Enterprise Performance baseline #3160 must not be bypassed.
- Record ZIP filename, source SHA, signature verdict, archive SHA-256, test runner/source SHA, MySQL version and sanitized data-preservation and recovery outcomes. Never upload production `.env` or data.

## Cross-surface impact

| Surface | Decision |
| --- | --- |
| Backend | New offline Python ZIP/manifest integrity verifier and regression tests only, **no** Laravel/API/schema modifications |
| Dashboard | No new dashboard update UI, route or permission; Admin ZIP updater remains independently owned by #2746 |
| Customer | No Flutter/API/contract changes; remains tested separately in final synchronized release gate |
| Driver | No Flutter/API/dispatch changes; B2C → DRIVER ONLY invariant unchanged |
| Van | No Flutter/API/dispatch changes; B2B → VAN ONLY invariant unchanged |
| AR/EN/RTL | No new user-facing strings; CLI uses machine output and existing Admin updater UI remains intact |

**Status:** bounded archive-integrity verification is delivered only when its exact-head CI proves the tests executed. Full historical and V2 ZIP fresh-install/upgrade/recovery **not accepted yet**. Keep #2747 open until all above real-package acceptance is confirmed; no main, release, tag or production actions.


## Actual historical GitHub Release archive inspection — 2026-11-13

This is **real public release-archive integrity evidence**, not a fabricated synthetic ZIP nor a production/V2 release. The issue-owned workflow now downloads the original **unmodified** published assets from the immutable-looking tag references and independently pins their Git SHA and archive SHA-256. If any fixed tag or asset is changed later, the verification fails instead of accepting a silently replaced archive.

| Historical update edge | Immutable release tag → Git commit | Actual archive SHA-256 | ZIP members | Listed / declared migrations |
| --- | --- | --- | ---: | ---: |
| 1.0.124 → 1.0.125 | `v1.0.125` → `1a4d2ba341424c816040a7185a9ab1df59c3d574` | `b4fd85f983469c754c50cbb2b3d3f6d6474545863d4ea41a7b3728cce36cfb52` | 1998 | 220 |
| 1.0.125 → 1.0.127 | `v1.0.127` → `a75d3780a8cf1eeb702781adbb17a5dcfd7d8130` | `fd7c801c333c902d5d6704283eba75de24c7aeae46bcbbe6ebaac6e5dc6fdf46` | 1999 | 221 |

Source ancestry was verified with GitHub compare: `v1.0.124` → `v1.0.125` (4 commits, no behind) and `v1.0.125` → `v1.0.127` (7 commits, no behind). The release-tag SHAs agree with each corresponding manifest `source_base` and `source_commit`. The actual ~79 MB archives were downloaded with authenticated GitHub CLI read-only to an inspection workspace, and SHA-256 values matched both the published JSON and the standalone checksum text. Native ZIP inspection checked internal `VERSION`, exact ordered `FOODEX-Update.files.txt`, manifest file counts, migration counts, duplicate paths, symlink and traversal indicators. No mismatches found. The new CI job independently runs the canonical Python verifier on these **same real pinned assets**.

- Source: [GitHub release v1.0.125](https://github.com/walidatiyaai2025-gif/FOOD/releases/tag/v1.0.125) and [GitHub release v1.0.127](https://github.com/walidatiyaai2025-gif/FOOD/releases/tag/v1.0.127).
- **Strict limitation:** Release ZIP and checksum bytes alone **do not authenticate the publisher**, prove a signature trusted by the updater, prove compatible PHP/Composer runtime, run MySQL migrations, preserve `.env`/uploads/financial rows, or demonstrate interrupted-update recovery. Neither historical release advertised a separately verified trusted package signature in this inspection. The test only yields `archive_integrity_only`.
- Do not call G006 complete or merge solely on this evidence. Actual source-approved signed packages, clean installation, supported upgrade, database-preservation/backup and recovery acceptance from G004/G005/P112 must be evidenced on the exact tested packages before marking full G006 done.


## Unix ZIP special-file mode protection

A ZIP member can have an ordinary-looking filename yet carry Unix external-attribute metadata identifying a FIFO, character/block device, socket or directory. The offline G006 validator now fails closed on these non-regular member types (and continues to reject symbolic links and directory entries). Ordinary regular files and archives without a Unix file-type field remain supported. Synthetic real-ZIP regression cases exercise every prohibited special type plus a valid regular-file control. This is archive **type integrity only**, not publisher authentication or safe extraction evidence; final signed artifacts and MySQL data-preserving upgrade/recovery acceptance are still required before this gate can close.

## Portable filesystem ZIP path alias protection (G006)

Update ZIPs may be scanned on Linux CI but later extracted on cPanel, Windows, macOS or another filesystem with different case-folding or Unicode normalization. An archive with `backend/Catalog.php` and `backend/catalog.php`, or composed/decomposed Unicode-equivalent paths, could then overwrite one target even when the SHA-256, manifest and ordered file list all agree. The **read-only** G006 verifier now rejects such portable collisions (NFC Unicode normalization plus casefold) before opening any member content; this supplements the pre-existing slash/backslash alias check.

It also rejects unsafe Windows filename components (reserved device names including `CON`/`NUL`/`COM1`/`LPT9`, NTFS alternate-data-stream colons, wildcard/metacharacter characters, control bytes and trailing spaces/dots). Case-insensitive security boundaries prevent `.ENV`, `Backend/Storage` or `Backend/Tests` from bypassing private/non-runtime exclusion. Synthetic tests exercise those negative paths and show that a regular PHP runtime, canonical migration and ordinary dotfile such as `backend/public/.htaccess` still pass with correct ZIP manifest/checksum.

**Limit:** These checks do not extract the ZIP, supply a trusted publisher signature, validate actual cPanel extraction or demonstrate safe production upgrades. No Laravel, Dashboard, Customer, Driver or Van runtime/API source changes. Required exact-head G006/Policy/Isolation/Required CI, actual signed historical 124→125→127 package upgrade, nonempty MySQL state preservation and interruption/backup/recovery evidence remain independent blockers to issue #2747 closure.

## Fail-closed cross-platform paths and ZIP decompression budgets (G006)

The integrity verifier rejects **every literal backslash** in ZIP member names, not merely colliding forward-slash aliases. A Linux extractor can treat a backslash as a literal filename while a Windows updater treats it as a path separator; normalizing names for the manifest alone would incorrectly certify a different extraction target. Synthetic regression supplies a slash-normalized files list for a single backslash member and requires rejection before CRC scans.

`archive.testzip()` decompresses every member to verify CRC. To prevent unbounded decompression on hostile archives, the verifier checks central-directory metadata before reading any member: at most 50,000 members, at most 512 MiB uncompressed per member and 2 GiB total, with a 4 KiB cap for the text `VERSION` marker. Tiny synthetic fixtures exercise the size/count boundaries by patching the limits rather than allocating multi-gigabyte blobs. Genuine oversized release packages must use an independently accepted full-deploy path; never silently weaken these controls to pass a ZIP integrity check.

These are **offline verifier safety improvements only**. They do not authenticate the publisher signature, execute migrations, preserve a real populated MySQL installation, or demonstrate interrupted upgrade/backup/recovery, and cannot justify merging draft PR #3364 or closing gate #2747 on their own.

## Metadata sidecar memory bounds

The G006 read-only verifier now imposes strict byte budgets **before** loading its untrusted sidecars into memory: the JSON manifest and ordered file list are each limited to 8 MiB; the SHA-256 checksum sidecar is limited to 4 KiB. The shared helper checks the actual file size and performs a bounded read even if an attacker replaces content after the size check, then decodes strict UTF-8; oversized or malformed sidecars fail closed without parsing. Synthetic tests cover all three limits and invalid UTF-8 alongside a successful ordinary fixture. The historical 1.0.125/1.0.127 archive-integrity workflow checks compatibility against the exact published sidecar bytes.

These memory limits do not authenticate any release signature, prove real MySQL upgrades, or authorize merging #3364 before original G006 source/recovery acceptance. No Laravel runtime, Admin, Customer, Driver or Van application behavior changed.

## Win32 superscript device-name aliases (G006)

Microsoft's Win32 filename rules reserve `COM¹`, `COM²`, `COM³` and `LPT¹`, `LPT²`, `LPT³` in every directory, including versions followed by extensions. The existing G006 regex checked ASCII COM1–9/LPT1–9 but could incorrectly approve these Unicode device-name targets on Linux CI. The offline archive verifier now rejects both groups before CRC/decompression; seven synthetic real-ZIP cases cover mixed case, extensions and nested components while the existing safe-runtime ZIP fixture remains accepted. Reference: https://learn.microsoft.com/en-us/windows/win32/fileio/naming-a-file

This is **read-only cross-platform archive integrity hardening**, not proof of publisher signature, the real 124→125→127 MySQL upgrade, upload preservation or rollback. No Laravel runtime, Dashboard, Customer, Driver or Van code/API changes. Keep the original #3364 PR draft and #2747 issue open until the full isolated exact-SHA G006 acceptance passes.

## Win32 console-device member names (2026-11-14)

The offline historical Update ZIP verifier now rejects the otherwise CRC/hash-consistent member aliases `CONIN$` and `CONOUT$` (case-insensitive, with or without extensions) in **any** ZIP path component. On modern Windows these names refer to console input/output devices rather than ordinary files; accepting them as attestable extraction targets would bypass a portable-file-path trust boundary. This extends the existing Win32 reserved-name restrictions without changing artifact signing or runtime updater behavior.

The G006 synthetic ZIP test covers six real-device variants (bare, mixed-case, nested and extension forms) plus two valid longer names (`CONIN$notes.txt`, `CONOUT$report.txt`) to prove the guard does not overblock regular files. Existing ZIP digest, source lineage, path traversal, symlink, manifest, sidecar, migration and archive resource-limit checks are unchanged.

**Acceptance limit:** this only certifies archive-integrity semantics. It is NOT cryptographic publisher authenticity, historical populated MySQL upgrade from v1.0.124 through v1.0.127, rollback/recovery or final Setup/Update ZIP acceptance. Original #3364 remains DRAFT, issue #2747 OPEN until all source requirements, required exact-head checks and independent provenance gates are satisfied. No main, tags, production, deployment or release.

## Ambiguous Info-ZIP Unicode Path extra data (2026-11-14)

The historical Update ZIP integrity verifier now explicitly rejects the Info-ZIP Unicode Path extra-field type `0x7075` in any archive member, even when archive bytes, outer manifest and SHA-256 sidecars are consistent. This field is a second source of filename identity: Python's `zipfile` ignores an alternate UTF-8 name when the supplied filename CRC is invalid, whereas different extraction implementations may disagree on what path to apply. Because the release manifest attests only one filename per member, the safest portable contract is **no alternative filename-bearing ZIP metadata**. The verifier also rejects truncated/malformed extra-field framing while allowing unrelated, well-framed metadata such as an extended timestamp (type `0x5455`).

The issue-owned synthetic regression constructs a real ZIP with a safe central `backend/app/...php` entry, malicious `backend/.env` Unicode Path extra field with bad CRC, and internally matching ZIP/manifest/checksum/file-list. It proves Python reports the central path and CRC as valid **yet** the stricter verifier rejects ambiguous path authority. Two additional regression tests ensure normal timestamp metadata passes and malformed framing rejects. The original historical v1.0.124→125 and v1.0.125→127 archive-integrity workflow must pass on the new exact-head commit; no signed release or real upgrade claim is made by these checks.

Cross-surface impact: historical offline ZIP validation script/tests/QA only. No Laravel routes, Dashboard business UI, Customer/Driver/Van Flutter/API schema or B2B/B2C runtime behavior changes. No user-facing strings; AR/EN parity unchanged.

**Final G006 acceptance remains blocked:** independently authenticated publisher signatures, exact signed version/source chain, live-populated MySQL upgrade and migration/data/uploads/.env retention, interruption/backup/rollback/recovery tests, and source-complete Admin Update ZIP trust workflow. Keep original PR #3364 DRAFT and issue #2747 OPEN, do not merge partial archive-integrity code or publish to main/production.


## Encrypted and non-portable compression member rejection

The offline verifier now rejects ZIP entries marked encrypted (general-purpose flag bit 0) and entries using compression methods other than **STORED** or **DEFLATED** before ZIP CRC/decompression scanning. Some ZIP readers reject these with an unhandled password or unsupported-method exception, and shared-host extractors may not implement the same methods. A matching SHA-256 is not permission to accept an encrypted or non-portable ZIP. Two real synthetic ZIP-header regression cases update both local and central headers and recompute the manifest, standalone checksum and Dashboard digest to prove that the explicit policy—not a stale hash—rejects these inputs. The existing historical release integrity and ordinary ZIP tests must remain green.

This is **archive-integrity-only** hardening on the original G006 PR. It is not a publisher-authenticated digital-signature check, a Laravel runtime change, a completed MySQL historical upgrade or recovery proof. Customer, Driver, Van and Dashboard APIs, UI strings and AR/EN/RTL are unchanged. G006 acceptance and the existing PR remain open/draft pending verified full-source requirements.


## Laravel public-disk / legacy uploaded-file protection (2026-11-14)

The offline G006 archive verifier now rejects checksum-valid ZIP members aimed at
`backend/public/storage`, `backend/public/uploads`, `public/storage`,
`public/uploads`, and `backend/uploads`, including the exact path itself,
case-insensitive aliases, and descendants. These are deployment-specific user
uploads or the Laravel public-disk symlink, **not** code owned by an immutable
release archive. A malicious update with a valid archive digest and forged
manifest must not overwrite those files after extraction. The guard is applied
before `archive.testzip()` and before trusting the manifest's member inventory.

A real synthetic Update ZIP and consistent SHA-256/JSON/list sidecars exercise
eight rejected path variants and a positive example with
`backend/public/.htaccess` and a static SVG. It preserves existing signed
runtime assets but does not assert that unknown custom user-upload directory
names are automatically protected; installation/apply-time allowlisting remains
a separately required control.

**Scope/limits:** verifier, synthetic Python test, and QA documentation only.
No changes to Laravel, API, Dashboard, Customer, Driver, Van or tenant data.
No real backup/recovery or database update was performed. This is an additional
defense, not publisher-signature authentication, a production ZIP, or evidence
of historical populated-MySQL v124→v125→v127 upgrade. Original PR #3364
and Issue #2747 must remain OPEN/DRAFT pending independently accepted G006
full-source CI, recovery and approval. No `main` or production changes.

### Local ZIP header / CENTRAL directory extraction parity (2026-11-15)

The G006 offline **archive-integrity-only** verifier now checks physical local ZIP member headers before reading VERSION or decompressing: local signature, flags, method, CRC32, compressed/uncompressed sizes, filename encoding and identity, local extra field framing and alternate Info-ZIP Unicode filename alias type `0x7075`. Non-data-descriptor members must match CENTRAL metadata exactly; bit-3 data-descriptor members may leave local CRC/sizes zero but cannot assert contradictory nonzero values. Python `zipfile.testzip()` validates CENTRAL CRC, so a checksum-valid, same-manifest ZIP may be accepted even though another hosting extractor follows conflicting LOCAL metadata.

The issue-owned synthetic tests forge **five** real, SHA-256- and manifest-consistent ZIP header variants (flags, method, CRC, compressed size, uncompressed size) and a **local-only** Unicode Path alias to `backend/.env` with harmless CENTRAL metadata. Each forged archive is CRC-valid for Python, but the stricter G006 verifier must reject it. Existing legitimate historical v124→v125 and v125→v127 archive integrity checks remain required on exact final HEAD.

The change is confined to this original G006 Python verifier, its unit tests and documentation. It is NOT a trusted publisher signature, safe runtime ZIP installer, historical populated MySQL upgrade, or backup/rollback/recovery validation. G006 #2747 and PR #3364 remain OPEN/DRAFT until the signed publisher identity, supported v124/v125/v127 data-preserving upgrade paths and full source acceptance pass. No Backend PHP, Dashboard, Customer/Driver/Van API or Flutter, AR/EN/RTL, `main`, release, tag, live cPanel or production change.


## Case-aliased migration path integrity parity (2026-11-15)

On the original #2747 branch, a real synthetic, SHA-256-consistent Update ZIP with
`BACKEND/DATABASE/MIGRATIONS/...php` and matching manifest/checksum/file-list was
**incorrectly accepted as migration-free** by the offline G006 verifier. Its
`migration_paths` inventory used a case-sensitive prefix, while canonical
Laravel `UpdatePackageValidator::validate` lowercases archive entries before
checking for undeclared migrations. On a case-insensitive extraction host those
names may resolve to the same executable migration directory.

The offline verifier now detects every case-folded migration-directory prefix,
**rejects any noncanonical casing before accepting the manifest**, and still
requires canonical `backend/database/migrations/` filenames to appear exactly
in the declared migration list. The new real-ZIP regression recomputes all
package SHA/manifest sidecars for three forged aliases and also accepts a
legitimate, explicitly declared canonical migration. This is not a checksum
bypass; it is an independent migration-declaration classification gap.

Cross-surface scope is limited to the original offline Python G006 verifier,
its synthetic tests, and this QA note. No production Laravel code, routes,
Dashboard, schema, Customer, Driver, or Van code/API were modified. Existing
AR/EN and B2B/B2C authorization contracts are unchanged.

**Whole G006 remains SOURCE-INCOMPLETE:** The offline verifier still does not
authenticate release publisher signatures, execute an actual populated MySQL
historical v124→v125→v127 upgrade, prove retained uploads/.env/secrets, or
exercise interrupted migrations and verified backup/recovery. Keep original
PR #3364 DRAFT and Issue #2747 OPEN until those obligations and new exact-head
required CI are satisfied. Never merge this bounded security increment as
complete source acceptance; no `main`, tags, production or release.

## G006 archive portable general-purpose flag-bit security regression (2026-11-15)

**Verified defect on original PR HEAD `58b2848c6fcaf75e06be36f5d489aa8cf399558b`:** for a real synthetic ZIP whose local and central headers both claimed reserved/unportable flags `0x0010` or `0x2000`, Python `ZipFile.testzip()` returned `None` (valid CRC), and this read-only G006 verifier incorrectly accepted the update when the SHA-256/manifest/standalone sidecars were recomputed consistently. The G004 Setup staging verifier already rejected unsupported flags; G006 must not certify those same ambiguous extraction semantics.

**Fix:** after the existing explicit encryption prohibition and before CRC scanning, reject any general-purpose bits outside `0x080E` (DEFLATE compression-level bits 1–2, data descriptor bit 3, UTF-8 filename bit 11). Keep the existing LOCAL/CENTRAL equality, method, path, symlink, budgets, migrations, SHA-256 and historical lineage guards unchanged. New checksum-consistent real ZIP regressions cover `0x0010`, `0x0040`, `0x2000` and `0x4000`; the legitimate original archive remains accepted. The cross-platform Python fixture explicitly encodes Unicode file inventories as UTF-8 and rewrites actual ZIP header bytes for the backslash-path regression (instead of relying on Windows `ZipInfo` behavior).

**GCC evidence:** Windows Python 3.9.13; before the fix both `0x0010` and `0x2000` yielded `G006_ACCEPTED` with valid CRC; after the fix, `py -3.9 -m py_compile` and full `py -3.9 -m unittest scripts.tests.test_v2_upgrade_package_provenance -q` succeeded, **39 tests PASS**, on the isolated scratch folder. No production files, live DB or secrets were touched.

**Acceptance boundary:** this is only an incremental security correction to the offline integrity verifier. It does not establish publisher signature trust, authoritative historical ZIP source, a real populated MySQL v124→v125→v127 upgrade, upload/config preservation, interrupted recovery or rollback. Keep #2747 and original PR #3364 OPEN/DRAFT until all source obligations and exact-head CI are actually certified. Integration target remains `Foodex_V2` only.

## G006 — compiled Laravel bootstrap cache exclusion (2026-11-15)

**Confirmed security defect on original PR SHA `ad2a02010d8c07f69016ce0c652b2fc749b82a3d`:** the nonpublishing G006 Update ZIP verifier rejects `.env` and `backend/storage` runtime data, but allowed `backend/bootstrap/cache/config.php`, `routes-v7.php`, case-folded `Backend/Bootstrap/Cache/CONFIG.PHP` and the root `backend/bootstrap/cache` itself as ordinary regular ZIP members. Dirty server/build workspaces may put production `APP_KEY`, `DB_PASSWORD`, host-specific cached configuration or routes in these PHP files. Even when a release manifest and standalone ZIP checksum consistently attest to the bytes, publishing or applying such members risks leaking secrets or overwriting live runtime cache.

**Real GCC reproduction before fix:** Windows Python 3.9.13, all four ZIP examples included synthetic secret-like payloads, each had `ZipFile.testzip() is None`, and G006 returned an accepted 3-member report. The initial original-HEAD test suite was 39/39 PASS, demonstrating a real missing negative contract rather than a baseline regression.

**Fail-closed fix:** in `_member_name()` reject files whose case-folded leading path components equal `backend/bootstrap/cache`, including the root and all descendants; apply before any CRC expansion or ZIP extraction. Preserve canonical Laravel `backend/bootstrap/app.php`, `backend/bootstrap/providers.php`, ordinary compiled public assets, migration declarations, package lineage, checksums, Unicode/ZIP security restrictions and all existing permission boundaries. The G004 Setup ZIP staging verifier already excludes compiled bootstrap cache files; G006 now aligns with that exclusion.

**GCC verification after fix:** `py -3.9 -m py_compile scripts/v2_upgrade_package_provenance.py scripts/tests/test_v2_upgrade_package_provenance.py` PASS; complete `py -3.9 -m unittest scripts.tests.test_v2_upgrade_package_provenance -v` **40/40 PASS**, including four checksum-consistent real ZIP negative cases, a five-member legitimate positive archive and all 39 original tests. No live DB/uploads/configuration were accessed; only original G006 Python offline verifier, its test, and this QA note changed.

**Strict acceptance limit:** this is an incremental read-only Update ZIP integrity safeguard, **NOT** a publisher-authenticated signed historical upgrade, populated MySQL v124→v125→v127 data-preservation/rollback test, signed package release or recoverable cPanel update. Maintain original #2747 OPEN and PR #3364 DRAFT until all source acceptance, high-risk review and exact-head CI prove complete. Do not promote to `main`, release/tag, or production.

### Historical v125/v127 archive compatibility refinement — safe bootstrap cache directory marker

**Exact-head GitHub CI failure observed on initial hardened commit `302573f2abcbb90db9cac3b5466a3d411f8f1d13`:** the real-archive G006 job downloaded SHA-256-matching immutable historical `v1.0.125` and `v1.0.127` Update ZIPs but rejected their legitimate `backend/bootstrap/cache/.gitignore` directory placeholder. This was a false positive in the first all-descendants guard, **not** permission to allow config or route caches. Source tags v1.0.125 and v1.0.127 both contain identical `backend/bootstrap/cache/.gitignore` bytes `b"*\\n!.gitignore\\n"` (Git blob `d6b7ef32c8478a48c3994dcadc86837f4371184d`), so legacy compatibility is constrained to one verifiable exact marker.

**Refined fail-closed policy:** continue rejecting all members under the case-folded `backend/bootstrap/cache` prefix, except exactly named `backend/bootstrap/cache/.gitignore`, and accept that placeholder only if `ZipInfo.file_size` and the actual decompressed bytes equal the canonical 14-byte source-controlled marker. Reject forged secret-bearing marker bytes, extra data, alternative casing and all `config.php` / `routes-v7.php` / compiled cache entries. Keep read-only validation and all original G006 signed-provenance constraints unchanged. Preserve legitimate bootstrap source and public compiled assets.

**GCC regression after refinement:** Windows Python 3.9.13, `py_compile` PASS, entire G006 suite **41/41 PASS**. The original cache negative test remains; new historical-marker test covers exact positive, malicious payloads and case-aliased attempts. The GitHub historical-archive job must be rerun on the new exact head and prove v125 and v127 acceptance; do not treat synthetic unit success alone as enough.

**No source-complete assertion:** trusted publisher signing/keys, populated MySQL v124→v125→v127 upgrade and backup/recovery still unproven. Keep Issue #2747 OPEN, original PR #3364 DRAFT, no merge/main/release/tag/production.

## 2026-11-15 — regular-file ancestor/child ZIP collision integrity guard

**Real reproducible original-head vulnerability:** the historical G006 archive verifier rejected duplicated and NFC/casefold-aliased full member names, but did not reject a **regular file serving as an implicit parent directory** of another member. On original PR SHA `2b3ca72e6cecdb9c0bb9166d37d2402b154cbf01`, Windows GCC Python 3.9.13 baseline **41/41 PASS**; three real synthetic Update ZIPs containing `backend/public/cache-entry` together with `backend/public/cache-entry/index.php`, `backend/public/CACHE-ENTRY/index.php`, or an NFC/NFD Unicode parent alias were **UNSAFE_ACCEPT** despite `zipfile.testzip() is None` and matching generated SHA-256, manifest and file-list sidecars. A new adversarial PHPUnit-independent Python regression failed against original source with all three assertions `PackageContractError not raised`, proving this is a missing source-contract check, not a hypothetical duplicate.

**Fail-closed patch:** after the already-bounded per-file central/local filename, type, portability, checksum/size and alias checks but before declaring the archive valid, split every NFC/casefold file target and reject if **any** of its ancestor path prefixes is itself another attested ZIP regular file. This ensures update ZIPs cannot claim mutually incompatible extraction targets on cPanel/Windows/macOS versus Linux. Positive control allows a normal sibling `backend/public/cache-entry.php` and independent nested `backend/public/cache-entry/index.php`; no directory entries, migrations, release manifest metadata, trusted signer or actual installer runtime changes.

**GCC after patch:** `py -3.9 -m py_compile` PASS; full offline G006 suite **42/42 PASS** (41 previously existing tests plus real ZIP ancestor-negative and safe positive); `git diff --check` PASS. The original published v1.0.125/v1.0.127 archive CI must independently pass on the exact new GitHub SHA; GCC synthetic tests do not establish historical source or publisher authenticity.

**Scope / blockers:** scripts/v2_upgrade_package_provenance.py, existing dedicated Python test and this G006 QA note only. No Laravel service, database, Dashboard/Flutter/Customer/Driver/Van code or independent workers touched. Full G006 acceptance remains **BLOCKED** by cryptographic publisher-authenticated release provenance and populated supported-version MySQL upgrades preserving uploads, credentials, backups, failure recovery and rollback. PR #3364 stays DRAFT and #2747 stays OPEN even if exact-head CI is green. Foodex_V2 only; no main, release, tags, production.

## Streaming ZIP data-descriptor integrity (Worker E, 2026-11-16)

**Source defect:** With ZIP general-purpose flag bit 3, a non-seekable writer places
a CRC/compressed-size/uncompressed-size data descriptor after the member payload.
The historical G006 offline validator compared local versus central header fields,
but did not read this post-data descriptor. Python `ZipFile.testzip()` checks
central-directory metadata and returns success for a descriptor with forged
CRC, compressed size, uncompressed size, or damaged optional signature.
Matching manifest and SHA-256 sidecars therefore cannot attest to consistent
portable extraction metadata across cPanel/Windows/Unix extractors.

**Guard:** On each bit-3 member, seek exactly past its declared compressed payload,
read the optional descriptor signature and the CRC/size fields, and compare them
to the attested central directory. Standard 32-bit and ZIP64 size forms, with
or without the optional descriptor signature, remain allowed when values match;
a missing or inconsistent descriptor fails closed before the global CRC scan.
Existing non-streamed ZIPs do not change.

**Real ZIP regression:** A Python non-seekable writer produces a genuine three-file
streaming Update ZIP with a positive valid descriptor, matching manifest,
file list and SHA-256. Four modified descriptor fields (signature, CRC, compressed
size, uncompressed size) each retain `ZipFile.testzip() == None` and valid
sidecar checksums. Before the source change all four were UNSAFE_ACCEPT.
After the guard each raises `PackageContractError: Conflicting ZIP data descriptor`.
GCC Windows Python 3.9.13: original 42 tests passed; after the source fix all
43 tests pass, `py_compile` and `git diff --check` pass. Hosted exact-head G006
historical published-asset validation must be rechecked after commit.

**Threat / risk:** HIGH, local/offline archive provenance and cross-extractor
integrity. No archive extraction, signatures, operational database or live files
are touched. Customer / Driver / Van: no runtime/API or compiled asset changes.
Backend / Dashboard: no routes, RBAC, operations or UI strings changed.
AR/EN and B2B/VAN-only / B2C/DRIVER-only remain unchanged.

**G006 remains source-incomplete:** This incremental fix does not authenticate
publisher signatures or trusted signing keys, execute a populated MySQL historical
1.0.124 -> 1.0.125 -> 1.0.127 upgrade, verify preserved .env/uploads/data,
or demonstrate backup/restore and interrupted migration recovery. Docker Engine
on the connected GCC device was unavailable for an isolated MySQL container.
No terminal full-source acceptance, production package, release, main merge,
or issue closure is claimed.

## Rehearse the original published ZIPs on nonempty disposable MySQL (2026-11-16)

**New source-backed acceptance job:** `.github/workflows/v2-g006-package-provenance.yml`
now runs `verify-real-historical-mysql-upgrades` **after** the actual-release ZIP
provenance/lineage job succeeds, using a private-to-the-run GitHub-hosted Ubuntu
runner, MySQL 8.0 service, PHP 8.3 and Composer CLI in CI only (never a
production-host terminal requirement). It downloads the exact already-published
unmodified v1.0.125 and v1.0.127 update assets with read-only GitHub access,
rechecks the pinned SHA-256 bytes and validates both archive contents with the
same fail-closed G006 verifier before executing any archive.

Two historical **independent edge tests**, not a fabricated single continuous
upgrade, then run against disposable, independently created MySQL databases:

- Actual 1.0.124 → 1.0.125: a detached v1.0.124 installation and real release
  ZIP are exercised by the checked-in historical `ci-125-recovery-upgrade.sh`
  harness. It seeds owner/order/customer/discount audit records, reproduces a
  partially applied historical migration with missing migration ledger row,
  applies the *published* 125 ZIP, verifies preserved data, upload-file and
  `.env` hashes and idempotent migration retry.
- Actual 1.0.125 → 1.0.127: a detached v1.0.125 baseline and real published
  127 release ZIP are exercised by `ci-127-upgrade.sh` with populated owner,
  B2B order, assignment/discount audit and an upload marker, then verifies
  replay-safe migrations, recovered permission/owner registration, unaffected
  records and unchanged `.env`/upload markers.

Original Git tag refs must equal the separately pinned historical source
commits; the test harness scripts are copied from the exact G006 candidate
into disposable detached tag worktrees, not modified on any release tag.
Each test runs with test-only MySQL credentials inside the temporary CI
environment. The complete runner/worktree/databases are disposable and no
private account, production upload or real server credential is used. The
workflow does **not** publish a new Setup ZIP, Update ZIP, tag or release.
Customer/Driver/Van Flutter, Backend runtime, API, RBAC, Dashboard and AR/EN
user-facing contracts are untouched by this CI/QA-only source increment.

**Acceptance accounting:** Successful local YAML parse, Bash syntax checks
and the 43 existing GCC Python ZIP-unit tests are only preflight. Mark the
two real historical MySQL edge rehearsals as passed **only if the new GitHub
Actions job concludes SUCCESS on the exact committed PR HEAD**; log any
failure and fix on the same original issue branch. Even green CI does not
prove a **continuous same-database 124→125→127 chain**, cryptographic
publisher identity/signature/trust-anchor verification, reversible database
migrations, a real Admin Update Center backup/restore under interrupted file
apply, or signed frozen-final-V2 ZIP deployment. These remain explicit G006,
G005 and P112 source blockers before PR #3364 can become Ready or Issue
#2747 can close. Never mark a backup/recovery scenario successful without
an actual restore/consistency assertion.

### First real-MySQL CI attempt: fail-closed harness bootstrap correction

On original PR head `16194976ecba1942877fe551515b1f20ba1e49e1`, the new
historical-MySQL G006 run
[43769892264](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/43769892264)
verified release downloads, archive SHA/source lineage and the disposable MySQL
connection, but the first apply step stopped **before running any PHP upgrade**:
the old issue branch does not contain
`scripts/verify-recovery-update-manifest.py`, which is already present in
the canonical `Foodex_V2@f5c4c033e5b47766329d31fc3d87b311a1972bc4`
source. This was a CI harness dependency failure, **not** a successfully
executed MySQL upgrade or a database rollback.

The follow-up copies that same immutable canonical helper by GitHub blob
`484a7cb5ba6258d810aaaf7b6a14540962e5e66b` using the already
read-only GitHub download token, and verifies the blob hash with
`git hash-object` **before** passing it into each throwaway tagged worktree.
No dynamic branch ref, other worker file, runtime updater, release package or
production environment is altered. The real-MySQL job is **not** accepted
until the next exact-head run reaches terminal SUCCESS; do not downgrade
preflight security to hide a missing file.

### Second CI attempt: correctly enter historical Git worktree

On `62b4644a351af92c8587c732dba83a05918761e0`, the new
[historical-MySQL job](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/43770539838)
successfully checked exact candidate/tag SHAs, created disposable MySQL,
downloaded and verified both published release archives, and prepared
detached v125/v127 worktrees. It then exited before the first archive
preflight message. The checked-in historical harness uses
`repo_root="$(git rev-parse --show-toplevel)"` and compares its root VERSION
to the historical tag. Calling `bash /absolute/path/to/tag-125/script` from
the G006 candidate worktree silently made its `repo_root` point at the wrong
checkout. The workflow now executes each harness **from its own detached
historical worktree** in a subshell, so `repo_root` and VERSION resolve to
the intended immutable tag. Do not count the aborted attempt as a successful
historical DB upgrade; require terminal green on the new exact PR HEAD.


## Worker E G006 — isolated published-v125 interrupted-migration acceptance (2026-11-16)

**Status: original historical MySQL job RED before this candidate; subsequent GitHub Actions outcome must be checked on the exact new PR head. The larger G006 source gate remains OPEN/DRAFT regardless of this bounded test.**

The pinned, actual **v1.0.125** ZIP and original migration file are *not* edited or re-signed. Its unguarded `Schema::create('order_customer_service_discounts')` truly fails with `SQLSTATE[42S01]` when a previous interrupted MySQL DDL created the table without a `migrations` ledger row. The isolated G006 job deliberately executes and **requires** this original failure first. Other/unexpected migration errors are still fatal.

Only in disposable `APP_ENV=testing`, MySQL `foodex_recovery_125` on `127.0.0.1`, the new **CI-only** `scripts/v2_g006_reconcile_interrupted_125.php` performs strict schema/type/null/default, primary/uniqueness, FK target/deletion-rule, and existing discount-audit-marker validation. It rejects missing or extra columns, an absent unique revision index, wrong foreign-key actions, modified audit data, unexpected database/environment or duplicate ledger rows. The test **removes one unique index temporarily**, verifies that the guard fails closed without recording the migration, restores it, then reconciles only this one verified historical CREATE TABLE fixture to the test `migrations` ledger and retries the original published update. Data/audit, `.env`, upload bytes, a second no-op migration run and the independent v125→127 historical path remain mandatory.

`scripts/v2_g006_patch_historical_recovery.py` modifies an *ephemeral copy* of the historical CI acceptance script in a detached Git worktree, only if its exact pre-existing migration boundary and count match. Neither the published v125/v127 assets nor the canonical `scripts/ci-125-recovery-upgrade.sh`, Laravel updater runtime, live database or release tagging are modified by this PR. Focused GCC Python 3.9: **4/4** adversarial fixture-patcher tests PASS, original v125 shell patch `bash -n` PASS, and CI-only PHP syntax `php -l` PASS. GCC Docker service was stopped; these local validations do **not** prove MySQL. Hosted MySQL CI must be independently checked on the final commit.

**Critical acceptance distinction:** This fixture-specific, structurally verified reconciliation is not an authorized production migration repair or an automatic general recovery algorithm. A trusted publisher signature for historical/final packages, production updater backup/recovery safety, continuous populated MySQL 124→125→127 transitions, actual signed installer/updater packages and staging UAT remain unresolved and tracked by #2747, #2746, #2846 and #2503. No `main`, production, release, tag or deployment is authorized. Customer/Driver/Van code and API behavior are unchanged individually; no AR/EN end-user UI surface is changed in this issue-scoped CI-only increment.

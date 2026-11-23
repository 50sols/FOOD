# GCC local CI — isolated GitHub PR-comment agent

Issue #4405. Integration target Foodex_V2 only. Local evidence never replaces exact-head Required CI.

## Objective

After a one-time setup on GCC, commands flow through GitHub PR comments, not repeated Desktop Commander calls. The Windows scheduled task checks comments every 120 seconds, verifies the command is from the repository owner, validates the exact current PR SHA, runs a fixed test profile in isolated Docker, and posts a summary back to the PR.

## Installation prerequisites

Git, GitHub CLI already authenticated to the FOOD repository, and Docker Desktop running Linux containers. Install the reviewed script at this stable location on GCC:

    %LOCALAPPDATA%\FoodexGccCI\gcc-agent.ps1

Download from an exact reviewed commit SHA, not a mutable branch ref. Test prerequisites and install using:

    powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$env:LOCALAPPDATA\FoodexGccCI\gcc-agent.ps1" -Mode Doctor
    powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$env:LOCALAPPDATA\FoodexGccCI\gcc-agent.ps1" -Mode Install

Install initializes the cursor to the latest existing repository comment, registers the FOODEX-GCC-Local-CI Windows logon task, and starts it. Pre-existing comments are ignored.

## Request syntax

Post a PR conversation comment authored by walidatiyaai2025-gif, on an open same-repository issue-style PR targeting Foodex_V2:

    /gcc-ci run 0123456789abcdef0123456789abcdef01234567

Replace the sample SHA with that PR's actual current full 40-character head SHA.

The agent rejects comments from other users, a different repository, non-V2 PRs, closed PRs, fork heads, unknown branch naming, and SHA changes. It does not accept shell commands from GitHub. The first batch runs only CI map/failure registry validation and focused Python tests.

## Evidence

The agent posts a PASS/FAIL comment with PR number, exact SHA and exit code; results are advisory. Logs stay on GCC under:

    %LOCALAPPDATA%\FoodexGccCI\test-<PR>-<COMMENT_ID>.log

It keeps a cursor in cursor.json to avoid repeating handled comments. Requests that fall outside the latest 100 comments between polls need to be re-issued.

## Isolation

Docker image: python:3.12 (includes Git for planner regression tests). Security options: offline network, read-only code mount, read-only container filesystem, no host credentials, no Docker socket, no elevated capabilities, CPU/memory/PID limits. Test command is fixed in the agent script.

This initial profile does not validate Laravel, Composer, Flutter, MySQL, Redis, signed Setup ZIP, Update ZIP, or real installer/recovery. Add backend and full test profiles only after review of isolated execution and parity. Never treat this local advisory evidence as equivalent to GitHub Required CI, product acceptance or production readiness.

Do not register GCC as a general self-hosted runner on the public FOOD repository. This is an opt-in guarded alternative to exposing the Windows workstation to GitHub PR code.

## Diagnostics / stopping

    powershell.exe -File "$env:LOCALAPPDATA\FoodexGccCI\gcc-agent.ps1" -Mode Doctor
    powershell.exe -File "$env:LOCALAPPDATA\FoodexGccCI\gcc-agent.ps1" -Mode Once
    Get-ScheduledTask -TaskName FOODEX-GCC-Local-CI
    Stop-ScheduledTask -TaskName FOODEX-GCC-Local-CI
    Unregister-ScheduledTask -TaskName FOODEX-GCC-Local-CI -Confirm:$false

No installation, test success, GitHub evidence, or scheduled service status should be claimed without verification.


## GCC heavyweight execution (owner extension, same #4405/PR #4406)

The agent additionally accepts an owner-authored comment on an open same-repository V2 PR:

    /gcc-ci full <exact 40-character current PR head SHA>

The full profile uses the **pinned, immutable** local toolkit from repository commit
`a2d2beb6ef39a60a88c2fbfadce533c432296b9f` (container build and execution scripts), verifying each
Git blob SHA before running. The toolkit is downloaded once, locally cached and
checked again for integrity at every full job. The agent first validates the
PR owner/base/head, fetches the exact PR commit and the v1.0.125 update base,
then uses Docker Compose with a unique per-request project name.

The isolated containers cover:
- PHP 8.3, Composer dependencies from the PR's exact lockfile, Composer audit,
  static analysis, PHP lint, and the pinned OpenAPI linter;
- deterministic exhaustive four-shard Laravel PHPUnit suite, SQLite
  fresh migration/seed, Node live-map acceptance;
- disposable MySQL 8 + Redis 7 deployment/installer/updater/recovery tests;
- one **production-vendor** Setup ZIP (no dev Composer dependencies), path,
  duplicate/symlink, environment-file checks, SHA256 and the existing
  full clean-install HTTP/browser contract against that same ZIP;
- the repository's canonical Update ZIP builder against v1.0.125, including
  structural validation and staged SHA256 if the required preview/runtime
  assets and version boundary are available.

For safety, the Composer dependency acquisition phase is an isolated container
with only the source checkout mounted read-only and an external network for
downloads. It uses --no-scripts and --no-plugins with no host secrets. The
actual Laravel tests and package acceptance run on the isolated database
network only; the runner has no general outbound network, Docker socket, host
profile or GitHub token. A dedicated Composer download volume persists between
jobs; all per-job code, database and Redis volumes are discarded after use.

Detailed logs and unsigned ZIP candidates remain exclusively on GCC:
- %LOCALAPPDATA%\FoodexGccCI\full-<PR>-<COMMENT_ID>.log
- %LOCALAPPDATA%\FoodexGccCI\evidence\<PR>-<COMMENT_ID>\

GitHub receives the exact SHA, PASS/FAIL summary, and ZIP SHA-256 values as a
PR comment. Never copy or publish logs containing installation tokens,
generated credentials, or runtime secrets to the public repository.

**Do not call an unsigned staging Setup/Update ZIP a signed production
artifact.** The current runner explicitly records signed=false,
trusted_release=false, and full_upgrade_acceptance=false. It checks the real
fresh Setup ZIP and the updater's existing MySQL recovery tests; separate
same-Update-ZIP version-to-version upgrade, trusted-signature provenance and
independent production-grade cPanel acceptance remain release gates. Failures
keep the PR and issue open. A local PASS is *advisory* and does not replace
Required CI, V2 Isolation, branch protection or manual release approval.

### Minutes and rollout switch

The trust-preserving rollout order is:
1. Keep the existing GitHub-hosted Required CI unmodified while proving the
   local pipeline on the same exact commit and comparing all applicable jobs.
2. After full parity and independently reviewed evidence exist, implement
   an explicit opt-in branch-protection-aware migration to trusted local
   checks, retaining fast hosted repository policy/isolation/last-commit
   verification. Fail closed when GCC is offline; never mark a skipped
   hosted job as passed without equivalent verified local acceptance.
3. Monitor billed runner minutes and local execution time by workflow,
   revise the routing map and keep rollback to hosted runners.

GitHub Actions minutes are **not yet reduced** simply by installing this
local worker: the repository's existing hosted Required CI continues until
the verification and ruleset migration is independently complete.


### Flutter preview / release prerequisites on isolated V2

The full GCC job additionally runs a pinned official Flutter 3.47.5 SDK
checkout inside a dedicated Docker image and builds the **existing**
Customer+Driver Flutter Web previews from the PR's exact SHA. It does not
modify deployed mobile apps or create Android APKs. This fills the runtime
preview input directories required by the existing Update ZIP packager and
uses a persistent Flutter pub download cache to reduce repeated pulls.

**Verified current blockers on Foodex_V2 (2026-11-23):**
- backend/composer.lock is absent from the integration branch. PR #3444
  (original G004/#2745, still DRAFT) owns the pending reproducible lockfile.
  GCC intentionally refuses to build/test full unsigned packages without
  the lock; do not merge #3444 merely to accelerate CI or duplicate its work.
- The source-tracked Preview Web build folders are absent; the GCC preview
  phase now builds them from source, but cannot be marked PASS until run.
- Actual signed Setup and Update ZIP artifacts, full same-Update-ZIP upgrade,
  and independent release provenance remain REQUIRED before production.
  Docker contract tests and image creation are not installation evidence.

The full pipeline will only be **activated on GCC after the exact-head
PowerShell script and pinned toolkit are verified on the authorized machine**.
An open PR containing the tools alone is not evidence of a running job.


## Scoped full-test commands and safe diagnostic output

The agent also accepts these *exact-SHA*, owner-authorized PR commands:

    /gcc-ci backend <40-character PR head>
    /gcc-ci setup <40-character PR head>
    /gcc-ci packages <40-character PR head>

- backend validates PHP/Composer, lint/static analysis, all four exhaustive
  PHPUnit shards, SQLite migration/seed and MySQL 8/Redis 7 deployment and
  recovery, producing local JUnit files (not raw public log uploads).
- setup builds Customer/Driver Web previews and the real production-dependency
  Setup ZIP, verifies its contents and exercises the existing clean-install
  HTTP/Playwright installer contract using that same ZIP. Does **not** claim
  that an Update ZIP works.
- packages runs setup acceptance and then attempts the canonical Update ZIP.
  It must **fail closed** when vendor/Composer inputs differ from the supported
  base. This is necessary because the existing updater protects vendor/ and
  does not implement a proven atomic dependency transition.
- full runs backend + setup + update; it is PASS only if every applicable stage
  succeeds. All profiles verify exact PR source SHA, preserve evidence locally
  and leave the existing GitHub Required CI authoritative.

On a failure, the PR comment contains a bounded stage label (for example,
docker-image-build, composer-dependencies or mysql-redis-startup), but no
server logs, credentials or test account tokens.

The canonical release-update-bundle workflow also stages TCPDF from the
immutable commit b18f6119161019916c5bb07cb8da5205ae5c1b63. The updated
GCC toolkit reproduces this pinned asset plus its cURL fallback, checks
Composer lock consistency and verifies the complete canonical Update ZIP
manifest/checksum and critical public/preview/PDF files.

**Actual results, not projected outcomes:** First Docker images downloaded and
built locally on #3444, but the first full test returned FAIL before confirmed
Laravel/ZIP acceptance. The diagnostic version must be tested on GCC, and
no online runner-minutes reduction or release certification is yet proven.

### Docker Desktop/Compose Bake compatibility (GCC regression)

The first GCC Docker image build completed Dockerfile layers but Compose
returned the known Buildx `failed to execute bake: read |0: file already closed`
failure (docker/compose #13243). The local agent now builds backend once
with `docker build --progress=plain`, aliases it for the runner, and builds
Flutter separately only when required. It starts services with
`COMPOSE_BAKE=false` and `docker compose up --no-build`, preserving cached
layers and ensuring the real Docker exit code determines PASS/FAIL.

Do not infer runtime acceptance from successful Docker image construction;
the corrected GCC backend, Setup and Update tests must actually pass.

### Windows checkout line-ending parity

The exact-SHA source is copied into an isolated Linux workspace. A Windows
working tree may use CRLF even though tracked Git blobs use LF; the old
`git diff --check HEAD` treated these EOL conversions as large whitespace
diffs and blocked Composer before installation. The preparation script now
re-checks SHA, regenerates the *disposable* Linux working tree from exact
Git blobs with `core.autocrlf=false` and LF, and then checks for true content
diffs. It does not reset or edit the GCC host checkout.

### Network-backed security audit separation

On GCC, Composer lock install remains mandatory. The Packagist security advisory
API timed out with curl error 28 on GCC after all packages installed. The
local preparation now avoids that live endpoint and a second npx OpenAPI lint.
Both Composer security audit and pinned Redocly remain mandatory in the
existing GitHub Backend fast-fail workflow for the same exact PR head.
No green local security result is inferred from this split. Do not remove
GitHub Required CI before independent trusted local parity is verified.

### Avoid repeat downloads in scoped Backend tests

A backend-only GCC run now passes its requested profile to the trusted
Composer preparer, skips the TCPDF Git fetch, skips the second no-dev vendor
tree used only for ZIP packaging, and skips the unrelated update-base Git
tag fetch. The Setup/packages/full profiles still build the production
vendor tree and pin the exact TCPDF source. This reduces local runtime,
Composer traffic, and GitHub API/remote communication per acceptance job.

Backend PHP syntax-checking on GCC is parallelized across its four allowed
container CPUs using zero-delimited filenames and fail-closed xargs status;
this reduces startup overhead without skipping any PHP source file.

The first genuine GCC Backend run reached Laravel Pint but Compose reported
Pint exceeding Composer's default 300-second child process timeout. The
optimized backend profile now omits the package-only TCPDF tree, parallelizes
PHP lint, and allows Pint/PHPStan up to 900 seconds with a separate bounded
outer timeout. Checks must actually pass; timeouts remain failures.

The first real Setup run progressed to the Flutter preview stage, where Git
refused /workspace as dubious ownership because its files are intentionally
chowned to the unprivileged runner UID. The trusted preview script now uses
only `git -c safe.directory=/workspace` for the exact commit verification,
not a global ownership or safety bypass.

### Canonical web installer evidence context

The real scripts/validate-fresh-setup.sh acceptance also consumes the
GITHUB_SHA and GITHUB_WORKSPACE variables used on GitHub Actions. GCC's
trusted runner now binds both to the immutable requested source and its
isolated writable Linux /workspace volume; a mismatched provided SHA is
rejected. The canonical 24 Playwright screenshots and source SHA are not
skipped or replaced with synthetic evidence.

### Full-shard failure evidence, no test masking

The first real GCC backend suite ran 1207 cases in shard 0 and found one
failure in the time-sensitive SchedulerRuntimeTest throttle assertion (the
underlying scheduler call took 62 seconds in Docker). The test is NOT
waived. GCC now executes each of the four deterministic PHPunit partitions
even if a previous partition fails, captures each JUnit result, enforces a
30-minute bound per shard, and refuses overall PASS when ANY shard fails.
Fix the original flaky clock assumption under its owning code change,
or obtain a genuinely green test; do not fake successful parity.

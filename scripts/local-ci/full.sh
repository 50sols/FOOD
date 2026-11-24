#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
test -n "$GCC_EXPECTED_SHA"
test "$(git -C /workspace rev-parse HEAD)" = "$GCC_EXPECTED_SHA"
# Canonical fresh-install acceptance was authored for GitHub Actions and
# requires these exact-head context variables for 24 real UI screenshots
# and immutable provenance. Supply the same contract in disposable GCC.
if [[ -n "${GITHUB_SHA:-}" && "$GITHUB_SHA" != "$GCC_EXPECTED_SHA" ]]; then
  echo 'Refusing GitHub source SHA mismatch in GCC acceptance' >&2; exit 2
fi
export GITHUB_SHA="$GCC_EXPECTED_SHA"
export GITHUB_WORKSPACE=/workspace
cd /workspace
version="$(tr -d '[:space:]' < VERSION)"
test -n "$version"
export CURRENT_VERSION="$version"
profile="$GCC_PROFILE"
case "$profile" in all|backend|packages|setup) ;; *) echo 'Unsupported GCC profile' >&2; exit 2 ;; esac
mkdir -p /evidence
step=startup
failed() {
  rc="$?"
  echo "GCC local CI FAIL step=$step exit=$rc source=$GCC_EXPECTED_SHA" >&2
  # Only publish a fixed diagnostic category from ephemeral installer output.
  # Raw HTTP/Laravel logs can contain credentials: never copy or stringify them
  # into the evidence JSON or the public GitHub PR comment.
  python3 - "$GCC_EXPECTED_SHA" "$version" "$step" "$rc" "${fresh_setup_log:-}" > /evidence/result.json <<'PY'
import json, pathlib, re, sys
sha, version, stage, exit_code, path = sys.argv[1:]
result = dict(schema=1, result="FAIL", source_sha=sha, version=version,
              failed_stage=stage, exit_code=int(exit_code),
              trusted_release=False)
if stage == "fresh-install-real-setup" and path:
    log = pathlib.Path(path)
    # Keep only a bounded private tail; the public evidence contains known
    # categorical identifiers, never log text or HTTP response bodies.
    if log.is_file():
        with log.open("rb") as stream:
            stream.seek(0, 2)
            stream.seek(max(stream.tell() - 131072, 0))
            messages = stream.read().decode("utf-8", errors="replace")
        category = "unclassified"
        if "Finish HTTP response indeterminate" in messages:
            category = "finish-response-indeterminate"
        elif "first-attempt HTTP transport: category=timeout" in messages:
            category = "migration-timeout"
        elif "first-attempt HTTP transport: category=connection_refused" in messages:
            category = "migration-connection-refused"
        elif "first-attempt HTTP transport: category=empty_reply" in messages:
            category = "migration-empty-reply"
        elif "G004 original HTTP migration failure: SQLSTATE=" in messages:
            category = "migration-sqlstate"
        elif "G004 safe installer error category:" in messages:
            category = "installer-validation"
        elif "Web installer step " in messages and " returned HTTP " in messages:
            category = "installer-http-status"
        elif "readiness timed out" in messages:
            category = "http-readiness-timeout"
        elif "server exited before becoming ready" in messages:
            category = "http-server-exited"
        result["failure_class"] = category
        # Only an integer 1..15 may leave the ephemeral local diagnostic.
        step_match = re.search(r"Web installer step (1[0-5]|[1-9]) returned HTTP [0-9]{3}", messages)
        if step_match:
            result["installer_step"] = int(step_match.group(1))
print(json.dumps(result, sort_keys=True))
PY
  exit "$rc"
}
trap failed ERR

if [[ "$profile" == backend || "$profile" == all ]]; then
  step=php-lint-analysis
  cd /workspace/backend
  test -s vendor/autoload.php
  php artisan package:discover --ansi
  # Preserve every PHP syntax failure while using GCC's four allocated CPUs.
  # Zero-delimited paths avoid shell injection and spaces in filenames.
  find app routes tests -type f -name '*.php' -print0 | xargs -0 -r -n1 -P4 php -l >/dev/null
  # Windows Docker Desktop is slower than hosted Linux for Pint/PHPStan.
  # Extend Composer's 300s child-process limit without suppressing failures;
  # an outer bound still fails closed if either check hangs.
  export COMPOSER_PROCESS_TIMEOUT=900
  timeout --kill-after=15s 930s composer lint
  timeout --kill-after=15s 930s composer analyse
  python3 ../scripts/laravel-test-shards.py --verify --count 4
  step=sqlite-migration-seeder
  cp .env.example .env
  php artisan key:generate --force
  mkdir -p database
  touch database/database.sqlite
  DB_CONNECTION=sqlite DB_DATABASE=database/database.sqlite php artisan migrate:fresh --force
  DB_CONNECTION=sqlite DB_DATABASE=database/database.sqlite php artisan db:seed --force
  node --test tests/Browser/driver-live-map.test.cjs
  step=complete-laravel-shards
  shard_failures=0
  for shard in 0 1 2 3; do
    python3 ../scripts/laravel-test-shards.py --count 4 --index "$shard" --output ".phpunit-gcc-$shard.xml"
    # Keep complete JUnit evidence for ALL shards even after an individual
    # failure. This never turns red tests green; it avoids rerunning costly
    # prior shards just to find a later failing fixture.
    # PHPUnit's canonical database is :memory: (backend/phpunit.xml).
    # The earlier file-backed override reconnected installer tests to a new
    # empty memory database, masking existing-data guards on GCC only.
    if DB_CONNECTION=sqlite DB_DATABASE=:memory: \
      timeout --kill-after=15s 1800s vendor/bin/phpunit \
        --configuration=".phpunit-gcc-$shard.xml" --log-junit="/evidence/laravel-shard-$shard.xml"; then
      echo "GCC Laravel shard $shard PASS"
    else
      shard_failures=$((shard_failures+1))
      echo "GCC Laravel shard $shard FAIL (retained JUnit evidence)" >&2
    fi
  done
  if (( shard_failures > 0 )); then
    echo "GCC backend has $shard_failures failed shards; refusing a PASS" >&2
    exit 1
  fi
  step=mysql-redis-deployment-recovery
  mysqladmin ping --host=127.0.0.1 --port=3306 --user=root --password=root --silent
  DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
  DB_DATABASE=foodex_acceptance DB_USERNAME=root DB_PASSWORD=root \
  CACHE_STORE=redis QUEUE_CONNECTION=redis REDIS_HOST=127.0.0.1 \
  FOODEX_DISPOSABLE_SERVICES=true FOODEX_MYSQL_CLIENT_BINARY=/usr/bin/mysql \
  FOODEX_MYSQL_DUMP_BINARY=/usr/bin/mysqldump \
    vendor/bin/phpunit tests/Deployment --log-junit=/evidence/deployment.xml
  cd /workspace
fi

if [[ "$profile" == packages || "$profile" == all || "$profile" == setup ]]; then
  step=build-production-setup
  test -s /workspace/production-backend/vendor/autoload.php
  stage=/workspace/gcc-package-stage
  rm -rf "$stage"
  mkdir -p "$stage/backend" /workspace/Release
  cp VERSION "$stage/VERSION"
  # Match the authoritative G004 Setup staging file set, rather than
  # constructing a second, differently filtered ZIP on GCC.
  rsync -a /workspace/backend/ "$stage/backend/" \
    --exclude='/vendor/' \
    --exclude='/.env' \
    --exclude='tests/' \
    --exclude='docs/' \
    --exclude='.phpunit.cache/' \
    --exclude='/storage/' \
    --exclude='/bootstrap/cache/' \
    --exclude='/public/storage' \
    --exclude='/public/uploads'
  rsync -a /workspace/production-backend/vendor/ "$stage/backend/vendor/"
  mkdir -p "$stage/backend/storage/app/public" \
    "$stage/backend/storage/app/private" \
    "$stage/backend/storage/app/system" \
    "$stage/backend/storage/logs" \
    "$stage/backend/storage/framework/sessions" \
    "$stage/backend/storage/framework/cache/data" \
    "$stage/backend/storage/framework/views" \
    "$stage/backend/storage/framework/testing" \
    "$stage/backend/bootstrap/cache"
  cp Release/INSTALL-FIRST.txt "$stage/INSTALL-FIRST.txt"
  test -s "$stage/backend/vendor/autoload.php"
  if find "$stage" -type l -print -quit | grep -q .; then
    echo 'Refusing symlinks in Setup ZIP' >&2; exit 1
  fi
  setup=/workspace/Release/FOODEX-Laravel-Setup.zip
  repeat=/workspace/Release/.G004-setup-repeat.zip
  rm -f "$setup" "$repeat"
  source_epoch="$(git show -s --format=%ct "$GCC_EXPECTED_SHA")"
  test -n "$source_epoch"
  python3 scripts/v2_build_setup_zip.py \
    --source-root "$stage" --output "$setup" --source-date-epoch "$source_epoch"
  python3 scripts/v2_build_setup_zip.py \
    --source-root "$stage" --output "$repeat" --source-date-epoch "$source_epoch"
  cmp "$setup" "$repeat"
  rm -f "$repeat"
  step=verify-real-setup-zip
  python3 - "$setup" "$version" <<'PY'
import sys, zipfile, pathlib, stat
name, version = sys.argv[1:]
with zipfile.ZipFile(name) as z:
    names = z.namelist()
    if any(pathlib.PurePosixPath(p).is_absolute() or ".." in pathlib.PurePosixPath(p).parts for p in names):
        raise SystemExit("Setup ZIP path traversal")
    if len(names) != len(set(names)):
        raise SystemExit("Setup ZIP duplicate entry")
    if any(stat.S_ISLNK(info.external_attr >> 16) for info in z.infolist()):
        raise SystemExit("Setup ZIP symlink")
    # Explicitly allow only public installer templates. A stray .env.local,
    # .env.production, .env.backup or nested dotenv is a secret-bearing file.
    allowed_env_templates = {"backend/.env.example", "backend/.env.production.example"}
    if any(
        pathlib.PurePosixPath(p).name.startswith(".env")
        and p not in allowed_env_templates
        for p in names
    ):
        raise SystemExit("Secrets in Setup ZIP")
    required = {"VERSION", "backend/artisan", "backend/vendor/autoload.php",
                "backend/public/index.php", "backend/.env.production.example"}
    missing = required - set(names)
    if missing: raise SystemExit(f"Setup ZIP missing: {sorted(missing)}")
    if z.read("VERSION").decode().strip() != version:
        raise SystemExit("Setup ZIP wrong version")
PY
  # Reuse the exact G004 staging verifier for checksums, portable paths,
  # source provenance and secret exclusions before running /install.
  python3 scripts/v2_setup_staging_verify.py \
    --zip "$setup" --version-file VERSION \
    --source-commit "$GCC_EXPECTED_SHA" \
    --evidence Release/V2_SETUP_STAGING_EVIDENCE.json
  cp "$setup" "/evidence/FOODEX-Setup-v$version.zip"
  step=fresh-install-real-setup
  mysql -h127.0.0.1 -uroot -proot -e \
    'DROP DATABASE IF EXISTS foodex_acceptance; CREATE DATABASE foodex_acceptance;'
  # Capture a local tmpfs diagnostic for fail-closed classification in the
  # ERR trap. The same output still goes to the PRIVATE Docker/GCC log.
  # Never upload this file or quote it in a PR comment.
  fresh_setup_log=/tmp/foodex-gcc-fresh-setup.log
  CURRENT_VERSION="$version" bash scripts/validate-fresh-setup.sh 2>&1 | tee "$fresh_setup_log"
  rm -f "$fresh_setup_log"
  fresh_setup_log=''
  test -s Release/FRESH_INSTALL_EVIDENCE.json
  cp Release/FRESH_INSTALL_EVIDENCE.json /evidence/fresh-install-evidence.json
  if [[ "$profile" != setup ]]; then
  step=verify-safe-update-dependency-boundary
  test "$(git show v1.0.125:VERSION | tr -d '[:space:]')" = 1.0.125
  # Canonical updater excludes vendor/ from ZIP payload. If production
  # Composer inputs changed, a plain dashboard ZIP cannot safely upgrade
  # the installed dependencies. Fail closed; full redeploy is mandatory.
  if ! git diff --quiet v1.0.125..HEAD -- backend/composer.json backend/composer.lock; then
    echo "BLOCKED: Composer dependencies differ from v1.0.125; ZIP update cannot safely replace protected vendor. Require reviewed full-deploy transition." >&2
    exit 42
  fi
  step=build-update-zip
  python3 scripts/build-update-bundle.py --base v1.0.125 \
    --target-version "$version" --minimum-current-version 1.0.125 \
    --output-dir /evidence/update --release-notes "UNTRUSTED GCC STAGING ONLY; not a signed release"
  step=verify-update-zip
  python3 - "$GCC_EXPECTED_SHA" "$version" <<'PY'
import pathlib, zipfile, hashlib, json, sys, stat
source_sha, version = sys.argv[1:]
folder=pathlib.Path("/evidence/update")
package=folder/"FOODEX-Update.zip"
manifest_path=folder/"FOODEX-Update.json"
hash_file=folder/"FOODEX-Update.sha256.txt"
if not all(p.is_file() for p in (package,manifest_path,hash_file)):
    raise SystemExit("Incomplete canonical update ZIP/manifest/checksum set")
manifest=json.loads(manifest_path.read_text(encoding="utf-8"))
digest=hashlib.sha256(package.read_bytes()).hexdigest()
for key, expected in {
    "target_version":version,
    "minimum_current_version":"1.0.125",
    "source_commit":source_sha,
    "sha256":digest,
    "requires_full_redeploy":False,
}.items():
    if manifest.get(key) != expected:
        raise SystemExit(f"Invalid Update manifest {key}: {manifest.get(key)!r}")
if hash_file.read_text(encoding="utf-8").split()[0]!=digest:
    raise SystemExit("Update package SHA-256 sidecar does not match")
with zipfile.ZipFile(package) as z:
    if z.testzip() is not None: raise SystemExit("Update ZIP CRC invalid")
    members=z.infolist()
    names=[x.filename for x in members]
    if len(names)!=len(set(names)): raise SystemExit("Duplicate ZIP entries")
    if any(stat.S_ISLNK(x.external_attr >> 16) for x in members):
        raise SystemExit("Update ZIP symlink entry")
    if any(p.startswith("/") or ".." in pathlib.PurePosixPath(p).parts for p in names):
        raise SystemExit("Update ZIP traversal")
    forbidden=("backend/vendor/","backend/storage/",".git/")
    if any(p.startswith(forbidden) or p.endswith("/.env") for p in names):
        raise SystemExit("Update ZIP touches protected files")
    required={
       "VERSION", "backend/app/Domain/Installer/InstallerWorkflow.php",
       "backend/app/Domain/Updater/LaravelUpdateRuntime.php",
       "backend/app/ThirdParty/tcpdf/tcpdf.php",
       "backend/app/ThirdParty/tcpdf/LICENSE.TXT",
       "backend/app/ThirdParty/tcpdf/fonts/dejavusans.php",
       "backend/app/ThirdParty/tcpdf/fonts/dejavusans.z",
       "backend/bootstrap/app.php", "backend/public/.htaccess",
    }
    for app in ("customer","driver"):
        required.update({
            f"backend/public/preview/{app}/index.html",
            f"backend/public/preview/{app}/main.dart.js",
            f"backend/public/preview/{app}/flutter_bootstrap.js",
            f"preview/{app}/index.html", f"preview/{app}/main.dart.js",
            f"preview/{app}/flutter_bootstrap.js",
        })
    if required - set(names):
        raise SystemExit(f"Missing Update runtime files: {sorted(required-set(names))}")
print("UPDATE_SHA256="+digest)
PY
  fi
fi

step=write-evidence
python3 - "$GCC_EXPECTED_SHA" "$version" "$profile" > /evidence/result.json <<'PY'
import hashlib,json,pathlib,sys
source,version,profile=sys.argv[1:]
root=pathlib.Path("/evidence")
artifacts={p.name:hashlib.sha256(p.read_bytes()).hexdigest()
           for p in root.rglob("*.zip") if p.is_file()}
if profile in {"packages","all","setup"} and not artifacts:
    raise SystemExit("Package artifacts missing")
print(json.dumps(dict(schema=1,result="PASS",source_sha=source,
 version=version,profile=profile,artifacts_sha256=artifacts,
 trusted_release=False,full_upgrade_acceptance=False,signed=False,
 environment="disposable-gcc-docker"),sort_keys=True))
PY
echo "GCC $profile completed for $GCC_EXPECTED_SHA — STAGING ONLY"

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
  python3 - "$GCC_EXPECTED_SHA" "$version" "$step" "$rc" > /evidence/result.json <<'PY'
import json, sys
print(json.dumps(dict(schema=1, result="FAIL", source_sha=sys.argv[1],
    version=sys.argv[2], failed_stage=sys.argv[3], exit_code=int(sys.argv[4]),
    trusted_release=False), sort_keys=True))
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
  for shard in 0 1 2 3; do
    python3 ../scripts/laravel-test-shards.py --count 4 --index "$shard" --output ".phpunit-gcc-$shard.xml"
    DB_CONNECTION=sqlite DB_DATABASE=database/database.sqlite vendor/bin/phpunit \
      --configuration=".phpunit-gcc-$shard.xml" --log-junit="/evidence/laravel-shard-$shard.xml"
  done
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
  rsync -a --exclude=vendor --exclude=.env --exclude=tests --exclude=docs \
    --exclude='.phpunit.cache' --exclude='storage/logs/*' \
    --exclude='storage/app/system/*' /workspace/backend/ "$stage/backend/"
  rsync -a /workspace/production-backend/vendor/ "$stage/backend/vendor/"
  rm -f "$stage/backend/bootstrap/cache/"*.php
  test -s "$stage/backend/vendor/autoload.php"
  if find "$stage" -type l -print -quit | grep -q .; then
    echo 'Refusing symlinks in Setup ZIP' >&2; exit 1
  fi
  setup=/workspace/Release/FOODEX-Laravel-Setup.zip
  rm -f "$setup"
  (cd "$stage" && zip -qr "$setup" .)
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
    if any(p.endswith("/.env") or p == "backend/.env" for p in names):
        raise SystemExit("Secrets in Setup ZIP")
    required = {"VERSION", "backend/artisan", "backend/vendor/autoload.php",
                "backend/public/index.php", "backend/.env.production.example"}
    missing = required - set(names)
    if missing: raise SystemExit(f"Setup ZIP missing: {sorted(missing)}")
    if z.read("VERSION").decode().strip() != version:
        raise SystemExit("Setup ZIP wrong version")
PY
  cp "$setup" "/evidence/FOODEX-Setup-v$version.zip"
  step=fresh-install-real-setup
  mysql -h127.0.0.1 -uroot -proot -e \
    'DROP DATABASE IF EXISTS foodex_acceptance; CREATE DATABASE foodex_acceptance;'
  CURRENT_VERSION="$version" bash scripts/validate-fresh-setup.sh
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

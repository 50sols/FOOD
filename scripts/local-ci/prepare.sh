#!/usr/bin/env bash
set -Eeuo pipefail

# Network is available ONLY for vetted dependency acquisition.
# No GitHub tokens, host credentials, Docker socket, or arbitrary PR commands.
test -n "$GCC_EXPECTED_SHA"
test "$(git -C /source rev-parse HEAD)" = "$GCC_EXPECTED_SHA"
test -f /source/backend/composer.json
test -f /source/backend/composer.lock
test -f /source/VERSION
test -d /source/scripts

mkdir -p /workspace /composer-cache
cp -a /source/. /workspace/
cd /workspace
test "$(git rev-parse HEAD)" = "$GCC_EXPECTED_SHA"
# Windows Git may checkout CRLF text although the indexed blobs are LF.
# In this disposable Linux Docker volume, rematerialize the EXACT validated
# SHA from Git objects with LF checkout. Never mutate the host /source mount.
git -c core.autocrlf=false -c core.eol=lf reset --hard "$GCC_EXPECTED_SHA" >/dev/null
test "$(git rev-parse HEAD)" = "$GCC_EXPECTED_SHA"
git -c core.autocrlf=false diff --exit-code --check HEAD
git -c core.autocrlf=false diff --quiet HEAD -- backend/composer.json backend/composer.lock

cd backend
composer validate --strict
composer install --no-interaction --prefer-dist --no-progress --no-scripts --no-plugins
# The existing required GitHub Backend fast-fail checks perform Composer's
# live security advisory audit and Redocly OpenAPI lint against THIS PR head.
# Both are network-dependent; Packagist DNS timeouts must not prevent GCC's
# isolated Laravel/MySQL/ZIP runtime acceptance. Do not claim their PASS here.
# Keep that GitHub security contract mandatory; never bypass it from GCC.
echo 'GCC: Composer audit and OpenAPI lint delegated to mandatory GitHub Backend fast-fail'

cd /workspace

# Same exact pinned TCPDF source and cURL fallback patch as the canonical
# release-update-bundle workflow. No mutable branch or unreviewed git HEAD.
tcpdf=/tmp/foodex-gcc-tcpdf
mkdir -p "$tcpdf"
git -C "$tcpdf" init --quiet
git -C "$tcpdf" remote add origin https://github.com/tecnickcom/TCPDF.git
git -C "$tcpdf" fetch --quiet --depth=1 origin b18f6119161019916c5bb07cb8da5205ae5c1b63
test "$(git -C "$tcpdf" rev-parse FETCH_HEAD)" = b18f6119161019916c5bb07cb8da5205ae5c1b63
git -C "$tcpdf" checkout --quiet --detach FETCH_HEAD
target=/workspace/backend/app/ThirdParty/tcpdf
mkdir -p "$target"
rsync -a --exclude=.git --exclude=examples --exclude=tests --exclude=test \
  "$tcpdf/" "$target/"
python3 - <<'PY'
from pathlib import Path
import re
p=Path("/workspace/backend/app/ThirdParty/tcpdf/include/tcpdf_static.php")
s=p.read_text(encoding="utf-8")
s, a=re.subn(r"protected const CURLOPT_DEFAULT = \[.*?\n    \];",
    "protected const CURLOPT_DEFAULT = [];",s,count=1,flags=re.DOTALL)
s, b=re.subn(r"protected const CURLOPT_FIXED = \[.*?\n    \];",
    "protected const CURLOPT_FIXED = [];",s,count=1,flags=re.DOTALL)
if a!=1 or b!=1: raise SystemExit("TCPDF pinned cURL patch drift")
p.write_text(s,encoding="utf-8")
PY
for required in tcpdf.php LICENSE.TXT fonts/dejavusans.php fonts/dejavusans.z; do
  test -s "$target/$required"
done

# Separate locked PRODUCTION vendor: no-dev + no scripts/plugins.
# Never package the dev/vendor tree.
mkdir -p /workspace/production-backend
rsync -a --exclude=vendor --exclude=.env --exclude=tests --exclude=storage/logs \
  /workspace/backend/ /workspace/production-backend/
composer install --working-dir=/workspace/production-backend --no-dev \
  --no-interaction --prefer-dist --no-progress --no-scripts --no-plugins
test -s /workspace/production-backend/vendor/autoload.php

# The sandbox runs unprivileged against the isolated MySQL+Redis namespace.
chown -R 10001:10001 /workspace
echo "GCC prepare PASS for $GCC_EXPECTED_SHA"

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
git diff --check HEAD

cd backend
composer validate --strict --no-check-lock
composer install --no-interaction --prefer-dist --no-progress --no-scripts --no-plugins
composer audit --no-interaction

cd /workspace
REDOCLY_TELEMETRY=off REDOCLY_SUPPRESS_UPDATE_NOTICE=true npx --yes @redocly/cli@2.59.0 lint docs/api/openapi.yaml

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

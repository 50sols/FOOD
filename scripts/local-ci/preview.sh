#!/usr/bin/env bash
set -Eeuo pipefail
test -n "$GCC_EXPECTED_SHA"
# The preparer deliberately chowns the disposable volume to the runner UID.
# Flutter builder runs as root; allow ONLY this exact isolated checkout path
# rather than a global trust bypass or safe.directory=*.
test "$(git -c safe.directory=/workspace -C /workspace rev-parse HEAD)" = "$GCC_EXPECTED_SHA"
export PUB_CACHE=/flutter-cache
cd /workspace
flutter --version
flutter --version | grep -F 'Flutter 3.47.5'
for app in customer driver; do
  cd "/workspace/apps/$app"_app
  if [[ "$app" == driver ]]; then
    flutter create --platforms=web --project-name foodex_driver_app .
  fi
  flutter pub get
  flutter build web --release --target=lib/preview_main.dart \
    --base-href="/preview/$app/" \
    --dart-define=FOODEX_PREVIEW_CONTRACT_VERSION=shared-flutter-v1
  test -s build/web/index.html
  test -s build/web/main.dart.js
  test -s build/web/flutter_bootstrap.js
  mkdir -p "/workspace/backend/public/preview/$app"
  cp -a build/web/. "/workspace/backend/public/preview/$app/"
done
chown -R 10001:10001 /workspace/backend/public/preview
echo "Flutter Web previews from exact SHA: $GCC_EXPECTED_SHA"

#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PREMIUM=0
ADMIN_MODE="wordpress"

usage() {
  cat <<'USAGE'
Usage: scripts/build.sh [--premium] [--admin-vite]

Builds local development outputs only:
  1. @smart-cloud/publisher-core
  2. @smart-cloud/publisher-exporter
  3. the WordPress admin bundle (default) or the Vite preview bundle

--premium selects the subscription-aware queue runner and paid admin module.
Canonical WordPress packaging still belongs to scripts/package.sh.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --premium) PREMIUM=1 ;;
    --admin-vite) ADMIN_MODE="vite" ;;
    --help|-h) usage; exit 0 ;;
    *) printf 'Unknown option: %s\n' "$1" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

command -v node >/dev/null 2>&1 || { printf 'Node.js is required.\n' >&2; exit 1; }
command -v npm >/dev/null 2>&1 || { printf 'npm is required.\n' >&2; exit 1; }
NODE_MAJOR="$(node -p 'Number(process.versions.node.split(".")[0])')"
((NODE_MAJOR >= 20)) || { printf 'Node.js 20 or newer is required.\n' >&2; exit 1; }

for module in core exporter admin; do
  [[ -d "$PROJECT_ROOT/$module/node_modules" ]] || {
    printf 'Dependencies are missing in %s. Run npm ci in that module first.\n' "$PROJECT_ROOT/$module" >&2
    exit 1
  }
done

BUILD_ENV="false"
if ((PREMIUM)); then
  BUILD_ENV="true"
fi

printf '[core] build (WPSUITE_PREMIUM=%s)\n' "$BUILD_ENV"
(cd "$PROJECT_ROOT/core" && WPSUITE_PREMIUM="$BUILD_ENV" npm run build)

printf '[exporter] build (WPSUITE_PREMIUM=%s)\n' "$BUILD_ENV"
(cd "$PROJECT_ROOT/exporter" && WPSUITE_PREMIUM="$BUILD_ENV" npm run build)

if [[ "$ADMIN_MODE" == "vite" ]]; then
  printf '[admin] Vite preview build (WPSUITE_PREMIUM=%s)\n' "$BUILD_ENV"
  (cd "$PROJECT_ROOT/admin" && WPSUITE_PREMIUM="$BUILD_ENV" npm run build)
else
  printf '[admin] WordPress build (WPSUITE_PREMIUM=%s)\n' "$BUILD_ENV"
  (cd "$PROJECT_ROOT/admin" && WPSUITE_PREMIUM="$BUILD_ENV" npm run build-wp)
fi

printf 'Local build completed. No package was assembled or published.\n'

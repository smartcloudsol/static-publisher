#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
RUN_BUILD=0

usage() {
  cat <<'USAGE'
Usage: scripts/check.sh [--with-build]

Runs the non-publishing Static Publisher quality gates. Add --with-build to
also regenerate local dist outputs after the type, lint, and test checks pass.
No WordPress ZIP or npm package is published by this script.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --with-build) RUN_BUILD=1 ;;
    --help|-h) usage; exit 0 ;;
    *) printf 'Unknown option: %s\n' "$1" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

require_command() {
  command -v "$1" >/dev/null 2>&1 || {
    printf 'Required command not found: %s\n' "$1" >&2
    exit 1
  }
}

require_command node
require_command npm
require_command php

NODE_MAJOR="$(node -p 'Number(process.versions.node.split(".")[0])')"
if ((NODE_MAJOR < 20)); then
  printf 'Node.js 20 or newer is required; found %s.\n' "$(node --version)" >&2
  exit 1
fi

run_npm() {
  local directory="$1"
  shift
  printf '\n[%s] npm %s\n' "${directory#"$PROJECT_ROOT"/}" "$*"
  (cd "$directory" && npm "$@")
}

printf '[php] syntax and runtime contract\n'
php -l "$PROJECT_ROOT/smartcloud-static-publisher.php" >/dev/null
php -l "$PROJECT_ROOT/hub-loader.php" >/dev/null
php -l "$PROJECT_ROOT/admin/php/admin.php" >/dev/null
php -l "$PROJECT_ROOT/includes/class-content-change-journal.php" >/dev/null
php -l "$PROJECT_ROOT/uninstall.php" >/dev/null
php "$PROJECT_ROOT/tests/hub-runtime-contract.test.php"
php "$PROJECT_ROOT/tests/content-sync-contract.test.php"
php "$PROJECT_ROOT/tests/content-sync-post-types.test.php"

run_npm "$PROJECT_ROOT/core" run lint
run_npm "$PROJECT_ROOT/core" exec -- tsc -p tsconfig.types.json --noEmit --declaration false --emitDeclarationOnly false
run_npm "$PROJECT_ROOT/exporter" run lint
run_npm "$PROJECT_ROOT/exporter" run check
run_npm "$PROJECT_ROOT/exporter" test
run_npm "$PROJECT_ROOT/admin" run lint
run_npm "$PROJECT_ROOT/admin" exec -- tsc --noEmit
run_npm "$PROJECT_ROOT/admin" test

if ((RUN_BUILD)); then
  "$SCRIPT_DIR/build.sh"
fi

printf '\nStatic Publisher checks passed.\n'

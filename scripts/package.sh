#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORKSPACE_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
FAMILY_ROOT="$WORKSPACE_ROOT/smartcloud-agent-ready-product-family"
ASSEMBLER="$FAMILY_ROOT/wpsuite-plugins/scripts/assemble.mjs"
BUILD=0
DRY_RUN=0

usage() {
  cat <<'USAGE'
Usage: scripts/package.sh [--build] [--dry-run]

Delegates Static Publisher WordPress packaging to the canonical five-plugin
product-family assembler. It never creates a ZIP from this source repository.

  --build    Rebuild shared Hub and Static Publisher admin inputs first.
  --dry-run  Print the canonical command without executing it.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --build) BUILD=1 ;;
    --dry-run) DRY_RUN=1 ;;
    --help|-h) usage; exit 0 ;;
    *) printf 'Unknown option: %s\n' "$1" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

[[ -f "$ASSEMBLER" ]] || {
  printf 'Canonical assembler not found: %s\n' "$ASSEMBLER" >&2
  exit 1
}
command -v node >/dev/null 2>&1 || { printf 'Node.js is required.\n' >&2; exit 1; }

COMMAND=(node wpsuite-plugins/scripts/assemble.mjs --only=smartcloud-static-publisher)
if ((BUILD)); then
  COMMAND+=(--build)
fi

printf 'Canonical product-family root: %s\n' "$FAMILY_ROOT"
printf 'Command:'
printf ' %q' "${COMMAND[@]}"
printf '\n'

if ((DRY_RUN)); then
  exit 0
fi

(cd "$FAMILY_ROOT" && WPSUITE_PREMIUM=true "${COMMAND[@]}")

printf 'Canonical outputs:\n'
printf '  %s\n' "$FAMILY_ROOT/wpsuite-plugins/packages/smartcloud-static-publisher"
printf '  %s\n' "$FAMILY_ROOT/wpsuite-plugins/dist/smartcloud-static-publisher-<version>.zip"
printf '  %s\n' "$FAMILY_ROOT/wpsuite-plugins/release-manifest.json"

#!/usr/bin/env bash
set -euo pipefail

WP_PATH=""
SITE_URL=""
RUN_MULTISITE=0

while (($#)); do
  case "$1" in
    --wp-path) WP_PATH="${2:-}"; shift 2 ;;
    --url) SITE_URL="${2:-}"; shift 2 ;;
    --multisite) RUN_MULTISITE=1; shift ;;
    *) printf 'Unknown argument: %s\n' "$1" >&2; exit 2 ;;
  esac
done

[[ -n "$WP_PATH" ]] || { printf '%s\n' 'Use --wp-path PATH.' >&2; exit 2; }
[[ -d "$WP_PATH" ]] || { printf 'WordPress path does not exist: %s\n' "$WP_PATH" >&2; exit 2; }

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
TEST_FILE="$(cd -- "$SCRIPT_DIR/.." && pwd)/tests/integration/wordpress-booted.php"
WP_ARGS=(--path="$WP_PATH")
if [[ -n "$SITE_URL" ]]; then
  WP_ARGS+=(--url="$SITE_URL")
fi

wp "${WP_ARGS[@]}" eval-file "$TEST_FILE"
if ((RUN_MULTISITE)); then
  wp "${WP_ARGS[@]}" eval-file "$(cd -- "$SCRIPT_DIR/.." && pwd)/tests/integration/wordpress-multisite.php"
fi

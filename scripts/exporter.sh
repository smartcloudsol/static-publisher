#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
EXPORTER_ROOT="$PROJECT_ROOT/exporter"
CONFIG_PATH=""
RUNTIME_DIR=""
WP_ROOT=""
EXECUTE=0
ALLOW_OUTPUT_WRITE=0
ALLOW_AWS=0
ALLOW_QUEUE=0
ALLOW_LOG_DELETE=0

usage() {
  cat <<'USAGE'
Usage:
  scripts/exporter.sh <command> --config FILE [guards] [-- command-args]

Commands: crawl, deploy, invalidate, publish, queue-runner, prune-logs,
          install-browsers, version, help

The script is a dry-run by default. Execution requires --execute plus the
command-specific guard:
  crawl                 --allow-output-write
  deploy, invalidate    --allow-aws
  publish               --allow-output-write --allow-aws
  queue-runner          --allow-queue --runtime-dir DIR
  prune-logs            --allow-log-delete

Optional environment/context:
  --runtime-dir DIR     Shared WordPress runtime directory.
  --wp-root DIR         WordPress root for @wp-root post-crawl copy aliases.

Examples:
  scripts/exporter.sh crawl --config ./publisher.config.json -- --url /about/
  scripts/exporter.sh crawl --config ./publisher.config.json \
    --execute --allow-output-write -- --crawl-mode incremental
  scripts/exporter.sh deploy --config ./publisher.config.json \
    --execute --allow-aws -- --profile production
USAGE
}

[[ $# -gt 0 ]] || { usage >&2; exit 2; }
COMMAND_NAME="$1"
shift
EXTRA_ARGS=()

while [[ $# -gt 0 ]]; do
  case "$1" in
    --config)
      shift
      [[ -n "${1:-}" ]] || { printf '%s\n' '--config requires a value.' >&2; exit 2; }
      CONFIG_PATH="$1"
      ;;
    --runtime-dir)
      shift
      [[ -n "${1:-}" ]] || { printf '%s\n' '--runtime-dir requires a value.' >&2; exit 2; }
      RUNTIME_DIR="$1"
      ;;
    --wp-root)
      shift
      [[ -n "${1:-}" ]] || { printf '%s\n' '--wp-root requires a value.' >&2; exit 2; }
      WP_ROOT="$1"
      ;;
    --execute) EXECUTE=1 ;;
    --allow-output-write) ALLOW_OUTPUT_WRITE=1 ;;
    --allow-aws) ALLOW_AWS=1 ;;
    --allow-queue) ALLOW_QUEUE=1 ;;
    --allow-log-delete) ALLOW_LOG_DELETE=1 ;;
    --help|-h) usage; exit 0 ;;
    --)
      shift
      EXTRA_ARGS=("$@")
      break
      ;;
    *) printf 'Unknown wrapper option: %s (put exporter arguments after --)\n' "$1" >&2; exit 2 ;;
  esac
  shift
done

case "$COMMAND_NAME" in
  crawl|deploy|invalidate|publish|queue-runner|prune-logs|install-browsers|version|help) ;;
  *) printf 'Unsupported exporter command: %s\n' "$COMMAND_NAME" >&2; usage >&2; exit 2 ;;
esac

command -v node >/dev/null 2>&1 || { printf 'Node.js is required.\n' >&2; exit 1; }
command -v jq >/dev/null 2>&1 || { printf 'jq is required for guarded config inspection.\n' >&2; exit 1; }
NODE_MAJOR="$(node -p 'Number(process.versions.node.split(".")[0])')"
((NODE_MAJOR >= 20)) || { printf 'Node.js 20 or newer is required.\n' >&2; exit 1; }
[[ -f "$EXPORTER_ROOT/dist/cli.js" ]] || {
  printf 'Built exporter CLI is missing. Run scripts/build.sh first.\n' >&2
  exit 1
}

if [[ "$COMMAND_NAME" != "version" && "$COMMAND_NAME" != "help" && "$COMMAND_NAME" != "install-browsers" ]]; then
  [[ -n "$CONFIG_PATH" ]] || { printf '%s\n' '--config is required.' >&2; exit 2; }
  CONFIG_PATH="$(realpath "$CONFIG_PATH")"
  [[ -f "$CONFIG_PATH" ]] || { printf 'Config file not found: %s\n' "$CONFIG_PATH" >&2; exit 1; }
  jq empty "$CONFIG_PATH" >/dev/null || { printf 'Config is not valid JSON: %s\n' "$CONFIG_PATH" >&2; exit 1; }
  jq -e '.sourceOrigin | type == "string" and length > 0' "$CONFIG_PATH" >/dev/null || {
    printf 'Config must contain a non-empty sourceOrigin.\n' >&2
    exit 1
  }
fi

if [[ -n "$RUNTIME_DIR" ]]; then
  RUNTIME_DIR="$(realpath "$RUNTIME_DIR")"
  [[ -d "$RUNTIME_DIR" ]] || { printf 'Runtime directory not found: %s\n' "$RUNTIME_DIR" >&2; exit 1; }
fi
if [[ -n "$WP_ROOT" ]]; then
  WP_ROOT="$(realpath "$WP_ROOT")"
  [[ -d "$WP_ROOT" ]] || { printf 'WordPress root not found: %s\n' "$WP_ROOT" >&2; exit 1; }
fi

case "$COMMAND_NAME" in
  crawl) ((ALLOW_OUTPUT_WRITE)) || { printf '%s\n' 'crawl execution requires --allow-output-write.' >&2; ((EXECUTE)) && exit 2; } ;;
  deploy|invalidate) ((ALLOW_AWS)) || { printf '%s\n' "$COMMAND_NAME execution requires --allow-aws." >&2; ((EXECUTE)) && exit 2; } ;;
  publish)
    ((ALLOW_OUTPUT_WRITE)) || { printf '%s\n' 'publish execution requires --allow-output-write.' >&2; ((EXECUTE)) && exit 2; }
    ((ALLOW_AWS)) || { printf '%s\n' 'publish execution requires --allow-aws.' >&2; ((EXECUTE)) && exit 2; }
    ;;
  queue-runner)
    [[ -n "$RUNTIME_DIR" ]] || { printf '%s\n' 'queue-runner requires --runtime-dir.' >&2; exit 2; }
    [[ -f "$RUNTIME_DIR/queue.json" ]] || { printf 'Queue file not found in %s\n' "$RUNTIME_DIR" >&2; exit 1; }
    jq empty "$RUNTIME_DIR/queue.json" >/dev/null || { printf 'queue.json is invalid JSON.\n' >&2; exit 1; }
    ((ALLOW_QUEUE)) || { printf '%s\n' 'queue-runner execution requires --allow-queue.' >&2; ((EXECUTE)) && exit 2; }
    ;;
  prune-logs) ((ALLOW_LOG_DELETE)) || { printf '%s\n' 'prune-logs execution requires --allow-log-delete.' >&2; ((EXECUTE)) && exit 2; } ;;
esac

CLI_COMMAND=(node "$EXPORTER_ROOT/dist/cli.js" "$COMMAND_NAME")
if [[ "$COMMAND_NAME" == "queue-runner" ]]; then
  CLI_COMMAND+=(--runtime-dir "$RUNTIME_DIR" --exporter-dir "$EXPORTER_ROOT")
fi
CLI_COMMAND+=("${EXTRA_ARGS[@]}")

printf 'Exporter root: %s\n' "$EXPORTER_ROOT"
if [[ -n "$CONFIG_PATH" ]]; then
  printf 'Config: %s\n' "$CONFIG_PATH"
  jq -r '"Source: \(.sourceOrigin // "")\nOutput: \(.outputDir // "export")\nS3 target: s3://\(.s3.bucket // "")/\(.s3.prefix // "")\nCloudFront: \(.cloudFront.distributionId // "(none)")"' "$CONFIG_PATH"
fi
[[ -n "$RUNTIME_DIR" ]] && printf 'Runtime: %s\n' "$RUNTIME_DIR"
printf 'Command:'
printf ' %q' "${CLI_COMMAND[@]}"
printf '\n'

if ((!EXECUTE)); then
  printf 'Dry-run only. Add --execute and the required guard(s) to run it.\n'
  exit 0
fi

export PUBLISHER_CONFIG="$CONFIG_PATH"
[[ -n "$RUNTIME_DIR" ]] && export STATIC_PUBLISHER_RUNTIME_DIR="$RUNTIME_DIR"
[[ -n "$WP_ROOT" ]] && export STATIC_PUBLISHER_WP_ROOT="$WP_ROOT"
"${CLI_COMMAND[@]}"

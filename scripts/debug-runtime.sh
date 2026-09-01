#!/usr/bin/env bash
set -euo pipefail

RUNTIME_DIR=""
LOG_FILE=""
TAIL_LINES=80

usage() {
  cat <<'USAGE'
Usage: scripts/debug-runtime.sh --runtime-dir DIR [--log FILE] [--tail LINES]

Prints a read-only, credential-redacted snapshot of queue state, runner
heartbeat, progress, crawl/deploy safety markers, and an optional log tail.
It never prints config.json or awsTempCreds values.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --runtime-dir) shift; RUNTIME_DIR="${1:-}" ;;
    --log) shift; LOG_FILE="${1:-}" ;;
    --tail) shift; TAIL_LINES="${1:-}" ;;
    --help|-h) usage; exit 0 ;;
    *) printf 'Unknown option: %s\n' "$1" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

[[ -n "$RUNTIME_DIR" ]] || { printf '%s\n' '--runtime-dir is required.' >&2; exit 2; }
[[ "$TAIL_LINES" =~ ^[1-9][0-9]*$ ]] || { printf '%s\n' '--tail must be a positive integer.' >&2; exit 2; }
((TAIL_LINES <= 2000)) || { printf '%s\n' '--tail may not exceed 2000 lines.' >&2; exit 2; }
command -v jq >/dev/null 2>&1 || { printf 'jq is required.\n' >&2; exit 1; }
RUNTIME_DIR="$(realpath "$RUNTIME_DIR")"
[[ -d "$RUNTIME_DIR" ]] || { printf 'Runtime directory not found: %s\n' "$RUNTIME_DIR" >&2; exit 1; }

print_json() {
  local label="$1"
  local file="$2"
  local filter="$3"
  printf '\n[%s]\n' "$label"
  if [[ ! -f "$file" ]]; then
    printf 'missing: %s\n' "$file"
    return
  fi
  if ! jq empty "$file" >/dev/null 2>&1; then
    printf 'invalid JSON: %s\n' "$file"
    return
  fi
  jq "$filter" "$file"
}

printf 'Runtime directory: %s\n' "$RUNTIME_DIR"
printf 'File locks:\n'
for lock in export.lock queue-mutation.lock; do
  if [[ -e "$RUNTIME_DIR/$lock" ]]; then
    printf '  active: %s\n' "$lock"
  else
    printf '  absent: %s\n' "$lock"
  fi
done

JOB_FILTER='{id,command,status,createdAt,startedAt,endedAt,exitCode,error,crawlMode,resumeFromStep,deploymentProfile,url,ruleId,coalesceKey,attempt,nextAttemptAt,enqueueSource,createdBy,usesTempAwsCreds:(has("awsTempCreds")),stopRequestedAt,stopMode,stoppedStep,logArchiveDir,logArchiveError}'
print_json 'heartbeat' "$RUNTIME_DIR/queue-runner-heartbeat.json" '.'
print_json 'current run' "$RUNTIME_DIR/current-run.json" "if type == \"object\" then $JOB_FILTER else . end"
print_json 'last run' "$RUNTIME_DIR/last-run.json" "if type == \"object\" then $JOB_FILTER else . end"
print_json 'queue (redacted)' "$RUNTIME_DIR/queue.json" "if type == \"array\" then map($JOB_FILTER) else . end"
print_json 'progress' "$RUNTIME_DIR/current-progress.json" '.'
print_json 'stop request' "$RUNTIME_DIR/stop-request.json" '.'
print_json 'scheduler state' "$RUNTIME_DIR/scheduler-state.json" '.'
print_json 'deploy diff' "$RUNTIME_DIR/deploy-diff.json" '.'
print_json 'deploy plan' "$RUNTIME_DIR/deploy-plan.json" '.'
print_json 'crawl incomplete marker' "$RUNTIME_DIR/crawl-incomplete.json" '.'
print_json 'content sync state' "$RUNTIME_DIR/content-sync-state.json" '.'
print_json 'content sync current range' "$RUNTIME_DIR/content-sync-current.json" '.'
print_json 'content sync checkpoint' "$RUNTIME_DIR/content-sync-checkpoint.json" '.'
print_json 'content sync baseline' "$RUNTIME_DIR/content-sync-baseline.json" '.'
print_json 'content sync release cutoff' "$RUNTIME_DIR/content-sync-release-cutoff.json" '.'
print_json 'content sync invalidation' "$RUNTIME_DIR/content-sync-invalidation.json" '.'
print_json 'content sync impact summary' "$RUNTIME_DIR/content-sync-impact-plan.json" '{schemaVersion,jobId,ruleId,consumerId,fromSequence,toSequence,eventCount,foldedCount,impactHash,directRenderCount:(.directRenderUrls|length),archivePageCount:(.archiveFamilyUrls|length),archiveFamilyCount:(.archiveFamilies|length),sitemapCount:(.sitemapUrls|length),deleteCount:(.deleteUrls|length)}'

if [[ -n "$LOG_FILE" ]]; then
  [[ "$LOG_FILE" == "$(basename "$LOG_FILE")" ]] || {
    printf 'Log file must be a basename without directory components.\n' >&2
    exit 2
  }
  STORAGE_ROOT="$(cd "$RUNTIME_DIR/.." && pwd)"
  RAW_LOG_DIR=""
  if [[ -f "$RUNTIME_DIR/config.json" ]] && jq empty "$RUNTIME_DIR/config.json" >/dev/null 2>&1; then
    RAW_LOG_DIR="$(jq -r 'if (.logDir | type) == "string" then .logDir else "" end' "$RUNTIME_DIR/config.json")"
  fi
  if [[ -n "$RAW_LOG_DIR" && "$RAW_LOG_DIR" == /* ]]; then
    LOG_DIR="$RAW_LOG_DIR"
  elif [[ -n "$RAW_LOG_DIR" ]]; then
    LOG_DIR="$STORAGE_ROOT/${RAW_LOG_DIR#/}"
  else
    LOG_DIR="$STORAGE_ROOT/logs"
  fi
  LOG_PATH="$LOG_DIR/$LOG_FILE"
  [[ -f "$LOG_PATH" ]] || { printf 'Log file not found: %s\n' "$LOG_PATH" >&2; exit 1; }
  printf '\n[log tail: %s]\n' "$LOG_PATH"
  tail -n "$TAIL_LINES" "$LOG_PATH"
fi

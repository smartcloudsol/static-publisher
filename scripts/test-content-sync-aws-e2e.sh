#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
TEMPLATE_PATH="$REPO_DIR/tests/aws/content-sync-e2e.template.yaml"
FIXTURE_SERVER="$REPO_DIR/tests/fixtures/content-sync-aws-server.mjs"
IMPACT_BUILDER="$REPO_DIR/tests/fixtures/create-content-sync-impact.mjs"
AWS_REGION="${AWS_REGION:-us-east-1}"
STACK_NAME="${PUBLISHER_AWS_E2E_STACK_NAME:-wpsuite-publisher-content-sync-e2e}"
STACK_NAME_EXPLICIT=0
DELETE_STACK_ON_EXIT=0
DESTROY_STACK=0
FIXTURE_PORT="${PUBLISHER_FIXTURE_PORT:-$((18000 + ($$ % 10000)))}"

while (($#)); do
  case "$1" in
    --region) AWS_REGION="${2:-}"; shift 2 ;;
    --stack-name) STACK_NAME="${2:-}"; STACK_NAME_EXPLICIT=1; shift 2 ;;
    --ephemeral) DELETE_STACK_ON_EXIT=1; shift ;;
    --keep-stack) DELETE_STACK_ON_EXIT=0; shift ;;
    --destroy) DESTROY_STACK=1; shift ;;
    *) printf 'Unknown argument: %s\n' "$1" >&2; exit 2 ;;
  esac
done

if ((DELETE_STACK_ON_EXIT)) && ((STACK_NAME_EXPLICIT)); then
  printf '%s\n' 'Do not combine --ephemeral with --stack-name; use --destroy for an explicitly named persistent stack.' >&2
  exit 2
fi

if ((DELETE_STACK_ON_EXIT)); then
  STACK_NAME="wpsuite-publisher-e2e-$(date +%s)-$$"
fi

[[ "$STACK_NAME" =~ ^[a-zA-Z][-a-zA-Z0-9]{0,127}$ ]] || {
  printf 'Invalid CloudFormation stack name: %s\n' "$STACK_NAME" >&2
  exit 2
}

for command_name in aws curl jq node npm; do
  command -v "$command_name" >/dev/null || {
    printf 'Required command is missing: %s\n' "$command_name" >&2
    exit 2
  }
done

if ((STACK_NAME_EXPLICIT == 0)) && ((DELETE_STACK_ON_EXIT == 0)); then
  TAGGED_STACKS_JSON="$(aws cloudformation describe-stacks \
    --region "$AWS_REGION" \
    --query "Stacks[?Tags[?Key=='WPSuitePurpose' && Value=='StaticPublisherContentSyncE2E']].StackName" \
    --output json)"
  mapfile -t TAGGED_STACKS < <(jq -r '.[]' <<<"$TAGGED_STACKS_JSON")
  if ((${#TAGGED_STACKS[@]} > 1)); then
    printf '%s\n' 'More than one persistent AWS E2E stack is tagged; select one with --stack-name.' >&2
    printf '  %s\n' "${TAGGED_STACKS[@]}" >&2
    exit 2
  fi
  if ((${#TAGGED_STACKS[@]} == 1)); then
    STACK_NAME="${TAGGED_STACKS[0]}"
  fi
fi

if ((DESTROY_STACK)); then
  BUCKET_TO_DESTROY="$(aws cloudformation describe-stacks \
    --stack-name "$STACK_NAME" \
    --region "$AWS_REGION" \
    --query "Stacks[0].Outputs[?OutputKey=='BucketName'].OutputValue | [0]" \
    --output text 2>/dev/null || true)"
  if [[ -z "$BUCKET_TO_DESTROY" || "$BUCKET_TO_DESTROY" == "None" ]]; then
    printf 'Persistent AWS E2E stack does not exist: %s\n' "$STACK_NAME"
    exit 0
  fi
  aws s3 rm "s3://$BUCKET_TO_DESTROY" --recursive --region "$AWS_REGION" >/dev/null
  aws cloudformation delete-stack --stack-name "$STACK_NAME" --region "$AWS_REGION"
  aws cloudformation wait stack-delete-complete --stack-name "$STACK_NAME" --region "$AWS_REGION"
  printf 'Persistent AWS E2E stack deleted: %s\n' "$STACK_NAME"
  exit 0
fi

if command -v cfn-lint >/dev/null; then
  cfn-lint "$TEMPLATE_PATH"
elif command -v uvx >/dev/null; then
  uvx cfn-lint "$TEMPLATE_PATH"
else
  printf '%s\n' 'cfn-lint or uvx is required for the pre-deployment template gate.' >&2
  exit 2
fi

WORK_DIR="$(mktemp -d -t wpsuite-publisher-aws-e2e.XXXXXX)"
RUNTIME_DIR="$WORK_DIR/runtime"
OUTPUT_DIR="$WORK_DIR/output"
LOG_DIR="$WORK_DIR/logs"
STATE_PATH="$WORK_DIR/fixture-state"
CONFIG_PATH="$WORK_DIR/publisher.config.json"
IMPACT_PATH="$RUNTIME_DIR/content-sync-impact.json"
SERVER_LOG="$WORK_DIR/fixture-server.log"
FIXTURE_PID=""
BUCKET_NAME=""
STACK_DEPLOYED=0

cleanup() {
  local exit_code=$?
  if [[ -n "$FIXTURE_PID" ]]; then
    kill "$FIXTURE_PID" 2>/dev/null || true
    wait "$FIXTURE_PID" 2>/dev/null || true
  fi
  if ((STACK_DEPLOYED)) && ((DELETE_STACK_ON_EXIT)); then
    if [[ -n "$BUCKET_NAME" ]]; then
      aws s3 rm "s3://$BUCKET_NAME" --recursive --region "$AWS_REGION" >/dev/null || true
    fi
    aws cloudformation delete-stack --stack-name "$STACK_NAME" --region "$AWS_REGION" || true
    aws cloudformation wait stack-delete-complete --stack-name "$STACK_NAME" --region "$AWS_REGION" || true
  fi
  if ((exit_code == 0)); then
    rm -rf -- "$WORK_DIR"
  else
    printf 'AWS E2E work directory retained for diagnosis: %s\n' "$WORK_DIR" >&2
  fi
  exit "$exit_code"
}
trap cleanup EXIT INT TERM

mkdir -p "$RUNTIME_DIR" "$OUTPUT_DIR" "$LOG_DIR"
printf '%s\n' before >"$STATE_PATH"

aws cloudformation validate-template \
  --template-body "file://$TEMPLATE_PATH" \
  --region "$AWS_REGION" >/dev/null
aws cloudformation deploy \
  --stack-name "$STACK_NAME" \
  --template-file "$TEMPLATE_PATH" \
  --region "$AWS_REGION" \
  --tags WPSuitePurpose=StaticPublisherContentSyncE2E \
  --no-fail-on-empty-changeset
STACK_DEPLOYED=1

stack_output() {
  aws cloudformation describe-stacks \
    --stack-name "$STACK_NAME" \
    --region "$AWS_REGION" \
    --query "Stacks[0].Outputs[?OutputKey=='$1'].OutputValue | [0]" \
    --output text
}

BUCKET_NAME="$(stack_output BucketName)"
DISTRIBUTION_ID="$(stack_output DistributionId)"
DISTRIBUTION_DOMAIN="$(stack_output DistributionDomainName)"
TARGET_ORIGIN="https://$DISTRIBUTION_DOMAIN"
SOURCE_ORIGIN="http://127.0.0.1:$FIXTURE_PORT"

# The persistent stack owns this bucket exclusively. Reset its contents before
# every run, while keeping the expensive CloudFront distribution reusable.
aws s3 rm "s3://$BUCKET_NAME" --recursive --region "$AWS_REGION" >/dev/null

jq -n \
  --arg source "$SOURCE_ORIGIN" \
  --arg target "$TARGET_ORIGIN" \
  --arg output "$OUTPUT_DIR" \
  --arg logs "$LOG_DIR" \
  --arg bucket "$BUCKET_NAME" \
  --arg region "$AWS_REGION" \
  --arg distribution "$DISTRIBUTION_ID" \
  '{
    sourceOrigin: $source,
    targetOrigin: $target,
    ignoreHttpsErrors: false,
    urlRewriteMode: "absolute",
    outputDir: $output,
    noJavaScriptRenderPathPrefixes: ["/"],
    seedPaths: ["/", "/listing/", "/404.html"],
    generated404RequestPath: "/404.html",
    sitemapPaths: ["/wp-sitemap.xml"],
    allowedAssetHosts: [],
    assetPathPrefixes: ["/wp-sitemap"],
    blockedPathPrefixes: [],
    blockedSearchFragments: [],
    extraReplacements: {},
    postCrawlCopyMap: {},
    logDir: $logs,
    logLevel: "info",
    s3SyncMode: "sdk-upload-delete",
    navigationTimeoutMs: 15000,
    readiness: {waitForSelector: null, waitForFunction: null, timeoutMs: 500, fallbackWaitMs: 50},
    viewport: {width: 1280, height: 800},
    maxPages: 20,
    concurrency: 1,
    assetDownloadConcurrency: 2,
    rewriteConcurrency: 2,
    s3: {
      bucket: $bucket,
      prefix: "wwwroot",
      region: $region,
      htmlCacheControl: "public,max-age=300",
      assetCacheControl: "public,max-age=300"
    },
    cloudFront: {distributionId: $distribution, invalidationPaths: ["/*"]},
    subscriptionType: "PROFESSIONAL"
  }' >"$CONFIG_PATH"

PUBLISHER_FIXTURE_PORT="$FIXTURE_PORT" \
PUBLISHER_FIXTURE_STATE="$STATE_PATH" \
  node "$FIXTURE_SERVER" >"$SERVER_LOG" 2>&1 &
FIXTURE_PID=$!
for _ in $(seq 1 50); do
  if curl --silent --fail "$SOURCE_ORIGIN/health" >/dev/null; then
    break
  fi
  sleep 0.1
done
curl --silent --fail "$SOURCE_ORIGIN/health" >/dev/null

cd "$REPO_DIR/exporter"
WPSUITE_PREMIUM=true npm run build
export PUBLISHER_CONFIG="$CONFIG_PATH"
export STATIC_PUBLISHER_RUNTIME_DIR="$RUNTIME_DIR"

node dist/crawl.js
node dist/deploy.js

# A previous persistent run may have populated viewer caches. Establish a
# deterministic clean cache state before the initial public smoke assertion.
INITIAL_INVALIDATION_ID="$(aws cloudfront create-invalidation \
  --distribution-id "$DISTRIBUTION_ID" \
  --paths '/*' \
  --region "$AWS_REGION" \
  --query 'Invalidation.Id' \
  --output text)"
aws cloudfront wait invalidation-completed \
  --distribution-id "$DISTRIBUTION_ID" \
  --id "$INITIAL_INVALIDATION_ID" \
  --region "$AWS_REGION"

printf '%s\n' 'remote-only guard fixture' >"$WORK_DIR/remote-only.txt"
aws s3api put-object \
  --bucket "$BUCKET_NAME" \
  --key wwwroot/remote-only.txt \
  --body "$WORK_DIR/remote-only.txt" \
  --content-type text/plain \
  --region "$AWS_REGION" >/dev/null

old_status="$(curl --silent --output /dev/null --write-out '%{http_code}' "$TARGET_ORIGIN/old/")"
[[ "$old_status" == "200" ]] || {
  printf 'Initial CloudFront smoke failed for /old/: HTTP %s\n' "$old_status" >&2
  exit 1
}

printf '%s\n' after >"$STATE_PATH"
node "$IMPACT_BUILDER" "$SOURCE_ORIGIN" "$IMPACT_PATH"
node dist/crawl.js --content-sync-plan "$IMPACT_PATH"

DEPLOY_PLAN="$RUNTIME_DIR/deploy-plan.json"
jq -e '.schemaVersion == 2 and (.deletedFiles | index("old/index.html")) != null' "$DEPLOY_PLAN" >/dev/null
jq -e '(.deletedFiles | index("remote-only.txt")) == null' "$DEPLOY_PLAN" >/dev/null

node dist/deploy.js --content-sync
node dist/invalidate.js --content-sync

jq -e '.batches | length > 0 and all(.[]; .status == "completed" and (.invalidationId | length > 0))' \
  "$RUNTIME_DIR/content-sync-invalidation.json" >/dev/null
jq -e 'any(.batches[].paths[]; . == "/wwwroot/old/index.html")' \
  "$RUNTIME_DIR/content-sync-invalidation.json" >/dev/null
jq -e '(.deletedKeys | index("wwwroot/old/index.html")) != null' \
  "$RUNTIME_DIR/deploy-diff.json" >/dev/null

aws s3api head-object \
  --bucket "$BUCKET_NAME" \
  --key wwwroot/remote-only.txt \
  --region "$AWS_REGION" >/dev/null

new_status="$(curl --silent --output /dev/null --write-out '%{http_code}' "$TARGET_ORIGIN/new/")"
old_status="$(curl --silent --output /dev/null --write-out '%{http_code}' "$TARGET_ORIGIN/old/")"
listing_body="$(curl --silent --fail "$TARGET_ORIGIN/listing/")"
[[ "$new_status" == "200" ]] || { printf 'Target smoke failed for /new/: HTTP %s\n' "$new_status" >&2; exit 1; }
[[ "$old_status" == "404" ]] || { printf 'Tombstone smoke failed for /old/: HTTP %s\n' "$old_status" >&2; exit 1; }
[[ "$listing_body" == *"New article"* ]] || { printf '%s\n' 'Listing smoke did not contain the new article.' >&2; exit 1; }

printf 'AWS content-sync E2E passed: stack=%s bucket=%s distribution=%s\n' \
  "$STACK_NAME" "$BUCKET_NAME" "$DISTRIBUTION_ID"
if ((DELETE_STACK_ON_EXIT == 0)); then
  printf 'Persistent test stack retained. Remove it explicitly with: %s --region %s --stack-name %s --destroy\n' \
    "$0" "$AWS_REGION" "$STACK_NAME"
fi

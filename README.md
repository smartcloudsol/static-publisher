# WP Suite Static Publisher

WP Suite Static Publisher exports a WordPress site into a fully static artifact using a Playwright-based Node.js exporter, then deploys it to S3 and invalidates CloudFront.

Project repository and extended documentation:

- https://github.com/smartcloudsol/static-publisher

The project now contains two coordinated parts:

- A WordPress plugin admin screen for configuration, status, queueing, and log viewing.
- A Node.js exporter pipeline for crawl, rewrite, deploy, and invalidate operations.

## Architecture

WordPress Plugin (PHP + React/Mantine admin)
-> Runtime JSON files in wp-content/uploads/smartcloud-static-publisher/runtime
-> External Node runner
-> Static artifact + S3 + CloudFront

Important design rule:

- The plugin does not execute shell commands directly from PHP.
- It queues jobs for an external runner in runtime JSON files.

This keeps runtime behavior deterministic and aligns with common WordPress.org security review expectations.

Because the exporter runs from shell (Node.js CLI), it can work against fully rendered pages and runtime-generated frontends without pushing crawl/deploy execution into PHP.

The distributed WordPress plugin ZIP does not bundle the Node.js runtime or npm dependencies. Install the exporter separately as the `@smart-cloud/publisher-exporter` npm CLI package on the machine that processes queued jobs.

## Repository Layout

- `smartcloud-static-publisher.php`: plugin bootstrap, admin menu, REST API, runtime file IO
- `admin/`: React + Vite + Mantine admin app
- `core/`: shared TypeScript package consumed by the admin app and exporter
- `exporter/`: source for the standalone `@smart-cloud/publisher-exporter` npm CLI package
- `scripts/`: optional helper scripts kept for custom/manual host setups

## Plugin Runtime Files

Generated under:

- `wp-content/uploads/smartcloud-static-publisher/runtime/config.json`
- `wp-content/uploads/smartcloud-static-publisher/runtime/queue.json`
- `wp-content/uploads/smartcloud-static-publisher/runtime/current-run.json`
- `wp-content/uploads/smartcloud-static-publisher/runtime/last-run.json`
- `wp-content/uploads/smartcloud-static-publisher/runtime/export.lock`
- exporter logs are written under `wp-content/uploads/smartcloud-static-publisher/<logDir>/*`
- completed, failed, and stopped job log snapshots are copied under `wp-content/uploads/smartcloud-static-publisher/<logDir>/archive/<timestamp-command-jobId-status>/` as gzip-compressed per-file artifacts plus `job.json`

## Admin App Setup

Build the admin bundle:

```bash
cd admin
npm ci
npm run build-wp dist
```

The plugin expects WordPress packaging output directly in the plugin admin directory:

- `admin/index.js`
- `admin/index.asset.php`
- `admin/index.css`

No Vite manifest is required in production packaging.

## WordPress i18n in Admin

The admin UI uses `@wordpress/i18n` and `__()` calls with text domain:

- `smartcloud-static-publisher`

Plugin-side wiring:

- Script dependency includes `wp-i18n`
- `wp_set_script_translations()` is called for the admin handle

To provide translations, place generated JSON translation files under `languages/` for this text domain.

## External Exporter Setup

### Linux Host Prerequisites (Node + Playwright)

The exporter requires both Node.js and Playwright browser binaries on the machine that runs crawl and queue jobs.

Recommended approach (NVM + latest LTS):

```bash
curl -fsSL https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.3/install.sh | bash
export NVM_DIR="$HOME/.nvm"
. "$NVM_DIR/nvm.sh"
nvm ls-remote --lts
nvm install --lts
nvm use --lts
nvm alias default 'lts/*'
node --version
npm --version
```

Install the standalone exporter CLI package:

```bash
npm install -g @smart-cloud/publisher-exporter
publisher-exporter install-browsers
```

Package docs: https://www.npmjs.com/package/@smart-cloud/publisher-exporter

If you prefer a dedicated local package root instead of a global npm install:

```bash
sudo mkdir -p /opt/smartcloud/publisher-exporter
sudo chown "$USER":"$USER" /opt/smartcloud/publisher-exporter
cd /opt/smartcloud/publisher-exporter
npm init -y
npm install @smart-cloud/publisher-exporter
npx @smart-cloud/publisher-exporter install-browsers
```

If this is the first Playwright setup on a Linux host, install OS dependencies as needed:

```bash
publisher-exporter install-browsers install --with-deps chromium
```

Important:

- If cron runs under the same OS user that installed Node and Playwright, NVM plus the default user-scoped Playwright cache is fine.
- If cron runs as a different or non-login service user such as `www-data`, prefer an explicit `HOME`, a `PATH` that already contains `publisher-exporter` and `node`, plus a shared `PLAYWRIGHT_BROWSERS_PATH`.
- If cron runs with a minimal environment, set `HOME` and `PATH` explicitly in crontab before calling `publisher-exporter queue-runner`.
- `publisher-exporter install-browsers` installs Playwright browser binaries for the current OS user unless `PLAYWRIGHT_BROWSERS_PATH` points to a shared location.
- If different users may run jobs, set a shared browser location via `PLAYWRIGHT_BROWSERS_PATH` (for example `/var/lib/playwright-browsers`) and ensure read/execute permissions for the cron user.
- If the shared browser directory lives under a protected system path, create it once with elevated privileges and make it writable by the same OS user that will run `publisher-exporter install-browsers`. The later cron job only needs read/execute access to that directory tree.
- In WordPress admin, `External exporter dir` should point to the installed package root when you want PHP-side diagnostics to verify the local CLI install. Examples: `/usr/local/lib/node_modules/@smart-cloud/publisher-exporter` or `/opt/smartcloud/publisher-exporter/node_modules/@smart-cloud/publisher-exporter`.
- For internal origins with self-signed or otherwise non-public TLS certificates, enable `Allow self-signed TLS certificates during crawl` in the admin UI (`ignoreHttpsErrors`). Keep it disabled for strict certificate validation.

Example shared browser install:

```bash
sudo mkdir -p /var/lib/playwright-browsers
sudo chown "$USER":"$USER" /var/lib/playwright-browsers
export PLAYWRIGHT_BROWSERS_PATH=/var/lib/playwright-browsers
publisher-exporter install-browsers
```

## Exporter Commands

If `@smart-cloud/publisher-exporter` is installed globally:

```bash
PUBLISHER_CONFIG=./publisher.config.json publisher-exporter crawl
PUBLISHER_CONFIG=./publisher.config.json publisher-exporter deploy
PUBLISHER_CONFIG=./publisher.config.json publisher-exporter invalidate
publisher-exporter queue-runner --runtime-dir /var/www/site/wp-content/uploads/smartcloud-static-publisher/runtime --max-jobs=1
```

If you do not want a global npm install, use `npx` instead:

```bash
PUBLISHER_CONFIG=./publisher.config.json npx @smart-cloud/publisher-exporter crawl
PUBLISHER_CONFIG=./publisher.config.json npx @smart-cloud/publisher-exporter deploy
PUBLISHER_CONFIG=./publisher.config.json npx @smart-cloud/publisher-exporter invalidate
npx @smart-cloud/publisher-exporter queue-runner --runtime-dir /var/www/site/wp-content/uploads/smartcloud-static-publisher/runtime --max-jobs=1
```

For source-repo development workflow, keep using npm scripts inside `exporter/`.

## No Shell Access on WordPress Host

If your WordPress hosting environment does not provide shell access, you can still use the exporter from your own machine or CI environment.

Typical flow:

- Keep the plugin installed for runtime JSON files if needed.
- Install `@smart-cloud/publisher-exporter` on your own machine or CI runner.
- Use a local `publisher.config.json` with your source URL, S3, and CloudFront settings.
- Execute crawl, deploy, and invalidate from that environment.

Trade-off:

- This mode bypasses the integrated WordPress admin workflow for queueing, status, and log viewing.
- In practice, you keep exporter automation, but you lose the plugin admin UI as the primary control surface.

## Logging and Deploy Progress

- Logging now covers crawl, deploy, and invalidate phases.
- Use `logLevel` in config: `error`, `warn`, `info`, `debug`.
- `info` shows major milestones and progress counters.
- `debug` adds detailed per-item operations.

Deploy supports multiple sync strategies via `s3SyncMode`:

- `sdk-upload-delete`: AWS SDK upload + stale object delete
- `sdk-upload-only`: AWS SDK upload, no delete
- `aws-s3-sync-delete`: executes `aws s3 sync --delete`
- `aws-s3-sync`: executes `aws s3 sync`
- `aws-s3-cp-recursive`: executes `aws s3 cp --recursive`

## Export Attribution

For sites without an active WPSuite subscription, exported HTML pages also receive this meta tag during rewrite:

```html
<meta name="generator" content="WPSuite.io Static Publisher" />
```

Notes:

- It is added only to HTML documents, not to JSON, CSS, or other exported assets.
- It is idempotent, so repeated crawl/deploy rewrite passes do not duplicate it.
- Sites with an active WPSuite subscription do not receive this tag.

## Multi-Target Deploy From One Crawl

Static Publisher treats the top-level target settings as your base target. Extra targets live under `deploymentProfiles` and are selected only when you pass `--profile` during `deploy` or `invalidate`.

Typical workflow:

- Crawl once from the source site into the local static artifact.
- Deploy the artifact to the base target with a normal `deploy`.
- Reuse that same artifact for `staging`, `production`, or client-specific targets with `--profile`.
- Avoid re-crawling the origin for every environment promotion.

Example:

```json
{
  "sourceOrigin": "https://dev.example.com",
  "targetOrigin": "https://staging.example.com",
  "urlRewriteMode": "absolute",
  "s3": {
    "bucket": "my-site-staging"
  },
  "cloudFront": {
    "distributionId": "E2STAGING123"
  },
  "deploymentProfiles": {
    "prod": {
      "targetOrigin": "https://example.com",
      "s3": {
        "bucket": "my-site-prod"
      },
      "cloudFront": {
        "distributionId": "E2PROD456"
      }
    }
  }
}
```

Run it like this:

```bash
PUBLISHER_CONFIG=./publisher.config.json npx @smart-cloud/publisher-exporter crawl
PUBLISHER_CONFIG=./publisher.config.json npx @smart-cloud/publisher-exporter deploy
PUBLISHER_CONFIG=./publisher.config.json npx @smart-cloud/publisher-exporter invalidate
PUBLISHER_CONFIG=./publisher.config.json npx @smart-cloud/publisher-exporter deploy --profile prod
PUBLISHER_CONFIG=./publisher.config.json npx @smart-cloud/publisher-exporter invalidate --profile prod
```

You can also select the profile via environment variable:

```bash
PUBLISHER_DEPLOY_PROFILE=prod PUBLISHER_CONFIG=./publisher.config.json npx @smart-cloud/publisher-exporter deploy
```

Notes:

- Without `--profile`, deploy and invalidate use the base target from the top-level config.
- Profile overrides currently support `targetOrigin`, `s3`, `cloudFront`, and profile-specific `extraReplacements`.
- If a profile changes `targetOrigin`, the base crawl output should use `urlRewriteMode: "absolute"`; this lets deploy rewrite the already-crawled artifact to the selected profile domain without re-crawling.
- If your crawl output is already relative/root-relative and only the bucket/CDN differs, you can still reuse the same artifact across profiles.
- For advanced raw-config automation, you can still set `defaultDeploymentProfile` manually in `publisher.config.json`, but the admin UI treats the top-level target as the default path.

## Queue Workflow

The admin can queue commands:

- `publish`
- `crawl`
- `deploy`
- `invalidate`
- `retry-timeouts`
- `url` (single path)

Queued jobs are written to `runtime/queue.json` and processed by your external Node runner.

### Scheduler Rules

PRO scheduler rules are stored in the runtime config and evaluated by `publisher-exporter queue-runner` at the start of each external runner invocation.

- Scheduler does not spawn a worker by itself. Use system cron, systemd timer, or Windows Task Scheduler to start `publisher-exporter queue-runner` regularly.
- A 1-minute runner tick is the recommended cadence. Each tick may auto-enqueue matching rules into `runtime/queue.json`, then the normal queue flow processes them.
- Supported scheduled commands are `publish`, `crawl`, `deploy`, `invalidate`, `retry-timeouts`, and `url`.
- The scheduler timezone field is currently stored for operations context; interval matching itself is based on elapsed minute buckets checked on each runner tick.
- If an equivalent queued or running job already exists for the same command, crawl mode, deployment profile, and URL, the scheduler skips that rule for the current interval bucket to avoid duplicate work.

`retry-timeouts` now resolves retry URLs from the newest archived full `crawl` or `publish` job log snapshot under `<logDir>/archive/`, instead of from whichever live root log files happened to be left by the most recent unrelated job. If no relevant archive exists yet, it falls back to the current root log set.

### Temporary AWS Credentials From Admin

For `publish`, `deploy`, and `invalidate` commands you can provide short-lived AWS credentials in the admin UI (`Temp AWS creds`).

- `AWS_ACCESS_KEY_ID`
- `AWS_SECRET_ACCESS_KEY`
- `AWS_SESSION_TOKEN` (optional, recommended for STS sessions)

Behavior:

- Credentials are attached to the queued job.
- Queue runner injects them only into the child process environment of that job.
- Credentials are redacted from `/state` responses (`currentRun` / `lastRun`) so they are not shown back in admin status payloads.

## Queue Runner Setup (Production)

Direct CLI invocation from cron is the recommended setup.

If you redirect cron stdout/stderr to a file, create that parent directory before enabling cron. Shell redirection will not create missing parent directories for you.

```bash
sudo install -d -o <cron-user> -g <cron-user> -m 755 \
  /var/www/site/wp-content/uploads/smartcloud-static-publisher/logs
```

Run one queued job manually on the runner host:

```bash
publisher-exporter queue-runner \
  --runtime-dir /var/www/site/wp-content/uploads/smartcloud-static-publisher/runtime \
  --max-jobs 1
```

Drain multiple jobs in one run:

```bash
publisher-exporter queue-runner \
  --runtime-dir /var/www/site/wp-content/uploads/smartcloud-static-publisher/runtime \
  --max-jobs 100
```

### Same Host: WordPress + Queue Runner

Use this when WordPress, the shared runtime directory, and the queue runner all live on the same Linux machine.

Linux cron example:

```cron
SHELL=/bin/bash
HOME=/home/<cron-user>
PATH=/home/<cron-user>/.nvm/versions/node/v24.15.0/bin:/usr/bin:/bin
PLAYWRIGHT_BROWSERS_PATH=/var/lib/playwright-browsers
RUNTIME_PATH=/var/www/site/wp-content/uploads/smartcloud-static-publisher/runtime
LOG_PATH=/var/www/site/wp-content/uploads/smartcloud-static-publisher/logs

* * * * * /usr/bin/flock -n /tmp/static-publisher.cron.lock publisher-exporter queue-runner --runtime-dir "$RUNTIME_PATH" --max-jobs 1 >> "$LOG_PATH/queue-runner-cron.log" 2>&1
17 3 * * * publisher-exporter prune-logs --runtime-dir "$RUNTIME_PATH" --older-than-days 30 >> "$LOG_PATH/prune-logs-cron.log" 2>&1
```

If you do not want a version-pinned NVM path in crontab, create a stable user launcher in `~/bin` and put that directory first in `PATH`:

```bash
mkdir -p "$HOME/bin"
cat > "$HOME/bin/publisher-exporter" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
export NVM_DIR="$HOME/.nvm"
. "$NVM_DIR/nvm.sh"
nvm use default >/dev/null
exec "$(npm prefix -g)/bin/publisher-exporter" "$@"
EOF
chmod +x "$HOME/bin/publisher-exporter"
```

A plain symlink to `~/.nvm/versions/node/vX.Y.Z/bin/publisher-exporter` will break after a Node upgrade. Prefer this small launcher, or enable an NVM-managed `current` symlink and link against that stable path.

### Separate Hosts: WordPress + Queue Runner with Shared Mounted Storage

Use this when WordPress runs on one machine and the queue runner runs on another, but both machines can see the same mounted `wp-content/uploads/smartcloud-static-publisher` storage.

Keep `outputDir` and `logDir` storage-relative in WordPress admin, for example `export` and `logs`.

On the crawler host, point `--runtime-dir` at the local mount path of the shared storage:

```cron
SHELL=/bin/bash
HOME=/home/<runner-user>
PATH=/home/<runner-user>/.nvm/versions/node/v24.15.0/bin:/usr/bin:/bin
PLAYWRIGHT_BROWSERS_PATH=/var/lib/playwright-browsers
RUNTIME_PATH=/mnt/site/runtime
LOG_PATH=/mnt/site/logs

* * * * * /usr/bin/flock -n /tmp/static-publisher.cron.lock publisher-exporter queue-runner --runtime-dir "$RUNTIME_PATH" --max-jobs 1 >> "$LOG_PATH/queue-runner-cron.log" 2>&1
```

If `postCrawlCopyMap` needs access to the WordPress tree from the crawler host too, also set `STATIC_PUBLISHER_WP_ROOT` for that host's local view of the WordPress root.

Windows / LocalWP manual run:

```powershell
$env:STATIC_PUBLISHER_RUNTIME_DIR='C:\Local Sites\my-site\app\public\wp-content\uploads\smartcloud-static-publisher\runtime'
npx @smart-cloud/publisher-exporter queue-runner --runtime-dir $env:STATIC_PUBLISHER_RUNTIME_DIR --max-jobs=1
```

Windows / LocalWP scheduled run with Task Scheduler:

1. Create a PowerShell wrapper such as `C:\smartcloud-static-publisher\run-queue-runner.ps1`:

```powershell
$env:STATIC_PUBLISHER_RUNTIME_DIR='C:\Local Sites\my-site\app\public\wp-content\uploads\smartcloud-static-publisher\runtime'
& 'C:\Program Files\nodejs\npx.cmd' '@smart-cloud/publisher-exporter' 'queue-runner' '--runtime-dir' $env:STATIC_PUBLISHER_RUNTIME_DIR '--max-jobs' '1'
exit $LASTEXITCODE
```

2. In Task Scheduler create a task with a trigger that repeats every `1 minute` indefinitely.
3. Use `powershell.exe` as Program/script and `-NoProfile -ExecutionPolicy Bypass -File "C:\smartcloud-static-publisher\run-queue-runner.ps1"` as Add arguments.

If you only need an occasional check, starting the PowerShell command manually is enough; queue-runner defaults to `--max-jobs=1`.

Cron variables explained:

- `HOME`: recommended stable home for cron so user-level caches and ambient credential stores (for example `~/.aws`) resolve consistently.
- `PATH`: must include the directory that contains both `publisher-exporter` and `node`. In crontab, prefer absolute paths instead of relying on `$HOME` expansion. If you use the optional `~/bin/publisher-exporter` launcher, put that absolute `bin` path first.
- `PLAYWRIGHT_BROWSERS_PATH`: shared browser install location when multiple users or services may run jobs on the same host.
- `RUNTIME_PATH`: runtime state folder created by the plugin in uploads, using the local path visible on the runner host.
- `LOG_PATH`: folder receiving the long-lived host-level cron log file.
- `/usr/bin/flock -n ...`: optional but recommended extra guard so a new cron tick exits early before the queue runner even starts.
- `>> ...queue-runner-cron.log 2>&1`: append stdout/stderr to a persistent cron log file for diagnostics; verify that `wp-content/uploads/smartcloud-static-publisher/logs/` already exists.

Notes:

- Queue runner uses `runtime/config.json` by default.
- Direct `publisher-exporter queue-runner` invocation already knows its own package directory; you do not need `STATIC_PUBLISHER_EXPORTER_DIR` unless you are using a custom wrapper that expects it.
- Queue runner keeps the root exporter log files as the current working set, but after each finished/stopped/failed job it writes gzip-compressed per-file artifacts plus the latest `current-progress.json` snapshot into `<logDir>/archive/<timestamp-command-jobId-status>/` and records them in `job.json`.
- Audit Log `job-run-finished` and `job-run-stopped` rows expose download buttons for the surviving archived artifacts directly from WordPress admin.
- `retry-timeouts` prefers the manifest-backed archived `errors.*` artifact from the newest full `crawl` or `publish` archive and falls back to older uncompressed archive layouts when needed.
- Prune old `<logDir>/archive/` folders with `publisher-exporter prune-logs --runtime-dir "$RUNTIME_PATH" --older-than-days 30` from daily cron or another retention job.
- Shell-redirection logs such as `queue-runner-cron.log` are not part of the per-job archive copy; they remain long-lived host-level cron logs.
- Plugin queueing works without shell execution; actual processing requires external Node runtime.
- WordPress WP-Cron is not used to execute Node jobs by default. Use system cron/systemd timer in Linux production.

## Run A Queued Job Off-Host

If the WordPress host cannot run Node, Playwright, or cron, you can still replay a queued job from your own shell or CI machine.

1. In the Job Queue panel use `Download config` next to the queued item and save it as `queued-job.json`.
2. Extract the nested `publisherConfig` to `publisher.config.json` using either `manualExecution.commands.extractPublisherConfigNode` or `manualExecution.commands.extractPublisherConfigPowerShell` from the downloaded JSON.
3. Install the published CLI package on that machine, or if you are running from a source checkout instead of the published package, build the exporter first:

```bash
npm install -g @smart-cloud/publisher-exporter
# or, from repository source:
# cd exporter && npm ci && npm run build
```

4. Optionally edit `publisher.config.json` locally, for example to change `outputDir` to a writable folder on your machine.
5. Run the exact job command from `manualExecution.commands.jobPosix` or `manualExecution.commands.jobPowerShell` in the downloaded JSON. These commands already reflect `publish` vs `crawl`, `incremental`, `retry-timeouts`, and `url` jobs.
6. If you want deployment from your own machine too, continue with the provided `deploySdk`, `invalidateSdk`, or AWS CLI commands from the same `manualExecution.commands` block.

Important:

- This is an out-of-band replay of the queued job; it does not mark the WordPress queue item as completed automatically.
- If the original queued item should not run later on the server, clean it up in WordPress after your manual replay.
- The WordPress plugin ZIP does not contain the exporter runtime. Install `@smart-cloud/publisher-exporter` separately on whichever machine replays the downloaded job.

## Shared Runtime Across Two Hosts

You can split WordPress and the queue runner across two machines as long as both see the same `wp-content/uploads/smartcloud-static-publisher` storage.

Example:

- VM1 / WordPress host: `/var/www/site/wp-content/uploads/smartcloud-static-publisher`
- VM2 / crawler host: the same shared storage mounted at `/mnt/site`
- queue runner on VM2: `STATIC_PUBLISHER_RUNTIME_DIR=/mnt/site/runtime`

In this setup:

- `outputDir` and `logDir` should stay storage-relative in WordPress admin, for example `export` and `logs`, not machine-specific absolute paths.
- the exporter resolves those relative paths against the local storage mount on the machine that is currently running the job.
- the raw `queue-runner-heartbeat.json` may contain VM2 paths in `runtimeDir` / `exporterDir`; that is expected because the heartbeat describes the runner host, not the WordPress host.

For `postCrawlCopyMap` source paths, use aliases instead of hardcoding host-specific absolute paths:

- `@storage-root`: the shared `smartcloud-static-publisher` storage root
- `@runtime`: the runtime directory inside that storage root
- `@wp-root`: the WordPress root as seen by the crawler host; resolved from `STATIC_PUBLISHER_WP_ROOT` or `WPSUITE_STATIC_PUBLISHER_WP_ROOT`

Use `@storage-root` when the files already live inside the shared publisher storage. Use `@wp-root` only when the crawler host can actually access the WordPress tree too.

Example runner environment on VM2:

```bash
export PLAYWRIGHT_BROWSERS_PATH='/var/lib/playwright-browsers'
export PATH='/home/<runner-user>/.nvm/versions/node/v24.15.0/bin:/usr/bin:/bin'
export RUNTIME_PATH='/mnt/site/runtime'
export STATIC_PUBLISHER_WP_ROOT='/var/www/site'
publisher-exporter queue-runner --runtime-dir "$RUNTIME_PATH" --max-jobs 1
```

## Configuration Notes

- `sourceOrigin` is now server-derived from WordPress Site Address URL and treated as read-only in admin UI.
- `outputDir` and `logDir` are storage-relative when saved from WordPress admin. In shared-runtime setups, keep them relative so each machine resolves them against its own mount of the same `smartcloud-static-publisher` storage root.
- `concurrency` controls parallel page rendering workers.
- `assetDownloadConcurrency` controls the later asset download phase separately, so asset fetches can run with a higher worker count than full page renders.
- `rewriteConcurrency` controls the final text rewrite pass. When omitted, it falls back to `assetDownloadConcurrency`, so existing configs keep working without a new required field.
- `extraReplacements` supports key-value rewrite pairs for text output.
- `postCrawlCopyMap` supports copying external files/folders into export output after crawl runs, including incremental crawl/publish; single-URL and retry-timeouts runs skip it. Source keys may use `@storage-root`, `@runtime`, or `@wp-root`; `@wp-root` resolves from `STATIC_PUBLISHER_WP_ROOT` or `WPSUITE_STATIC_PUBLISHER_WP_ROOT` on the crawler host.

For SDK deploy modes, unchanged-file detection is optimized:

- Fast path: compare S3 object `ETag` + size when ETag is single-part MD5.
- Fallback path: compare stored object metadata checksum (`x-amz-meta-wpsuite-sha256`) when ETag is not decisive.
- Uploads store `wpsuite-sha256` metadata for more accurate future skips.

Example:

```json
{
  "targetOrigin": "https://wpsuite.io",
  "urlRewriteMode": "relative",
  "seedPaths": ["/"],
  "sitemapPaths": ["/sitemap_index.xml", "/sitemap.xml"],
  "allowedAssetHosts": ["wpsuite.local", "localhost"],
  "extraReplacements": {
    "https://dev.wpsuite.io": "https://wpsuite.io"
  },
  "postCrawlCopyMap": {
    "@storage-root/shared-assets/": "/shared-assets/",
    "@wp-root/wp-content/uploads/wpsuite-static/": "/wpsuite/wp-content/uploads/wpsuite-static/"
  },
  "blockedPathPrefixes": ["/wp-admin", "/wp-login.php", "/wp-json"],
  "concurrency": 1,
  "assetDownloadConcurrency": 6,
  "rewriteConcurrency": 6,
  "logLevel": "info",
  "s3SyncMode": "sdk-upload-delete"
}
```

Extended example with base target and extra targets:

```json
{
  "targetOrigin": "https://staging.example.com",
  "urlRewriteMode": "absolute",
  "s3": {
    "bucket": "my-site-staging"
  },
  "deploymentProfiles": {
    "prod": {
      "targetOrigin": "https://example.com",
      "s3": {
        "bucket": "my-site-prod"
      },
      "cloudFront": {
        "distributionId": "E1234567890"
      }
    }
  }
}
```

## Validation

Recommended checks:

```bash
cd admin && npm run build
php -l smartcloud-static-publisher.php
```

## Security Notes

- Capability checks are enforced on admin REST endpoints (`manage_options`).
- REST requests use WordPress nonces.
- Inputs are sanitized before persisting config and queue jobs.
- Log file reads are restricted to known runtime log files.

### Example IAM Role Profiles (Least Privilege)

Adjust bucket, prefix, account ID, and distribution ID before use.

Command to profile mapping:

- `deploy` -> `deploy-only`
- `invalidate` -> `deploy+invalidate`
- `publish` (crawl + deploy + invalidate) -> `deploy+invalidate`

`deploy-only` policy (S3 only):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ListOnlyTargetPrefix",
      "Effect": "Allow",
      "Action": ["s3:ListBucket"],
      "Resource": "arn:aws:s3:::YOUR_BUCKET",
      "Condition": {
        "StringLike": {
          "s3:prefix": ["YOUR_PREFIX/*"]
        }
      }
    },
    {
      "Sid": "RWOnlyTargetPrefixObjects",
      "Effect": "Allow",
      "Action": [
        "s3:GetObject",
        "s3:PutObject",
        "s3:DeleteObject",
        "s3:AbortMultipartUpload",
        "s3:ListBucketMultipartUploads",
        "s3:ListMultipartUploadParts"
      ],
      "Resource": "arn:aws:s3:::YOUR_BUCKET/YOUR_PREFIX/*"
    }
  ]
}
```

`deploy+invalidate` policy (S3 + CloudFront invalidation):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ListOnlyTargetPrefix",
      "Effect": "Allow",
      "Action": ["s3:ListBucket"],
      "Resource": "arn:aws:s3:::YOUR_BUCKET",
      "Condition": {
        "StringLike": {
          "s3:prefix": ["YOUR_PREFIX/*"]
        }
      }
    },
    {
      "Sid": "RWOnlyTargetPrefixObjects",
      "Effect": "Allow",
      "Action": [
        "s3:GetObject",
        "s3:PutObject",
        "s3:DeleteObject",
        "s3:AbortMultipartUpload",
        "s3:ListBucketMultipartUploads",
        "s3:ListMultipartUploadParts"
      ],
      "Resource": "arn:aws:s3:::YOUR_BUCKET/YOUR_PREFIX/*"
    },
    {
      "Sid": "InvalidateSpecificDistribution",
      "Effect": "Allow",
      "Action": ["cloudfront:CreateInvalidation"],
      "Resource": "arn:aws:cloudfront::YOUR_ACCOUNT_ID:distribution/YOUR_DISTRIBUTION_ID"
    }
  ]
}
```

## External Calls

Depending on your configuration and selected command, the workflow can call:

- Source origin and allowed asset hosts during crawl/render (`sourceOrigin`, `allowedAssetHosts`).
- AWS S3 APIs during deploy (`PutObject`, `ListObjectsV2`, `DeleteObjects`).
- AWS CloudFront API during invalidate (`CreateInvalidation`).

The WordPress plugin itself only stores config/queue state and does not execute the crawl/deploy shell workflow directly.

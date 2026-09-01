=== SmartCloud Static Publisher ===
Contributors: smartcloud
Tags: static site, playwright, s3, cloudfront, export
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.13
License: MIT
License URI: https://mit-license.org/
Text Domain: smartcloud-static-publisher

Reliable static export for WordPress with sitemap-based crawl, browser rendering, asset capture, and S3 + CloudFront deployment.

== Description ==

Static Publisher is a WordPress plugin + Node.js exporter workflow for deterministic static publishing.

Static Publisher is part of the WP Suite product family by Smart Cloud Solutions, Inc. WP Suite keeps WordPress as the CMS and editing layer, while optional connected features can extend selected workflows into modular AWS-backed services for identity, AI, APIs, workflows, protected routes, and static delivery. The free Static Publisher features described here do not require a WP Suite account, subscription, or WP Suite-managed AWS backend; you control any AWS credentials and target infrastructure you configure for publishing.

Project repository and extended documentation:
https://github.com/smartcloudsol/static-publisher

It is designed for setups where WordPress is the editor/origin and production is served from static hosting (for example S3 + CloudFront).

The plugin provides:

* Admin UI for export configuration
* Runtime config generation in uploads
* Job queueing (publish, crawl, deploy, invalidate, retry-timeouts, single URL)
* Run status and log viewing

The Node.js exporter provides:

* Sitemap-based discovery
* Playwright rendering for JS-heavy pages
* Asset capture from network + parsed sources
* Separate concurrency for page rendering, asset downloads, and final rewrite
* URL rewriting modes (absolute, root-relative, relative)
* Resumable targeted content sync with archive, sitemap, and tombstone reconciliation
* S3 upload and CloudFront invalidation
* Detailed logging for crawl, deploy, and invalidate

This plugin does not execute shell commands directly from PHP.
Instead, it writes queue/config runtime files that an external Node runner executes.

This shell-first design is intentional: rendered frontend pages can be exported by Playwright/Node while WordPress/PHP remains focused on queueing and configuration.

This plugin is not affiliated with or endorsed by Amazon Web Services or the WordPress Foundation. All trademarks are property of their respective owners.

== Usage Notice ==

SmartCloud Static Publisher does not require an external SaaS account to operate.

The plugin itself only manages configuration and queue state in WordPress.

Actual crawling and deployment are executed by your own Node runtime using this project’s exporter commands. This means:

* You control when and where exports run.
* You control AWS credentials and target infrastructure.
* WordPress/PHP does not proxy deployment traffic.
* The WordPress plugin ZIP does not bundle the Node.js exporter runtime; install `@smart-cloud/publisher-exporter` separately on the machine that processes queued jobs.

== Free and Premium Usage Notice ==

SmartCloud Static Publisher is fully functional in Free mode and does not require a WP Suite account, subscription, trial period, or paid service to perform its core static publishing workflow.

In Free mode, the plugin supports the complete static publishing flow:

* Configure source and target settings in WP Admin.
* Queue and run full `crawl`, `publish`, `deploy`, `invalidate`, `retry-timeouts`, and single-URL jobs.
* Export rendered WordPress pages using the separately installed Node.js exporter.
* Capture required assets discovered during browser rendering.
* Rewrite URLs according to the configured rewrite mode.
* Deploy exported files to the configured S3 bucket.
* Create CloudFront invalidations for the configured distribution.
* View current job status and standard run logs.

Free mode includes S3 deployment and CloudFront invalidation. These are not paid-only features.

Audit Logs is also part of the main Static Publisher navigation and is not gated behind a WP Suite subscription.

The plugin package itself manages WordPress-side configuration, queue state, runtime files, and status/log access. Actual crawling and deployment are executed by the separately installed `@smart-cloud/publisher-exporter` CLI on the user’s own server, workstation, CI runner, or other queue-runner host.

Optional WP Suite Pro features are not required for the plugin to work. They are additional workflow, convenience, and team/enterprise publishing features. Examples may include:

* Incremental crawl / incremental publish and targeted content sync for Professional or Agency subscriptions.
* Scheduled workflows and operational content-sync status.
* Extra Deployment Targets and Scheduler Settings backed by linked WP Suite site configuration.
* Additional team/workspace-oriented configuration features.

Extra Deployment Targets are selected by key at deploy time, while downloaded job configs keep only the base target plus an optional target override id.

These optional Pro features may be visible in the plugin interface as upgrade-only controls, but they are separate from the fully functional free static publishing workflow described above.

When no active WP Suite subscription is connected, SmartCloud Static Publisher remains usable for full static crawl/publish/deploy workflows. There is no time limit, export quota, forced trial expiry, or required payment for the core static publishing functionality.

== Installation ==

1. Upload the plugin ZIP (or install from the WordPress plugin repository).
2. Activate the plugin in WordPress.
3. Open WP Admin -> SmartCloud -> Static Publisher.
4. Configure source/target settings and queue an export job.
5. Run the Node exporter in your environment so queued jobs are processed.

Install `@smart-cloud/publisher-exporter` separately on the queue-runner host. The plugin package itself does not ship the Node runtime or npm dependencies.

Package docs: https://www.npmjs.com/package/@smart-cloud/publisher-exporter

Direct CLI invocation from cron is the recommended setup.

If you redirect cron stdout/stderr to a file, create that parent directory before enabling cron. Shell redirection will not create missing parent directories for you.

`sudo install -d -o <cron-user> -g <cron-user> -m 755 /path/to/wordpress/wp-content/uploads/smartcloud-static-publisher/logs`

Run one queued job manually on the runner host:

`publisher-exporter queue-runner --runtime-dir /path/to/wordpress/wp-content/uploads/smartcloud-static-publisher/runtime --max-jobs=1`

Same host Linux cron example:

`SHELL=/bin/bash`

`HOME=/home/<cron-user>`

`PATH=/home/<cron-user>/.nvm/versions/node/v24.15.0/bin:/usr/bin:/bin`

`PLAYWRIGHT_BROWSERS_PATH=/var/lib/playwright-browsers`

`RUNTIME_PATH=/path/to/wordpress/wp-content/uploads/smartcloud-static-publisher/runtime`

`LOG_PATH=/path/to/wordpress/wp-content/uploads/smartcloud-static-publisher/logs`

`* * * * * /usr/bin/flock -n /tmp/static-publisher.cron.lock publisher-exporter queue-runner --runtime-dir "$RUNTIME_PATH" --max-jobs 1 >> "$LOG_PATH/queue-runner-cron.log" 2>&1`

`17 3 * * * publisher-exporter prune-logs --runtime-dir "$RUNTIME_PATH" --older-than-days 30 >> "$LOG_PATH/prune-logs-cron.log" 2>&1`

If you want a stable launcher across Node upgrades under NVM, prefer a small `~/bin/publisher-exporter` wrapper over a plain symlink to `~/.nvm/versions/node/vX.Y.Z/bin/publisher-exporter`. A plain symlink to the versioned NVM path breaks after upgrades.

Separate hosts with shared mounted storage:

Keep `outputDir` and `logDir` storage-relative in admin, for example `export` and `logs`, then point the crawler host at its own local mount path of the shared publisher storage.

Example crawler-host cron:

`SHELL=/bin/bash`

`HOME=/home/<runner-user>`

`PATH=/home/<runner-user>/.nvm/versions/node/v24.15.0/bin:/usr/bin:/bin`

`PLAYWRIGHT_BROWSERS_PATH=/var/lib/playwright-browsers`

`RUNTIME_PATH=/mnt/site/runtime`

`LOG_PATH=/mnt/site/logs`

`* * * * * /usr/bin/flock -n /tmp/static-publisher.cron.lock publisher-exporter queue-runner --runtime-dir "$RUNTIME_PATH" --max-jobs 1 >> "$LOG_PATH/queue-runner-cron.log" 2>&1`

If `postCrawlCopyMap` also needs to see the WordPress tree from the crawler host, set `STATIC_PUBLISHER_WP_ROOT` (or `WPSUITE_STATIC_PUBLISHER_WP_ROOT`) on that host too.

Windows / LocalWP manual run example:

`$env:STATIC_PUBLISHER_RUNTIME_DIR='C:\Local Sites\my-site\app\public\wp-content\uploads\smartcloud-static-publisher\runtime'; npx @smart-cloud/publisher-exporter queue-runner --runtime-dir $env:STATIC_PUBLISHER_RUNTIME_DIR --max-jobs=1`

Windows / LocalWP scheduled run:

1. Create a PowerShell wrapper file, for example `C:\smartcloud-static-publisher\run-queue-runner.ps1`.
2. Put this into that file:

`$env:STATIC_PUBLISHER_RUNTIME_DIR='C:\Local Sites\my-site\app\public\wp-content\uploads\smartcloud-static-publisher\runtime'; & 'C:\Program Files\nodejs\npx.cmd' '@smart-cloud/publisher-exporter' 'queue-runner' '--runtime-dir' $env:STATIC_PUBLISHER_RUNTIME_DIR '--max-jobs' '1'; exit $LASTEXITCODE`

3. In Windows Task Scheduler create a task with a trigger that repeats every 1 minute indefinitely.
4. Use `powershell.exe` as Program/script and `-NoProfile -ExecutionPolicy Bypass -File "C:\smartcloud-static-publisher\run-queue-runner.ps1"` as Add arguments.

== Server Prerequisites (Node + Playwright) ==

The exporter requires both Node.js and Playwright browser binaries on the machine that executes queued jobs.

Recommended Node setup is NVM + latest LTS:

`curl -fsSL https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.3/install.sh | bash`

`export NVM_DIR="$HOME/.nvm" && . "$NVM_DIR/nvm.sh" && nvm ls-remote --lts && nvm install --lts && nvm use --lts`

Install the standalone exporter CLI package and Chromium browser binary:

`npm install -g @smart-cloud/publisher-exporter && publisher-exporter install-browsers`

Important:

* If cron runs under the same OS user that installed Node and Playwright, NVM plus the default user-scoped Playwright cache is fine.
* If cron runs as a different or non-login service user such as `www-data`, prefer an explicit `HOME`, a `PATH` that already contains `publisher-exporter` and `node`, plus a shared `PLAYWRIGHT_BROWSERS_PATH`.
* In crontab, prefer absolute paths instead of relying on `$HOME` expansion inside the `PATH` value.
* `npx playwright install` is not a global install; browser binaries are user-scoped by default (usually under `~/.cache/ms-playwright`).
* If multiple users may run jobs, use `PLAYWRIGHT_BROWSERS_PATH` to point to a shared browser folder and grant proper permissions.
* If the shared browser directory lives under a protected system path, create it once with elevated privileges and make it writable by the same OS user that will run `publisher-exporter install-browsers`. The later cron job only needs read/execute access to that directory tree.
* For internal/self-signed TLS origins, enable `Allow self-signed TLS certificates during crawl` in the admin UI (config key: `ignoreHttpsErrors`).

If you prefer a shared browser cache for a non-login service user such as `www-data`, create the shared directory once, hand it to the job user, then install browsers from the CLI package without keeping the browser cache root-owned:

`sudo mkdir -p /var/lib/playwright-browsers && sudo chown "$USER":"$USER" /var/lib/playwright-browsers && PLAYWRIGHT_BROWSERS_PATH=/var/lib/playwright-browsers /usr/bin/npx @smart-cloud/publisher-exporter install-browsers`

== Run A Queued Job From Another Machine ==

If the WordPress host cannot run Node, Playwright, or cron, you can replay a queued job from your own shell or CI machine.

1. In the Job Queue panel click `Download config` next to the queued job and save the file as `queued-job.json`.
2. Extract the nested `publisherConfig` object to `publisher.config.json` using one of the commands already included in the downloaded file under `manualExecution.commands`.
3. Install `@smart-cloud/publisher-exporter` on that machine first.
4. Optionally edit `publisher.config.json` locally, for example to change `outputDir` to a writable local folder.
5. Run the exact command from `manualExecution.commands.jobPosix` or `manualExecution.commands.jobPowerShell`.
6. If you also want to deploy from your own machine, continue with the provided `deploySdk` and `invalidateSdk` commands from the same `manualExecution.commands` block.

Important:

* This is an out-of-band replay of the queued job and does not mark the WordPress queue item as completed automatically.
* If the original queued item should not run later on the server, remove or clean it up in WordPress after your manual replay.
* The WordPress plugin ZIP does not contain the exporter runtime. Install `@smart-cloud/publisher-exporter` separately on the machine that replays the downloaded job.

== Shared Runtime Across Two Hosts ==

SmartCloud Static Publisher can run with WordPress on one machine and the queue runner on another, as long as both machines point to the same shared `wp-content/uploads/smartcloud-static-publisher` storage.

Example:

* WordPress host sees the storage at `/var/www/site/wp-content/uploads/smartcloud-static-publisher`
* Queue runner host mounts the same storage at `/mnt/site`
* Queue runner uses `STATIC_PUBLISHER_RUNTIME_DIR='/mnt/site/runtime'`

Recommended rules for this setup:

* Keep `outputDir` and `logDir` storage-relative in admin, for example `export` and `logs`, not machine-specific absolute paths.
* Use `@storage-root` in `postCrawlCopyMap` when the source files are already inside the shared publisher storage.
* Use `@wp-root` only when the queue runner host can also access the WordPress tree, and set `STATIC_PUBLISHER_WP_ROOT` (or `WPSUITE_STATIC_PUBLISHER_WP_ROOT`) on that host.
* `@runtime` points to the shared runtime folder itself.

Example `postCrawlCopyMap` sources:

* `@storage-root/shared-assets/`
* `@wp-root/wp-content/uploads/smartcloud-static-publisher/`

If you inspect the raw `queue-runner-heartbeat.json`, the `runtimeDir` and `exporterDir` values reflect the queue runner host paths. That is expected and does not break WordPress-side queue state handling.

== Machine-readable resources ==

* Plugin manifest: https://wpsuite.io/.well-known/ai-plugin.json
* OpenAPI spec (backend): https://wpsuite.io/.well-known/openapi.yaml

== Frequently Asked Questions ==

= Does this plugin run crawling and deploy from PHP? =
No. For safety and reliability, PHP only stores config and queue instructions. The actual crawl/deploy is performed by the Node exporter.

= Does WordPress WP-Cron execute queued jobs automatically? =
Not by default. This plugin does not spawn Node.js from PHP.
Recommended production setup is system cron (or systemd timer) that runs `publisher-exporter queue-runner`.

= Do Scheduler Settings run jobs by themselves? =
No. Scheduler rules are evaluated only when the external `publisher-exporter queue-runner` process starts from cron, systemd timer, or Windows Task Scheduler.

Important details:
* Scheduler only adds matching jobs to `runtime/queue.json`; the same runner tick or a later tick then processes them through the normal queue.
* Recommended cadence is every 1 minute.
* Supported scheduled commands are `publish`, `crawl`, `deploy`, `invalidate`, `retry-timeouts`, `url`, and Professional/Agency-only `content-sync`.
* Content sync requires a successful normal publish baseline and processes only the configured post types, multisite scope, listings, archives, and sitemap surfaces.
* The scheduler timezone field is currently informational for operations context; interval matching is based on elapsed minute buckets checked at each runner start.
* If an equivalent queued or running job already exists for the same command, crawl mode, deployment profile, and URL, the rule is skipped for that interval bucket.

= Can this run on LocalWP / Windows too? =
Yes. Start `publisher-exporter queue-runner` or `npx @smart-cloud/publisher-exporter queue-runner` manually from PowerShell for one-off processing, or use Windows Task Scheduler with a repeating 1-minute trigger plus a small PowerShell wrapper. Linux production should use system cron.

= Can I download a queued job and run it from another machine? =
Yes. Use `Download config` next to the queued job, extract `publisherConfig` into `publisher.config.json`, then run the exact `manualExecution.commands.jobPosix` or `manualExecution.commands.jobPowerShell` command from the downloaded JSON. This replays the job outside WordPress and does not update queue state automatically.

= Can I choose deploy strategy for S3? =
Yes. Exporter config supports two SDK modes:
* `sdk-upload-delete`
* `sdk-upload-only`

= Is deploy progress logged? =
Yes. Deploy and invalidate now write detailed progress logs in addition to crawl logs. Use `logLevel` (`error`, `warn`, `info`, `debug`) to control verbosity.

= Does SDK deploy re-upload unchanged files every time? =
No. SDK deploy now skips unchanged files using S3 ETag + size fast checks, with checksum metadata fallback (`x-amz-meta-wpsuite-sha256`) when ETag is not decisive.

= Can pages and assets use different concurrency values? =
Yes. `concurrency` controls parallel page rendering workers, `assetDownloadConcurrency` controls the later asset download phase, and `rewriteConcurrency` controls the final text rewrite pass. If `rewriteConcurrency` is omitted it falls back to `assetDownloadConcurrency`, so existing configurations keep the earlier behavior.

= How should I use blocked query fragments? =
Use `blockedSearchFragments` for preview or editor query patterns that must never enter the crawl queue. The setting is empty by default. If your WordPress setup exposes plugin-specific preview URLs, add those fragments explicitly to your config. Typical examples include page-builder previews, WordPress Customizer preview parameters, multilingual editor preview flags, and similar per-plugin markers. This matters because exported page output paths ignore query strings, so a preview URL can overwrite the canonical output for the same page path.

= Why is there a generator meta tag in exported HTML? =
Sites without an active WP Suite subscription receive this tag in exported HTML pages:

`<meta name="generator" content="WPSuite.io Static Publisher" />`

The exporter adds it during HTML rewrite only once per file, so repeated rewrite/deploy passes do not duplicate it. Sites with an active WP Suite subscription do not receive this tag.

= Can I pass temporary AWS credentials from admin? =
Yes, for `publish`, `deploy`, and `invalidate` jobs.

Use the `Temp AWS creds` dialog in the Job Queue panel and provide:

* `AWS_ACCESS_KEY_ID`
* `AWS_SECRET_ACCESS_KEY`
* `AWS_SESSION_TOKEN` (optional)

These are attached to the queued job and injected by queue-runner only for that job process.

= Why use sitemap-based crawling? =
It avoids non-deterministic router crawling and gives controlled page discovery, including sitemap index recursion.

= Can I export only one URL? =
Yes. Queue the `url` command with a specific path.

= Does it support retries? =
Yes. Timeout retries are supported (`retry-timeouts`) without requiring a full rerun. They now look at the newest archived full `crawl` or `publish` job logs, not at the most recent unrelated root log files from a later `deploy` or single-URL run.

= Where are runtime and logs stored? =
Runtime state lives under `wp-content/uploads/smartcloud-static-publisher/runtime/`.
Exporter logs are written under `wp-content/uploads/smartcloud-static-publisher/<logDir>/`.
After each finished, failed, or stopped job, queue-runner also writes gzip-compressed per-file artifacts plus `job.json` into `wp-content/uploads/smartcloud-static-publisher/<logDir>/archive/<timestamp-command-jobId-status>/` together with the latest progress snapshot when available.
Audit Log rows for finished and stopped runs can download those archived artifacts from WordPress admin.
Use `publisher-exporter prune-logs --runtime-dir /path/to/wordpress/wp-content/uploads/smartcloud-static-publisher/runtime --older-than-days 30` from daily cron if you want automatic retention cleanup.

= Will it work with Elementor and lazy-loaded frontends? =
Yes. Export uses Playwright rendering, so runtime-loaded markup/assets can be captured.

= Where should Playwright browsers be installed when using cron? =
Install them for the same OS user that executes the queue-runner cron job.
If needed, set `PLAYWRIGHT_BROWSERS_PATH` to a shared location and ensure that cron user has access.

= Can crawl run against self-signed certificates? =
Yes. Enable `Allow self-signed TLS certificates during crawl` in admin.
This should only be used for trusted internal environments. Keep it disabled for strict certificate validation.

== Screenshots ==

1. Static Publisher admin dashboard
2. Core export configuration panel
3. S3 and CloudFront settings
4. Job queue and command selection
5. Runtime logs viewer

== External Services ==

This plugin/workflow may integrate with the following external services, depending on configuration:

1. **Source WordPress origin and allowed asset hosts (required for crawl/export)**
   - **What it is & what it’s used for:**
     The configured source origin and allowed asset hosts are fetched during crawl/render to collect pages and assets for static export.
   - **What data is sent & when:**
     Standard HTTP(S) requests from the exporter to source pages/assets. Request data typically includes normal browser request metadata (URL, headers, cookies/session context if your source site requires it).
   - **Where it goes:**
     `sourceOrigin` and hostnames listed in `allowedAssetHosts`.

2. **Amazon S3 (optional; deploy command)**
   - **What it is & what it’s used for:**
     Object storage target for exported static files.
   - **What data is sent & when:**
     Exported HTML/assets plus object metadata (content type, cache-control) when `deploy` runs.
   - **Where it goes / API usage:**
     AWS S3 APIs via AWS SDK (`PutObject`, `ListObjectsV2`, `DeleteObjects`) to your configured bucket/region.
   - **Links:**
     - AWS Service Terms: https://aws.amazon.com/service-terms/
     - AWS Privacy: https://aws.amazon.com/privacy/

3. **Amazon CloudFront (optional; invalidate command)**
   - **What it is & what it’s used for:**
     CDN invalidation after deployment.
   - **What data is sent & when:**
     Invalidation path list and distribution identifier when `invalidate` runs.
   - **Where it goes / API usage:**
     AWS CloudFront API via AWS SDK (`CreateInvalidation`) for your configured distribution.
   - **Links:**
     - AWS Service Terms: https://aws.amazon.com/service-terms/
     - AWS Privacy: https://aws.amazon.com/privacy/

4. **WP Suite platform connection (optional; site/workspace linking & shared features)**
   - **When it applies:**
     When you use **WP Admin → SmartCloud → Connect your Site to WP Suite** to link this WordPress site to a WP Suite workspace, or to switch/disconnect later.
   - **What it’s used for:**
     Storing and retrieving Pro feature configuration (e.g., API/chatbot/feature settings) and enabling an admin-side preview experience so you can try Pro features in WP Admin before enabling them on the live site.
   - **What data may be sent:**
     Minimal account/session data required for authentication, and minimal site/workspace linking data required to associate a WordPress site with a workspace (e.g., site/workspace identifiers and the site’s URL/domain).
   - **Where it goes / how it’s called:**
     Secure HTTPS requests from the browser to WPSuite.io services (e.g. **wpsuite.io** and **api.wpsuite.io**).
   - **Links:**
     - WPSuite.io Privacy Policy: https://wpsuite.io/privacy-policy
     - WPSuite.io Terms of Use: https://wpsuite.io/terms-of-use

5. **Amazon Cognito (optional; authentication for WP Suite Hub and/or protected APIs)**
   - **When it applies:**
     - When using the **WP Suite Hub**, users authenticate (sign in / sign up) before creating/selecting a workspace and linking a site.
     - If a plugin is configured to access protected endpoints that rely on Cognito, authentication/token flows may also be used for those requests.
   - **What it’s used for:**
     User authentication and token-based authorization for subsequent API calls (e.g., to WPSuite.io APIs).
   - **Links:**
     - AWS Service Terms: https://aws.amazon.com/service-terms/
     - AWS Privacy: https://aws.amazon.com/privacy/

6. **Stripe (optional; subscription/purchase flow)**
   - **When it applies:** Only when the user opens the optional WP Suite subscription / purchase flow in the shared admin component.
   - **What it’s used for:** Displaying hosted pricing/subscription UI for optional paid features.
   - **What data may be sent:** Browser/session data required by Stripe to render the hosted purchase UI and process the purchase flow.
   - **Links:**
     - Terms: https://stripe.com/legal/consumer
     - Privacy: https://stripe.com/privacy

== Example IAM Role Profiles ==

Adjust values before use (`YOUR_BUCKET`, `YOUR_PREFIX`, `YOUR_ACCOUNT_ID`, `YOUR_DISTRIBUTION_ID`).

Command-to-profile mapping:

* `deploy` -> `deploy-only`
* `invalidate` -> `deploy+invalidate`
* `publish` -> `deploy+invalidate`

`deploy-only` policy (S3 only):

`{
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
}`

`deploy+invalidate` policy (S3 + CloudFront invalidation):

`{
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
}`

== Trademark Notice ==

Amazon Web Services, AWS, Amazon S3, and Amazon CloudFront are trademarks of Amazon.com, Inc. or its affiliates.

SmartCloud Static Publisher is an independent project and is not affiliated with, sponsored by, or endorsed by Amazon Web Services or the WordPress Foundation.

== Source & Build ==

Public source code:
The project source is maintained by Smart Cloud Solutions, Inc.

Build and distribution:
SmartCloud Static Publisher is shipped to WordPress.org as a pre-built distribution.
Build steps and development notes are documented in the repository README.

== Changelog ==

= 1.0.13 =
* Restore safety: Recover the last public slug when a trashed post is restored and republished, even if WordPress retained a `__trashed` desired-slug alias, and repair earlier invalid journal projections before impact planning.
* Content-sync performance: Add Media Library file change tokens and verified release-fingerprint reuse for unchanged WordPress core, plugin, and theme assets so targeted jobs avoid redundant asset downloads and rewrites.
* Baseline safety: Extend release fingerprints to plugin and theme images, fonts, source maps, manifests, and other render dependencies before allowing trusted asset reuse.
* Admin UI: Hide the native WordPress checkbox beneath Mantine switches and add appropriate pointer or disabled cursors.

= 1.0.12 =
* Incremental publishing: Refresh singular listing pages containing Query Loops and complete archive pagination families when published membership, taxonomy assignments, or sticky state changes.
* Targeted content sync: Add scheduler-driven, resumable publish/update/taxonomy/sticky/unpublish/delete processing with manifest-backed tombstones, archive and sitemap reconciliation, completed CloudFront invalidations, verification, and cursor acknowledgement.
* Baseline safety: Require a verified normal publish after release, scope, or deployment-target changes and show actionable stale-baseline status in the admin.
* Trash handling: Preserve the exact last public permalink across repeated trash/restore/trash transitions instead of leaking WordPress `__trashed` aliases into deployment plans.
* Multisite: Add an opt-in full-network content-sync scope for same-origin path-based networks while keeping current-site-only tracking as the default; network tracking requires network activation.
* Premium access: Require an active WP Suite Professional or Agency subscription for incremental publishing and targeted content sync.
* Packaging: Include and verify the content-journal runtime in canonical plugin builds.

= 1.0.11 =
* Compatibility: Declared compatibility with WordPress 7.1.

= 1.0.10 =
* Multisite: Store Hub ownership per site and recognize network-activated owners when selecting the shared Hub runtime.
* Hub admin: Load the WebCrypto vendor before the shared admin bundle.

= 1.0.9 =
* Dependency: Rebuilt the bundled WP Suite Hub and Amplify vendor runtime with exact supported SmartCloud Amplify UI 6.15.5/3.6.5/6.15.5 versions.

= 1.0.8 =
* Compatibility: Restored ownership-safe WP Suite Theme CSS fragment updates on WordPress-managed Custom CSS storage.
* Fixed: Pointed exporter runtime configuration at the root-level virtual WP Suite license and configuration endpoints instead of the retired uploads directory.
* Compatibility: Renamed the exporter runtime field from `uploadUrl` to `virtualAssetBaseUrl`, while retaining legacy input support for queued jobs created before the update.

= 1.0.7 =
* Packaging: Renamed the bundled shared runtime directory to `smartcloud-wpsuite`.
* Migration: Made `smartcloud-wpsuite` the canonical admin, option, and REST namespace while retaining legacy aliases and site-settings fallback for rolling upgrades.
* Cleanup: Added multisite-aware uninstall removal for Static Publisher options and its complete uploads runtime, logs, and export tree without touching shared WP Suite licences.

= 1.0.6 =
* Maintenance: Refreshed the Static Publisher admin and exporter package chain, AWS SDK/browser tooling, and dependency security fixes.
* Compatibility: Updated the bundled shared Hub runtime to 2.5.7.

= 1.0.5 =
* Shared Hub: Added ownership-safe WP Suite Theme CSS fragment updates and hardened the frontend settings bootstrap for CSS-rich configuration values.
* Compatibility: Updated the bundled shared Hub runtime to 2.5.6.

= 1.0.4 =
* Fix: Made the shared WP Suite Abilities foundation safe to load from multiple product plugin paths without redeclaring its base provider class.
* Compatibility: Updated the bundled shared Hub runtime to 2.5.5 so existing sites re-elect a current Hub owner after upgrading.

= 1.0.3 =
* Incremental publishing: Added a safe change-token provider hook for public routes backed by theme or plugin files.
* Admin: Restored ordered and unordered list markers in the documentation sidebar without loading global Mantine CSS.

= 1.0.2 =
* Admin: Removed obsolete server-side Node.js and Playwright diagnostics from the job queue screen.

= 1.0.1 =
* Compatibility: Refined paid-feature handling in preparation for upcoming WP Suite Agency subscriptions.

= 1.0.0 =
* Initial release.
* WordPress admin for static publisher configuration and queue control.
* Runtime file generation under uploads directory.
* Secure REST endpoints with capability and nonce checks.
* Playwright-based static export integration with S3 and CloudFront workflow.

== Upgrade Notice ==

= 1.0.13 =
Recommended for sites using targeted content sync. Run one successful normal full or incremental publish after upgrading to establish the new release baseline and populate trusted Media Library asset tokens.

= 1.0.12 =
Recommended for incremental exports containing Query Loops or paginated archives and for Professional or Agency sites using targeted content sync. Run one successful normal publish after upgrading to establish the required baseline. Multisite subsite tracking is opt-in and requires network activation.

= 1.0.11 =
Declares compatibility with WordPress 7.1.

= 1.0.10 =
Recommended for multisite installations using the shared WP Suite Hub.

= 1.0.9 =
Recommended dependency refresh away from deprecated SmartCloud Amplify UI releases.

= 1.0.5 =
Recommended compatibility update for safe shared WP Suite Theme CSS management and reliable frontend bootstrap data when CSS-rich settings are present.

= 1.0.4 =
Recommended compatibility update for sites running multiple WP Suite plugins. It prevents a shared Abilities class redeclaration during plugin loading or updates.

= 1.0.3 =
Adds incremental change-token support for file-backed public routes and improves documentation sidebar readability. No configuration changes are required.

= 1.0.2 =
Small admin cleanup. No configuration changes are required.

= 1.0.1 =
Recommended compatibility update in preparation for upcoming WP Suite Agency subscriptions. No configuration changes are required.

= 1.0.0 =
Initial release.

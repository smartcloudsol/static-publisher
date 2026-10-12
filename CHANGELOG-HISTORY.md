# Historical upgrade guidance

The following original operator guidance is preserved in full from the Static Publisher upgrade notices. The public readme uses short notices to fit the WordPress.org 300-character limit. Follow the notes for the version being installed; external exporter and worker updates are separate from the WordPress plugin.

## 1.0.26

Install @smart-cloud/publisher-exporter 1.1.81 on the coordinator. Before selecting a delegated cross-account target, deploy the updated worker stack with its exact S3-only target role and external ID, then reinstall the generated remote-workers.json. Configure the queue-runner user's named AWS profile with the separate coordinator target role when local S3 or CloudFront calls need that identity.

## 1.0.25

Install @smart-cloud/publisher-exporter 1.1.74 and restart the external queue runner before enabling page-cache purge. Keep purge disabled until a server adapter implements the generic provider contract and a real source request proves MISS-to-HIT behavior without caching authentication redirects or logged-in HTML. The first incremental crawl after upgrading may render all supported pages once because the global token schema now includes the site revision and configured dependency hashes.

## 1.0.24

After active jobs finish, install @smart-cloud/publisher-exporter 1.1.72 and restart every external coordinator queue runner. The saved-page counter fix applies to new jobs; it does not rewrite an active job's progress. Existing tested Lambda workers on 1.1.71 can remain in place because the 1.1.72 remote-worker bundle is byte-identical; no stack update or remote-workers.json replacement is needed solely for this counter fix. If upgrading older worker infrastructure, deploy workers with the 1.1.71 Chromium launch fix and install the regenerated remote-workers.json to enable direct DynamoDB intra-batch progress; older configs expose progress only after a batch completes. Render batching defaults to 5 URLs; review render, rewrite, and deploy batch sizes before increasing them. Republish targets produced by the earlier delegated rewrite implementation to replace incorrect S3 Content-Type metadata.

## 1.0.23

Install @smart-cloud/publisher-exporter 1.1.65 and restart the external queue runner. Before enabling Lambda delegation, deploy the updated worker stack and install its new remote-workers.json containing functions.asset. Existing mixed local/remote settings remain unchanged until an administrator explicitly saves the unified delegation and concurrency controls.

## 1.0.22

Install @smart-cloud/publisher-exporter 1.1.63 and restart the external queue runner so it writes the safe target/rule discovery snapshot, then restart or refresh Agent Composer MCP tool discovery after updating both plugins. The scheduling response now returns a job_id used by the read-only job-status tool. Before queueing a standard content-sync job, complete one successful normal publish so the selected rule and target pair has a ready baseline.

## 1.0.21

Install @smart-cloud/publisher-exporter 1.1.62 on every runner host. Before enabling Lambda rendering, rewriting, or deployment, redeploy the CDK stack and copy its newly generated remote-workers.json to the site's Static Publisher runtime directory; existing files without deployment targets are insufficient for delegated deploys. Remove obsolete PUBLISHER_REMOTE_* variables from cron. Run a crawl or publish to populate Consent candidates, and run one normal publish before relying on targeted plugin-resource updates.

## 1.0.20

AI Kit public release gating requires @smart-cloud/publisher-exporter 1.1.54. Install that exporter on the runner host, let the scheduler evaluate the saved content-sync rules, then complete one successful publish before enabling or expecting gated Knowledge Sync delivery. Existing Static Publisher workflows do not require AI Kit or this opt-in gate.

## 1.0.16

Sites with theme-specific taxonomy archives must install the matching theme routing integration, then run one successful normal full or incremental publish to establish the new release baseline. Existing content-sync retries retain their original impact plan; the successful normal publish supersedes it safely.

## 1.0.12

Recommended for incremental exports containing Query Loops or paginated archives and for Professional or Agency sites using targeted content sync. Run one successful normal publish after upgrading to establish the required baseline. Multisite subsite tracking is opt-in and requires network activation.


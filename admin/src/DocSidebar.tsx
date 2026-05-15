import { Code, Drawer, List, Stack, Text, Title } from "@mantine/core";
import { useEffect, useRef } from "react";
import { __ } from "@wordpress/i18n";

const TEXT_DOMAIN = "smartcloud-static-publisher";

const pages = {
  general: (
    <>
      <Title order={2}>
        <span className="highlightable">
          {__("Static Publisher Settings", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "This sidebar explains what each major setting does on this admin screen. Click an info icon next to a field to jump here.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="shell-runtime-overview">
        <span className="highlightable">
          {__("Shell Runtime Overview", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "WordPress only queues jobs. Crawling and deployment are executed by the Node.js exporter from shell/CLI, not from PHP runtime.",
          TEXT_DOMAIN,
        )}
      </Text>
      <Text mt="xs">
        {__(
          "This enables rendered page export workflows while keeping admin-side execution review-safe.",
          TEXT_DOMAIN,
        )}
      </Text>
      <Text mt="xs" fw={600}>
        {__("Quick server setup:", TEXT_DOMAIN)}
      </Text>
      <List size="sm" spacing="xs" withPadding mt="xs">
        <List.Item>
          {__(
            "Install Node.js and Playwright Chromium under the same OS user that runs WordPress/PHP.",
            TEXT_DOMAIN,
          )}
        </List.Item>
        <List.Item>
          {__(
            "Put queue-runner cron into the same user's crontab, otherwise diagnostics may be misleading.",
            TEXT_DOMAIN,
          )}
        </List.Item>
        <List.Item>
          <Code>
            * * * * * publisher-exporter queue-runner --runtime-dir /.../runtime
            --max-jobs 1
          </Code>
        </List.Item>
        <List.Item>
          {__(
            "On Windows or LocalWP, run queue-runner manually from PowerShell or use Windows Task Scheduler with a repeating 1-minute trigger instead of cron.",
            TEXT_DOMAIN,
          )}
        </List.Item>
      </List>
      <Text mt="xs" fw={600}>
        {__("No shell access on the WordPress host:", TEXT_DOMAIN)}
      </Text>
      <List size="sm" spacing="xs" withPadding mt="xs">
        <List.Item>
          {__(
            "Use Download config next to the queued job, extract publisherConfig into a local publisher.config.json, then run the provided manualExecution.commands.jobPosix or jobPowerShell command from your own shell or CI.",
            TEXT_DOMAIN,
          )}
        </List.Item>
        <List.Item>
          {__(
            "This is an out-of-band replay of the same job instructions. It does not automatically mark the WordPress queue item as completed.",
            TEXT_DOMAIN,
          )}
        </List.Item>
      </List>
      <Text mt="xs" size="sm">
        {__("Full docs and setup examples:", TEXT_DOMAIN)}{" "}
        <a
          href="https://github.com/smartcloudsol/static-publisher"
          target="_blank"
          rel="noreferrer"
        >
          https://github.com/smartcloudsol/static-publisher
        </a>
      </Text>

      <Title order={3} mt="md" id="source-origin">
        <span className="highlightable">
          {__("Source Origin", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "Source origin is read-only and comes from WordPress Site Address URL on the server side. This keeps crawler origin consistent with the active site configuration.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="deployment-targets">
        <span className="highlightable">
          {__("Deployment Targets", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "The base target defines where the normal deploy goes: target origin, S3 bucket/prefix, region, sync mode, and CloudFront invalidation settings.",
          TEXT_DOMAIN,
        )}
      </Text>
      <Text mt="xs">
        {__(
          "Extra targets reuse the same crawl artifact, but override selected deploy-time settings for staging, production, or client-specific destinations.",
          TEXT_DOMAIN,
        )}
      </Text>
      <List size="sm" spacing="xs" withPadding mt="xs">
        <List.Item>
          {__(
            "Base target is always available and remains the fallback when no profile is selected.",
            TEXT_DOMAIN,
          )}
        </List.Item>
        <List.Item>
          {__(
            "Additional profiles are for alternate buckets, domains, and invalidation settings without re-crawling.",
            TEXT_DOMAIN,
          )}
        </List.Item>
        <List.Item>
          {__(
            "If an additional profile changes target origin, the base crawl should use absolute URL rewrite mode so deploy-time replacement stays safe.",
            TEXT_DOMAIN,
          )}
        </List.Item>
      </List>

      <Title order={3} mt="md" id="extra-replacements">
        <span className="highlightable">
          {__("Extra Replacements", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "Use key-value replacement pairs only for custom cases that are not already covered by the normal target-origin rewrite.",
          TEXT_DOMAIN,
        )}
      </Text>
      <Text mt="xs">
        {__(
          "Standard origin rewrite already covers common escaped JSON URL forms as well, including typical Elementor or Yoast JSON/JSON-LD payloads. Use this field only when you need additional non-standard string replacements.",
          TEXT_DOMAIN,
        )}
      </Text>
      <List size="sm" spacing="xs" withPadding mt="xs">
        <List.Item>
          <strong>{__("From", TEXT_DOMAIN)}:</strong>{" "}
          {__("original string or URL to match", TEXT_DOMAIN)}
        </List.Item>
        <List.Item>
          <strong>{__("To", TEXT_DOMAIN)}:</strong>{" "}
          {__("replacement value written to export files", TEXT_DOMAIN)}
        </List.Item>
      </List>

      <Title order={3} mt="md" id="post-crawl-copy-map">
        <span className="highlightable">
          {__("Post-Crawl Copy Map", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "Copies external files/folders into the export tree after crawl runs, including incremental crawl/publish. Key is a filesystem path, value is URL path prefix under export root. Single-URL and retry-timeouts runs skip this step.",
          TEXT_DOMAIN,
        )}
      </Text>
      <Text mt="xs">
        {__(
          "Directory source: full directory contents are copied. File source: file is copied to the exact target path, or under target directory if value ends with '/'.",
          TEXT_DOMAIN,
        )}
      </Text>
      <Text mt="xs">
        {__(
          "Source keys can also use @storage-root, @runtime, or @wp-root aliases. @storage-root resolves to the shared wp-content/uploads/smartcloud-static-publisher root, @runtime resolves to its runtime subdirectory, and @wp-root resolves from STATIC_PUBLISHER_WP_ROOT on the crawler host.",
          TEXT_DOMAIN,
        )}
      </Text>
      <List size="sm" spacing="xs" withPadding mt="xs">
        <List.Item>
          <Code>@wp-root/wp-content/uploads/wpsuite-static/</Code>
          {" -> "}
          <Code>/wpsuite/wp-content/uploads/wpsuite-static/</Code>
        </List.Item>
        <List.Item>
          <Code>@storage-root/export-assets/</Code>
          {" -> "}
          <Code>/static/export-assets/</Code>
        </List.Item>
      </List>

      <Title order={3} mt="md" id="scheduler">
        <span className="highlightable">{__("Scheduler", TEXT_DOMAIN)}</span>
      </Title>
      <Text>
        {__(
          "Scheduler can auto-enqueue queue jobs when publisher-exporter queue-runner is started by cron or another external scheduler.",
          TEXT_DOMAIN,
        )}
      </Text>
      <List size="sm" spacing="xs" withPadding mt="xs">
        <List.Item>
          {__(
            "Each rule needs id, command, and intervalMinutes. Supported commands are publish, crawl, deploy, invalidate, retry-timeouts, and single URL export.",
            TEXT_DOMAIN,
          )}
        </List.Item>
        <List.Item>
          {__(
            "Scheduler only enqueues work into runtime/queue.json. It does not replace the external queue-runner cron/systemd timer/Task Scheduler tick, and WordPress WP-Cron is not the default executor.",
            TEXT_DOMAIN,
          )}
        </List.Item>
        <List.Item>
          {__(
            "The timezone field is currently stored for operations context. Interval execution is based on elapsed minute buckets checked on each queue-runner tick.",
            TEXT_DOMAIN,
          )}
        </List.Item>
        <List.Item>
          {__(
            "If an equivalent queued or running job already exists for the same command, crawl mode, deployment profile, and URL, that rule is skipped for the current interval bucket to avoid duplicate work.",
            TEXT_DOMAIN,
          )}
        </List.Item>
        <List.Item>
          {__(
            "For local Windows setups, Windows Task Scheduler can provide the same per-minute queue-runner tick that Linux cron provides on servers.",
            TEXT_DOMAIN,
          )}
        </List.Item>
      </List>

      <Title order={3} mt="md" id="job-crawl-mode">
        <span className="highlightable">{__("Crawl Mode", TEXT_DOMAIN)}</span>
      </Title>
      <Text>
        {__(
          "Crawl mode applies to manual and scheduled publish/crawl jobs. It controls whether the exporter renders everything again or tries to reuse unchanged pages.",
          TEXT_DOMAIN,
        )}
      </Text>
      <List size="sm" spacing="xs" withPadding mt="xs">
        <List.Item>
          <Code>full</Code>:{" "}
          {__("render all discovered pages again.", TEXT_DOMAIN)}
        </List.Item>
        <List.Item>
          <Code>incremental</Code>:{" "}
          {__(
            "keep prior output, skip unchanged pages when change tokens or sitemap metadata say they are unchanged, and fall back to normal rendering when they cannot prove that safely.",
            TEXT_DOMAIN,
          )}
        </List.Item>
        <List.Item>
          {__(
            "Incremental mode is available only for publish and crawl commands, and requires an active WPSuite subscription.",
            TEXT_DOMAIN,
          )}
        </List.Item>
        <List.Item>
          {__(
            "Normal manual full jobs remain usable without this subscription-gated mode.",
            TEXT_DOMAIN,
          )}
        </List.Item>
      </List>

      <Title order={3} mt="md" id="deploy-diff">
        <span className="highlightable">
          {__("Deploy Diff Summary", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "After SDK deploy phases, exporter writes a deploy-diff summary to runtime. Admin displays uploaded, skipped, failed and deleted counts for quick verification.",
          TEXT_DOMAIN,
        )}
      </Text>
      <Text mt="xs">
        {__(
          "Because deploy is SDK-driven, this summary reflects uploaded, skipped, failed, and deleted object counts from the actual deploy run.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="target-origin">
        <span className="highlightable">
          {__("Target Origin", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "For the base target this is the primary public origin written into exported files. Additional profiles can override it during deploy-time rewriting. Use '.' for relative output workflows.",
          TEXT_DOMAIN,
        )}
      </Text>
      <Text mt="xs">
        {__(
          'Sites without an active WPSuite subscription also receive a small generator meta tag in exported HTML pages: <meta name="generator" content="WPSuite.io Static Publisher" />. Subscriber exports skip this tag.',
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="url-rewrite-mode">
        <span className="highlightable">
          {__("URL Rewrite Mode", TEXT_DOMAIN)}
        </span>
      </Title>
      <List size="sm" spacing="xs" withPadding mt="xs">
        <List.Item>
          <Code>absolute</Code>: {__("writes full URLs", TEXT_DOMAIN)}
        </List.Item>
        <List.Item>
          <Code>root-relative</Code>:{" "}
          {__("writes /path style URLs", TEXT_DOMAIN)}
        </List.Item>
        <List.Item>
          <Code>relative</Code>: {__("writes file-relative URLs", TEXT_DOMAIN)}
        </List.Item>
      </List>

      <Title order={3} mt="md" id="ignore-https-errors">
        <span className="highlightable">
          {__("Ignore HTTPS Errors", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "Allows crawling origins with self-signed or invalid TLS certificates. Keep disabled on production-grade public origins.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="output-dir">
        <span className="highlightable">
          {__("Output Directory", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "Storage-relative folder where generated export files are written.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="log-dir">
        <span className="highlightable">
          {__("Log Directory", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "Storage-relative folder where crawl/deploy/invalidate JSONL and report files are written.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="log-level">
        <span className="highlightable">{__("Log Level", TEXT_DOMAIN)}</span>
      </Title>
      <Text>
        {__(
          "Controls exporter verbosity across crawl, deploy and invalidate phases.",
          TEXT_DOMAIN,
        )}
      </Text>
      <List size="sm" spacing="xs" withPadding mt="xs">
        <List.Item>
          <strong>error:</strong> {__("only failures", TEXT_DOMAIN)}
        </List.Item>
        <List.Item>
          <strong>warn:</strong> {__("failures and warnings", TEXT_DOMAIN)}
        </List.Item>
        <List.Item>
          <strong>info:</strong>{" "}
          {__("phase progress and key milestones (recommended)", TEXT_DOMAIN)}
        </List.Item>
        <List.Item>
          <strong>debug:</strong>{" "}
          {__("full detailed progress, individual operations", TEXT_DOMAIN)}
        </List.Item>
      </List>

      <Title order={3} mt="md" id="s3-sync-mode">
        <span className="highlightable">{__("S3 Sync Mode", TEXT_DOMAIN)}</span>
      </Title>
      <Text>
        {__(
          "Defines whether deploy keeps bucket content strictly in sync with local output, or only uploads changed/new files.",
          TEXT_DOMAIN,
        )}
      </Text>
      <List size="sm" spacing="xs" withPadding mt="xs">
        <List.Item>
          <Code>sdk-upload-delete</Code>:{" "}
          {__("upload files, then delete stale S3 keys", TEXT_DOMAIN)}
        </List.Item>
        <List.Item>
          <Code>sdk-upload-only</Code>:{" "}
          {__("upload files, never delete stale keys", TEXT_DOMAIN)}
        </List.Item>
      </List>

      <Title order={3} mt="md" id="concurrency">
        <span className="highlightable">{__("Concurrency", TEXT_DOMAIN)}</span>
      </Title>
      <Text>
        {__(
          "Number of parallel page workers during crawl. Higher values may speed up exports but increase source load.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="asset-download-concurrency">
        <span className="highlightable">
          {__("Asset Download Concurrency", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "Number of parallel asset download workers after page rendering completes. This can usually be higher than page concurrency because static asset fetches are much cheaper than full page renders.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="rewrite-concurrency">
        <span className="highlightable">
          {__("Rewrite Concurrency", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "Number of parallel workers used by the final full-output text rewrite pass after crawl/save and asset download phases complete.",
          TEXT_DOMAIN,
        )}
      </Text>
      <Text mt="xs">
        {__(
          "When omitted, it falls back to asset download concurrency. This setting does not control the inline rewrite done during each individual page save.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="max-pages">
        <span className="highlightable">{__("Max Pages", TEXT_DOMAIN)}</span>
      </Title>
      <Text>
        {__(
          "Optional hard cap for rendered pages in one crawl run. Use 0 for unlimited.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="seed-paths">
        <span className="highlightable">{__("Seed Paths", TEXT_DOMAIN)}</span>
      </Title>
      <Text>
        {__(
          "Initial URLs enqueued before and alongside sitemap discovery. Good place for robots.txt, llms.txt, and critical pages.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="sitemap-paths">
        <span className="highlightable">
          {__("Sitemap Paths", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__("Sitemap endpoints used for recursive URL discovery.", TEXT_DOMAIN)}
      </Text>

      <Title order={3} mt="md" id="allowed-asset-hosts">
        <span className="highlightable">
          {__("Allowed Asset Hosts", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "Additional hostnames allowed for asset downloads besides source/target host.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="asset-include-prefixes">
        <span className="highlightable">
          {__("Asset Include Prefixes", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "Only assets matching these path rules are downloaded. Plain entries use prefix matching; start a line with 're:' to use a JavaScript regular expression against the pathname.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="unhashed-asset-prefixes">
        <span className="highlightable">
          {__("Unhashed Asset Prefixes", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "Assets under these prefixes keep original filenames even when query-string cache busters exist.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="no-js-render-prefixes">
        <span className="highlightable">
          {__("No-JS Render Prefixes", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "Pages matching these path rules are rendered with JavaScript disabled. Plain entries use prefix matching; start a line with 're:' to use a JavaScript regular expression against the pathname.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="blocked-path-prefixes">
        <span className="highlightable">
          {__("Blocked Path Prefixes", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "Page URLs matching these path rules are excluded from crawl queue. Plain entries use prefix matching; start a line with 're:' to use a JavaScript regular expression against the pathname.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="blocked-query-fragments">
        <span className="highlightable">
          {__("Blocked Query Fragments", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "Any URL containing these query fragments is skipped during crawl discovery.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="s3-bucket">
        <span className="highlightable">{__("S3 Bucket", TEXT_DOMAIN)}</span>
      </Title>
      <Text>
        {__(
          "Destination bucket for base deploy operations. Additional profiles can override it for alternate targets.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="s3-prefix">
        <span className="highlightable">{__("S3 Prefix", TEXT_DOMAIN)}</span>
      </Title>
      <Text>
        {__(
          "Destination key prefix inside the bucket for the base target. Profiles may point to a different path.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="aws-region">
        <span className="highlightable">{__("AWS Region", TEXT_DOMAIN)}</span>
      </Title>
      <Text>
        {__(
          "AWS region used by SDK and CLI operations for the active deploy target. Profiles can override it if the alternate bucket lives elsewhere.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="cloudfront-distribution-id">
        <span className="highlightable">
          {__("CloudFront Distribution ID", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "Distribution ID used by invalidate command after deploy for the active target. Profiles can point invalidate to a different CDN.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="invalidation-paths">
        <span className="highlightable">
          {__("Invalidation Paths", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "CloudFront paths to invalidate after deploy. Base target and additional profiles can each use their own invalidation path list. Typical value is /*.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="job-command">
        <span className="highlightable">{__("Job Command", TEXT_DOMAIN)}</span>
      </Title>
      <Text>
        {__(
          "Select which command the queue runner should execute: crawl, deploy, invalidate, publish, retry-timeouts, or single URL export.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="job-deployment-profile">
        <span className="highlightable">
          {__("Job Deployment Profile", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "Used by publish, deploy, and invalidate jobs when you want to target a non-base deployment profile. Leave it empty to use the base target settings.",
          TEXT_DOMAIN,
        )}
      </Text>
      <Text mt="xs">
        {__(
          "This selector does not create a new crawl snapshot. It only chooses which deploy-time target overrides should be applied to the existing crawl artifact.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="job-url-path">
        <span className="highlightable">{__("Job URL Path", TEXT_DOMAIN)}</span>
      </Title>
      <Text>
        {__(
          "Used only for 'url' command. Provide one path to export a single page workflow.",
          TEXT_DOMAIN,
        )}
      </Text>

      <Title order={3} mt="md" id="aws-credentials-mode">
        <span className="highlightable">
          {__("AWS Credentials Mode", TEXT_DOMAIN)}
        </span>
      </Title>
      <Text>
        {__(
          "Shell mode uses ambient credentials (SSO/session/env/instance role). Temp mode allows one-time credentials per queued job.",
          TEXT_DOMAIN,
        )}
      </Text>
      <Text mt="xs">
        {__(
          "Use shell mode in production whenever possible. Temp credentials are intended for short-lived/manual runs.",
          TEXT_DOMAIN,
        )}
      </Text>
    </>
  ),
} as const;

interface DocSidebarProps {
  opened: boolean;
  close: () => void;
  page: keyof typeof pages;
  scrollToId: string;
}

export default function DocSidebar({
  opened,
  close,
  page,
  scrollToId,
}: DocSidebarProps) {
  const highlightTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(
    null,
  );
  const scrollHighlightTimeoutRef = useRef<ReturnType<
    typeof setTimeout
  > | null>(null);

  useEffect(() => {
    if (scrollHighlightTimeoutRef.current) {
      clearTimeout(scrollHighlightTimeoutRef.current);
      scrollHighlightTimeoutRef.current = null;
    }
    if (highlightTimeoutRef.current) {
      clearTimeout(highlightTimeoutRef.current);
      highlightTimeoutRef.current = null;
    }

    document
      .querySelectorAll(".highlighted-doc-item")
      .forEach((el) => el.classList.remove("highlighted-doc-item"));

    if (!opened || !scrollToId) {
      return;
    }

    scrollHighlightTimeoutRef.current = setTimeout(() => {
      const targetElement = document.getElementById(scrollToId);

      if (!targetElement) {
        scrollHighlightTimeoutRef.current = null;
        return;
      }

      targetElement.scrollIntoView({
        behavior: "smooth",
        block: "center",
      });

      const highlightableEl = targetElement.querySelector(".highlightable");

      if (highlightableEl) {
        highlightableEl.classList.add("highlighted-doc-item");

        highlightTimeoutRef.current = setTimeout(() => {
          highlightableEl.classList.remove("highlighted-doc-item");
          highlightTimeoutRef.current = null;
        }, 2000);
      }

      scrollHighlightTimeoutRef.current = null;
    }, 0);

    return () => {
      if (scrollHighlightTimeoutRef.current) {
        clearTimeout(scrollHighlightTimeoutRef.current);
        scrollHighlightTimeoutRef.current = null;
      }
      if (highlightTimeoutRef.current) {
        clearTimeout(highlightTimeoutRef.current);
        highlightTimeoutRef.current = null;
      }
      document
        .querySelectorAll(".highlighted-doc-item")
        .forEach((el) => el.classList.remove("highlighted-doc-item"));
    };
  }, [opened, scrollToId]);

  return (
    <Drawer
      opened={opened}
      onClose={close}
      title={__("Static Publisher Documentation", TEXT_DOMAIN)}
      position="right"
      size="xl"
      zIndex={999999}
    >
      <Stack gap="md">{pages[page]}</Stack>
    </Drawer>
  );
}

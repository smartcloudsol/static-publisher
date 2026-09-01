import {
  ActionIcon,
  Alert,
  Badge,
  Box,
  Button,
  Card,
  Code,
  Container,
  DEFAULT_THEME,
  Divider,
  Group,
  Loader,
  Modal,
  MultiSelect,
  NavLink,
  PasswordInput,
  ScrollArea,
  SegmentedControl,
  Select,
  SimpleGrid,
  Stack,
  Switch,
  Table,
  Text,
  TextInput,
  Textarea,
  Title,
} from "@mantine/core";
import { notifications } from "@mantine/notifications";
import {
  IconAlertCircle,
  IconCloudUpload,
  IconDownload,
  IconFileText,
  IconFolder,
  IconInfoCircle,
  IconKey,
  IconPlus,
  IconPlayerPlay,
  IconPlayerStop,
  IconSettings,
  IconTrash,
} from "@tabler/icons-react";
import { __ } from "@wordpress/i18n";
import { useEffect, useMemo, useRef, useState } from "react";
import { useDisclosure, useMediaQuery } from "@mantine/hooks";
import {
  getStoreSelect,
  reloadConfig,
  type Store,
} from "@smart-cloud/publisher-core";
import { getWpSuite } from "@smart-cloud/wpsuite-core";
import DocSidebar from "./DocSidebar";

const TEXT_DOMAIN = "smartcloud-static-publisher";
const IS_PREMIUM_BUILD = __WPSUITE_PREMIUM__;

type RewriteMode = "absolute" | "root-relative" | "relative";
type LogLevel = "error" | "warn" | "info" | "debug";
type S3SyncMode = "sdk-upload-delete" | "sdk-upload-only";
type CrawlMode = "full" | "incremental";
type JobCommand =
  | "publish"
  | "crawl"
  | "deploy"
  | "invalidate"
  | "retry-timeouts"
  | "url"
  | "content-sync";

type ProConfigPatch = {
  scheduler?: unknown;
  defaultDeploymentProfile?: string;
  deploymentProfiles?: unknown;
};

type ProAccessStatus = {
  isLinked: boolean;
  hasSubscription: boolean;
};

function getInitialProAccessStatus(): ProAccessStatus {
  const wpsuite = getWpSuite();
  const accountId = String(wpsuite?.siteSettings?.accountId ?? "").trim();
  const siteId = String(wpsuite?.siteSettings?.siteId ?? "").trim();
  const siteKey = String(wpsuite?.siteSettings?.siteKey ?? "").trim();

  return {
    isLinked: !!(accountId && siteId && siteKey),
    // Fail closed until the signed remote publisher configuration confirms
    // an exact Professional or Agency subscription type.
    hasSubscription: false,
  };
}

function getDefaultCrawlMode(hasIncrementalAccess: boolean): CrawlMode {
  return hasIncrementalAccess ? "incremental" : "full";
}

type ProConfigModule = {
  getProAccessStatus: () => Promise<ProAccessStatus>;
  loadRemoteProConfig: () => Promise<ProConfigPatch | null>;
  saveRemoteProConfig: (
    config: ProConfigPatch,
  ) => Promise<ProConfigPatch | null>;
};

type SchedulerRule = {
  id: string;
  enabled: boolean;
  command: JobCommand;
  intervalMinutes: number;
  crawlMode?: CrawlMode;
  deploymentProfile?: string;
  url?: string;
  postTypes?: string[];
  listingPaths?: string[];
  includeSubsites?: boolean;
  includePostTypeArchives?: boolean;
  includeTaxonomyArchives?: boolean;
  includeAuthorArchives?: boolean;
  includeDateArchives?: boolean;
  includePostsPage?: boolean;
  includeSitemapChain?: boolean;
};

type AwsTempCreds = {
  accessKeyId: string;
  secretAccessKey: string;
  sessionToken: string;
};

type QueueItem = {
  id: string;
  command: string;
  status?: string;
  createdAt?: string;
  crawlMode?: CrawlMode;
  deploymentProfile?: string;
  url?: string;
  usesTempAwsCreds?: boolean;
  ruleId?: string;
  coalesceKey?: string;
  attempt?: number;
  nextAttemptAt?: string;
  error?: string;
  fromSequence?: number;
  toSequence?: number;
  consumerId?: string;
};

type ContentSyncPostTypeOption = {
  slug: string;
  label: string;
  hasArchive: boolean;
  hierarchical: boolean;
  siteCount?: number;
};

type ContentSyncPostTypeResponse = {
  items?: ContentSyncPostTypeOption[];
  multisite?: boolean;
  networkActive?: boolean;
};

type ContentSyncRuleState = {
  ruleId?: string;
  consumerId?: string;
  coalesceKey?: string;
  committedSequence?: number;
  observedHeadSequence?: number;
  lag?: number;
  lastSuccessfulJobId?: string;
  lastSuccessfulAt?: string;
  trailingWorkDetected?: boolean;
  retryAttempt?: number;
  nextRetryAt?: string | null;
  lastError?: string | null;
  baselineStatus?: "ready" | "required";
  baselineReason?: string | null;
};

type ContentSyncCurrent = {
  jobId?: string;
  ruleId?: string;
  consumerId?: string;
  coalesceKey?: string;
  fromSequence?: number;
  toSequence?: number;
  phase?: string;
  impactHash?: string | null;
  startedAt?: string;
  updatedAt?: string;
};

type ContentSyncCheckpoint = {
  jobId?: string;
  phase?: string;
  completedItems?: number;
  totalItems?: number;
  attempt?: number;
  updatedAt?: string;
  details?: Record<string, unknown>;
};

type ContentSyncBaseline = {
  ruleId?: string;
  consumerId?: string;
  coalesceKey?: string;
  baselineId?: string;
  deploymentProfile?: string;
  committedSequence?: number;
  releaseJobId?: string;
  verifiedAt?: string;
};

type ContentSyncRuntimeState = {
  state?: {
    updatedAt?: string;
    rules?: Record<string, ContentSyncRuleState>;
  } | null;
  current?: ContentSyncCurrent | null;
  checkpoint?: ContentSyncCheckpoint | null;
  baseline?: {
    updatedAt?: string;
    entries?: Record<string, ContentSyncBaseline>;
  } | null;
};

type PublisherConfig = {
  sourceOrigin: string;
  targetOrigin: string;
  ignoreHttpsErrors: boolean;
  urlRewriteMode: RewriteMode;
  exporterDir: string;
  noJavaScriptRenderPathPrefixes: string[];
  seedPaths: string[];
  generated404RequestPath: string;
  sitemapPaths: string[];
  allowedAssetHosts: string[];
  assetPathPrefixes: string[];
  blockedPathPrefixes: string[];
  blockedSearchFragments: string[];
  extraReplacements: Record<string, string>;
  postCrawlCopyMap: Record<string, string>;
  scheduler: {
    enabled: boolean;
    timezone: string;
    rules: SchedulerRule[];
  };
  outputDir: string;
  logDir: string;
  concurrency: number;
  assetDownloadConcurrency: number;
  rewriteConcurrency: number;
  maxPages: number;
  verbose: boolean;
  logLevel: LogLevel;
  s3SyncMode: S3SyncMode;
  s3: {
    bucket: string;
    prefix: string;
    region: string;
    htmlCacheControl: string;
    assetCacheControl: string;
  };
  cloudFront: {
    distributionId: string;
    invalidationPaths: string[];
  };
  defaultDeploymentProfile?: string;
  deploymentProfiles?: Record<string, DeploymentProfile>;
};

type DeploymentProfile = {
  targetOrigin?: string;
  extraReplacements?: Record<string, string>;
  s3?: Partial<PublisherConfig["s3"]>;
  cloudFront?: {
    distributionId?: string;
    invalidationPaths?: string[];
  };
};

type PluginBoot = {
  restUrl: string;
  nonce: string;
  settings: PublisherConfig;
};

type StateResponse = {
  config: PublisherConfig;
  hasSavedConfiguration?: boolean;
  currentRun: {
    id: string;
    command: string;
    crawlMode?: CrawlMode;
    deploymentProfile?: string;
    status: string;
    createdAt: string;
    ruleId?: string;
    coalesceKey?: string;
    attempt?: number;
    nextAttemptAt?: string;
    error?: string;
  } | null;
  currentProgress: {
    checkedAt?: string;
    source?: string;
    message?: string;
    details?: Record<string, unknown>;
    startedAt?: string;
    currentStep?: string;
    stepStartedAt?: string;
    stepElapsedSec?: number;
    totalElapsedSec?: number;
    stepDurationsSec?: Record<string, number>;
  } | null;
  currentCrawlEvent: {
    checkedAt?: string;
    currentStep?: string;
    level?: string;
    message?: string;
    details?: Record<string, unknown>;
  } | null;
  lastRun: {
    id: string;
    command: string;
    crawlMode?: CrawlMode;
    deploymentProfile?: string;
    status: string;
    createdAt: string;
    endedAt?: string;
    stopRequestedAt?: string;
    stopRequestedByLogin?: string;
    stopMode?: string;
    stoppedStep?: string;
    ruleId?: string;
    coalesceKey?: string;
    attempt?: number;
    nextAttemptAt?: string;
    error?: string;
  } | null;
  schedulerState: {
    lastEnqueuedBucketByRuleId?: Record<string, number>;
    lastEvaluatedBucketByRuleId?: Record<string, number>;
    lastCreatedBucketByRuleId?: Record<string, number>;
    coalescedCountByRuleId?: Record<string, number>;
  } | null;
  queueRunnerHeartbeat?: Record<string, unknown> | null;
  contentSync?: ContentSyncRuntimeState | null;
  deployDiff: {
    generatedAt?: string;
    mode?: string;
    summary?: {
      totalFiles?: number;
      uploaded?: number;
      skipped?: number;
      failed?: number;
      deleted?: number;
      strategy?: string;
      note?: string;
    };
  } | null;
  lockActive: boolean;
  queueLength: number;
  queueItems: QueueItem[];
  availableLogs: string[];
  stopRequest: {
    requestedAt?: string;
    targetJobId?: string;
    targetJobCommand?: string;
    mode?: string;
  } | null;
};

type LogFileResponse = {
  file: string;
  contents: string;
  truncated?: boolean;
  fileSize?: number;
  returnedSize?: number;
  limit?: number;
};

type LoadStateOptions = {
  showGlobalLoader?: boolean;
  syncConfig?: boolean;
  refreshSelectedLog?: boolean;
};

type AdminTab =
  | "jobs"
  | "configuration"
  | "audit"
  | "scheduler"
  | "extraTargets";

type AuditArtifact = {
  id: string;
  label: string;
  role?: string;
  originalFileName?: string;
  storedFileName: string;
  downloadName?: string;
  downloadPath: string;
  size?: number;
  compressed?: boolean;
  compression?: string;
};

type AuditLogEntry = {
  id: string;
  occurredAt: string;
  eventType: string;
  status: string;
  actorSource?: string;
  actorUserId?: number | null;
  jobId?: string;
  command?: string;
  message?: string;
  details?: Record<string, unknown>;
  artifacts?: AuditArtifact[];
};

type AuditLogResponse = {
  items: AuditLogEntry[];
  total: number;
  page: number;
  pageSize: number;
  totalPages: number;
};

type WpSuiteWindow = Window & {
  WpSuite?: {
    plugins?: {
      staticPublisher?: PluginBoot;
    };
  };
};

const DEFAULT_CONFIG: PublisherConfig = {
  sourceOrigin: "",
  targetOrigin: ".",
  ignoreHttpsErrors: false,
  urlRewriteMode: "relative",
  exporterDir: "",
  noJavaScriptRenderPathPrefixes: [],
  seedPaths: ["/"],
  generated404RequestPath: "",
  sitemapPaths: ["/sitemap_index.xml", "/sitemap.xml"],
  allowedAssetHosts: [],
  assetPathPrefixes: ["/wp-content/", "/wp-includes/", "/assets/"],
  blockedPathPrefixes: ["/wp-admin", "/wp-login.php", "/wp-json"],
  blockedSearchFragments: [],
  extraReplacements: {},
  postCrawlCopyMap: {},
  scheduler: {
    enabled: false,
    timezone: "UTC",
    rules: [],
  },
  outputDir: "export",
  logDir: "logs",
  concurrency: 1,
  assetDownloadConcurrency: 1,
  rewriteConcurrency: 1,
  maxPages: 0,
  verbose: false,
  logLevel: "info",
  s3SyncMode: "sdk-upload-delete",
  s3: {
    bucket: "",
    prefix: "",
    region: "eu-central-1",
    htmlCacheControl: "public,max-age=60,s-maxage=60",
    assetCacheControl: "public,max-age=31536000,immutable",
  },
  cloudFront: {
    distributionId: "",
    invalidationPaths: ["/*"],
  },
  defaultDeploymentProfile: "",
  deploymentProfiles: {},
};

function parseLines(input: string): string[] {
  return input
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line.length > 0);
}

function linesText(input: string[] | undefined): string {
  return (input ?? []).join("\n");
}

type KeyValueRow = {
  id: string;
  key: string;
  value: string;
};

type DeploymentProfileDraft = {
  name: string;
  targetOrigin: string;
  s3: {
    bucket: string;
    prefix: string;
    region: string;
    htmlCacheControl: string;
    assetCacheControl: string;
  };
  cloudFront: {
    distributionId: string;
    invalidationPathsText: string;
  };
  extraReplacementRows: KeyValueRow[];
};

const NO_DEPLOYMENT_PROFILE_VALUE = "::none::";
const DEPLOYMENT_PROFILE_NAME_PATTERN = /^[A-Za-z0-9._-]+$/;

function createKeyValueRow(key = "", value = ""): KeyValueRow {
  return {
    id: `${Date.now()}-${Math.random().toString(16).slice(2)}`,
    key,
    value,
  };
}

function mapToRows(map: Record<string, string> | undefined): KeyValueRow[] {
  const entries = Object.entries(map || {});
  if (entries.length === 0) {
    return [createKeyValueRow()];
  }
  return entries.map(([key, value]) => createKeyValueRow(key, value));
}

function rowsToMap(rows: KeyValueRow[]): Record<string, string> {
  const out: Record<string, string> = {};
  for (const row of rows) {
    const key = row.key.trim();
    const value = row.value.trim();
    if (!key || !value) {
      continue;
    }
    out[key] = value;
  }
  return out;
}

function normalizeDeploymentProfileMap(
  input: unknown,
): Record<string, DeploymentProfile> {
  if (!input || typeof input !== "object") {
    return {};
  }

  const normalized: Record<string, DeploymentProfile> = {};

  for (const [rawName, rawProfile] of Object.entries(
    input as Record<string, unknown>,
  )) {
    const name = String(rawName ?? "").trim();
    if (!name || !rawProfile || typeof rawProfile !== "object") {
      continue;
    }

    const profile = rawProfile as Record<string, unknown>;
    const next: DeploymentProfile = {};

    const targetOrigin = String(profile.targetOrigin ?? "").trim();
    if (targetOrigin) {
      next.targetOrigin = targetOrigin;
    }

    if (
      profile.extraReplacements &&
      typeof profile.extraReplacements === "object"
    ) {
      next.extraReplacements = Object.fromEntries(
        Object.entries(profile.extraReplacements as Record<string, unknown>)
          .map(([key, value]) => [
            String(key ?? "").trim(),
            String(value ?? ""),
          ])
          .filter(([key]) => key.length > 0),
      );
    }

    if (profile.s3 && typeof profile.s3 === "object") {
      const s3 = profile.s3 as Record<string, unknown>;
      const nextS3: Partial<PublisherConfig["s3"]> = {};
      if (typeof s3.bucket === "string") {
        nextS3.bucket = s3.bucket.trim();
      }
      if (typeof s3.prefix === "string") {
        nextS3.prefix = s3.prefix.trim();
      }
      if (typeof s3.region === "string") {
        nextS3.region = s3.region.trim();
      }
      if (typeof s3.htmlCacheControl === "string") {
        nextS3.htmlCacheControl = s3.htmlCacheControl.trim();
      }
      if (typeof s3.assetCacheControl === "string") {
        nextS3.assetCacheControl = s3.assetCacheControl.trim();
      }
      if (Object.keys(nextS3).length > 0) {
        next.s3 = nextS3;
      }
    }

    if (profile.cloudFront && typeof profile.cloudFront === "object") {
      const cloudFront = profile.cloudFront as Record<string, unknown>;
      const nextCloudFront: DeploymentProfile["cloudFront"] = {};
      if (typeof cloudFront.distributionId === "string") {
        nextCloudFront.distributionId = cloudFront.distributionId.trim();
      }
      if (Array.isArray(cloudFront.invalidationPaths)) {
        nextCloudFront.invalidationPaths = cloudFront.invalidationPaths
          .map((entry) => String(entry ?? "").trim())
          .filter((entry) => entry.length > 0);
      }
      if (
        nextCloudFront.distributionId ||
        (nextCloudFront.invalidationPaths?.length ?? 0) > 0
      ) {
        next.cloudFront = nextCloudFront;
      }
    }

    normalized[name] = next;
  }

  return normalized;
}

function normalizePublisherConfig(config: PublisherConfig): PublisherConfig {
  const deploymentProfiles = normalizeDeploymentProfileMap(
    config.deploymentProfiles,
  );
  const scheduler = sanitizeSchedulerFromRemote(
    config.scheduler,
    DEFAULT_CONFIG.scheduler,
  );

  const concurrency = Math.max(1, Number(config.concurrency || 1));
  const assetDownloadConcurrency = Math.max(
    1,
    Number(config.assetDownloadConcurrency || concurrency),
  );
  const rewriteConcurrency = Math.max(
    1,
    Number(config.rewriteConcurrency || assetDownloadConcurrency),
  );

  return {
    ...config,
    exporterDir: String(config.exporterDir || "").trim(),
    concurrency,
    assetDownloadConcurrency,
    rewriteConcurrency,
    scheduler,
    defaultDeploymentProfile: "",
    deploymentProfiles,
  };
}

function createDeploymentProfileDraft(
  name = "",
  profile?: DeploymentProfile,
): DeploymentProfileDraft {
  return {
    name,
    targetOrigin: profile?.targetOrigin ?? "",
    s3: {
      bucket: profile?.s3?.bucket ?? "",
      prefix: profile?.s3?.prefix ?? "",
      region: profile?.s3?.region ?? "",
      htmlCacheControl: profile?.s3?.htmlCacheControl ?? "",
      assetCacheControl: profile?.s3?.assetCacheControl ?? "",
    },
    cloudFront: {
      distributionId: profile?.cloudFront?.distributionId ?? "",
      invalidationPathsText: linesText(profile?.cloudFront?.invalidationPaths),
    },
    extraReplacementRows: mapToRows(profile?.extraReplacements),
  };
}

function buildDeploymentProfileFromDraft(
  draft: DeploymentProfileDraft,
): DeploymentProfile {
  const profile: DeploymentProfile = {};
  const targetOrigin = draft.targetOrigin.trim();
  if (targetOrigin) {
    profile.targetOrigin = targetOrigin;
  }

  const extraReplacements = rowsToMap(draft.extraReplacementRows);
  if (Object.keys(extraReplacements).length > 0) {
    profile.extraReplacements = extraReplacements;
  }

  const s3: Partial<PublisherConfig["s3"]> = {};
  const bucket = draft.s3.bucket.trim();
  const prefix = draft.s3.prefix.trim();
  const region = draft.s3.region.trim();
  const htmlCacheControl = draft.s3.htmlCacheControl.trim();
  const assetCacheControl = draft.s3.assetCacheControl.trim();
  if (bucket) {
    s3.bucket = bucket;
  }
  if (prefix) {
    s3.prefix = prefix;
  }
  if (region) {
    s3.region = region;
  }
  if (htmlCacheControl) {
    s3.htmlCacheControl = htmlCacheControl;
  }
  if (assetCacheControl) {
    s3.assetCacheControl = assetCacheControl;
  }
  if (Object.keys(s3).length > 0) {
    profile.s3 = s3;
  }

  const cloudFront: NonNullable<DeploymentProfile["cloudFront"]> = {};
  const distributionId = draft.cloudFront.distributionId.trim();
  const invalidationPaths = parseLines(draft.cloudFront.invalidationPathsText);
  if (distributionId) {
    cloudFront.distributionId = distributionId;
  }
  if (invalidationPaths.length > 0) {
    cloudFront.invalidationPaths = invalidationPaths;
  }
  if (
    cloudFront.distributionId ||
    (cloudFront.invalidationPaths?.length ?? 0) > 0
  ) {
    profile.cloudFront = cloudFront;
  }

  return profile;
}

function deploymentProfileHasOverrides(profile: DeploymentProfile): boolean {
  return (
    String(profile.targetOrigin ?? "").trim() !== "" ||
    Object.keys(profile.extraReplacements ?? {}).length > 0 ||
    Object.values(profile.s3 ?? {}).some(
      (value) => String(value ?? "").trim() !== "",
    ) ||
    String(profile.cloudFront?.distributionId ?? "").trim() !== "" ||
    (profile.cloudFront?.invalidationPaths?.length ?? 0) > 0
  );
}

function summarizeDeploymentProfileS3(profile: DeploymentProfile): string {
  const bucket = String(profile.s3?.bucket ?? "").trim();
  const prefix = String(profile.s3?.prefix ?? "").trim();
  const region = String(profile.s3?.region ?? "").trim();

  if (bucket) {
    return prefix ? `${bucket}/${prefix.replace(/^\/+/, "")}` : bucket;
  }
  if (region) {
    return `region ${region}`;
  }
  return "-";
}

function summarizeDeploymentProfileCloudFront(
  profile: DeploymentProfile,
): string {
  const distributionId = String(
    profile.cloudFront?.distributionId ?? "",
  ).trim();
  const invalidationPathCount =
    profile.cloudFront?.invalidationPaths?.length ?? 0;

  if (distributionId && invalidationPathCount > 0) {
    return `${distributionId} (${invalidationPathCount} paths)`;
  }
  if (distributionId) {
    return distributionId;
  }
  if (invalidationPathCount > 0) {
    return `${invalidationPathCount} paths`;
  }
  return "-";
}

function formatJobCommandLabel(command: string, crawlMode?: CrawlMode): string {
  if (
    (command === "publish" || command === "crawl") &&
    crawlMode === "incremental"
  ) {
    return `${command} (incremental)`;
  }
  return command;
}

function defaultContentSyncScope(): Pick<
  SchedulerRule,
  | "postTypes"
  | "listingPaths"
  | "includeSubsites"
  | "includePostTypeArchives"
  | "includeTaxonomyArchives"
  | "includeAuthorArchives"
  | "includeDateArchives"
  | "includePostsPage"
  | "includeSitemapChain"
> {
  return {
    postTypes: [],
    listingPaths: [],
    includeSubsites: false,
    includePostTypeArchives: true,
    includeTaxonomyArchives: true,
    includeAuthorArchives: false,
    includeDateArchives: false,
    includePostsPage: true,
    includeSitemapChain: true,
  };
}

function normalizeContentSyncListingPaths(values: string[]): string[] {
  return [
    ...new Set(
      values
        .map((value) => value.trim())
        .filter(Boolean)
        .map((value) => {
          if (/^https?:\/\//i.test(value)) {
            throw new Error(
              "Content-sync listing routes must be site-relative paths.",
            );
          }
          const path = `/${value.replace(/^\/+|\/+$/g, "")}`;
          return path === "/" ? "/" : `${path}/`;
        }),
    ),
  ];
}

function summarizeSchedulerScope(rule: SchedulerRule): string {
  if (rule.command !== "content-sync") {
    return rule.url || "-";
  }

  const postTypes = rule.postTypes ?? [];
  const listingCount = rule.listingPaths?.length ?? 0;
  const archiveFamilies = [
    rule.includeSubsites === true ? "multisite network" : "current site",
    rule.includePostTypeArchives !== false ? "post type" : "",
    rule.includeTaxonomyArchives !== false ? "taxonomy" : "",
    rule.includeAuthorArchives === true ? "author" : "",
    rule.includeDateArchives === true ? "date" : "",
    rule.includePostsPage !== false ? "posts page" : "",
    rule.includeSitemapChain !== false ? "sitemap" : "",
  ].filter(Boolean);

  return `${
    postTypes.join(", ") || "no post types"
  }; ${listingCount} listing route${listingCount === 1 ? "" : "s"}; ${
    archiveFamilies.join(", ") || "no archive families"
  }`;
}

function formatAuditDetails(
  details: Record<string, unknown> | undefined,
): string {
  if (!details || Object.keys(details).length === 0) {
    return "";
  }
  return JSON.stringify(details, null, 2);
}

function formatSchedulerLastEnqueue(
  rule: SchedulerRule,
  bucket: unknown,
): string {
  const bucketNumber = Number(bucket);
  if (!Number.isFinite(bucketNumber) || bucketNumber < 0) {
    return "Never";
  }

  const intervalMs = Math.max(1, Number(rule.intervalMinutes || 1)) * 60 * 1000;
  const startedAt = new Date(bucketNumber * intervalMs);
  if (Number.isNaN(startedAt.getTime())) {
    return "Unknown";
  }

  return startedAt.toLocaleString();
}

function parseSchedulerRules(input: string): SchedulerRule[] {
  if (input.trim() === "") {
    return [];
  }

  const parsed = JSON.parse(input) as unknown;
  if (!Array.isArray(parsed)) {
    throw new Error("Scheduler rules must be a JSON array.");
  }

  return parsed
    .map((entry, index) => {
      const row = (entry ?? {}) as Partial<SchedulerRule>;
      const command = row.command;
      if (
        command !== "publish" &&
        command !== "crawl" &&
        command !== "deploy" &&
        command !== "invalidate" &&
        command !== "retry-timeouts" &&
        command !== "url" &&
        command !== "content-sync"
      ) {
        throw new Error(`Invalid scheduler command at row ${index + 1}.`);
      }

      const id = String(row.id ?? "").trim() || `${command}-${index + 1}`;
      const interval = Number.parseInt(String(row.intervalMinutes ?? 0), 10);
      if (!Number.isFinite(interval) || interval < 1) {
        throw new Error(`Invalid intervalMinutes at row ${index + 1}.`);
      }

      const url = String(row.url ?? "").trim();
      const deploymentProfile = String(row.deploymentProfile ?? "").trim();
      const postTypes = Array.isArray(row.postTypes)
        ? [
            ...new Set(
              row.postTypes
                .map((value) => String(value).trim())
                .filter(Boolean),
            ),
          ]
        : [];
      const listingPaths = Array.isArray(row.listingPaths)
        ? normalizeContentSyncListingPaths(
            row.listingPaths.map((value) => String(value)),
          )
        : [];
      const crawlMode =
        (command === "publish" || command === "crawl") &&
        row.crawlMode === "incremental"
          ? "incremental"
          : "full";
      if (command === "url" && !url) {
        throw new Error(`URL is required for scheduler row ${index + 1}.`);
      }
      if (command === "content-sync" && postTypes.length === 0) {
        throw new Error(
          `At least one post type is required for content-sync scheduler row ${
            index + 1
          }.`,
        );
      }

      return {
        id,
        enabled: row.enabled !== false,
        command,
        intervalMinutes: interval,
        ...(command === "publish" || command === "crawl" ? { crawlMode } : {}),
        ...((command === "publish" ||
          command === "deploy" ||
          command === "invalidate" ||
          command === "content-sync") &&
        deploymentProfile
          ? { deploymentProfile }
          : {}),
        ...(url ? { url } : {}),
        ...(command === "content-sync"
          ? {
              ...defaultContentSyncScope(),
              postTypes,
              listingPaths,
              includeSubsites: row.includeSubsites === true,
              includePostTypeArchives: row.includePostTypeArchives !== false,
              includeTaxonomyArchives: row.includeTaxonomyArchives !== false,
              includeAuthorArchives: row.includeAuthorArchives === true,
              includeDateArchives: row.includeDateArchives === true,
              includePostsPage: row.includePostsPage !== false,
              includeSitemapChain: row.includeSitemapChain !== false,
            }
          : {}),
      } as SchedulerRule;
    })
    .filter(Boolean);
}

function getSchedulerTimezoneOptions(): Array<{
  value: string;
  label: string;
}> {
  const fallback = [
    "UTC",
    "Europe/Budapest",
    "Europe/Vienna",
    "Europe/Berlin",
    "Europe/London",
    "America/New_York",
    "America/Chicago",
    "America/Los_Angeles",
    "Asia/Tokyo",
    "Asia/Dubai",
    "Australia/Sydney",
  ];

  const supportedValuesOf = (
    Intl as unknown as {
      supportedValuesOf?: (key: string) => string[];
    }
  ).supportedValuesOf;

  const values =
    typeof supportedValuesOf === "function"
      ? supportedValuesOf("timeZone")
      : fallback;

  return values.map((tz) => ({ value: tz, label: tz }));
}

function getBoot(): PluginBoot | null {
  const win = window as WpSuiteWindow;
  return win.WpSuite?.plugins?.staticPublisher ?? null;
}

function prettyPrintJson(input: string): string {
  try {
    return JSON.stringify(JSON.parse(input), null, 2);
  } catch {
    return input;
  }
}

function formatLogContents(fileName: string, raw: string): string {
  if (!raw.trim()) {
    return "";
  }

  if (fileName.endsWith(".jsonl")) {
    const lines = raw.split(/\r?\n/).filter((line) => line.trim().length > 0);
    return lines
      .map((line, index) => {
        const pretty = prettyPrintJson(line);
        return `#${index + 1}\n${pretty}`;
      })
      .join("\n\n");
  }

  if (fileName.endsWith(".json")) {
    return prettyPrintJson(raw);
  }

  return raw;
}

type JsonlLogRow = {
  line: number;
  time: string;
  level: string;
  message: string;
  extras: Record<string, unknown>;
};

type JsonlParseResult = {
  rows: JsonlLogRow[];
  truncatedCount: number;
};

function parseJsonlRows(raw: string, maxRows = 1200): JsonlParseResult {
  const lines = raw.split(/\r?\n/).filter((line) => line.trim().length > 0);
  const hasTerminalNewline = /\r?\n$/.test(raw);
  const truncatedCount = Math.max(0, lines.length - maxRows);
  const startIndex = Math.max(0, lines.length - maxRows);
  const displayLines = lines.slice(startIndex);
  const rows: JsonlLogRow[] = [];

  for (let i = 0; i < displayLines.length; i++) {
    const line = displayLines[i];
    const absoluteLine = startIndex + i + 1;
    try {
      const parsed = JSON.parse(line) as Record<string, unknown>;
      const { time, level, message, ...extras } = parsed;
      rows.push({
        line: absoluteLine,
        time: typeof time === "string" ? time : "",
        level: typeof level === "string" ? level : "info",
        message: typeof message === "string" ? message : "",
        extras,
      });
    } catch {
      if (startIndex + i === lines.length - 1 && !hasTerminalNewline) {
        break;
      }
      rows.push({
        line: absoluteLine,
        time: "",
        level: "parse-error",
        message: line,
        extras: {},
      });
    }
  }

  return { rows, truncatedCount };
}

function formatExtraValue(value: unknown): string {
  if (typeof value === "string") return value;
  if (typeof value === "number" || typeof value === "boolean") {
    return String(value);
  }
  if (value === null || value === undefined) return "";
  try {
    return JSON.stringify(value);
  } catch {
    return String(value);
  }
}

function formatByteSize(value: number | undefined): string {
  if (!value || value <= 0) return "0 B";
  if (value < 1024) return `${value} B`;
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;
  return `${(value / (1024 * 1024)).toFixed(1)} MB`;
}

function humanizeProgressKey(key: string): string {
  const labels: Record<string, string> = {
    file: "File",
    key: "S3 key",
    contentType: "Content type",
    mode: "Mode",
    elapsedSec: "Elapsed",
    pagesQueued: "Pages queued",
    assetsQueued: "Assets queued",
    sitemapsQueued: "Sitemaps queued",
    pagesSaved: "Pages saved",
    assetsSaved: "Assets saved",
    pagesDiscovered: "Pages discovered",
    assetsDiscovered: "Assets discovered",
    sitemapsDiscovered: "Sitemaps discovered",
    url: "URL",
    source: "Source",
  };

  if (labels[key]) {
    return labels[key];
  }

  return key
    .replace(/([a-z0-9])([A-Z])/g, "$1 $2")
    .replace(/[-_]+/g, " ")
    .replace(/^./, (char) => char.toUpperCase());
}

function formatProgressValue(key: string, value: unknown): string {
  if (key === "elapsedSec" && typeof value === "number") {
    return `${value}s`;
  }

  if (key === "mode" && typeof value === "string") {
    return value.replace(/[-_]+/g, " ");
  }

  return formatExtraValue(value);
}

function formatProgressDetails(
  details: Record<string, unknown> | undefined,
): string {
  if (!details) return "";

  const parts: string[] = [];
  const index = details.index;
  const totalFiles = details.totalFiles;

  if (typeof index === "number" && typeof totalFiles === "number") {
    parts.push(`File ${index} / ${totalFiles}`);
  }

  for (const [key, value] of Object.entries(details)) {
    if (key === "index" || key === "totalFiles") {
      continue;
    }
    if (value === undefined || value === null || value === "") {
      continue;
    }
    parts.push(
      `${humanizeProgressKey(key)}: ${formatProgressValue(key, value)}`,
    );
  }

  return parts.join(" | ");
}

function readProgressNumber(
  details: Record<string, unknown> | undefined,
  key: string,
): number | undefined {
  const value = details?.[key];
  return typeof value === "number" && Number.isFinite(value)
    ? value
    : undefined;
}

function readProgressString(
  details: Record<string, unknown> | undefined,
  key: string,
): string {
  const value = details?.[key];
  return typeof value === "string" ? value.trim() : "";
}

function formatDurationShort(seconds: number | undefined): string {
  if (typeof seconds !== "number" || !Number.isFinite(seconds) || seconds < 0) {
    return "";
  }
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = Math.floor(seconds % 60);
  if (h > 0) return `${h}h ${m}m ${s}s`;
  if (m > 0) return `${m}m ${s}s`;
  return `${s}s`;
}

function buildStableCurrentProgress(
  step: string,
  progress:
    | {
        message?: string;
        details?: Record<string, unknown>;
        totalElapsedSec?: number;
        stepElapsedSec?: number;
        stepDurationsSec?: Record<string, number>;
      }
    | null
    | undefined,
): { message: string; details: string; summary: string } {
  const details = progress?.details;
  const stepName = step.trim();

  if (stepName === "deploy") {
    const total = readProgressNumber(details, "totalFiles");
    const uploaded =
      readProgressNumber(details, "uploaded") ??
      readProgressNumber(details, "index");
    const failed = readProgressNumber(details, "failed") ?? 0;
    const elapsedSec = readProgressNumber(details, "elapsedSec");
    const mode = readProgressString(details, "mode").replace(/[-_]+/g, " ");
    const attempted =
      typeof uploaded === "number" ? uploaded + failed : undefined;
    const percent =
      typeof attempted === "number" && typeof total === "number" && total > 0
        ? Math.min(100, Math.max(0, (attempted / total) * 100))
        : undefined;
    const stepElapsed = formatDurationShort(progress?.stepElapsedSec);
    const totalElapsed = formatDurationShort(progress?.totalElapsedSec);

    const message =
      typeof attempted === "number" && typeof total === "number"
        ? `Deploy progress: ${attempted}/${total} (${percent?.toFixed(1)}%)`
        : typeof elapsedSec === "number"
        ? `Deploy in progress (${elapsedSec}s)`
        : "Deploy in progress";

    const detailParts: string[] = [];
    if (typeof uploaded === "number") {
      detailParts.push(`Uploaded: ${uploaded}`);
    }
    if (typeof failed === "number") {
      detailParts.push(`Failed: ${failed}`);
    }
    if (typeof total === "number" && typeof attempted === "number") {
      detailParts.push(`Remaining: ${Math.max(0, total - attempted)}`);
    }
    if (mode !== "") {
      detailParts.push(`Mode: ${mode}`);
    }
    if (stepElapsed !== "") {
      detailParts.push(`Deploy elapsed: ${stepElapsed}`);
    }
    if (totalElapsed !== "") {
      detailParts.push(`Total elapsed: ${totalElapsed}`);
    }

    const crawlDuration = formatDurationShort(
      progress?.stepDurationsSec?.crawl,
    );
    const deployDuration = formatDurationShort(
      progress?.stepDurationsSec?.deploy,
    );
    const invalidateDuration = formatDurationShort(
      progress?.stepDurationsSec?.invalidate,
    );

    const summary =
      typeof uploaded === "number" && typeof failed === "number"
        ? `Uploaded ${uploaded} file(s), failed ${failed}.${
            crawlDuration || deployDuration || invalidateDuration
              ? ` Step durations - crawl: ${crawlDuration || "-"}, deploy: ${
                  deployDuration || "-"
                }, invalidate: ${invalidateDuration || "-"}.`
              : ""
          }`
        : "";

    return {
      message,
      details: detailParts.join(" | "),
      summary,
    };
  }

  if (stepName === "crawl") {
    const phase = readProgressString(details, "phase");
    const pagesDiscovered =
      readProgressNumber(details, "pagesDiscovered") ??
      readProgressNumber(details, "pagesQueued");
    const assetsDiscovered =
      readProgressNumber(details, "assetsDiscovered") ??
      readProgressNumber(details, "assetsQueued");
    const sitemapsDiscovered =
      readProgressNumber(details, "sitemapsDiscovered") ??
      readProgressNumber(details, "sitemapsQueued");
    const pagesRendered = readProgressNumber(details, "pagesRendered");
    const donePages = readProgressNumber(details, "donePages");
    const doneAssets = readProgressNumber(details, "doneAssets");
    const doneSitemaps = readProgressNumber(details, "doneSitemaps");
    const pagesSaved = readProgressNumber(details, "pagesSaved");
    const processedPages = readProgressNumber(details, "donePages");
    const changedTextFiles = readProgressNumber(details, "changedTextFiles");

    const pagesStarted = donePages;
    const pagesTotal =
      pagesDiscovered ?? pagesRendered ?? pagesSaved ?? pagesStarted;
    const pagesProcessed = processedPages ?? pagesRendered;
    const pagesReusedOrSkipped =
      typeof processedPages === "number"
        ? Math.max(0, processedPages - (pagesRendered ?? 0))
        : undefined;
    const assetsDownloaded = doneAssets;
    const assetsTotal = assetsDiscovered ?? assetsDownloaded;
    const sitemapsTotal = sitemapsDiscovered ?? doneSitemaps;

    const stepElapsed = formatDurationShort(progress?.stepElapsedSec);
    const totalElapsed = formatDurationShort(progress?.totalElapsedSec);

    let message = "Crawl in progress";
    if (phase === "discovery") {
      message = `Discovery: ${pagesTotal ?? 0} pages, ${
        assetsTotal ?? 0
      } assets, ${sitemapsTotal ?? 0} sitemaps`;
    } else if (phase === "render-pages") {
      message = `Processed pages: ${pagesProcessed ?? 0}/${
        pagesTotal ?? pagesProcessed ?? 0
      }`;
    } else if (phase === "save-pages") {
      message = `Saving pages: ${pagesSaved ?? 0}/${
        pagesTotal ?? pagesSaved ?? 0
      }`;
    } else if (phase === "download-assets") {
      message = `Downloading assets: ${assetsDownloaded ?? 0}/${
        assetsTotal ?? assetsDownloaded ?? 0
      }`;
    } else if (
      typeof pagesProcessed === "number" ||
      typeof pagesTotal === "number"
    ) {
      message = `Processed pages: ${pagesProcessed ?? 0}/${
        pagesTotal ?? pagesProcessed ?? 0
      }`;
    }

    const detailParts: string[] = [];

    if (typeof pagesTotal === "number") {
      detailParts.push(`Discovered pages: ${pagesTotal ?? 0}`);
    }
    if (typeof pagesProcessed === "number" || typeof pagesTotal === "number") {
      detailParts.push(
        `Processed pages: ${pagesProcessed ?? 0}/${
          pagesTotal ?? pagesProcessed ?? 0
        }`,
      );
    }
    if (typeof pagesRendered === "number" || typeof pagesTotal === "number") {
      detailParts.push(`Rendered pages: ${pagesRendered ?? 0}`);
    }
    if (typeof pagesReusedOrSkipped === "number") {
      detailParts.push(`Reused/skipped pages: ${pagesReusedOrSkipped}`);
    }
    if (typeof pagesSaved === "number" || typeof pagesTotal === "number") {
      detailParts.push(
        `Saved pages: ${pagesSaved ?? 0}/${pagesTotal ?? pagesSaved ?? 0}`,
      );
    }
    if (typeof assetsTotal === "number") {
      detailParts.push(`Discovered assets: ${assetsTotal ?? 0}`);
    }
    if (
      typeof assetsDownloaded === "number" ||
      typeof assetsTotal === "number"
    ) {
      detailParts.push(
        `Downloaded assets: ${assetsDownloaded ?? 0}/${
          assetsTotal ?? assetsDownloaded ?? 0
        }`,
      );
    }
    if (typeof doneSitemaps === "number" || typeof sitemapsTotal === "number") {
      detailParts.push(
        `Sitemaps: ${doneSitemaps ?? sitemapsTotal ?? 0}/${
          sitemapsTotal ?? doneSitemaps ?? 0
        }`,
      );
    }
    if (typeof changedTextFiles === "number") {
      detailParts.push(`Changed text files: ${changedTextFiles}`);
    }
    if (stepElapsed !== "") {
      detailParts.push(`Crawl elapsed: ${stepElapsed}`);
    }
    if (totalElapsed !== "") {
      detailParts.push(`Total elapsed: ${totalElapsed}`);
    }

    const crawlDuration = formatDurationShort(
      progress?.stepDurationsSec?.crawl,
    );
    const deployDuration = formatDurationShort(
      progress?.stepDurationsSec?.deploy,
    );
    const invalidateDuration = formatDurationShort(
      progress?.stepDurationsSec?.invalidate,
    );

    const summary =
      typeof pagesTotal === "number" ||
      typeof assetsTotal === "number" ||
      typeof sitemapsTotal === "number"
        ? `${
            typeof pagesProcessed === "number" &&
            typeof pagesTotal === "number" &&
            pagesProcessed >= pagesTotal
              ? `Processed ${pagesProcessed} pages, rendered ${
                  pagesRendered ?? 0
                }, reused/skipped ${pagesReusedOrSkipped ?? 0}, processed ${
                  doneSitemaps ?? sitemapsTotal ?? 0
                } sitemaps, downloaded ${
                  assetsDownloaded ?? assetsTotal ?? 0
                } assets.`
              : `Processed ${pagesProcessed ?? 0} of ${
                  pagesTotal ?? 0
                } pages so far, ${sitemapsTotal ?? 0} sitemaps, ${
                  assetsTotal ?? 0
                } assets so far.`
          }${
            crawlDuration || deployDuration || invalidateDuration
              ? ` Step durations - crawl: ${crawlDuration || "-"}, deploy: ${
                  deployDuration || "-"
                }, invalidate: ${invalidateDuration || "-"}.`
              : ""
          }`
        : "";

    return {
      message,
      details: detailParts.join(" | "),
      summary,
    };
  }

  const fallbackMessage = (progress?.message || "").trim();
  return {
    message: fallbackMessage,
    details: formatProgressDetails(details),
    summary: "",
  };
}

function logLevelColor(level: string): string {
  switch (level) {
    case "error":
      return "red";
    case "warn":
      return "yellow";
    case "debug":
      return "grape";
    case "page":
      return "green";
    case "asset":
      return "teal";
    case "sitemap":
      return "blue";
    case "reject":
      return "orange";
    case "parse-error":
      return "red";
    default:
      return "gray";
  }
}

function sanitizeSchedulerFromRemote(
  input: unknown,
  fallback: PublisherConfig["scheduler"],
): PublisherConfig["scheduler"] {
  if (!input || typeof input !== "object") {
    return fallback;
  }

  const row = input as Partial<PublisherConfig["scheduler"]>;
  const timezone =
    typeof row.timezone === "string" && row.timezone.trim() !== ""
      ? row.timezone.trim()
      : fallback.timezone;

  let rules: SchedulerRule[];
  try {
    const serialized = JSON.stringify(
      Array.isArray(row.rules) ? row.rules : [],
    );
    rules = parseSchedulerRules(serialized);
  } catch {
    rules = fallback.rules;
  }

  return {
    enabled: row.enabled === true,
    timezone,
    rules,
  };
}

async function loadProConfigModule(): Promise<ProConfigModule> {
  if (IS_PREMIUM_BUILD) {
    return (await import("./paid-features/config")) as ProConfigModule;
  }
  return (await import("./free-features/config")) as ProConfigModule;
}

async function restRequest<T>(
  boot: PluginBoot,
  path: string,
  init?: RequestInit,
): Promise<T> {
  const response = await fetch(`${boot.restUrl}${path}`, {
    credentials: "same-origin",
    ...init,
    headers: {
      "Content-Type": "application/json",
      "X-WP-Nonce": boot.nonce,
      ...(init?.headers ?? {}),
    },
  });

  if (!response.ok) {
    const data = (await response.json().catch(() => null)) as {
      message?: string;
    } | null;
    throw new Error(data?.message || `HTTP ${response.status}`);
  }

  return (await response.json()) as T;
}

function getDownloadFileNameFromDisposition(value: string | null): string {
  if (!value) {
    return "";
  }

  const encodedMatch = value.match(/filename\*=UTF-8''([^;]+)/i);
  if (encodedMatch?.[1]) {
    try {
      return decodeURIComponent(encodedMatch[1]);
    } catch {
      return encodedMatch[1];
    }
  }

  const plainMatch = value.match(/filename="?([^";]+)"?/i);
  return plainMatch?.[1] ?? "";
}

async function restDownloadRequest(
  boot: PluginBoot,
  path: string,
  init?: RequestInit,
): Promise<{ blob: Blob; fileName: string }> {
  const response = await fetch(`${boot.restUrl}${path}`, {
    credentials: "same-origin",
    ...init,
    headers: {
      "X-WP-Nonce": boot.nonce,
      ...(init?.headers ?? {}),
    },
  });

  if (!response.ok) {
    const data = (await response.json().catch(() => null)) as {
      message?: string;
    } | null;
    throw new Error(data?.message || `HTTP ${response.status}`);
  }

  return {
    blob: await response.blob(),
    fileName: getDownloadFileNameFromDisposition(
      response.headers.get("Content-Disposition"),
    ),
  };
}

function inferHasSavedConfig(
  config: PublisherConfig,
  state: StateResponse | null,
): boolean {
  if (typeof state?.hasSavedConfiguration === "boolean") {
    return state.hasSavedConfiguration;
  }

  if ((state?.lastRun ?? null) !== null) {
    return true;
  }
  if ((state?.queueItems?.length ?? 0) > 0) {
    return true;
  }
  if ((state?.availableLogs?.length ?? 0) > 0) {
    return true;
  }

  const hasInfraTarget =
    config.s3.bucket.trim() !== "" ||
    config.s3.prefix.trim() !== "" ||
    config.cloudFront.distributionId.trim() !== "";

  const hasNonDefaultRewrite =
    config.targetOrigin.trim() !== "." || config.urlRewriteMode !== "relative";

  const hasCustomPaths =
    config.seedPaths.length > 1 ||
    config.generated404RequestPath.trim() !== "" ||
    config.sitemapPaths.join("\n") !== "/sitemap_index.xml\n/sitemap.xml";

  return hasInfraTarget || hasNonDefaultRewrite || hasCustomPaths;
}

type MainProps = {
  store: Store;
};

export default function Main({ store }: MainProps) {
  const boot = useMemo(() => getBoot(), []);
  const initialProAccess = useMemo(() => getInitialProAccessStatus(), []);
  const initialHasIncrementalAccess =
    IS_PREMIUM_BUILD &&
    initialProAccess.isLinked &&
    initialProAccess.hasSubscription;
  const isMobile = useMediaQuery(
    `(max-width: ${DEFAULT_THEME.breakpoints.sm})`,
  );
  const [config, setConfig] = useState<PublisherConfig>(
    boot?.settings ? normalizePublisherConfig(boot.settings) : DEFAULT_CONFIG,
  );
  const [state, setState] = useState<StateResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [queueing, setQueueing] = useState(false);
  const [refreshingState, setRefreshingState] = useState(false);
  const [command, setCommand] = useState<JobCommand>("publish");
  const [crawlMode, setCrawlMode] = useState<CrawlMode>(() =>
    getDefaultCrawlMode(initialHasIncrementalAccess),
  );
  const [deploymentProfile, setDeploymentProfile] = useState("");
  const [singleUrl, setSingleUrl] = useState("");
  const [selectedLog, setSelectedLog] = useState("");
  const [rawLogContent, setRawLogContent] = useState("");
  const [logMeta, setLogMeta] = useState<LogFileResponse | null>(null);
  const [logViewMode, setLogViewMode] = useState<"pretty" | "raw">("pretty");
  const selectedLogRef = useRef("");
  const [autoRefresh, setAutoRefresh] = useState(false);
  const [autoRefreshInterval, setAutoRefreshInterval] = useState(30);
  const [autoRefreshCycle, setAutoRefreshCycle] = useState(0);
  const [awsCredsOpen, { open: openAwsCreds, close: closeAwsCreds }] =
    useDisclosure(false);
  const [awsTempCreds, setAwsTempCreds] = useState<AwsTempCreds>({
    accessKeyId: "",
    secretAccessKey: "",
    sessionToken: "",
  });
  const [pendingDeleteJobId, setPendingDeleteJobId] = useState<string | null>(
    null,
  );
  const [downloadingJobId, setDownloadingJobId] = useState<string | null>(null);
  const [downloadingLog, setDownloadingLog] = useState(false);
  const [clearingLogs, setClearingLogs] = useState(false);
  const [stoppingCurrentJob, setStoppingCurrentJob] = useState(false);
  const [
    clearLogsConfirmOpen,
    { open: openClearLogsConfirm, close: closeClearLogsConfirm },
  ] = useDisclosure(false);
  const [
    stopCurrentRunConfirmOpen,
    { open: openStopCurrentRunConfirm, close: closeStopCurrentRunConfirm },
  ] = useDisclosure(false);

  const [dirBrowseOpen, { open: openDirBrowse, close: closeDirBrowse }] =
    useDisclosure(false);
  const [dirBrowseTarget, setDirBrowseTarget] = useState<
    "outputDir" | "logDir"
  >("outputDir");
  const [dirBrowsePath, setDirBrowsePath] = useState("");
  const [dirBrowseDirs, setDirBrowseDirs] = useState<string[]>([]);
  const [dirBrowseLoading, setDirBrowseLoading] = useState(false);

  const [seedPathsText, setSeedPathsText] = useState(
    linesText(config.seedPaths),
  );
  const [generated404RequestPath, setGenerated404RequestPath] = useState(
    config.generated404RequestPath ?? "",
  );
  const [noJsRenderPrefixesText, setNoJsRenderPrefixesText] = useState(
    linesText(config.noJavaScriptRenderPathPrefixes),
  );
  const [sitemapPathsText, setSitemapPathsText] = useState(
    linesText(config.sitemapPaths),
  );
  const [allowedHostsText, setAllowedHostsText] = useState(
    linesText(config.allowedAssetHosts),
  );
  const [assetPrefixesText, setAssetPrefixesText] = useState(
    linesText(config.assetPathPrefixes),
  );
  const [blockedPrefixesText, setBlockedPrefixesText] = useState(
    linesText(config.blockedPathPrefixes),
  );
  const [blockedFragmentsText, setBlockedFragmentsText] = useState(
    linesText(config.blockedSearchFragments),
  );
  const [invalidationText, setInvalidationText] = useState(
    linesText(config.cloudFront.invalidationPaths),
  );
  const [extraReplacementRows, setExtraReplacementRows] = useState<
    KeyValueRow[]
  >(mapToRows(config.extraReplacements));
  const [postCrawlCopyRows, setPostCrawlCopyRows] = useState<KeyValueRow[]>(
    mapToRows(config.postCrawlCopyMap),
  );
  const [proAccess, setProAccess] = useState<ProAccessStatus>(initialProAccess);
  const [mainSection, setMainSection] = useState<AdminTab>("configuration");
  const [hasSavedConfig, setHasSavedConfig] = useState(false);
  const initialTabResolvedRef = useRef(false);
  const [savingSchedulerConfig, setSavingSchedulerConfig] = useState(false);
  const [savingDeploymentTargetsConfig, setSavingDeploymentTargetsConfig] =
    useState(false);
  const [auditLoading, setAuditLoading] = useState(false);
  const [auditEntries, setAuditEntries] = useState<AuditLogEntry[]>([]);
  const [auditPage, setAuditPage] = useState(1);
  const [auditPageSize, setAuditPageSize] = useState(25);
  const [auditTotal, setAuditTotal] = useState(0);
  const [auditTotalPages, setAuditTotalPages] = useState(1);
  const [auditEventTypeFilter, setAuditEventTypeFilter] = useState("");
  const [auditStatusFilter, setAuditStatusFilter] = useState("");
  const [auditSearchFilter, setAuditSearchFilter] = useState("");
  const [downloadingAuditArtifactId, setDownloadingAuditArtifactId] = useState<
    string | null
  >(null);
  const [deploymentProfileModalOpen, setDeploymentProfileModalOpen] =
    useState(false);
  const [editingDeploymentProfileName, setEditingDeploymentProfileName] =
    useState<string | null>(null);
  const [deploymentProfileDraft, setDeploymentProfileDraft] =
    useState<DeploymentProfileDraft>(createDeploymentProfileDraft());
  const [schedulerRuleModalOpen, setSchedulerRuleModalOpen] = useState(false);
  const [contentSyncPostTypes, setContentSyncPostTypes] = useState<
    ContentSyncPostTypeOption[]
  >([]);
  const [contentSyncMultisite, setContentSyncMultisite] = useState(false);
  const [contentSyncNetworkActive, setContentSyncNetworkActive] =
    useState(false);
  const [contentSyncPostTypesLoading, setContentSyncPostTypesLoading] =
    useState(false);
  const [editingSchedulerRuleIndex, setEditingSchedulerRuleIndex] = useState<
    number | null
  >(null);
  const [schedulerRuleDraft, setSchedulerRuleDraft] = useState<SchedulerRule>({
    id: "",
    enabled: true,
    command: "publish",
    intervalMinutes: 60,
    crawlMode: "full",
    deploymentProfile: "",
    url: "",
    ...defaultContentSyncScope(),
  });
  const [scrollToId, setScrollToId] = useState<string>("");
  const [docOpened, { open: openDoc, close: closeDoc }] = useDisclosure(false);

  const openInfo = (targetScrollToId: string) => {
    setScrollToId(targetScrollToId);
    openDoc();
  };

  const refreshProAccessStatus = async () => {
    try {
      const module = await loadProConfigModule();
      const status = await module.getProAccessStatus();
      setProAccess(status);
    } catch {
      setProAccess(getInitialProAccessStatus());
    }
  };

  const openSchedulerRuleCreate = () => {
    setEditingSchedulerRuleIndex(null);
    setSchedulerRuleDraft({
      id: "",
      enabled: true,
      command: "publish",
      intervalMinutes: 60,
      crawlMode: getDefaultCrawlMode(hasIncrementalAccess),
      deploymentProfile: "",
      url: "",
      ...defaultContentSyncScope(),
    });
    setSchedulerRuleModalOpen(true);
  };

  const openDeploymentProfileCreate = () => {
    setEditingDeploymentProfileName(null);
    setDeploymentProfileDraft(createDeploymentProfileDraft());
    setDeploymentProfileModalOpen(true);
  };

  const openDeploymentProfileEdit = (name: string) => {
    const profile = normalizeDeploymentProfileMap(config.deploymentProfiles)[
      name
    ];
    if (!profile) {
      return;
    }

    setEditingDeploymentProfileName(name);
    setDeploymentProfileDraft(createDeploymentProfileDraft(name, profile));
    setDeploymentProfileModalOpen(true);
  };

  const closeDeploymentProfileModal = () => {
    setDeploymentProfileModalOpen(false);
    setEditingDeploymentProfileName(null);
  };

  const removeDeploymentProfile = (name: string) => {
    setConfig((prev) => {
      const currentProfiles = normalizeDeploymentProfileMap(
        prev.deploymentProfiles,
      );

      if (!currentProfiles[name]) {
        return prev;
      }

      const nextProfiles = { ...currentProfiles };
      delete nextProfiles[name];

      return {
        ...prev,
        defaultDeploymentProfile: "",
        deploymentProfiles: nextProfiles,
      };
    });

    setDeploymentProfile((prev) => (prev === name ? "" : prev));
    setSchedulerRuleDraft((prev) => ({
      ...prev,
      deploymentProfile:
        String(prev.deploymentProfile ?? "").trim() === name
          ? ""
          : prev.deploymentProfile,
    }));
  };

  const saveDeploymentProfileDraft = () => {
    if (!canManageExtraDeploymentProfiles) {
      notifications.show({
        title: __("Subscription required", TEXT_DOMAIN),
        message: __(
          "Additional deployment profiles require an active WPSuite subscription.",
          TEXT_DOMAIN,
        ),
        color: "yellow",
        icon: <IconAlertCircle size={16} />,
      });
      return;
    }

    const normalizedName = deploymentProfileDraft.name.trim();
    if (!normalizedName) {
      notifications.show({
        title: __("Deployment profile validation", TEXT_DOMAIN),
        message: __("Profile name is required.", TEXT_DOMAIN),
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
      return;
    }

    if (!DEPLOYMENT_PROFILE_NAME_PATTERN.test(normalizedName)) {
      notifications.show({
        title: __("Deployment profile validation", TEXT_DOMAIN),
        message: __(
          "Use only letters, numbers, dot, dash, and underscore in profile names.",
          TEXT_DOMAIN,
        ),
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
      return;
    }

    const nextProfile = buildDeploymentProfileFromDraft(deploymentProfileDraft);
    if (!deploymentProfileHasOverrides(nextProfile)) {
      notifications.show({
        title: __("Deployment profile validation", TEXT_DOMAIN),
        message: __(
          "Add at least one override before saving a deployment profile.",
          TEXT_DOMAIN,
        ),
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
      return;
    }

    const currentProfiles = normalizeDeploymentProfileMap(
      config.deploymentProfiles,
    );
    if (
      normalizedName !== editingDeploymentProfileName &&
      currentProfiles[normalizedName]
    ) {
      notifications.show({
        title: __("Deployment profile validation", TEXT_DOMAIN),
        message: __("Profile name must be unique.", TEXT_DOMAIN),
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
      return;
    }

    setConfig((prev) => {
      const nextProfiles = {
        ...normalizeDeploymentProfileMap(prev.deploymentProfiles),
      };

      if (
        editingDeploymentProfileName &&
        editingDeploymentProfileName !== normalizedName
      ) {
        delete nextProfiles[editingDeploymentProfileName];
      }

      nextProfiles[normalizedName] = nextProfile;

      return {
        ...prev,
        defaultDeploymentProfile: "",
        deploymentProfiles: nextProfiles,
      };
    });

    closeDeploymentProfileModal();
  };

  const openSchedulerRuleEdit = (index: number) => {
    const rule = config.scheduler.rules[index];
    if (!rule) {
      return;
    }
    setEditingSchedulerRuleIndex(index);
    setSchedulerRuleDraft({
      ...(rule.command === "content-sync" ? defaultContentSyncScope() : {}),
      ...rule,
      crawlMode: rule.crawlMode ?? getDefaultCrawlMode(hasIncrementalAccess),
      url: rule.url ?? "",
    });
    setSchedulerRuleModalOpen(true);
  };

  const removeSchedulerRule = (index: number) => {
    setConfig((prev) => ({
      ...prev,
      scheduler: {
        ...prev.scheduler,
        rules: prev.scheduler.rules.filter((_, rowIndex) => rowIndex !== index),
      },
    }));
  };

  const saveSchedulerRuleDraft = () => {
    const normalizedId = schedulerRuleDraft.id.trim();
    const normalizedUrl = (schedulerRuleDraft.url ?? "").trim();
    const normalizedDeploymentProfile = String(
      schedulerRuleDraft.deploymentProfile ?? "",
    ).trim();
    const commandSupportsCrawlMode =
      schedulerRuleDraft.command === "publish" ||
      schedulerRuleDraft.command === "crawl";
    const commandSupportsDeploymentProfile =
      schedulerRuleDraft.command === "publish" ||
      schedulerRuleDraft.command === "deploy" ||
      schedulerRuleDraft.command === "invalidate" ||
      schedulerRuleDraft.command === "content-sync";
    const isContentSync = schedulerRuleDraft.command === "content-sync";
    const normalizedCrawlMode =
      commandSupportsCrawlMode && schedulerRuleDraft.crawlMode === "incremental"
        ? "incremental"
        : "full";

    if (isContentSync && !hasIncrementalAccess) {
      notifications.show({
        title: __("Active subscription required", TEXT_DOMAIN),
        message: __(
          "Content sync requires a Premium build and an active Professional or Agency subscription.",
          TEXT_DOMAIN,
        ),
        color: "yellow",
        icon: <IconAlertCircle size={16} />,
      });
      return;
    }

    if (!normalizedId) {
      notifications.show({
        title: __("Scheduler rule validation", TEXT_DOMAIN),
        message: __("Rule ID is required.", TEXT_DOMAIN),
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
      return;
    }

    if (!Number.isFinite(schedulerRuleDraft.intervalMinutes)) {
      notifications.show({
        title: __("Scheduler rule validation", TEXT_DOMAIN),
        message: __("Interval must be a valid number.", TEXT_DOMAIN),
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
      return;
    }

    if (schedulerRuleDraft.intervalMinutes < 1) {
      notifications.show({
        title: __("Scheduler rule validation", TEXT_DOMAIN),
        message: __("Interval must be at least 1 minute.", TEXT_DOMAIN),
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
      return;
    }

    if (schedulerRuleDraft.command === "url" && !normalizedUrl) {
      notifications.show({
        title: __("Scheduler rule validation", TEXT_DOMAIN),
        message: __("URL is required when command is url.", TEXT_DOMAIN),
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
      return;
    }

    const normalizedPostTypes = [
      ...new Set(
        (schedulerRuleDraft.postTypes ?? [])
          .map((value) => value.trim().toLowerCase())
          .filter(Boolean),
      ),
    ];
    let normalizedListingPaths: string[];
    try {
      normalizedListingPaths = normalizeContentSyncListingPaths(
        schedulerRuleDraft.listingPaths ?? [],
      );
    } catch (error) {
      notifications.show({
        title: __("Scheduler rule validation", TEXT_DOMAIN),
        message: (error as Error).message,
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
      return;
    }

    if (isContentSync && normalizedPostTypes.length === 0) {
      notifications.show({
        title: __("Scheduler rule validation", TEXT_DOMAIN),
        message: __(
          "Select at least one public post type for content sync.",
          TEXT_DOMAIN,
        ),
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
      return;
    }

    if (
      commandSupportsDeploymentProfile &&
      normalizedDeploymentProfile &&
      !normalizeDeploymentProfileMap(config.deploymentProfiles)[
        normalizedDeploymentProfile
      ]
    ) {
      notifications.show({
        title: __("Scheduler rule validation", TEXT_DOMAIN),
        message: __("Selected deployment profile was not found.", TEXT_DOMAIN),
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
      return;
    }

    const nextRule: SchedulerRule = {
      ...schedulerRuleDraft,
      id: normalizedId,
      intervalMinutes: Math.max(
        1,
        Math.floor(schedulerRuleDraft.intervalMinutes),
      ),
      ...(commandSupportsCrawlMode ? { crawlMode: normalizedCrawlMode } : {}),
      ...(commandSupportsDeploymentProfile && normalizedDeploymentProfile
        ? { deploymentProfile: normalizedDeploymentProfile }
        : {}),
      ...(schedulerRuleDraft.command === "url" && normalizedUrl
        ? { url: normalizedUrl }
        : {}),
      ...(isContentSync
        ? {
            postTypes: normalizedPostTypes,
            listingPaths: normalizedListingPaths,
            includeSubsites: schedulerRuleDraft.includeSubsites === true,
            includePostTypeArchives:
              schedulerRuleDraft.includePostTypeArchives !== false,
            includeTaxonomyArchives:
              schedulerRuleDraft.includeTaxonomyArchives !== false,
            includeAuthorArchives:
              schedulerRuleDraft.includeAuthorArchives === true,
            includeDateArchives:
              schedulerRuleDraft.includeDateArchives === true,
            includePostsPage: schedulerRuleDraft.includePostsPage !== false,
            includeSitemapChain:
              schedulerRuleDraft.includeSitemapChain !== false,
          }
        : {}),
    };

    if (!commandSupportsCrawlMode) {
      delete nextRule.crawlMode;
    }
    if (!commandSupportsDeploymentProfile || !normalizedDeploymentProfile) {
      delete nextRule.deploymentProfile;
    }
    if (schedulerRuleDraft.command !== "url") {
      delete nextRule.url;
    }
    if (!isContentSync) {
      delete nextRule.postTypes;
      delete nextRule.listingPaths;
      delete nextRule.includeSubsites;
      delete nextRule.includePostTypeArchives;
      delete nextRule.includeTaxonomyArchives;
      delete nextRule.includeAuthorArchives;
      delete nextRule.includeDateArchives;
      delete nextRule.includePostsPage;
      delete nextRule.includeSitemapChain;
    }

    setConfig((prev) => {
      const currentRules = [...prev.scheduler.rules];
      const hasDuplicateId = currentRules.some(
        (rule, index) =>
          rule.id === nextRule.id &&
          (editingSchedulerRuleIndex === null ||
            index !== editingSchedulerRuleIndex),
      );

      if (hasDuplicateId) {
        notifications.show({
          title: __("Scheduler rule validation", TEXT_DOMAIN),
          message: __("Rule ID must be unique.", TEXT_DOMAIN),
          color: "red",
          icon: <IconAlertCircle size={16} />,
        });
        return prev;
      }

      if (editingSchedulerRuleIndex === null) {
        currentRules.push(nextRule);
      } else {
        currentRules[editingSchedulerRuleIndex] = nextRule;
      }

      return {
        ...prev,
        scheduler: {
          ...prev.scheduler,
          rules: currentRules,
        },
      };
    });

    setSchedulerRuleModalOpen(false);
    setEditingSchedulerRuleIndex(null);
  };

  const refreshSiteSettingsCache = async () => {
    const wpsuite = getWpSuite();
    const siteSettings = wpsuite?.siteSettings;
    const restUrl = String(wpsuite?.restUrl ?? "").trim();
    const nonce = String(wpsuite?.nonce ?? "").trim();

    if (!siteSettings || !restUrl || !nonce) {
      throw new Error(
        "Missing WPSuite site identity or REST bootstrap data required for cache refresh.",
      );
    }

    const response = await fetch(
      `${restUrl.replace(/\/$/, "")}/update-site-settings`,
      {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": nonce,
        },
        body: JSON.stringify({
          ...siteSettings,
          lastUpdate: Date.now(),
        }),
      },
    );

    if (!response.ok) {
      const message = await response.text();
      throw new Error(message || `HTTP ${response.status}`);
    }
  };

  const hydrateStoreProConfig = () => {
    if (!IS_PREMIUM_BUILD) {
      return;
    }
    const publisherConfig = getStoreSelect(store).getConfig();
    if (!publisherConfig) {
      return;
    }
    const scheduler = publisherConfig.scheduler
      ? sanitizeSchedulerFromRemote(publisherConfig.scheduler, config.scheduler)
      : config.scheduler;
    const deploymentProfiles = publisherConfig.deploymentProfiles
      ? normalizeDeploymentProfileMap(
          publisherConfig.deploymentProfiles as Record<string, unknown>,
        )
      : config.deploymentProfiles;

    setConfig((prev) => ({
      ...prev,
      scheduler,
      defaultDeploymentProfile: "",
      deploymentProfiles,
    }));
  };

  const infoLabel = (text: string, id: string) => (
    <Group gap="xs" align="center">
      <span>{text}</span>
      <ActionIcon
        variant="subtle"
        size="xs"
        onClick={() => openInfo(id)}
        aria-label={__("Open field help", TEXT_DOMAIN)}
      >
        <IconInfoCircle size={14} />
      </ActionIcon>
    </Group>
  );

  const queueJobCellStyle = {
    display: "flex",
    flexDirection: "column" as const,
    justifyContent: "space-between",
    height: "100%",
  };

  const queueJobActionsStyle = {
    display: "flex",
    flexDirection: "column" as const,
    justifyContent: "flex-end",
    height: "100%",
  };

  useEffect(() => {
    queueMicrotask(() => {
      setSeedPathsText(linesText(config.seedPaths));
      setGenerated404RequestPath(config.generated404RequestPath ?? "");
      setNoJsRenderPrefixesText(
        linesText(config.noJavaScriptRenderPathPrefixes),
      );
      setSitemapPathsText(linesText(config.sitemapPaths));
      setAllowedHostsText(linesText(config.allowedAssetHosts));
      setAssetPrefixesText(linesText(config.assetPathPrefixes));
      setBlockedPrefixesText(linesText(config.blockedPathPrefixes));
      setBlockedFragmentsText(linesText(config.blockedSearchFragments));
      setInvalidationText(linesText(config.cloudFront.invalidationPaths));
      setExtraReplacementRows(mapToRows(config.extraReplacements));
      setPostCrawlCopyRows(mapToRows(config.postCrawlCopyMap));
    });
  }, [config]);

  const loadLogFile = async (logFile: string) => {
    if (!boot || !logFile) {
      setRawLogContent("");
      setLogMeta(null);
      return;
    }
    try {
      const data = await restRequest<LogFileResponse>(
        boot,
        `/logs?file=${encodeURIComponent(logFile)}`,
        { method: "GET" },
      );
      setRawLogContent(data.contents ?? "");
      setLogMeta(data);
    } catch {
      setRawLogContent("");
      setLogMeta(null);
    }
  };

  const downloadLogFile = async (logFile: string) => {
    if (!boot || !logFile) return;
    setDownloadingLog(true);
    try {
      const response = await restRequest<LogFileResponse>(
        boot,
        `/logs?file=${encodeURIComponent(logFile)}&full=1`,
        { method: "GET" },
      );

      const blob = new Blob([response.contents ?? ""], {
        type: "text/plain;charset=utf-8",
      });
      const blobUrl = URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      anchor.href = blobUrl;
      anchor.download = response.file || logFile;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      URL.revokeObjectURL(blobUrl);
    } catch (error) {
      notifications.show({
        title: __("Download failed", TEXT_DOMAIN),
        message: (error as Error).message,
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
    } finally {
      setDownloadingLog(false);
    }
  };

  const downloadAuditArtifact = async (
    entry: AuditLogEntry,
    artifact: AuditArtifact,
  ) => {
    if (!boot || !artifact.downloadPath) {
      return;
    }

    const requestId = `${entry.id}:${artifact.id}`;
    setDownloadingAuditArtifactId(requestId);
    try {
      const response = await restDownloadRequest(boot, artifact.downloadPath, {
        method: "GET",
      });
      const blobUrl = URL.createObjectURL(response.blob);
      const anchor = document.createElement("a");
      anchor.href = blobUrl;
      anchor.download =
        response.fileName ||
        artifact.downloadName ||
        artifact.storedFileName ||
        artifact.label;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      URL.revokeObjectURL(blobUrl);
    } catch (error) {
      notifications.show({
        title: __("Download failed", TEXT_DOMAIN),
        message: (error as Error).message,
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
    } finally {
      setDownloadingAuditArtifactId((current) =>
        current === requestId ? null : current,
      );
    }
  };

  const logContent =
    logViewMode === "raw"
      ? rawLogContent
      : formatLogContents(
          selectedLog || state?.availableLogs?.[0] || "",
          rawLogContent,
        );
  const jsonlTable = useMemo(() => {
    const activeLog = selectedLog || state?.availableLogs?.[0] || "";
    if (logViewMode !== "pretty" || !activeLog.endsWith(".jsonl")) {
      return null;
    }
    return parseJsonlRows(rawLogContent);
  }, [logViewMode, rawLogContent, selectedLog, state?.availableLogs]);

  const loadAuditLog = async () => {
    if (!boot) {
      return;
    }

    setAuditLoading(true);
    try {
      const params = new URLSearchParams();
      params.set("page", String(auditPage));
      params.set("pageSize", String(auditPageSize));
      if (auditEventTypeFilter) {
        params.set("eventType", auditEventTypeFilter);
      }
      if (auditStatusFilter) {
        params.set("status", auditStatusFilter);
      }
      const trimmedSearch = auditSearchFilter.trim();
      if (trimmedSearch) {
        params.set("search", trimmedSearch);
      }

      const data = await restRequest<AuditLogResponse>(
        boot,
        `/audit?${params.toString()}`,
        { method: "GET" },
      );
      setAuditEntries(data.items ?? []);
      setAuditTotal(data.total ?? 0);
      setAuditTotalPages(data.totalPages ?? 1);
      setAuditPage(data.page ?? 1);
    } catch (error) {
      notifications.show({
        title: __("Failed to load audit log", TEXT_DOMAIN),
        message: (error as Error).message,
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
    } finally {
      setAuditLoading(false);
    }
  };

  const loadContentSyncPostTypes = async () => {
    if (!boot || !IS_PREMIUM_BUILD) {
      return;
    }

    setContentSyncPostTypesLoading(true);
    try {
      const data = await restRequest<ContentSyncPostTypeResponse>(
        boot,
        "/content-sync/post-types",
        { method: "GET" },
      );
      setContentSyncPostTypes(
        Array.isArray(data.items)
          ? data.items.filter(
              (item) =>
                typeof item?.slug === "string" &&
                item.slug.trim() !== "" &&
                typeof item?.label === "string",
            )
          : [],
      );
      setContentSyncMultisite(data.multisite === true);
      setContentSyncNetworkActive(data.networkActive === true);
    } catch {
      setContentSyncPostTypes([]);
      setContentSyncMultisite(false);
      setContentSyncNetworkActive(false);
    } finally {
      setContentSyncPostTypesLoading(false);
    }
  };

  const loadState = async (options?: LoadStateOptions) => {
    const showGlobalLoader = options?.showGlobalLoader ?? false;
    const syncConfig = options?.syncConfig ?? false;
    const refreshSelectedLog = options?.refreshSelectedLog ?? true;
    if (!boot) return;
    if (showGlobalLoader) {
      setLoading(true);
    } else {
      setRefreshingState(true);
    }
    try {
      const data = await restRequest<StateResponse>(boot, "/state");
      const normalizedConfig = normalizePublisherConfig(data.config);
      const normalizedState = {
        ...data,
        config: normalizedConfig,
      };
      setState(normalizedState);
      const inferredSavedConfig = inferHasSavedConfig(
        normalizedConfig,
        normalizedState,
      );
      setHasSavedConfig(inferredSavedConfig);

      if (syncConfig) {
        setConfig(normalizedConfig);
        if (!initialTabResolvedRef.current) {
          setMainSection(inferredSavedConfig ? "jobs" : "configuration");
          initialTabResolvedRef.current = true;
        }
      }

      const currentSelectedLog = selectedLogRef.current;
      const nextSelectedLog =
        currentSelectedLog &&
        normalizedState.availableLogs.includes(currentSelectedLog)
          ? currentSelectedLog
          : normalizedState.availableLogs[0] ?? "";

      if (nextSelectedLog !== currentSelectedLog) {
        selectedLogRef.current = nextSelectedLog;
        setSelectedLog(nextSelectedLog);
      }

      if (refreshSelectedLog) {
        if (nextSelectedLog) {
          await loadLogFile(nextSelectedLog);
        } else {
          setRawLogContent("");
          setLogMeta(null);
        }
      }
    } catch (error) {
      notifications.show({
        title: __("Failed to load state", TEXT_DOMAIN),
        message: (error as Error).message,
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
    } finally {
      if (showGlobalLoader) {
        setLoading(false);
      } else {
        setRefreshingState(false);
      }
    }
  };

  useEffect(() => {
    queueMicrotask(async () => {
      await loadState({ showGlobalLoader: true, syncConfig: true });
      await reloadConfig(store);
      hydrateStoreProConfig();
      await refreshProAccessStatus();
      await loadContentSyncPostTypes();
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    queueMicrotask(() => {
      void loadLogFile(selectedLog);
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedLog]);

  useEffect(() => {
    selectedLogRef.current = selectedLog;
  }, [selectedLog]);

  useEffect(() => {
    if (!autoRefresh) return;
    const id = setInterval(() => {
      void loadState({ syncConfig: false, refreshSelectedLog: true });
    }, autoRefreshInterval * 1000);
    return () => clearInterval(id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [autoRefresh, autoRefreshInterval, autoRefreshCycle]);

  const handleManualRefreshState = async () => {
    if (autoRefresh) {
      setAutoRefreshCycle((current) => current + 1);
    }

    await loadState({
      syncConfig: false,
      refreshSelectedLog: true,
    });
  };

  useEffect(() => {
    if (mainSection !== "audit") {
      return;
    }
    queueMicrotask(() => {
      void loadAuditLog();
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [
    mainSection,
    auditPage,
    auditPageSize,
    auditEventTypeFilter,
    auditStatusFilter,
    auditSearchFilter,
  ]);

  const fetchDirs = async (path: string) => {
    if (!boot) return;
    setDirBrowseLoading(true);
    try {
      const data = await restRequest<{ path: string; dirs: string[] }>(
        boot,
        `/dirs?path=${encodeURIComponent(path)}`,
      );
      setDirBrowsePath(data.path);
      setDirBrowseDirs(data.dirs);
    } catch {
      setDirBrowseDirs([]);
    } finally {
      setDirBrowseLoading(false);
    }
  };

  const openDirBrowseFor = (target: "outputDir" | "logDir") => {
    setDirBrowseTarget(target);
    setDirBrowsePath("");
    setDirBrowseDirs([]);
    openDirBrowse();
    void fetchDirs("");
  };

  const navigateDirBrowse = (subdir: string) => {
    const next = dirBrowsePath !== "" ? `${dirBrowsePath}/${subdir}` : subdir;
    void fetchDirs(next);
  };

  const navigateDirBrowseUp = () => {
    const parts = dirBrowsePath.split("/");
    parts.pop();
    void fetchDirs(parts.join("/"));
  };

  const confirmDirSelection = () => {
    const value = dirBrowsePath !== "" ? dirBrowsePath : "";
    if (dirBrowseTarget === "outputDir") {
      setConfig((prev) => ({ ...prev, outputDir: value || "export" }));
    } else {
      setConfig((prev) => ({ ...prev, logDir: value || "logs" }));
    }
    closeDirBrowse();
  };

  const currentStep = String(
    state?.currentProgress?.currentStep ||
      state?.currentCrawlEvent?.currentStep ||
      "",
  ).trim();
  const stableCurrentProgress = buildStableCurrentProgress(
    currentStep,
    state?.currentProgress,
  );
  const currentProgressMessage = stableCurrentProgress.message;
  const currentProgressDetails = stableCurrentProgress.details;
  const currentProgressSummary = stableCurrentProgress.summary;
  const currentCrawlEvent = state?.currentCrawlEvent ?? null;
  const currentCrawlEventMessage = String(
    currentCrawlEvent?.message ?? "",
  ).trim();
  const currentCrawlEventFile = readProgressString(
    currentCrawlEvent?.details,
    "file",
  );
  const currentRunStatus = (state?.currentRun?.status || "queued").trim();
  const isJobRunning = currentRunStatus === "running";
  const stopRequest = state?.stopRequest ?? null;
  const stopRequestedForCurrentRun =
    !!state?.currentRun?.id &&
    !!stopRequest?.targetJobId &&
    stopRequest.targetJobId === state.currentRun.id;
  const lastRunStopped = state?.lastRun?.status === "stopped";
  const lastStopMode = String(state?.lastRun?.stopMode || "").trim();
  const lastStoppedStep = String(state?.lastRun?.stoppedStep || "").trim();
  const lastStopRequestedAt = String(
    state?.lastRun?.stopRequestedAt || "",
  ).trim();
  const lastStopRequestedByLogin = String(
    state?.lastRun?.stopRequestedByLogin || "",
  ).trim();
  const currentRunBadgeColor =
    currentRunStatus === "running"
      ? "blue"
      : currentRunStatus === "failed"
      ? "red"
      : currentRunStatus === "success"
      ? "green"
      : "gray";
  const hasAnyLogs = (state?.availableLogs?.length ?? 0) > 0;
  const waitingForQueuedLogs =
    !hasAnyLogs &&
    ((state?.queueItems?.length ?? 0) > 0 || state?.currentRun !== null);
  const deployDiffSummary = state?.deployDiff?.summary;
  const schedulerRuleCount = config.scheduler.rules.length;
  const schedulerBuckets =
    state?.schedulerState?.lastEvaluatedBucketByRuleId ??
    state?.schedulerState?.lastEnqueuedBucketByRuleId ??
    {};
  const schedulerCoalescedCounts =
    state?.schedulerState?.coalescedCountByRuleId ?? {};
  const contentSyncRuntime = state?.contentSync ?? null;
  const contentSyncRuleStates = Object.values(
    contentSyncRuntime?.state?.rules ?? {},
  );
  const contentSyncBaselines = contentSyncRuntime?.baseline?.entries ?? {};
  const currentContentSync = contentSyncRuntime?.current ?? null;
  const contentSyncCheckpoint = contentSyncRuntime?.checkpoint ?? null;
  const queueRunnerStatus = String(
    state?.queueRunnerHeartbeat?.status ?? "",
  ).trim();
  const deploymentProfiles = useMemo(
    () => normalizeDeploymentProfileMap(config.deploymentProfiles),
    [config.deploymentProfiles],
  );
  const deploymentProfileNames = useMemo(
    () => Object.keys(deploymentProfiles).sort(),
    [deploymentProfiles],
  );
  const schedulerTimezoneOptions = useMemo(
    () => getSchedulerTimezoneOptions(),
    [],
  );
  const hasActiveSubscription = proAccess.isLinked && proAccess.hasSubscription;
  const hasIncrementalAccess = IS_PREMIUM_BUILD && hasActiveSubscription;
  const defaultCrawlMode = getDefaultCrawlMode(hasIncrementalAccess);
  const proSchedulerEditingEnabled = IS_PREMIUM_BUILD && hasActiveSubscription;
  const canManageExtraDeploymentProfiles =
    IS_PREMIUM_BUILD && hasActiveSubscription;
  const canQueueJobs = hasSavedConfig;
  const commandSupportsCrawlMode = command === "publish" || command === "crawl";
  const commandSupportsDeploymentProfile =
    command === "publish" || command === "deploy" || command === "invalidate";
  const selectedQueueDeploymentProfile = deploymentProfiles[deploymentProfile]
    ? deploymentProfile
    : "";
  const showIncrementalFallbackWarning =
    commandSupportsCrawlMode &&
    crawlMode === "incremental" &&
    !hasIncrementalAccess;
  const deploymentProfileOptions = useMemo(
    () => [
      {
        value: NO_DEPLOYMENT_PROFILE_VALUE,
        label: __("Use base target settings", TEXT_DOMAIN),
      },
      ...deploymentProfileNames.map((name) => ({ value: name, label: name })),
    ],
    [deploymentProfileNames],
  );
  const schedulerDeploymentProfileOptions = useMemo(() => {
    const options = [...deploymentProfileOptions];
    const activeProfile = String(
      schedulerRuleDraft.deploymentProfile ?? "",
    ).trim();

    if (
      activeProfile &&
      !options.some((option) => option.value === activeProfile)
    ) {
      options.push({
        value: activeProfile,
        label: `${activeProfile} (missing from config)`,
      });
    }

    return options;
  }, [deploymentProfileOptions, schedulerRuleDraft.deploymentProfile]);
  const contentSyncPostTypeSelectOptions = useMemo(() => {
    const options = contentSyncPostTypes.map((item) => ({
      value: item.slug,
      label: item.hasArchive
        ? `${item.label} (${item.slug}, archive)`
        : `${item.label} (${item.slug})`,
    }));
    for (const slug of schedulerRuleDraft.postTypes ?? []) {
      if (!options.some((option) => option.value === slug)) {
        options.push({ value: slug, label: `${slug} (unavailable)` });
      }
    }
    return options;
  }, [contentSyncPostTypes, schedulerRuleDraft.postTypes]);
  const navPrimary: Array<{
    value: AdminTab;
    label: string;
    icon: JSX.Element;
  }> = [
    {
      value: "jobs",
      label: __("Jobs", TEXT_DOMAIN),
      icon: <IconPlayerPlay size={16} />,
    },
    {
      value: "configuration",
      label: __("Configuration", TEXT_DOMAIN),
      icon: <IconSettings size={16} />,
    },
    {
      value: "audit",
      label: __("Audit Logs", TEXT_DOMAIN),
      icon: <IconFileText size={16} />,
    },
  ];
  const navPro: Array<{
    value: AdminTab;
    label: string;
    icon: JSX.Element;
    disabled?: boolean;
  }> = [
    {
      value: "scheduler",
      label: __("Scheduler Settings", TEXT_DOMAIN),
      icon: <IconKey size={16} />,
    },
    {
      value: "extraTargets",
      label: __("Extra Deployment Targets", TEXT_DOMAIN),
      icon: <IconCloudUpload size={16} />,
    },
  ];

  if (!boot) {
    return (
      <Container size="md" mt="lg">
        <Alert color="red" icon={<IconAlertCircle size={18} />}>
          {__(
            "Static Publisher bootstrap data is not available. Check plugin script enqueue.",
            TEXT_DOMAIN,
          )}
        </Alert>
      </Container>
    );
  }

  const applyListFields = (): PublisherConfig => {
    return {
      ...config,
      noJavaScriptRenderPathPrefixes: parseLines(noJsRenderPrefixesText),
      seedPaths: parseLines(seedPathsText),
      generated404RequestPath: generated404RequestPath.trim(),
      sitemapPaths: parseLines(sitemapPathsText),
      allowedAssetHosts: parseLines(allowedHostsText),
      assetPathPrefixes: parseLines(assetPrefixesText),
      blockedPathPrefixes: parseLines(blockedPrefixesText),
      blockedSearchFragments: parseLines(blockedFragmentsText),
      extraReplacements: rowsToMap(extraReplacementRows),
      postCrawlCopyMap: rowsToMap(postCrawlCopyRows),
      scheduler: {
        ...config.scheduler,
        rules: config.scheduler.rules,
      },
      cloudFront: {
        ...config.cloudFront,
        invalidationPaths: parseLines(invalidationText),
      },
    };
  };

  const saveConfig = async () => {
    setSaving(true);
    try {
      // PRO settings have their own remote persistence path. The normal
      // WordPress settings endpoint intentionally receives no PRO values, so
      // retain the currently hydrated remote state when replacing local state
      // with that endpoint's response.
      const preservedProConfig = {
        scheduler: config.scheduler,
        defaultDeploymentProfile: config.defaultDeploymentProfile,
        deploymentProfiles: config.deploymentProfiles,
      };
      const merged = {
        ...applyListFields(),
        defaultDeploymentProfile: "",
        deploymentProfiles: {},
        scheduler: {
          enabled: false,
          timezone: "UTC",
          rules: [],
        },
      };

      const response = await restRequest<{
        config: PublisherConfig;
        message: string;
      }>(boot, "/config", {
        method: "POST",
        body: JSON.stringify(merged),
      });
      const normalizedConfig = normalizePublisherConfig({
        ...response.config,
        ...preservedProConfig,
      });
      setConfig(normalizedConfig);
      setHasSavedConfig(true);
      notifications.show({
        title: __("Configuration saved", TEXT_DOMAIN),
        message: response.message,
        color: "green",
      });
      await loadState({ syncConfig: false, refreshSelectedLog: true });
    } catch (error) {
      notifications.show({
        title: __("Save failed", TEXT_DOMAIN),
        message: (error as Error).message,
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
    } finally {
      setSaving(false);
    }
  };

  const syncRemoteProConfigState = async () => {
    await refreshSiteSettingsCache();
    await loadState({ syncConfig: true, refreshSelectedLog: false });
    await reloadConfig(store);
    hydrateStoreProConfig();
    await refreshProAccessStatus();
  };

  const saveSchedulerConfig = async () => {
    if (
      !IS_PREMIUM_BUILD ||
      !proAccess.isLinked ||
      !proAccess.hasSubscription
    ) {
      notifications.show({
        title: __("Active subscription required", TEXT_DOMAIN),
        message: __(
          "Connect this site to wpsuite.io with an active Professional or Agency subscription before saving PRO scheduler settings.",
          TEXT_DOMAIN,
        ),
        color: "yellow",
        icon: <IconAlertCircle size={16} />,
      });
      return;
    }

    setSavingSchedulerConfig(true);
    try {
      const nextScheduler = applyListFields().scheduler;
      const module = await loadProConfigModule();
      await module.saveRemoteProConfig({
        scheduler: nextScheduler,
      });

      await syncRemoteProConfigState();

      notifications.show({
        title: __("PRO settings saved", TEXT_DOMAIN),
        message: __(
          "Scheduler settings were saved to the linked wpsuite.io site configuration.",
          TEXT_DOMAIN,
        ),
        color: "green",
      });
    } catch (error) {
      notifications.show({
        title: __("PRO save failed", TEXT_DOMAIN),
        message: (error as Error).message,
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
    } finally {
      setSavingSchedulerConfig(false);
    }
  };

  const saveDeploymentTargetsConfig = async () => {
    if (!IS_PREMIUM_BUILD || !proAccess.isLinked) {
      notifications.show({
        title: __("WPSuite connection required", TEXT_DOMAIN),
        message: __(
          "Connect this site to wpsuite.io before saving extra deployment targets.",
          TEXT_DOMAIN,
        ),
        color: "yellow",
        icon: <IconAlertCircle size={16} />,
      });
      return;
    }

    setSavingDeploymentTargetsConfig(true);
    try {
      const nextDeploymentProfiles = normalizeDeploymentProfileMap(
        applyListFields().deploymentProfiles,
      );
      const module = await loadProConfigModule();
      await module.saveRemoteProConfig({
        defaultDeploymentProfile: "",
        deploymentProfiles: nextDeploymentProfiles,
      });

      await syncRemoteProConfigState();

      notifications.show({
        title: __("PRO settings saved", TEXT_DOMAIN),
        message: __(
          "Extra deployment targets were saved to the linked wpsuite.io site configuration.",
          TEXT_DOMAIN,
        ),
        color: "green",
      });
    } catch (error) {
      notifications.show({
        title: __("PRO save failed", TEXT_DOMAIN),
        message: (error as Error).message,
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
    } finally {
      setSavingDeploymentTargetsConfig(false);
    }
  };

  const queueJob = async () => {
    if (!hasSavedConfig) {
      notifications.show({
        title: __("Configuration required", TEXT_DOMAIN),
        message: __(
          "Save Configuration first before queueing jobs.",
          TEXT_DOMAIN,
        ),
        color: "yellow",
        icon: <IconAlertCircle size={16} />,
      });
      return;
    }

    setQueueing(true);
    try {
      const supportsAwsCreds =
        command === "publish" ||
        command === "deploy" ||
        command === "invalidate";
      const hasAnyAwsCred =
        awsTempCreds.accessKeyId.trim() !== "" ||
        awsTempCreds.secretAccessKey.trim() !== "" ||
        awsTempCreds.sessionToken.trim() !== "";

      await restRequest<{ message: string }>(boot, "/jobs", {
        method: "POST",
        body: JSON.stringify({
          command,
          crawlMode: commandSupportsCrawlMode ? crawlMode : undefined,
          deploymentProfile:
            commandSupportsDeploymentProfile && selectedQueueDeploymentProfile
              ? selectedQueueDeploymentProfile
              : undefined,
          url: command === "url" ? singleUrl : "",
          awsTempCreds:
            supportsAwsCreds && hasAnyAwsCred
              ? {
                  accessKeyId: awsTempCreds.accessKeyId.trim(),
                  secretAccessKey: awsTempCreds.secretAccessKey.trim(),
                  sessionToken: awsTempCreds.sessionToken.trim(),
                }
              : undefined,
        }),
      });
      notifications.show({
        title: __("Job queued", TEXT_DOMAIN),
        message: __(
          "Export runner can pick the command from runtime/queue.json.",
          TEXT_DOMAIN,
        ),
        color: "green",
      });
      await loadState({ syncConfig: false, refreshSelectedLog: true });
    } catch (error) {
      notifications.show({
        title: __("Queue failed", TEXT_DOMAIN),
        message: (error as Error).message,
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
    } finally {
      setQueueing(false);
    }
  };

  const deleteQueuedJob = async (jobId: string) => {
    try {
      const response = await restRequest<{ message: string }>(
        boot,
        `/jobs/${encodeURIComponent(jobId)}`,
        { method: "DELETE" },
      );
      setPendingDeleteJobId(null);
      notifications.show({
        title: __("Job deleted", TEXT_DOMAIN),
        message: response.message,
        color: "green",
      });
      await loadState({ syncConfig: false, refreshSelectedLog: false });
    } catch (error) {
      notifications.show({
        title: __("Delete failed", TEXT_DOMAIN),
        message: (error as Error).message,
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
    }
  };

  const downloadQueuedJobConfig = async (jobId: string) => {
    setDownloadingJobId(jobId);
    try {
      const response = await restRequest<{
        fileName: string;
        content: unknown;
      }>(boot, `/jobs/${encodeURIComponent(jobId)}/download-config`, {
        method: "GET",
      });

      const payload = JSON.stringify(response.content, null, 2);
      const blob = new Blob([payload], { type: "application/json" });
      const blobUrl = URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      anchor.href = blobUrl;
      anchor.download =
        response.fileName || `static-publisher-job-${jobId}.json`;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      URL.revokeObjectURL(blobUrl);

      notifications.show({
        title: __("Config downloaded", TEXT_DOMAIN),
        message: __(
          "Use this JSON for local shell execution when server prerequisites are missing.",
          TEXT_DOMAIN,
        ),
        color: "green",
      });
    } catch (error) {
      notifications.show({
        title: __("Download failed", TEXT_DOMAIN),
        message: (error as Error).message,
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
    } finally {
      setDownloadingJobId(null);
    }
  };

  const clearAllLogs = async () => {
    if (!boot) return;
    setClearingLogs(true);
    try {
      const response = await restRequest<{ message: string; deleted: number }>(
        boot,
        "/logs",
        { method: "DELETE" },
      );
      setRawLogContent("");
      setLogMeta(null);
      selectedLogRef.current = "";
      setSelectedLog("");
      notifications.show({
        title: __("Logs cleared", TEXT_DOMAIN),
        message: response.message,
        color: "green",
      });
      await loadState({ syncConfig: false, refreshSelectedLog: false });
    } catch (error) {
      notifications.show({
        title: __("Clear logs failed", TEXT_DOMAIN),
        message: (error as Error).message,
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
    } finally {
      setClearingLogs(false);
    }
  };

  const requestStopCurrentJob = async () => {
    if (!boot) return;
    setStoppingCurrentJob(true);
    try {
      const response = await restRequest<{
        message: string;
        alreadyRequested?: boolean;
      }>(boot, "/jobs/current/stop", { method: "POST" });
      notifications.show({
        title: response.alreadyRequested
          ? __("Stop already requested", TEXT_DOMAIN)
          : __("Stop requested", TEXT_DOMAIN),
        message: response.message,
        color: "yellow",
        icon: <IconAlertCircle size={16} />,
      });
      await loadState({ syncConfig: false, refreshSelectedLog: false });
    } catch (error) {
      notifications.show({
        title: __("Stop request failed", TEXT_DOMAIN),
        message: (error as Error).message,
        color: "red",
        icon: <IconAlertCircle size={16} />,
      });
    } finally {
      setStoppingCurrentJob(false);
    }
  };

  return (
    <Box
      className="sp-admin-shell"
      style={{
        minHeight: "calc(100vh - 80px)",
        padding: "1rem",
      }}
    >
      <style>{`.highlighted-doc-item { background-color: rgba(255, 255, 0, 0.3); }
.logs-controls { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
.logs-controls .logs-file-select { flex: 1 1 160px; min-width: 140px; }
.logs-controls .logs-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.sp-admin-shell .sp-main-content .mantine-Card-root { border: 0 !important; box-shadow: none !important; }
@media (max-width: 767px) {
  .logs-controls > *, .logs-controls .logs-actions { flex: 1 1 100%; width: 100%; }
  .logs-controls .logs-actions { justify-content: stretch; }
  .logs-controls .logs-actions > * { flex: 1 1 100%; }
}`}</style>
      <DocSidebar
        opened={docOpened}
        close={closeDoc}
        page="general"
        scrollToId={scrollToId}
      />
      <Box py="lg" maw={1280} ml={0} mr="auto">
        <Card p="sm" withBorder mb="md">
          <Group
            align="flex-start"
            style={{
              flexDirection: "column",
              width: "100%",
            }}
          >
            <Title
              order={1}
              style={{
                display: "flex",
                alignItems: "center",
                gap: "8px",
                color: "#218BE6",
              }}
            >
              {__("SmartCloud Static Publisher", TEXT_DOMAIN)}
            </Title>
            <Text c="dimmed" size="sm">
              {__(
                "Sitemap-based export control panel for static publishing workflows.",
                TEXT_DOMAIN,
              )}
            </Text>
            <Text>
              {__(
                "This interface allows you to configure how Static Publisher crawls, deploys, and validates your static site output.",
                TEXT_DOMAIN,
              )}
            </Text>
            <Text>
              {__(
                "You can set up runtime settings, queue export jobs, review execution logs, and manage scheduler-driven workflows from one place.",
                TEXT_DOMAIN,
              )}
            </Text>
            <Group gap="sm">
              <Button
                variant="default"
                size="xs"
                leftSection={<IconInfoCircle size={14} />}
                onClick={() => openInfo("shell-runtime-overview")}
              >
                {__("Documentation", TEXT_DOMAIN)}
              </Button>
              <Badge color={state?.lockActive ? "red" : "teal"} variant="light">
                {state?.lockActive
                  ? __("Export lock active", TEXT_DOMAIN)
                  : __("Ready", TEXT_DOMAIN)}
              </Badge>
            </Group>
          </Group>
        </Card>

        {loading ? (
          <Group justify="center" mt="xl">
            <Loader />
          </Group>
        ) : (
          <Stack gap="md">
            <Group align="flex-start" wrap="nowrap" gap="xl">
              {!isMobile && (
                <Card withBorder shadow="sm" radius="md" p="sm" w={250}>
                  <Stack gap="lg">
                    <Box>
                      <Text size="sm" fw={600} mb="xs" c="dimmed">
                        {__("Static Publisher", TEXT_DOMAIN)}
                      </Text>
                      <Stack gap={0}>
                        {navPrimary.map((item) => (
                          <NavLink
                            key={item.value}
                            label={item.label}
                            leftSection={item.icon}
                            active={mainSection === item.value}
                            onClick={() => setMainSection(item.value)}
                          />
                        ))}
                      </Stack>
                    </Box>
                    <Box>
                      <Text size="sm" fw={600} mb="xs" c="dimmed">
                        {__("Pro Features", TEXT_DOMAIN)}
                      </Text>
                      <Stack gap={0}>
                        {navPro.map((item) => (
                          <NavLink
                            key={item.value}
                            label={item.label}
                            leftSection={item.icon}
                            disabled={item.disabled}
                            active={mainSection === item.value}
                            onClick={() => {
                              if (item.disabled) {
                                return;
                              }
                              setMainSection(item.value);
                              if (item.value === "audit") {
                                setAuditPage(1);
                              }
                            }}
                          />
                        ))}
                      </Stack>
                    </Box>
                  </Stack>
                </Card>
              )}

              <Box className="sp-main-content" style={{ flex: 1, minWidth: 0 }}>
                {isMobile && (
                  <Card withBorder shadow="sm" radius="md" p="sm" mb="md">
                    <Stack gap="md">
                      <Box>
                        <Text size="sm" fw={600} mb="xs" c="dimmed">
                          {__("Static Publisher", TEXT_DOMAIN)}
                        </Text>
                        <Stack gap="xs">
                          {navPrimary.map((item) => (
                            <NavLink
                              key={item.value}
                              label={item.label}
                              leftSection={item.icon}
                              active={mainSection === item.value}
                              onClick={() => setMainSection(item.value)}
                            />
                          ))}
                        </Stack>
                      </Box>
                      <Box>
                        <Text size="sm" fw={600} mb="xs" c="dimmed">
                          {__("Pro Features", TEXT_DOMAIN)}
                        </Text>
                        <Stack gap="xs">
                          {navPro.map((item) => (
                            <NavLink
                              key={item.value}
                              label={item.label}
                              leftSection={item.icon}
                              disabled={item.disabled}
                              active={mainSection === item.value}
                              onClick={() => {
                                if (item.disabled) {
                                  return;
                                }
                                setMainSection(item.value);
                                if (item.value === "audit") {
                                  setAuditPage(1);
                                }
                              }}
                            />
                          ))}
                        </Stack>
                      </Box>
                    </Stack>
                  </Card>
                )}
                <Stack gap="md">
                  {mainSection === "configuration" && (
                    <Card withBorder shadow="sm" radius="md" padding="lg">
                      <Group mb="md" gap="xs">
                        <IconSettings size={18} />
                        <Title order={3}>
                          {__("Core Configuration", TEXT_DOMAIN)}
                        </Title>
                      </Group>
                      <Stack>
                        <TextInput
                          label={infoLabel(
                            __("Source origin", TEXT_DOMAIN),
                            "source-origin",
                          )}
                          description={__(
                            "Derived from WordPress Site Address URL and managed server-side.",
                            TEXT_DOMAIN,
                          )}
                          value={config.sourceOrigin}
                          readOnly
                          placeholder={__(
                            "https://dev.example.com",
                            TEXT_DOMAIN,
                          )}
                        />
                        <Select
                          label={infoLabel(
                            __("URL rewrite mode", TEXT_DOMAIN),
                            "url-rewrite-mode",
                          )}
                          value={config.urlRewriteMode}
                          onChange={(value) => {
                            if (!value) return;
                            setConfig((prev) => ({
                              ...prev,
                              urlRewriteMode: value as RewriteMode,
                            }));
                          }}
                          data={[
                            { value: "absolute", label: "absolute" },
                            { value: "root-relative", label: "root-relative" },
                            { value: "relative", label: "relative" },
                          ]}
                        />
                        <TextInput
                          label={infoLabel(
                            __("External exporter dir", TEXT_DOMAIN),
                            "external-exporter-dir",
                          )}
                          description={__(
                            "Optional absolute path to the installed @smart-cloud/publisher-exporter package root on the queue-runner host.",
                            TEXT_DOMAIN,
                          )}
                          value={config.exporterDir}
                          placeholder={__(
                            "/usr/local/lib/node_modules/@smart-cloud/publisher-exporter",
                            TEXT_DOMAIN,
                          )}
                          onChange={(event) =>
                            setConfig((prev) => ({
                              ...prev,
                              exporterDir: event.currentTarget.value,
                            }))
                          }
                        />
                        <Switch
                          label={infoLabel(
                            __(
                              "Allow self-signed TLS certificates during crawl",
                              TEXT_DOMAIN,
                            ),
                            "ignore-https-errors",
                          )}
                          description={__(
                            "Enable only for internal origins with self-signed/invalid certs. Disable for strict certificate validation.",
                            TEXT_DOMAIN,
                          )}
                          checked={config.ignoreHttpsErrors}
                          onChange={(event) =>
                            setConfig((prev) => ({
                              ...prev,
                              ignoreHttpsErrors: event.currentTarget.checked,
                            }))
                          }
                          size="sm"
                          styles={{
                            track: { cursor: "pointer" },
                            label: { cursor: "pointer" },
                          }}
                        />
                        <SimpleGrid cols={{ base: 1, sm: 2 }}>
                          <TextInput
                            label={infoLabel(
                              __("Output dir", TEXT_DOMAIN),
                              "output-dir",
                            )}
                            description={__(
                              "Storage-relative output folder under the shared Static Publisher storage root. Keep it relative for shared-runtime setups.",
                              TEXT_DOMAIN,
                            )}
                            value={config.outputDir}
                            onChange={(event) =>
                              setConfig((prev) => ({
                                ...prev,
                                outputDir: event.currentTarget.value,
                              }))
                            }
                            rightSection={
                              <ActionIcon
                                variant="subtle"
                                size="sm"
                                title={__("Browse directories", TEXT_DOMAIN)}
                                onClick={() => openDirBrowseFor("outputDir")}
                              >
                                <IconFolder size={14} />
                              </ActionIcon>
                            }
                          />
                          <TextInput
                            label={infoLabel(
                              __("Log dir", TEXT_DOMAIN),
                              "log-dir",
                            )}
                            description={__(
                              "Storage-relative log folder under the shared Static Publisher storage root. Keep it relative for shared-runtime setups.",
                              TEXT_DOMAIN,
                            )}
                            value={config.logDir}
                            onChange={(event) =>
                              setConfig((prev) => ({
                                ...prev,
                                logDir: event.currentTarget.value,
                              }))
                            }
                            rightSection={
                              <ActionIcon
                                variant="subtle"
                                size="sm"
                                title={__("Browse directories", TEXT_DOMAIN)}
                                onClick={() => openDirBrowseFor("logDir")}
                              >
                                <IconFolder size={14} />
                              </ActionIcon>
                            }
                          />
                        </SimpleGrid>
                        <Select
                          label={infoLabel(
                            __("Log level", TEXT_DOMAIN),
                            "log-level",
                          )}
                          value={config.logLevel}
                          onChange={(value) => {
                            if (!value) return;
                            const level = value as LogLevel;
                            setConfig((prev) => ({
                              ...prev,
                              logLevel: level,
                              verbose: level === "debug",
                            }));
                          }}
                          data={[
                            { value: "error", label: "error" },
                            { value: "warn", label: "warn" },
                            { value: "info", label: "info" },
                            { value: "debug", label: "debug" },
                          ]}
                        />
                        <SimpleGrid cols={{ base: 1, sm: 2, lg: 3 }}>
                          <TextInput
                            label={infoLabel(
                              __("Concurrency", TEXT_DOMAIN),
                              "concurrency",
                            )}
                            description={__(
                              "Number of parallel page workers during crawl.",
                              TEXT_DOMAIN,
                            )}
                            type="number"
                            value={String(config.concurrency)}
                            onChange={(event) =>
                              setConfig((prev) => ({
                                ...prev,
                                concurrency: Number(
                                  event.currentTarget.value || 1,
                                ),
                              }))
                            }
                          />
                          <TextInput
                            label={infoLabel(
                              __("Asset download concurrency", TEXT_DOMAIN),
                              "asset-download-concurrency",
                            )}
                            description={__(
                              "Number of parallel asset download workers after page rendering finishes.",
                              TEXT_DOMAIN,
                            )}
                            type="number"
                            value={String(config.assetDownloadConcurrency)}
                            onChange={(event) =>
                              setConfig((prev) => ({
                                ...prev,
                                assetDownloadConcurrency: Number(
                                  event.currentTarget.value || 1,
                                ),
                              }))
                            }
                          />
                          <TextInput
                            label={infoLabel(
                              __("Rewrite concurrency", TEXT_DOMAIN),
                              "rewrite-concurrency",
                            )}
                            description={__(
                              "Number of parallel text rewrite workers in the final rewrite phase. Defaults to asset download concurrency when not set.",
                              TEXT_DOMAIN,
                            )}
                            type="number"
                            value={String(config.rewriteConcurrency)}
                            onChange={(event) =>
                              setConfig((prev) => ({
                                ...prev,
                                rewriteConcurrency: Number(
                                  event.currentTarget.value ||
                                    prev.assetDownloadConcurrency ||
                                    1,
                                ),
                              }))
                            }
                          />
                        </SimpleGrid>
                        <SimpleGrid cols={{ base: 1, sm: 2 }}>
                          <TextInput
                            label={infoLabel(
                              __("Max pages (0 = unlimited)", TEXT_DOMAIN),
                              "max-pages",
                            )}
                            description={__(
                              "Hard cap for rendered pages per crawl run.",
                              TEXT_DOMAIN,
                            )}
                            type="number"
                            value={String(config.maxPages)}
                            onChange={(event) =>
                              setConfig((prev) => ({
                                ...prev,
                                maxPages: Number(
                                  event.currentTarget.value || 0,
                                ),
                              }))
                            }
                          />
                        </SimpleGrid>
                      </Stack>
                    </Card>
                  )}

                  {mainSection === "configuration" && (
                    <Card withBorder shadow="sm" radius="md" padding="lg">
                      <Group mb="md" gap="xs" justify="space-between">
                        <Group gap="xs">
                          <IconCloudUpload size={18} />
                          <Title order={3}>
                            {__("Base Deployment Target", TEXT_DOMAIN)}
                          </Title>
                        </Group>
                        <Button
                          variant="subtle"
                          size="compact-sm"
                          leftSection={<IconInfoCircle size={14} />}
                          onClick={() => openInfo("deployment-targets")}
                        >
                          {__("Open help", TEXT_DOMAIN)}
                        </Button>
                      </Group>
                      <Stack>
                        <Text size="sm" c="dimmed">
                          {__(
                            "Set your main deploy target here. Additional deployment targets live under PRO Features > Extra Deployment Targets and are saved remotely.",
                            TEXT_DOMAIN,
                          )}
                        </Text>
                        <Alert
                          color="blue"
                          variant="light"
                          icon={<IconInfoCircle size={16} />}
                        >
                          <Text size="sm">
                            {__(
                              "If an extra target changes domain, use absolute URL rewrite mode for the base crawl.",
                              TEXT_DOMAIN,
                            )}
                          </Text>
                        </Alert>
                        {!canManageExtraDeploymentProfiles && (
                          <Alert color="yellow" variant="light">
                            <Text size="sm">
                              {__(
                                "Base target is always editable. Extra deployment targets are managed separately under PRO Features and require an active WPSuite subscription.",
                                TEXT_DOMAIN,
                              )}
                            </Text>
                          </Alert>
                        )}
                        <Text fw={600}>{__("Base target", TEXT_DOMAIN)}</Text>
                        <SimpleGrid cols={{ base: 1, lg: 2 }} spacing="md">
                          <Stack gap="md">
                            <TextInput
                              label={infoLabel(
                                __("Target origin", TEXT_DOMAIN),
                                "target-origin",
                              )}
                              description={__(
                                "Public URL used when rewriting links in exported output. Use '.' for relative mode. Non-subscriber HTML exports also receive a generator meta tag.",
                                TEXT_DOMAIN,
                              )}
                              value={config.targetOrigin}
                              onChange={(event) =>
                                setConfig((prev) => ({
                                  ...prev,
                                  targetOrigin: event.currentTarget.value,
                                }))
                              }
                              placeholder={__(
                                "https://www.example.com or .",
                                TEXT_DOMAIN,
                              )}
                            />
                            <TextInput
                              label={infoLabel(
                                __("AWS region", TEXT_DOMAIN),
                                "aws-region",
                              )}
                              description={__(
                                "AWS region used by SDK operations.",
                                TEXT_DOMAIN,
                              )}
                              value={config.s3.region}
                              onChange={(event) =>
                                setConfig((prev) => ({
                                  ...prev,
                                  s3: {
                                    ...prev.s3,
                                    region: event.currentTarget.value,
                                  },
                                }))
                              }
                            />
                          </Stack>
                          <Stack gap="md">
                            <TextInput
                              label={infoLabel(
                                __("S3 bucket", TEXT_DOMAIN),
                                "s3-bucket",
                              )}
                              description={__(
                                "Destination bucket for deploy/upload operations.",
                                TEXT_DOMAIN,
                              )}
                              value={config.s3.bucket}
                              onChange={(event) =>
                                setConfig((prev) => ({
                                  ...prev,
                                  s3: {
                                    ...prev.s3,
                                    bucket: event.currentTarget.value,
                                  },
                                }))
                              }
                            />
                            <TextInput
                              label={infoLabel(
                                __("S3 prefix", TEXT_DOMAIN),
                                "s3-prefix",
                              )}
                              description={__(
                                "Key prefix inside bucket where files are uploaded.",
                                TEXT_DOMAIN,
                              )}
                              value={config.s3.prefix}
                              onChange={(event) =>
                                setConfig((prev) => ({
                                  ...prev,
                                  s3: {
                                    ...prev.s3,
                                    prefix: event.currentTarget.value,
                                  },
                                }))
                              }
                            />
                            <Select
                              label={infoLabel(
                                __("S3 sync mode", TEXT_DOMAIN),
                                "s3-sync-mode",
                              )}
                              value={config.s3SyncMode}
                              onChange={(value) => {
                                if (!value) return;
                                setConfig((prev) => ({
                                  ...prev,
                                  s3SyncMode: value as S3SyncMode,
                                }));
                              }}
                              data={[
                                {
                                  value: "sdk-upload-delete",
                                  label:
                                    "sdk-upload-delete (SDK upload + delete stale)",
                                },
                                {
                                  value: "sdk-upload-only",
                                  label: "sdk-upload-only (SDK upload only)",
                                },
                              ]}
                            />
                          </Stack>
                        </SimpleGrid>
                        <TextInput
                          label={infoLabel(
                            __("CloudFront distribution ID", TEXT_DOMAIN),
                            "cloudfront-distribution-id",
                          )}
                          description={__(
                            "Distribution targeted by invalidate command.",
                            TEXT_DOMAIN,
                          )}
                          value={config.cloudFront.distributionId}
                          onChange={(event) =>
                            setConfig((prev) => ({
                              ...prev,
                              cloudFront: {
                                ...prev.cloudFront,
                                distributionId: event.currentTarget.value,
                              },
                            }))
                          }
                        />
                        <Textarea
                          label={infoLabel(
                            __(
                              "Invalidation paths (one per line)",
                              TEXT_DOMAIN,
                            ),
                            "invalidation-paths",
                          )}
                          description={__(
                            "CloudFront paths to invalidate after deploy (for example /*).",
                            TEXT_DOMAIN,
                          )}
                          value={invalidationText}
                          onChange={(event) =>
                            setInvalidationText(event.currentTarget.value)
                          }
                          autosize
                          minRows={3}
                        />
                        <Stack gap="xs">
                          <Group justify="space-between" align="center">
                            <Text fw={600} id="extra-replacements">
                              {__("Extra replacements", TEXT_DOMAIN)}
                            </Text>
                            <Button
                              size="xs"
                              variant="default"
                              leftSection={<IconPlus size={14} />}
                              onClick={() =>
                                setExtraReplacementRows((prev) => [
                                  ...prev,
                                  createKeyValueRow(),
                                ])
                              }
                            >
                              {__("Add row", TEXT_DOMAIN)}
                            </Button>
                          </Group>
                          <Text size="sm" c="dimmed">
                            {__(
                              "Usually optional. Standard target-origin rewrite already covers common escaped JSON URLs; use this only for custom extra string replacements.",
                              TEXT_DOMAIN,
                            )}
                          </Text>
                          <Table withTableBorder withColumnBorders>
                            <Table.Thead>
                              <Table.Tr>
                                <Table.Th>{__("From", TEXT_DOMAIN)}</Table.Th>
                                <Table.Th>{__("To", TEXT_DOMAIN)}</Table.Th>
                                <Table.Th style={{ width: 80 }}>
                                  {__("Action", TEXT_DOMAIN)}
                                </Table.Th>
                              </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                              {extraReplacementRows.map((row) => (
                                <Table.Tr key={row.id}>
                                  <Table.Td>
                                    <TextInput
                                      value={row.key}
                                      placeholder={__(
                                        "https://dev.example.com",
                                        TEXT_DOMAIN,
                                      )}
                                      onChange={(event) =>
                                        setExtraReplacementRows((prev) =>
                                          prev.map((item) =>
                                            item.id === row.id
                                              ? {
                                                  ...item,
                                                  key: event.currentTarget
                                                    .value,
                                                }
                                              : item,
                                          ),
                                        )
                                      }
                                    />
                                  </Table.Td>
                                  <Table.Td>
                                    <TextInput
                                      value={row.value}
                                      placeholder={__(
                                        "https://www.example.com",
                                        TEXT_DOMAIN,
                                      )}
                                      onChange={(event) =>
                                        setExtraReplacementRows((prev) =>
                                          prev.map((item) =>
                                            item.id === row.id
                                              ? {
                                                  ...item,
                                                  value:
                                                    event.currentTarget.value,
                                                }
                                              : item,
                                          ),
                                        )
                                      }
                                    />
                                  </Table.Td>
                                  <Table.Td>
                                    <ActionIcon
                                      color="red"
                                      variant="subtle"
                                      onClick={() =>
                                        setExtraReplacementRows((prev) => {
                                          const next = prev.filter(
                                            (item) => item.id !== row.id,
                                          );
                                          return next.length > 0
                                            ? next
                                            : [createKeyValueRow()];
                                        })
                                      }
                                    >
                                      <IconTrash size={14} />
                                    </ActionIcon>
                                  </Table.Td>
                                </Table.Tr>
                              ))}
                            </Table.Tbody>
                          </Table>
                        </Stack>
                        <Divider />
                        <Alert color="blue" variant="light">
                          <Text size="sm">
                            {__(
                              "Manage staging, production, or client-specific extra deployment targets under PRO Features > Extra Deployment Targets. The local WordPress configuration keeps only the base target.",
                              TEXT_DOMAIN,
                            )}
                          </Text>
                        </Alert>
                      </Stack>
                    </Card>
                  )}
                </Stack>

                {(mainSection === "configuration" ||
                  mainSection === "jobs") && (
                  <>
                    {mainSection === "configuration" && (
                      <>
                        <Card withBorder shadow="sm" radius="md" padding="lg">
                          <Title order={3} mb="sm">
                            {__("Crawl and Asset Discovery Lists", TEXT_DOMAIN)}
                          </Title>
                          <SimpleGrid cols={{ base: 1, md: 2 }} spacing="md">
                            <Textarea
                              label={infoLabel(
                                __("Seed paths", TEXT_DOMAIN),
                                "seed-paths",
                              )}
                              description={__(
                                "Initial paths to queue directly before/alongside sitemap discovery.",
                                TEXT_DOMAIN,
                              )}
                              value={seedPathsText}
                              onChange={(event) =>
                                setSeedPathsText(event.currentTarget.value)
                              }
                              autosize
                              minRows={3}
                            />
                            <TextInput
                              label={infoLabel(
                                __("Generated 404 request path", TEXT_DOMAIN),
                                "generated-404-request-path",
                              )}
                              description={__(
                                "Optional source path that should render a real HTTP 404, then be captured into the matching static output path. Leave empty to disable.",
                                TEXT_DOMAIN,
                              )}
                              placeholder="/not-found/preview/"
                              value={generated404RequestPath}
                              onChange={(event) =>
                                setGenerated404RequestPath(
                                  event.currentTarget.value,
                                )
                              }
                            />
                            <Textarea
                              label={infoLabel(
                                __("Sitemap paths", TEXT_DOMAIN),
                                "sitemap-paths",
                              )}
                              description={__(
                                "Sitemap endpoints used for recursive URL discovery.",
                                TEXT_DOMAIN,
                              )}
                              value={sitemapPathsText}
                              onChange={(event) =>
                                setSitemapPathsText(event.currentTarget.value)
                              }
                              autosize
                              minRows={3}
                            />
                            <Textarea
                              label={infoLabel(
                                __("Allowed asset hosts", TEXT_DOMAIN),
                                "allowed-asset-hosts",
                              )}
                              description={__(
                                "Additional hostnames allowed for asset downloads (one per line).",
                                TEXT_DOMAIN,
                              )}
                              value={allowedHostsText}
                              onChange={(event) =>
                                setAllowedHostsText(event.currentTarget.value)
                              }
                              autosize
                              minRows={3}
                            />
                            <Textarea
                              label={infoLabel(
                                __("Asset include prefixes", TEXT_DOMAIN),
                                "asset-include-prefixes",
                              )}
                              description={__(
                                "Only asset URLs matching these path rules are downloaded. Use plain prefixes by default, or prefix a line with 're:' for a JavaScript regular expression.",
                                TEXT_DOMAIN,
                              )}
                              value={assetPrefixesText}
                              onChange={(event) =>
                                setAssetPrefixesText(event.currentTarget.value)
                              }
                              autosize
                              minRows={3}
                            />
                            <Textarea
                              label={infoLabel(
                                __("No-JS render path prefixes", TEXT_DOMAIN),
                                "no-js-render-prefixes",
                              )}
                              description={__(
                                "Paths matching these rules are rendered without JavaScript execution. Use plain prefixes by default, or prefix a line with 're:' for a JavaScript regular expression.",
                                TEXT_DOMAIN,
                              )}
                              value={noJsRenderPrefixesText}
                              onChange={(event) =>
                                setNoJsRenderPrefixesText(
                                  event.currentTarget.value,
                                )
                              }
                              autosize
                              minRows={3}
                            />
                            <Textarea
                              label={infoLabel(
                                __("Blocked path prefixes", TEXT_DOMAIN),
                                "blocked-path-prefixes",
                              )}
                              description={__(
                                "Page paths matching these rules are excluded from crawl. Use plain prefixes by default, or prefix a line with 're:' for a JavaScript regular expression.",
                                TEXT_DOMAIN,
                              )}
                              value={blockedPrefixesText}
                              onChange={(event) =>
                                setBlockedPrefixesText(
                                  event.currentTarget.value,
                                )
                              }
                              autosize
                              minRows={3}
                            />
                            <Textarea
                              label={infoLabel(
                                __("Blocked query fragments", TEXT_DOMAIN),
                                "blocked-query-fragments",
                              )}
                              description={__(
                                "URLs containing these query fragments are skipped. Add environment- or plugin-specific preview markers here when needed.",
                                TEXT_DOMAIN,
                              )}
                              value={blockedFragmentsText}
                              onChange={(event) =>
                                setBlockedFragmentsText(
                                  event.currentTarget.value,
                                )
                              }
                              placeholder={__(
                                "elementor-preview\ntrp-edit-translation",
                                TEXT_DOMAIN,
                              )}
                              autosize
                              minRows={3}
                            />
                          </SimpleGrid>
                        </Card>

                        <Card withBorder shadow="sm" radius="md" padding="lg">
                          <Stack gap="md">
                            <Group justify="space-between" align="center">
                              <Title order={3}>
                                {__("Post-Crawl Copy Map", TEXT_DOMAIN)}
                              </Title>
                              <Button
                                variant="subtle"
                                size="compact-sm"
                                leftSection={<IconInfoCircle size={14} />}
                                onClick={() => openInfo("post-crawl-copy-map")}
                              >
                                {__("Open help", TEXT_DOMAIN)}
                              </Button>
                            </Group>

                            <Stack gap="xs">
                              <Group justify="space-between" align="center">
                                <Text fw={600} id="post-crawl-copy-map">
                                  {__("Post-crawl copy map", TEXT_DOMAIN)}
                                </Text>
                                <Button
                                  size="xs"
                                  variant="default"
                                  leftSection={<IconPlus size={14} />}
                                  onClick={() =>
                                    setPostCrawlCopyRows((prev) => [
                                      ...prev,
                                      createKeyValueRow(),
                                    ])
                                  }
                                >
                                  {__("Add row", TEXT_DOMAIN)}
                                </Button>
                              </Group>
                              <Text size="sm" c="dimmed">
                                {__(
                                  "Source path (file/folder) -> URL path prefix under export root. Content is copied after crawl runs, including incremental crawl/publish, before deploy; single-URL and retry-timeouts runs skip it.",
                                  TEXT_DOMAIN,
                                )}
                              </Text>
                              <Table withTableBorder withColumnBorders>
                                <Table.Thead>
                                  <Table.Tr>
                                    <Table.Th>
                                      {__("Source file/folder", TEXT_DOMAIN)}
                                    </Table.Th>
                                    <Table.Th>
                                      {__("Export URL path", TEXT_DOMAIN)}
                                    </Table.Th>
                                    <Table.Th style={{ width: 80 }}>
                                      {__("Action", TEXT_DOMAIN)}
                                    </Table.Th>
                                  </Table.Tr>
                                </Table.Thead>
                                <Table.Tbody>
                                  {postCrawlCopyRows.map((row) => (
                                    <Table.Tr key={row.id}>
                                      <Table.Td>
                                        <TextInput
                                          value={row.key}
                                          placeholder={__(
                                            "/var/www/html/wpsuite/wp-content/uploads/wpsuite-static/",
                                            TEXT_DOMAIN,
                                          )}
                                          onChange={(event) =>
                                            setPostCrawlCopyRows((prev) =>
                                              prev.map((item) =>
                                                item.id === row.id
                                                  ? {
                                                      ...item,
                                                      key: event.currentTarget
                                                        .value,
                                                    }
                                                  : item,
                                              ),
                                            )
                                          }
                                        />
                                      </Table.Td>
                                      <Table.Td>
                                        <TextInput
                                          value={row.value}
                                          placeholder={__(
                                            "/wpsuite/wp-content/uploads/wpsuite-static/",
                                            TEXT_DOMAIN,
                                          )}
                                          onChange={(event) =>
                                            setPostCrawlCopyRows((prev) =>
                                              prev.map((item) =>
                                                item.id === row.id
                                                  ? {
                                                      ...item,
                                                      value:
                                                        event.currentTarget
                                                          .value,
                                                    }
                                                  : item,
                                              ),
                                            )
                                          }
                                        />
                                      </Table.Td>
                                      <Table.Td>
                                        <ActionIcon
                                          color="red"
                                          variant="subtle"
                                          onClick={() =>
                                            setPostCrawlCopyRows((prev) => {
                                              const next = prev.filter(
                                                (item) => item.id !== row.id,
                                              );
                                              return next.length > 0
                                                ? next
                                                : [createKeyValueRow()];
                                            })
                                          }
                                        >
                                          <IconTrash size={14} />
                                        </ActionIcon>
                                      </Table.Td>
                                    </Table.Tr>
                                  ))}
                                </Table.Tbody>
                              </Table>
                            </Stack>
                          </Stack>
                        </Card>

                        <Group justify="space-between">
                          <Button
                            leftSection={<IconSettings size={16} />}
                            loading={saving}
                            onClick={saveConfig}
                          >
                            {__("Save WordPress Configuration", TEXT_DOMAIN)}
                          </Button>
                          <Text c="dimmed" size="sm">
                            {__("Queue length:", TEXT_DOMAIN)}{" "}
                            <strong>{state?.queueLength ?? 0}</strong>
                          </Text>
                        </Group>
                      </>
                    )}

                    {mainSection === "jobs" && (
                      <>
                        <Card withBorder shadow="sm" radius="md" padding="lg">
                          <Group mb="sm" gap="xs">
                            <IconPlayerPlay size={18} />
                            <Title order={3}>
                              {__("Job Queue", TEXT_DOMAIN)}
                            </Title>
                          </Group>
                          {!hasSavedConfig && (
                            <Alert mb="md" color="yellow" variant="light">
                              <Text size="sm">
                                {__(
                                  "Jobs are disabled until Configuration is saved at least once.",
                                  TEXT_DOMAIN,
                                )}
                              </Text>
                            </Alert>
                          )}
                          <SimpleGrid
                            cols={{ base: 1, md: 5 }}
                            spacing="md"
                            verticalSpacing="md"
                          >
                            <Select
                              style={queueJobCellStyle}
                              label={infoLabel(
                                __("Command", TEXT_DOMAIN),
                                "job-command",
                              )}
                              value={command}
                              description={__(
                                "Select the command to queue.",
                                TEXT_DOMAIN,
                              )}
                              onChange={(value) => {
                                const nextCommand =
                                  (value as JobCommand) || "publish";
                                const nextCommandSupportsCrawlMode =
                                  nextCommand === "publish" ||
                                  nextCommand === "crawl";
                                const previousCommandSupportsCrawlMode =
                                  command === "publish" || command === "crawl";
                                setCommand(nextCommand);
                                if (!nextCommandSupportsCrawlMode) {
                                  setCrawlMode(defaultCrawlMode);
                                } else if (!previousCommandSupportsCrawlMode) {
                                  setCrawlMode(defaultCrawlMode);
                                }
                                if (
                                  nextCommand !== "publish" &&
                                  nextCommand !== "deploy" &&
                                  nextCommand !== "invalidate"
                                ) {
                                  setDeploymentProfile("");
                                }
                              }}
                              data={[
                                { value: "publish", label: "publish" },
                                { value: "crawl", label: "crawl" },
                                { value: "deploy", label: "deploy" },
                                { value: "invalidate", label: "invalidate" },
                                {
                                  value: "retry-timeouts",
                                  label: "retry-timeouts",
                                },
                                {
                                  value: "url",
                                  label: "url (single path export)",
                                },
                              ]}
                            />
                            <TextInput
                              style={queueJobCellStyle}
                              disabled={command !== "url"}
                              label={infoLabel(
                                __("URL path", TEXT_DOMAIN),
                                "job-url-path",
                              )}
                              description={__(
                                "Used only for the 'url' command.",
                                TEXT_DOMAIN,
                              )}
                              placeholder={__("/blog/post/", TEXT_DOMAIN)}
                              value={singleUrl}
                              onChange={(event) =>
                                setSingleUrl(event.currentTarget.value)
                              }
                            />
                            <Select
                              style={queueJobCellStyle}
                              label={infoLabel(
                                __("Crawl mode", TEXT_DOMAIN),
                                "job-crawl-mode",
                              )}
                              value={crawlMode}
                              disabled={!commandSupportsCrawlMode}
                              description={
                                !commandSupportsCrawlMode
                                  ? __(
                                      "Used only for publish and crawl commands.",
                                      TEXT_DOMAIN,
                                    )
                                  : hasIncrementalAccess
                                  ? __(
                                      "Incremental skips unchanged pages when possible.",
                                      TEXT_DOMAIN,
                                    )
                                  : __(
                                      "Incremental requests stay selectable, but without an active WPSuite subscription the exporter will fall back to a full crawl.",
                                      TEXT_DOMAIN,
                                    )
                              }
                              onChange={(value) =>
                                setCrawlMode(
                                  (value as CrawlMode) || defaultCrawlMode,
                                )
                              }
                              data={[
                                {
                                  value: "full",
                                  label: __("full", TEXT_DOMAIN),
                                },
                                {
                                  value: "incremental",
                                  label: __("incremental", TEXT_DOMAIN),
                                },
                              ]}
                            />
                            <Select
                              style={queueJobCellStyle}
                              label={infoLabel(
                                __("Deployment profile", TEXT_DOMAIN),
                                "job-deployment-profile",
                              )}
                              value={
                                commandSupportsDeploymentProfile
                                  ? selectedQueueDeploymentProfile ||
                                    NO_DEPLOYMENT_PROFILE_VALUE
                                  : NO_DEPLOYMENT_PROFILE_VALUE
                              }
                              disabled={!commandSupportsDeploymentProfile}
                              description={
                                !commandSupportsDeploymentProfile
                                  ? __(
                                      "Used only for publish, deploy, and invalidate commands.",
                                      TEXT_DOMAIN,
                                    )
                                  : deploymentProfileNames.length === 0
                                  ? __(
                                      "Base target will be used. Add extra targets under PRO Features > Extra Deployment Targets if needed.",
                                      TEXT_DOMAIN,
                                    )
                                  : __(
                                      "Optional. Leave empty to use the base target.",
                                      TEXT_DOMAIN,
                                    )
                              }
                              onChange={(value) =>
                                setDeploymentProfile(
                                  value === NO_DEPLOYMENT_PROFILE_VALUE
                                    ? ""
                                    : value || "",
                                )
                              }
                              data={deploymentProfileOptions}
                            />
                            <Box style={queueJobActionsStyle}>
                              <Stack gap="sm">
                                <Button
                                  onClick={queueJob}
                                  loading={queueing}
                                  disabled={!canQueueJobs}
                                  leftSection={<IconPlayerPlay size={16} />}
                                  fullWidth
                                >
                                  {__("Queue Job", TEXT_DOMAIN)}
                                </Button>
                                <Button
                                  variant="default"
                                  leftSection={<IconKey size={16} />}
                                  onClick={openAwsCreds}
                                  fullWidth
                                >
                                  {__("Temp AWS creds", TEXT_DOMAIN)}
                                </Button>
                              </Stack>
                            </Box>
                          </SimpleGrid>
                          {showIncrementalFallbackWarning && (
                            <Alert mt="md" color="yellow" variant="light">
                              <Text size="sm">
                                {__(
                                  "Incremental crawl was selected, but this site has no active WPSuite subscription. The exporter will run a full crawl for the queued job.",
                                  TEXT_DOMAIN,
                                )}
                              </Text>
                            </Alert>
                          )}
                          <Text mt="sm" size="sm" c="dimmed">
                            {__(
                              "WordPress only queues jobs into runtime/queue.json. Node execution stays outside PHP for review-safe operation.",
                              TEXT_DOMAIN,
                            )}
                          </Text>
                          {state?.currentRun && (
                            <Alert mt="md" color="blue" variant="light">
                              <Stack gap={4}>
                                <Group gap="xs">
                                  <Text fw={600}>
                                    {__("Current run:", TEXT_DOMAIN)}
                                  </Text>
                                  <Code>
                                    {formatJobCommandLabel(
                                      state.currentRun.command,
                                      state.currentRun.crawlMode,
                                    )}
                                  </Code>
                                  <Badge
                                    color={currentRunBadgeColor}
                                    variant="light"
                                    size="sm"
                                  >
                                    {currentRunStatus || "queued"}
                                  </Badge>
                                  {state.currentRun.deploymentProfile && (
                                    <Badge
                                      color="blue"
                                      variant="light"
                                      size="sm"
                                    >
                                      {`profile: ${state.currentRun.deploymentProfile}`}
                                    </Badge>
                                  )}
                                  {state.currentRun.ruleId && (
                                    <Badge
                                      color="violet"
                                      variant="light"
                                      size="sm"
                                    >
                                      {`rule: ${state.currentRun.ruleId}`}
                                    </Badge>
                                  )}
                                  {state.currentRun.command ===
                                    "content-sync" && (
                                    <Badge
                                      color="orange"
                                      variant="light"
                                      size="sm"
                                    >
                                      {`attempt: ${
                                        state.currentRun.attempt ?? 0
                                      }`}
                                    </Badge>
                                  )}
                                </Group>
                                <Text size="sm" c="dimmed">
                                  {__("Queued at", TEXT_DOMAIN)}:{" "}
                                  {state.currentRun.createdAt}
                                </Text>
                                {state.currentRun.command === "publish" &&
                                  currentRunStatus === "running" &&
                                  currentStep !== "" && (
                                    <Text size="sm">
                                      {__("Current step", TEXT_DOMAIN)}:{" "}
                                      <Code>{currentStep}</Code>
                                    </Text>
                                  )}
                                {currentProgressMessage !== "" && (
                                  <Text size="sm">
                                    {__("Progress", TEXT_DOMAIN)}:{" "}
                                    {currentProgressMessage}
                                  </Text>
                                )}
                                {state.currentRun.command === "content-sync" &&
                                  currentContentSync && (
                                    <Text size="sm">
                                      {__("Claimed journal range", TEXT_DOMAIN)}
                                      :{" "}
                                      <Code>{`(${
                                        currentContentSync.fromSequence ?? 0
                                      }, ${
                                        currentContentSync.toSequence ?? 0
                                      }]`}</Code>
                                    </Text>
                                  )}
                                {currentProgressDetails !== "" && (
                                  <Text size="xs" c="dimmed" ff="monospace">
                                    {currentProgressDetails}
                                  </Text>
                                )}
                                {currentProgressSummary !== "" && (
                                  <Text size="xs" c="dimmed">
                                    {__("Summary", TEXT_DOMAIN)}:{" "}
                                    {currentProgressSummary}
                                  </Text>
                                )}
                                {currentCrawlEventMessage !== "" &&
                                  currentCrawlEventMessage !==
                                    currentProgressMessage && (
                                    <Text size="xs" c="dimmed">
                                      {__("Current activity", TEXT_DOMAIN)}:{" "}
                                      {currentCrawlEventMessage}
                                    </Text>
                                  )}
                                {currentCrawlEventFile !== "" && (
                                  <Text size="xs" c="dimmed" ff="monospace">
                                    {__("Current file", TEXT_DOMAIN)}:{" "}
                                    {currentCrawlEventFile}
                                  </Text>
                                )}
                                <Group gap="xs" mt="xs">
                                  <Button
                                    size="xs"
                                    color="red"
                                    variant="light"
                                    leftSection={<IconPlayerStop size={14} />}
                                    loading={stoppingCurrentJob}
                                    disabled={
                                      !isJobRunning ||
                                      stopRequestedForCurrentRun
                                    }
                                    onClick={openStopCurrentRunConfirm}
                                  >
                                    {__("Stop active job", TEXT_DOMAIN)}
                                  </Button>
                                  {stopRequestedForCurrentRun && (
                                    <Text size="xs" c="dimmed">
                                      {__(
                                        "Stop requested. The runner will stop the job after the current step exits and leave it out of the queue.",
                                        TEXT_DOMAIN,
                                      )}
                                    </Text>
                                  )}
                                </Group>
                                {stopRequestedForCurrentRun &&
                                  stopRequest?.requestedAt && (
                                    <Text size="xs" c="dimmed">
                                      {__("Stop requested at", TEXT_DOMAIN)}:{" "}
                                      {stopRequest.requestedAt}
                                    </Text>
                                  )}
                              </Stack>
                            </Alert>
                          )}
                          {!state?.currentRun && lastRunStopped && (
                            <Alert mt="md" color="yellow" variant="light">
                              <Stack gap={4}>
                                <Group gap="xs">
                                  <Text fw={600}>
                                    {lastStopMode === "requeue"
                                      ? __(
                                          "Last run was stopped and requeued.",
                                          TEXT_DOMAIN,
                                        )
                                      : __(
                                          "Last run was stopped.",
                                          TEXT_DOMAIN,
                                        )}
                                  </Text>
                                  <Badge
                                    color="yellow"
                                    variant="light"
                                    size="sm"
                                  >
                                    {__("stopped", TEXT_DOMAIN)}
                                  </Badge>
                                </Group>
                                <Text size="sm">
                                  {__("Command", TEXT_DOMAIN)}:{" "}
                                  <Code>
                                    {formatJobCommandLabel(
                                      state?.lastRun?.command || "",
                                      state?.lastRun?.crawlMode,
                                    )}
                                  </Code>
                                </Text>
                                {lastStoppedStep !== "" && (
                                  <Text size="sm">
                                    {__("Interrupted step", TEXT_DOMAIN)}:{" "}
                                    <Code>{lastStoppedStep}</Code>
                                  </Text>
                                )}
                                {lastStopRequestedAt !== "" && (
                                  <Text size="xs" c="dimmed">
                                    {__("Stop requested at", TEXT_DOMAIN)}:{" "}
                                    {lastStopRequestedAt}
                                    {lastStopRequestedByLogin
                                      ? ` (${lastStopRequestedByLogin})`
                                      : ""}
                                  </Text>
                                )}
                                {lastStopMode !== "requeue" && (
                                  <Text size="xs" c="dimmed">
                                    {__(
                                      "The stopped job was not requeued automatically. Queue a new job manually when you want to resume work.",
                                      TEXT_DOMAIN,
                                    )}
                                  </Text>
                                )}
                              </Stack>
                            </Alert>
                          )}
                          {deployDiffSummary && (
                            <Alert mt="md" color="teal" variant="light">
                              <Stack gap={4}>
                                <Text fw={600}>
                                  {__("Latest deploy diff", TEXT_DOMAIN)}
                                </Text>
                                <Text size="sm">
                                  {__("Uploaded", TEXT_DOMAIN)}:{" "}
                                  {deployDiffSummary.uploaded ?? 0}
                                  {" | "}
                                  {__("Skipped", TEXT_DOMAIN)}:{" "}
                                  {deployDiffSummary.skipped ?? 0}
                                  {" | "}
                                  {__("Failed", TEXT_DOMAIN)}:{" "}
                                  {deployDiffSummary.failed ?? 0}
                                  {" | "}
                                  {__("Deleted", TEXT_DOMAIN)}:{" "}
                                  {deployDiffSummary.deleted ?? 0}
                                </Text>
                                {state?.deployDiff?.generatedAt && (
                                  <Text size="xs" c="dimmed">
                                    {__("Generated at", TEXT_DOMAIN)}:{" "}
                                    {state.deployDiff.generatedAt}
                                  </Text>
                                )}
                                {deployDiffSummary.note && (
                                  <Text size="xs" c="dimmed">
                                    {deployDiffSummary.note}
                                  </Text>
                                )}
                              </Stack>
                            </Alert>
                          )}
                          {!!state?.queueItems?.length && (
                            <Stack mt="md" gap="xs">
                              <Text fw={600} size="sm">
                                {__("Queued jobs", TEXT_DOMAIN)}
                              </Text>
                              {state.queueItems.map((item) => (
                                <Group
                                  key={item.id}
                                  justify="space-between"
                                  p="xs"
                                  style={{
                                    border: "1px solid #dee2e6",
                                    borderRadius: 8,
                                  }}
                                >
                                  <Stack gap={2}>
                                    <Group gap="xs">
                                      <Code>
                                        {formatJobCommandLabel(
                                          item.command,
                                          item.crawlMode,
                                        )}
                                      </Code>
                                      <Badge
                                        color="gray"
                                        variant="light"
                                        size="sm"
                                      >
                                        {item.status || "queued"}
                                      </Badge>
                                      <Text size="xs" c="dimmed">
                                        {item.createdAt ?? ""}
                                      </Text>
                                      {item.deploymentProfile && (
                                        <Badge
                                          color="blue"
                                          variant="light"
                                          size="sm"
                                        >
                                          {`profile: ${item.deploymentProfile}`}
                                        </Badge>
                                      )}
                                      {item.usesTempAwsCreds && (
                                        <Badge
                                          color="orange"
                                          variant="light"
                                          size="sm"
                                        >
                                          {__("temp AWS creds", TEXT_DOMAIN)}
                                        </Badge>
                                      )}
                                      {item.ruleId && (
                                        <Badge
                                          color="violet"
                                          variant="light"
                                          size="sm"
                                        >
                                          {`rule: ${item.ruleId}`}
                                        </Badge>
                                      )}
                                      {item.command === "content-sync" && (
                                        <Badge
                                          color="orange"
                                          variant="light"
                                          size="sm"
                                        >
                                          {`attempt: ${item.attempt ?? 0}`}
                                        </Badge>
                                      )}
                                    </Group>
                                    {item.url && (
                                      <Text size="xs" ff="monospace" c="dimmed">
                                        {item.url}
                                      </Text>
                                    )}
                                    {item.coalesceKey && (
                                      <Text size="xs" ff="monospace" c="dimmed">
                                        {item.coalesceKey}
                                      </Text>
                                    )}
                                    {item.nextAttemptAt && (
                                      <Text size="xs" c="dimmed">
                                        {__("Next retry", TEXT_DOMAIN)}:{" "}
                                        {item.nextAttemptAt}
                                      </Text>
                                    )}
                                    {item.error && (
                                      <Text size="xs" c="red">
                                        {item.error}
                                      </Text>
                                    )}
                                  </Stack>
                                  <Group gap="xs">
                                    <Button
                                      variant="subtle"
                                      color="blue"
                                      size="xs"
                                      leftSection={<IconDownload size={14} />}
                                      loading={downloadingJobId === item.id}
                                      onClick={() =>
                                        void downloadQueuedJobConfig(item.id)
                                      }
                                    >
                                      {__("Download config", TEXT_DOMAIN)}
                                    </Button>
                                    <Button
                                      variant="subtle"
                                      color="red"
                                      size="xs"
                                      leftSection={<IconTrash size={14} />}
                                      disabled={state.lockActive}
                                      onClick={() =>
                                        setPendingDeleteJobId(item.id)
                                      }
                                    >
                                      {__("Delete", TEXT_DOMAIN)}
                                    </Button>
                                  </Group>
                                </Group>
                              ))}
                            </Stack>
                          )}
                        </Card>

                        <Card withBorder shadow="sm" radius="md" padding="lg">
                          <Group mb="sm" gap="xs">
                            <IconFileText size={18} />
                            <Title order={3}>{__("Logs", TEXT_DOMAIN)}</Title>
                          </Group>
                          <div className="logs-controls">
                            <div className="logs-file-select">
                              <Select
                                label={__("Log file", TEXT_DOMAIN)}
                                value={selectedLog}
                                onChange={(value) =>
                                  setSelectedLog(value ?? "")
                                }
                                data={(state?.availableLogs ?? []).map(
                                  (log) => ({
                                    value: log,
                                    label: log,
                                  }),
                                )}
                                searchable
                              />
                            </div>
                            <div className="logs-actions">
                              <SegmentedControl
                                value={logViewMode}
                                onChange={(value) =>
                                  setLogViewMode(value as "pretty" | "raw")
                                }
                                data={[
                                  {
                                    label: __("Pretty", TEXT_DOMAIN),
                                    value: "pretty",
                                  },
                                  {
                                    label: __("Raw", TEXT_DOMAIN),
                                    value: "raw",
                                  },
                                ]}
                              />
                              <Button
                                variant="default"
                                loading={refreshingState}
                                onClick={() => void handleManualRefreshState()}
                              >
                                {__("Refresh State", TEXT_DOMAIN)}
                              </Button>
                              <Button
                                variant="subtle"
                                color="red"
                                loading={clearingLogs}
                                disabled={!hasAnyLogs || isJobRunning}
                                onClick={openClearLogsConfirm}
                              >
                                {__("Clear all logs", TEXT_DOMAIN)}
                              </Button>
                              <Switch
                                label={__("Auto-refresh", TEXT_DOMAIN)}
                                checked={autoRefresh}
                                onChange={(e) =>
                                  setAutoRefresh(e.currentTarget.checked)
                                }
                                size="sm"
                                styles={{
                                  track: { cursor: "pointer" },
                                  label: { cursor: "pointer" },
                                }}
                              />
                              {autoRefresh && (
                                <Select
                                  size="xs"
                                  value={String(autoRefreshInterval)}
                                  onChange={(v) =>
                                    setAutoRefreshInterval(Number(v ?? 30))
                                  }
                                  data={[
                                    {
                                      value: "10",
                                      label: __("Every 10 s", TEXT_DOMAIN),
                                    },
                                    {
                                      value: "30",
                                      label: __("Every 30 s", TEXT_DOMAIN),
                                    },
                                    {
                                      value: "60",
                                      label: __("Every 1 min", TEXT_DOMAIN),
                                    },
                                    {
                                      value: "300",
                                      label: __("Every 5 min", TEXT_DOMAIN),
                                    },
                                  ]}
                                  style={{ minWidth: 120 }}
                                />
                              )}
                            </div>
                          </div>
                          {waitingForQueuedLogs ? (
                            <Alert
                              mt="md"
                              color="blue"
                              variant="light"
                              icon={<IconInfoCircle size={16} />}
                            >
                              <Stack gap={4}>
                                <Text fw={600} size="sm">
                                  {__(
                                    "Waiting for queued jobs to start...",
                                    TEXT_DOMAIN,
                                  )}
                                </Text>
                                <Text size="sm">
                                  {__(
                                    "Jobs are queued, but no log file has been created yet. The first log appears when the external queue runner picks up a job.",
                                    TEXT_DOMAIN,
                                  )}
                                </Text>
                                <Text size="sm" c="dimmed">
                                  {__("Queued jobs:", TEXT_DOMAIN)}{" "}
                                  {state?.queueLength ?? 0}
                                </Text>
                              </Stack>
                            </Alert>
                          ) : jsonlTable ? (
                            <Box mt="md">
                              {logMeta?.truncated && selectedLog && (
                                <Alert
                                  mb="sm"
                                  color="yellow"
                                  variant="light"
                                  icon={<IconInfoCircle size={16} />}
                                >
                                  <Group
                                    justify="space-between"
                                    align="flex-start"
                                    gap="sm"
                                  >
                                    <Stack gap={4} style={{ flex: 1 }}>
                                      <Text fw={600} size="sm">
                                        {__(
                                          "Preview is truncated",
                                          TEXT_DOMAIN,
                                        )}
                                      </Text>
                                      <Text size="sm">
                                        {__(
                                          "The admin view is showing only the tail of the log for performance. Download the full log if you need the complete file.",
                                          TEXT_DOMAIN,
                                        )}
                                      </Text>
                                      <Text size="xs" c="dimmed">
                                        {__("Showing last", TEXT_DOMAIN)}{" "}
                                        {formatByteSize(logMeta.returnedSize)}{" "}
                                        {__("of", TEXT_DOMAIN)}{" "}
                                        {formatByteSize(logMeta.fileSize)}.
                                      </Text>
                                    </Stack>
                                    <Button
                                      variant="subtle"
                                      size="compact-sm"
                                      leftSection={<IconDownload size={14} />}
                                      loading={downloadingLog}
                                      onClick={() =>
                                        void downloadLogFile(selectedLog)
                                      }
                                    >
                                      {__("Download full log", TEXT_DOMAIN)}
                                    </Button>
                                  </Group>
                                </Alert>
                              )}
                              {jsonlTable.truncatedCount > 0 && (
                                <Alert mb="sm" color="yellow" variant="light">
                                  {__(
                                    `Showing last ${jsonlTable.rows.length} lines. ${jsonlTable.truncatedCount} earlier lines are hidden for performance.`,
                                    TEXT_DOMAIN,
                                  )}
                                </Alert>
                              )}
                              <Box
                                style={{
                                  maxHeight: 520,
                                  overflow: "auto",
                                  border: "1px solid #ced4da",
                                  borderRadius: 8,
                                }}
                              >
                                {selectedLog && !logMeta?.truncated && (
                                  <Button
                                    mt="sm"
                                    variant="subtle"
                                    size="compact-sm"
                                    leftSection={<IconDownload size={14} />}
                                    loading={downloadingLog}
                                    onClick={() =>
                                      void downloadLogFile(selectedLog)
                                    }
                                  >
                                    {__("Download full log", TEXT_DOMAIN)}
                                  </Button>
                                )}
                                <Table
                                  striped
                                  highlightOnHover
                                  withTableBorder={false}
                                  withColumnBorders
                                  style={{ minWidth: 920 }}
                                >
                                  <Table.Thead>
                                    <Table.Tr>
                                      <Table.Th>#</Table.Th>
                                      <Table.Th>
                                        {__("Time", TEXT_DOMAIN)}
                                      </Table.Th>
                                      <Table.Th>
                                        {__("Level", TEXT_DOMAIN)}
                                      </Table.Th>
                                      <Table.Th>
                                        {__("Message", TEXT_DOMAIN)}
                                      </Table.Th>
                                      <Table.Th>
                                        {__("Extra", TEXT_DOMAIN)}
                                      </Table.Th>
                                    </Table.Tr>
                                  </Table.Thead>
                                  <Table.Tbody>
                                    {jsonlTable.rows.map((row) => (
                                      <Table.Tr key={row.line}>
                                        <Table.Td>
                                          <Text size="xs" c="dimmed">
                                            {row.line}
                                          </Text>
                                        </Table.Td>
                                        <Table.Td>
                                          <Text size="xs" ff="monospace">
                                            {row.time}
                                          </Text>
                                        </Table.Td>
                                        <Table.Td>
                                          <Badge
                                            size="sm"
                                            variant="light"
                                            color={logLevelColor(row.level)}
                                            style={{ width: "max-content" }}
                                          >
                                            {row.level || "info"}
                                          </Badge>
                                        </Table.Td>
                                        <Table.Td>
                                          <Text
                                            size="sm"
                                            ff="monospace"
                                            style={{ whiteSpace: "pre-wrap" }}
                                          >
                                            {row.message}
                                          </Text>
                                        </Table.Td>
                                        <Table.Td>
                                          <Text
                                            size="xs"
                                            ff="monospace"
                                            style={{ whiteSpace: "pre-wrap" }}
                                          >
                                            {Object.entries(row.extras)
                                              .map(
                                                ([k, v]) =>
                                                  `${k}: ${formatExtraValue(
                                                    v,
                                                  )}`,
                                              )
                                              .join("\n")}
                                          </Text>
                                        </Table.Td>
                                      </Table.Tr>
                                    ))}
                                  </Table.Tbody>
                                </Table>
                              </Box>
                            </Box>
                          ) : (
                            <Textarea
                              mt="md"
                              value={logContent}
                              readOnly
                              rows={18}
                              styles={{
                                input: {
                                  fontFamily:
                                    "ui-monospace, SFMono-Regular, Menlo, monospace",
                                  lineHeight: 1.5,
                                  whiteSpace: "pre",
                                  overflowY: "auto",
                                  maxHeight: 520,
                                },
                              }}
                            />
                          )}
                        </Card>
                      </>
                    )}
                  </>
                )}

                {mainSection === "scheduler" && (
                  <Card withBorder shadow="sm" radius="md" padding="lg">
                    <Group mb="md" gap="xs">
                      <IconKey size={18} />
                      <Title order={3}>
                        {__("PRO Scheduler Settings", TEXT_DOMAIN)}
                      </Title>
                    </Group>
                    <Stack>
                      <Alert color="blue" variant="light">
                        <Text size="sm">
                          {__(
                            "These scheduler settings are stored in the linked wpsuite.io site configuration and are saved separately from the local WordPress settings.",
                            TEXT_DOMAIN,
                          )}
                        </Text>
                      </Alert>
                      {IS_PREMIUM_BUILD && !proAccess.isLinked && (
                        <Alert color="yellow" variant="light">
                          <Text size="sm">
                            {__(
                              "Scheduler PRO editing is available only after connecting this site to wpsuite.io (accountId/siteId/siteKey required).",
                              TEXT_DOMAIN,
                            )}
                          </Text>
                        </Alert>
                      )}
                      {IS_PREMIUM_BUILD && !hasActiveSubscription && (
                        <Alert color="orange" variant="light">
                          <Text size="sm">
                            {__(
                              "Scheduler Settings requires an active WPSuite subscription.",
                              TEXT_DOMAIN,
                            )}
                          </Text>
                        </Alert>
                      )}
                      <Switch
                        label={__("Enable scheduler", TEXT_DOMAIN)}
                        description={__(
                          "When enabled, each queue-runner cron tick can auto-enqueue jobs based on the rules below.",
                          TEXT_DOMAIN,
                        )}
                        checked={config.scheduler.enabled}
                        disabled={!proSchedulerEditingEnabled}
                        onChange={(event) =>
                          setConfig((prev) => ({
                            ...prev,
                            scheduler: {
                              ...prev.scheduler,
                              enabled: event.currentTarget.checked,
                            },
                          }))
                        }
                        size="sm"
                        styles={{
                          track: { cursor: "pointer" },
                          label: { cursor: "pointer" },
                        }}
                      />
                      <Select
                        label={__("Scheduler timezone", TEXT_DOMAIN)}
                        description={__(
                          "Stored for operations context. Current scheduler intervals are driven by queue-runner ticks, not wall-clock timezone windows.",
                          TEXT_DOMAIN,
                        )}
                        searchable
                        data={schedulerTimezoneOptions}
                        value={config.scheduler.timezone}
                        disabled={!proSchedulerEditingEnabled}
                        onChange={(value) => {
                          if (!value) {
                            return;
                          }
                          setConfig((prev) => ({
                            ...prev,
                            scheduler: {
                              ...prev.scheduler,
                              timezone: value,
                            },
                          }));
                        }}
                      />
                      <Alert color="blue" variant="light">
                        <Text size="sm">
                          {__(
                            "Scheduler does not start a background worker by itself. It is evaluated when publisher-exporter queue-runner runs from system cron, systemd timer, or Windows Task Scheduler. A 1-minute runner tick is the recommended cadence.",
                            TEXT_DOMAIN,
                          )}
                        </Text>
                      </Alert>
                      {config.scheduler.rules.some(
                        (rule) => rule.command === "content-sync",
                      ) && (
                        <Alert color="yellow" variant="light">
                          <Text fw={600} size="sm">
                            {__("Content-sync baseline safety", TEXT_DOMAIN)}
                          </Text>
                          <Text size="sm">
                            {__(
                              "A successful full or incremental publish must establish a verified baseline before targeted content sync can deploy. Theme, plugin, permalink, sitemap, rewrite, scope, or deployment-target changes require a new normal publish.",
                              TEXT_DOMAIN,
                            )}
                          </Text>
                        </Alert>
                      )}
                      <Card withBorder radius="sm" padding="md">
                        <Group justify="space-between" mb="sm">
                          <Text fw={600}>
                            {__("Content-sync operations", TEXT_DOMAIN)}
                          </Text>
                          <Group gap="xs">
                            <Badge
                              color={
                                queueRunnerStatus === "error"
                                  ? "red"
                                  : queueRunnerStatus
                                  ? "blue"
                                  : "gray"
                              }
                              variant="light"
                            >
                              {queueRunnerStatus ||
                                __("runner unknown", TEXT_DOMAIN)}
                            </Badge>
                            {contentSyncRuntime?.state?.updatedAt && (
                              <Text size="xs" c="dimmed">
                                {contentSyncRuntime.state.updatedAt}
                              </Text>
                            )}
                          </Group>
                        </Group>
                        {!currentContentSync &&
                        contentSyncRuleStates.length === 0 ? (
                          <Alert color="gray" variant="light">
                            <Text size="sm">
                              {__(
                                "No content-sync runtime state has been recorded yet. Save a content-sync rule and run a normal publish to establish its baseline.",
                                TEXT_DOMAIN,
                              )}
                            </Text>
                          </Alert>
                        ) : (
                          <Stack gap="sm">
                            {currentContentSync && (
                              <Alert color="blue" variant="light">
                                <Stack gap={4}>
                                  <Group gap="xs">
                                    <Text fw={600} size="sm">
                                      {__("Running cutoff", TEXT_DOMAIN)}
                                    </Text>
                                    <Badge variant="light">
                                      {currentContentSync.phase ||
                                        __("claimed", TEXT_DOMAIN)}
                                    </Badge>
                                    <Code>{`(${
                                      currentContentSync.fromSequence ?? 0
                                    }, ${
                                      currentContentSync.toSequence ?? 0
                                    }]`}</Code>
                                  </Group>
                                  <Text size="xs" ff="monospace">
                                    {currentContentSync.ruleId || "-"} /{" "}
                                    {currentContentSync.consumerId || "-"}
                                  </Text>
                                  {contentSyncCheckpoint && (
                                    <Text size="sm">
                                      {__("Checkpoint", TEXT_DOMAIN)}:{" "}
                                      {contentSyncCheckpoint.phase || "-"} -{" "}
                                      {contentSyncCheckpoint.completedItems ??
                                        0}
                                      /{contentSyncCheckpoint.totalItems ?? 0} (
                                      {__("attempt", TEXT_DOMAIN)}{" "}
                                      {contentSyncCheckpoint.attempt ?? 0})
                                    </Text>
                                  )}
                                  {contentSyncCheckpoint?.details &&
                                    Object.keys(contentSyncCheckpoint.details)
                                      .length > 0 && (
                                      <Code block>
                                        {formatAuditDetails(
                                          contentSyncCheckpoint.details,
                                        )}
                                      </Code>
                                    )}
                                </Stack>
                              </Alert>
                            )}
                            {contentSyncRuleStates.map((ruleState, index) => {
                              const key =
                                ruleState.coalesceKey ||
                                `${ruleState.ruleId || "rule"}-${index}`;
                              const baseline = ruleState.coalesceKey
                                ? contentSyncBaselines[ruleState.coalesceKey]
                                : undefined;
                              const baselineReady =
                                !!baseline &&
                                ruleState.baselineStatus !== "required";
                              const lag =
                                ruleState.lag ??
                                Math.max(
                                  0,
                                  (ruleState.observedHeadSequence ?? 0) -
                                    (ruleState.committedSequence ?? 0),
                                );
                              return (
                                <Box
                                  key={key}
                                  p="sm"
                                  style={{
                                    border: "1px solid #dee2e6",
                                    borderRadius: 8,
                                  }}
                                >
                                  <Group
                                    justify="space-between"
                                    align="flex-start"
                                  >
                                    <Stack gap={3}>
                                      <Group gap="xs">
                                        <Code>{ruleState.ruleId || "-"}</Code>
                                        <Badge
                                          color={lag > 0 ? "yellow" : "green"}
                                          variant="light"
                                        >
                                          {__("lag", TEXT_DOMAIN)}: {lag}
                                        </Badge>
                                        <Badge
                                          color={
                                            baselineReady ? "green" : "red"
                                          }
                                          variant="light"
                                        >
                                          {baselineReady
                                            ? __("baseline ready", TEXT_DOMAIN)
                                            : __(
                                                ruleState.baselineStatus ===
                                                  "required"
                                                  ? "baseline stale"
                                                  : "baseline required",
                                                TEXT_DOMAIN,
                                              )}
                                        </Badge>
                                        {ruleState.trailingWorkDetected && (
                                          <Badge color="orange" variant="light">
                                            {__("trailing work", TEXT_DOMAIN)}
                                          </Badge>
                                        )}
                                      </Group>
                                      <Text size="sm">
                                        {__("Committed", TEXT_DOMAIN)}:{" "}
                                        {ruleState.committedSequence ?? 0} |{" "}
                                        {__("Observed", TEXT_DOMAIN)}:{" "}
                                        {ruleState.observedHeadSequence ?? 0}
                                      </Text>
                                      <Text size="xs" c="dimmed" ff="monospace">
                                        {ruleState.consumerId || "-"}
                                      </Text>
                                      {baseline && (
                                        <Text size="xs" c="dimmed">
                                          {__("Baseline verified", TEXT_DOMAIN)}
                                          : {baseline.verifiedAt || "-"}
                                        </Text>
                                      )}
                                      {ruleState.baselineStatus ===
                                        "required" && (
                                        <Alert color="red" variant="light">
                                          <Text fw={600} size="sm">
                                            {__(
                                              "New baseline required",
                                              TEXT_DOMAIN,
                                            )}
                                          </Text>
                                          <Text size="sm">
                                            {ruleState.baselineReason ||
                                              __(
                                                "The active release changed. Queue a successful full or incremental publish before content sync can resume.",
                                                TEXT_DOMAIN,
                                              )}
                                          </Text>
                                        </Alert>
                                      )}
                                    </Stack>
                                    <Stack gap={3} align="flex-end">
                                      <Text size="xs">
                                        {__("Retry attempt", TEXT_DOMAIN)}:{" "}
                                        {ruleState.retryAttempt ?? 0}
                                      </Text>
                                      {ruleState.nextRetryAt && (
                                        <Text size="xs" c="dimmed">
                                          {__("Next retry", TEXT_DOMAIN)}:{" "}
                                          {ruleState.nextRetryAt}
                                        </Text>
                                      )}
                                      {ruleState.lastError && (
                                        <Text size="xs" c="red">
                                          {ruleState.lastError}
                                        </Text>
                                      )}
                                    </Stack>
                                  </Group>
                                </Box>
                              );
                            })}
                          </Stack>
                        )}
                      </Card>
                      <Stack gap="xs">
                        <Group justify="space-between" align="center">
                          <Text fw={600}>
                            {__("Scheduler rules", TEXT_DOMAIN)}
                          </Text>
                          <Button
                            size="xs"
                            leftSection={<IconPlus size={14} />}
                            onClick={openSchedulerRuleCreate}
                            disabled={!proSchedulerEditingEnabled}
                          >
                            {__("Add rule", TEXT_DOMAIN)}
                          </Button>
                        </Group>
                        {config.scheduler.rules.length === 0 ? (
                          <Alert color="gray" variant="light">
                            <Text size="sm">
                              {__(
                                "No scheduler rules defined yet. Add a rule to auto-enqueue jobs.",
                                TEXT_DOMAIN,
                              )}
                            </Text>
                          </Alert>
                        ) : (
                          <Table
                            withTableBorder
                            withColumnBorders
                            highlightOnHover
                          >
                            <Table.Thead>
                              <Table.Tr>
                                <Table.Th>{__("ID", TEXT_DOMAIN)}</Table.Th>
                                <Table.Th>
                                  {__("Command", TEXT_DOMAIN)}
                                </Table.Th>
                                <Table.Th>
                                  {__("Profile", TEXT_DOMAIN)}
                                </Table.Th>
                                <Table.Th>
                                  {__("Interval", TEXT_DOMAIN)}
                                </Table.Th>
                                <Table.Th>
                                  {__("Last evaluation", TEXT_DOMAIN)}
                                </Table.Th>
                                <Table.Th>{__("Scope", TEXT_DOMAIN)}</Table.Th>
                                <Table.Th>
                                  {__("Enabled", TEXT_DOMAIN)}
                                </Table.Th>
                                <Table.Th>
                                  {__("Actions", TEXT_DOMAIN)}
                                </Table.Th>
                              </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                              {config.scheduler.rules.map((rule, index) => (
                                <Table.Tr key={`${rule.id}-${index}`}>
                                  <Table.Td>{rule.id}</Table.Td>
                                  <Table.Td>
                                    {formatJobCommandLabel(
                                      rule.command,
                                      rule.crawlMode,
                                    )}
                                  </Table.Td>
                                  <Table.Td>
                                    {rule.command === "publish" ||
                                    rule.command === "deploy" ||
                                    rule.command === "invalidate" ||
                                    rule.command === "content-sync"
                                      ? rule.deploymentProfile ||
                                        __("base target", TEXT_DOMAIN)
                                      : "-"}
                                  </Table.Td>
                                  <Table.Td>{`${rule.intervalMinutes}m`}</Table.Td>
                                  <Table.Td>
                                    <Stack gap={2}>
                                      <Text size="sm">
                                        {formatSchedulerLastEnqueue(
                                          rule,
                                          schedulerBuckets[rule.id],
                                        )}
                                      </Text>
                                      {rule.command === "content-sync" && (
                                        <Text size="xs" c="dimmed">
                                          {__("Coalesced", TEXT_DOMAIN)}:{" "}
                                          {schedulerCoalescedCounts[rule.id] ??
                                            0}
                                        </Text>
                                      )}
                                    </Stack>
                                  </Table.Td>
                                  <Table.Td>
                                    <Text size="xs">
                                      {summarizeSchedulerScope(rule)}
                                    </Text>
                                  </Table.Td>
                                  <Table.Td>
                                    <Badge
                                      color={rule.enabled ? "green" : "gray"}
                                    >
                                      {rule.enabled
                                        ? __("Yes", TEXT_DOMAIN)
                                        : __("No", TEXT_DOMAIN)}
                                    </Badge>
                                  </Table.Td>
                                  <Table.Td>
                                    <Group gap="xs">
                                      <ActionIcon
                                        variant="subtle"
                                        size="sm"
                                        onClick={() =>
                                          openSchedulerRuleEdit(index)
                                        }
                                        disabled={!proSchedulerEditingEnabled}
                                      >
                                        <IconSettings size={14} />
                                      </ActionIcon>
                                      <ActionIcon
                                        variant="subtle"
                                        color="red"
                                        size="sm"
                                        onClick={() =>
                                          removeSchedulerRule(index)
                                        }
                                        disabled={!proSchedulerEditingEnabled}
                                      >
                                        <IconTrash size={14} />
                                      </ActionIcon>
                                    </Group>
                                  </Table.Td>
                                </Table.Tr>
                              ))}
                            </Table.Tbody>
                          </Table>
                        )}
                      </Stack>
                      <Text size="xs" c="dimmed">
                        {__("Configured rules:", TEXT_DOMAIN)}{" "}
                        {schedulerRuleCount}
                        {" | "}
                        {__(
                          "Scheduler enqueue buckets tracked:",
                          TEXT_DOMAIN,
                        )}{" "}
                        {Object.keys(schedulerBuckets).length}
                      </Text>
                      <Group justify="flex-end">
                        <Button
                          leftSection={<IconKey size={16} />}
                          loading={savingSchedulerConfig}
                          onClick={saveSchedulerConfig}
                          disabled={!proSchedulerEditingEnabled}
                        >
                          {__("Save PRO Scheduler Settings", TEXT_DOMAIN)}
                        </Button>
                      </Group>
                    </Stack>
                  </Card>
                )}

                {mainSection === "extraTargets" && (
                  <Card withBorder shadow="sm" radius="md" padding="lg">
                    <Group mb="md" gap="xs">
                      <IconCloudUpload size={18} />
                      <Title order={3}>
                        {__("Extra Deployment Targets", TEXT_DOMAIN)}
                      </Title>
                    </Group>
                    <Stack>
                      <Alert color="blue" variant="light">
                        <Text size="sm">
                          {__(
                            "These extra deployment targets are stored in the linked wpsuite.io site configuration and are saved separately from the local WordPress settings.",
                            TEXT_DOMAIN,
                          )}
                        </Text>
                      </Alert>
                      {IS_PREMIUM_BUILD && !proAccess.isLinked && (
                        <Alert color="yellow" variant="light">
                          <Text size="sm">
                            {__(
                              "Extra deployment targets are available only after connecting this site to wpsuite.io (accountId/siteId/siteKey required).",
                              TEXT_DOMAIN,
                            )}
                          </Text>
                        </Alert>
                      )}
                      {IS_PREMIUM_BUILD && !hasActiveSubscription && (
                        <Alert color="orange" variant="light">
                          <Text size="sm">
                            {__(
                              "Extra Deployment Targets requires an active WPSuite subscription.",
                              TEXT_DOMAIN,
                            )}
                          </Text>
                        </Alert>
                      )}
                      <Group justify="space-between" align="flex-start">
                        <Stack gap={2}>
                          <Text fw={600}>
                            {__("Remote target variants", TEXT_DOMAIN)}
                          </Text>
                          <Text size="sm" c="dimmed">
                            {__(
                              "Use these for staging, production, or client-specific variants without duplicating the base crawl configuration.",
                              TEXT_DOMAIN,
                            )}
                          </Text>
                        </Stack>
                        <Button
                          size="xs"
                          leftSection={<IconPlus size={14} />}
                          onClick={openDeploymentProfileCreate}
                          disabled={!canManageExtraDeploymentProfiles}
                        >
                          {__(
                            "Add target" +
                              (canManageExtraDeploymentProfiles
                                ? ""
                                : " (PRO)"),
                            TEXT_DOMAIN,
                          )}
                        </Button>
                      </Group>

                      {deploymentProfileNames.length === 0 ? (
                        <Alert color="gray" variant="light">
                          <Text size="sm">
                            {__(
                              "No extra deployment targets yet. The base target from Configuration continues to work on its own.",
                              TEXT_DOMAIN,
                            )}
                          </Text>
                        </Alert>
                      ) : (
                        <Table
                          withTableBorder
                          withColumnBorders
                          highlightOnHover
                        >
                          <Table.Thead>
                            <Table.Tr>
                              <Table.Th>{__("Name", TEXT_DOMAIN)}</Table.Th>
                              <Table.Th>
                                {__("Target origin", TEXT_DOMAIN)}
                              </Table.Th>
                              <Table.Th>{__("S3", TEXT_DOMAIN)}</Table.Th>
                              <Table.Th>
                                {__("CloudFront", TEXT_DOMAIN)}
                              </Table.Th>
                              <Table.Th>
                                {__("Replacements", TEXT_DOMAIN)}
                              </Table.Th>
                              <Table.Th>{__("Actions", TEXT_DOMAIN)}</Table.Th>
                            </Table.Tr>
                          </Table.Thead>
                          <Table.Tbody>
                            {deploymentProfileNames.map((name) => {
                              const profile = deploymentProfiles[name];
                              return (
                                <Table.Tr key={name}>
                                  <Table.Td>
                                    <Code>{name}</Code>
                                  </Table.Td>
                                  <Table.Td>
                                    {profile.targetOrigin || "-"}
                                  </Table.Td>
                                  <Table.Td>
                                    {summarizeDeploymentProfileS3(profile)}
                                  </Table.Td>
                                  <Table.Td>
                                    {summarizeDeploymentProfileCloudFront(
                                      profile,
                                    )}
                                  </Table.Td>
                                  <Table.Td>
                                    {Object.keys(
                                      profile.extraReplacements ?? {},
                                    ).length || "-"}
                                  </Table.Td>
                                  <Table.Td>
                                    <Group gap="xs">
                                      <ActionIcon
                                        variant="subtle"
                                        size="sm"
                                        onClick={() =>
                                          openDeploymentProfileEdit(name)
                                        }
                                        disabled={
                                          !canManageExtraDeploymentProfiles
                                        }
                                      >
                                        <IconSettings size={14} />
                                      </ActionIcon>
                                      <ActionIcon
                                        variant="subtle"
                                        color="red"
                                        size="sm"
                                        onClick={() =>
                                          removeDeploymentProfile(name)
                                        }
                                        disabled={
                                          !canManageExtraDeploymentProfiles
                                        }
                                      >
                                        <IconTrash size={14} />
                                      </ActionIcon>
                                    </Group>
                                  </Table.Td>
                                </Table.Tr>
                              );
                            })}
                          </Table.Tbody>
                        </Table>
                      )}
                      <Group justify="flex-end">
                        <Button
                          leftSection={<IconCloudUpload size={16} />}
                          loading={savingDeploymentTargetsConfig}
                          onClick={saveDeploymentTargetsConfig}
                          disabled={!canManageExtraDeploymentProfiles}
                        >
                          {__("Save Extra Deployment Targets", TEXT_DOMAIN)}
                        </Button>
                      </Group>
                    </Stack>
                  </Card>
                )}

                {mainSection === "audit" && (
                  <Card withBorder shadow="sm" radius="md" padding="lg">
                    <>
                      <Group mb="sm" gap="xs" justify="space-between">
                        <Group gap="xs">
                          <IconFileText size={18} />
                          <Title order={3}>
                            {__("Audit Log", TEXT_DOMAIN)}
                          </Title>
                        </Group>
                        <Button
                          variant="default"
                          loading={auditLoading}
                          onClick={() => void loadAuditLog()}
                        >
                          {__("Refresh", TEXT_DOMAIN)}
                        </Button>
                      </Group>
                      <SimpleGrid cols={{ base: 1, md: 4 }} spacing="md">
                        <Select
                          label={__("Event type", TEXT_DOMAIN)}
                          value={auditEventTypeFilter}
                          onChange={(value) => {
                            setAuditEventTypeFilter(value ?? "");
                            setAuditPage(1);
                          }}
                          data={[
                            { value: "", label: __("All", TEXT_DOMAIN) },
                            { value: "job-created", label: "job-created" },
                            { value: "job-deleted", label: "job-deleted" },
                            {
                              value: "job-run-started",
                              label: "job-run-started",
                            },
                            {
                              value: "job-run-stopped",
                              label: "job-run-stopped",
                            },
                            {
                              value: "job-run-finished",
                              label: "job-run-finished",
                            },
                            {
                              value: "queue-runner-error",
                              label: "queue-runner-error",
                            },
                            {
                              value: "content-sync-demand-detected",
                              label: "content-sync-demand-detected",
                            },
                            {
                              value: "content-sync-coalesced",
                              label: "content-sync-coalesced",
                            },
                            {
                              value: "content-sync-range-claimed",
                              label: "content-sync-range-claimed",
                            },
                            {
                              value: "content-sync-baseline-established",
                              label: "content-sync-baseline-established",
                            },
                            {
                              value: "content-sync-baseline-rejected",
                              label: "content-sync-baseline-rejected",
                            },
                            {
                              value: "content-sync-impact-planned",
                              label: "content-sync-impact-planned",
                            },
                            {
                              value: "content-sync-checkpoint",
                              label: "content-sync-checkpoint",
                            },
                            {
                              value: "content-sync-deployed",
                              label: "content-sync-deployed",
                            },
                            {
                              value: "content-sync-cursor-acknowledged",
                              label: "content-sync-cursor-acknowledged",
                            },
                            {
                              value: "content-sync-trailing-job-created",
                              label: "content-sync-trailing-job-created",
                            },
                            {
                              value: "content-sync-retry-scheduled",
                              label: "content-sync-retry-scheduled",
                            },
                            {
                              value: "content-sync-failed",
                              label: "content-sync-failed",
                            },
                          ]}
                        />
                        <Select
                          label={__("Status", TEXT_DOMAIN)}
                          value={auditStatusFilter}
                          onChange={(value) => {
                            setAuditStatusFilter(value ?? "");
                            setAuditPage(1);
                          }}
                          data={[
                            { value: "", label: __("All", TEXT_DOMAIN) },
                            { value: "queued", label: "queued" },
                            { value: "running", label: "running" },
                            { value: "stopped", label: "stopped" },
                            { value: "success", label: "success" },
                            { value: "failed", label: "failed" },
                            { value: "retry-wait", label: "retry-wait" },
                            { value: "info", label: "info" },
                          ]}
                        />
                        <Select
                          label={__("Rows/page", TEXT_DOMAIN)}
                          value={String(auditPageSize)}
                          onChange={(value) => {
                            const next = Number(value ?? "25");
                            setAuditPageSize(Number.isFinite(next) ? next : 25);
                            setAuditPage(1);
                          }}
                          data={[
                            { value: "10", label: "10" },
                            { value: "25", label: "25" },
                            { value: "50", label: "50" },
                            { value: "100", label: "100" },
                          ]}
                        />
                        <TextInput
                          label={__("Search", TEXT_DOMAIN)}
                          value={auditSearchFilter}
                          onChange={(event) => {
                            setAuditSearchFilter(event.currentTarget.value);
                            setAuditPage(1);
                          }}
                          placeholder={__(
                            "job id, message, details",
                            TEXT_DOMAIN,
                          )}
                        />
                      </SimpleGrid>
                      <Box mt="md">
                        <Table
                          withTableBorder
                          withColumnBorders
                          highlightOnHover
                        >
                          <Table.Thead>
                            <Table.Tr>
                              <Table.Th>{__("Time", TEXT_DOMAIN)}</Table.Th>
                              <Table.Th>{__("Event", TEXT_DOMAIN)}</Table.Th>
                              <Table.Th>{__("Status", TEXT_DOMAIN)}</Table.Th>
                              <Table.Th>{__("Job", TEXT_DOMAIN)}</Table.Th>
                              <Table.Th>{__("Actor", TEXT_DOMAIN)}</Table.Th>
                              <Table.Th>{__("Message", TEXT_DOMAIN)}</Table.Th>
                            </Table.Tr>
                          </Table.Thead>
                          <Table.Tbody>
                            {auditEntries.length === 0 ? (
                              <Table.Tr>
                                <Table.Td colSpan={6}>
                                  <Text c="dimmed" size="sm">
                                    {__("No audit entries found.", TEXT_DOMAIN)}
                                  </Text>
                                </Table.Td>
                              </Table.Tr>
                            ) : (
                              auditEntries.map((entry) => (
                                <Table.Tr key={entry.id}>
                                  <Table.Td>
                                    <Text size="xs" ff="monospace">
                                      {entry.occurredAt}
                                    </Text>
                                  </Table.Td>
                                  <Table.Td>{entry.eventType}</Table.Td>
                                  <Table.Td>
                                    <Badge
                                      color={
                                        entry.status === "failed"
                                          ? "red"
                                          : entry.status === "success"
                                          ? "green"
                                          : entry.status === "stopped"
                                          ? "yellow"
                                          : entry.status === "running"
                                          ? "blue"
                                          : entry.status === "queued"
                                          ? "gray"
                                          : entry.status === "retry-wait"
                                          ? "orange"
                                          : "dark"
                                      }
                                      variant="light"
                                    >
                                      {entry.status}
                                    </Badge>
                                  </Table.Td>
                                  <Table.Td>
                                    <Text size="xs" ff="monospace">
                                      {entry.jobId || "-"}
                                    </Text>
                                    <Text size="xs" c="dimmed">
                                      {entry.command || ""}
                                    </Text>
                                  </Table.Td>
                                  <Table.Td>
                                    <Text size="xs">
                                      {entry.actorSource || "-"}
                                    </Text>
                                    <Text size="xs" c="dimmed">
                                      {entry.actorUserId ?? ""}
                                    </Text>
                                  </Table.Td>
                                  <Table.Td>
                                    <Stack gap={4}>
                                      <Text size="sm">
                                        {entry.message || ""}
                                      </Text>
                                      {formatAuditDetails(entry.details) && (
                                        <Code block>
                                          {formatAuditDetails(entry.details)}
                                        </Code>
                                      )}
                                      {entry.artifacts &&
                                      entry.artifacts.length > 0 ? (
                                        <Stack gap="xs">
                                          {entry.artifacts.map((artifact) => {
                                            const requestId = `${entry.id}:${artifact.id}`;
                                            return (
                                              <Button
                                                key={artifact.id}
                                                variant="subtle"
                                                size="xs"
                                                leftSection={
                                                  <IconDownload size={14} />
                                                }
                                                loading={
                                                  downloadingAuditArtifactId ===
                                                  requestId
                                                }
                                                onClick={() =>
                                                  void downloadAuditArtifact(
                                                    entry,
                                                    artifact,
                                                  )
                                                }
                                              >
                                                {artifact.label}
                                              </Button>
                                            );
                                          })}
                                        </Stack>
                                      ) : null}
                                    </Stack>
                                  </Table.Td>
                                </Table.Tr>
                              ))
                            )}
                          </Table.Tbody>
                        </Table>
                      </Box>
                      <Group justify="space-between" mt="md">
                        <Text size="sm" c="dimmed">
                          {__("Total entries:", TEXT_DOMAIN)} {auditTotal}
                        </Text>
                        <Group gap="xs">
                          <Button
                            variant="default"
                            size="xs"
                            disabled={auditPage <= 1 || auditLoading}
                            onClick={() =>
                              setAuditPage((prev) => Math.max(1, prev - 1))
                            }
                          >
                            {__("Previous", TEXT_DOMAIN)}
                          </Button>
                          <Text size="sm">
                            {auditPage} / {auditTotalPages}
                          </Text>
                          <Button
                            variant="default"
                            size="xs"
                            disabled={
                              auditPage >= auditTotalPages || auditLoading
                            }
                            onClick={() =>
                              setAuditPage((prev) =>
                                Math.min(auditTotalPages, prev + 1),
                              )
                            }
                          >
                            {__("Next", TEXT_DOMAIN)}
                          </Button>
                        </Group>
                      </Group>
                    </>
                  </Card>
                )}
              </Box>
            </Group>
          </Stack>
        )}
      </Box>

      <Modal
        opened={schedulerRuleModalOpen}
        onClose={() => setSchedulerRuleModalOpen(false)}
        title={
          editingSchedulerRuleIndex === null
            ? __("Add scheduler rule", TEXT_DOMAIN)
            : __("Edit scheduler rule", TEXT_DOMAIN)
        }
        centered
      >
        <Stack gap="sm">
          <TextInput
            label={__("Rule ID", TEXT_DOMAIN)}
            value={schedulerRuleDraft.id}
            onChange={(event) =>
              setSchedulerRuleDraft((prev) => ({
                ...prev,
                id: event.currentTarget.value,
              }))
            }
            placeholder="hourly-publish"
          />

          <Select
            label={__("Command", TEXT_DOMAIN)}
            value={schedulerRuleDraft.command}
            data={[
              { value: "publish", label: "publish" },
              { value: "crawl", label: "crawl" },
              { value: "deploy", label: "deploy" },
              { value: "invalidate", label: "invalidate" },
              { value: "retry-timeouts", label: "retry-timeouts" },
              { value: "url", label: "url" },
              {
                value: "content-sync",
                label: "content-sync",
                disabled: !hasIncrementalAccess,
              },
            ]}
            onChange={(value) => {
              if (!value) {
                return;
              }
              setSchedulerRuleDraft((prev) => {
                const nextCommand = value as JobCommand;
                const nextCommandSupportsCrawlMode =
                  nextCommand === "publish" || nextCommand === "crawl";
                const previousCommandSupportsCrawlMode =
                  prev.command === "publish" || prev.command === "crawl";
                const enteringContentSync =
                  nextCommand === "content-sync" &&
                  prev.command !== "content-sync";

                return {
                  ...prev,
                  ...(enteringContentSync ? defaultContentSyncScope() : {}),
                  command: nextCommand,
                  ...(nextCommandSupportsCrawlMode
                    ? previousCommandSupportsCrawlMode
                      ? {}
                      : { crawlMode: defaultCrawlMode }
                    : { crawlMode: defaultCrawlMode }),
                  ...(nextCommand === "publish" ||
                  nextCommand === "deploy" ||
                  nextCommand === "invalidate" ||
                  nextCommand === "content-sync"
                    ? {}
                    : { deploymentProfile: "" }),
                  ...(nextCommand === "url" ? {} : { url: "" }),
                };
              });
            }}
          />

          {(schedulerRuleDraft.command === "publish" ||
            schedulerRuleDraft.command === "crawl") && (
            <Select
              label={__("Crawl mode", TEXT_DOMAIN)}
              value={schedulerRuleDraft.crawlMode ?? "full"}
              data={[
                { value: "full", label: __("full", TEXT_DOMAIN) },
                {
                  value: "incremental",
                  label: __("incremental", TEXT_DOMAIN),
                },
              ]}
              description={__(
                "Incremental mode is available for scheduled publish and crawl jobs only with an active subscription.",
                TEXT_DOMAIN,
              )}
              onChange={(value) =>
                setSchedulerRuleDraft((prev) => ({
                  ...prev,
                  crawlMode: (value as CrawlMode) || defaultCrawlMode,
                }))
              }
            />
          )}

          {(schedulerRuleDraft.command === "publish" ||
            schedulerRuleDraft.command === "deploy" ||
            schedulerRuleDraft.command === "invalidate" ||
            schedulerRuleDraft.command === "content-sync") && (
            <Select
              label={__("Deployment profile", TEXT_DOMAIN)}
              value={
                String(schedulerRuleDraft.deploymentProfile ?? "").trim() ||
                NO_DEPLOYMENT_PROFILE_VALUE
              }
              data={schedulerDeploymentProfileOptions}
              description={
                deploymentProfileNames.length === 0
                  ? __(
                      "Leave this empty to use the base target. Add extra targets under Pro Features > Extra Deployment Targets when needed.",
                      TEXT_DOMAIN,
                    )
                  : __(
                      "Optional. Leave empty to use the base target.",
                      TEXT_DOMAIN,
                    )
              }
              onChange={(value) =>
                setSchedulerRuleDraft((prev) => ({
                  ...prev,
                  deploymentProfile:
                    value === NO_DEPLOYMENT_PROFILE_VALUE ? "" : value || "",
                }))
              }
            />
          )}

          {schedulerRuleDraft.command === "content-sync" && (
            <Stack gap="sm">
              <Alert color="yellow" variant="light">
                <Text size="sm">
                  {__(
                    "Content sync is subscription-gated and scheduler-only. A successful normal publish must establish a trusted baseline before this rule can deploy targeted changes.",
                    TEXT_DOMAIN,
                  )}
                </Text>
              </Alert>
              <MultiSelect
                label={__("Public post types", TEXT_DOMAIN)}
                description={__(
                  "Select at least one public, publicly queryable post type with stable permalinks.",
                  TEXT_DOMAIN,
                )}
                data={contentSyncPostTypeSelectOptions}
                value={schedulerRuleDraft.postTypes ?? []}
                searchable
                required
                disabled={!hasIncrementalAccess}
                rightSection={
                  contentSyncPostTypesLoading ? <Loader size="xs" /> : undefined
                }
                onChange={(value) =>
                  setSchedulerRuleDraft((prev) => ({
                    ...prev,
                    postTypes: value,
                  }))
                }
              />
              <Textarea
                label={__("Explicit listing routes", TEXT_DOMAIN)}
                description={__(
                  "Optional site-relative routes, one per line, for Query Loop or other listing pages that WordPress cannot infer reliably.",
                  TEXT_DOMAIN,
                )}
                placeholder={"/\n/insights/"}
                minRows={3}
                value={linesText(schedulerRuleDraft.listingPaths)}
                onChange={(event) =>
                  setSchedulerRuleDraft((prev) => ({
                    ...prev,
                    listingPaths: parseLines(event.currentTarget.value),
                  }))
                }
              />
              <Switch
                label={__("Include multisite subsites", TEXT_DOMAIN)}
                description={__(
                  !contentSyncMultisite
                    ? "This WordPress installation is not currently a multisite network."
                    : !contentSyncNetworkActive
                      ? "Network-activate SmartCloud Static Publisher before enabling full-network tracking."
                      : "Track matching post types across the full WordPress network. Changing this scope requires a new successful normal publish baseline.",
                  TEXT_DOMAIN,
                )}
                checked={schedulerRuleDraft.includeSubsites === true}
                disabled={
                  !contentSyncMultisite || !contentSyncNetworkActive
                }
                onChange={(event) =>
                  setSchedulerRuleDraft((prev) => ({
                    ...prev,
                    includeSubsites: event.currentTarget.checked,
                  }))
                }
              />
              <Text fw={600} size="sm">
                {__("Reconcile navigation surfaces", TEXT_DOMAIN)}
              </Text>
              <SimpleGrid cols={{ base: 1, sm: 2 }} spacing="xs">
                {(
                  [
                    [
                      "includePostTypeArchives",
                      __("Post type archives", TEXT_DOMAIN),
                    ],
                    [
                      "includeTaxonomyArchives",
                      __("Taxonomy archives", TEXT_DOMAIN),
                    ],
                    [
                      "includeAuthorArchives",
                      __("Author archives", TEXT_DOMAIN),
                    ],
                    ["includeDateArchives", __("Date archives", TEXT_DOMAIN)],
                    ["includePostsPage", __("Posts page", TEXT_DOMAIN)],
                    ["includeSitemapChain", __("Sitemap chain", TEXT_DOMAIN)],
                  ] as Array<[keyof SchedulerRule, string]>
                ).map(([field, label]) => (
                  <Switch
                    key={field}
                    label={label}
                    checked={schedulerRuleDraft[field] === true}
                    onChange={(event) =>
                      setSchedulerRuleDraft((prev) => ({
                        ...prev,
                        [field]: event.currentTarget.checked,
                      }))
                    }
                    size="sm"
                  />
                ))}
              </SimpleGrid>
            </Stack>
          )}

          <TextInput
            label={__("Interval (minutes)", TEXT_DOMAIN)}
            type="number"
            min={1}
            value={String(schedulerRuleDraft.intervalMinutes)}
            onChange={(event) => {
              const value = Number(event.currentTarget.value || "1");
              setSchedulerRuleDraft((prev) => ({
                ...prev,
                intervalMinutes: value,
              }));
            }}
          />

          {schedulerRuleDraft.command === "url" && (
            <TextInput
              label={__("URL path", TEXT_DOMAIN)}
              placeholder="/blog/post/"
              value={schedulerRuleDraft.url ?? ""}
              onChange={(event) => {
                const value = event.currentTarget.value;
                setSchedulerRuleDraft((prev) => ({
                  ...prev,
                  url: value,
                }));
              }}
            />
          )}

          <Switch
            label={__("Enabled", TEXT_DOMAIN)}
            checked={schedulerRuleDraft.enabled}
            onChange={(event) => {
              const checked = event.currentTarget.checked;
              setSchedulerRuleDraft((prev) => ({
                ...prev,
                enabled: checked,
              }));
            }}
            size="sm"
          />

          <Group justify="flex-end">
            <Button
              variant="default"
              onClick={() => setSchedulerRuleModalOpen(false)}
            >
              {__("Cancel", TEXT_DOMAIN)}
            </Button>
            <Button onClick={saveSchedulerRuleDraft}>
              {editingSchedulerRuleIndex === null
                ? __("Add rule", TEXT_DOMAIN)
                : __("Save rule", TEXT_DOMAIN)}
            </Button>
          </Group>
        </Stack>
      </Modal>

      <Modal
        opened={deploymentProfileModalOpen}
        onClose={closeDeploymentProfileModal}
        title={
          editingDeploymentProfileName === null
            ? __("Add extra target", TEXT_DOMAIN)
            : __("Edit extra target", TEXT_DOMAIN)
        }
        size="xl"
        centered
      >
        <Stack gap="md">
          <TextInput
            label={__("Target key", TEXT_DOMAIN)}
            value={deploymentProfileDraft.name}
            onChange={(event) => {
              const value = event.currentTarget.value;
              setDeploymentProfileDraft((prev) => ({
                ...prev,
                name: value,
              }));
            }}
            placeholder="staging"
            description={__(
              "Used with CLI --profile and in queue or scheduler selectors.",
              TEXT_DOMAIN,
            )}
          />

          <Alert
            color="blue"
            variant="light"
            icon={<IconInfoCircle size={16} />}
          >
            <Text size="sm">
              {__(
                "If this target changes domain, use absolute URL rewrite mode for the base crawl.",
                TEXT_DOMAIN,
              )}
            </Text>
          </Alert>

          <TextInput
            label={__("Public URL override", TEXT_DOMAIN)}
            value={deploymentProfileDraft.targetOrigin}
            onChange={(event) => {
              const value = event.currentTarget.value;
              setDeploymentProfileDraft((prev) => ({
                ...prev,
                targetOrigin: value,
              }));
            }}
            placeholder="https://staging.example.com or ."
            description={__(
              "Optional. Rewrites links for this target. Use '.' for relative output.",
              TEXT_DOMAIN,
            )}
          />

          <SimpleGrid cols={{ base: 1, md: 2 }} spacing="md">
            <TextInput
              label={__("S3 bucket override", TEXT_DOMAIN)}
              value={deploymentProfileDraft.s3.bucket}
              onChange={(event) => {
                const value = event.currentTarget.value;
                setDeploymentProfileDraft((prev) => ({
                  ...prev,
                  s3: {
                    ...prev.s3,
                    bucket: value,
                  },
                }));
              }}
            />
            <TextInput
              label={__("S3 prefix override", TEXT_DOMAIN)}
              value={deploymentProfileDraft.s3.prefix}
              onChange={(event) => {
                const value = event.currentTarget.value;
                setDeploymentProfileDraft((prev) => ({
                  ...prev,
                  s3: {
                    ...prev.s3,
                    prefix: value,
                  },
                }));
              }}
            />
            <TextInput
              label={__("AWS region override", TEXT_DOMAIN)}
              value={deploymentProfileDraft.s3.region}
              onChange={(event) => {
                const value = event.currentTarget.value;
                setDeploymentProfileDraft((prev) => ({
                  ...prev,
                  s3: {
                    ...prev.s3,
                    region: value,
                  },
                }));
              }}
            />
            <TextInput
              label={__("HTML cache-control override", TEXT_DOMAIN)}
              value={deploymentProfileDraft.s3.htmlCacheControl}
              onChange={(event) => {
                const value = event.currentTarget.value;
                setDeploymentProfileDraft((prev) => ({
                  ...prev,
                  s3: {
                    ...prev.s3,
                    htmlCacheControl: value,
                  },
                }));
              }}
            />
            <TextInput
              label={__("Asset cache-control override", TEXT_DOMAIN)}
              value={deploymentProfileDraft.s3.assetCacheControl}
              onChange={(event) => {
                const value = event.currentTarget.value;
                setDeploymentProfileDraft((prev) => ({
                  ...prev,
                  s3: {
                    ...prev.s3,
                    assetCacheControl: value,
                  },
                }));
              }}
            />
            <TextInput
              label={__("CloudFront distribution ID override", TEXT_DOMAIN)}
              value={deploymentProfileDraft.cloudFront.distributionId}
              onChange={(event) => {
                const value = event.currentTarget.value;
                setDeploymentProfileDraft((prev) => ({
                  ...prev,
                  cloudFront: {
                    ...prev.cloudFront,
                    distributionId: value,
                  },
                }));
              }}
            />
          </SimpleGrid>

          <Textarea
            label={__(
              "CloudFront invalidation paths override (one per line)",
              TEXT_DOMAIN,
            )}
            value={deploymentProfileDraft.cloudFront.invalidationPathsText}
            onChange={(event) => {
              const value = event.currentTarget.value;
              setDeploymentProfileDraft((prev) => ({
                ...prev,
                cloudFront: {
                  ...prev.cloudFront,
                  invalidationPathsText: value,
                },
              }));
            }}
            autosize
            minRows={3}
            placeholder="/*"
          />

          <Stack gap="xs">
            <Group justify="space-between" align="center">
              <Text fw={600}>
                {__("Profile extra replacements", TEXT_DOMAIN)}
              </Text>
              <Button
                size="xs"
                variant="default"
                leftSection={<IconPlus size={14} />}
                onClick={() =>
                  setDeploymentProfileDraft((prev) => ({
                    ...prev,
                    extraReplacementRows: [
                      ...prev.extraReplacementRows,
                      createKeyValueRow(),
                    ],
                  }))
                }
              >
                {__("Add row", TEXT_DOMAIN)}
              </Button>
            </Group>
            <Text size="sm" c="dimmed">
              {__(
                "Applied only when this profile is selected during deploy or invalidate.",
                TEXT_DOMAIN,
              )}
            </Text>
            <Table withTableBorder withColumnBorders>
              <Table.Thead>
                <Table.Tr>
                  <Table.Th>{__("From", TEXT_DOMAIN)}</Table.Th>
                  <Table.Th>{__("To", TEXT_DOMAIN)}</Table.Th>
                  <Table.Th style={{ width: 80 }}>
                    {__("Action", TEXT_DOMAIN)}
                  </Table.Th>
                </Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {deploymentProfileDraft.extraReplacementRows.map((row) => (
                  <Table.Tr key={row.id}>
                    <Table.Td>
                      <TextInput
                        value={row.key}
                        placeholder="https://dev.example.com"
                        onChange={(event) => {
                          const value = event.currentTarget.value;
                          setDeploymentProfileDraft((prev) => ({
                            ...prev,
                            extraReplacementRows: prev.extraReplacementRows.map(
                              (item) =>
                                item.id === row.id
                                  ? {
                                      ...item,
                                      key: value,
                                    }
                                  : item,
                            ),
                          }));
                        }}
                      />
                    </Table.Td>
                    <Table.Td>
                      <TextInput
                        value={row.value}
                        placeholder="https://staging.example.com"
                        onChange={(event) => {
                          const value = event.currentTarget.value;
                          setDeploymentProfileDraft((prev) => ({
                            ...prev,
                            extraReplacementRows: prev.extraReplacementRows.map(
                              (item) =>
                                item.id === row.id
                                  ? {
                                      ...item,
                                      value,
                                    }
                                  : item,
                            ),
                          }));
                        }}
                      />
                    </Table.Td>
                    <Table.Td>
                      <ActionIcon
                        color="red"
                        variant="subtle"
                        onClick={() =>
                          setDeploymentProfileDraft((prev) => {
                            const nextRows = prev.extraReplacementRows.filter(
                              (item) => item.id !== row.id,
                            );
                            return {
                              ...prev,
                              extraReplacementRows:
                                nextRows.length > 0
                                  ? nextRows
                                  : [createKeyValueRow()],
                            };
                          })
                        }
                      >
                        <IconTrash size={14} />
                      </ActionIcon>
                    </Table.Td>
                  </Table.Tr>
                ))}
              </Table.Tbody>
            </Table>
          </Stack>

          <Group justify="flex-end">
            <Button variant="default" onClick={closeDeploymentProfileModal}>
              {__("Cancel", TEXT_DOMAIN)}
            </Button>
            <Button onClick={saveDeploymentProfileDraft}>
              {editingDeploymentProfileName === null
                ? __("Add target", TEXT_DOMAIN)
                : __("Save target", TEXT_DOMAIN)}
            </Button>
          </Group>
        </Stack>
      </Modal>

      <Modal
        opened={awsCredsOpen}
        onClose={closeAwsCreds}
        title={__("Temporary AWS Credentials", TEXT_DOMAIN)}
        size="lg"
      >
        <Stack gap="sm">
          <Text size="sm" c="dimmed">
            {__(
              "Used only for queued publish/deploy/invalidate jobs. Credentials are short-lived and should be rotated regularly.",
              TEXT_DOMAIN,
            )}
          </Text>
          <PasswordInput
            label={__("AWS_ACCESS_KEY_ID", TEXT_DOMAIN)}
            value={awsTempCreds.accessKeyId}
            onChange={(event) =>
              setAwsTempCreds((prev) => ({
                ...prev,
                accessKeyId: event.currentTarget.value,
              }))
            }
            autoComplete="off"
          />
          <PasswordInput
            label={__("AWS_SECRET_ACCESS_KEY", TEXT_DOMAIN)}
            value={awsTempCreds.secretAccessKey}
            onChange={(event) =>
              setAwsTempCreds((prev) => ({
                ...prev,
                secretAccessKey: event.currentTarget.value,
              }))
            }
            autoComplete="off"
          />
          <PasswordInput
            label={__("AWS_SESSION_TOKEN", TEXT_DOMAIN)}
            value={awsTempCreds.sessionToken}
            onChange={(event) =>
              setAwsTempCreds((prev) => ({
                ...prev,
                sessionToken: event.currentTarget.value,
              }))
            }
            autoComplete="off"
          />
          <Group justify="space-between" mt="sm">
            <Button
              variant="subtle"
              color="red"
              onClick={() =>
                setAwsTempCreds({
                  accessKeyId: "",
                  secretAccessKey: "",
                  sessionToken: "",
                })
              }
            >
              {__("Clear", TEXT_DOMAIN)}
            </Button>
            <Button onClick={closeAwsCreds}>{__("Done", TEXT_DOMAIN)}</Button>
          </Group>
        </Stack>
      </Modal>

      <Modal
        opened={clearLogsConfirmOpen}
        onClose={closeClearLogsConfirm}
        title={__("Clear all logs", TEXT_DOMAIN)}
        centered
      >
        <Stack gap="sm">
          <Text size="sm">
            {__(
              "Are you sure you want to clear all log files? This cannot be undone.",
              TEXT_DOMAIN,
            )}
          </Text>
          <Group justify="flex-end">
            <Button variant="default" onClick={closeClearLogsConfirm}>
              {__("Cancel", TEXT_DOMAIN)}
            </Button>
            <Button
              color="red"
              loading={clearingLogs}
              onClick={() => {
                closeClearLogsConfirm();
                void clearAllLogs();
              }}
            >
              {__("Clear all logs", TEXT_DOMAIN)}
            </Button>
          </Group>
        </Stack>
      </Modal>

      <Modal
        opened={stopCurrentRunConfirmOpen}
        onClose={closeStopCurrentRunConfirm}
        title={__("Stop active job", TEXT_DOMAIN)}
        centered
      >
        <Stack gap="sm">
          <Text size="sm">
            {__(
              "Are you sure you want to stop the active job? The queue runner will terminate the current step and leave the job out of the queue. Queue a new job manually when you are ready to run again.",
              TEXT_DOMAIN,
            )}
          </Text>
          <Group justify="flex-end">
            <Button variant="default" onClick={closeStopCurrentRunConfirm}>
              {__("Cancel", TEXT_DOMAIN)}
            </Button>
            <Button
              color="red"
              loading={stoppingCurrentJob}
              onClick={() => {
                closeStopCurrentRunConfirm();
                void requestStopCurrentJob();
              }}
            >
              {__("Stop job", TEXT_DOMAIN)}
            </Button>
          </Group>
        </Stack>
      </Modal>

      <Modal
        opened={pendingDeleteJobId !== null}
        onClose={() => setPendingDeleteJobId(null)}
        title={__("Delete queued job", TEXT_DOMAIN)}
        centered
      >
        <Stack gap="sm">
          <Text size="sm">
            {state?.queueItems?.find(
              (item) => item.id === pendingDeleteJobId,
            )?.command === "content-sync"
              ? __(
                  "Abandon this content-sync retry? Its local plan and checkpoint will be cleared, but the journal cursor will stay unchanged so the scheduler can rediscover the unacknowledged range.",
                  TEXT_DOMAIN,
                )
              : __(
                  "Are you sure you want to delete this queued job? This cannot be undone.",
                  TEXT_DOMAIN,
                )}
          </Text>
          <Group justify="flex-end">
            <Button
              variant="default"
              onClick={() => setPendingDeleteJobId(null)}
            >
              {__("Cancel", TEXT_DOMAIN)}
            </Button>
            <Button
              color="red"
              leftSection={<IconTrash size={14} />}
              onClick={() =>
                pendingDeleteJobId && void deleteQueuedJob(pendingDeleteJobId)
              }
            >
              {state?.queueItems?.find(
                (item) => item.id === pendingDeleteJobId,
              )?.command === "content-sync"
                ? __("Abandon retry", TEXT_DOMAIN)
                : __("Delete", TEXT_DOMAIN)}
            </Button>
          </Group>
        </Stack>
      </Modal>

      <Modal
        opened={dirBrowseOpen}
        onClose={closeDirBrowse}
        title={
          dirBrowseTarget === "outputDir"
            ? __("Select output directory", TEXT_DOMAIN)
            : __("Select log directory", TEXT_DOMAIN)
        }
        size="md"
      >
        <Stack gap="xs">
          <Group gap="xs">
            <Text size="sm" c="dimmed">
              {__("Root:", TEXT_DOMAIN)}
            </Text>
            <Text size="sm" ff="monospace">
              smartcloud-static-publisher/
              {dirBrowsePath !== "" ? dirBrowsePath + "/" : ""}
            </Text>
          </Group>

          {dirBrowseLoading ? (
            <Group justify="center" py="md">
              <Loader size="sm" />
            </Group>
          ) : (
            <ScrollArea h={280}>
              <Stack gap={2}>
                {dirBrowsePath !== "" && (
                  <Button
                    variant="subtle"
                    justify="start"
                    size="sm"
                    leftSection={<IconFolder size={14} />}
                    onClick={navigateDirBrowseUp}
                  >
                    ..
                  </Button>
                )}
                {dirBrowseDirs.length === 0 && (
                  <Text size="sm" c="dimmed" py="sm" ta="center">
                    {__("No subdirectories", TEXT_DOMAIN)}
                  </Text>
                )}
                {dirBrowseDirs.map((dir) => (
                  <Button
                    key={dir}
                    variant="subtle"
                    justify="start"
                    size="sm"
                    leftSection={<IconFolder size={14} />}
                    onClick={() => navigateDirBrowse(dir)}
                  >
                    {dir}
                  </Button>
                ))}
              </Stack>
            </ScrollArea>
          )}

          <Group justify="flex-end" mt="sm">
            <Button variant="default" size="sm" onClick={closeDirBrowse}>
              {__("Cancel", TEXT_DOMAIN)}
            </Button>
            <Button size="sm" onClick={confirmDirSelection}>
              {dirBrowsePath !== ""
                ? __("Select this folder", TEXT_DOMAIN)
                : __("Use root", TEXT_DOMAIN)}
            </Button>
          </Group>
        </Stack>
      </Modal>
    </Box>
  );
}

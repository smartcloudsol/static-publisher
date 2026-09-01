import { getConfig, getWpSuite } from "@smart-cloud/wpsuite-core";
import {
  createReduxStore,
  dispatch,
  register,
  select,
  type StoreDescriptor,
} from "@wordpress/data";
import type {
  PublisherCloudFrontConfig,
  PublisherCrawlMode,
  PublisherDeploymentProfile,
  PublisherDeploymentProfiles,
  PublisherRemoteConfig,
  PublisherS3Config,
  PublisherSchedulerConfig,
  PublisherSchedulerRule,
} from "./types";

function normalizeOriginOrDot(value: string): string {
  const trimmed = value.trim();
  if (trimmed === ".") {
    return ".";
  }
  return trimmed.replace(/\/$/, "");
}

function sanitizeStringMap(input: unknown): Record<string, string> {
  if (!input || typeof input !== "object") {
    return {};
  }

  return Object.fromEntries(
    Object.entries(input as Record<string, unknown>)
      .map(([key, value]) => [key.trim(), String(value ?? "")])
      .filter(([key]) => key.length > 0),
  );
}

function sanitizeStringArray(input: unknown): string[] {
  return Array.isArray(input)
    ? input
        .map((value) => String(value ?? "").trim())
        .filter((value) => value.length > 0)
    : [];
}

function sanitizePostTypes(input: unknown): string[] {
  return [
    ...new Set(
      sanitizeStringArray(input)
        .map((value) => value.toLowerCase().replace(/[^a-z0-9_-]/g, ""))
        .filter(Boolean),
    ),
  ];
}

function sanitizeDeploymentProfileS3(
  input: unknown,
): Partial<PublisherS3Config> | undefined {
  if (!input || typeof input !== "object") {
    return undefined;
  }

  const source = input as Record<string, unknown>;
  const out: Partial<PublisherS3Config> = {};

  if (typeof source.bucket === "string") {
    out.bucket = source.bucket.trim();
  }
  if (typeof source.prefix === "string") {
    out.prefix = source.prefix.trim();
  }
  if (typeof source.region === "string") {
    out.region = source.region.trim();
  }
  if (typeof source.htmlCacheControl === "string") {
    out.htmlCacheControl = source.htmlCacheControl.trim();
  }
  if (typeof source.assetCacheControl === "string") {
    out.assetCacheControl = source.assetCacheControl.trim();
  }

  return Object.keys(out).length > 0 ? out : undefined;
}

function sanitizeDeploymentProfileCloudFront(
  input: unknown,
): Partial<PublisherCloudFrontConfig> | undefined {
  if (!input || typeof input !== "object") {
    return undefined;
  }

  const source = input as Record<string, unknown>;
  const out: Partial<PublisherCloudFrontConfig> = {};

  if (typeof source.distributionId === "string") {
    out.distributionId = source.distributionId.trim();
  }

  const invalidationPaths = sanitizeStringArray(source.invalidationPaths);
  if (invalidationPaths.length > 0) {
    out.invalidationPaths = invalidationPaths;
  }

  return Object.keys(out).length > 0 ? out : undefined;
}

function sanitizeDeploymentProfile(
  input: unknown,
): PublisherDeploymentProfile | null {
  if (!input || typeof input !== "object") {
    return null;
  }

  const source = input as Record<string, unknown>;
  const out: PublisherDeploymentProfile = {};

  if (typeof source.targetOrigin === "string") {
    const targetOrigin = normalizeOriginOrDot(source.targetOrigin);
    if (targetOrigin) {
      out.targetOrigin = targetOrigin;
    }
  }

  const extraReplacements = sanitizeStringMap(source.extraReplacements);
  if (Object.keys(extraReplacements).length > 0) {
    out.extraReplacements = extraReplacements;
  }

  const s3 = sanitizeDeploymentProfileS3(source.s3);
  if (s3) {
    out.s3 = s3;
  }

  const cloudFront = sanitizeDeploymentProfileCloudFront(source.cloudFront);
  if (cloudFront) {
    out.cloudFront = cloudFront;
  }

  return out;
}

function sanitizeDeploymentProfiles(
  input: unknown,
): PublisherDeploymentProfiles {
  if (!input || typeof input !== "object") {
    return {};
  }

  const out: PublisherDeploymentProfiles = {};

  for (const [rawName, rawProfile] of Object.entries(
    input as Record<string, unknown>,
  )) {
    const name = rawName.trim();
    if (!name) {
      continue;
    }

    const profile = sanitizeDeploymentProfile(rawProfile);
    if (profile) {
      out[name] = profile;
    }
  }

  return out;
}

function sanitizeDeploymentProfileName(
  value: unknown,
  deploymentProfiles: PublisherDeploymentProfiles,
): string | undefined {
  if (typeof value !== "string") {
    return undefined;
  }

  const trimmed = value.trim();
  if (!trimmed || !deploymentProfiles[trimmed]) {
    return undefined;
  }

  return trimmed;
}

function sanitizeSchedulerRule(
  input: unknown,
  index: number,
): PublisherSchedulerRule | null {
  if (!input || typeof input !== "object") {
    return null;
  }

  const row = input as Record<string, unknown>;
  const command = String(
    row.command ?? "",
  ).trim() as PublisherSchedulerRule["command"];
  if (
    command !== "publish" &&
    command !== "crawl" &&
    command !== "deploy" &&
    command !== "invalidate" &&
    command !== "retry-timeouts" &&
    command !== "url" &&
    command !== "content-sync"
  ) {
    return null;
  }

  const intervalMinutes = Number.parseInt(
    String(row.intervalMinutes ?? "0"),
    10,
  );
  if (!Number.isFinite(intervalMinutes) || intervalMinutes < 1) {
    return null;
  }

  const id = String(row.id ?? `${command}-${index + 1}`).trim();
  if (!id) {
    return null;
  }

  const url = String(row.url ?? "").trim();
  if (command === "url" && !url) {
    return null;
  }

  const crawlMode = String(row.crawlMode ?? "").trim();
  const deploymentProfile = String(row.deploymentProfile ?? "").trim();
  const postTypes = sanitizePostTypes(row.postTypes);
  const listingPaths = sanitizeStringArray(row.listingPaths);
  if (command === "content-sync" && postTypes.length === 0) {
    return null;
  }

  return {
    id,
    enabled: row.enabled !== false,
    command,
    intervalMinutes,
    ...(crawlMode === "full" || crawlMode === "incremental"
      ? { crawlMode: crawlMode as PublisherCrawlMode }
      : {}),
    ...(deploymentProfile ? { deploymentProfile } : {}),
    ...(url ? { url } : {}),
    ...(postTypes.length > 0 ? { postTypes } : {}),
    ...(listingPaths.length > 0 ? { listingPaths } : {}),
    ...Object.fromEntries(
      [
        "includePostTypeArchives",
        "includeSubsites",
        "includeTaxonomyArchives",
        "includeAuthorArchives",
        "includeDateArchives",
        "includePostsPage",
        "includeSitemapChain",
      ]
        .filter((key) => typeof row[key] === "boolean")
        .map((key) => [key, row[key]]),
    ),
  };
}

function sanitizeSchedulerConfig(input: unknown): PublisherSchedulerConfig {
  const v =
    input && typeof input === "object"
      ? (input as Record<string, unknown>)
      : ({} as Record<string, unknown>);

  const rawRules = Array.isArray(v.rules) ? v.rules : [];
  const rules = rawRules
    .map((row, index) => sanitizeSchedulerRule(row, index))
    .filter((row): row is PublisherSchedulerRule => !!row);

  return {
    enabled: v.enabled === true,
    timezone: String(v.timezone ?? "UTC").trim() || "UTC",
    rules,
  };
}

export const sanitizePublisherConfig = (
  input: unknown,
): PublisherRemoteConfig => {
  const v =
    input && typeof input === "object"
      ? (input as Record<string, unknown>)
      : ({} as Record<string, unknown>);

  const deploymentProfiles = sanitizeDeploymentProfiles(v.deploymentProfiles);
  const defaultDeploymentProfile = sanitizeDeploymentProfileName(
    v.defaultDeploymentProfile,
    deploymentProfiles,
  );
  const deploymentTargetOverride = sanitizeDeploymentProfileName(
    v.deploymentTargetOverride,
    deploymentProfiles,
  );

  const out: PublisherRemoteConfig = {
    ...v,
  };

  out.scheduler = sanitizeSchedulerConfig(v.scheduler);
  out.deploymentProfiles = deploymentProfiles;

  if (defaultDeploymentProfile) {
    out.defaultDeploymentProfile = defaultDeploymentProfile;
  } else {
    delete out.defaultDeploymentProfile;
  }

  if (deploymentTargetOverride) {
    out.deploymentTargetOverride = deploymentTargetOverride;
  } else {
    delete out.deploymentTargetOverride;
  }

  if (
    v.subscriptionType === "PROFESSIONAL" ||
    v.subscriptionType === "AGENCY"
  ) {
    out.subscriptionType = v.subscriptionType;
  } else {
    delete out.subscriptionType;
  }

  return out;
};

export const loadPublisherConfig = async (): Promise<PublisherRemoteConfig> =>
  sanitizePublisherConfig(await getConfig("publisher"));

export const hasPublisherSubscription = (
  config: PublisherRemoteConfig | null | undefined,
): boolean =>
  config?.subscriptionType === "PROFESSIONAL" ||
  config?.subscriptionType === "AGENCY";

export const hasPublisherContentSyncAccess = hasPublisherSubscription;

export const getDefaultPublisherCrawlMode = (
  config: PublisherRemoteConfig | null | undefined,
): PublisherCrawlMode =>
  hasPublisherSubscription(config) ? "incremental" : "full";

export const resolvePublisherCrawlMode = (
  requestedMode: PublisherCrawlMode | null | undefined,
  config: PublisherRemoteConfig | null | undefined,
): PublisherCrawlMode =>
  requestedMode === "incremental" && hasPublisherSubscription(config)
    ? "incremental"
    : "full";

export const resolvePublisherDeploymentProfileId = (
  config: PublisherRemoteConfig | null | undefined,
  requestedProfile: string | null | undefined,
): string => {
  if (!hasPublisherSubscription(config)) {
    return "";
  }

  const deploymentProfiles = config?.deploymentProfiles ?? {};
  for (const candidate of [
    requestedProfile,
    config?.deploymentTargetOverride,
    config?.defaultDeploymentProfile,
  ]) {
    const name = typeof candidate === "string" ? candidate.trim() : "";
    if (name && deploymentProfiles[name]) {
      return name;
    }
  }

  return "";
};

export const resolvePublisherDeploymentProfile = (
  config: PublisherRemoteConfig | null | undefined,
  requestedProfile: string | null | undefined,
): PublisherDeploymentProfile | null => {
  const deploymentProfileId = resolvePublisherDeploymentProfileId(
    config,
    requestedProfile,
  );

  return deploymentProfileId
    ? (config?.deploymentProfiles?.[deploymentProfileId] ?? null)
    : null;
};

const getDefaultState = async (): Promise<State> => {
  const config = await loadPublisherConfig();
  return {
    config,
  };
};

const actions = {
  setConfig: (config: PublisherRemoteConfig) => ({
    type: "SET_CONFIG" as const,
    config,
  }),
};

const selectors = {
  getConfig(state: State) {
    return state.config;
  },
  getState(state: State) {
    return state;
  },
};

const resolvers = {};

export interface State {
  config: PublisherRemoteConfig | null;
}

export type Store = StoreDescriptor;

export type StoreSelectors = {
  getConfig(): PublisherRemoteConfig | null;
  getState(): State;
};

export type StoreActions = {
  setConfig?: typeof actions.setConfig;
};

export const getStoreSelect = (store: Store): StoreSelectors =>
  select(store) as unknown as StoreSelectors;

export const reloadConfig = async (store: Store) => {
  const wpsuite = getWpSuite();
  if (wpsuite?.siteSettings) {
    wpsuite.siteSettings.lastUpdate = Date.now();
  }
  const sanitized = await loadPublisherConfig();
  (dispatch(store) as unknown as StoreActions).setConfig?.(sanitized);
};

export const createStore = async (): Promise<Store> => {
  const DEFAULT_STATE = await getDefaultState();
  const store = createReduxStore("smartcloud/publisher", {
    reducer(
      state = DEFAULT_STATE,
      action: { type: string; config?: PublisherRemoteConfig },
    ) {
      switch (action.type) {
        case "SET_CONFIG":
          return {
            ...state,
            config: action.config ?? null,
          };
      }
      return state;
    },
    actions,
    selectors,
    resolvers,
  });

  register(store);
  return store;
};

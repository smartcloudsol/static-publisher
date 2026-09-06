type ContentSyncRule = {
  id: string;
  command: string;
  enabled?: boolean;
  deploymentProfile?: string;
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

type ContentSyncStatusConfig = {
  sourceOrigin: string;
  targetOrigin: string;
  urlRewriteMode: string;
  s3: { bucket: string; prefix: string };
  cloudFront: { distributionId: string };
  deploymentTargetOverride?: string;
  defaultDeploymentProfile?: string;
  deploymentProfiles?: Record<string, {
    targetOrigin?: string;
    s3?: { bucket?: string; prefix?: string };
    cloudFront?: { distributionId?: string };
    extraReplacements?: Record<string, string>;
  }>;
  scheduler?: { enabled?: boolean; rules?: ContentSyncRule[] };
};

// Keep this browser implementation aligned with exporter/content-sync-contract.ts.
// The contract tests compare against the exporter, including profile resolution.
function stableValue(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(stableValue);
  if (value && typeof value === "object") {
    return Object.fromEntries(
      Object.entries(value as Record<string, unknown>)
        .sort(([left], [right]) => left.localeCompare(right))
        .map(([key, entry]) => [key, stableValue(entry)]),
    );
  }
  return value;
}

async function fingerprint(value: unknown): Promise<string> {
  const bytes = new TextEncoder().encode(JSON.stringify(stableValue(value)));
  const digest = await globalThis.crypto.subtle.digest("SHA-256", bytes);
  return Array.from(new Uint8Array(digest), (byte) =>
    byte.toString(16).padStart(2, "0"),
  ).join("");
}

function sortedUnique(values: string[]): string[] {
  return [...new Set(values.map((value) => value.trim()).filter(Boolean))].sort();
}

function canonicalScope(rule: ContentSyncRule) {
  const postTypes = sortedUnique((rule.postTypes ?? []).map((value) =>
    String(value).toLowerCase().replace(/[^a-z0-9_-]/g, ""),
  ));
  if (!postTypes.length) {
    throw new Error("Content-sync requires at least one public post type.");
  }
  const listingPaths = sortedUnique((rule.listingPaths ?? []).map((value) => {
    const raw = String(value).trim();
    if (!raw) return "";
    const parsed = new URL(raw, "https://content-sync.invalid/");
    if (parsed.origin !== "https://content-sync.invalid" && /^https?:\/\//i.test(raw)) {
      throw new Error("Content-sync listing routes must be same-site paths.");
    }
    const pathname = `/${parsed.pathname.replace(/^\/+|\/+$/g, "")}`;
    return pathname === "/" ? "/" : `${pathname}/`;
  }));
  return {
    postTypes,
    listingPaths,
    includeSubsites: rule.includeSubsites === true,
    includePostTypeArchives: rule.includePostTypeArchives !== false,
    includeTaxonomyArchives: rule.includeTaxonomyArchives !== false,
    includeAuthorArchives: rule.includeAuthorArchives === true,
    includeDateArchives: rule.includeDateArchives === true,
    includePostsPage: rule.includePostsPage !== false,
    includeSitemapChain: rule.includeSitemapChain !== false,
  };
}

function normalizeOrigin(value: string): string {
  const url = new URL(value);
  if (url.protocol !== "http:" && url.protocol !== "https:") {
    throw new Error(`Unsupported source origin protocol: ${url.protocol}`);
  }
  url.hash = "";
  url.search = "";
  url.pathname = url.pathname.replace(/\/+$/, "");
  return url.toString().replace(/\/$/, "");
}

/** Resolve an entitled scheduler's exact runtime scope, using saved configuration. */
export async function currentContentSyncKey(
  config: ContentSyncStatusConfig,
  rule: ContentSyncRule,
): Promise<string> {
  const requestedOverride = String(
    rule.deploymentProfile ?? config.deploymentTargetOverride ?? "",
  ).trim();
  const deploymentProfile = String(
    requestedOverride || config.defaultDeploymentProfile || "",
  ).trim();
  const profile = deploymentProfile
    ? config.deploymentProfiles?.[deploymentProfile]
    : undefined;
  if (deploymentProfile && !profile) {
    throw new Error(`Unknown deployment profile "${deploymentProfile}".`);
  }
  const scopeFingerprint = await fingerprint({
    schemaVersion: 1,
    sourceOrigin: normalizeOrigin(config.sourceOrigin),
    ruleId: rule.id.trim(),
    scope: canonicalScope(rule),
  });
  const s3 = { ...config.s3, ...profile?.s3 };
  const cloudFront = { ...config.cloudFront, ...profile?.cloudFront };
  const targetOrigin = profile?.targetOrigin ?? config.targetOrigin;
  if ([targetOrigin, s3.bucket, s3.prefix, cloudFront.distributionId,
    config.urlRewriteMode].some((value) => typeof value !== "string")) {
    throw new Error("Content-sync requires a valid deployment target configuration.");
  }
  const targetFingerprint = await fingerprint({
    schemaVersion: 1,
    sourceOrigin: config.sourceOrigin,
    targetOrigin,
    deploymentProfile,
    s3Bucket: s3.bucket,
    s3Prefix: s3.prefix,
    cloudFrontDistributionId: cloudFront.distributionId,
    urlRewriteMode: config.urlRewriteMode,
    extraReplacements: profile?.extraReplacements ?? {},
  });
  const hash = await fingerprint({ scopeFingerprint, targetFingerprint });
  return `content-sync:${rule.id}:${hash.slice(0, 32)}`;
}

/** Historical entries must never determine whether the current scope is ready. */
export async function selectCurrentContentSyncStates<
  T extends { ruleId?: string; coalesceKey?: string },
>(config: ContentSyncStatusConfig, states: Record<string, T>): Promise<T[]> {
  if (config.scheduler?.enabled === false) return [];
  const rules = (config.scheduler?.rules ?? []).filter((rule) =>
    rule.command === "content-sync" && rule.enabled !== false,
  );
  const keys = await Promise.all(rules.map((rule) => currentContentSyncKey(config, rule)));
  return keys.flatMap((key, index) => {
    const state = Object.hasOwn(states, key) ? states[key] : undefined;
    if (!state || (state.coalesceKey && state.coalesceKey !== key) ||
      (state.ruleId && state.ruleId !== rules[index].id)) return [];
    return [state];
  });
}

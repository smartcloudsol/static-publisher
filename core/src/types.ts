import type {
  SiteSettings,
  SubscriptionType,
  WpSuiteView,
} from "@smart-cloud/wpsuite-core";

export type PublisherJobCommand =
  | "publish"
  | "crawl"
  | "deploy"
  | "invalidate"
  | "retry-timeouts"
  | "url";

export type PublisherCrawlMode = "full" | "incremental";

export interface PublisherSchedulerRule {
  id: string;
  enabled: boolean;
  command: PublisherJobCommand;
  intervalMinutes: number;
  crawlMode?: PublisherCrawlMode;
  deploymentProfile?: string;
  url?: string;
}

export interface PublisherSchedulerConfig {
  enabled: boolean;
  timezone: string;
  rules: PublisherSchedulerRule[];
}

export interface PublisherS3Config {
  bucket: string;
  prefix: string;
  region: string;
  htmlCacheControl: string;
  assetCacheControl: string;
}

export interface PublisherCloudFrontConfig {
  distributionId: string;
  invalidationPaths: string[];
}

export interface PublisherDeploymentProfile {
  targetOrigin?: string;
  extraReplacements?: Record<string, string>;
  s3?: Partial<PublisherS3Config>;
  cloudFront?: Partial<PublisherCloudFrontConfig>;
}

export type PublisherDeploymentProfiles = Record<
  string,
  PublisherDeploymentProfile
>;

export type PublisherExtraDeploymentTargets = PublisherDeploymentProfiles;

export type PublisherWpSuiteSiteSettings = Omit<SiteSettings, "siteKey">;

export interface PublisherWpSuiteBootstrapInput {
  siteSettings?: Partial<PublisherWpSuiteSiteSettings> | null;
  uploadUrl?: string;
  restUrl?: string;
  nonce?: string;
  view?: WpSuiteView;
  locationHref?: string;
  siteOrigin?: string;
}

export interface PublisherRuntimeWpSuiteConfig {
  apiBase?: string;
  runtimeToken?: string;
  uploadUrl?: string;
  siteSettings?: Partial<PublisherWpSuiteSiteSettings> | null;
  subscriptionType?: SubscriptionType;
}

export interface PublisherRemoteConfig {
  scheduler?: PublisherSchedulerConfig;
  deploymentProfiles?: PublisherDeploymentProfiles;
  defaultDeploymentProfile?: string;
  deploymentTargetOverride?: string;
  subscriptionType?: SubscriptionType;
  [key: string]: unknown;
}

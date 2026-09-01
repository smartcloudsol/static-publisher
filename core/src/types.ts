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
  | "url"
  | "content-sync";

export type PublisherCrawlMode = "full" | "incremental";

export interface PublisherContentSyncScope {
  postTypes: string[];
  listingPaths: string[];
  includeSubsites: boolean;
  includePostTypeArchives: boolean;
  includeTaxonomyArchives: boolean;
  includeAuthorArchives: boolean;
  includeDateArchives: boolean;
  includePostsPage: boolean;
  includeSitemapChain: boolean;
}

export interface PublisherContentProjectionTerm {
  taxonomy: string;
  termId: number;
  url: string | null;
}

export type PublisherContentArchiveKind =
  | "post-type"
  | "taxonomy"
  | "author"
  | "date"
  | "posts-page"
  | "listing";

export interface PublisherContentArchiveProjection {
  kind: PublisherContentArchiveKind;
  url: string;
  blogId?: number;
  postType?: string;
  taxonomy?: string;
  termId?: number;
  authorId?: number;
  year?: number;
  month?: number;
  day?: number;
}

export interface PublisherContentProjection {
  blogId?: number;
  status: string;
  url: string | null;
  authorId: number;
  publishedGmt: string | null;
  modifiedGmt: string | null;
  terms: PublisherContentProjectionTerm[];
  sticky: boolean;
  archives: string[];
  archiveFamilies: PublisherContentArchiveProjection[];
}

export interface PublisherContentChangeEvent {
  sequence: number;
  recordedGmt: string;
  postId: number;
  blogId: number;
  postType: string;
  operation:
    | "publish"
    | "update"
    | "unpublish"
    | "delete"
    | "permalink"
    | "taxonomy"
    | "sticky";
  before: PublisherContentProjection | null;
  after: PublisherContentProjection | null;
  correlationId: string | null;
}

export interface PublisherSchedulerRule {
  id: string;
  enabled: boolean;
  command: PublisherJobCommand;
  intervalMinutes: number;
  crawlMode?: PublisherCrawlMode;
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

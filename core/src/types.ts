export type PublisherJobCommand =
  | "publish"
  | "crawl"
  | "deploy"
  | "invalidate"
  | "retry-timeouts"
  | "url";

export interface PublisherSchedulerRule {
  id: string;
  enabled: boolean;
  command: PublisherJobCommand;
  intervalMinutes: number;
  url?: string;
}

export interface PublisherSchedulerConfig {
  enabled: boolean;
  timezone: string;
  rules: PublisherSchedulerRule[];
}

export interface PublisherRemoteConfig {
  scheduler?: PublisherSchedulerConfig;
  subscriptionType?: string;
  [key: string]: unknown;
}

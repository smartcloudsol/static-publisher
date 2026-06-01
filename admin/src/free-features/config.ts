import { getWpSuite } from "@smart-cloud/wpsuite-core";

export type ProConfigPatch = {
  scheduler?: unknown;
  defaultDeploymentProfile?: string;
  deploymentProfiles?: unknown;
};

export type ProAccessStatus = {
  isLinked: boolean;
  hasSubscription: boolean;
};

export async function getProAccessStatus(): Promise<ProAccessStatus> {
  const wpsuite = getWpSuite();
  const accountId = String(wpsuite?.siteSettings?.accountId ?? "").trim();
  const siteId = String(wpsuite?.siteSettings?.siteId ?? "").trim();
  const siteKey = String(wpsuite?.siteSettings?.siteKey ?? "").trim();
  return {
    isLinked: !!(accountId && siteId && siteKey),
    hasSubscription: wpsuite?.siteSettings?.subscriber === true,
  };
}

export async function loadRemoteProConfig(): Promise<ProConfigPatch | null> {
  return null;
}

export async function saveRemoteProConfig(): Promise<ProConfigPatch | null> {
  return null;
}

import { getWpSuite, type WpSuiteGlobal } from "@smart-cloud/wpsuite-core";
import { createStore, type Store } from "./store";
import type {
  PublisherWpSuiteBootstrapInput,
  PublisherWpSuiteSiteSettings,
} from "./types";

export type PublisherPlugin = {
  restUrl?: string;
  nonce?: string;
  apiBase?: string;
  settings?: unknown;
  view?: string;
};

export const getPublisherPlugin = (): PublisherPlugin | null => {
  if (typeof WpSuite === "undefined") {
    return null;
  }
  return (WpSuite.plugins?.staticPublisher as PublisherPlugin) ?? null;
};

const STRING_SITE_SETTING_KEYS = [
  "accountId",
  "siteId",
  "wpsuiteThemeCss",
  "reCaptchaPublicKey",
] as const;

const BOOLEAN_SITE_SETTING_KEYS = [
  "subscriber",
  "useRecaptchaNet",
  "useRecaptchaEnterprise",
  "renderRecaptchaProvider",
] as const;

type MutablePublisherWpSuiteSiteSettings = PublisherWpSuiteSiteSettings & {
  siteKey?: unknown;
};

function sanitizePublisherWpSuiteSiteSettings(
  input: PublisherWpSuiteBootstrapInput["siteSettings"],
  existing?: PublisherWpSuiteSiteSettings,
): PublisherWpSuiteSiteSettings {
  const source =
    input && typeof input === "object"
      ? (input as Record<string, unknown>)
      : ({} as Record<string, unknown>);
  const next: MutablePublisherWpSuiteSiteSettings = {
    ...(existing ?? {}),
  };

  delete next.siteKey;

  for (const key of STRING_SITE_SETTING_KEYS) {
    if (!Object.hasOwn(source, key)) {
      continue;
    }

    const value = String(source[key] ?? "").trim();
    if (value) {
      next[key] = value;
    } else {
      delete next[key];
    }
  }

  if (Object.hasOwn(source, "lastUpdate")) {
    const value = Number(source.lastUpdate);
    if (Number.isFinite(value) && value > 0) {
      next.lastUpdate = value;
    } else {
      delete next.lastUpdate;
    }
  }

  for (const key of BOOLEAN_SITE_SETTING_KEYS) {
    if (!Object.hasOwn(source, key)) {
      continue;
    }

    if (typeof source[key] === "boolean") {
      next[key] = source[key] as boolean;
    } else {
      delete next[key];
    }
  }

  return next;
}

function applyRuntimeLocation(input: string | undefined): void {
  if (!input || typeof window !== "undefined") {
    return;
  }

  const href = input.trim();
  if (!href) {
    return;
  }

  try {
    const url = new URL(href.includes("://") ? href : `https://${href}`);
    const runtimeGlobal = globalThis as typeof globalThis & {
      location?: Location;
    };
    const currentLocation = runtimeGlobal.location;

    if (currentLocation?.hostname) {
      return;
    }

    Object.defineProperty(runtimeGlobal, "location", {
      value: url as unknown as Location,
      configurable: true,
      writable: true,
    });
  } catch {
    // Ignore malformed non-browser location bootstrap values.
  }
}

export const bootstrapPublisherWpSuite = (
  input: PublisherWpSuiteBootstrapInput = {},
): WpSuiteGlobal => {
  const current = getWpSuite();
  const siteSettings = sanitizePublisherWpSuiteSiteSettings(
    input.siteSettings,
    current?.siteSettings,
  );

  const next: WpSuiteGlobal = {
    siteSettings,
    nonce:
      typeof input.nonce === "string" ? input.nonce : (current?.nonce ?? ""),
    restUrl:
      typeof input.restUrl === "string"
        ? input.restUrl
        : (current?.restUrl ?? ""),
    uploadUrl:
      typeof input.uploadUrl === "string"
        ? input.uploadUrl
        : (current?.uploadUrl ?? ""),
    view: input.view ?? current?.view ?? "settings",
    plugins: current?.plugins ?? {},
    ...(current?.events ? { events: current.events } : {}),
  };

  globalThis.WpSuite = next;
  applyRuntimeLocation(input.locationHref ?? input.siteOrigin);

  return next;
};

let storePromise: Promise<Store> | null = null;

export const getStore = (): Promise<Store> => {
  if (!storePromise) {
    storePromise = createStore();
  }
  return storePromise;
};

export const waitForPublisherReady = (): Promise<void> => Promise.resolve();

import { getConfig, getWpSuite } from "@smart-cloud/wpsuite-core";
import {
  createReduxStore,
  dispatch,
  register,
  select,
  type StoreDescriptor,
} from "@wordpress/data";
import type {
  PublisherRemoteConfig,
  PublisherSchedulerConfig,
  PublisherSchedulerRule,
} from "./types";

function sanitizeSchedulerRule(input: unknown, index: number): PublisherSchedulerRule | null {
  if (!input || typeof input !== "object") {
    return null;
  }

  const row = input as Record<string, unknown>;
  const command = String(row.command ?? "").trim() as PublisherSchedulerRule["command"];
  if (
    command !== "publish" &&
    command !== "crawl" &&
    command !== "deploy" &&
    command !== "invalidate" &&
    command !== "retry-timeouts" &&
    command !== "url"
  ) {
    return null;
  }

  const intervalMinutes = Number.parseInt(String(row.intervalMinutes ?? "0"), 10);
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

  return {
    id,
    enabled: row.enabled !== false,
    command,
    intervalMinutes,
    ...(url ? { url } : {}),
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

export const sanitizePublisherConfig = (input: unknown): PublisherRemoteConfig => {
  const v =
    input && typeof input === "object"
      ? (input as Record<string, unknown>)
      : ({} as Record<string, unknown>);

  const out: PublisherRemoteConfig = {
    ...v,
  };

  out.scheduler = sanitizeSchedulerConfig(v.scheduler);

  if (typeof v.subscriptionType === "string") {
    out.subscriptionType = v.subscriptionType;
  }

  return out;
};

const getDefaultState = async (): Promise<State> => {
  const config = sanitizePublisherConfig(await getConfig("publisher"));
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
  const cfg = await getConfig("publisher");
  const sanitized = sanitizePublisherConfig(cfg);
  (dispatch(store) as unknown as StoreActions).setConfig?.(sanitized);
};

export const createStore = async (): Promise<Store> => {
  const DEFAULT_STATE = await getDefaultState();
  const store = createReduxStore("smartcloud/publisher", {
    reducer(state = DEFAULT_STATE, action: { type: string; config?: PublisherRemoteConfig }) {
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

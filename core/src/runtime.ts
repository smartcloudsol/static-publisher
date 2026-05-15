import { createStore, type Store } from "./store";

export type PublisherPlugin = {
  restUrl?: string;
  nonce?: string;
  settings?: unknown;
  view?: string;
};

export const getPublisherPlugin = (): PublisherPlugin | null => {
  if (typeof WpSuite === "undefined") {
    return null;
  }
  return (WpSuite.plugins?.staticPublisher as PublisherPlugin) ?? null;
};

let storePromise: Promise<Store> | null = null;

export const getStore = (): Promise<Store> => {
  if (!storePromise) {
    storePromise = createStore();
  }
  return storePromise;
};

export const waitForPublisherReady = (): Promise<void> => Promise.resolve();

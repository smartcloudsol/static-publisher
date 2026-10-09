export const PUBLISHER_ADMIN_PAGES = ["jobs", "configuration", "audit", "scheduler", "extraTargets"] as const;
export type PublisherAdminPage = typeof PUBLISHER_ADMIN_PAGES[number];

export function requestedPublisherAdminPage(search: string): PublisherAdminPage | undefined {
  const requested = new URLSearchParams(search).get("section");
  return PUBLISHER_ADMIN_PAGES.find(page => page === requested);
}

/** Explicit deep links survive asynchronous hydration; ordinary visits retain the saved-config default. */
export function resolvePublisherAdminPage(search: string, hasSavedConfig = false): PublisherAdminPage {
  return requestedPublisherAdminPage(search) ?? (hasSavedConfig ? "jobs" : "configuration");
}

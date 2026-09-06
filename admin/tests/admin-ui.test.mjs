import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

// Browser tooling is already part of the exporter workspace. No HTTP server or
// live WordPress connection is needed: all fixture requests stay in Playwright.
const requireExporter = createRequire(new URL('../../exporter/package.json', import.meta.url));
const { build } = requireExporter('esbuild');
const { chromium } = requireExporter('playwright');
const ts = requireExporter('typescript');
const contractSource = readFileSync(new URL('../../exporter/src/content-sync-contract.ts', import.meta.url), 'utf8');
const contractCode = ts.transpileModule(contractSource, {
  compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 },
}).outputText;
const { canonicalizeContentSyncScope, contentSyncScopeFingerprint, contentSyncTargetFingerprint, sha256Fingerprint } =
  await import(`data:text/javascript;base64,${Buffer.from(contractCode).toString('base64')}`);
const adminDir = fileURLToPath(new URL('..', import.meta.url));
const mocks = {
  '@smart-cloud/publisher-core': `
    export const getStore = async () => ({});
    export const reloadConfig = async () => {};
    export const getStoreSelect = () => ({getConfig: () => window.fixtureConfig});
  `,
  '@smart-cloud/wpsuite-core': 'export const getWpSuite = () => window.WpSuite;',
  '@wordpress/i18n': 'export const __ = (value) => value;',
  './paid-features/config': `
    export async function getProAccessStatus() {
      window.accessRequests = (window.accessRequests || 0) + 1;
      if (window.holdAccess) await new Promise(resolve => window.releaseAccess = resolve);
      return {isLinked: true, hasSubscription: true};
    }
    export const loadRemoteProConfig = async () => window.fixtureConfig;
    export const saveRemoteProConfig = async patch => Object.assign(window.fixtureConfig, patch);
  `,
};
const bundle = await build({
  absWorkingDir: adminDir,
  stdin: {
    contents: "import '@mantine/core/styles.css'; import '@mantine/notifications/styles.css'; import './src/index.tsx';",
    resolveDir: adminDir, loader: 'tsx',
  },
  bundle: true, write: false, outdir: '/tmp/static-publisher-admin-ui-test',
  jsx: 'automatic', define: { __WPSUITE_PREMIUM__: 'true', 'process.env.NODE_ENV': '"test"' },
  plugins: [{ name: 'fixture-services', setup(plugin) {
    plugin.onResolve({ filter: /./ }, ({ path }) => Object.hasOwn(mocks, path)
      ? { path, namespace: 'fixture' } : undefined);
    plugin.onLoad({ filter: /./, namespace: 'fixture' }, ({ path }) => ({ contents: mocks[path], loader: 'js' }));
  } }],
});
const js = bundle.outputFiles.find(file => file.path.endsWith('.js')).text;
const css = bundle.outputFiles.find(file => file.path.endsWith('.css')).text;
const browser = await chromium.launch({ headless: true });

const longBaselineReason = 'The installed WordPress release changed after the last verified content-sync baseline. Run a successful full or incremental publish to establish a new baseline.';
const longConsumerId = 'content-sync:64a976a57b30a414fbbd314318b474899adb974efdd5fa3f8af1c58832d67e40';
const distinctRetryError = 'The content-sync request failed after retrying https://source.example.test/wp-json/smartcloud-static-publisher/v1/content-sync/acknowledgement because the connection timed out.';

function runtimeFixture(config, currentStatus, cardPresentation) {
  const targetFingerprint = contentSyncTargetFingerprint({
    sourceOrigin: config.sourceOrigin, targetOrigin: config.targetOrigin, deploymentProfile: '',
    s3Bucket: config.s3.bucket, s3Prefix: config.s3.prefix,
    cloudFrontDistributionId: config.cloudFront.distributionId,
    urlRewriteMode: config.urlRewriteMode, extraReplacements: {},
  });
  const savedRule = config.scheduler.rules[0];
  const rules = {};
  const entries = {};
  // Use the exporter's real contract functions to generate independent fixture
  // keys; a typo in the browser selector must not reproduce itself in tests.
  for (const [rule, status, consumerId] of [
    [{ ...savedRule, postTypes: ['post'] }, currentStatus === 'ready' ? 'required' : 'ready', 'historical-scope'],
    [savedRule, currentStatus, 'current-saved-scope'],
  ]) {
    const scopeFingerprint = contentSyncScopeFingerprint(config.sourceOrigin, rule.id, canonicalizeContentSyncScope(rule));
    const coalesceKey = `content-sync:${rule.id}:${sha256Fingerprint({ scopeFingerprint, targetFingerprint }).slice(0, 32)}`;
    rules[coalesceKey] = {
      ruleId: rule.id, coalesceKey, consumerId, committedSequence: 5, observedHeadSequence: 5,
      baselineStatus: status, baselineReason: status === 'required' ? `${consumerId} needs a baseline` : null,
    };
    if (status === 'ready') entries[coalesceKey] = {
      ruleId: rule.id, coalesceKey, consumerId, verifiedAt: '2026-09-06T15:00:00Z',
    };
    if (consumerId === 'current-saved-scope' && cardPresentation) {
      Object.assign(rules[coalesceKey], {
        consumerId: longConsumerId,
        baselineReason: longBaselineReason,
        retryAttempt: 12,
        nextRetryAt: '2026-09-06T17:45:12.123Z',
        lastError: cardPresentation === 'duplicate' ? longBaselineReason : distinctRetryError,
      });
      // A stale baseline remains in the store and still shows when it was
      // verified; this is the real overflowing card shape from the operator UI.
      entries[coalesceKey] = {
        ruleId: rule.id, coalesceKey, consumerId: longConsumerId,
        verifiedAt: '2026-09-06T15:00:00.123Z',
      };
    }
  }
  return { state: { rules }, baseline: { entries } };
}

async function openFixture(viewport, holdAccess = false, currentBaselineStatus = null, cardPresentation = null) {
  const page = await browser.newPage({ viewport });
  page.setDefaultTimeout(5000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.addInitScript(({ holdAccess }) => {
    window.holdAccess = holdAccess;
    window.fixtureConfig = {
      sourceOrigin: 'https://source.example.test', targetOrigin: '.',
      ignoreHttpsErrors: false, urlRewriteMode: 'relative', exporterDir: '',
      noJavaScriptRenderPathPrefixes: [], seedPaths: ['/'], generated404RequestPath: '',
      sitemapPaths: ['/sitemap.xml'], allowedAssetHosts: [], assetPathPrefixes: [],
      blockedPathPrefixes: [], blockedSearchFragments: [], extraReplacements: {}, postCrawlCopyMap: {},
      outputDir: 'export', logDir: 'logs', concurrency: 1, assetDownloadConcurrency: 1,
      rewriteConcurrency: 1, maxPages: 0, verbose: false, logLevel: 'info', s3SyncMode: 'sdk-upload-only',
      s3: { bucket: 'fixture-bucket', prefix: '', region: 'eu-central-1', htmlCacheControl: '', assetCacheControl: '' },
      cloudFront: { distributionId: '', invalidationPaths: ['/*'] },
      defaultDeploymentProfile: '', deploymentProfiles: {},
      scheduler: { enabled: true, timezone: 'UTC', rules: [{
        id: 'hourly-content-sync', command: 'content-sync', enabled: true,
        intervalMinutes: 60, postTypes: ['page', 'post'],
      }] },
    };
    window.WpSuite = {
      siteSettings: { accountId: 'fixture', siteId: 'fixture', siteKey: 'fixture' },
      restUrl: '/suite', nonce: 'fixture',
      plugins: { staticPublisher: { restUrl: '/publisher', nonce: 'fixture', settings: window.fixtureConfig } },
    };
  }, { holdAccess });
  await page.route('**/*', async route => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/') return route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><head><link rel="stylesheet" href="/app.css"></head><body style="margin:0"><div id="root"></div><script src="/app.js"></script></body></html>' });
    if (path === '/app.js') return route.fulfill({ contentType: 'text/javascript', body: js });
    if (path === '/app.css') return route.fulfill({ contentType: 'text/css', body: css });
    const config = await page.evaluate(() => window.fixtureConfig);
    let json;
    if (path === '/publisher/state') json = {
      config, hasSavedConfiguration: true, availableLogs: [], queueItems: [], queueLength: 0,
      currentRun: null, lastRun: null, schedulerState: {}, lockActive: false,
      contentSync: currentBaselineStatus ? runtimeFixture(config, currentBaselineStatus, cardPresentation) : null,
    };
    else if (path.includes('post-types')) json = { items: [
      { slug: 'page', label: 'Page', hasArchive: false, hierarchical: true },
      { slug: 'post', label: 'Post', hasArchive: false, hierarchical: false },
    ] };
    else if (path.includes('audit')) json = {
      page: 1, total: 3, totalPages: 1,
      items: ['idle', 'retry-wait', 'baseline-required'].map((status, i) => ({
        id: String(i), occurredAt: '2026-09-06T07:27:11.807Z', eventType: 'content-sync-baseline-required',
        status, jobId: 'fixture-publish-job', command: 'publish', actor: 'queue-runner',
        message: 'No verified normal-release baseline exists for this rule scope.',
        details: { ruleId: 'hourly-content-sync', headSequence: 0 },
      })),
    };
    else if (path === '/suite/update-site-settings') json = {};
    else return route.fulfill({ status: 404, json: { message: `Unexpected fixture request: ${path}` } });
    return route.fulfill({ json });
  });
  await page.goto('https://publisher.example.test/');
  try {
    await page.getByRole('textbox', { name: /^Crawl mode/ }).waitFor();
  } catch (error) {
    throw new Error(`${error.message}\nBrowser errors: ${errors.join('; ')}\n${await page.locator('body').innerText()}`);
  }
  return { page, errors };
}

async function choose(page, label, value) {
  await page.getByRole('textbox', { name: new RegExp(`^${label}`) }).click();
  await page.getByRole('option', { name: value, exact: true }).click();
}

try {
  for (const [name, viewport] of [['desktop', { width: 1440, height: 1000 }], ['mobile', { width: 390, height: 844 }]]) {
    await test(`${name}: audit scrolls with complete statuses; scheduler actions stay reachable`, async () => {
      const { page, errors } = await openFixture(viewport);
      try {
        await page.getByText('Audit Logs', { exact: true }).click();
        const audit = page.getByRole('region', { name: 'Audit Log', exact: true });
        await audit.locator('tbody tr').first().waitFor();
        const geometry = await audit.evaluate(element => ({
          width: element.clientWidth, scrollWidth: element.scrollWidth,
          overflow: getComputedStyle(element).overflowX,
        }));
        assert.ok(geometry.scrollWidth > geometry.width, JSON.stringify(geometry));
        assert.equal(geometry.overflow, 'auto');
        for (const status of ['idle', 'retry-wait', 'baseline-required']) {
          const badge = audit.locator('.mantine-Badge-label', { hasText: status });
          assert.equal(await badge.textContent(), status);
          assert.equal(await badge.evaluate(element => getComputedStyle(element).textOverflow), 'clip');
          assert.ok(await badge.evaluate(element => element.scrollWidth <= element.clientWidth + 1), `${status} must fit its badge`);
        }
        await audit.evaluate(element => { element.scrollLeft = element.scrollWidth; });
        assert.ok(await audit.evaluate(element => element.scrollLeft > 0));
        await page.getByText('Scheduler Settings', { exact: true }).click();
        const scheduler = page.getByRole('region', { name: 'Scheduler rules', exact: true });
        await scheduler.waitFor();
        assert.ok(await scheduler.evaluate(element => element.scrollWidth > element.clientWidth));
        await scheduler.evaluate(element => { element.scrollLeft = element.scrollWidth; });
        const edit = scheduler.locator('tbody tr td:last-child button').first();
        await edit.click();
        await page.getByRole('dialog', { name: 'Edit scheduler rule' }).waitFor();
        assert.equal(await page.getByLabel('Rule ID', { exact: true }).inputValue(), 'hourly-content-sync');
        assert.deepEqual(errors, []);
      } finally { await page.close(); }
    });
  }

  await test('delayed paid access updates an untouched crawl default to incremental', async () => {
    const { page, errors } = await openFixture({ width: 1440, height: 1000 }, true);
    try {
      assert.equal(await page.getByRole('textbox', { name: /^Crawl mode/ }).inputValue(), 'full');
      await page.waitForFunction(() => typeof window.releaseAccess === 'function');
      await page.evaluate(() => { window.holdAccess = false; window.releaseAccess(); });
      await page.waitForFunction(() => document.querySelector('input[value="incremental"]'));
      assert.equal(await page.getByRole('textbox', { name: /^Crawl mode/ }).inputValue(), 'incremental');
      assert.deepEqual(errors, []);
    } finally { await page.close(); }
  });

  await test('explicit full survives delayed access, PRO save refresh, and command changes', async () => {
    const { page, errors } = await openFixture({ width: 1440, height: 1000 }, true);
    try {
      await choose(page, 'Crawl mode', 'incremental');
      await choose(page, 'Crawl mode', 'full');
      await page.waitForFunction(() => typeof window.releaseAccess === 'function');
      await page.evaluate(() => { window.holdAccess = false; window.releaseAccess(); });
      await page.getByText('Scheduler Settings', { exact: true }).click();
      await page.getByRole('button', { name: 'Save PRO Scheduler Settings', exact: true }).click();
      await page.waitForFunction(() => window.accessRequests >= 2);
      await page.getByText('Jobs', { exact: true }).click();
      assert.equal(await page.getByRole('textbox', { name: /^Crawl mode/ }).inputValue(), 'full');
      await choose(page, 'Command', 'deploy');
      await choose(page, 'Command', 'publish');
      assert.equal(await page.getByRole('textbox', { name: /^Crawl mode/ }).inputValue(), 'full');
      assert.deepEqual(errors, []);
    } finally { await page.close(); }
  });

  for (const status of ['ready', 'required']) {
    await test(`baseline status uses saved scope (${status}), ignoring historical and unsaved scopes`, async () => {
      const { page, errors } = await openFixture({ width: 1440, height: 1000 }, false, status);
      try {
        await page.getByText('Scheduler Settings', { exact: true }).click();
        await page.getByText('current-saved-scope', { exact: true }).waitFor();
        assert.equal(await page.getByText('historical-scope', { exact: true }).count(), 0);
        assert.equal(await page.getByText(status === 'ready' ? 'baseline ready' : 'baseline stale', { exact: true }).count(), 1);
        await page.getByText('Jobs', { exact: true }).click();
        assert.equal(await page.getByText('New baseline required', { exact: true }).count(), status === 'required' ? 1 : 0);
        await page.getByText('Scheduler Settings', { exact: true }).click();
        const scheduler = page.getByRole('region', { name: 'Scheduler rules', exact: true });
        await scheduler.locator('tbody tr td:last-child button').first().click();
        const modal = page.getByRole('dialog', { name: 'Edit scheduler rule' });
        await modal.getByRole('textbox', { name: 'Public post types' }).click();
        await page.getByRole('option', { name: 'Page (page)', exact: true }).click();
        await modal.getByRole('textbox', { name: 'Public post types' }).press('Escape');
        await modal.getByRole('button', { name: 'Save rule', exact: true }).click();
        await modal.waitFor({ state: 'hidden' });
        assert.match(await scheduler.locator('tbody tr td').nth(5).innerText(), /^post;/);
        // Save rule updates only the editor draft; until the PRO save succeeds,
        // operational state must continue to describe the persisted page+post scope.
        await page.getByText('current-saved-scope', { exact: true }).waitFor();
        assert.equal(await page.getByText('historical-scope', { exact: true }).count(), 0);
        assert.equal(await page.getByText(status === 'ready' ? 'baseline ready' : 'baseline stale', { exact: true }).count(), 1);
        await page.getByText('Jobs', { exact: true }).click();
        assert.equal(await page.getByText('New baseline required', { exact: true }).count(), status === 'required' ? 1 : 0);
        assert.deepEqual(errors, []);
      } finally { await page.close(); }
    });
  }

  for (const width of [320, 390, 1440]) {
    for (const errorKind of ['duplicate', 'distinct']) {
      await test(`${width}px baseline card wraps complete status and ${errorKind} errors without clipping`, async () => {
        const { page, errors } = await openFixture({ width, height: 1000 }, false, 'required', errorKind);
        try {
          await page.getByText('Scheduler Settings', { exact: true }).click();
          const card = page.locator('.sp-content-sync-rule');
          await card.getByText(longConsumerId, { exact: true }).waitFor();
          assert.equal(await card.count(), 1);
          assert.equal(await card.getByText(longBaselineReason, { exact: true }).count(), 1);
          assert.equal(await card.getByText(distinctRetryError, { exact: true }).count(), errorKind === 'distinct' ? 1 : 0);
          await card.getByText('Baseline verified: 2026-09-06T15:00:00.123Z', { exact: true }).waitFor();
          await card.getByText('Retry attempt: 12', { exact: true }).waitFor();
          await card.getByText('Next retry: 2026-09-06T17:45:12.123Z', { exact: true }).waitFor();
          const badge = card.locator('.mantine-Badge-label').filter({ hasText: /^baseline stale$/ });
          assert.equal(await badge.textContent(), 'baseline stale');
          assert.ok(await badge.evaluate(element => element.scrollWidth <= element.clientWidth + 1), 'Complete baseline stale label must fit its badge');
          await card.screenshot({ path: `/tmp/static-publisher-baseline-${width}-${errorKind}.png` });
          const geometry = await card.evaluate(element => {
            const box = element.getBoundingClientRect();
            const styles = getComputedStyle(element);
            const left = box.left + parseFloat(styles.borderLeftWidth) + parseFloat(styles.paddingLeft);
            const right = box.right - parseFloat(styles.borderRightWidth) - parseFloat(styles.paddingRight);
            const outside = [];
            for (const child of element.querySelectorAll('*')) {
              const rect = child.getBoundingClientRect();
              if (rect.width && (rect.left < left - 1 || rect.right > right + 1)) {
                outside.push({ type: child.tagName, text: child.textContent.slice(0, 90), left: rect.left, right: rect.right });
              }
            }
            const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
            for (let node = walker.nextNode(); node; node = walker.nextNode()) {
              if (!node.textContent.trim()) continue;
              const range = document.createRange();
              range.selectNodeContents(node);
              for (const rect of range.getClientRects()) {
                if (rect.left < left - 1 || rect.right > right + 1) {
                  outside.push({ type: 'text', text: node.textContent.slice(0, 90), left: rect.left, right: rect.right });
                }
              }
            }
            return { outside, width: element.clientWidth, scrollWidth: element.scrollWidth };
          });
          assert.deepEqual(geometry.outside, [], `All card descendants and text must fit the content rail (${width}px)`);
          assert.ok(geometry.scrollWidth <= geometry.width + 1, 'The baseline card must not overflow horizontally');
          assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'The page must not overflow horizontally');
          assert.deepEqual(errors, []);
        } finally { await page.close(); }
      });
    }
  }
} finally { await browser.close(); }

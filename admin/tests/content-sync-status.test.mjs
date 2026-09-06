import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';

async function loadModule(path) {
  const source = await readFile(new URL(path, import.meta.url), 'utf8');
  const { outputText } = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 },
  });
  return import(`data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`);
}

const { currentContentSyncKey, selectCurrentContentSyncStates } = await loadModule('../src/content-sync-status.ts');
const {
  canonicalizeContentSyncScope, contentSyncScopeFingerprint,
  contentSyncTargetFingerprint, sha256Fingerprint,
} = await loadModule('../../exporter/src/content-sync-contract.ts');
const { resolveDeploymentTarget } = await loadModule('../../exporter/src/deployment-target.ts');

function config(overrides = {}) {
  return {
    subscriptionType: 'PROFESSIONAL',
    sourceOrigin: 'https://dev.example.test/',
    targetOrigin: 'https://www.example.test',
    urlRewriteMode: 'absolute',
    s3: { bucket: 'publisher-target', prefix: 'prod/www/' },
    cloudFront: { distributionId: 'BASE' },
    // The runtime target hash uses profile replacements, not this base map.
    extraReplacements: { 'base-only': 'ignored' },
    scheduler: { enabled: true, rules: [{ id: 'hourly', command: 'content-sync', postTypes: ['post'] }] },
    ...overrides,
  };
}

function exporterKey(config, rule) {
  const scope = canonicalizeContentSyncScope(rule);
  const scopeFingerprint = contentSyncScopeFingerprint(config.sourceOrigin, rule.id, scope);
  const deployment = resolveDeploymentTarget(config, rule.deploymentProfile);
  const targetFingerprint = contentSyncTargetFingerprint({
    sourceOrigin: config.sourceOrigin,
    targetOrigin: deployment.config.targetOrigin,
    deploymentProfile: deployment.name || '',
    s3Bucket: deployment.config.s3.bucket,
    s3Prefix: deployment.config.s3.prefix,
    cloudFrontDistributionId: deployment.config.cloudFront.distributionId,
    urlRewriteMode: deployment.config.urlRewriteMode,
    extraReplacements: deployment.profile?.extraReplacements ?? {},
  });
  return `content-sync:${rule.id}:${sha256Fingerprint({ scopeFingerprint, targetFingerprint }).slice(0, 32)}`;
}

const profiles = {
  production: {
    targetOrigin: 'https://production.example.test',
    s3: { bucket: 'production', prefix: 'public/' },
    cloudFront: { distributionId: 'PRODUCTION' },
    extraReplacements: { zebra: 'last', alpha: 'first' },
  },
  preview: { s3: { prefix: 'preview/' } },
};

for (const [label, ruleChanges, configChanges] of [
  ['post defaults', {}, {}],
  ['page defaults', { postTypes: ['page'] }, {}],
  ['canonical CPT and listing routes', {
    postTypes: ['wps_solution', ' Page ', 'POST', 'page'],
    listingPaths: ['/insights', '/insights/?ignored=yes#hash', '/', ''],
    includeSubsites: true, includePostTypeArchives: false,
    includeTaxonomyArchives: false, includeAuthorArchives: true,
    includeDateArchives: true, includePostsPage: false, includeSitemapChain: false,
  }, { sourceOrigin: 'https://dev.example.test/blog///?query=x#hash' }],
  ['default profile', {}, { defaultDeploymentProfile: 'production', deploymentProfiles: profiles }],
  ['explicit profile overrides default', { deploymentProfile: ' preview ' }, {
    defaultDeploymentProfile: 'production', deploymentProfiles: profiles,
  }],
  ['empty rule profile uses default', { deploymentProfile: '' }, {
    defaultDeploymentProfile: 'production', deploymentProfiles: profiles,
  }],
  ['deployment override', {}, { deploymentTargetOverride: 'production', deploymentProfiles: profiles }],
  ['empty rule profile suppresses deployment override', { deploymentProfile: '' }, {
    deploymentTargetOverride: 'production', deploymentProfiles: profiles,
  }],
]) {
  test(`browser key matches exporter: ${label}`, async () => {
    const saved = config(configChanges);
    const rule = { ...saved.scheduler.rules[0], ...ruleChanges };
    assert.equal(await currentContentSyncKey(saved, rule), exporterKey(saved, rule));
  });
}

for (const currentRequired of [true, false]) {
  test(`current scope ${currentRequired ? 'required' : 'ready'} wins over historical scope`, async () => {
    const saved = config();
    const currentRule = { ...saved.scheduler.rules[0], postTypes: ['post', 'page'] };
    const oldKey = exporterKey(saved, saved.scheduler.rules[0]);
    saved.scheduler.rules = [currentRule];
    const key = exporterKey(saved, currentRule);
    const current = { ruleId: 'hourly', coalesceKey: key, baselineRequired: currentRequired };
    const old = { ruleId: 'hourly', coalesceKey: oldKey, baselineRequired: !currentRequired };
    for (const states of [{ [oldKey]: old, [key]: current }, { [key]: current, [oldKey]: old }]) {
      assert.deepEqual(await selectCurrentContentSyncStates(saved, states), [current]);
    }
    assert.deepEqual(await selectCurrentContentSyncStates(saved, { [oldKey]: old }), []);
  });
}

test('removed, disabled, and other-command rules do not surface historical states', async () => {
  const saved = config();
  const key = exporterKey(saved, saved.scheduler.rules[0]);
  const states = { [key]: { ruleId: 'hourly', coalesceKey: key, baselineRequired: true } };
  for (const rules of [[], [{ ...saved.scheduler.rules[0], enabled: false }],
    [{ ...saved.scheduler.rules[0], command: 'publish' }]]) {
    assert.deepEqual(await selectCurrentContentSyncStates({ ...saved, scheduler: { rules } }, states), []);
  }
  assert.deepEqual(await selectCurrentContentSyncStates({
    ...saved, scheduler: { ...saved.scheduler, enabled: false },
  }, states), []);
});

test('missing or invalid target/scope never falls back to another record', async () => {
  const saved = config();
  const key = exporterKey(saved, saved.scheduler.rules[0]);
  const states = { [key]: { ruleId: 'hourly', coalesceKey: key, baselineRequired: false } };
  for (const invalid of [
    { ...saved, defaultDeploymentProfile: 'missing' },
    { ...saved, sourceOrigin: 'not a URL' },
    { ...saved, sourceOrigin: 'file:///tmp/site' },
    { ...saved, s3: {} },
    { ...saved, scheduler: { rules: [{ ...saved.scheduler.rules[0], postTypes: [] }] } },
    { ...saved, scheduler: { rules: [{ ...saved.scheduler.rules[0], listingPaths: ['https://other.test/'] }] } },
    { ...saved, scheduler: { rules: [{ ...saved.scheduler.rules[0], deploymentProfile: 'missing' }] } },
  ]) {
    await assert.rejects(selectCurrentContentSyncStates(invalid, states));
  }
});

test('conflicting runtime identity is excluded even under the current dictionary key', async () => {
  const saved = config();
  const key = exporterKey(saved, saved.scheduler.rules[0]);
  for (const state of [{ ruleId: 'another-rule', coalesceKey: key },
    { ruleId: 'hourly', coalesceKey: 'another-key' }]) {
    assert.deepEqual(await selectCurrentContentSyncStates(saved, { [key]: state }), []);
  }
});

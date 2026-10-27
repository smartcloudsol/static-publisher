import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

const source = readFileSync(new URL('../src/paid-features/config.ts', import.meta.url), 'utf8');
const { outputText } = ts.transpileModule(source, {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
});

const transportSource = readFileSync(new URL('../../../common/wpsuite-core/src/site-configuration.ts', import.meta.url), 'utf8');
const transportOutput = ts.transpileModule(transportSource, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
const profileSource = readFileSync(new URL('../../../common/wpsuite-core/src/backend-profile.ts', import.meta.url), 'utf8');
const profileOutput = ts.transpileModule(profileSource, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
const profile = {};
new Function('exports', profileOutput)(profile);

function loadConfig(fetch, initialConnection) {
  let connection = initialConnection ?? { accountId: 'account', siteId: 'site', siteKey: 'test-site-key' };
  const transport = {};
  new Function('require', 'exports', transportOutput)((name) => { assert.equal(name, './backend-profile.ts'); return profile; }, transport);
  const exports = {};
  const imports = {
    '@smart-cloud/wpsuite-core': {
      getBackendProfileContext: profile.getBackendProfileContext,
      resolveBackendProfileLegacyTargets: profile.resolveBackendProfileLegacyTargets,
      getConfig: () => { throw new Error('Cached plugin config must not be used for writes'); },
      getWpSuite: () => ({ siteSettings: connection }),
      resolveWorkspaceId: (identity) => identity?.workspaceId ?? identity?.accountId,
      getSitePluginSettings: (target, fetcher) => transport.getSitePluginSettings(target, fetcher),
      putSitePluginSettings: (target, settings) => transport.putSitePluginSettings(target, settings, fetch),
    },
    '@smart-cloud/publisher-core': {
      getPublisherPlugin: () => ({ apiBase: 'https://settings.example.test/' }),
      PLUGIN_KEY: 'publisher',
    },
  };
  const require = (name) => {
    assert.ok(Object.hasOwn(imports, name), `Unexpected import: ${name}`);
    return imports[name];
  };
  new Function('require', 'exports', 'fetch', outputText)(require, exports, fetch);
  return { ...exports, setConnection: (value) => { connection = value; } };
}

const settingsUrl = 'https://settings.example.test/account/account/site/site/settings';

for (const [name, saved, patch] of [
  ['saving profiles retains the scheduler including page', {
    scheduler: { rules: [{ id: 'hourly', postTypes: ['page', 'post'] }] },
    deploymentProfiles: [{ id: 'old' }],
    defaultDeploymentProfile: 'old',
    anotherSetting: { enabled: true },
  }, {
    deploymentProfiles: [{ id: 'new' }], defaultDeploymentProfile: 'new',
  }],
  ['saving scheduler retains deployment profiles', {
    scheduler: { rules: [] },
    deploymentProfiles: [{ id: 'production', bucket: 'test-bucket' }],
    defaultDeploymentProfile: 'production',
    anotherSetting: { enabled: true },
  }, {
    scheduler: { rules: [{ id: 'hourly', postTypes: ['page'] }] },
  }],
]) {
  test(name, async () => {
    const calls = [];
    const expected = { ...saved, ...patch };
    const { saveRemoteProConfig } = loadConfig(async (url, options) => {
      calls.push({ url, options });
      assert.equal(url, settingsUrl);
      assert.equal(options.headers['X-Site-Key'], 'test-site-key');
      assert.equal(options.headers['X-Plugin'], 'publisher');
      if (calls.length === 1) {
        assert.equal(options.method, 'GET');
        assert.equal(options.cache, 'no-store');
        return Response.json({ settings: saved });
      }
      assert.equal(options.method, 'PUT');
      assert.equal(options.headers['Content-Type'], 'application/json');
      assert.deepEqual(JSON.parse(options.body), { settings: expected });
      return Response.json({ settings: expected });
    });
    assert.deepEqual(await saveRemoteProConfig(patch), {
      scheduler: expected.scheduler,
      defaultDeploymentProfile: expected.defaultDeploymentProfile,
      deploymentProfiles: expected.deploymentProfiles,
    });
    assert.equal(calls.length, 2);
  });
}

for (const [name, response] of [
  ['network failure', () => { throw new Error('Network unavailable'); }],
  ['forbidden response', () => new Response('Forbidden', { status: 403 })],
  ['invalid JSON', () => new Response('{')],
  ...[null, [], {}, { settings: null }, { settings: [] }, { settings: 'invalid' }]
    .map((data) => [`malformed settings ${JSON.stringify(data)}`, () => Response.json(data)]),
]) {
  test(`GET ${name} blocks PUT`, async () => {
    const methods = [];
    const { saveRemoteProConfig } = loadConfig(async (_url, options) => {
      methods.push(options.method);
      return response();
    });
    await assert.rejects(saveRemoteProConfig({ scheduler: { rules: [] } }));
    assert.deepEqual(methods, ['GET']);
  });
}

for (const [name, response] of [
  ['HTTP failure', () => new Response('Update failed', { status: 500 })],
  ['network failure', () => { throw new Error('Network unavailable'); }],
  ['invalid JSON', () => new Response('{')],
  ...[null, {}, { settings: null }, { settings: [] }]
    .map((data) => [`malformed settings ${JSON.stringify(data)}`, () => Response.json(data)]),
]) {
  test(`PUT ${name} rejects instead of reporting success`, async () => {
    const methods = [];
    const { saveRemoteProConfig } = loadConfig(async (_url, options) => {
      methods.push(options.method);
      return options.method === 'GET' ? Response.json({ settings: {} }) : response();
    });
    await assert.rejects(saveRemoteProConfig({ scheduler: { rules: [] } }));
    assert.deepEqual(methods, ['GET', 'PUT']);
  });
}


test('first settings save accepts an identified site with no configuration', async () => {
  const patch = { scheduler: { rules: [{ id: 'first', postTypes: ['page'] }] } };
  const methods = [];
  const { saveRemoteProConfig } = loadConfig(async (_url, options) => {
    methods.push(options.method);
    if (options.method === 'GET') return Response.json({ accountId: 'account', siteId: 'site' });
    assert.deepEqual(JSON.parse(options.body), { settings: patch });
    return Response.json({ settings: patch });
  });
  assert.deepEqual((await saveRemoteProConfig(patch)).scheduler, patch.scheduler);
  assert.deepEqual(methods, ['GET', 'PUT']);
});

for (const envelope of [{ accountId: 'other', siteId: 'site' }, { accountId: 'account', siteId: 'other' }]) {
  test(`unconfigured site identity mismatch blocks PUT: ${JSON.stringify(envelope)}`, async () => {
    const methods = [];
    const { saveRemoteProConfig } = loadConfig(async (_url, options) => {
      methods.push(options.method);
      return Response.json(envelope);
    });
    await assert.rejects(saveRemoteProConfig({ scheduler: { rules: [] } }));
    assert.deepEqual(methods, ['GET']);
  });
}

const workspaceConnection = { workspaceId: 'account', accountId: 'account', siteId: 'site', siteKey: 'test-site-key', workspaceApiMode: 'workspace', platformApiBaseUrl: 'https://foundation.example/dev', platformConfigUrl: 'https://delivery.example/dev.json' };
function workspaceConfiguration(settings, revision = 8, overrides = {}) {
  return { workspaceId: 'account', siteId: 'site', pluginKey: 'publisher', revision, settings, ...overrides };
}
function workspaceGet(settings, revision = 8) {
  return { site: { workspaceId: 'account', siteId: 'site' }, configuration: workspaceConfiguration(settings, revision) };
}

test('workspace publisher merges authoritative fields and uses actual read revision', async () => {
  const calls = [];
  const saved = { scheduler: { rules: ['preserve'] }, deploymentProfiles: [{ id: 'keep' }], unrelated: { keep: true } };
  const patch = { defaultDeploymentProfile: 'keep' };
  const { saveRemoteProConfig } = loadConfig(async (url, options) => {
    calls.push(options.method);
    assert.equal(url, 'https://foundation.example/dev/workspace/account/site/site/settings');
    assert.equal(options.headers['X-Plugin'], 'publisher');
    assert.equal(options.credentials, 'omit'); assert.equal(options.redirect, 'error');
    if (options.method === 'GET') { assert.equal(options.cache, 'no-store'); return Response.json(workspaceGet(saved)); }
    assert.deepEqual(JSON.parse(options.body), { pluginKey: 'publisher', expectedRevision: 8, settings: { ...saved, ...patch } });
    return Response.json({ configuration: workspaceConfiguration({ ...saved, ...patch }, 9) });
  }, workspaceConnection);
  assert.deepEqual(await saveRemoteProConfig(patch), { scheduler: saved.scheduler, defaultDeploymentProfile: 'keep', deploymentProfiles: saved.deploymentProfiles });
  assert.deepEqual(calls, ['GET', 'PUT']);
});

test('workspace first configuration records revision zero', async () => {
  const patch = { scheduler: { rules: [] } };
  const { saveRemoteProConfig } = loadConfig(async (_url, options) => {
    if (options.method === 'GET') return Response.json({ site: { workspaceId: 'account', siteId: 'site' }, configuration: null });
    assert.deepEqual(JSON.parse(options.body), { pluginKey: 'publisher', expectedRevision: 0, settings: patch });
    return Response.json({ configuration: workspaceConfiguration(patch, 1) });
  }, workspaceConnection);
  assert.deepEqual((await saveRemoteProConfig(patch)).scheduler, patch.scheduler);
});

for (const value of [null, {}, { site: { workspaceId: 'foreign', siteId: 'site' }, configuration: workspaceConfiguration({}) }, workspaceGet(null), workspaceGet([]), { ...workspaceGet({}), configuration: workspaceConfiguration({}, 8, { pluginKey: 'gatey' }) }]) {
  test(`workspace malformed or foreign authoritative GET blocks replacement: ${JSON.stringify(value)}`, async () => {
    const methods = [];
    const { saveRemoteProConfig } = loadConfig(async (_url, options) => { methods.push(options.method); return Response.json(value); }, workspaceConnection);
    await assert.rejects(saveRemoteProConfig({ scheduler: { rules: [] } }));
    assert.deepEqual(methods, ['GET']);
  });
}

test('workspace concurrent save is rejected with no silent retry or cached overwrite', async () => {
  const methods = [];
  const { saveRemoteProConfig } = loadConfig(async (_url, options) => {
    methods.push(options.method);
    if (options.method === 'GET') return Response.json(workspaceGet({ scheduler: { rules: ['current'] } }));
    assert.equal(JSON.parse(options.body).expectedRevision, 8);
    return new Response('private remote diagnostic', { status: 409 });
  }, workspaceConnection);
  await assert.rejects(saveRemoteProConfig({ defaultDeploymentProfile: 'new' }), /Site settings changed/);
  assert.deepEqual(methods, ['GET', 'PUT']);
});

test('manual reconnect during authoritative GET blocks the old-site PUT', async () => {
  const methods = []; let client;
  client = loadConfig(async (_url, options) => {
    methods.push(options.method); client.setConnection({ ...workspaceConnection, siteId: 'new-manual-site' });
    return Response.json(workspaceGet({}));
  }, workspaceConnection);
  await assert.rejects(client.saveRemoteProConfig({ scheduler: { rules: [] } }), /connection changed/);
  assert.deepEqual(methods, ['GET']);
});

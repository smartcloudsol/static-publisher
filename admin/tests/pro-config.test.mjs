import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

const source = readFileSync(new URL('../src/paid-features/config.ts', import.meta.url), 'utf8');
const { outputText } = ts.transpileModule(source, {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
});

function loadConfig(fetch) {
  const exports = {};
  const imports = {
    '@smart-cloud/wpsuite-core': {
      getConfig: () => { throw new Error('Cached plugin config must not be used for writes'); },
      getWpSuite: () => ({
        siteSettings: { accountId: 'account', siteId: 'site', siteKey: 'test-site-key' },
      }),
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
  return exports;
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

import assert from 'node:assert/strict';
import test from 'node:test';

import {
  bootstrapPublisherWpSuite,
  resolvePublisherCrawlMode,
  resolvePublisherDeploymentProfile,
  sanitizePublisherConfig,
} from '../dist/index.js';

const baseProfileConfig = {
  subscriptionType: 'PROFESSIONAL',
  deploymentProfiles: {
    production: {
      targetOrigin: 'https://example.com',
    },
    fallback: {
      targetOrigin: 'https://fallback.example.com',
    },
  },
  defaultDeploymentProfile: 'production',
  deploymentTargetOverride: 'fallback',
};

function withIsolatedWpSuite(seed) {
  const previous = globalThis.WpSuite;
  globalThis.WpSuite = seed;

  return () => {
    if (typeof previous === 'undefined') {
      delete globalThis.WpSuite;
    } else {
      globalThis.WpSuite = previous;
    }
  };
}

test('bootstrapPublisherWpSuite keeps plugin and event identity across repeated boots', () => {
  const restoreWpSuite = withIsolatedWpSuite({
    siteSettings: {
      accountId: 'account-1',
      siteId: 'site-1',
      siteKey: 'site-key',
      subscriber: true,
      useRecaptchaNet: false,
      renderRecaptchaProvider: true,
      lastUpdate: 200,
    },
    restUrl: '/wp-json',
    nonce: 'abc',
    uploadUrl: '/upload',
    plugins: {
      staticPublisher: {
        restUrl: '/publisher-rest',
      },
    },
    events: {
      eventName: 'publisher-ready',
    },
    view: 'settings',
  });

  try {
    const first = bootstrapPublisherWpSuite({
      siteSettings: {
        accountId: 'account-1',
        siteKey: 'ignored',
        subscriber: true,
        lastUpdate: 300,
      },
    });

    const second = bootstrapPublisherWpSuite({
      siteSettings: {
        accountId: 'account-1',
        useRecaptchaNet: false,
        renderRecaptchaProvider: true,
        lastUpdate: 'bad',
      },
    });

    assert.equal(first.plugins, second.plugins);
    assert.equal(first.events, second.events);
    assert.equal(first.plugins.staticPublisher.restUrl, '/publisher-rest');
  } finally {
    restoreWpSuite();
  }
});

test('bootstrapPublisherWpSuite filters site settings safely', () => {
  const restoreWpSuite = withIsolatedWpSuite({
    siteSettings: {
      accountId: 'existing-account',
      siteId: 'existing-site',
      siteKey: 'existing-key',
      lastUpdate: 100,
    },
  });

  try {
    const next = bootstrapPublisherWpSuite({
      siteSettings: {
        accountId: 'updated-account',
        siteId: '',
        siteKey: 'blocked',
        reCaptchaPublicKey: '  public-key  ',
        subscriber: 'not-boolean',
        useRecaptchaNet: false,
        lastUpdate: 'NaN',
      },
    });

    assert.equal(next.siteSettings.accountId, 'updated-account');
    assert.equal(Object.hasOwn(next.siteSettings, 'siteId'), false);
    assert.equal(next.siteSettings.reCaptchaPublicKey, 'public-key');
    assert.equal(Object.hasOwn(next.siteSettings, 'subscriber'), false);
    assert.equal(next.siteSettings.useRecaptchaNet, false);
    assert.equal(Object.hasOwn(next.siteSettings, 'siteKey'), false);
    assert.equal(Object.hasOwn(next.siteSettings, 'lastUpdate'), false);
  } finally {
    restoreWpSuite();
  }
});

test('resolvePublisherCrawlMode follows subscription gates for incremental mode', () => {
  assert.equal(resolvePublisherCrawlMode('incremental', { subscriptionType: 'PROFESSIONAL' }), 'incremental');
  assert.equal(resolvePublisherCrawlMode('incremental', { subscriptionType: 'BASIC' }), 'full');
  assert.equal(resolvePublisherCrawlMode('full', { subscriptionType: 'PROFESSIONAL' }), 'full');
});

test('deployment profile resolution handles requested, override/default, and unknown profile states', () => {
  const config = sanitizePublisherConfig(baseProfileConfig);

  assert.equal(resolvePublisherDeploymentProfile(config, 'production'), config.deploymentProfiles.production);
  assert.equal(resolvePublisherDeploymentProfile(config, undefined), config.deploymentProfiles.fallback);
  assert.equal(resolvePublisherDeploymentProfile(config, 'missing'), config.deploymentProfiles.fallback);

  const withoutOverrideOnly = {
    ...baseProfileConfig,
    deploymentTargetOverride: '',
    defaultDeploymentProfile: 'production',
  };
  const normalizedWithoutOverrideOnly = sanitizePublisherConfig(withoutOverrideOnly);
  assert.equal(
    resolvePublisherDeploymentProfile(normalizedWithoutOverrideOnly, 'missing'),
    normalizedWithoutOverrideOnly.deploymentProfiles.production,
  );

  const withoutDefaultOrOverride = {
    ...baseProfileConfig,
    deploymentTargetOverride: '',
    defaultDeploymentProfile: '',
  };
  assert.equal(resolvePublisherDeploymentProfile(withoutDefaultOrOverride, 'missing'), null);
});

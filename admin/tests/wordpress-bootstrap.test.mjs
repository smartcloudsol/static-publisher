import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { homedir, tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

test('WordPress dual Hub enqueue emits one executable Publisher bootstrap before the application', () => {
  const root = mkdtempSync(join(tmpdir(), 'publisher-wp-bootstrap-'));
  try {
    const archive = process.env.WP_ARCHIVE ?? join(homedir(), '.wp-cli/cache/core/wordpress-7.1-en_US.tar.gz');
    execFileSync('tar', ['-xzf', archive, '-C', root, 'wordpress/wp-includes']);
    const plugin = join(root, 'publisher');
    mkdirSync(join(plugin, 'admin'), { recursive: true });
    writeFileSync(join(plugin, 'admin/index.js'), '// fixture bundle path');
    const result = JSON.parse(execFileSync(process.env.PHP_BIN ?? 'php', [
      fileURLToPath(new URL('./wordpress-bootstrap.fixture.php', import.meta.url)), root, plugin,
    ], { encoding: 'utf8' }));
    assert.equal(result.count, 1, 'summary and details must not append the same bootstrap twice');
    assert.deepEqual(result.queue, ['smartcloud-static-publisher-admin']);
    const context = { WpSuite: { plugins: { otherPlugin: { preserved: true } }, siteSettings: { siteId: 'existing' } } };
    context.window = context;
    // Execute exactly what WP_Scripts emits; duplicate lexical declarations
    // cause a SyntaxError here before any bootstrap assignment can execute.
    runInNewContext(result.inline, context);
    assert.equal(context.WpSuite.plugins.staticPublisher.restUrl, '/publisher');
    assert.equal(context.WpSuite.plugins.otherPlugin.preserved, true);
    assert.equal(context.WpSuite.siteSettings.siteId, 'existing');
    // Also tolerate another compatible plugin loader replaying the bootstrap.
    runInNewContext(result.inline, context);
    assert.equal(context.WpSuite.plugins.staticPublisher.nonce, 'fixture');
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

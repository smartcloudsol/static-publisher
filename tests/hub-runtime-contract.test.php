<?php

declare(strict_types=1);

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$root = dirname(__DIR__);
$pluginSource = file_get_contents($root . '/smartcloud-static-publisher.php');
$loaderSource = file_get_contents($root . '/hub-loader.php');

expect(is_string($pluginSource) && is_string($loaderSource), 'Static Publisher runtime contract sources must be readable.');
expect(str_contains($loaderSource, 'SMARTCLOUD_WPSUITE_RUNTIME_DIRECTORY'), 'Static Publisher Hub loader must separate the runtime directory from stable identifiers.');
expect(str_contains($loaderSource, "'smartcloud-wpsuite'"), 'Static Publisher Hub loader must target the renamed runtime directory.');
expect(str_contains($loaderSource, "'hub-for-wpsuiteio'"), 'Static Publisher must retain the legacy WP Suite slug alias during migration.');
expect(str_contains($pluginSource, "get_option('smartcloud-wpsuite/site-settings')"), 'Static Publisher must read the canonical site-settings option.');
expect(str_contains($pluginSource, "get_option('hub-for-wpsuiteio/site-settings')"), 'Static Publisher must retain a legacy site-settings fallback.');
expect(str_contains($pluginSource, "home_url('/' . \$assetPath)"), 'Static Publisher must expose the canonical root-level WP Suite virtual asset URL.');
expect(str_contains($pluginSource, "home_url('/?smartcloud_wpsuiteio_asset=')"), 'Static Publisher must support WP Suite virtual assets with plain permalinks.');
expect(!str_contains($pluginSource, 'getWpSuiteUploadPaths'), 'Static Publisher must not point the exporter at the retired uploads-backed WP Suite asset path.');
expect(str_contains($pluginSource, "'virtualAssetBaseUrl' => sanitize_url(\$this->getWpSuiteVirtualAssetBaseUrl())"), 'Static Publisher must use the semantic virtualAssetBaseUrl runtime configuration key.');
$uninstallSource = file_get_contents($root . '/uninstall.php');
expect(is_string($uninstallSource), 'Static Publisher uninstall cleanup must be packaged.');
expect(str_contains($uninstallSource, "'smartcloud-static-publisher'"), 'Static Publisher uninstall must target its complete uploads tree.');
expect(!str_contains($uninstallSource, 'smartcloud-wpsuiteio/license-jws'), 'Static Publisher uninstall must not remove shared WP Suite licences.');

fwrite(STDOUT, "Static Publisher runtime compatibility checks passed.\n");

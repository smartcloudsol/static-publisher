<?php
/**
 * Plugin Name:       SmartCloud Static Publisher
 * Plugin URI:        https://wpsuite.io/static-publisher/
 * Description:       Static export admin for WP Suite Static Publisher. Generates runtime config, queues export jobs, and shows exporter logs.
 * Requires at least: 6.2
 * Tested up to:      7.0
 * Requires PHP:      8.1
 * Version:           1.0.2
 * Author:            Smart Cloud Solutions Inc.
 * Author URI:        https://smart-cloud-solutions.com
 * License:           MIT
 * License URI:       https://mit-license.org/
 * Text Domain:       smartcloud-static-publisher
 *
 * @package smartcloud-static-publisher
 */

namespace SmartCloud\WPSuite\StaticPublisher;

use SmartCloud\WPSuite\StaticPublisher\Admin\Admin;

if (!defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '8.1', '<')) {
    deactivate_plugins(plugin_basename(__FILE__));
    wp_die(
        esc_html__('Static Publisher requires PHP 8.1 or higher.', 'smartcloud-static-publisher'),
        esc_html__('Plugin dependency check', 'smartcloud-static-publisher'),
        array('back_link' => true)
    );
}

const VERSION = '1.0.2';

final class Plugin
{
    private const SLUG = 'smartcloud-static-publisher';
    private const OPTION_KEY = 'smartcloud_static_publisher_config';
    private const OPTION_AUDIT_LOG_KEY = 'smartcloud_static_publisher_audit_log';
    private const OPTION_AUDIT_CURSOR_KEY = 'smartcloud_static_publisher_audit_cursor';
    private const OPTION_RUNTIME_NONCE_KEY = 'smartcloud_static_publisher_runtime_nonce';
    private const OPTION_QUEUE_MUTATION_LOCK_KEY = 'smartcloud_static_publisher_queue_mutation_lock';
    private const REST_NAMESPACE = 'smartcloud-static-publisher/v1';
    private const CHANGE_TOKEN_AUTH_HEADER = 'x-static-publisher-token';

    private static ?Plugin $instance = null;
    private ?Admin $admin = null;

    public static function instance(): Plugin
    {
        return self::$instance ?? (self::$instance = new self());
    }

    private function __construct()
    {
        $this->defineConstants();
        $this->includes();

        if ($this->admin instanceof Admin) {
            $this->admin->registerHooks();
        }

        add_action('rest_api_init', array($this, 'registerRestRoutes'));
        register_activation_hook(__FILE__, array($this, 'onActivation'));
    }

    public function onActivation(): void
    {
        $paths = $this->getRuntimePaths();
        wp_mkdir_p($paths['runtime']);
        wp_mkdir_p($paths['logs']);

        $this->getRuntimeNonce();

        $this->writeJsonFile($paths['config'], $this->buildRuntimeConfig($this->getConfig()));
        $this->writeJsonFile($paths['queue'], array());
    }

    private function defineConstants(): void
    {
        if (!defined('SMARTCLOUD_STATIC_PUBLISHER_VERSION')) {
            define('SMARTCLOUD_STATIC_PUBLISHER_VERSION', VERSION);
        }
        if (!defined('SMARTCLOUD_STATIC_PUBLISHER_SLUG')) {
            define('SMARTCLOUD_STATIC_PUBLISHER_SLUG', self::SLUG);
        }
        if (!defined('SMARTCLOUD_STATIC_PUBLISHER_PATH')) {
            define('SMARTCLOUD_STATIC_PUBLISHER_PATH', plugin_dir_path(__FILE__));
        }
        if (!defined('SMARTCLOUD_STATIC_PUBLISHER_URL')) {
            define('SMARTCLOUD_STATIC_PUBLISHER_URL', plugin_dir_url(__FILE__));
        }
    }

    private function includes(): void
    {
        if (file_exists(SMARTCLOUD_STATIC_PUBLISHER_PATH . 'hub-loader.php')) {
            require_once SMARTCLOUD_STATIC_PUBLISHER_PATH . 'hub-loader.php';
        }

        if (file_exists(SMARTCLOUD_STATIC_PUBLISHER_PATH . 'admin/admin.php')) {
            require_once SMARTCLOUD_STATIC_PUBLISHER_PATH . 'admin/admin.php';
        }

        if (class_exists('\SmartCloud\WPSuite\StaticPublisher\Admin\Admin')) {
            $this->admin = new Admin($this);
        }
    }

    public function registerRestRoutes(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/change-tokens', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'canReadChangeTokens'),
            'callback' => array($this, 'handleGetChangeTokens'),
        ));
    }

    public function canReadChangeTokens(\WP_REST_Request $request): bool
    {
        $providedToken = sanitize_text_field((string) $request->get_header(self::CHANGE_TOKEN_AUTH_HEADER));
        if ($providedToken === '') {
            return false;
        }

        return hash_equals($this->getRuntimeNonce(), $providedToken);
    }

    public function handleGetChangeTokens(\WP_REST_Request $request): \WP_REST_Response
    {
        $payload = $request->get_json_params();
        $data = is_array($payload) ? $payload : array();
        $rawUrls = isset($data['urls']) && is_array($data['urls']) ? $data['urls'] : array();

        $urls = array();
        foreach ($rawUrls as $rawUrl) {
            $normalized = $this->normalizePublicUrlForChangeToken((string) $rawUrl);
            if ($normalized !== null) {
                $urls[] = $normalized;
            }
        }

        $urls = array_values(array_unique($urls));
        if (count($urls) > 250) {
            $urls = array_slice($urls, 0, 250);
        }

        $renderDependencyTargetSignatures = $this->computeActiveCodeRenderDependencyTargetSignatures();
        $globalSignature = $this->computeGlobalRenderDependencySignature($renderDependencyTargetSignatures);
        $items = array();
        foreach ($urls as $url) {
            $items[] = $this->buildChangeTokenItem($url, $globalSignature, $renderDependencyTargetSignatures);
        }

        return new \WP_REST_Response(array(
            'items' => $items,
        ), 200);
    }

    public function getConfig(): array
    {
        $stored = get_option(self::OPTION_KEY);
        if (!is_array($stored)) {
            $stored = array();
        }
        return $this->sanitizeConfig($stored);
    }

    public function getResolvedConfig(?array $localConfig = null): array
    {
        return is_array($localConfig) ? $this->sanitizeConfig($localConfig) : $this->getConfig();
    }

    public function sanitizeConfig(array $input): array
    {
        $rewriteMode = isset($input['urlRewriteMode']) ? sanitize_text_field((string) $input['urlRewriteMode']) : 'relative';
        if (!in_array($rewriteMode, array('absolute', 'root-relative', 'relative'), true)) {
            $rewriteMode = 'relative';
        }

        $siteAddressOrigin = $this->resolveSiteAddressOrigin();

        return array(
            'sourceOrigin' => $siteAddressOrigin,
            'targetOrigin' => $this->sanitizeOriginOrDot($input['targetOrigin'] ?? ''),
            'ignoreHttpsErrors' => !empty($input['ignoreHttpsErrors']),
            'urlRewriteMode' => $rewriteMode,
            'exporterDir' => $this->sanitizeOptionalHostPath($input['exporterDir'] ?? ''),
            'outputDir' => $this->normalizeStorageRelativePath((string) ($input['outputDir'] ?? 'export'), 'export'),
            'noJavaScriptRenderPathPrefixes' => $this->sanitizePathList($input['noJavaScriptRenderPathPrefixes'] ?? array()),
            'seedPaths' => $this->sanitizePathList($input['seedPaths'] ?? array()),
            'generated404RequestPath' => $this->sanitizeOptionalPublicPath($input['generated404RequestPath'] ?? ''),
            'sitemapPaths' => $this->sanitizePathList($input['sitemapPaths'] ?? array('/sitemap_index.xml', '/sitemap.xml')),
            'allowedAssetHosts' => $this->sanitizeHostList($input['allowedAssetHosts'] ?? array()),
            'assetPathPrefixes' => $this->sanitizePathList($input['assetPathPrefixes'] ?? array('/wp-content/', '/wp-includes/')),
            'blockedPathPrefixes' => $this->sanitizePathList($input['blockedPathPrefixes'] ?? array('/wp-admin', '/wp-login.php', '/wp-json')),
            'blockedSearchFragments' => $this->sanitizeStringList($input['blockedSearchFragments'] ?? array()),
            'extraReplacements' => $this->sanitizeMap($input['extraReplacements'] ?? array()),
            'postCrawlCopyMap' => $this->sanitizeMap($input['postCrawlCopyMap'] ?? array()),
            'logDir' => $this->normalizeStorageRelativePath((string) ($input['logDir'] ?? 'logs'), 'logs'),
            'verbose' => !empty($input['verbose']),
            'logLevel' => $this->sanitizeLogLevel($input['logLevel'] ?? 'info'),
            's3SyncMode' => $this->sanitizeS3SyncMode($input['s3SyncMode'] ?? 'sdk-upload-delete'),
            'navigationTimeoutMs' => max(1000, absint($input['navigationTimeoutMs'] ?? 30000)),
            'readiness' => array(
                'waitForSelector' => $this->sanitizeNullableText($input['readiness']['waitForSelector'] ?? null),
                'waitForFunction' => $this->sanitizeNullableText($input['readiness']['waitForFunction'] ?? null),
                'timeoutMs' => max(100, absint($input['readiness']['timeoutMs'] ?? 1500)),
                'fallbackWaitMs' => max(100, absint($input['readiness']['fallbackWaitMs'] ?? 1500)),
            ),
            'viewport' => array(
                'width' => max(320, absint($input['viewport']['width'] ?? 1440)),
                'height' => max(320, absint($input['viewport']['height'] ?? 1200)),
            ),
            'maxPages' => max(0, absint($input['maxPages'] ?? 0)),
            'concurrency' => max(1, absint($input['concurrency'] ?? 1)),
            'assetDownloadConcurrency' => max(1, absint($input['assetDownloadConcurrency'] ?? ($input['concurrency'] ?? 1))),
            'rewriteConcurrency' => max(1, absint($input['rewriteConcurrency'] ?? ($input['assetDownloadConcurrency'] ?? ($input['concurrency'] ?? 1)))),
            's3' => array(
                'bucket' => sanitize_text_field((string) ($input['s3']['bucket'] ?? '')),
                'prefix' => $this->sanitizePathToken($input['s3']['prefix'] ?? ''),
                'region' => sanitize_text_field((string) ($input['s3']['region'] ?? 'eu-central-1')),
                'htmlCacheControl' => sanitize_text_field((string) ($input['s3']['htmlCacheControl'] ?? 'public,max-age=60,s-maxage=60')),
                'assetCacheControl' => sanitize_text_field((string) ($input['s3']['assetCacheControl'] ?? 'public,max-age=31536000,immutable')),
            ),
            'cloudFront' => array(
                'distributionId' => sanitize_text_field((string) ($input['cloudFront']['distributionId'] ?? '')),
                'invalidationPaths' => $this->sanitizePathList($input['cloudFront']['invalidationPaths'] ?? array('/*')),
            ),
        );
    }

    private function sanitizeOrigin($value): string
    {
        $url = esc_url_raw((string) $value, array('http', 'https'));
        return rtrim($url, '/');
    }

    private function sanitizeOriginOrDot($value): string
    {
        $raw = trim((string) $value);
        if ($raw === '.') {
            return '.';
        }
        return $this->sanitizeOrigin($raw);
    }

    public function sanitizeDeploymentProfileName($value): string
    {
        $name = sanitize_text_field((string) $value);
        $name = preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
        return is_string($name) ? trim($name) : '';
    }

    private function resolveSiteAddressOrigin(): string
    {
        $siteAddress = get_site_url();
        $origin = $this->sanitizeOrigin($siteAddress);
        if ($origin !== '') {
            return $origin;
        }

        $fallback = home_url('/');
        return $this->sanitizeOrigin($fallback);
    }

    private function sanitizePathToken($value): string
    {
        $token = sanitize_text_field((string) $value);
        $token = preg_replace('#[^a-zA-Z0-9_\-./]#', '', $token);
        return is_string($token) ? trim($token) : '';
    }

    private function sanitizeOptionalPublicPath($value): string
    {
        $raw = sanitize_text_field((string) $value);
        if ($raw === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $raw)) {
            $parsed = wp_parse_url($raw);
            if (!is_array($parsed)) {
                return '';
            }
            $raw = isset($parsed['path']) ? (string) $parsed['path'] : '';
        }

        $path = '/' . ltrim($this->sanitizePathToken($raw), '/');
        $path = preg_replace('#/+#', '/', $path);

        return is_string($path) && $path !== '/' ? $path : '';
    }

    private function sanitizeOptionalHostPath($value): string
    {
        $raw = trim((string) $value);
        $raw = preg_replace('/[\x00-\x1F\x7F]/', '', $raw);
        if (!is_string($raw) || $raw === '') {
            return '';
        }

        return wp_normalize_path($raw);
    }

    private function sanitizeAwsEnvValue($value): string
    {
        $raw = trim((string) $value);
        $raw = preg_replace('/[\x00-\x1F\x7F]/', '', $raw);
        return is_string($raw) ? $raw : '';
    }

    public function sanitizeAwsTempCreds(array $value): array
    {
        return array(
            'accessKeyId' => $this->sanitizeAwsEnvValue($value['accessKeyId'] ?? ''),
            'secretAccessKey' => $this->sanitizeAwsEnvValue($value['secretAccessKey'] ?? ''),
            'sessionToken' => $this->sanitizeAwsEnvValue($value['sessionToken'] ?? ''),
        );
    }

    public function sanitizeJobForState($job)
    {
        if (!is_array($job)) {
            return $job;
        }
        if (isset($job['awsTempCreds'])) {
            unset($job['awsTempCreds']);
            $job['usesTempAwsCreds'] = true;
        }
        if (isset($job['wpsuite']) && is_array($job['wpsuite'])) {
            $job['wpsuite'] = array(
                'apiBase' => sanitize_text_field((string) ($job['wpsuite']['apiBase'] ?? '')),
                'runtimeToken' => sanitize_text_field((string) ($job['wpsuite']['runtimeToken'] ?? ($job['wpsuite']['nonce'] ?? ''))),
                'uploadUrl' => sanitize_url((string) ($job['wpsuite']['uploadUrl'] ?? '')),
                'subscriptionType' => sanitize_text_field((string) ($job['wpsuite']['subscriptionType'] ?? '')),
                'siteSettings' => array(
                    'accountId' => sanitize_text_field((string) ($job['wpsuite']['siteSettings']['accountId'] ?? ($job['wpsuite']['accountId'] ?? ''))),
                    'siteId' => sanitize_text_field((string) ($job['wpsuite']['siteSettings']['siteId'] ?? ($job['wpsuite']['siteId'] ?? ''))),
                    'lastUpdate' => isset($job['wpsuite']['siteSettings']['lastUpdate']) ? max(0, (int) $job['wpsuite']['siteSettings']['lastUpdate']) : 0,
                    'subscriber' => !empty($job['wpsuite']['siteSettings']['subscriber']) || !empty($job['wpsuite']['subscriber']),
                ),
            );
        }
        if (isset($job['crawlMode'])) {
            $crawlMode = sanitize_text_field((string) $job['crawlMode']);
            $job['crawlMode'] = $crawlMode === 'incremental' ? 'incremental' : 'full';
        }
        if (isset($job['deploymentProfile'])) {
            $deploymentProfile = $this->sanitizeDeploymentProfileName($job['deploymentProfile']);
            if ($deploymentProfile === '') {
                unset($job['deploymentProfile']);
            } else {
                $job['deploymentProfile'] = $deploymentProfile;
            }
        }
        if (isset($job['enqueueSource'])) {
            $enqueueSource = sanitize_text_field((string) $job['enqueueSource']);
            $job['enqueueSource'] = $enqueueSource === 'scheduler' ? 'scheduler' : 'manual';
        }
        if (isset($job['endedAt'])) {
            $job['endedAt'] = sanitize_text_field((string) $job['endedAt']);
        }
        if (isset($job['stopRequestedAt'])) {
            $job['stopRequestedAt'] = sanitize_text_field((string) $job['stopRequestedAt']);
        }
        if (isset($job['stopRequestedByUserId'])) {
            $job['stopRequestedByUserId'] = is_numeric($job['stopRequestedByUserId']) ? (int) $job['stopRequestedByUserId'] : null;
        }
        if (isset($job['stopRequestedByLogin'])) {
            $job['stopRequestedByLogin'] = sanitize_text_field((string) $job['stopRequestedByLogin']);
        }
        if (isset($job['stopMode'])) {
            $job['stopMode'] = sanitize_text_field((string) $job['stopMode']);
        }
        if (isset($job['stoppedStep'])) {
            $job['stoppedStep'] = sanitize_text_field((string) $job['stoppedStep']);
        }
        if (isset($job['logArchiveDir'])) {
            $job['logArchiveDir'] = $this->sanitizeOptionalHostPath((string) $job['logArchiveDir']);
        }
        if (isset($job['logArchiveCreatedAt'])) {
            $job['logArchiveCreatedAt'] = sanitize_text_field((string) $job['logArchiveCreatedAt']);
        }
        if (isset($job['logArchiveFileCount'])) {
            $job['logArchiveFileCount'] = is_numeric($job['logArchiveFileCount']) ? (int) $job['logArchiveFileCount'] : 0;
        }
        if (isset($job['logArchiveError'])) {
            $job['logArchiveError'] = sanitize_text_field((string) $job['logArchiveError']);
        }
        return $job;
    }

    private function sanitizeStringList($value): array
    {
        if (!is_array($value)) {
            return array();
        }
        $out = array();
        foreach ($value as $entry) {
            $line = sanitize_text_field((string) $entry);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return array_values(array_unique($out));
    }

    private function sanitizePathList($value): array
    {
        return $this->sanitizeStringList($value);
    }

    private function sanitizeHostList($value): array
    {
        if (!is_array($value)) {
            return array();
        }
        $out = array();
        foreach ($value as $entry) {
            $host = sanitize_text_field((string) $entry);
            $host = preg_replace('/[^a-z0-9.-]/i', '', $host);
            if (is_string($host) && $host !== '') {
                $out[] = strtolower($host);
            }
        }
        return array_values(array_unique($out));
    }

    private function sanitizeMap($value): array
    {
        if (!is_array($value)) {
            return array();
        }
        $out = array();
        foreach ($value as $k => $v) {
            $key = sanitize_text_field((string) $k);
            $val = sanitize_text_field((string) $v);
            if ($key !== '') {
                $out[$key] = $val;
            }
        }
        return $out;
    }

    private function sanitizeNullableText($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = sanitize_text_field((string) $value);
        return $text === '' ? null : $text;
    }

    private function sanitizeLogLevel($value): string
    {
        $level = sanitize_text_field((string) $value);
        $allowed = array('error', 'warn', 'info', 'debug');
        return in_array($level, $allowed, true) ? $level : 'info';
    }

    private function sanitizeS3SyncMode($value): string
    {
        $mode = sanitize_text_field((string) $value);
        $allowed = array(
            'sdk-upload-delete',
            'sdk-upload-only',
        );
        return in_array($mode, $allowed, true) ? $mode : 'sdk-upload-delete';
    }

    public function stripRuntimeOnlyConfigFromWpStorage(array $config): array
    {
        $stripped = $config;
        unset($stripped['wpsuite']);
        unset($stripped['deploymentTargetOverride']);
        return $stripped;
    }

    public function stripLocalOnlyConfigFromRuntimeConfig(array $config): array
    {
        $stripped = $config;
        unset($stripped['exporterDir']);
        unset($stripped['scheduler']);
        unset($stripped['defaultDeploymentProfile']);
        unset($stripped['deploymentProfiles']);
        unset($stripped['wpsuite']);
        return $stripped;
    }

    public function buildRuntimeConfig(array $config, string $deploymentTargetOverride = ''): array
    {
        $runtime = $this->stripLocalOnlyConfigFromRuntimeConfig($config);
        $runtime['wpsuite'] = $this->getWpSuiteRuntimeConfig();

        $override = $this->sanitizeDeploymentProfileName($deploymentTargetOverride);
        if ($override !== '') {
            $runtime['deploymentTargetOverride'] = $override;
        } else {
            unset($runtime['deploymentTargetOverride']);
        }

        return $runtime;
    }

    private function getWpSuiteSiteSettings(): array
    {
        $slug = defined('SMARTCLOUD_WPSUITE_SLUG') ? SMARTCLOUD_WPSUITE_SLUG : 'hub-for-wpsuiteio';
        $raw = get_option($slug . '/site-settings');

        if (is_object($raw)) {
            $raw = get_object_vars($raw);
        }

        if (!is_array($raw)) {
            $raw = array();
        }

        return array(
            'accountId' => sanitize_text_field((string) ($raw['accountId'] ?? '')),
            'siteId' => sanitize_text_field((string) ($raw['siteId'] ?? '')),
            'siteKey' => sanitize_text_field((string) ($raw['siteKey'] ?? '')),
            'lastUpdate' => isset($raw['lastUpdate']) ? max(0, (int) $raw['lastUpdate']) : 0,
            'subscriber' => !empty($raw['subscriber']),
        );
    }

    private function getWpSuiteUploadPaths(): array
    {
        $uploadDirInfo = wp_upload_dir();
        $slug = defined('SMARTCLOUD_WPSUITE_SLUG') ? SMARTCLOUD_WPSUITE_SLUG : 'hub-for-wpsuiteio';

        return array(
            'dir' => trailingslashit((string) ($uploadDirInfo['basedir'] ?? '')) . $slug . '/',
            'url' => trailingslashit((string) ($uploadDirInfo['baseurl'] ?? '')) . $slug . '/',
        );
    }

    private function generateRuntimeNonce(): string
    {
        try {
            return bin2hex(random_bytes(24));
        } catch (\Throwable $error) {
            return wp_generate_password(48, false, false);
        }
    }

    public function getRuntimeNonce(): string
    {
        $existing = get_option(self::OPTION_RUNTIME_NONCE_KEY, '');
        $nonce = is_string($existing) ? sanitize_text_field($existing) : '';
        if ($nonce !== '') {
            return $nonce;
        }

        $nonce = $this->generateRuntimeNonce();
        update_option(self::OPTION_RUNTIME_NONCE_KEY, $nonce, false);
        return $nonce;
    }

    public function getWpSuiteRuntimeConfig(): array
    {
        $settings = $this->getWpSuiteSiteSettings();
        $uploadPaths = $this->getWpSuiteUploadPaths();

        return array(
            'apiBase' => $this->getWpSuiteApiBase(),
            'runtimeToken' => $this->getRuntimeNonce(),
            'uploadUrl' => sanitize_url((string) ($uploadPaths['url'] ?? '')),
            'siteSettings' => array(
                'accountId' => $settings['accountId'],
                'siteId' => $settings['siteId'],
                'lastUpdate' => max(0, (int) ($settings['lastUpdate'] ?? 0)),
                'subscriber' => !empty($settings['subscriber']),
            ),
        );
    }

    private function getWpSuiteApiBase(): string
    {
        $siteUrl = (string) get_site_url();
        if (strpos($siteUrl, 'dev.wpsuite.io') !== false) {
            return 'https://api.wpsuite.io/dev';
        }
        return 'https://api.wpsuite.io';
    }

    public function getWpSuiteIdentityForJobs(): array
    {
        $settings = $this->getWpSuiteSiteSettings();

        return array(
            'accountId' => $settings['accountId'],
            'siteId' => $settings['siteId'],
            'siteKey' => $settings['siteKey'],
            'subscriber' => !empty($settings['subscriber']),
            'apiBase' => $this->getWpSuiteApiBase(),
        );
    }

    private function normalizePublicUrlForChangeToken(string $rawUrl): ?string
    {
        $rawUrl = trim($rawUrl);
        if ($rawUrl === '') {
            return null;
        }

        $siteOrigin = $this->sanitizeOrigin(home_url('/'));
        if ($siteOrigin === '') {
            return null;
        }

        if (strpos($rawUrl, '/') === 0) {
            $rawUrl = $siteOrigin . $rawUrl;
        }

        $url = esc_url_raw($rawUrl, array('http', 'https'));
        if ($url === '') {
            return null;
        }

        $urlParts = wp_parse_url($url);
        $siteParts = wp_parse_url($siteOrigin);
        if (!is_array($urlParts) || !is_array($siteParts)) {
            return null;
        }

        $urlScheme = strtolower((string) ($urlParts['scheme'] ?? ''));
        $siteScheme = strtolower((string) ($siteParts['scheme'] ?? ''));
        $urlHost = strtolower((string) ($urlParts['host'] ?? ''));
        $siteHost = strtolower((string) ($siteParts['host'] ?? ''));
        $urlPort = (string) ($urlParts['port'] ?? '');
        $sitePort = (string) ($siteParts['port'] ?? '');

        if ($urlScheme !== $siteScheme || $urlHost !== $siteHost || $urlPort !== $sitePort) {
            return null;
        }

        $path = isset($urlParts['path']) ? (string) $urlParts['path'] : '/';
        $query = isset($urlParts['query']) && $urlParts['query'] !== ''
            ? '?' . (string) $urlParts['query']
            : '';

        return $siteOrigin . $path . $query;
    }

    private function buildChangeTokenItem(string $url, array $globalSignature, array $renderDependencyTargetSignatures): array
    {
        $generated404Item = $this->buildGenerated404ChangeTokenItem($url, $globalSignature, $renderDependencyTargetSignatures);
        if (is_array($generated404Item)) {
            return $generated404Item;
        }

        $resolved = $this->resolveTrackedRouteForUrl($url);
        if (!$resolved || !is_array($resolved)) {
            return $this->buildUnsupportedChangeTokenItem(
                $url,
                'URL did not resolve to a tracked WordPress route.'
            );
        }

        $kind = sanitize_key((string) ($resolved['kind'] ?? ''));
        $sharedLayoutDependency = $this->collectSharedLayoutDependencyDataForUrl($url, $renderDependencyTargetSignatures);
        if ($kind === 'post' && isset($resolved['post']) && $resolved['post'] instanceof \WP_Post) {
            /** @var \WP_Post $post */
            $post = $resolved['post'];
            $dependencyPostIds = $this->collectReferencedPostIdsForPost($post);
            $scopedRenderDependencyTargets = array_merge(
                $this->collectScopedRenderDependencyTargetsForPost($post, $renderDependencyTargetSignatures),
                (array) ($sharedLayoutDependency['targets'] ?? array())
            );
            $dependencyPayload = array();
            foreach ($dependencyPostIds as $dependencyPostId) {
                $dependencyPost = get_post($dependencyPostId);
                if (!($dependencyPost instanceof \WP_Post)) {
                    continue;
                }

                $scopedRenderDependencyTargets = array_merge(
                    $scopedRenderDependencyTargets,
                    $this->collectScopedRenderDependencyTargetsForPost($dependencyPost, $renderDependencyTargetSignatures)
                );

                $dependencyPayload[] = array(
                    'id' => (int) $dependencyPost->ID,
                    'type' => (string) $dependencyPost->post_type,
                    'status' => (string) $dependencyPost->post_status,
                    'modifiedGmt' => (string) ($dependencyPost->post_modified_gmt ?: $dependencyPost->post_modified),
                );
            }

            $payload = array(
                'url' => $url,
                'post' => array(
                    'id' => (int) $post->ID,
                    'type' => (string) $post->post_type,
                    'status' => (string) $post->post_status,
                    'modifiedGmt' => (string) ($post->post_modified_gmt ?: $post->post_modified),
                ),
                'dependencies' => $dependencyPayload,
                'code' => $this->buildScopedRenderDependencySignature(
                    $scopedRenderDependencyTargets,
                    $renderDependencyTargetSignatures
                ),
                'layout' => $sharedLayoutDependency['signature'] ?? $this->emptySharedLayoutDependencyData()['signature'],
                'global' => $globalSignature,
            );

            return array(
                'url' => $url,
                'supported' => true,
                'token' => hash('sha256', (string) wp_json_encode($payload)),
                'tokenSource' => sanitize_text_field((string) ($resolved['tokenSource'] ?? 'wp-singular')),
                'postId' => (int) $post->ID,
                'dependencyPostIds' => $dependencyPostIds,
                'reason' => null,
            );
        }

        if (in_array($kind, array('posts-archive', 'term-archive', 'post-type-archive', 'author-archive', 'date-archive'), true)) {
            $archiveItem = $this->buildArchiveChangeTokenItem($url, $resolved, $globalSignature, $renderDependencyTargetSignatures, $sharedLayoutDependency);
            if (is_array($archiveItem)) {
                return $archiveItem;
            }
        }

        return $this->buildUnsupportedChangeTokenItem(
            $url,
            'URL did not resolve to a tracked WordPress route.'
        );
    }

    private function buildGenerated404ChangeTokenItem(string $url, array $globalSignature, array $renderDependencyTargetSignatures): ?array
    {
        $normalizedUrl = $this->normalizePublicUrlForChangeToken($url);
        $configuredUrl = $this->getConfiguredGenerated404RequestUrl();
        if ($normalizedUrl === null || $configuredUrl === null) {
            return null;
        }

        if ($this->normalizeGenerated404ComparableUrl($normalizedUrl) !== $this->normalizeGenerated404ComparableUrl($configuredUrl)) {
            return null;
        }

        $query = $this->buildTrackedRouteQueryForUrl($normalizedUrl);
        $sharedLayoutDependency = $this->collectSharedLayoutDependencyDataForUrl(
            $normalizedUrl,
            $renderDependencyTargetSignatures,
            $query instanceof \WP_Query ? $query : null,
            '404'
        );

        $config = $this->getConfig();
        $requestPath = sanitize_text_field((string) ($config['generated404RequestPath'] ?? ''));
        if ($requestPath === '') {
            return null;
        }

        $payload = array(
            'url' => $normalizedUrl,
            'notFound' => array(
                'kind' => 'generated-404-request-path',
                'requestPath' => $requestPath,
            ),
            'code' => $this->buildScopedRenderDependencySignature(
                (array) ($sharedLayoutDependency['targets'] ?? array()),
                $renderDependencyTargetSignatures
            ),
            'layout' => $sharedLayoutDependency['signature'] ?? $this->emptySharedLayoutDependencyData()['signature'],
            'global' => $globalSignature,
        );

        return array(
            'url' => $normalizedUrl,
            'supported' => true,
            'token' => hash('sha256', (string) wp_json_encode($payload)),
            'tokenSource' => 'wp-generated-404',
            'postId' => null,
            'dependencyPostIds' => array(),
            'reason' => null,
        );
    }

    private function getConfiguredGenerated404RequestUrl(): ?string
    {
        $config = $this->getConfig();
        $requestPath = sanitize_text_field((string) ($config['generated404RequestPath'] ?? ''));
        if ($requestPath === '') {
            return null;
        }

        return $this->normalizePublicUrlForChangeToken($requestPath);
    }

    private function normalizeGenerated404ComparableUrl(string $normalizedUrl): string
    {
        $parts = wp_parse_url($normalizedUrl);
        if (!is_array($parts)) {
            return '';
        }

        $origin = $this->sanitizeOrigin($normalizedUrl);
        if ($origin === '') {
            return '';
        }

        $path = isset($parts['path']) ? (string) $parts['path'] : '/';
        if ($path !== '/' && pathinfo($path, PATHINFO_EXTENSION) === '') {
            $path = untrailingslashit($path);
            if ($path === '') {
                $path = '/';
            }
        }

        $query = isset($parts['query']) && $parts['query'] !== ''
            ? '?' . (string) $parts['query']
            : '';

        return $origin . $path . $query;
    }

    private function buildUnsupportedChangeTokenItem(string $url, string $reason): array
    {
        return array(
            'url' => $url,
            'supported' => false,
            'token' => null,
            'tokenSource' => 'unsupported',
            'postId' => null,
            'dependencyPostIds' => array(),
            'reason' => $reason,
        );
    }

    private function buildArchiveChangeTokenItem(string $url, array $resolved, array $globalSignature, array $renderDependencyTargetSignatures, array $sharedLayoutDependency): ?array
    {
        $kind = sanitize_key((string) ($resolved['kind'] ?? ''));
        $paged = max(1, absint($resolved['paged'] ?? 1));
        $tokenSource = sanitize_text_field((string) ($resolved['tokenSource'] ?? 'unsupported'));

        if ($kind === 'posts-archive') {
            $page = isset($resolved['page']) && $resolved['page'] instanceof \WP_Post
                ? $resolved['page']
                : null;
            $listing = $this->buildArchivePostQuerySignature(array(
                'post_type' => 'post',
                'paged' => $paged,
            ));
            $scopedRenderDependencyTargets = $this->collectScopedRenderDependencyTargetsForArchive(
                $resolved,
                $listing,
                $renderDependencyTargetSignatures
            );
            $scopedRenderDependencyTargets = array_merge(
                $scopedRenderDependencyTargets,
                (array) ($sharedLayoutDependency['targets'] ?? array())
            );

            $payload = array(
                'url' => $url,
                'archive' => array(
                    'kind' => 'posts-archive',
                    'paged' => $paged,
                    'showOnFront' => sanitize_text_field((string) get_option('show_on_front')),
                    'pageForPosts' => $page instanceof \WP_Post ? (int) $page->ID : absint(get_option('page_for_posts')),
                ),
                'page' => $page instanceof \WP_Post
                    ? array(
                        'id' => (int) $page->ID,
                        'type' => (string) $page->post_type,
                        'status' => (string) $page->post_status,
                        'modifiedGmt' => (string) ($page->post_modified_gmt ?: $page->post_modified),
                    )
                    : null,
                'listing' => $listing,
                'code' => $this->buildScopedRenderDependencySignature(
                    $scopedRenderDependencyTargets,
                    $renderDependencyTargetSignatures
                ),
                'layout' => $sharedLayoutDependency['signature'] ?? $this->emptySharedLayoutDependencyData()['signature'],
                'global' => $globalSignature,
            );

            return array(
                'url' => $url,
                'supported' => true,
                'token' => hash('sha256', (string) wp_json_encode($payload)),
                'tokenSource' => $tokenSource !== '' ? $tokenSource : 'wp-posts-archive',
                'postId' => $page instanceof \WP_Post ? (int) $page->ID : null,
                'dependencyPostIds' => $listing['postIds'],
                'reason' => null,
            );
        }

        if ($kind === 'term-archive') {
            if (!isset($resolved['term']) || !($resolved['term'] instanceof \WP_Term)) {
                return null;
            }

            /** @var \WP_Term $term */
            $term = $resolved['term'];
            $taxonomy = get_taxonomy($term->taxonomy);
            $postTypes = array_values(array_filter(
                array_map('sanitize_key', (array) ($taxonomy->object_type ?? array())),
                static function ($postType): bool {
                    $postTypeObject = get_post_type_object($postType);
                    return $postTypeObject instanceof \WP_Post_Type && is_post_type_viewable($postTypeObject);
                }
            ));

            $listing = $this->buildArchivePostQuerySignature(array(
                'post_type' => count($postTypes) === 1 ? $postTypes[0] : (!empty($postTypes) ? $postTypes : 'any'),
                'paged' => $paged,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Intentional term archive signature lookup for change-token generation; scoped to a single term and returns IDs only.
                'tax_query' => array(
                    array(
                        'taxonomy' => $term->taxonomy,
                        'field' => 'term_id',
                        'terms' => array((int) $term->term_id),
                        'include_children' => is_taxonomy_hierarchical($term->taxonomy),
                    ),
                ),
            ));
            $scopedRenderDependencyTargets = $this->collectScopedRenderDependencyTargetsForArchive(
                $resolved,
                $listing,
                $renderDependencyTargetSignatures
            );
            $scopedRenderDependencyTargets = array_merge(
                $scopedRenderDependencyTargets,
                (array) ($sharedLayoutDependency['targets'] ?? array())
            );

            $payload = array(
                'url' => $url,
                'archive' => array(
                    'kind' => 'term-archive',
                    'paged' => $paged,
                    'taxonomy' => (string) $term->taxonomy,
                    'termId' => (int) $term->term_id,
                    'slug' => (string) $term->slug,
                    'parent' => (int) $term->parent,
                    'count' => (int) $term->count,
                    'name' => (string) $term->name,
                    'descriptionHash' => hash('sha256', (string) $term->description),
                    'termMetaHash' => hash('sha256', (string) wp_json_encode(get_term_meta($term->term_id))),
                ),
                'listing' => $listing,
                'code' => $this->buildScopedRenderDependencySignature(
                    $scopedRenderDependencyTargets,
                    $renderDependencyTargetSignatures
                ),
                'layout' => $sharedLayoutDependency['signature'] ?? $this->emptySharedLayoutDependencyData()['signature'],
                'global' => $globalSignature,
            );

            return array(
                'url' => $url,
                'supported' => true,
                'token' => hash('sha256', (string) wp_json_encode($payload)),
                'tokenSource' => $tokenSource !== '' ? $tokenSource : 'wp-taxonomy-archive',
                'postId' => null,
                'dependencyPostIds' => $listing['postIds'],
                'reason' => null,
            );
        }

        if ($kind === 'author-archive') {
            if (!isset($resolved['author']) || !($resolved['author'] instanceof \WP_User)) {
                return null;
            }

            /** @var \WP_User $author */
            $author = $resolved['author'];
            $profilePayload = array(
                'displayName' => (string) $author->display_name,
                'userUrl' => (string) $author->user_url,
                'nickname' => (string) get_user_meta($author->ID, 'nickname', true),
                'firstName' => (string) get_user_meta($author->ID, 'first_name', true),
                'lastName' => (string) get_user_meta($author->ID, 'last_name', true),
                'description' => (string) get_user_meta($author->ID, 'description', true),
            );

            $listing = $this->buildArchivePostQuerySignature(array(
                'post_type' => 'post',
                'paged' => $paged,
                'author' => (int) $author->ID,
            ));
            $scopedRenderDependencyTargets = $this->collectScopedRenderDependencyTargetsForArchive(
                $resolved,
                $listing,
                $renderDependencyTargetSignatures
            );
            $scopedRenderDependencyTargets = array_merge(
                $scopedRenderDependencyTargets,
                (array) ($sharedLayoutDependency['targets'] ?? array())
            );

            $payload = array(
                'url' => $url,
                'archive' => array(
                    'kind' => 'author-archive',
                    'paged' => $paged,
                    'authorId' => (int) $author->ID,
                    'nicename' => (string) $author->user_nicename,
                    'profileHash' => hash('sha256', (string) wp_json_encode($profilePayload)),
                ),
                'listing' => $listing,
                'code' => $this->buildScopedRenderDependencySignature(
                    $scopedRenderDependencyTargets,
                    $renderDependencyTargetSignatures
                ),
                'layout' => $sharedLayoutDependency['signature'] ?? $this->emptySharedLayoutDependencyData()['signature'],
                'global' => $globalSignature,
            );

            return array(
                'url' => $url,
                'supported' => true,
                'token' => hash('sha256', (string) wp_json_encode($payload)),
                'tokenSource' => $tokenSource !== '' ? $tokenSource : 'wp-author-archive',
                'postId' => null,
                'dependencyPostIds' => $listing['postIds'],
                'reason' => null,
            );
        }

        if ($kind === 'date-archive') {
            $dateType = sanitize_key((string) ($resolved['dateType'] ?? ''));
            $year = absint($resolved['year'] ?? 0);
            $month = absint($resolved['month'] ?? 0);
            $day = absint($resolved['day'] ?? 0);

            if (!in_array($dateType, array('year', 'month', 'day'), true) || $year <= 0) {
                return null;
            }

            $queryArgs = array(
                'post_type' => 'post',
                'paged' => $paged,
                'year' => $year,
            );

            if (in_array($dateType, array('month', 'day'), true)) {
                if ($month < 1 || $month > 12) {
                    return null;
                }
                $queryArgs['monthnum'] = $month;
            }

            if ($dateType === 'day') {
                if ($day < 1 || !checkdate($month, $day, $year)) {
                    return null;
                }
                $queryArgs['day'] = $day;
            }

            $listing = $this->buildArchivePostQuerySignature($queryArgs);
            $scopedRenderDependencyTargets = $this->collectScopedRenderDependencyTargetsForArchive(
                $resolved,
                $listing,
                $renderDependencyTargetSignatures
            );
            $scopedRenderDependencyTargets = array_merge(
                $scopedRenderDependencyTargets,
                (array) ($sharedLayoutDependency['targets'] ?? array())
            );
            $payload = array(
                'url' => $url,
                'archive' => array(
                    'kind' => 'date-archive',
                    'dateType' => $dateType,
                    'paged' => $paged,
                    'year' => $year,
                    'month' => $month > 0 ? $month : null,
                    'day' => $day > 0 ? $day : null,
                ),
                'listing' => $listing,
                'code' => $this->buildScopedRenderDependencySignature(
                    $scopedRenderDependencyTargets,
                    $renderDependencyTargetSignatures
                ),
                'layout' => $sharedLayoutDependency['signature'] ?? $this->emptySharedLayoutDependencyData()['signature'],
                'global' => $globalSignature,
            );

            return array(
                'url' => $url,
                'supported' => true,
                'token' => hash('sha256', (string) wp_json_encode($payload)),
                'tokenSource' => $tokenSource !== '' ? $tokenSource : 'wp-date-archive',
                'postId' => null,
                'dependencyPostIds' => $listing['postIds'],
                'reason' => null,
            );
        }

        if ($kind === 'post-type-archive') {
            $postType = sanitize_key((string) ($resolved['postType'] ?? ''));
            if ($postType === '') {
                return null;
            }

            $postTypeObject = get_post_type_object($postType);
            if (!($postTypeObject instanceof \WP_Post_Type)) {
                return null;
            }

            $listing = $this->buildArchivePostQuerySignature(array(
                'post_type' => $postType,
                'paged' => $paged,
            ));
            $scopedRenderDependencyTargets = $this->collectScopedRenderDependencyTargetsForArchive(
                $resolved,
                $listing,
                $renderDependencyTargetSignatures
            );
            $scopedRenderDependencyTargets = array_merge(
                $scopedRenderDependencyTargets,
                (array) ($sharedLayoutDependency['targets'] ?? array())
            );

            $payload = array(
                'url' => $url,
                'archive' => array(
                    'kind' => 'post-type-archive',
                    'paged' => $paged,
                    'postType' => $postType,
                    'label' => (string) ($postTypeObject->labels->name ?? $postTypeObject->label ?? $postType),
                    'hasArchive' => (string) $postTypeObject->has_archive,
                ),
                'listing' => $listing,
                'code' => $this->buildScopedRenderDependencySignature(
                    $scopedRenderDependencyTargets,
                    $renderDependencyTargetSignatures
                ),
                'layout' => $sharedLayoutDependency['signature'] ?? $this->emptySharedLayoutDependencyData()['signature'],
                'global' => $globalSignature,
            );

            return array(
                'url' => $url,
                'supported' => true,
                'token' => hash('sha256', (string) wp_json_encode($payload)),
                'tokenSource' => $tokenSource !== '' ? $tokenSource : 'wp-post-type-archive',
                'postId' => null,
                'dependencyPostIds' => $listing['postIds'],
                'reason' => null,
            );
        }

        return null;
    }

    private function collectScopedRenderDependencyTargetsForArchive(array $resolved, array $listing, array $renderDependencyTargetSignatures): array
    {
        $targets = array();
        $kind = sanitize_key((string) ($resolved['kind'] ?? ''));

        if ($kind === 'posts-archive' && isset($resolved['page']) && $resolved['page'] instanceof \WP_Post) {
            $targets = array_merge(
                $targets,
                $this->collectScopedRenderDependencyTargetsForPost($resolved['page'], $renderDependencyTargetSignatures)
            );
        }

        if ($kind === 'term-archive' && isset($resolved['term']) && $resolved['term'] instanceof \WP_Term) {
            $targets = array_merge(
                $targets,
                $this->collectScopedRenderDependencyTargetsForText(
                    (string) $resolved['term']->description,
                    $renderDependencyTargetSignatures
                )
            );
        }

        $postIds = isset($listing['postIds']) && is_array($listing['postIds'])
            ? $listing['postIds']
            : array();

        foreach ($postIds as $postId) {
            $listedPost = get_post((int) $postId);
            if (!($listedPost instanceof \WP_Post)) {
                continue;
            }

            $targets = array_merge(
                $targets,
                $this->collectScopedRenderDependencyTargetsForPost($listedPost, $renderDependencyTargetSignatures)
            );
        }

        return $this->normalizeScopedRenderDependencyTargets($targets, $renderDependencyTargetSignatures);
    }

    private function collectScopedRenderDependencyTargetsForPost(\WP_Post $post, array $renderDependencyTargetSignatures): array
    {
        static $cache = array();

        $cacheKey = (int) $post->ID . ':' . (string) ($post->post_modified_gmt ?: $post->post_modified);
        if (isset($cache[$cacheKey]) && is_array($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $targets = $this->collectScopedRenderDependencyTargetsForText(
            (string) $post->post_content,
            $renderDependencyTargetSignatures
        );

        $elementorData = get_post_meta($post->ID, '_elementor_data', true);
        if (is_string($elementorData) && $elementorData !== '') {
            $elementorTarget = $this->matchScopedRenderDependencyTargetBySlug('elementor', $renderDependencyTargetSignatures);
            if ($elementorTarget !== null) {
                $targets[] = $elementorTarget;
            }

            $elements = json_decode($elementorData, true);
            if (is_array($elements)) {
                $this->collectScopedRenderDependencyTargetsForElementorElements(
                    $elements,
                    $renderDependencyTargetSignatures,
                    $targets
                );
            }
        }

        $cache[$cacheKey] = $this->normalizeScopedRenderDependencyTargets($targets, $renderDependencyTargetSignatures);
        return $cache[$cacheKey];
    }

    private function collectScopedRenderDependencyTargetsForText(string $content, array $renderDependencyTargetSignatures): array
    {
        if ($content === '') {
            return array();
        }

        $targets = array();
        $this->collectScopedRenderDependencyTargetsForShortcodes($content, $renderDependencyTargetSignatures, $targets);

        if (function_exists('has_blocks') && has_blocks($content)) {
            $blocks = parse_blocks($content);
            if (is_array($blocks)) {
                $this->collectScopedRenderDependencyTargetsForBlocks($blocks, $renderDependencyTargetSignatures, $targets);
            }
        }

        return $this->normalizeScopedRenderDependencyTargets($targets, $renderDependencyTargetSignatures);
    }

    private function collectScopedRenderDependencyTargetsForBlocks(array $blocks, array $renderDependencyTargetSignatures, array &$targets): void
    {
        static $blockTypeCache = array();

        foreach ($blocks as $block) {
            $blockName = isset($block['blockName']) ? trim((string) $block['blockName']) : '';
            if ($blockName !== '') {
                $this->addScopedRenderDependencyTargetForBlockName($blockName, $renderDependencyTargetSignatures, $targets);

                if (isset($blockTypeCache[$blockName])) {
                    $blockType = $blockTypeCache[$blockName];
                } else {
                    $blockType = null;
                    if (class_exists('\WP_Block_Type_Registry')) {
                        $registry = \WP_Block_Type_Registry::get_instance();
                        if (is_object($registry) && method_exists($registry, 'get_registered')) {
                            $candidate = $registry->get_registered($blockName);
                            if ($candidate instanceof \WP_Block_Type) {
                                $blockType = $candidate;
                            }
                        } elseif (is_object($registry) && method_exists($registry, 'get_all_registered')) {
                            $allRegistered = $registry->get_all_registered();
                            if (is_array($allRegistered) && isset($allRegistered[$blockName]) && $allRegistered[$blockName] instanceof \WP_Block_Type) {
                                $blockType = $allRegistered[$blockName];
                            }
                        }
                    }

                    $blockTypeCache[$blockName] = $blockType;
                }

                if ($blockType instanceof \WP_Block_Type) {
                    $renderCallback = $blockType->render_callback ?? null;
                    if ($renderCallback !== null) {
                        $this->addScopedRenderDependencyTargetsForCallback(
                            $renderCallback,
                            $renderDependencyTargetSignatures,
                            $targets
                        );
                    }

                    $blockFile = isset($blockType->file) && is_string($blockType->file)
                        ? $blockType->file
                        : '';
                    if ($blockFile !== '') {
                        $this->addScopedRenderDependencyTargetForFilePath(
                            $blockFile,
                            $renderDependencyTargetSignatures,
                            $targets
                        );
                    }
                }
            }

            $innerHtml = (string) ($block['innerHTML'] ?? '');
            if ($innerHtml !== '') {
                $this->collectScopedRenderDependencyTargetsForShortcodes(
                    $innerHtml,
                    $renderDependencyTargetSignatures,
                    $targets
                );
            }

            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $this->collectScopedRenderDependencyTargetsForBlocks(
                    $block['innerBlocks'],
                    $renderDependencyTargetSignatures,
                    $targets
                );
            }
        }
    }

    private function addScopedRenderDependencyTargetForBlockName(string $blockName, array $renderDependencyTargetSignatures, array &$targets): void
    {
        $parts = explode('/', $blockName, 2);
        $namespace = strtolower(trim((string) ($parts[0] ?? '')));
        if ($namespace === '' || $namespace === 'core') {
            return;
        }

        $target = $this->matchScopedRenderDependencyTargetBySlug($namespace, $renderDependencyTargetSignatures);
        if ($target !== null) {
            $targets[] = $target;
        }
    }

    private function collectScopedRenderDependencyTargetsForShortcodes(string $content, array $renderDependencyTargetSignatures, array &$targets): void
    {
        if ($content === '' || !function_exists('get_shortcode_regex')) {
            return;
        }

        global $shortcode_tags;
        if (!is_array($shortcode_tags) || empty($shortcode_tags)) {
            return;
        }

        $pattern = get_shortcode_regex();
        if (!preg_match_all('/' . $pattern . '/s', $content, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $match) {
            $shortcodeTag = isset($match[2]) ? sanitize_key((string) $match[2]) : '';
            if ($shortcodeTag === '') {
                continue;
            }

            $callback = $shortcode_tags[$shortcodeTag] ?? null;
            if ($callback !== null) {
                $this->addScopedRenderDependencyTargetsForCallback(
                    $callback,
                    $renderDependencyTargetSignatures,
                    $targets
                );
            }

            $target = $this->matchScopedRenderDependencyTargetBySlug($shortcodeTag, $renderDependencyTargetSignatures);
            if ($target !== null) {
                $targets[] = $target;
            }
        }
    }

    private function collectScopedRenderDependencyTargetsForElementorElements(array $elements, array $renderDependencyTargetSignatures, array &$targets): void
    {
        foreach ($elements as $element) {
            $widgetType = sanitize_key((string) ($element['widgetType'] ?? $element['elType'] ?? ''));
            if ($widgetType !== '') {
                $target = $this->resolveElementorWidgetRenderDependencyTarget($widgetType, $renderDependencyTargetSignatures);
                if ($target !== null) {
                    $targets[] = $target;
                }
            }

            $settings = isset($element['settings']) && is_array($element['settings'])
                ? $element['settings']
                : array();

            if (in_array($widgetType, array('text-editor', 'html', 'shortcode'), true)) {
                $content = (string) ($settings['editor'] ?? $settings['html'] ?? $settings['shortcode'] ?? '');
                if ($content !== '') {
                    $this->collectScopedRenderDependencyTargetsForShortcodes(
                        $content,
                        $renderDependencyTargetSignatures,
                        $targets
                    );
                }
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $this->collectScopedRenderDependencyTargetsForElementorElements(
                    $element['elements'],
                    $renderDependencyTargetSignatures,
                    $targets
                );
            }
        }
    }

    private function resolveElementorWidgetRenderDependencyTarget(string $widgetType, array $renderDependencyTargetSignatures): ?string
    {
        static $cache = array();

        $normalizedWidgetType = sanitize_key($widgetType);
        if ($normalizedWidgetType === '') {
            return null;
        }

        if (array_key_exists($normalizedWidgetType, $cache)) {
            return $cache[$normalizedWidgetType];
        }

        $fallback = $this->matchScopedRenderDependencyTargetBySlug($normalizedWidgetType, $renderDependencyTargetSignatures);

        if (!class_exists('\Elementor\Plugin')) {
            $cache[$normalizedWidgetType] = $fallback;
            return $cache[$normalizedWidgetType];
        }

        try {
            $elementorInstance = \Elementor\Plugin::$instance ?? null;
        } catch (\Throwable $error) {
            unset($error);
            $cache[$normalizedWidgetType] = $fallback;
            return $cache[$normalizedWidgetType];
        }

        if (!is_object($elementorInstance) || !isset($elementorInstance->widgets_manager) || !is_object($elementorInstance->widgets_manager)) {
            $cache[$normalizedWidgetType] = $fallback;
            return $cache[$normalizedWidgetType];
        }

        $widgetsManager = $elementorInstance->widgets_manager;
        if (!method_exists($widgetsManager, 'get_widget_types')) {
            $cache[$normalizedWidgetType] = $fallback;
            return $cache[$normalizedWidgetType];
        }

        $widgetTypes = $widgetsManager->get_widget_types();
        if (!is_array($widgetTypes)) {
            $cache[$normalizedWidgetType] = $fallback;
            return $cache[$normalizedWidgetType];
        }

        $widget = $widgetTypes[$widgetType] ?? $widgetTypes[$normalizedWidgetType] ?? null;
        if (!is_object($widget)) {
            $cache[$normalizedWidgetType] = $fallback;
            return $cache[$normalizedWidgetType];
        }

        try {
            $reflection = new \ReflectionClass($widget);
            $fileName = $reflection->getFileName();
            if (is_string($fileName) && $fileName !== '') {
                $resolvedTarget = $this->resolveScopedRenderDependencyTargetForFilePath(
                    $fileName,
                    $renderDependencyTargetSignatures
                );
                if ($resolvedTarget !== null) {
                    $cache[$normalizedWidgetType] = $resolvedTarget;
                    return $cache[$normalizedWidgetType];
                }
            }
        } catch (\ReflectionException $error) {
            unset($error);
        }

        $cache[$normalizedWidgetType] = $fallback;
        return $cache[$normalizedWidgetType];
    }

    private function addScopedRenderDependencyTargetsForCallback($callback, array $renderDependencyTargetSignatures, array &$targets): void
    {
        try {
            if (is_string($callback) && $callback !== '') {
                if (strpos($callback, '::') !== false) {
                    list($className, $methodName) = explode('::', $callback, 2);
                    $reflection = new \ReflectionMethod($className, $methodName);
                } else {
                    $reflection = new \ReflectionFunction($callback);
                }
            } elseif ($callback instanceof \Closure) {
                $reflection = new \ReflectionFunction($callback);
            } elseif (is_array($callback) && count($callback) === 2 && isset($callback[0], $callback[1])) {
                $reflection = new \ReflectionMethod($callback[0], (string) $callback[1]);
            } elseif (is_object($callback) && method_exists($callback, '__invoke')) {
                $reflection = new \ReflectionMethod($callback, '__invoke');
            } else {
                return;
            }
        } catch (\ReflectionException $error) {
            unset($error);
            return;
        }

        $fileName = $reflection->getFileName();
        if (!is_string($fileName) || $fileName === '') {
            return;
        }

        $this->addScopedRenderDependencyTargetForFilePath($fileName, $renderDependencyTargetSignatures, $targets);
    }

    private function addScopedRenderDependencyTargetForFilePath(string $filePath, array $renderDependencyTargetSignatures, array &$targets): void
    {
        $target = $this->resolveScopedRenderDependencyTargetForFilePath($filePath, $renderDependencyTargetSignatures);
        if ($target !== null) {
            $targets[] = $target;
        }
    }

    private function resolveScopedRenderDependencyTargetForFilePath(string $filePath, array $renderDependencyTargetSignatures): ?string
    {
        $normalizedFilePath = wp_normalize_path($filePath);
        if ($normalizedFilePath === '') {
            return null;
        }

        $bestLabel = null;
        $bestLength = -1;

        foreach ($renderDependencyTargetSignatures as $label => $targetSignature) {
            if (!is_array($targetSignature) || !$this->isScopedRenderDependencyTargetLabel((string) $label)) {
                continue;
            }

            $targetPath = wp_normalize_path((string) ($targetSignature['path'] ?? ''));
            if ($targetPath === '') {
                continue;
            }

            $targetDirectory = is_dir($targetPath) ? rtrim($targetPath, '/') . '/' : '';
            $matches = $normalizedFilePath === $targetPath
                || ($targetDirectory !== '' && strpos($normalizedFilePath, $targetDirectory) === 0);

            if ($matches && strlen($targetPath) > $bestLength) {
                $bestLabel = (string) $label;
                $bestLength = strlen($targetPath);
            }
        }

        return $bestLabel;
    }

    private function matchScopedRenderDependencyTargetBySlug(string $slug, array $renderDependencyTargetSignatures): ?string
    {
        $normalizedSlug = strtolower(str_replace('_', '-', trim($slug)));
        if ($normalizedSlug === '') {
            return null;
        }

        foreach ($renderDependencyTargetSignatures as $label => $targetSignature) {
            if (!is_array($targetSignature) || !$this->isScopedRenderDependencyTargetLabel((string) $label)) {
                continue;
            }

            $targetSlug = $this->scopedRenderDependencyTargetSlug((string) $label);
            if ($targetSlug === '') {
                continue;
            }

            if ($targetSlug === $normalizedSlug) {
                return (string) $label;
            }
        }

        foreach ($renderDependencyTargetSignatures as $label => $targetSignature) {
            if (!is_array($targetSignature) || !$this->isScopedRenderDependencyTargetLabel((string) $label)) {
                continue;
            }

            $targetSlug = $this->scopedRenderDependencyTargetSlug((string) $label);
            if ($targetSlug === '') {
                continue;
            }

            if (strpos($normalizedSlug, $targetSlug . '-') === 0 || strpos($normalizedSlug, $targetSlug . '_') === 0) {
                return (string) $label;
            }
        }

        return null;
    }

    private function scopedRenderDependencyTargetSlug(string $label): string
    {
        if (strpos($label, 'plugin-dir:') === 0) {
            return strtolower(str_replace('_', '-', substr($label, strlen('plugin-dir:'))));
        }

        if (strpos($label, 'mu-plugin-dir:') === 0) {
            return strtolower(str_replace('_', '-', substr($label, strlen('mu-plugin-dir:'))));
        }

        if (strpos($label, 'plugin-file:') === 0) {
            return strtolower(str_replace('_', '-', pathinfo(substr($label, strlen('plugin-file:')), PATHINFO_FILENAME)));
        }

        if (strpos($label, 'mu-plugin-file:') === 0) {
            return strtolower(str_replace('_', '-', pathinfo(substr($label, strlen('mu-plugin-file:')), PATHINFO_FILENAME)));
        }

        return '';
    }

    private function isScopedRenderDependencyTargetLabel(string $label): bool
    {
        return strpos($label, 'plugin-dir:') === 0
            || strpos($label, 'plugin-file:') === 0
            || strpos($label, 'mu-plugin-dir:') === 0
            || strpos($label, 'mu-plugin-file:') === 0;
    }

    private function normalizeScopedRenderDependencyTargets(array $targets, array $renderDependencyTargetSignatures): array
    {
        $normalizedTargets = array();

        foreach ($targets as $target) {
            $label = sanitize_text_field((string) $target);
            if ($label === '' || !$this->isScopedRenderDependencyTargetLabel($label) || !isset($renderDependencyTargetSignatures[$label])) {
                continue;
            }

            $normalizedTargets[$label] = true;
        }

        $labels = array_keys($normalizedTargets);
        sort($labels, SORT_STRING);
        return $labels;
    }

    private function buildScopedRenderDependencySignature(array $targets, array $renderDependencyTargetSignatures): array
    {
        $labels = $this->normalizeScopedRenderDependencyTargets($targets, $renderDependencyTargetSignatures);
        $signatureTargets = array();

        foreach ($labels as $label) {
            $targetSignature = $renderDependencyTargetSignatures[$label] ?? null;
            if (!is_array($targetSignature)) {
                continue;
            }

            $signatureTargets[] = array(
                'label' => $label,
                'hash' => sanitize_text_field((string) ($targetSignature['hash'] ?? '')),
            );
        }

        return array(
            'hash' => hash('sha256', (string) wp_json_encode($signatureTargets)),
            'targets' => $signatureTargets,
        );
    }

    private function emptySharedLayoutDependencyData(): array
    {
        return array(
            'targets' => array(),
            'signature' => array(
                'hash' => hash('sha256', '[]'),
                'items' => array(),
            ),
        );
    }

    private function collectSharedLayoutDependencyDataForUrl(string $url, array $renderDependencyTargetSignatures, ?\WP_Query $query = null, string $forcedTemplateType = ''): array
    {
        static $cache = array();

        $normalizedUrl = $this->normalizePublicUrlForChangeToken($url);
        if ($normalizedUrl === null) {
            return $this->emptySharedLayoutDependencyData();
        }

        $cacheKey = $normalizedUrl . '|' . sanitize_key($forcedTemplateType);
        if ($query === null && isset($cache[$cacheKey]) && is_array($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        if (!current_theme_supports('block-templates') || !function_exists('get_query_template')) {
            $result = $this->emptySharedLayoutDependencyData();
            if ($query === null) {
                $cache[$cacheKey] = $result;
            }
            return $result;
        }

        $resolvedQuery = $query instanceof \WP_Query
            ? $query
            : $this->buildTrackedRouteQueryForUrl($normalizedUrl);
        if (!($resolvedQuery instanceof \WP_Query) && $forcedTemplateType !== '') {
            $resolvedQuery = new \WP_Query();
            if ($forcedTemplateType === '404') {
                $resolvedQuery->is_404 = true;
            }
        }

        if (!($resolvedQuery instanceof \WP_Query)) {
            $result = $this->emptySharedLayoutDependencyData();
            if ($query === null) {
                $cache[$cacheKey] = $result;
            }
            return $result;
        }

        // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress core exposes the resolved block-template state through these globals.
        global $wp_query, $wp_the_query, $post, $_wp_current_template_id, $_wp_current_template_content;

        $originalWpQuery = $wp_query ?? null;
        $originalWpTheQuery = $wp_the_query ?? null;
        $originalPost = $post ?? null;
        $originalTemplateId = $_wp_current_template_id ?? null;
        $originalTemplateContent = $_wp_current_template_content ?? null;

        $templatePath = '';
        $templateId = '';
        $templateContent = '';

        try {
            $wp_query = $resolvedQuery;
            $wp_the_query = $resolvedQuery;
            $queriedObject = $resolvedQuery->get_queried_object();
            if ($queriedObject instanceof \WP_Post) {
                $post = $queriedObject;
            }

            $_wp_current_template_id = null;
            $_wp_current_template_content = null;

            $templatePath = $this->resolveQueryTemplatePathForCurrentQuery($forcedTemplateType);
            $templateId = sanitize_text_field((string) ($_wp_current_template_id ?? ''));
            $templateContent = is_string($_wp_current_template_content ?? null)
                ? (string) $_wp_current_template_content
                : '';
        } finally {
            $wp_query = $originalWpQuery;
            $wp_the_query = $originalWpTheQuery;
            $post = $originalPost;
            $_wp_current_template_id = $originalTemplateId;
            $_wp_current_template_content = $originalTemplateContent;
            // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        }

        if ($templateId === '' && $templateContent === '') {
            $result = $this->emptySharedLayoutDependencyData();
            if ($query === null) {
                $cache[$cacheKey] = $result;
            }
            return $result;
        }

        $layoutItems = array();
        $targets = $this->collectScopedRenderDependencyTargetsForText(
            $templateContent,
            $renderDependencyTargetSignatures
        );

        $templateObject = function_exists('get_block_template') && $templateId !== ''
            ? get_block_template($templateId, 'wp_template')
            : null;
        $layoutItems[] = $this->buildSharedLayoutDependencyItemFromTemplate(
            'template',
            $templateId,
            is_object($templateObject) ? $templateObject : null,
            $templateContent,
            $templatePath
        );

        $visitedTemplateRefs = array();
        if ($templateId !== '') {
            $visitedTemplateRefs['wp_template:' . $templateId] = true;
        }
        $visitedPostRefs = array();

        if ($templateContent !== '' && function_exists('has_blocks') && has_blocks($templateContent)) {
            $blocks = parse_blocks($templateContent);
            if (is_array($blocks)) {
                $this->collectSharedLayoutDependencyReferencesFromBlocks(
                    $blocks,
                    $renderDependencyTargetSignatures,
                    $layoutItems,
                    $targets,
                    $visitedTemplateRefs,
                    $visitedPostRefs
                );
            }
        }

        $result = array(
            'targets' => $this->normalizeScopedRenderDependencyTargets($targets, $renderDependencyTargetSignatures),
            'signature' => array(
                'hash' => hash('sha256', (string) wp_json_encode($layoutItems)),
                'items' => $layoutItems,
            ),
        );

        if ($query === null) {
            $cache[$cacheKey] = $result;
        }

        return $result;
    }

    private function resolveQueryTemplatePathForCurrentQuery(string $forcedTemplateType = ''): string
    {
        if ($forcedTemplateType === '404' && function_exists('get_404_template')) {
            return (string) get_404_template();
        }

        if (function_exists('is_404') && is_404() && function_exists('get_404_template')) {
            return (string) get_404_template();
        }

        if (function_exists('is_front_page') && is_front_page() && function_exists('get_front_page_template')) {
            return (string) get_front_page_template();
        }

        if (function_exists('is_home') && is_home() && function_exists('get_home_template')) {
            return (string) get_home_template();
        }

        if (function_exists('is_post_type_archive') && is_post_type_archive() && function_exists('get_post_type_archive_template')) {
            return (string) get_post_type_archive_template();
        }

        if (function_exists('is_tax') && is_tax() && function_exists('get_taxonomy_template')) {
            return (string) get_taxonomy_template();
        }

        if (function_exists('is_category') && is_category() && function_exists('get_category_template')) {
            return (string) get_category_template();
        }

        if (function_exists('is_tag') && is_tag() && function_exists('get_tag_template')) {
            return (string) get_tag_template();
        }

        if (function_exists('is_author') && is_author() && function_exists('get_author_template')) {
            return (string) get_author_template();
        }

        if (function_exists('is_date') && is_date() && function_exists('get_date_template')) {
            return (string) get_date_template();
        }

        if (function_exists('is_page') && is_page() && function_exists('get_page_template')) {
            return (string) get_page_template();
        }

        if (function_exists('is_single') && is_single() && function_exists('get_single_template')) {
            return (string) get_single_template();
        }

        if (function_exists('is_singular') && is_singular() && function_exists('get_singular_template')) {
            return (string) get_singular_template();
        }

        if (function_exists('is_search') && is_search() && function_exists('get_search_template')) {
            return (string) get_search_template();
        }

        if (function_exists('is_archive') && is_archive() && function_exists('get_archive_template')) {
            return (string) get_archive_template();
        }

        if (function_exists('get_index_template')) {
            return (string) get_index_template();
        }

        return '';
    }

    private function buildSharedLayoutDependencyItemFromTemplate(string $kind, string $fallbackId, $template, string $fallbackContent, string $templatePath = ''): array
    {
        $templateId = is_object($template) && isset($template->id)
            ? sanitize_text_field((string) $template->id)
            : sanitize_text_field($fallbackId);
        $content = is_object($template) && isset($template->content)
            ? (string) $template->content
            : $fallbackContent;

        $item = array(
            'kind' => $kind,
            'id' => $templateId,
            'slug' => is_object($template) && isset($template->slug)
                ? sanitize_text_field((string) $template->slug)
                : '',
            'source' => is_object($template) && isset($template->source)
                ? sanitize_key((string) $template->source)
                : '',
            'contentHash' => hash('sha256', $content),
        );

        if ($templatePath !== '') {
            $item['templatePath'] = wp_normalize_path($templatePath);
        }

        if (is_object($template) && isset($template->has_theme_file)) {
            $item['hasThemeFile'] = (bool) $template->has_theme_file;
        }

        $wpId = is_object($template) && isset($template->wp_id) ? absint($template->wp_id) : 0;
        if ($wpId > 0) {
            $item['wpId'] = $wpId;
            $templatePost = get_post($wpId);
            if ($templatePost instanceof \WP_Post) {
                $item['modifiedGmt'] = (string) ($templatePost->post_modified_gmt ?: $templatePost->post_modified);
            }
        }

        return $item;
    }

    private function buildSharedLayoutDependencyItemFromPost(string $kind, \WP_Post $post): array
    {
        return array(
            'kind' => $kind,
            'postId' => (int) $post->ID,
            'postType' => (string) $post->post_type,
            'slug' => (string) $post->post_name,
            'modifiedGmt' => (string) ($post->post_modified_gmt ?: $post->post_modified),
            'contentHash' => hash('sha256', (string) $post->post_content),
        );
    }

    private function collectSharedLayoutDependencyReferencesFromBlocks(array $blocks, array $renderDependencyTargetSignatures, array &$layoutItems, array &$targets, array &$visitedTemplateRefs, array &$visitedPostRefs): void
    {
        static $blockTemplateCache = array();

        foreach ($blocks as $block) {
            $blockName = isset($block['blockName']) ? trim((string) $block['blockName']) : '';
            $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();

            if ($blockName === 'core/template-part' && function_exists('get_block_template')) {
                $slug = sanitize_title((string) ($attrs['slug'] ?? ''));
                $theme = sanitize_text_field((string) ($attrs['theme'] ?? get_stylesheet()));
                if ($slug !== '' && $theme !== '') {
                    $templatePartId = $theme . '//' . $slug;
                    $visitedKey = 'wp_template_part:' . $templatePartId;
                    if (!isset($visitedTemplateRefs[$visitedKey])) {
                        $visitedTemplateRefs[$visitedKey] = true;

                        if (isset($blockTemplateCache[$visitedKey])) {
                            $templatePart = $blockTemplateCache[$visitedKey];
                        } else {
                            $templatePart = get_block_template($templatePartId, 'wp_template_part');
                            $blockTemplateCache[$visitedKey] = $templatePart ?: false;
                        }

                        if (is_object($templatePart)) {
                            $templatePartContent = isset($templatePart->content) ? (string) $templatePart->content : '';
                            $layoutItems[] = $this->buildSharedLayoutDependencyItemFromTemplate(
                                'template-part',
                                $templatePartId,
                                $templatePart,
                                $templatePartContent
                            );
                            $targets = array_merge(
                                $targets,
                                $this->collectScopedRenderDependencyTargetsForText($templatePartContent, $renderDependencyTargetSignatures)
                            );
                            if ($templatePartContent !== '' && function_exists('has_blocks') && has_blocks($templatePartContent)) {
                                $templatePartBlocks = parse_blocks($templatePartContent);
                                if (is_array($templatePartBlocks)) {
                                    $this->collectSharedLayoutDependencyReferencesFromBlocks(
                                        $templatePartBlocks,
                                        $renderDependencyTargetSignatures,
                                        $layoutItems,
                                        $targets,
                                        $visitedTemplateRefs,
                                        $visitedPostRefs
                                    );
                                }
                            }
                        }
                    }
                }
            }

            if (($blockName === 'core/block' || $blockName === 'core/navigation') && !empty($attrs['ref'])) {
                $referencedPostId = absint($attrs['ref']);
                $visitedKey = $blockName . ':' . $referencedPostId;
                if ($referencedPostId > 0 && !isset($visitedPostRefs[$visitedKey])) {
                    $visitedPostRefs[$visitedKey] = true;
                    $referencedPost = get_post($referencedPostId);
                    if ($referencedPost instanceof \WP_Post) {
                        $layoutItems[] = $this->buildSharedLayoutDependencyItemFromPost(
                            $blockName === 'core/block' ? 'reusable-block' : 'navigation-post',
                            $referencedPost
                        );
                        $targets = array_merge(
                            $targets,
                            $this->collectScopedRenderDependencyTargetsForText(
                                (string) $referencedPost->post_content,
                                $renderDependencyTargetSignatures
                            )
                        );

                        if (function_exists('has_blocks') && has_blocks($referencedPost->post_content)) {
                            $referencedBlocks = parse_blocks((string) $referencedPost->post_content);
                            if (is_array($referencedBlocks)) {
                                $this->collectSharedLayoutDependencyReferencesFromBlocks(
                                    $referencedBlocks,
                                    $renderDependencyTargetSignatures,
                                    $layoutItems,
                                    $targets,
                                    $visitedTemplateRefs,
                                    $visitedPostRefs
                                );
                            }
                        }
                    }
                }
            }

            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $this->collectSharedLayoutDependencyReferencesFromBlocks(
                    $block['innerBlocks'],
                    $renderDependencyTargetSignatures,
                    $layoutItems,
                    $targets,
                    $visitedTemplateRefs,
                    $visitedPostRefs
                );
            }
        }
    }

    private function buildArchivePostQuerySignature(array $queryArgs): array
    {
        $query = new \WP_Query(array_merge(
            array(
                'post_status' => 'publish',
                'ignore_sticky_posts' => false,
                'fields' => 'ids',
                'no_found_rows' => false,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ),
            $queryArgs
        ));

        $postIds = array();
        $posts = array();
        foreach ((array) $query->posts as $postId) {
            $post = get_post((int) $postId);
            if (!($post instanceof \WP_Post)) {
                continue;
            }

            $postIds[] = (int) $post->ID;
            $posts[] = array(
                'id' => (int) $post->ID,
                'type' => (string) $post->post_type,
                'status' => (string) $post->post_status,
                'modifiedGmt' => (string) ($post->post_modified_gmt ?: $post->post_modified),
            );
        }

        return array(
            'paged' => max(1, absint($queryArgs['paged'] ?? 1)),
            'foundPosts' => (int) $query->found_posts,
            'maxNumPages' => (int) $query->max_num_pages,
            'postIds' => $postIds,
            'posts' => $posts,
        );
    }

    private function resolveTrackedRouteForUrl(string $url): ?array
    {
        $normalizedUrl = $this->normalizePublicUrlForChangeToken($url);
        if ($normalizedUrl === null) {
            return null;
        }

        $resolved = $this->resolveTrackedRouteForNormalizedUrl($normalizedUrl);
        if (is_array($resolved)) {
            return $resolved;
        }

        return $this->resolveTrackedRouteWithWordPressQuery($normalizedUrl);
    }

    private function resolveTrackedRouteForNormalizedUrl(string $normalizedUrl): ?array
    {
        if ($normalizedUrl === '') {
            return null;
        }

        $showOnFront = sanitize_text_field((string) get_option('show_on_front'));
        $pageForPosts = absint(get_option('page_for_posts'));
        if ($showOnFront === 'posts') {
            $homePaged = $this->matchPaginatedArchiveUrl($normalizedUrl, home_url('/'));
            if ($homePaged !== null) {
                return array(
                    'kind' => 'posts-archive',
                    'tokenSource' => 'wp-posts-home',
                    'paged' => $homePaged,
                    'page' => null,
                );
            }
        } elseif ($pageForPosts > 0) {
            $postsPageUrl = get_permalink($pageForPosts);
            if (is_string($postsPageUrl) && $postsPageUrl !== '') {
                $postsPagePaged = $this->matchPaginatedArchiveUrl($normalizedUrl, $postsPageUrl);
                if ($postsPagePaged !== null) {
                    $postsPage = get_post($pageForPosts);
                    return array(
                        'kind' => 'posts-archive',
                        'tokenSource' => 'wp-posts-page',
                        'paged' => $postsPagePaged,
                        'page' => $postsPage instanceof \WP_Post ? $postsPage : null,
                    );
                }
            }
        }

        $postTypeArchive = $this->resolveTrackedPostTypeArchiveForUrl($normalizedUrl);
        if ($postTypeArchive !== null) {
            return $postTypeArchive;
        }

        $taxonomyArchive = $this->resolveTrackedTaxonomyArchiveForUrl($normalizedUrl);
        if ($taxonomyArchive !== null) {
            return $taxonomyArchive;
        }

        $authorArchive = $this->resolveTrackedAuthorArchiveForUrl($normalizedUrl);
        if ($authorArchive !== null) {
            return $authorArchive;
        }

        $dateArchive = $this->resolveTrackedDateArchiveForUrl($normalizedUrl);
        if ($dateArchive !== null) {
            return $dateArchive;
        }

        $postId = url_to_postid($normalizedUrl);
        if ($postId > 0) {
            $post = get_post($postId);
            if ($post instanceof \WP_Post) {
                return array(
                    'kind' => 'post',
                    'post' => $post,
                    'tokenSource' => 'wp-singular',
                );
            }
        }

        $homeUrl = $this->normalizePublicUrlForChangeToken(home_url('/'));
        $frontPageId = absint(get_option('page_on_front'));

        if (
            $showOnFront === 'page' &&
            $frontPageId > 0 &&
            $homeUrl !== null &&
            $normalizedUrl !== null &&
            $this->matchPaginatedArchiveUrl($normalizedUrl, $homeUrl) === 1
        ) {
            $frontPage = get_post($frontPageId);
            if ($frontPage instanceof \WP_Post) {
                return array(
                    'kind' => 'post',
                    'post' => $frontPage,
                    'tokenSource' => 'wp-front-page',
                );
            }
        }

        return null;
    }

    private function resolveTrackedRouteWithWordPressQuery(string $normalizedUrl): ?array
    {
        $query = $this->buildTrackedRouteQueryForUrl($normalizedUrl);
        if (!($query instanceof \WP_Query) || $query->is_404()) {
            return null;
        }

        $paged = max(1, absint($query->get('paged')));
        $pageForPosts = absint(get_option('page_for_posts'));
        $frontPageId = absint(get_option('page_on_front'));

        if ($query->is_home()) {
            $queriedObject = $query->get_queried_object();
            $postsPage = $queriedObject instanceof \WP_Post
                ? $queriedObject
                : ($pageForPosts > 0 ? get_post($pageForPosts) : null);
            return array(
                'kind' => 'posts-archive',
                'tokenSource' => $postsPage instanceof \WP_Post ? 'wp-posts-page' : 'wp-posts-home',
                'paged' => $paged,
                'page' => $postsPage instanceof \WP_Post ? $postsPage : null,
            );
        }

        if ($query->is_post_type_archive()) {
            $postTypeObject = $query->get_queried_object();
            if ($postTypeObject instanceof \WP_Post_Type && $postTypeObject->name !== '') {
                return array(
                    'kind' => 'post-type-archive',
                    'tokenSource' => 'wp-post-type-archive',
                    'paged' => $paged,
                    'postType' => sanitize_key((string) $postTypeObject->name),
                );
            }

            $postType = $query->get('post_type');
            if (is_string($postType) && $postType !== '') {
                return array(
                    'kind' => 'post-type-archive',
                    'tokenSource' => 'wp-post-type-archive',
                    'paged' => $paged,
                    'postType' => sanitize_key($postType),
                );
            }
        }

        if ($query->is_category() || $query->is_tag() || $query->is_tax()) {
            $term = $query->get_queried_object();
            if ($term instanceof \WP_Term) {
                return array(
                    'kind' => 'term-archive',
                    'tokenSource' => 'wp-taxonomy-archive',
                    'paged' => $paged,
                    'term' => $term,
                );
            }
        }

        if ($query->is_author()) {
            $author = $query->get_queried_object();
            if ($author instanceof \WP_User) {
                return array(
                    'kind' => 'author-archive',
                    'tokenSource' => 'wp-author-archive',
                    'paged' => $paged,
                    'author' => $author,
                );
            }
        }

        if ($query->is_date()) {
            $year = absint($query->get('year'));
            $month = absint($query->get('monthnum'));
            $day = absint($query->get('day'));
            if ($year > 0 && $month > 0 && $day > 0) {
                return array(
                    'kind' => 'date-archive',
                    'tokenSource' => 'wp-date-archive-day',
                    'dateType' => 'day',
                    'paged' => $paged,
                    'year' => $year,
                    'month' => $month,
                    'day' => $day,
                );
            }

            if ($year > 0 && $month > 0) {
                return array(
                    'kind' => 'date-archive',
                    'tokenSource' => 'wp-date-archive-month',
                    'dateType' => 'month',
                    'paged' => $paged,
                    'year' => $year,
                    'month' => $month,
                );
            }

            if ($year > 0) {
                return array(
                    'kind' => 'date-archive',
                    'tokenSource' => 'wp-date-archive-year',
                    'dateType' => 'year',
                    'paged' => $paged,
                    'year' => $year,
                );
            }
        }

        if ($query->is_singular()) {
            $post = $query->get_queried_object();
            if ($post instanceof \WP_Post) {
                return array(
                    'kind' => 'post',
                    'post' => $post,
                    'tokenSource' => $frontPageId > 0 && (int) $post->ID === $frontPageId
                        ? 'wp-front-page'
                        : 'wp-singular',
                );
            }
        }

        return null;
    }

    private function buildTrackedRouteQueryForUrl(string $normalizedUrl): ?\WP_Query
    {
        $parts = wp_parse_url($normalizedUrl);
        if (!is_array($parts)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');
        $queryString = isset($parts['query']) ? (string) $parts['query'] : '';
        $requestUri = $path . ($queryString !== '' ? '?' . $queryString : '');

        $queryVars = array();
        if ($queryString !== '') {
            parse_str($queryString, $queryVars);
        }

        global $wp;

        $originalWp = isset($wp) ? $wp : null;
        // phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- This helper snapshots the current request globals only to restore the pre-simulation environment unchanged after the synthetic query runs.
        $originalGet = $_GET;
        $originalRequest = $_REQUEST;
        $originalServer = array(
            'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
            'PATH_INFO' => $_SERVER['PATH_INFO'] ?? null,
            'PHP_SELF' => $_SERVER['PHP_SELF'] ?? null,
            'QUERY_STRING' => $_SERVER['QUERY_STRING'] ?? null,
        );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash

        try {
            $_GET = is_array($queryVars) ? $queryVars : array();
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Synthetic query simulation mirrors GET vars into REQUEST for WordPress core parsing only; no user-submitted action is processed here.
            $_REQUEST = $_GET;
            $_SERVER['REQUEST_URI'] = $requestUri;
            $_SERVER['PATH_INFO'] = $path;
            $_SERVER['PHP_SELF'] = $path;
            $_SERVER['QUERY_STRING'] = $queryString;

            $wp = new \WP();
            if ($wp->parse_request() === false) {
                return null;
            }

            $query = new \WP_Query();
            $query->query($wp->query_vars);
            return $query;
        } finally {
            $wp = $originalWp;
            $_GET = $originalGet;
            $_REQUEST = $originalRequest;

            foreach ($originalServer as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key]);
                    continue;
                }

                $_SERVER[$key] = $value;
            }
        }
    }

    private function resolveTrackedPostTypeArchiveForUrl(string $normalizedUrl): ?array
    {
        $postTypes = get_post_types(array('public' => true), 'objects');
        if (!is_array($postTypes)) {
            return null;
        }

        foreach ($postTypes as $postType => $postTypeObject) {
            if (!($postTypeObject instanceof \WP_Post_Type) || !$postTypeObject->has_archive) {
                continue;
            }

            $archiveUrl = get_post_type_archive_link($postType);
            if (!is_string($archiveUrl) || $archiveUrl === '') {
                continue;
            }

            $paged = $this->matchPaginatedArchiveUrl($normalizedUrl, $archiveUrl);
            if ($paged === null) {
                continue;
            }

            return array(
                'kind' => 'post-type-archive',
                'tokenSource' => 'wp-post-type-archive',
                'paged' => $paged,
                'postType' => sanitize_key((string) $postType),
            );
        }

        return null;
    }

    private function resolveTrackedAuthorArchiveForUrl(string $normalizedUrl): ?array
    {
        $slug = $this->extractArchiveSlugFromUrl($normalizedUrl);
        if ($slug === null || $slug === '') {
            return null;
        }

        $author = get_user_by('slug', $slug);
        if (!($author instanceof \WP_User)) {
            return null;
        }

        $authorLink = get_author_posts_url((int) $author->ID, (string) $author->user_nicename);
        if (!is_string($authorLink) || $authorLink === '') {
            return null;
        }

        $paged = $this->matchPaginatedArchiveUrl($normalizedUrl, $authorLink);
        if ($paged === null) {
            return null;
        }

        return array(
            'kind' => 'author-archive',
            'tokenSource' => 'wp-author-archive',
            'paged' => $paged,
            'author' => $author,
        );
    }

    private function resolveTrackedDateArchiveForUrl(string $normalizedUrl): ?array
    {
        $segments = $this->extractArchivePathSegments($normalizedUrl);
        $count = count($segments);

        if ($count >= 3) {
            $yearSegment = (string) $segments[$count - 3];
            $monthSegment = (string) $segments[$count - 2];
            $daySegment = (string) $segments[$count - 1];

            if (preg_match('/^\d{4}$/', $yearSegment) && ctype_digit($monthSegment) && ctype_digit($daySegment)) {
                $year = (int) $yearSegment;
                $month = (int) $monthSegment;
                $day = (int) $daySegment;
                if ($month >= 1 && $month <= 12 && checkdate($month, $day, $year)) {
                    $archiveUrl = get_day_link($year, $month, $day);
                    if (is_string($archiveUrl) && $archiveUrl !== '') {
                        $paged = $this->matchPaginatedArchiveUrl($normalizedUrl, $archiveUrl);
                        if ($paged !== null) {
                            return array(
                                'kind' => 'date-archive',
                                'tokenSource' => 'wp-date-archive-day',
                                'dateType' => 'day',
                                'paged' => $paged,
                                'year' => $year,
                                'month' => $month,
                                'day' => $day,
                            );
                        }
                    }
                }
            }
        }

        if ($count >= 2) {
            $yearSegment = (string) $segments[$count - 2];
            $monthSegment = (string) $segments[$count - 1];

            if (preg_match('/^\d{4}$/', $yearSegment) && ctype_digit($monthSegment)) {
                $year = (int) $yearSegment;
                $month = (int) $monthSegment;
                if ($month >= 1 && $month <= 12) {
                    $archiveUrl = get_month_link($year, $month);
                    if (is_string($archiveUrl) && $archiveUrl !== '') {
                        $paged = $this->matchPaginatedArchiveUrl($normalizedUrl, $archiveUrl);
                        if ($paged !== null) {
                            return array(
                                'kind' => 'date-archive',
                                'tokenSource' => 'wp-date-archive-month',
                                'dateType' => 'month',
                                'paged' => $paged,
                                'year' => $year,
                                'month' => $month,
                            );
                        }
                    }
                }
            }
        }

        if ($count >= 1) {
            $yearSegment = (string) $segments[$count - 1];
            if (preg_match('/^\d{4}$/', $yearSegment)) {
                $year = (int) $yearSegment;
                $archiveUrl = get_year_link($year);
                if (is_string($archiveUrl) && $archiveUrl !== '') {
                    $paged = $this->matchPaginatedArchiveUrl($normalizedUrl, $archiveUrl);
                    if ($paged !== null) {
                        return array(
                            'kind' => 'date-archive',
                            'tokenSource' => 'wp-date-archive-year',
                            'dateType' => 'year',
                            'paged' => $paged,
                            'year' => $year,
                        );
                    }
                }
            }
        }

        return null;
    }

    private function resolveTrackedTaxonomyArchiveForUrl(string $normalizedUrl): ?array
    {
        $slug = $this->extractArchiveSlugFromUrl($normalizedUrl);
        if ($slug === null || $slug === '') {
            return null;
        }

        $taxonomies = get_taxonomies(array('public' => true), 'names');
        if (!is_array($taxonomies)) {
            return null;
        }

        foreach ($taxonomies as $taxonomy) {
            $term = get_term_by('slug', $slug, (string) $taxonomy);
            if (!($term instanceof \WP_Term)) {
                continue;
            }

            $termLink = get_term_link($term);
            if (is_wp_error($termLink) || !is_string($termLink) || $termLink === '') {
                continue;
            }

            $paged = $this->matchPaginatedArchiveUrl($normalizedUrl, $termLink);
            if ($paged === null) {
                continue;
            }

            return array(
                'kind' => 'term-archive',
                'tokenSource' => 'wp-taxonomy-archive',
                'paged' => $paged,
                'term' => $term,
            );
        }

        return null;
    }

    private function extractArchiveSlugFromUrl(string $normalizedUrl): ?string
    {
        $segments = $this->extractArchivePathSegments($normalizedUrl);
        if (empty($segments)) {
            return null;
        }

        return sanitize_title(urldecode((string) end($segments)));
    }

    private function extractArchivePathSegments(string $normalizedUrl): array
    {
        $parts = wp_parse_url($normalizedUrl);
        if (!is_array($parts)) {
            return array();
        }

        $path = (string) ($parts['path'] ?? '/');
        $segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
        $count = count($segments);
        if ($count >= 2 && $segments[$count - 2] === 'page' && ctype_digit($segments[$count - 1])) {
            array_pop($segments);
            array_pop($segments);
        }

        return $segments;
    }

    private function matchPaginatedArchiveUrl(string $normalizedUrl, string $archiveUrl): ?int
    {
        $normalizedArchiveUrl = $this->normalizePublicUrlForChangeToken($archiveUrl);
        if ($normalizedArchiveUrl === null) {
            return null;
        }

        $currentParts = wp_parse_url($normalizedUrl);
        $archiveParts = wp_parse_url($normalizedArchiveUrl);
        if (!is_array($currentParts) || !is_array($archiveParts)) {
            return null;
        }

        $currentOrigin = strtolower((string) ($currentParts['scheme'] ?? '')) . '://' . strtolower((string) ($currentParts['host'] ?? ''));
        $archiveOrigin = strtolower((string) ($archiveParts['scheme'] ?? '')) . '://' . strtolower((string) ($archiveParts['host'] ?? ''));
        if (!empty($currentParts['port'])) {
            $currentOrigin .= ':' . absint($currentParts['port']);
        }
        if (!empty($archiveParts['port'])) {
            $archiveOrigin .= ':' . absint($archiveParts['port']);
        }

        if ($currentOrigin !== $archiveOrigin) {
            return null;
        }

        if ((string) ($currentParts['query'] ?? '') !== (string) ($archiveParts['query'] ?? '')) {
            return null;
        }

        $currentPath = $this->normalizeTrackedRoutePath((string) ($currentParts['path'] ?? '/'));
        $archivePath = $this->normalizeTrackedRoutePath((string) ($archiveParts['path'] ?? '/'));

        if ($currentPath === $archivePath) {
            return 1;
        }

        $pattern = $archivePath === ''
            ? '#^/page/([0-9]+)$#'
            : '#^' . preg_quote($archivePath, '#') . '/page/([0-9]+)$#';

        $matchTarget = $currentPath === '' ? '/' : $currentPath;
        if (preg_match($pattern, $matchTarget, $matches)) {
            return max(1, absint($matches[1]));
        }

        return null;
    }

    private function normalizeTrackedRoutePath(string $path): string
    {
        $normalized = '/' . trim($path, '/');
        if ($normalized === '/') {
            return '';
        }

        return rtrim($normalized, '/');
    }

    private function collectReferencedPostIdsForPost(\WP_Post $post): array
    {
        $referenced = array();

        if (has_blocks($post->post_content)) {
            $blocks = parse_blocks($post->post_content);
            $this->collectBlockPostReferences($blocks, (int) $post->ID, $referenced);
        }

        $this->collectShortcodePostReferences($post->post_content, (int) $post->ID, $referenced);

        $elementorData = get_post_meta($post->ID, '_elementor_data', true);
        if (is_string($elementorData) && $elementorData !== '') {
            $elements = json_decode($elementorData, true);
            if (is_array($elements)) {
                $this->collectElementorPostReferences($elements, (int) $post->ID, $referenced);
            }
        }

        sort($referenced);
        return array_values(array_unique($referenced));
    }

    private function collectBlockPostReferences(array $blocks, int $currentPostId, array &$referenced): void
    {
        foreach ($blocks as $block) {
            $blockName = $block['blockName'] ?? '';
            $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();

            switch ($blockName) {
                case 'core/post-content':
                case 'core/post-featured-image':
                case 'core/post-title':
                    if (!empty($attrs['postId'])) {
                        $this->addReferencedPostId($referenced, (int) $attrs['postId'], $currentPostId);
                    }
                    break;

                case 'core/latest-posts':
                    if (!empty($attrs['selectedPosts']) && is_array($attrs['selectedPosts'])) {
                        foreach ($attrs['selectedPosts'] as $postId) {
                            $this->addReferencedPostId($referenced, (int) $postId, $currentPostId);
                        }
                    }
                    break;

                case 'core/query':
                    if (!empty($attrs['query']['include'])) {
                        $include = is_string($attrs['query']['include'])
                            ? explode(',', $attrs['query']['include'])
                            : (array) $attrs['query']['include'];
                        foreach ($include as $postId) {
                            $this->addReferencedPostId($referenced, (int) $postId, $currentPostId);
                        }
                    }
                    break;

                case 'core/embed':
                    $embedUrl = sanitize_text_field((string) ($attrs['url'] ?? ''));
                    if ($embedUrl !== '' && preg_match('/[?&]p=(\d+)/', $embedUrl, $matches)) {
                        $this->addReferencedPostId($referenced, (int) $matches[1], $currentPostId);
                    }
                    break;
            }

            $innerHtml = (string) ($block['innerHTML'] ?? '');
            if ($innerHtml !== '') {
                $this->collectShortcodePostReferences($innerHtml, $currentPostId, $referenced);
            }

            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $this->collectBlockPostReferences($block['innerBlocks'], $currentPostId, $referenced);
            }
        }
    }

    private function collectElementorPostReferences(array $elements, int $currentPostId, array &$referenced): void
    {
        foreach ($elements as $element) {
            $widgetType = $element['widgetType'] ?? $element['elType'] ?? '';
            $settings = isset($element['settings']) && is_array($element['settings']) ? $element['settings'] : array();

            switch ($widgetType) {
                case 'posts':
                case 'archive-posts':
                case 'loop-grid':
                    if (!empty($settings['posts_post_id'])) {
                        $postIds = is_string($settings['posts_post_id'])
                            ? explode(',', $settings['posts_post_id'])
                            : (array) $settings['posts_post_id'];
                        foreach ($postIds as $postId) {
                            $this->addReferencedPostId($referenced, (int) $postId, $currentPostId);
                        }
                    }
                    break;

                case 'single-post':
                    if (!empty($settings['post_id'])) {
                        $this->addReferencedPostId($referenced, (int) $settings['post_id'], $currentPostId);
                    }
                    break;
            }

            if (in_array($widgetType, array('text-editor', 'html', 'shortcode'), true)) {
                $content = (string) ($settings['editor'] ?? $settings['html'] ?? $settings['shortcode'] ?? '');
                if ($content !== '') {
                    $this->collectShortcodePostReferences($content, $currentPostId, $referenced);
                }
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $this->collectElementorPostReferences($element['elements'], $currentPostId, $referenced);
            }
        }
    }

    private function collectShortcodePostReferences(string $content, int $currentPostId, array &$referenced): void
    {
        if ($content === '') {
            return;
        }

        if (preg_match_all('/\[\w+[^\]]*\bid\s*=\s*["\']?(\d+)["\']?[^\]]*\]/', $content, $matches)) {
            foreach ($matches[1] as $postId) {
                $this->addReferencedPostId($referenced, (int) $postId, $currentPostId);
            }
        }

        if (preg_match_all('/\[\w+[^\]]*\bposts?\s*=\s*["\']?([0-9,\s]+)["\']?[^\]]*\]/', $content, $matches)) {
            foreach ($matches[1] as $idList) {
                $postIds = preg_split('/[,\s]+/', trim((string) $idList), -1, PREG_SPLIT_NO_EMPTY);
                if (!is_array($postIds)) {
                    continue;
                }
                foreach ($postIds as $postId) {
                    $this->addReferencedPostId($referenced, (int) $postId, $currentPostId);
                }
            }
        }

        if (preg_match_all('/(?:href|src)\s*=\s*["\'][^"\']*[?&]p=(\d+)[^"\']*["\']/', $content, $matches)) {
            foreach ($matches[1] as $postId) {
                $this->addReferencedPostId($referenced, (int) $postId, $currentPostId);
            }
        }
    }

    private function addReferencedPostId(array &$referenced, int $postId, int $currentPostId): void
    {
        if ($postId <= 0 || $postId === $currentPostId) {
            return;
        }

        $referenced[] = $postId;
    }

    private function shouldIncludeRenderDependencyFile(string $filePath): bool
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($extension, array('php', 'js', 'css', 'json', 'html', 'mjs', 'cjs'), true);
    }

    private function shouldIgnoreRenderDependencyPath(string $filePath): bool
    {
        $normalizedPath = strtolower(wp_normalize_path($filePath));
        if ($normalizedPath === '') {
            return true;
        }

        foreach (array(
            '/.github/',
            '/docs/',
            '/doc/',
            '/tests/',
            '/test/',
            '/spec/',
            '/specs/',
            '/fixtures/',
            '/fixture/',
            '/examples/',
            '/example/',
            '/coverage/',
            '/node_modules/',
        ) as $pathFragment) {
            if (strpos($normalizedPath, $pathFragment) !== false) {
                return true;
            }
        }

        $baseName = basename($normalizedPath);
        if (preg_match('/^(readme|changelog|license|copying)(\.[a-z0-9._-]+)?$/', $baseName) === 1) {
            return true;
        }

        if (preg_match('/^(package(-lock)?\.json|composer\.(json|lock)|yarn\.lock|pnpm-lock\.yaml|phpunit\.xml(\.dist)?|phpcs\.xml(\.dist)?|vite\.config\.[a-z0-9]+|webpack(\.[a-z0-9_-]+)?\.config\.[a-z0-9]+|postcss\.config\.[a-z0-9]+|eslint\.config\.[a-z0-9]+|tsconfig(\.[a-z0-9_-]+)?\.json|\.eslintrc(\.[a-z0-9_-]+)?|\.prettierrc(\.[a-z0-9_-]+)?|prettier\.config\.[a-z0-9]+|babel\.config\.[a-z0-9]+|rollup\.config\.[a-z0-9]+|gruntfile\.[a-z0-9]+|gulpfile\.[a-z0-9]+)$/', $baseName) === 1) {
            return true;
        }

        return false;
    }

    private function collectRenderDependencyFileSignatureEntries(string $targetPath, string $label, array &$entries, int &$latestModified): void
    {
        $normalizedTarget = wp_normalize_path($targetPath);
        if ($normalizedTarget === '') {
            return;
        }

        if (is_file($normalizedTarget)) {
            if (!$this->shouldIncludeRenderDependencyFile($normalizedTarget) || $this->shouldIgnoreRenderDependencyPath($normalizedTarget)) {
                return;
            }

            $fileStat = @stat($normalizedTarget);
            if (!is_array($fileStat)) {
                return;
            }

            $modifiedAt = isset($fileStat['mtime']) ? (int) $fileStat['mtime'] : 0;
            $latestModified = max($latestModified, $modifiedAt);
            $entries[] = $label . ':file:' . basename($normalizedTarget) . ':' . filesize($normalizedTarget) . ':' . $modifiedAt;
            return;
        }

        if (!is_dir($normalizedTarget)) {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($normalizedTarget, \FilesystemIterator::SKIP_DOTS)
            );
        } catch (\UnexpectedValueException $error) {
            unset($error);
            return;
        }

        foreach ($iterator as $fileInfo) {
            if (!($fileInfo instanceof \SplFileInfo) || !$fileInfo->isFile()) {
                continue;
            }

            $filePath = wp_normalize_path($fileInfo->getPathname());
            if (!$this->shouldIncludeRenderDependencyFile($filePath) || $this->shouldIgnoreRenderDependencyPath($filePath)) {
                continue;
            }

            $relativePath = ltrim(substr($filePath, strlen($normalizedTarget)), '/');
            $modifiedAt = (int) $fileInfo->getMTime();
            $latestModified = max($latestModified, $modifiedAt);
            $entries[] = $label . ':dir:' . $relativePath . ':' . $fileInfo->getSize() . ':' . $modifiedAt;
        }
    }

    private function buildActiveCodeRenderDependencyTargets(): array
    {
        $targets = array();

        $templateDirectory = wp_normalize_path((string) get_template_directory());
        if ($templateDirectory !== '') {
            $targets['theme-template'] = $templateDirectory;
        }

        $stylesheetDirectory = wp_normalize_path((string) get_stylesheet_directory());
        if ($stylesheetDirectory !== '') {
            $targets['theme-stylesheet'] = $stylesheetDirectory;
        }

        $pluginRoot = defined('WP_PLUGIN_DIR') ? wp_normalize_path((string) WP_PLUGIN_DIR) : '';
        if (function_exists('wp_get_active_and_valid_plugins')) {
            foreach (wp_get_active_and_valid_plugins() as $pluginFile) {
                $normalizedPluginFile = wp_normalize_path((string) $pluginFile);
                if ($normalizedPluginFile === '') {
                    continue;
                }

                $pluginBaseName = function_exists('plugin_basename')
                    ? (string) plugin_basename($normalizedPluginFile)
                    : basename($normalizedPluginFile);
                $pluginDirectory = wp_normalize_path(dirname($normalizedPluginFile));
                if ($pluginRoot !== '' && $pluginDirectory === $pluginRoot) {
                    $targets['plugin-file:' . $pluginBaseName] = $normalizedPluginFile;
                    continue;
                }

                $targets['plugin-dir:' . dirname($pluginBaseName)] = $pluginDirectory;
            }
        }

        $muPluginRoot = defined('WPMU_PLUGIN_DIR') ? wp_normalize_path((string) WPMU_PLUGIN_DIR) : '';
        if (function_exists('wp_get_mu_plugins')) {
            foreach (wp_get_mu_plugins() as $muPluginFile) {
                $normalizedPluginFile = wp_normalize_path((string) $muPluginFile);
                if ($normalizedPluginFile === '') {
                    continue;
                }

                $relativeName = basename($normalizedPluginFile);
                $pluginDirectory = wp_normalize_path(dirname($normalizedPluginFile));
                if ($muPluginRoot !== '' && $pluginDirectory === $muPluginRoot) {
                    $targets['mu-plugin-file:' . $relativeName] = $normalizedPluginFile;
                    continue;
                }

                $targets['mu-plugin-dir:' . basename($pluginDirectory)] = $pluginDirectory;
            }
        }

        ksort($targets, SORT_STRING);

        return $targets;
    }

    private function computeActiveCodeRenderDependencyTargetSignatures(): array
    {
        $signatures = array();

        foreach ($this->buildActiveCodeRenderDependencyTargets() as $label => $targetPath) {
            $entries = array();
            $latestModified = 0;
            $this->collectRenderDependencyFileSignatureEntries((string) $targetPath, (string) $label, $entries, $latestModified);
            sort($entries, SORT_STRING);

            $signatures[(string) $label] = array(
                'path' => wp_normalize_path((string) $targetPath),
                'hash' => hash('sha256', implode("\n", $entries)),
                'entryCount' => count($entries),
                'latestModifiedGmt' => $latestModified > 0 ? gmdate('c', $latestModified) : '',
            );
        }

        return $signatures;
    }

    private function computeElementorLibraryRenderDependencySignature(): array
    {
        $templateIds = get_posts(array(
            'post_type' => 'elementor_library',
            'post_status' => array('publish', 'private'),
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'suppress_filters' => false,
        ));

        if (!is_array($templateIds)) {
            return array(
                'hash' => hash('sha256', '[]'),
                'count' => 0,
            );
        }

        $signature = array();
        foreach ($templateIds as $templateId) {
            $template = get_post((int) $templateId);
            if (!($template instanceof \WP_Post)) {
                continue;
            }

            $signature[] = array(
                'id' => (int) $template->ID,
                'status' => (string) $template->post_status,
                'modifiedGmt' => (string) ($template->post_modified_gmt ?: $template->post_modified),
                'templateType' => sanitize_key((string) get_post_meta($template->ID, '_elementor_template_type', true)),
                'conditionsHash' => hash('sha256', (string) wp_json_encode(get_post_meta($template->ID, '_elementor_conditions', true))),
            );
        }

        return array(
            'hash' => hash('sha256', (string) wp_json_encode($signature)),
            'count' => count($signature),
        );
    }

    private function computeGlobalRenderDependencySignature(array $renderDependencyTargetSignatures): array
    {
        $theme = wp_get_theme();
        $themeMods = get_theme_mods();
        $menuSignature = array();
        $menus = wp_get_nav_menus();
        if (is_array($menus)) {
            foreach ($menus as $menu) {
                if (!($menu instanceof \WP_Term)) {
                    continue;
                }

                $items = wp_get_nav_menu_items($menu->term_id, array(
                    'post_status' => 'any',
                ));
                $itemSignature = array();
                if (is_array($items)) {
                    foreach ($items as $item) {
                        if (!($item instanceof \WP_Post)) {
                            continue;
                        }
                        $itemSignature[] = array(
                            'id' => (int) $item->ID,
                            'modifiedGmt' => (string) ($item->post_modified_gmt ?: $item->post_modified),
                        );
                    }
                }

                $menuSignature[] = array(
                    'termId' => (int) $menu->term_id,
                    'name' => (string) $menu->name,
                    'items' => $itemSignature,
                );
            }
        }

        $elementorActiveKitId = absint(get_option('elementor_active_kit'));
        $elementorActiveKit = $elementorActiveKitId > 0 ? get_post($elementorActiveKitId) : null;
        $elementorLibrarySignature = $this->computeElementorLibraryRenderDependencySignature();
        $themeTemplateSignature = isset($renderDependencyTargetSignatures['theme-template']) && is_array($renderDependencyTargetSignatures['theme-template'])
            ? $renderDependencyTargetSignatures['theme-template']
            : array();
        $themeStylesheetSignature = isset($renderDependencyTargetSignatures['theme-stylesheet']) && is_array($renderDependencyTargetSignatures['theme-stylesheet'])
            ? $renderDependencyTargetSignatures['theme-stylesheet']
            : array();

        return array(
            'stylesheet' => (string) $theme->get_stylesheet(),
            'themeVersion' => (string) $theme->get('Version'),
            'themeModsHash' => hash('sha256', (string) wp_json_encode($themeMods)),
            'menuSignatureHash' => hash('sha256', (string) wp_json_encode($menuSignature)),
            'themeTemplateCodeHash' => sanitize_text_field((string) ($themeTemplateSignature['hash'] ?? '')),
            'themeTemplateCodeLatestModifiedGmt' => sanitize_text_field((string) ($themeTemplateSignature['latestModifiedGmt'] ?? '')),
            'themeStylesheetCodeHash' => sanitize_text_field((string) ($themeStylesheetSignature['hash'] ?? '')),
            'themeStylesheetCodeLatestModifiedGmt' => sanitize_text_field((string) ($themeStylesheetSignature['latestModifiedGmt'] ?? '')),
            'showOnFront' => sanitize_text_field((string) get_option('show_on_front')),
            'pageOnFront' => absint(get_option('page_on_front')),
            'pageForPosts' => absint(get_option('page_for_posts')),
            'elementorActiveKitId' => $elementorActiveKitId,
            'elementorActiveKitModifiedGmt' => $elementorActiveKit instanceof \WP_Post
                ? (string) ($elementorActiveKit->post_modified_gmt ?: $elementorActiveKit->post_modified)
                : '',
            'elementorLibrarySignatureHash' => sanitize_text_field((string) ($elementorLibrarySignature['hash'] ?? '')),
            'elementorLibraryCount' => absint($elementorLibrarySignature['count'] ?? 0),
        );
    }

    public function findQueuedJobById(string $jobId): ?array
    {
        $queue = $this->readQueue();
        foreach ($queue as $job) {
            if (!is_array($job)) {
                continue;
            }
            $id = isset($job['id']) ? sanitize_text_field((string) $job['id']) : '';
            if ($id !== '' && $id === $jobId) {
                return $job;
            }
        }
        return null;
    }

    public function buildJobDownloadPayload(array $job, array $config): array
    {
        $sanitizedJob = $this->sanitizeJobForState($job);
        $manualCommands = $this->buildManualJobExecutionCommands($sanitizedJob);

        return array(
            'generatedAt' => gmdate('c'),
            'job' => $sanitizedJob,
            'publisherConfig' => $config,
            'manualExecution' => array(
                'notes' => array(
                    'Use this file when server-side prerequisites are missing and jobs must run from your own shell/CI.',
                    'This JSON is a wrapper payload. Extract the nested publisherConfig object into ./publisher.config.json before running the commands below.',
                    'Install Node.js, the @smart-cloud/publisher-exporter CLI package, and Playwright Chromium before running crawl.',
                    'Running the downloaded job locally does not mark the queued item as completed in WordPress; it is an out-of-band replay of the same instructions.',
                    'Use the deploySdk and invalidateSdk commands below to run deploy and CDN invalidation with the same downloaded publisherConfig.',
                ),
                'links' => array(
                    'playwrightDocs' => 'https://playwright.dev/docs/intro',
                ),
                'commands' => $manualCommands,
            ),
        );
    }

    private function buildManualJobExecutionCommands(array $job): array
    {
        return array(
            'extractPublisherConfigNode' => 'node -e ' . escapeshellarg("const fs = require('node:fs'); const payload = JSON.parse(fs.readFileSync('./queued-job.json', 'utf8')); fs.writeFileSync('./publisher.config.json', JSON.stringify(payload.publisherConfig, null, 2));"),
            'extractPublisherConfigPowerShell' => 'Get-Content .\\queued-job.json -Raw | ConvertFrom-Json | Select-Object -ExpandProperty publisherConfig | ConvertTo-Json -Depth 100 | Set-Content .\\publisher.config.json',
            'jobPosix' => $this->buildManualJobCommandPosix($job),
            'jobPowerShell' => $this->buildManualJobCommandPowerShell($job),
            'crawl' => 'PUBLISHER_CONFIG=./publisher.config.json npx @smart-cloud/publisher-exporter crawl',
            'deploySdk' => 'PUBLISHER_CONFIG=./publisher.config.json npx @smart-cloud/publisher-exporter deploy',
            'invalidateSdk' => 'PUBLISHER_CONFIG=./publisher.config.json npx @smart-cloud/publisher-exporter invalidate',
        );
    }

    private function buildManualJobCommandPosix(array $job): string
    {
        $command = sanitize_text_field((string) ($job['command'] ?? ''));
        $crawlMode = sanitize_text_field((string) ($job['crawlMode'] ?? ''));
        $deploymentProfile = $this->sanitizeDeploymentProfileName($job['deploymentProfile'] ?? '');
        $url = trim((string) ($job['url'] ?? ''));
        $crawlArgs = '';
        $deploymentArgs = $deploymentProfile !== ''
            ? ' --profile ' . escapeshellarg($deploymentProfile)
            : '';

        if (($command === 'publish' || $command === 'crawl') && $crawlMode === 'incremental') {
            $crawlArgs = ' --crawl-mode incremental';
        } elseif ($command === 'retry-timeouts') {
            $crawlArgs = ' --retry-timeouts';
        } elseif ($command === 'url' && $url !== '') {
            $crawlArgs = ' --url ' . escapeshellarg($url);
        }

        $crawlCommand = 'PUBLISHER_CONFIG=./publisher.config.json npx @smart-cloud/publisher-exporter crawl' . $crawlArgs;
        $deployCommand = 'PUBLISHER_CONFIG=./publisher.config.json npx @smart-cloud/publisher-exporter deploy' . $deploymentArgs;
        $invalidateCommand = 'PUBLISHER_CONFIG=./publisher.config.json npx @smart-cloud/publisher-exporter invalidate' . $deploymentArgs;

        switch ($command) {
            case 'publish':
                return $crawlCommand . ' && ' . $deployCommand . ' && ' . $invalidateCommand;

            case 'crawl':
            case 'retry-timeouts':
            case 'url':
                return $crawlCommand;

            case 'deploy':
                return $deployCommand;

            case 'invalidate':
                return $invalidateCommand;

            default:
                return '# Unsupported queued command in downloaded payload';
        }
    }

    private function buildManualJobCommandPowerShell(array $job): string
    {
        $command = sanitize_text_field((string) ($job['command'] ?? ''));
        $crawlMode = sanitize_text_field((string) ($job['crawlMode'] ?? ''));
        $deploymentProfile = $this->sanitizeDeploymentProfileName($job['deploymentProfile'] ?? '');
        $url = trim((string) ($job['url'] ?? ''));
        $crawlArgs = '';
        $deploymentArgs = $deploymentProfile !== ''
            ? ' --profile ' . $this->quotePowerShellArgument($deploymentProfile)
            : '';

        if (($command === 'publish' || $command === 'crawl') && $crawlMode === 'incremental') {
            $crawlArgs = ' --crawl-mode incremental';
        } elseif ($command === 'retry-timeouts') {
            $crawlArgs = ' --retry-timeouts';
        } elseif ($command === 'url' && $url !== '') {
            $crawlArgs = ' --url ' . $this->quotePowerShellArgument($url);
        }

        $prefix = '$env:PUBLISHER_CONFIG="./publisher.config.json"; ';
        $crawlCommand = $prefix . 'npx @smart-cloud/publisher-exporter crawl' . $crawlArgs;
        $deployCommand = $prefix . 'npx @smart-cloud/publisher-exporter deploy' . $deploymentArgs;
        $invalidateCommand = $prefix . 'npx @smart-cloud/publisher-exporter invalidate' . $deploymentArgs;
        $guard = '; if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }';

        switch ($command) {
            case 'publish':
                return $crawlCommand . $guard . '; ' . $deployCommand . $guard . '; ' . $invalidateCommand;

            case 'crawl':
            case 'retry-timeouts':
            case 'url':
                return $crawlCommand;

            case 'deploy':
                return $deployCommand;

            case 'invalidate':
                return $invalidateCommand;

            default:
                return '# Unsupported queued command in downloaded payload';
        }
    }

    private function quotePowerShellArgument(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    public function getActiveStopRequest($currentRun = null): ?array
    {
        $paths = $this->getRuntimePaths();
        $stopRequest = $this->readJsonFile((string) ($paths['stopRequest'] ?? ''));
        if (!is_array($stopRequest)) {
            return null;
        }

        $targetJobId = sanitize_text_field((string) ($stopRequest['targetJobId'] ?? ''));
        $currentRunId = is_array($currentRun)
            ? sanitize_text_field((string) ($currentRun['id'] ?? ''))
            : '';

        if ($currentRunId !== '' && $targetJobId !== '' && $targetJobId !== $currentRunId) {
            return null;
        }

        return array(
            'requestedAt' => sanitize_text_field((string) ($stopRequest['requestedAt'] ?? '')),
            'targetJobId' => $targetJobId,
            'targetJobCommand' => sanitize_text_field((string) ($stopRequest['targetJobCommand'] ?? '')),
            'mode' => sanitize_text_field((string) ($stopRequest['mode'] ?? 'stop')),
        );
    }

    public function getRuntimePaths(): array
    {
        $upload = wp_get_upload_dir();
        $storageRoot = trailingslashit($upload['basedir']) . 'smartcloud-static-publisher';
        $runtime = trailingslashit($storageRoot) . 'runtime';
        $config = $this->getConfig();
        $logsRelative = $this->normalizeStorageRelativePath((string) ($config['logDir'] ?? 'logs'), 'logs');
        $logs = trailingslashit($storageRoot) . $logsRelative;

        return array(
            'runtime' => $runtime,
            'config' => trailingslashit($runtime) . 'config.json',
            'manifest' => trailingslashit($runtime) . 'manifest.json',
            'lock' => trailingslashit($runtime) . 'export.lock',
            'queueMutationLock' => trailingslashit($runtime) . 'queue-mutation.lock',
            'currentRun' => trailingslashit($runtime) . 'current-run.json',
            'stopRequest' => trailingslashit($runtime) . 'stop-request.json',
            'currentProgress' => trailingslashit($runtime) . 'current-progress.json',
            'currentCrawlEvent' => trailingslashit($logs) . 'current-crawl-event.json',
            'schedulerState' => trailingslashit($runtime) . 'scheduler-state.json',
            'lastRun' => trailingslashit($runtime) . 'last-run.json',
            'queue' => trailingslashit($runtime) . 'queue.json',
            'auditEvents' => trailingslashit($runtime) . 'audit-events.jsonl',
            'queueRunnerHeartbeat' => trailingslashit($runtime) . 'queue-runner-heartbeat.json',
            'deployDiff' => trailingslashit($runtime) . 'deploy-diff.json',
            'logs' => $logs,
        );
    }

    public function ingestRuntimeAuditEvents(): void
    {
        $paths = $this->getRuntimePaths();
        $auditPath = (string) ($paths['auditEvents'] ?? '');
        if ($auditPath === '' || !is_file($auditPath) || !is_readable($auditPath)) {
            return;
        }

        $size = filesize($auditPath);
        if (!is_int($size) || $size < 0) {
            return;
        }

        $offset = absint(get_option(self::OPTION_AUDIT_CURSOR_KEY, 0));
        if ($offset > $size) {
            $offset = 0;
        }

        $raw = $this->readFileContents($auditPath);
        if (!is_string($raw) || $raw === '') {
            return;
        }

        $rawSize = strlen($raw);
        if ($offset > $rawSize) {
            $offset = 0;
        }

        $slice = $offset > 0 ? substr($raw, $offset) : $raw;
        if (!is_string($slice) || $slice === '') {
            return;
        }

        preg_match_all('/.*(?:\r\n|\n|\r|$)/', $slice, $matches);
        $chunks = isset($matches[0]) && is_array($matches[0]) ? $matches[0] : array();

        foreach ($chunks as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = json_decode(trim($line), true);
            if (!is_array($decoded)) {
                continue;
            }

            $this->appendAuditLogEntry(array(
                'occurredAt' => isset($decoded['occurredAt']) ? (string) $decoded['occurredAt'] : gmdate('c'),
                'eventType' => isset($decoded['eventType']) ? (string) $decoded['eventType'] : 'runtime-event',
                'status' => isset($decoded['status']) ? (string) $decoded['status'] : 'info',
                'actorSource' => isset($decoded['actorSource']) ? (string) $decoded['actorSource'] : 'queue-runner',
                'actorUserId' => null,
                'jobId' => isset($decoded['jobId']) ? (string) $decoded['jobId'] : '',
                'command' => isset($decoded['command']) ? (string) $decoded['command'] : '',
                'message' => isset($decoded['message']) ? (string) $decoded['message'] : '',
                'details' => is_array($decoded['details'] ?? null) ? $decoded['details'] : array(),
            ));
        }

        $newOffset = $offset + strlen($slice);

        if (is_int($newOffset) && $newOffset >= 0) {
            update_option(self::OPTION_AUDIT_CURSOR_KEY, $newOffset, false);
        }
    }

    public function getAuditLogEntries(): array
    {
        $raw = get_option(self::OPTION_AUDIT_LOG_KEY);
        if (!is_array($raw)) {
            return array();
        }

        return array_values(array_filter($raw, 'is_array'));
    }

    public function appendAuditLogEntry(array $entry): void
    {
        $entries = $this->getAuditLogEntries();
        $clean = $this->sanitizeAuditLogEntry($entry);
        array_unshift($entries, $clean);

        $maxEntries = 2000;
        if (count($entries) > $maxEntries) {
            $entries = array_slice($entries, 0, $maxEntries);
        }

        update_option(self::OPTION_AUDIT_LOG_KEY, $entries, false);
    }

    private function sanitizeAuditLogEntry(array $entry): array
    {
        $occurredAt = sanitize_text_field((string) ($entry['occurredAt'] ?? gmdate('c')));
        if ($occurredAt === '') {
            $occurredAt = gmdate('c');
        }

        $eventType = strtolower(sanitize_text_field((string) ($entry['eventType'] ?? 'event')));
        if ($eventType === '') {
            $eventType = 'event';
        }

        $status = strtolower(sanitize_text_field((string) ($entry['status'] ?? 'info')));
        if (!in_array($status, array('info', 'success', 'failed', 'queued', 'running', 'stopped'), true)) {
            $status = 'info';
        }

        $actorSource = sanitize_text_field((string) ($entry['actorSource'] ?? 'system'));
        $actorUserId = isset($entry['actorUserId']) && $entry['actorUserId'] !== null ? absint($entry['actorUserId']) : null;
        $jobId = sanitize_text_field((string) ($entry['jobId'] ?? ''));
        $command = sanitize_text_field((string) ($entry['command'] ?? ''));
        $message = sanitize_text_field((string) ($entry['message'] ?? ''));
        $details = is_array($entry['details'] ?? null) ? $this->sanitizeAuditDetails($entry['details']) : array();

        return array(
            'id' => sanitize_text_field((string) ($entry['id'] ?? wp_generate_uuid4())),
            'occurredAt' => $occurredAt,
            'eventType' => $eventType,
            'status' => $status,
            'actorSource' => $actorSource,
            'actorUserId' => $actorUserId,
            'jobId' => $jobId,
            'command' => $command,
            'message' => $message,
            'details' => $details,
        );
    }

    private function sanitizeAuditDetails(array $details): array
    {
        $out = array();
        foreach ($details as $key => $value) {
            $safeKey = sanitize_key((string) $key);
            if ($safeKey === '') {
                continue;
            }

            if (is_array($value)) {
                $out[$safeKey] = $this->sanitizeAuditDetails($value);
                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value)) {
                $out[$safeKey] = $value;
                continue;
            }

            $out[$safeKey] = sanitize_text_field((string) $value);
        }

        return $out;
    }

    public function getRuntimeRelativePaths(): array
    {
        $upload = wp_get_upload_dir();
        $config = $this->getConfig();
        $logsRelative = $this->normalizeStorageRelativePath((string) ($config['logDir'] ?? 'logs'), 'logs');
        return array(
            'runtime' => trailingslashit($upload['baseurl']) . 'smartcloud-static-publisher/runtime/',
            'logs' => trailingslashit($upload['baseurl']) . 'smartcloud-static-publisher/' . trailingslashit($logsRelative),
        );
    }

    public function normalizeStorageRelativePath(string $value, string $fallback): string
    {
        $candidate = str_replace('\\', '/', trim($value));
        $candidate = trim($candidate, '/');
        if ($candidate === '') {
            return $fallback;
        }

        $segments = explode('/', $candidate);
        $clean = array();
        foreach ($segments as $segment) {
            $segment = sanitize_file_name($segment);
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }
            $clean[] = $segment;
        }

        if (empty($clean)) {
            return $fallback;
        }

        return implode('/', $clean);
    }

    private function getProtectedRuntimeFileNames(array $paths): array
    {
        $protected = array(
            'crawl-manifest.json' => true,
            'deploy-plan.json' => true,
        );

        foreach ($paths as $key => $filePath) {
            if ($key === 'logs' || $key === 'currentCrawlEvent') {
                continue;
            }

            $basename = basename((string) $filePath);
            if ($basename !== '') {
                $protected[$basename] = true;
            }
        }

        return $protected;
    }

    public function readQueue(): array
    {
        $paths = $this->getRuntimePaths();
        $queue = $this->readJsonFile($paths['queue']);
        return is_array($queue) ? array_values($queue) : array();
    }

    public function listLogFiles(): array
    {
        $paths = $this->getRuntimePaths();
        if (!is_dir($paths['logs'])) {
            return array();
        }

        $protectedRuntimeFiles = array();
        $realLogsDir = realpath((string) $paths['logs']);
        $realRuntimeDir = realpath((string) $paths['runtime']);
        if (
            is_string($realLogsDir)
            && is_string($realRuntimeDir)
            && wp_normalize_path($realLogsDir) === wp_normalize_path($realRuntimeDir)
        ) {
            $protectedRuntimeFiles = $this->getProtectedRuntimeFileNames($paths);
        }

        $entries = scandir($paths['logs']);
        if (!is_array($entries)) {
            return array();
        }

        $files = array();
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (isset($protectedRuntimeFiles[$entry])) {
                continue;
            }
            $path = trailingslashit($paths['logs']) . $entry;
            if (is_file($path)) {
                $files[] = $entry;
            }
        }

        sort($files, SORT_STRING);
        return $files;
    }

    public function enrichAuditLogEntryForResponse(array $entry): array
    {
        $artifacts = $this->listAuditArtifactsForEntry($entry);
        if (!empty($artifacts)) {
            $entry['artifacts'] = $artifacts;
        }

        return $entry;
    }

    private function listAuditArtifactsForEntry(array $entry): array
    {
        $details = is_array($entry['details'] ?? null) ? $entry['details'] : array();
        $archiveKey = $this->extractAuditArchiveKeyFromDetails($details);
        if ($archiveKey === '') {
            return array();
        }

        $archiveDir = $this->resolveAuditArchiveDir($archiveKey);
        if (!is_string($archiveDir) || !is_dir($archiveDir)) {
            return array();
        }

        $manifest = $this->readJsonFile(trailingslashit($archiveDir) . 'job.json');
        $artifacts = array();
        if (is_array($manifest) && is_array($manifest['artifacts'] ?? null)) {
            foreach ($manifest['artifacts'] as $artifact) {
                if (!is_array($artifact)) {
                    continue;
                }

                $record = $this->buildAuditArtifactDescriptor(
                    $archiveKey,
                    (string) ($artifact['storedFileName'] ?? ''),
                    $artifact
                );
                if (is_array($record)) {
                    $artifacts[] = $record;
                }
            }
        }

        if (!empty($artifacts)) {
            return array_values($artifacts);
        }

        return $this->buildFallbackAuditArtifacts($archiveKey, $archiveDir);
    }

    private function extractAuditArchiveKeyFromDetails(array $details): string
    {
        $archiveKey = sanitize_file_name($this->readAuditDetailString($details, 'logArchiveKey'));
        if ($archiveKey !== '') {
            return $archiveKey;
        }

        $archiveDir = trim($this->readAuditDetailString($details, 'logArchiveDir'));
        if ($archiveDir === '') {
            return '';
        }

        return sanitize_file_name(basename(wp_normalize_path($archiveDir)));
    }

    private function readAuditDetailString(array $details, string $key): string
    {
        $candidates = array_values(array_unique(array(
            $key,
            sanitize_key($key),
        )));

        foreach ($candidates as $candidate) {
            if (!array_key_exists($candidate, $details)) {
                continue;
            }

            return sanitize_text_field((string) $details[$candidate]);
        }

        return '';
    }

    private function buildFallbackAuditArtifacts(string $archiveKey, string $archiveDir): array
    {
        $entries = scandir($archiveDir);
        if (!is_array($entries)) {
            return array();
        }

        $artifacts = array();
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'job.json') {
                continue;
            }

            $compressed = substr($entry, -3) === '.gz';
            $record = $this->buildAuditArtifactDescriptor($archiveKey, $entry, array(
                'originalFileName' => $compressed ? substr($entry, 0, -3) : $entry,
                'compressed' => $compressed,
                'compression' => $compressed ? 'gzip' : '',
            ));
            if (is_array($record)) {
                $artifacts[] = $record;
            }
        }

        usort($artifacts, function ($left, $right): int {
            return strcmp(
                (string) ($left['storedFileName'] ?? ''),
                (string) ($right['storedFileName'] ?? '')
            );
        });

        return array_values($artifacts);
    }

    private function buildAuditArtifactDescriptor(string $archiveKey, string $storedFileName, array $manifestArtifact = array()): ?array
    {
        $storedFileName = basename($storedFileName);
        if ($storedFileName === '' || $storedFileName === 'job.json') {
            return null;
        }

        $path = $this->resolveAuditArchiveFilePath($archiveKey, $storedFileName);
        if (!is_string($path) || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $compressed = !empty($manifestArtifact['compressed']) || substr($storedFileName, -3) === '.gz';
        $originalFileName = sanitize_file_name((string) ($manifestArtifact['originalFileName'] ?? ''));
        if ($originalFileName === '' && $compressed && substr($storedFileName, -3) === '.gz') {
            $originalFileName = sanitize_file_name(substr($storedFileName, 0, -3));
        }
        if ($originalFileName === '') {
            $originalFileName = $storedFileName;
        }

        $compression = $compressed ? sanitize_text_field((string) ($manifestArtifact['compression'] ?? 'gzip')) : '';
        $label = $compressed
            ? sprintf(
                /* translators: %s: archived log file name */
                __('%s (gzip)', 'smartcloud-static-publisher'),
                $originalFileName
            )
            : $originalFileName;

        $artifactId = sanitize_key($archiveKey . '-' . $storedFileName);
        if ($artifactId === '') {
            $artifactId = md5($archiveKey . '|' . $storedFileName);
        }

        $downloadName = sanitize_file_name($archiveKey . '-' . $storedFileName);
        if ($downloadName === '') {
            $downloadName = sanitize_file_name($storedFileName);
        }

        $size = filesize($path);
        if (!is_int($size) || $size < 0) {
            $size = is_numeric($manifestArtifact['storedSize'] ?? null) ? max(0, (int) $manifestArtifact['storedSize']) : 0;
        }

        $record = array(
            'id' => $artifactId,
            'label' => $label,
            'role' => sanitize_key((string) ($manifestArtifact['role'] ?? '')),
            'originalFileName' => $originalFileName,
            'storedFileName' => $storedFileName,
            'downloadName' => $downloadName,
            'downloadPath' => $this->buildAuditArtifactDownloadPath($archiveKey, $storedFileName),
            'size' => max(0, $size),
            'compressed' => $compressed,
        );

        if ($compression !== '') {
            $record['compression'] = $compression;
        }

        return $record;
    }

    private function buildAuditArtifactDownloadPath(string $archiveKey, string $storedFileName): string
    {
        return '/audit/artifacts/download?archive=' . rawurlencode($archiveKey) . '&file=' . rawurlencode($storedFileName);
    }

    private function resolveAuditArchiveDir(string $archiveKey): ?string
    {
        if ($archiveKey === '' || strpos($archiveKey, "\0") !== false) {
            return null;
        }

        $safeArchiveKey = sanitize_file_name($archiveKey);
        if ($safeArchiveKey !== $archiveKey) {
            return null;
        }

        $paths = $this->getRuntimePaths();
        $archiveRoot = rtrim((string) $paths['logs'], "/\\") . DIRECTORY_SEPARATOR . 'archive';
        $candidate = $archiveRoot . DIRECTORY_SEPARATOR . $safeArchiveKey;

        $realArchiveRoot = realpath($archiveRoot);
        $realCandidate = realpath($candidate);
        if (!is_string($realArchiveRoot) || !is_string($realCandidate) || !is_dir($realCandidate)) {
            return null;
        }

        $normalizedArchiveRoot = wp_normalize_path($realArchiveRoot);
        $normalizedCandidate = wp_normalize_path($realCandidate);
        $prefix = trailingslashit($normalizedArchiveRoot);
        if (strpos($normalizedCandidate, $prefix) !== 0) {
            return null;
        }

        return $realCandidate;
    }

    public function resolveAuditArchiveFilePath(string $archiveKey, string $file): ?string
    {
        if ($file === '' || strpos($file, "\0") !== false) {
            return null;
        }

        $safeFile = basename($file);
        if ($safeFile !== $file) {
            return null;
        }

        $archiveDir = $this->resolveAuditArchiveDir($archiveKey);
        if (!is_string($archiveDir)) {
            return null;
        }

        $candidate = $archiveDir . DIRECTORY_SEPARATOR . $safeFile;
        $realCandidate = realpath($candidate);
        if (!is_string($realCandidate)) {
            return null;
        }

        $normalizedArchiveDir = wp_normalize_path($archiveDir);
        $normalizedCandidate = wp_normalize_path($realCandidate);
        $prefix = trailingslashit($normalizedArchiveDir);
        if (strpos($normalizedCandidate, $prefix) !== 0) {
            return null;
        }

        return $realCandidate;
    }

    public function guessAuditArtifactContentType(string $path): string
    {
        $normalizedPath = strtolower($path);
        if (substr($normalizedPath, -3) === '.gz') {
            return 'application/gzip';
        }
        if (substr($normalizedPath, -6) === '.jsonl') {
            return 'application/x-ndjson';
        }
        if (substr($normalizedPath, -5) === '.json') {
            return 'application/json';
        }

        return 'text/plain; charset=utf-8';
    }

    public function resolveLogFilePath(string $file): ?string
    {
        if ($file === '' || strpos($file, "\0") !== false) {
            return null;
        }

        $safeFile = basename($file);
        if ($safeFile !== $file) {
            return null;
        }

        $paths = $this->getRuntimePaths();
        $logsDir = rtrim((string) $paths['logs'], "/\\");
        $candidate = $logsDir . DIRECTORY_SEPARATOR . $safeFile;

        $realLogs = realpath($logsDir);
        $realCandidate = realpath($candidate);
        if (!is_string($realLogs) || !is_string($realCandidate)) {
            return null;
        }

        $normalizedLogs = wp_normalize_path($realLogs);
        $normalizedCandidate = wp_normalize_path($realCandidate);
        $prefix = trailingslashit($normalizedLogs);
        if (strpos($normalizedCandidate, $prefix) !== 0) {
            return null;
        }

        return $realCandidate;
    }

    public function readJsonFile(string $path)
    {
        if (!file_exists($path) || !is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    public function readLogContents(string $path, string $file, bool $full = false, int $limit = 400000): array
    {
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return array(
                'contents' => '',
                'truncated' => false,
                'fileSize' => 0,
                'returnedSize' => 0,
                'limit' => $full ? 0 : $limit,
            );
        }

        $fileSize = strlen($raw);
        if ($full || mb_strlen($raw) <= $limit) {
            return array(
                'contents' => $raw,
                'truncated' => false,
                'fileSize' => $fileSize,
                'returnedSize' => $fileSize,
                'limit' => $full ? 0 : $limit,
            );
        }

        $chunk = mb_substr($raw, -$limit);
        if (!str_ends_with($file, '.jsonl')) {
            return array(
                'contents' => $chunk,
                'truncated' => true,
                'fileSize' => $fileSize,
                'returnedSize' => strlen($chunk),
                'limit' => $limit,
            );
        }

        $firstBreak = mb_strpos($chunk, "\n");
        if ($firstBreak !== false && $firstBreak + 1 < mb_strlen($chunk)) {
            $chunk = mb_substr($chunk, $firstBreak + 1);
        }

        $lastBreak = mb_strrpos($chunk, "\n");
        if ($lastBreak === false || $lastBreak <= 0) {
            return array(
                'contents' => $chunk,
                'truncated' => true,
                'fileSize' => $fileSize,
                'returnedSize' => strlen($chunk),
                'limit' => $limit,
            );
        }

        $trimmed = rtrim(mb_substr($chunk, 0, $lastBreak), "\r\n");
        return array(
            'contents' => $trimmed,
            'truncated' => true,
            'fileSize' => $fileSize,
            'returnedSize' => strlen($trimmed),
            'limit' => $limit,
        );
    }

    public function writeJsonFile(string $path, $data): void
    {
        $dir = dirname($path);
        wp_mkdir_p($dir);
        $encoded = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return;
        }
        file_put_contents($path, $encoded);
    }

    public function withQueueMutationLock(callable $callback)
    {
        $deadline = microtime(true) + 5.0;
        $staleAfterSeconds = 30;
        $lockKey = self::OPTION_QUEUE_MUTATION_LOCK_KEY;
        $lockToken = wp_generate_uuid4();

        while (true) {
            $payload = array(
                'token' => $lockToken,
                'pid' => function_exists('getmypid') ? getmypid() : null,
                'createdAt' => gmdate('c'),
            );

            if (add_option($lockKey, $payload, '', false)) {
                break;
            }

            $existing = get_option($lockKey);
            $createdAt = is_array($existing)
                ? strtotime((string) ($existing['createdAt'] ?? ''))
                : false;

            if ($createdAt !== false && (time() - $createdAt) > $staleAfterSeconds) {
                delete_option($lockKey);
                continue;
            }

            if (!is_array($existing)) {
                delete_option($lockKey);
                continue;
            }

            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Timed out acquiring queue mutation lock.');
            }

            usleep(50000);
        }

        try {
            return $callback();
        } finally {
            $existing = get_option($lockKey);
            if (is_array($existing) && (($existing['token'] ?? '') === $lockToken)) {
                delete_option($lockKey);
            }
        }
    }

    public function readFileContents(string $path): ?string
    {
        $filesystem = $this->getFilesystem();
        if (!($filesystem instanceof \WP_Filesystem_Base) || !$filesystem->exists($path)) {
            return null;
        }

        $contents = $filesystem->get_contents($path);
        return is_string($contents) ? $contents : null;
    }

    private function getFilesystem()
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        global $wp_filesystem;

        if (!($wp_filesystem instanceof \WP_Filesystem_Base)) {
            WP_Filesystem();
        }

        return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
    }

    public function deleteFile(string $path): bool
    {
        $filesystem = $this->getFilesystem();
        if (!($filesystem instanceof \WP_Filesystem_Base) || !$filesystem->exists($path)) {
            return false;
        }

        return (bool) $filesystem->delete($path, false, 'f');
    }
}

if (defined('SMARTCLOUD_STATIC_PUBLISHER_BOOTSTRAPPED')) {
    return;
}
define('SMARTCLOUD_STATIC_PUBLISHER_BOOTSTRAPPED', true);

add_action('init', 'SmartCloud\WPSuite\StaticPublisher\staticPublisherHubInit', 15);
add_action('plugins_loaded', 'SmartCloud\WPSuite\StaticPublisher\staticPublisherHubLoaded', 20);

Plugin::instance();

function staticPublisherHubInit(): void
{
    if (class_exists('\SmartCloud\WPSuite\Hub\StaticPublisherHubLoader')) {
        loader()->init();
    }
}

function staticPublisherHubLoaded(): void
{
    if (class_exists('\SmartCloud\WPSuite\Hub\StaticPublisherHubLoader')) {
        loader()->check();
    }
}

function loader(): \SmartCloud\WPSuite\Hub\StaticPublisherHubLoader
{
    return \SmartCloud\WPSuite\Hub\StaticPublisherHubLoader::instance(
        'smartcloud-static-publisher/smartcloud-static-publisher.php',
        'smartcloud-static-publisher'
    );
}

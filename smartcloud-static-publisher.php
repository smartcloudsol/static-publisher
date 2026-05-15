<?php
/**
 * Plugin Name:       SmartCloud Static Publisher
 * Plugin URI:        https://wpsuite.io/static-publisher/
 * Description:       Static export admin for WP Suite Static Publisher. Generates runtime config, queues export jobs, and shows exporter logs.
 * Requires at least: 6.2
 * Tested up to:      6.9
 * Requires PHP:      8.1
 * Version:           1.0.0
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

const VERSION = '1.0.0';

final class Plugin
{
    private const SLUG = 'smartcloud-static-publisher';
    private const OPTION_KEY = 'smartcloud_static_publisher_config';
    private const OPTION_AUDIT_LOG_KEY = 'smartcloud_static_publisher_audit_log';
    private const OPTION_AUDIT_CURSOR_KEY = 'smartcloud_static_publisher_audit_cursor';
    private const OPTION_QUEUE_MUTATION_LOCK_KEY = 'smartcloud_static_publisher_queue_mutation_lock';
    private const REST_NAMESPACE = 'smartcloud-static-publisher/v1';

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

        $this->writeJsonFile($paths['config'], $this->stripLocalOnlyConfigFromRuntimeConfig($this->getConfig()));
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
        if (current_user_can('manage_options')) {
            return true;
        }

        $providedSiteKey = sanitize_text_field((string) $request->get_header('x-site-key'));
        if ($providedSiteKey === '') {
            return false;
        }

        $settings = $this->getWpSuiteSiteSettings();
        $expectedSiteKey = sanitize_text_field((string) ($settings['siteKey'] ?? ''));
        if ($expectedSiteKey === '') {
            return false;
        }

        return hash_equals($expectedSiteKey, $providedSiteKey);
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

        $globalSignature = $this->computeGlobalRenderDependencySignature();
        $items = array();
        foreach ($urls as $url) {
            $items[] = $this->buildChangeTokenItem($url, $globalSignature);
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
        $baseConfig = is_array($localConfig) ? $this->sanitizeConfig($localConfig) : $this->getConfig();
        return $this->mergeRemotePublisherConfig($baseConfig);
    }

    private function mergeRemotePublisherConfig(array $config): array
    {
        $remoteSettings = $this->getRemotePublisherSettings();
        if (!is_array($remoteSettings)) {
            return $config;
        }

        if (array_key_exists('deploymentProfiles', $remoteSettings)) {
            $config['deploymentProfiles'] = $this->sanitizeDeploymentProfilesConfig($remoteSettings['deploymentProfiles']);
        }

        if (array_key_exists('defaultDeploymentProfile', $remoteSettings)) {
            $defaultDeploymentProfile = $this->sanitizeDeploymentProfileName($remoteSettings['defaultDeploymentProfile']);
            $config['defaultDeploymentProfile'] = isset($config['deploymentProfiles'][$defaultDeploymentProfile])
                ? $defaultDeploymentProfile
                : '';
        }

        if (array_key_exists('scheduler', $remoteSettings)) {
            $config['scheduler'] = $this->sanitizeSchedulerConfig($remoteSettings['scheduler']);
        }

        return $config;
    }

    private function getRemotePublisherSettings(): ?array
    {
        $identity = $this->getWpSuiteIdentityForJobs();
        $accountId = isset($identity['accountId']) ? sanitize_text_field((string) $identity['accountId']) : '';
        $siteId = isset($identity['siteId']) ? sanitize_text_field((string) $identity['siteId']) : '';
        $siteKey = isset($identity['siteKey']) ? sanitize_text_field((string) $identity['siteKey']) : '';

        if ($accountId === '' || $siteId === '' || $siteKey === '') {
            return null;
        }

        $endpoint = trailingslashit($this->getWpSuiteApiBase()) . 'account/' . rawurlencode($accountId) . '/site/' . rawurlencode($siteId) . '/settings';
        $response = wp_remote_get($endpoint, array(
            'timeout' => 12,
            'headers' => array(
                'Accept' => 'application/json',
                'X-Site-Key' => $siteKey,
                'X-Plugin' => 'publisher',
            ),
        ));

        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return null;
        }

        if (isset($data['settings']) && is_array($data['settings'])) {
            return $data['settings'];
        }

        return $data;
    }

    public function hasActiveWpSuiteSubscription(): bool
    {
        $identity = $this->getWpSuiteIdentityForJobs();
        $accountId = isset($identity['accountId']) ? sanitize_text_field((string) $identity['accountId']) : '';
        $siteId = isset($identity['siteId']) ? sanitize_text_field((string) $identity['siteId']) : '';
        $siteKey = isset($identity['siteKey']) ? sanitize_text_field((string) $identity['siteKey']) : '';

        if ($accountId === '' || $siteId === '' || $siteKey === '') {
            return false;
        }

        $endpoint = trailingslashit($this->getWpSuiteApiBase()) . 'account/' . rawurlencode($accountId) . '/site/' . rawurlencode($siteId) . '/license';
        $response = wp_remote_get($endpoint, array(
            'timeout' => 12,
            'headers' => array(
                'Accept' => 'application/json',
                'X-Site-Key' => $siteKey,
            ),
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return false;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        return is_array($data) && !empty($data['config']) && !empty($data['jws']);
    }

    public function getPreferredDefaultCrawlMode(): string
    {
        $identity = $this->getWpSuiteIdentityForJobs();
        return !empty($identity['subscriber']) ? 'incremental' : 'full';
    }

    public function sanitizeConfig(array $input): array
    {
        $rewriteMode = isset($input['urlRewriteMode']) ? sanitize_text_field((string) $input['urlRewriteMode']) : 'relative';
        if (!in_array($rewriteMode, array('absolute', 'root-relative', 'relative'), true)) {
            $rewriteMode = 'relative';
        }

        $siteAddressOrigin = $this->resolveSiteAddressOrigin();
        $deploymentProfiles = $this->sanitizeDeploymentProfilesConfig($input['deploymentProfiles'] ?? array());
        $defaultDeploymentProfile = $this->sanitizeDeploymentProfileName($input['defaultDeploymentProfile'] ?? '');
        if ($defaultDeploymentProfile !== '' && !isset($deploymentProfiles[$defaultDeploymentProfile])) {
            $defaultDeploymentProfile = '';
        }

        return array(
            'sourceOrigin' => $siteAddressOrigin,
            'targetOrigin' => $this->sanitizeOriginOrDot($input['targetOrigin'] ?? ''),
            'ignoreHttpsErrors' => !empty($input['ignoreHttpsErrors']),
            'urlRewriteMode' => $rewriteMode,
            'exporterDir' => $this->sanitizeOptionalHostPath($input['exporterDir'] ?? ''),
            'outputDir' => $this->normalizeStorageRelativePath((string) ($input['outputDir'] ?? 'export'), 'export'),
            'noJavaScriptRenderPathPrefixes' => $this->sanitizePathList($input['noJavaScriptRenderPathPrefixes'] ?? array()),
            'seedPaths' => $this->sanitizePathList($input['seedPaths'] ?? array()),
            'sitemapPaths' => $this->sanitizePathList($input['sitemapPaths'] ?? array('/sitemap_index.xml', '/sitemap.xml')),
            'allowedAssetHosts' => $this->sanitizeHostList($input['allowedAssetHosts'] ?? array()),
            'assetPathPrefixes' => $this->sanitizePathList($input['assetPathPrefixes'] ?? array('/wp-content/', '/wp-includes/')),
            'blockedPathPrefixes' => $this->sanitizePathList($input['blockedPathPrefixes'] ?? array('/wp-admin', '/wp-login.php', '/wp-json')),
            'blockedSearchFragments' => $this->sanitizeStringList($input['blockedSearchFragments'] ?? array()),
            'extraReplacements' => $this->sanitizeMap($input['extraReplacements'] ?? array()),
            'postCrawlCopyMap' => $this->sanitizeMap($input['postCrawlCopyMap'] ?? array()),
            'defaultDeploymentProfile' => $defaultDeploymentProfile,
            'deploymentProfiles' => $deploymentProfiles,
            'scheduler' => $this->sanitizeSchedulerConfig($input['scheduler'] ?? array()),
            'wpsuite' => $this->getWpSuiteIdentityForJobs(),
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

    private function sanitizeDeploymentProfilesConfig($value): array
    {
        if (!is_array($value)) {
            return array();
        }

        $profiles = array();
        foreach ($value as $rawName => $rawProfile) {
            $name = $this->sanitizeDeploymentProfileName($rawName);
            if ($name === '' || !is_array($rawProfile)) {
                continue;
            }

            $profile = array();

            $targetOrigin = $this->sanitizeOriginOrDot($rawProfile['targetOrigin'] ?? '');
            if ($targetOrigin !== '') {
                $profile['targetOrigin'] = $targetOrigin;
            }

            $extraReplacements = $this->sanitizeMap($rawProfile['extraReplacements'] ?? array());
            if (!empty($extraReplacements)) {
                $profile['extraReplacements'] = $extraReplacements;
            }

            if (isset($rawProfile['s3']) && is_array($rawProfile['s3'])) {
                $s3 = array();
                if (array_key_exists('bucket', $rawProfile['s3'])) {
                    $s3['bucket'] = sanitize_text_field((string) ($rawProfile['s3']['bucket'] ?? ''));
                }
                if (array_key_exists('prefix', $rawProfile['s3'])) {
                    $s3['prefix'] = $this->sanitizePathToken($rawProfile['s3']['prefix'] ?? '');
                }
                if (array_key_exists('region', $rawProfile['s3'])) {
                    $s3['region'] = sanitize_text_field((string) ($rawProfile['s3']['region'] ?? ''));
                }
                if (array_key_exists('htmlCacheControl', $rawProfile['s3'])) {
                    $s3['htmlCacheControl'] = sanitize_text_field((string) ($rawProfile['s3']['htmlCacheControl'] ?? ''));
                }
                if (array_key_exists('assetCacheControl', $rawProfile['s3'])) {
                    $s3['assetCacheControl'] = sanitize_text_field((string) ($rawProfile['s3']['assetCacheControl'] ?? ''));
                }
                if (!empty($s3)) {
                    $profile['s3'] = $s3;
                }
            }

            if (isset($rawProfile['cloudFront']) && is_array($rawProfile['cloudFront'])) {
                $cloudFront = array();
                if (array_key_exists('distributionId', $rawProfile['cloudFront'])) {
                    $cloudFront['distributionId'] = sanitize_text_field((string) ($rawProfile['cloudFront']['distributionId'] ?? ''));
                }
                if (array_key_exists('invalidationPaths', $rawProfile['cloudFront'])) {
                    $cloudFront['invalidationPaths'] = $this->sanitizePathList($rawProfile['cloudFront']['invalidationPaths'] ?? array());
                }
                if (!empty($cloudFront)) {
                    $profile['cloudFront'] = $cloudFront;
                }
            }

            $profiles[$name] = $profile;
        }

        return $profiles;
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
                'accountId' => sanitize_text_field((string) ($job['wpsuite']['accountId'] ?? '')),
                'siteId' => sanitize_text_field((string) ($job['wpsuite']['siteId'] ?? '')),
                'subscriber' => !empty($job['wpsuite']['subscriber']),
                'apiBase' => sanitize_text_field((string) ($job['wpsuite']['apiBase'] ?? '')),
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

    private function sanitizeSchedulerConfig($value): array
    {
        $input = is_array($value) ? $value : array();
        $defaultCrawlMode = $this->getPreferredDefaultCrawlMode();
        $rawRules = isset($input['rules']) && is_array($input['rules']) ? $input['rules'] : array();
        $rules = array();
        foreach ($rawRules as $index => $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $command = sanitize_text_field((string) ($rule['command'] ?? 'publish'));
            $allowedCommands = array('publish', 'crawl', 'deploy', 'invalidate', 'retry-timeouts', 'url');
            if (!in_array($command, $allowedCommands, true)) {
                continue;
            }
            $intervalMinutes = max(1, absint($rule['intervalMinutes'] ?? 60));
            $id = sanitize_text_field((string) ($rule['id'] ?? ($command . '-' . ($index + 1))));
            if ($id === '') {
                continue;
            }
            $crawlMode = sanitize_text_field((string) ($rule['crawlMode'] ?? $defaultCrawlMode));
            if (!in_array($crawlMode, array('full', 'incremental'), true)) {
                $crawlMode = $defaultCrawlMode;
            }
            if (!in_array($command, array('publish', 'crawl'), true)) {
                $crawlMode = 'full';
            }
            $deploymentProfile = $this->sanitizeDeploymentProfileName($rule['deploymentProfile'] ?? '');
            if (!in_array($command, array('publish', 'deploy', 'invalidate'), true)) {
                $deploymentProfile = '';
            }
            $url = sanitize_text_field((string) ($rule['url'] ?? ''));
            if ($command === 'url' && $url === '') {
                continue;
            }

            $sanitizedRule = array(
                'id' => $id,
                'enabled' => !isset($rule['enabled']) || !empty($rule['enabled']),
                'command' => $command,
                'intervalMinutes' => $intervalMinutes,
            );
            if (in_array($command, array('publish', 'crawl'), true)) {
                $sanitizedRule['crawlMode'] = $crawlMode;
            }
            if ($deploymentProfile !== '') {
                $sanitizedRule['deploymentProfile'] = $deploymentProfile;
            }
            if ($url !== '') {
                $sanitizedRule['url'] = $url;
            }
            $rules[] = $sanitizedRule;
        }

        return array(
            'enabled' => !empty($input['enabled']),
            'timezone' => sanitize_text_field((string) ($input['timezone'] ?? 'UTC')),
            'rules' => $rules,
        );
    }

    public function stripProConfigFromWpStorage(array $config): array
    {
        $stripped = $config;
        $stripped['scheduler'] = array(
            'enabled' => false,
            'timezone' => 'UTC',
            'rules' => array(),
        );
        $stripped['defaultDeploymentProfile'] = '';
        $stripped['deploymentProfiles'] = array();
        return $stripped;
    }

    public function stripRuntimeOnlyConfigFromWpStorage(array $config): array
    {
        $stripped = $config;
        unset($stripped['wpsuite']);
        return $stripped;
    }

    private function stripLocalOnlyConfigFromRuntimeConfig(array $config): array
    {
        $stripped = $config;
        unset($stripped['exporterDir']);
        return $stripped;
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
            'subscriber' => !empty($raw['subscriber']),
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

    private function buildChangeTokenItem(string $url, array $globalSignature): array
    {
        $resolved = $this->resolveTrackedRouteForUrl($url);
        if (!$resolved || !is_array($resolved)) {
            return $this->buildUnsupportedChangeTokenItem(
                $url,
                'URL did not resolve to a tracked WordPress route.'
            );
        }

        $kind = sanitize_key((string) ($resolved['kind'] ?? ''));
        if ($kind === 'post' && isset($resolved['post']) && $resolved['post'] instanceof \WP_Post) {
            /** @var \WP_Post $post */
            $post = $resolved['post'];
            $dependencyPostIds = $this->collectReferencedPostIdsForPost($post);
            $dependencyPayload = array();
            foreach ($dependencyPostIds as $dependencyPostId) {
                $dependencyPost = get_post($dependencyPostId);
                if (!($dependencyPost instanceof \WP_Post)) {
                    continue;
                }

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
            $archiveItem = $this->buildArchiveChangeTokenItem($url, $resolved, $globalSignature);
            if (is_array($archiveItem)) {
                return $archiveItem;
            }
        }

        return $this->buildUnsupportedChangeTokenItem(
            $url,
            'URL did not resolve to a tracked WordPress route.'
        );
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

    private function buildArchiveChangeTokenItem(string $url, array $resolved, array $globalSignature): ?array
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
            rtrim($homeUrl, '/') === rtrim($normalizedUrl, '/')
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

        $currentPath = '/' . trim((string) ($currentParts['path'] ?? '/'), '/');
        $archivePath = '/' . trim((string) ($archiveParts['path'] ?? '/'), '/');
        $currentPath = $currentPath === '/' ? '' : rtrim($currentPath, '/');
        $archivePath = $archivePath === '/' ? '' : rtrim($archivePath, '/');

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

    private function computeGlobalRenderDependencySignature(): array
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

        return array(
            'stylesheet' => (string) $theme->get_stylesheet(),
            'themeVersion' => (string) $theme->get('Version'),
            'themeModsHash' => hash('sha256', (string) wp_json_encode($themeMods)),
            'menuSignatureHash' => hash('sha256', (string) wp_json_encode($menuSignature)),
            'showOnFront' => sanitize_text_field((string) get_option('show_on_front')),
            'pageOnFront' => absint(get_option('page_on_front')),
            'pageForPosts' => absint(get_option('page_for_posts')),
            'elementorActiveKitId' => $elementorActiveKitId,
            'elementorActiveKitModifiedGmt' => $elementorActiveKit instanceof \WP_Post
                ? (string) ($elementorActiveKit->post_modified_gmt ?: $elementorActiveKit->post_modified)
                : '',
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

    public function getServerDiagnostics(): array
    {
        $node = $this->detectNodeBinary();
        $heartbeat = $this->detectQueueRunnerHeartbeat();
        $exporterPackage = $this->detectExporterPackage();
        $playwrightPackage = $this->detectPlaywrightPackage((string) ($exporterPackage['configuredDir'] ?? ''));
        $playwrightChromium = $this->detectPlaywrightChromium((string) ($exporterPackage['configuredDir'] ?? ''));

        $nodeAvailableByFilesystemOrExec = !empty($node['available']);
        $nodeAvailableByHeartbeat = !empty($heartbeat['nodeAvailable']);
        $nodeAvailable = $nodeAvailableByFilesystemOrExec || $nodeAvailableByHeartbeat;
        $exporterAvailableByFilesystem = !empty($exporterPackage['installed']);
        $exporterAvailableByHeartbeat = !empty($heartbeat['fresh']) && !empty($heartbeat['exporterDir']);
        $exporterAvailable = $exporterAvailableByFilesystem || $exporterAvailableByHeartbeat;
        $playwrightPackageAvailable = !empty($playwrightPackage['installed']) || ($exporterAvailableByHeartbeat && !empty($playwrightChromium['installed']));

        if (!$nodeAvailableByFilesystemOrExec && $nodeAvailableByHeartbeat) {
            $node['available'] = true;
            $node['checkMethod'] = 'queue-runner-heartbeat';
            if (empty($node['path']) && !empty($heartbeat['nodePath'])) {
                $node['path'] = (string) $heartbeat['nodePath'];
            }
            if (empty($node['version']) && !empty($heartbeat['nodeVersion'])) {
                $node['version'] = (string) $heartbeat['nodeVersion'];
            }
        }

        if (!$exporterAvailableByFilesystem && $exporterAvailableByHeartbeat) {
            $exporterPackage['installed'] = true;
            $exporterPackage['path'] = (string) ($heartbeat['exporterDir'] ?? '');
            $exporterPackage['configuredDir'] = (string) ($heartbeat['exporterDir'] ?? '');
            $exporterPackage['checkMethod'] = 'queue-runner-heartbeat';
        }

        $issues = array();
        if (!$nodeAvailable) {
            $issues[] = 'Node.js binary was not detected from PHP process environment.';
        }
        if (!$exporterAvailable) {
            $issues[] = !empty($exporterPackage['configuredDir'])
                ? 'Configured exporter directory does not contain a usable @smart-cloud/publisher-exporter installation.'
                : 'External exporter directory is not configured. Set it to the installed @smart-cloud/publisher-exporter package root for local server diagnostics.';
        }
        if (!$playwrightPackageAvailable && !empty($exporterPackage['configuredDir'])) {
            $issues[] = 'Playwright npm package was not detected under the configured exporter directory.';
        }
        if (empty($playwrightChromium['installed'])) {
            $issues[] = 'Playwright Chromium browser binary was not detected in known browser cache paths.';
        }

        return array(
            'checkedAt' => gmdate('c'),
            'phpUser' => $this->detectPhpUser(),
            'node' => $node,
            'exporter' => array(
                'configuredDir' => (string) ($exporterPackage['configuredDir'] ?? ''),
                'packageInstalled' => !empty($exporterPackage['installed']),
                'packagePath' => (string) ($exporterPackage['path'] ?? ''),
                'packageName' => (string) ($exporterPackage['packageName'] ?? ''),
                'packageVersion' => (string) ($exporterPackage['version'] ?? ''),
                'cliPath' => (string) ($exporterPackage['cliPath'] ?? ''),
            ),
            'queueRunnerHeartbeat' => $heartbeat,
            'playwright' => array(
                'packageInstalled' => $playwrightPackageAvailable,
                'packagePath' => (string) ($playwrightPackage['path'] ?? ''),
                'chromiumInstalled' => !empty($playwrightChromium['installed']),
                'chromiumPath' => (string) ($playwrightChromium['path'] ?? ''),
                'browsersPath' => (string) ($playwrightChromium['browsersPath'] ?? ''),
            ),
            'readyForServerQueueExecution' =>
                $nodeAvailable &&
                $exporterAvailable &&
                $playwrightPackageAvailable &&
                !empty($playwrightChromium['installed']),
            'issues' => $issues,
            'hints' => array(
                'Detection is best-effort from PHP environment. Cron may use a different OS user and PATH.',
                'Queue runner heartbeat is used as a fallback for Node runtime health when PHP-FPM PATH/HOME differs.',
                'For decoupled installs, set External exporter directory to the installed @smart-cloud/publisher-exporter package root on the queue-runner host.',
                'If cron or PHP-FPM user differs, install Node/Playwright for that user or set NODE_BIN, NVM_DIR and PLAYWRIGHT_BROWSERS_PATH in the relevant server/cron environment.',
            ),
        );
    }

    private function detectQueueRunnerHeartbeat(): array
    {
        $paths = $this->getRuntimePaths();
        $heartbeat = $this->readJsonFile((string) ($paths['queueRunnerHeartbeat'] ?? ''));
        if (!is_array($heartbeat)) {
            return array(
                'available' => false,
                'checkedAt' => '',
                'status' => '',
                'nodePath' => '',
                'nodeVersion' => '',
                'exporterDir' => '',
                'nodeAvailable' => false,
                'fresh' => false,
            );
        }

        $checkedAt = isset($heartbeat['checkedAt']) ? (string) $heartbeat['checkedAt'] : '';
        $status = isset($heartbeat['status']) ? (string) $heartbeat['status'] : '';
        $nodePath = isset($heartbeat['nodePath']) ? (string) $heartbeat['nodePath'] : '';
        $nodeVersion = isset($heartbeat['nodeVersion']) ? (string) $heartbeat['nodeVersion'] : '';
        $exporterDir = isset($heartbeat['exporterDir']) ? (string) $heartbeat['exporterDir'] : '';
        $currentJobId = isset($heartbeat['currentJobId']) ? (string) $heartbeat['currentJobId'] : '';
        $currentJobCommand = isset($heartbeat['currentJobCommand']) ? (string) $heartbeat['currentJobCommand'] : '';
        $currentStep = isset($heartbeat['currentStep']) ? (string) $heartbeat['currentStep'] : '';
        $message = isset($heartbeat['message']) ? (string) $heartbeat['message'] : '';
        $stopRequestedAt = isset($heartbeat['stopRequestedAt']) ? (string) $heartbeat['stopRequestedAt'] : '';
        $stopRequestedByLogin = isset($heartbeat['stopRequestedByLogin']) ? (string) $heartbeat['stopRequestedByLogin'] : '';
        $stopRequestedMode = isset($heartbeat['stopRequestedMode']) ? (string) $heartbeat['stopRequestedMode'] : '';
        $lastStoppedStep = isset($heartbeat['lastStoppedStep']) ? (string) $heartbeat['lastStoppedStep'] : '';
        $timestamp = $checkedAt !== '' ? strtotime($checkedAt) : false;
        $fresh = is_int($timestamp) && (time() - $timestamp) <= 24 * 60 * 60;
        $nodeAvailable = $fresh && $nodeVersion !== '';

        return array(
            'available' => true,
            'checkedAt' => sanitize_text_field($checkedAt),
            'status' => sanitize_text_field($status),
            'nodePath' => sanitize_text_field($nodePath),
            'nodeVersion' => sanitize_text_field($nodeVersion),
            'exporterDir' => $this->sanitizeOptionalHostPath($exporterDir),
            'currentJobId' => sanitize_text_field($currentJobId),
            'currentJobCommand' => sanitize_text_field($currentJobCommand),
            'currentStep' => sanitize_text_field($currentStep),
            'message' => sanitize_text_field($message),
            'stopRequestedAt' => sanitize_text_field($stopRequestedAt),
            'stopRequestedByLogin' => sanitize_text_field($stopRequestedByLogin),
            'stopRequestedMode' => sanitize_text_field($stopRequestedMode),
            'lastStoppedStep' => sanitize_text_field($lastStoppedStep),
            'nodeAvailable' => $nodeAvailable,
            'fresh' => $fresh,
        );
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

    private function detectPhpUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (is_array($pw) && !empty($pw['name'])) {
                return sanitize_text_field((string) $pw['name']);
            }
        }

        $envUser = getenv('USER');
        if (is_string($envUser) && $envUser !== '') {
            return sanitize_text_field($envUser);
        }

        $current = get_current_user();
        return is_string($current) ? sanitize_text_field($current) : '';
    }

    private function detectNodeBinary(): array
    {
        $candidates = array();
        $nodeBin = getenv('NODE_BIN');
        if (is_string($nodeBin) && $nodeBin !== '') {
            $candidates[] = $nodeBin;
        }

        $nvmDir = getenv('NVM_DIR');
        if (is_string($nvmDir) && $nvmDir !== '') {
            $candidates[] = rtrim($nvmDir, '/\\') . '/versions/node/current/bin/node';
            $matches = glob(rtrim($nvmDir, '/\\') . '/versions/node/*/bin/node');
            if (is_array($matches) && !empty($matches)) {
                rsort($matches, SORT_NATURAL);
                $candidates = array_merge($candidates, $matches);
            }
        }

        $pathEnv = getenv('PATH');
        if (is_string($pathEnv) && $pathEnv !== '') {
            $parts = explode(PATH_SEPARATOR, $pathEnv);
            foreach ($parts as $part) {
                $part = trim((string) $part);
                if ($part === '') {
                    continue;
                }
                $candidates[] = rtrim($part, '/\\') . DIRECTORY_SEPARATOR . 'node';
            }
        }

        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            $homeNvmDir = rtrim($home, '/\\') . '/.nvm';
            $candidates[] = $homeNvmDir . '/versions/node/current/bin/node';
            $matches = glob($homeNvmDir . '/versions/node/*/bin/node');
            if (is_array($matches) && !empty($matches)) {
                rsort($matches, SORT_NATURAL);
                $candidates = array_merge($candidates, $matches);
            }
        }

        $candidates[] = '/usr/bin/node';
        $candidates[] = '/usr/local/bin/node';

        $candidates = array_values(array_unique(array_filter($candidates, 'is_string')));

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
                $versionInfo = $this->detectNodeVersion($candidate);
                return array(
                    'available' => !empty($versionInfo['executable']),
                    'path' => $candidate,
                    'version' => (string) ($versionInfo['version'] ?? ''),
                    'checkMethod' => (string) ($versionInfo['checkMethod'] ?? 'filesystem-only'),
                );
            }
        }

        return array(
            'available' => false,
            'path' => '',
            'version' => '',
            'checkMethod' => 'filesystem-only',
        );
    }

    private function detectNodeVersion(string $nodeBinary): array
    {
        if (!$this->canRunShellCommands()) {
            return array(
                'executable' => true,
                'version' => '',
                'checkMethod' => 'filesystem-only',
            );
        }

        $command = escapeshellarg($nodeBinary) . ' --version 2>/dev/null';
        $output = shell_exec($command);
        $version = is_string($output) ? trim($output) : '';
        if ($version !== '') {
            return array(
                'executable' => true,
                'version' => sanitize_text_field($version),
                'checkMethod' => 'node-version',
            );
        }

        return array(
            'executable' => false,
            'version' => '',
            'checkMethod' => 'node-version-failed',
        );
    }

    private function canRunShellCommands(): bool
    {
        if (!function_exists('shell_exec')) {
            return false;
        }

        $disabled = (string) ini_get('disable_functions');
        if ($disabled === '') {
            return true;
        }

        $disabledList = array_map('trim', explode(',', $disabled));
        return !in_array('shell_exec', $disabledList, true);
    }

    private function resolveConfiguredExporterDir(): string
    {
        $config = get_option(self::OPTION_KEY);
        if (is_array($config)) {
            $configured = $this->sanitizeOptionalHostPath($config['exporterDir'] ?? '');
            if ($configured !== '') {
                return $configured;
            }
        }

        $envCandidates = array(
            getenv('STATIC_PUBLISHER_EXPORTER_DIR'),
            getenv('WPSUITE_STATIC_PUBLISHER_EXPORTER_DIR'),
            getenv('EXPORTER_DIR'),
        );

        foreach ($envCandidates as $candidate) {
            $normalized = $this->sanitizeOptionalHostPath($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function detectExporterPackage(): array
    {
        $exporterDir = $this->resolveConfiguredExporterDir();
        $packagePath = $exporterDir !== '' ? rtrim($exporterDir, '/\\') . '/package.json' : '';
        $cliCandidates = $exporterDir !== ''
            ? array(
                rtrim($exporterDir, '/\\') . '/dist/cli.js',
                rtrim($exporterDir, '/\\') . '/cli.js',
            )
            : array();
        $cliPath = '';

        foreach ($cliCandidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                $cliPath = $candidate;
                break;
            }
        }

        if ($packagePath === '' || !is_file($packagePath) || !is_readable($packagePath)) {
            return array(
                'installed' => false,
                'configuredDir' => $exporterDir,
                'path' => '',
                'packageName' => '',
                'version' => '',
                'cliPath' => $cliPath,
                'checkMethod' => 'filesystem-only',
            );
        }

        $raw = file_get_contents($packagePath);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $packageName = is_array($decoded) ? sanitize_text_field((string) ($decoded['name'] ?? '')) : '';
        $version = is_array($decoded) ? sanitize_text_field((string) ($decoded['version'] ?? '')) : '';

        return array(
            'installed' => $packageName === '@smart-cloud/publisher-exporter' || $cliPath !== '',
            'configuredDir' => $exporterDir,
            'path' => $packagePath,
            'packageName' => $packageName,
            'version' => $version,
            'cliPath' => $cliPath,
            'checkMethod' => 'filesystem-only',
        );
    }

    private function detectPlaywrightPackage(string $exporterDir = ''): array
    {
        if ($exporterDir === '') {
            $exporterDir = $this->resolveConfiguredExporterDir();
        }

        $candidates = array(
            $exporterDir . '/node_modules/playwright/package.json',
            $exporterDir . '/node_modules/playwright-core/package.json',
        );

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return array(
                    'installed' => true,
                    'path' => $candidate,
                );
            }
        }

        return array(
            'installed' => false,
            'path' => '',
        );
    }

    private function detectPlaywrightChromium(string $exporterDir = ''): array
    {
        $paths = array();

        $envBrowsersPath = getenv('PLAYWRIGHT_BROWSERS_PATH');
        if (is_string($envBrowsersPath) && $envBrowsersPath !== '') {
            $paths[] = $envBrowsersPath;
        }

        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            $paths[] = rtrim($home, '/\\') . '/.cache/ms-playwright';
        }

        $paths[] = '/var/lib/playwright-browsers';

        if ($exporterDir === '') {
            $exporterDir = $this->resolveConfiguredExporterDir();
        }
        if ($exporterDir !== '') {
            $paths[] = rtrim($exporterDir, '/\\') . '/node_modules/playwright-core/.local-browsers';
        }

        $paths = array_values(array_unique(array_filter($paths, 'is_string')));

        foreach ($paths as $basePath) {
            if (!is_dir($basePath) || !is_readable($basePath)) {
                continue;
            }

            $pattern = rtrim($basePath, '/\\') . '/chromium-*/chrome-linux/chrome';
            $matches = glob($pattern);
            if (is_array($matches) && !empty($matches)) {
                return array(
                    'installed' => true,
                    'path' => (string) $matches[0],
                    'browsersPath' => $basePath,
                );
            }
        }

        return array(
            'installed' => false,
            'path' => '',
            'browsersPath' => (is_string($envBrowsersPath) && $envBrowsersPath !== '') ? $envBrowsersPath : '',
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

        $entries = scandir($paths['logs']);
        if (!is_array($entries)) {
            return array();
        }

        $files = array();
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
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

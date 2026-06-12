<?php

namespace SmartCloud\WPSuite\StaticPublisher\Admin;

use SmartCloud\WPSuite\StaticPublisher\Plugin;
use WP_REST_Request;
use WP_REST_Response;
use const SmartCloud\WPSuite\StaticPublisher\VERSION;

if (!defined('ABSPATH')) {
    exit;
}

class Admin
{
    private const SLUG = 'smartcloud-static-publisher';
    private const MENU_SLUG = 'smartcloud-static-publisher';
    private const OPTION_KEY = 'smartcloud_static_publisher_config';
    private const REST_NAMESPACE = 'smartcloud-static-publisher/v1';

    private Plugin $plugin;
    private string $adminPageHook = '';

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    public function registerHooks(): void
    {
        add_action('admin_menu', array($this, 'addMenu'), 30);
        add_action('admin_enqueue_scripts', array($this, 'enqueueAssets'));
        add_action('rest_api_init', array($this, 'registerRestRoutes'));
    }

    public function addMenu(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $parentSlug = defined('SMARTCLOUD_WPSUITE_SLUG') ? SMARTCLOUD_WPSUITE_SLUG : 'hub-for-wpsuiteio';
        if (menu_page_url($parentSlug, false) === '') {
            add_menu_page(
                __('SmartCloud', 'smartcloud-static-publisher'),
                __('SmartCloud', 'smartcloud-static-publisher'),
                'manage_options',
                $parentSlug,
                '__return_null',
                'dashicons-cloud',
                56
            );
        }

        $this->adminPageHook = (string) add_submenu_page(
            $parentSlug,
            __('Static Publisher', 'smartcloud-static-publisher'),
            __('Static Publisher', 'smartcloud-static-publisher'),
            'manage_options',
            self::MENU_SLUG,
            array($this, 'renderPage')
        );
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to access this page.', 'smartcloud-static-publisher'));
        }

        echo '<div id="smartcloud-static-publisher-admin"></div>';
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== $this->adminPageHook) {
            return;
        }

        $scriptHandle = self::SLUG . '-admin';
        $scriptAbsPath = SMARTCLOUD_STATIC_PUBLISHER_PATH . 'admin/index.js';

        if (!file_exists($scriptAbsPath) || !is_readable($scriptAbsPath)) {
            wp_enqueue_style('wp-components');
            echo '<div class="notice notice-warning"><p>' .
                esc_html__('Static Publisher admin bundle is missing (admin/index.js). Reinstall the packaged plugin, or rebuild from source with yarn run build-wp dist in the admin project.', 'smartcloud-static-publisher') .
                '</p></div>';
            return;
        }

        $asset = array('dependencies' => array(), 'version' => VERSION);
        $assetPath = SMARTCLOUD_STATIC_PUBLISHER_PATH . 'admin/index.asset.php';
        if (file_exists($assetPath)) {
            $loaded = require $assetPath;
            if (is_array($loaded)) {
                $asset = array_merge($asset, $loaded);
            }
        }

        $dependencies = isset($asset['dependencies']) && is_array($asset['dependencies']) ? $asset['dependencies'] : array();
        if (!in_array('wp-i18n', $dependencies, true)) {
            $dependencies[] = 'wp-i18n';
        }
        $dependencies = $this->getAdminScriptDependencies($dependencies);
        $version = isset($asset['version']) && is_string($asset['version']) ? $asset['version'] : VERSION;

        wp_enqueue_script(
            $scriptHandle,
            SMARTCLOUD_STATIC_PUBLISHER_URL . 'admin/index.js',
            $dependencies,
            $version,
            array('in_footer' => true, 'strategy' => 'defer')
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations(
                $scriptHandle,
                'smartcloud-static-publisher',
                SMARTCLOUD_STATIC_PUBLISHER_PATH . 'languages'
            );
        }

        $styleAbsPath = SMARTCLOUD_STATIC_PUBLISHER_PATH . 'admin/index.css';
        if (file_exists($styleAbsPath) && is_readable($styleAbsPath)) {
            wp_enqueue_style(
                self::SLUG . '-admin-style',
                SMARTCLOUD_STATIC_PUBLISHER_URL . 'admin/index.css',
                array(),
                $version
            );
        }

        $this->enqueueMantineVendorStyle($version);

        $bootstrap = array(
            'key' => self::SLUG,
            'version' => VERSION,
            'restUrl' => rest_url(self::REST_NAMESPACE),
            'nonce' => wp_create_nonce('wp_rest'),
            'apiBase' => $this->plugin->getWpSuiteRuntimeConfig()['apiBase'] ?? '',
            'settings' => $this->plugin->getConfig(),
            'runtime' => array(
                'paths' => $this->plugin->getRuntimeRelativePaths(),
            ),
            'wpOrgCompliance' => array(
                'supportsNodeRunner' => true,
                'phpExecDisabled' => true,
            ),
        );

        $inline = 'const __staticPublisherGlobal = (typeof globalThis !== "undefined") ? globalThis : window;
__staticPublisherGlobal.WpSuite = __staticPublisherGlobal.WpSuite ?? {};
__staticPublisherGlobal.WpSuite.plugins = __staticPublisherGlobal.WpSuite.plugins ?? {};
__staticPublisherGlobal.WpSuite.events = __staticPublisherGlobal.WpSuite.events ?? {
  emit: (type, detail) => window.dispatchEvent(new CustomEvent(type, { detail })),
  on: (type, cb, opts) => window.addEventListener(type, cb, opts),
};
__staticPublisherGlobal.WpSuite.plugins.staticPublisher = __staticPublisherGlobal.WpSuite.plugins.staticPublisher ?? {};
Object.assign(__staticPublisherGlobal.WpSuite.plugins.staticPublisher, ' . wp_json_encode($bootstrap) . ');
var WpSuite = __staticPublisherGlobal.WpSuite;';

        wp_add_inline_script($scriptHandle, $inline, 'before');
    }

    public function registerRestRoutes(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/state', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'canManageRead'),
            'callback' => array($this, 'handleGetState'),
        ));

        register_rest_route(self::REST_NAMESPACE, '/config', array(
            array(
                'methods' => 'GET',
                'permission_callback' => array($this, 'canManageRead'),
                'callback' => array($this, 'handleGetConfig'),
            ),
            array(
                'methods' => 'POST',
                'permission_callback' => array($this, 'canManageWrite'),
                'callback' => array($this, 'handleSaveConfig'),
            ),
        ));

        register_rest_route(self::REST_NAMESPACE, '/jobs', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'canManageWrite'),
            'callback' => array($this, 'handleQueueJob'),
        ));

        register_rest_route(self::REST_NAMESPACE, '/jobs/(?P<id>[a-zA-Z0-9-]+)', array(
            'methods' => 'DELETE',
            'permission_callback' => array($this, 'canManageWrite'),
            'callback' => array($this, 'handleDeleteJob'),
        ));

        register_rest_route(self::REST_NAMESPACE, '/jobs/(?P<id>[a-zA-Z0-9-]+)/download-config', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'canManageRead'),
            'callback' => array($this, 'handleDownloadJobConfig'),
        ));

        register_rest_route(self::REST_NAMESPACE, '/jobs/current/stop', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'canManageWrite'),
            'callback' => array($this, 'handleStopCurrentJob'),
        ));

        register_rest_route(self::REST_NAMESPACE, '/logs', array(
            array(
                'methods' => 'GET',
                'permission_callback' => array($this, 'canManageRead'),
                'callback' => array($this, 'handleGetLogs'),
            ),
            array(
                'methods' => 'DELETE',
                'permission_callback' => array($this, 'canManageWrite'),
                'callback' => array($this, 'handleClearLogs'),
            ),
        ));

        register_rest_route(self::REST_NAMESPACE, '/dirs', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'canManageRead'),
            'callback' => array($this, 'handleBrowseDirs'),
        ));

        register_rest_route(self::REST_NAMESPACE, '/audit', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'canManageRead'),
            'callback' => array($this, 'handleGetAuditLog'),
        ));

        register_rest_route(self::REST_NAMESPACE, '/audit/artifacts/download', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'canManageRead'),
            'callback' => array($this, 'handleDownloadAuditArtifact'),
        ));
    }

    public function canManageRead(): bool
    {
        return current_user_can('manage_options');
    }

    public function canManageWrite(): bool
    {
        return current_user_can('manage_options');
    }

    public function handleGetState(): WP_REST_Response
    {
        $this->plugin->ingestRuntimeAuditEvents();
        $paths = $this->plugin->getRuntimePaths();
        $currentRun = $this->plugin->sanitizeJobForState($this->plugin->readJsonFile($paths['currentRun']));
        $currentProgress = $this->plugin->readJsonFile($paths['currentProgress']);
        $currentCrawlEvent = $this->plugin->readJsonFile($paths['currentCrawlEvent']);
        $lastRun = $this->plugin->sanitizeJobForState($this->plugin->readJsonFile($paths['lastRun']));
        $queue = $this->plugin->readQueue();
        $storedConfig = get_option(self::OPTION_KEY, null);
        $state = array(
            'config' => $this->plugin->getResolvedConfig(),
            'hasSavedConfiguration' => is_array($storedConfig),
            'currentRun' => $currentRun,
            'currentProgress' => is_array($currentProgress) ? $currentProgress : null,
            'currentCrawlEvent' => is_array($currentCrawlEvent) ? $currentCrawlEvent : null,
            'lastRun' => $lastRun,
            'schedulerState' => $this->plugin->readJsonFile($paths['schedulerState']),
            'deployDiff' => $this->plugin->readJsonFile($paths['deployDiff']),
            'lockActive' => file_exists($paths['lock']),
            'queueLength' => count($queue),
            'queueItems' => array_map(array($this->plugin, 'sanitizeJobForState'), $queue),
            'availableLogs' => $this->plugin->listLogFiles(),
            'stopRequest' => $this->plugin->getActiveStopRequest($currentRun),
            'serverDiagnostics' => $this->plugin->getServerDiagnostics(),
        );

        return new WP_REST_Response($state, 200);
    }

    public function handleGetConfig(): WP_REST_Response
    {
        return new WP_REST_Response(array('config' => $this->plugin->getResolvedConfig()), 200);
    }

    public function handleSaveConfig(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $request->get_json_params();
        $data = is_array($payload) ? $payload : array();
        $config = $this->plugin->sanitizeConfig($data);
        $resolvedConfig = $this->plugin->getResolvedConfig($config);

        $storedConfig = $this->plugin->stripRuntimeOnlyConfigFromWpStorage($config);

        update_option(self::OPTION_KEY, $storedConfig, false);

        $paths = $this->plugin->getRuntimePaths();
        wp_mkdir_p($paths['runtime']);
        $this->plugin->writeJsonFile($paths['config'], $this->plugin->buildRuntimeConfig($config));

        return new WP_REST_Response(array(
            'success' => true,
            'config' => $resolvedConfig,
            'message' => __('Configuration saved.', 'smartcloud-static-publisher'),
        ), 200);
    }

    public function handleQueueJob(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $request->get_json_params();
        $data = is_array($payload) ? $payload : array();

        $command = isset($data['command']) ? sanitize_text_field((string) $data['command']) : '';
        $allowedCommands = array('publish', 'crawl', 'deploy', 'invalidate', 'retry-timeouts', 'url');
        $allowedCrawlModes = array('full', 'incremental');
        $defaultCrawlMode = 'full';

        if (!in_array($command, $allowedCommands, true)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('Invalid command.', 'smartcloud-static-publisher'),
            ), 400);
        }

        $crawlMode = isset($data['crawlMode']) ? sanitize_text_field((string) $data['crawlMode']) : $defaultCrawlMode;
        if (!in_array($crawlMode, $allowedCrawlModes, true)) {
            $crawlMode = $defaultCrawlMode;
        }
        if (!in_array($command, array('publish', 'crawl'), true)) {
            $crawlMode = 'full';
        }
        $url = '';
        if ($command === 'url') {
            $url = isset($data['url']) ? sanitize_text_field((string) $data['url']) : '';
            if ($url === '') {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => __('URL path is required for the url command.', 'smartcloud-static-publisher'),
                ), 400);
            }
        }

        $deploymentProfile = isset($data['deploymentProfile'])
            ? $this->plugin->sanitizeDeploymentProfileName($data['deploymentProfile'])
            : '';
        if (!in_array($command, array('publish', 'deploy', 'invalidate'), true)) {
            $deploymentProfile = '';
        }

        $awsCredCommands = array('publish', 'deploy', 'invalidate');
        $awsTempCreds = null;
        if (in_array($command, $awsCredCommands, true) && isset($data['awsTempCreds']) && is_array($data['awsTempCreds'])) {
            $sanitizedCreds = $this->plugin->sanitizeAwsTempCreds($data['awsTempCreds']);
            $hasAnyCred = !empty($sanitizedCreds['accessKeyId']) || !empty($sanitizedCreds['secretAccessKey']) || !empty($sanitizedCreds['sessionToken']);
            if ($hasAnyCred && (empty($sanitizedCreds['accessKeyId']) || empty($sanitizedCreds['secretAccessKey']))) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => __('Temp AWS creds require both access key ID and secret access key.', 'smartcloud-static-publisher'),
                ), 400);
            }
            if (!empty($sanitizedCreds['accessKeyId']) || !empty($sanitizedCreds['secretAccessKey']) || !empty($sanitizedCreds['sessionToken'])) {
                $awsTempCreds = $sanitizedCreds;
            }
        }

        $paths = $this->plugin->getRuntimePaths();
        wp_mkdir_p($paths['runtime']);
        $this->plugin->writeJsonFile($paths['config'], $this->plugin->buildRuntimeConfig($this->plugin->getConfig()));
        $job = array(
            'id' => wp_generate_uuid4(),
            'command' => $command,
            'enqueueSource' => 'manual',
            'url' => $url,
            'wpsuite' => $this->plugin->getWpSuiteRuntimeConfig(),
            'status' => 'queued',
            'createdAt' => gmdate('c'),
            'createdBy' => get_current_user_id(),
        );
        if (in_array($command, array('publish', 'crawl'), true)) {
            $job['crawlMode'] = $crawlMode;
        }
        if ($deploymentProfile !== '') {
            $job['deploymentProfile'] = $deploymentProfile;
        }
        if (is_array($awsTempCreds)) {
            $job['awsTempCreds'] = $awsTempCreds;
        }

        try {
            $queueLength = $this->plugin->withQueueMutationLock(function () use ($paths, $job) {
                $queue = $this->plugin->readQueue();
                $queue[] = $job;
                $this->plugin->writeJsonFile($paths['queue'], array_values($queue));
                return count($queue);
            });
        } catch (\RuntimeException $error) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('Queue is busy. Please try again in a moment.', 'smartcloud-static-publisher'),
            ), 409);
        }

        $this->plugin->appendAuditLogEntry(array(
            'eventType' => 'job-created',
            'status' => 'success',
            'actorSource' => 'wp-admin',
            'actorUserId' => get_current_user_id(),
            'jobId' => (string) $job['id'],
            'command' => (string) $job['command'],
            'message' => __('Job queued from admin UI.', 'smartcloud-static-publisher'),
            'details' => array(
                'queueLength' => $queueLength,
                'usesTempAwsCreds' => is_array($awsTempCreds),
                'crawlMode' => $crawlMode,
                'deploymentProfile' => $deploymentProfile,
                'url' => $url,
            ),
        ));

        return new WP_REST_Response(array(
            'success' => true,
            'job' => $this->plugin->sanitizeJobForState($job),
            'queueLength' => $queueLength,
            'message' => __('Job queued. External runner can pick it from runtime queue.json.', 'smartcloud-static-publisher'),
        ), 200);
    }

    public function handleDeleteJob(WP_REST_Request $request): WP_REST_Response
    {
        $jobId = sanitize_text_field((string) $request->get_param('id'));
        if ($jobId === '') {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('Invalid job id.', 'smartcloud-static-publisher'),
            ), 400);
        }

        $deletedJob = null;
        $paths = $this->plugin->getRuntimePaths();

        try {
            $queueLength = $this->plugin->withQueueMutationLock(function () use ($jobId, $paths, &$deletedJob) {
                $queue = $this->plugin->readQueue();
                $nextQueue = array();

                foreach ($queue as $job) {
                    if (!is_array($job)) {
                        continue;
                    }
                    $id = isset($job['id']) ? sanitize_text_field((string) $job['id']) : '';
                    if ($id !== '' && $id === $jobId && $deletedJob === null) {
                        $deletedJob = $job;
                        continue;
                    }
                    $nextQueue[] = $job;
                }

                if (!is_array($deletedJob)) {
                    return null;
                }

                $this->plugin->writeJsonFile($paths['queue'], array_values($nextQueue));
                return count($nextQueue);
            });
        } catch (\RuntimeException $error) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('Queue is busy. Please try again in a moment.', 'smartcloud-static-publisher'),
            ), 409);
        }

        if (!is_array($deletedJob)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('Queued job not found.', 'smartcloud-static-publisher'),
            ), 404);
        }

        $this->plugin->appendAuditLogEntry(array(
            'eventType' => 'job-deleted',
            'status' => 'success',
            'actorSource' => 'wp-admin',
            'actorUserId' => get_current_user_id(),
            'jobId' => (string) ($deletedJob['id'] ?? $jobId),
            'command' => isset($deletedJob['command']) ? (string) $deletedJob['command'] : '',
            'message' => __('Queued job deleted from admin UI.', 'smartcloud-static-publisher'),
            'details' => array(
                'queueLength' => $queueLength,
            ),
        ));

        return new WP_REST_Response(array(
            'success' => true,
            'job' => $this->plugin->sanitizeJobForState($deletedJob),
            'queueLength' => $queueLength,
            'message' => __('Queued job deleted.', 'smartcloud-static-publisher'),
        ), 200);
    }

    public function handleDownloadJobConfig(WP_REST_Request $request): WP_REST_Response
    {
        $jobId = sanitize_text_field((string) $request->get_param('id'));
        if ($jobId === '') {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('Invalid job id.', 'smartcloud-static-publisher'),
            ), 400);
        }

        $job = $this->plugin->findQueuedJobById($jobId);
        if (!is_array($job)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('Queued job not found.', 'smartcloud-static-publisher'),
            ), 404);
        }

        $payload = $this->plugin->buildJobDownloadPayload(
            $job,
            $this->plugin->buildRuntimeConfig(
                $this->plugin->getConfig(),
                (string) ($job['deploymentProfile'] ?? '')
            )
        );

        return new WP_REST_Response(array(
            'success' => true,
            'fileName' => 'static-publisher-job-' . $jobId . '.json',
            'content' => $payload,
        ), 200);
    }

    public function handleStopCurrentJob(WP_REST_Request $request): WP_REST_Response
    {
        $paths = $this->plugin->getRuntimePaths();
        $currentRun = $this->plugin->sanitizeJobForState($this->plugin->readJsonFile($paths['currentRun']));

        if (!is_array($currentRun) || (($currentRun['status'] ?? '') !== 'running') || empty($currentRun['id'])) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('There is no active running job to stop.', 'smartcloud-static-publisher'),
            ), 409);
        }

        $existing = $this->plugin->getActiveStopRequest($currentRun);
        if (is_array($existing)) {
            return new WP_REST_Response(array(
                'success' => true,
                'alreadyRequested' => true,
                'message' => __('Stop is already requested. The runner will stop the job when the current step exits and leave it out of the queue.', 'smartcloud-static-publisher'),
            ), 200);
        }

        $user = wp_get_current_user();
        $this->plugin->writeJsonFile((string) ($paths['stopRequest'] ?? ''), array(
            'requestedAt' => gmdate('c'),
            'targetJobId' => sanitize_text_field((string) ($currentRun['id'] ?? '')),
            'targetJobCommand' => sanitize_text_field((string) ($currentRun['command'] ?? '')),
            'mode' => 'stop',
            'requestedByUserId' => get_current_user_id() ?: null,
            'requestedByLogin' => $user instanceof \WP_User ? sanitize_text_field((string) $user->user_login) : '',
        ));

        return new WP_REST_Response(array(
            'success' => true,
            'message' => __('Stop requested. The queue runner will stop the active step and leave the job out of the queue.', 'smartcloud-static-publisher'),
        ), 200);
    }

    public function handleGetLogs(WP_REST_Request $request): WP_REST_Response
    {
        $file = trim((string) $request->get_param('file'));
        $full = rest_sanitize_boolean($request->get_param('full'));
        $logs = $this->plugin->listLogFiles();

        if ($file === '') {
            return new WP_REST_Response(array('files' => $logs), 200);
        }

        if (!in_array($file, $logs, true)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('Log file not found.', 'smartcloud-static-publisher'),
            ), 404);
        }

        $path = $this->plugin->resolveLogFilePath($file);
        $preview = array(
            'contents' => '',
            'truncated' => false,
            'fileSize' => 0,
            'returnedSize' => 0,
            'limit' => 0,
        );
        if (is_string($path) && file_exists($path) && is_readable($path)) {
            $preview = $this->plugin->readLogContents($path, $file, $full);
        }

        return new WP_REST_Response(array(
            'file' => $file,
            'contents' => (string) $preview['contents'],
            'truncated' => !empty($preview['truncated']),
            'fileSize' => (int) $preview['fileSize'],
            'returnedSize' => (int) $preview['returnedSize'],
            'limit' => (int) $preview['limit'],
        ), 200);
    }

    public function handleClearLogs(WP_REST_Request $request): WP_REST_Response
    {
        $paths = $this->plugin->getRuntimePaths();
        $currentRun = $this->plugin->sanitizeJobForState($this->plugin->readJsonFile($paths['currentRun']));

        if (is_array($currentRun) && (($currentRun['status'] ?? '') === 'running')) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('A job is currently running. Stop the active job before clearing logs.', 'smartcloud-static-publisher'),
            ), 409);
        }

        $logs = $this->plugin->listLogFiles();
        if (empty($logs)) {
            return new WP_REST_Response(array(
                'success' => true,
                'deleted' => 0,
                'message' => __('No log files to clear.', 'smartcloud-static-publisher'),
            ), 200);
        }

        $deleted = 0;
        foreach ($logs as $file) {
            $path = $this->plugin->resolveLogFilePath((string) $file);
            if (!is_string($path) || !is_file($path)) {
                continue;
            }
            if ($this->plugin->deleteFile($path)) {
                $deleted++;
            }
        }

        return new WP_REST_Response(array(
            'success' => true,
            'deleted' => $deleted,
            'message' => sprintf(
                /* translators: %d: number of log files deleted. */
                __('Cleared %d log file(s).', 'smartcloud-static-publisher'),
                $deleted
            ),
        ), 200);
    }

    public function handleBrowseDirs(WP_REST_Request $request): WP_REST_Response
    {
        $upload = wp_get_upload_dir();
        $storageRoot = wp_normalize_path(
            trailingslashit($upload['basedir']) . 'smartcloud-static-publisher'
        );

        $pathParam = $request->get_param('path');
        $relPath = is_string($pathParam) ? trim($pathParam) : '';

        if ($relPath !== '') {
            $relPath = $this->plugin->normalizeStorageRelativePath($relPath, '');
        }

        $absPath = wp_normalize_path(
            $relPath !== '' ? $storageRoot . '/' . $relPath : $storageRoot
        );

        if (strpos(trailingslashit($absPath), trailingslashit($storageRoot)) !== 0) {
            return new WP_REST_Response(array('path' => '', 'dirs' => array()), 200);
        }

        $dirs = array();
        if (is_dir($absPath)) {
            $entries = scandir($absPath);
            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    if (is_dir($absPath . '/' . $entry)) {
                        $dirs[] = $entry;
                    }
                }
            }
        }

        sort($dirs);

        return new WP_REST_Response(array(
            'path' => $relPath,
            'dirs' => $dirs,
        ), 200);
    }

    public function handleGetAuditLog(WP_REST_Request $request): WP_REST_Response
    {
        $this->plugin->ingestRuntimeAuditEvents();

        $page = max(1, absint($request->get_param('page') ?? 1));
        $pageSize = max(1, min(100, absint($request->get_param('pageSize') ?? 25)));
        $eventType = sanitize_text_field((string) ($request->get_param('eventType') ?? ''));
        $status = sanitize_text_field((string) ($request->get_param('status') ?? ''));
        $jobId = sanitize_text_field((string) ($request->get_param('jobId') ?? ''));
        $search = sanitize_text_field((string) ($request->get_param('search') ?? ''));

        $entries = $this->plugin->getAuditLogEntries();
        $filtered = array_values(array_filter($entries, function ($entry) use ($eventType, $status, $jobId, $search): bool {
            if (!is_array($entry)) {
                return false;
            }

            if ($eventType !== '' && (($entry['eventType'] ?? '') !== $eventType)) {
                return false;
            }

            if ($status !== '' && (($entry['status'] ?? '') !== $status)) {
                return false;
            }

            if ($jobId !== '' && (($entry['jobId'] ?? '') !== $jobId)) {
                return false;
            }

            if ($search !== '') {
                $needle = strtolower($search);
                $haystack = strtolower(wp_json_encode($entry) ?: '');
                if ($haystack === '' || strpos($haystack, $needle) === false) {
                    return false;
                }
            }

            return true;
        }));

        $total = count($filtered);
        $totalPages = max(1, (int) ceil($total / $pageSize));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $pageSize;
        $items = array_slice($filtered, $offset, $pageSize);
        $items = array_values(array_map(array($this->plugin, 'enrichAuditLogEntryForResponse'), $items));

        return new WP_REST_Response(array(
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalPages' => $totalPages,
        ), 200);
    }

    public function handleDownloadAuditArtifact(WP_REST_Request $request)
    {
        $archiveKey = sanitize_file_name((string) ($request->get_param('archive') ?? ''));
        $file = trim((string) ($request->get_param('file') ?? ''));
        $path = $this->plugin->resolveAuditArchiveFilePath($archiveKey, $file);

        if (!is_string($path) || !is_file($path) || !is_readable($path)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('Archived log artifact not found.', 'smartcloud-static-publisher'),
            ), 404);
        }

        $downloadName = sanitize_file_name($archiveKey . '-' . basename($path));
        if ($downloadName === '') {
            $downloadName = sanitize_file_name(basename($path));
        }

        $contents = $this->plugin->readFileContents($path);
        if (!is_string($contents)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('Unable to read archived log artifact.', 'smartcloud-static-publisher'),
            ), 500);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        nocache_headers();
        header('Content-Type: ' . $this->plugin->guessAuditArtifactContentType($path));
        header('Content-Disposition: attachment; filename="' . $downloadName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));

        header('Content-Length: ' . (string) strlen($contents));

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Serving a validated download response.
        echo $contents;
        exit;
    }

    private function getAdminScriptDependencies(array $dependencies = array()): array
    {
        $vendorHandles = array(
            'smartcloud-wpsuite-webcrypto-vendor',
            'smartcloud-wpsuite-amplify-vendor',
            'smartcloud-wpsuite-mantine-vendor',
        );

        foreach ($vendorHandles as $handle) {
            if (wp_script_is($handle, 'registered') && !in_array($handle, $dependencies, true)) {
                $dependencies[] = $handle;
            }
        }

        return array_values(array_unique($dependencies));
    }

    private function enqueueMantineVendorStyle(string $version): void
    {
        if (!defined('SMARTCLOUD_WPSUITE_URL')) {
            return;
        }

        wp_enqueue_style(
            'smartcloud-wpsuite-mantine-vendor-style',
            SMARTCLOUD_WPSUITE_URL . 'assets/css/mantine-vendor.css',
            array(),
            $version
        );
    }
}

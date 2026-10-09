<?php

namespace SmartCloud\WPSuite\StaticPublisher\Admin;

/** Local, intentionally small adapter over the canonical Publisher controller. */
trait PublishingSurface
{
    private function publishingHelp(): array
    {
        $topics = array(
            array('target-address', __('Target address', 'smartcloud-static-publisher'), __('The public address written into exported pages.', 'smartcloud-static-publisher'), array(__('Enter the destination site origin, such as https://www.example.com. Use a dot for a portable relative export. Additional deployment targets can override this address in Advanced.', 'smartcloud-static-publisher'))),
            array('link-format', __('Link format', 'smartcloud-static-publisher'), __('Choose how links are written in the export.', 'smartcloud-static-publisher'), array(__('Absolute links include the full address. Root-relative links start with / and resolve from the destination site root. Relative links resolve from the exported file.', 'smartcloud-static-publisher'))),
            array('export-directories', __('Export directories', 'smartcloud-static-publisher'), __('Use directories available to the installed exporter.', 'smartcloud-static-publisher'), array(__('Output directory is the storage-relative folder where generated files are written. Exporter directory override selects an existing exporter installation; leaving it empty uses the plugin default.', 'smartcloud-static-publisher'), __('Saving these paths does not install or start the exporter. Runtime installation and advanced storage settings remain in Advanced.', 'smartcloud-static-publisher'))),
            array('s3-destination', __('S3 destination', 'smartcloud-static-publisher'), __('Select the existing bucket, folder prefix and region.', 'smartcloud-static-publisher'), array(__('Bucket is the destination bucket name. Prefix identifies the folder-like key prefix within that bucket; leave it empty only when the configured target is intended to use the bucket root. Region must match the bucket region.', 'smartcloud-static-publisher'), __('Setup does not create storage or verify deployment access. Credentials and advanced deployment options remain unchanged. Publishing uses the existing deployment configuration, including any selected Pro default.', 'smartcloud-static-publisher'))),
            array('content-baseline', __('Content synchronization baseline', 'smartcloud-static-publisher'), __('Recorded baseline entries describe content synchronization history.', 'smartcloud-static-publisher'), array(__('The count comes from the recorded baseline file. It does not prove that the current destination matches the baseline or that an incremental publication is ready. Inspect jobs and logs for the result of a specific run.', 'smartcloud-static-publisher'))),
            array('queue-runner', __('Queue runner', 'smartcloud-static-publisher'), __('The runner executes queued publishing jobs.', 'smartcloud-static-publisher'), array(__('Runner status and last checked time come from its recorded heartbeat. A previous report is not a live connectivity test. The runner and deployment access must be installed separately.', 'smartcloud-static-publisher'))),
            array('publishing-jobs', __('Publishing jobs', 'smartcloud-static-publisher'), __('Use Publish to choose a task and deployment target for this run.', 'smartcloud-static-publisher'), array(__('Queued jobs wait for the runner. You can queue another publication while a job is running; it does not replace the running job. Stop current job requests a stop through the existing runner controls.', 'smartcloud-static-publisher'), __('Last job describes the latest recorded run, which may be a crawl or another operation rather than a publication. Its finish time does not by itself indicate success. Open publishing jobs and logs for details.', 'smartcloud-static-publisher'))),
        );
        return array_map(static fn(array $topic): array => array('id' => $topic[0], 'title' => $topic[1], 'summary' => $topic[2], 'paragraphs' => $topic[3]), $topics);
    }

    private function publishingRevision(): string
    {
        return hash('sha256', (string) wp_json_encode(get_option(self::OPTION_KEY, null)));
    }

    public function publishingSurface(): array|\WP_Error
    {
        if (!current_user_can('manage_options')) {
            return new \WP_Error('publisher_surface_forbidden', __('You cannot manage publishing.', 'smartcloud-static-publisher'), array('status' => 403));
        }
        $state = $this->handleGetState()->get_data();
        $config = $state['config'];
        $configured = !empty($state['hasSavedConfiguration']) && !empty($config['targetOrigin']) && !empty($config['s3']['bucket']) && !empty($config['s3']['region']) && !empty($config['outputDir']);
        $fieldHelp = array('targetOrigin' => 'target-address', 'urlRewriteMode' => 'link-format', 'outputDir' => 'export-directories', 'exporterDir' => 'export-directories', 'bucket' => 's3-destination', 'prefix' => 's3-destination', 'region' => 's3-destination');
        $fieldDescriptions = array(
            'targetOrigin' => __('Public destination address, such as https://www.example.com, or a dot for a portable export.', 'smartcloud-static-publisher'),
            'urlRewriteMode' => __('Write links as full addresses, paths from the site root, or paths relative to each file.', 'smartcloud-static-publisher'),
            'outputDir' => __('Storage-relative folder where the exporter writes generated files.', 'smartcloud-static-publisher'),
            'exporterDir' => __('Optional path to an installed exporter on the runner host. Leave empty to use the plugin default.', 'smartcloud-static-publisher'),
            'bucket' => __('Name of the existing S3 bucket that will receive the exported site.', 'smartcloud-static-publisher'),
            'prefix' => __('Folder-like key prefix inside the bucket. Leave empty only to publish at its root.', 'smartcloud-static-publisher'),
            'region' => __('AWS region of the destination bucket, for example eu-central-1.', 'smartcloud-static-publisher'),
        );
        $field = static fn(string $id, string $label, string $type = 'text', bool $required = false): array => array('id' => $id, 'label' => $label, 'type' => $type, 'required' => $required, 'help_id' => $fieldHelp[$id], 'description' => $fieldDescriptions[$id]);
        $actions = array();
        if (($state['currentRun']['status'] ?? '') === 'running') {
            $actions[] = array('id' => 'stop', 'label' => __('Stop current job', 'smartcloud-static-publisher'), 'confirm' => __('Request the current publishing job to stop?', 'smartcloud-static-publisher'));
        }
        return array(
            'schema_version' => 1, 'configured' => $configured, 'can_edit' => true, 'revision' => $this->publishingRevision(),
            'status' => array('state' => $configured ? 'ready' : 'needs_setup', 'label' => $configured ? __('Publishing configured', 'smartcloud-static-publisher') : __('Publishing setup needed', 'smartcloud-static-publisher')),
            'notice' => __('Publishing requires an installed runner and deployment access. Pro profiles are managed in Advanced.', 'smartcloud-static-publisher'),
            'introduction' => array(
                'title' => __('How static publishing works', 'smartcloud-static-publisher'),
                'description' => __('Keep editing in WordPress and publish an exported copy as your public website.', 'smartcloud-static-publisher'),
                'items' => array(
                    array('title' => __('Prepare your destination', 'smartcloud-static-publisher'), 'description' => __('Set the public address, export folder and existing S3 destination. Setup preserves other publishing settings and saves your choices only when you finish.', 'smartcloud-static-publisher')),
                    array('title' => __('Run jobs with your exporter', 'smartcloud-static-publisher'), 'description' => __('Install the separate Node exporter and queue runner on your server, workstation or CI host. WordPress queues jobs and shows progress; the runner needs access to the runtime files and your deployment credentials.', 'smartcloud-static-publisher')),
                    array('title' => __('Start without a WP Suite subscription', 'smartcloud-static-publisher'), 'description' => __('Full exports, S3 deployment, CloudFront invalidation and audit logs work without a WP Suite account or subscription. You provide the runner and AWS destination. Use Publish to select a task, then check jobs and logs for its result.', 'smartcloud-static-publisher')),
                    array('title' => __('Add Pro options when needed', 'smartcloud-static-publisher'), 'description' => __('Incremental publishing, targeted content sync, scheduling and additional deployment targets require an active Professional or Agency subscription. Their detailed configuration remains in Advanced settings and resources.', 'smartcloud-static-publisher')),
                ),
            ),
            'help' => $this->publishingHelp(),
            'metrics' => array(
                array('label' => __('Queued jobs', 'smartcloud-static-publisher'), 'help_id' => 'publishing-jobs', 'value' => (int) $state['queueLength']),
                array('label' => __('Runner last reported status', 'smartcloud-static-publisher'), 'help_id' => 'queue-runner', 'value' => (string) ($state['queueRunnerHeartbeat']['status'] ?? __('Not reported', 'smartcloud-static-publisher'))),
                array('label' => __('Runner last checked', 'smartcloud-static-publisher'), 'help_id' => 'queue-runner', 'value' => (string) ($state['queueRunnerHeartbeat']['checkedAt'] ?? __('Not reported', 'smartcloud-static-publisher'))),
                array('label' => __('Current job', 'smartcloud-static-publisher'), 'help_id' => 'publishing-jobs', 'value' => (string) ($state['currentRun']['status'] ?? __('None', 'smartcloud-static-publisher'))),
                array('label' => __('Last job', 'smartcloud-static-publisher'), 'help_id' => 'publishing-jobs', 'value' => (string) ($state['lastRun']['status'] ?? __('None', 'smartcloud-static-publisher'))),
                array('label' => __('Last job finished', 'smartcloud-static-publisher'), 'help_id' => 'publishing-jobs', 'value' => (string) ($state['lastRun']['endedAt'] ?? __('Not reported', 'smartcloud-static-publisher'))),
                array('label' => __('Recorded content-sync baselines', 'smartcloud-static-publisher'), 'help_id' => 'content-baseline', 'value' => count((array) ($state['contentSync']['baseline']['entries'] ?? array()))),
            ),
            'values' => array('targetOrigin' => $config['targetOrigin'], 'urlRewriteMode' => $config['urlRewriteMode'], 'outputDir' => $config['outputDir'], 'exporterDir' => $config['exporterDir'], 'bucket' => $config['s3']['bucket'], 'prefix' => $config['s3']['prefix'], 'region' => $config['s3']['region']),
            'steps' => array(
                array('id' => 'destination', 'help_id' => 'target-address', 'title' => __('Site destination', 'smartcloud-static-publisher'), 'description' => __('Choose the public target address. Use a dot for a portable relative export.', 'smartcloud-static-publisher'), 'fields' => array($field('targetOrigin', __('Target address', 'smartcloud-static-publisher'), 'text', true), array_merge($field('urlRewriteMode', __('Link format', 'smartcloud-static-publisher'), 'select', true), array('options' => array(array('value' => 'relative', 'label' => __('Relative', 'smartcloud-static-publisher')), array('value' => 'root-relative', 'label' => __('Root relative', 'smartcloud-static-publisher')), array('value' => 'absolute', 'label' => __('Absolute', 'smartcloud-static-publisher'))))))),
                array('id' => 'runtime', 'help_id' => 'export-directories', 'title' => __('Exporter', 'smartcloud-static-publisher'), 'description' => __('Use the existing exporter installation. An empty exporter path uses the plugin default; it does not install the runtime.', 'smartcloud-static-publisher'), 'fields' => array($field('outputDir', __('Output directory', 'smartcloud-static-publisher'), 'text', true), $field('exporterDir', __('Exporter directory override', 'smartcloud-static-publisher')))),
                array('id' => 'storage', 'help_id' => 's3-destination', 'title' => __('Deployment target', 'smartcloud-static-publisher'), 'description' => __('Set the existing S3 destination. Credentials and advanced deployment settings remain unchanged.', 'smartcloud-static-publisher'), 'fields' => array($field('bucket', __('S3 bucket', 'smartcloud-static-publisher'), 'text', true), $field('prefix', __('S3 prefix', 'smartcloud-static-publisher')), $field('region', __('AWS region', 'smartcloud-static-publisher'), 'text', true))),
            ),
            'actions' => $actions,
            'links' => array(
                array('label' => __('Publishing jobs and logs', 'smartcloud-static-publisher'), 'url' => admin_url('admin.php?page=' . self::MENU_SLUG . '&section=jobs')),
                array('label' => __('Publisher configuration', 'smartcloud-static-publisher'), 'url' => admin_url('admin.php?page=' . self::MENU_SLUG . '&section=configuration')),
            ),
        );
    }

    public function savePublishingSurface(array $values, string $revision): array|\WP_Error
    {
        if (!current_user_can('manage_options')) {
            return new \WP_Error('publisher_surface_forbidden', __('You cannot manage publishing.', 'smartcloud-static-publisher'), array('status' => 403));
        }
        if (!hash_equals($this->publishingRevision(), $revision)) {
            return new \WP_Error('publisher_surface_conflict', __('Publishing settings changed. Reload before saving.', 'smartcloud-static-publisher'), array('status' => 409));
        }
        $allowed = array('targetOrigin', 'urlRewriteMode', 'outputDir', 'exporterDir', 'bucket', 'prefix', 'region');
        if (array_diff(array_keys($values), $allowed) || array_filter($values, static fn($value): bool => !is_string($value))) {
            return new \WP_Error('publisher_surface_invalid', __('Unsupported publishing settings.', 'smartcloud-static-publisher'), array('status' => 400));
        }
        $config = $this->plugin->getConfig();
        foreach ($values as $key => $value) {
            if (in_array($key, array('bucket', 'prefix', 'region'), true)) {
                $config['s3'][$key] = $value;
            } else {
                $config[$key] = $value;
            }
        }
        if (empty($config['targetOrigin']) || ($config['targetOrigin'] !== '.' && (!filter_var($config['targetOrigin'], FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($config['targetOrigin'], PHP_URL_SCHEME)), array('http', 'https'), true))) || trim($config['s3']['bucket']) === '' || trim($config['s3']['region']) === '' || trim($config['outputDir']) === '' || !in_array($config['urlRewriteMode'], array('absolute', 'root-relative', 'relative'), true)) {
            return new \WP_Error('publisher_surface_invalid', __('Complete the target address, link format, output directory, bucket and region.', 'smartcloud-static-publisher'), array('status' => 400));
        }
        $request = new \WP_REST_Request('POST');
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode($config));
        $this->handleSaveConfig($request);
        return $this->publishingSurface();
    }

    public function publishingSurfaceAction(string $action, array $input): array|\WP_Error
    {
        if (!current_user_can('manage_options')) {
            return new \WP_Error('publisher_surface_forbidden', __('You cannot manage publishing.', 'smartcloud-static-publisher'), array('status' => 403));
        }
        if ($input || !in_array($action, array('publish', 'stop'), true)) {
            return new \WP_Error('publisher_surface_invalid', __('Unsupported publishing action.', 'smartcloud-static-publisher'), array('status' => 400));
        }
        if ($action === 'publish') {
            $surface = $this->publishingSurface();
            if (is_wp_error($surface)) return $surface;
            if (!$surface['configured']) return new \WP_Error('publisher_surface_setup_required', __('Save the publishing setup first.', 'smartcloud-static-publisher'), array('status' => 409));
            $request = new \WP_REST_Request('POST');
            $request->set_header('content-type', 'application/json');
            $request->set_body(wp_json_encode(array('command' => 'publish', 'crawlMode' => 'full')));
            $response = $this->handleQueueJob($request);
        } else {
            $response = $this->handleStopCurrentJob();
        }
        $data = $response->get_data();
        if ($response->get_status() >= 400) return new \WP_Error('publisher_surface_action_failed', $data['message'] ?? __('Publishing action failed.', 'smartcloud-static-publisher'), array('status' => $response->get_status()));
        return array('message' => $data['message'] ?? __('Publishing request accepted.', 'smartcloud-static-publisher'));
    }
}

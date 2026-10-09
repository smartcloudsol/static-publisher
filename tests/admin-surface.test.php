<?php
declare(strict_types=1);

namespace {
    define('ABSPATH', '/tmp/');
    $GLOBALS['allowed'] = true;
    $GLOBALS['options'] = array('smartcloud_static_publisher_config' => array('targetOrigin' => 'https://example.test', 'urlRewriteMode' => 'relative', 'outputDir' => 'export', 'exporterDir' => '/opt/exporter', 's3' => array('bucket' => 'old-bucket', 'prefix' => 'site', 'region' => 'eu-central-1', 'assetCacheControl' => 'unchanged'), 'cloudFront' => array('distributionId' => 'EXAMPLE', 'invalidationPaths' => array('/kept/*')), 'extraReplacements' => array('custom' => 'preserved')));
    function current_user_can($cap) { return $GLOBALS['allowed']; }
    function __($text, $domain = '') { return $text; }
    function wp_json_encode($value) { return json_encode($value); }
    function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
    function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
    function wp_mkdir_p($path) { return true; }
    function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
    function get_current_user_id() { return 1; }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    class WP_Error { public function __construct(public $code, public $message = '', public $data = array()) {} }
    class WP_REST_Response { public function __construct(private $data, private $status = 200) {} public function get_data() { return $this->data; } public function get_status() { return $this->status; } }
    class WP_REST_Request { private $body = ''; public function __construct($method) {} public function set_header($key, $value) {} public function set_body($body) { $this->body = $body; } public function get_json_params() { return json_decode($this->body, true); } }
}
namespace SmartCloud\WPSuite\StaticPublisher {
    class Plugin {
        public array $jobs = array();
        public array $runtimeState = array();
        public function withQueueMutationLock($callback) { return $callback(); }
        public function getConfig() { return \get_option('smartcloud_static_publisher_config'); }
        public function getResolvedConfig($config = null) { return $config ?? $this->getConfig(); }
        public function sanitizeConfig($config) { return $config; }
        public function stripRuntimeOnlyConfigFromWpStorage($config) { return $config; }
        public function buildRuntimeConfig($config) { return $config; }
        public function writeJsonFile($path, $data) {}
        public function ingestRuntimeAuditEvents() {}
        public function getRuntimePaths() { $keys = array('runtime', 'config', 'currentRun', 'currentProgress', 'currentCrawlEvent', 'lastRun', 'schedulerState', 'queueRunnerHeartbeat', 'deployDiff', 'contentSyncState', 'contentSyncCurrent', 'contentSyncCheckpoint', 'contentSyncBaseline', 'lock'); return array_combine($keys, array_map(static fn($key) => '/tmp/nonexistent-publisher-test/' . $key, $keys)); }
        public function readJsonFile($path) { return $this->runtimeState[basename($path)] ?? null; }
        public function sanitizeJobForState($job) { return $job; }
        public function readQueue() { return $this->jobs; }
        public function listLogFiles() { return array(); }
        public function getActiveStopRequest($run) { return null; }
        public function enqueueStandardJob($data, $actor, $user) { $this->jobs[] = $data; return array('message' => 'queued'); }
    }
}
namespace {
    require dirname(__DIR__) . '/admin/php/admin.php';
    $plugin = new \SmartCloud\WPSuite\StaticPublisher\Plugin();
    $admin = new \SmartCloud\WPSuite\StaticPublisher\Admin\Admin($plugin);
    $checks = 0;
    function check($value, $message) { global $checks; ++$checks; if (!$value) throw new RuntimeException($message); }
    $original = $plugin->getConfig();
    $surface = $admin->publishingSurface();
    check($surface['configured'], 'Existing local setup is recognized.');
    check($surface['status']['label'] === 'Publishing configured', 'Saved setup does not claim verified deployment readiness.');
    check(count($surface['introduction']['items']) === 4, 'Publishing introduction covers destination, runner, core features and optional Pro features.');
    check(str_contains($surface['introduction']['items'][1]['description'], 'separate Node exporter'), 'Introduction explicitly explains the separately installed runner prerequisite.');
    check(str_contains($surface['introduction']['items'][2]['description'], 'You provide the runner and AWS destination'), 'Subscription-free core features do not promise hosted deployment infrastructure.');
    $helpIds = array_column($surface['help'], 'id');
    foreach ($surface['steps'] as $step) {
        check(in_array($step['help_id'], $helpIds, true), 'Every wizard step has contextual help.');
        foreach ($step['fields'] as $field) {
            check(in_array($field['help_id'], $helpIds, true), 'Every publishing field resolves its help topic.');
            check(is_string($field['description'] ?? null) && trim($field['description']) !== '', 'Every publishing wizard field has immediately visible guidance, alongside contextual help.');
        }
    }
    foreach ($surface['metrics'] as $metric) check(in_array($metric['help_id'], $helpIds, true), 'Every publishing metric resolves its help topic.');
    $plugin->runtimeState = array('currentRun' => array('id' => 'running-job', 'status' => 'running'), 'lastRun' => array('command' => 'crawl', 'status' => 'failed', 'endedAt' => '2026-10-07T10:00:00Z'));
    $running = $admin->publishingSurface();
    check(array_column($running['actions'], 'id') === array('stop'), 'Publication uses the launch dialog without a duplicate full-publish action; stop remains available.');
    check(array_column($running['metrics'], 'value', 'label')['Last job finished'] === '2026-10-07T10:00:00Z', 'Recorded finish time is shown without claiming a successful publication.');
    check(!isset(array_column($running['metrics'], 'value', 'label')['Last publish']), 'A crawl is not mislabeled as a publication.');
    $plugin->runtimeState = array();
    $saved = $admin->savePublishingSurface(array('bucket' => 'new-bucket'), $surface['revision']);
    check(!is_wp_error($saved), 'Partial local save accepted.');
    $expected = $original; $expected['s3']['bucket'] = 'new-bucket';
    check($plugin->getConfig() === $expected, 'Nested advanced fields and unrelated local settings survive unchanged.');
    check($admin->savePublishingSurface(array('prefix' => 'other'), $surface['revision'])->data['status'] === 409, 'Stale revision rejected.');
    check($admin->savePublishingSurface(array('deploymentProfiles' => 'overwrite'), $saved['revision'])->data['status'] === 400, 'Pro field rejected.');
    check($admin->savePublishingSurface(array('targetOrigin' => 'javascript:alert(1)'), $saved['revision'])->data['status'] === 400, 'Invalid target rejected.');
    check($admin->publishingSurfaceAction('publish', array('awsTempCreds' => array()))->data['status'] === 400, 'Ad-hoc job fields rejected.');
    check(!is_wp_error($admin->publishingSurfaceAction('publish', array())), 'Canonical publish accepted.');
    check($plugin->jobs === array(array('command' => 'publish', 'crawlMode' => 'full')), 'Action uses only existing full publication contract.');
    $GLOBALS['allowed'] = false;
    check($admin->publishingSurface()->data['status'] === 403, 'Read checks capability.');
    check($admin->savePublishingSurface(array(), $saved['revision'])->data['status'] === 403, 'Save checks capability.');
    check($admin->publishingSurfaceAction('publish', array())->data['status'] === 403, 'Action checks capability.');
    echo "Publisher surface: {$checks} checks passed.\n";
}

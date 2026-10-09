<?php
declare(strict_types=1);
namespace SmartCloud\WPSuite\Hub {
    class ConfigurationReset {
        public static function revision(array $descriptor): string { return hash('sha256', json_encode(array_map(fn($key) => $GLOBALS['options'][$key] ?? null, $descriptor['options']))); }
        public static function deleteOptions(array $keys, bool $transaction = true) { if (!empty($GLOBALS['fail_options'])) return new \WP_Error('fixture_failure', 'failed', array()); foreach ($keys as $key) unset($GLOBALS['options'][$key]); return true; }
    }
}
namespace {
    define('ABSPATH', '/tmp/');
    function __($s, $domain = '') { return $s; }
    function current_user_can($cap) { return $GLOBALS['allowed']; }
    function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0700, true); }
    function wp_json_encode($value) { return json_encode($value); }
    function wp_generate_uuid4() { return bin2hex(random_bytes(16)); }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    class WP_Error { public function __construct(public $code, public $message, public $data) {} }
    require dirname(__DIR__) . '/admin/php/configuration-reset.php';
    class Fixture {
        use \SmartCloud\WPSuite\StaticPublisher\Admin\PublishingConfigurationReset;
        public function __construct(public $plugin) {}
    }
    $root = sys_get_temp_dir() . '/publisher-reset-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    $plugin = new class($root) {
        public array $jobs = array(); public $current = null; public bool $locked = false;
        public function __construct(public string $root) {}
        public function getRuntimePaths() { return array('runtime' => $this->root, 'config' => $this->root.'/config.json', 'remoteWorkers' => $this->root.'/remote-workers.json', 'lock' => $this->root.'/export.lock', 'queueMutationLock' => $this->root.'/queue-mutation.lock', 'currentRun' => $this->root.'/current-run.json'); }
        public function withQueueMutationLock($callback) { $this->locked = true; try { return $callback(); } finally { $this->locked = false; } }
        public function readQueue() {
            if (!$this->locked) throw new RuntimeException('Queue read without WP lock.');
            $lock = json_decode(file_get_contents($this->root.'/export.lock'), true);
            if (!array_key_exists('pid', $lock) || $lock['pid'] !== null || empty($lock['startedAt']) || $lock['operation'] !== 'configuration-reset') throw new RuntimeException('Runner wrapper must not mistake an administrative lock for a stale non-runner PID.');
            return $this->jobs;
        }
        public function readJsonFile($path) { return $this->current; }
    };
    $GLOBALS['allowed'] = true;
    $GLOBALS['options'] = array('smartcloud_static_publisher_config' => array('target'=>'old'), 'smartcloud_static_publisher_runtime_nonce'=>'fixture', 'smartcloud_static_publisher_audit_log'=>array('history'), 'smartcloud-wpsuite/site-settings'=>array('site'=>'preserve'), 'gatey'=>array('pool'=>'preserve'));
    $fixture = new Fixture($plugin);
    $descriptor = $fixture->publishingConfigurationReset();
    $revision = \SmartCloud\WPSuite\Hub\ConfigurationReset::revision($descriptor);
    $checks = 0;
    function check($condition, $message) { global $checks; ++$checks; if (!$condition) throw new RuntimeException($message); }
    try {
        file_put_contents($root.'/config.json', '{"fixture":true}'); file_put_contents($root.'/remote-workers.json', '{}'); file_put_contents($root.'/last-run.json', '{"status":"success"}');
        $GLOBALS['allowed'] = false;
        check($fixture->resetPublishingConfiguration($revision)->code === 'publisher_reset_forbidden', 'Reset requires management permission.');
        $GLOBALS['allowed'] = true;
        foreach (array('export.lock', 'queue-mutation.lock') as $lock) {
            file_put_contents($root.'/'.$lock, 'external');
            check($fixture->resetPublishingConfiguration($revision)->code === 'publisher_reset_busy', 'External runner/queue lock prevents reset.');
            check(file_get_contents($root.'/'.$lock) === 'external', 'External locks are never deleted.'); unlink($root.'/'.$lock);
        }
        $plugin->jobs = array(array('id'=>'queued'));
        check($fixture->resetPublishingConfiguration($revision)->code === 'publisher_reset_busy', 'Queued jobs prevent reset.'); $plugin->jobs = array();
        $plugin->current = array('id'=>'running');
        check($fixture->resetPublishingConfiguration($revision)->code === 'publisher_reset_busy', 'Current job prevents reset.'); $plugin->current = null;
        check($fixture->resetPublishingConfiguration('stale')->code === 'publisher_reset_conflict', 'Stale configuration requires review.');
        check(file_exists($root.'/config.json') && isset($GLOBALS['options']['smartcloud_static_publisher_config']), 'Rejected resets preserve configuration.');
        $GLOBALS['fail_options'] = true;
        check($fixture->resetPublishingConfiguration($revision)->code === 'fixture_failure', 'Option failure is reported.');
        check(file_get_contents($root.'/config.json') === '{"fixture":true}' && file_exists($root.'/remote-workers.json'), 'Option failure restores staged runner configuration.');
        check(!file_exists($root.'/export.lock') && !file_exists($root.'/queue-mutation.lock'), 'Failure releases owned locks.');
        $GLOBALS['fail_options'] = false;
        check($fixture->resetPublishingConfiguration($revision) === true, 'Confirmed idle reset succeeds.');
        check(!isset($GLOBALS['options']['smartcloud_static_publisher_config'], $GLOBALS['options']['smartcloud_static_publisher_runtime_nonce']), 'Only configuration and runtime access nonce are cleared.');
        check(!file_exists($root.'/config.json') && !file_exists($root.'/remote-workers.json'), 'Old generated runner configuration cannot continue scheduling.');
        check(file_exists($root.'/last-run.json') && isset($GLOBALS['options']['smartcloud_static_publisher_audit_log'], $GLOBALS['options']['gatey'], $GLOBALS['options']['smartcloud-wpsuite/site-settings']), 'History, other plugins and site connection survive.');
        check(!file_exists($root.'/export.lock') && !file_exists($root.'/queue-mutation.lock'), 'Owned locks released.');
        echo "Publisher configuration reset: $checks checks passed.\n";
    } finally { foreach (glob($root.'/*') as $path) unlink($path); rmdir($root); }
}

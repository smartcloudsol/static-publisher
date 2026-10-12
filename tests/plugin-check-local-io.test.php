<?php
declare(strict_types=1);

namespace SmartCloud\WPSuite\StaticPublisher {
    class Plugin {
        public function __construct(public array $current) {}
        public function readJsonFile($path) { return $path === 'current-fixture' ? $this->current : null; }
    }
}
namespace {
    define('ABSPATH', '/tmp/');
    function sanitize_text_field($value) { return $value; }
    function __($value, $domain = '') { return $value === 'Content-sync state cleanup failed.' ? 'Cleanup failed & <machine detail>.' : $value; }
    function wp_delete_file($file) { $GLOBALS['deleted_files'][] = $file; if (empty($GLOBALS['block_delete'])) unlink($file); }
    require dirname(__DIR__) . '/admin/php/admin.php';
    $checks = 0;
    function check($value, $message) { global $checks; ++$checks; if (!$value) throw new RuntimeException($message); }
    $root = sys_get_temp_dir() . '/publisher-local-io-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    try {
        $file = $root . '/impact.json';
        file_put_contents($file, '{}');
        $plugin = new \SmartCloud\WPSuite\StaticPublisher\Plugin(array('jobId' => 'fixture'));
        $admin = new \SmartCloud\WPSuite\StaticPublisher\Admin\Admin($plugin);
        $method = new ReflectionMethod($admin, 'abandonQueuedContentSyncState');
        $paths = array('contentSyncCurrent' => 'current-fixture', 'contentSyncImpactPlan' => $file);
        check($method->invoke($admin, array('id' => 'another'), $paths) === false && is_file($file), 'Unowned content-sync state is not removed.');
        check($method->invoke($admin, array('id' => 'fixture'), $paths) === true && !file_exists($file), 'Owned normal temporary state uses WordPress deletion.');
        check($GLOBALS['deleted_files'] === array($file), 'Deletion targets only the exact owned temporary file.');
        file_put_contents($file, '{}');
        $GLOBALS['block_delete'] = true;
        try {
            $method->invoke($admin, array('id' => 'fixture'), $paths);
            throw new RuntimeException('Filtered deletion must not claim success when the original file survives.');
        } catch (RuntimeException $error) {
            check($error->getMessage() === 'Cleanup failed & <machine detail>.', 'Machine exception preserves semantic translation, without HTML entity encoding.');
            check(json_decode(json_encode(array('message' => $error->getMessage())), true)['message'] === 'Cleanup failed & <machine detail>.', 'JSON transport retains the exact machine message.');
        }
        check(is_file($file), 'Rejected filtered deletion leaves the original file for operator recovery.');
        $reset = file_get_contents(dirname(__DIR__) . '/admin/php/configuration-reset.php');
        check(str_contains($reset, "fopen(\$path, 'x')"), 'Reset retains the Node runner exclusive-create lock protocol.');
        check(str_contains($reset, 'chmod($path, 0600)'), 'Administrative locks are private to the local owner.');
        check(str_contains($reset, 'rename($path, $backup)') && str_contains($reset, 'rename($backup, $path)'), 'Configuration staging and rollback retain same-directory atomic renames.');
        $source = file_get_contents(dirname(__DIR__) . '/smartcloud-static-publisher.php');
        check(str_contains($source, "fopen(\$auditPath, 'rb')") && str_contains($source, 'fseek($handle, $offset)'), 'Audit ingestion keeps binary byte-addressed local reads.');
        $readme = file_get_contents(dirname(__DIR__) . '/readme.txt');
        $notices = explode('== Upgrade Notice ==', $readme, 2)[1];
        preg_match_all('/= ([\d.]+) =\s*([^=]*)(?=\n= [\d.]+ =|$)/', $notices, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) check(strlen(trim($match[2])) <= 300, 'Upgrade notice ' . $match[1] . ' fits the WordPress.org limit.');
        $history = file_get_contents(dirname(__DIR__) . '/CHANGELOG-HISTORY.md');
        foreach (array('1.0.26', '1.0.25', '1.0.24', '1.0.23', '1.0.22', '1.0.21', '1.0.20', '1.0.16', '1.0.12') as $version) check(str_contains($history, '## ' . $version), 'Full historical operator guidance remains available for ' . $version . '.');
        echo "Publisher Plugin Check local IO: {$checks} checks passed.\n";
    } finally {
        if (file_exists($root . '/impact.json')) unlink($root . '/impact.json');
        rmdir($root);
    }
}

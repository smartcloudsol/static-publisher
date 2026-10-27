<?php
declare(strict_types=1);

namespace SmartCloud\WPSuite\Hub {
    final class BackendProfiles {
        public static function runWithContext(array $context, callable $callback): mixed {
            $previous = $GLOBALS['profile']; $GLOBALS['profile'] = $context;
            try { return $callback(); } finally { $GLOBALS['profile'] = $previous; }
        }
    }
}
namespace SmartCloud\WPSuite\StaticPublisher {
    final class Plugin {
        public static function instance(): self { return new self(); }
        public function getConfig(): array { return $GLOBALS['configs'][BackendProfile::context()['id']] ?? []; }
        public function withQueueMutationLock(callable $callback): mixed { return $callback(); }
    }
}
namespace {
    define('ABSPATH', __DIR__ . '/');
    final class WP_Error { public function __construct(public string $code, public string $message, public array $data = []) {} }
    $profile = null; $configs = [];
    $uploadsRoot = sys_get_temp_dir() . '/publisher-profile-rollout-' . getmypid();
    function wp_get_upload_dir(): array { return array('basedir' => $GLOBALS['uploadsRoot']); }
    function apply_filters(string $hook, mixed $value): mixed {
        if ($hook === 'smartcloud_wpsuite_backend_profile_context') return $GLOBALS['profile'];
        if ($hook === 'smartcloud_wpsuite_backend_profile_contexts') return $GLOBALS['configuredContexts'] ?? array();
        if ($hook === 'smartcloud_wpsuite_backend_profile_option_name' && ($GLOBALS['profile']['namespace'] ?? '') !== '') return 'wpsuite-profile/' . $GLOBALS['profile']['id'] . '/' . $value;
        return $value;
    }
    function untrailingslashit(string $value): string { return rtrim($value, '/'); }
    function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
    require dirname(__DIR__) . '/includes/class-backend-profile.php';
    $class = \SmartCloud\WPSuite\StaticPublisher\BackendProfile::class;
    check($class::context() === null && $class::storageSuffix() === '', 'ordinary installations remain unchanged');
    check($class::optionName('publisher') === 'publisher', 'ordinary option name unchanged');
    $dev = ['enabled' => true, 'id' => 'development', 'bindingRevision' => 2, 'generation' => 3, 'namespace' => '', 'legacyStateOwner' => 'development'];
    $prod = ['enabled' => true, 'id' => 'production', 'bindingRevision' => 1, 'generation' => 4, 'namespace' => 'production', 'legacyStateOwner' => 'development'];
    $profile = $dev;
    check($class::storageSuffix() === '/backend-profiles/v1/development', 'initial owner profile queue is inaccessible to old unprofiled runners');
    $profile = $prod;
    check($class::storageSuffix() === '/backend-profiles/v1/production', 'second profile has independent runtime state');
    check($class::optionName('publisher') === 'wpsuite-profile/production/publisher', 'second profile has independent config/lock/nonce keys');
    $class::assertTarget(['targetOrigin' => 'https://wpsuite.io', 's3' => ['bucket' => 'prod'], 'cloudFront' => ['distributionId' => 'PROD']]);
    try { $class::assertTarget(['targetOrigin' => 'https://staging.wpsuite.io', 's3' => ['bucket' => 'dev'], 'cloudFront' => ['distributionId' => 'DEV']]); throw new RuntimeException('wrong publishing target accepted'); }
    catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'does not match'), 'wrong target fails closed'); }
    $configs = [
        'production' => ['s3' => ['bucket' => 'shared', 'prefix' => 'prod'], 'cloudFront' => ['distributionId' => 'PROD']],
        'development' => ['s3' => ['bucket' => 'shared', 'prefix' => 'dev'], 'cloudFront' => ['distributionId' => 'DEV']],
    ];
    check($class::activationCheck(true, $prod, [$dev]) === true, 'same bucket with separate prefixes and distributions is supported');
    $configs['development']['s3']['prefix'] = 'prod/child';
    check($class::activationCheck(true, $prod, [$dev]) instanceof WP_Error, 'nested S3 prefixes are not isolated');
    $configs['development']['s3']['prefix'] = '';
    check($class::activationCheck(true, $prod, [$dev]) instanceof WP_Error, 'bucket root overlaps every prefix');
    $configs['development']['s3'] = ['bucket' => 'different', 'prefix' => 'dev'];
    $configs['development']['cloudFront']['distributionId'] = 'PROD';
    check($class::activationCheck(true, $prod, [$dev]) instanceof WP_Error, 'same distribution across profiles is rejected');
    $configuredContexts = [$dev, $prod];
    try {
        $class::assertTarget(['targetOrigin' => 'https://wpsuite.io', 's3' => ['bucket' => 'different', 'prefix' => 'dev'], 'cloudFront' => ['distributionId' => 'ANOTHER']]);
        throw new RuntimeException('post-activation destination overlap accepted');
    } catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'overlap'), 'queued publish validates current other-profile destinations too'); }
    $class::assertTarget(['targetOrigin' => 'https://wpsuite.io', 's3' => ['bucket' => 'isolated', 'prefix' => 'prod'], 'cloudFront' => ['distributionId' => 'ISOLATED']]);
    $configs['production'] = [];
    check($class::activationCheck(true, $prod, [$dev]) === true, 'unconfigured Publisher does not prevent initial profile setup');
    check($profile === $prod, 'validation restores caller profile context');
    $legacy = $uploadsRoot . '/smartcloud-static-publisher/runtime';
    mkdir($legacy, 0700, true);
    file_put_contents($legacy . '/config.json', '{"legacy":"private-fixture"}');
    check($class::initializationCheck(true, $dev) instanceof WP_Error, 'initialization requires compatible runner even before context activation');
    check(!file_exists($legacy . '/export.lock') && file_exists($legacy . '/config.json'), 'missing runner marker leaves legacy publishing untouched');
    try { $class::assertRunnerReady(); throw new RuntimeException('old runner accepted'); }
    catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'compatible'), 'profile enqueue fails closed until compatible runner starts'); }
    $protocol = $uploadsRoot . '/smartcloud-static-publisher/backend-profiles/v1';
    mkdir($protocol, 0700, true);
    $marker = array('protocol' => $class::RUNNER_PROTOCOL, 'pid' => getmypid(), 'updatedAt' => gmdate('c'), 'capabilities' => array('immutable-config', 'exact-origin-render', 'isolated-queues'));
    file_put_contents($protocol . '/runner-ready.json', json_encode($marker));
    file_put_contents($legacy . '/queue.json', json_encode(array(array('id' => 'old', 'status' => 'queued'))));
    check($class::initializationCheck(true, $dev) instanceof WP_Error, 'initialization rejects undrained legacy jobs');
    check(!file_exists($legacy . '/export.lock'), 'busy queue does not receive a premature fence');
    file_put_contents($legacy . '/queue.json', json_encode(array(array('id' => 'done', 'status' => 'success'))));
    file_put_contents($legacy . '/current-run.json', '{broken');
    check($class::initializationCheck(true, $dev) instanceof WP_Error, 'malformed current-run state fails closed');
    file_put_contents($legacy . '/current-run.json', 'null');
    file_put_contents($legacy . '/export.lock', 'running');
    check($class::initializationCheck(true, $dev) instanceof WP_Error, 'initialization rejects an active legacy runner');
    unlink($legacy . '/export.lock');
    check($class::initializationCheck(true, $dev) === true, 'idle legacy runtime is fenced and quarantined atomically');
    check(!file_exists($legacy . '/config.json') && file_get_contents($legacy . '/config.legacy-drained.json') === '{"legacy":"private-fixture"}', 'quarantine preserves original configuration privately');
    check(json_decode(file_get_contents($legacy . '/export.lock'), true)['purpose'] === 'backend-profile-legacy-fence', 'old exclusive-create runners remain permanently excluded');
    check(!file_exists($legacy . '/queue-mutation.lock'), 'temporary queue lock is released');
    check($class::initializationCheck(true, $dev) === true, 'retry after a later DB failure preserves fence and does not re-enable legacy scheduler');
    $class::assertRunnerReady();
    $marker['updatedAt'] = gmdate('c', time() - 700);
    file_put_contents($protocol . '/runner-ready.json', json_encode($marker));
    try { $class::assertRunnerReady(); throw new RuntimeException('stale runner accepted'); }
    catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'compatible'), 'stale compatible-runner proof cannot authorize new publishing'); }
    unlink($protocol . '/runner-ready.json'); unlink($legacy . '/queue.json'); unlink($legacy . '/current-run.json'); unlink($legacy . '/export.lock'); unlink($legacy . '/config.legacy-drained.json');
    rmdir($protocol); rmdir(dirname($protocol)); rmdir($legacy); rmdir(dirname($legacy)); rmdir($uploadsRoot);
    echo "Publisher backend profile isolation/target checks PASS\n";
}

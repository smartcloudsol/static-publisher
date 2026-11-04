<?php

namespace SmartCloud\WPSuite\StaticPublisher;

if (!defined('ABSPATH')) { exit; }

/** Shared-CMS backend context; absent on every ordinary installation. */
final class BackendProfile
{
    public static function context(): ?array
    {
        $context = function_exists('apply_filters') ? apply_filters('smartcloud_wpsuite_backend_profile_context', null) : null;
        if ($context === null || $context === false) return null;
        if (!is_array($context) || ($context['enabled'] ?? false) !== true
            || !in_array($context['id'] ?? '', array('production', 'development'), true)
            || !is_int($context['bindingRevision'] ?? null) || $context['bindingRevision'] < 1
            || !in_array($context['namespace'] ?? '', array('', $context['id']), true)) {
            throw new \RuntimeException('Invalid publishing backend profile context.');
        }
        if (isset($context['stateNamespace']) && $context['stateNamespace'] !== $context['id'] . '/bindings/' . $context['bindingRevision']) throw new \RuntimeException('Invalid publishing backend state namespace.');
        return $context;
    }

    public static function optionName(string $name): string
    {
        return function_exists('apply_filters') ? (string) apply_filters('smartcloud_wpsuite_backend_profile_option_name', $name) : $name;
    }

    public static function storageSuffix(): string
    {
        $context = self::context();
        return $context ? (isset($context['stateNamespace']) ? '/backend-profiles/v2/' . $context['stateNamespace'] : '/backend-profiles/v1/' . $context['id']) : '';
    }

    public static function targetShape(array $config): array
    {
        return ['targetOrigin' => (string) ($config['targetOrigin'] ?? ''),
            's3' => ['bucket' => (string) ($config['s3']['bucket'] ?? ''), 'prefix' => (string) ($config['s3']['prefix'] ?? ''), 'region' => (string) ($config['s3']['region'] ?? '')],
            'cloudFront' => ['distributionId' => (string) ($config['cloudFront']['distributionId'] ?? '')]];
    }

    public static function runtimeMetadata(array $config = []): ?array
    {
        $context = self::context();
        if (!$context) return null;
        $manager = '\\SmartCloud\\WPSuite\\Hub\\BackendProfiles';
        if (!class_exists($manager) || !is_callable(array($manager, 'renderHeaders'))) {
            throw new \RuntimeException('The publishing profile render binding is unavailable.');
        }
        return array(
            'id' => $context['id'],
            'bindingRevision' => $context['bindingRevision'],
            'generation' => (int) ($context['generation'] ?? 0),
            'namespace' => (string) ($context['namespace'] ?? ''),
            ...(isset($context['stateNamespace']) ? ['stateNamespace' => $context['stateNamespace'], 'target' => self::targetShape($config)] : []),
            'sourceOrigin' => untrailingslashit(home_url('/')),
            'renderHeaders' => $manager::renderHeaders($context),
        );
    }

    public static function assertTarget(array $config): void
    {
        $context = self::context();
        if (!$context) return;
        $expected = $context['id'] === 'production' ? 'https://wpsuite.io' : 'https://staging.wpsuite.io';
        if (isset($context['stateNamespace'])) {
            $identity = $context['identity'] ?? null;
            if (!is_array($identity) || ($identity['workspaceApiMode'] ?? '') !== 'workspace') throw new \RuntimeException('Invalid replacement backend identity.');
            $api = $identity['foundationApiBaseUrl'] ?? '';
            if ($context['id'] === 'production' && $api === 'https://api-prod.wpsuite.io') $expected = 'https://prod.wpsuite.io';
            elseif ($context['id'] === 'production' && $api !== 'https://api.wpsuite.io') throw new \RuntimeException('Invalid replacement backend API.');
            elseif ($context['id'] === 'development' && $api !== 'https://api-dev.wpsuite.io') throw new \RuntimeException('Invalid replacement backend API.');
            $target = self::targetShape($config);
            if ($target['s3']['region'] === '') throw new \RuntimeException('Configure the publishing region for this backend profile first.');
        }
        if (trim((string) ($config['s3']['bucket'] ?? '')) === '' || trim((string) ($config['cloudFront']['distributionId'] ?? '')) === '') throw new \RuntimeException('Configure the publishing bucket and CloudFront distribution for this backend profile first.');
        $others = function_exists('apply_filters') ? apply_filters('smartcloud_wpsuite_backend_profile_contexts', array()) : array();
        $validation = self::activationCheck(true, $context, is_array($others) ? $others : array(), $config);
        if ($validation !== true) throw new \RuntimeException('The publishing profile destinations overlap or could not be verified.');
        if (untrailingslashit((string) ($config['targetOrigin'] ?? '')) !== $expected) {
            throw new \RuntimeException('The publishing target does not match this backend profile.');
        }
        if (!empty($config['remoteWorkers']['render']['enabled']) || !empty($config['remoteWorkers']['rewrite']['enabled']) || !empty($config['remoteWorkers']['deploy']['enabled'])) self::assertRunnerReady(false, true);
    }

    public const RUNNER_PROTOCOL = 'wpsuite-publisher-backend-profile-v1';
    public const RUNNER_PROTOCOL_V2 = 'wpsuite-publisher-backend-profile-v2';

    private static function legacyRuntimeRoot(): string
    {
        $uploads = wp_get_upload_dir();
        return rtrim((string) $uploads['basedir'], '/') . '/smartcloud-static-publisher/runtime';
    }

    /** Old executors must be stopped/drained before shared-CMS bindings change. */
    private static function legacyFence(string $path): bool
    {
        $value = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        return is_array($value) && ($value['protocol'] ?? '') === self::RUNNER_PROTOCOL && ($value['purpose'] ?? '') === 'backend-profile-legacy-fence';
    }

    public static function assertLegacyIdle(): void
    {
        $root = self::legacyRuntimeRoot();
        foreach (array('export.lock', 'queue-mutation.lock') as $file) {
            if (file_exists($root . '/' . $file) && !($file === 'export.lock' && self::legacyFence($root . '/' . $file))) throw new \RuntimeException('Stop and drain the legacy Publisher runner before enabling backend profiles.');
        }
        foreach (array('queue.json', 'current-run.json') as $file) {
            $path = $root . '/' . $file;
            if (!file_exists($path)) continue;
            $data = json_decode((string) file_get_contents($path), true);
            if ($file === 'current-run.json' && $data === null && json_last_error() === JSON_ERROR_NONE) continue;
            if (!is_array($data)) throw new \RuntimeException('The legacy publishing queue could not be verified.');
            $jobs = $file === 'queue.json' ? $data : array($data);
            foreach ($jobs as $job) {
                if (!is_array($job) || ($job && in_array((string) ($job['status'] ?? 'queued'), array('queued', 'retry-wait', 'running'), true))) throw new \RuntimeException('Drain the legacy publishing queue before enabling backend profiles.');
            }
        }
    }

    public static function assertRunnerReady(bool $required = false, bool $remoteRequired = false): void
    {
        if (!$required && !self::context()) return;
        $v2 = isset(self::context()['stateNamespace']);
        $protocol = $v2 ? self::RUNNER_PROTOCOL_V2 : self::RUNNER_PROTOCOL;
        $path = dirname(self::legacyRuntimeRoot()) . '/backend-profiles/' . ($v2 ? 'v2' : 'v1') . '/runner-ready.json';
        $marker = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $capabilities = array('immutable-config', 'exact-origin-render', 'isolated-queues');
        if ($v2) $capabilities = array_merge($capabilities, array('revision-bound-target', 'current-binding-check'));
        if ($remoteRequired) $capabilities[] = 'remote-profile-v1';
        if (($remoteRequired && !$v2) || !is_array($marker) || ($marker['protocol'] ?? '') !== $protocol
            || !is_int($marker['pid'] ?? null) || $marker['pid'] < 1
            || !is_string($marker['updatedAt'] ?? null) || strtotime($marker['updatedAt']) < time() - 600 || strtotime($marker['updatedAt']) > time() + 60
            || array_diff($capabilities, (array) ($marker['capabilities'] ?? array()))) {
            throw new \RuntimeException($remoteRequired ? 'Install and start the backend-profile Lambda-compatible Publisher runner before enabling delegation.' : 'Install and start the compatible backend-profile Publisher runner before publishing.');
        }
    }

    /** Atomic routing inventory: only current registry bindings, never archived revisions. */
    public static function writeCurrentBindings(): void
    {
        if (!isset(self::context()['stateNamespace'])) return;
        $manager = '\\SmartCloud\\WPSuite\\Hub\\BackendProfiles';
        if (!class_exists($manager) || !is_callable([$manager, 'configuredContexts'])) throw new \RuntimeException('Publisher binding registry unavailable.');
        $root = dirname(self::legacyRuntimeRoot()) . '/backend-profiles/v2';
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) throw new \RuntimeException('Publisher binding directory unavailable.');
        $lock = fopen($root . '/current-bindings.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('Publisher binding registry lock unavailable.');
        try {
            $bindings = [];
            foreach ($manager::configuredContexts() as $row) {
                if (!isset($row['stateNamespace'])) continue;
                if (!in_array($row['id'] ?? '', ['production', 'development'], true) || !is_int($row['bindingRevision'] ?? null) || $row['bindingRevision'] < 1 || $row['stateNamespace'] !== $row['id'] . '/bindings/' . $row['bindingRevision']) throw new \RuntimeException('Invalid current Publisher binding.');
                $bindings[] = ['id' => $row['id'], 'bindingRevision' => $row['bindingRevision'], 'stateNamespace' => $row['stateNamespace']];
            }
            $current = self::context();
            if (!in_array(['id' => $current['id'], 'bindingRevision' => $current['bindingRevision'], 'stateNamespace' => $current['stateNamespace']], $bindings, true)) throw new \RuntimeException('Current Publisher binding is stale.');
            $tmp = $root . '/current-bindings.' . bin2hex(random_bytes(8)) . '.tmp';
            $json = json_encode(['protocol' => self::RUNNER_PROTOCOL_V2, 'bindings' => $bindings], JSON_THROW_ON_ERROR);
            if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json) || !chmod($tmp, 0600) || !rename($tmp, $root . '/current-bindings.json')) { @unlink($tmp); throw new \RuntimeException('Publisher binding registry write failed.'); }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function initializationCheck($result, $owner = null)
    {
        if ($result !== true) return $result;
        try {
            // Validate readiness first: a missing new runner never disrupts legacy publishing.
            self::assertRunnerReady(true);
            self::assertLegacyIdle();
            return Plugin::instance()->withQueueMutationLock(static function () {
                $root = self::legacyRuntimeRoot();
                if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) throw new \RuntimeException('The legacy publishing runtime could not be fenced.');
                $fencePath = $root . '/export.lock';
                $fence = @fopen($fencePath, 'x');
                if ($fence === false && !self::legacyFence($fencePath)) throw new \RuntimeException('Stop the legacy Publisher runner before enabling backend profiles.');
                if (is_resource($fence)) {
                    $written = fwrite($fence, json_encode(array('protocol' => self::RUNNER_PROTOCOL, 'purpose' => 'backend-profile-legacy-fence', 'pid' => getmypid(), 'startedAt' => gmdate('c'))));
                    fflush($fence); fclose($fence);
                    if (!$written) throw new \RuntimeException('The legacy publishing fence could not be persisted.');
                }
                // This lock is intentionally never automatically removed, including DB failure.
                // It uses the old CLI's exclusive-create protocol to close the cron startup race.
                $queueLock = @fopen($root . '/queue-mutation.lock', 'x');
                if ($queueLock === false) throw new \RuntimeException('The legacy publishing queue is busy; initialization remains fenced.');
                try {
                    fclose($queueLock);
                    $queueLock = null;
                    // Ignore our queue lock only while holding it; export.lock remains fenced.
                    foreach (array('queue.json', 'current-run.json') as $file) {
                        $path = $root . '/' . $file;
                        if (!file_exists($path)) continue;
                        $data = json_decode((string) file_get_contents($path), true);
                        if ($file === 'current-run.json' && $data === null && json_last_error() === JSON_ERROR_NONE) continue;
                        if (!is_array($data)) throw new \RuntimeException('The legacy publishing queue could not be verified.');
                        foreach ($file === 'queue.json' ? $data : array($data) as $job) {
                            if (!is_array($job) || ($job && in_array((string) ($job['status'] ?? 'queued'), array('queued', 'retry-wait', 'running'), true))) throw new \RuntimeException('The legacy publishing queue changed; initialization remains fenced.');
                        }
                    }
                    $source = $root . '/config.json';
                    $quarantine = $root . '/config.legacy-drained.json';
                    if (is_file($source)) {
                        if (file_exists($quarantine)) throw new \RuntimeException('A legacy config appeared after quarantine; review it before retrying.');
                        if (!chmod($source, 0600) || !rename($source, $quarantine)) throw new \RuntimeException('The legacy publishing configuration could not be quarantined.');
                    }
                    return true;
                } finally {
                    if (is_resource($queueLock)) fclose($queueLock);
                    @unlink($root . '/queue-mutation.lock');
                }
            });
        } catch (\Throwable $error) {
            return new \WP_Error('publisher_legacy_runtime_active', $error->getMessage(), array('status' => 409));
        }
    }

    /** Compare only configured local publishing destinations, never credentials. */
    public static function activationCheck($result, array $target, array $others, ?array $candidate = null)
    {
        if ($result !== true) return $result;
        $manager = '\\SmartCloud\\WPSuite\\Hub\\BackendProfiles';
        if (!class_exists($manager)) return $result;
        try {
            self::assertLegacyIdle();
            $read = static fn() => Plugin::instance()->getConfig();
            $config = $candidate ?? $manager::runWithContext($target, $read);
            $bucket = trim((string) ($config['s3']['bucket'] ?? ''));
            if ($bucket === '') return $result; // A new profile can be configured before Publisher.
            foreach ($others as $other) {
                if (!is_array($other) || ($other['id'] ?? '') === ($target['id'] ?? '')) continue;
                $previous = $manager::runWithContext($other, $read);
                if (trim((string) ($previous['s3']['bucket'] ?? '')) === '') continue;
                $prefix = trim((string) ($config['s3']['prefix'] ?? ''), '/');
                $otherPrefix = trim((string) ($previous['s3']['prefix'] ?? ''), '/');
                $overlap = $prefix === '' || $otherPrefix === '' || $prefix === $otherPrefix
                    || str_starts_with($prefix . '/', $otherPrefix . '/') || str_starts_with($otherPrefix . '/', $prefix . '/');
                $distribution = trim((string) ($config['cloudFront']['distributionId'] ?? ''));
                if (($bucket === trim((string) ($previous['s3']['bucket'] ?? '')) && $overlap)
                    || ($distribution !== '' && $distribution === trim((string) ($previous['cloudFront']['distributionId'] ?? '')))) {
                    return new \WP_Error('publisher_backend_profile_overlap', 'The publishing profiles must use separate S3 prefixes and CloudFront distributions.', array('status' => 409));
                }
            }
            return $result;
        } catch (\Throwable $error) {
            return new \WP_Error('publisher_backend_profile_validation_failed', 'Publishing profile isolation could not be verified.', array('status' => 409));
        }
    }
}

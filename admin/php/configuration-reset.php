<?php

namespace SmartCloud\WPSuite\StaticPublisher\Admin;

use SmartCloud\WPSuite\Hub\ConfigurationReset;

/** Reset configuration only; publishing output, queues and audit history are never deleted. */
trait PublishingConfigurationReset
{
    public function publishingConfigurationReset(): array
    {
        return array(
            'options' => array('smartcloud_static_publisher_config', 'smartcloud_static_publisher_runtime_nonce'),
            'title' => __('Reset all Static Publishing configuration?', 'smartcloud-static-publisher'),
            'consequences' => array(
                __('All publishing settings saved in WordPress will be removed, including destinations, export settings, remote worker settings and advanced options.', 'smartcloud-static-publisher'),
                __('Generated runner configuration will be removed. Publishing must be configured again. Finish or remove queued jobs and stop the runner before resetting.', 'smartcloud-static-publisher'),
            ),
            'preserved' => array(__('Published files, WordPress content, job and audit history, synchronization baselines and AWS resources are preserved. Account-managed deployment profiles and schedules remain on your backend.', 'smartcloud-static-publisher')),
            'reset' => array($this, 'resetPublishingConfiguration'),
        );
    }

    public function resetPublishingConfiguration(string $revision): bool|\WP_Error
    {
        if (!current_user_can('manage_options')) {
            return new \WP_Error('publisher_reset_forbidden', __('You cannot reset publishing configuration.', 'smartcloud-static-publisher'), array('status' => 403));
        }
        try {
            return $this->plugin->withQueueMutationLock(function () use ($revision) {
                $paths = $this->plugin->getRuntimePaths();
                if (!wp_mkdir_p($paths['runtime'])) {
                    return new \WP_Error('publisher_reset_storage', __('The publishing runtime directory is not writable.', 'smartcloud-static-publisher'), array('status' => 500));
                }
                // The Node runner uses exclusive-create files, not flock. Acquire both exact locks.
                $handles = array();
                $staged = array();
                $optionsReset = false;
                try {
                    foreach (array($paths['lock'], $paths['queueMutationLock']) as $path) {
                        $handle = @fopen($path, 'x'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Same exclusive-create protocol as the runner.
                        if (false === $handle) {
                            return new \WP_Error('publisher_reset_busy', __('Stop the publishing runner and wait for queue operations to finish before resetting.', 'smartcloud-static-publisher'), array('status' => 409));
                        }
                        $handles[$path] = $handle;
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Protect this exclusively created local Node-runner lock; remote filesystem modes cannot enforce its process-local protocol.
                        if (!chmod($path, 0600)) throw new \RuntimeException('Cannot protect publishing reset lock.');
                        // The safe runner wrapper treats any numeric non-runner PID as stale.
                        // Leave pid null so it conservatively respects this administrative lock.
                        $payload = wp_json_encode(array('pid' => null, 'startedAt' => gmdate('c'), 'createdAt' => gmdate('c'), 'operation' => 'configuration-reset'));
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write to the owned exclusive-create handle; reopening through WP_Filesystem would break the Node runner's lock protocol.
                        if (!is_string($payload) || fwrite($handle, $payload) !== strlen($payload) || !fflush($handle)) throw new \RuntimeException('Cannot write publishing reset lock.');
                    }
                    if ($this->plugin->readQueue() || $this->plugin->readJsonFile($paths['currentRun'])) {
                        return new \WP_Error('publisher_reset_busy', __('Finish or remove queued jobs and stop the current publishing job before resetting.', 'smartcloud-static-publisher'), array('status' => 409));
                    }
                    if (!hash_equals($revision, ConfigurationReset::revision($this->publishingConfigurationReset()))) {
                        return new \WP_Error('publisher_reset_conflict', __('Publishing settings changed. Review the reset again.', 'smartcloud-static-publisher'), array('status' => 409));
                    }
                    // Stage only generated configuration. A failure restores it before releasing the runner locks.
                    foreach (array($paths['config'], $paths['remoteWorkers']) as $path) {
                        if (!file_exists($path)) continue;
                        $backup = $path . '.reset-' . wp_generate_uuid4();
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-directory local rename must atomically remove runner-visible configuration while both runner locks are held.
                        if (!rename($path, $backup)) throw new \RuntimeException('Cannot stage runner configuration.');
                        $staged[$path] = $backup;
                    }
                    $result = ConfigurationReset::deleteOptions($this->publishingConfigurationReset()['options']);
                    if (is_wp_error($result)) return $result;
                    $optionsReset = true;
                    foreach ($staged as $backup) {
                        wp_delete_file($backup);
                        clearstatcache(true, $backup);
                        if (file_exists($backup)) throw new \RuntimeException('Cannot remove staged runner configuration.');
                    }
                    $staged = array();
                    return true;
                } finally {
                    foreach ($staged as $path => $backup) {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Roll back the same local atomic staging rename before releasing the Node runner locks.
                        if (!$optionsReset && file_exists($backup)) rename($backup, $path);
                    }
                    foreach (array_reverse($handles, true) as $path => $handle) {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the owned exclusive-create local lock handle before unlinking its exact pathname.
                        fclose($handle);
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete only our exact local runner lock; wp_delete_file filters may redirect removal and leave the runner indefinitely blocked.
                        unlink($path);
                    }
                }
            });
        } catch (\Throwable $error) {
            return new \WP_Error('publisher_reset_failed', __('Publishing configuration could not be completely reset. Review the settings and runner before trying again.', 'smartcloud-static-publisher'), array('status' => 500));
        }
    }
}

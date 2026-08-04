<?php
/**
 * SmartCloud Static Publisher uninstall cleanup.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

function smartcloud_static_publisher_remove_storage(): void
{
    $upload = wp_get_upload_dir();
    $base = realpath((string) ($upload['basedir'] ?? ''));
    $root = realpath(trailingslashit((string) ($upload['basedir'] ?? '')) . 'smartcloud-static-publisher');
    if ($base === false || $root === false || basename($root) !== 'smartcloud-static-publisher') {
        return;
    }

    $base_prefix = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with($root . DIRECTORY_SEPARATOR, $base_prefix)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        if ($entry->isLink() || $entry->isFile()) {
            unlink($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Explicit uninstall of files beneath the validated plugin-owned upload root.
        } elseif ($entry->isDir()) {
            rmdir($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Explicit uninstall of directories beneath the validated plugin-owned upload root.
        }
    }
    rmdir($root); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Validated plugin-owned upload root.
}

function smartcloud_static_publisher_uninstall_site(): void
{
    foreach (array(
        'smartcloud_static_publisher_config',
        'smartcloud_static_publisher_audit_log',
        'smartcloud_static_publisher_audit_cursor',
        'smartcloud_static_publisher_runtime_nonce',
        'smartcloud_static_publisher_queue_mutation_lock',
    ) as $option) {
        delete_option($option);
    }

    smartcloud_static_publisher_remove_storage();
}

if (is_multisite()) {
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $smartcloud_static_publisher_site_id) {
        switch_to_blog((int) $smartcloud_static_publisher_site_id);
        smartcloud_static_publisher_uninstall_site();
        restore_current_blog();
    }
} else {
    smartcloud_static_publisher_uninstall_site();
}

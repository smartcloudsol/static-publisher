<?php

if (!defined('ABSPATH') || !defined('WP_CLI')) {
    fwrite(STDERR, "Run this file through WP-CLI: wp eval-file tests/integration/wordpress-booted.php\n");
    exit(2);
}

function publisher_integration_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function publisher_integration_request(string $route, array $payload, ?string $token): WP_REST_Response
{
    $request = new WP_REST_Request('POST', '/smartcloud-static-publisher/v1/content-sync/' . $route);
    $request->set_header('content-type', 'application/json');
    if ($token !== null) {
        $request->set_header('x-static-publisher-token', $token);
    }
    $request->set_body(wp_json_encode($payload));
    $response = rest_do_request($request);
    publisher_integration_assert($response instanceof WP_REST_Response, "{$route} did not return a REST response.");
    return $response;
}

function publisher_integration_change_tokens(array $urls, string $token): WP_REST_Response
{
    $request = new WP_REST_Request('POST', '/smartcloud-static-publisher/v1/change-tokens');
    $request->set_header('content-type', 'application/json');
    $request->set_header('x-static-publisher-token', $token);
    $request->set_body(wp_json_encode(array('urls' => $urls)));
    $response = rest_do_request($request);
    publisher_integration_assert($response instanceof WP_REST_Response, 'Change-token lookup did not return a REST response.');
    return $response;
}

function publisher_integration_events(
    string $token,
    string $consumerId,
    string $scopeFingerprint,
    string $baselineId,
    int $afterSequence,
    int $throughSequence
): array {
    $items = array();
    $cursor = $afterSequence;
    do {
        $response = publisher_integration_request('events', array(
            'consumerId' => $consumerId,
            'scopeFingerprint' => $scopeFingerprint,
            'baselineId' => $baselineId,
            'postTypes' => array('post'),
            'includeSubsites' => false,
            'afterSequence' => $cursor,
            'throughSequence' => $throughSequence,
            'limit' => 2,
        ), $token);
        publisher_integration_assert($response->get_status() === 200, 'Paginated event request failed.');
        $data = $response->get_data();
        $page = is_array($data['items'] ?? null) ? $data['items'] : array();
        $items = array_merge($items, $page);
        $next = (int) ($data['nextSequence'] ?? $cursor);
        $hasMore = (bool) ($data['hasMore'] ?? false);
        publisher_integration_assert(!$hasMore || $next > $cursor, 'Event pagination did not advance.');
        $cursor = $next;
    } while ($hasMore);
    return $items;
}

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$pluginFile = 'smartcloud-static-publisher/smartcloud-static-publisher.php';
publisher_integration_assert(
    is_plugin_active($pluginFile) || is_plugin_active_for_network($pluginFile),
    'SmartCloud Static Publisher must be active for this site.'
);

do_action('rest_api_init');

$tablePrefix = is_multisite() ? $wpdb->base_prefix : $wpdb->prefix;
$eventsTable = $tablePrefix . 'smartcloud_static_publisher_content_events';
$consumersTable = $tablePrefix . 'smartcloud_static_publisher_content_consumers';
$eventColumns = $wpdb->get_col("SHOW COLUMNS FROM {$eventsTable}", 0);
$consumerColumns = $wpdb->get_col("SHOW COLUMNS FROM {$consumersTable}", 0);
$requiredEventColumns = array(
    'sequence',
    'blog_id',
    'recorded_gmt',
    'post_id',
    'post_type',
    'operation',
    'before_projection',
    'after_projection',
    'correlation_id',
);
$missingEventColumns = array_values(array_diff($requiredEventColumns, array_map('strval', $eventColumns)));
publisher_integration_assert(
    empty($missingEventColumns),
    'The real content-event table is missing required columns: '
        . implode(', ', $missingEventColumns)
        . '. Actual columns: '
        . implode(', ', array_map('strval', $eventColumns))
);
$missingConsumerColumns = array_values(array_diff(
    array('consumer_id', 'root_blog_id', 'include_subsites', 'scope_fingerprint', 'baseline_id', 'sequence', 'post_types', 'acknowledged_gmt'),
    array_map('strval', $consumerColumns)
));
publisher_integration_assert(
    empty($missingConsumerColumns),
    'The real content-consumer table is missing required columns: '
        . implode(', ', $missingConsumerColumns)
        . '. Actual columns: '
        . implode(', ', array_map('strval', $consumerColumns))
);

$token = (string) get_option('smartcloud_static_publisher_runtime_nonce', '');
publisher_integration_assert($token !== '', 'The runtime token is missing.');

$unauthenticated = publisher_integration_request('head', array('postTypes' => array('post')), null);
publisher_integration_assert($unauthenticated->get_status() === 401, 'Unauthenticated journal access must return 401.');

$initialHeadResponse = publisher_integration_request('head', array(
    'postTypes' => array('post'),
    'includeSubsites' => false,
), $token);
publisher_integration_assert($initialHeadResponse->get_status() === 200, 'Authenticated head request failed.');
$initialHead = (int) ($initialHeadResponse->get_data()['headSequence'] ?? -1);

$consumerId = 'booted-integration-' . wp_generate_uuid4();
$scopeFingerprint = hash('sha256', 'booted-integration-scope-' . $consumerId);
$baselineId = hash('sha256', 'booted-integration-baseline-' . $consumerId);
$baseline = publisher_integration_request('baseline', array(
    'consumerId' => $consumerId,
    'scopeFingerprint' => $scopeFingerprint,
    'baselineId' => $baselineId,
    'postTypes' => array('post'),
    'includeSubsites' => false,
    'sequence' => $initialHead,
), $token);
publisher_integration_assert($baseline->get_status() === 200, 'Baseline establishment failed.');

$postIds = array();
$termId = 0;
$mediaFile = '';
register_shutdown_function(static function () use (&$postIds, &$termId, &$mediaFile, $eventsTable, $consumersTable, $consumerId): void {
    global $wpdb;
    $cleanupPostIds = array_values(array_unique(array_map('absint', $postIds)));
    foreach ($postIds as $cleanupPostId) {
        wp_delete_post((int) $cleanupPostId, true);
    }
    if ($termId > 0) {
        wp_delete_term($termId, 'category');
    }
    if ($mediaFile !== '' && is_file($mediaFile)) {
        unlink($mediaFile);
    }
    if (!empty($cleanupPostIds)) {
        $placeholders = implode(', ', array_fill(0, count($cleanupPostIds), '%d'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$eventsTable} WHERE blog_id = %d AND post_id IN ({$placeholders})",
            array_merge(array(get_current_blog_id()), $cleanupPostIds)
        ));
    }
    $wpdb->delete($consumersTable, array('consumer_id' => $consumerId), array('%s'));
});
$httpAttempts = 0;
$httpGuard = static function ($preempt, $args, $url) use (&$httpAttempts) {
    unset($preempt, $args, $url);
    $httpAttempts++;
    return new WP_Error('publisher_integration_http_blocked', 'Save hooks must not perform network I/O.');
};
add_filter('pre_http_request', $httpGuard, PHP_INT_MIN, 3);

try {
    $uploads = wp_upload_dir();
    publisher_integration_assert(empty($uploads['error']), 'The integration upload directory is unavailable.');
    $mediaDirectory = trailingslashit((string) $uploads['basedir']) . 'publisher-integration';
    publisher_integration_assert(wp_mkdir_p($mediaDirectory), 'Could not create the disposable media directory.');
    $mediaName = 'token-' . substr(str_replace('-', '', $consumerId), -12) . '.txt';
    $mediaFile = trailingslashit($mediaDirectory) . $mediaName;
    $mediaUrl = trailingslashit((string) $uploads['baseurl']) . 'publisher-integration/' . rawurlencode($mediaName);
    publisher_integration_assert(file_put_contents($mediaFile, 'first media revision') !== false, 'Could not create the disposable media file.');
    clearstatcache(true, $mediaFile);
    $mediaTokenResponse = publisher_integration_change_tokens(array($mediaUrl), $token);
    publisher_integration_assert($mediaTokenResponse->get_status() === 200, 'Media change-token lookup failed.');
    $mediaTokenItem = $mediaTokenResponse->get_data()['items'][0] ?? null;
    publisher_integration_assert(
        is_array($mediaTokenItem)
        && !empty($mediaTokenItem['supported'])
        && ($mediaTokenItem['tokenSource'] ?? '') === 'wp-media-file'
        && is_string($mediaTokenItem['token'] ?? null),
        'A same-site Media Library file must expose a reusable file token.'
    );
    $firstMediaToken = (string) $mediaTokenItem['token'];
    publisher_integration_assert(file_put_contents($mediaFile, 'second, longer media revision') !== false, 'Could not update the disposable media file.');
    clearstatcache(true, $mediaFile);
    $changedMediaTokenResponse = publisher_integration_change_tokens(array($mediaUrl), $token);
    $changedMediaTokenItem = $changedMediaTokenResponse->get_data()['items'][0] ?? null;
    publisher_integration_assert(
        is_array($changedMediaTokenItem) && (string) ($changedMediaTokenItem['token'] ?? '') !== $firstMediaToken,
        'A replaced Media Library file must invalidate its trusted asset token.'
    );

    $term = wp_insert_term('Publisher integration ' . substr($consumerId, -8), 'category');
    publisher_integration_assert(!is_wp_error($term), 'Could not create the disposable category.');
    $termId = (int) $term['term_id'];

    $slug = 'publisher-integration-' . substr(str_replace('-', '', $consumerId), -12);
    $postId = wp_insert_post(array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_title' => 'Publisher booted integration',
        'post_name' => $slug,
        'post_content' => 'Initial public body.',
    ), true);
    publisher_integration_assert(!is_wp_error($postId), 'Publish transition failed.');
    $postId = (int) $postId;
    $postIds[] = $postId;
    $publicUrl = get_permalink($postId);
    publisher_integration_assert(is_string($publicUrl) && str_contains($publicUrl, $slug), 'The public permalink is invalid.');

    $updated = wp_update_post(array(
        'ID' => $postId,
        'post_content' => 'Updated public body.',
    ), true);
    publisher_integration_assert(!is_wp_error($updated), 'Published update failed.');
    wp_set_post_terms($postId, array($termId), 'category', false);
    stick_post($postId);

    $drafted = wp_update_post(array('ID' => $postId, 'post_status' => 'draft'), true);
    publisher_integration_assert(!is_wp_error($drafted), 'Unpublish transition failed.');
    wp_update_post(array('ID' => $postId, 'post_status' => 'publish'));

    publisher_integration_assert(wp_trash_post($postId) instanceof WP_Post, 'First trash transition failed.');
    publisher_integration_assert(
        (string) get_post_field('post_name', $postId) === $slug . '__trashed',
        'WordPress did not assign the expected technical trash alias.'
    );
    update_post_meta($postId, '_wp_desired_post_slug', $slug . '__trashed');
    publisher_integration_assert(wp_untrash_post($postId) instanceof WP_Post, 'Restore transition failed.');
    publisher_integration_assert(
        (string) get_post_field('post_name', $postId) === $slug,
        'Restore must recover the last public slug even when the WordPress desired-slug metadata contains a trash alias.'
    );
    wp_update_post(array('ID' => $postId, 'post_status' => 'publish'));
    publisher_integration_assert(get_permalink($postId) === $publicUrl, 'Republishing a restored post must recover the exact public permalink.');
    publisher_integration_assert(wp_trash_post($postId) instanceof WP_Post, 'Second trash transition failed.');

    $deletePostId = wp_insert_post(array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_title' => 'Publisher permanent-delete integration',
        'post_name' => $slug . '-delete',
        'post_content' => 'Delete this public post.',
    ), true);
    publisher_integration_assert(!is_wp_error($deletePostId), 'Permanent-delete fixture publish failed.');
    $deletePostId = (int) $deletePostId;
    $postIds[] = $deletePostId;
    publisher_integration_assert(wp_delete_post($deletePostId, true) instanceof WP_Post, 'Permanent delete failed.');
} finally {
    remove_filter('pre_http_request', $httpGuard, PHP_INT_MIN);
}

publisher_integration_assert($httpAttempts === 0, 'A save hook attempted synchronous network I/O.');

$finalHeadResponse = publisher_integration_request('head', array(
    'consumerId' => $consumerId,
    'scopeFingerprint' => $scopeFingerprint,
    'baselineId' => $baselineId,
    'postTypes' => array('post'),
    'includeSubsites' => false,
), $token);
publisher_integration_assert($finalHeadResponse->get_status() === 200, 'Bound head request failed.');
$finalHead = (int) ($finalHeadResponse->get_data()['headSequence'] ?? -1);
publisher_integration_assert($finalHead > $initialHead, 'Editorial transitions did not advance the journal.');

$events = publisher_integration_events(
    $token,
    $consumerId,
    $scopeFingerprint,
    $baselineId,
    $initialHead,
    $finalHead
);
$operations = array_column($events, 'operation');
foreach (array('publish', 'update', 'taxonomy', 'sticky', 'unpublish', 'delete') as $expectedOperation) {
    publisher_integration_assert(
        in_array($expectedOperation, $operations, true),
        "Missing booted journal operation: {$expectedOperation}."
    );
}

$trashEvents = array_values(array_filter($events, static function (array $event) use ($postId): bool {
    return (int) ($event['postId'] ?? 0) === $postId
        && (string) ($event['operation'] ?? '') === 'unpublish'
        && (string) ($event['after']['status'] ?? '') === 'trash';
}));
publisher_integration_assert(count($trashEvents) >= 2, 'Both trash transitions must be journaled.');
foreach ($trashEvents as $trashEvent) {
    $beforeUrl = (string) ($trashEvent['before']['url'] ?? '');
    publisher_integration_assert($beforeUrl === $publicUrl, 'Trash must retain the exact last public permalink.');
    publisher_integration_assert(!str_contains($beforeUrl, '__trashed'), 'Trash aliases must never enter a public projection.');
}
foreach ($events as $event) {
    foreach (array('before', 'after') as $projectionKey) {
        $projection = $event[$projectionKey] ?? null;
        if (!is_array($projection) || (string) ($projection['status'] ?? '') !== 'publish') {
            continue;
        }
        publisher_integration_assert(
            !str_contains((string) ($projection['url'] ?? ''), '__trashed'),
            sprintf(
                'Event %d (%s) exposed a WordPress trash alias in its public %s URL: %s',
                (int) ($event['sequence'] ?? 0),
                (string) ($event['operation'] ?? ''),
                $projectionKey,
                (string) ($projection['url'] ?? '')
            )
        );
    }
}

$ack = publisher_integration_request('ack', array(
    'consumerId' => $consumerId,
    'scopeFingerprint' => $scopeFingerprint,
    'baselineId' => $baselineId,
    'postTypes' => array('post'),
    'includeSubsites' => false,
    'expectedSequence' => $initialHead,
    'sequence' => $finalHead,
), $token);
publisher_integration_assert($ack->get_status() === 200, 'Cursor CAS acknowledgement failed.');

$staleAck = publisher_integration_request('ack', array(
    'consumerId' => $consumerId,
    'scopeFingerprint' => $scopeFingerprint,
    'baselineId' => $baselineId,
    'postTypes' => array('post'),
    'includeSubsites' => false,
    'expectedSequence' => $initialHead,
    'sequence' => $finalHead,
), $token);
publisher_integration_assert($staleAck->get_status() === 409, 'A stale cursor CAS must be rejected.');

WP_CLI::success(sprintf(
    'Booted WordPress integration passed on blog %d with %d journal events (%d..%d).',
    get_current_blog_id(),
    count($events),
    $initialHead,
    $finalHead
));

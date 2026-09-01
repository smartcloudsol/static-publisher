<?php

if (!defined('ABSPATH') || !defined('WP_CLI')) {
    fwrite(STDERR, "Run this file through WP-CLI.\n");
    exit(2);
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';

function publisher_multisite_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function publisher_multisite_request(string $route, array $payload, string $token): WP_REST_Response
{
    $request = new WP_REST_Request('POST', '/smartcloud-static-publisher/v1/content-sync/' . $route);
    $request->set_header('content-type', 'application/json');
    $request->set_header('x-static-publisher-token', $token);
    $request->set_body(wp_json_encode($payload));
    $response = rest_do_request($request);
    publisher_multisite_assert($response instanceof WP_REST_Response, "{$route} did not return a REST response.");
    return $response;
}

publisher_multisite_assert(is_multisite(), 'This gate requires WordPress multisite.');
publisher_multisite_assert(
    is_plugin_active_for_network('smartcloud-static-publisher/smartcloud-static-publisher.php'),
    'SmartCloud Static Publisher must be network-activated for subsite tracking.'
);
publisher_multisite_assert(
    get_current_blog_id() === (int) get_main_site_id(),
    'Run the multisite gate against the network main site URL.'
);

do_action('rest_api_init');
global $wpdb;

$eventsTable = $wpdb->base_prefix . 'smartcloud_static_publisher_content_events';
$consumersTable = $wpdb->base_prefix . 'smartcloud_static_publisher_content_consumers';
$token = (string) get_option('smartcloud_static_publisher_runtime_nonce', '');
publisher_multisite_assert($token !== '', 'The main-site runtime token is missing.');

$consumerId = 'booted-multisite-' . wp_generate_uuid4();
$scopeFingerprint = hash('sha256', 'booted-multisite-scope-' . $consumerId);
$baselineId = hash('sha256', 'booted-multisite-baseline-' . $consumerId);
$networkHeadBeforeResponse = publisher_multisite_request('head', array(
    'postTypes' => array('post'),
    'includeSubsites' => true,
), $token);
publisher_multisite_assert($networkHeadBeforeResponse->get_status() === 200, 'Network head request failed.');
$networkHeadBefore = (int) ($networkHeadBeforeResponse->get_data()['headSequence'] ?? -1);

$localHeadBeforeResponse = publisher_multisite_request('head', array(
    'postTypes' => array('post'),
    'includeSubsites' => false,
), $token);
$localHeadBefore = (int) ($localHeadBeforeResponse->get_data()['headSequence'] ?? -1);

$baseline = publisher_multisite_request('baseline', array(
    'consumerId' => $consumerId,
    'scopeFingerprint' => $scopeFingerprint,
    'baselineId' => $baselineId,
    'postTypes' => array('post'),
    'includeSubsites' => true,
    'sequence' => $networkHeadBefore,
), $token);
publisher_multisite_assert($baseline->get_status() === 200, 'Network baseline establishment failed.');

$subsiteId = 0;
register_shutdown_function(static function () use (&$subsiteId, $eventsTable, $consumersTable, $consumerId): void {
    global $wpdb;
    if ($subsiteId > 0 && get_site($subsiteId) !== null) {
        wpmu_delete_blog($subsiteId, true);
    }
    if ($subsiteId > 0) {
        $wpdb->delete($eventsTable, array('blog_id' => $subsiteId), array('%d'));
    }
    $wpdb->delete($consumersTable, array('consumer_id' => $consumerId), array('%s'));
});

$network = get_network();
publisher_multisite_assert($network instanceof WP_Network, 'The current network is unavailable.');
$administrators = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
$administratorId = (int) ($administrators[0] ?? 0);
publisher_multisite_assert($administratorId > 0, 'A network administrator is required for the disposable subsite.');
$suffix = strtolower(substr(str_replace('-', '', $consumerId), -10));
$path = trailingslashit((string) $network->path) . 'publisher-integration-' . $suffix . '/';
$createdSubsite = wpmu_create_blog(
    (string) $network->domain,
    $path,
    'Publisher integration ' . $suffix,
    $administratorId,
    array('public' => 1),
    (int) $network->id
);
$subsiteId = is_wp_error($createdSubsite) ? 0 : (int) $createdSubsite;
publisher_multisite_assert($subsiteId > 0, 'Could not create the disposable subsite.');

switch_to_blog($subsiteId);
try {
    update_option('permalink_structure', '/%postname%/');
    update_option('posts_per_page', 1);
    $postId = wp_insert_post(array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_title' => 'Multisite publisher integration',
        'post_name' => 'multisite-publisher-integration',
        'post_content' => 'Disposable subsite content.',
    ), true);
    publisher_multisite_assert(!is_wp_error($postId), 'Subsite publish transition failed.');
    $postId = (int) $postId;
    $subsitePostUrl = (string) get_permalink($postId);
    $secondPostId = wp_insert_post(array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_title' => 'Multisite publisher pagination',
        'post_name' => 'multisite-publisher-pagination',
        'post_content' => 'Second disposable subsite content.',
    ), true);
    publisher_multisite_assert(!is_wp_error($secondPostId), 'Second subsite publish transition failed.');

    add_filter('wp_sitemaps_max_urls', static function (int $maxUrls, string $objectType): int {
        return $objectType === 'post' ? 1 : $maxUrls;
    }, 10, 2);
    $postsSitemapProvider = wp_sitemaps_get_server()->registry->get_provider('posts');
    publisher_multisite_assert(
        $postsSitemapProvider instanceof WP_Sitemaps_Provider
            && $postsSitemapProvider->get_max_num_pages('post') >= 2,
        'The disposable subsite did not expose a paginated post sitemap chain.'
    );
} finally {
    restore_current_blog();
}

$networkHeadAfterResponse = publisher_multisite_request('head', array(
    'consumerId' => $consumerId,
    'scopeFingerprint' => $scopeFingerprint,
    'baselineId' => $baselineId,
    'postTypes' => array('post'),
    'includeSubsites' => true,
), $token);
publisher_multisite_assert($networkHeadAfterResponse->get_status() === 200, 'Bound network head request failed.');
$networkHeadAfter = (int) ($networkHeadAfterResponse->get_data()['headSequence'] ?? -1);
publisher_multisite_assert($networkHeadAfter > $networkHeadBefore, 'A subsite publish did not advance the network journal head.');

$localHeadAfterResponse = publisher_multisite_request('head', array(
    'postTypes' => array('post'),
    'includeSubsites' => false,
), $token);
$localHeadAfter = (int) ($localHeadAfterResponse->get_data()['headSequence'] ?? -1);
publisher_multisite_assert($localHeadAfter === $localHeadBefore, 'A subsite event leaked into the site-local journal scope.');

$eventsResponse = publisher_multisite_request('events', array(
    'consumerId' => $consumerId,
    'scopeFingerprint' => $scopeFingerprint,
    'baselineId' => $baselineId,
    'postTypes' => array('post'),
    'includeSubsites' => true,
    'afterSequence' => $networkHeadBefore,
    'throughSequence' => $networkHeadAfter,
    'limit' => 250,
), $token);
publisher_multisite_assert($eventsResponse->get_status() === 200, 'Network event request failed.');
$events = (array) ($eventsResponse->get_data()['items'] ?? array());
$subsiteEvents = array_values(array_filter($events, static function (array $event) use ($subsiteId): bool {
    return (int) ($event['blogId'] ?? 0) === $subsiteId;
}));
publisher_multisite_assert(count($subsiteEvents) >= 1, 'The network journal omitted the subsite identity.');
publisher_multisite_assert(
    (string) ($subsiteEvents[0]['after']['url'] ?? '') === $subsitePostUrl,
    'The subsite public projection URL is incorrect.'
);

$families = (array) ($subsiteEvents[0]['after']['archiveFamilies'] ?? array());
$postsPageFamily = null;
foreach ($families as $family) {
    if (($family['kind'] ?? '') === 'posts-page') {
        $postsPageFamily = $family;
        break;
    }
}
publisher_multisite_assert(is_array($postsPageFamily), 'The subsite posts-page archive family is missing.');
publisher_multisite_assert((int) ($postsPageFamily['blogId'] ?? 0) === $subsiteId, 'Archive family lost its subsite identity.');
$impactResponse = publisher_multisite_request('impact', array(
    'families' => array($postsPageFamily),
    'includeSubsites' => true,
), $token);
publisher_multisite_assert($impactResponse->get_status() === 200, 'Subsite archive resolution failed.');
$resolved = (array) ($impactResponse->get_data()['families'][0] ?? array());
publisher_multisite_assert(
    in_array((string) $postsPageFamily['url'], (array) ($resolved['pageUrls'] ?? array()), true),
    'Subsite archive resolution returned the wrong site pages.'
);
publisher_multisite_assert((int) ($resolved['maxPages'] ?? 0) >= 2, 'Subsite archive pagination was not resolved.');
publisher_multisite_assert(
    in_array(trailingslashit((string) $postsPageFamily['url']) . 'page/2/', (array) ($resolved['pageUrls'] ?? array()), true),
    'The second subsite archive page URL is missing.'
);

$fingerprint = publisher_multisite_request('fingerprint', array('includeSubsites' => true), $token);
publisher_multisite_assert($fingerprint->get_status() === 200, 'Network release fingerprint failed.');
publisher_multisite_assert(
    preg_match('/^[a-f0-9]{64}$/', (string) ($fingerprint->get_data()['fingerprint'] ?? '')) === 1,
    'Network release fingerprint is invalid.'
);

WP_CLI::success(sprintf(
    'Booted multisite integration passed for disposable blog %d (%d..%d).',
    $subsiteId,
    $networkHeadBefore,
    $networkHeadAfter
));

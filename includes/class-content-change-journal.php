<?php

namespace SmartCloud\WPSuite\StaticPublisher;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Durable public-content change journal used by targeted publisher consumers.
 *
 * Hooks only persist desired state. Export and deployment remain owned by the
 * Node runner, so an editorial request never waits for an external service.
 */
final class ContentChangeJournal
{
    private const SCHEMA_VERSION = '3';
    private const SCHEMA_OPTION = 'smartcloud_static_publisher_content_journal_schema';
    private const CONSUMERS_OPTION = 'smartcloud_static_publisher_content_sync_consumers';
    private const RETENTION_FLOOR_OPTION = 'smartcloud_static_publisher_content_journal_retention_floor';
    private const LAST_PUBLIC_PROJECTION_META = '_smartcloud_static_publisher_last_public_projection';
    private const LAST_PUBLIC_SLUG_META = '_smartcloud_static_publisher_last_public_slug';
    private const REST_NAMESPACE = 'smartcloud-static-publisher/v1';

    private Plugin $plugin;

    /** @var array<int, array<string, mixed>> */
    private array $preTrashProjections = array();

    /** @var array<int, string> */
    private array $preRestoreSlugs = array();

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    public function registerHooks(): void
    {
        add_action('wp_trash_post', array($this, 'capturePreTrashProjection'), 10, 2);
        add_action('untrash_post', array($this, 'prepareRestoreSlug'), 1, 2);
        add_action('untrashed_post', array($this, 'repairRestoredSlug'), 20, 2);
        add_action('wp_after_insert_post', array($this, 'capturePostWrite'), 10, 4);
        add_action('before_delete_post', array($this, 'capturePostDelete'), 10, 2);
        add_action('set_object_terms', array($this, 'captureTermChange'), 10, 6);
        add_action('update_option_sticky_posts', array($this, 'captureStickyChange'), 10, 3);
        add_action('add_option_sticky_posts', array($this, 'captureStickyAdd'), 10, 2);
        add_action('delete_option_sticky_posts', array($this, 'captureStickyDelete'), 10, 1);
    }

    public function registerRestRoutes(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/content-sync/head', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'canAccessRuntime'),
            'callback' => array($this, 'handleHead'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/content-sync/events', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'canAccessRuntime'),
            'callback' => array($this, 'handleEvents'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/content-sync/ack', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'canAccessRuntime'),
            'callback' => array($this, 'handleAcknowledge'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/content-sync/baseline', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'canAccessRuntime'),
            'callback' => array($this, 'handleEstablishBaseline'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/content-sync/impact', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'canAccessRuntime'),
            'callback' => array($this, 'handleImpactMetadata'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/content-sync/fingerprint', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'canAccessRuntime'),
            'callback' => array($this, 'handleReleaseFingerprint'),
        ));
    }

    public function canAccessRuntime(\WP_REST_Request $request): bool
    {
        return $this->plugin->canReadChangeTokens($request);
    }

    public function installSchema(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $this->tableName();
        $consumersTable = $this->consumersTableName();
        $charsetCollate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            sequence bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
            recorded_gmt datetime NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            post_type varchar(64) NOT NULL,
            operation varchar(32) NOT NULL,
            before_projection longtext NULL,
            after_projection longtext NULL,
            correlation_id varchar(64) NULL,
            PRIMARY KEY  (sequence),
            KEY blog_type_sequence (blog_id, post_type, sequence),
            KEY post_type_sequence (post_type, sequence),
            KEY post_id_sequence (blog_id, post_id, sequence)
        ) {$charsetCollate};";

        dbDelta($sql);
        $consumersSql = "CREATE TABLE {$consumersTable} (
            consumer_id varchar(191) NOT NULL,
            root_blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
            include_subsites tinyint(1) unsigned NOT NULL DEFAULT 0,
            scope_fingerprint varchar(128) NOT NULL,
            baseline_id varchar(64) NOT NULL,
            sequence bigint(20) unsigned NOT NULL DEFAULT 0,
            post_types longtext NOT NULL,
            acknowledged_gmt datetime NOT NULL,
            PRIMARY KEY  (consumer_id),
            KEY scope_sequence (scope_fingerprint(64), sequence)
        ) {$charsetCollate};";
        dbDelta($consumersSql);
        $mainBlogId = is_multisite() ? (int) get_main_site_id() : (int) get_current_blog_id();
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET blog_id = %d WHERE blog_id = 0", $mainBlogId));
        $wpdb->query($wpdb->prepare("UPDATE {$consumersTable} SET root_blog_id = %d WHERE root_blog_id = 0", $mainBlogId));
        update_site_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION);
    }

    public function maybeInstallSchema(): void
    {
        if ((string) get_site_option(self::SCHEMA_OPTION, '') !== self::SCHEMA_VERSION) {
            $this->installSchema();
        }
    }

    public function capturePostWrite(int $postId, \WP_Post $post, bool $update, ?\WP_Post $postBefore): void
    {
        if (!$this->isJournalPostType($post->post_type) || wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        $before = $postBefore instanceof \WP_Post ? $this->buildProjection($postBefore) : null;
        if ($post->post_status === 'trash' && isset($this->preTrashProjections[$postId])) {
            $before = $this->preTrashProjections[$postId];
            unset($this->preTrashProjections[$postId]);
        }
        if (
            ($post->post_status === 'trash' && !$this->projectionIsPublic($before))
            || ($this->projectionIsPublic($before) && $this->projectionUsesTrashedSlug($before))
        ) {
            $persistedBefore = $this->storedLastPublicProjection($postId)
                ?? $this->latestPublicProjection($postId)
                ?? $this->projectionFromWordPressDesiredSlug($post);
            if ($persistedBefore !== null) {
                $before = $persistedBefore;
            }
        }
        $after = $this->buildProjection($post);
        if ($this->projectionIsPublic($after) && $this->projectionUsesTrashedSlug($after)) {
            $after = $this->storedLastPublicProjection($postId)
                ?? $this->latestPublicProjection($postId)
                ?? $after;
        }
        if (!$this->projectionIsPublic($before) && !$this->projectionIsPublic($after)) {
            return;
        }

        $operation = $this->classifyOperation($before, $after, $update ? 'update' : 'publish');
        $this->appendEvent($postId, $post->post_type, $operation, $before, $after);
        $this->rememberLastPublicProjection($postId, $after);
    }

    public function capturePreTrashProjection(int $postId, string $previousStatus = ''): void
    {
        unset($previousStatus);
        $post = get_post($postId);
        if (!($post instanceof \WP_Post) || !$this->isJournalPostType($post->post_type)) {
            return;
        }

        $projection = $this->buildProjection($post);
        if ($this->projectionIsPublic($projection) && !$this->projectionUsesTrashedSlug($projection)) {
            $this->preTrashProjections[$postId] = $projection;
            $this->rememberLastPublicProjection($postId, $projection);
            return;
        }

        $persisted = $this->storedLastPublicProjection($postId)
            ?? $this->latestPublicProjection($postId)
            ?? $this->projectionFromWordPressDesiredSlug($post);
        if ($persisted !== null) {
            $this->preTrashProjections[$postId] = $persisted;
        }
    }

    public function prepareRestoreSlug(int $postId, string $previousStatus = ''): void
    {
        unset($previousStatus);
        $post = get_post($postId);
        if (!($post instanceof \WP_Post) || !$this->isJournalPostType($post->post_type)) {
            return;
        }

        $desiredSlug = get_post_meta($postId, '_wp_desired_post_slug', true);
        $desiredSlug = is_string($desiredSlug) ? sanitize_title($desiredSlug) : '';
        if ($desiredSlug === '' || str_ends_with($desiredSlug, '__trashed')) {
            $desiredSlug = sanitize_title((string) get_post_meta($postId, self::LAST_PUBLIC_SLUG_META, true));
        }
        if ($desiredSlug === '' || str_ends_with($desiredSlug, '__trashed')) {
            $desiredSlug = $this->slugFromLastPublicProjection($postId);
        }
        if ($desiredSlug === '' || str_ends_with($desiredSlug, '__trashed')) {
            return;
        }

        $this->preRestoreSlugs[$postId] = $desiredSlug;
        update_post_meta($postId, '_wp_desired_post_slug', $desiredSlug);
    }

    public function repairRestoredSlug(int $postId, string $previousStatus = ''): void
    {
        unset($previousStatus);
        $desiredSlug = $this->preRestoreSlugs[$postId] ?? '';
        unset($this->preRestoreSlugs[$postId]);
        if ($desiredSlug === '') {
            return;
        }

        $post = get_post($postId);
        if (
            !($post instanceof \WP_Post)
            || !$this->isJournalPostType($post->post_type)
            || !str_ends_with((string) $post->post_name, '__trashed')
        ) {
            return;
        }

        wp_update_post(array(
            'ID' => $postId,
            'post_name' => $desiredSlug,
        ));
    }

    public function capturePostDelete(int $postId, ?\WP_Post $post): void
    {
        if (!($post instanceof \WP_Post) || !$this->isJournalPostType($post->post_type)) {
            return;
        }

        $before = $this->buildProjection($post);
        if (!$this->projectionIsPublic($before)) {
            return;
        }

        $this->appendEvent($postId, $post->post_type, 'delete', $before, null);
    }

    /**
     * @param array<int|string> $terms
     * @param array<int>        $ttIds
     * @param array<int>        $oldTtIds
     */
    public function captureTermChange(int $objectId, $terms, array $ttIds, string $taxonomy, bool $append, array $oldTtIds): void
    {
        unset($terms, $append);
        $post = get_post($objectId);
        if (!($post instanceof \WP_Post) || $post->post_status !== 'publish' || !$this->isJournalPostType($post->post_type)) {
            return;
        }

        $taxonomyObject = get_taxonomy($taxonomy);
        if (!($taxonomyObject instanceof \WP_Taxonomy) || !$taxonomyObject->public) {
            return;
        }

        $after = $this->buildProjection($post);
        $before = $after;
        $before['terms'] = $this->replaceProjectionTaxonomyTerms(
            (array) ($before['terms'] ?? array()),
            $taxonomy,
            $this->termsFromTermTaxonomyIds($oldTtIds, $taxonomy)
        );
        $before['archiveFamilies'] = $this->archiveFamilyProjection($post, $before['terms']);
        $before['archives'] = array_values(array_unique(array_column($before['archiveFamilies'], 'url')));

        if ($ttIds === $oldTtIds) {
            return;
        }

        $this->appendEvent($objectId, $post->post_type, 'taxonomy', $before, $after);
    }

    /** @param array<int> $oldValue @param array<int> $newValue */
    public function captureStickyChange($oldValue, $newValue, string $option): void
    {
        unset($option);
        $oldIds = array_values(array_unique(array_map('absint', is_array($oldValue) ? $oldValue : array())));
        $newIds = array_values(array_unique(array_map('absint', is_array($newValue) ? $newValue : array())));
        $changedIds = array_values(array_unique(array_merge(array_diff($oldIds, $newIds), array_diff($newIds, $oldIds))));

        foreach ($changedIds as $postId) {
            $post = get_post($postId);
            if (!($post instanceof \WP_Post) || $post->post_status !== 'publish' || !$this->isJournalPostType($post->post_type)) {
                continue;
            }

            $after = $this->buildProjection($post);
            $before = $after;
            $before['sticky'] = in_array($postId, $oldIds, true);
            $after['sticky'] = in_array($postId, $newIds, true);
            $this->appendEvent($postId, $post->post_type, 'sticky', $before, $after);
        }
    }

    public function captureStickyAdd(string $option, $value): void
    {
        $this->captureStickyChange(array(), $value, $option);
    }

    public function captureStickyDelete(string $option): void
    {
        $this->captureStickyChange(get_option($option, array()), array(), $option);
    }

    public function handleHead(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $this->requestData($request);
        $postTypes = $this->sanitizePostTypes($data['postTypes'] ?? array(), !empty($data['includeSubsites']));
        if (empty($postTypes)) {
            return $this->errorResponse('invalid-post-types', __('Select at least one public, publicly queryable post type.', 'smartcloud-static-publisher'), 400);
        }
        $includeSubsites = !empty($data['includeSubsites']);
        $networkScopeError = $this->networkScopeError($includeSubsites);
        if ($networkScopeError instanceof \WP_REST_Response) {
            return $networkScopeError;
        }

        $consumerId = $this->sanitizeConsumerId($data['consumerId'] ?? '');
        $scopeFingerprint = $this->sanitizeFingerprint($data['scopeFingerprint'] ?? '');
        $baselineId = $this->sanitizeBaselineId($data['baselineId'] ?? '');
        $consumer = $consumerId !== '' ? $this->readConsumer($consumerId) : null;
        if ($consumerId !== '' && ($scopeFingerprint === '' || $baselineId === '')) {
            return $this->errorResponse('invalid-consumer', __('A scope fingerprint and baseline ID are required when reading a content-sync consumer.', 'smartcloud-static-publisher'), 400);
        }
        if (is_array($consumer) && !$this->consumerMatches($consumer, $scopeFingerprint, $baselineId, $postTypes, $includeSubsites)) {
            return $this->errorResponse('baseline-required', __('The content-sync consumer scope or verified release baseline changed.', 'smartcloud-static-publisher'), 409);
        }

        $retentionFloor = max(0, (int) get_site_option(self::RETENTION_FLOOR_OPTION, 0));
        $committedSequence = is_array($consumer) ? max(0, (int) ($consumer['sequence'] ?? 0)) : 0;
        if ($committedSequence < $retentionFloor) {
            return $this->errorResponse('baseline-required', __('The content-sync consumer cursor is older than retained journal history.', 'smartcloud-static-publisher'), 409);
        }

        return new \WP_REST_Response(array(
            'headSequence' => $this->headSequence($postTypes, $includeSubsites),
            'committedSequence' => $committedSequence,
            'retentionFloor' => $retentionFloor,
            'baselineStatus' => is_array($consumer) ? 'ready' : 'missing',
            'consumer' => $consumer,
            'postTypes' => $postTypes,
            'includeSubsites' => $includeSubsites,
        ), 200);
    }

    public function handleEvents(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $data = $this->requestData($request);
        $postTypes = $this->sanitizePostTypes($data['postTypes'] ?? array(), !empty($data['includeSubsites']));
        if (empty($postTypes)) {
            return $this->errorResponse('invalid-post-types', __('Select at least one public, publicly queryable post type.', 'smartcloud-static-publisher'), 400);
        }
        $includeSubsites = !empty($data['includeSubsites']);
        $networkScopeError = $this->networkScopeError($includeSubsites);
        if ($networkScopeError instanceof \WP_REST_Response) {
            return $networkScopeError;
        }

        $consumerId = $this->sanitizeConsumerId($data['consumerId'] ?? '');
        $scopeFingerprint = $this->sanitizeFingerprint($data['scopeFingerprint'] ?? '');
        $baselineId = $this->sanitizeBaselineId($data['baselineId'] ?? '');
        $consumer = $consumerId !== '' ? $this->readConsumer($consumerId) : null;
        if (!is_array($consumer) || !$this->consumerMatches($consumer, $scopeFingerprint, $baselineId, $postTypes, $includeSubsites)) {
            return $this->errorResponse('baseline-required', __('A matching verified release baseline is required before reading content-sync events.', 'smartcloud-static-publisher'), 409);
        }

        $afterSequence = max(0, (int) ($data['afterSequence'] ?? 0));
        $throughSequence = max($afterSequence, (int) ($data['throughSequence'] ?? $this->headSequence($postTypes, $includeSubsites)));
        $retentionFloor = max(0, (int) get_site_option(self::RETENTION_FLOOR_OPTION, 0));
        if ($afterSequence < $retentionFloor) {
            return $this->errorResponse('baseline-required', __('The requested content journal cursor is older than retained history.', 'smartcloud-static-publisher'), 409);
        }
        $limit = min(250, max(1, (int) ($data['limit'] ?? 100)));
        $placeholders = implode(', ', array_fill(0, count($postTypes), '%s'));
        $where = "post_type IN ({$placeholders})";
        $params = $postTypes;
        if (!$includeSubsites) {
            $where .= ' AND blog_id = %d';
            $params[] = get_current_blog_id();
        }
        $params = array_merge($params, array($afterSequence, $throughSequence, $limit));
        $sql = $wpdb->prepare(
            "SELECT sequence, blog_id, recorded_gmt, post_id, post_type, operation, before_projection, after_projection, correlation_id
             FROM {$this->tableName()}
             WHERE {$where} AND sequence > %d AND sequence <= %d
             ORDER BY sequence ASC LIMIT %d",
            $params
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $items = array_map(array($this, 'hydrateEventRow'), is_array($rows) ? $rows : array());
        $nextSequence = !empty($items) ? (int) end($items)['sequence'] : $afterSequence;

        return new \WP_REST_Response(array(
            'fromSequence' => $afterSequence,
            'throughSequence' => $throughSequence,
            'nextSequence' => $nextSequence,
            'hasMore' => count($items) === $limit && $nextSequence < $throughSequence,
            'items' => $items,
        ), 200);
    }

    public function handleAcknowledge(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $data = $this->requestData($request);
        $consumerId = $this->sanitizeConsumerId($data['consumerId'] ?? '');
        $scopeFingerprint = $this->sanitizeFingerprint($data['scopeFingerprint'] ?? '');
        $baselineId = $this->sanitizeBaselineId($data['baselineId'] ?? '');
        $hasSequence = array_key_exists('sequence', $data) && is_numeric($data['sequence']);
        $hasExpected = array_key_exists('expectedSequence', $data) && is_numeric($data['expectedSequence']);
        $sequence = $hasSequence ? max(0, (int) $data['sequence']) : -1;
        $expectedSequence = $hasExpected ? max(0, (int) $data['expectedSequence']) : -1;
        $postTypes = $this->sanitizePostTypes($data['postTypes'] ?? array(), !empty($data['includeSubsites']));
        $includeSubsites = !empty($data['includeSubsites']);
        if ($consumerId === '' || $scopeFingerprint === '' || $baselineId === '' || !$hasSequence || !$hasExpected || empty($postTypes)) {
            return $this->errorResponse('invalid-consumer', __('A valid consumer ID, scope fingerprint, baseline ID, expected cursor, sequence, and public post-type scope are required.', 'smartcloud-static-publisher'), 400);
        }
        $networkScopeError = $this->networkScopeError($includeSubsites);
        if ($networkScopeError instanceof \WP_REST_Response) {
            return $networkScopeError;
        }
        $headSequence = $this->headSequence($postTypes, $includeSubsites);
        if ($sequence > $headSequence) {
            return $this->errorResponse('cursor-ahead-of-head', __('The content-sync cursor cannot advance beyond the current journal head.', 'smartcloud-static-publisher'), 409);
        }

        $existing = $this->readConsumer($consumerId);
        if (!is_array($existing) || !$this->consumerMatches($existing, $scopeFingerprint, $baselineId, $postTypes, $includeSubsites)) {
            return $this->errorResponse('baseline-required', __('The content-sync scope changed and requires a new verified baseline.', 'smartcloud-static-publisher'), 409);
        }

        $committed = max(0, (int) ($existing['sequence'] ?? 0));
        if ($expectedSequence !== $committed || $sequence < $expectedSequence) {
            return $this->errorResponse('cursor-conflict', __('The content-sync cursor changed or would move backward.', 'smartcloud-static-publisher'), 409);
        }

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->consumersTableName()}
             SET sequence = %d, acknowledged_gmt = %s
             WHERE consumer_id = %s AND scope_fingerprint = %s AND baseline_id = %s AND sequence = %d",
            $sequence,
            current_time('mysql', true),
            $consumerId,
            $scopeFingerprint,
            $baselineId,
            $expectedSequence
        ));
        if ($updated !== 1 && $sequence !== $expectedSequence) {
            return $this->errorResponse('cursor-conflict', __('The content-sync cursor was updated concurrently.', 'smartcloud-static-publisher'), 409);
        }

        return new \WP_REST_Response(array(
            'success' => true,
            'consumerId' => $consumerId,
            'committedSequence' => $sequence,
            'headSequence' => $headSequence,
        ), 200);
    }

    public function handleEstablishBaseline(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $data = $this->requestData($request);
        $consumerId = $this->sanitizeConsumerId($data['consumerId'] ?? '');
        $scopeFingerprint = $this->sanitizeFingerprint($data['scopeFingerprint'] ?? '');
        $baselineId = $this->sanitizeBaselineId($data['baselineId'] ?? '');
        $postTypes = $this->sanitizePostTypes($data['postTypes'] ?? array(), !empty($data['includeSubsites']));
        $includeSubsites = !empty($data['includeSubsites']);
        if ($consumerId === '' || $scopeFingerprint === '' || $baselineId === '' || empty($postTypes)) {
            return $this->errorResponse('invalid-baseline', __('A valid consumer, scope, baseline ID, and post-type scope are required.', 'smartcloud-static-publisher'), 400);
        }
        $networkScopeError = $this->networkScopeError($includeSubsites);
        if ($networkScopeError instanceof \WP_REST_Response) {
            return $networkScopeError;
        }

        $headSequence = $this->headSequence($postTypes, $includeSubsites);
        $hasSequence = array_key_exists('sequence', $data) && is_numeric($data['sequence']);
        $sequence = $hasSequence ? max(0, (int) $data['sequence']) : -1;
        $retentionFloor = max(0, (int) get_site_option(self::RETENTION_FLOOR_OPTION, 0));
        if (!$hasSequence || $sequence > $headSequence || $sequence < $retentionFloor) {
            return $this->errorResponse('invalid-baseline-sequence', __('The verified release cutoff must be within retained journal history and cannot exceed the current head.', 'smartcloud-static-publisher'), 409);
        }
        $postTypesJson = wp_json_encode($postTypes);
        $result = $wpdb->replace(
            $this->consumersTableName(),
            array(
                'consumer_id' => $consumerId,
                'root_blog_id' => get_current_blog_id(),
                'include_subsites' => $includeSubsites ? 1 : 0,
                'scope_fingerprint' => $scopeFingerprint,
                'baseline_id' => $baselineId,
                'sequence' => $sequence,
                'post_types' => is_string($postTypesJson) ? $postTypesJson : '[]',
                'acknowledged_gmt' => current_time('mysql', true),
            ),
            array('%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s')
        );
        if ($result === false) {
            return $this->errorResponse('baseline-write-failed', __('The verified content-sync baseline could not be stored.', 'smartcloud-static-publisher'), 500);
        }

        return new \WP_REST_Response(array(
            'success' => true,
            'consumerId' => $consumerId,
            'baselineId' => $baselineId,
            'committedSequence' => $sequence,
            'headSequence' => $headSequence,
        ), 200);
    }

    public function handleImpactMetadata(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $this->requestData($request);
        $includeSubsites = !empty($data['includeSubsites']);
        $networkScopeError = $this->networkScopeError($includeSubsites);
        if ($networkScopeError instanceof \WP_REST_Response) {
            return $networkScopeError;
        }
        $rawFamilies = isset($data['families']) && is_array($data['families']) ? $data['families'] : array();
        if (count($rawFamilies) > 500) {
            return $this->errorResponse('impact-too-large', __('Too many archive families were requested in one impact page.', 'smartcloud-static-publisher'), 400);
        }

        $families = array();
        foreach ($rawFamilies as $rawFamily) {
            if (!is_array($rawFamily)) {
                return $this->errorResponse('invalid-impact-family', __('Every archive family must be an object.', 'smartcloud-static-publisher'), 400);
            }
            $family = $this->sanitizeArchiveFamily($rawFamily);
            if ($family === null) {
                return $this->errorResponse('invalid-impact-family', __('An archive family is invalid or does not belong to this WordPress site.', 'smartcloud-static-publisher'), 400);
            }
            if (!$includeSubsites && (int) ($family['blogId'] ?? 0) !== get_current_blog_id()) {
                return $this->errorResponse('subsite-outside-scope', __('A subsite archive cannot be resolved by a site-local content-sync rule.', 'smartcloud-static-publisher'), 409);
            }
            $families[] = $this->resolveArchiveFamilyPages($family);
        }

        return new \WP_REST_Response(array(
            'families' => $families,
            'resolvedAt' => gmdate('c'),
        ), 200);
    }

    public function handleReleaseFingerprint(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $this->requestData($request);
        $includeSubsites = !empty($data['includeSubsites']);
        $networkScopeError = $this->networkScopeError($includeSubsites);
        if ($networkScopeError instanceof \WP_REST_Response) {
            return $networkScopeError;
        }
        $theme = wp_get_theme();
        $activePlugins = array_values(array_map('strval', (array) get_option('active_plugins', array())));
        sort($activePlugins, SORT_STRING);
        $networkPlugins = is_multisite() ? array_keys((array) get_site_option('active_sitewide_plugins', array())) : array();
        sort($networkPlugins, SORT_STRING);
        $renderPluginFiles = array_values(array_unique(array_merge($activePlugins, $networkPlugins)));
        $material = array(
            'schemaVersion' => 1,
            'homeUrl' => home_url('/'),
            'siteUrl' => site_url('/'),
            'wordpressVersion' => (string) get_bloginfo('version'),
            'pluginVersion' => defined('SMARTCLOUD_STATIC_PUBLISHER_VERSION') ? (string) SMARTCLOUD_STATIC_PUBLISHER_VERSION : '',
            'theme' => array(
                'stylesheet' => (string) get_stylesheet(),
                'template' => (string) get_template(),
                'version' => (string) $theme->get('Version'),
                'codeFingerprint' => $this->directoryFingerprint((string) get_stylesheet_directory(), $this->renderDependencyFingerprintExtensions()),
                'templateCodeFingerprint' => $this->directoryFingerprint((string) get_template_directory(), $this->renderDependencyFingerprintExtensions()),
            ),
            'pluginCodeFingerprint' => $this->fileSetFingerprint(array(
                dirname(__DIR__) . '/smartcloud-static-publisher.php',
                __FILE__,
                dirname(__DIR__) . '/admin/php/admin.php',
                dirname(__DIR__) . '/admin/dist/index.js',
            )),
            'activePlugins' => $activePlugins,
            'networkPlugins' => $networkPlugins,
            'activePluginCodeFingerprint' => $this->pluginSetCodeFingerprint($renderPluginFiles),
            'mustUsePluginCodeFingerprint' => defined('WPMU_PLUGIN_DIR')
                ? $this->directoryFingerprint((string) WPMU_PLUGIN_DIR, $this->renderDependencyFingerprintExtensions())
                : '',
            'permalinkStructure' => (string) get_option('permalink_structure', ''),
            'paginationBase' => $this->paginationBase(),
            'showOnFront' => (string) get_option('show_on_front', ''),
            'pageOnFront' => absint(get_option('page_on_front')),
            'pageForPosts' => absint(get_option('page_for_posts')),
            'includeSubsites' => $includeSubsites,
        );
        if ($includeSubsites && is_multisite()) {
            $networkSites = array();
            foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $blogId) {
                switch_to_blog((int) $blogId);
                try {
                    $siteTheme = wp_get_theme();
                    $sitePlugins = array_values(array_map('strval', (array) get_option('active_plugins', array())));
                    sort($sitePlugins, SORT_STRING);
                    $networkSites[] = array(
                        'blogId' => (int) $blogId,
                        'homeUrl' => home_url('/'),
                        'siteUrl' => site_url('/'),
                        'theme' => array(
                            'stylesheet' => (string) get_stylesheet(),
                            'template' => (string) get_template(),
                            'version' => (string) $siteTheme->get('Version'),
                            'codeFingerprint' => $this->directoryFingerprint((string) get_stylesheet_directory(), $this->renderDependencyFingerprintExtensions()),
                        ),
                        'activePlugins' => $sitePlugins,
                        'permalinkStructure' => (string) get_option('permalink_structure', ''),
                        'showOnFront' => (string) get_option('show_on_front', ''),
                        'pageOnFront' => absint(get_option('page_on_front')),
                        'pageForPosts' => absint(get_option('page_for_posts')),
                    );
                } finally {
                    restore_current_blog();
                }
            }
            $material['networkSites'] = $networkSites;
        }
        $material = apply_filters('smartcloud_static_publisher_content_release_fingerprint', $material);
        $encoded = wp_json_encode($material);
        return new \WP_REST_Response(array(
            'schemaVersion' => 1,
            'fingerprint' => hash('sha256', is_string($encoded) ? $encoded : ''),
            'generatedAt' => gmdate('c'),
        ), 200);
    }

    private function fileSetFingerprint(array $files): string
    {
        $files = array_values(array_unique(array_filter(array_map('strval', $files), 'is_file')));
        sort($files, SORT_STRING);
        $context = hash_init('sha256');
        foreach ($files as $file) {
            hash_update($context, wp_normalize_path($file) . "\n");
            hash_update_file($context, $file);
        }
        return hash_final($context);
    }

    private function directoryFingerprint(string $root, array $extensions): string
    {
        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot)) {
            return '';
        }
        $files = array();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($iterator as $entry) {
            if (!($entry instanceof \SplFileInfo) || !$entry->isFile() || $entry->isLink()) {
                continue;
            }
            if (!in_array(strtolower((string) $entry->getExtension()), $extensions, true)) {
                continue;
            }
            $files[] = $entry->getPathname();
        }
        return $this->fileSetFingerprint($files);
    }

    private function renderDependencyFingerprintExtensions(): array
    {
        return array(
            'php',
            'html',
            'htm',
            'json',
            'webmanifest',
            'xml',
            'css',
            'js',
            'mjs',
            'cjs',
            'map',
            'svg',
            'png',
            'jpg',
            'jpeg',
            'gif',
            'webp',
            'avif',
            'ico',
            'woff',
            'woff2',
            'ttf',
            'otf',
            'eot',
            'wasm',
        );
    }

    private function pluginSetCodeFingerprint(array $pluginFiles): string
    {
        if (!defined('WP_PLUGIN_DIR')) {
            return '';
        }
        $root = realpath((string) WP_PLUGIN_DIR);
        if ($root === false) {
            return '';
        }
        $items = array();
        foreach (array_values(array_unique(array_map('strval', $pluginFiles))) as $relative) {
            $candidate = realpath($root . '/' . ltrim($relative, '/'));
            if ($candidate === false || !str_starts_with(wp_normalize_path($candidate), trailingslashit(wp_normalize_path($root)))) {
                continue;
            }
            $directory = is_dir($candidate) ? $candidate : dirname($candidate);
            $items[$relative] = is_file($candidate) && realpath($directory) === $root
                ? $this->fileSetFingerprint(array($candidate))
                : $this->directoryFingerprint($directory, $this->renderDependencyFingerprintExtensions());
        }
        ksort($items, SORT_STRING);
        $encoded = wp_json_encode($items);
        return hash('sha256', is_string($encoded) ? $encoded : '');
    }

    private function sanitizeArchiveFamily(array $family): ?array
    {
        $allowedKinds = array('post-type', 'taxonomy', 'author', 'date', 'posts-page', 'listing');
        $kind = sanitize_key((string) ($family['kind'] ?? ''));
        $url = esc_url_raw((string) ($family['url'] ?? ''), array('http', 'https'));
        if (!in_array($kind, $allowedKinds, true) || $url === '' || !$this->isSameSiteUrl($url)) {
            return null;
        }

        $normalized = array('kind' => $kind, 'url' => $url);
        foreach (array('postType', 'taxonomy') as $key) {
            if (isset($family[$key])) {
                $normalized[$key] = sanitize_key((string) $family[$key]);
            }
        }
        foreach (array('termId', 'authorId', 'year', 'month', 'day') as $key) {
            if (isset($family[$key])) {
                $normalized[$key] = absint($family[$key]);
            }
        }
        $blogId = absint($family['blogId'] ?? get_current_blog_id());
        if ($blogId < 1 || (is_multisite() && get_site($blogId) === null)) {
            return null;
        }
        $normalized['blogId'] = $blogId;
        return $normalized;
    }

    private function resolveArchiveFamilyPages(array $family): array
    {
        $blogId = absint($family['blogId'] ?? get_current_blog_id());
        if (is_multisite() && $blogId !== get_current_blog_id()) {
            if (get_site($blogId) === null) {
                return array_merge($family, array('pageUrls' => array(), 'maxPages' => 0, 'source' => 'missing-site'));
            }
            switch_to_blog($blogId);
            try {
                return $this->resolveArchiveFamilyPagesForCurrentBlog($family);
            } finally {
                restore_current_blog();
            }
        }
        return $this->resolveArchiveFamilyPagesForCurrentBlog($family);
    }

    private function resolveArchiveFamilyPagesForCurrentBlog(array $family): array
    {
        $provided = apply_filters('smartcloud_static_publisher_content_archive_pages', null, $family);
        if (is_array($provided)) {
            $pageUrls = array_values(array_unique(array_filter(array_map(function ($url): string {
                $normalized = esc_url_raw((string) $url, array('http', 'https'));
                return $normalized !== '' && $this->isSameSiteUrl($normalized) ? $normalized : '';
            }, $provided))));
            return array_merge($family, array(
                'pageUrls' => $pageUrls,
                'maxPages' => count($pageUrls),
                'source' => 'provider',
            ));
        }

        $kind = (string) ($family['kind'] ?? '');
        if ($kind === 'listing') {
            return array_merge($family, array(
                'pageUrls' => array((string) $family['url']),
                'maxPages' => 1,
                'source' => 'explicit-listing',
            ));
        }

        $postType = sanitize_key((string) ($family['postType'] ?? ($kind === 'posts-page' ? 'post' : '')));
        if ($postType === '' || !$this->isJournalPostType($postType)) {
            return array_merge($family, array('pageUrls' => array((string) $family['url']), 'maxPages' => 1, 'source' => 'fallback'));
        }

        $queryArgs = array(
            'post_type' => $postType,
            'post_status' => 'publish',
            'posts_per_page' => max(1, (int) get_option('posts_per_page', 10)),
            'paged' => 1,
            'fields' => 'ids',
            'no_found_rows' => false,
            'ignore_sticky_posts' => false,
        );
        if ($kind === 'taxonomy') {
            $taxonomy = sanitize_key((string) ($family['taxonomy'] ?? ''));
            $termId = absint($family['termId'] ?? 0);
            if ($taxonomy !== '' && $termId > 0) {
                $queryArgs['tax_query'] = array(array('taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => array($termId)));
            }
        } elseif ($kind === 'author') {
            $queryArgs['author'] = absint($family['authorId'] ?? 0);
        } elseif ($kind === 'date') {
            foreach (array('year', 'month', 'day') as $part) {
                if (!empty($family[$part])) {
                    $queryArgs[$part === 'month' ? 'monthnum' : $part] = absint($family[$part]);
                }
            }
        }

        $query = new \WP_Query($queryArgs);
        $maxPages = max(1, (int) $query->max_num_pages);
        $pageUrls = array((string) $family['url']);
        for ($page = 2; $page <= $maxPages; $page++) {
            $pageUrls[] = $this->archivePaginationUrl((string) $family['url'], $page);
        }

        return array_merge($family, array(
            'pageUrls' => array_values(array_unique($pageUrls)),
            'maxPages' => $maxPages,
            'paginationBase' => $this->paginationBase(),
            'foundPosts' => max(0, (int) $query->found_posts),
            'source' => 'wp-query',
        ));
    }

    private function archivePaginationUrl(string $baseUrl, int $page): string
    {
        if ((string) get_option('permalink_structure', '') === '') {
            return (string) add_query_arg('paged', $page, $baseUrl);
        }
        return trailingslashit($baseUrl) . rawurlencode($this->paginationBase()) . '/' . $page . '/';
    }

    private function paginationBase(): string
    {
        global $wp_rewrite;
        return $wp_rewrite instanceof \WP_Rewrite && $wp_rewrite->pagination_base !== ''
            ? (string) $wp_rewrite->pagination_base
            : 'page';
    }

    private function isSameSiteUrl(string $url): bool
    {
        $candidate = wp_parse_url($url);
        $site = wp_parse_url(home_url('/'));
        if (!is_array($candidate) || !is_array($site)) {
            return false;
        }
        $candidateHost = strtolower((string) ($candidate['host'] ?? ''));
        $siteHost = strtolower((string) ($site['host'] ?? ''));
        $candidatePort = isset($candidate['port']) ? (int) $candidate['port'] : (((string) ($candidate['scheme'] ?? '')) === 'https' ? 443 : 80);
        $sitePort = isset($site['port']) ? (int) $site['port'] : (((string) ($site['scheme'] ?? '')) === 'https' ? 443 : 80);
        return $candidateHost !== '' && hash_equals($siteHost, $candidateHost) && $candidatePort === $sitePort;
    }

    private function appendEvent(int $postId, string $postType, string $operation, ?array $before, ?array $after): void
    {
        global $wpdb;

        $this->maybeInstallSchema();
        $correlationId = apply_filters('smartcloud_static_publisher_content_correlation_id', '', $postId, $operation);
        $wpdb->insert(
            $this->tableName(),
            array(
                'blog_id' => get_current_blog_id(),
                'recorded_gmt' => current_time('mysql', true),
                'post_id' => $postId,
                'post_type' => sanitize_key($postType),
                'operation' => sanitize_key($operation),
                'before_projection' => $before === null ? null : wp_json_encode($before),
                'after_projection' => $after === null ? null : wp_json_encode($after),
                'correlation_id' => $this->sanitizeCorrelationId($correlationId),
            ),
            array('%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s')
        );
    }

    private function latestPublicProjection(int $postId): ?array
    {
        global $wpdb;

        $this->maybeInstallSchema();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT before_projection, after_projection FROM {$this->tableName()} WHERE blog_id = %d AND post_id = %d ORDER BY sequence DESC LIMIT 25",
                get_current_blog_id(),
                $postId
            ),
            ARRAY_A
        );
        foreach (is_array($rows) ? $rows : array() as $row) {
            foreach (array('after_projection', 'before_projection') as $column) {
                $encoded = $row[$column] ?? null;
                if (!is_string($encoded) || $encoded === '') {
                    continue;
                }
                $projection = json_decode($encoded, true);
                if (is_array($projection) && $this->projectionIsPublic($projection) && !$this->projectionUsesTrashedSlug($projection)) {
                    return $projection;
                }
            }
        }
        return null;
    }

    private function rememberLastPublicProjection(int $postId, ?array $projection): void
    {
        if (!$this->projectionIsPublic($projection) || $this->projectionUsesTrashedSlug($projection)) {
            return;
        }

        $encoded = wp_json_encode($projection);
        if (is_string($encoded) && $encoded !== '') {
            update_post_meta($postId, self::LAST_PUBLIC_PROJECTION_META, $encoded);
        }
        $post = get_post($postId);
        if (
            $post instanceof \WP_Post
            && $post->post_status === 'publish'
            && $post->post_name !== ''
            && !str_ends_with((string) $post->post_name, '__trashed')
        ) {
            update_post_meta($postId, self::LAST_PUBLIC_SLUG_META, (string) $post->post_name);
        }
    }

    private function storedLastPublicProjection(int $postId): ?array
    {
        $encoded = get_post_meta($postId, self::LAST_PUBLIC_PROJECTION_META, true);
        if (!is_string($encoded) || $encoded === '') {
            return null;
        }

        $projection = json_decode($encoded, true);
        return is_array($projection)
            && $this->projectionIsPublic($projection)
            && !$this->projectionUsesTrashedSlug($projection)
                ? $projection
                : null;
    }

    private function projectionFromWordPressDesiredSlug(\WP_Post $post): ?array
    {
        $desiredSlug = get_post_meta($post->ID, '_wp_desired_post_slug', true);
        if (!is_string($desiredSlug) || $desiredSlug === '') {
            return null;
        }

        $publicPost = clone $post;
        $publicPost->post_status = 'publish';
        $publicPost->post_name = sanitize_title($desiredSlug);
        $projection = $this->buildProjection($publicPost);
        return $this->projectionIsPublic($projection) && !$this->projectionUsesTrashedSlug($projection)
            ? $projection
            : null;
    }

    private function slugFromLastPublicProjection(int $postId): string
    {
        $projection = $this->storedLastPublicProjection($postId) ?? $this->latestPublicProjection($postId);
        $url = is_array($projection) ? (string) ($projection['url'] ?? '') : '';
        $path = $url !== '' ? (string) wp_parse_url($url, PHP_URL_PATH) : '';
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        if (empty($segments)) {
            return '';
        }
        $slug = sanitize_title(rawurldecode((string) end($segments)));
        return str_ends_with($slug, '__trashed') ? '' : $slug;
    }

    private function projectionUsesTrashedSlug(?array $projection): bool
    {
        $url = is_array($projection) ? (string) ($projection['url'] ?? '') : '';
        $path = $url !== '' ? (string) wp_parse_url($url, PHP_URL_PATH) : '';
        foreach (array_filter(explode('/', trim($path, '/'))) as $segment) {
            if (str_ends_with(rawurldecode($segment), '__trashed')) {
                return true;
            }
        }
        return false;
    }

    private function buildProjection(\WP_Post $post): array
    {
        $isPublic = $post->post_status === 'publish';
        $url = $isPublic ? get_permalink($post) : false;
        $terms = $this->publicTermsForPost($post);
        $archiveFamilies = $this->archiveFamilyProjection($post, $terms);
        $projection = array(
            'blogId' => (int) get_current_blog_id(),
            'status' => (string) $post->post_status,
            'url' => is_string($url) && $url !== '' ? esc_url_raw($url) : null,
            'authorId' => (int) $post->post_author,
            'publishedGmt' => $this->normalizeGmtDate($post->post_date_gmt ?: $post->post_date),
            'modifiedGmt' => $this->normalizeGmtDate($post->post_modified_gmt ?: $post->post_modified),
            'terms' => $terms,
            'sticky' => $post->post_type === 'post' && in_array((int) $post->ID, array_map('absint', (array) get_option('sticky_posts', array())), true),
            'archives' => array_values(array_unique(array_column($archiveFamilies, 'url'))),
            'archiveFamilies' => $archiveFamilies,
        );

        $filtered = apply_filters('smartcloud_static_publisher_content_projection', $projection, $post);
        return is_array($filtered) ? $filtered : $projection;
    }

    private function archiveFamilyProjection(\WP_Post $post, array $terms): array
    {
        $families = array();
        $append = static function (array &$items, string $kind, $url, array $metadata = array()): void {
            if (!is_string($url) || $url === '') {
                return;
            }
            $normalized = esc_url_raw($url, array('http', 'https'));
            if ($normalized !== '') {
                $items[] = array_merge(array(
                    'kind' => $kind,
                    'url' => $normalized,
                    'blogId' => (int) get_current_blog_id(),
                ), $metadata);
            }
        };
        $postTypeObject = get_post_type_object($post->post_type);
        if ($postTypeObject instanceof \WP_Post_Type && $postTypeObject->has_archive) {
            $append($families, 'post-type', get_post_type_archive_link($post->post_type), array('postType' => $post->post_type));
        }

        if ($post->post_type === 'post') {
            $postsPageId = absint(get_option('page_for_posts'));
            $postsUrl = $postsPageId > 0 ? get_permalink($postsPageId) : home_url('/');
            $append($families, 'posts-page', $postsUrl, array('postType' => 'post'));
        }

        foreach ($terms as $term) {
            if (!empty($term['url'])) {
                $append($families, 'taxonomy', (string) $term['url'], array(
                    'taxonomy' => sanitize_key((string) ($term['taxonomy'] ?? '')),
                    'termId' => absint($term['termId'] ?? 0),
                    'postType' => $post->post_type,
                ));
            }
        }

        $append($families, 'author', get_author_posts_url((int) $post->post_author), array(
            'authorId' => (int) $post->post_author,
            'postType' => $post->post_type,
        ));

        $timestamp = strtotime((string) ($post->post_date_gmt ?: $post->post_date) . ' UTC');
        if (is_int($timestamp) && $timestamp > 0) {
            $year = (int) gmdate('Y', $timestamp);
            $month = (int) gmdate('m', $timestamp);
            $day = (int) gmdate('d', $timestamp);
            $append($families, 'date', get_year_link($year), array('year' => $year, 'postType' => $post->post_type));
            $append($families, 'date', get_month_link($year, $month), array('year' => $year, 'month' => $month, 'postType' => $post->post_type));
            $append($families, 'date', get_day_link($year, $month, $day), array('year' => $year, 'month' => $month, 'day' => $day, 'postType' => $post->post_type));
        }

        $listingPaths = apply_filters('smartcloud_static_publisher_content_listing_paths', array(), $post);
        foreach (is_array($listingPaths) ? $listingPaths : array() as $listingPath) {
            $candidate = esc_url_raw((string) $listingPath, array('http', 'https'));
            $append($families, 'listing', $candidate !== '' ? $candidate : home_url('/' . ltrim((string) $listingPath, '/')));
        }

        $deduplicated = array();
        foreach ($families as $family) {
            $key = (string) ($family['kind'] ?? '') . '|' . (string) ($family['url'] ?? '');
            $deduplicated[$key] = $family;
        }
        return array_values($deduplicated);
    }

    private function publicTermsForPost(\WP_Post $post): array
    {
        $out = array();
        foreach (get_object_taxonomies($post->post_type, 'objects') as $taxonomy) {
            if (!($taxonomy instanceof \WP_Taxonomy) || !$taxonomy->public) {
                continue;
            }
            $terms = wp_get_object_terms($post->ID, $taxonomy->name);
            if (is_wp_error($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                if (!($term instanceof \WP_Term)) {
                    continue;
                }
                $termUrl = get_term_link($term);
                $out[] = array(
                    'taxonomy' => (string) $term->taxonomy,
                    'termId' => (int) $term->term_id,
                    'url' => is_string($termUrl) ? esc_url_raw($termUrl) : null,
                );
            }
        }
        return $out;
    }

    private function termsFromTermTaxonomyIds(array $termTaxonomyIds, string $taxonomy): array
    {
        global $wpdb;
        $ids = array_values(array_filter(array_map('absint', $termTaxonomyIds)));
        if (empty($ids)) {
            return array();
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $termIds = $wpdb->get_col($wpdb->prepare(
            "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND term_taxonomy_id IN ({$placeholders})",
            array_merge(array($taxonomy), $ids)
        ));
        $out = array();
        foreach ($termIds as $termId) {
            $term = get_term((int) $termId, $taxonomy);
            if (!($term instanceof \WP_Term)) {
                continue;
            }
            $termUrl = get_term_link($term);
            $out[] = array(
                'taxonomy' => $taxonomy,
                'termId' => (int) $term->term_id,
                'url' => is_string($termUrl) ? esc_url_raw($termUrl) : null,
            );
        }
        return $out;
    }

    private function replaceProjectionTaxonomyTerms(array $terms, string $taxonomy, array $replacement): array
    {
        return array_values(array_merge(
            array_filter($terms, static fn($term): bool => !is_array($term) || (string) ($term['taxonomy'] ?? '') !== $taxonomy),
            $replacement
        ));
    }

    private function classifyOperation(?array $before, ?array $after, string $fallback): string
    {
        $wasPublic = $this->projectionIsPublic($before);
        $isPublic = $this->projectionIsPublic($after);
        if (!$wasPublic && $isPublic) {
            return 'publish';
        }
        if ($wasPublic && !$isPublic) {
            return 'unpublish';
        }
        if ($wasPublic && $isPublic && (string) ($before['url'] ?? '') !== (string) ($after['url'] ?? '')) {
            return 'permalink';
        }
        return sanitize_key($fallback) ?: 'update';
    }

    private function projectionIsPublic(?array $projection): bool
    {
        return is_array($projection) && ($projection['status'] ?? '') === 'publish' && !empty($projection['url']);
    }

    private function isJournalPostType(string $postType): bool
    {
        $object = get_post_type_object($postType);
        return $object instanceof \WP_Post_Type
            && $object->public
            && $object->publicly_queryable
            && !in_array($postType, array('attachment', 'revision', 'nav_menu_item'), true);
    }

    private function sanitizePostTypes($value, bool $includeSubsites = false): array
    {
        if (!is_array($value) || empty($value)) {
            return array();
        }
        $out = array();
        foreach ($value as $postType) {
            $candidate = sanitize_key((string) $postType);
            if ($candidate === '' || !$this->isJournalPostTypeInScope($candidate, $includeSubsites)) {
                return array();
            }
            $out[] = $candidate;
        }
        $out = array_values(array_unique($out));
        sort($out, SORT_STRING);
        return $out;
    }

    private function isJournalPostTypeInScope(string $postType, bool $includeSubsites): bool
    {
        global $wpdb;

        if ($this->isJournalPostType($postType)) {
            return true;
        }
        if (!$includeSubsites || !is_multisite()) {
            return false;
        }
        $this->maybeInstallSchema();
        $journaled = $wpdb->get_var($wpdb->prepare(
            "SELECT post_type FROM {$this->tableName()} WHERE post_type = %s LIMIT 1",
            $postType
        ));
        if (is_string($journaled) && hash_equals($postType, $journaled)) {
            return true;
        }
        foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $blogId) {
            if ((int) $blogId === get_current_blog_id()) {
                continue;
            }
            switch_to_blog((int) $blogId);
            try {
                if ($this->isJournalPostType($postType)) {
                    return true;
                }
            } finally {
                restore_current_blog();
            }
        }
        return false;
    }

    private function headSequence(array $postTypes, bool $includeSubsites = false): int
    {
        global $wpdb;
        $this->maybeInstallSchema();
        $placeholders = implode(', ', array_fill(0, count($postTypes), '%s'));
        $where = "post_type IN ({$placeholders})";
        $params = $postTypes;
        if (!$includeSubsites) {
            $where .= ' AND blog_id = %d';
            $params[] = get_current_blog_id();
        }
        return max(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(sequence) FROM {$this->tableName()} WHERE {$where}",
            $params
        )));
    }

    private function hydrateEventRow(array $row): array
    {
        $sequence = (int) ($row['sequence'] ?? 0);
        $blogId = max(1, (int) ($row['blog_id'] ?? get_current_blog_id()));
        $postId = (int) ($row['post_id'] ?? 0);
        $before = $this->decodeProjection($row['before_projection'] ?? null);
        $after = $this->decodeProjection($row['after_projection'] ?? null);
        if ($this->projectionIsPublic($before) && $this->projectionUsesTrashedSlug($before)) {
            $repaired = $this->publicProjectionBeforeSequence($blogId, $postId, $sequence)
                ?? $this->lastPublicProjectionForBlogPost($blogId, $postId);
            if ($repaired !== null) {
                $before = $repaired;
            }
        }
        if ($this->projectionIsPublic($after) && $this->projectionUsesTrashedSlug($after)) {
            $repaired = $this->publicProjectionBeforeSequence($blogId, $postId, $sequence)
                ?? $this->lastPublicProjectionForBlogPost($blogId, $postId);
            if ($repaired !== null) {
                $after = $repaired;
            }
        }

        return array(
            'sequence' => $sequence,
            'blogId' => $blogId,
            'recordedGmt' => mysql2date('c', (string) ($row['recorded_gmt'] ?? ''), false),
            'postId' => $postId,
            'postType' => sanitize_key((string) ($row['post_type'] ?? '')),
            'operation' => sanitize_key((string) ($row['operation'] ?? '')),
            'before' => $before,
            'after' => $after,
            'correlationId' => $this->sanitizeCorrelationId($row['correlation_id'] ?? ''),
        );
    }

    private function publicProjectionBeforeSequence(int $blogId, int $postId, int $sequence): ?array
    {
        global $wpdb;

        if ($postId < 1 || $sequence < 1) {
            return null;
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT before_projection, after_projection FROM {$this->tableName()} WHERE blog_id = %d AND post_id = %d AND sequence < %d ORDER BY sequence DESC LIMIT 50",
                $blogId,
                $postId,
                $sequence
            ),
            ARRAY_A
        );
        foreach (is_array($rows) ? $rows : array() as $candidateRow) {
            foreach (array('after_projection', 'before_projection') as $column) {
                $projection = $this->decodeProjection($candidateRow[$column] ?? null);
                if ($this->projectionIsPublic($projection) && !$this->projectionUsesTrashedSlug($projection)) {
                    return $projection;
                }
            }
        }
        return null;
    }

    private function lastPublicProjectionForBlogPost(int $blogId, int $postId): ?array
    {
        $switched = is_multisite() && $blogId !== get_current_blog_id() && switch_to_blog($blogId);
        try {
            $stored = $this->storedLastPublicProjection($postId);
            if ($stored !== null) {
                return $stored;
            }
            $post = get_post($postId);
            return $post instanceof \WP_Post ? $this->projectionFromWordPressDesiredSlug($post) : null;
        } finally {
            if ($switched) {
                restore_current_blog();
            }
        }
    }

    private function decodeProjection($value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function requestData(\WP_REST_Request $request): array
    {
        $payload = $request->get_json_params();
        return is_array($payload) ? $payload : array();
    }

    private function readConsumer(string $consumerId): ?array
    {
        global $wpdb;
        $this->maybeInstallSchema();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT consumer_id, root_blog_id, include_subsites, scope_fingerprint, baseline_id, sequence, post_types, acknowledged_gmt
             FROM {$this->consumersTableName()} WHERE consumer_id = %s",
            $consumerId
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $postTypes = json_decode((string) ($row['post_types'] ?? '[]'), true);
        return array(
            'consumerId' => (string) ($row['consumer_id'] ?? ''),
            'rootBlogId' => max(1, (int) ($row['root_blog_id'] ?? get_current_blog_id())),
            'includeSubsites' => !empty($row['include_subsites']),
            'scopeFingerprint' => (string) ($row['scope_fingerprint'] ?? ''),
            'baselineId' => (string) ($row['baseline_id'] ?? ''),
            'sequence' => max(0, (int) ($row['sequence'] ?? 0)),
            'postTypes' => is_array($postTypes) ? array_values($postTypes) : array(),
            'acknowledgedGmt' => mysql2date('c', (string) ($row['acknowledged_gmt'] ?? ''), false),
        );
    }

    private function consumerMatches(
        array $consumer,
        string $scopeFingerprint,
        string $baselineId,
        array $postTypes,
        bool $includeSubsites
    ): bool
    {
        $storedPostTypes = isset($consumer['postTypes']) && is_array($consumer['postTypes']) ? array_values($consumer['postTypes']) : array();
        sort($storedPostTypes, SORT_STRING);
        $expectedPostTypes = array_values($postTypes);
        sort($expectedPostTypes, SORT_STRING);
        return $scopeFingerprint !== ''
            && $baselineId !== ''
            && hash_equals((string) ($consumer['scopeFingerprint'] ?? ''), $scopeFingerprint)
            && hash_equals((string) ($consumer['baselineId'] ?? ''), $baselineId)
            && (int) ($consumer['rootBlogId'] ?? 0) === get_current_blog_id()
            && (bool) ($consumer['includeSubsites'] ?? false) === $includeSubsites
            && $storedPostTypes === $expectedPostTypes;
    }

    private function sanitizeConsumerId($value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9:._-]/', '', (string) $value);
        return is_string($value) ? substr($value, 0, 191) : '';
    }

    private function sanitizeFingerprint($value): string
    {
        $value = strtolower(trim((string) $value));
        return preg_match('/^[a-f0-9]{32,128}$/', $value) ? $value : '';
    }

    private function sanitizeBaselineId($value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9:._-]/', '', (string) $value);
        return is_string($value) && $value !== '' ? substr($value, 0, 64) : '';
    }

    private function sanitizeCorrelationId($value): ?string
    {
        $value = preg_replace('/[^a-zA-Z0-9:._-]/', '', (string) $value);
        return is_string($value) && $value !== '' ? substr($value, 0, 64) : null;
    }

    private function normalizeGmtDate(string $value): ?string
    {
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }
        return mysql2date('c', $value, false);
    }

    private function tableName(): string
    {
        global $wpdb;
        $prefix = is_multisite() ? $wpdb->base_prefix : $wpdb->prefix;
        return $prefix . 'smartcloud_static_publisher_content_events';
    }

    private function consumersTableName(): string
    {
        global $wpdb;
        $prefix = is_multisite() ? $wpdb->base_prefix : $wpdb->prefix;
        return $prefix . 'smartcloud_static_publisher_content_consumers';
    }

    private function networkScopeError(bool $includeSubsites): ?\WP_REST_Response
    {
        if (!$includeSubsites || !is_multisite()) {
            return null;
        }
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!is_plugin_active_for_network('smartcloud-static-publisher/smartcloud-static-publisher.php')) {
            return $this->errorResponse(
                'network-activation-required',
                __('Tracking multisite subsites requires network activation of SmartCloud Static Publisher.', 'smartcloud-static-publisher'),
                409
            );
        }
        foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $blogId) {
            if (!$this->isSameSiteUrl((string) get_home_url((int) $blogId, '/'))) {
                return $this->errorResponse(
                    'subsite-origin-unsupported',
                    __('Full-network content sync currently requires same-origin, path-based multisite URLs.', 'smartcloud-static-publisher'),
                    409
                );
            }
        }
        return null;
    }

    private function errorResponse(string $code, string $message, int $status): \WP_REST_Response
    {
        return new \WP_REST_Response(array('success' => false, 'code' => $code, 'message' => $message), $status);
    }
}

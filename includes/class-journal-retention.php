<?php

namespace SmartCloud\WPSuite\StaticPublisher;

if (!defined('ABSPATH')) {
    exit;
}

/** Network-wide journal retention metadata and serialization. */
final class JournalRetention
{
    private const LEGACY_FLOOR = 'smartcloud_static_publisher_content_journal_retention_floor';
    private const UNUSED_PROFILES = 'smartcloud_static_publisher_journal_unused_profiles';
    private ?Plugin $plugin;
    private static array $locks = array();

    public function __construct(?Plugin $plugin = null)
    {
        $this->plugin = $plugin;
    }

    private function table(string $suffix): string
    {
        global $wpdb;
        $prefix = (is_multisite() ? $wpdb->base_prefix : $wpdb->prefix) . 'smartcloud_static_publisher_';
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $prefix) || !in_array($suffix, array('content_events', 'content_consumers', 'journal_state', 'journal_scopes', 'journal_posts'), true)) throw new \RuntimeException('Unsafe journal table identifier.');
        return $prefix . $suffix;
    }

    private function checked($result)
    {
        global $wpdb;
        if ($result === false || !empty($wpdb->last_error)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are not rendered as HTML.
            throw new \RuntimeException('Journal database operation failed: ' . (string) $wpdb->last_error);
        }
        return $result;
    }

    public function installSchema(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $state = $this->table('journal_state');
        $scopes = $this->table('journal_scopes');
        $posts = $this->table('journal_posts');
        dbDelta("CREATE TABLE {$state} (
            id tinyint unsigned NOT NULL,
            high_water bigint(20) unsigned NOT NULL DEFAULT 0,
            retention_floor bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$scopes} (
            blog_id bigint(20) unsigned NOT NULL,
            scope_type varchar(64) NOT NULL,
            high_water bigint(20) unsigned NOT NULL DEFAULT 0,
            retention_floor bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (blog_id, scope_type)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$posts} (
            blog_id bigint(20) unsigned NOT NULL,
            post_type varchar(64) NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            sequence bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (blog_id, post_type, post_id)
        ) ENGINE=InnoDB {$charset};");
        if (!empty($wpdb->last_error)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are not rendered as HTML.
            throw new \RuntimeException('Journal metadata schema installation failed: ' . $wpdb->last_error);
        }
    }

    /** Serialize all journal access on this network/database, including nested calls. */
    public function withLock(callable $callback)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifiers are validated and dynamic values prepared; uncached journal state is required for consistency.
        $database = (string) $wpdb->get_var('SELECT DATABASE()');
        $this->checked($database);
        if ($database === '') {
            throw new \RuntimeException('Journal database identity unavailable.');
        }
        $name = 'wpsuite-journal-' . substr(hash('sha256', $database . ':' . $this->table('content_events')), 0, 40);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifiers are validated and dynamic values prepared; uncached journal state is required for consistency.
        $connection = $this->checked($wpdb->get_var('SELECT CONNECTION_ID()'));
        if (!$connection) throw new \RuntimeException('Journal connection identity unavailable.');
        $heldKey = $name . ':' . getmypid() . ':' . $connection;
        if (isset(self::$locks[$heldKey])) {
            self::$locks[$heldKey]++;
            try {
                return $callback();
            } finally {
                self::$locks[$heldKey]--;
            }
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifiers are validated and dynamic values prepared; uncached journal state is required for consistency.
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, 10));
        $this->checked($acquired);
        if ((string) $acquired !== '1') {
            throw new \RuntimeException('Could not acquire journal lock.');
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifiers are validated and dynamic values prepared; uncached journal state is required for consistency.
        $afterAcquire = $this->checked($wpdb->get_var('SELECT CONNECTION_ID()'));
        if ((string) $afterAcquire !== (string) $connection) throw new \RuntimeException('Journal connection changed while acquiring lock.');
        self::$locks[$heldKey] = 1;
        try {
            return $callback();
        } finally {
            unset(self::$locks[$heldKey]);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifiers are validated and dynamic values prepared; uncached journal state is required for consistency.
            $released = $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
            if ((string) $released !== '1') {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational logging records advisory-lock release failure.
                error_log('WP Suite journal lock release failed.');
            }
        }
    }

    /** @return array{headSequence:int,retentionFloor:int} */
    public function scopeState(array $postTypes, bool $includeSubsites, int $blogId): array
    {
        global $wpdb;
        $postTypes = array_values(array_unique(array_filter($postTypes, static fn($v) => is_string($v) && $v !== '')));
        $events = $this->table('content_events');
        $scopes = $this->table('journal_scopes');
        $types = array_values(array_unique(array_merge($postTypes, array('@resource'))));
        $placeholders = implode(',', array_fill(0, count($types), '%s'));
        $scopeSql = "scope_type IN ({$placeholders})";
        $scopeArgs = $types;
        $liveSql = "operation = 'resource'";
        $liveArgs = array();
        if ($postTypes) {
            $liveSql .= ' OR post_type IN (' . implode(',', array_fill(0, count($postTypes), '%s')) . ')';
            $liveArgs = $postTypes;
        }
        $liveSql = '(' . $liveSql . ')';
        if (!$includeSubsites) {
            $scopeSql .= ' AND blog_id = %d';
            $scopeArgs[] = $blogId;
            $liveSql .= ' AND blog_id = %d';
            $liveArgs[] = $blogId;
        }
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table identifiers are validated and dynamic values prepared; uncached journal state is required for consistency.
        $scope = $this->checked($wpdb->get_row($wpdb->prepare("SELECT MAX(high_water) AS head, MAX(retention_floor) AS floor FROM {$scopes} WHERE {$scopeSql}", $scopeArgs), ARRAY_A));
        $sql = "SELECT MAX(sequence) FROM {$events} WHERE {$liveSql}";
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers are validated and dynamic values prepared; uncached journal state is required for consistency.
        $live = $this->checked($wpdb->get_var($liveArgs ? $wpdb->prepare($sql, $liveArgs) : $sql));
        $floor = max(0, (int) ($scope['floor'] ?? 0), (int) get_site_option(self::LEGACY_FLOOR, 0));
        return array('headSequence' => max($floor, (int) ($scope['head'] ?? 0), (int) $live), 'retentionFloor' => $floor);
    }

    public function latestPostSequence(int $blogId, string $type, int $postId): int
    {
        global $wpdb;
        $events = $this->table('content_events');
        $posts = $this->table('journal_posts');
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifiers are validated and dynamic values prepared; uncached journal state is required for consistency.
        $live = $this->checked($wpdb->get_var($wpdb->prepare("SELECT MAX(sequence) FROM {$events} WHERE blog_id = %d AND post_type = %s AND post_id = %d", $blogId, $type, $postId)));
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifiers are validated and dynamic values prepared; uncached journal state is required for consistency.
        $saved = $this->checked($wpdb->get_var($wpdb->prepare("SELECT MAX(sequence) FROM {$posts} WHERE blog_id = %d AND post_type = %s AND post_id = %d", $blogId, $type, $postId)));
        return max(0, (int) $live, (int) $saved);
    }

    private function origin(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
            throw new \RuntimeException('Invalid source origin.');
        }
        $scheme = strtolower($parts['scheme']);
        $port = isset($parts['port']) && !(($scheme === 'https' && $parts['port'] == 443) || ($scheme === 'http' && $parts['port'] == 80)) ? ':' . (int) $parts['port'] : '';
        return $scheme . '://' . strtolower($parts['host']) . $port;
    }

    private function fileBytes(string $path, bool $required = true): ?string
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            if (!$required && $path !== '' && !file_exists($path)) return null;
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are not rendered as HTML.
            throw new \RuntimeException('Required journal discovery file is unavailable: ' . $path);
        }
        $bytes = file_get_contents($path);
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are not rendered as HTML.
        if ($bytes === false) throw new \RuntimeException('Cannot read journal discovery file: ' . $path);
        return $bytes;
    }

    private function unusedProfileBinding(array $paths): array
    {
        $profile = BackendProfile::context();
        if ($profile === null || !is_int($profile['generation'] ?? null) || $profile['generation'] < 1) throw new \RuntimeException('A current versioned backend profile is required.');
        $runtime = (string) ($paths['runtime'] ?? '');
        if ($runtime === '' || $runtime[0] !== '/') throw new \RuntimeException('An absolute profile runtime is required.');
        return array('blogId' => (int) get_current_blog_id(), 'sourceOrigin' => $this->origin(home_url('/')),
            'profileId' => $profile['id'], 'generation' => $profile['generation'], 'bindingRevision' => $profile['bindingRevision'],
            'stateNamespace' => $profile['stateNamespace'] ?? null, 'runtime' => rtrim($runtime, '/'),
            'optionName' => BackendProfile::optionName('smartcloud_static_publisher_config'));
    }

    /** Absence alone never skips a profile: an explicit, identity-bound declaration is also required. */
    private function pristineProfileProof(array $paths, array $binding): array
    {
        global $wpdb;
        // Draft/legacy local settings are preserved, never interpreted as an active runtime.
        // The operator declaration is bound to their exact bytes and every subsequent native change revokes it.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bind the declaration to the uncached exact option bytes, not a cached or sanitized absence.
        $raw = $this->checked($wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, $binding['optionName'])));
        $missing = new \stdClass();
        $cached = get_option($binding['optionName'], $missing);
        if ($raw === null) {
            if ($cached !== $missing) throw new \RuntimeException('Publisher configuration has an unverified virtual source.');
        } else {
            if (!is_array($cached) || maybe_serialize($cached) !== $raw) throw new \RuntimeException('Publisher configuration cannot be reliably inventoried.');
        }
        $observed = array('configurationSha256' => $raw === null ? null : hash('sha256', $raw));
        foreach (array('config', 'remoteWorkers', 'manifest', 'lock', 'queueMutationLock', 'stopRequest', 'currentProgress', 'schedulerState', 'lastRun', 'queueRunnerHeartbeat', 'deployDiff', 'contentSyncState', 'contentSyncCurrent', 'contentSyncImpactPlan', 'contentSyncCheckpoint', 'contentSyncBaseline', 'contentSyncActiveRules', 'contentSyncCandidateManifest', 'contentSyncInvalidation', 'deployPlan', 'auditEvents') as $key) {
            $path = (string) ($paths[$key] ?? '');
            if ($path === '') throw new \RuntimeException('Complete Publisher initialization inventory is required.');
            if (file_exists($path) || is_link($path)) throw new \RuntimeException('Publisher runtime state already exists; an unused declaration cannot replace discovery.');
            $observed[$key] = null;
        }
        foreach (array('queue' => array(), 'currentRun' => null) as $key => $empty) {
            $path = (string) ($paths[$key] ?? '');
            if ($path === '' || is_link($path)) throw new \RuntimeException('Invalid Publisher queue state.');
            $bytes = $this->fileBytes($path, false);
            if ($bytes !== null && json_decode($bytes, false, 512, JSON_THROW_ON_ERROR) !== $empty) throw new \RuntimeException('Publisher work exists or cannot be reliably parsed for this profile.');
            $observed[$key] = $bytes === null ? null : hash('sha256', $bytes);
        }
        foreach (array('content-sync-releases.json', 'content-sync-release-cutoffs.json') as $name) {
            $path = $binding['runtime'] . '/' . $name;
            if (file_exists($path) || is_link($path)) throw new \RuntimeException('Publisher release history exists for this profile.');
            $observed[$name] = null;
        }
        return array('binding' => $binding, 'observed' => $observed);
    }

    /** Explicit local operator declaration; never creates a runtime, snapshot or consumer. */
    public function declareUnusedProfile(string $id, bool $execute = false): array
    {
        if ($this->plugin === null || !in_array($id, array('development', 'production'), true)) throw new \InvalidArgumentException('Select a configured backend profile.');
        $manager = '\\SmartCloud\\WPSuite\\Hub\\BackendProfiles';
        if (!is_callable(array($manager, 'configuredContexts')) || !is_callable(array($manager, 'runWithContext'))) throw new \RuntimeException('Backend profile management is unavailable.');
        return $this->withLock(function () use ($manager, $id, $execute): array {
            $contexts = array_values(array_filter($manager::configuredContexts(), static fn($context) => ($context['id'] ?? null) === $id));
            if (count($contexts) !== 1) throw new \RuntimeException('Backend profile identity is ambiguous.');
            return $manager::runWithContext($contexts[0], function () use ($execute): array {
                $paths = $this->plugin->getRuntimePaths();
                $binding = $this->unusedProfileBinding($paths);
                $proof = $this->pristineProfileProof($paths, $binding);
                $records = get_site_option(self::UNUSED_PROFILES, array());
                if (!is_array($records)) throw new \RuntimeException('Invalid unused Publisher declarations.');
                $key = $binding['blogId'] . ':' . $binding['profileId'];
                if (isset($records[$key]) && ($records[$key]['binding'] ?? null) === $binding && ($records[$key]['revoked'] ?? false)) throw new \RuntimeException('This profile was initialized; a fresh verified discovery snapshot is required.');
                $record = array('schemaVersion' => 1, 'binding' => $binding, 'configurationSha256' => $proof['observed']['configurationSha256'], 'declaredAt' => gmdate('c'), 'revoked' => false);
                if ($execute) {
                    $records[$key] = $record;
                    update_site_option(self::UNUSED_PROFILES, $records);
                    if (get_site_option(self::UNUSED_PROFILES) !== $records) throw new \RuntimeException('Unused Publisher declaration readback failed.');
                }
                return array('dryRun' => !$execute, 'status' => 'unused-before-initialization', 'binding' => $binding, 'verifiedPristine' => true, 'proof' => $proof, 'eventsDeleted' => 0);
            });
        });
    }

    /** Native configuration creation/change/deletion permanently revokes the old declaration. */
    public function revokeUnusedProfileForOption($option): void
    {
        if (!is_string($option) || !str_ends_with($option, 'smartcloud_static_publisher_config')) return;
        $this->withLock(function () use ($option): void {
            $records = get_site_option(self::UNUSED_PROFILES, array());
            if (!is_array($records)) throw new \RuntimeException('Invalid unused Publisher declarations.');
            $changed = false;
            foreach ($records as &$record) {
                if (($record['binding']['blogId'] ?? null) === (int) get_current_blog_id() && ($record['binding']['optionName'] ?? null) === $option && empty($record['revoked'])) {
                    $record['revoked'] = true;
                    $changed = true;
                }
            }
            unset($record);
            if ($changed) update_site_option(self::UNUSED_PROFILES, $records);
        });
    }

    /** Inventory is repeated immediately before commit, under the journal mutex. */
    private function inventory(): array
    {
        global $wpdb;
        $manager = '\\SmartCloud\\WPSuite\\Hub\\BackendProfiles';
        $blogIds = array((int) get_current_blog_id());
        if (is_multisite()) {
            if (!function_exists('get_sites') || !function_exists('get_current_network_id')) throw new \RuntimeException('Complete multisite inventory unavailable.');
            $ids = get_sites(array('network_id' => 0, 'fields' => 'ids', 'number' => 0));
            if (!is_array($ids) || !$ids) throw new \RuntimeException('Complete multisite inventory unavailable.');
            if (!is_string($wpdb->blogs) || !preg_match('/^[A-Za-z0-9_]+$/D', $wpdb->blogs)) throw new \RuntimeException('Unsafe multisite table identifier.');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifiers are validated and dynamic values prepared; uncached journal state is required for consistency.
            $count = (int) $this->checked($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->blogs}"));
            if ($count !== count($ids)) throw new \RuntimeException('Incomplete multisite inventory.');
            $blogIds = array_map('intval', $ids);
            if (count($blogIds) !== count(array_unique($blogIds)) || min($blogIds) < 1) throw new \RuntimeException('Invalid multisite inventory.');
        }
        sort($blogIds, SORT_NUMERIC);
        $proof = array();
        $stableProof = array();
        $protect = array();
        $unused = array();
        foreach ($blogIds as $blogId) {
            $switched = is_multisite() && $blogId !== (int) get_current_blog_id();
            if ($switched && !switch_to_blog($blogId)) throw new \RuntimeException('Cannot switch to site.');
            try {
                $contexts = array(null);
                if (class_exists($manager) && is_callable(array($manager, 'configuredContexts')) && is_callable(array($manager, 'runWithContext'))) {
                    $configured = $manager::configuredContexts();
                    if (!is_array($configured)) throw new \RuntimeException('Backend profile inventory unavailable.');
                    if ($configured) $contexts = $configured;
                    elseif (is_callable(array($manager, 'enabled')) && $manager::enabled()) throw new \RuntimeException('Backend profile inventory is empty.');
                } elseif (BackendProfile::context() !== null) {
                    throw new \RuntimeException('Backend profile inventory unavailable.');
                }
                foreach ($contexts as $context) {
                    $read = function () use ($blogId, &$proof, &$stableProof, &$protect, &$unused, $wpdb) {
                        $paths = $this->plugin->getRuntimePaths();
                        $runtime = (string) ($paths['runtime'] ?? '');
                        if ($runtime === '' || $runtime[0] !== '/') throw new \RuntimeException('Runtime directory must be absolute.');
                        $runtime = rtrim($runtime, '/');
                        if (BackendProfile::context() !== null) {
                            $binding = $this->unusedProfileBinding($paths);
                            $records = get_site_option(self::UNUSED_PROFILES, array());
                            if (!is_array($records)) throw new \RuntimeException('Invalid unused Publisher declarations.');
                            $record = $records[$blogId . ':' . $binding['profileId']] ?? null;
                            $declaredAt = is_array($record) && is_string($record['declaredAt'] ?? null) ? strtotime($record['declaredAt']) : false;
                            if (is_array($record) && ($record['schemaVersion'] ?? null) === 1 && ($record['revoked'] ?? null) === false && ($record['binding'] ?? null) === $binding && $declaredAt !== false && $declaredAt <= time() + 60) {
                                $pristine = $this->pristineProfileProof($paths, $binding);
                                if (!array_key_exists('configurationSha256', $record) || $record['configurationSha256'] !== $pristine['observed']['configurationSha256']) throw new \RuntimeException('Unused Publisher configuration changed; verified discovery is required.');
                                $proof[] = array('unusedDeclaration' => $record, 'pristine' => $pristine);
                                $stableProof[] = array('unusedDeclaration' => $record, 'pristine' => $pristine);
                                $unused[] = array('blogId' => $blogId, 'profileId' => $binding['profileId'], 'generation' => $binding['generation'], 'bindingRevision' => $binding['bindingRevision'], 'declaredAt' => $record['declaredAt'], 'status' => 'declared-unused-before-initialization');
                                return;
                            }
                        }
                        $snapshotBytes = $this->fileBytes((string) ($paths['contentSyncActiveRules'] ?? ''));
                        $configBytes = $this->fileBytes((string) ($paths['config'] ?? ''));
                        $baselineBytes = $this->fileBytes((string) ($paths['contentSyncBaseline'] ?? ''), false);
                        $snapshot = json_decode($snapshotBytes, true);
                        $config = json_decode($configBytes, true);
                        if (!is_array($config)) throw new \RuntimeException('Invalid runtime config.');
                        $baseline = $baselineBytes === null ? array('entries' => array()) : json_decode($baselineBytes, true);
                        if (!is_array($snapshot) || ($snapshot['contractVersion'] ?? null) !== 1 || ($snapshot['discoveryReady'] ?? null) !== true
                            || !isset($snapshot['rules'], $snapshot['entries']) || !is_array($snapshot['rules']) || !array_is_list($snapshot['rules']) || !is_array($snapshot['entries']) || !array_is_list($snapshot['entries'])
                            || !is_array($baseline) || ($baselineBytes !== null && ($baseline['schemaVersion'] ?? null) !== 1) || !is_array($baseline['entries'] ?? null)) throw new \RuntimeException('Invalid content-sync discovery snapshot.');
                        $at = isset($snapshot['evaluatedAt']) && is_string($snapshot['evaluatedAt']) ? strtotime($snapshot['evaluatedAt']) : false;
                        $maxAge = max(1, (int) apply_filters('smartcloud_static_publisher_journal_snapshot_max_age', 900));
                        if ($at === false || $at < time() - $maxAge || $at > time() + 60) throw new \RuntimeException('Stale content-sync discovery snapshot.');
                        $profile = BackendProfile::context();
                        $expectedProfile = null;
                        if ($profile !== null) {
                            $expectedProfile = array('id' => $profile['id'], 'bindingRevision' => $profile['bindingRevision'], 'generation' => $profile['generation'] ?? null);
                            if (isset($profile['stateNamespace'])) $expectedProfile['stateNamespace'] = $profile['stateNamespace'];
                        }
                        $identity = $snapshot['identity'] ?? null;
                        if (!is_array($identity) || !array_key_exists('backendProfile', $identity) || ($identity['runtimeDir'] ?? null) !== $runtime || ($identity['sourceOrigin'] ?? null) !== rtrim(home_url('/'), '/')
                            || ($identity['configSha256'] ?? null) !== hash('sha256', $configBytes) || ($identity['backendProfile'] ?? null) !== $expectedProfile
                            || rtrim((string) ($config['sourceOrigin'] ?? ''), '/') !== $identity['sourceOrigin']) throw new \RuntimeException('Content-sync snapshot identity mismatch.');
                        $configProfile = $config['backendProfile'] ?? null;
                        if ($configProfile !== null) {
                            if (!is_array($configProfile)) throw new \RuntimeException('Invalid runtime profile identity.');
                            $configProfile = array('id' => $configProfile['id'] ?? null, 'bindingRevision' => $configProfile['bindingRevision'] ?? null, 'generation' => $configProfile['generation'] ?? 0)
                                + (isset($configProfile['stateNamespace']) ? array('stateNamespace' => $configProfile['stateNamespace']) : array());
                        }
                        if ($configProfile !== $expectedProfile) throw new \RuntimeException('Runtime config profile identity mismatch.');
                        $proof[] = array($blogId, $runtime, hash('sha256', $snapshotBytes), hash('sha256', $configBytes), $baselineBytes === null ? null : hash('sha256', $baselineBytes), $expectedProfile);
                        $stableSnapshot = $snapshot;
                        unset($stableSnapshot['evaluatedAt']);
                        $stableProof[] = array($blogId, $runtime, $stableSnapshot, hash('sha256', $configBytes), $baselineBytes === null ? null : hash('sha256', $baselineBytes), $expectedProfile);
                        $entries = $snapshot['entries'];
                        $seenRules = array(); $seenConsumers = array();
                        foreach ($snapshot['rules'] as $rule) {
                            if (!is_array($rule) || !is_bool($rule['active'] ?? null)) throw new \RuntimeException('Invalid content-sync rule.');
                            if (!$rule['active']) continue;
                            $ruleId = $rule['ruleId'] ?? null; $key = $rule['coalesceKey'] ?? null;
                            $types = $rule['postTypes'] ?? null; $subsites = $rule['includeSubsites'] ?? null;
                            if (!is_string($ruleId) || $ruleId === '' || !is_string($key) || $key === '' || !is_array($types) || !array_is_list($types) || !$types || !is_bool($subsites)) throw new \RuntimeException('Invalid active rule scope.');
                            foreach ($types as $type) if (!is_string($type) || $type === '' || strlen($type) > 64 || sanitize_key($type) !== $type) throw new \RuntimeException('Invalid active rule post type.');
                            $types = array_values(array_unique($types)); sort($types, SORT_STRING);
                            if (isset($seenRules[$key])) throw new \RuntimeException('Ambiguous active rule identity.');
                            $seenRules[$key] = true;
                            $matches = array_values(array_filter($entries, static fn($entry) => is_array($entry) && ($entry['ruleId'] ?? null) === $ruleId && ($entry['coalesceKey'] ?? null) === $key));
                            if (count($matches) > 1) throw new \RuntimeException('Ambiguous active consumer entries.');
                            $ack = 0; $reason = 'baseline-required'; $consumerId = '';
                            $base = $baseline['entries'][$key] ?? null;
                            if (count($matches) === 1 && is_array($base)) {
                                $entry = $matches[0]; $consumerId = (string) ($entry['consumerId'] ?? '');
                                if ($consumerId !== '') {
                                    if (isset($seenConsumers[$consumerId])) throw new \RuntimeException('Ambiguous active consumer identity.');
                                    $seenConsumers[$consumerId] = true;
                                }
                                $fingerprint = (string) ($base['scopeFingerprint'] ?? ''); $baselineId = (string) ($base['baselineId'] ?? '');
                                if ($consumerId !== '' && $fingerprint !== '' && $fingerprint === ($rule['scopeFingerprint'] ?? null) && $baselineId !== '' && ($base['schemaVersion'] ?? null) === 1 && ($base['ruleId'] ?? null) === $ruleId && ($base['coalesceKey'] ?? null) === $key && ($base['consumerId'] ?? null) === $consumerId) {
                                    $table = $this->table('content_consumers');
                                    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifiers are validated and dynamic values prepared; uncached journal state is required for consistency.
                                    $row = $this->checked($wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE consumer_id = %s", $consumerId), ARRAY_A));
                                    $storedTypes = is_array($row) ? json_decode((string) ($row['post_types'] ?? ''), true) : null;
                                    if (is_array($storedTypes)) sort($storedTypes, SORT_STRING);
                                    if (is_array($row) && (int) $row['root_blog_id'] === $blogId && (bool) $row['include_subsites'] === $subsites && $storedTypes === $types
                                        && hash_equals((string) $row['scope_fingerprint'], $fingerprint) && hash_equals((string) $row['baseline_id'], $baselineId)) {
                                        $ack = max(0, (int) $row['sequence']); $reason = 'ready';
                                    }
                                }
                            }
                            if ($reason === 'ready') {
                                $position = $this->scopeState($types, $subsites, $blogId);
                                if ($ack < $position['retentionFloor'] || $ack > $position['headSequence']) { $ack = 0; $reason = 'baseline-required'; }
                            }
                            $protect[] = array('blogId' => $blogId, 'includeSubsites' => $subsites, 'postTypes' => $types, 'sequence' => $ack, 'status' => $reason, 'ruleId' => $ruleId, 'consumerId' => $consumerId);
                        }
                    };
                    if ($context !== null) $manager::runWithContext($context, $read); else $read();
                }
            } finally {
                if ($switched) restore_current_blog();
            }
        }
        return array('proof' => $proof, 'consumers' => $protect, 'unusedProfiles' => $unused, 'fingerprint' => hash('sha256', wp_json_encode($stableProof, JSON_THROW_ON_ERROR)));
    }

    private function assertInnoDB(): void
    {
        global $wpdb;
        foreach (array('content_events', 'content_consumers', 'journal_state', 'journal_scopes', 'journal_posts') as $suffix) {
            $name = $this->table($suffix);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifiers are validated and dynamic values prepared; uncached journal state is required for consistency.
            $row = $this->checked($wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $name), ARRAY_A));
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are not rendered as HTML.
            if (!is_array($row) || strcasecmp((string) ($row['Engine'] ?? ''), 'InnoDB') !== 0) throw new \RuntimeException('Journal table missing or not InnoDB: ' . $name);
        }
    }

    private function protectionPredicate(array $consumers): array
    {
        $clauses = array(); $args = array();
        foreach ($consumers as $consumer) {
            $types = $consumer['postTypes'];
            $clause = "(sequence > %d AND (operation = 'resource' OR post_type IN (" . implode(',', array_fill(0, count($types), '%s')) . '))';
            $args[] = $consumer['sequence']; array_push($args, ...$types);
            if (!$consumer['includeSubsites']) { $clause .= ' AND blog_id = %d'; $args[] = $consumer['blogId']; }
            $clauses[] = $clause . ')';
        }
        return array($clauses ? '(' . implode(' OR ', $clauses) . ')' : '0=1', $args);
    }

    private function sql(string $query, array $args = array())
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers are validated and dynamic values prepared; uncached journal state is required for consistency.
        return $args ? $wpdb->prepare($query, $args) : $query;
    }

    /** Bind a confirmation to the selected batch and safety state, ignoring the clock tick only. */
    public static function previewFingerprint(array $report): string
    {
        unset($report['cutoff'], $report['reportedAt']);
        $report['dryRun'] = true;
        return hash('sha256', wp_json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function prune(int $days = 30, int $batch = 500, bool $dryRun = true, ?string $expectedPreviewFingerprint = null): array
    {
        if ($days < 1 || $days > 36500 || $batch < 1 || $batch > 5000) throw new \InvalidArgumentException('Days must be 1–36500 and batch size 1–5000.');
        if ($this->plugin === null) throw new \RuntimeException('Publisher plugin is unavailable.');
        $report = $this->withLock(function () use ($days, $batch, $dryRun, $expectedPreviewFingerprint) {
            global $wpdb;
            $this->assertInnoDB();
            $inventory = $this->inventory();
            list($protection, $protectionArgs) = $this->protectionPredicate($inventory['consumers']);
            $events = $this->table('content_events');
            $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
            $args = array_merge(array($cutoff), $protectionArgs);
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifiers are validated and dynamic values prepared; uncached journal state is required for consistency.
            $expired = (int) $this->checked($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$events} WHERE recorded_gmt < %s", $cutoff)));
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers are validated and dynamic values prepared; uncached journal state is required for consistency.
            $skipped = (int) $this->checked($wpdb->get_var($this->sql("SELECT COUNT(*) FROM {$events} WHERE recorded_gmt < %s AND {$protection}", $args)));
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers are validated and dynamic values prepared; uncached journal state is required for consistency.
            $rows = $this->checked($wpdb->get_results($this->sql("SELECT sequence,blog_id,post_id,post_type,operation FROM {$events} WHERE recorded_gmt < %s AND NOT {$protection} ORDER BY recorded_gmt ASC, sequence ASC LIMIT %d", array_merge($args, array($batch))), ARRAY_A));
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifiers are validated and dynamic values prepared; uncached journal state is required for consistency.
            $total = (int) $this->checked($wpdb->get_var("SELECT COUNT(*) FROM {$events}"));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifiers are validated and dynamic values prepared; uncached journal state is required for consistency.
            $water = $this->checked($wpdb->get_row("SELECT high_water,retention_floor FROM {$this->table('journal_state')} WHERE id=1", ARRAY_A));
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifiers are validated and dynamic values prepared; uncached journal state is required for consistency.
            $liveHead = (int) $this->checked($wpdb->get_var("SELECT MAX(sequence) FROM {$events}"));
            $report = array('dryRun' => $dryRun, 'days' => $days, 'batchSize' => $batch, 'cutoff' => $cutoff, 'cutoffSequence' => $rows ? max(array_map(static fn($row) => (int) $row['sequence'], $rows)) : 0, 'expired' => $expired, 'skipped' => $skipped, 'eligible' => $expired - $skipped, 'selected' => count($rows), 'deleted' => 0, 'retained' => $total, 'remaining' => $expired - $skipped, 'activeConsumers' => $inventory['consumers'], 'unusedProfiles' => $inventory['unusedProfiles'], 'highWaterSequence' => max($liveHead, (int) ($water['high_water'] ?? 0)), 'retentionFloor' => max((int) ($water['retention_floor'] ?? 0), (int) get_site_option(self::LEGACY_FLOOR, 0)), 'errors' => array());
            $report['selectionFingerprint'] = hash('sha256', wp_json_encode($rows, JSON_THROW_ON_ERROR));
            $report['safetyFingerprint'] = $inventory['fingerprint'];
            if ($expectedPreviewFingerprint !== null && !hash_equals($expectedPreviewFingerprint, self::previewFingerprint($report))) throw new \UnexpectedValueException('Journal changed. Review cleanup again.');
            if ($dryRun || !$rows) return $report;
            $handle = $wpdb->dbh;
            if (!($handle instanceof \mysqli)) throw new \RuntimeException('Transactional mysqli connection unavailable.');
            $threadId = $handle->thread_id;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifiers are validated and dynamic values prepared; uncached journal state is required for consistency.
            $database = (string) $wpdb->get_var('SELECT DATABASE()');
            $lockName = 'wpsuite-journal-' . substr(hash('sha256', $database . ':' . $events), 0, 40);
            $native = static function (string $query) use ($wpdb, $handle, $threadId) {
                if ($wpdb->dbh !== $handle || $handle->thread_id !== $threadId) throw new \RuntimeException('Journal database connection changed during transaction.');
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are not rendered as HTML.
                try { $result = $handle->query($query); } catch (\Throwable $error) { throw new \RuntimeException('Journal transaction database error.', 0, $error); }
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are not rendered as HTML.
                if ($result === false) throw new \RuntimeException('Journal transaction database error: ' . $handle->error);
                return $result === true ? $handle->affected_rows : $result;
            };
            $assertLock = static function () use ($native, $handle, $threadId, $lockName): void {
                $result = $native("SELECT IS_USED_LOCK('" . $handle->real_escape_string($lockName) . "')");
                $owner = $result->fetch_row()[0]; $result->free();
                if ((string) $owner !== (string) $threadId) throw new \RuntimeException('Journal mutex was lost during transaction.');
            };
            $assertLock();
            $native('START TRANSACTION');
            try {
                $state = $this->table('journal_state'); $scopes = $this->table('journal_scopes'); $posts = $this->table('journal_posts');
                $headResult = $native("SELECT MAX(sequence) FROM {$events}");
                $head = (int) $headResult->fetch_row()[0];
                $headResult->free();
                $ids = array_map(static fn($row) => (int) $row['sequence'], $rows);
                $maxDeleted = max($ids);
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifiers are validated and dynamic values prepared; uncached journal state is required for consistency.
                $native($wpdb->prepare("INSERT INTO {$state} (id,high_water,retention_floor) VALUES (1,%d,%d) ON DUPLICATE KEY UPDATE high_water=GREATEST(high_water,VALUES(high_water)), retention_floor=GREATEST(retention_floor,VALUES(retention_floor))", $head, $maxDeleted));
                $in = implode(',', array_fill(0, count($ids), '%d'));
                $scopeQuery = "INSERT INTO {$scopes} (blog_id,scope_type,high_water,retention_floor) SELECT blog_id, CASE WHEN operation = 'resource' THEN '@resource' ELSE post_type END AS scope_type, MAX(sequence), MAX(sequence) FROM {$events} WHERE sequence IN ({$in}) GROUP BY blog_id, scope_type ON DUPLICATE KEY UPDATE high_water=GREATEST(high_water,VALUES(high_water)), retention_floor=GREATEST(retention_floor,VALUES(retention_floor))";
                $native($this->sql($scopeQuery, $ids));
                $postQuery = "INSERT INTO {$posts} (blog_id,post_type,post_id,sequence) SELECT blog_id,post_type,post_id,MAX(sequence) FROM {$events} WHERE post_id > 0 AND sequence IN ({$in}) GROUP BY blog_id,post_type,post_id ON DUPLICATE KEY UPDATE sequence=GREATEST(sequence,VALUES(sequence))";
                $native($this->sql($postQuery, $ids));
                $assertLock();
                $deleted = $native($this->sql("DELETE FROM {$events} WHERE sequence IN (" . implode(',', array_fill(0, count($ids), '%d')) . ')', $ids));
                if ((int) $deleted !== count($ids)) throw new \RuntimeException('Journal deletion count changed.');
                if ($this->inventory() !== $inventory) throw new \RuntimeException('Journal consumer discovery changed during pruning.');
                $assertLock();
                $native('COMMIT');
                $report['deleted'] = (int) $deleted; $report['retained'] -= (int) $deleted; $report['remaining'] -= (int) $deleted;
                $report['retentionFloor'] = max($report['retentionFloor'], $maxDeleted);
            } catch (\Throwable $error) {
                try { $native('ROLLBACK'); } catch (\Throwable $ignored) { /* A lost connection already rolls back. */ }
                throw $error;
            }
            return $report;
        });
        if (!$dryRun) {
            $report['reportedAt'] = gmdate('c');
            update_site_option('smartcloud_static_publisher_journal_retention_last_report', $report);
            do_action('smartcloud_static_publisher_journal_retention_report', $report);
        }
        return $report;
    }

    public function runScheduled(): void
    {
        try {
            $days = (int) apply_filters('smartcloud_static_publisher_journal_retention_days', get_site_option('smartcloud_static_publisher_journal_retention_days', 30));
            $batch = (int) apply_filters('smartcloud_static_publisher_journal_retention_batch_size', get_site_option('smartcloud_static_publisher_journal_retention_batch_size', 500));
            $report = $this->prune($days, $batch, false);
            if ($report['remaining'] > 0 && !wp_next_scheduled('smartcloud_static_publisher_journal_retention_continue')) wp_schedule_single_event(time() + 60, 'smartcloud_static_publisher_journal_retention_continue');
            return;
        } catch (\Throwable $error) {
            $report = array('dryRun' => false, 'deleted' => null, 'outcome' => 'unconfirmed', 'errors' => array($error->getMessage()));
        }
        $report['reportedAt'] = gmdate('c');
        update_site_option('smartcloud_static_publisher_journal_retention_last_report', $report);
        do_action('smartcloud_static_publisher_journal_retention_report', $report);
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook('smartcloud_static_publisher_journal_retention_daily');
        wp_clear_scheduled_hook('smartcloud_static_publisher_journal_retention_continue');
    }

    public function registerHooks(): void
    {
        if ($this->plugin === null) throw new \RuntimeException('Publisher plugin is unavailable.');
        foreach (array('added_option', 'updated_option', 'deleted_option') as $hook) add_action($hook, array($this, 'revokeUnusedProfileForOption'), 10, 1);
        add_action('init', static function () {
            if (!wp_next_scheduled('smartcloud_static_publisher_journal_retention_daily')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'smartcloud_static_publisher_journal_retention_daily');
        });
        add_action('smartcloud_static_publisher_journal_retention_daily', array($this, 'runScheduled'));
        add_action('smartcloud_static_publisher_journal_retention_continue', array($this, 'runScheduled'));
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('static-publisher journal-profile-unused', function ($args, $flags) {
                try {
                    if (isset($flags['dry-run'], $flags['execute'])) throw new \InvalidArgumentException('--dry-run and --execute are mutually exclusive.');
                    foreach (array('dry-run', 'execute') as $flag) {
                        if (array_key_exists($flag, $flags) && $flags[$flag] !== true) throw new \InvalidArgumentException('--' . $flag . ' must be a valueless flag.');
                    }
                    $report = $this->declareUnusedProfile((string) ($flags['profile'] ?? ''), isset($flags['execute']));
                    \WP_CLI::line(wp_json_encode($report));
                } catch (\Throwable $error) {
                    \WP_CLI::error(wp_json_encode(array('errors' => array($error->getMessage()))));
                }
            });
            \WP_CLI::add_command('static-publisher journal-prune', function ($args, $flags) {
                try {
                    if (isset($flags['dry-run'], $flags['execute'])) throw new \InvalidArgumentException('--dry-run and --execute are mutually exclusive.');
                    foreach (array('dry-run', 'execute') as $flag) {
                        if (array_key_exists($flag, $flags) && $flags[$flag] !== true) throw new \InvalidArgumentException('--' . $flag . ' must be a valueless flag.');
                    }
                    $positive = static function ($value, string $name): int {
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are not rendered as HTML.
                        if (!is_scalar($value) || !preg_match('/^[1-9][0-9]*$/D', (string) $value) || (float) $value > PHP_INT_MAX) throw new \InvalidArgumentException($name . ' must be a positive integer.');
                        return (int) $value;
                    };
                    $report = $this->prune($positive($flags['days'] ?? apply_filters('smartcloud_static_publisher_journal_retention_days', get_site_option('smartcloud_static_publisher_journal_retention_days', 30)), '--days'), $positive($flags['batch-size'] ?? apply_filters('smartcloud_static_publisher_journal_retention_batch_size', get_site_option('smartcloud_static_publisher_journal_retention_batch_size', 500)), '--batch-size'), !isset($flags['execute']));
                    \WP_CLI::line(wp_json_encode($report));
                } catch (\Throwable $error) {
                    \WP_CLI::error(wp_json_encode(array('errors' => array($error->getMessage()))));
                }
            });
        }
    }
}

<?php

namespace SmartCloud\WPSuite\StaticPublisher;

if (!defined('ABSPATH')) {
    exit;
}

/** Explicit, backup-first compaction of the journal's event table. */
final class JournalMaintenance
{
    private const COMPLETED = 'smartcloud_static_publisher_journal_rebuild_completed';
    private const STARTED = 'smartcloud_static_publisher_journal_rebuild_started';
    private const LAST_REPORT = 'smartcloud_static_publisher_journal_retention_last_report';
    private JournalRetention $retention;

    public function __construct(JournalRetention $retention)
    {
        $this->retention = $retention;
    }

    /** Validate every path component before creating an exclusive private backup. */
    private function validateBackupPath(string $path): void
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || substr($path, -1) === '/') {
            throw new \InvalidArgumentException('Backup path must be an absolute file path.');
        }
        $parts = explode('/', $path);
        foreach (array_slice($parts, 1) as $part) {
            if ($part === '' || $part === '.' || $part === '..') throw new \InvalidArgumentException('Backup path is not canonical.');
        }
        $parent = dirname($path);
        $cursor = '';
        foreach (array_slice(explode('/', $parent), 1) as $part) {
            $cursor .= '/' . $part;
            if (is_link($cursor) || !is_dir($cursor)) throw new \RuntimeException('Backup parent path must contain only real directories.');
        }
        $realParent = realpath($parent);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Exclusive private backup and durable streaming require native local file APIs.
        if ($realParent === false || $realParent !== $parent || !is_writable($parent)) throw new \RuntimeException('Backup parent directory is unavailable.');
        $permissions = fileperms($parent);
        if ($permissions === false || ($permissions & 0077) !== 0) throw new \RuntimeException('Backup parent directory must be private (mode 0700).');
        $upload = wp_get_upload_dir();
        $roots = array(realpath(ABSPATH), realpath((string) ($upload['basedir'] ?? '')));
        if (!$roots[0] || !$roots[1]) throw new \RuntimeException('Cannot establish web and upload root locations.');
        foreach ($roots as $root) {
            $root = rtrim($root, '/');
            if ($parent === $root || str_starts_with($parent . '/', $root . '/')) throw new \RuntimeException('Backup must be outside the WordPress and uploads directories.');
        }
        if (file_exists($path) || is_link($path)) throw new \RuntimeException('Backup already exists; operator reconciliation is required.');
    }

    private function writeLine($stream, array $record): string
    {
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $length = strlen($line);
        $written = 0;
        while ($written < $length) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Exclusive private backup and durable streaming require native local file APIs.
            $count = fwrite($stream, substr($line, $written));
            if ($count === false || $count === 0) throw new \RuntimeException('Could not write complete journal backup.');
            $written += $count;
        }
        return $line;
    }

    /**
     * @param callable(string):\mysqli_result|int $query Native connection only.
     * @return array{count:int,sha256:string}
     */
    private function streamRows(callable $query, string $table, string $order, $stream = null): array
    {
        $hash = hash_init('sha256');
        $offset = 0;
        do {
            $result = $query("SELECT * FROM `{$table}` ORDER BY {$order} LIMIT 500 OFFSET " . $offset);
            $batch = 0;
            while ($row = $result->fetch_assoc()) {
                $record = array('kind' => 'row', 'table' => $table, 'row' => $row);
                $line = $stream ? $this->writeLine($stream, $record) : json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
                hash_update($hash, $line);
                $batch++;
            }
            $result->free();
            $offset += $batch;
        } while ($batch === 500);
        return array('count' => $offset, 'sha256' => hash_final($hash));
    }

    private function schema(callable $query, string $table): string
    {
        $result = $query("SHOW CREATE TABLE `{$table}`");
        $row = $result->fetch_row();
        $result->free();
        if (!is_array($row) || !isset($row[1])) throw new \RuntimeException('Unable to read journal table schema.');
        return (string) $row[1];
    }

    /** Backup, compact and verify once; a crash fence requires manual reconciliation. */
    public function rebuild(string $backupPath): array
    {
        $this->validateBackupPath($backupPath);
        return $this->retention->withLock(function () use ($backupPath) {
            global $wpdb;
            if (get_site_option(self::COMPLETED, false) || get_site_option(self::STARTED, false)) throw new \RuntimeException('Journal rebuild already completed or requires operator reconciliation.');
            $report = get_site_option(self::LAST_REPORT, null);
            $reportedAt = is_array($report) && is_string($report['reportedAt'] ?? null) ? strtotime($report['reportedAt']) : false;
            $days = is_array($report) ? ($report['days'] ?? null) : null;
            if (!is_array($report) || ($report['dryRun'] ?? null) !== false || ($report['errors'] ?? null) !== array() || ($report['remaining'] ?? null) !== 0
                || !is_int($days) || $days < 1 || $days > 36500 || $reportedAt === false || $reportedAt < time() - DAY_IN_SECONDS || $reportedAt > time() + 60) {
                throw new \RuntimeException('A successful, complete retention run from the last 24 hours is required.');
            }
            $check = $this->retention->prune($days, 1, true);
            if (($check['eligible'] ?? null) !== 0) throw new \RuntimeException('Eligible expired journal records remain.');
            $this->validateBackupPath($backupPath);
            $handle = $wpdb->dbh;
            if (!($handle instanceof \mysqli)) throw new \RuntimeException('Native journal database connection unavailable.');
            $thread = $handle->thread_id;
            $query = static function (string $sql) use ($wpdb, $handle, $thread) {
                if ($wpdb->dbh !== $handle || $handle->thread_id !== $thread) throw new \RuntimeException('Journal database connection changed.');
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are not rendered as HTML.
                try { $result = $handle->query($sql); } catch (\Throwable $error) { throw new \RuntimeException('Journal maintenance database operation failed.', 0, $error); }
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are not rendered as HTML.
                if ($result === false) throw new \RuntimeException('Journal maintenance database operation failed: ' . $handle->error);
                return $result === true ? $handle->affected_rows : $result;
            };
            $prefix = (is_multisite() ? $wpdb->base_prefix : $wpdb->prefix) . 'smartcloud_static_publisher_';
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $prefix)) throw new \RuntimeException('Unsafe journal table prefix.');
            $tables = array(
                $prefix . 'content_events' => '`sequence`',
                $prefix . 'content_consumers' => '`consumer_id`',
                $prefix . 'journal_state' => '`id`',
                $prefix . 'journal_scopes' => '`blog_id`,`scope_type`',
                $prefix . 'journal_posts' => '`blog_id`,`post_type`,`post_id`',
            );
            $databaseResult = $query('SELECT DATABASE()');
            $database = (string) ($databaseResult->fetch_row()[0] ?? ''); $databaseResult->free();
            if ($database === '') throw new \RuntimeException('Journal database identity unavailable.');
            $lock = 'wpsuite-journal-' . substr(hash('sha256', $database . ':' . $prefix . 'content_events'), 0, 40);
            $assertLock = static function () use ($query, $handle, $lock, $thread): void {
                $result = $query("SELECT IS_USED_LOCK('" . $handle->real_escape_string($lock) . "')");
                $owner = $result->fetch_row()[0]; $result->free();
                if ((string) $owner !== (string) $thread) throw new \RuntimeException('Journal mutex was lost.');
            };
            $assertLock();
            $events = $prefix . 'content_events'; $state = $prefix . 'journal_state';
            $status = $query("SHOW TABLE STATUS WHERE Name = '" . $handle->real_escape_string($events) . "'");
            $statusRow = $status->fetch_assoc(); $status->free();
            if (!is_array($statusRow) || strcasecmp((string) ($statusRow['Engine'] ?? ''), 'InnoDB') !== 0 || !is_numeric($statusRow['Auto_increment'] ?? null)) throw new \RuntimeException('Journal event table or auto-increment is unavailable.');
            $filePerTable = $query("SELECT @@innodb_file_per_table");
            $dedicated = (int) $filePerTable->fetch_row()[0]; $filePerTable->free();
            if ($dedicated !== 1) throw new \RuntimeException('Physical journal space reclamation requires InnoDB file-per-table storage.');
            $storageBefore = array_intersect_key($statusRow, array_flip(array('Data_length', 'Index_length', 'Data_free')));
            $next = (int) $statusRow['Auto_increment'];
            $headResult = $query("SELECT GREATEST(COALESCE((SELECT MAX(sequence) FROM `{$events}`),0),COALESCE((SELECT MAX(high_water) FROM `{$state}`),0))");
            $head = (int) $headResult->fetch_row()[0]; $headResult->free();
            if ($next <= $head || $next < 1) throw new \RuntimeException('Journal auto-increment does not exceed its retained high-water mark.');
            $started = array('backupPath' => $backupPath, 'startedAt' => gmdate('c'));
            update_site_option(self::STARTED, $started);
            if (get_site_option(self::STARTED, null) !== $started) throw new \RuntimeException('Could not persist journal rebuild safety fence.');
            $assertLock();
            $oldMask = umask(0077);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive private backup and durable streaming require native local file APIs.
            try { $stream = @fopen($backupPath, 'xb'); } finally { umask($oldMask); }
            if (!$stream) throw new \RuntimeException('Could not create exclusive journal backup.');
            $schemas = array(); $before = array();
            try {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Exclusive private backup and durable streaming require native local file APIs.
                if (!chmod($backupPath, 0600)) throw new \RuntimeException('Could not secure journal backup permissions.');
                $openStat = fstat($stream); $pathStat = lstat($backupPath);
                if (!$openStat || !$pathStat || $openStat['ino'] !== $pathStat['ino'] || $openStat['dev'] !== $pathStat['dev'] || ($openStat['mode'] & 0077) !== 0) throw new \RuntimeException('Journal backup file changed or is not private.');
                $this->writeLine($stream, array('kind' => 'header', 'schemaVersion' => 1, 'database' => $database, 'tables' => array_keys($tables), 'autoIncrement' => $next, 'highWater' => $head));
                foreach ($tables as $table => $order) {
                    $assertLock();
                    $schemas[$table] = $this->schema($query, $table);
                    $this->writeLine($stream, array('kind' => 'schema', 'table' => $table, 'sql' => $schemas[$table]));
                    $before[$table] = $this->streamRows($query, $table, $order, $stream);
                }
                $this->writeLine($stream, array('kind' => 'end', 'tables' => $before));
                if (!fflush($stream) || (function_exists('fsync') && !fsync($stream))) throw new \RuntimeException('Could not flush journal backup.');
            } finally {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Exclusive private backup and durable streaming require native local file APIs.
                fclose($stream);
            }
            $backupSha = hash_file('sha256', $backupPath);
            if (!is_string($backupSha)) throw new \RuntimeException('Could not verify journal backup hash.');
            $assertLock();
            $query("ALTER TABLE `{$events}` ENGINE=InnoDB, AUTO_INCREMENT={$next}");
            $assertLock();
            $after = array();
            foreach ($tables as $table => $order) {
                $currentSchema = $this->schema($query, $table);
                // MySQL may advance the displayed counter; all other schema bytes must agree.
                $normalize = static fn(string $sql): string => preg_replace('/ AUTO_INCREMENT=\\d+/', '', $sql);
                if ($normalize($currentSchema) !== $normalize($schemas[$table])) throw new \RuntimeException('Journal schema changed during rebuild.');
                $after[$table] = $this->streamRows($query, $table, $order);
                if ($after[$table] !== $before[$table]) throw new \RuntimeException('Journal records changed during rebuild.');
            }
            $status = $query("SHOW TABLE STATUS WHERE Name = '" . $handle->real_escape_string($events) . "'");
            $statusRow = $status->fetch_assoc(); $status->free();
            $afterNext = (int) ($statusRow['Auto_increment'] ?? 0);
            if ($afterNext < $next || $afterNext <= $head) throw new \RuntimeException('Journal auto-increment regressed during rebuild.');
            if (!hash_equals($backupSha, (string) hash_file('sha256', $backupPath))) throw new \RuntimeException('Journal backup changed during rebuild.');
            $assertLock();
            $result = array('backupPath' => $backupPath, 'backupSha256' => $backupSha, 'rowCounts' => array_map(static fn($value) => $value['count'], $before), 'tableHashes' => array_map(static fn($value) => $value['sha256'], $before), 'autoIncrementBefore' => $next, 'autoIncrementAfter' => $afterNext, 'storageBefore' => $storageBefore, 'storageAfter' => array_intersect_key($statusRow, array_flip(array('Data_length', 'Index_length', 'Data_free'))), 'filePerTable' => true, 'highWater' => $head, 'completedAt' => gmdate('c'));
            update_site_option(self::COMPLETED, $result);
            if (get_site_option(self::COMPLETED, null) !== $result) throw new \RuntimeException('Could not persist journal rebuild completion.');
            delete_site_option(self::STARTED);
            return $result;
        });
    }

    public function registerCommand(): void
    {
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('static-publisher journal-rebuild', function ($args, $flags) {
                try {
                    if (($flags['execute'] ?? null) !== true || !isset($flags['backup']) || !is_string($flags['backup'])) throw new \InvalidArgumentException('Specify --execute and --backup=/absolute/private/path.jsonl.');
                    \WP_CLI::line(wp_json_encode($this->rebuild($flags['backup'])));
                } catch (\Throwable $error) {
                    \WP_CLI::error(wp_json_encode(array('errors' => array($error->getMessage()))));
                }
            });
        }
    }
}

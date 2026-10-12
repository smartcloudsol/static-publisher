<?php

declare(strict_types=1);

// Real WordPress prepare algorithm, with no database connection or credentials.
$wordpress = $argv[1] ?? '';
if (!is_file($wordpress . '/wp-includes/class-wpdb.php')) {
    fwrite(STDERR, "Usage: php content-sync-sql-prepare.test.php <wordpress-fixture-root>\n");
    exit(1);
}
define('ABSPATH', $wordpress . '/');
require_once $wordpress . '/wp-includes/class-wpdb.php';
require_once __DIR__ . '/../includes/class-content-change-journal.php';

function _doing_it_wrong(mixed ...$args): void { throw new RuntimeException('Invalid SQL placeholder alignment.'); }
function get_current_blog_id(): int { return 7; }
function is_multisite(): bool { return false; }
function get_site_option(string $name, mixed $default = false): mixed {
    return $name === 'smartcloud_static_publisher_content_journal_schema' ? '3' : $default;
}
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function absint(mixed $value): int { return abs((int) $value); }
function __(string $text, string $domain = ''): string { return $text; }
function mysql2date(string $format, string $date, bool $translate): string { return $date; }
class WP_Post_Type {
    public bool $public = true;
    public function __construct(public string $name) {}
}
function get_post_type_object(string $type): ?WP_Post_Type {
    return in_array($type, array('post', 'page'), true) ? new WP_Post_Type($type) : null;
}
function is_post_type_viewable(WP_Post_Type $type): bool { return $type->public; }
class WP_REST_Request {
    public function __construct(private array $data) {}
    public function get_json_params(): array { return $this->data; }
}
class WP_REST_Response {
    public function __construct(private array $data, private int $status) {}
    public function get_status(): int { return $this->status; }
    public function get_data(): array { return $this->data; }
}
final class ContentSyncRealPrepareFixture extends wpdb {
    public array $sql = array();
    public array $consumer = array();
    public function __construct() { $this->prefix = 'wp_'; }
    public function _real_escape($value) { return addslashes((string) $value); }
    public function add_placeholder_escape($query) { return $query; }
    public function get_row($query = null, $output = OBJECT, $y = 0) { return $this->consumer; }
    public function get_results($query = null, $output = OBJECT) { $this->sql[] = $query; return array(); }
}
function sql_expect(bool $value, string $message): void {
    if (!$value) { throw new RuntimeException($message); }
}
$wpdb = new ContentSyncRealPrepareFixture();
$journal = (new ReflectionClass(SmartCloud\WPSuite\StaticPublisher\ContentChangeJournal::class))->newInstanceWithoutConstructor();
foreach (array(false, true) as $subsites) {
    foreach (array(array('post'), array('post', 'page')) as $types) {
        $wpdb->sql = array();
        $wpdb->consumer = array(
            'consumer_id' => 'publisher-main', 'root_blog_id' => 7,
            'include_subsites' => $subsites, 'scope_fingerprint' => str_repeat('a', 64),
            'baseline_id' => 'baseline-1', 'sequence' => 20,
            'post_types' => json_encode($types), 'acknowledged_gmt' => '2026-10-12 12:00:00',
        );
        $response = $journal->handleEvents(new WP_REST_Request(array(
            'consumerId' => 'publisher-main', 'scopeFingerprint' => str_repeat('a', 64),
            'baselineId' => 'baseline-1', 'postTypes' => $types, 'includeSubsites' => $subsites,
            'afterSequence' => 11, 'throughSequence' => 93, 'limit' => 17,
        )));
        sql_expect($response->get_status() === 200 && count($wpdb->sql) === 1, 'A verified consumer must execute exactly one bounded event read.');
        $sql = $wpdb->sql[0];
        sql_expect(str_contains($sql, 'FROM `wp_smartcloud_static_publisher_content_events`'), 'Event table identifier must use real wpdb %i escaping.');
        $sorted = $types; sort($sorted);
        sql_expect(str_contains($sql, "post_type IN ('" . implode("', '", $sorted) . "') OR operation = 'resource'"), 'Every sorted post type binds before scope/cursor/limit values.');
        sql_expect(str_contains($sql, 'AND blog_id = 7') === !$subsites, 'Local-only reads retain their exact blog restriction.');
        sql_expect(str_contains($sql, 'sequence > 11 AND sequence <= 93') && str_contains($sql, 'ORDER BY sequence ASC LIMIT 17'), 'Cursor, ordering and bounded limit semantics remain unchanged.');
        sql_expect(!preg_match('/%[ids]/', $sql), 'No unresolved SQL placeholders reach execution.');
        sql_expect($response->get_data()['nextSequence'] === 11 && !$response->get_data()['hasMore'], 'Empty-page cursor semantics are preserved.');
    }
}
echo "Publisher real WordPress SQL identifier/value alignment: PASS\n";

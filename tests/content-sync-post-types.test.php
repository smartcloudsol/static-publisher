<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

class WP_Post_Type
{
    public object $labels;
    public bool $has_archive = false;
    public bool $hierarchical = false;
    public function __construct(
        public string $name,
        public bool $public,
        public bool $publicly_queryable,
        public bool $_builtin = false,
    ) {
        $this->labels = (object) array('singular_name' => ucfirst($name));
    }
}
class WP_REST_Response
{
    public function __construct(private array $data, private int $status) {}
    public function get_data(): array { return $this->data; }
}
function get_post_type_object(string $name): ?WP_Post_Type { return $GLOBALS['post_types'][$name] ?? null; }
function get_post_types(array $args, string $output): array
{
    return array_filter($GLOBALS['post_types'], static function ($type) use ($args) {
        foreach ($args as $key => $value) {
            if ($type->$key !== $value) return false;
        }
        return true;
    });
}
// Match WordPress core's built-in versus custom post-type visibility semantics.
function is_post_type_viewable(WP_Post_Type $type): bool
{
    return !in_array($type->name, $GLOBALS['hidden_types'] ?? array(), true)
        && ($type->publicly_queryable || ($type->_builtin && $type->public));
}
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function sanitize_text_field(string $value): string { return strip_tags($value); }
function is_multisite(): bool { return false; }
function is_plugin_active_for_network(string $plugin): bool { return false; }
function post_type_expect(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException($message);
}

require dirname(__DIR__) . '/includes/class-content-change-journal.php';
require dirname(__DIR__) . '/admin/php/admin.php';

use SmartCloud\WPSuite\StaticPublisher\ContentChangeJournal;
use SmartCloud\WPSuite\StaticPublisher\Admin\Admin;

$GLOBALS['post_types'] = array(
    'post' => new WP_Post_Type('post', true, true, true),
    'page' => new WP_Post_Type('page', true, false, true),
    'attachment' => new WP_Post_Type('attachment', true, true, true),
    'revision' => new WP_Post_Type('revision', false, false, true),
    'nav_menu_item' => new WP_Post_Type('nav_menu_item', false, false, true),
    'case_study' => new WP_Post_Type('case_study', true, true),
    'private_record' => new WP_Post_Type('private_record', false, true),
    'nonqueryable' => new WP_Post_Type('nonqueryable', true, false),
    'filtered' => new WP_Post_Type('filtered', true, true),
);
$GLOBALS['hidden_types'] = array('filtered');
$admin = (new ReflectionClass(Admin::class))->newInstanceWithoutConstructor();
$items = $admin->handleGetContentSyncPostTypes()->get_data()['items'];
$slugs = array_column($items, 'slug');
sort($slugs);
post_type_expect($slugs === array('case_study', 'page', 'post'), 'Selector must include pages and public CPTs, excluding media, private, and hidden types.');
$journal = (new ReflectionClass(ContentChangeJournal::class))->newInstanceWithoutConstructor();
$sanitize = new ReflectionMethod(ContentChangeJournal::class, 'sanitizePostTypes');
$isJournal = new ReflectionMethod(ContentChangeJournal::class, 'isJournalPostType');
foreach ($GLOBALS['post_types'] as $name => $type) {
    post_type_expect($isJournal->invoke($journal, $name) === in_array($name, $slugs, true), "Selector/journal disagree for {$name}.");
}
post_type_expect($sanitize->invoke($journal, array('post', 'page', 'case_study')) === array('case_study', 'page', 'post'), 'Normal publish baseline scope containing page must be accepted.');
post_type_expect($sanitize->invoke($journal, array('post', 'attachment')) === array(), 'Unsupported media scope must fail closed.');
post_type_expect($sanitize->invoke($journal, array('missing')) === array(), 'Unknown types must fail closed.');
echo "Content-sync selector, journal, and baseline post-type checks passed.\n";

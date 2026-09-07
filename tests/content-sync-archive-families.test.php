<?php

declare(strict_types=1);

// Exercise the real journal with a site's routing provider and isolated WordPress state.
define('ABSPATH', __DIR__ . '/');

class WP_Post
{
    public int $ID = 42;
    public string $post_type = 'review';
    public int $post_author = 7;
    public string $post_status = 'publish';
    public string $post_date_gmt = '2026-09-07 09:00:00';
    public string $post_date = '2026-09-07 09:00:00';
    public string $post_modified_gmt = '2026-09-07 09:52:52';
    public string $post_modified = '2026-09-07 09:52:52';
}
class WP_Post_Type
{
    public bool $public = true;
    public function __construct(public string $name, public bool $has_archive = true) {}
}
class WP_Taxonomy
{
    public bool $public = true;
    public function __construct(public string $name) {}
}
class WP_Term
{
    public function __construct(public int $term_id, public string $taxonomy, public string $slug) {}
}
class WP_Query
{
    public int $found_posts;
    public int $max_num_pages;
    public function __construct(array $args)
    {
        $GLOBALS['queries'][] = $args;
        $termId = $args['tax_query'][0]['terms'][0] ?? 0;
        $this->found_posts = $GLOBALS['counts'][$args['post_type']][$termId] ?? 0;
        $this->max_num_pages = (int) ceil($this->found_posts / $args['posts_per_page']);
    }
}
class WP_Rewrite { public string $pagination_base = 'page'; }
class ArchiveTestDatabase
{
    public string $prefix = 'wp_';
    public string $term_taxonomy = 'wp_term_taxonomy';
    public array $inserted = array();
    public function prepare(string $query, array $values): string { return $query; }
    public function get_col(string $query): array { return array(11); }
    public function insert(string $table, array $data, array $formats): void { $this->inserted[] = $data; }
}
function apply_filters(string $name, $value, ...$args)
{
    return isset($GLOBALS['filters'][$name]) ? ($GLOBALS['filters'][$name])($value, ...$args) : $value;
}
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function absint($value): int { return abs((int) $value); }
function esc_url_raw(string $value, array $protocols = array('http', 'https')): string
{
    return filter_var($value, FILTER_VALIDATE_URL) && in_array(parse_url($value, PHP_URL_SCHEME), $protocols, true) ? $value : '';
}
function wp_parse_url(string $url, int $component = -1) { return parse_url($url, $component); }
function trailingslashit(string $value): string { return rtrim($value, '/') . '/'; }
function home_url(string $path = ''): string { return 'https://site' . get_current_blog_id() . '.example' . $path; }
function get_current_blog_id(): int { return $GLOBALS['blog_id']; }
function is_multisite(): bool { return $GLOBALS['multisite']; }
function get_site(int $id): ?object { return in_array($id, array(1, 2), true) ? (object) array('blog_id' => $id) : null; }
function switch_to_blog(int $id): bool { $GLOBALS['blog_stack'][] = get_current_blog_id(); $GLOBALS['blog_id'] = $id; return true; }
function restore_current_blog(): bool { $GLOBALS['blog_id'] = array_pop($GLOBALS['blog_stack']); return true; }
function get_post_type_object(string $name): ?WP_Post_Type
{
    return in_array($name, array('post', 'review'), true) ? new WP_Post_Type($name, $name === 'review') : null;
}
function is_post_type_viewable(WP_Post_Type $type): bool { return $type->public; }
function get_post_type_archive_link(string $type): string { return home_url('/reviews/'); }
function get_author_posts_url(int $id): string { return home_url('/blog/author/' . $id . '/'); }
function get_year_link(int $year): string { return home_url('/blog/' . $year . '/'); }
function get_month_link(int $year, int $month): string { return get_year_link($year) . $month . '/'; }
function get_day_link(int $year, int $month, int $day): string { return get_month_link($year, $month) . $day . '/'; }
function get_option(string $name, $default = false)
{
    return array('posts_per_page' => 10, 'permalink_structure' => '/blog/%postname%/', 'page_for_posts' => 0)[$name] ?? $default;
}
function mysql2date(string $format, string $date, bool $translate): string { return gmdate($format, strtotime($date . ' UTC')); }
function get_object_taxonomies(string $postType, string $output): array { return array(new WP_Taxonomy('post_tag')); }
function wp_get_object_terms(int $postId, string $taxonomy): array
{
    $GLOBALS['membership_reads']++;
    return array(new WP_Term(12, 'post_tag', 'current-term'));
}
function is_wp_error($value): bool { return false; }
function get_term_link(WP_Term $term): string { return home_url('/blog/tag/' . $term->slug . '/'); }
function get_term(int $termId, string $taxonomy): WP_Term { return new WP_Term($termId, $taxonomy, 'removed-term'); }
function get_post(int $postId): WP_Post { return new WP_Post(); }
function get_taxonomy(string $taxonomy): WP_Taxonomy { return new WP_Taxonomy($taxonomy); }
function get_permalink($post): string { return home_url('/reviews/item/'); }
function get_site_option(string $name, $default = false) { return $name === 'smartcloud_static_publisher_content_journal_schema' ? '3' : $default; }
function current_time(string $type, bool $gmt): string { return '2026-09-07 09:52:52'; }
function wp_json_encode($value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
function archive_expect(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException($message);
    $GLOBALS['assertions']++;
}

require dirname(__DIR__) . '/includes/class-content-change-journal.php';
use SmartCloud\WPSuite\StaticPublisher\ContentChangeJournal;

$GLOBALS['blog_id'] = 1;
$GLOBALS['multisite'] = false;
$GLOBALS['blog_stack'] = array();
$GLOBALS['filters'] = array();
$GLOBALS['membership_reads'] = 0;
$GLOBALS['assertions'] = 0;
$GLOBALS['queries'] = array();
$GLOBALS['wp_rewrite'] = new WP_Rewrite();
$GLOBALS['wpdb'] = new ArchiveTestDatabase();
$journal = (new ReflectionClass(ContentChangeJournal::class))->newInstanceWithoutConstructor();
$invoke = static fn(string $method, ...$args) => (new ReflectionMethod(ContentChangeJournal::class, $method))->invoke($journal, ...$args);
$hook = 'smartcloud_static_publisher_content_archive_families';
$provider = static function (array $families, array $context): array {
    $GLOBALS['contexts'][] = array('context' => $context, 'currentBlog' => get_current_blog_id());
    foreach ($families as &$family) {
        if ($family['kind'] === 'taxonomy' && $context['postType'] === 'review') {
            foreach ($context['terms'] as $term) {
                if ($term['termId'] !== $family['termId']) continue;
                // Legacy events have only their captured URL; never consult current membership.
                $slug = $term['slug'] ?? basename(trim((string) parse_url($term['url'], PHP_URL_PATH), '/'));
                $family['url'] = home_url('/reviews/tags/' . $slug . '/');
                $family['postType'] = 'review';
            }
        } elseif (in_array($family['kind'], array('author', 'date'), true)) {
            $family['postType'] = 'post';
        }
    }
    unset($family);
    return $families;
};
$GLOBALS['filters'][$hook] = $provider;
$oldTerm = array('taxonomy' => 'post_tag', 'termId' => 11, 'url' => home_url('/blog/tag/removed-term/'));
$newTerm = array('taxonomy' => 'post_tag', 'termId' => 12, 'slug' => 'current-term', 'url' => home_url('/blog/tag/current-term/'));
$post = new WP_Post();
$beforeFamilies = $invoke('archiveFamilyProjection', $post, array($oldTerm));
$afterFamilies = $invoke('archiveFamilyProjection', $post, array($newTerm));
$taxonomies = static fn(array $families): array => array_values(array_filter($families, static fn(array $f): bool => $f['kind'] === 'taxonomy'));
archive_expect($taxonomies($beforeFamilies)[0]['url'] === home_url('/reviews/tags/removed-term/'), 'Before projection must route its captured, removed term.');
archive_expect($taxonomies($afterFamilies)[0]['url'] === home_url('/reviews/tags/current-term/'), 'After projection must route its new term.');
archive_expect($GLOBALS['contexts'][0]['context'] === array('postType' => 'review', 'postId' => 42, 'blogId' => 1, 'terms' => array($oldTerm)), 'Provider must receive complete captured context.');
archive_expect($invoke('publicTermsForPost', $post)[0]['slug'] === 'current-term', 'Fresh term projections must retain the slug.');
$journal->captureTermChange(42, array('current-term'), array(112), 'post_tag', false, array(111));
$captured = $GLOBALS['wpdb']->inserted[0];
$capturedBefore = json_decode($captured['before_projection'], true);
$capturedAfter = json_decode($captured['after_projection'], true);
archive_expect($captured['operation'] === 'taxonomy' && count($GLOBALS['wpdb']->inserted) === 1, 'Term replacement must append a single taxonomy journal event.');
archive_expect($taxonomies($capturedBefore['archiveFamilies'])[0]['url'] === home_url('/reviews/tags/removed-term/') && $capturedBefore['terms'][0]['slug'] === 'removed-term', 'Actual term-change capture must route the removed relationship and capture its slug.');
archive_expect($taxonomies($capturedAfter['archiveFamilies'])[0]['url'] === home_url('/reviews/tags/current-term/'), 'Actual term-change capture must separately route current membership.');
$membershipReads = $GLOBALS['membership_reads'];

$legacyFamily = array('kind' => 'taxonomy', 'url' => $oldTerm['url'], 'blogId' => 1, 'postType' => 'review', 'taxonomy' => 'post_tag', 'termId' => 11);
$legacyProjection = array('status' => 'publish', 'url' => home_url('/reviews/item/'), 'terms' => array($oldTerm), 'archiveFamilies' => array($legacyFamily), 'archives' => array($oldTerm['url'], home_url('/featured/')));
$afterProjection = $legacyProjection;
$afterProjection['terms'] = array($newTerm);
$afterProjection['archiveFamilies'][0]['url'] = $newTerm['url'];
$afterProjection['archiveFamilies'][0]['termId'] = 12;
$afterProjection['archives'] = array($newTerm['url']);
$row = array('sequence' => 406, 'blog_id' => 1, 'post_id' => 42, 'post_type' => 'review', 'recorded_gmt' => '2026-09-07 09:52:52', 'operation' => 'taxonomy', 'before_projection' => json_encode($legacyProjection), 'after_projection' => json_encode($afterProjection));
$event = $invoke('hydrateEventRow', $row);
archive_expect($event['before']['archiveFamilies'][0]['url'] === home_url('/reviews/tags/removed-term/'), 'Old journal rows must adapt before routes.');
archive_expect($event['after']['archiveFamilies'][0]['url'] === home_url('/reviews/tags/current-term/'), 'Old journal rows must adapt after routes independently.');
archive_expect($event['before']['archives'] === array(home_url('/reviews/tags/removed-term/'), home_url('/featured/')), 'Replay must replace old family URLs while preserving extra explicit archives.');
archive_expect($GLOBALS['membership_reads'] === $membershipReads, 'Replay must not read current term membership.');
$context = array('postType' => 'review', 'postId' => 42, 'blogId' => 1);
archive_expect($invoke('filterProjectionArchiveFamilies', $event['before'], $context) === $event['before'], 'Repeated projection routing must be idempotent.');
archive_expect($invoke('filterProjectionArchiveFamilies', null, $context) === null, 'Null before/after projection must remain null.');
$bareProjection = array('url' => home_url('/legacy/'), 'archives' => array(home_url('/featured/')));
archive_expect($invoke('filterProjectionArchiveFamilies', $bareProjection, $context) === $bareProjection, 'Older projections without family metadata must remain intact.');

$GLOBALS['multisite'] = true;
$subsiteProjection = $legacyProjection;
$subsiteProjection['archiveFamilies'][0]['blogId'] = 2;
$subsiteProjection['archiveFamilies'][0]['url'] = 'https://site2.example/blog/tag/removed-term/';
$subsiteProjection['terms'][0]['url'] = $subsiteProjection['archiveFamilies'][0]['url'];
$subsiteProjection['archives'] = array($subsiteProjection['archiveFamilies'][0]['url']);
$subsite = $invoke('filterProjectionArchiveFamilies', $subsiteProjection, array_merge($context, array('blogId' => 2)));
archive_expect($subsite['archiveFamilies'][0]['url'] === 'https://site2.example/reviews/tags/removed-term/', 'Subsite routing and URL validation must use the captured blog.');
archive_expect(get_current_blog_id() === 1 && $GLOBALS['blog_stack'] === array(), 'Successful replay must restore the original blog.');
$lastContext = end($GLOBALS['contexts']);
archive_expect($lastContext['currentBlog'] === 2 && $lastContext['context']['blogId'] === 2, 'Provider execution and declared context must agree on the subsite.');
$GLOBALS['filters'][$hook] = static function (): array { throw new RuntimeException('provider failure'); };
try {
    $invoke('filterProjectionArchiveFamilies', $subsiteProjection, array_merge($context, array('blogId' => 2)));
    throw new RuntimeException('Expected provider exception.');
} catch (RuntimeException $error) {
    archive_expect($error->getMessage() === 'provider failure', 'Provider exceptions must remain visible.');
}
archive_expect(get_current_blog_id() === 1 && $GLOBALS['blog_stack'] === array(), 'Failing replay must also restore the original blog.');
archive_expect($invoke('filterProjectionArchiveFamilies', $subsiteProjection, array_merge($context, array('blogId' => 99))) === $subsiteProjection, 'Missing subsites must not invoke providers in the wrong site.');
$GLOBALS['multisite'] = false;

$GLOBALS['filters'][$hook] = static fn(array $families): array => array(
    $families[0], $families[0], 'invalid',
    array_merge($families[0], array('url' => 'https://external.example/tag/')),
    array_merge($families[0], array('url' => 'https://site1.example:444/tag/')),
    array_merge($families[0], array('kind' => 'unrecognized'))
);
archive_expect($invoke('filterArchiveFamilies', array($legacyFamily), $context) == array($legacyFamily), 'Provider results must reject malformed, foreign-origin and unknown-kind families and deduplicate valid ones.');
$GLOBALS['filters'][$hook] = static fn() => false;
archive_expect($invoke('filterArchiveFamilies', array($legacyFamily), $context) == array($legacyFamily), 'Invalid provider return must preserve original valid families.');
$GLOBALS['filters'][$hook] = $provider;

archive_expect($invoke('isSameSiteUrl', 'https://site1.example/reviews/') === true, 'Same-origin HTTPS URLs must be accepted.');
archive_expect($invoke('isSameSiteUrl', 'HTTPS://SITE1.EXAMPLE:443/wordpress/reviews/') === true, 'Scheme and host case plus an explicit default port and subdirectory path must preserve the same origin.');
archive_expect($invoke('isSameSiteUrl', 'http://site1.example/reviews/') === false, 'HTTP must not match an HTTPS site even when the host and implicit port syntax look compatible.');
archive_expect($invoke('isSameSiteUrl', 'http://site1.example:443/reviews/') === false, 'HTTP with a misleading HTTPS port must not match an HTTPS origin.');
archive_expect($invoke('isSameSiteUrl', 'https://site1.example:80/reviews/') === false, 'HTTPS with a misleading HTTP port must not match an HTTPS origin.');
archive_expect($invoke('isSameSiteUrl', 'https://editor@site1.example/reviews/') === false, 'URLs with a username must be rejected.');
archive_expect($invoke('isSameSiteUrl', 'https://editor:secret@site1.example/reviews/') === false, 'URLs with username and password userinfo must be rejected.');

// The planner relies on foundPosts=0 for safe removal of archives that now 404.
$GLOBALS['counts'] = array('post' => array(11 => 0), 'review' => array(11 => 1, 12 => 23));
$blogFamily = array_merge($legacyFamily, array('postType' => 'post'));
$blog = $invoke('resolveArchiveFamilyPagesForCurrentBlog', $blogFamily);
$scoped = $invoke('resolveArchiveFamilyPagesForCurrentBlog', $taxonomies($beforeFamilies)[0]);
archive_expect($blog['foundPosts'] === 0 && $scoped['foundPosts'] === 1, 'A CPT-only term must be empty in the native blog query and nonempty in its CPT archive.');
archive_expect($blog['pageUrls'] === array($oldTerm['url']) && $blog['maxPages'] === 1, 'An empty archive must retain its root URL for the exporter tombstone path.');
$paginated = $invoke('resolveArchiveFamilyPagesForCurrentBlog', $taxonomies($afterFamilies)[0]);
archive_expect($paginated['foundPosts'] === 23 && $paginated['maxPages'] === 3, 'Scoped query must count published CPT posts with the site page size.');
archive_expect($paginated['pageUrls'] === array(home_url('/reviews/tags/current-term/'), home_url('/reviews/tags/current-term/page/2/'), home_url('/reviews/tags/current-term/page/3/')), 'All CPT archive pages must use the corrected route.');
$GLOBALS['counts']['review'][11] = 0;
$removed = $invoke('resolveArchiveFamilyPagesForCurrentBlog', $taxonomies($beforeFamilies)[0]);
archive_expect($removed['foundPosts'] === 0 && $removed['pageUrls'] === array(home_url('/reviews/tags/removed-term/')), 'Removing the last CPT term member must expose foundPosts=0 at the scoped URL for deletion.');
$lastQuery = end($GLOBALS['queries']);
archive_expect($lastQuery['post_type'] === 'review' && $lastQuery['post_status'] === 'publish' && $lastQuery['tax_query'][0] === array('taxonomy' => 'post_tag', 'field' => 'term_id', 'terms' => array(11)), 'Archive counts must query the routed type and exact captured taxonomy term.');

// A renamed term keeps its ID. Counting that ID for its former URL would make a
// real 404 mandatory; a route-aware provider must be able to count the old slug.
$queryHook = 'smartcloud_static_publisher_content_archive_query_args';
$GLOBALS['counts']['review'] = array(11 => 9, 'renamed-term' => 23);
$GLOBALS['filters'][$queryHook] = static function (array $args, array $family): array {
    $slug = basename(trim((string) parse_url($family['url'], PHP_URL_PATH), '/'));
    $args['tax_query'][0]['field'] = 'slug';
    $args['tax_query'][0]['terms'] = array($slug);
    return $args;
};
$oldSlug = $invoke('resolveArchiveFamilyPagesForCurrentBlog', $taxonomies($beforeFamilies)[0]);
archive_expect($oldSlug['foundPosts'] === 0 && $oldSlug['maxPages'] === 1, 'Former term slug must resolve as empty even though its unchanged term ID still has posts.');
$lastQuery = end($GLOBALS['queries']);
archive_expect($lastQuery['tax_query'][0]['field'] === 'slug' && $lastQuery['tax_query'][0]['terms'] === array('removed-term'), 'Provider query arguments must reach WP_Query unchanged.');
$renamedFamily = array_merge($taxonomies($beforeFamilies)[0], array('url' => home_url('/reviews/tags/renamed-term/')));
$renamed = $invoke('resolveArchiveFamilyPagesForCurrentBlog', $renamedFamily);
archive_expect($renamed['foundPosts'] === 23 && $renamed['pageUrls'][2] === home_url('/reviews/tags/renamed-term/page/3/'), 'Current renamed route must retain accurate counts and pagination.');
$GLOBALS['filters'][$queryHook] = static fn() => false;
$fallbackQuery = $invoke('resolveArchiveFamilyPagesForCurrentBlog', $taxonomies($beforeFamilies)[0]);
archive_expect($fallbackQuery['foundPosts'] === 9 && end($GLOBALS['queries'])['tax_query'][0]['field'] === 'term_id', 'Invalid query provider return must fall back to the journal query.');
echo 'Content-sync archive routing and historical replay checks passed (' . $GLOBALS['assertions'] . " assertions).\n";

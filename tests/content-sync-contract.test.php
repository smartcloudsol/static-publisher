<?php

declare(strict_types=1);

function content_sync_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/smartcloud-static-publisher.php');
$journal = file_get_contents($root . '/includes/class-content-change-journal.php');
$adminMain = file_get_contents($root . '/admin/src/main.tsx');
$adminPhp = file_get_contents($root . '/admin/php/admin.php');
$adminPaidConfig = file_get_contents($root . '/admin/src/paid-features/config.ts');

content_sync_expect(
    is_string($plugin) && is_string($journal) && is_string($adminMain) && is_string($adminPhp) && is_string($adminPaidConfig),
    'Content-sync sources must be readable.'
);
content_sync_expect(str_contains($journal, "add_action('wp_after_insert_post'"), 'Published post writes must enter the journal.');
content_sync_expect(str_contains($journal, "add_action('wp_trash_post'"), 'Trash transitions must capture the public projection before WordPress mutates the slug.');
content_sync_expect(str_contains($journal, "add_action('untrash_post'"), 'Restore transitions must prepare the last public slug before WordPress removes its desired-slug metadata.');
content_sync_expect(str_contains($journal, "add_action('untrashed_post'"), 'Restore transitions must repair a leaked trash alias after WordPress completes the restore.');
content_sync_expect(str_contains($journal, "add_action('before_delete_post'"), 'Published post deletion must enter the journal.');
content_sync_expect(str_contains($journal, "add_action('set_object_terms'"), 'Taxonomy changes must enter the journal.');
content_sync_expect(str_contains($journal, "add_action('update_option_sticky_posts'"), 'Sticky-state changes must enter the journal.');
content_sync_expect(str_contains($journal, "add_action('add_option_sticky_posts'"), 'Initial sticky-state creation must enter the journal.');
content_sync_expect(str_contains($journal, "add_action('delete_option_sticky_posts'"), 'Sticky-state removal must enter the journal.');
content_sync_expect(str_contains($journal, "'/content-sync/head'"), 'The journal head endpoint must be registered.');
content_sync_expect(str_contains($journal, "'/content-sync/events'"), 'The ordered event-range endpoint must be registered.');
content_sync_expect(str_contains($journal, "'/content-sync/ack'"), 'The monotonic acknowledgement endpoint must be registered.');
content_sync_expect(str_contains($journal, "'/content-sync/baseline'"), 'The verified release baseline endpoint must be registered.');
content_sync_expect(str_contains($journal, "'/content-sync/impact'"), 'The archive impact endpoint must be registered.');
content_sync_expect(str_contains($journal, "'/content-sync/fingerprint'"), 'The release fingerprint endpoint must be registered.');
content_sync_expect(str_contains($journal, 'smartcloud_static_publisher_content_sync_consumers'), 'Consumer cursors must use dedicated durable storage.');
content_sync_expect(str_contains($journal, "'expectedSequence'"), 'Consumer acknowledgement must require the expected committed cursor.');
content_sync_expect(str_contains($journal, 'sequence = %d'), 'Conditional acknowledgement must compare the stored cursor.');
content_sync_expect(str_contains($journal, "'cursor-conflict'"), 'Concurrent or regressing acknowledgement must return a cursor conflict.');
content_sync_expect(str_contains($journal, "'baselineId'"), 'Journal reads and acknowledgements must bind to a verified baseline.');
content_sync_expect(str_contains($journal, "'invalid-baseline-sequence'"), 'Normal releases must establish only their captured pre-crawl journal cutoff.');
content_sync_expect(str_contains($journal, "'baseline-required'"), 'Scope changes must require a new baseline.');
content_sync_expect(str_contains($journal, "'pageUrls' => \$pageUrls"), 'Archive impact responses must contain the complete page URL set.');
content_sync_expect(str_contains($journal, "'maxPages' => \$maxPages"), 'Archive impact responses must expose the current page count.');
content_sync_expect(str_contains($journal, "'paginationBase' => \$this->paginationBase()"), 'Archive impact responses must expose the WordPress pagination base.');
content_sync_expect(str_contains($journal, "'foundPosts' => max(0, (int) \$query->found_posts)"), 'Archive impact responses must expose query membership totals.');
content_sync_expect(str_contains($journal, 'preTrashProjections[$postId]'), 'Unpublish events must use the pre-trash permalink projection.');
content_sync_expect(str_contains($journal, 'latestPublicProjection($postId)'), 'Trash transitions must recover the last durable public projection when WordPress has already mutated the slug.');
content_sync_expect(str_contains($journal, 'projectionUsesTrashedSlug'), 'Trash aliases must never become public deletion projections.');
content_sync_expect(str_contains($journal, 'LAST_PUBLIC_PROJECTION_META'), 'The exact public projection must survive across separate trash and restore requests.');
content_sync_expect(str_contains($journal, 'LAST_PUBLIC_SLUG_META'), 'The last public slug must survive repeated restore and publish cycles.');
content_sync_expect(str_contains($journal, 'renderDependencyFingerprintExtensions'), 'Trusted release-bound asset reuse must be protected by the release fingerprint.');
content_sync_expect(str_contains($journal, "'woff2'"), 'Release fingerprints must cover plugin and theme font assets.');
content_sync_expect(str_contains($journal, "'svg'"), 'Release fingerprints must cover plugin and theme image assets.');
content_sync_expect(str_contains($journal, 'blog_id bigint(20) unsigned'), 'The durable journal must store multisite blog identity.');
content_sync_expect(str_contains($journal, "'blogId' => (int) get_current_blog_id()"), 'Public projections must preserve multisite identity.');
content_sync_expect(str_contains($journal, 'include_subsites'), 'Consumer baselines must bind the optional subsite scope.');
content_sync_expect(str_contains($journal, 'network-activation-required'), 'Network-wide tracking must fail closed without network activation.');
content_sync_expect(str_contains($journal, 'subsite-origin-unsupported'), 'Network-wide tracking must fail closed for a separate subsite origin.');
content_sync_expect(str_contains($plugin, "'familyGeneration' => \$this->buildArchiveFamilyGeneration"), 'Archive tokens must cover complete pagination-family membership.');
content_sync_expect(str_contains($plugin, "smartcloud_static_publisher_listing_change_token_data"), 'Custom listing providers need a stable extension contract.');
content_sync_expect(str_contains($plugin, "'listings' => \$listingDependency"), 'Singular route tokens must include dynamic listing dependencies.');
content_sync_expect(str_contains($plugin, 'resolveNetworkBlogIdForChangeTokenUrl'), 'Main-site token requests must resolve same-network subsite URLs.');
content_sync_expect(str_contains($plugin, 'switch_to_blog($blogId)'), 'Subsite token generation must run in the owning blog context.');
content_sync_expect(str_contains($plugin, "new \\WP_Rewrite()"), 'Subsite token generation must use the owning blog rewrite rules.');
content_sync_expect(str_contains($plugin, 'restore_current_blog()'), 'Subsite token generation must always restore the main-site context.');
content_sync_expect(str_contains($plugin, 'buildMediaLibraryFileChangeTokenItem'), 'Media Library files need stable same-site change tokens for trusted asset reuse.');
content_sync_expect(str_contains($plugin, "'tokenSource' => 'wp-media-file'"), 'Media Library tokens must expose a machine-readable source.');
content_sync_expect(!str_contains($adminMain, 'siteSettings?.subscriber === true'), 'Admin access must not trust the coarse subscriber flag.');
content_sync_expect(!str_contains($adminPaidConfig, 'siteSettings?.subscriber === true'), 'Paid admin access must require an exact subscription type.');
content_sync_expect(str_contains($adminPaidConfig, 'subscriptionType === "PROFESSIONAL"'), 'Professional subscriptions must enable paid publishing modes.');
content_sync_expect(str_contains($adminPaidConfig, 'subscriptionType === "AGENCY"'), 'Agency subscriptions must enable paid publishing modes.');
content_sync_expect(str_contains($adminPhp, 'abandonQueuedContentSyncState'), 'Deleting a content-sync retry must use controlled abandonment.');
content_sync_expect(str_contains($adminPhp, "'journalCursorPreserved' => true"), 'Content-sync abandonment must preserve journal cursor ownership.');
content_sync_expect(str_contains($adminMain, 'Abandon retry'), 'The admin must distinguish content-sync abandonment from ordinary deletion.');
content_sync_expect(str_contains($adminMain, 'New baseline required'), 'The admin must show an actionable stale-baseline state.');
content_sync_expect(str_contains($adminMain, 'baseline stale'), 'The admin must distinguish stale and ready baselines.');
content_sync_expect(str_contains($adminMain, 'switchControlStyles'), 'Admin switches must share the WordPress-safe input and cursor styles.');
content_sync_expect(str_contains($adminMain, 'WebkitAppearance: "none"'), 'WordPress native checkbox styling must not leak through Mantine switches.');

fwrite(STDOUT, "Content-sync journal and incremental listing contracts passed.\n");

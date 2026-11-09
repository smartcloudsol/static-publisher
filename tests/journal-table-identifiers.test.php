<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
function is_multisite(): bool { return false; }
$GLOBALS['wpdb'] = (object) array('prefix' => 'wp_');
require __DIR__ . '/../includes/class-journal-retention.php';
$instance = new SmartCloud\WPSuite\StaticPublisher\JournalRetention();
$table = new ReflectionMethod($instance, 'table');
if ($table->invoke($instance, 'content_events') !== 'wp_smartcloud_static_publisher_content_events') throw new RuntimeException('Valid journal table changed');
foreach (array("wp_`; DROP TABLE x; --", 'wp space') as $prefix) {
    $GLOBALS['wpdb']->prefix = $prefix;
    try { $table->invoke($instance, 'content_events'); throw new RuntimeException('Unsafe table prefix accepted'); } catch (RuntimeException $error) { if ($error->getMessage() !== 'Unsafe journal table identifier.') throw $error; }
}
$GLOBALS['wpdb']->prefix = 'wp_';
try { $table->invoke($instance, 'bad_suffix'); throw new RuntimeException('Unknown table suffix accepted'); } catch (RuntimeException $error) { if ($error->getMessage() !== 'Unsafe journal table identifier.') throw $error; }
echo "Journal table identifiers reject injection and unknown tables.\n";

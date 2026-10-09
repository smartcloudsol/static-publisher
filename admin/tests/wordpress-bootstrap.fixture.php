<?php
declare(strict_types=1);

// Use WordPress's real script registry and concatenation, without a database.
namespace {
    define('ABSPATH', $argv[1] . '/wordpress/');
    define('SMARTCLOUD_STATIC_PUBLISHER_PATH', $argv[2] . '/');
    define('SMARTCLOUD_STATIC_PUBLISHER_URL', 'https://example.test/publisher/');
    require ABSPATH . 'wp-includes/plugin.php';
    require ABSPATH . 'wp-includes/class-wp-dependency.php';
    require ABSPATH . 'wp-includes/class-wp-dependencies.php';
    require ABSPATH . 'wp-includes/class-wp-scripts.php';
    require ABSPATH . 'wp-includes/functions.wp-scripts.php';
    function __($value, $domain = '') { return $value; }
    function admin_url($path) { return '/wp-admin/' . $path; }
    function wp_enqueue_style(...$args) {}
    function wp_json_encode($value, $options = 0) { return json_encode($value, $options); }
    function rest_url($path) { return '/publisher'; }
    function wp_create_nonce($action) { return 'fixture'; }
    function _doing_it_wrong($function, $message, $version) { throw new \RuntimeException($message); }
    do_action('init');
}
namespace SmartCloud\WPSuite\StaticPublisher {
    const VERSION = 'fixture';
    class Plugin {
        public function getWpSuiteRuntimeConfig() { return array('apiBase' => '/api'); }
        public function getConfig() { return array('targetOrigin' => 'https://example.test/'); }
        public function getRuntimeRelativePaths() { return array(); }
    }
}
namespace {
    require dirname(__DIR__) . '/php/admin.php';
    $admin = new \SmartCloud\WPSuite\StaticPublisher\Admin\Admin(new \SmartCloud\WPSuite\StaticPublisher\Plugin());
    $entry = $admin->registerHubCapabilities(array())[0];
    // This is the real Hub invocation order on an integrated product page.
    ($entry['surface_enqueue'])();
    ($entry['detail_enqueue'])();
    $scripts = wp_scripts();
    $handle = 'smartcloud-static-publisher-admin';
    echo json_encode(array(
        'inline' => $scripts->get_inline_script_data($handle, 'before'),
        'count' => count(array_filter($scripts->get_data($handle, 'before'), 'is_string')),
        'queue' => $scripts->queue,
    ));
}

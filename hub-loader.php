<?php

namespace SmartCloud\WPSuite\Hub;

if (!defined('ABSPATH')) {
    exit;
}

const SMARTCLOUD_WPSUITE_STATIC_PUBLISHER_HUB_VERSION = '2.5.3';

final class StaticPublisherHubLoader
{
    private static ?StaticPublisherHubLoader $instance = null;

    private string $plugin;

    private string $text_domain;

    /** @var object|null */
    private $admin = null;

    private function __construct(string $plugin, string $text_domain)
    {
        $this->plugin = $plugin;
        $this->text_domain = $text_domain;
        $this->includes();
    }

    public static function instance(string $plugin, string $text_domain): StaticPublisherHubLoader
    {
        return self::$instance ?? (self::$instance = new self($plugin, $text_domain));
    }

    public function init(): void
    {
        if (!isset($this->admin)) {
            return;
        }

        add_action('admin_menu', array($this, 'createAdminMenu'), 10);

        if (method_exists($this->admin, 'init')) {
            $this->admin->init();
        }
    }

    public function createAdminMenu(): void
    {
        if (!isset($this->admin)) {
            return;
        }

        $iconUrl = method_exists($this->admin, 'getIconUrl') ? $this->admin->getIconUrl() : 'dashicons-cloud';

        add_menu_page(
            __('SmartCloud', 'smartcloud-static-publisher'),
            __('SmartCloud', 'smartcloud-static-publisher'),
            'manage_options',
            SMARTCLOUD_WPSUITE_SLUG,
            null,
            $iconUrl,
            58
        );

        $connectSuffix = add_submenu_page(
            SMARTCLOUD_WPSUITE_SLUG,
            __('Connect your Site to WP Suite', 'smartcloud-static-publisher'),
            __('Connect your Site', 'smartcloud-static-publisher'),
            'manage_options',
            SMARTCLOUD_WPSUITE_SLUG,
            array($this->admin, 'renderAdminPage')
        );

        $settingsSuffix = add_submenu_page(
            SMARTCLOUD_WPSUITE_SLUG,
            __('WPSuite General Settings', 'smartcloud-static-publisher'),
            __('Global Settings', 'smartcloud-static-publisher'),
            'manage_options',
            SMARTCLOUD_WPSUITE_SLUG . '-settings',
            array($this->admin, 'renderAdminPage')
        );

        if (method_exists($this->admin, 'enqueueAdminScripts')) {
            $this->admin->enqueueAdminScripts($connectSuffix, $settingsSuffix);
        }
    }

    public function check(): void
    {
        if (!isset($this->admin)) {
            return;
        }

        if (method_exists($this->admin, 'check')) {
            $this->admin->check();
        }
    }

    private function includes(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!empty($GLOBALS['smartcloud_wpsuite_menu_parent'])) {
            return false;
        }

        if (!defined('SMARTCLOUD_WPSUITE_SLUG')) {
            define('SMARTCLOUD_WPSUITE_SLUG', 'hub-for-wpsuiteio');
        }

        $ownerOption = SMARTCLOUD_WPSUITE_SLUG . '/top-menu-owner';
        $owner = get_option($ownerOption);
        $ownerVersion = get_option($ownerOption . '/version') ?? '1.0.0';

        $ownerMissing = empty($owner);
        $ownerIsMe = $owner === $this->plugin;

        $pluginDir = plugin_dir_path(__DIR__);
        $ownerPlugin = ltrim(str_replace('\\/', '/', wp_unslash((string) $owner)), '/\\');
        $ownerPluginPath = wp_normalize_path(untrailingslashit($pluginDir) . '/' . $ownerPlugin);
        $activeValidPlugins = array_map('wp_normalize_path', wp_get_active_and_valid_plugins());

        $ownerIsActive = !empty($ownerPlugin) && is_plugin_active($ownerPlugin);
        $ownerExists = !empty($ownerPlugin) && file_exists($ownerPluginPath);
        $ownerIsValid = in_array($ownerPluginPath, $activeValidPlugins, true);
        $ownerInactive = !$ownerIsActive || !$ownerIsValid || !$ownerExists;

        $ownerVersionIsSmaller = version_compare((string) $ownerVersion, SMARTCLOUD_WPSUITE_STATIC_PUBLISHER_HUB_VERSION) === -1;
        $ownerVersionEquals = version_compare((string) $ownerVersion, SMARTCLOUD_WPSUITE_STATIC_PUBLISHER_HUB_VERSION) === 0;

        if ($ownerMissing || $ownerIsMe || $ownerInactive || $ownerVersionIsSmaller) {
            $result = false;

            if (empty($GLOBALS['smartcloud_wpsuite_fallback_parent_added'])) {
                $GLOBALS['smartcloud_wpsuite_fallback_parent_added'] = true;
                $result = true;

                if (!defined('SMARTCLOUD_WPSUITE_VERSION')) {
                    define('SMARTCLOUD_WPSUITE_VERSION', SMARTCLOUD_WPSUITE_STATIC_PUBLISHER_HUB_VERSION);
                }
                if (!defined('SMARTCLOUD_WPSUITE_PATH')) {
                    define('SMARTCLOUD_WPSUITE_PATH', plugin_dir_path(__FILE__) . SMARTCLOUD_WPSUITE_SLUG . '/');
                }
                if (!defined('SMARTCLOUD_WPSUITE_URL')) {
                    define('SMARTCLOUD_WPSUITE_URL', plugin_dir_url(__FILE__) . SMARTCLOUD_WPSUITE_SLUG . '/');
                }
                if (!defined('SMARTCLOUD_WPSUITE_READY_HOOK')) {
                    define('SMARTCLOUD_WPSUITE_READY_HOOK', SMARTCLOUD_WPSUITE_SLUG . '/ready');
                }

                if (file_exists(SMARTCLOUD_WPSUITE_PATH . 'index.php')) {
                    require_once SMARTCLOUD_WPSUITE_PATH . 'index.php';
                }
                if (class_exists('\SmartCloud\WPSuite\Hub\HubAdmin')) {
                    $this->admin = new HubAdmin();
                }

                if (!$ownerIsMe || !$ownerVersionEquals) {
                    update_option($ownerOption, $this->plugin, false);
                    update_option($ownerOption . '/version', SMARTCLOUD_WPSUITE_STATIC_PUBLISHER_HUB_VERSION, false);
                }
            }

            if (!$ownerIsMe && $ownerVersionIsSmaller) {
                update_option($ownerOption, $this->plugin, false);
                update_option($ownerOption . '/version', SMARTCLOUD_WPSUITE_STATIC_PUBLISHER_HUB_VERSION, false);
            }

            return $result;
        }

        return false;
    }
}

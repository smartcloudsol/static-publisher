<?php
namespace SmartCloud\WPSuite\StaticPublisher;

if (!defined('ABSPATH')) exit;

/** Preview-bound, one-batch journal maintenance for authenticated local administrators. */
final class JournalAdmin
{
    private Plugin $plugin;

    public function __construct(Plugin $plugin) { $this->plugin = $plugin; }

    public function registerRoutes(): void
    {
        register_rest_route('smartcloud-static-publisher/v1', '/journal-retention', array(
            array('methods' => 'GET', 'permission_callback' => array($this, 'permission'), 'callback' => array($this, 'preview')),
            array('methods' => 'POST', 'permission_callback' => array($this, 'permission'), 'callback' => array($this, 'execute')),
        ));
    }

    public function permission(\WP_REST_Request $request)
    {
        if (!current_user_can('manage_options') || (is_multisite() && !current_user_can('manage_network_options'))) return new \WP_Error('journal_forbidden', __('Journal cleanup requires a site administrator, or a network administrator on multisite.', 'smartcloud-static-publisher'), array('status' => 403));
        if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) return new \WP_Error('journal_nonce', __('Reload this page before reviewing journal cleanup.', 'smartcloud-static-publisher'), array('status' => 403));
        return true;
    }

    private function settings(): array
    {
        return array(
            'days' => (int) apply_filters('smartcloud_static_publisher_journal_retention_days', get_site_option('smartcloud_static_publisher_journal_retention_days', 30)),
            'batchSize' => (int) apply_filters('smartcloud_static_publisher_journal_retention_batch_size', get_site_option('smartcloud_static_publisher_journal_retention_batch_size', 500)),
        );
    }

    private function identity(): array
    {
        $profile = BackendProfile::context();
        return array('userId' => get_current_user_id(), 'blogId' => get_current_blog_id(), 'networkId' => is_multisite() ? get_current_network_id() : 0,
            'profile' => $profile === null ? null : array_intersect_key($profile, array_flip(array('id', 'generation', 'bindingRevision', 'stateNamespace'))));
    }

    private function response(array $data): \WP_REST_Response
    {
        return new \WP_REST_Response($data, 200, array('Cache-Control' => 'no-store'));
    }

    private function failure(\Throwable $error): \WP_Error
    {
        if ($error instanceof \UnexpectedValueException) return new \WP_Error('journal_preview_changed', __('The preview expired or the journal or content-sync configuration changed. Review cleanup again.', 'smartcloud-static-publisher'), array('status' => 409));
        return new \WP_Error('journal_cleanup_unavailable', __('Journal cleanup is blocked because its safety checks could not be completed.', 'smartcloud-static-publisher'), array('status' => 503));
    }

    public function preview(\WP_REST_Request $request)
    {
        try {
            $settings = $this->settings();
            $report = (new JournalRetention($this->plugin))->prune($settings['days'], $settings['batchSize'], true);
            $payload = array('version' => 1, 'identity' => $this->identity(), 'settings' => $settings, 'fingerprint' => JournalRetention::previewFingerprint($report), 'expiresAt' => time() + 300);
            $encoded = base64_encode(wp_json_encode($payload, JSON_THROW_ON_ERROR));
            $token = $encoded . '.' . hash_hmac('sha256', $encoded, wp_salt('nonce'));
            return $this->response(array('report' => $report, 'confirmationToken' => $token, 'networkWide' => is_multisite(), 'wpCronDisabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON));
        } catch (\Throwable $error) { return $this->failure($error); }
    }

    public function execute(\WP_REST_Request $request)
    {
        try {
            $input = $request->get_json_params();
            if (!is_array($input) || ($input['confirmed'] ?? null) !== true || !is_string($input['confirmationToken'] ?? null) || strlen($input['confirmationToken']) > 8192) throw new \UnexpectedValueException('Fresh confirmation required.');
            $parts = explode('.', $input['confirmationToken']);
            if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], wp_salt('nonce')), $parts[1])) throw new \UnexpectedValueException('Invalid confirmation.');
            $bytes = base64_decode($parts[0], true);
            $payload = is_string($bytes) ? json_decode($bytes, true, 32, JSON_THROW_ON_ERROR) : null;
            $settings = $this->settings();
            if (!is_array($payload) || ($payload['version'] ?? null) !== 1 || ($payload['identity'] ?? null) !== $this->identity() || ($payload['settings'] ?? null) !== $settings
                || !is_int($payload['expiresAt'] ?? null) || $payload['expiresAt'] < time() || $payload['expiresAt'] > time() + 300 || !is_string($payload['fingerprint'] ?? null)) throw new \UnexpectedValueException('Confirmation changed or expired.');
            $report = (new JournalRetention($this->plugin))->prune($settings['days'], $settings['batchSize'], false, $payload['fingerprint']);
            return $this->response(array('report' => $report));
        } catch (\Throwable $error) { return $this->failure($error); }
    }
}

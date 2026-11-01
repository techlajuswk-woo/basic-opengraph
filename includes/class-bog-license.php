<?php

if (!defined('ABSPATH')) {
    exit;
}

class BOG_License
{
    public const OPTION_KEY = 'bog_license_key';
    public const OPTION_STATUS = 'bog_license_status';
    public const OPTION_REMOTE = 'bog_license_remote';
    public const CRON_HOOK = 'bog_license_remote_check';
    public const PLUGIN_SLUG = 'basic-opengraph';
    public const REMOTE_GRACE_PERIOD = 3 * DAY_IN_SECONDS;

    public static function register(): void
    {
        add_action('admin_init', [self::class, 'maybe_revalidate']);
        add_action('admin_notices', [self::class, 'render_admin_notice']);
        add_action('admin_post_bog_activate_license', [self::class, 'handle_activate']);
        add_action('admin_post_bog_deactivate_license', [self::class, 'handle_deactivate']);
        add_action(self::CRON_HOOK, [self::class, 'cron_revalidate']);
        add_action('init', [self::class, 'schedule_cron']);
    }

    public static function schedule_cron(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'twicedaily', self::CRON_HOOK);
        }
    }

    public static function cron_revalidate(): void
    {
        if (get_option(self::OPTION_KEY, '') === '') {
            return;
        }

        self::refresh_status(true);
    }

    public static function is_valid(): bool
    {
        $key = get_option(self::OPTION_KEY, '');

        if ($key === '') {
            return false;
        }

        return !empty(self::validate_key($key)['valid']);
    }

    public static function get_status(): array
    {
        $cached = get_option(self::OPTION_STATUS, []);

        if (is_array($cached) && !empty($cached['checked_at']) && (time() - (int) $cached['checked_at']) < HOUR_IN_SECONDS) {
            return $cached;
        }

        return self::refresh_status();
    }

    public static function refresh_status(bool $force_remote = false): array
    {
        $key = get_option(self::OPTION_KEY, '');
        $result = self::validate_key($key, $force_remote);
        $remote = get_option(self::OPTION_REMOTE, []);

        $status = [
            'valid' => $result['valid'],
            'message' => $result['message'],
            'domain' => $result['domain'],
            'licensed_domain' => $result['licensed_domain'] ?? '',
            'expires' => $result['expires'] ?? null,
            'remote_status' => $result['remote_status'] ?? ($remote['status'] ?? 'unknown'),
            'remote_checked_at' => $result['remote_checked_at'] ?? ($remote['checked_at'] ?? 0),
            'checked_at' => time(),
        ];

        update_option(self::OPTION_STATUS, $status, false);

        return $status;
    }

    public static function get_api_url(): string
    {
        return apply_filters(
            'bog_license_api_url',
            trailingslashit(BOG_LICENSE_SERVER_URL) . 'api/validate'
        );
    }

    public static function get_site_domain(): string
    {
        $host = wp_parse_url(home_url('/', 'https'), PHP_URL_HOST);

        return self::normalize_domain((string) $host);
    }

    public static function mask_code(string $code): string
    {
        $code = self::normalize_code($code);

        if ($code === '') {
            return '';
        }

        if (preg_match('/^TLJ-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code)) {
            return 'TLJ-****-****-****-****';
        }

        return strlen($code) <= 4
            ? str_repeat('•', strlen($code))
            : substr($code, 0, 3) . str_repeat('•', strlen($code) - 3);
    }

    public static function validate_key(string $license_key, bool $force_remote = false): array
    {
        $license_key = trim($license_key);
        $site_domain = self::get_site_domain();

        if ($license_key === '') {
            return self::result(false, __('No license code entered.', 'basic-opengraph'), $site_domain);
        }

        if (!self::is_simple_code($license_key)) {
            return self::result(false, __('Invalid license code format. Use TLJ-XXXX-XXXX-XXXX-XXXX.', 'basic-opengraph'), $site_domain);
        }

        $license_code = self::normalize_code($license_key);
        $remote = self::validate_remotely($license_code, $force_remote);

        if ($remote['status'] === 'revoked') {
            return self::merge_remote(false, __('This license has been revoked by Techlaju.', 'basic-opengraph'), $remote, $site_domain);
        }

        if ($remote['status'] === 'invalid') {
            return self::merge_remote(false, $remote['message'], $remote, $site_domain);
        }

        if ($remote['status'] === 'active' && !empty($remote['valid'])) {
            return self::merge_remote(true, __('License is active for this website.', 'basic-opengraph'), $remote, $site_domain);
        }

        $last_remote = get_option(self::OPTION_REMOTE, []);

        if (
            !empty($last_remote['valid'])
            && !empty($last_remote['checked_at'])
            && (time() - (int) $last_remote['checked_at']) < self::REMOTE_GRACE_PERIOD
            && isset($last_remote['license_hash'])
            && $last_remote['license_hash'] === hash('sha256', $license_code)
        ) {
            return self::merge_remote(
                true,
                __('License is active. Remote validation is temporarily unavailable.', 'basic-opengraph'),
                $remote,
                $site_domain
            );
        }

        return self::merge_remote(
            false,
            __('Unable to verify license with Techlaju. Please try again shortly.', 'basic-opengraph'),
            $remote,
            $site_domain
        );
    }

    public static function handle_activate(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage the license.', 'basic-opengraph'));
        }

        check_admin_referer('bog_license_action');

        $license_key = isset($_POST['bog_license_key']) ? sanitize_text_field(wp_unslash($_POST['bog_license_key'])) : '';
        $existing = get_option(self::OPTION_KEY, '');

        if ($license_key === '' && $existing !== '') {
            $license_key = $existing;
        } else {
            update_option(self::OPTION_KEY, $license_key, false);
        }

        self::refresh_status(true);

        wp_safe_redirect(add_query_arg([
            'page' => 'basic-opengraph',
            'bog_license_updated' => '1',
        ], admin_url('options-general.php')));
        exit;
    }

    public static function handle_deactivate(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage the license.', 'basic-opengraph'));
        }

        check_admin_referer('bog_license_action');

        delete_option(self::OPTION_KEY);
        delete_option(self::OPTION_STATUS);
        delete_option(self::OPTION_REMOTE);

        wp_safe_redirect(add_query_arg([
            'page' => 'basic-opengraph',
            'bog_license_updated' => '1',
        ], admin_url('options-general.php')));
        exit;
    }

    public static function maybe_revalidate(): void
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (!isset($_GET['page']) || $_GET['page'] !== 'basic-opengraph') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        self::refresh_status();
    }

    public static function render_admin_notice(): void
    {
        if (!current_user_can('manage_options') || self::is_valid()) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen || $screen->id !== 'settings_page_basic-opengraph') {
            return;
        }

        $status = self::get_status();
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e('Basic OpenGraph by Techlaju', 'basic-opengraph'); ?>:</strong>
                <?php echo esc_html($status['message'] ?? __('A valid license is required.', 'basic-opengraph')); ?>
            </p>
        </div>
        <?php
    }

    private static function validate_remotely(string $license_code, bool $force_remote = false): array
    {
        $cached = get_option(self::OPTION_REMOTE, []);
        $cache_key = hash('sha256', $license_code);

        if (
            !$force_remote
            && is_array($cached)
            && !empty($cached['checked_at'])
            && (time() - (int) $cached['checked_at']) < 12 * HOUR_IN_SECONDS
            && isset($cached['license_hash'])
            && $cached['license_hash'] === $cache_key
        ) {
            return $cached;
        }

        $response = wp_remote_post(self::get_api_url(), [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'license_code' => $license_code,
                'domain' => self::get_site_domain(),
                'plugin' => self::PLUGIN_SLUG,
                'plugin_version' => BOG_VERSION,
            ]),
        ]);

        if (is_wp_error($response)) {
            return self::remote_result('unreachable', false, $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || !is_array($body)) {
            return self::remote_result('unreachable', false, __('Unexpected response from Techlaju license server.', 'basic-opengraph'));
        }

        $remote = self::remote_result(
            sanitize_text_field((string) ($body['status'] ?? 'invalid')),
            !empty($body['valid']),
            sanitize_text_field((string) ($body['message'] ?? '')),
            (int) ($body['expires'] ?? 0)
        );
        $remote['licensed_domain'] = sanitize_text_field((string) ($body['licensed_domain'] ?? ''));
        $remote['license_hash'] = $cache_key;

        update_option(self::OPTION_REMOTE, $remote, false);

        return $remote;
    }

    private static function merge_remote(bool $valid, string $message, array $remote, string $site_domain): array
    {
        return [
            'valid' => $valid,
            'message' => $message,
            'domain' => $site_domain,
            'licensed_domain' => $remote['licensed_domain'] ?? '',
            'expires' => $remote['expires'] ?? null,
            'remote_status' => $remote['status'] ?? 'unknown',
            'remote_checked_at' => $remote['checked_at'] ?? time(),
        ];
    }

    private static function remote_result(string $status, bool $valid, string $message, int $expires = 0): array
    {
        return [
            'status' => $status,
            'valid' => $valid,
            'message' => $message,
            'expires' => $expires > 0 ? $expires : null,
            'checked_at' => time(),
        ];
    }

    private static function result(bool $valid, string $message, string $domain): array
    {
        return [
            'valid' => $valid,
            'message' => $message,
            'domain' => $domain,
            'licensed_domain' => '',
            'expires' => null,
        ];
    }

    private static function is_simple_code(string $code): bool
    {
        return (bool) preg_match('/^TLJ-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', self::normalize_code($code));
    }

    private static function normalize_code(string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '');
    }

    private static function normalize_domain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = rtrim($domain, '/');

        if (str_contains($domain, '/')) {
            $domain = (string) wp_parse_url('http://' . $domain, PHP_URL_HOST);
        }

        return $domain;
    }
}

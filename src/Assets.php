<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox;

/**
 * One stylesheet and one small script, no build step, nothing remote.
 */
final class Assets
{
    public const HANDLE = 'ruvelo-inbox';

    public static function register(): void
    {
        add_action('init', [self::class, 'registerAssets'], 5);
        add_action('admin_enqueue_scripts', [self::class, 'admin']);
        add_action('wp_enqueue_scripts', [self::class, 'front']);
    }

    public static function registerAssets(): void
    {
        $version = RUVELO_INBOX_VERSION;
        wp_register_style(self::HANDLE, RUVELO_INBOX_URL.'assets/css/inbox.css', [], $version);
        wp_register_script(self::HANDLE, RUVELO_INBOX_URL.'assets/js/inbox.js', ['heartbeat', 'wp-a11y', 'wp-i18n'], $version, ['in_footer' => true]);
        wp_set_script_translations(self::HANDLE, 'ruvelo-inbox', RUVELO_INBOX_DIR.'languages');
        wp_register_script(self::HANDLE.'-blocks', RUVELO_INBOX_URL.'assets/js/blocks.js', ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n'], $version, ['in_footer' => false]);
        wp_set_script_translations(self::HANDLE.'-blocks', 'ruvelo-inbox', RUVELO_INBOX_DIR.'languages');
    }

    /**
     * Settings the script needs: the REST root and a nonce for the signed-in person.
     */
    public static function localize(): void
    {
        static $done = false;
        if ($done || ! is_user_logged_in()) {
            return;
        }
        $done = true;
        wp_localize_script(self::HANDLE, 'ruveloInbox', [
            'rest' => esc_url_raw(rest_url('ruvelo-inbox/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    public static function enqueue(): void
    {
        wp_enqueue_style(self::HANDLE);
        if (is_user_logged_in()) {
            self::localize();
            wp_enqueue_script(self::HANDLE);
        }
    }

    public static function admin(): void
    {
        if (is_user_logged_in()) {
            self::enqueue();
        }
    }

    public static function front(): void
    {
        if (is_admin_bar_showing() && Settings::bool('bell_front') && is_user_logged_in()) {
            self::enqueue();
        }
    }
}

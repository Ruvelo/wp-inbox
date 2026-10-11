<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox;

/**
 * The Inbox and Notification Bell blocks, and their shortcodes for classic
 * themes: `[ruvelo_inbox]` and `[ruvelo_inbox_bell]`.
 */
final class Blocks
{
    public static function register(): void
    {
        register_block_type(RUVELO_INBOX_DIR.'blocks/inbox');
        register_block_type(RUVELO_INBOX_DIR.'blocks/inbox-bell');
        add_shortcode('ruvelo_inbox', [self::class, 'inboxShortcode']);
        add_shortcode('ruvelo_inbox_bell', [self::class, 'bellShortcode']);
    }

    /**
     * The inbox, with a Preferences tab (`?inbox_view=preferences`).
     *
     * @param array<string, mixed> $attributes
     */
    public static function inbox(array $attributes = [], string $wrapper = ''): string
    {
        if (is_user_logged_in()) {
            Assets::enqueue();
        } else {
            wp_enqueue_style(Assets::HANDLE);
        }

        $showPrefs = ! isset($attributes['showPreferences']) || (bool) $attributes['showPreferences'];
        $showHeading = ! isset($attributes['showHeading']) || (bool) $attributes['showHeading'];
        $perPage = isset($attributes['perPage']) && is_numeric($attributes['perPage']) ? max(1, min(100, (int) $attributes['perPage'])) : 20;
        $base = Urls::current();
        $inboxUrl = remove_query_arg(['inbox_view', 'inbox_status', 'inbox_type', 'inbox_page'], $base);
        $prefsUrl = add_query_arg('inbox_view', 'preferences', $inboxUrl);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which tab to show.
        $view = isset($_GET['inbox_view']) ? sanitize_key(wp_unslash($_GET['inbox_view'])) : '';

        $html = $showPrefs && $view === 'preferences'
            ? Views::preferences(['context' => 'block', 'base' => $base, 'show_heading' => $showHeading, 'show_tabs' => true, 'inbox_url' => $inboxUrl])
            : Views::inbox(['context' => 'block', 'base' => $base, 'per_page' => $perPage, 'show_heading' => $showHeading, 'show_tabs' => $showPrefs, 'preferences_url' => $showPrefs ? $prefsUrl : Urls::preferences()]);

        return '<div '.($wrapper !== '' ? $wrapper : 'class="wp-block-ruvelo-inbox"').'>'.$html.'</div>';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function bell(array $attributes = [], string $wrapper = ''): string
    {
        if (! is_user_logged_in()) {
            return '';
        }
        Assets::enqueue();
        $label = isset($attributes['label']) && is_string($attributes['label']) ? sanitize_text_field($attributes['label']) : '';

        return '<div '.($wrapper !== '' ? $wrapper : 'class="wp-block-ruvelo-inbox-bell"').'>'.Views::bell('block', $label).'</div>';
    }

    /**
     * @param array<string, string>|string $atts
     */
    public static function inboxShortcode(array|string $atts = []): string
    {
        $atts = shortcode_atts(['preferences' => 'yes', 'heading' => 'yes', 'per_page' => '20'], is_array($atts) ? $atts : [], 'ruvelo_inbox');

        return self::inbox([
            'showPreferences' => ! in_array(strtolower($atts['preferences']), ['0', 'no', 'false', 'off'], true),
            'showHeading' => ! in_array(strtolower($atts['heading']), ['0', 'no', 'false', 'off'], true),
            'perPage' => (int) $atts['per_page'],
        ]);
    }

    /**
     * @param array<string, string>|string $atts
     */
    public static function bellShortcode(array|string $atts = []): string
    {
        $atts = shortcode_atts(['label' => ''], is_array($atts) ? $atts : [], 'ruvelo_inbox_bell');

        return self::bell(['label' => $atts['label']]);
    }
}

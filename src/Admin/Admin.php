<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Admin;

use Ruvelo\WP\Inbox\Inbox;
use Ruvelo\WP\Inbox\Settings;
use Ruvelo\WP\Inbox\Urls;
use Ruvelo\WP\Inbox\Views;

/**
 * The Inbox menu: everyone's notifications and preferences (any signed-in
 * user can open wp-admin's profile, so they can open these too), and the
 * settings for people with `ruvelo_manage_inbox`.
 */
final class Admin
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [SettingsPage::class, 'register']);
        add_filter('admin_body_class', [self::class, 'bodyClass']);
        add_filter('plugin_action_links_'.plugin_basename(RUVELO_INBOX_FILE), [self::class, 'links']);
    }

    public static function menu(): void
    {
        $count = Inbox::unreadCount(get_current_user_id());
        $bubble = $count > 0
            ? ' <span class="awaiting-mod count-'.$count.'" data-ruvelo-inbox-menu-count><span class="pending-count" aria-hidden="true">'.esc_html(Views::badge($count)).'</span><span class="screen-reader-text">'
                /* translators: %d: number of unread notifications */
                .esc_html(sprintf(_n('%d unread notification', '%d unread notifications', $count, 'ruvelo-inbox'), $count)).'</span></span>'
            : '';

        add_menu_page(
            __('Notifications', 'ruvelo-inbox'),
            __('Inbox', 'ruvelo-inbox').$bubble,
            'read',
            Urls::ADMIN_PAGE,
            [self::class, 'inbox'],
            'dashicons-bell',
            3
        );
        add_submenu_page(Urls::ADMIN_PAGE, __('Notifications', 'ruvelo-inbox'), __('Notifications', 'ruvelo-inbox'), 'read', Urls::ADMIN_PAGE, [self::class, 'inbox']);
        add_submenu_page(Urls::ADMIN_PAGE, __('Notification preferences', 'ruvelo-inbox'), __('Preferences', 'ruvelo-inbox'), 'read', Urls::PREFERENCES_PAGE, [self::class, 'preferences']);
        add_submenu_page(Urls::ADMIN_PAGE, __('Inbox settings', 'ruvelo-inbox'), __('Settings', 'ruvelo-inbox'), Settings::CAP_MANAGE, SettingsPage::SLUG, [SettingsPage::class, 'render']);
    }

    public static function inbox(): void
    {
        echo '<div class="wrap ruvelo-inbox-wrap"><hr class="wp-header-end">';
        echo Views::inbox(['context' => 'admin', 'base' => Urls::current()]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the templates.
        echo '</div>';
    }

    public static function preferences(): void
    {
        echo '<div class="wrap ruvelo-inbox-wrap"><hr class="wp-header-end">';
        echo Views::preferences(['context' => 'admin', 'base' => Urls::current(), 'inbox_url' => admin_url('admin.php?page='.Urls::ADMIN_PAGE)]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the templates.
        echo '</div>';
    }

    public static function bodyClass(mixed $classes): string
    {
        $classes = is_string($classes) ? $classes : '';
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen !== null && str_contains((string) $screen->id, 'ruvelo-inbox') && ! str_contains((string) $screen->id, SettingsPage::SLUG)) {
            $classes .= ' ruvelo-inbox-screen ruvelo-inbox-scheme-'.Settings::scheme();
        }

        return $classes;
    }

    /**
     * @param array<int|string, string> $links
     * @return array<int|string, string>
     */
    public static function links(array $links): array
    {
        if (current_user_can(Settings::CAP_MANAGE)) {
            array_unshift($links, '<a href="'.esc_url(admin_url('admin.php?page='.SettingsPage::SLUG)).'">'.esc_html__('Settings', 'ruvelo-inbox').'</a>');
        }

        return $links;
    }
}

<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox;

/**
 * Where the inbox lives: the admin screen, or the front-end page with the
 * Inbox block when the owner picked one (Settings → Front-end inbox page).
 */
final class Urls
{
    public const ADMIN_PAGE = 'ruvelo-inbox';

    public const PREFERENCES_PAGE = 'ruvelo-inbox-preferences';

    /**
     * @param array<string, string|int> $args
     * @param bool $outside For emails: prefer the front-end page, which works for everyone.
     */
    public static function inbox(array $args = [], bool $outside = false): string
    {
        $page = self::frontPage();
        $url = $page !== '' && ($outside || ! is_admin())
            ? $page
            : admin_url('admin.php?page='.self::ADMIN_PAGE);

        /**
         * Filters the inbox's address (the bell's "View all", emails).
         *
         * @param string $url
         */
        $url = (string) apply_filters('ruvelo_inbox_url', $url);

        return $args === [] ? $url : add_query_arg($args, $url);
    }

    /**
     * @param array<string, string|int> $args
     */
    public static function preferences(array $args = [], bool $outside = false): string
    {
        $page = self::frontPage();
        $url = $page !== '' && ($outside || ! is_admin())
            ? add_query_arg('inbox_view', 'preferences', $page)
            : admin_url('admin.php?page='.self::PREFERENCES_PAGE);

        /**
         * Filters the preferences page's address.
         *
         * @param string $url
         */
        $url = (string) apply_filters('ruvelo_inbox_preferences_url', $url);

        return $args === [] ? $url : add_query_arg($args, $url);
    }

    /**
     * The link an item opens through: marks it read, then redirects to its
     * URL. A plain link, so it works without JavaScript.
     */
    public static function open(Notification $notification, string $base): string
    {
        return wp_nonce_url(add_query_arg(['ruvelo_inbox_open' => $notification->id], $base), 'ruvelo_inbox_open_'.$notification->id);
    }

    private static function frontPage(): string
    {
        $id = Settings::int('page_id');
        if ($id <= 0 || get_post_status($id) !== 'publish') {
            return '';
        }
        $link = get_permalink($id);

        return is_string($link) ? $link : '';
    }

    /**
     * The URL of the page being shown, without the inbox's own action
     * parameters, for forms to come back to.
     */
    public static function current(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        $scheme = is_ssl() ? 'https' : 'http';
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        $port = wp_parse_url(home_url(), PHP_URL_PORT);
        $url = $scheme.'://'.(is_string($host) ? $host : '').($port ? ':'.$port : '').$uri;

        return remove_query_arg(['ruvelo_inbox_open', '_wpnonce', 'ruvelo_inbox_done', 'ruvelo_inbox_count'], $url);
    }
}

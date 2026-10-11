<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox;

/**
 * Renders the bell, the inbox and the preferences from templates/, which a
 * theme can override by copying a file to `your-theme/ruvelo-inbox/`.
 */
final class Views
{
    /**
     * The inbox: tabs, filter, items grouped by day, bulk actions.
     *
     * @param array<string, mixed> $args context (admin|block), base (URL), per_page
     */
    public static function inbox(array $args = []): string
    {
        $userId = get_current_user_id();
        if ($userId === 0) {
            return self::signIn();
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
        $status = isset($_GET['inbox_status']) && sanitize_key(wp_unslash($_GET['inbox_status'])) === 'unread' ? 'unread' : 'all';
        $type = isset($_GET['inbox_type']) ? sanitize_key(wp_unslash($_GET['inbox_type'])) : '';
        $page = isset($_GET['inbox_page']) ? max(1, absint($_GET['inbox_page'])) : 1;
        // phpcs:enable

        $perPage = isset($args['per_page']) && is_numeric($args['per_page']) ? max(1, (int) $args['per_page']) : max(1, Settings::int('per_page'));
        $result = Inbox::query($userId, ['status' => $status, 'type' => $type, 'page' => $page, 'per_page' => $perPage]);
        $base = isset($args['base']) && is_string($args['base']) ? $args['base'] : Urls::current();
        $base = remove_query_arg(['inbox_page', 'ruvelo_inbox_done', 'ruvelo_inbox_count'], $base);

        $days = [];
        foreach ($result['items'] as $notification) {
            $days[$notification->day()][] = $notification;
        }

        $types = [];
        foreach (Store::typesFor($userId) as $name) {
            $types[$name] = Types::label($name);
        }
        asort($types);

        return self::render('inbox', [
            'context' => $args['context'] ?? 'block',
            'base' => $base,
            'status' => $status,
            'type' => $type,
            'page' => $page,
            'pages' => (int) ceil($result['total'] / $perPage),
            'total' => $result['total'],
            'days' => $days,
            'types' => $types,
            'unread' => Inbox::unreadCount($userId),
            'notice' => Actions::notice(),
            'show_heading' => ! isset($args['show_heading']) || (bool) $args['show_heading'],
            'show_tabs' => ! empty($args['show_tabs']),
            'preferences_url' => $args['preferences_url'] ?? Urls::preferences(),
        ]);
    }

    /**
     * The preferences: a switch per type and channel, grouped.
     *
     * @param array<string, mixed> $args context, base, inbox_url
     */
    public static function preferences(array $args = []): string
    {
        $userId = get_current_user_id();
        if ($userId === 0) {
            return self::signIn();
        }

        return self::render('preferences', [
            'context' => $args['context'] ?? 'block',
            'base' => isset($args['base']) && is_string($args['base']) ? remove_query_arg(['ruvelo_inbox_done', 'ruvelo_inbox_count'], $args['base']) : Urls::current(),
            'groups' => Types::grouped(),
            'channels' => Types::channels(),
            'prefs' => Preferences::forUser($userId),
            'notice' => Actions::notice(),
            'emails' => Settings::bool('emails'),
            'show_heading' => ! isset($args['show_heading']) || (bool) $args['show_heading'],
            'show_tabs' => ! empty($args['show_tabs']),
            'inbox_url' => $args['inbox_url'] ?? Urls::inbox(),
        ]);
    }

    /**
     * The bell: a link to the inbox, with the unread count. JavaScript turns
     * it into a button that opens the dropdown.
     *
     * @param string $context bar (the toolbar) | block (a site header)
     */
    public static function bell(string $context = 'block', string $label = ''): string
    {
        $userId = get_current_user_id();
        if ($userId === 0) {
            return '';
        }

        return self::render('bell', [
            'context' => $context,
            'id' => 'ruvelo-inbox-bell-'.wp_unique_id(),
            'label' => $label !== '' ? $label : __('Notifications', 'ruvelo-inbox'),
            'unread' => Inbox::unreadCount($userId),
            'latest' => Store::latestId($userId),
            'items' => self::bellItems($userId),
            'inbox_url' => Urls::inbox(),
            'preferences_url' => Urls::preferences(),
            'base' => Urls::current(),
            'scheme' => Settings::scheme(),
        ]);
    }

    /**
     * The bell's list, also sent by the Heartbeat when it changes.
     */
    public static function bellItems(int $userId, string $base = ''): string
    {
        return self::render('bell-items', [
            'items' => Inbox::latest($userId, max(1, Settings::int('bell_limit'))),
            'base' => $base !== '' ? $base : Urls::inbox(),
        ]);
    }

    public static function signIn(): string
    {
        return self::render('sign-in', ['login' => wp_login_url(Urls::current())]);
    }

    public static function badge(int $count): string
    {
        return $count > 99 ? '99+' : (string) $count;
    }

    /**
     * @param array<string, mixed> $vars
     */
    public static function render(string $template, array $vars = []): string
    {
        $file = self::locate($template);
        if ($file === '') {
            return '';
        }
        ob_start();
        (static function (string $ruveloFile, array $view): void {
            include $ruveloFile;
        })($file, $vars);

        return (string) ob_get_clean();
    }

    /**
     * The template file: the theme's copy if it has one.
     */
    public static function locate(string $template): string
    {
        $theme = locate_template('ruvelo-inbox/'.$template.'.php');
        $file = $theme !== '' ? $theme : RUVELO_INBOX_DIR.'templates/'.$template.'.php';

        /**
         * Filters the template file used for a view.
         *
         * @param string $file
         * @param string $template inbox, preferences, bell, bell-items, parts/item…
         */
        $file = (string) apply_filters('ruvelo_inbox_template', $file, $template);

        return is_readable($file) ? $file : '';
    }

    /**
     * The hidden fields every inbox form carries.
     */
    public static function fields(string $action, string $back, string $nonce = Actions::NONCE): string
    {
        return '<input type="hidden" name="ruvelo_inbox_action" value="'.esc_attr($action).'">'
            .'<input type="hidden" name="ruvelo_inbox_nonce" value="'.esc_attr(wp_create_nonce($nonce)).'">'
            .'<input type="hidden" name="redirect_to" value="'.esc_url($back).'">';
    }

    /**
     * Inline SVG icons (no icon font, nothing remote).
     */
    public static function icon(string $name): string
    {
        $paths = [
            'bell' => '<path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
            'check' => '<path d="M20 6 9 17l-5-5"/>',
            'check-all' => '<path d="M18 6 7 17l-5-5"/><path d="m22 10-7.5 7.5L13 16"/>',
            'dot' => '<circle cx="12" cy="12" r="4"/>',
            'trash' => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
            'sliders' => '<path d="M4 21v-7"/><path d="M4 10V3"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M20 21v-5"/><path d="M20 12V3"/><path d="M2 14h4"/><path d="M10 8h4"/><path d="M18 16h4"/>',
            'inbox' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        ];

        return '<svg class="ruvelo-inbox-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.($paths[$name] ?? '').'</svg>';
    }
}

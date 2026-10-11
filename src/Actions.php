<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox;

use Ruvelo\WP\Inbox\Support\Redirect;

/**
 * The no-JavaScript path. Every button on the bell, the inbox and the
 * preferences page is a plain form that posts back to the page it's on, and
 * every item is a plain link; this handles them on `init` and redirects
 * back (post/redirect/get). It works on the front end, too, so members of
 * sites that keep people out of wp-admin can still use the Inbox block.
 */
final class Actions
{
    public const NONCE = 'ruvelo_inbox_actions';

    public const PREFERENCES_NONCE = 'ruvelo_inbox_preferences';

    public static function register(): void
    {
        add_action('init', [self::class, 'handle'], 20);
        add_action('init', [Mailer::class, 'handleUnsubscribe'], 21);
    }

    public static function handle(): void
    {
        if (isset($_GET['ruvelo_inbox_open'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in open().
            self::open();
        }
        if (isset($_SERVER['REQUEST_METHOD'], $_POST['ruvelo_inbox_action']) && $_SERVER['REQUEST_METHOD'] === 'POST') { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in post().
            self::post(sanitize_key(wp_unslash($_POST['ruvelo_inbox_action']))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in post().
        }
    }

    private static function open(): void
    {
        $id = absint($_GET['ruvelo_inbox_open'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified just below.
        $userId = get_current_user_id();
        if ($userId === 0) {
            Redirect::to(wp_login_url(Urls::current()));
        }
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (! wp_verify_nonce($nonce, 'ruvelo_inbox_open_'.$id)) {
            Redirect::to(Urls::inbox());
        }

        $notification = Inbox::find($id, $userId);
        if ($notification === null) {
            Redirect::to(Urls::inbox());
        }
        Inbox::markRead($id, $userId);

        $target = $notification->url !== '' ? $notification->url : Urls::inbox();
        Redirect::to(wp_validate_redirect($target, Urls::inbox()));
    }

    private static function post(string $action): void
    {
        $userId = get_current_user_id();
        if ($userId === 0) {
            return;
        }

        $nonceAction = $action === 'preferences' ? self::PREFERENCES_NONCE : self::NONCE;
        $nonce = isset($_POST['ruvelo_inbox_nonce']) ? sanitize_text_field(wp_unslash($_POST['ruvelo_inbox_nonce'])) : '';
        if (! wp_verify_nonce($nonce, $nonceAction)) {
            wp_die(esc_html__('This page was open for too long. Go back, reload it and try again.', 'ruvelo-inbox'), esc_html__('Try again', 'ruvelo-inbox'), ['response' => 403, 'back_link' => true]);
        }

        $back = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '';
        $back = wp_validate_redirect($back, Urls::inbox());
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $count = 0;
        $done = $action;

        switch ($action) {
            case 'read':
                $count = Inbox::markRead($id, $userId) ? 1 : 0;
                break;
            case 'unread':
                $count = Inbox::markUnread($id, $userId) ? 1 : 0;
                break;
            case 'delete':
                $count = Inbox::delete($id, $userId) ? 1 : 0;
                break;
            case 'read_all':
                $type = isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : '';
                $count = Inbox::markAllRead($userId, $type);
                break;
            case 'bulk':
                $bulk = isset($_POST['bulk_action']) ? sanitize_key(wp_unslash($_POST['bulk_action'])) : '';
                $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_values(array_map('absint', wp_unslash($_POST['ids']))) : [];
                $count = Inbox::bulk($userId, $bulk, $ids);
                $done = 'bulk_'.$bulk;
                break;
            case 'preferences':
                $posted = isset($_POST['prefs']) && is_array($_POST['prefs']) ? map_deep(wp_unslash($_POST['prefs']), 'sanitize_text_field') : [];
                $result = Inbox::updatePreferences($userId, self::choices(is_array($posted) ? $posted : []));
                $done = is_wp_error($result) ? 'error' : 'saved';
                break;
            case 'test':
                if (! current_user_can(Settings::CAP_MANAGE)) {
                    return;
                }
                Inbox::notify($userId, [
                    'type' => 'general',
                    'title' => __('Your inbox works', 'ruvelo-inbox'),
                    'body' => __('This is a test notification. New ones show up in the bell within a minute, without reloading.', 'ruvelo-inbox'),
                    'url' => Urls::inbox(),
                    'icon' => '✓',
                ]);
                $done = 'test';
                break;
            default:
                return;
        }

        Redirect::to(add_query_arg(['ruvelo_inbox_done' => $done, 'ruvelo_inbox_count' => $count], $back));
    }

    /**
     * Checkboxes only post when ticked: every switch on the page that isn't
     * posted is off.
     *
     * @param array<mixed> $posted
     * @return array<string, array<string, bool>>
     */
    public static function choices(array $posted): array
    {
        $choices = [];
        foreach (Types::all() as $type => $args) {
            if ($args['required']) {
                continue;
            }
            foreach ($args['channels'] as $channel) {
                $choices[$type][$channel] = isset($posted[$type]) && is_array($posted[$type]) && ! empty($posted[$type][$channel]);
            }
        }

        return $choices;
    }

    /**
     * The message for `?ruvelo_inbox_done=…`, shown once after a redirect.
     */
    public static function notice(): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
        $done = isset($_GET['ruvelo_inbox_done']) ? sanitize_key(wp_unslash($_GET['ruvelo_inbox_done'])) : '';
        $count = isset($_GET['ruvelo_inbox_count']) ? absint($_GET['ruvelo_inbox_count']) : 0;
        // phpcs:enable

        return match ($done) {
            'read_all', 'bulk_read' => $count > 0
                /* translators: %d: number of notifications */
                ? sprintf(_n('Marked %d notification as read.', 'Marked %d notifications as read.', $count, 'ruvelo-inbox'), $count)
                : __('You’re all caught up.', 'ruvelo-inbox'),
            /* translators: %d: number of notifications */
            'bulk_unread' => sprintf(_n('Marked %d notification as unread.', 'Marked %d notifications as unread.', $count, 'ruvelo-inbox'), $count),
            /* translators: %d: number of notifications */
            'bulk_delete' => sprintf(_n('Deleted %d notification.', 'Deleted %d notifications.', $count, 'ruvelo-inbox'), $count),
            'delete' => __('Notification deleted.', 'ruvelo-inbox'),
            'unread' => __('Marked as unread.', 'ruvelo-inbox'),
            'read' => __('Marked as read.', 'ruvelo-inbox'),
            'saved' => __('Preferences saved. They apply to new notifications.', 'ruvelo-inbox'),
            'error' => __('Those preferences couldn’t be saved. Reload the page and try again.', 'ruvelo-inbox'),
            'test' => __('Sent. Look at the bell.', 'ruvelo-inbox'),
            default => '',
        };
    }
}

<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox;

use WP_User;

/**
 * Email, never during a page load. `notify()` queues a row; WP-Cron sends a
 * few minutes later, one email per person with everything that arrived in
 * between, so a busy thread is one email, not ten. Every email says why it
 * came and links to turn that type off.
 */
final class Mailer
{
    public const CRON_HOOK = 'ruvelo_inbox_send_emails';

    /**
     * @param array{type: string, title: string, body: string, url: string, actor_name: string} $message
     */
    public static function queue(int $userId, int $notificationId, array $message): int
    {
        global $wpdb;

        $delay = max(0, Settings::int('email_delay')) * MINUTE_IN_SECONDS;
        $now = time();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
        $wpdb->insert(Install::emailsTable(), [
            'user_id' => $userId,
            'notification_id' => $notificationId,
            'type' => $message['type'],
            'title' => $message['title'],
            'body' => $message['body'],
            'url' => $message['url'],
            'actor_name' => $message['actor_name'],
            'status' => 'pending',
            'created_at' => gmdate('Y-m-d H:i:s', $now),
            'send_after' => gmdate('Y-m-d H:i:s', $now + $delay),
        ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
        $id = (int) $wpdb->insert_id;

        self::schedule($delay + 15);

        /**
         * Fires when an email is queued for someone.
         *
         * @param int $id
         * @param int $userId
         * @param array<string, mixed> $message
         */
        do_action('ruvelo_inbox_email_queued', $id, $userId, $message);

        return $id;
    }

    public static function schedule(int $delay = 60): void
    {
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + max(1, $delay), self::CRON_HOOK);
        }
    }

    public static function pending(): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE status = %s', Install::emailsTable(), 'pending'));
    }

    /**
     * Counts by status, for `wp ruvelo-inbox emails status`.
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT status, COUNT(*) AS n FROM %i GROUP BY status', Install::emailsTable()), ARRAY_A);
        $counts = ['pending' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * The WP-Cron callback.
     */
    public static function cron(): void
    {
        self::run();
    }

    /**
     * Sends to up to `batch_size` people whose emails are due, then schedules
     * itself again if more are waiting.
     *
     * @param bool $now Ignore the delay (WP-CLI).
     * @return int Emails sent.
     */
    public static function run(?int $batch = null, bool $now = false): int
    {
        global $wpdb;

        $batch = $batch ?? max(1, Settings::int('batch_size'));
        $table = Install::emailsTable();
        $due = $now ? gmdate('Y-m-d H:i:s', time() + YEAR_IN_SECONDS) : current_time('mysql', true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $users = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT user_id FROM %i WHERE status = %s AND send_after <= %s ORDER BY user_id LIMIT %d', $table, 'pending', $due, $batch));

        $sent = 0;
        foreach (is_array($users) ? $users : [] as $userId) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE user_id = %d AND status = %s AND send_after <= %s ORDER BY id ASC LIMIT 50', $table, (int) $userId, 'pending', $due), ARRAY_A);
            $rows = is_array($rows) ? array_values($rows) : [];
            if ($rows === []) {
                continue;
            }
            $status = self::deliver((int) $userId, $rows);
            $sent += $status === 'sent' ? 1 : 0;

            $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
            $in = implode(',', array_fill(0, count($ids), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom table; one %d placeholder per id.
            $wpdb->query($wpdb->prepare("UPDATE %i SET status = %s, sent_at = %s WHERE id IN ({$in})", $table, $status, current_time('mysql', true), ...$ids));
        }

        if (self::pending() > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
            $next = $wpdb->get_var($wpdb->prepare('SELECT MIN(send_after) FROM %i WHERE status = %s', $table, 'pending'));
            $at = is_string($next) ? (int) strtotime($next.' UTC') : time();
            self::schedule(max(60, $at - time()));
        }

        return $sent;
    }

    /**
     * One email to one person with everything waiting for them.
     *
     * @param list<array<string, mixed>> $rows
     * @return string sent | skipped | failed
     */
    private static function deliver(int $userId, array $rows): string
    {
        $user = get_userdata($userId);
        if (! $user instanceof WP_User || $user->user_email === '') {
            return 'skipped';
        }

        // Preferences may have changed since it was queued.
        $items = array_values(array_filter($rows, static fn (array $row): bool => Preferences::wants($userId, (string) $row['type'], 'email')));
        if ($items === []) {
            return 'skipped';
        }

        $switched = switch_to_user_locale($userId);
        $email = self::compose($user, $items);

        /**
         * Filters an email before it is sent. Return false to skip it.
         *
         * @param array{to: string, subject: string, body: string, headers: list<string>, unsubscribe: string}|false $email
         * @param WP_User $user
         * @param list<array<string, mixed>> $items
         */
        $email = apply_filters('ruvelo_inbox_email', $email, $user, $items);
        $status = 'skipped';
        if (is_array($email) && isset($email['to'], $email['subject'], $email['body'])) {
            $unsubscribe = isset($email['unsubscribe']) && is_string($email['unsubscribe']) ? $email['unsubscribe'] : '';
            // One-click unsubscribe headers (RFC 8058), only where they survive intact: PHP's
            // mail() transport wraps long headers into encoded words, which mail apps can't use.
            $headers = static function ($mailer) use ($unsubscribe): void {
                if ($unsubscribe !== '' && is_object($mailer) && property_exists($mailer, 'Mailer') && $mailer->Mailer !== 'mail' && method_exists($mailer, 'addCustomHeader')) {
                    $mailer->addCustomHeader('List-Unsubscribe', '<'.$unsubscribe.'>');
                    $mailer->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
                }
            };
            add_action('phpmailer_init', $headers);
            $status = wp_mail((string) $email['to'], (string) $email['subject'], (string) $email['body'], (array) ($email['headers'] ?? [])) ? 'sent' : 'failed';
            remove_action('phpmailer_init', $headers);

            /**
             * Fires after an email is handed to wp_mail().
             *
             * @param WP_User $user
             * @param list<array<string, mixed>> $items
             * @param string $status sent | failed
             */
            do_action('ruvelo_inbox_email_sent', $user, $items, $status);
        }

        if ($switched) {
            restore_previous_locale();
        }

        return $status;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{to: string, subject: string, body: string, headers: list<string>, unsubscribe: string}
     */
    public static function compose(WP_User $user, array $items): array
    {
        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $decode = static fn (mixed $text): string => wp_specialchars_decode(is_scalar($text) ? (string) $text : '', ENT_QUOTES);
        $inbox = Urls::inbox([], true);
        $lines = [];

        if (count($items) === 1) {
            $item = $items[0];
            /* translators: 1: site title, 2: notification title */
            $subject = sprintf(__('[%1$s] %2$s', 'ruvelo-inbox'), $site, $decode($item['title']));
            $lines[] = $decode($item['title']);
            if ((string) $item['body'] !== '') {
                $lines[] = '';
                $lines[] = $decode($item['body']);
            }
            $lines[] = '';
            /* translators: %s: a link */
            $lines[] = sprintf(__('Open it: %s', 'ruvelo-inbox'), (string) $item['url'] !== '' ? (string) $item['url'] : $inbox);
        } else {
            /* translators: 1: site title, 2: number of notifications */
            $subject = sprintf(__('[%1$s] %2$d new notifications', 'ruvelo-inbox'), $site, count($items));
            /* translators: %s: the person's display name */
            $lines[] = sprintf(__('Hi %s, here’s what happened:', 'ruvelo-inbox'), $decode($user->display_name));
            foreach ($items as $item) {
                $lines[] = '';
                $lines[] = '• '.$decode($item['title']);
                if ((string) $item['body'] !== '') {
                    $body = trim((string) preg_replace('/\s+/u', ' ', $decode($item['body'])));
                    $lines[] = '  '.(mb_strlen($body) > 200 ? rtrim(mb_substr($body, 0, 199)).'…' : $body);
                }
                if ((string) $item['url'] !== '') {
                    $lines[] = '  '.(string) $item['url'];
                }
            }
            $lines[] = '';
            /* translators: %s: a link */
            $lines[] = sprintf(__('See them all: %s', 'ruvelo-inbox'), $inbox);
        }

        $lines[] = '';
        $lines[] = '--';
        $types = array_values(array_unique(array_map(static fn (array $item): string => (string) $item['type'], $items)));
        foreach ($types as $type) {
            /* translators: 1: notification type label, 2: link */
            $lines[] = sprintf(__('Stop emails about %1$s: %2$s', 'ruvelo-inbox'), mb_strtolower(Types::label($type)), self::unsubscribeUrl($user->ID, $type));
        }
        /* translators: %s: link */
        $lines[] = sprintf(__('Choose what you hear about: %s', 'ruvelo-inbox'), Urls::preferences([], true));

        return [
            'to' => $user->user_email,
            'subject' => $subject,
            'body' => implode("\n", $lines)."\n",
            'headers' => ['Content-Type: text/plain; charset=UTF-8'],
            'unsubscribe' => self::unsubscribeUrl($user->ID, count($types) === 1 ? $types[0] : '*'),
        ];
    }

    /**
     * A link that turns off email for one type (or `*`, every type) without
     * signing in. The key is an HMAC of the user and type with the site's salt.
     */
    public static function unsubscribeUrl(int $userId, string $type): string
    {
        return add_query_arg([
            'ruvelo_inbox_unsubscribe' => rawurlencode($type),
            'user' => $userId,
            'key' => self::key($userId, $type),
        ], home_url('/'));
    }

    public static function key(int $userId, string $type): string
    {
        return substr(hash_hmac('sha256', $userId.'|'.$type, wp_salt('auth')), 0, 32);
    }

    public static function verify(int $userId, string $type, string $key): bool
    {
        return $userId > 0 && $key !== '' && hash_equals(self::key($userId, $type), $key);
    }

    /**
     * Handles the unsubscribe link: a confirmation page on GET (so link
     * scanners don't unsubscribe anyone), the change on POST, including the
     * one-click POST from mail apps (RFC 8058).
     */
    public static function handleUnsubscribe(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification -- The HMAC key is the proof: the link works for people who aren't signed in.
        if (! isset($_GET['ruvelo_inbox_unsubscribe'], $_GET['user'], $_GET['key'])) {
            return;
        }
        $type = rawurldecode(sanitize_text_field(wp_unslash($_GET['ruvelo_inbox_unsubscribe'])));
        $userId = absint($_GET['user']);
        $key = sanitize_text_field(wp_unslash($_GET['key']));
        $post = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
        // phpcs:enable

        $type = $type === '*' ? '*' : sanitize_key($type);
        if (! self::verify($userId, $type, $key)) {
            wp_die(esc_html__('This unsubscribe link isn’t valid any more. Sign in to choose which emails you get.', 'ruvelo-inbox'), esc_html__('Link expired', 'ruvelo-inbox'), ['response' => 403, 'link_url' => esc_url(Urls::preferences([], true)), 'link_text' => esc_html__('Notification preferences', 'ruvelo-inbox')]);
        }

        $what = $type === '*' ? __('any notifications', 'ruvelo-inbox') : mb_strtolower(Types::label($type));

        if ($post) {
            Preferences::unsubscribe($userId, $type);
            /* translators: %s: what they unsubscribed from, like "mentions in notes" */
            $text = sprintf(__('Done. You won’t get emails about %s any more.', 'ruvelo-inbox'), $what);
            wp_die('<p>'.esc_html($text).'</p><p><a href="'.esc_url(Urls::preferences([], true)).'">'.esc_html__('Choose what you hear about', 'ruvelo-inbox').'</a></p>', esc_html__('Unsubscribed', 'ruvelo-inbox'), ['response' => 200]);
        }

        /* translators: %s: what they unsubscribe from, like "mentions in notes" */
        $question = sprintf(__('Stop emails about %s?', 'ruvelo-inbox'), $what);
        $form = '<h1>'.esc_html($question).'</h1>'
            .'<p>'.esc_html__('They’ll still show up in your inbox on the site. You can turn them back on in your notification preferences.', 'ruvelo-inbox').'</p>'
            .'<form method="post" action="'.esc_url(self::unsubscribeUrl($userId, $type)).'"><p><button type="submit" class="button button-primary">'.esc_html__('Stop these emails', 'ruvelo-inbox').'</button></p></form>';
        wp_die($form, esc_html($question), ['response' => 200]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
    }

    /**
     * Drops sent and skipped rows older than a month.
     */
    public static function prune(int $days = 30): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        return (int) $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE status <> %s AND created_at < %s', Install::emailsTable(), 'pending', gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)));
    }

    public static function forgetUser(int $userId): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        return (int) $wpdb->delete(Install::emailsTable(), ['user_id' => $userId], ['%d']);
    }
}

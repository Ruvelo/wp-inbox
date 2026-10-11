<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Cli;

use Ruvelo\WP\Inbox\Demo;
use Ruvelo\WP\Inbox\Inbox;
use Ruvelo\WP\Inbox\Mailer;
use Ruvelo\WP\Inbox\Settings;
use Ruvelo\WP\Inbox\Store;
use Ruvelo\WP\Inbox\Types;
use WP_CLI;
use WP_CLI\Utils;
use WP_User;

/**
 * Send, list and clean up notifications.
 *
 * ## EXAMPLES
 *
 *     wp ruvelo-inbox send maya --title="Your export is ready" --url=https://example.com/exports/9081
 *     wp ruvelo-inbox list maya --unread
 *     wp ruvelo-inbox prune --days=30
 *     wp ruvelo-inbox types
 */
final class Command
{
    /**
     * Sends a notification to one or more people.
     *
     * ## OPTIONS
     *
     * <user>...
     * : User IDs, logins or emails. Or --role.
     *
     * --title=<title>
     * : What happened, in a line.
     *
     * [--body=<body>]
     * : More detail.
     *
     * [--url=<url>]
     * : Where it leads.
     *
     * [--type=<type>]
     * : A registered type (see `wp ruvelo-inbox types`).
     * ---
     * default: general
     * ---
     *
     * [--icon=<icon>]
     * : A short string or emoji.
     *
     * [--actor=<user>]
     * : Who did it: a user, or a name.
     *
     * [--role=<role>]
     * : Send to everyone with this role instead (use "all" for everyone).
     *
     * [--porcelain]
     * : Print only the new notification IDs.
     *
     * ## EXAMPLES
     *
     *     wp ruvelo-inbox send maya tom --title="Billing is down for maintenance at 22:00 UTC" --icon=!
     *     wp ruvelo-inbox send --role=editor --title="Style guide updated" --url=/wp-admin/
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function send(array $args, array $assoc): void
    {
        $role = isset($assoc['role']) ? (string) $assoc['role'] : '';
        $users = $role !== ''
            ? get_users(array_filter(['role' => $role === 'all' ? '' : $role, 'fields' => 'ID']))
            : array_map([self::class, 'user'], $args);
        $users = array_values(array_map('intval', $users));
        if ($users === []) {
            WP_CLI::error('Nobody to send to: give users or --role.');
        }

        $actor = isset($assoc['actor']) ? (string) $assoc['actor'] : '';
        $actorUser = $actor !== '' ? self::find($actor) : null;

        $ids = Inbox::notify($users, [
            'type' => (string) ($assoc['type'] ?? 'general'),
            'title' => (string) ($assoc['title'] ?? ''),
            'body' => (string) ($assoc['body'] ?? ''),
            'url' => (string) ($assoc['url'] ?? ''),
            'icon' => (string) ($assoc['icon'] ?? ''),
            'actor' => $actorUser !== null ? $actorUser->ID : $actor,
        ]);

        if (! empty($assoc['porcelain'])) {
            WP_CLI::line(implode(' ', $ids));

            return;
        }
        WP_CLI::success(sprintf('Sent to %d %s (%d in an inbox; the rest chose email only or nothing).', count($users), count($users) === 1 ? 'person' : 'people', count($ids)));
    }

    /**
     * Lists someone's notifications.
     *
     * ## OPTIONS
     *
     * <user>
     * : Whose. ID, login or email.
     *
     * [--unread]
     * : Only unread ones.
     *
     * [--type=<type>]
     * : Only this type.
     *
     * [--per-page=<n>]
     * : How many.
     * ---
     * default: 20
     * ---
     *
     * [--format=<format>]
     * : table, json, csv, yaml, count or ids.
     * ---
     * default: table
     * ---
     *
     * @subcommand list
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function list_(array $args, array $assoc): void
    {
        $user = self::user((string) ($args[0] ?? ''));
        $result = Inbox::query($user, [
            'status' => ! empty($assoc['unread']) ? 'unread' : 'all',
            'type' => (string) ($assoc['type'] ?? ''),
            'per_page' => (int) ($assoc['per-page'] ?? 20),
        ]);
        $format = (string) ($assoc['format'] ?? 'table');

        if ($format === 'count') {
            WP_CLI::line((string) $result['total']);

            return;
        }
        if ($format === 'ids') {
            WP_CLI::line(implode(' ', array_map(static fn ($n): int => $n->id, $result['items'])));

            return;
        }

        $rows = array_map(static fn ($n): array => [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $n->title,
            'read' => $n->isUnread() ? 'no' : 'yes',
            'created_at' => $n->createdAt,
            'url' => $n->url,
        ], $result['items']);
        Utils\format_items($format, $rows, ['id', 'type', 'title', 'read', 'created_at', 'url']);
    }

    /**
     * Deletes old notifications now, like the daily cleanup.
     *
     * ## OPTIONS
     *
     * [--days=<days>]
     * : Older than this many days. Defaults to the setting.
     *
     * [--include-unread]
     * : Delete old unread notifications too.
     *
     * [--dry-run]
     * : Only count them.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function prune(array $args, array $assoc): void
    {
        $days = isset($assoc['days']) ? (int) $assoc['days'] : Settings::int('retention_days');
        if ($days <= 0) {
            WP_CLI::error('Give --days greater than 0 (the setting keeps everything).');
        }
        $keepUnread = empty($assoc['include-unread']) && Settings::bool('keep_unread');

        if (! empty($assoc['dry-run'])) {
            WP_CLI::success(sprintf('%d notifications are older than %d days.', Store::prunable($days, $keepUnread), $days));

            return;
        }
        $deleted = Store::prune($days, $keepUnread);
        Mailer::prune();
        WP_CLI::success(sprintf('Deleted %d notifications older than %d days.', $deleted, $days));
    }

    /**
     * Lists the registered notification types.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function types(array $args, array $assoc): void
    {
        $rows = [];
        foreach (Types::all() as $name => $type) {
            $rows[] = [
                'type' => $name,
                'label' => $type['label'],
                'group' => $type['group'],
                'channels' => implode(',', $type['channels']),
                'defaults' => implode(',', array_keys(array_filter($type['defaults']))),
                'required' => $type['required'] ? 'yes' : 'no',
                'source' => $type['source'],
            ];
        }
        Utils\format_items((string) ($assoc['format'] ?? 'table'), $rows, ['type', 'label', 'group', 'channels', 'defaults', 'required', 'source']);
    }

    /**
     * Shows or sends the emails waiting for WP-Cron.
     *
     * ## OPTIONS
     *
     * <action>
     * : status or run.
     * ---
     * options:
     *   - status
     *   - run
     * ---
     *
     * [--now]
     * : With run: don't wait for the delay.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function emails(array $args, array $assoc): void
    {
        if (($args[0] ?? 'status') === 'run') {
            $sent = Mailer::run(null, ! empty($assoc['now']));
            WP_CLI::success(sprintf('Sent %d emails. %d waiting.', $sent, Mailer::pending()));

            return;
        }
        foreach (Mailer::counts() as $status => $count) {
            WP_CLI::line(sprintf('%s: %d', $status, $count));
        }
    }

    /**
     * Seeds Halyard's demo: four teammates, a few posts with notes, and a
     * realistic inbox for Maya (user 1). For trying the plugin out.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Don't ask.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function demo(array $args, array $assoc): void
    {
        WP_CLI::confirm('Add Halyard’s demo users, posts and notifications to this site?', $assoc);
        $counts = Demo::seed();
        WP_CLI::success(sprintf('Halyard is ready: %d people, %d posts, %d notifications.', $counts['users'], $counts['posts'], $counts['notifications']));
    }

    private static function user(string $value): int
    {
        $user = self::find($value);
        if ($user === null) {
            WP_CLI::error(sprintf('There’s no user “%s”.', $value));
        }

        return $user->ID ?? 0;
    }

    private static function find(string $value): ?WP_User
    {
        $user = is_numeric($value) ? get_userdata((int) $value) : (get_user_by('login', $value) ?: get_user_by('email', $value));

        return $user instanceof WP_User ? $user : null;
    }
}

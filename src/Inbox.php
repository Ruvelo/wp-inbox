<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox;

use WP_Error;
use WP_User;

/**
 * The developer API. Other plugins send through `Inbox::notify()`, or,
 * without a hard dependency, through `do_action('ruvelo_inbox_notify', $users, $message)`.
 *
 * Nothing here checks capabilities: it's the one write path that the REST
 * API, WP-CLI, abilities, sources and screens all use, and they check.
 */
final class Inbox
{
    private static bool $paused = false;

    /**
     * Sends a notification to one or more people.
     *
     * `$message` keys: `title` (required), `type` (a registered type; default
     * `general`), `body`, `url`, `icon` (a short string or emoji), `actor`
     * (a user ID, WP_User or name: who did it), `data` (anything JSON can
     * hold), `created_at` (a timestamp, for imports).
     *
     * Each person gets it on the channels they want for that type: a row in
     * their inbox, and an email that WP-Cron sends a few minutes later
     * (bundled with anything else that arrived meanwhile).
     *
     * @param int|WP_User|array<int|WP_User> $users
     * @param array<string, mixed> $message
     * @return list<int> The IDs of the inbox rows created.
     */
    public static function notify(int|WP_User|array $users, array $message): array
    {
        if (self::$paused) {
            return [];
        }

        $userIds = self::userIds($users);
        $message = self::normalize($message);

        if ($message['title'] === '') {
            _doing_it_wrong(__METHOD__, esc_html__('A notification needs a title.', 'ruvelo-inbox'), '1.0.0');

            return [];
        }

        /**
         * Filters a notification before it is sent. Return an empty title to
         * cancel it.
         *
         * @param array<string, mixed> $message
         * @param list<int> $userIds
         */
        $filtered = apply_filters('ruvelo_inbox_message', $message, $userIds);
        if (is_array($filtered)) {
            $message = self::normalize($filtered);
        }

        /**
         * Filters who gets a notification.
         *
         * @param list<int> $userIds
         * @param array<string, mixed> $message
         */
        $userIds = self::userIds(apply_filters('ruvelo_inbox_recipients', $userIds, $message));

        if ($message['title'] === '' || $userIds === []) {
            return [];
        }

        /**
         * Short-circuits sending: return an array of IDs to skip the database
         * and email entirely (the test fake does this).
         *
         * @param list<int>|null $short
         * @param list<int> $userIds
         * @param array<string, mixed> $message
         */
        $short = apply_filters('ruvelo_inbox_pre_notify', null, $userIds, $message);
        if (is_array($short)) {
            return array_values(array_map('intval', $short));
        }

        $type = $message['type'];
        $sendsEmail = Types::get($type)['sends_email'] && Settings::bool('emails');
        $ids = [];

        foreach ($userIds as $userId) {
            $channels = [];
            foreach (Types::get($type)['channels'] as $channel) {
                if (Preferences::wants($userId, $type, $channel)) {
                    $channels[] = $channel;
                }
            }

            /**
             * Filters the channels one person gets a notification on.
             *
             * @param list<string> $channels
             * @param int $userId
             * @param array<string, mixed> $message
             */
            $channels = (array) apply_filters('ruvelo_inbox_notification_channels', $channels, $userId, $message);

            $id = 0;
            if (in_array('inbox', $channels, true)) {
                $id = Store::insert([
                    'user_id' => $userId,
                    'type' => $type,
                    'title' => $message['title'],
                    'body' => $message['body'],
                    'url' => $message['url'],
                    'icon' => $message['icon'],
                    'actor_id' => $message['actor_id'],
                    'actor_name' => $message['actor_name'],
                    'data' => $message['data'],
                    'created_at' => $message['created_at'],
                    'read_at' => $message['read_at'],
                ]);
                if ($id > 0) {
                    $ids[] = $id;

                    /**
                     * Fires after a notification lands in someone's inbox.
                     *
                     * @param int $id
                     * @param int $userId
                     * @param array<string, mixed> $message
                     */
                    do_action('ruvelo_inbox_notification_created', $id, $userId, $message);
                }
            }

            if ($sendsEmail && in_array('email', $channels, true)) {
                Mailer::queue($userId, $id, $message);
            }
        }

        /**
         * Fires once a notification has gone to everyone.
         *
         * @param list<int> $ids Inbox rows created.
         * @param list<int> $userIds
         * @param array<string, mixed> $message
         */
        do_action('ruvelo_inbox_notified', $ids, $userIds, $message);

        return $ids;
    }

    /**
     * The `ruvelo_inbox_notify` action: send without depending on this plugin.
     */
    public static function fromAction(mixed $users = [], mixed $message = []): void
    {
        if (is_array($message) && (is_int($users) || is_numeric($users) || $users instanceof WP_User || is_array($users))) {
            self::notify(is_numeric($users) ? (int) $users : $users, $message);
        }
    }

    /**
     * Stops sending while true: for imports and the demo.
     */
    public static function pause(bool $paused = true): void
    {
        self::$paused = $paused;
    }

    public static function paused(): bool
    {
        return self::$paused;
    }

    /**
     * Registers a notification type (see Types::register()).
     *
     * @param array<string, mixed> $args
     */
    public static function registerType(string $name, array $args = []): bool
    {
        return Types::register($name, $args);
    }

    /**
     * The `ruvelo_inbox_register_type` action.
     */
    public static function registerFromAction(mixed $name = '', mixed $args = []): void
    {
        if (is_string($name)) {
            Types::register($name, is_array($args) ? $args : []);
        }
    }

    public static function unreadCount(int $userId): int
    {
        return Store::unreadCount($userId);
    }

    /**
     * @return list<Notification>
     */
    public static function latest(int $userId, int $limit = 6): array
    {
        return Store::latest($userId, $limit);
    }

    /**
     * @param array{status?: string, type?: string, page?: int, per_page?: int} $args
     * @return array{items: list<Notification>, total: int}
     */
    public static function query(int $userId, array $args = []): array
    {
        return Store::query($userId, $args);
    }

    /**
     * One of this person's notifications; null if it isn't theirs.
     */
    public static function find(int $id, int $userId): ?Notification
    {
        return Store::findFor($id, $userId);
    }

    public static function markRead(int $id, int $userId): bool
    {
        $changed = Store::setRead($id, $userId, true);
        if ($changed) {
            /**
             * Fires when a notification is marked read.
             *
             * @param int $id
             * @param int $userId
             */
            do_action('ruvelo_inbox_read', $id, $userId);
        }

        return $changed;
    }

    public static function markUnread(int $id, int $userId): bool
    {
        $changed = Store::setRead($id, $userId, false);
        if ($changed) {
            /**
             * Fires when a notification is marked unread.
             *
             * @param int $id
             * @param int $userId
             */
            do_action('ruvelo_inbox_unread', $id, $userId);
        }

        return $changed;
    }

    /**
     * Marks everything read, or only one type.
     *
     * @return int How many changed.
     */
    public static function markAllRead(int $userId, string $type = ''): int
    {
        $count = Store::markAllRead($userId, null, $type);

        /**
         * Fires after "Mark all as read".
         *
         * @param int $userId
         * @param int $count
         */
        do_action('ruvelo_inbox_read_all', $userId, $count);

        return $count;
    }

    public static function delete(int $id, int $userId): bool
    {
        $deleted = Store::delete($userId, [$id]) > 0;
        if ($deleted) {
            /**
             * Fires after a notification is deleted.
             *
             * @param int $id
             * @param int $userId
             */
            do_action('ruvelo_inbox_deleted', $id, $userId);
        }

        return $deleted;
    }

    /**
     * Bulk actions from the inbox: read, unread or delete.
     *
     * @param list<int> $ids
     */
    public static function bulk(int $userId, string $action, array $ids): int
    {
        return match ($action) {
            'read' => Store::markAllRead($userId, $ids),
            'unread' => Store::markUnread($userId, $ids),
            'delete' => array_sum(array_map(static fn (int $id): int => self::delete($id, $userId) ? 1 : 0, $ids)),
            default => 0,
        };
    }

    public static function wants(int $userId, string $type, string $channel): bool
    {
        return Preferences::wants($userId, $type, $channel);
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public static function preferences(int $userId): array
    {
        return Preferences::forUser($userId);
    }

    /**
     * @param array<mixed> $choices
     */
    public static function updatePreferences(int $userId, array $choices): true|WP_Error
    {
        return Preferences::update($userId, $choices);
    }

    /**
     * @return list<int>
     */
    private static function userIds(mixed $users): array
    {
        $ids = [];
        foreach (is_array($users) ? $users : [$users] as $user) {
            $id = $user instanceof WP_User ? $user->ID : (is_numeric($user) ? (int) $user : 0);
            if ($id > 0 && ! in_array($id, $ids, true) && get_userdata($id) !== false) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $message
     * @return array{type: string, title: string, body: string, url: string, icon: string, actor_id: int, actor_name: string, data: array<string, mixed>, created_at: string, read_at: string|null}
     */
    public static function normalize(array $message): array
    {
        $type = isset($message['type']) && is_string($message['type']) ? sanitize_key($message['type']) : '';
        $actorId = isset($message['actor_id']) && is_numeric($message['actor_id']) ? (int) $message['actor_id'] : 0;
        $actorName = isset($message['actor_name']) && is_string($message['actor_name']) ? $message['actor_name'] : '';
        $actor = $message['actor'] ?? null;
        if ($actor instanceof WP_User) {
            $actorId = $actor->ID;
        } elseif (is_numeric($actor)) {
            $actorId = (int) $actor;
        } elseif (is_string($actor) && $actor !== '') {
            $actorName = $actor;
        }
        if ($actorId > 0 && $actorName === '') {
            $user = get_userdata($actorId);
            $actorName = $user !== false ? $user->display_name : '';
        }

        $created = $message['created_at'] ?? null;
        $createdAt = is_int($created) ? gmdate('Y-m-d H:i:s', $created) : (is_string($created) && strtotime($created) !== false ? $created : current_time('mysql', true));
        $read = $message['read_at'] ?? null;
        $readAt = is_int($read) ? gmdate('Y-m-d H:i:s', $read) : (is_string($read) && strtotime($read) !== false ? $read : null);

        $title = isset($message['title']) && is_scalar($message['title']) ? sanitize_text_field((string) $message['title']) : '';
        $body = isset($message['body']) && is_scalar($message['body']) ? sanitize_textarea_field((string) $message['body']) : '';
        $url = isset($message['url']) && is_string($message['url']) ? esc_url_raw(trim($message['url']), ['http', 'https']) : '';
        $icon = isset($message['icon']) && is_scalar($message['icon']) ? sanitize_text_field((string) $message['icon']) : '';
        $data = isset($message['data']) && is_array($message['data']) ? $message['data'] : [];

        return [
            'type' => $type !== '' ? $type : 'general',
            'title' => mb_substr($title, 0, 255),
            'body' => mb_substr($body, 0, 5000),
            'url' => $url,
            'icon' => mb_substr($icon, 0, 8),
            'actor_id' => max(0, $actorId),
            'actor_name' => mb_substr(sanitize_text_field($actorName), 0, 190),
            'data' => $data,
            'created_at' => $createdAt,
            'read_at' => $readAt,
        ];
    }
}

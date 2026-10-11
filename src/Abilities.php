<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox;

use WP_Error;

/**
 * The inbox for AI agents, through the WordPress Abilities API (6.9+).
 *
 * Agents act as the signed-in person: they read and tidy that person's own
 * inbox, nobody else's. Sending to other people needs
 * `ruvelo_send_notifications`, checked on every call.
 */
final class Abilities
{
    public const CATEGORY = 'ruvelo-inbox';

    public const LIST = 'ruvelo-inbox/list-notifications';

    public const COUNT = 'ruvelo-inbox/get-unread-count';

    public const MARK_READ = 'ruvelo-inbox/mark-read';

    public const SEND = 'ruvelo-inbox/send-notification';

    public static function register(): void
    {
        add_action('wp_abilities_api_categories_init', [self::class, 'registerCategory']);
        add_action('wp_abilities_api_init', [self::class, 'registerAbilities']);
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return [self::LIST, self::COUNT, self::MARK_READ, self::SEND];
    }

    public static function registerCategory(): void
    {
        wp_register_ability_category(self::CATEGORY, [
            'label' => __('Notifications', 'ruvelo-inbox'),
            'description' => __('Read and tidy the signed-in person’s notifications, and send notifications to people.', 'ruvelo-inbox'),
        ]);
    }

    public static function registerAbilities(): void
    {
        $notification = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'type' => ['type' => 'string'],
                'type_label' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'body' => ['type' => 'string'],
                'url' => ['type' => 'string'],
                'read' => ['type' => 'boolean'],
                'created_at' => ['type' => 'string'],
                'time_ago' => ['type' => 'string'],
            ],
        ];
        // WordPress 7.0 exposes abilities over REST through show_in_rest; 7.1 added public. Both are set.
        $readonly = ['public' => true, 'show_in_rest' => true, 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]];

        wp_register_ability(self::LIST, [
            'label' => __('List my notifications', 'ruvelo-inbox'),
            'description' => __('The signed-in person’s notifications, newest first: what happened (title and body), where it leads (url), its type, whether it’s been read and when it arrived. Use status "unread" to see only what’s new.', 'ruvelo-inbox'),
            'category' => self::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['all', 'unread'], 'default' => 'all'],
                    'type' => ['type' => 'string', 'description' => __('Only this notification type, like "note_mention".', 'ruvelo-inbox')],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'unread' => ['type' => 'integer'],
                    'total' => ['type' => 'integer'],
                    'notifications' => ['type' => 'array', 'items' => $notification],
                ],
            ],
            'execute_callback' => [self::class, 'list'],
            'permission_callback' => [self::class, 'signedIn'],
            'meta' => $readonly,
        ]);

        wp_register_ability(self::COUNT, [
            'label' => __('Count my unread notifications', 'ruvelo-inbox'),
            'description' => __('How many notifications the signed-in person hasn’t read yet.', 'ruvelo-inbox'),
            'category' => self::CATEGORY,
            'input_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            'output_schema' => ['type' => 'object', 'properties' => ['unread' => ['type' => 'integer']]],
            'execute_callback' => [self::class, 'count'],
            'permission_callback' => [self::class, 'signedIn'],
            'meta' => $readonly,
        ]);

        wp_register_ability(self::MARK_READ, [
            'label' => __('Mark my notifications as read', 'ruvelo-inbox'),
            'description' => __('Marks some of the signed-in person’s notifications as read (by ID), or all of them when no IDs are given.', 'ruvelo-inbox'),
            'category' => self::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 100],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'object', 'properties' => ['marked' => ['type' => 'integer'], 'unread' => ['type' => 'integer']]],
            'execute_callback' => [self::class, 'markRead'],
            'permission_callback' => [self::class, 'signedIn'],
            'meta' => ['public' => true, 'show_in_rest' => true, 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
        ]);

        wp_register_ability(self::SEND, [
            'label' => __('Send a notification', 'ruvelo-inbox'),
            'description' => __('Sends a notification to people on this site (by user ID, login or email). It lands in their inbox, and by email if they chose that for the type. Needs the ruvelo_send_notifications capability.', 'ruvelo-inbox'),
            'category' => self::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'to' => ['type' => 'array', 'items' => ['type' => ['integer', 'string']], 'minItems' => 1, 'maxItems' => 100],
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                    'body' => ['type' => 'string', 'maxLength' => 5000],
                    'url' => ['type' => 'string', 'format' => 'uri'],
                    'type' => ['type' => 'string', 'default' => 'general'],
                ],
                'required' => ['to', 'title'],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'object', 'properties' => ['recipients' => ['type' => 'integer'], 'ids' => ['type' => 'array', 'items' => ['type' => 'integer']]]],
            'execute_callback' => [self::class, 'send'],
            'permission_callback' => [self::class, 'canSend'],
            'meta' => ['public' => true, 'show_in_rest' => true, 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
        ]);
    }

    public static function signedIn(): bool
    {
        return is_user_logged_in();
    }

    public static function canSend(): bool
    {
        return is_user_logged_in() && current_user_can(Settings::CAP_SEND);
    }

    /**
     * @return array{unread: int, total: int, notifications: list<array<string, mixed>>}
     */
    public static function list(mixed $input = []): array
    {
        $input = is_array($input) ? $input : [];
        $userId = get_current_user_id();
        $result = Inbox::query($userId, [
            'status' => ($input['status'] ?? 'all') === 'unread' ? 'unread' : 'all',
            'type' => isset($input['type']) && is_string($input['type']) ? sanitize_key($input['type']) : '',
            'per_page' => isset($input['limit']) && is_numeric($input['limit']) ? max(1, min(50, (int) $input['limit'])) : 10,
        ]);

        $items = [];
        foreach ($result['items'] as $notification) {
            $item = $notification->toArray();
            $items[] = array_intersect_key($item, array_flip(['id', 'type', 'type_label', 'title', 'body', 'url', 'read', 'created_at', 'time_ago']));
        }

        return ['unread' => Inbox::unreadCount($userId), 'total' => $result['total'], 'notifications' => $items];
    }

    /**
     * @return array{unread: int}
     */
    public static function count(mixed $input = []): array
    {
        return ['unread' => Inbox::unreadCount(get_current_user_id())];
    }

    /**
     * @return array{marked: int, unread: int}
     */
    public static function markRead(mixed $input = []): array
    {
        $userId = get_current_user_id();
        $ids = is_array($input) && isset($input['ids']) && is_array($input['ids']) ? array_values(array_map('intval', $input['ids'])) : [];
        $marked = $ids === [] ? Inbox::markAllRead($userId) : Inbox::bulk($userId, 'read', $ids);

        return ['marked' => $marked, 'unread' => Inbox::unreadCount($userId)];
    }

    /**
     * @return array{recipients: int, ids: list<int>}|WP_Error
     */
    public static function send(mixed $input = []): array|WP_Error
    {
        $input = is_array($input) ? $input : [];
        $users = [];
        foreach ((array) ($input['to'] ?? []) as $who) {
            $user = is_numeric($who) ? get_userdata((int) $who) : (is_string($who) ? (get_user_by('login', $who) ?: get_user_by('email', $who)) : false);
            if ($user === false) {
                /* translators: %s: what the agent gave */
                return new WP_Error('ruvelo_inbox_unknown_user', sprintf(__('There’s no user “%s” on this site.', 'ruvelo-inbox'), is_scalar($who) ? (string) $who : ''));
            }
            $users[] = $user->ID;
        }
        $ids = Inbox::notify($users, [
            'type' => isset($input['type']) && is_string($input['type']) ? $input['type'] : 'general',
            'title' => isset($input['title']) && is_string($input['title']) ? $input['title'] : '',
            'body' => isset($input['body']) && is_string($input['body']) ? $input['body'] : '',
            'url' => isset($input['url']) && is_string($input['url']) ? $input['url'] : '',
            'actor' => get_current_user_id(),
        ]);

        return ['recipients' => count(array_unique($users)), 'ids' => $ids];
    }
}

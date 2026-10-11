<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Sources;

use Ruvelo\WP\Inbox\Inbox;
use Ruvelo\WP\Inbox\Preferences;
use Ruvelo\WP\Inbox\Types;

/**
 * Ruvelo Feedback: when a request someone posted or voted for changes
 * status or ships, it fires `ruvelo_feedback_notification` for each person
 * (from WP-Cron). Signed-in people get it in their inbox too, and can
 * switch Ruvelo Feedback's email off here (`ruvelo_feedback_send_email`).
 * Guests, who voted with an email address only, keep getting the email.
 *
 * Nothing happens unless Ruvelo Feedback is active.
 */
final class Feedback implements Source
{
    public const UPDATE = 'feedback_update';

    public static function label(): string
    {
        return __('Ruvelo Feedback', 'ruvelo-inbox');
    }

    public static function description(): string
    {
        return __('When a feature request someone posted or voted for is planned, started or shipped.', 'ruvelo-inbox');
    }

    public static function available(): bool
    {
        return defined('RUVELO_FEEDBACK_VERSION') || class_exists('Ruvelo\\WP\\Feedback\\Plugin', false);
    }

    public static function types(): void
    {
        Types::register(self::UPDATE, [
            'label' => __('Feedback you asked for', 'ruvelo-inbox'),
            'description' => __('When a request you posted or voted for changes status or ships.', 'ruvelo-inbox'),
            'group' => __('Feedback', 'ruvelo-inbox'),
            'defaults' => ['inbox' => true, 'email' => true],
            'icon' => '↗',
            'sends_email' => false, // Ruvelo Feedback sends it; the switch decides.
            'source' => 'feedback',
        ]);
    }

    public static function hooks(): void
    {
        add_action('ruvelo_feedback_notification', [self::class, 'received'], 10, 2);
        add_filter('ruvelo_feedback_send_email', [self::class, 'email'], 10, 3);
    }

    public static function unhook(): void
    {
        remove_action('ruvelo_feedback_notification', [self::class, 'received'], 10);
        remove_filter('ruvelo_feedback_send_email', [self::class, 'email'], 10);
    }

    /**
     * @param mixed $recipient array{user_id: int, email: string, name: string, role: string}
     * @param mixed $message array{subject: string, title: string, body: string, url: string, request_id: int, status: string, event: string, unsubscribe: string}
     */
    public static function received(mixed $recipient = [], mixed $message = []): void
    {
        if (! is_array($recipient) || ! is_array($message) || Inbox::paused()) {
            return;
        }
        $userId = isset($recipient['user_id']) && is_numeric($recipient['user_id']) ? (int) $recipient['user_id'] : 0;
        $title = isset($message['title']) && is_string($message['title']) ? $message['title'] : '';
        if ($userId <= 0 || $title === '') {
            return;
        }
        $event = isset($message['event']) && is_string($message['event']) ? $message['event'] : '';

        Sources::send($userId, [
            'type' => self::UPDATE,
            'title' => $title,
            'body' => isset($message['body']) && is_string($message['body']) ? $message['body'] : '',
            'url' => isset($message['url']) && is_string($message['url']) ? $message['url'] : '',
            'icon' => $event === 'shipped' ? '✓' : '↗',
            'data' => [
                'request_id' => isset($message['request_id']) && is_numeric($message['request_id']) ? (int) $message['request_id'] : 0,
                'status' => isset($message['status']) && is_string($message['status']) ? $message['status'] : '',
                'event' => $event,
                'role' => isset($recipient['role']) && is_string($recipient['role']) ? $recipient['role'] : '',
            ],
        ]);
    }

    /**
     * Ruvelo Feedback's email, only if a signed-in recipient still wants it.
     */
    public static function email(mixed $send = true, mixed $recipient = [], mixed $message = []): bool
    {
        if (! $send || ! is_array($recipient)) {
            return (bool) $send;
        }
        $userId = isset($recipient['user_id']) && is_numeric($recipient['user_id']) ? (int) $recipient['user_id'] : 0;

        return $userId <= 0 || Preferences::wants($userId, self::UPDATE, 'email');
    }
}

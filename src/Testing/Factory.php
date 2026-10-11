<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Testing;

use Ruvelo\WP\Inbox\Notification;
use Ruvelo\WP\Inbox\Store;

/**
 * Notifications for your own plugin's tests, written straight to the inbox
 * (no preferences, no email):
 *
 *     $n = Factory::create($user_id, ['type' => 'invoice_paid', 'read' => true]);
 *     Factory::createMany($user_id, 30);
 */
final class Factory
{
    private static int $sequence = 0;

    /**
     * @param array<string, mixed> $attributes type, title, body, url, icon, actor_id, actor_name, data, read (bool), created_at (timestamp or GMT date)
     */
    public static function create(int $userId, array $attributes = []): Notification
    {
        self::$sequence++;
        $created = $attributes['created_at'] ?? time();
        $createdAt = is_int($created) ? gmdate('Y-m-d H:i:s', $created) : (string) $created;
        $read = $attributes['read'] ?? false;

        $id = Store::insert([
            'user_id' => $userId,
            'type' => (string) ($attributes['type'] ?? 'general'),
            'title' => (string) ($attributes['title'] ?? 'Notification '.self::$sequence),
            'body' => (string) ($attributes['body'] ?? ''),
            'url' => (string) ($attributes['url'] ?? ''),
            'icon' => (string) ($attributes['icon'] ?? ''),
            'actor_id' => (int) ($attributes['actor_id'] ?? 0),
            'actor_name' => (string) ($attributes['actor_name'] ?? ''),
            'data' => is_array($attributes['data'] ?? null) ? $attributes['data'] : [],
            'created_at' => $createdAt,
            'read_at' => $read ? (is_string($read) ? $read : $createdAt) : null,
        ]);

        $notification = Store::find($id);
        if ($notification === null) {
            throw new \RuntimeException('The notification could not be saved.');
        }

        return $notification;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return list<Notification>
     */
    public static function createMany(int $userId, int $count, array $attributes = []): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = self::create($userId, $attributes + ['created_at' => time() - ($count - $i) * MINUTE_IN_SECONDS]);
        }

        return $out;
    }
}

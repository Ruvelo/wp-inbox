<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox;

/**
 * Live updates over the WordPress Heartbeat API, which the admin already
 * runs: no polling of our own. The bell sends the newest id it has; the
 * reply carries the unread count, and the fresh list only when something
 * new arrived.
 */
final class Heartbeat
{
    public const KEY = 'ruvelo_inbox';

    public static function register(): void
    {
        add_filter('heartbeat_received', [self::class, 'received'], 10, 2);
    }

    /**
     * @param array<string, mixed> $response
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function received(mixed $response, mixed $data): array
    {
        $response = is_array($response) ? $response : [];
        if (! is_array($data) || ! isset($data[self::KEY]) || ! is_user_logged_in()) {
            return $response;
        }
        $client = is_array($data[self::KEY]) ? $data[self::KEY] : [];
        $latest = isset($client['latest']) && is_numeric($client['latest']) ? (int) $client['latest'] : 0;
        $base = isset($client['base']) && is_string($client['base']) ? esc_url_raw($client['base']) : '';

        $response[self::KEY] = self::payload(get_current_user_id(), $latest, wp_validate_redirect($base, ''));

        return $response;
    }

    /**
     * @return array{unread: int, latest: int, fresh: int, html: string|null}
     */
    public static function payload(int $userId, int $knownLatest, string $base = ''): array
    {
        $latest = Store::latestId($userId);
        $changed = $latest > $knownLatest;

        $payload = [
            'unread' => Store::unreadCount($userId),
            'latest' => $latest,
            'fresh' => $changed && $knownLatest > 0 ? Store::newSince($userId, $knownLatest) : 0,
            'html' => $changed ? Views::bellItems($userId, $base) : null,
        ];

        /**
         * Filters what the Heartbeat sends the bell.
         *
         * @param array{unread: int, latest: int, fresh: int, html: string|null} $payload
         * @param int $userId
         */
        return apply_filters('ruvelo_inbox_heartbeat', $payload, $userId);
    }
}

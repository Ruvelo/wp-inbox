<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox;

/**
 * The notifications table. Every query is scoped to one person, except the
 * cleanup. No capability checks here: callers decide who may do what.
 */
final class Store
{
    private const CACHE_GROUP = 'ruvelo_inbox';

    /**
     * @param array{user_id: int, type: string, title: string, body: string, url: string, icon: string, actor_id: int, actor_name: string, data: array<string, mixed>, created_at: string, read_at?: string|null} $row
     */
    public static function insert(array $row): int
    {
        global $wpdb;

        $values = [
            'user_id' => $row['user_id'],
            'type' => $row['type'],
            'title' => $row['title'],
            'body' => $row['body'],
            'url' => $row['url'],
            'icon' => $row['icon'],
            'actor_id' => $row['actor_id'],
            'actor_name' => $row['actor_name'],
            'data' => (string) wp_json_encode($row['data']),
            'created_at' => $row['created_at'],
        ];
        $formats = ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s'];
        if (! empty($row['read_at'])) {
            $values['read_at'] = $row['read_at'];
            $formats[] = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
        $ok = $wpdb->insert(Install::notificationsTable(), $values, $formats);
        self::flush($row['user_id']);

        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public static function find(int $id): ?Notification
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', Install::notificationsTable(), $id), ARRAY_A);

        return is_array($row) ? Notification::fromRow($row) : null;
    }

    /**
     * One of this person's notifications, or null (also when it's someone else's).
     */
    public static function findFor(int $id, int $userId): ?Notification
    {
        $notification = self::find($id);

        return $notification !== null && $notification->userId === $userId && $userId > 0 ? $notification : null;
    }

    /**
     * A page of someone's notifications, newest first.
     *
     * @param array{status?: string, type?: string, page?: int, per_page?: int} $args
     * @return array{items: list<Notification>, total: int}
     */
    public static function query(int $userId, array $args = []): array
    {
        global $wpdb;

        $table = Install::notificationsTable();
        $perPage = max(1, min(100, (int) ($args['per_page'] ?? 20)));
        $page = max(1, (int) ($args['page'] ?? 1));
        $type = (string) ($args['type'] ?? '');
        $unread = ($args['status'] ?? 'all') === 'unread';

        $where = $wpdb->prepare('user_id = %d', $userId);
        if ($unread) {
            $where .= ' AND read_at IS NULL';
        }
        if ($type !== '') {
            $where .= $wpdb->prepare(' AND type = %s', $type);
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; $where is built from prepare() above.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$where}", $table));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $table, $perPage, ($page - 1) * $perPage), ARRAY_A);
        // phpcs:enable

        $items = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $items[] = Notification::fromRow($row);
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return list<Notification>
     */
    public static function latest(int $userId, int $limit = 6): array
    {
        return self::query($userId, ['per_page' => $limit])['items'];
    }

    /**
     * One indexed COUNT, cached until something changes.
     */
    public static function unreadCount(int $userId): int
    {
        global $wpdb;

        if ($userId <= 0) {
            return 0;
        }
        $cached = wp_cache_get('unread:'.$userId, self::CACHE_GROUP);
        if (is_numeric($cached)) {
            return (int) $cached;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table, cached just above.
        $count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE user_id = %d AND read_at IS NULL', Install::notificationsTable(), $userId));
        wp_cache_set('unread:'.$userId, $count, self::CACHE_GROUP, HOUR_IN_SECONDS);

        return $count;
    }

    /**
     * The newest notification's id: the Heartbeat compares it to see if the
     * bell's list is stale.
     */
    public static function latestId(int $userId): int
    {
        global $wpdb;

        $cached = wp_cache_get('latest:'.$userId, self::CACHE_GROUP);
        if (is_numeric($cached)) {
            return (int) $cached;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table, cached just above.
        $id = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM %i WHERE user_id = %d', Install::notificationsTable(), $userId));
        wp_cache_set('latest:'.$userId, $id, self::CACHE_GROUP, HOUR_IN_SECONDS);

        return $id;
    }

    /**
     * Unread notifications newer than an id (for the Heartbeat's announcement).
     */
    public static function newSince(int $userId, int $afterId): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; runs only when the list changed.
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE user_id = %d AND id > %d AND read_at IS NULL', Install::notificationsTable(), $userId, $afterId));
    }

    /**
     * Types this person has notifications of, for the inbox's filter.
     *
     * @return list<string>
     */
    public static function typesFor(int $userId): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $types = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT type FROM %i WHERE user_id = %d', Install::notificationsTable(), $userId));

        return array_values(array_map('strval', is_array($types) ? $types : []));
    }

    public static function setRead(int $id, int $userId, bool $read): bool
    {
        global $wpdb;

        $table = Install::notificationsTable();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $changed = $read
            ? $wpdb->query($wpdb->prepare('UPDATE %i SET read_at = %s WHERE id = %d AND user_id = %d AND read_at IS NULL', $table, current_time('mysql', true), $id, $userId))
            : $wpdb->query($wpdb->prepare('UPDATE %i SET read_at = NULL WHERE id = %d AND user_id = %d AND read_at IS NOT NULL', $table, $id, $userId));
        // phpcs:enable
        self::flush($userId);

        return (int) $changed > 0;
    }

    /**
     * @param list<int>|null $ids Only these; null for all.
     */
    public static function markAllRead(int $userId, ?array $ids = null, string $type = ''): int
    {
        global $wpdb;

        $table = Install::notificationsTable();
        $where = $wpdb->prepare('user_id = %d AND read_at IS NULL', $userId);
        if ($type !== '') {
            $where .= $wpdb->prepare(' AND type = %s', $type);
        }
        if ($ids !== null) {
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if ($ids === []) {
                return 0;
            }
            $where .= $wpdb->prepare(' AND id IN ('.implode(',', array_fill(0, count($ids), '%d')).')', ...$ids); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One %d placeholder per id.
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; $where is prepared above and the ids are integers.
        $count = (int) $wpdb->query($wpdb->prepare("UPDATE %i SET read_at = %s WHERE {$where}", $table, current_time('mysql', true)));
        self::flush($userId);

        return $count;
    }

    /**
     * @param list<int> $ids
     */
    public static function markUnread(int $userId, array $ids): int
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom table; one %d placeholder per id.
        $count = (int) $wpdb->query($wpdb->prepare("UPDATE %i SET read_at = NULL WHERE user_id = %d AND read_at IS NOT NULL AND id IN ({$in})", Install::notificationsTable(), $userId, ...$ids));
        self::flush($userId);

        return $count;
    }

    /**
     * @param list<int> $ids
     */
    public static function delete(int $userId, array $ids): int
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom table; one %d placeholder per id.
        $count = (int) $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE user_id = %d AND id IN ({$in})", Install::notificationsTable(), $userId, ...$ids));
        self::flush($userId);

        return $count;
    }

    public static function deleteForUser(int $userId): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $count = (int) $wpdb->delete(Install::notificationsTable(), ['user_id' => $userId], ['%d']);
        self::flush($userId);

        return $count;
    }

    /**
     * Deletes notifications older than the retention period, a few thousand
     * rows at a time so a big table never locks for long.
     *
     * @return int Rows deleted.
     */
    public static function prune(int $days, bool $keepUnread = true): int
    {
        global $wpdb;

        if ($days <= 0) {
            return 0;
        }
        $table = Install::notificationsTable();
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $deleted = 0;
        do {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
            $batch = $keepUnread
                ? (int) $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE created_at < %s AND read_at IS NOT NULL LIMIT 2000', $table, $cutoff))
                : (int) $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE created_at < %s LIMIT 2000', $table, $cutoff));
            // phpcs:enable
            $deleted += $batch;
        } while ($batch === 2000);

        if ($deleted > 0) {
            wp_cache_flush_group(self::CACHE_GROUP);
        }

        return $deleted;
    }

    /**
     * Counts older than the retention period, for `prune --dry-run`.
     */
    public static function prunable(int $days, bool $keepUnread = true): int
    {
        global $wpdb;

        $cutoff = gmdate('Y-m-d H:i:s', time() - max(0, $days) * DAY_IN_SECONDS);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        return $keepUnread
            ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE created_at < %s AND read_at IS NOT NULL', Install::notificationsTable(), $cutoff))
            : (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE created_at < %s', Install::notificationsTable(), $cutoff));
        // phpcs:enable
    }

    public static function flush(int $userId): void
    {
        wp_cache_delete('unread:'.$userId, self::CACHE_GROUP);
        wp_cache_delete('latest:'.$userId, self::CACHE_GROUP);
    }
}

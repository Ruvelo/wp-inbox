<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox;

use WP_Error;

/**
 * Who wants what, where. Only explicit choices are stored (user option
 * `ruvelo_inbox_preferences`, per site); everything else follows the type's
 * default, so changing a default reaches everyone who never chose.
 */
final class Preferences
{
    public const META = 'ruvelo_inbox_preferences';

    /**
     * The choices a person made: type => channel => bool.
     *
     * @return array<string, array<string, bool>>
     */
    public static function explicit(int $userId): array
    {
        $saved = get_user_option(self::META, $userId);
        if (! is_array($saved)) {
            return [];
        }

        $clean = [];
        foreach ($saved as $type => $channels) {
            if (! is_string($type) || ! is_array($channels)) {
                continue;
            }
            foreach ($channels as $channel => $on) {
                if (is_string($channel)) {
                    $clean[$type][$channel] = (bool) $on;
                }
            }
        }

        return $clean;
    }

    /**
     * Whether this person wants this type on this channel.
     */
    public static function wants(int $userId, string $type, string $channel): bool
    {
        $args = Types::get($type);

        if (! in_array($channel, $args['channels'], true)) {
            $wants = false;
        } elseif ($args['required']) {
            $wants = true;
        } else {
            $explicit = self::explicit($userId);
            $wants = $explicit[$type][$channel] ?? $args['defaults'][$channel] ?? false;
        }

        /**
         * Filters whether a person gets a type on a channel.
         *
         * @param bool $wants
         * @param int $userId
         * @param string $type
         * @param string $channel
         */
        return (bool) apply_filters('ruvelo_inbox_wants', $wants, $userId, $type, $channel);
    }

    /**
     * Every registered type with its channels resolved for this person.
     *
     * @return array<string, array<string, bool>>
     */
    public static function forUser(int $userId): array
    {
        $out = [];
        foreach (array_keys(Types::all()) as $type) {
            foreach (Types::get($type)['channels'] as $channel) {
                $out[$type][$channel] = self::wants($userId, $type, $channel);
            }
        }

        return $out;
    }

    /**
     * Saves choices. Only the pairs given change. Unknown types or channels,
     * and turning off a required type, are errors and nothing is saved.
     *
     * @param array<mixed> $choices type => channel => bool
     */
    public static function update(int $userId, array $choices): true|WP_Error
    {
        $saved = self::explicit($userId);

        foreach ($choices as $type => $channels) {
            if (! is_string($type) || ! Types::exists($type)) {
                /* translators: %s: notification type */
                return new WP_Error('ruvelo_inbox_unknown_type', sprintf(__('There is no notification type called “%s”.', 'ruvelo-inbox'), (string) $type), ['status' => 422]);
            }
            if (! is_array($channels)) {
                return new WP_Error('ruvelo_inbox_invalid_preferences', __('Give each type an object of channel => true or false.', 'ruvelo-inbox'), ['status' => 422]);
            }
            $args = Types::get($type);
            foreach ($channels as $channel => $on) {
                if (! is_string($channel) || ! in_array($channel, $args['channels'], true)) {
                    /* translators: 1: channel, 2: notification type label */
                    return new WP_Error('ruvelo_inbox_unknown_channel', sprintf(__('“%1$s” isn’t a channel for %2$s.', 'ruvelo-inbox'), (string) $channel, $args['label']), ['status' => 422]);
                }
                $on = is_bool($on) ? $on : rest_sanitize_boolean(is_int($on) || is_string($on) ? $on : false);
                if ($args['required'] && ! $on) {
                    /* translators: %s: notification type label */
                    return new WP_Error('ruvelo_inbox_required', sprintf(__('%s can’t be turned off.', 'ruvelo-inbox'), $args['label']), ['status' => 422]);
                }
                $saved[$type][$channel] = $on;
            }
        }

        update_user_option($userId, self::META, $saved);

        /**
         * Fires after someone's preferences change.
         *
         * @param int $userId
         * @param array<string, array<string, bool>> $saved Every explicit choice now stored.
         */
        do_action('ruvelo_inbox_preferences_updated', $userId, $saved);

        return true;
    }

    /**
     * Turns email off for one type, or for every type (`*`). Used by the
     * unsubscribe links, so it never fails on a required type: it skips it.
     */
    public static function unsubscribe(int $userId, string $type): void
    {
        $saved = self::explicit($userId);
        $types = $type === '*' ? array_keys(Types::all()) : [$type];
        foreach ($types as $name) {
            $args = Types::get($name);
            if (in_array('email', $args['channels'], true) && ! $args['required']) {
                $saved[$name]['email'] = false;
            }
        }
        update_user_option($userId, self::META, $saved);

        /** This action is documented in src/Preferences.php */
        do_action('ruvelo_inbox_preferences_updated', $userId, $saved);
    }

    public static function forget(int $userId): void
    {
        delete_user_option($userId, self::META);
    }
}

<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox;

/**
 * The kinds of notification people can switch on and off.
 *
 * A type has a label, a description, a group on the preferences screen, the
 * channels it can use (`inbox`, `email`) and a default per channel. Notifying
 * with a type nobody registered still works: it lands in the inbox, labelled
 * after its key, with email off.
 *
 * @phpstan-type TypeArgs array{label: string, description: string, group: string, channels: list<string>, defaults: array<string, bool>, required: bool, icon: string, sends_email: bool, source: string}
 */
final class Types
{
    public const CHANNELS = ['inbox', 'email'];

    /** @var array<string, TypeArgs> */
    private static array $types = [];

    /**
     * Registers a type. Call it on `init` or later. Returns false (and
     * changes nothing) for an invalid name.
     *
     * Arguments: `label`, `description`, `group`, `channels` (default both),
     * `defaults` (channel => bool; default inbox on, email off), `required`
     * (shown, always on), `icon` (a short string or emoji), `sends_email`
     * (false when your plugin sends its own email and only wants the switch).
     *
     * @param array<string, mixed> $args
     */
    public static function register(string $name, array $args = []): bool
    {
        $key = sanitize_key($name);
        if ($key === '' || $key !== $name || strlen($key) > 100) {
            _doing_it_wrong(__METHOD__, esc_html__('Notification type names use lowercase letters, numbers, dashes and underscores, up to 100 characters.', 'ruvelo-inbox'), '1.0.0');

            return false;
        }

        /**
         * Filters a type's arguments as it is registered.
         *
         * @param array<string, mixed> $args
         * @param string $name
         */
        $args = apply_filters('ruvelo_inbox_type_args', $args, $key);
        self::$types[$key] = self::normalize($key, is_array($args) ? $args : []);

        /**
         * Fires after a notification type is registered.
         *
         * @param string $name
         * @param TypeArgs $args
         */
        do_action('ruvelo_inbox_type_registered', $key, self::$types[$key]);

        return true;
    }

    public static function unregister(string $name): void
    {
        unset(self::$types[$name]);
    }

    public static function exists(string $name): bool
    {
        return isset(self::$types[$name]);
    }

    /**
     * A registered type, or the fallback for one nobody registered.
     *
     * @return TypeArgs
     */
    public static function get(string $name): array
    {
        return self::$types[$name] ?? self::normalize($name, ['defaults' => ['inbox' => true, 'email' => false]]);
    }

    public static function label(string $name): string
    {
        return self::get($name)['label'];
    }

    /**
     * Every registered type, grouped and in the order registered.
     *
     * @return array<string, TypeArgs>
     */
    public static function all(): array
    {
        return self::$types;
    }

    /**
     * Types by group, for the preferences screen.
     *
     * @return array<string, array<string, TypeArgs>>
     */
    public static function grouped(): array
    {
        $groups = [];
        foreach (self::$types as $name => $type) {
            $groups[$type['group']][$name] = $type;
        }

        return $groups;
    }

    /**
     * Channel labels, for the preferences screen and the REST API.
     *
     * @return array<string, string>
     */
    public static function channels(): array
    {
        return [
            'inbox' => __('Inbox', 'ruvelo-inbox'),
            'email' => __('Email', 'ruvelo-inbox'),
        ];
    }

    /**
     * Drops every registration (tests).
     */
    public static function reset(): void
    {
        self::$types = [];
    }

    /**
     * @param array<string, mixed> $args
     * @return TypeArgs
     */
    private static function normalize(string $name, array $args): array
    {
        $channels = [];
        foreach ((array) ($args['channels'] ?? self::CHANNELS) as $channel) {
            if (is_string($channel) && in_array($channel, self::CHANNELS, true) && ! in_array($channel, $channels, true)) {
                $channels[] = $channel;
            }
        }
        if ($channels === []) {
            $channels = ['inbox'];
        }

        $defaults = [];
        $given = is_array($args['defaults'] ?? null) ? $args['defaults'] : [];
        foreach ($channels as $channel) {
            $defaults[$channel] = (bool) ($given[$channel] ?? ($channel === 'inbox'));
        }

        $label = isset($args['label']) && is_string($args['label']) && $args['label'] !== ''
            ? $args['label']
            : ucfirst(trim(str_replace(['_', '-'], ' ', $name)));

        return [
            'label' => $label,
            'description' => isset($args['description']) && is_string($args['description']) ? $args['description'] : '',
            'group' => isset($args['group']) && is_string($args['group']) && $args['group'] !== '' ? $args['group'] : __('Other', 'ruvelo-inbox'),
            'channels' => $channels,
            'defaults' => $defaults,
            'required' => ! empty($args['required']),
            'icon' => isset($args['icon']) && is_string($args['icon']) ? mb_substr($args['icon'], 0, 8) : '',
            'sends_email' => ! array_key_exists('sends_email', $args) || (bool) $args['sends_email'],
            'source' => isset($args['source']) && is_string($args['source']) ? $args['source'] : '',
        ];
    }
}

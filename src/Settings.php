<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox;

/**
 * Every setting in one place: defaults, the saved option, and the
 * `ruvelo_inbox_settings` filter so a site can define them in code.
 */
final class Settings
{
    public const OPTION = 'ruvelo_inbox_settings';

    /** Change the settings. */
    public const CAP_MANAGE = 'ruvelo_manage_inbox';

    /** Send notifications to other people (REST, abilities, the settings screen). */
    public const CAP_SEND = 'ruvelo_send_notifications';

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'sources' => ['notes' => true, 'comments' => true, 'posts' => true, 'feedback' => true],
            'emails' => true,           // the plugin's own emails (core and Ruvelo Feedback send theirs)
            'email_delay' => 5,         // minutes to wait, so a burst becomes one email
            'batch_size' => 50,         // people emailed per WP-Cron run
            'retention_days' => 90,     // 0 keeps everything
            'keep_unread' => true,      // cleanup spares unread notifications
            'bell_front' => true,       // the bell in the toolbar on the front end too
            'bell_limit' => 6,
            'per_page' => 20,
            'color_scheme' => 'auto',   // light | auto | dark
            'page_id' => 0,             // a front-end page with the Inbox block
            'delete_data' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (self::$cache === null) {
            $saved = get_option(self::OPTION, []);
            $settings = array_merge(self::defaults(), is_array($saved) ? $saved : []);

            /**
             * Filters the settings. Use it to define settings in code.
             *
             * @param array<string, mixed> $settings
             */
            $filtered = apply_filters('ruvelo_inbox_settings', $settings);
            self::$cache = is_array($filtered) ? $filtered : $settings;
        }

        return self::$cache;
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? self::defaults()[$key] ?? null;
    }

    public static function string(string $key): string
    {
        $value = self::get($key);

        return is_scalar($value) ? (string) $value : '';
    }

    public static function int(string $key): int
    {
        $value = self::get($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    public static function bool(string $key): bool
    {
        return (bool) self::get($key);
    }

    public static function source(string $key): bool
    {
        $sources = self::get('sources');
        $defaults = self::defaults()['sources'];

        if (! is_array($sources)) {
            return (bool) ($defaults[$key] ?? false);
        }

        return (bool) ($sources[$key] ?? $defaults[$key] ?? false);
    }

    public static function scheme(): string
    {
        $scheme = self::string('color_scheme');

        return in_array($scheme, ['light', 'dark', 'auto'], true) ? $scheme : 'auto';
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function update(array $values): void
    {
        $saved = get_option(self::OPTION, []);
        update_option(self::OPTION, array_merge(is_array($saved) ? $saved : [], $values));
        self::flush();
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * Cleans what the settings screen posts.
     *
     * @return array<string, mixed>
     */
    public static function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $defaults = self::defaults();
        $sources = [];
        foreach (array_keys($defaults['sources']) as $key) {
            $sources[$key] = ! empty($input['sources'][$key]);
        }
        $scheme = isset($input['color_scheme']) && is_string($input['color_scheme']) ? sanitize_key($input['color_scheme']) : 'auto';

        return [
            'sources' => $sources,
            'emails' => ! empty($input['emails']),
            'email_delay' => self::clamp($input['email_delay'] ?? 5, 0, 1440),
            'batch_size' => self::clamp($input['batch_size'] ?? 50, 1, 1000),
            'retention_days' => self::clamp($input['retention_days'] ?? 90, 0, 3650),
            'keep_unread' => ! empty($input['keep_unread']),
            'bell_front' => ! empty($input['bell_front']),
            'bell_limit' => self::clamp($input['bell_limit'] ?? 6, 1, 20),
            'per_page' => self::clamp($input['per_page'] ?? 20, 5, 100),
            'color_scheme' => in_array($scheme, ['light', 'dark', 'auto'], true) ? $scheme : 'auto',
            'page_id' => self::clamp($input['page_id'] ?? 0, 0, PHP_INT_MAX),
            'delete_data' => ! empty($input['delete_data']),
        ];
    }

    private static function clamp(mixed $value, int $min, int $max): int
    {
        $value = is_numeric($value) ? (int) $value : $min;

        return max($min, min($max, $value));
    }
}

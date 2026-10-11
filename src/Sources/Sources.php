<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Sources;

use Ruvelo\WP\Inbox\Inbox;
use Ruvelo\WP\Inbox\Settings;
use Ruvelo\WP\Inbox\Types;

/**
 * The notifications the plugin sends by itself: core Notes, comments,
 * posts going live, and Ruvelo Feedback. Each can be turned off in
 * Settings; each is a set of registered types people can switch.
 */
final class Sources
{
    /** @var array<string, class-string<Source>> */
    public const SOURCES = [
        'notes' => Notes::class,
        'comments' => Comments::class,
        'posts' => Posts::class,
        'feedback' => Feedback::class,
    ];

    /** @var list<string> */
    private static array $active = [];

    /**
     * Runs on `init` (priority 9): registers each enabled source's types and
     * hooks, then the built-in `general` type, then lets other plugins
     * register theirs.
     */
    public static function boot(): void
    {

        foreach (self::SOURCES as $key => $class) {
            if (Settings::source($key) && $class::available()) {
                $class::types();
                $class::hooks();
                self::$active[] = $key;
            }
        }

        // Last, so its "Other" group comes after the sources' groups.
        Types::register('general', [
            'label' => __('Announcements', 'ruvelo-inbox'),
            'description' => __('Messages from the site’s team, and notifications without a type of their own.', 'ruvelo-inbox'),
            'group' => __('Other', 'ruvelo-inbox'),
            'defaults' => ['inbox' => true, 'email' => false],
            'icon' => '•',
            'source' => 'ruvelo-inbox',
        ]);

        /**
         * Fires when other plugins should register their notification types:
         * `Ruvelo\WP\Inbox\Inbox::registerType()` or
         * `do_action('ruvelo_inbox_register_type', $name, $args)`.
         */
        do_action('ruvelo_inbox_register_types');
    }

    /**
     * Unhooks every source and registers everything again, from the current
     * settings (after the settings change, and in tests).
     */
    public static function reload(): void
    {
        foreach (self::SOURCES as $class) {
            $class::unhook();
        }
        self::$active = [];
        Types::reset();
        self::boot();
    }

    /**
     * @return list<string>
     */
    public static function active(): array
    {
        return self::$active;
    }

    public static function isActive(string $key): bool
    {
        return in_array($key, self::$active, true);
    }

    /**
     * Labels and availability for the settings screen.
     *
     * @return array<string, array{label: string, description: string, available: bool}>
     */
    public static function describe(): array
    {
        $out = [];
        foreach (self::SOURCES as $key => $class) {
            $out[$key] = ['label' => $class::label(), 'description' => $class::description(), 'available' => $class::available()];
        }

        return $out;
    }

    /**
     * A display name for whoever wrote a comment or note.
     */
    public static function nameOf(int $userId, string $fallback = ''): string
    {
        $user = $userId > 0 ? get_userdata($userId) : false;
        if ($user !== false && $user->display_name !== '') {
            return $user->display_name;
        }

        return $fallback !== '' ? $fallback : __('Someone', 'ruvelo-inbox');
    }

    /**
     * Plain text from comment or note HTML, for a notification's body.
     */
    public static function plain(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', wp_specialchars_decode(wp_strip_all_tags($html), ENT_QUOTES)));
    }

    public static function editLink(int $postId): string
    {
        return admin_url('post.php?post='.$postId.'&action=edit');
    }

    /**
     * Sends unless sources are paused (imports, the demo).
     *
     * @param array<string, mixed> $message
     */
    public static function send(int $userId, array $message): void
    {
        Inbox::notify($userId, $message);
    }
}

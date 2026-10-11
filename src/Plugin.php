<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox;

use Ruvelo\WP\Inbox\Sources\Sources;

/**
 * Wires the plugin into WordPress. Nothing here talks to the outside world:
 * no remote requests, no telemetry, no admin notices.
 */
final class Plugin
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        // The developer API without a hard dependency: these do nothing if the plugin is off.
        add_action('ruvelo_inbox_notify', [Inbox::class, 'fromAction'], 10, 2);
        add_action('ruvelo_inbox_register_type', [Inbox::class, 'registerFromAction'], 10, 2);

        add_action('init', [self::class, 'init'], 9);
        add_action('rest_api_init', [Rest\Controller::class, 'register']);
        add_action(Mailer::CRON_HOOK, [Mailer::class, 'cron']);
        add_action(Install::PRUNE_HOOK, [self::class, 'prune']);

        Assets::register();
        Actions::register();
        AdminBar::register();
        Heartbeat::register();
        Privacy::register();

        if (function_exists('wp_register_ability')) {
            Abilities::register();
        }
        if (is_admin()) {
            Admin\Admin::register();
        }
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('ruvelo-inbox', Cli\Command::class);
        }
    }

    public static function init(): void
    {
        Install::maybeUpgrade();
        Install::schedule();
        Sources::boot();
        Blocks::register();
    }

    /**
     * The daily cleanup.
     */
    public static function prune(): void
    {
        Store::prune(Settings::int('retention_days'), Settings::bool('keep_unread'));
        Mailer::prune();
    }
}

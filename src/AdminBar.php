<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox;

use WP_Admin_Bar;

/**
 * The bell in the toolbar, in wp-admin and (unless turned off) on the
 * front end, next to "Howdy".
 */
final class AdminBar
{
    public static function register(): void
    {
        add_action('admin_bar_menu', [self::class, 'node'], 7);
    }

    public static function node(WP_Admin_Bar $bar): void
    {
        if (! is_user_logged_in() || (! is_admin() && ! Settings::bool('bell_front'))) {
            return;
        }

        /**
         * Filters whether the toolbar shows the bell for this person.
         *
         * @param bool $show
         */
        if (! apply_filters('ruvelo_inbox_show_bell', true)) {
            return;
        }

        $bar->add_node([
            'id' => 'ruvelo-inbox',
            'parent' => 'top-secondary',
            'title' => Views::bell('bar'),
            'meta' => ['class' => 'ruvelo-inbox-node'],
        ]);
    }
}

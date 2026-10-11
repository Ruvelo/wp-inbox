<?php

/**
 * Plugin Name:       Ruvelo Inbox
 * Plugin URI:        https://ruvelo.github.io/wp-inbox/
 * Description:       The notification centre WordPress never shipped: a bell, an inbox and per-person preferences, plus a developer API other plugins send to.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Requires PHP:      8.2
 * Author:            Ruvelo
 * Author URI:        https://github.com/Ruvelo
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ruvelo-inbox
 * Domain Path:       /languages
 */

declare(strict_types=1);
use Ruvelo\WP\Inbox\Install;
use Ruvelo\WP\Inbox\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

define('RUVELO_INBOX_VERSION', '1.0.0');
define('RUVELO_INBOX_FILE', __FILE__);
define('RUVELO_INBOX_DIR', plugin_dir_path(__FILE__));
define('RUVELO_INBOX_URL', plugin_dir_url(__FILE__));

// A tiny PSR-4 loader, so the plugin works from a zip without Composer.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Ruvelo\\WP\\Inbox\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $file = RUVELO_INBOX_DIR.'src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_readable($file)) {
        require $file;
    }
});

register_activation_hook(__FILE__, [Install::class, 'activate']);
register_deactivation_hook(__FILE__, [Install::class, 'deactivate']);

Plugin::boot();

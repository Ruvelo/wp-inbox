<?php

/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Scheduled jobs are always cleared, so nothing is left running.
 * Notifications, preferences and settings are deleted only when the site
 * owner ticked "Also delete every notification, preference and setting".
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

wp_clear_scheduled_hook('ruvelo_inbox_send_emails');
wp_clear_scheduled_hook('ruvelo_inbox_prune');

$ruvelo_settings = get_option('ruvelo_inbox_settings', []);
$ruvelo_delete = is_array($ruvelo_settings) && ! empty($ruvelo_settings['delete_data']);

/**
 * Lets a site decide in code, e.g. on a staging copy.
 *
 * @param bool $delete
 */
if (! apply_filters('ruvelo_inbox_uninstall_delete_data', $ruvelo_delete)) {
    return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own tables.
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ruvelo_inbox_notifications");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ruvelo_inbox_emails");
$wpdb->delete($wpdb->usermeta, ['meta_key' => $wpdb->get_blog_prefix().'ruvelo_inbox_preferences']); // phpcs:ignore WordPress.DB.SlowDBQuery -- Runs once, on uninstall.
$wpdb->delete($wpdb->commentmeta, ['meta_key' => '_ruvelo_inbox_notified']); // phpcs:ignore WordPress.DB.SlowDBQuery -- Runs once, on uninstall.
// phpcs:enable

foreach (['ruvelo_inbox_settings', 'ruvelo_inbox_db_version', 'ruvelo_inbox_demo_conversations', 'ruvelo_inbox_demo_posts'] as $ruvelo_option) {
    delete_option($ruvelo_option);
}

foreach (wp_roles()->role_objects as $ruvelo_role) {
    $ruvelo_role->remove_cap('ruvelo_manage_inbox');
    $ruvelo_role->remove_cap('ruvelo_send_notifications');
}

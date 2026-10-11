<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox;

/**
 * Tables, capabilities and the daily cleanup. Runs on activation and
 * whenever the stored schema version is behind the code.
 */
final class Install
{
    public const DB_VERSION = '1';

    public const DB_OPTION = 'ruvelo_inbox_db_version';

    public const PRUNE_HOOK = 'ruvelo_inbox_prune';

    public static function activate(): void
    {
        self::install();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::PRUNE_HOOK);
        wp_clear_scheduled_hook(Mailer::CRON_HOOK);
    }

    public static function maybeUpgrade(): void
    {
        if (get_option(self::DB_OPTION) !== self::DB_VERSION) {
            self::install();
        }
    }

    public static function install(): void
    {
        self::createTables();
        self::grantCapabilities();
        self::schedule();
        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    /**
     * The daily cleanup. Checked on every load so it survives a cron reset,
     * and only ever one event.
     */
    public static function schedule(): void
    {
        if (! wp_next_scheduled(self::PRUNE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK);
        }
    }

    public static function notificationsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix.'ruvelo_inbox_notifications';
    }

    public static function emailsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix.'ruvelo_inbox_emails';
    }

    public static function createTables(): void
    {
        global $wpdb;

        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $notifications = self::notificationsTable();
        $emails = self::emailsTable();

        // One row per person per notification. The bell's unread count is a
        // COUNT on (user_id, read_at); the inbox lists by (user_id, created_at).
        dbDelta("CREATE TABLE {$notifications} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  type varchar(100) NOT NULL DEFAULT 'general',
  title varchar(255) NOT NULL DEFAULT '',
  body text NOT NULL,
  url text NOT NULL,
  icon varchar(32) NOT NULL DEFAULT '',
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  actor_name varchar(190) NOT NULL DEFAULT '',
  data longtext NOT NULL,
  read_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_read (user_id,read_at),
  KEY user_created (user_id,created_at),
  KEY created_at (created_at)
) {$charset};");

        // Emails waiting for WP-Cron. One row per notification; a person's
        // waiting rows go out together as one email.
        dbDelta("CREATE TABLE {$emails} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  notification_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type varchar(100) NOT NULL DEFAULT 'general',
  title varchar(255) NOT NULL DEFAULT '',
  body text NOT NULL,
  url text NOT NULL,
  actor_name varchar(190) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL DEFAULT 'pending',
  created_at datetime NOT NULL,
  send_after datetime NOT NULL,
  sent_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status_after (status,send_after),
  KEY user_status (user_id,status)
) {$charset};");
    }

    /**
     * Administrators manage the inbox and send notifications by default.
     * Filter `ruvelo_inbox_manager_roles` and `ruvelo_inbox_sender_roles`
     * to change who gets which capability.
     */
    public static function grantCapabilities(): void
    {
        $caps = [
            Settings::CAP_MANAGE => apply_filters('ruvelo_inbox_manager_roles', ['administrator']),
            Settings::CAP_SEND => apply_filters('ruvelo_inbox_sender_roles', ['administrator']),
        ];
        foreach ($caps as $cap => $roles) {
            foreach (is_array($roles) ? $roles : [] as $name) {
                $role = is_string($name) ? get_role($name) : null;
                $role?->add_cap($cap);
            }
        }
    }

    public static function revokeCapabilities(): void
    {
        foreach (wp_roles()->role_objects as $role) {
            $role->remove_cap(Settings::CAP_MANAGE);
            $role->remove_cap(Settings::CAP_SEND);
        }
    }
}

<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Tests;

use Ruvelo\WP\Inbox\Install;
use Ruvelo\WP\Inbox\Settings;
use Ruvelo\WP\Inbox\Sources\Sources;
use WP_UnitTestCase;

abstract class TestCase extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        $wpdb->query('DELETE FROM '.Install::notificationsTable());
        $wpdb->query('DELETE FROM '.Install::emailsTable());
        // phpcs:enable
        delete_option(Settings::OPTION);
        Settings::flush();
        wp_cache_flush();
        Install::grantCapabilities();
        Sources::reload();
        wp_clear_scheduled_hook('ruvelo_inbox_send_emails');
        reset_phpmailer_instance();
    }

    public function tear_down(): void
    {
        $_POST = [];
        $_GET = [];
        parent::tear_down();
    }

    protected function user(string $role = 'subscriber', string $name = ''): int
    {
        $args = ['role' => $role];
        if ($name !== '') {
            $args['display_name'] = $name;
        }

        return self::factory()->user->create($args);
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected function settings(array $settings): void
    {
        Settings::update($settings);
    }

    /**
     * Every email sent so far.
     *
     * @return list<array{to: string, subject: string, body: string, header: string}>
     */
    protected function mails(): array
    {
        $mailer = tests_retrieve_phpmailer_instance();
        $out = [];
        foreach ($mailer->mock_sent as $sent) {
            $out[] = [
                'to' => (string) ($sent['to'][0][0] ?? ''),
                'subject' => (string) $sent['subject'],
                'body' => (string) $sent['body'],
                'header' => (string) $sent['header'],
            ];
        }

        return $out;
    }
}

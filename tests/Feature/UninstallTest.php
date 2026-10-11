<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Tests\Feature;

use Ruvelo\WP\Inbox\Inbox;
use Ruvelo\WP\Inbox\Install;
use Ruvelo\WP\Inbox\Mailer;
use Ruvelo\WP\Inbox\Settings;
use Ruvelo\WP\Inbox\Testing\Factory;
use Ruvelo\WP\Inbox\Tests\TestCase;
use Ruvelo\WP\Inbox\Types;

/**
 * @runTestsInSeparateProcesses
 *
 * @preserveGlobalState disabled
 */
final class UninstallTest extends TestCase
{
    private function uninstall(): void
    {
        if (! defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'ruvelo-inbox/ruvelo-inbox.php');
        }
        // Let DROP TABLE reach the real tables.
        remove_filter('query', [$this, '_drop_temporary_tables']);
        remove_filter('query', [$this, '_create_temporary_tables']);
        include dirname(__DIR__, 2).'/uninstall.php';
    }

    private function tableExists(string $table): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public function test_by_default_notifications_stay_and_cron_is_cleared(): void
    {
        Types::register('invoice_paid', ['defaults' => ['email' => true]]);
        $user = $this->user();
        Inbox::notify($user, ['type' => 'invoice_paid', 'title' => 'Paid']);
        $this->assertNotFalse(wp_next_scheduled(Mailer::CRON_HOOK));
        $this->assertNotFalse(wp_next_scheduled(Install::PRUNE_HOOK));

        $this->uninstall();

        $this->assertFalse(wp_next_scheduled(Mailer::CRON_HOOK));
        $this->assertFalse(wp_next_scheduled(Install::PRUNE_HOOK));
        $this->assertTrue($this->tableExists(Install::notificationsTable()));
        $this->assertSame(1, Inbox::query($user)['total']);
    }

    public function test_opting_in_removes_everything(): void
    {
        $user = $this->user();
        Factory::create($user);
        Inbox::updatePreferences($user, ['general' => ['email' => true]]);
        $this->settings(['delete_data' => true]);

        $this->uninstall();

        $this->assertFalse($this->tableExists(Install::notificationsTable()));
        $this->assertFalse($this->tableExists(Install::emailsTable()));
        $this->assertFalse(get_option(Settings::OPTION));
        $this->assertFalse(get_user_option('ruvelo_inbox_preferences', $user));
        $this->assertFalse(get_role('administrator')->has_cap(Settings::CAP_MANAGE));

        Install::install(); // Put the tables back for the other tests.
    }

    public function test_deactivating_leaves_no_cron_behind(): void
    {
        Mailer::schedule();
        Install::deactivate();

        $this->assertFalse(wp_next_scheduled(Mailer::CRON_HOOK));
        $this->assertFalse(wp_next_scheduled(Install::PRUNE_HOOK));
    }
}

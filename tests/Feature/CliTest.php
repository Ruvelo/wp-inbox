<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Tests\Feature;

use RuntimeException;
use Ruvelo\WP\Inbox\Cli\Command;
use Ruvelo\WP\Inbox\Inbox;
use Ruvelo\WP\Inbox\Mailer;
use Ruvelo\WP\Inbox\Testing\Factory;
use Ruvelo\WP\Inbox\Tests\TestCase;
use Ruvelo\WP\Inbox\Types;
use WP_CLI;

require_once dirname(__DIR__).'/Support/FakeCli.php';

final class CliTest extends TestCase
{
    public function set_up(): void
    {
        parent::set_up();
        WP_CLI::$out = [];
    }

    public function test_send_to_users_by_login_and_role(): void
    {
        $maya = self::factory()->user->create(['user_login' => 'maya', 'role' => 'editor']);
        $tom = $this->user('editor');
        $this->user('subscriber');

        (new Command)->send(['maya'], ['title' => 'Your export is ready', 'url' => 'https://halyard.test/exports/9081', 'icon' => '↓', 'porcelain' => true]);
        $id = (int) WP_CLI::last();
        $this->assertSame('Your export is ready', Inbox::find($id, $maya)->title);

        (new Command)->send([], ['title' => 'Style guide updated', 'role' => 'editor']);
        $this->assertSame(2, Inbox::unreadCount($maya));
        $this->assertSame(1, Inbox::unreadCount($tom));
        $this->assertStringContainsString('Sent to 2 people', WP_CLI::last());
    }

    public function test_send_to_nobody_is_an_error(): void
    {
        $this->expectException(RuntimeException::class);
        (new Command)->send(['nobody-here'], ['title' => 'Hi']);
    }

    public function test_list(): void
    {
        $user = self::factory()->user->create(['user_login' => 'kenji']);
        Factory::create($user, ['title' => 'Read one', 'read' => true]);
        Factory::create($user, ['title' => 'Unread one']);

        (new Command)->list_(['kenji'], ['format' => 'json']);
        $this->assertCount(2, json_decode(WP_CLI::last(), true));

        (new Command)->list_(['kenji'], ['unread' => true, 'format' => 'count']);
        $this->assertSame('1', WP_CLI::last());
    }

    public function test_prune(): void
    {
        $user = $this->user();
        Factory::create($user, ['read' => true, 'created_at' => time() - 40 * DAY_IN_SECONDS]);
        Factory::create($user, ['created_at' => time() - 40 * DAY_IN_SECONDS]);

        (new Command)->prune([], ['days' => '30', 'dry-run' => true]);
        $this->assertStringContainsString('1 notifications are older', WP_CLI::last());

        (new Command)->prune([], ['days' => '30', 'include-unread' => true]);
        $this->assertStringContainsString('Deleted 2', WP_CLI::last());
    }

    public function test_types(): void
    {
        Types::register('invoice_paid', ['label' => 'Invoice paid', 'group' => 'Billing']);

        (new Command)->types([], ['format' => 'json']);

        $rows = array_column(json_decode(WP_CLI::last(), true), 'label', 'type');
        $this->assertSame('Invoice paid', $rows['invoice_paid']);
    }

    public function test_emails_status_and_run(): void
    {
        Types::register('invoice_paid', ['defaults' => ['email' => true]]);
        Inbox::notify($this->user(), ['type' => 'invoice_paid', 'title' => 'Paid']);

        (new Command)->emails(['status'], []);
        $this->assertSame('failed: 0', WP_CLI::last());
        (new Command)->emails(['run'], ['now' => true]);

        $this->assertStringContainsString('Sent 1 emails', WP_CLI::last());
        $this->assertSame(0, Mailer::pending());
    }
}

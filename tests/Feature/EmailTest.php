<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Tests\Feature;

use Ruvelo\WP\Inbox\Inbox;
use Ruvelo\WP\Inbox\Mailer;
use Ruvelo\WP\Inbox\Tests\TestCase;
use Ruvelo\WP\Inbox\Types;
use WPDieException;

final class EmailTest extends TestCase
{
    public function set_up(): void
    {
        parent::set_up();
        Types::register('invoice_paid', ['label' => 'Invoice paid', 'defaults' => ['email' => true]]);
        Types::register('payout', ['label' => 'Payouts', 'defaults' => ['email' => true]]);
        Types::register('handled_elsewhere', ['label' => 'Elsewhere', 'defaults' => ['email' => true], 'sends_email' => false]);
    }

    public function test_emails_wait_for_wp_cron_never_the_page_load(): void
    {
        $user = $this->user();

        Inbox::notify($user, ['type' => 'invoice_paid', 'title' => 'Invoice INV-2288 paid']);

        $this->assertSame([], $this->mails());
        $this->assertSame(1, Mailer::pending());
        $next = wp_next_scheduled(Mailer::CRON_HOOK);
        $this->assertNotFalse($next);
        $this->assertGreaterThanOrEqual(time() + 5 * MINUTE_IN_SECONDS, $next, 'The default delay is 5 minutes');
        $this->assertSame(0, Mailer::run(), 'Not due yet');
    }

    public function test_a_burst_becomes_one_email_per_person(): void
    {
        $maya = $this->user('editor', 'Maya Okafor');
        $tom = $this->user();
        $this->settings(['email_delay' => 0]);

        Inbox::notify([$maya, $tom], ['type' => 'invoice_paid', 'title' => 'Invoice INV-2288 paid', 'body' => 'Bluefin Studio paid €4,800.00.', 'url' => 'https://halyard.test/i/2288']);
        Inbox::notify($maya, ['type' => 'payout', 'title' => 'Payout of €31,940.12 sent']);
        Inbox::notify($maya, ['type' => 'invoice_paid', 'title' => 'Invoice INV-2284 paid']);

        $this->assertSame(2, Mailer::run());
        $mails = $this->mails();
        $this->assertCount(2, $mails);

        $toMaya = $mails[0]['to'] === get_userdata($maya)->user_email ? $mails[0] : $mails[1];
        $this->assertStringContainsString('3 new notifications', $toMaya['subject']);
        $this->assertStringContainsString('Hi Maya Okafor', $toMaya['body']);
        $this->assertStringContainsString('• Invoice INV-2288 paid', $toMaya['body']);
        $this->assertStringContainsString('https://halyard.test/i/2288', $toMaya['body']);
        $this->assertStringContainsString('Stop emails about invoice paid: ', $toMaya['body']);
        $this->assertStringContainsString('Stop emails about payouts: ', $toMaya['body']);
        $this->assertSame(0, Mailer::pending());
        $this->assertSame(4, Mailer::counts()['sent']);
    }

    public function test_one_notification_is_a_plain_email_about_it(): void
    {
        $user = $this->user();
        $this->settings(['email_delay' => 0]);

        Inbox::notify($user, ['type' => 'invoice_paid', 'title' => 'Invoice INV-2288 paid', 'body' => 'By SEPA transfer.']);
        Mailer::run();

        $mail = $this->mails()[0];
        $this->assertStringContainsString('Invoice INV-2288 paid', $mail['subject']);
        $this->assertStringContainsString('By SEPA transfer.', $mail['body']);
        $this->assertStringContainsString('ruvelo_inbox_unsubscribe=invoice_paid', $mail['body']);
        $this->assertStringContainsString('ruvelo_inbox_unsubscribe=invoice_paid', Mailer::compose(get_userdata($user), [['type' => 'invoice_paid', 'title' => 'x', 'body' => '', 'url' => '']])['unsubscribe']);
    }

    public function test_preferences_are_checked_again_when_sending(): void
    {
        $user = $this->user();
        $this->settings(['email_delay' => 0]);
        Inbox::notify($user, ['type' => 'invoice_paid', 'title' => 'Paid']);

        Inbox::updatePreferences($user, ['invoice_paid' => ['email' => false]]);
        Mailer::run();

        $this->assertSame([], $this->mails());
        $this->assertSame(1, Mailer::counts()['skipped']);
    }

    public function test_batches_are_limited_and_reschedule_themselves(): void
    {
        $this->settings(['email_delay' => 0, 'batch_size' => 2]);
        $users = [$this->user(), $this->user(), $this->user()];
        Inbox::notify($users, ['type' => 'invoice_paid', 'title' => 'Paid']);
        wp_clear_scheduled_hook(Mailer::CRON_HOOK);

        $this->assertSame(2, Mailer::run());
        $this->assertSame(1, Mailer::pending());
        $this->assertNotFalse(wp_next_scheduled(Mailer::CRON_HOOK));
        $this->assertSame(1, Mailer::run());
    }

    public function test_no_email_when_the_site_turns_them_off_or_another_plugin_sends(): void
    {
        $user = $this->user();
        Inbox::notify($user, ['type' => 'handled_elsewhere', 'title' => 'Sent by its own plugin']);
        $this->assertSame(0, Mailer::pending());

        $this->settings(['emails' => false]);
        Inbox::notify($user, ['type' => 'invoice_paid', 'title' => 'Paid']);
        $this->assertSame(0, Mailer::pending());
        $this->assertSame(2, Inbox::unreadCount($user), 'Both still reach the inbox');
    }

    public function test_email_only_people_get_the_email_without_an_inbox_row(): void
    {
        $user = $this->user();
        Inbox::updatePreferences($user, ['invoice_paid' => ['inbox' => false]]);

        $this->assertSame([], Inbox::notify($user, ['type' => 'invoice_paid', 'title' => 'Paid']));
        $this->assertSame(1, Mailer::pending());
    }

    public function test_the_email_filter_can_change_or_skip_it(): void
    {
        $this->settings(['email_delay' => 0]);
        add_filter('ruvelo_inbox_email', static fn (array $email): array => ['subject' => 'Custom'] + $email);
        Inbox::notify($this->user(), ['type' => 'invoice_paid', 'title' => 'Paid']);
        Mailer::run();
        $this->assertSame('Custom', $this->mails()[0]['subject']);

        add_filter('ruvelo_inbox_email', '__return_false', 20);
        Inbox::notify($this->user(), ['type' => 'invoice_paid', 'title' => 'Paid']);
        Mailer::run();
        $this->assertCount(1, $this->mails());
    }

    public function test_unsubscribe_links_are_signed(): void
    {
        $user = $this->user();

        $this->assertTrue(Mailer::verify($user, 'invoice_paid', Mailer::key($user, 'invoice_paid')));
        $this->assertFalse(Mailer::verify($user, 'payout', Mailer::key($user, 'invoice_paid')));
        $this->assertFalse(Mailer::verify($user + 1, 'invoice_paid', Mailer::key($user, 'invoice_paid')));
    }

    public function test_unsubscribing_asks_first_then_turns_the_type_off(): void
    {
        $user = $this->user();
        $_GET = ['ruvelo_inbox_unsubscribe' => 'invoice_paid', 'user' => (string) $user, 'key' => Mailer::key($user, 'invoice_paid')];

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $page = $this->die();
        $this->assertStringContainsString('Stop emails about invoice paid?', $page);
        $this->assertTrue(Inbox::wants($user, 'invoice_paid', 'email'), 'A GET only asks');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertStringContainsString('Done.', $this->die());
        $this->assertFalse(Inbox::wants($user, 'invoice_paid', 'email'));
        $this->assertTrue(Inbox::wants($user, 'payout', 'email'));
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    public function test_a_forged_unsubscribe_link_changes_nothing(): void
    {
        $user = $this->user();
        $_GET = ['ruvelo_inbox_unsubscribe' => '*', 'user' => (string) $user, 'key' => 'nope'];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->die();

        $this->assertTrue(Inbox::wants($user, 'invoice_paid', 'email'));
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    private function die(): string
    {
        try {
            Mailer::handleUnsubscribe();
        } catch (WPDieException $e) {
            return $e->getMessage();
        }
        $this->fail('Expected a page.');
    }
}

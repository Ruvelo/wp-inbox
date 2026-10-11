<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Tests\Feature;

use Ruvelo\WP\Inbox\Inbox;
use Ruvelo\WP\Inbox\Mailer;
use Ruvelo\WP\Inbox\Store;
use Ruvelo\WP\Inbox\Testing\Factory;
use Ruvelo\WP\Inbox\Testing\Fake;
use Ruvelo\WP\Inbox\Tests\TestCase;
use Ruvelo\WP\Inbox\Types;

final class NotifyTest extends TestCase
{
    public function test_it_lands_in_the_inbox(): void
    {
        $maya = $this->user('editor', 'Maya Okafor');
        $tom = $this->user('editor', 'Tom Reyes');

        $ids = Inbox::notify($maya, [
            'title' => 'Invoice INV-2288 paid',
            'body' => 'Bluefin Studio paid €4,800.00 by SEPA transfer.',
            'url' => 'https://halyard.test/invoices/INV-2288',
            'icon' => '€',
            'actor' => $tom,
            'data' => ['invoice' => 'INV-2288'],
        ]);

        $this->assertCount(1, $ids);
        $n = Inbox::find($ids[0], $maya);
        $this->assertNotNull($n);
        $this->assertSame('general', $n->type);
        $this->assertSame('Invoice INV-2288 paid', $n->title);
        $this->assertSame('https://halyard.test/invoices/INV-2288', $n->url);
        $this->assertSame('Tom Reyes', $n->actorName);
        $this->assertSame($tom, $n->actorId);
        $this->assertSame(['invoice' => 'INV-2288'], $n->data);
        $this->assertTrue($n->isUnread());
        $this->assertSame(1, Inbox::unreadCount($maya));
    }

    public function test_it_fans_out_to_everyone_once(): void
    {
        $a = $this->user();
        $b = $this->user();
        $c = get_userdata($this->user());

        $ids = Inbox::notify([$a, $b, (string) $a, $c, 999999], ['title' => 'Release on Thursday']);

        $this->assertCount(3, $ids);
        $this->assertSame(1, Inbox::unreadCount($a));
        $this->assertSame(1, Inbox::unreadCount($b));
        $this->assertSame(1, Inbox::unreadCount($c->ID));
    }

    public function test_a_title_is_required(): void
    {
        $this->setExpectedIncorrectUsage('Ruvelo\WP\Inbox\Inbox::notify');

        $this->assertSame([], Inbox::notify($this->user(), ['body' => 'No title']));
    }

    public function test_unsafe_urls_and_markup_are_dropped(): void
    {
        $user = $this->user();
        $ids = Inbox::notify($user, ['title' => '<b>Bold</b> move', 'body' => "<script>x</script>Line one\nLine two", 'url' => 'javascript:alert(1)']);

        $n = Inbox::find($ids[0], $user);
        $this->assertSame('Bold move', $n->title);
        $this->assertSame("Line one\nLine two", $n->body);
        $this->assertSame('', $n->url);
    }

    public function test_preferences_decide_the_channels(): void
    {
        Types::register('invoice_paid', ['defaults' => ['email' => true]]);
        $quiet = $this->user();
        $loud = $this->user();
        Inbox::updatePreferences($quiet, ['invoice_paid' => ['inbox' => false, 'email' => false]]);

        $ids = Inbox::notify([$quiet, $loud], ['type' => 'invoice_paid', 'title' => 'Paid']);

        $this->assertCount(1, $ids);
        $this->assertSame(0, Inbox::unreadCount($quiet));
        $this->assertSame(1, Inbox::unreadCount($loud));
        $this->assertSame(1, Mailer::pending(), 'Only the loud one gets an email');
    }

    public function test_the_action_sends_without_a_dependency(): void
    {
        $user = $this->user();

        do_action('ruvelo_inbox_notify', $user, ['type' => 'general', 'title' => 'From another plugin']);
        do_action('ruvelo_inbox_notify', [$user], ['title' => 'Again']);

        $this->assertSame(2, Inbox::unreadCount($user));
    }

    public function test_filters_change_the_message_and_the_recipients(): void
    {
        $a = $this->user();
        $b = $this->user();
        add_filter('ruvelo_inbox_message', static fn (array $m): array => ['title' => strtoupper($m['title'])] + $m);
        add_filter('ruvelo_inbox_recipients', static fn (array $ids): array => array_values(array_diff($ids, [$b])));

        Inbox::notify([$a, $b], ['title' => 'quiet']);

        $this->assertSame('QUIET', Inbox::latest($a)[0]->title);
        $this->assertSame(0, Inbox::unreadCount($b));
    }

    public function test_hooks_fire_for_each_row_and_once_at_the_end(): void
    {
        $users = [$this->user(), $this->user()];
        $created = 0;
        $done = null;
        add_action('ruvelo_inbox_notification_created', static function () use (&$created): void {
            $created++;
        });
        add_action('ruvelo_inbox_notified', static function (array $ids, array $userIds) use (&$done): void {
            $done = [count($ids), $userIds];
        }, 10, 2);

        Inbox::notify($users, ['title' => 'Hello']);

        $this->assertSame(2, $created);
        $this->assertSame([2, $users], $done);
    }

    public function test_read_unread_delete_are_scoped_to_the_owner(): void
    {
        $maya = $this->user();
        $tom = $this->user();
        $id = Inbox::notify($maya, ['title' => 'Mine'])[0];

        $this->assertNull(Inbox::find($id, $tom));
        $this->assertFalse(Inbox::markRead($id, $tom));
        $this->assertFalse(Inbox::delete($id, $tom));

        $this->assertTrue(Inbox::markRead($id, $maya));
        $this->assertFalse(Inbox::markRead($id, $maya), 'Already read');
        $this->assertSame(0, Inbox::unreadCount($maya));
        $this->assertTrue(Inbox::markUnread($id, $maya));
        $this->assertSame(1, Inbox::unreadCount($maya));
        $this->assertTrue(Inbox::delete($id, $maya));
        $this->assertNull(Inbox::find($id, $maya));
    }

    public function test_mark_all_read_and_bulk(): void
    {
        $user = $this->user();
        $other = $this->user();
        $mine = Factory::createMany($user, 4);
        Factory::create($other);

        $this->assertSame(2, Inbox::bulk($user, 'read', [$mine[0]->id, $mine[1]->id]));
        $this->assertSame(1, Inbox::bulk($user, 'unread', [$mine[0]->id]));
        $this->assertSame(3, Inbox::markAllRead($user));
        $this->assertSame(0, Inbox::unreadCount($user));
        $this->assertSame(1, Inbox::unreadCount($other));
        $this->assertSame(2, Inbox::bulk($user, 'delete', [$mine[2]->id, $mine[3]->id]));
        $this->assertSame(2, Inbox::query($user)['total']);
    }

    public function test_query_filters_by_status_and_type_and_pages(): void
    {
        $user = $this->user();
        Factory::createMany($user, 5, ['type' => 'a']);
        Factory::createMany($user, 3, ['type' => 'b', 'read' => true]);

        $this->assertSame(8, Inbox::query($user)['total']);
        $this->assertSame(5, Inbox::query($user, ['status' => 'unread'])['total']);
        $this->assertSame(3, Inbox::query($user, ['type' => 'b'])['total']);
        $page = Inbox::query($user, ['per_page' => 3, 'page' => 3]);
        $this->assertCount(2, $page['items']);
        $this->assertSame(['a', 'b'], (function (array $t): array {
            sort($t);

            return $t;
        })(Store::typesFor($user)));
    }

    public function test_newest_first(): void
    {
        $user = $this->user();
        Factory::create($user, ['title' => 'Old', 'created_at' => time() - DAY_IN_SECONDS]);
        Factory::create($user, ['title' => 'New']);

        $this->assertSame(['New', 'Old'], array_map(static fn ($n): string => $n->title, Inbox::latest($user)));
    }

    public function test_pruning_keeps_recent_and_unread_ones(): void
    {
        $user = $this->user();
        Factory::create($user, ['title' => 'Old read', 'read' => true, 'created_at' => time() - 100 * DAY_IN_SECONDS]);
        Factory::create($user, ['title' => 'Old unread', 'created_at' => time() - 100 * DAY_IN_SECONDS]);
        Factory::create($user, ['title' => 'Recent', 'read' => true]);

        $this->assertSame(1, Store::prune(90, true));
        $this->assertSame(['Recent', 'Old unread'], array_map(static fn ($n): string => $n->title, Inbox::latest($user)));
        $this->assertSame(1, Store::prune(90, false));
        $this->assertSame(0, Store::prune(0, false), '0 keeps everything');
    }

    public function test_the_daily_cleanup_is_scheduled_and_uses_the_setting(): void
    {
        $this->assertNotFalse(wp_next_scheduled('ruvelo_inbox_prune'));
        $user = $this->user();
        Factory::create($user, ['read' => true, 'created_at' => time() - 40 * DAY_IN_SECONDS]);
        $this->settings(['retention_days' => 30]);

        do_action('ruvelo_inbox_prune');

        $this->assertSame(0, Inbox::query($user)['total']);
    }

    public function test_the_fake_records_instead_of_sending(): void
    {
        $user = $this->user();
        $fake = Fake::start();

        Inbox::notify($user, ['type' => 'general', 'title' => 'Recorded']);
        do_action('ruvelo_inbox_notify', $user, ['type' => 'other', 'title' => 'Also recorded']);

        $this->assertCount(1, $fake->sentTo($user, 'general'));
        $this->assertCount(2, $fake->sent());
        $this->assertSame(0, Inbox::unreadCount($user));
        $fake->stop();

        Inbox::notify($user, ['title' => 'Real']);
        $this->assertSame(1, Inbox::unreadCount($user));
    }

    public function test_paused_sends_nothing(): void
    {
        $user = $this->user();
        Inbox::pause();
        Inbox::notify($user, ['title' => 'Quiet']);
        Inbox::pause(false);

        $this->assertSame(0, Inbox::unreadCount($user));
    }

    public function test_notification_presentation(): void
    {
        $user = $this->user();
        $n = Factory::create($user, ['actor_name' => 'Inès Laurent', 'body' => str_repeat('word ', 60), 'created_at' => time() - 2 * HOUR_IN_SECONDS]);

        $this->assertSame('IL', $n->avatar());
        $this->assertSame('2 hours ago', $n->timeAgo());
        $this->assertSame('Today', $n->day());
        $this->assertStringEndsWith('…', $n->excerpt(40));
        $this->assertSame('!', Factory::create($user, ['icon' => '!'])->avatar());
        $array = $n->toArray();
        $this->assertFalse($array['read']);
        $this->assertSame('Announcements', $array['type_label']);
    }
}

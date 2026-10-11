<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Tests\Feature;

use Ruvelo\WP\Inbox\Heartbeat;
use Ruvelo\WP\Inbox\Inbox;
use Ruvelo\WP\Inbox\Testing\Factory;
use Ruvelo\WP\Inbox\Tests\TestCase;

final class HeartbeatTest extends TestCase
{
    public function test_it_answers_only_signed_in_people_who_asked(): void
    {
        $this->assertSame(['other' => 1], apply_filters('heartbeat_received', ['other' => 1], ['ruvelo_inbox' => ['latest' => 0]], 'admin'));

        wp_set_current_user($this->user());
        $this->assertArrayNotHasKey('ruvelo_inbox', apply_filters('heartbeat_received', [], ['something' => 'else'], 'admin'));
        $this->assertArrayHasKey('ruvelo_inbox', apply_filters('heartbeat_received', [], ['ruvelo_inbox' => ['latest' => 0]], 'admin'));
    }

    public function test_the_list_comes_only_when_something_new_arrived(): void
    {
        $user = $this->user();
        wp_set_current_user($user);
        $first = Factory::create($user, ['title' => 'Invoice INV-2288 paid']);

        $payload = apply_filters('heartbeat_received', [], ['ruvelo_inbox' => ['latest' => 0]], 'admin')['ruvelo_inbox'];
        $this->assertSame(1, $payload['unread']);
        $this->assertSame($first->id, $payload['latest']);
        $this->assertStringContainsString('Invoice INV-2288 paid', $payload['html']);

        $same = Heartbeat::payload($user, $first->id);
        $this->assertNull($same['html'], 'Nothing new: no list');
        $this->assertSame(0, $same['fresh']);

        Factory::create($user, ['title' => 'Payout sent']);
        Factory::create($user, ['title' => 'Export ready']);
        $fresh = Heartbeat::payload($user, $first->id);
        $this->assertSame(3, $fresh['unread']);
        $this->assertSame(2, $fresh['fresh'], 'Two new ones to announce');
        $this->assertStringContainsString('Export ready', (string) $fresh['html']);
    }

    public function test_the_count_follows_reads(): void
    {
        $user = $this->user();
        $n = Factory::create($user);
        Inbox::markRead($n->id, $user);

        $this->assertSame(0, Heartbeat::payload($user, $n->id)['unread']);
    }
}

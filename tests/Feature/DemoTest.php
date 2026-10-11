<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Tests\Feature;

use Ruvelo\WP\Inbox\Demo;
use Ruvelo\WP\Inbox\Inbox;
use Ruvelo\WP\Inbox\Mailer;
use Ruvelo\WP\Inbox\Tests\TestCase;

final class DemoTest extends TestCase
{
    public function test_halyard_gives_maya_a_realistic_inbox_without_emailing_anyone(): void
    {
        $counts = Demo::seed();

        $this->assertSame(15, $counts['notifications']);
        $this->assertSame('Maya Okafor', get_userdata(1)->display_name);
        $this->assertSame(5, Inbox::unreadCount(1));
        $this->assertSame(0, Mailer::pending());
        $this->assertSame([], $this->mails());

        // Seeding again starts her inbox over instead of doubling it.
        Demo::seed();
        $this->assertSame(15, Inbox::query(1)['total']);
    }
}

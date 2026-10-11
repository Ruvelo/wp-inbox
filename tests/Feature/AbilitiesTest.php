<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Tests\Feature;

use Ruvelo\WP\Inbox\Abilities;
use Ruvelo\WP\Inbox\Inbox;
use Ruvelo\WP\Inbox\Testing\Factory;
use Ruvelo\WP\Inbox\Tests\TestCase;
use WP_Error;

final class AbilitiesTest extends TestCase
{
    public function test_the_abilities_are_registered_for_rest_on_7_0_and_7_1(): void
    {
        foreach (Abilities::names() as $name) {
            $ability = wp_get_ability($name);
            $this->assertNotNull($ability, $name);
            // 7.0 only reads show_in_rest; 7.1 added public.
            $this->assertTrue($ability->get_meta_item('show_in_rest'), $name);
            $this->assertTrue($ability->get_meta_item('public'), $name);
        }
        $this->assertTrue(wp_get_ability(Abilities::LIST)->get_meta()['annotations']['readonly']);
    }

    public function test_agents_read_only_their_own_inbox(): void
    {
        $maya = $this->user();
        Factory::create($maya, ['title' => 'Tom mentioned you']);
        Factory::create($maya, ['title' => 'Old', 'read' => true]);
        Factory::create($this->user(), ['title' => 'Someone else’s']);

        $this->assertInstanceOf(WP_Error::class, wp_get_ability(Abilities::LIST)->execute([]), 'Guests get nothing');

        wp_set_current_user($maya);
        $list = wp_get_ability(Abilities::LIST)->execute(['status' => 'unread']);
        $this->assertSame(1, $list['unread']);
        $this->assertSame(['Tom mentioned you'], array_column($list['notifications'], 'title'));
        $this->assertSame(['unread' => 1], wp_get_ability(Abilities::COUNT)->execute([]));

        $this->assertSame(['marked' => 1, 'unread' => 0], wp_get_ability(Abilities::MARK_READ)->execute([]));
    }

    public function test_sending_needs_the_capability(): void
    {
        $to = self::factory()->user->create(['user_login' => 'kenji']);
        $send = wp_get_ability(Abilities::SEND);

        wp_set_current_user($this->user('editor'));
        $this->assertInstanceOf(WP_Error::class, $send->execute(['to' => ['kenji'], 'title' => 'Hi']));
        $this->assertSame(0, Inbox::unreadCount($to));

        wp_set_current_user($this->user('administrator'));
        $result = $send->execute(['to' => ['kenji'], 'title' => 'Release on Thursday']);
        $this->assertSame(1, $result['recipients']);
        $this->assertSame(1, Inbox::unreadCount($to));
        $this->assertInstanceOf(WP_Error::class, $send->execute(['to' => ['ghost'], 'title' => 'Hi']));
    }
}

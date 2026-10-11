<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Tests\Feature;

use Ruvelo\WP\Inbox\Inbox;
use Ruvelo\WP\Inbox\Mailer;
use Ruvelo\WP\Inbox\Preferences;
use Ruvelo\WP\Inbox\Privacy;
use Ruvelo\WP\Inbox\Testing\Factory;
use Ruvelo\WP\Inbox\Tests\TestCase;
use Ruvelo\WP\Inbox\Types;

final class PrivacyTest extends TestCase
{
    public function test_the_exporter_and_eraser_are_registered(): void
    {
        $this->assertArrayHasKey('ruvelo-inbox', apply_filters('wp_privacy_personal_data_exporters', []));
        $this->assertArrayHasKey('ruvelo-inbox', apply_filters('wp_privacy_personal_data_erasers', []));
    }

    public function test_export_includes_notifications_and_choices(): void
    {
        Types::register('invoice_paid', ['label' => 'Invoice paid']);
        $user = $this->user();
        Factory::create($user, ['title' => 'Invoice INV-2288 paid', 'actor_name' => 'Tom Reyes']);
        Inbox::updatePreferences($user, ['invoice_paid' => ['email' => true]]);

        $export = Privacy::export(get_userdata($user)->user_email);

        $this->assertTrue($export['done']);
        $groups = array_column($export['data'], 'group_id');
        $this->assertContains('ruvelo-inbox-notifications', $groups);
        $this->assertContains('ruvelo-inbox-preferences', $groups);
        $this->assertStringContainsString('Invoice INV-2288 paid', (string) wp_json_encode($export['data']));
    }

    public function test_erase_removes_everything_about_the_person(): void
    {
        Types::register('invoice_paid', ['defaults' => ['email' => true]]);
        $user = $this->user();
        $other = $this->user();
        Inbox::notify([$user, $other], ['type' => 'invoice_paid', 'title' => 'Paid']);
        Inbox::updatePreferences($user, ['invoice_paid' => ['inbox' => true]]);

        $result = Privacy::erase(get_userdata($user)->user_email);

        $this->assertTrue($result['items_removed']);
        $this->assertSame(0, Inbox::query($user)['total']);
        $this->assertSame([], Preferences::explicit($user));
        $this->assertSame(1, Mailer::pending(), 'Only the other person’s email is left');
        $this->assertSame(1, Inbox::unreadCount($other));
    }

    public function test_deleting_a_user_deletes_their_inbox(): void
    {
        require_once ABSPATH.'wp-admin/includes/user.php';
        $user = $this->user();
        Factory::createMany($user, 3);

        wp_delete_user($user);

        $this->assertSame(0, Inbox::query($user)['total']);
    }
}

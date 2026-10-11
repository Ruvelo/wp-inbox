<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Tests\Feature;

use Ruvelo\WP\Inbox\Inbox;
use Ruvelo\WP\Inbox\Tests\TestCase;
use Ruvelo\WP\Inbox\Types;

final class TypesTest extends TestCase
{
    public function test_a_type_has_a_label_group_channels_and_defaults(): void
    {
        $this->assertTrue(Inbox::registerType('invoice_paid', [
            'label' => 'Invoice paid',
            'description' => 'When a customer pays.',
            'group' => 'Billing',
            'defaults' => ['email' => true],
        ]));

        $type = Types::get('invoice_paid');
        $this->assertSame('Invoice paid', $type['label']);
        $this->assertSame('Billing', $type['group']);
        $this->assertSame(['inbox', 'email'], $type['channels']);
        $this->assertSame(['inbox' => true, 'email' => true], $type['defaults']);
        $this->assertTrue($type['sends_email']);
        $this->assertArrayHasKey('invoice_paid', Types::grouped()['Billing']);
    }

    public function test_channels_are_limited_to_inbox_and_email(): void
    {
        Types::register('digest', ['channels' => ['email', 'sms', 'email']]);

        $this->assertSame(['email'], Types::get('digest')['channels']);
        $this->assertSame(['email' => false], Types::get('digest')['defaults']);
    }

    public function test_invalid_names_are_refused(): void
    {
        $this->setExpectedIncorrectUsage('Ruvelo\WP\Inbox\Types::register');

        $this->assertFalse(Types::register('Invoice Paid!'));
        $this->assertFalse(Types::exists('Invoice Paid!'));
    }

    public function test_plugins_register_without_a_hard_dependency(): void
    {
        do_action('ruvelo_inbox_register_type', 'shipment_sent', ['label' => 'Shipment sent', 'group' => 'Orders']);

        $this->assertTrue(Types::exists('shipment_sent'));
        $this->assertSame('Shipment sent', Types::label('shipment_sent'));
    }

    public function test_unregistered_types_fall_back_to_inbox_only(): void
    {
        $type = Types::get('something_else');

        $this->assertSame('Something else', $type['label']);
        $this->assertSame(['inbox' => true, 'email' => false], $type['defaults']);
    }

    public function test_the_built_in_types_are_registered(): void
    {
        foreach (['general', 'note_reply', 'note_on_post', 'note_thread', 'comment_reply', 'comment_on_post', 'post_published', 'post_scheduled'] as $type) {
            $this->assertTrue(Types::exists($type), $type);
        }
        $this->assertSame(function_exists('wp_get_note_mentioned_user_ids'), Types::exists('note_mention'));
        // Only when Ruvelo Feedback is active.
        $this->assertFalse(Types::exists('feedback_update'));
    }

    public function test_the_args_filter_can_change_a_type(): void
    {
        add_filter('ruvelo_inbox_type_args', static fn (array $args, string $name): array => $name === 'loud' ? ['required' => true] + $args : $args, 10, 2);
        Types::register('loud', ['label' => 'Loud']);

        $this->assertTrue(Types::get('loud')['required']);
    }
}

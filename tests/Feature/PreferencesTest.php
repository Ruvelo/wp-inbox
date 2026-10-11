<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Tests\Feature;

use Ruvelo\WP\Inbox\Inbox;
use Ruvelo\WP\Inbox\Preferences;
use Ruvelo\WP\Inbox\Tests\TestCase;
use Ruvelo\WP\Inbox\Types;
use WP_Error;

final class PreferencesTest extends TestCase
{
    public function set_up(): void
    {
        parent::set_up();
        Types::register('invoice_paid', ['label' => 'Invoice paid', 'defaults' => ['email' => false]]);
        Types::register('security', ['label' => 'Security alerts', 'channels' => ['email'], 'required' => true]);
    }

    public function test_defaults_apply_until_someone_chooses(): void
    {
        $user = $this->user();

        $this->assertTrue(Inbox::wants($user, 'invoice_paid', 'inbox'));
        $this->assertFalse(Inbox::wants($user, 'invoice_paid', 'email'));
        $this->assertSame([], Preferences::explicit($user));
    }

    public function test_only_the_pairs_given_change(): void
    {
        $user = $this->user();

        $this->assertTrue(Inbox::updatePreferences($user, ['invoice_paid' => ['email' => true]]));
        $this->assertTrue(Inbox::updatePreferences($user, ['invoice_paid' => ['inbox' => false]]));

        $this->assertSame(['invoice_paid' => ['email' => true, 'inbox' => false]], Preferences::explicit($user));
        $this->assertFalse(Inbox::wants($user, 'invoice_paid', 'inbox'));
        $this->assertTrue(Inbox::wants($user, 'invoice_paid', 'email'));
    }

    public function test_preferences_are_per_person(): void
    {
        $maya = $this->user();
        $tom = $this->user();
        Inbox::updatePreferences($maya, ['invoice_paid' => ['inbox' => false]]);

        $this->assertFalse(Inbox::wants($maya, 'invoice_paid', 'inbox'));
        $this->assertTrue(Inbox::wants($tom, 'invoice_paid', 'inbox'));
    }

    public function test_required_types_are_always_on_and_cannot_be_turned_off(): void
    {
        $user = $this->user();

        $this->assertTrue(Inbox::wants($user, 'security', 'email'));
        $this->assertFalse(Inbox::wants($user, 'security', 'inbox'), 'Not one of its channels');

        $result = Inbox::updatePreferences($user, ['security' => ['email' => false]]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('ruvelo_inbox_required', $result->get_error_code());
    }

    public function test_unknown_types_and_channels_are_errors_and_nothing_is_saved(): void
    {
        $user = $this->user();

        $this->assertSame('ruvelo_inbox_unknown_type', Inbox::updatePreferences($user, ['nope' => ['email' => true]])->get_error_code());
        $this->assertSame('ruvelo_inbox_unknown_channel', Inbox::updatePreferences($user, ['invoice_paid' => ['email' => true, 'sms' => true]])->get_error_code());
        $this->assertSame([], Preferences::explicit($user));
    }

    public function test_for_user_resolves_every_type(): void
    {
        $user = $this->user();
        Inbox::updatePreferences($user, ['invoice_paid' => ['email' => true]]);

        $prefs = Inbox::preferences($user);
        $this->assertSame(['inbox' => true, 'email' => true], $prefs['invoice_paid']);
        $this->assertSame(['email' => true], $prefs['security']);
    }

    public function test_unsubscribe_turns_email_off_for_one_type_or_all(): void
    {
        $user = $this->user();
        Types::register('payout', ['defaults' => ['email' => true]]);
        Types::register('export', ['defaults' => ['email' => true]]);

        Preferences::unsubscribe($user, 'payout');
        $this->assertFalse(Inbox::wants($user, 'payout', 'email'));
        $this->assertTrue(Inbox::wants($user, 'export', 'email'));

        Preferences::unsubscribe($user, '*');
        $this->assertFalse(Inbox::wants($user, 'export', 'email'));
        $this->assertTrue(Inbox::wants($user, 'security', 'email'), 'Required types stay on');
        $this->assertTrue(Inbox::wants($user, 'export', 'inbox'), 'The inbox is untouched');
    }

    public function test_the_wants_filter_has_the_last_word(): void
    {
        $user = $this->user();
        add_filter('ruvelo_inbox_wants', static fn (bool $wants, int $id, string $type, string $channel): bool => $channel === 'email' ? true : $wants, 10, 4);

        $this->assertTrue(Inbox::wants($user, 'invoice_paid', 'email'));
    }

    public function test_an_update_fires_an_action(): void
    {
        $user = $this->user();
        $seen = null;
        add_action('ruvelo_inbox_preferences_updated', static function (int $id, array $saved) use (&$seen): void {
            $seen = [$id, $saved];
        }, 10, 2);

        Inbox::updatePreferences($user, ['invoice_paid' => ['email' => 'true']]);

        $this->assertSame([$user, ['invoice_paid' => ['email' => true]]], $seen);
    }
}

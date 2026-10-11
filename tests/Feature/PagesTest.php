<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Tests\Feature;

use Ruvelo\WP\Inbox\Actions;
use Ruvelo\WP\Inbox\AdminBar;
use Ruvelo\WP\Inbox\Inbox;
use Ruvelo\WP\Inbox\Support\Redirect;
use Ruvelo\WP\Inbox\Testing\Factory;
use Ruvelo\WP\Inbox\Tests\TestCase;
use Ruvelo\WP\Inbox\Types;
use Ruvelo\WP\Inbox\Urls;
use Ruvelo\WP\Inbox\Views;
use WPDieException;

/**
 * The bell, the inbox and the preferences, and the no-JavaScript path
 * behind every button.
 */
final class PagesTest extends TestCase
{
    /**
     * @param array<string, mixed> $post
     */
    private function post(array $post): string
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $post;
        try {
            Actions::handle();
        } catch (Redirect $redirect) {
            return $redirect->url;
        } finally {
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }
        $this->fail('Expected a redirect.');
    }

    private function nonce(): string
    {
        return wp_create_nonce(Actions::NONCE);
    }

    public function test_the_inbox_groups_by_day_and_marks_unread(): void
    {
        $user = $this->user();
        wp_set_current_user($user);
        Factory::create($user, ['title' => 'Invoice INV-2288 paid', 'actor_name' => 'Tom Reyes']);
        Factory::create($user, ['title' => 'Payout sent', 'read' => true, 'created_at' => time() - DAY_IN_SECONDS]);
        Factory::create($user, ['title' => 'Old one', 'read' => true, 'created_at' => strtotime('-20 days')]);

        $html = Views::inbox(['context' => 'admin', 'base' => admin_url('admin.php?page=ruvelo-inbox')]);

        $this->assertStringContainsString('<h2>Today</h2>', $html);
        $this->assertStringContainsString('<h2>Yesterday</h2>', $html);
        $this->assertStringContainsString('ruvelo-inbox-item is-unread', $html);
        $this->assertStringContainsString('<strong>1 unread</strong>', $html);
        $this->assertStringContainsString('>TR<', $html, 'Initials avatar');
        $this->assertStringContainsString('name="ruvelo_inbox_action" value="read_all"', $html);
        $this->assertStringContainsString('ruvelo_inbox_open=', $html, 'Items are plain links');
    }

    public function test_the_inbox_filters_and_has_an_empty_state(): void
    {
        $user = $this->user();
        wp_set_current_user($user);
        $this->assertStringContainsString('Your inbox is empty', Views::inbox());

        Factory::create($user, ['type' => 'payout', 'title' => 'Payout sent', 'read' => true]);
        Factory::create($user, ['type' => 'general', 'title' => 'Hello']);
        $_GET = ['inbox_status' => 'unread'];
        $html = Views::inbox();
        $this->assertStringContainsString('Hello', $html);
        $this->assertStringNotContainsString('Payout sent', $html);

        $_GET = ['inbox_type' => 'payout'];
        $html = Views::inbox();
        $this->assertStringContainsString('Payout sent', $html);
        $this->assertStringNotContainsString('>Hello<', $html);
        $this->assertStringContainsString('<option value="payout" selected', $html);
    }

    public function test_guests_are_asked_to_sign_in(): void
    {
        wp_set_current_user(0);

        $this->assertStringContainsString('Sign in', Views::inbox());
        $this->assertSame('', Views::bell());
    }

    public function test_every_button_works_without_javascript(): void
    {
        $user = $this->user();
        wp_set_current_user($user);
        [$a, $b, $c] = Factory::createMany($user, 3);
        $back = admin_url('admin.php?page=ruvelo-inbox');

        $url = $this->post(['ruvelo_inbox_action' => 'read', 'id' => $a->id, 'ruvelo_inbox_nonce' => $this->nonce(), 'redirect_to' => $back]);
        $this->assertStringStartsWith($back, $url);
        $this->assertStringContainsString('ruvelo_inbox_done=read', $url);
        $this->assertFalse(Inbox::find($a->id, $user)->isUnread());

        $this->post(['ruvelo_inbox_action' => 'unread', 'id' => $a->id, 'ruvelo_inbox_nonce' => $this->nonce()]);
        $this->assertTrue(Inbox::find($a->id, $user)->isUnread());

        $this->post(['ruvelo_inbox_action' => 'bulk', 'bulk_action' => 'delete', 'ids' => [$b->id, $c->id], 'ruvelo_inbox_nonce' => $this->nonce()]);
        $this->assertSame(1, Inbox::query($user)['total']);

        $this->post(['ruvelo_inbox_action' => 'read_all', 'ruvelo_inbox_nonce' => $this->nonce()]);
        $this->assertSame(0, Inbox::unreadCount($user));
    }

    public function test_a_bad_nonce_changes_nothing(): void
    {
        $user = $this->user();
        wp_set_current_user($user);
        $n = Factory::create($user);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['ruvelo_inbox_action' => 'delete', 'id' => $n->id, 'ruvelo_inbox_nonce' => 'nope'];

        try {
            Actions::handle();
            $this->fail('Expected wp_die().');
        } catch (WPDieException) {
            $this->assertNotNull(Inbox::find($n->id, $user));
        } finally {
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }
    }

    public function test_you_cannot_touch_someone_elses_notification(): void
    {
        $theirs = Factory::create($this->user());
        wp_set_current_user($this->user());

        $this->post(['ruvelo_inbox_action' => 'delete', 'id' => $theirs->id, 'ruvelo_inbox_nonce' => $this->nonce()]);

        $this->assertNotNull(Inbox::find($theirs->id, $theirs->userId));
    }

    public function test_opening_an_item_marks_it_read_and_goes_there(): void
    {
        $user = $this->user();
        wp_set_current_user($user);
        $n = Factory::create($user, ['url' => home_url('/invoices/INV-2288')]);
        $_GET = ['ruvelo_inbox_open' => (string) $n->id, '_wpnonce' => wp_create_nonce('ruvelo_inbox_open_'.$n->id)];

        try {
            Actions::handle();
            $this->fail('Expected a redirect.');
        } catch (Redirect $redirect) {
            $this->assertSame(home_url('/invoices/INV-2288'), $redirect->url);
        }
        $this->assertFalse(Inbox::find($n->id, $user)->isUnread());

        // Elsewhere on the web: the inbox instead (no open redirects).
        $away = Factory::create($user, ['url' => 'https://evil.example/']);
        $_GET = ['ruvelo_inbox_open' => (string) $away->id, '_wpnonce' => wp_create_nonce('ruvelo_inbox_open_'.$away->id)];
        try {
            Actions::handle();
        } catch (Redirect $redirect) {
            $this->assertSame(Urls::inbox(), $redirect->url);
        }
    }

    public function test_preferences_save_from_the_form(): void
    {
        Types::register('invoice_paid', ['label' => 'Invoice paid', 'group' => 'Billing', 'defaults' => ['email' => true]]);
        Types::register('security', ['label' => 'Security alerts', 'channels' => ['email'], 'required' => true]);
        $user = $this->user();
        wp_set_current_user($user);

        $html = Views::preferences();
        $this->assertStringContainsString('<h2>Billing</h2>', $html);
        $this->assertStringContainsString('name="prefs[invoice_paid][email]" value="1" checked', $html);
        $this->assertStringContainsString('role="switch"', $html);
        $this->assertStringContainsString('Always on', $html);

        // Unticked switches aren't posted: they're off.
        $this->post(['ruvelo_inbox_action' => 'preferences', 'prefs' => ['invoice_paid' => ['inbox' => '1']], 'ruvelo_inbox_nonce' => wp_create_nonce(Actions::PREFERENCES_NONCE)]);

        $this->assertTrue(Inbox::wants($user, 'invoice_paid', 'inbox'));
        $this->assertFalse(Inbox::wants($user, 'invoice_paid', 'email'));
        $this->assertTrue(Inbox::wants($user, 'security', 'email'));
    }

    public function test_the_bell_shows_the_count_and_latest(): void
    {
        $user = $this->user();
        wp_set_current_user($user);
        Factory::createMany($user, 120);

        $html = Views::bell('bar');
        $this->assertStringContainsString('data-ruvelo-inbox-bell', $html);
        $this->assertStringContainsString('>99+<', $html);
        $this->assertStringContainsString('aria-label="Notifications, 120 unread"', $html);
        $this->assertSame(6, substr_count($html, 'data-ruvelo-inbox-item='));
        $this->assertStringContainsString('View all', $html);
    }

    public function test_the_bell_is_in_the_toolbar(): void
    {
        require_once ABSPATH.WPINC.'/class-wp-admin-bar.php';
        wp_set_current_user($this->user());
        $bar = new \WP_Admin_Bar;
        $bar->add_node(['id' => 'top-secondary', 'title' => '']);

        AdminBar::node($bar);
        $this->assertNotNull($bar->get_node('ruvelo-inbox'));

        $this->settings(['bell_front' => false]);
        $bar->remove_node('ruvelo-inbox');
        AdminBar::node($bar);
        $this->assertNull($bar->get_node('ruvelo-inbox'), 'Not on the front end when turned off');
    }

    public function test_the_blocks_and_shortcodes_render(): void
    {
        $this->assertTrue(\WP_Block_Type_Registry::get_instance()->is_registered('ruvelo/inbox'));
        $this->assertTrue(\WP_Block_Type_Registry::get_instance()->is_registered('ruvelo/inbox-bell'));
        $user = $this->user();
        wp_set_current_user($user);
        Factory::create($user, ['title' => 'Hello from the block']);

        $block = do_blocks('<!-- wp:ruvelo/inbox /-->');
        $this->assertStringContainsString('wp-block-ruvelo-inbox', $block);
        $this->assertStringContainsString('Hello from the block', $block);
        $this->assertStringContainsString('inbox_view=preferences', $block, 'A Preferences tab');

        $_GET = ['inbox_view' => 'preferences'];
        $this->assertStringContainsString('Notification preferences', do_shortcode('[ruvelo_inbox]'));
        $_GET = [];
        $this->assertStringNotContainsString('inbox_view=preferences', do_shortcode('[ruvelo_inbox preferences="no"]'));
        $this->assertStringContainsString('data-ruvelo-inbox-bell', do_blocks('<!-- wp:ruvelo/inbox-bell /-->'));

        wp_set_current_user(0);
        $this->assertSame('', trim(do_shortcode('[ruvelo_inbox_bell]')));
    }

    public function test_a_front_end_page_takes_over_the_links(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Inbox', 'post_content' => '<!-- wp:ruvelo/inbox /-->']);
        $this->settings(['page_id' => $page]);

        $this->assertSame(get_permalink($page), Urls::inbox([], true));
        $this->assertStringContainsString('inbox_view=preferences', Urls::preferences([], true));
    }
}

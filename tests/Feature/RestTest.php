<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Tests\Feature;

use Ruvelo\WP\Inbox\Inbox;
use Ruvelo\WP\Inbox\Testing\Factory;
use Ruvelo\WP\Inbox\Tests\TestCase;
use Ruvelo\WP\Inbox\Types;
use WP_REST_Request;
use WP_REST_Response;

final class RestTest extends TestCase
{
    /**
     * @param array<string, mixed> $params
     */
    private function call(string $method, string $path, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/ruvelo-inbox/v1'.$path);
        if ($params !== []) {
            $method === 'GET' ? $request->set_query_params($params) : $request->set_body_params($params);
        }

        return rest_get_server()->dispatch($request);
    }

    public function test_guests_get_401_everywhere(): void
    {
        wp_set_current_user(0);
        foreach ([['GET', '/notifications'], ['GET', '/notifications/count'], ['POST', '/notifications/read-all'], ['GET', '/preferences'], ['GET', '/types'], ['POST', '/notifications/1/read']] as [$method, $path]) {
            $this->assertSame(401, $this->call($method, $path)->get_status(), $path);
        }
    }

    public function test_listing_and_counting(): void
    {
        $user = $this->user();
        Factory::createMany($user, 3, ['type' => 'general']);
        Factory::create($user, ['type' => 'payout', 'read' => true]);
        Factory::create($this->user()); // Someone else's.
        wp_set_current_user($user);

        $all = $this->call('GET', '/notifications');
        $this->assertSame(200, $all->get_status());
        $this->assertCount(4, $all->get_data()['items']);
        $this->assertSame(3, $all->get_data()['meta']['unread']);
        $this->assertSame('4', $all->get_headers()['X-WP-Total']);
        $this->assertArrayHasKey('open_url', $all->get_data()['items'][0]);

        $this->assertCount(3, $this->call('GET', '/notifications', ['status' => 'unread'])->get_data()['items']);
        $this->assertCount(1, $this->call('GET', '/notifications', ['type' => 'payout'])->get_data()['items']);
        $paged = $this->call('GET', '/notifications', ['per_page' => 3, 'page' => 2]);
        $this->assertCount(1, $paged->get_data()['items']);
        $this->assertSame('2', $paged->get_headers()['X-WP-TotalPages']);
        $this->assertSame(3, $this->call('GET', '/notifications/count')->get_data()['unread']);
    }

    public function test_someone_elses_notification_is_a_404(): void
    {
        $theirs = Factory::create($this->user());
        wp_set_current_user($this->user());

        foreach ([['GET', ''], ['POST', '/read'], ['POST', '/unread'], ['DELETE', '']] as [$method, $suffix]) {
            $this->assertSame(404, $this->call($method, '/notifications/'.$theirs->id.$suffix)->get_status(), $method.$suffix);
        }
        $this->assertNotNull(Inbox::find($theirs->id, $theirs->userId));
        $this->assertTrue(Inbox::find($theirs->id, $theirs->userId)->isUnread());
    }

    public function test_read_unread_read_all_and_delete(): void
    {
        $user = $this->user();
        [$a, $b] = Factory::createMany($user, 2);
        wp_set_current_user($user);

        $read = $this->call('POST', '/notifications/'.$a->id.'/read');
        $this->assertTrue($read->get_data()['read']);
        $this->assertSame(1, $read->get_data()['unread']);
        $this->assertFalse($this->call('POST', '/notifications/'.$a->id.'/unread')->get_data()['read']);

        $all = $this->call('POST', '/notifications/read-all');
        $this->assertSame(['marked' => 2, 'unread' => 0], $all->get_data());

        $deleted = $this->call('DELETE', '/notifications/'.$b->id);
        $this->assertTrue($deleted->get_data()['deleted']);
        $this->assertNull(Inbox::find($b->id, $user));
    }

    public function test_preferences_round_trip(): void
    {
        Types::register('invoice_paid', ['label' => 'Invoice paid', 'group' => 'Billing']);
        Types::register('security', ['label' => 'Security', 'channels' => ['email'], 'required' => true]);
        $user = $this->user();
        wp_set_current_user($user);

        $prefs = $this->call('GET', '/preferences')->get_data();
        $this->assertSame(['inbox' => 'Inbox', 'email' => 'Email'], $prefs['channels']);
        $row = array_values(array_filter($prefs['types'], static fn (array $t): bool => $t['type'] === 'invoice_paid'))[0];
        $this->assertSame(['inbox' => true, 'email' => false], $row['channels']);

        $saved = $this->call('PUT', '/preferences', ['preferences' => ['invoice_paid' => ['email' => true]]]);
        $this->assertSame(200, $saved->get_status());
        $this->assertTrue(Inbox::wants($user, 'invoice_paid', 'email'));

        $this->assertSame(422, $this->call('PUT', '/preferences', ['preferences' => ['nope' => ['email' => true]]])->get_status());
        $this->assertSame(422, $this->call('PUT', '/preferences', ['preferences' => ['security' => ['email' => false]]])->get_status());
    }

    public function test_types_lists_registrations(): void
    {
        wp_set_current_user($this->user());
        $types = array_column($this->call('GET', '/types')->get_data(), 'label', 'type');

        $this->assertSame('Announcements', $types['general']);
    }

    public function test_sending_needs_the_capability(): void
    {
        $to = $this->user();
        wp_set_current_user($this->user('editor'));
        $this->assertSame(403, $this->call('POST', '/notifications', ['users' => [$to], 'title' => 'Hi'])->get_status());
        $this->assertSame(0, Inbox::unreadCount($to));

        $admin = $this->user('administrator');
        wp_set_current_user($admin);
        $sent = $this->call('POST', '/notifications', ['users' => [$to], 'title' => 'Release on Thursday', 'url' => 'https://halyard.test/release']);
        $this->assertSame(201, $sent->get_status());
        $this->assertSame(1, $sent->get_data()['sent']);
        $this->assertSame($admin, Inbox::latest($to)[0]->actorId);
    }
}

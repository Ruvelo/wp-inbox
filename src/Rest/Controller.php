<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Rest;

use Ruvelo\WP\Inbox\Inbox;
use Ruvelo\WP\Inbox\Notification;
use Ruvelo\WP\Inbox\Preferences;
use Ruvelo\WP\Inbox\Settings;
use Ruvelo\WP\Inbox\Store;
use Ruvelo\WP\Inbox\Types;
use Ruvelo\WP\Inbox\Urls;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `ruvelo-inbox/v1`. Every route needs a signed-in user and only touches
 * that user's own notifications: someone else's is a 404, never a 403, so
 * ids can't be probed. Sending to other people needs `ruvelo_send_notifications`.
 */
final class Controller
{
    public const NAMESPACE = 'ruvelo-inbox/v1';

    public static function register(): void
    {
        $id = ['id' => ['type' => 'integer', 'minimum' => 1, 'required' => true]];

        register_rest_route(self::NAMESPACE, '/notifications', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [self::class, 'index'],
                'permission_callback' => [self::class, 'signedIn'],
                'args' => [
                    'status' => ['type' => 'string', 'enum' => ['all', 'unread'], 'default' => 'all'],
                    'type' => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_key'],
                    'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                ],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [self::class, 'send'],
                'permission_callback' => [self::class, 'canSend'],
                'args' => [
                    'users' => ['type' => 'array', 'items' => ['type' => 'integer'], 'required' => true, 'minItems' => 1, 'maxItems' => 1000],
                    'title' => ['type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 255],
                    'type' => ['type' => 'string', 'default' => 'general'],
                    'body' => ['type' => 'string', 'default' => ''],
                    'url' => ['type' => 'string', 'default' => ''],
                    'icon' => ['type' => 'string', 'default' => '', 'maxLength' => 8],
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/notifications/count', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'count'],
            'permission_callback' => [self::class, 'signedIn'],
        ]);

        register_rest_route(self::NAMESPACE, '/notifications/read-all', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'readAll'],
            'permission_callback' => [self::class, 'signedIn'],
            'args' => ['type' => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_key']],
        ]);

        register_rest_route(self::NAMESPACE, '/notifications/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [self::class, 'show'],
                'permission_callback' => [self::class, 'signedIn'],
                'args' => $id,
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [self::class, 'delete'],
                'permission_callback' => [self::class, 'signedIn'],
                'args' => $id,
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/notifications/(?P<id>\d+)/read', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'read'],
            'permission_callback' => [self::class, 'signedIn'],
            'args' => $id,
        ]);

        register_rest_route(self::NAMESPACE, '/notifications/(?P<id>\d+)/unread', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'unread'],
            'permission_callback' => [self::class, 'signedIn'],
            'args' => $id,
        ]);

        register_rest_route(self::NAMESPACE, '/preferences', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [self::class, 'preferences'],
                'permission_callback' => [self::class, 'signedIn'],
            ],
            [
                'methods' => 'PUT, POST, PATCH',
                'callback' => [self::class, 'updatePreferences'],
                'permission_callback' => [self::class, 'signedIn'],
                'args' => ['preferences' => ['type' => 'object', 'required' => true]],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/types', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'types'],
            'permission_callback' => [self::class, 'signedIn'],
        ]);
    }

    public static function signedIn(): true|WP_Error
    {
        return is_user_logged_in()
            ? true
            : new WP_Error('rest_not_logged_in', __('Sign in to see your notifications.', 'ruvelo-inbox'), ['status' => 401]);
    }

    public static function canSend(): true|WP_Error
    {
        if (! is_user_logged_in()) {
            return self::signedIn();
        }

        return current_user_can(Settings::CAP_SEND)
            ? true
            : new WP_Error('rest_forbidden', __('You can’t send notifications to other people.', 'ruvelo-inbox'), ['status' => 403]);
    }

    public static function index(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        $perPage = (int) $request->get_param('per_page');
        $result = Inbox::query($userId, [
            'status' => (string) $request->get_param('status'),
            'type' => (string) $request->get_param('type'),
            'page' => (int) $request->get_param('page'),
            'per_page' => $perPage,
        ]);

        $types = [];
        foreach (Store::typesFor($userId) as $type) {
            $types[] = ['type' => $type, 'label' => Types::label($type)];
        }

        $response = new WP_REST_Response([
            'items' => array_map([self::class, 'item'], $result['items']),
            'meta' => ['unread' => Inbox::unreadCount($userId), 'total' => $result['total'], 'types' => $types],
        ]);
        $response->header('X-WP-Total', (string) $result['total']);
        $response->header('X-WP-TotalPages', (string) (int) ceil($result['total'] / max(1, $perPage)));

        return $response;
    }

    public static function count(): WP_REST_Response
    {
        $userId = get_current_user_id();

        return new WP_REST_Response(['unread' => Inbox::unreadCount($userId), 'latest' => Store::latestId($userId)]);
    }

    public static function show(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $notification = self::mine($request);

        return $notification instanceof Notification ? new WP_REST_Response(self::item($notification)) : $notification;
    }

    public static function read(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $notification = self::mine($request);
        if (! $notification instanceof Notification) {
            return $notification;
        }
        Inbox::markRead($notification->id, $notification->userId);

        return self::fresh($notification);
    }

    public static function unread(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $notification = self::mine($request);
        if (! $notification instanceof Notification) {
            return $notification;
        }
        Inbox::markUnread($notification->id, $notification->userId);

        return self::fresh($notification);
    }

    public static function readAll(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        $marked = Inbox::markAllRead($userId, (string) $request->get_param('type'));

        return new WP_REST_Response(['marked' => $marked, 'unread' => Inbox::unreadCount($userId)]);
    }

    public static function delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $notification = self::mine($request);
        if (! $notification instanceof Notification) {
            return $notification;
        }
        Inbox::delete($notification->id, $notification->userId);

        return new WP_REST_Response(['deleted' => true, 'previous' => self::item($notification), 'unread' => Inbox::unreadCount($notification->userId)]);
    }

    public static function preferences(): WP_REST_Response
    {
        return new WP_REST_Response(self::preferencesFor(get_current_user_id()));
    }

    public static function updatePreferences(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $choices = $request->get_param('preferences');
        $result = Preferences::update(get_current_user_id(), is_array($choices) ? $choices : []);
        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response(self::preferencesFor(get_current_user_id()));
    }

    public static function types(): WP_REST_Response
    {
        $out = [];
        foreach (Types::all() as $name => $type) {
            $out[] = ['type' => $name] + $type;
        }

        return new WP_REST_Response($out);
    }

    public static function send(WP_REST_Request $request): WP_REST_Response
    {
        $users = array_values(array_filter(array_map('intval', (array) $request->get_param('users'))));
        $ids = Inbox::notify($users, [
            'type' => (string) $request->get_param('type'),
            'title' => (string) $request->get_param('title'),
            'body' => (string) $request->get_param('body'),
            'url' => (string) $request->get_param('url'),
            'icon' => (string) $request->get_param('icon'),
            'actor' => get_current_user_id(),
        ]);

        $response = new WP_REST_Response(['ids' => $ids, 'sent' => count($ids)]);
        $response->set_status(201);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public static function item(Notification $notification): array
    {
        return $notification->toArray() + [
            'open_url' => Urls::open($notification, Urls::inbox()),
        ];
    }

    /**
     * @return array{channels: array<string, string>, types: list<array<string, mixed>>}
     */
    public static function preferencesFor(int $userId): array
    {
        $prefs = Preferences::forUser($userId);
        $types = [];
        foreach (Types::all() as $name => $type) {
            $types[] = [
                'type' => $name,
                'label' => $type['label'],
                'description' => $type['description'],
                'group' => $type['group'],
                'required' => $type['required'],
                'channels' => $prefs[$name] ?? [],
            ];
        }

        return ['channels' => Types::channels(), 'types' => $types];
    }

    private static function mine(WP_REST_Request $request): Notification|WP_Error
    {
        $notification = Inbox::find((int) $request->get_param('id'), get_current_user_id());

        return $notification ?? new WP_Error('rest_not_found', __('There’s no notification with that ID in your inbox.', 'ruvelo-inbox'), ['status' => 404]);
    }

    private static function fresh(Notification $notification): WP_REST_Response
    {
        $fresh = Inbox::find($notification->id, $notification->userId) ?? $notification;

        return new WP_REST_Response(self::item($fresh) + ['unread' => Inbox::unreadCount($notification->userId)]);
    }
}

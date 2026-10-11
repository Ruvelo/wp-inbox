<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox;

/**
 * Tools → Export/Erase Personal Data: a person's notifications, their
 * waiting emails and their preferences. Also cleans up when a user is deleted.
 */
final class Privacy
{
    private const PAGE = 200;

    public static function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'exporters']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'erasers']);
        add_action('admin_init', [self::class, 'policy']);
        add_action('deleted_user', [self::class, 'deletedUser']);
    }

    /**
     * @param array<string, mixed> $exporters
     * @return array<string, mixed>
     */
    public static function exporters(array $exporters): array
    {
        $exporters['ruvelo-inbox'] = ['exporter_friendly_name' => __('Notifications', 'ruvelo-inbox'), 'callback' => [self::class, 'export']];

        return $exporters;
    }

    /**
     * @param array<string, mixed> $erasers
     * @return array<string, mixed>
     */
    public static function erasers(array $erasers): array
    {
        $erasers['ruvelo-inbox'] = ['eraser_friendly_name' => __('Notifications', 'ruvelo-inbox'), 'callback' => [self::class, 'erase']];

        return $erasers;
    }

    /**
     * @return array{data: list<array<string, mixed>>, done: bool}
     */
    public static function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if ($user === false) {
            return ['data' => [], 'done' => true];
        }

        $data = [];
        if ($page === 1) {
            $prefs = [];
            foreach (Preferences::explicit($user->ID) as $type => $channels) {
                foreach ($channels as $channel => $on) {
                    $prefs[] = ['name' => Types::label($type).' ('.$channel.')', 'value' => $on ? __('On', 'ruvelo-inbox') : __('Off', 'ruvelo-inbox')];
                }
            }
            if ($prefs !== []) {
                $data[] = ['group_id' => 'ruvelo-inbox-preferences', 'group_label' => __('Notification preferences', 'ruvelo-inbox'), 'item_id' => 'ruvelo-inbox-preferences', 'data' => $prefs];
            }
        }

        $result = Store::query($user->ID, ['page' => $page, 'per_page' => self::PAGE]);
        foreach ($result['items'] as $notification) {
            $data[] = [
                'group_id' => 'ruvelo-inbox-notifications',
                'group_label' => __('Notifications', 'ruvelo-inbox'),
                'item_id' => 'ruvelo-inbox-'.$notification->id,
                'data' => [
                    ['name' => __('Type', 'ruvelo-inbox'), 'value' => $notification->typeLabel()],
                    ['name' => __('Title', 'ruvelo-inbox'), 'value' => $notification->title],
                    ['name' => __('Text', 'ruvelo-inbox'), 'value' => $notification->body],
                    ['name' => __('Link', 'ruvelo-inbox'), 'value' => $notification->url],
                    ['name' => __('From', 'ruvelo-inbox'), 'value' => $notification->actorName],
                    ['name' => __('Received', 'ruvelo-inbox'), 'value' => $notification->createdAt.' UTC'],
                    ['name' => __('Read', 'ruvelo-inbox'), 'value' => $notification->readAt !== null ? $notification->readAt.' UTC' : __('Not yet', 'ruvelo-inbox')],
                ],
            ];
        }

        return ['data' => $data, 'done' => $page * self::PAGE >= $result['total']];
    }

    /**
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public static function erase(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if ($user === false) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }

        $removed = Store::deleteForUser($user->ID) > 0;
        $removed = Mailer::forgetUser($user->ID) > 0 || $removed;
        if (Preferences::explicit($user->ID) !== []) {
            Preferences::forget($user->ID);
            $removed = true;
        }

        return ['items_removed' => $removed, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    /**
     * A deleted user's inbox goes with them.
     */
    public static function deletedUser(int $userId): void
    {
        Store::deleteForUser($userId);
        Mailer::forgetUser($userId);
    }

    public static function policy(): void
    {
        if (! function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content(
            __('Ruvelo Inbox', 'ruvelo-inbox'),
            '<p>'.esc_html__('When something on this site concerns you (a mention, a reply, an update to something you follow), we keep a notification for you: what happened, a link, who did it and when, and whether you’ve read it. Old notifications are deleted after a while. You choose which notifications you get, in the inbox and by email; we keep those choices. Notification emails are sent from this site; nothing is shared with anyone else.', 'ruvelo-inbox').'</p>'
        );
    }
}

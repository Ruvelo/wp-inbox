<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox;

use Ruvelo\WP\Inbox\Sources\Sources;
use WP_User;

/**
 * Seeds Halyard's demo (demo/content.php): the people, the posts with their
 * notes and comments, and Maya's inbox. Only for trying the plugin out:
 * `wp ruvelo-inbox demo --yes`, and the Playground blueprint.
 */
final class Demo
{
    /**
     * @return array{users: int, posts: int, notifications: int}
     */
    public static function seed(): array
    {
        /** @var array{people: array<string, array{0: string, 1: string, 2: string}>, posts: array<string, array{title: string, status: string, author: string, content: string}>, inbox: list<array<int, mixed>>} $content */
        $content = require RUVELO_INBOX_DIR.'demo/content.php';

        // The setup itself mustn't notify anyone.
        Inbox::pause();

        $maya = get_userdata(1);
        if (! $maya instanceof WP_User) {
            Inbox::pause(false);

            return ['users' => 0, 'posts' => 0, 'notifications' => 0];
        }
        wp_update_user(['ID' => 1, 'display_name' => 'Maya Okafor', 'first_name' => 'Maya', 'last_name' => 'Okafor', 'nickname' => 'Maya']);

        $people = ['maya' => 1];
        foreach ($content['people'] as $login => [$first, $last, $role]) {
            $user = get_user_by('login', $login);
            $id = $user instanceof WP_User ? $user->ID : wp_insert_user([
                'user_login' => $login,
                'user_email' => $login.'@halyard.test',
                'user_pass' => wp_generate_password(24),
                'first_name' => $first,
                'last_name' => $last,
                'display_name' => $first.' '.$last,
                'role' => $role,
            ]);
            $people[strtolower($first) === 'inès' ? 'ines' : strtolower($first)] = is_int($id) ? $id : 0;
        }

        // Remembered by key: pending posts have no slug to find them by.
        $saved = get_option('ruvelo_inbox_demo_posts', []);
        $saved = is_array($saved) ? $saved : [];
        $posts = [];
        foreach ($content['posts'] as $key => $post) {
            $existing = isset($saved[$key]) && get_post((int) $saved[$key]) !== null ? (int) $saved[$key] : 0;
            $posts[$key] = $existing > 0 ? $existing : (int) wp_insert_post([
                'post_title' => $post['title'],
                'post_name' => sanitize_title($post['title']),
                'post_content' => '<!-- wp:paragraph --><p>'.esc_html($post['content']).'</p><!-- /wp:paragraph -->',
                'post_status' => $post['status'],
                'post_author' => $people[$post['author']] ?? 1,
                'post_date' => gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS),
            ]);
        }

        if (! isset($saved['inbox-page']) || get_post((int) $saved['inbox-page']) === null) {
            $posts['inbox-page'] = (int) wp_insert_post([
                'post_type' => 'page',
                'post_title' => 'Your notifications',
                'post_name' => 'notifications',
                'post_content' => '<!-- wp:ruvelo/inbox {"showHeading":false} /-->',
                'post_status' => 'publish',
                'post_author' => 1,
            ]);
        } else {
            $posts['inbox-page'] = (int) $saved['inbox-page'];
        }
        update_option('ruvelo_inbox_demo_posts', $posts, false);

        self::conversations($posts, $people);
        Inbox::pause(false);

        // Start Maya's inbox over, then fill it.
        Store::deleteForUser(1);
        $inboxOnly = static fn (): array => ['inbox'];
        add_filter('ruvelo_inbox_notification_channels', $inboxOnly);

        $count = 0;
        foreach ($content['inbox'] as $row) {
            [$minutes, $type, $from, $postKey, $title, $body, $read] = $row;
            $extra = isset($row[7]) && is_array($row[7]) ? $row[7] : [];
            $actorId = is_string($from) && isset($people[$from]) ? $people[$from] : 0;
            $actorName = $actorId > 0 ? Sources::nameOf($actorId) : (is_string($from) ? $from : '');
            $postId = is_string($postKey) ? ($posts[$postKey] ?? 0) : 0;
            $postTitle = $postId > 0 ? get_the_title($postId) : '';
            $created = time() - (int) $minutes * MINUTE_IN_SECONDS;

            $url = isset($extra['url']) && is_string($extra['url']) ? home_url($extra['url']) : self::link($type, $postId);
            $ids = Inbox::notify(1, [
                'type' => $type,
                'title' => is_string($title) ? $title : self::title((string) $type, $actorName, $postTitle, $created),
                'body' => (string) $body,
                'url' => $url,
                'icon' => $extra['icon'] ?? '',
                'actor' => $actorId > 0 ? $actorId : $actorName,
                'data' => ['post_id' => $postId, 'demo' => true],
                'created_at' => $created,
                'read_at' => $read ? $created + 600 : null,
            ]);
            $count += count($ids);
        }

        // Teammates get a little too, so their bells aren't empty.
        foreach (['tom', 'ines', 'kenji'] as $who) {
            if (($people[$who] ?? 0) > 0) {
                Inbox::notify($people[$who], [
                    'type' => 'general',
                    'title' => 'Halyard 4.2 ships on Thursday',
                    'body' => 'Code freeze starts Wednesday at 18:00 UTC. Post blockers in #release before then.',
                    'icon' => '!',
                    'actor' => $people['ines'] ?? 0,
                    'created_at' => time() - 1530 * MINUTE_IN_SECONDS,
                ]);
            }
        }
        remove_filter('ruvelo_inbox_notification_channels', $inboxOnly);

        return ['users' => count($people), 'posts' => count($content['posts']), 'notifications' => $count];
    }

    /**
     * The notes and comments the notifications point at.
     *
     * @param array<string, int> $posts
     * @param array<string, int> $people
     */
    private static function conversations(array $posts, array $people): void
    {
        if (get_option('ruvelo_inbox_demo_conversations')) {
            return;
        }
        $mention = '<span class="wp-note-mention user-1">@Maya Okafor</span>';
        $note = static function (string $post, string $who, string $text, int $parent = 0) use ($posts, $people): int {
            $user = get_userdata($people[$who] ?? 0);

            return (int) wp_insert_comment([
                'comment_post_ID' => $posts[$post] ?? 0,
                'comment_type' => 'note',
                'comment_content' => $text,
                'comment_parent' => $parent,
                'user_id' => $people[$who] ?? 0,
                'comment_author' => $user instanceof WP_User ? $user->display_name : '',
                'comment_approved' => 1,
            ]);
        };
        $comment = static function (string $post, string $who, string $text, int $parent = 0) use ($posts, $people): int {
            $user = get_userdata($people[$who] ?? 0);

            return (int) wp_insert_comment([
                'comment_post_ID' => $posts[$post] ?? 0,
                'comment_type' => 'comment',
                'comment_content' => $text,
                'comment_parent' => $parent,
                'user_id' => $people[$who] ?? 0,
                'comment_author' => $user instanceof WP_User ? $user->display_name : $who,
                'comment_author_email' => $user instanceof WP_User ? $user->user_email : 'priya@northwind.test',
                'comment_approved' => 1,
            ]);
        };

        $note('release-notes', 'tom', $mention.' can you confirm the dunning numbers before this goes out? I used 41% from the Bluefin case study.');
        $note('pricing', 'ines', 'Can we lead with the annual discount? Two of last week’s demos asked about it first.');
        $first = $note('dunning', 'maya', 'Should the second reminder still mention the late fee?');
        $note('dunning', 'tom', 'Agreed, the second reminder reads better without the late-fee line. I’ve cut it.', $first);
        $thread = $note('webhooks', 'kenji', 'Is three days of retries enough for customers on weekly batch jobs?');
        $note('webhooks', 'maya', 'Three days is what Stripe does too. Let’s show the intervals.', $thread);
        $note('webhooks', 'kenji', 'I’ll add a table of the retry intervals under the backoff paragraph.', $thread);
        $note('templates', 'ines', $mention.' does the PO number need to be on credit notes too?');

        $mine = $comment('bluefin', 'maya', 'The retry schedule we recommend is 3, 5 and 7 days after the first failure.');
        $comment('bluefin', 'kenji', 'Good catch, I’ve fixed the retry schedule in the post. Bluefin used 3, 7 and 14 days, not 3, 5 and 7.', $mine);
        $comment('bluefin', 'Priya Shah', 'We’re seeing the same pattern at our agency: most failed cards are expired, not declined. Did Bluefin email before the first retry?');
        $sso = $comment('security', 'maya', 'Does this include the Starter plan from today?');
        $comment('security', 'tom', 'Yes, Starter gets SSO from today. The pricing table updates on Monday.', $sso);

        update_option('ruvelo_inbox_demo_conversations', 1, false);
    }

    private static function link(mixed $type, int $postId): string
    {
        if ($postId <= 0) {
            return Urls::inbox();
        }
        if (is_string($type) && str_starts_with($type, 'note_')) {
            return admin_url('post.php?post='.$postId.'&action=edit');
        }
        if ($type === 'post_scheduled') {
            return admin_url('post.php?post='.$postId.'&action=edit');
        }
        $link = get_permalink($postId);
        $link = is_string($link) ? $link : home_url('/');

        return is_string($type) && str_starts_with($type, 'comment_') ? $link.'#comments' : $link;
    }

    private static function title(string $type, string $name, string $post, int $created): string
    {
        return match ($type) {
            /* translators: 1: person's name, 2: post title */
            'note_mention' => sprintf(__('%1$s mentioned you in a note on “%2$s”', 'ruvelo-inbox'), $name, $post),
            /* translators: 1: person's name, 2: post title */
            'note_reply' => sprintf(__('%1$s replied to your note on “%2$s”', 'ruvelo-inbox'), $name, $post),
            /* translators: 1: person's name, 2: post title */
            'note_on_post' => sprintf(__('%1$s left a note on “%2$s”', 'ruvelo-inbox'), $name, $post),
            /* translators: 1: person's name, 2: post title */
            'note_thread' => sprintf(__('%1$s replied in a note thread on “%2$s”', 'ruvelo-inbox'), $name, $post),
            /* translators: 1: person's name, 2: post title */
            'comment_reply' => sprintf(__('%1$s replied to your comment on “%2$s”', 'ruvelo-inbox'), $name, $post),
            /* translators: 1: person's name, 2: post title */
            'comment_on_post' => sprintf(__('%1$s commented on “%2$s”', 'ruvelo-inbox'), $name, $post),
            /* translators: %s: post title */
            'post_published' => sprintf(__('“%s” is published', 'ruvelo-inbox'), $post),
            /* translators: 1: post title, 2: date and time */
            'post_scheduled' => sprintf(__('“%1$s” is scheduled for %2$s', 'ruvelo-inbox'), $post, (string) wp_date((string) get_option('date_format').' '.(string) get_option('time_format'), $created + 2 * DAY_IN_SECONDS)),
            default => $post,
        };
    }
}

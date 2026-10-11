<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Sources;

use Ruvelo\WP\Inbox\Inbox;
use Ruvelo\WP\Inbox\Preferences;
use Ruvelo\WP\Inbox\Types;
use WP_Comment;
use WP_Post;
use WP_User;

/**
 * The block editor's Notes (comments of type `note`, WordPress 6.9+).
 *
 * WordPress itself emails a post's author about every note, and (from 7.1)
 * emails people mentioned with `@`. Replies to your note and threads you
 * took part in reach nobody. This puts all four in the inbox, and lets each
 * person switch core's two emails off from the same preferences: the
 * `notify_post_author` filter for the author, and a copy of core's mention
 * loop that asks the preferences before calling core's own mailer.
 *
 * "Following" a thread isn't something core stores, so taking part in it
 * (writing the first note or a reply) is what follows it.
 */
final class Notes implements Source
{
    public const MENTION = 'note_mention';

    public const REPLY = 'note_reply';

    public const ON_POST = 'note_on_post';

    public const THREAD = 'note_thread';

    public static function label(): string
    {
        return __('Notes in the editor', 'ruvelo-inbox');
    }

    public static function description(): string
    {
        return __('Mentions, replies, new notes on your posts and threads you’re in, from the block editor’s Notes. You can take over WordPress’s own note emails from each person’s preferences.', 'ruvelo-inbox');
    }

    public static function available(): bool
    {
        return true;
    }

    /**
     * Mentions arrived in WordPress 7.1.
     */
    public static function mentions(): bool
    {
        return function_exists('wp_get_note_mentioned_user_ids');
    }

    public static function types(): void
    {
        $group = __('Notes', 'ruvelo-inbox');

        if (self::mentions()) {
            Types::register(self::MENTION, [
                'label' => __('Mentions in notes', 'ruvelo-inbox'),
                'description' => __('When someone @mentions you in a note.', 'ruvelo-inbox'),
                'group' => $group,
                'defaults' => ['inbox' => true, 'email' => true],
                'icon' => '@',
                'sends_email' => false, // WordPress sends it; the switch decides.
                'source' => 'notes',
            ]);
        }
        Types::register(self::REPLY, [
            'label' => __('Replies to your notes', 'ruvelo-inbox'),
            'description' => __('When someone replies to a note you wrote.', 'ruvelo-inbox'),
            'group' => $group,
            'defaults' => ['inbox' => true, 'email' => false],
            'source' => 'notes',
        ]);
        Types::register(self::ON_POST, [
            'label' => __('Notes on your posts', 'ruvelo-inbox'),
            'description' => __('When someone leaves a note on a post you wrote.', 'ruvelo-inbox'),
            'group' => $group,
            'defaults' => ['inbox' => true, 'email' => true],
            'sends_email' => false, // WordPress sends it; the switch decides.
            'source' => 'notes',
        ]);
        Types::register(self::THREAD, [
            'label' => __('Note threads you’re in', 'ruvelo-inbox'),
            'description' => __('New replies in a note thread you started or replied to.', 'ruvelo-inbox'),
            'group' => $group,
            'defaults' => ['inbox' => true, 'email' => false],
            'source' => 'notes',
        ]);
    }

    public static function hooks(): void
    {
        add_action('wp_insert_comment', [self::class, 'inserted'], 20, 2);
        add_filter('notify_post_author', [self::class, 'postAuthorEmail'], 20, 2);

        if (function_exists('wp_notify_note_mentions') && function_exists('wp_send_note_notification')
            && has_action('rest_insert_comment', 'wp_notify_note_mentions') !== false) {
            remove_action('rest_insert_comment', 'wp_notify_note_mentions', 10);
            add_action('rest_insert_comment', [self::class, 'mentionEmails'], 10, 3);
        }
    }

    public static function unhook(): void
    {
        remove_action('wp_insert_comment', [self::class, 'inserted'], 20);
        remove_filter('notify_post_author', [self::class, 'postAuthorEmail'], 20);
        if (has_action('rest_insert_comment', [self::class, 'mentionEmails']) !== false) {
            remove_action('rest_insert_comment', [self::class, 'mentionEmails'], 10);
            if (function_exists('wp_notify_note_mentions')) {
                add_action('rest_insert_comment', 'wp_notify_note_mentions', 10, 3);
            }
        }
    }

    /**
     * A note was saved: tell the people it concerns, each once, by the most
     * specific reason (mention, then reply, then your post, then your thread).
     */
    public static function inserted(int $id, WP_Comment $comment): void
    {
        if ($comment->comment_type !== 'note' || Inbox::paused()) {
            return;
        }
        $post = get_post((int) $comment->comment_post_ID);
        if (! $post instanceof WP_Post) {
            return;
        }

        $author = (int) $comment->user_id;
        $reasons = [];

        if (Types::exists(self::MENTION)) {
            foreach (self::mentioned($comment->comment_content) as $userId) {
                $reasons[$userId] ??= self::MENTION;
            }
        }
        $parentId = (int) $comment->comment_parent;
        $parent = $parentId > 0 ? get_comment($parentId) : null;
        if ($parent instanceof WP_Comment && (int) $parent->user_id > 0) {
            $reasons[(int) $parent->user_id] ??= self::REPLY;
        }
        if ((int) $post->post_author > 0) {
            $reasons[(int) $post->post_author] ??= self::ON_POST;
        }
        if ($parent instanceof WP_Comment) {
            foreach (self::participants($parent) as $userId) {
                $reasons[$userId] ??= self::THREAD;
            }
        }

        unset($reasons[$author]);

        $name = Sources::nameOf($author, $comment->comment_author);
        $title = get_the_title($post);
        $body = Sources::plain($comment->comment_content);

        foreach ($reasons as $userId => $type) {
            // Notes are internal: only people who could read this one in the editor hear about it.
            if (! user_can($userId, 'edit_comment', $id)) {
                continue;
            }
            Sources::send($userId, [
                'type' => $type,
                'title' => self::title($type, $name, $title),
                'body' => $body,
                'url' => Sources::editLink($post->ID),
                'actor' => $author > 0 ? $author : $name,
                'data' => ['post_id' => $post->ID, 'comment_id' => $id, 'parent_id' => $parentId],
            ]);
        }
    }

    private static function title(string $type, string $name, string $post): string
    {
        return match ($type) {
            /* translators: 1: person's name, 2: post title */
            self::MENTION => sprintf(__('%1$s mentioned you in a note on “%2$s”', 'ruvelo-inbox'), $name, $post),
            /* translators: 1: person's name, 2: post title */
            self::REPLY => sprintf(__('%1$s replied to your note on “%2$s”', 'ruvelo-inbox'), $name, $post),
            /* translators: 1: person's name, 2: post title */
            self::ON_POST => sprintf(__('%1$s left a note on “%2$s”', 'ruvelo-inbox'), $name, $post),
            /* translators: 1: person's name, 2: post title */
            default => sprintf(__('%1$s replied in a note thread on “%2$s”', 'ruvelo-inbox'), $name, $post),
        };
    }

    /**
     * Users mentioned in a note: core's parser on 7.1, the same rule
     * (`<span class="wp-note-mention user-N">`) otherwise.
     *
     * @return list<int>
     */
    public static function mentioned(string $content): array
    {
        if (function_exists('wp_get_note_mentioned_user_ids')) {
            return array_values(array_map('intval', wp_get_note_mentioned_user_ids($content)));
        }
        if (! str_contains($content, 'wp-note-mention')) {
            return [];
        }
        $ids = [];
        $tags = new \WP_HTML_Tag_Processor($content);
        while ($tags->next_tag(['tag_name' => 'SPAN', 'class_name' => 'wp-note-mention'])) {
            foreach ($tags->class_list() as $class) {
                if (preg_match('/^user-(\d+)$/', $class, $match) === 1 && (int) $match[1] > 0) {
                    $ids[] = (int) $match[1];
                    break;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Everyone who wrote in a thread: its first note and the replies.
     *
     * @return list<int>
     */
    private static function participants(WP_Comment $note): array
    {
        $root = $note;
        $guard = 0;
        while ((int) $root->comment_parent > 0 && $guard++ < 10) {
            $up = get_comment((int) $root->comment_parent);
            if (! $up instanceof WP_Comment) {
                break;
            }
            $root = $up;
        }

        $replies = get_comments(['type' => 'note', 'parent' => (int) $root->comment_ID, 'status' => 'all', 'number' => 200]);
        $ids = [(int) $root->user_id];
        foreach (is_array($replies) ? $replies : [] as $reply) {
            if ($reply instanceof WP_Comment) {
                $ids[] = (int) $reply->user_id;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Core's "New note on your post" email, only if the author still wants it.
     */
    public static function postAuthorEmail(mixed $maybe, mixed $commentId): bool
    {
        $comment = get_comment(is_numeric($commentId) ? (int) $commentId : 0);
        if (! $maybe || ! $comment instanceof WP_Comment || $comment->comment_type !== 'note') {
            return (bool) $maybe;
        }
        $post = get_post((int) $comment->comment_post_ID);
        if (! $post instanceof WP_Post || (int) $post->post_author <= 0) {
            return (bool) $maybe;
        }

        return Preferences::wants((int) $post->post_author, self::ON_POST, 'email');
    }

    /**
     * Core's mention emails (wp_notify_note_mentions()), with the same rules,
     * asking each person's preferences first. Core's mailer sends them.
     */
    public static function mentionEmails(mixed $comment, mixed $request = null, bool $creating = true): void
    {
        if (! $creating || ! $comment instanceof WP_Comment || $comment->comment_type !== 'note' || ! get_option('wp_notes_notify', 1)) {
            return;
        }
        if (! function_exists('wp_send_note_notification')) {
            return;
        }

        $author = (int) $comment->user_id;
        $post = get_post((int) $comment->comment_post_ID);
        $postAuthor = $post instanceof WP_Post ? (int) $post->post_author : 0;

        foreach (self::mentioned($comment->comment_content) as $userId) {
            if ($userId === $author || $userId === $postAuthor) {
                continue;
            }
            $user = get_userdata($userId);
            if (! $user instanceof WP_User || $user->user_email === '' || ! user_can($userId, 'edit_comment', $comment->comment_ID)) {
                continue;
            }
            if (! Preferences::wants($userId, self::MENTION, 'email')) {
                continue;
            }
            wp_send_note_notification($user, $comment, $post instanceof WP_Post ? $post : null);
        }
    }
}

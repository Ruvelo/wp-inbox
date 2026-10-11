<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Sources;

use Ruvelo\WP\Inbox\Inbox;
use Ruvelo\WP\Inbox\Preferences;
use Ruvelo\WP\Inbox\Types;
use WP_Comment;
use WP_Post;

/**
 * Comments: "someone replied to your comment" (which WordPress never sends)
 * and "new comment on your post" (which it emails when "Email me whenever
 * anyone posts a comment" is on; the inbox adds it, and each author can
 * switch that email off for themselves).
 *
 * Only approved comments count, so nobody hears about spam, and each
 * comment is told once, when it's first approved.
 */
final class Comments implements Source
{
    public const REPLY = 'comment_reply';

    public const ON_POST = 'comment_on_post';

    private const DONE = '_ruvelo_inbox_notified';

    public static function label(): string
    {
        return __('Comments', 'ruvelo-inbox');
    }

    public static function description(): string
    {
        return __('Replies to people’s comments, and new comments on posts they wrote.', 'ruvelo-inbox');
    }

    public static function available(): bool
    {
        return true;
    }

    public static function types(): void
    {
        $group = __('Comments', 'ruvelo-inbox');

        Types::register(self::REPLY, [
            'label' => __('Replies to your comments', 'ruvelo-inbox'),
            'description' => __('When someone replies to a comment you left.', 'ruvelo-inbox'),
            'group' => $group,
            'defaults' => ['inbox' => true, 'email' => true],
            'source' => 'comments',
        ]);
        Types::register(self::ON_POST, [
            'label' => __('Comments on your posts', 'ruvelo-inbox'),
            'description' => __('When someone comments on a post you wrote.', 'ruvelo-inbox'),
            'group' => $group,
            'defaults' => ['inbox' => true, 'email' => true],
            'sends_email' => false, // WordPress sends it (Settings → Discussion); the switch decides.
            'source' => 'comments',
        ]);
    }

    public static function hooks(): void
    {
        add_action('wp_insert_comment', [self::class, 'inserted'], 20, 2);
        add_action('transition_comment_status', [self::class, 'transitioned'], 20, 3);
        add_filter('notify_post_author', [self::class, 'postAuthorEmail'], 20, 2);
    }

    public static function unhook(): void
    {
        remove_action('wp_insert_comment', [self::class, 'inserted'], 20);
        remove_action('transition_comment_status', [self::class, 'transitioned'], 20);
        remove_filter('notify_post_author', [self::class, 'postAuthorEmail'], 20);
    }

    public static function inserted(int $id, WP_Comment $comment): void
    {
        if ((string) $comment->comment_approved === '1') {
            self::approved($comment);
        }
    }

    public static function transitioned(mixed $new, mixed $old, mixed $comment): void
    {
        if ($new === 'approved' && $comment instanceof WP_Comment) {
            self::approved($comment);
        }
    }

    public static function approved(WP_Comment $comment): void
    {
        if (! in_array($comment->comment_type, ['comment', ''], true) || Inbox::paused()) {
            return;
        }
        $id = (int) $comment->comment_ID;
        if (get_comment_meta($id, self::DONE, true)) {
            return;
        }
        $post = get_post((int) $comment->comment_post_ID);
        if (! $post instanceof WP_Post) {
            return;
        }
        update_comment_meta($id, self::DONE, 1);

        $commenter = (int) $comment->user_id;
        $name = Sources::nameOf($commenter, $comment->comment_author);
        $title = get_the_title($post);
        $url = (string) get_comment_link($comment);
        $body = Sources::plain($comment->comment_content);
        $told = [$commenter];

        $parent = (int) $comment->comment_parent > 0 ? get_comment((int) $comment->comment_parent) : null;
        if ($parent instanceof WP_Comment && (int) $parent->user_id > 0 && ! in_array((int) $parent->user_id, $told, true)) {
            $to = (int) $parent->user_id;
            $told[] = $to;
            if (user_can($to, 'read_post', $post->ID)) {
                Sources::send($to, [
                    'type' => self::REPLY,
                    /* translators: 1: person's name, 2: post title */
                    'title' => sprintf(__('%1$s replied to your comment on “%2$s”', 'ruvelo-inbox'), $name, $title),
                    'body' => $body,
                    'url' => $url,
                    'actor' => $commenter > 0 ? $commenter : $name,
                    'data' => ['post_id' => $post->ID, 'comment_id' => $id, 'parent_id' => (int) $parent->comment_ID],
                ]);
            }
        }

        $author = (int) $post->post_author;
        if ($author > 0 && ! in_array($author, $told, true)) {
            Sources::send($author, [
                'type' => self::ON_POST,
                /* translators: 1: person's name, 2: post title */
                'title' => sprintf(__('%1$s commented on “%2$s”', 'ruvelo-inbox'), $name, $title),
                'body' => $body,
                'url' => $url,
                'actor' => $commenter > 0 ? $commenter : $name,
                'data' => ['post_id' => $post->ID, 'comment_id' => $id],
            ]);
        }
    }

    /**
     * Core's "New comment on your post" email, only if the author still wants it.
     */
    public static function postAuthorEmail(mixed $maybe, mixed $commentId): bool
    {
        $comment = get_comment(is_numeric($commentId) ? (int) $commentId : 0);
        if (! $maybe || ! $comment instanceof WP_Comment || ! in_array($comment->comment_type, ['comment', ''], true)) {
            return (bool) $maybe;
        }
        $post = get_post((int) $comment->comment_post_ID);
        if (! $post instanceof WP_Post || (int) $post->post_author <= 0) {
            return (bool) $maybe;
        }

        return Preferences::wants((int) $post->post_author, self::ON_POST, 'email');
    }
}

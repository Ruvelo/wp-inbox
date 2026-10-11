<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Sources;

use Ruvelo\WP\Inbox\Inbox;
use Ruvelo\WP\Inbox\Types;
use WP_Post;

/**
 * Tells authors when their post goes live or is scheduled, unless they
 * did it themselves: a scheduled post publishing overnight, or an editor
 * publishing a contributor's draft.
 */
final class Posts implements Source
{
    public const PUBLISHED = 'post_published';

    public const SCHEDULED = 'post_scheduled';

    public static function label(): string
    {
        return __('Posts', 'ruvelo-inbox');
    }

    public static function description(): string
    {
        return __('Tells authors when their post is published or scheduled by someone else, or goes live on schedule.', 'ruvelo-inbox');
    }

    public static function available(): bool
    {
        return true;
    }

    public static function types(): void
    {
        $group = __('Your posts', 'ruvelo-inbox');

        Types::register(self::PUBLISHED, [
            'label' => __('Your post is published', 'ruvelo-inbox'),
            'description' => __('When a post you wrote goes live on schedule, or someone else publishes it.', 'ruvelo-inbox'),
            'group' => $group,
            'defaults' => ['inbox' => true, 'email' => false],
            'icon' => '✓',
            'source' => 'posts',
        ]);
        Types::register(self::SCHEDULED, [
            'label' => __('Your post is scheduled', 'ruvelo-inbox'),
            'description' => __('When someone else schedules a post you wrote.', 'ruvelo-inbox'),
            'group' => $group,
            'defaults' => ['inbox' => true, 'email' => false],
            'icon' => '◷',
            'source' => 'posts',
        ]);
    }

    public static function hooks(): void
    {
        add_action('transition_post_status', [self::class, 'transitioned'], 20, 3);
    }

    public static function unhook(): void
    {
        remove_action('transition_post_status', [self::class, 'transitioned'], 20);
    }

    /**
     * Post types that notify: public ones with an admin screen. Filter
     * `ruvelo_inbox_post_types` to change them.
     *
     * @return list<string>
     */
    public static function postTypes(): array
    {
        $types = array_values(get_post_types(['public' => true, 'show_ui' => true]));
        $types = array_values(array_diff($types, ['attachment']));

        /**
         * Filters the post types whose authors hear about publishing.
         *
         * @param list<string> $types
         */
        $filtered = apply_filters('ruvelo_inbox_post_types', $types);

        return is_array($filtered) ? array_values(array_filter($filtered, 'is_string')) : $types;
    }

    public static function transitioned(mixed $new, mixed $old, mixed $post): void
    {
        if (! $post instanceof WP_Post || $new === $old || Inbox::paused() || wp_is_post_revision($post) || wp_is_post_autosave($post)) {
            return;
        }
        if (! in_array($post->post_type, self::postTypes(), true)) {
            return;
        }

        $author = (int) $post->post_author;
        $by = get_current_user_id();
        if ($author <= 0 || $author === $by) {
            return;
        }
        // Nobody signed in (imports, WP-CLI) only counts for a scheduled post going live.
        if ($by === 0 && ! ($new === 'publish' && $old === 'future')) {
            return;
        }

        $title = get_the_title($post);
        $title = $title !== '' ? $title : __('(no title)', 'ruvelo-inbox');
        $name = $by > 0 ? Sources::nameOf($by) : '';

        if ($new === 'publish') {
            $body = $old === 'future'
                ? __('It went live on schedule.', 'ruvelo-inbox')
                /* translators: %s: person's name */
                : ($name !== '' ? sprintf(__('%s published it.', 'ruvelo-inbox'), $name) : __('It’s live now.', 'ruvelo-inbox'));
            Sources::send($author, [
                'type' => self::PUBLISHED,
                /* translators: %s: post title */
                'title' => sprintf(__('“%s” is published', 'ruvelo-inbox'), $title),
                'body' => $body,
                'url' => (string) get_permalink($post),
                'actor' => $by > 0 ? $by : '',
                'icon' => '✓',
                'data' => ['post_id' => $post->ID],
            ]);
        } elseif ($new === 'future' && $by > 0) {
            $when = (string) wp_date((string) get_option('date_format').' '.(string) get_option('time_format'), (int) strtotime($post->post_date_gmt.' UTC'));
            Sources::send($author, [
                'type' => self::SCHEDULED,
                /* translators: 1: post title, 2: date and time */
                'title' => sprintf(__('“%1$s” is scheduled for %2$s', 'ruvelo-inbox'), $title, $when),
                /* translators: %s: person's name */
                'body' => sprintf(__('%s scheduled it.', 'ruvelo-inbox'), $name),
                'url' => Sources::editLink($post->ID),
                'actor' => $by,
                'icon' => '◷',
                'data' => ['post_id' => $post->ID],
            ]);
        }
    }
}

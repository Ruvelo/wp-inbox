<?php

declare(strict_types=1);

/**
 * WordPress 7.1 functions that php-stubs/wordpress-stubs (6.9) doesn't
 * declare yet, copied from wp-includes/comment.php. For PHPStan only.
 */

/**
 * @return list<positive-int>
 */
function wp_get_note_mentioned_user_ids(string $content): array {}

/**
 * @param mixed $request
 */
function wp_notify_note_mentions(?WP_Comment $comment, $request = null, bool $creating = true): void {}

function wp_send_note_notification(WP_User $user, WP_Comment $comment, ?WP_Post $post): bool {}

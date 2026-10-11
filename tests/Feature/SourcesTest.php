<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Tests\Feature;

use Ruvelo\WP\Inbox\Inbox;
use Ruvelo\WP\Inbox\Mailer;
use Ruvelo\WP\Inbox\Sources\Feedback;
use Ruvelo\WP\Inbox\Sources\Notes;
use Ruvelo\WP\Inbox\Sources\Sources;
use Ruvelo\WP\Inbox\Tests\TestCase;
use Ruvelo\WP\Inbox\Types;

final class SourcesTest extends TestCase
{
    private function note(int $post, int $author, string $content, int $parent = 0): int
    {
        return self::factory()->comment->create([
            'comment_post_ID' => $post,
            'comment_type' => 'note',
            'comment_content' => $content,
            'comment_parent' => $parent,
            'user_id' => $author,
            'comment_author' => get_userdata($author)->display_name,
            'comment_approved' => 1,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function types(int $user): array
    {
        $out = [];
        foreach (Inbox::query($user, ['per_page' => 50])['items'] as $n) {
            $out[$n->type] = ($out[$n->type] ?? 0) + 1;
        }

        return $out;
    }

    // Notes ----------------------------------------------------------------

    public function test_a_note_tells_the_post_author(): void
    {
        $maya = $this->user('editor', 'Maya Okafor');
        $ines = $this->user('editor', 'Inès Laurent');
        $post = self::factory()->post->create(['post_author' => $maya, 'post_title' => 'Pricing page refresh', 'post_status' => 'draft']);

        $this->note($post, $ines, 'Can we lead with the annual discount?');

        $n = Inbox::latest($maya)[0];
        $this->assertSame(Notes::ON_POST, $n->type);
        $this->assertSame('Inès Laurent left a note on “Pricing page refresh”', $n->title);
        $this->assertSame('Can we lead with the annual discount?', $n->body);
        $this->assertStringContainsString('post.php?post='.$post.'&action=edit', $n->url);
        $this->assertSame($ines, $n->actorId);
        $this->assertSame(0, Inbox::unreadCount($ines), 'Nobody hears about their own note');
    }

    public function test_mentions_replies_and_threads_each_tell_people_once(): void
    {
        if (! function_exists('wp_get_note_mentioned_user_ids')) {
            $this->markTestSkipped('Note mentions arrived in WordPress 7.1.');
        }
        $maya = $this->user('editor', 'Maya Okafor');
        $tom = $this->user('editor', 'Tom Reyes');
        $kenji = $this->user('editor', 'Kenji Mori');
        $ines = $this->user('editor', 'Inès Laurent');
        $post = self::factory()->post->create(['post_author' => $ines, 'post_status' => 'draft', 'post_title' => 'Webhook retries guide']);

        $root = $this->note($post, $kenji, 'Is three days of retries enough?');
        $this->note($post, $maya, 'Three days is what most do.', $root);
        $this->note($post, $tom, 'Agreed. <span class="wp-note-mention user-'.$maya.'">@Maya Okafor</span> can you add the table?', $root);

        // Maya: the mention wins over "a thread you're in".
        $this->assertSame([Notes::MENTION => 1], $this->types($maya));
        $this->assertSame('Tom Reyes mentioned you in a note on “Webhook retries guide”', Inbox::latest($maya)[0]->title);
        // Kenji wrote the first note: both replies are replies to his note.
        $this->assertSame([Notes::REPLY => 2], $this->types($kenji));
        // Inès wrote the post.
        $this->assertSame([Notes::ON_POST => 3], $this->types($ines));
        // Tom took part only by writing the last note.
        $this->assertSame([], $this->types($tom));
    }

    public function test_thread_participants_hear_about_new_replies(): void
    {
        $maya = $this->user('editor');
        $tom = $this->user('editor');
        $kenji = $this->user('editor');
        $post = self::factory()->post->create(['post_author' => $this->user('editor'), 'post_status' => 'draft']);

        $root = $this->note($post, $kenji, 'First');
        $this->note($post, $maya, 'Second', $root);
        $this->note($post, $tom, 'Third', $root);

        $this->assertSame([Notes::THREAD => 1], $this->types($maya));
    }

    public function test_people_who_cannot_see_the_note_are_not_told(): void
    {
        if (! function_exists('wp_get_note_mentioned_user_ids')) {
            $this->markTestSkipped('Note mentions arrived in WordPress 7.1.');
        }
        $subscriber = $this->user('subscriber');
        $post = self::factory()->post->create(['post_author' => $this->user('editor'), 'post_status' => 'draft']);

        $this->note($post, $this->user('editor'), '<span class="wp-note-mention user-'.$subscriber.'">@Sam</span> look');

        $this->assertSame(0, Inbox::unreadCount($subscriber));
    }

    public function test_mentions_are_found_like_core_finds_them(): void
    {
        $this->assertSame([12, 7], Notes::mentioned('<span class="wp-note-mention user-12">@A</span> and <span class="x wp-note-mention user-7">@B</span> <span class="user-9">no</span>'));
        $this->assertSame([], Notes::mentioned('plain text'));
    }

    public function test_core_note_emails_follow_each_persons_switch(): void
    {
        $author = $this->user('editor');
        $note = get_comment($this->note(self::factory()->post->create(['post_author' => $author, 'post_status' => 'draft']), $this->user('editor'), 'Hi'));

        $this->assertTrue(Notes::postAuthorEmail(true, $note->comment_ID));
        Inbox::updatePreferences($author, [Notes::ON_POST => ['email' => false]]);
        $this->assertFalse(Notes::postAuthorEmail(true, $note->comment_ID));
        $this->assertFalse(Notes::postAuthorEmail(false, $note->comment_ID), 'Never turns an email on');
        $this->assertFalse(apply_filters('notify_post_author', true, $note->comment_ID), 'Hooked into core');
    }

    public function test_core_mention_emails_are_taken_over_and_respect_the_switch(): void
    {
        if (! function_exists('wp_send_note_notification')) {
            $this->markTestSkipped('Note mentions arrived in WordPress 7.1.');
        }
        $this->assertFalse(has_action('rest_insert_comment', 'wp_notify_note_mentions'));
        $this->assertSame(10, has_action('rest_insert_comment', [Notes::class, 'mentionEmails']));

        $maya = $this->user('editor');
        $tom = $this->user('editor');
        $post = self::factory()->post->create(['post_author' => $this->user('editor'), 'post_status' => 'draft']);
        $content = '<span class="wp-note-mention user-'.$maya.'">@Maya</span> <span class="wp-note-mention user-'.$tom.'">@Tom</span> please look';
        $note = get_comment($this->note($post, $this->user('editor'), $content));
        Inbox::updatePreferences($tom, [Notes::MENTION => ['email' => false]]);
        reset_phpmailer_instance();

        Notes::mentionEmails($note, null, true);

        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertSame(get_userdata($maya)->user_email, $mails[0]['to']);
    }

    public function test_turning_notes_off_gives_core_its_emails_back(): void
    {
        $this->settings(['sources' => ['notes' => false, 'comments' => true, 'posts' => true, 'feedback' => true]]);
        Sources::reload();

        $this->assertFalse(Types::exists(Notes::ON_POST));
        $this->assertFalse(has_action('wp_insert_comment', [Notes::class, 'inserted']));
        if (function_exists('wp_notify_note_mentions')) {
            $this->assertSame(10, has_action('rest_insert_comment', 'wp_notify_note_mentions'));
        }
        $author = $this->user('editor');
        $this->note(self::factory()->post->create(['post_author' => $author, 'post_status' => 'draft']), $this->user('editor'), 'Hi');
        $this->assertSame(0, Inbox::unreadCount($author));
    }

    // Comments -------------------------------------------------------------

    public function test_a_reply_to_your_comment_and_a_comment_on_your_post(): void
    {
        $maya = $this->user('author', 'Maya Okafor');
        $kenji = $this->user('subscriber', 'Kenji Mori');
        $priya = $this->user('subscriber', 'Priya Shah');
        $post = self::factory()->post->create(['post_author' => $maya, 'post_title' => 'How Bluefin cut failed payments']);

        $first = self::factory()->comment->create(['comment_post_ID' => $post, 'user_id' => $kenji, 'comment_author' => 'Kenji Mori', 'comment_content' => 'Which retry schedule?']);
        self::factory()->comment->create(['comment_post_ID' => $post, 'user_id' => $priya, 'comment_author' => 'Priya Shah', 'comment_parent' => $first, 'comment_content' => '3, 7 and 14 days.']);

        $reply = Inbox::latest($kenji)[0];
        $this->assertSame('comment_reply', $reply->type);
        $this->assertSame('Priya Shah replied to your comment on “How Bluefin cut failed payments”', $reply->title);
        $this->assertStringContainsString('#comment-', $reply->url);
        $this->assertSame(['comment_on_post' => 2], $this->types($maya));
        $this->assertSame(0, Inbox::unreadCount($priya));
    }

    public function test_comments_count_once_approved_and_only_once(): void
    {
        $maya = $this->user('author');
        $post = self::factory()->post->create(['post_author' => $maya]);
        $id = self::factory()->comment->create(['comment_post_ID' => $post, 'comment_approved' => '0', 'comment_author' => 'Spammy']);
        $this->assertSame(0, Inbox::unreadCount($maya), 'Held comments stay quiet');

        wp_set_comment_status($id, 'approve');
        wp_set_comment_status($id, 'hold');
        wp_set_comment_status($id, 'approve');

        $this->assertSame(1, Inbox::unreadCount($maya));
    }

    public function test_pingbacks_and_your_own_comments_are_ignored(): void
    {
        $maya = $this->user('author');
        $post = self::factory()->post->create(['post_author' => $maya]);
        self::factory()->comment->create(['comment_post_ID' => $post, 'comment_type' => 'pingback']);
        self::factory()->comment->create(['comment_post_ID' => $post, 'user_id' => $maya]);

        $this->assertSame(0, Inbox::unreadCount($maya));
    }

    public function test_core_comment_emails_follow_the_switch(): void
    {
        $maya = $this->user('author');
        $id = self::factory()->comment->create(['comment_post_ID' => self::factory()->post->create(['post_author' => $maya])]);
        Inbox::updatePreferences($maya, ['comment_on_post' => ['email' => false]]);

        $this->assertFalse(apply_filters('notify_post_author', true, $id));
    }

    // Posts ----------------------------------------------------------------

    public function test_authors_hear_when_someone_else_publishes_or_schedules(): void
    {
        $kenji = $this->user('contributor', 'Kenji Mori');
        $editor = $this->user('editor', 'Inès Laurent');
        wp_set_current_user($editor);
        $post = self::factory()->post->create(['post_author' => $kenji, 'post_status' => 'pending', 'post_title' => 'Webhook retries guide']);

        wp_update_post(['ID' => $post, 'post_status' => 'future', 'edit_date' => true, 'post_date' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS), 'post_date_gmt' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS)]);
        $scheduled = Inbox::latest($kenji)[0];
        $this->assertSame('post_scheduled', $scheduled->type);
        $this->assertStringStartsWith('“Webhook retries guide” is scheduled for', $scheduled->title);

        wp_set_current_user(0); // WP-Cron publishing it.
        wp_publish_post($post);
        $published = Inbox::latest($kenji)[0];
        $this->assertSame('post_published', $published->type);
        $this->assertSame('It went live on schedule.', $published->body);
    }

    public function test_publishing_your_own_post_is_not_news(): void
    {
        $maya = $this->user('author');
        wp_set_current_user($maya);
        self::factory()->post->create(['post_author' => $maya, 'post_status' => 'publish']);

        $this->assertSame(0, Inbox::unreadCount($maya));
    }

    // Ruvelo Feedback ------------------------------------------------------

    public function test_feedback_is_off_until_ruvelo_feedback_is_active(): void
    {
        $this->assertFalse(Feedback::available());
        $this->assertFalse(has_action('ruvelo_feedback_notification', [Feedback::class, 'received']));

        // Firing its action without the plugin breaks nothing and stores nothing.
        do_action('ruvelo_feedback_notification', ['user_id' => $this->user()], ['title' => 'Shipped']);
        $this->assertSame(0, Inbox::query($this->user())['total']);
    }

    public function test_feedback_updates_reach_signed_in_voters(): void
    {
        Feedback::types();
        Feedback::hooks();
        $voter = $this->user();

        // Exactly what Ruvelo Feedback 1.0 sends (src/Notifications.php).
        do_action('ruvelo_feedback_notification',
            ['user_id' => $voter, 'email' => 'voter@halyard.test', 'name' => 'Kenji', 'role' => 'voter'],
            ['subject' => '[Halyard] Shipped: Dark mode', 'title' => 'Shipped: Dark mode', 'body' => 'Something you asked for is live.', 'url' => 'https://halyard.test/feedback/changelog/dark-mode/', 'request_id' => 42, 'status' => 'shipped', 'event' => 'shipped', 'unsubscribe' => 'https://halyard.test/?x']
        );
        do_action('ruvelo_feedback_notification', ['user_id' => 0, 'email' => 'guest@halyard.test', 'name' => '', 'role' => 'voter'], ['title' => 'Shipped: Dark mode']);

        $n = Inbox::latest($voter)[0];
        $this->assertSame(Feedback::UPDATE, $n->type);
        $this->assertSame('Shipped: Dark mode', $n->title);
        $this->assertSame('✓', $n->icon);
        $this->assertSame(['request_id' => 42, 'status' => 'shipped', 'event' => 'shipped', 'role' => 'voter'], $n->data);
        $this->assertSame(0, Mailer::pending(), 'Ruvelo Feedback sends its own email');

        Feedback::unhook();
    }

    public function test_feedback_emails_follow_the_switch_for_signed_in_people_only(): void
    {
        Feedback::types();
        Feedback::hooks();
        $voter = $this->user();
        Inbox::updatePreferences($voter, [Feedback::UPDATE => ['email' => false]]);

        $this->assertFalse(apply_filters('ruvelo_feedback_send_email', true, ['user_id' => $voter], []));
        $this->assertTrue(apply_filters('ruvelo_feedback_send_email', true, ['user_id' => 0, 'email' => 'guest@halyard.test'], []));
        $this->assertFalse(apply_filters('ruvelo_feedback_send_email', false, ['user_id' => 0], []), 'Never turns an email on');

        Feedback::unhook();
    }
}

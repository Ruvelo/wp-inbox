=== Ruvelo Inbox ===
Contributors: francoisbultez
Tags: notifications, inbox, notification center, bell, notes
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The notification centre WordPress never shipped: a bell, an inbox and per-person preferences, plus an API other plugins send to.

== Description ==

WordPress tells people things by email, if at all. Someone mentions you in a note, replies to your comment or publishes your draft, and you find out from your mail, or not. Ruvelo Inbox gives every signed-in person a bell with an unread count, an inbox, and a say in what reaches them and how. Other plugins can send to it with one line, and nothing breaks if it isn't installed.

**A bell in the toolbar.** In the admin and on the site, next to "Howdy". It shows how many notifications are unread and opens on the latest. Opening one marks it read and takes you straight to it. The count stays live through the WordPress Heartbeat the admin already runs, so there's no extra polling.

**An inbox for everything else.** All and Unread, grouped by day, filtered by type, with mark read, mark unread and delete on each item and in bulk. Every button is a plain form, so it all works without JavaScript.

**Preferences people set themselves.** One switch per kind of notification and channel: in the inbox, by email, both or neither. Emails wait a few minutes so a busy thread becomes one email, and each one says why it came and links to stop that kind.

**Useful from day one.** It collects what WordPress already produces and had nowhere to put:

* **Notes in the editor** (WordPress 6.9+): mentions (7.1), replies to your notes, notes on your posts, and replies in threads you're in. WordPress emails the post author and the people mentioned; each person can now switch those emails off and keep the inbox.
* **Comments:** "someone replied to your comment", which WordPress never sends, and "new comment on your post", with the same per-person switch for core's email.
* **Posts:** when your scheduled post goes live, or someone else publishes or schedules your post.
* **Ruvelo Feedback:** when a request you posted or voted for is planned, started or shipped.

Each source can be turned off in Settings.

**For membership sites.** The Inbox block puts the inbox and preferences on any page, and the Notification Bell block goes in your theme's header. Point Settings → Front-end inbox page at it and emails and the bell link there, so members never see wp-admin.

**For developers.** `Ruvelo\WP\Inbox\Inbox::notify( $user_ids, [ 'title' => …, 'url' => … ] )`, or `do_action( 'ruvelo_inbox_notify', $user_ids, $message )` with no dependency at all. Register your types with a label, a description and default channels, and the preferences screen lists them. Plus a REST API (`ruvelo-inbox/v1`), WP-CLI (`wp ruvelo-inbox send|list|prune|types`), Abilities API registrations for AI agents, actions and filters around everything, and test helpers for your own plugin's tests.

= Our promises =

* **No nags, no upsells, no "pro" version.** No admin notices, no review requests, no ads, no redirect after activation. It's complete and free, and it will stay that way.
* **No tracking and no remote requests.** The plugin never phones home and loads nothing from anywhere else: no fonts, scripts, images or avatars from a service. The only emails it sends are the ones each person asks for.
* **Never used to promote anything.** The inbox carries what your site and its plugins send. Ruvelo will never put its own messages in it.
* **Updates come only from WordPress.org and Composer**, through WordPress's own updater. Ruvelo will never sell this plugin, its WordPress.org account or its users.
* **Your data stays yours.** Deleting the plugin keeps everyone's notifications unless you tick "Also delete every notification, preference and setting". Scheduled jobs are always removed.
* **Accessible and light.** Labels, focus states, keyboard support and live announcements for new notifications, aiming at WCAG 2.2 AA. Works without JavaScript, respects reduced motion, and follows each person's light or dark setting.

Ruvelo Inbox is built by [Valtry](https://valtry.co.uk/?utm_source=ruvelo&utm_medium=wordpress&utm_campaign=wp-inbox), the website security scanner, as part of Ruvelo, its free and open-source extensions for WordPress and Laravel. The code, issues and roadmap are on [GitHub](https://github.com/Ruvelo/wp-inbox).

== Installation ==

1. Install and activate the plugin from Plugins → Add New, or with `wp plugin install ruvelo-inbox --activate`.
2. That's it: the bell is in the toolbar, and notes, comments and posts start arriving. Choose what you hear about in Inbox → Preferences.
3. Optional: Inbox → Settings to turn sources on or off, change how long notifications are kept, or pick a front-end inbox page (a page with the Inbox block).

With Composer: `composer require ruvelo/wp-inbox`.

== Frequently Asked Questions ==

= How does another plugin send a notification? =

Without depending on Ruvelo Inbox:

`do_action( 'ruvelo_inbox_notify', $user_ids, [ 'type' => 'invoice_paid', 'title' => 'Invoice INV-2288 paid', 'body' => 'Bluefin Studio paid €4,800.00.', 'url' => $url ] );`

If the plugin isn't active, nothing happens. Register the type on `init` so people can switch it: `do_action( 'ruvelo_inbox_register_type', 'invoice_paid', [ 'label' => 'Invoice paid', 'group' => 'Billing', 'defaults' => [ 'email' => true ] ] );`. The developer guide on GitHub lists every hook, REST route and command.

= Does it send email right away? =

No. Emails wait a few minutes (five by default) and go out through WP-Cron in batches, one email per person with everything that arrived meanwhile. On a quiet site, set up a real cron job for WP-Cron, or run `wp ruvelo-inbox emails run`.

= WordPress already emails me about notes and comments. Will I get two emails? =

No. Ruvelo Inbox doesn't send those itself. It adds them to the inbox and lets each person switch WordPress's own email for them off (or keep it). The same goes for Ruvelo Feedback's emails.

= What does "Note threads you're in" mean? =

WordPress doesn't store who follows a note thread, so taking part in one (writing its first note or a reply) is what follows it. You hear about new replies there, unless you switch it off.

= Who can see what? =

Everyone sees only their own notifications, everywhere: the inbox, the REST API, the abilities. Someone else's notification is a 404. Sending to other people (REST, abilities, the settings screen) needs the `ruvelo_send_notifications` capability, and changing the settings needs `ruvelo_manage_inbox`; administrators have both.

= How long are notifications kept? =

90 days by default, and unread ones are kept however old. A daily WP-Cron job deletes the rest. Change both in Settings, or run `wp ruvelo-inbox prune`.

= Will it slow my site down? =

The unread count is one indexed query, cached until something changes. Live updates ride on the Heartbeat the admin already runs. Notifications live in their own table with indexes on (user, read) and date, not in post meta.

= What about privacy? =

Each notification records what happened, a link, who did it and when, for the person it's for. It adds a paragraph to your privacy policy guide and works with Tools → Export Personal Data and Erase Personal Data. Deleting a user deletes their notifications. Nothing leaves your site except the emails people ask for.

= Is it accessible? =

That's the aim: WCAG 2.2 AA. The bell is a real button with the unread count in its label, the dropdown closes with Escape and returns focus, new notifications are announced to screen readers, the switches are labelled checkboxes, and everything works with a keyboard and without JavaScript. If you find a barrier, please open an issue.

== Screenshots ==

1. The bell in the toolbar: the unread count and the latest notifications.
2. The inbox: All and Unread, grouped by day, a type filter and bulk actions.
3. Preferences: a switch for each kind of notification, in the inbox and by email.
4. The Inbox block on the site, for members who never visit the admin.
5. In dark mode.
6. On a phone.
7. Settings: sources, email batching, the bell, cleanup.

== Changelog ==

= 1.0.0 - 2026-10-09 =

* First release: the bell, the inbox, preferences, email batching, Notes, comments, posts and Ruvelo Feedback sources, blocks, REST API, WP-CLI and abilities.

== Upgrade Notice ==

= 1.0.0 =

First release.

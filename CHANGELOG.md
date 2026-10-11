# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-10-09

First release.

### Added

- A developer API: `Ruvelo\WP\Inbox\Inbox::notify()` and, without a hard dependency, the `ruvelo_inbox_notify` action; notification types registered with labels, descriptions, groups and default channels (`Inbox::registerType()` or the `ruvelo_inbox_register_type` action).
- Notifications in their own table, indexed on `(user_id, read_at)`, `(user_id, created_at)` and `created_at`, with a daily cleanup (90 days by default, sparing unread ones).
- Per-person preferences per type and channel (inbox, email), respected by `notify()`.
- Email batched through WP-Cron: one email per person with everything that arrived during a short delay, with signed per-type unsubscribe links that ask before acting.
- Sources, each a set of types and each toggleable: core Notes (mentions on 7.1, replies, notes on your posts, threads you're in), with core's note emails following each person's switches; replies to comments and comments on your posts; posts going live or scheduled by someone else; Ruvelo Feedback status changes through `ruvelo_feedback_notification`.
- A bell in the toolbar (admin and front end) with an unread badge, the latest items, mark all as read and live counts over the Heartbeat API.
- An Inbox screen (All and Unread, grouped by day, type filter, bulk actions) and a Preferences screen, both working without JavaScript; light and dark.
- Blocks: Inbox (with a Preferences tab) and Notification Bell, with `[ruvelo_inbox]` and `[ruvelo_inbox_bell]` shortcodes, and an optional front-end inbox page for emails and the bell to link to.
- REST API (`ruvelo-inbox/v1`), WP-CLI (`wp ruvelo-inbox send|list|prune|types|emails|demo`), Abilities API registrations, actions and filters.
- Test helpers for other plugins: `Testing\Fake` and `Testing\Factory`.
- Personal data export and erasure, cleanup when a user is deleted, and an uninstall that deletes data only when asked.
- Halyard's demo (`wp ruvelo-inbox demo`) and a WordPress Playground blueprint.

[Unreleased]: https://github.com/Ruvelo/wp-inbox/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Ruvelo/wp-inbox/releases/tag/v1.0.0

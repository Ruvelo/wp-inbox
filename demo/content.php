<?php

/**
 * Halyard's demo: a B2B billing platform's team site. Maya Okafor (user 1)
 * writes the blog and case studies with Tom Reyes, Inès Laurent and Kenji
 * Mori, so her inbox fills with what a team site produces: mentions in
 * notes, replies, comments, posts going live and feature requests shipping.
 *
 * Used by `wp ruvelo-inbox demo` (src/Demo.php). Times are "minutes ago".
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

return [
    'people' => [
        'tom.reyes' => ['Tom', 'Reyes', 'editor'],
        'ines.laurent' => ['Inès', 'Laurent', 'editor'],
        'kenji.mori' => ['Kenji', 'Mori', 'author'],
    ],

    'posts' => [
        'release-notes' => ['title' => 'October release notes', 'status' => 'draft', 'author' => 'maya', 'content' => 'Usage-based billing is out of beta, invoices can carry two tax IDs, and failed payments retry on a smarter schedule.'],
        'pricing' => ['title' => 'Pricing page refresh', 'status' => 'pending', 'author' => 'maya', 'content' => 'Three plans, monthly or annual, with usage pricing for API calls above the included volume.'],
        'bluefin' => ['title' => 'How Bluefin Studio cut failed payments by 40%', 'status' => 'publish', 'author' => 'maya', 'content' => 'Bluefin Studio bills 1,200 clients a month. Here is how smart retries and a friendlier reminder sequence won back 40% of the payments they used to lose.'],
        'usage' => ['title' => 'Usage-based billing, explained', 'status' => 'publish', 'author' => 'maya', 'content' => 'Meter what your customers use, set a price per unit, and Halyard adds it to the next invoice.'],
        'dunning' => ['title' => 'Q4 dunning email copy', 'status' => 'draft', 'author' => 'ines', 'content' => 'Reminder one: a friendly heads-up. Reminder two: the card update link, front and centre. Reminder three: the pause.'],
        'webhooks' => ['title' => 'Webhook retries guide', 'status' => 'draft', 'author' => 'kenji', 'content' => 'Halyard retries failed webhook deliveries with exponential backoff for up to three days.'],
        'templates' => ['title' => 'Invoice templates refresh', 'status' => 'draft', 'author' => 'tom', 'content' => 'New invoice templates with room for a purchase order number and two tax IDs.'],
        'security' => ['title' => 'Security update: SSO for every plan', 'status' => 'publish', 'author' => 'ines', 'content' => 'Single sign-on with Google Workspace and Microsoft Entra is now on every plan, including Starter.'],
    ],

    // [minutes ago, type, from, post key, title, body, read?, extra]
    'inbox' => [
        [6, 'note_mention', 'tom', 'release-notes', null, '@Maya can you confirm the dunning numbers before this goes out? I used 41% from the Bluefin case study.', false],
        [24, 'feedback_update', null, null, 'Shipped: Usage-based billing', 'Something you asked for is live. Read about it in “Usage-based billing is here”.', false, ['url' => '/feedback/changelog/usage-based-billing-is-here/', 'icon' => '✓']],
        [58, 'comment_reply', 'kenji', 'bluefin', null, 'Good catch, I’ve fixed the retry schedule in the post. Bluefin used 3, 7 and 14 days, not 3, 5 and 7.', false],
        [125, 'note_on_post', 'ines', 'pricing', null, 'Can we lead with the annual discount? Two of last week’s demos asked about it first.', false],
        [190, 'post_published', null, 'usage', null, 'It went live on schedule.', false],
        [310, 'note_reply', 'tom', 'dunning', null, 'Agreed, the second reminder reads better without the late-fee line. I’ve cut it.', true],
        [1530, 'general', 'ines', null, 'Halyard 4.2 ships on Thursday', 'Code freeze starts Wednesday at 18:00 UTC. Post blockers in #release before then.', true, ['icon' => '!']],
        [1710, 'comment_on_post', 'Priya Shah', 'bluefin', null, 'We’re seeing the same pattern at our agency: most failed cards are expired, not declined. Did Bluefin email before the first retry?', true],
        [2950, 'feedback_update', null, null, 'Planned: Two-way Xero sync', 'It’s on the roadmap. We’ll let you know when work starts.', true, ['url' => '/feedback/requests/two-way-xero-sync/', 'icon' => '↗']],
        [3240, 'note_thread', 'kenji', 'webhooks', null, 'I’ll add a table of the retry intervals under the backoff paragraph.', true],
        [4400, 'post_scheduled', 'tom', 'usage', null, 'Tom Reyes scheduled it.', true],
        [5800, 'note_mention', 'ines', 'templates', null, '@Maya does the PO number need to be on credit notes too?', true],
        [8700, 'feedback_update', null, null, 'In progress: Dark mode', 'Work has started on something you asked for.', true, ['url' => '/feedback/requests/dark-mode/', 'icon' => '↗']],
        [12900, 'comment_reply', 'tom', 'security', null, 'Yes, Starter gets SSO from today. The pricing table updates on Monday.', true],
        [17300, 'general', null, null, 'Welcome to your inbox', 'Mentions, replies and updates from across Halyard land here. Choose what you hear about, and how, in Preferences.', true, ['icon' => '•']],
    ],
];

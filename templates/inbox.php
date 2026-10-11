<?php
/**
 * The inbox: All and Unread, a type filter, notifications grouped by day,
 * bulk actions and pages. Every control is a link or a form.
 *
 * @var array{context: string, base: string, status: string, type: string, page: int, pages: int, total: int, days: array<string, list<Notification>>, types: array<string, string>, unread: int, notice: string, show_heading: bool, show_tabs: bool, preferences_url: string} $view
 */

declare(strict_types=1);

use Ruvelo\WP\Inbox\Notification;
use Ruvelo\WP\Inbox\Settings;
use Ruvelo\WP\Inbox\Views;

defined('ABSPATH') || exit;

$ruvelo_base = $view['base'];
$ruvelo_all = remove_query_arg(['inbox_status'], $ruvelo_base);
$ruvelo_unread_url = add_query_arg('inbox_status', 'unread', $ruvelo_base);
$ruvelo_bulk = 'ruvelo-inbox-bulk-'.wp_unique_id();
$ruvelo_filter = 'ruvelo-inbox-type-'.wp_unique_id();
$ruvelo_query = [];
wp_parse_str((string) wp_parse_url($ruvelo_base, PHP_URL_QUERY), $ruvelo_query);
unset($ruvelo_query['inbox_type'], $ruvelo_query['inbox_page']);
$ruvelo_action = strtok($ruvelo_base, '?');
?>
<div class="ruvelo-inbox ruvelo-inbox--<?php echo esc_attr($view['context']); ?>" data-ruvelo-inbox-page data-theme="<?php echo esc_attr(Settings::scheme()); ?>">
    <?php if ($view['show_tabs']) {
        echo Views::render('parts/tabs', ['current' => 'inbox', 'inbox_url' => remove_query_arg(['inbox_view', 'inbox_status', 'inbox_type'], $ruvelo_base), 'preferences_url' => $view['preferences_url']]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.
    } ?>
    <div class="ruvelo-inbox-head">
        <div>
            <?php if ($view['show_heading']) { ?>
                <?php $ruvelo_tag = $view['context'] === 'admin' ? 'h1' : 'h2'; ?>
                <<?php echo esc_attr($ruvelo_tag); ?> class="ruvelo-inbox-h1"><?php esc_html_e('Notifications', 'ruvelo-inbox'); ?></<?php echo esc_attr($ruvelo_tag); ?>>
            <?php } ?>
            <p class="ruvelo-inbox-sub" data-ruvelo-inbox-summary>
                <?php if ($view['unread'] > 0) {
                    /* translators: %s: "5 unread", emphasised */
                    printf(esc_html__('You have %s.', 'ruvelo-inbox'), '<strong>'.esc_html(sprintf(
                        /* translators: %d: number of unread notifications */
                        _n('%d unread', '%d unread', $view['unread'], 'ruvelo-inbox'),
                        $view['unread']
                    )).'</strong>');
                } else {
                    esc_html_e('You’re all caught up.', 'ruvelo-inbox');
                } ?>
            </p>
        </div>
        <div class="ruvelo-inbox-actions">
            <form method="post" action="<?php echo esc_url($ruvelo_base); ?>">
                <?php echo Views::fields('read_all', $ruvelo_base); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Views::fields().?>
                <button type="submit" class="ruvelo-inbox-btn ruvelo-inbox-btn--quiet"<?php echo $view['unread'] === 0 ? ' disabled' : ''; ?>><?php echo Views::icon('check-all'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.?><?php esc_html_e('Mark all as read', 'ruvelo-inbox'); ?></button>
            </form>
            <?php if (! $view['show_tabs']) { ?>
                <a class="ruvelo-inbox-btn ruvelo-inbox-btn--quiet" href="<?php echo esc_url($view['preferences_url']); ?>"><?php echo Views::icon('sliders'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.?><?php esc_html_e('Preferences', 'ruvelo-inbox'); ?></a>
            <?php } ?>
        </div>
    </div>

    <?php if ($view['notice'] !== '') { ?>
        <p class="ruvelo-inbox-notice" role="status"><?php echo esc_html($view['notice']); ?></p>
    <?php } ?>

    <div class="ruvelo-inbox-toolbar">
        <nav class="ruvelo-inbox-tabs" aria-label="<?php esc_attr_e('Show', 'ruvelo-inbox'); ?>">
            <a href="<?php echo esc_url($ruvelo_all); ?>"<?php echo $view['status'] === 'all' ? ' aria-current="page"' : ''; ?>><?php esc_html_e('All', 'ruvelo-inbox'); ?></a>
            <a href="<?php echo esc_url($ruvelo_unread_url); ?>"<?php echo $view['status'] === 'unread' ? ' aria-current="page"' : ''; ?>><?php esc_html_e('Unread', 'ruvelo-inbox'); ?><?php if ($view['unread'] > 0) { ?> <span class="ruvelo-inbox-count" data-ruvelo-inbox-count><?php echo esc_html(Views::badge($view['unread'])); ?></span><?php } ?></a>
        </nav>
        <?php if (count($view['types']) > 1 || $view['type'] !== '') { ?>
            <form method="get" action="<?php echo esc_url((string) $ruvelo_action); ?>" class="ruvelo-inbox-filter" data-ruvelo-inbox-filter>
                <?php foreach ($ruvelo_query as $ruvelo_key => $ruvelo_value) {
                    if (is_string($ruvelo_value)) { ?>
                        <input type="hidden" name="<?php echo esc_attr((string) $ruvelo_key); ?>" value="<?php echo esc_attr($ruvelo_value); ?>">
                    <?php }
                    } ?>
                <label class="ruvelo-inbox-sr" for="<?php echo esc_attr($ruvelo_filter); ?>"><?php esc_html_e('Type', 'ruvelo-inbox'); ?></label>
                <select id="<?php echo esc_attr($ruvelo_filter); ?>" name="inbox_type">
                    <option value=""><?php esc_html_e('All types', 'ruvelo-inbox'); ?></option>
                    <?php foreach ($view['types'] as $ruvelo_type => $ruvelo_label) { ?>
                        <option value="<?php echo esc_attr($ruvelo_type); ?>"<?php selected($view['type'], $ruvelo_type); ?>><?php echo esc_html($ruvelo_label); ?></option>
                    <?php } ?>
                </select>
                <button type="submit" class="ruvelo-inbox-btn ruvelo-inbox-btn--quiet" data-ruvelo-inbox-filter-submit><?php esc_html_e('Filter', 'ruvelo-inbox'); ?></button>
            </form>
        <?php } ?>
    </div>

    <?php if ($view['days'] === []) { ?>
        <div class="ruvelo-inbox-empty">
            <span class="ruvelo-inbox-empty-mark"><?php echo Views::icon('inbox'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.?></span>
            <?php if ($view['status'] === 'unread' || $view['type'] !== '') { ?>
                <h2><?php esc_html_e('Nothing here', 'ruvelo-inbox'); ?></h2>
                <p><?php esc_html_e('No notifications match. Everything else is under All.', 'ruvelo-inbox'); ?> <a href="<?php echo esc_url(remove_query_arg(['inbox_status', 'inbox_type'], $ruvelo_base)); ?>"><?php esc_html_e('Show all', 'ruvelo-inbox'); ?></a></p>
            <?php } else { ?>
                <h2><?php esc_html_e('Your inbox is empty', 'ruvelo-inbox'); ?></h2>
                <p><?php esc_html_e('When someone mentions you, replies to you or something you follow changes, it shows up here.', 'ruvelo-inbox'); ?></p>
            <?php } ?>
        </div>
    <?php } else { ?>
        <form method="post" action="<?php echo esc_url($ruvelo_base); ?>" id="<?php echo esc_attr($ruvelo_bulk); ?>" class="ruvelo-inbox-bulk" data-ruvelo-inbox-bulk>
            <?php echo Views::fields('bulk', $ruvelo_base); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Views::fields().?>
            <label class="ruvelo-inbox-check"><input type="checkbox" data-ruvelo-inbox-select-all hidden> <span data-ruvelo-inbox-select-all-label hidden><?php esc_html_e('Select all', 'ruvelo-inbox'); ?></span></label>
            <label class="ruvelo-inbox-sr" for="<?php echo esc_attr($ruvelo_bulk); ?>-action"><?php esc_html_e('Bulk action', 'ruvelo-inbox'); ?></label>
            <select id="<?php echo esc_attr($ruvelo_bulk); ?>-action" name="bulk_action">
                <option value=""><?php esc_html_e('With selected…', 'ruvelo-inbox'); ?></option>
                <option value="read"><?php esc_html_e('Mark as read', 'ruvelo-inbox'); ?></option>
                <option value="unread"><?php esc_html_e('Mark as unread', 'ruvelo-inbox'); ?></option>
                <option value="delete"><?php esc_html_e('Delete', 'ruvelo-inbox'); ?></option>
            </select>
            <button type="submit" class="ruvelo-inbox-btn ruvelo-inbox-btn--quiet"><?php esc_html_e('Apply', 'ruvelo-inbox'); ?></button>
        </form>
        <?php foreach ($view['days'] as $ruvelo_day => $ruvelo_items) { ?>
            <section class="ruvelo-inbox-day">
                <h2><?php echo esc_html($ruvelo_day); ?></h2>
                <ul class="ruvelo-inbox-list">
                    <?php foreach ($ruvelo_items as $ruvelo_item) {
                        echo Views::render('parts/item', ['item' => $ruvelo_item, 'base' => $ruvelo_base, 'bulk_form' => $ruvelo_bulk]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.
                    } ?>
                </ul>
            </section>
        <?php } ?>
        <?php if ($view['pages'] > 1) { ?>
            <nav class="ruvelo-inbox-pages" aria-label="<?php esc_attr_e('Pages', 'ruvelo-inbox'); ?>">
                <span><?php
                    /* translators: 1: current page, 2: number of pages */
                    echo esc_html(sprintf(__('Page %1$d of %2$d', 'ruvelo-inbox'), $view['page'], $view['pages'])); ?></span>
                <div>
                    <?php if ($view['page'] > 1) { ?>
                        <a class="ruvelo-inbox-btn ruvelo-inbox-btn--quiet" href="<?php echo esc_url(add_query_arg('inbox_page', $view['page'] - 1, $ruvelo_base)); ?>"><?php esc_html_e('Newer', 'ruvelo-inbox'); ?></a>
                    <?php } ?>
                    <?php if ($view['page'] < $view['pages']) { ?>
                        <a class="ruvelo-inbox-btn ruvelo-inbox-btn--quiet" href="<?php echo esc_url(add_query_arg('inbox_page', $view['page'] + 1, $ruvelo_base)); ?>"><?php esc_html_e('Older', 'ruvelo-inbox'); ?></a>
                    <?php } ?>
                </div>
            </nav>
        <?php } ?>
    <?php } ?>
</div>

<?php
/**
 * One notification in the inbox.
 *
 * @var array{item: Notification, base: string, bulk_form: string} $view
 */

declare(strict_types=1);

use Ruvelo\WP\Inbox\Notification;
use Ruvelo\WP\Inbox\Urls;
use Ruvelo\WP\Inbox\Views;

defined('ABSPATH') || exit;

$ruvelo_item = $view['item'];
$ruvelo_unread = $ruvelo_item->isUnread();
?>
<li class="ruvelo-inbox-item<?php echo $ruvelo_unread ? ' is-unread' : ''; ?>" data-ruvelo-inbox-row="<?php echo esc_attr((string) $ruvelo_item->id); ?>">
    <input type="checkbox" class="ruvelo-inbox-select" name="ids[]" value="<?php echo esc_attr((string) $ruvelo_item->id); ?>" form="<?php echo esc_attr($view['bulk_form']); ?>" aria-label="<?php
        /* translators: %s: notification title */
        echo esc_attr(sprintf(__('Select “%s”', 'ruvelo-inbox'), $ruvelo_item->title)); ?>">
    <span class="ruvelo-inbox-avatar<?php echo $ruvelo_item->hasActorAvatar() ? '' : ' ruvelo-inbox-avatar--icon'; ?>" aria-hidden="true"><?php echo esc_html($ruvelo_item->avatar()); ?></span>
    <div class="ruvelo-inbox-body">
        <a class="ruvelo-inbox-title" href="<?php echo esc_url(Urls::open($ruvelo_item, $view['base'])); ?>" data-ruvelo-inbox-item="<?php echo esc_attr((string) $ruvelo_item->id); ?>"<?php echo $ruvelo_item->url !== '' ? ' data-url="'.esc_url($ruvelo_item->url).'"' : ''; ?><?php echo $ruvelo_unread ? ' data-unread' : ''; ?>>
            <?php if ($ruvelo_unread) { ?><span class="ruvelo-inbox-sr"><?php esc_html_e('Unread:', 'ruvelo-inbox'); ?> </span><?php } ?>
            <?php echo esc_html($ruvelo_item->title); ?>
        </a>
        <?php if ($ruvelo_item->body !== '') { ?>
            <p class="ruvelo-inbox-text"><?php echo esc_html($ruvelo_item->excerpt(280)); ?></p>
        <?php } ?>
        <div class="ruvelo-inbox-meta">
            <?php if ($ruvelo_item->actorName !== '') { ?><span><?php echo esc_html($ruvelo_item->actorName); ?></span><?php } ?>
            <time datetime="<?php echo esc_attr(gmdate('c', $ruvelo_item->timestamp())); ?>" title="<?php echo esc_attr((string) wp_date((string) get_option('date_format').' '.(string) get_option('time_format'), $ruvelo_item->timestamp())); ?>"><?php echo esc_html($ruvelo_item->timeAgo()); ?></time>
            <span class="ruvelo-inbox-chip"><?php echo esc_html($ruvelo_item->typeLabel()); ?></span>
        </div>
    </div>
    <div class="ruvelo-inbox-tools">
        <form method="post" action="<?php echo esc_url($view['base']); ?>">
            <?php echo Views::fields($ruvelo_unread ? 'read' : 'unread', $view['base']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Views::fields().?>
            <input type="hidden" name="id" value="<?php echo esc_attr((string) $ruvelo_item->id); ?>">
            <?php $ruvelo_tip = $ruvelo_unread ? __('Mark as read', 'ruvelo-inbox') : __('Mark as unread', 'ruvelo-inbox'); ?>
            <button type="submit" class="ruvelo-inbox-icon" title="<?php echo esc_attr($ruvelo_tip); ?>" aria-label="<?php echo esc_attr($ruvelo_tip.': '.$ruvelo_item->title); ?>"><?php echo Views::icon($ruvelo_unread ? 'check' : 'dot'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.?></button>
        </form>
        <form method="post" action="<?php echo esc_url($view['base']); ?>">
            <?php echo Views::fields('delete', $view['base']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Views::fields().?>
            <input type="hidden" name="id" value="<?php echo esc_attr((string) $ruvelo_item->id); ?>">
            <button type="submit" class="ruvelo-inbox-icon ruvelo-inbox-icon--danger" title="<?php esc_attr_e('Delete', 'ruvelo-inbox'); ?>" aria-label="<?php echo esc_attr(__('Delete', 'ruvelo-inbox').': '.$ruvelo_item->title); ?>"><?php echo Views::icon('trash'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.?></button>
        </form>
    </div>
</li>

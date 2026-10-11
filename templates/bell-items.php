<?php
/**
 * The bell's list of the latest notifications.
 *
 * @var array{items: list<Notification>, base: string} $view
 */

declare(strict_types=1);

use Ruvelo\WP\Inbox\Notification;
use Ruvelo\WP\Inbox\Urls;

defined('ABSPATH') || exit;

if ($view['items'] === []) { ?>
    <li class="ruvelo-inbox-panel-empty">
        <strong><?php esc_html_e('You’re all caught up', 'ruvelo-inbox'); ?></strong>
        <span><?php esc_html_e('New notifications will show up here.', 'ruvelo-inbox'); ?></span>
    </li>
<?php }

foreach ($view['items'] as $ruvelo_item) {
    $ruvelo_excerpt = $ruvelo_item->excerpt(96);
    ?>
    <li>
        <a class="ruvelo-inbox-panel-item<?php echo $ruvelo_item->isUnread() ? ' is-unread' : ''; ?>" href="<?php echo esc_url(Urls::open($ruvelo_item, $view['base'])); ?>" data-ruvelo-inbox-item="<?php echo esc_attr((string) $ruvelo_item->id); ?>"<?php echo $ruvelo_item->url !== '' ? ' data-url="'.esc_url($ruvelo_item->url).'"' : ''; ?><?php echo $ruvelo_item->isUnread() ? ' data-unread' : ''; ?>>
            <span class="ruvelo-inbox-avatar<?php echo $ruvelo_item->hasActorAvatar() ? '' : ' ruvelo-inbox-avatar--icon'; ?>" aria-hidden="true"><?php echo esc_html($ruvelo_item->avatar()); ?></span>
            <span class="ruvelo-inbox-panel-text">
                <span class="ruvelo-inbox-panel-title"><?php echo esc_html($ruvelo_item->title); ?></span>
                <?php if ($ruvelo_excerpt !== '') { ?>
                    <span class="ruvelo-inbox-panel-body"><?php echo esc_html($ruvelo_excerpt); ?></span>
                <?php } ?>
                <time class="ruvelo-inbox-panel-time" datetime="<?php echo esc_attr(gmdate('c', $ruvelo_item->timestamp())); ?>"><?php echo esc_html($ruvelo_item->timeAgo()); ?></time>
            </span>
            <?php if ($ruvelo_item->isUnread()) { ?>
                <span class="ruvelo-inbox-dot"><span class="ruvelo-inbox-sr"><?php esc_html_e('Unread', 'ruvelo-inbox'); ?></span></span>
            <?php } ?>
        </a>
    </li>
<?php }

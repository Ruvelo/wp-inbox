<?php
/**
 * The bell: a link to the inbox that JavaScript turns into a dropdown.
 *
 * @var array{context: string, id: string, label: string, unread: int, latest: int, items: string, inbox_url: string, preferences_url: string, base: string, scheme: string} $view
 */

declare(strict_types=1);

use Ruvelo\WP\Inbox\Views;

defined('ABSPATH') || exit;

$ruvelo_unread = $view['unread'];
$ruvelo_label = $ruvelo_unread > 0
    /* translators: 1: "Notifications", 2: number unread */
    ? sprintf(_n('%1$s, %2$d unread', '%1$s, %2$d unread', $ruvelo_unread, 'ruvelo-inbox'), $view['label'], $ruvelo_unread)
    : $view['label'];
?>
<div class="ruvelo-inbox-bell ruvelo-inbox-bell--<?php echo esc_attr($view['context']); ?>" data-ruvelo-inbox-bell data-unread="<?php echo esc_attr((string) $ruvelo_unread); ?>" data-latest="<?php echo esc_attr((string) $view['latest']); ?>" data-label="<?php echo esc_attr($view['label']); ?>" data-theme="<?php echo esc_attr($view['scheme']); ?>">
    <a class="ruvelo-inbox-bell-button" href="<?php echo esc_url($view['inbox_url']); ?>" data-ruvelo-inbox-toggle aria-controls="<?php echo esc_attr($view['id']); ?>" aria-label="<?php echo esc_attr($ruvelo_label); ?>">
        <?php echo Views::icon('bell'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.?>
        <span class="ruvelo-inbox-badge" data-ruvelo-inbox-badge aria-hidden="true"<?php echo $ruvelo_unread === 0 ? ' hidden' : ''; ?>><?php echo esc_html(Views::badge($ruvelo_unread)); ?></span>
    </a>
    <div class="ruvelo-inbox-panel" id="<?php echo esc_attr($view['id']); ?>" data-ruvelo-inbox-panel role="region" aria-labelledby="<?php echo esc_attr($view['id']); ?>-title" tabindex="-1" hidden>
        <div class="ruvelo-inbox-panel-head">
            <h2 id="<?php echo esc_attr($view['id']); ?>-title"><?php echo esc_html($view['label']); ?></h2>
            <form method="post" action="<?php echo esc_url($view['base']); ?>" data-ruvelo-inbox-read-all>
                <?php echo Views::fields('read_all', $view['base']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Views::fields().?>
                <button type="submit" class="ruvelo-inbox-link"<?php echo $ruvelo_unread === 0 ? ' disabled' : ''; ?>><?php esc_html_e('Mark all as read', 'ruvelo-inbox'); ?></button>
            </form>
        </div>
        <ul class="ruvelo-inbox-panel-list" data-ruvelo-inbox-list>
            <?php echo $view['items']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered by templates/bell-items.php, escaped there.?>
        </ul>
        <div class="ruvelo-inbox-panel-foot">
            <a class="ruvelo-inbox-link" href="<?php echo esc_url($view['inbox_url']); ?>"><?php esc_html_e('View all', 'ruvelo-inbox'); ?></a>
            <a class="ruvelo-inbox-link ruvelo-inbox-link--quiet" href="<?php echo esc_url($view['preferences_url']); ?>"><?php esc_html_e('Preferences', 'ruvelo-inbox'); ?></a>
        </div>
    </div>
    <span class="ruvelo-inbox-sr" aria-live="polite" data-ruvelo-inbox-live></span>
</div>

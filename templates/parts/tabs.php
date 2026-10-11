<?php
/**
 * "Notifications | Preferences" links, on the front-end Inbox block.
 *
 * @var array{current: string, inbox_url: string, preferences_url: string} $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<nav class="ruvelo-inbox-views" aria-label="<?php esc_attr_e('Inbox', 'ruvelo-inbox'); ?>">
    <a href="<?php echo esc_url($view['inbox_url']); ?>"<?php echo $view['current'] === 'inbox' ? ' aria-current="page"' : ''; ?>><?php esc_html_e('Notifications', 'ruvelo-inbox'); ?></a>
    <a href="<?php echo esc_url($view['preferences_url']); ?>"<?php echo $view['current'] === 'preferences' ? ' aria-current="page"' : ''; ?>><?php esc_html_e('Preferences', 'ruvelo-inbox'); ?></a>
</nav>

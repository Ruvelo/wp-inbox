<?php
/**
 * Shown to visitors who aren't signed in.
 *
 * @var array{login: string} $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<div class="ruvelo-inbox ruvelo-inbox--signin">
    <p><?php esc_html_e('Your notifications live here once you’re signed in.', 'ruvelo-inbox'); ?> <a href="<?php echo esc_url($view['login']); ?>"><?php esc_html_e('Sign in', 'ruvelo-inbox'); ?></a></p>
</div>

<?php
/**
 * Preferences: a switch per notification type and channel.
 *
 * @var array{context: string, base: string, groups: array<string, array<string, array{label: string, description: string, group: string, channels: list<string>, defaults: array<string, bool>, required: bool, icon: string, sends_email: bool, source: string}>>, channels: array<string, string>, prefs: array<string, array<string, bool>>, notice: string, emails: bool, show_heading: bool, show_tabs: bool, inbox_url: string} $view
 */

declare(strict_types=1);

use Ruvelo\WP\Inbox\Actions;
use Ruvelo\WP\Inbox\Settings;
use Ruvelo\WP\Inbox\Views;

defined('ABSPATH') || exit;

$ruvelo_base = $view['base'];
?>
<div class="ruvelo-inbox ruvelo-inbox--<?php echo esc_attr($view['context']); ?>" data-ruvelo-inbox-page data-theme="<?php echo esc_attr(Settings::scheme()); ?>">
    <?php if ($view['show_tabs']) {
        echo Views::render('parts/tabs', ['current' => 'preferences', 'inbox_url' => $view['inbox_url'], 'preferences_url' => $ruvelo_base]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.
    } ?>
    <div class="ruvelo-inbox-head">
        <div>
            <?php if ($view['show_heading']) { ?>
                <?php $ruvelo_tag = $view['context'] === 'admin' ? 'h1' : 'h2'; ?>
                <<?php echo esc_attr($ruvelo_tag); ?> class="ruvelo-inbox-h1"><?php esc_html_e('Notification preferences', 'ruvelo-inbox'); ?></<?php echo esc_attr($ruvelo_tag); ?>>
            <?php } ?>
            <p class="ruvelo-inbox-sub"><?php esc_html_e('Choose how you hear about each kind of update. Changes apply to new notifications.', 'ruvelo-inbox'); ?></p>
        </div>
        <?php if (! $view['show_tabs']) { ?>
            <div class="ruvelo-inbox-actions">
                <a class="ruvelo-inbox-btn ruvelo-inbox-btn--quiet" href="<?php echo esc_url($view['inbox_url']); ?>"><?php echo Views::icon('inbox'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.?><?php esc_html_e('Back to notifications', 'ruvelo-inbox'); ?></a>
            </div>
        <?php } ?>
    </div>

    <?php if ($view['notice'] !== '') { ?>
        <p class="ruvelo-inbox-notice" role="status"><?php echo esc_html($view['notice']); ?></p>
    <?php } ?>

    <?php if ($view['groups'] === []) { ?>
        <div class="ruvelo-inbox-empty">
            <h2><?php esc_html_e('Nothing to choose yet', 'ruvelo-inbox'); ?></h2>
            <p><?php esc_html_e('When this site starts sending notifications, you can choose how you get each kind here.', 'ruvelo-inbox'); ?></p>
        </div>
    <?php } else { ?>
        <form method="post" action="<?php echo esc_url($ruvelo_base); ?>" class="ruvelo-inbox-prefs-form">
            <?php echo Views::fields('preferences', $ruvelo_base, Actions::PREFERENCES_NONCE); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Views::fields().?>
            <?php foreach ($view['groups'] as $ruvelo_group => $ruvelo_types) { ?>
                <section class="ruvelo-inbox-prefs-group">
                    <h2><?php echo esc_html($ruvelo_group); ?></h2>
                    <table class="ruvelo-inbox-prefs">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Notification', 'ruvelo-inbox'); ?></th>
                                <?php foreach ($view['channels'] as $ruvelo_channel_label) { ?>
                                    <th scope="col" class="ruvelo-inbox-prefs-channel"><?php echo esc_html($ruvelo_channel_label); ?></th>
                                <?php } ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ruvelo_types as $ruvelo_type => $ruvelo_args) {
                                $ruvelo_row = 'ruvelo-inbox-pref-'.sanitize_html_class($ruvelo_type); ?>
                                <tr>
                                    <th scope="row" id="<?php echo esc_attr($ruvelo_row); ?>">
                                        <span class="ruvelo-inbox-prefs-label"><?php echo esc_html($ruvelo_args['label']); ?>
                                            <?php if ($ruvelo_args['required']) { ?><span class="ruvelo-inbox-chip ruvelo-inbox-chip--accent"><?php esc_html_e('Always on', 'ruvelo-inbox'); ?></span><?php } ?>
                                        </span>
                                        <?php if ($ruvelo_args['description'] !== '') { ?>
                                            <span class="ruvelo-inbox-prefs-desc"><?php echo esc_html($ruvelo_args['description']); ?></span>
                                        <?php } ?>
                                    </th>
                                    <?php foreach ($view['channels'] as $ruvelo_channel => $ruvelo_channel_label) { ?>
                                        <td class="ruvelo-inbox-prefs-channel">
                                            <?php if (in_array($ruvelo_channel, $ruvelo_args['channels'], true)) {
                                                $ruvelo_on = $view['prefs'][$ruvelo_type][$ruvelo_channel] ?? false; ?>
                                                <span class="ruvelo-inbox-switch">
                                                    <input type="checkbox" role="switch" name="prefs[<?php echo esc_attr($ruvelo_type); ?>][<?php echo esc_attr($ruvelo_channel); ?>]" value="1"<?php checked($ruvelo_on); ?><?php disabled($ruvelo_args['required']); ?> aria-label="<?php echo esc_attr($ruvelo_args['label'].': '.$ruvelo_channel_label); ?>">
                                                </span>
                                            <?php } else { ?>
                                                <span class="ruvelo-inbox-none" title="<?php esc_attr_e('Not available for this notification', 'ruvelo-inbox'); ?>">–<span class="ruvelo-inbox-sr"><?php esc_html_e('Not available', 'ruvelo-inbox'); ?></span></span>
                                            <?php } ?>
                                        </td>
                                    <?php } ?>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </section>
            <?php } ?>
            <div class="ruvelo-inbox-prefs-save">
                <button type="submit" class="ruvelo-inbox-btn"><?php esc_html_e('Save preferences', 'ruvelo-inbox'); ?></button>
                <p><?php if ($view['emails']) {
                    esc_html_e('Emails wait a few minutes, so several updates arrive as one. Each email has a link to stop that kind.', 'ruvelo-inbox');
                } else {
                    esc_html_e('Some notification emails are paused on this site; your choices are kept for when they’re back.', 'ruvelo-inbox');
                } ?></p>
            </div>
        </form>
    <?php } ?>
</div>

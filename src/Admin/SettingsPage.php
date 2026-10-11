<?php

declare(strict_types=1);

namespace Ruvelo\WP\Inbox\Admin;

use Ruvelo\WP\Inbox\Actions;
use Ruvelo\WP\Inbox\Install;
use Ruvelo\WP\Inbox\Mailer;
use Ruvelo\WP\Inbox\Settings;
use Ruvelo\WP\Inbox\Sources\Sources;
use Ruvelo\WP\Inbox\Urls;
use Ruvelo\WP\Inbox\Views;

/**
 * Inbox → Settings, on the Settings API.
 */
final class SettingsPage
{
    public const SLUG = 'ruvelo-inbox-settings';

    public static function register(): void
    {
        register_setting('ruvelo_inbox', Settings::OPTION, [
            'type' => 'object',
            'sanitize_callback' => [Settings::class, 'sanitize'],
            'default' => Settings::defaults(),
        ]);
        add_action('update_option_'.Settings::OPTION, [Settings::class, 'flush']);
        add_filter('option_page_capability_ruvelo_inbox', static fn (): string => Settings::CAP_MANAGE);
    }

    public static function render(): void
    {
        if (! current_user_can(Settings::CAP_MANAGE)) {
            return;
        }
        $s = Settings::all();
        $name = Settings::OPTION;
        $sources = Sources::describe();
        $counts = Mailer::counts();
        $next = wp_next_scheduled(Install::PRUNE_HOOK);
        ?>
        <div class="wrap ruvelo-inbox-settings">
            <h1><?php esc_html_e('Inbox settings', 'ruvelo-inbox'); ?></h1>
            <?php settings_errors(); ?>
            <?php $notice = Actions::notice(); ?>
            <?php if ($notice !== '') { ?>
                <div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div>
            <?php } ?>
            <form method="post" action="options.php">
                <?php settings_fields('ruvelo_inbox'); ?>

                <h2><?php esc_html_e('What sends notifications', 'ruvelo-inbox'); ?></h2>
                <p><?php esc_html_e('Each source adds its own notification types, which people can switch on and off in their preferences. Other plugins can send through the developer API whatever you choose here.', 'ruvelo-inbox'); ?></p>
                <table class="form-table" role="presentation">
                    <?php foreach ($sources as $key => $source) { ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($source['label']); ?></th>
                            <td>
                                <label><input type="checkbox" name="<?php echo esc_attr($name); ?>[sources][<?php echo esc_attr($key); ?>]" value="1"<?php checked(Settings::source($key)); ?>> <?php esc_html_e('On', 'ruvelo-inbox'); ?></label>
                                <p class="description"><?php echo esc_html($source['description']); ?>
                                    <?php if (! $source['available']) { ?>
                                        <strong><?php esc_html_e('Activate Ruvelo Feedback to use this.', 'ruvelo-inbox'); ?></strong>
                                    <?php } ?>
                                </p>
                            </td>
                        </tr>
                    <?php } ?>
                </table>

                <h2><?php esc_html_e('Email', 'ruvelo-inbox'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Notification emails', 'ruvelo-inbox'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr($name); ?>[emails]" value="1"<?php checked(! empty($s['emails'])); ?>> <?php esc_html_e('Email people the notifications they ask for', 'ruvelo-inbox'); ?></label>
                            <p class="description"><?php esc_html_e('Only types that are on for email in someone’s preferences. Emails from WordPress itself (notes, comments) and from Ruvelo Feedback are sent by them and follow each person’s switch either way.', 'ruvelo-inbox'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ruvelo-inbox-delay"><?php esc_html_e('Wait before sending', 'ruvelo-inbox'); ?></label></th>
                        <td>
                            <input id="ruvelo-inbox-delay" type="number" min="0" max="1440" class="small-text" name="<?php echo esc_attr($name); ?>[email_delay]" value="<?php echo esc_attr((string) Settings::int('email_delay')); ?>"> <?php esc_html_e('minutes', 'ruvelo-inbox'); ?>
                            <p class="description"><?php esc_html_e('Everything that arrives for someone in this time goes out as one email, sent by WP-Cron in the background.', 'ruvelo-inbox'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ruvelo-inbox-batch"><?php esc_html_e('People per batch', 'ruvelo-inbox'); ?></label></th>
                        <td>
                            <input id="ruvelo-inbox-batch" type="number" min="1" max="1000" class="small-text" name="<?php echo esc_attr($name); ?>[batch_size]" value="<?php echo esc_attr((string) Settings::int('batch_size')); ?>">
                            <p class="description"><?php
                                /* translators: 1: emails waiting, 2: emails sent */
                                echo esc_html(sprintf(__('Waiting: %1$d. Sent: %2$d.', 'ruvelo-inbox'), $counts['pending'], $counts['sent'])); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Bell and pages', 'ruvelo-inbox'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Bell on the front end', 'ruvelo-inbox'); ?></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr($name); ?>[bell_front]" value="1"<?php checked(! empty($s['bell_front'])); ?>> <?php esc_html_e('Show the bell in the toolbar on the site, not only in the admin', 'ruvelo-inbox'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ruvelo-inbox-limit"><?php esc_html_e('Items in the bell', 'ruvelo-inbox'); ?></label></th>
                        <td><input id="ruvelo-inbox-limit" type="number" min="1" max="20" class="small-text" name="<?php echo esc_attr($name); ?>[bell_limit]" value="<?php echo esc_attr((string) Settings::int('bell_limit')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ruvelo-inbox-per-page"><?php esc_html_e('Notifications per page', 'ruvelo-inbox'); ?></label></th>
                        <td><input id="ruvelo-inbox-per-page" type="number" min="5" max="100" class="small-text" name="<?php echo esc_attr($name); ?>[per_page]" value="<?php echo esc_attr((string) Settings::int('per_page')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ruvelo-inbox-page"><?php esc_html_e('Front-end inbox page', 'ruvelo-inbox'); ?></label></th>
                        <td>
                            <?php wp_dropdown_pages(['name' => $name.'[page_id]', 'id' => 'ruvelo-inbox-page', 'selected' => Settings::int('page_id'), 'show_option_none' => esc_html__('None: use the admin screen', 'ruvelo-inbox'), 'option_none_value' => '0']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() escapes its own output.?>
                            <p class="description"><?php esc_html_e('A page with the Inbox block. When set, the bell on the site and the links in emails go there, so members never need wp-admin.', 'ruvelo-inbox'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ruvelo-inbox-scheme"><?php esc_html_e('Colours', 'ruvelo-inbox'); ?></label></th>
                        <td>
                            <select id="ruvelo-inbox-scheme" name="<?php echo esc_attr($name); ?>[color_scheme]">
                                <option value="auto"<?php selected(Settings::scheme(), 'auto'); ?>><?php esc_html_e('Follow each person’s system (light or dark)', 'ruvelo-inbox'); ?></option>
                                <option value="light"<?php selected(Settings::scheme(), 'light'); ?>><?php esc_html_e('Always light', 'ruvelo-inbox'); ?></option>
                                <option value="dark"<?php selected(Settings::scheme(), 'dark'); ?>><?php esc_html_e('Always dark', 'ruvelo-inbox'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Cleanup', 'ruvelo-inbox'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ruvelo-inbox-retention"><?php esc_html_e('Keep notifications for', 'ruvelo-inbox'); ?></label></th>
                        <td>
                            <input id="ruvelo-inbox-retention" type="number" min="0" max="3650" class="small-text" name="<?php echo esc_attr($name); ?>[retention_days]" value="<?php echo esc_attr((string) Settings::int('retention_days')); ?>"> <?php esc_html_e('days', 'ruvelo-inbox'); ?>
                            <p><label><input type="checkbox" name="<?php echo esc_attr($name); ?>[keep_unread]" value="1"<?php checked(! empty($s['keep_unread'])); ?>> <?php esc_html_e('Keep unread notifications however old', 'ruvelo-inbox'); ?></label></p>
                            <p class="description"><?php
                                echo esc_html__('A daily WP-Cron job deletes older ones. 0 keeps everything.', 'ruvelo-inbox');
        if ($next !== false) {
            /* translators: %s: date and time */
            echo ' '.esc_html(sprintf(__('Next run: %s.', 'ruvelo-inbox'), (string) wp_date((string) get_option('date_format').' '.(string) get_option('time_format'), $next)));
        } ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('When the plugin is deleted', 'ruvelo-inbox'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr($name); ?>[delete_data]" value="1"<?php checked(! empty($s['delete_data'])); ?>> <?php esc_html_e('Also delete every notification, preference and setting', 'ruvelo-inbox'); ?></label>
                            <p class="description"><?php esc_html_e('Off by default: deleting the plugin keeps everyone’s notifications. Scheduled jobs are always removed.', 'ruvelo-inbox'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save changes', 'ruvelo-inbox')); ?>
            </form>

            <h2><?php esc_html_e('Try it', 'ruvelo-inbox'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page='.self::SLUG)); ?>">
                <?php echo Views::fields('test', admin_url('admin.php?page='.self::SLUG)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Views::fields().?>
                <p><?php esc_html_e('Send yourself a notification and watch the bell pick it up.', 'ruvelo-inbox'); ?></p>
                <p><?php submit_button(__('Send me a test notification', 'ruvelo-inbox'), 'secondary', 'submit', false); ?> &nbsp; <a href="<?php echo esc_url(Urls::inbox()); ?>"><?php esc_html_e('Open the inbox', 'ruvelo-inbox'); ?></a></p>
            </form>
        </div>
        <?php
    }
}

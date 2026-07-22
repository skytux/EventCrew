<?php
/**
 * Settings admin view.
 *
 * @var array<int, array{slug: string, label: string, emoji: string, capacity: int, archived: bool, anchor: string, start_offset: int|null, end_offset: int|null}> $roles
 * @var int $active_recipients How many active accounts the open-task email would reach.
 * @var string $send_nonce_action Nonce action for the "send now" form.
 * @var string $nonce_action Nonce action for the save form.
 * @var bool $eventmesh_available Whether EventMesh is installed and active.
 * @var bool $auto_create_tasks Whether a newly-synced event auto-creates its tasks.
 * @var int $notice_hours Hours before a task's start inside which a cancel counts as late.
 * @var float $reputation_threshold Score at or above which a rated member is in good standing.
 * @var bool $reputation_gate Whether at-risk members are stopped from signing up.
 * @var bool $cron_fallback Whether due sends run inline on an ordinary request.
 * @var int|false $cron_next_run Timestamp of the next scheduled notifications run, or false.
 * @var int $cron_last_run Timestamp of the last completed run, or 0.
 * @var int $app_page_id The page holding the signup shortcode, for the mobile app home.
 * @var string $app_name The installed app's name (blank falls back to the site name).
 * @var string $app_theme_color The app's theme colour, as a hex string.
 * @var array{token: string, configured: bool, dns_bypass: bool, use_fallback: bool, webhook_url: string, test_url: string, secret: string, webhook_info: array<string, mixed>|null, bot_username: string, board_chat_id: int, setup_nonce_action: string} $telegram Telegram bot configuration and live webhook status.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e('EventCrew Settings', 'eventcrew'); ?></h1>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="eventcrew_save_settings">
        <?php wp_nonce_field($nonce_action); ?>

        <h2><?php esc_html_e('Roles', 'eventcrew'); ?></h2>
        <p class="description">
            <?php esc_html_e('The kinds of job a task can be. "People needed" is only the default for new tasks - each task keeps its own number.', 'eventcrew'); ?>
        </p>
        <p class="description">
            <?php esc_html_e('The timing columns are what "Create an event\'s tasks" uses. Offsets are in minutes from the chosen anchor, negative for before it: decorating might run -120 to 0 from the start, cleaning 0 to 60 from the end. Leave both blank for a role whose timing varies - its tasks are created without times for you to fill in.', 'eventcrew'); ?>
        </p>

        <div style="overflow-x:auto;margin:1em 0">
        <table class="widefat striped" style="width:100%">
            <thead>
                <tr>
                    <th style="width:5em"><?php esc_html_e('Emoji', 'eventcrew'); ?></th>
                    <th><?php esc_html_e('Label', 'eventcrew'); ?></th>
                    <th style="width:11em"><?php esc_html_e('Slug', 'eventcrew'); ?></th>
                    <th style="width:7em"><?php esc_html_e('People', 'eventcrew'); ?></th>
                    <th style="width:9em"><?php esc_html_e('Measured from', 'eventcrew'); ?></th>
                    <th style="width:7em"><?php esc_html_e('Starts', 'eventcrew'); ?></th>
                    <th style="width:7em"><?php esc_html_e('Ends', 'eventcrew'); ?></th>
                    <th style="width:7em"><?php esc_html_e('Archived', 'eventcrew'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                // One blank row is appended so a new role can always be added
                // without a JavaScript "add row" control.
                $eventcrew_rows = $roles;
                $eventcrew_rows[] = [
                    'slug' => '',
                    'label' => '',
                    'emoji' => '',
                    'capacity' => 1,
                    'archived' => false,
                    'anchor' => \EventCrew\Support\Roles::ANCHOR_START,
                    'start_offset' => null,
                    'end_offset' => null,
                ];

                $eventcrew_anchors = [
                    \EventCrew\Support\Roles::ANCHOR_START => __('Event start', 'eventcrew'),
                    \EventCrew\Support\Roles::ANCHOR_END => __('Event end', 'eventcrew'),
                ];
                ?>
                <?php foreach ($eventcrew_rows as $eventcrew_index => $eventcrew_role) : ?>
                    <tr<?php echo $eventcrew_role['archived'] ? ' style="opacity:.6"' : ''; ?>>
                        <td>
                            <input
                                type="text"
                                name="role_emoji[<?php echo esc_attr((string) $eventcrew_index); ?>]"
                                value="<?php echo esc_attr($eventcrew_role['emoji']); ?>"
                                size="2">
                        </td>
                        <td>
                            <input
                                type="text"
                                name="role_label[<?php echo esc_attr((string) $eventcrew_index); ?>]"
                                value="<?php echo esc_attr($eventcrew_role['label']); ?>"
                                class="regular-text">
                        </td>
                        <td>
                            <input
                                type="text"
                                name="role_slug[<?php echo esc_attr((string) $eventcrew_index); ?>]"
                                value="<?php echo esc_attr($eventcrew_role['slug']); ?>"
                                class="regular-text"
                                <?php echo '' !== $eventcrew_role['slug'] ? 'readonly' : ''; ?>>
                        </td>
                        <td>
                            <input
                                type="number"
                                min="1"
                                step="1"
                                name="role_capacity[<?php echo esc_attr((string) $eventcrew_index); ?>]"
                                value="<?php echo esc_attr((string) $eventcrew_role['capacity']); ?>"
                                style="width:5em">
                        </td>
                        <td>
                            <select name="role_anchor[<?php echo esc_attr((string) $eventcrew_index); ?>]">
                                <?php foreach ($eventcrew_anchors as $eventcrew_value => $eventcrew_anchor_label) : ?>
                                    <option
                                        value="<?php echo esc_attr($eventcrew_value); ?>"
                                        <?php selected($eventcrew_role['anchor'], $eventcrew_value); ?>>
                                        <?php echo esc_html($eventcrew_anchor_label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input
                                type="number"
                                step="15"
                                name="role_start_offset[<?php echo esc_attr((string) $eventcrew_index); ?>]"
                                value="<?php echo esc_attr(null === $eventcrew_role['start_offset'] ? '' : (string) $eventcrew_role['start_offset']); ?>"
                                style="width:5em">
                        </td>
                        <td>
                            <input
                                type="number"
                                step="15"
                                name="role_end_offset[<?php echo esc_attr((string) $eventcrew_index); ?>]"
                                value="<?php echo esc_attr(null === $eventcrew_role['end_offset'] ? '' : (string) $eventcrew_role['end_offset']); ?>"
                                style="width:5em">
                        </td>
                        <td>
                            <?php if ('' !== $eventcrew_role['slug']) : ?>
                                <label>
                                    <input
                                        type="checkbox"
                                        name="role_archived[<?php echo esc_attr((string) $eventcrew_index); ?>]"
                                        value="1"
                                        <?php checked($eventcrew_role['archived']); ?>>
                                    <?php esc_html_e('Hide', 'eventcrew'); ?>
                                </label>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <p class="description">
            <?php esc_html_e('Archiving takes a role out of the task form and out of new events, while leaving every task and credit already recorded against it intact and correctly named. Roles are never deleted, because a task created months ago still has to be able to say what it was.', 'eventcrew'); ?>
        </p>

        <h2><?php esc_html_e('EventMesh', 'eventcrew'); ?></h2>
        <?php if ($eventmesh_available) : ?>
            <p>
                <label>
                    <input
                        type="checkbox"
                        name="auto_create_tasks"
                        value="1"
                        <?php checked($auto_create_tasks); ?>>
                    <?php esc_html_e('Automatically create tasks when a new event is synced from EventMesh', 'eventcrew'); ?>
                </label>
            </p>
            <p class="description">
                <?php esc_html_e('Uses the active roles\' offsets, exactly like "Create an event\'s tasks" on the Tasks page. Only ever runs the first time an event is synced - a later re-sync that corrects its time never touches tasks that may already have signups or edits on them. Use the button on the Tasks page for any event synced before this was turned on.', 'eventcrew'); ?>
            </p>
        <?php else : ?>
            <p class="description">
                <?php esc_html_e('EventMesh is not active, so there is nothing to sync tasks from.', 'eventcrew'); ?>
            </p>
        <?php endif; ?>

        <h2><?php esc_html_e('Cancellations', 'eventcrew'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="eventcrew-notice-hours"><?php esc_html_e('Late-cancel window (hours)', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="number"
                        id="eventcrew-notice-hours"
                        name="notice_hours"
                        value="<?php echo esc_attr((string) $notice_hours); ?>"
                        min="0"
                        step="1"
                        style="width:6em">
                    <p class="description">
                        <?php esc_html_e('Cancelling this many hours or less before a task starts is recorded as a late cancellation, which counts against reputation; earlier than that carries no penalty. Default 48.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Reputation', 'eventcrew'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="eventcrew-reputation-threshold"><?php esc_html_e('Good-standing threshold', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="number"
                        id="eventcrew-reputation-threshold"
                        name="reputation_threshold"
                        value="<?php echo esc_attr(rtrim(rtrim(sprintf('%.2f', $reputation_threshold), '0'), '.')); ?>"
                        min="0.05"
                        max="1"
                        step="0.05"
                        style="width:6em">
                    <p class="description">
                        <?php esc_html_e('A member’s recency-weighted score runs from 0 to 1. At or above this they are in good standing, below it they are at risk. People with fewer than three completed tasks are unrated. Default 0.6.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Sign-up gate', 'eventcrew'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="reputation_gate" value="1" <?php checked($reputation_gate); ?>>
                        <?php esc_html_e('Stop at-risk members from signing up for new tasks', 'eventcrew'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('When on, an at-risk member who taps Join is asked to talk to you instead. New and good-standing members are never affected.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Notifications', 'eventcrew'); ?></h2>
        <p class="description">
            <?php esc_html_e('Task reminders (24h before) and the open-task email (48h before) are sent by an hourly background job.', 'eventcrew'); ?>
        </p>
        <?php
        $eventcrew_when = static function (int $timestamp): string {
            if (0 === $timestamp) {
                return __('never', 'eventcrew');
            }

            return (string) wp_date('D j M Y, H:i', $timestamp);
        };
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Background job', 'eventcrew'); ?></th>
                <td>
                    <p style="margin-top:0">
                        <?php
                        printf(
                            /* translators: 1: next run time, 2: last run time */
                            esc_html__('Next run: %1$s. Last run: %2$s.', 'eventcrew'),
                            esc_html(false === $cron_next_run ? __('not scheduled', 'eventcrew') : $eventcrew_when((int) $cron_next_run)),
                            esc_html($eventcrew_when($cron_last_run))
                        );
                        ?>
                    </p>
                    <label>
                        <input type="checkbox" name="cron_fallback" value="1" <?php checked($cron_fallback); ?>>
                        <?php esc_html_e('Also run due sends on ordinary page loads (enable if WP-Cron does not fire on this host)', 'eventcrew'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('WP-Cron relies on a loopback request some shared hosts block. If "Last run" stays at "never" or falls hours behind, turn this on — any visit (including the bot webhook) will then run whatever is due.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Public signup page', 'eventcrew'); ?></h2>
        <p class="description">
            <?php
            printf(
                /* translators: %s: the shortcode, already wrapped in <code> */
                esc_html__('Add the open-task board to any page with the %s shortcode, or the "EventCrew signup" block. Visitors sign in by email — no account needed — and claiming respects the same rules as the bot.', 'eventcrew'),
                '<code>[eventcrew_signup]</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal.
            );
            ?>
        </p>

        <h2><?php esc_html_e('Mobile app', 'eventcrew'); ?></h2>
        <p class="description">
            <?php esc_html_e('Turn the signup page into an installable app. Pick the page with the shortcode, then open it on a phone and choose “Add to Home Screen” (iPhone) or “Install app” (Android).', 'eventcrew'); ?>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="eventcrew-app-page"><?php esc_html_e('Signup page', 'eventcrew'); ?></label>
                </th>
                <td>
                    <?php
                    wp_dropdown_pages([
                        'name' => 'app_page_id',
                        'id' => 'eventcrew-app-page',
                        'selected' => $app_page_id,
                        'show_option_none' => __('— None (app disabled) —', 'eventcrew'),
                        'option_none_value' => '0',
                    ]);
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-app-name"><?php esc_html_e('App name', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="text"
                        id="eventcrew-app-name"
                        name="app_name"
                        value="<?php echo esc_attr($app_name); ?>"
                        class="regular-text"
                        placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
                    <p class="description"><?php esc_html_e('Shown under the home-screen icon. Blank uses the site name.', 'eventcrew'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-app-color"><?php esc_html_e('Theme colour', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="color"
                        id="eventcrew-app-color"
                        name="app_theme_color"
                        value="<?php echo esc_attr($app_theme_color); ?>">
                    <p class="description"><?php esc_html_e('The app’s accent colour, and the fallback icon’s background.', 'eventcrew'); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Telegram bot', 'eventcrew'); ?></h2>
        <p class="description">
            <?php esc_html_e('Create a bot with @BotFather in Telegram, paste the token it gives you here, and save. Then install the webhook below and add the bot to your group.', 'eventcrew'); ?>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="eventcrew-telegram-token"><?php esc_html_e('Bot token', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="text"
                        id="eventcrew-telegram-token"
                        name="telegram_bot_token"
                        value="<?php echo esc_attr($telegram['token']); ?>"
                        class="regular-text"
                        autocomplete="off"
                        spellcheck="false">
                    <p class="description">
                        <?php esc_html_e('Kept private on your server. Clearing it disconnects the bot.', 'eventcrew'); ?>
                        <?php esc_html_e('You can instead define EVENTCREW_TELEGRAM_TOKEN in wp-config.php to keep it out of the database; the constant wins over this field.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('DNS workaround', 'eventcrew'); ?></th>
                <td>
                    <label>
                        <input
                            type="checkbox"
                            name="telegram_dns_bypass"
                            value="1"
                            <?php checked($telegram['dns_bypass']); ?>>
                        <?php esc_html_e('Resolve Telegram’s address via Cloudflare (1.1.1.1)', 'eventcrew'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Only enable this if installing the webhook fails with “Could not resolve host: api.telegram.org”. It looks the address up over HTTPS instead of the server’s own DNS, which some shared hosts block. Leave off on a normal host.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('REST workaround', 'eventcrew'); ?></th>
                <td>
                    <label>
                        <input
                            type="checkbox"
                            name="telegram_use_fallback"
                            value="1"
                            <?php checked($telegram['use_fallback']); ?>>
                        <?php esc_html_e('Receive updates through admin-ajax.php instead of the REST API', 'eventcrew'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Enable this if the webhook status shows “Wrong response from the webhook: 400/403”. Some hosts and security plugins block the /wp-json REST API for anonymous requests; this routes Telegram through admin-ajax.php, which is almost always left open. Re-install the webhook after changing it. Leave off on a normal host.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php if ('' !== $telegram['test_url']) : ?>
            <details style="margin:1em 0">
                <summary><?php esc_html_e('Test the endpoint by hand', 'eventcrew'); ?></summary>
                <p class="description">
                    <?php esc_html_e('Send this exact POST to check what your server returns to Telegram (200 = good). A 400/403/404 here reproduces what Telegram sees, without waiting on the bot.', 'eventcrew'); ?>
                </p>
                <p>
                    <strong><?php esc_html_e('Telegram is posting to:', 'eventcrew'); ?></strong><br>
                    <code style="word-break:break-all"><?php echo esc_html($telegram['test_url']); ?></code>
                </p>
                <?php if (! $telegram['use_fallback']) : ?>
                    <p>
                        <strong><?php esc_html_e('With header:', 'eventcrew'); ?></strong><br>
                        <code>X-Telegram-Bot-Api-Secret-Token: <?php echo esc_html($telegram['secret']); ?></code>
                    </p>
                <?php endif; ?>
                <p>
                    <strong><?php esc_html_e('And this JSON body (Content-Type: application/json):', 'eventcrew'); ?></strong><br>
                    <code style="word-break:break-all">{"update_id":1,"message":{"message_id":1,"date":0,"chat":{"id":1,"type":"private"},"from":{"id":1},"text":"/start"}}</code>
                </p>
            </details>
        <?php endif; ?>

        <?php submit_button(__('Save settings', 'eventcrew')); ?>
    </form>

    <h2><?php esc_html_e('Webhook', 'eventcrew'); ?></h2>
    <?php if (! $telegram['configured']) : ?>
        <p class="description">
            <?php esc_html_e('Add a bot token above and save before installing the webhook.', 'eventcrew'); ?>
        </p>
    <?php else : ?>
        <p class="description">
            <?php
            printf(
                /* translators: %s: the webhook URL Telegram will post updates to */
                esc_html__('Telegram will post updates to %s. This needs HTTPS with a valid certificate - Telegram refuses plain HTTP and self-signed certificates.', 'eventcrew'),
                '<code>' . esc_html($telegram['webhook_url']) . '</code>'
            );
            ?>
        </p>

        <?php
        $eventcrew_info = $telegram['webhook_info'];
        if (is_array($eventcrew_info)) :
            $eventcrew_pending = (int) ($eventcrew_info['pending_update_count'] ?? 0);
            $eventcrew_last_error = (string) ($eventcrew_info['last_error_message'] ?? '');
            $eventcrew_active = '' !== (string) ($eventcrew_info['url'] ?? '');
            ?>
            <table class="widefat striped" style="max-width:640px;margin:1em 0">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Status', 'eventcrew'); ?></th>
                        <td>
                            <?php
                            echo $eventcrew_active
                                ? esc_html__('Installed', 'eventcrew')
                                : esc_html__('Not installed', 'eventcrew');
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Pending updates', 'eventcrew'); ?></th>
                        <td><?php echo esc_html((string) $eventcrew_pending); ?></td>
                    </tr>
                    <?php if ('' !== $eventcrew_last_error) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('Last error', 'eventcrew'); ?></th>
                            <td><?php echo esc_html($eventcrew_last_error); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ('' !== $telegram['bot_username']) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('Bot', 'eventcrew'); ?></th>
                            <td>@<?php echo esc_html($telegram['bot_username']); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Board group', 'eventcrew'); ?></th>
                        <td>
                            <?php
                            echo 0 !== $telegram['board_chat_id']
                                ? esc_html((string) $telegram['board_chat_id'])
                                : esc_html__('Not set yet - run /board in the group once.', 'eventcrew');
                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        <?php else : ?>
            <p class="description">
                <?php esc_html_e('Could not read the webhook status from Telegram. Check the token.', 'eventcrew'); ?>
            </p>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="eventcrew_telegram_setup">
            <?php wp_nonce_field($telegram['setup_nonce_action']); ?>
            <?php submit_button(__('Install / refresh webhook', 'eventcrew'), 'secondary'); ?>
        </form>
    <?php endif; ?>

    <h2><?php esc_html_e('Open-task email', 'eventcrew'); ?></h2>
    <p>
        <?php
        printf(
            /* translators: %d: number of active accounts */
            esc_html__('%d active account(s) would receive the open-task email.', 'eventcrew'),
            (int) $active_recipients
        );
        ?>
    </p>
    <p class="description">
        <?php esc_html_e('Sends to everyone with a verified, switched-on account, skipping anyone already signed up for that date. It only sends when there are open tasks, and never mails the same person twice for the same date. People switch it off by disabling their account (in the bot with /stop, or the link in any email).', 'eventcrew'); ?>
    </p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="eventcrew_send_open_task">
        <?php wp_nonce_field($send_nonce_action); ?>
        <?php submit_button(__('Send open-task email now', 'eventcrew'), 'secondary'); ?>
    </form>
</div>

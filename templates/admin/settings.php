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

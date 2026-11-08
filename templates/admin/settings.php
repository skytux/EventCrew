<?php

/**
 * Settings admin view.
 *
 * @var array<int, array{slug: string, label: string, emoji: string, capacity: int, archived: bool, anchor: string, start_offset: int|null, end_offset: int|null, description: string, restricted: bool, every_night: bool, sales_desk: bool, on_board: bool, guest_signup: bool, anonymous: bool, title_contains: string, plus_ones: int, conditions: array{counts: array<string, int>, min_roles: int, times_each: int}}> $roles
 * @var int $organize_plus_ones The "+1 allowed" number new Organize tasks copy.
 * @var string $guide_url Link to the crew's full guide, shared by every role.
 * @var string $nonce_action Nonce action for the save form.
 * @var bool $eventmesh_available Whether EventMesh is installed and active.
 * @var bool $auto_create_tasks Whether a newly-synced event auto-creates its tasks.
 * @var int $notice_hours Hours before a task's start inside which a cancel counts as late.
 * @var int $signout_lock_minutes Minutes before a task's start from which crew can no longer sign out (0 = off).
 * @var int $second_thoughts_minutes Minutes after a take or cancel before its messages and tickets are sent (0 = off).
 * @var array<int, array{name: string, option: string, value: int, min: int, label: string, help: string}> $takeover_rows Takeover request settings.
 * @var float $reputation_threshold Score at or above which a rated member is in good standing.
 * @var array<int, array{status: string, label: string, percent: int}> $reputation_weights Per-outcome score weights.
 * @var int $tasks_per_credit Completed tasks a member works to earn one free-entry credit.
 * @var bool $extra_task_credit Whether an extra task on the same night earns a free-entry credit.
 * @var int $min_rated_tasks Finished tasks before a member is rated rather than "New".
 * @var int $reputation_half_life Days for an outcome to count half as much toward the score.
 * @var bool $reputation_gate Whether at-risk members are stopped from signing up.
 * @var bool $board_push_enabled Whether the board is auto-re-posted to the group before open events.
 * @var bool $ticket_giving Whether members can give their free-entry ticket away.
 * @var int $board_push_lead_week Hours before an open event the first board re-post goes out.
 * @var int $board_push_lead_soon Hours before an open event the second board re-post goes out.
 * @var int $notify_hour Hour of day (0-23) the scheduled notification window opens.
 * @var int $open_task_lead_week Hours before an open date the heads-up open-task call goes out.
 * @var int $open_task_lead_soon Hours before an open date the last-call open-task call goes out.
 * @var int $door_link_lead Minutes before the night's first task start organizers get the sales desk link.
 * @var bool $welcome_enabled Whether the welcome message goes out.
 * @var string $maintainer_contact Whom to contact when the bot cannot be reached ('' = the default).
 * @var bool $newsletter_enabled Whether the newsletter goes out.
 * @var bool $followup_enabled Whether the post-event email goes out the day after each event.
 * @var int $newsletter_days Days before an event the newsletter goes out.
 * @var string $newsletter_preview_mode NewsletterSchedule::PREVIEW_AFTER_PREVIOUS or PREVIEW_BEFORE_EVENT.
 * @var int $newsletter_preview_hours Hours after the previous event ends that the preview goes.
 * @var int $newsletter_preview_days Days before the event that the preview goes (the other choice).
 * @var string $newsletter_preview_time The preview time of day, HH:MM.
 * @var string $newsletter_send_time The time of day the newsletter goes, HH:MM.
 * @var bool $newsletter_crew Whether crew members with a verified email are added to the newsletter.
 * @var int $newsletter_bar_image The sign-up bar's background image (attachment id; 0 = none).
 * @var string $newsletter_bar_image_url That image's medium size URL for the preview, or ''.
 * @var bool $newsletter_write Whether the language model writes the newsletter's texts.
 * @var string $newsletter_words The site's words and style for those texts.
 * @var bool $llm_configured Whether the language model's server and model are set.
 * @var string $welcome_nonce_action Nonce action for the welcome test send.
 * @var int $door_link_crew Minutes before their own task the sales-desk crew can get the link.
 * @var int $door_scan_open Minutes before the event's start that ticket scans are taken.
 * @var int $door_scan_close Minutes after the event's end that ticket scans are still taken.
 * @var int $door_max_people The most people let in on one night; 0 for no max.
 * @var bool $door_allow_over_max Whether the sales desk may go over the max, with a warning.
 * @var string $turnstile_site_key Cloudflare Turnstile site key for the web sign-in form.
 * @var string $turnstile_secret Cloudflare Turnstile secret key, masked to its last 4 characters ('' when none is saved).
 * @var bool $cron_fallback Whether due sends run inline on an ordinary request.
 * @var int|false $cron_next_run Timestamp of the next scheduled notifications run, or false.
 * @var int $cron_last_run Timestamp of the last completed run, or 0.
 * @var int $app_page_id The page holding the signup shortcode, for the mobile app home.
 * @var string $orphan_signup_page Title of a published shortcode page when none is selected, else ''.
 * @var array<string, mixed> $app_name The installed app's name, a TranslatedText value (blank falls back to the site name).
 * @var string $app_theme_color The app's theme colour, as a hex string.
 * @var string $app_accent_color The Crew app's accent colour, as a hex string.
 * @var array<string, mixed> $signature The crew's sign-off, a TranslatedText value, shared by the emails and the broadcast DMs.
 * @var bool $email_html Whether notifications go out as HTML rather than plain text.
 * @var string $email_logo An explicit logo URL for emails; blank uses the site's own logo.
 * @var string $email_background The picture behind every email; blank draws the purple fade alone.
 * @var array{allowed: bool, warning: string, buttons_warning: string, shown: int, shown_max: int, kinds: array<string, array{label: string, ticked: bool}>} $hybrid The Hybrid tick of each message kind (HybridPolicy).
 * @var string $organization_name The organization's name as typed in Settings; blank uses the site name.
 * @var string $ticket_background The image URL drawn behind the ticket page; blank draws none.
 * @var array<string, mixed> $ticket_note Free text shown on every ticket, a TranslatedText value; blank shows the built-in doors line.
 * @var bool $doors_close_on Whether the doors close $doors_close_minutes minutes after the start.
 * @var int $doors_close_minutes How many minutes after the start the doors close.
 * @var string $default_venue The default venue of the events, or ''.
 * @var bool $venue_ai Whether the language model reads each event's venue from its description.
 * @var array<string, mixed> $infrastructure_note How the site is run, a TranslatedText value, shown to organizers in the crew page's Help tab; blank shows nothing.
 * @var string $email_edit_url Admin URL that opens the email template in the editor.
 * @var string $email_nonce_action Nonce action for the test-send and reset buttons.
 * @var string $email_test_to The address a test email would go to (the current user's).
 * @var string $import_mailbox The address organizers mail ticket files and guest lists to; '' when none is set.
 * @var array<int, string> $import_senders Addresses besides the organizers' that may mail a list in.
 * @var array<int, array{id: string, name: string, columns: array<string, string|array<int, string>>, decoder_js: string}> $ticket_formats The ticket file formats.
 * @var array{protocol: string, server: string, port: int, security: string, username: string, password: string, folder: string, imported_folder: string, rejected_folder: string, authserv_id: string} $mail_fetch How the site reads the import mailbox itself.
 * @var array{time: int, ok: bool, message: string} $mail_fetch_status The last mailbox check.
 * @var array{token: string, configured: bool, dns_bypass: bool, use_fallback: bool, secret_in_url: bool, webhook_url: string, test_url: string, header_only_url: string, secret: string, webhook_info: array<string, mixed>|null, bot_username: string, board_chat_id: int, group_link: string, group_link_auto: string, group_lock: bool, group_gate: bool, board_cards: bool, board_banner_page: int, profile: array{about: array{source: string, texts: array<string, string>, manual: array<string, bool>}, description: array{source: string, texts: array<string, string>, manual: array<string, bool>}}, setup_nonce_action: string} $telegram Telegram bot configuration and live webhook status.
 * @var array{enabled: bool, forward: bool, url: string, model: string, key_status: string, private_limit: int, organizer_limit: int, group_limit: int, pages: array<int, int>, page_choices: array<int, string>, default_pages: array<int, string>, test_url: string, remove_key_url: string} $assistant The language-model helper for plain bot messages.
 * @var array{controller: string, address: string, contact: string, dpo_name: string, dpo_contact: string, retention_years: int, retention_min: int, retention_max: int, purge: bool, page_id: int, configured: bool, notice_url: string, buyer_page_id: int, buyer_notice_url: string, create_buyer_url: string, core_policy_url: string, create_nonce_action: string} $privacy Who is answerable for the crew's data, and how long it is kept.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e('EventCrew Settings', 'eventcrew'); ?></h1>

    <h2 class="nav-tab-wrapper eventcrew-tabs" style="margin:.5em 0 1em">
        <a href="#" class="nav-tab" data-ec-tab="roles"><?php esc_html_e('Roles', 'eventcrew'); ?></a>
        <a href="#" class="nav-tab" data-ec-tab="telegram"><?php esc_html_e('Telegram bot', 'eventcrew'); ?></a>
        <a href="#" class="nav-tab" data-ec-tab="web"><?php esc_html_e('Web page', 'eventcrew'); ?></a>
        <a href="#" class="nav-tab" data-ec-tab="email"><?php esc_html_e('Email', 'eventcrew'); ?></a>
        <a href="#" class="nav-tab" data-ec-tab="tuning"><?php esc_html_e('Reputation & alerts', 'eventcrew'); ?></a>
        <a href="#" class="nav-tab" data-ec-tab="privacy"><?php esc_html_e('Privacy', 'eventcrew'); ?></a>
    </h2>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="eventcrew_save_settings">
        <?php wp_nonce_field($nonce_action); ?>

        <div class="ec-tab-panel" data-ec-tab="roles">
        <h2><?php esc_html_e('Roles', 'eventcrew'); ?></h2>
        <p class="description">
            <?php esc_html_e('The kinds of job a task can be. "People needed" is only the default for new tasks - each task keeps its own number.', 'eventcrew'); ?>
        </p>
        <p class="description">
            <?php esc_html_e('The timing columns are what "Create an event\'s tasks" uses. Offsets are in minutes from the chosen anchor, negative for before it: decorating might run -120 to 0 from the start, cleaning 0 to 60 from the end. Leave both blank for a role whose timing varies - its tasks are created without times for you to fill in.', 'eventcrew'); ?>
        </p>

        <?php
        // One blank role is appended so a new role can always be added
        // without a JavaScript "add" control.
        $eventcrew_rows = $roles;
        $eventcrew_rows[] = [
            'slug' => '',
            'label' => '',
            'emoji' => '',
            'capacity' => 1,
            'archived' => false,
            'restricted' => false,
            'every_night' => true,
            'sales_desk' => false,
            'on_board' => true,
            'guest_signup' => true,
            'anonymous' => false,
            'members_display' => false,
            'title_contains' => '',
            'plus_ones' => 0,
            'conditions' => \EventCrew\Support\Roles::noConditions(),
            'anchor' => \EventCrew\Support\Roles::ANCHOR_START,
            // A new role's tasks start when the event starts (offset 0).
            'start_offset' => 0,
            'end_offset' => null,
            'description' => '',
        ];

        $eventcrew_anchors = [
            \EventCrew\Support\Roles::ANCHOR_START => __('Event start', 'eventcrew'),
            \EventCrew\Support\Roles::ANCHOR_END => __('Event end', 'eventcrew'),
        ];

        /*
         * One role at a time: each role is a closed block showing its emoji,
         * name and slug; pressing it opens that role's fields as an ordinary
         * settings form. The shared name="" makes the browser keep only one
         * open (a browser too old for that just lets several open). Closed
         * blocks are still inside the form, so Save stores every role exactly
         * as the old table did.
         */
        ?>
        <div class="eventcrew-role-list">
        <?php foreach ($eventcrew_rows as $eventcrew_index => $eventcrew_role) : ?>
            <?php
            $eventcrew_i = (string) $eventcrew_index;
            $eventcrew_id = 'eventcrew-role-' . $eventcrew_i;
            $eventcrew_is_new = '' === $eventcrew_role['slug'];
            ?>
            <details class="eventcrew-role<?php echo $eventcrew_role['archived'] ? ' is-archived' : ''; ?>" name="eventcrew-role">
                <summary>
                    <?php if ($eventcrew_is_new) : ?>
                        <strong><?php esc_html_e('+ Add a role', 'eventcrew'); ?></strong>
                    <?php else : ?>
                        <strong><?php echo esc_html(trim($eventcrew_role['emoji'] . ' ' . $eventcrew_role['label'])); ?></strong>
                        <code><?php echo esc_html($eventcrew_role['slug']); ?></code>
                        <?php if ($eventcrew_role['archived']) : ?>
                            <span class="eventcrew-role-tag"><?php esc_html_e('Archived', 'eventcrew'); ?></span>
                        <?php endif; ?>
                        <?php if ($eventcrew_role['restricted']) : ?>
                            <span class="eventcrew-role-tag"><?php esc_html_e('Role holders only', 'eventcrew'); ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </summary>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($eventcrew_id); ?>-emoji"><?php esc_html_e('Emoji', 'eventcrew'); ?></label></th>
                        <td>
                            <input
                                type="text"
                                id="<?php echo esc_attr($eventcrew_id); ?>-emoji"
                                name="role_emoji[<?php echo esc_attr($eventcrew_i); ?>]"
                                value="<?php echo esc_attr($eventcrew_role['emoji']); ?>"
                                size="2">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($eventcrew_id); ?>-label"><?php esc_html_e('Label', 'eventcrew'); ?></label></th>
                        <td>
                            <?php
                            \EventCrew\Support\TranslatedText::field(
                                'role_label[' . $eventcrew_i . ']',
                                $eventcrew_id . '-label',
                                $eventcrew_role['label_text'] ?? $eventcrew_role['label'],
                                0,
                                false,
                                '',
                                $eventcrew_is_new ? '' : 'role.' . $eventcrew_role['slug'] . '.label'
                            );
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($eventcrew_id); ?>-slug"><?php esc_html_e('Slug', 'eventcrew'); ?></label></th>
                        <td>
                            <input
                                type="text"
                                id="<?php echo esc_attr($eventcrew_id); ?>-slug"
                                name="role_slug[<?php echo esc_attr($eventcrew_i); ?>]"
                                value="<?php echo esc_attr($eventcrew_role['slug']); ?>"
                                class="regular-text"
                                <?php echo $eventcrew_is_new ? '' : 'readonly'; ?>>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($eventcrew_id); ?>-capacity"><?php esc_html_e('People', 'eventcrew'); ?></label></th>
                        <td>
                            <input
                                type="number"
                                min="0"
                                step="1"
                                id="<?php echo esc_attr($eventcrew_id); ?>-capacity"
                                name="role_capacity[<?php echo esc_attr($eventcrew_i); ?>]"
                                value="<?php echo esc_attr((string) $eventcrew_role['capacity']); ?>"
                                class="small-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($eventcrew_id); ?>-plus-ones"><?php esc_html_e('+1 allowed', 'eventcrew'); ?></label></th>
                        <td>
                            <input
                                type="number"
                                min="0"
                                max="20"
                                step="1"
                                id="<?php echo esc_attr($eventcrew_id); ?>-plus-ones"
                                name="role_plus_ones[<?php echo esc_attr($eventcrew_i); ?>]"
                                value="<?php echo esc_attr((string) $eventcrew_role['plus_ones']); ?>"
                                class="small-text">
                            <p class="description"><?php esc_html_e('How many guests each person on a new task of this role may bring in for free. New tasks copy this number; change it on a single task on the Tasks screen.', 'eventcrew'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Timing', 'eventcrew'); ?></th>
                        <td>
                            <label>
                                <?php esc_html_e('Measured from', 'eventcrew'); ?>
                                <select name="role_anchor[<?php echo esc_attr($eventcrew_i); ?>]">
                                    <?php foreach ($eventcrew_anchors as $eventcrew_value => $eventcrew_anchor_label) : ?>
                                        <option
                                            value="<?php echo esc_attr($eventcrew_value); ?>"
                                            <?php selected($eventcrew_role['anchor'], $eventcrew_value); ?>>
                                            <?php echo esc_html($eventcrew_anchor_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label><br>
                            <label>
                                <?php esc_html_e('Starts', 'eventcrew'); ?>
                                <input
                                    type="number"
                                    step="15"
                                    name="role_start_offset[<?php echo esc_attr($eventcrew_i); ?>]"
                                    value="<?php echo esc_attr(null === $eventcrew_role['start_offset'] ? '' : (string) $eventcrew_role['start_offset']); ?>"
                                    class="small-text">
                            </label>
                            <label>
                                <?php esc_html_e('Ends', 'eventcrew'); ?>
                                <input
                                    type="number"
                                    step="15"
                                    name="role_end_offset[<?php echo esc_attr($eventcrew_i); ?>]"
                                    value="<?php echo esc_attr(null === $eventcrew_role['end_offset'] ? '' : (string) $eventcrew_role['end_offset']); ?>"
                                    class="small-text">
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Access', 'eventcrew'); ?></th>
                        <td>
                            <?php if (! $eventcrew_is_new) : ?>
                                <label>
                                    <input
                                        type="checkbox"
                                        name="role_archived[<?php echo esc_attr($eventcrew_i); ?>]"
                                        value="1"
                                        <?php checked($eventcrew_role['archived']); ?>>
                                    <?php esc_html_e('Archived (hidden)', 'eventcrew'); ?>
                                </label><br>
                            <?php endif; ?>
                            <label>
                                <input
                                    type="checkbox"
                                    name="role_restricted[<?php echo esc_attr($eventcrew_i); ?>]"
                                    value="1"
                                    <?php checked($eventcrew_role['restricted']); ?>>
                                <?php esc_html_e('Only people with this role', 'eventcrew'); ?>
                            </label><br>
                            <label>
                                <input
                                    type="checkbox"
                                    name="role_every_night[<?php echo esc_attr($eventcrew_i); ?>]"
                                    value="1"
                                    <?php checked($eventcrew_role['every_night']); ?>>
                                <?php esc_html_e('Add to every night', 'eventcrew'); ?>
                            </label><br>
                            <label>
                                <input
                                    type="checkbox"
                                    name="role_sales_desk[<?php echo esc_attr($eventcrew_i); ?>]"
                                    value="1"
                                    <?php checked($eventcrew_role['sales_desk']); ?>>
                                <?php esc_html_e('Opens the sales desk', 'eventcrew'); ?>
                            </label><br>
                            <?php
                            /*
                             * What people without this role get on the boards
                             * (BoardVisibility). Guest sign up greys out while
                             * Guest display and Members display are both off,
                             * which it then means nothing without
                             * (eventcrew-admin.css).
                             */
                            ?>
                            <label>
                                <input
                                    type="checkbox"
                                    name="role_on_board[<?php echo esc_attr($eventcrew_i); ?>]"
                                    value="1"
                                    <?php checked($eventcrew_role['on_board']); ?>>
                                <?php esc_html_e('Guest display: people without this role see its tasks', 'eventcrew'); ?>
                            </label><br>
                            <label>
                                <input
                                    type="checkbox"
                                    name="role_members_display[<?php echo esc_attr($eventcrew_i); ?>]"
                                    value="1"
                                    <?php checked($eventcrew_role['members_display'] ?? false); ?>>
                                <?php esc_html_e('Members display: signed-in members see its tasks, even without the role', 'eventcrew'); ?>
                            </label><br>
                            <label class="eventcrew-needs-display">
                                <input
                                    type="checkbox"
                                    name="role_guest_signup[<?php echo esc_attr($eventcrew_i); ?>]"
                                    value="1"
                                    <?php checked($eventcrew_role['guest_signup'] ?? true); ?>>
                                <?php esc_html_e('Guest sign up: people without this role can sign up (needs Guest or Members display)', 'eventcrew'); ?>
                            </label><br>
                            <label>
                                <input
                                    type="checkbox"
                                    name="role_anonymous[<?php echo esc_attr($eventcrew_i); ?>]"
                                    value="1"
                                    <?php checked($eventcrew_role['anonymous'] ?? false); ?>>
                                <?php esc_html_e('Anonymous display: hide the names from people without this role', 'eventcrew'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($eventcrew_id); ?>-title-contains"><?php esc_html_e('Only on events', 'eventcrew'); ?></label></th>
                        <td>
                            <input
                                type="text"
                                id="<?php echo esc_attr($eventcrew_id); ?>-title-contains"
                                name="role_title_contains[<?php echo esc_attr($eventcrew_i); ?>]"
                                value="<?php echo esc_attr($eventcrew_role['title_contains']); ?>"
                                class="regular-text">
                            <p class="description"><?php esc_html_e('New events get a task of this role only when the event title contains this text (upper or lower case does not matter). Leave empty for every event.', 'eventcrew'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Conditions', 'eventcrew'); ?></th>
                        <td>
                            <?php $eventcrew_conditions = $eventcrew_role['conditions']; ?>
                            <p class="description"><?php esc_html_e('Completed tasks', 'eventcrew'); ?></p>
                            <?php foreach ($roles as $eventcrew_count_role) : ?>
                                <?php
                                if ($eventcrew_count_role['slug'] === $eventcrew_role['slug']) {
                                    continue;
                                }
                                ?>
                                <label style="display:block">
                                    <input
                                        type="number"
                                        min="0"
                                        step="1"
                                        name="role_count[<?php echo esc_attr($eventcrew_i); ?>][<?php echo esc_attr($eventcrew_count_role['slug']); ?>]"
                                        value="<?php echo esc_attr((string) ($eventcrew_conditions['counts'][$eventcrew_count_role['slug']] ?? '')); ?>"
                                        class="small-text">
                                    <?php echo esc_html(\EventCrew\Support\Roles::display($eventcrew_count_role['slug'])); ?>
                                </label>
                            <?php endforeach; ?>
                            <label style="display:block">
                                <?php esc_html_e('At least', 'eventcrew'); ?>
                                <input
                                    type="number"
                                    min="0"
                                    step="1"
                                    name="role_min_roles[<?php echo esc_attr($eventcrew_i); ?>]"
                                    value="<?php echo esc_attr(0 === $eventcrew_conditions['min_roles'] ? '' : (string) $eventcrew_conditions['min_roles']); ?>"
                                    class="small-text">
                                <?php esc_html_e('different roles,', 'eventcrew'); ?>
                                <input
                                    type="number"
                                    min="0"
                                    step="1"
                                    name="role_times_each[<?php echo esc_attr($eventcrew_i); ?>]"
                                    value="<?php echo esc_attr(0 === $eventcrew_conditions['times_each'] ? '' : (string) $eventcrew_conditions['times_each']); ?>"
                                    class="small-text">
                                <?php esc_html_e('times each', 'eventcrew'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($eventcrew_id); ?>-description"><?php esc_html_e('Description', 'eventcrew'); ?></label></th>
                        <td>
                            <?php
                            \EventCrew\Support\TranslatedText::field(
                                'role_description[' . $eventcrew_i . ']',
                                $eventcrew_id . '-description',
                                $eventcrew_role['description_text'] ?? $eventcrew_role['description'],
                                0,
                                true,
                                '',
                                $eventcrew_is_new ? '' : 'role.' . $eventcrew_role['slug'] . '.description'
                            );
                            ?>
                        </td>
                    </tr>
                </table>
            </details>
        <?php endforeach; ?>
        </div>

        <p class="description">
            <?php esc_html_e('Archiving takes a role out of the task form and out of new events, while leaving every task and credit already recorded against it intact and correctly named. Roles are never deleted, because a task created months ago still has to be able to say what it was.', 'eventcrew'); ?>
        </p>
        <p class="description">
            <?php esc_html_e('A role ticked "Only people with this role" (a DJ, a VJ, a ceremony leader) is shown only to people given that role, in the Crew app, in the bot and at the sales desk. Its tasks have no sign-up limit and are never posted on the Telegram group board. Give people the role on the People page, in the Crew app Admin tab, or with /people in the bot. With "Add to every night" ticked, every upcoming night gets one task of that role, including nights that already exist; unticking it later removes nothing.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
        </p>
        <p class="description">
            <?php esc_html_e('"Opens the sales desk": the people on that night’s task of this role are the welcome crew. Once marked arrived they get the sales desk link, can see who has a ticket and the guest list, and can mark the crew with /roster.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
        </p>
        <p class="description">
            <?php esc_html_e('Conditions: the completed tasks someone needs before the organizers hear about them. Set a number for any of the named roles, or a number of different roles each done a number of times, or both. Meeting them lets nobody sign up. The organizers get a Telegram message and an email the first time someone meets them, and the first time someone is given the role. The People page, Role conditions tab, and the bot’s /people show who meets them.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
        </p>
        <p class="description">
            <?php esc_html_e('The description is a sentence or two on what the job involves. It is shown under the task on the web page, and in the signup confirmation and the reminder.', 'eventcrew'); ?>
        </p>

        <p>
            <label for="eventcrew-organize-plus-ones"><?php esc_html_e('Organize task: +1 allowed', 'eventcrew'); ?></label>
            <input
                type="number"
                min="0"
                max="20"
                step="1"
                id="eventcrew-organize-plus-ones"
                name="organize_plus_ones"
                value="<?php echo esc_attr((string) $organize_plus_ones); ?>"
                class="small-text">
        </p>
        <p class="description">
            <?php esc_html_e('The Organize task is not in the list above. New Organize tasks copy this number.', 'eventcrew'); ?>
        </p>

        <h2><?php esc_html_e('Crew guide', 'eventcrew'); ?></h2>
        <p>
            <label for="eventcrew-guide-url"><?php esc_html_e('Link to the full guide', 'eventcrew'); ?></label><br>
            <input
                type="url"
                id="eventcrew-guide-url"
                name="guide_url"
                value="<?php echo esc_attr($guide_url); ?>"
                class="large-text"
                placeholder="https://">
        </p>
        <p class="description">
            <?php esc_html_e('One link for every role, shown as a named "Crew guide" button or link - on the Telegram board, on the web page, and in the signup confirmation and the reminder. Leave blank to show no guide link. Make sure everyone with the link can open it.', 'eventcrew'); ?>
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

        </div>
        <div class="ec-tab-panel" data-ec-tab="tuning">
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
            <tr>
                <th scope="row">
                    <label for="eventcrew-signout-lock"><?php esc_html_e('Sign-out closes (minutes before the start)', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="number"
                        id="eventcrew-signout-lock"
                        name="signout_lock_minutes"
                        value="<?php echo esc_attr((string) $signout_lock_minutes); ?>"
                        min="0"
                        step="1"
                        style="width:6em">
                    <p class="description">
                        <?php esc_html_e('From this many minutes before a task starts, and after the start, crew members can no longer sign themselves out of it. Organizers can still take them off. Tasks without a start time are never locked. 0 turns this off. Default 5.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-second-thoughts"><?php esc_html_e('Second thoughts window (minutes)', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="number"
                        id="eventcrew-second-thoughts"
                        name="second_thoughts_minutes"
                        value="<?php echo esc_attr((string) $second_thoughts_minutes); ?>"
                        min="0"
                        step="1"
                        style="width:6em">
                    <p class="description">
                        <?php esc_html_e('Taking or cancelling a task shows at once everywhere. The messages and tickets it causes wait this many minutes, and then only what is still true is sent. A cancel within these minutes after taking the task is never a late cancellation. The wait runs on WP-Cron, so it can be a few minutes longer. 0 turns this off. Default 5.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Takeover requests', 'eventcrew'); ?></h2>
        <p><?php esc_html_e('When someone asks to take over another person’s task, the holder decides. If the holder does not answer by the deadline, they keep the task. These numbers set the deadline.', 'eventcrew'); ?></p>
        <table class="form-table" role="presentation">
            <?php foreach ($takeover_rows as $takeover_row) : ?>
            <tr>
                <th scope="row">
                    <label for="eventcrew-<?php echo esc_attr($takeover_row['name']); ?>"><?php echo esc_html($takeover_row['label']); ?></label>
                </th>
                <td>
                    <input
                        type="number"
                        id="eventcrew-<?php echo esc_attr($takeover_row['name']); ?>"
                        name="<?php echo esc_attr($takeover_row['name']); ?>"
                        value="<?php echo esc_attr((string) $takeover_row['value']); ?>"
                        min="<?php echo esc_attr((string) $takeover_row['min']); ?>"
                        step="1"
                        style="width:6em">
                    <p class="description"><?php echo esc_html($takeover_row['help']); ?></p>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>

        <h2><?php esc_html_e('Board reminders', 'eventcrew'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Auto re-post the board', 'eventcrew'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="board_push_enabled" value="1" <?php checked($board_push_enabled); ?>>
                        <?php esc_html_e('Re-post the group board as an open event approaches', 'eventcrew'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('When on, the board is re-posted into the group at each lead time below (deleting the previous one), so it resurfaces instead of sitting buried. It reaches the whole group and cannot be muted per person.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-push-lead-week"><?php esc_html_e('First reminder (hours before)', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="number"
                        id="eventcrew-push-lead-week"
                        name="board_push_lead_week"
                        value="<?php echo esc_attr((string) $board_push_lead_week); ?>"
                        min="0"
                        step="1"
                        style="width:6em">
                    <p class="description">
                        <?php esc_html_e('How far ahead of an open event the first re-post goes out. Default 168 (one week). Set to 0 to skip this one.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-push-lead-soon"><?php esc_html_e('Second reminder (hours before)', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="number"
                        id="eventcrew-push-lead-soon"
                        name="board_push_lead_soon"
                        value="<?php echo esc_attr((string) $board_push_lead_soon); ?>"
                        min="0"
                        step="1"
                        style="width:6em">
                    <p class="description">
                        <?php esc_html_e('The nearer re-post, closer to the event. Default 48. Set to 0 to skip this one.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('When notifications go out', 'eventcrew'); ?></h2>
        <p class="description">
            <?php esc_html_e('Scheduled messages — task reminders, the open-task call, standing notices and the board re-post — are held until this hour and go out over the twelve hours that follow. Without it they left the moment they came due, which for a task starting at three in the morning meant a reminder at three in the morning.', 'eventcrew'); ?>
        </p>
        <p class="description">
            <?php esc_html_e('Messages that answer something a person just did — a signup confirmation, a freed slot, a ticket — are never held. Nor is a reminder for a task that starts before the next window opens: missing it entirely would be worse than an awkward hour.', 'eventcrew'); ?>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="eventcrew-notify-hour"><?php esc_html_e('Start sending at', 'eventcrew'); ?></label>
                </th>
                <td>
                    <select name="notify_hour" id="eventcrew-notify-hour">
                        <?php for ($eventcrew_h = 0; $eventcrew_h <= 23; $eventcrew_h++) : ?>
                            <option value="<?php echo esc_attr((string) $eventcrew_h); ?>" <?php selected($notify_hour, $eventcrew_h); ?>>
                                <?php echo esc_html(sprintf('%02d:00', $eventcrew_h)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: the site's timezone, e.g. Europe/Helsinki */
                            esc_html__('Your site’s time (%s). Default 09:00.', 'eventcrew'),
                            esc_html(wp_timezone_string())
                        );
                        ?>
                    </p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Open-task call', 'eventcrew'); ?></h2>
        <p class="description">
            <?php esc_html_e('The personal “some tasks still need people” notice, sent by email and Telegram DM to everyone active. It goes out at the two lead times below — a heads-up while there is still time to rearrange a weekend, then a last call — and again if a job is added to a date after people have already been told about it.', 'eventcrew'); ?>
        </p>
        <p class="description">
            <?php esc_html_e('Nobody gets more than one of these a day, however many dates are open: they are gathered into a single message.', 'eventcrew'); ?>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="eventcrew-open-task-lead-week"><?php esc_html_e('Heads-up (hours before)', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="number"
                        id="eventcrew-open-task-lead-week"
                        name="open_task_lead_week"
                        value="<?php echo esc_attr((string) $open_task_lead_week); ?>"
                        min="0"
                        step="1"
                        style="width:6em">
                    <p class="description">
                        <?php esc_html_e('Default 168 (one week). Set to 0 to skip it and send only the last call.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-open-task-lead-soon"><?php esc_html_e('Last call (hours before)', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="number"
                        id="eventcrew-open-task-lead-soon"
                        name="open_task_lead_soon"
                        value="<?php echo esc_attr((string) $open_task_lead_soon); ?>"
                        min="0"
                        step="1"
                        style="width:6em">
                    <p class="description">
                        <?php esc_html_e('Default 48. Set to 0 to skip it. A date that first appears inside both windows gets one message, not two.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-door-link-lead"><?php esc_html_e('Sales desk link, organizers (minutes before)', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="number"
                        id="eventcrew-door-link-lead"
                        name="door_link_lead"
                        value="<?php echo esc_attr((string) $door_link_lead); ?>"
                        min="0"
                        step="1"
                        style="width:6em">
                    <p class="description">
                        <?php esc_html_e('Default 30. Counted back from the night’s first task start, or from 17:00 when no task has a start time.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Organizer notice channels', 'eventcrew'); ?></th>
                <td>
                    <?php $eventcrew_org_channels = \EventCrew\Support\DoorLinkSender::organizerChannels(); ?>
                    <label>
                        <input type="checkbox" name="organizer_notice_channels[]" value="telegram"
                            <?php checked(in_array('telegram', $eventcrew_org_channels, true)); ?>>
                        <?php esc_html_e('Telegram', 'eventcrew'); ?>
                    </label>
                    <label style="margin-left:1em">
                        <input type="checkbox" name="organizer_notice_channels[]" value="email"
                            <?php checked(in_array('email', $eventcrew_org_channels, true)); ?>>
                        <?php esc_html_e('Email', 'eventcrew'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Only organizers signed up on the night’s Organize task get it: the sales desk link and a button to the Crew app’s Admin tab. Untick both to send nothing.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-door-link-crew"><?php esc_html_e('Sales desk link, welcome crew (minutes before)', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="number"
                        id="eventcrew-door-link-crew"
                        name="door_link_crew"
                        value="<?php echo esc_attr((string) $door_link_crew); ?>"
                        min="0"
                        step="1"
                        style="width:6em">
                    <p class="description">
                        <?php esc_html_e('Default 10. Counted back from their own task’s start; the link comes only once they are marked arrived, and stops working when the event ends.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-door-scan-open"><?php esc_html_e('Ticket scans open (minutes before the event starts)', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="number"
                        id="eventcrew-door-scan-open"
                        name="door_scan_open"
                        value="<?php echo esc_attr((string) $door_scan_open); ?>"
                        min="0"
                        step="1"
                        style="width:6em">
                    <p class="description">
                        <?php esc_html_e('Default 60. The start is the linked event’s start, or else the first sales desk task’s start. A ticket QR code scanned earlier is refused.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-door-scan-close"><?php esc_html_e('Ticket scans close (minutes after the event ends)', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="number"
                        id="eventcrew-door-scan-close"
                        name="door_scan_close"
                        value="<?php echo esc_attr((string) $door_scan_close); ?>"
                        min="0"
                        step="1"
                        style="width:6em">
                    <p class="description">
                        <?php esc_html_e('Default 0. A ticket QR code scanned later is refused. Finding a person on the list and ticking them by hand still works.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-door-max-people"><?php esc_html_e('Max people per event', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="number"
                        id="eventcrew-door-max-people"
                        name="door_max_people"
                        value="<?php echo $door_max_people > 0 ? esc_attr((string) $door_max_people) : ''; ?>"
                        min="0"
                        step="1"
                        style="width:6em">
                    <p class="description">
                        <?php esc_html_e('Empty for no max. Counts everyone the sales desk expects: crew, tickets, guests and the people they may bring, and the people counted without a name.', 'eventcrew'); ?>
                    </p>
                    <label>
                        <input type="checkbox" name="door_allow_over_max" value="1" <?php checked($door_allow_over_max); ?>>
                        <?php esc_html_e('Allow going over the max', 'eventcrew'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Ticked: the sales desk can still add people, and shows a warning each time. Unticked: adding stops at the max.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Welcome message', 'eventcrew'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Send the welcome message', 'eventcrew'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="welcome_enabled" value="1" <?php checked($welcome_enabled); ?>>
                        <?php esc_html_e('On', 'eventcrew'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('How tasks, tickets, credits and standing work, by email and Telegram, once to each member. Organizers also get the organizer guide. New members get it when they confirm their email; everyone else from the hourly run, inside the notification hours, 25 people per hour. Use "Send me the welcome message" below to check it first.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-maintainer-contact"><?php esc_html_e('Maintainer contact', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="text"
                        class="regular-text"
                        id="eventcrew-maintainer-contact"
                        name="maintainer_contact"
                        value="<?php echo esc_attr($maintainer_contact); ?>"
                        placeholder="<?php echo esc_attr(\EventCrew\Support\WelcomeSender::maintainerDefault()); ?>"
                    >
                    <p class="description">
                        <?php esc_html_e('Whom to contact when the bot cannot be reached, for example an email address and a Telegram name. Shown in the welcome message. Empty: the text in the box.', 'eventcrew'); ?>
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
                        <?php esc_html_e('A member’s recency-weighted score runs from 0 to 1. At or above this they are in good standing, below it they are at risk. People with fewer than three finished tasks are unrated. Default 0.6.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Outcome weights', 'eventcrew'); ?></th>
                <td>
                    <details>
                        <summary style="cursor:pointer"><?php esc_html_e('Adjust what each outcome is worth (advanced)', 'eventcrew'); ?></summary>
                        <div style="margin-top:.6em">
                            <?php foreach ($reputation_weights as $eventcrew_weight) : ?>
                                <p style="margin:.2em 0">
                                    <label style="display:inline-block;min-width:12em">
                                        <?php echo esc_html($eventcrew_weight['label']); ?>
                                    </label>
                                    <input
                                        type="number"
                                        name="reputation_weight[<?php echo esc_attr($eventcrew_weight['status']); ?>]"
                                        value="<?php echo esc_attr((string) $eventcrew_weight['percent']); ?>"
                                        min="0"
                                        max="100"
                                        step="1"
                                        style="width:5em"> %
                                </p>
                            <?php endforeach; ?>
                            <p class="description">
                                <?php esc_html_e('What each finished task is worth toward the score, 0–100%. Recent tasks still count for more than old ones. Defaults: completed 100, replacement found 80, cancelled late 40, no-show 0.', 'eventcrew'); ?>
                            </p>
                        </div>
                    </details>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-tasks-per-credit"><?php esc_html_e('Tasks per credit', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input type="number" id="eventcrew-tasks-per-credit" name="tasks_per_credit"
                        value="<?php echo esc_attr((string) $tasks_per_credit); ?>" min="1" step="1" style="width:5em">
                    <p class="description">
                        <?php esc_html_e('Completed tasks a member works to earn one free-entry credit. Default 2.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Extra tasks', 'eventcrew'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="extra_task_credit" value="1" <?php checked($extra_task_credit); ?>>
                        <?php esc_html_e('Credit for extra tasks on the same night', 'eventcrew'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('When on, every task a member does on a night after their first one earns one more free-entry credit, on top of the credits for completed tasks. It shows as pending until the after-event run adds it, and only for tasks marked completed or arrived late. A cancelled or no-show task gives nothing.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal. ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-min-rated-tasks"><?php esc_html_e('Rated after', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input type="number" id="eventcrew-min-rated-tasks" name="min_rated_tasks"
                        value="<?php echo esc_attr((string) $min_rated_tasks); ?>" min="1" step="1" style="width:5em">
                    <?php esc_html_e('finished tasks', 'eventcrew'); ?>
                    <p class="description">
                        <?php esc_html_e('Below this a member is “New” (unrated); at or above it they are scored. Default 3.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-half-life"><?php esc_html_e('Recency half-life', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input type="number" id="eventcrew-half-life" name="reputation_half_life"
                        value="<?php echo esc_attr((string) $reputation_half_life); ?>" min="1" step="1" style="width:6em">
                    <?php esc_html_e('days', 'eventcrew'); ?>
                    <p class="description">
                        <?php esc_html_e('An outcome this many days old counts half as much toward the score as a same-day one. Default 180.', 'eventcrew'); ?>
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

        </div>
        <div class="ec-tab-panel" data-ec-tab="web">
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="eventcrew-organization-name"><?php esc_html_e('Organization name', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="text"
                        id="eventcrew-organization-name"
                        name="organization_name"
                        value="<?php echo esc_attr($organization_name); ?>"
                        class="regular-text"
                        maxlength="<?php echo (int) \EventCrew\Support\Organization::MAX_LENGTH; ?>"
                        placeholder="<?php echo esc_attr((string) get_bloginfo('name')); ?>">
                    <p class="description"><?php esc_html_e('The name members see on the ticket, in emails and Telegram messages, in the bot and on the public pages. In message texts and the ticket note it is {organization}. Leave it blank to use the site name. wp-admin keeps saying EventCrew.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Public signup page', 'eventcrew'); ?></h2>
        <p class="description">
            <?php
            printf(
                /* translators: %s: the shortcode, already wrapped in <code> */
                esc_html__('Add the open-task board to any page with the %s shortcode, or the "EventCrew signup" block. Visitors sign in by email — no account needed — and signing up respects the same rules as the bot.', 'eventcrew'),
                '<code>[eventcrew_signup]</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal.
            );
            ?>
        </p>

        <h2><?php esc_html_e('Spam protection', 'eventcrew'); ?></h2>
        <p class="description">
            <?php
            printf(
                /* translators: %s: the Cloudflare Turnstile dashboard URL, already wrapped in an anchor tag */
                esc_html__('Add a Cloudflare Turnstile CAPTCHA to the public sign-in form so bots cannot use it to send sign-in emails. Create a free widget at %s and paste its two keys here. Leave both blank to turn it off.', 'eventcrew'),
                '<a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener">dash.cloudflare.com</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal.
            );
            ?>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="eventcrew-turnstile-site-key"><?php esc_html_e('Site key', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="text"
                        id="eventcrew-turnstile-site-key"
                        name="turnstile_site_key"
                        value="<?php echo esc_attr($turnstile_site_key); ?>"
                        class="regular-text"
                        autocomplete="off"
                        spellcheck="false">
                    <p class="description"><?php esc_html_e('The public key that renders the widget. Safe to expose in the page.', 'eventcrew'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-turnstile-secret"><?php esc_html_e('Secret key', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="password"
                        id="eventcrew-turnstile-secret"
                        name="turnstile_secret"
                        value=""
                        placeholder="<?php echo esc_attr($turnstile_secret); ?>"
                        class="regular-text"
                        autocomplete="new-password"
                        spellcheck="false">
                    <?php if ('' !== $turnstile_secret) : ?>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: four dots and the last 4 characters of the saved secret */
                                esc_html__('Saved: %s. Leave the field empty to keep it.', 'eventcrew'),
                                '<code>' . esc_html($turnstile_secret) . '</code>'
                            );
                            ?>
                        </p>
                        <p>
                            <label>
                                <input type="checkbox" name="turnstile_secret_remove" value="1">
                                <?php esc_html_e('Remove the saved secret key', 'eventcrew'); ?>
                            </label>
                        </p>
                    <?php endif; ?>
                    <p class="description"><?php esc_html_e('Kept private on your server; used to verify each submission with Cloudflare.', 'eventcrew'); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Mobile app', 'eventcrew'); ?></h2>
        <p class="description">
            <?php esc_html_e('Turn the signup page into an installable app. Pick the page with the shortcode, then open it on a phone and choose “Add to Home Screen” (iPhone) or “Install app” (Android).', 'eventcrew'); ?>
        </p>
        <?php if ('' !== $orphan_signup_page) : ?>
            <div class="notice notice-warning inline" style="margin:1em 0">
                <p>
                    <?php
                    printf(
                        /* translators: %s: the title of the page holding the signup shortcode */
                        esc_html__('The page “%s” has the signup shortcode on it, but no page is picked below. Pick it: the setting does more than the mobile app.', 'eventcrew'),
                        esc_html($orphan_signup_page)
                    );
                    ?>
                </p>
                <p>
                    <?php esc_html_e('While it is unset, the “manage your data” link at the foot of every email cannot point at your signup page, so it falls back to a plain standalone page reached by a link that never expires. Picking the page below switches those links to one-time ones that stop working after 30 minutes.', 'eventcrew'); ?>
                </p>
            </div>
        <?php endif; ?>
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
                    <p class="description">
                        <?php esc_html_e('Also where the “manage your data” link in your emails sends people. With a page picked they get a one-time link good for 30 minutes; with none, a standalone page on a link that never expires.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-app-name"><?php esc_html_e('App name', 'eventcrew'); ?></label>
                </th>
                <td>
                    <?php
                    \EventCrew\Support\TranslatedText::field(
                        'app_name',
                        'eventcrew-app-name',
                        $app_name,
                        0,
                        false,
                        get_bloginfo('name'),
                        'text.app_name'
                    );
                    ?>
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
                    <p class="description"><?php esc_html_e('The Crew app page background, the email buttons, and the fallback icon’s background.', 'eventcrew'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-app-accent"><?php esc_html_e('Accent colour', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="color"
                        id="eventcrew-app-accent"
                        name="app_accent_color"
                        value="<?php echo esc_attr($app_accent_color); ?>">
                    <p class="description"><?php esc_html_e('The selected tab and the outline of the field you type in. Pick a colour that stands out from the theme colour.', 'eventcrew'); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Ticket page', 'eventcrew'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="eventcrew-ticket-background"><?php esc_html_e('Ticket background', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="url"
                        id="eventcrew-ticket-background"
                        name="ticket_background"
                        value="<?php echo esc_attr($ticket_background); ?>"
                        class="large-text"
                        placeholder="https://…"
                        autocomplete="off"
                        spellcheck="false">
                    <p class="description"><?php esc_html_e('The URL of an image, such as your site’s background picture, shown faintly behind the ticket. Leave it blank for a plain white ticket.', 'eventcrew'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Giving tickets away', 'eventcrew'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="ticket_giving" value="1" <?php checked($ticket_giving); ?>>
                        <?php esc_html_e('Members can give away their ticket', 'eventcrew'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('When on, a member can give a free-entry ticket bought with credits to another member or to an email address, from the ticket page or the bot. When off, the Give away links and buttons are hidden and old ones are refused. Tickets already given stay valid.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal. ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-ticket-note"><?php esc_html_e('Ticket note', 'eventcrew'); ?></label>
                </th>
                <td>
                    <?php
                    \EventCrew\Support\TranslatedText::field(
                        'ticket_note',
                        'eventcrew-ticket-note',
                        $ticket_note,
                        0,
                        true,
                        '',
                        'text.ticket_note'
                    );
                    ?>
                    <p class="description"><?php esc_html_e('Text shown on every ticket, under the VALID line, such as the door code and the address. Line breaks are kept. Left blank, the ticket shows only when the doors close, if it knows.', 'eventcrew'); ?></p>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: the placeholders, like {organization}, {start_time} */
                            esc_html__('Placeholders: %s. A line with an empty placeholder is left out.', 'eventcrew'),
                            '<code>' . esc_html(implode(', ', \EventCrew\Support\TicketNote::TAGS)) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here.
                        );
                        ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Doors close', 'eventcrew'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="doors_close_on" value="1" <?php checked($doors_close_on); ?>>
                        <?php
                        printf(
                            /* translators: %s: a number box for the minutes, like 30 */
                            esc_html__('Doors close %s minutes after the start', 'eventcrew'),
                            '<input type="number" name="doors_close_minutes" min="0" max="' . (int) \EventCrew\Support\EventTimes::MAX_DOORS_MINUTES . '" step="5" class="small-text" value="' . (int) $doors_close_minutes . '">' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,Generic.Files.LineLength.TooLong -- integers only.
                        );
                        ?>
                    </label>
                    <p class="description"><?php esc_html_e('In the ticket note and the message texts it is {doors_close}, like 19:30. A time set for one event on the Messages page, Coming events tab, wins.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal. ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="eventcrew-default-venue"><?php esc_html_e('Default venue', 'eventcrew'); ?></label></th>
                <td>
                    <input type="text" name="default_venue" id="eventcrew-default-venue" class="regular-text"
                        maxlength="<?php echo (int) \EventCrew\Support\EventVenue::MAX_LENGTH; ?>"
                        value="<?php echo esc_attr((string) ($default_venue ?? '')); ?>">
                    <p class="description"><?php esc_html_e('Shown as “📍 venue” on the ticket and in the reminder, sign-up and “A spot opened” messages ({venue}). It replaces the venue from EventMesh. A venue set for one event on the Messages page, Coming events tab, wins. Blank: the venue from EventMesh, if any.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal. ?></p>
                    <p>
                        <label>
                            <input type="checkbox" name="venue_ai" value="1" <?php checked(! empty($venue_ai)); ?>>
                            <?php esc_html_e('Read the venue from the event description with AI', 'eventcrew'); ?>
                        </label>
                    </p>
                    <p class="description"><?php esc_html_e('For a coming event with no venue of its own, the language model reads the venue from the event’s description, once per event, in the background. What it finds is saved as the event’s own venue on the Messages page, Coming events tab, where you can change or clear it. It uses the language model set up on the Telegram bot tab, under “Plain messages (language model)”.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal. ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-infrastructure-note"><?php esc_html_e('Infrastructure', 'eventcrew'); ?></label>
                </th>
                <td>
                    <?php
                    \EventCrew\Support\TranslatedText::field(
                        'infrastructure_note',
                        'eventcrew-infrastructure-note',
                        $infrastructure_note,
                        0,
                        true,
                        '',
                        'text.infrastructure_note'
                    );
                    ?>
                    <p class="description"><?php esc_html_e('How this site is run: where it is hosted, the workarounds it needs, how email comes and goes, outside services. Shown to organizers only, in the Help tab of the Crew app. A line starting with "## " is a heading, lines starting with "- " are a list, and an empty line starts a new paragraph. Do not put passwords or keys here. Leave it blank to show nothing.', 'eventcrew'); ?></p>
                </td>
            </tr>
        </table>

        </div>
        <div class="ec-tab-panel" data-ec-tab="privacy">
        <h2><?php esc_html_e('Who is responsible for the data', 'eventcrew'); ?></h2>
        <p class="description">
            <?php esc_html_e('EventCrew holds real people’s email addresses and a record of what they did for you, which makes whoever runs these events the data controller for it. Fill this in and the plugin writes the privacy notice for you — the public page, and the suggested text for WordPress’s own privacy policy — describing what this install actually does, including whether the Telegram bot and the Cloudflare check are in use.', 'eventcrew'); ?>
        </p>
        <?php if (! $privacy['configured']) : ?>
            <div class="notice notice-warning inline" style="margin:1em 0">
                <p>
                    <?php esc_html_e('The privacy notice is not published yet. Until an organisation and a contact address are filled in below, the public page stays blank and nothing is added to your site’s privacy policy — a notice that cannot say who is answerable would be worse than none.', 'eventcrew'); ?>
                </p>
            </div>
        <?php endif; ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="eventcrew-privacy-controller"><?php esc_html_e('Organisation', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="text"
                        id="eventcrew-privacy-controller"
                        name="privacy_controller"
                        value="<?php echo esc_attr($privacy['controller']); ?>"
                        class="regular-text"
                        placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
                    <p class="description"><?php esc_html_e('The club, association or person answerable for these records. Required.', 'eventcrew'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-privacy-contact"><?php esc_html_e('Contact address', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="text"
                        id="eventcrew-privacy-contact"
                        name="privacy_contact"
                        value="<?php echo esc_attr($privacy['contact']); ?>"
                        class="regular-text">
                    <p class="description"><?php esc_html_e('Where people write about their data. Required, and it must be an address somebody actually reads — a request has to be answered within a month.', 'eventcrew'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-privacy-address"><?php esc_html_e('Postal address', 'eventcrew'); ?></label>
                </th>
                <td>
                    <textarea
                        id="eventcrew-privacy-address"
                        name="privacy_address"
                        rows="3"
                        class="large-text code"><?php echo esc_textarea($privacy['address']); ?></textarea>
                    <p class="description"><?php esc_html_e('Optional, and left out of the notice when blank.', 'eventcrew'); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Data protection officer', 'eventcrew'); ?></h2>
        <p class="description">
            <?php esc_html_e('Most event organisers do not need one. A DPO is required only of public authorities, of anyone monitoring people systematically on a large scale, and of anyone handling special-category data at scale — none of which is what this plugin does. Leave both blank and the notice says so plainly, and points people at the contact address above. Fill in a contact and it is published as the officer’s, which is what makes a designation meaningful.', 'eventcrew'); ?>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="eventcrew-privacy-dpo-name"><?php esc_html_e('Officer’s name', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="text"
                        id="eventcrew-privacy-dpo-name"
                        name="privacy_dpo_name"
                        value="<?php echo esc_attr($privacy['dpo_name']); ?>"
                        class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-privacy-dpo-contact"><?php esc_html_e('Officer’s contact', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="text"
                        id="eventcrew-privacy-dpo-contact"
                        name="privacy_dpo_contact"
                        value="<?php echo esc_attr($privacy['dpo_contact']); ?>"
                        class="regular-text">
                    <p class="description"><?php esc_html_e('An address or phone number people can use directly. This is the field that decides whether a DPO is published at all.', 'eventcrew'); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('How long records are kept', 'eventcrew'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="eventcrew-privacy-retention"><?php esc_html_e('Retention period', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="number"
                        id="eventcrew-privacy-retention"
                        name="privacy_retention_years"
                        value="<?php echo esc_attr((string) $privacy['retention_years']); ?>"
                        min="<?php echo esc_attr((string) $privacy['retention_min']); ?>"
                        max="<?php echo esc_attr((string) $privacy['retention_max']); ?>"
                        step="1"
                        style="width:6em">
                    <?php esc_html_e('years after someone’s last activity', 'eventcrew'); ?>
                    <p class="description"><?php esc_html_e('Counted from the last task they were on, credit they were given, or credit they spent — or from the day they joined if none of those ever happened. This is what the notice publishes.', 'eventcrew'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Enforce it', 'eventcrew'); ?></th>
                <td>
                    <label for="eventcrew-privacy-purge">
                        <input
                            type="checkbox"
                            id="eventcrew-privacy-purge"
                            name="privacy_purge"
                            value="1"
                            <?php checked($privacy['purge']); ?>>
                        <?php esc_html_e('Anonymise expired records automatically', 'eventcrew'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('The hourly heartbeat strips anything past the period above: email address, name, notes and any Telegram link are erased for good, while the attendance rows stay, so “nine people worked that night” survives with nobody attached to it. Organizers are never touched while they hold the role.', 'eventcrew'); ?>
                    </p>
                    <p class="description">
                        <strong><?php esc_html_e('This cannot be undone.', 'eventcrew'); ?></strong>
                        <?php esc_html_e('Leave it off and the period is a promise you keep by hand — which is a perfectly good answer, and the one to pick if you are unsure.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('The public notice', 'eventcrew'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="eventcrew-privacy-page"><?php esc_html_e('Privacy page', 'eventcrew'); ?></label>
                </th>
                <td>
                    <?php
                    wp_dropdown_pages([
                        'name' => 'privacy_page_id',
                        'id' => 'eventcrew-privacy-page',
                        'selected' => $privacy['page_id'],
                        'show_option_none' => __('— None —', 'eventcrew'),
                        'option_none_value' => '0',
                    ]);
                    ?>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: the shortcode, already wrapped in <code> */
                            esc_html__('The page carrying the %s shortcode or the “EventCrew privacy notice” block. Signing-up links to it. If you have no such page yet, there is a button below that makes one.', 'eventcrew'),
                            '<code>[eventcrew_privacy]</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal.
                        );
                        ?>
                    </p>
                    <?php if ('' !== $privacy['notice_url']) : ?>
                        <p class="description">
                            <a href="<?php echo esc_url($privacy['notice_url']); ?>" target="_blank" rel="noopener">
                                <?php esc_html_e('View the notice people are shown', 'eventcrew'); ?>
                            </a>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-privacy-buyer-page"><?php esc_html_e('Ticket buyer privacy page', 'eventcrew'); ?></label>
                </th>
                <td>
                    <?php
                    wp_dropdown_pages([
                        'name' => 'privacy_buyer_page_id',
                        'id' => 'eventcrew-privacy-buyer-page',
                        'selected' => $privacy['buyer_page_id'],
                        'show_option_none' => __('— None —', 'eventcrew'),
                        'option_none_value' => '0',
                    ]);
                    ?>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: 1: the shortcode, already wrapped in <code>, 2: number of days */
                            esc_html__('The page carrying the %1$s shortcode: the public notice for people who bought a ticket in the web shop. It says what is taken from the shop\'s order export, that buyers can type a dash instead of their address in the shop, that they are emailed a thank-you message with their ticket after the import, and that their email address, name and ticket code are deleted %2$d days after the event unless they joined the next events announcement. Link to it from your shop.', 'eventcrew'),
                            '<code>[eventcrew_ticket_privacy]</code>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal.
                            (int) \EventCrew\Support\PrivacyPolicy::BUYER_EMAIL_DAYS
                        );
                        ?>
                    </p>
                    <?php if (0 === $privacy['buyer_page_id']) : ?>
                        <p class="description">
                            <a href="<?php echo esc_url($privacy['create_buyer_url']); ?>">
                                <?php esc_html_e('Create the ticket buyer privacy page', 'eventcrew'); ?>
                            </a>
                        </p>
                    <?php elseif ('' !== $privacy['buyer_notice_url']) : ?>
                        <p class="description">
                            <a href="<?php echo esc_url($privacy['buyer_notice_url']); ?>" target="_blank" rel="noopener">
                                <?php esc_html_e('View the ticket buyer notice', 'eventcrew'); ?>
                            </a>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        </div>
        <div class="ec-tab-panel" data-ec-tab="email">
        <h2><?php esc_html_e('Notification emails', 'eventcrew'); ?></h2>
        <p class="description">
            <?php esc_html_e('Every notification goes out as a designed HTML email with your logo on top and a button for whatever it is asking people to do — show a ticket, sign in, take an open slot. A plain-text copy travels with each one, so a mail client that will not show HTML still gets the whole message.', 'eventcrew'); ?>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="eventcrew-signature"><?php esc_html_e('Signature', 'eventcrew'); ?></label>
                </th>
                <td>
                    <?php
                    \EventCrew\Support\TranslatedText::field(
                        'signature',
                        'eventcrew-signature',
                        $signature,
                        0,
                        true,
                        __('Barefoot and with ♥ — the Example crew', 'eventcrew'),
                        'text.signature'
                    );
                    ?>
                    <p class="description">
                        <?php esc_html_e('In emails the sign-off is centred under the message: the first line in italics, the next lines in gold. One line is split after its first comma, so “With love, the crew” becomes two lines.', 'eventcrew'); ?>
                    </p>
                    <p class="description">
                        <?php esc_html_e('How your crew signs off. It closes every notification email, and every Telegram message the person did not just ask for: reminders, calls, and news about their tasks, standing or access. Leave it blank for no sign-off.', 'eventcrew'); ?>
                    </p>
                    <p class="description">
                        <?php esc_html_e('Not added to the bot’s direct reply to something the person just did, such as a sign-up or cancellation confirmation: they are mid-conversation.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Format', 'eventcrew'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="email_html" value="1" <?php checked($email_html); ?>>
                        <?php esc_html_e('Send notifications as HTML', 'eventcrew'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('Turn this off to send plain text only.', 'eventcrew'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-email-logo"><?php esc_html_e('Logo', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="url"
                        id="eventcrew-email-logo"
                        name="email_logo"
                        value="<?php echo esc_attr($email_logo); ?>"
                        class="large-text"
                        placeholder="https://…"
                        autocomplete="off"
                        spellcheck="false">
                    <p class="description">
                        <?php esc_html_e('The image at the top of every email. Leave this blank to use your site’s own logo, falling back to the Site Icon, then to your site’s name in text. Paste a URL here to use a different image — a wide banner works as well as a square logo.', 'eventcrew'); ?>
                        <?php esc_html_e('The image is linked, so the mail client fetches it from wherever it is hosted when the email is opened. If it does not appear, check that the file is reachable without being logged in — some hosts refuse to serve images to anything that is not a browser.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-email-background"><?php esc_html_e('Background picture', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="url"
                        id="eventcrew-email-background"
                        name="email_background"
                        value="<?php echo esc_attr($email_background); ?>"
                        class="large-text"
                        placeholder="https://…"
                        autocomplete="off"
                        spellcheck="false">
                    <p class="description">
                        <?php esc_html_e('The picture behind the whole email, around the card. Use a JPEG that is already darkened, so the light logo and writing on it stay readable. Leave this blank for the purple fade alone; the fade is also what shows when a mail client does not load the picture.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Design', 'eventcrew'); ?></th>
                <td>
                    <a href="<?php echo esc_url($email_edit_url); ?>" class="button">
                        <?php esc_html_e('Edit the email template', 'eventcrew'); ?>
                    </a>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: the list of merge tags, already wrapped in <code> */
                            esc_html__('Opens the template in the normal editor, with revisions and undo. It is the frame around each message, not the wording: keep %s where the message should appear, and use the other tags wherever you want them.', 'eventcrew'),
                            '<code>{{content}}</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal.
                        );
                        ?>
                    </p>
                    <p class="description">
                        <?php esc_html_e('Available tags:', 'eventcrew'); ?>
                        <code>{{content}}</code> <code>{{logo}}</code> <code>{{tagline}}</code>
                        <code>{{backdrop}}</code> <code>{{signature}}</code> <code>{{footer}}</code>
                        <code>{{accent}}</code> <code>{{site_name}}</code> <code>{{site_url}}</code>
                        <code>{{subject}}</code> <code>{{year}}</code>
                    </p>
                    <p class="description">
                        <?php esc_html_e('The built-in template draws the background picture (or the purple fade), the logo and the site’s tagline (Settings > General) above a cream card, the sign-off centred at the foot of the card, and the site name at the bottom. Buttons are white with a gold glow, and headings are gold. {{accent}} gives the colour set under “Web page”, for a template of your own that wants it.', 'eventcrew'); ?>
                    </p>
                    <p class="description">
                        <?php esc_html_e('Your template keeps whatever design it was created with. Use “Reset the template” below to take up the current built-in one.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Ticket email', 'eventcrew'); ?></h2>
        <p>
            <?php esc_html_e('The ticket email text and its {reason} lines are edited on the Messages page:', 'eventcrew'); ?>
            <a href="<?php echo esc_url(\EventCrew\Admin\MessagesPage::url(\EventCrew\Admin\MessagesPage::TAB_MESSAGES, ['kind' => \EventCrew\Admin\MessagesPage::TICKET_KIND], 'ec-message-' . \EventCrew\Admin\MessagesPage::TICKET_KIND)); // phpcs:ignore Generic.Files.LineLength.TooLong ?>"><?php esc_html_e('Messages > Ticket (email)', 'eventcrew'); ?></a>
        </p>

        <h2><?php esc_html_e('Hybrid messages', 'eventcrew'); ?></h2>
        <?php if (! $hybrid['allowed']) : ?>
            <p class="description" style="color:#b32d2e"><?php echo esc_html($hybrid['warning']); ?></p>
        <?php endif; ?>
        <?php if ('' !== $hybrid['buttons_warning']) : ?>
            <p class="description" style="color:#996800"><?php echo esc_html($hybrid['buttons_warning']); ?></p>
        <?php endif; ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="eventcrew-languages-shown"><?php esc_html_e('Languages shown in multi-language texts and buttons', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong ?></label>
                </th>
                <td>
                    <input type="number" id="eventcrew-languages-shown" name="languages_shown" class="small-text"
                        min="1" max="<?php echo esc_attr((string) $hybrid['shown_max']); ?>" step="1"
                        value="<?php echo esc_attr((string) $hybrid['shown']); ?>">
                    <p class="description"><?php esc_html_e('Telegram, emails and web pages. The reader’s own language first, then the next ones in the site’s order. Default 2.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal. ?></p>
                </td>
            </tr>
        </table>
        <table class="widefat striped" style="max-width:40em;margin-bottom:1.5em">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Message', 'eventcrew'); ?></th>
                    <th scope="col">
                        <?php esc_html_e('Hybrid', 'eventcrew'); ?>
                        <details style="display:inline-block;vertical-align:top">
                            <summary style="cursor:pointer;list-style:none" aria-label="<?php esc_attr_e('What is hybrid?', 'eventcrew'); ?>">
                                <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                            </summary>
                            <p style="max-width:24em;font-weight:400"><?php esc_html_e('One message in all site languages, one part per language, each part starting with its short language code (FI, EN). For people whose language is not known.', 'eventcrew'); ?></p>
                            <p style="max-width:24em;font-weight:400"><?php esc_html_e('With the tick off, each person gets the message in their own language only, and people whose language is not known get it in the main language.', 'eventcrew'); ?></p>
                        </details>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($hybrid['kinds'] as $ec_kind => $ec_hybrid) : ?>
                    <tr<?php echo $hybrid['allowed'] ? '' : ' style="opacity:.55"'; ?>>
                        <td><label for="eventcrew-hybrid-<?php echo esc_attr($ec_kind); ?>"><?php echo esc_html($ec_hybrid['label']); ?></label></td>
                        <td>
                            <input type="checkbox" id="eventcrew-hybrid-<?php echo esc_attr($ec_kind); ?>"
                                name="hybrid[<?php echo esc_attr($ec_kind); ?>]" value="1"
                                <?php checked($ec_hybrid['ticked']); ?>
                                <?php disabled(! $hybrid['allowed']); ?>>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2><?php esc_html_e('Post-event email', 'eventcrew'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Send the post-event email', 'eventcrew'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="followup_enabled" value="1"
                            <?php checked($followup_enabled); ?>>
                        <?php esc_html_e('On', 'eventcrew'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Edit the text on the Messages page:', 'eventcrew'); ?>
                        <?php
                        $eventcrew_links = [];

                        foreach (['followup_came', 'followup_missed'] as $eventcrew_message_id) {
                            $eventcrew_message = \EventCrew\Support\MessageTemplates::find($eventcrew_message_id);

                            if (null !== $eventcrew_message) {
                                $eventcrew_links[] = '<a href="' . esc_url(\EventCrew\Admin\MessagesPage::url(
                                    \EventCrew\Admin\MessagesPage::TAB_MESSAGES,
                                    ['kind' => $eventcrew_message_id],
                                    'ec-message-' . $eventcrew_message_id
                                )) . '">' . esc_html($eventcrew_message->label) . '</a>';
                            }
                        }

                        echo implode(' · ', $eventcrew_links); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each link is escaped above.
                        ?>
                    </p>
                    <p class="description">
                        <?php esc_html_e('Off by default. When it is on, everyone who had a ticket to an event and gave an email address gets a short email the day after at 12:00: a thank-you if they were checked in at the door, and a kind “sorry we missed you” if they were not. Both show the next three events. The day before the event at 12:00, every organizer gets a preview with a Stop button; the Roster page has the same button.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                    </p>
                    <p class="description">
                        <?php esc_html_e('It starts with the first event whose day begins after you switch it on. Events that are already over, or going on, at that moment get no email.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                    </p>
                    <p class="description">
                        <?php esc_html_e('Every link in it carries utm_source=eventcrew, utm_medium=email and utm_campaign=thanks-YYYY-MM-DD or missed-YYYY-MM-DD, so your shop’s and your site’s statistics can tell which sales came from these emails. The Roster page shows how many people opened a link.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                    </p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Next events announcement', 'eventcrew'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Send the next events announcement', 'eventcrew'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="newsletter_enabled" value="1"
                            <?php checked($newsletter_enabled); ?>>
                        <?php esc_html_e('On', 'eventcrew'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Edit the text on the Messages page:', 'eventcrew'); ?>
                        <?php
                        $eventcrew_links = [];

                        foreach (['newsletter'] as $eventcrew_message_id) {
                            $eventcrew_message = \EventCrew\Support\MessageTemplates::find($eventcrew_message_id);

                            if (null !== $eventcrew_message) {
                                $eventcrew_links[] = '<a href="' . esc_url(\EventCrew\Admin\MessagesPage::url(
                                    \EventCrew\Admin\MessagesPage::TAB_MESSAGES,
                                    ['kind' => $eventcrew_message_id],
                                    'ec-message-' . $eventcrew_message_id
                                )) . '">' . esc_html($eventcrew_message->label) . '</a>';
                            }
                        }

                        echo implode(' · ', $eventcrew_links); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each link is escaped above.
                        ?>
                    </p>
                    <p class="description">
                        <?php esc_html_e('Off by default. When it is on, the coming events go by email to everyone who joined the next events announcement and confirmed their address. People join with the [eventcrew_newsletter] shortcode. Before sending, every organizer gets a preview email with a Stop button; the crew app’s Admin tab has the same button.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Send the preview', 'eventcrew'); ?></th>
                <td>
                    <?php $ec_schedule = \EventCrew\Support\NewsletterSchedule::class; ?>
                    <fieldset>
                        <label>
                            <input type="radio" name="newsletter_preview_mode"
                                value="<?php echo esc_attr($ec_schedule::PREVIEW_AFTER_PREVIOUS); ?>"
                                <?php checked($newsletter_preview_mode, $ec_schedule::PREVIEW_AFTER_PREVIOUS); ?>>
                            <input type="number" name="newsletter_preview_hours"
                                min="0" max="<?php echo esc_attr((string) $ec_schedule::MAX_PREVIEW_HOURS); ?>"
                                step="1" class="small-text"
                                aria-label="<?php esc_attr_e('Hours', 'eventcrew'); ?>"
                                value="<?php echo esc_attr((string) $newsletter_preview_hours); ?>">
                            <?php esc_html_e('hours after the previous event ends', 'eventcrew'); ?>
                        </label>
                        <br>
                        <label>
                            <input type="radio" name="newsletter_preview_mode"
                                value="<?php echo esc_attr($ec_schedule::PREVIEW_BEFORE_EVENT); ?>"
                                <?php checked($newsletter_preview_mode, $ec_schedule::PREVIEW_BEFORE_EVENT); ?>>
                            <input type="number" name="newsletter_preview_days"
                                min="<?php echo esc_attr((string) $ec_schedule::MIN_PREVIEW_DAYS); ?>"
                                max="<?php echo esc_attr((string) $ec_schedule::MAX_PREVIEW_DAYS); ?>"
                                step="1" class="small-text"
                                aria-label="<?php esc_attr_e('Days', 'eventcrew'); ?>"
                                value="<?php echo esc_attr((string) $newsletter_preview_days); ?>">
                            <?php esc_html_e('days before the event, at', 'eventcrew'); ?>
                            <input type="time" name="newsletter_preview_time"
                                aria-label="<?php esc_attr_e('Time', 'eventcrew'); ?>"
                                value="<?php echo esc_attr($newsletter_preview_time); ?>">
                        </label>
                    </fieldset>
                    <p class="description">
                        <?php esc_html_e('When every organizer gets the preview email. With no previous event, or a previous event with no end time, the second choice is used. A preview time that is not before the sending time sends the preview one day before the sending. Times are in the site time zone and are checked once an hour.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="ec-newsletter-days"><?php esc_html_e('Send the newsletter', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input type="number" id="ec-newsletter-days" name="newsletter_days"
                        min="1" max="60" step="1" class="small-text"
                        value="<?php echo esc_attr((string) $newsletter_days); ?>">
                    <?php esc_html_e('days before the event, at', 'eventcrew'); ?>
                    <input type="time" name="newsletter_send_time"
                        aria-label="<?php esc_attr_e('Time', 'eventcrew'); ?>"
                        value="<?php echo esc_attr($newsletter_send_time); ?>">
                    <p class="description">
                        <?php esc_html_e('How many days before each event the next events announcement goes out. Events that fall in the same window go in one announcement.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Add crew members to the newsletter', 'eventcrew'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="newsletter_crew" value="1"
                            <?php checked($newsletter_crew); ?>>
                        <?php esc_html_e('On', 'eventcrew'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Texts by the language model', 'eventcrew'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="newsletter_write" value="1"
                            <?php checked($newsletter_write); ?>>
                        <?php esc_html_e('Let the language model write the event texts and, when you wrote none, the opening text', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('On by default. When it is on, each event in the announcement gets 2–3 sentences in each site language, written from the event’s description and its DJs’ descriptions, in place of the full description. The texts are written when the preview is made, so the preview shows what the subscribers get. If a text cannot be written, the event shows its full description.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                    </p>
                    <?php if (! $llm_configured) : ?>
                        <p class="description">
                            <?php esc_html_e('The language model is not set up. Enter its API URL and model under “Plain messages (language model)” below.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="ec-newsletter-words">
                        <?php esc_html_e('Words and style for written texts', 'eventcrew'); ?>
                    </label>
                </th>
                <td>
                    <textarea id="ec-newsletter-words" name="newsletter_words" rows="5" class="large-text"
                        maxlength="<?php echo (int) \EventCrew\Support\NewsletterWriter::MAX_WORDS; ?>"><?php
                        echo esc_textarea($newsletter_words);
                        ?></textarea>
                    <p class="description">
                        <?php esc_html_e('Words and phrases this site uses, in English and in Finnish, and how it speaks to its readers. The language model prefers them when it writes the texts. Leave it empty to let the model choose its own words.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Sign-up bar background image', 'eventcrew'); ?></th>
                <td>
                    <input type="hidden" id="ec-nl-bar-image" name="newsletter_bar_image"
                        value="<?php echo esc_attr((string) $newsletter_bar_image); ?>">
                    <img id="ec-nl-bar-image-preview" alt=""
                        src="<?php echo esc_url($newsletter_bar_image_url); ?>"
                        style="display:<?php echo '' !== $newsletter_bar_image_url ? 'block' : 'none'; ?>;max-width:320px;height:auto;margin-bottom:8px">
                    <button type="button" class="button" id="ec-nl-bar-image-choose">
                        <?php esc_html_e('Choose image', 'eventcrew'); ?>
                    </button>
                    <button type="button" class="button-link" id="ec-nl-bar-image-remove"
                        <?php echo '' === $newsletter_bar_image_url ? 'hidden' : ''; ?>>
                        <?php esc_html_e('Remove image', 'eventcrew'); ?>
                    </button>
                    <p class="description">
                        <?php esc_html_e('Shown behind the sign-up bar at the bottom of the public pages, under a dark layer so the text stays readable. Without an image the bar has a plain dark background.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                    </p>
                </td>
            </tr>
        </table>
        <script>
        // The Media Library picker for the sign-up bar's image. With JS off the
        // buttons do nothing and the saved image stays as it is.
        (function () {
            var choose = document.getElementById('ec-nl-bar-image-choose');
            if (!choose || !window.wp || !wp.media) { return; }
            var input = document.getElementById('ec-nl-bar-image');
            var preview = document.getElementById('ec-nl-bar-image-preview');
            var remove = document.getElementById('ec-nl-bar-image-remove');
            var frame = null;
            choose.addEventListener('click', function () {
                if (!frame) {
                    frame = wp.media({ library: { type: 'image' }, multiple: false });
                    frame.on('select', function () {
                        var image = frame.state().get('selection').first().toJSON();
                        var size = image.sizes && image.sizes.medium ? image.sizes.medium : image;
                        input.value = String(image.id);
                        preview.src = size.url;
                        preview.style.display = 'block';
                        remove.hidden = false;
                    });
                }
                frame.open();
            });
            remove.addEventListener('click', function () {
                input.value = '0';
                preview.removeAttribute('src');
                preview.style.display = 'none';
                remove.hidden = true;
            });
        })();
        </script>

        </div>
        <div class="ec-tab-panel" data-ec-tab="telegram">
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
                        type="password"
                        id="eventcrew-telegram-token"
                        name="telegram_bot_token"
                        value=""
                        placeholder="<?php echo esc_attr($telegram['token']); ?>"
                        class="regular-text"
                        autocomplete="new-password"
                        spellcheck="false">
                    <?php if ('' !== $telegram['token']) : ?>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: four dots and the last 4 characters of the saved secret */
                                esc_html__('Saved: %s. Leave the field empty to keep it.', 'eventcrew'),
                                '<code>' . esc_html($telegram['token']) . '</code>'
                            );
                            ?>
                        </p>
                        <p>
                            <label>
                                <input type="checkbox" name="telegram_bot_token_remove" value="1">
                                <?php esc_html_e('Remove the saved token (this disconnects the bot)', 'eventcrew'); ?>
                            </label>
                        </p>
                    <?php endif; ?>
                    <p class="description">
                        <?php esc_html_e('Kept private on your server. Only its last 4 characters are shown.', 'eventcrew'); ?>
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
            <?php require EVENTCREW_PLUGIN_DIR . 'templates/admin/settings-telegram-login.php'; ?>
            <tr>
                <th scope="row">
                    <label for="eventcrew-telegram-group-link"><?php esc_html_e('Group link', 'eventcrew'); ?></label>
                </th>
                <td>
                    <input
                        type="url"
                        id="eventcrew-telegram-group-link"
                        name="telegram_group_link"
                        value="<?php echo esc_attr($telegram['group_link']); ?>"
                        class="regular-text"
                        placeholder="https://t.me/yourgroup"
                        spellcheck="false">
                    <p class="description">
                        <?php esc_html_e('Optional. Your group’s invite link (t.me/yourgroup, or a t.me/+… private invite — copy it from Telegram’s group info). When there is a link, the web signup page, the open-task emails and the open-task DMs all offer a way straight into the group.', 'eventcrew'); ?>
                    </p>
                    <?php if ('' !== $telegram['group_link_auto']) : ?>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: the invite link discovered from Telegram, already wrapped in <code> */
                                esc_html__('Leave this blank and EventCrew uses the link it found by asking Telegram: %s. Anything you type here wins over it.', 'eventcrew'),
                                '<code style="word-break:break-all">' . esc_html($telegram['group_link_auto']) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
                            );
                            ?>
                        </p>
                    <?php else : ?>
                        <p class="description">
                            <?php esc_html_e('Left blank, EventCrew asks Telegram for one. That works for a public group, or a private one where the bot is an administrator; otherwise paste your own here.', 'eventcrew'); ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Group lock', 'eventcrew'); ?></th>
                <td>
                    <label>
                        <input
                            type="checkbox"
                            name="telegram_group_lock"
                            value="1"
                            <?php checked($telegram['group_lock']); ?>>
                        <?php esc_html_e('Keep the board locked to the first group the bot joins', 'eventcrew'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('On by default. While locked, adding the bot to another group — or running /board there — will not move the board, so no one can hijack it into a different chat. To move the board on purpose (for example, going live): untick this, save, add the bot to the new group (or run /board in it), then tick it again and save.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Private chats', 'eventcrew'); ?></th>
                <td>
                    <label>
                        <input
                            type="checkbox"
                            name="telegram_group_gate"
                            value="1"
                            <?php checked($telegram['group_gate']); ?>>
                        <?php esc_html_e('Only people in the crew group can start the bot', 'eventcrew'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('On by default. Someone who is not yet in the crew and not in the group gets no answer from the bot. If they open the bot from a link in an email or on the website, they get a button to the crew group. Members who are already set up are not affected. Turn this off if the bot cannot check who is in the group.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Event cards', 'eventcrew'); ?></th>
                <td>
                    <label>
                        <input
                            type="checkbox"
                            name="telegram_board_cards"
                            value="1"
                            <?php checked($telegram['board_cards']); ?>>
                        <?php esc_html_e('Show the group board as event cards', 'eventcrew'); ?>
                    </label>
                    <p class="description">
                        <?php
                        // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                        esc_html_e('Off by default. When on, the text board shows the next event, who is signed up for which task, the task buttons and the date buttons. The next event\'s picture is posted to the group on its own, once when it becomes the next event and again on its morning, never with /board: its picture, name, date, price, two sentences about the DJ or host written by the language model, and its links. Only for an EventMesh event with a picture.', 'eventcrew');
                        ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="eventcrew-board-banner-page"><?php esc_html_e('Board banner', 'eventcrew'); ?></label>
                </th>
                <td>
                    <?php
                    // wp_dropdown_pages() escapes its own output.
                    // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
                    wp_dropdown_pages([
                        'name' => 'telegram_board_banner_page',
                        'id' => 'eventcrew-board-banner-page',
                        'selected' => $telegram['board_banner_page'],
                        'show_option_none' => __('— None —', 'eventcrew'),
                        'option_none_value' => '0',
                    ]);
                    // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
                    ?>
                    <p class="description">
                        <?php
                        // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                        esc_html_e('A wide strip of this page\'s picture is shown above the board text in Telegram: its first cover block, around the cover\'s focal point, or else its featured image. Change the picture on the page and the banner follows.', 'eventcrew');
                        ?>
                    </p>
                </td>
            </tr>
            <?php
            // The bot's About and Description: written once, kept in every
            // language (BotProfileSettings). Saving sends each changed
            // language text to Telegram; an empty field clears it there.
            $eventcrew_profile_fields = [
                ['about', __('About', 'eventcrew'), 120, false],
                ['description', __('Description', 'eventcrew'), 512, true],
            ];
            ?>
            <?php foreach ($eventcrew_profile_fields as $eventcrew_pf) : ?>
                <?php
                [$eventcrew_pf_name, $eventcrew_pf_label, $eventcrew_pf_max, $eventcrew_pf_area] = $eventcrew_pf;
                $eventcrew_pf_id = 'eventcrew-telegram-' . $eventcrew_pf_name;
                ?>
                <tr>
                    <th scope="row">
                        <label for="<?php echo esc_attr($eventcrew_pf_id); ?>">
                            <?php echo esc_html($eventcrew_pf_label); ?>
                        </label>
                        <?php
                        // Sending the texts to Telegram runs in the background.
                        $eventcrew_pf_badge = 'about' === $eventcrew_pf_name
                            ? \EventCrew\Support\BackgroundJobs::badge(
                                \EventCrew\Support\BackgroundJobs::latest(\EventCrew\Admin\BotProfileSettings::JOB_TARGET)
                            )
                            : '';
                        if ('' !== $eventcrew_pf_badge) {
                            echo '<br>' . $eventcrew_pf_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
                        }
                        ?>
                    </th>
                    <td>
                        <?php
                        \EventCrew\Support\TranslatedText::field(
                            \EventCrew\Admin\BotProfileSettings::POST_NAME . '[' . $eventcrew_pf_name . ']',
                            $eventcrew_pf_id,
                            $telegram['profile'][$eventcrew_pf_name],
                            $eventcrew_pf_max,
                            $eventcrew_pf_area,
                            '',
                            'telegram.' . $eventcrew_pf_name
                        );
                        ?>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %d: the most characters Telegram allows in this text */
                                esc_html__('At most %d characters.', 'eventcrew'),
                                (int) $eventcrew_pf_max
                            );
                            ?>
                        </p>
                        <?php if ('description' === $eventcrew_pf_name) : ?>
                            <p class="description">
                                <?php esc_html_e('Telegram’s API has no setting that stops people adding the bot to other groups. To stop it, turn off Allow Groups in BotFather: /mybots → the bot → Bot Settings → Allow Groups?', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
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
                        <?php esc_html_e('Enable this if the webhook status shows “Wrong response from the webhook: 400/403”. Some hosts and security plugins block the /wp-json REST API for anonymous requests; this routes Telegram through admin-ajax.php, which is almost always left open. Saving re-installs the webhook for you. Leave off on a normal host.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <?php if ($telegram['use_fallback']) : ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Secret in the URL', 'eventcrew'); ?></th>
                    <td>
                        <label>
                            <input
                                type="checkbox"
                                name="telegram_secret_in_url"
                                value="1"
                                <?php checked($telegram['secret_in_url']); ?>>
                            <?php esc_html_e('Also send the webhook secret as a URL parameter', 'eventcrew'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Telegram always sends the secret as a header; this sends a second copy in the address as well. That is only needed on hosts or proxies that strip custom headers — and it means the secret is written into your server’s access log on every update the bot receives.', 'eventcrew'); ?>
                        </p>
                        <p class="description">
                            <?php esc_html_e('Turn it off if you can: expand “Test the endpoint by hand” below and send the header-only request first. A 200 means your host keeps the header and it is safe to untick this. Saving re-installs the webhook either way, and if the bot goes quiet, tick it again and save.', 'eventcrew'); ?>
                        </p>
                    </td>
                </tr>
            <?php endif; ?>
        </table>
        <script>
        // Live character counts for the bot's About and Description fields.
        // With JS off the counts stay empty and maxlength still holds the limit.
        (function () {
            document.querySelectorAll('.ec-char-count').forEach(function (count) {
                var field = document.getElementById(count.getAttribute('data-ec-for'));
                if (!field) { return; }
                function update() { count.textContent = field.value.length + ' / ' + field.maxLength + '.'; }
                field.addEventListener('input', update);
                update();
            });
        })();
        </script>
        <?php \EventCrew\Support\TranslatedText::script(); ?>

        <?php if ($telegram['secret_in_url']) : ?>
            <div class="notice notice-warning inline" style="margin:1em 0">
                <p>
                    <?php esc_html_e('The webhook secret is currently travelling in the URL, so it is in your server’s access logs. Anything that can read those logs can impersonate any member of your crew to the bot, organizers included.', 'eventcrew'); ?>
                </p>
                <p>
                    <?php esc_html_e('Untick the box above if the header-only test passes — and rotate the secret afterwards, since switching it off does not remove what is already in the logs.', 'eventcrew'); ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if ('' !== $telegram['test_url']) : ?>
            <details style="margin:1em 0">
                <summary><?php esc_html_e('Test the endpoint by hand', 'eventcrew'); ?></summary>
                <p class="description">
                    <?php esc_html_e('Send this exact POST to check what your server returns to Telegram (200 = good). A 400/403/404 here reproduces what Telegram sees, without waiting on the bot.', 'eventcrew'); ?>
                </p>
                <p class="description">
                    <?php esc_html_e('The webhook secret below is shown only by its last 4 characters. Put the full secret in its place to send the request.', 'eventcrew'); ?>
                </p>
                <p>
                    <strong><?php esc_html_e('Telegram is posting to:', 'eventcrew'); ?></strong><br>
                    <code style="word-break:break-all"><?php echo esc_html($telegram['test_url']); ?></code>
                </p>
                <?php if (! $telegram['secret_in_url']) : ?>
                    <p>
                        <strong><?php esc_html_e('With header:', 'eventcrew'); ?></strong><br>
                        <code>X-Telegram-Bot-Api-Secret-Token: <?php echo esc_html($telegram['secret']); ?></code>
                    </p>
                <?php endif; ?>
                <p>
                    <strong><?php esc_html_e('And this JSON body (Content-Type: application/json):', 'eventcrew'); ?></strong><br>
                    <code style="word-break:break-all"><?php echo esc_html(\EventCrew\Telegram\WebhookController::TEST_BODY); ?></code>
                </p>

                <?php if ('' !== $telegram['header_only_url']) : ?>
                    <?php
                    /*
                     * The same request with the secret moved out of the URL and
                     * into the header - exactly what this endpoint would receive
                     * once the box above is unticked. Trying it here answers the
                     * only question that matters ("does this host keep the
                     * header?") without changing anything Telegram is doing.
                     */
                    ?>
                    <hr style="margin:1.2em 0">
                    <p class="description">
                        <strong><?php esc_html_e('Before turning off “Secret in the URL”, try this:', 'eventcrew'); ?></strong><br>
                        <?php esc_html_e('The same request with the secret in the header instead of the address. A 200 means your host keeps the header, so the URL copy can be switched off safely. Anything else means leave it on.', 'eventcrew'); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e('POST to:', 'eventcrew'); ?></strong><br>
                        <code style="word-break:break-all"><?php echo esc_html($telegram['header_only_url']); ?></code>
                    </p>
                    <p>
                        <strong><?php esc_html_e('With header:', 'eventcrew'); ?></strong><br>
                        <code>X-Telegram-Bot-Api-Secret-Token: <?php echo esc_html($telegram['secret']); ?></code>
                    </p>
                <?php endif; ?>
            </details>
        <?php endif; ?>

        <h2 id="ec-assistant"><?php esc_html_e('Plain messages (language model)', 'eventcrew'); ?></h2>
        <p class="description">
            <?php esc_html_e('When this is on, the bot answers messages that are not commands with the help of a language model on a server you choose. Its API must accept OpenAI-style chat completions. In a private chat the bot answers every such message. In the group it answers only messages meant for the bot that it can answer, and stays silent otherwise. Changes (signing up for a task, leaving it, the guest list) always ask the person to confirm with a button first.', 'eventcrew'); ?>
        </p>
        <p class="description">
            <?php esc_html_e('Privacy: the text of these messages, whether the sender is an organizer, and the open tasks, events and chosen pages are sent to that server. EventCrew does not store the message text.', 'eventcrew'); ?>
        </p>
        <?php if (\EventCrew\Support\AssistantSettings::KEY_UNREADABLE === $assistant['key_status']) : ?>
            <div class="notice notice-error inline"><p>
                <?php esc_html_e('The stored API key cannot be decrypted (the site\'s security keys have changed). Enter the key again.', 'eventcrew'); ?>
            </p></div>
        <?php endif; ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Language model', 'eventcrew'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="assistant_enabled" value="1" <?php checked($assistant['enabled']); ?>>
                        <?php esc_html_e('Answer plain messages with the language model', 'eventcrew'); ?>
                    </label><br>
                    <label>
                        <input type="checkbox" name="assistant_forward" value="1"
                            <?php checked($assistant['forward']); ?>>
                        <?php esc_html_e('Send a private chat to the organizers when the person gives feedback, is unhappy or very happy, or asks for a human', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Each organizer gets the last 24 hours of that chat by email, and a short Telegram message. At most once per person per hour, and only for people with a confirmed email.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ec-assistant-url"><?php esc_html_e('LLM API URL', 'eventcrew'); ?></label></th>
                <td>
                    <input type="url" id="ec-assistant-url" name="assistant_url" class="regular-text code" value="<?php echo esc_attr($assistant['url']); ?>">
                    <p class="description">
                        <?php esc_html_e('The base address of the API, ending before /chat/completions.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ec-assistant-model"><?php esc_html_e('Model', 'eventcrew'); ?></label></th>
                <td>
                    <input type="text" id="ec-assistant-model" name="assistant_model" class="regular-text code" value="<?php echo esc_attr($assistant['model']); ?>">
                    <p class="description">
                        <?php esc_html_e('The model name exactly as the server lists it.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ec-assistant-key"><?php esc_html_e('API key', 'eventcrew'); ?></label></th>
                <td>
                    <input type="password" id="ec-assistant-key" name="assistant_key" class="regular-text" value="" autocomplete="new-password">
                    <p class="description">
                        <?php if (\EventCrew\Support\AssistantSettings::KEY_OK === $assistant['key_status']) : ?>
                            <?php esc_html_e('A key is stored (encrypted). Leave the field empty to keep it.', 'eventcrew'); ?>
                            <a href="<?php echo esc_url($assistant['remove_key_url']); ?>"><?php esc_html_e('Remove key', 'eventcrew'); ?></a>
                        <?php elseif (\EventCrew\Support\AssistantSettings::KEY_UNREADABLE === $assistant['key_status']) : ?>
                            <?php esc_html_e('The stored key cannot be read.', 'eventcrew'); ?>
                            <a href="<?php echo esc_url($assistant['remove_key_url']); ?>"><?php esc_html_e('Remove key', 'eventcrew'); ?></a>
                        <?php else : ?>
                            <?php esc_html_e('No key is stored. Leave it empty if your server does not ask for one.', 'eventcrew'); ?>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Limits per hour', 'eventcrew'); ?></th>
                <td>
                    <label>
                        <input type="number" min="0" max="10000" name="assistant_private_limit" class="small-text" value="<?php echo esc_attr((string) $assistant['private_limit']); ?>">
                        <?php esc_html_e('messages per person in a private chat', 'eventcrew'); ?>
                    </label><br>
                    <label>
                        <input type="number" min="0" max="10000" name="assistant_organizer_limit" class="small-text" value="<?php echo esc_attr((string) $assistant['organizer_limit']); ?>">
                        <?php esc_html_e('messages per organizer in a private chat', 'eventcrew'); ?>
                    </label><br>
                    <label>
                        <input type="number" min="0" max="10000" name="assistant_group_limit" class="small-text" value="<?php echo esc_attr((string) $assistant['group_limit']); ?>">
                        <?php esc_html_e('messages per group', 'eventcrew'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Messages over the limit get no answer from the model. Commands always work.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Pages it may read', 'eventcrew'); ?></th>
                <td>
                    <?php if ([] === $assistant['page_choices']) : ?>
                        <p class="description"><?php esc_html_e('There are no published pages.', 'eventcrew'); ?></p>
                    <?php else : ?>
                        <details>
                            <summary><?php esc_html_e('Choose pages', 'eventcrew'); ?></summary>
                            <?php foreach ($assistant['page_choices'] as $pageId => $pageTitle) : ?>
                                <label style="display:block">
                                    <input type="checkbox" name="assistant_pages[]" value="<?php echo esc_attr((string) $pageId); ?>" <?php checked(in_array($pageId, $assistant['pages'], true)); ?>>
                                    <?php echo esc_html('' !== $pageTitle ? $pageTitle : '#' . $pageId); ?>
                                </label>
                            <?php endforeach; ?>
                        </details>
                    <?php endif; ?>
                    <p class="description">
                        <?php esc_html_e('With none chosen, it reads the pages in the site menu, or every published page if there is no menu. Pages with a password are never read.', 'eventcrew'); ?>
                        <?php if ([] !== $assistant['default_pages']) : ?>
                            <br>
                            <?php
                            printf(
                                /* translators: %s: comma-separated page titles */
                                esc_html__('With none chosen, now: %s', 'eventcrew'),
                                esc_html(implode(', ', $assistant['default_pages']))
                            );
                            ?>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Test', 'eventcrew'); ?></th>
                <td>
                    <a class="button" href="<?php echo esc_url($assistant['test_url']); ?>"><?php esc_html_e('Test connection', 'eventcrew'); ?></a>
                    <p class="description">
                        <?php esc_html_e('Save first. The test sends one tiny request with the saved settings.', 'eventcrew'); ?>
                    </p>
                </td>
            </tr>
        </table>

        </div>
        <?php submit_button(__('Save settings', 'eventcrew')); ?>
    </form>

    <div class="ec-tab-panel" data-ec-tab="tuning">
    <h2><?php esc_html_e('Check the welcome message', 'eventcrew'); ?></h2>
    <p class="description">
        <?php
        printf(
            /* translators: %s: the current administrator's email address */
            esc_html__('Sends every welcome message to the member with your address, %s, by email and Telegram, with the live settings and open tasks. It works while the welcome message is off, and nobody else gets anything.', 'eventcrew'),
            '<code>' . esc_html($email_test_to) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
        );
        ?>
    </p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="eventcrew_send_welcome_test">
        <?php wp_nonce_field($welcome_nonce_action); ?>
        <?php submit_button(__('Send me the welcome message', 'eventcrew'), 'secondary', 'submit', false); ?>
    </form>
    </div>

    <div class="ec-tab-panel" data-ec-tab="email">
    <h2><?php esc_html_e('Check it', 'eventcrew'); ?></h2>
    <p class="description">
        <?php
        printf(
            /* translators: %s: the current administrator's email address */
            esc_html__('Send yourself one, at %s, to see the template with your logo and a button in it. Save any changes above first.', 'eventcrew'),
            '<code>' . esc_html($email_test_to) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
        );
        ?>
    </p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
        <input type="hidden" name="action" value="eventcrew_send_test_email">
        <?php wp_nonce_field($email_nonce_action); ?>
        <?php submit_button(__('Send a test email', 'eventcrew'), 'secondary', 'submit', false); ?>
    </form>
    <form
        method="post"
        action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
        style="display:inline-block;margin-left:.5em"
        onsubmit="return confirm('<?php echo esc_js(__('Replace the template with the built-in design? Your version stays in the template’s revisions.', 'eventcrew')); ?>');">
        <input type="hidden" name="action" value="eventcrew_reset_email_template">
        <?php wp_nonce_field($email_nonce_action); ?>
        <?php submit_button(__('Reset the template', 'eventcrew'), 'secondary', 'submit', false); ?>
    </form>

    <h2><?php esc_html_e('Ticket buyer import by email', 'eventcrew'); ?></h2>
    <p class="description">
        <?php esc_html_e('Organizers can email the Holvi ticket file (.xlsx or .csv) to the import mailbox; its tickets go to the door list. Names typed in the mail itself, one per line, go to the guest list, and "+2" after a name adds two plus-ones. This site reads that mailbox itself; set it up below. The list goes to the next event. While no mailbox is set, the organizer guide and the Roster page do not mention the email import.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
    </p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="eventcrew_save_import_mailbox">
        <?php wp_nonce_field($email_nonce_action); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="eventcrew-import-mailbox"><?php esc_html_e('Import mailbox', 'eventcrew'); ?></label></th>
                <td>
                    <input type="email" name="import_mailbox" id="eventcrew-import-mailbox" class="regular-text" value="<?php echo esc_attr($import_mailbox); ?>">
                    <p class="description"><?php esc_html_e('The address organizers send their lists to. Leave it empty if this site has no import mailbox.', 'eventcrew'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="eventcrew-import-senders"><?php esc_html_e('Extra sender addresses', 'eventcrew'); ?></label></th>
                <td>
                    <textarea name="import_senders" id="eventcrew-import-senders" rows="3" class="regular-text" spellcheck="false"><?php echo esc_textarea(implode("\n", $import_senders)); ?></textarea>
                    <p class="description"><?php esc_html_e('One email address per line. Lists mailed from these addresses are imported too, besides lists from organizers. The import notice goes to every organizer and to the sender.', 'eventcrew'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Ticket file formats', 'eventcrew'); ?></th>
                <td>
                    <p class="description" style="margin-top:0"><?php esc_html_e('When a mailed list has an .xlsx or .csv file attached, the file is read instead of the mail text. Its first row must hold the column headers of one of these formats. In a header field, separate accepted header texts with "|", and join columns with "+", as in "Etunimi + Sukunimi". Upper and lower case do not matter.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>
                    <?php
                    // The stored formats, then one empty format to add a new one.
                    $ec_format_rows = $ticket_formats;
                    $ec_format_rows[] = ['id' => '', 'name' => '', 'columns' => [], 'decoder_js' => ''];
                    $ec_header_field = static fn (array $format, string $column): string => implode(
                        ' | ',
                        \EventCrew\Support\TicketFormats::headerTexts($format['columns'][$column] ?? '')
                    );
                    foreach ($ec_format_rows as $ec_index => $ec_format) :
                        $ec_is_new = '' === $ec_format['id'];
                        $ec_field = 'ticket_formats[' . (int) $ec_index . ']';
                        ?>
                        <fieldset style="border:1px solid #c3c4c7;padding:.5em 1em;margin:1em 0">
                            <legend style="font-weight:600">
                                <?php echo $ec_is_new ? esc_html__('Add a format', 'eventcrew') : esc_html($ec_format['name']); ?>
                            </legend>
                            <input type="hidden" name="<?php echo esc_attr($ec_field . '[id]'); ?>" value="<?php echo esc_attr($ec_format['id']); ?>">
                            <p>
                                <label><?php esc_html_e('Name', 'eventcrew'); ?><br>
                                <input type="text" class="regular-text" name="<?php echo esc_attr($ec_field . '[name]'); ?>" value="<?php echo esc_attr($ec_format['name']); ?>"></label>
                            </p>
                            <p>
                                <label><?php esc_html_e('Name column header', 'eventcrew'); ?><br>
                                <input type="text" class="regular-text" name="<?php echo esc_attr($ec_field . '[col_name]'); ?>" value="<?php echo esc_attr($ec_header_field($ec_format, 'name')); ?>"></label>
                            </p>
                            <p>
                                <label><?php esc_html_e('Email column header', 'eventcrew'); ?><br>
                                <input type="text" class="regular-text" name="<?php echo esc_attr($ec_field . '[col_email]'); ?>" value="<?php echo esc_attr($ec_header_field($ec_format, 'email')); ?>"></label>
                            </p>
                            <p>
                                <label><?php esc_html_e('Ticket code column header', 'eventcrew'); ?><br>
                                <input type="text" class="regular-text" name="<?php echo esc_attr($ec_field . '[col_code]'); ?>" value="<?php echo esc_attr($ec_header_field($ec_format, 'code')); ?>"></label>
                            </p>
                            <p>
                                <label><?php esc_html_e('Decoder (JavaScript)', 'eventcrew'); ?><br>
                                <textarea class="large-text code" rows="3" spellcheck="false" name="<?php echo esc_attr($ec_field . '[decoder_js]'); ?>"><?php echo esc_textarea($ec_format['decoder_js']); ?></textarea></label>
                            </p>
                            <?php if (! $ec_is_new) : ?>
                                <p>
                                    <label><input type="checkbox" name="<?php echo esc_attr($ec_field . '[remove]'); ?>" value="1"> <?php esc_html_e('Remove this format', 'eventcrew'); ?></label>
                                </p>
                            <?php endif; ?>
                        </fieldset>
                    <?php endforeach; ?>
                    <p class="description"><?php esc_html_e('With no format left, the Holvi format is used.', 'eventcrew'); ?></p>
                </td>
            </tr>
        </table>
        <?php submit_button(__('Save the import settings', 'eventcrew'), 'secondary', 'submit', false); ?>
    </form>

    <h3><?php esc_html_e('Read the mailbox from this site', 'eventcrew'); ?></h3>
    <p class="description">
        <?php esc_html_e('The site can read the import mailbox itself every five minutes. Use JMAP if your host blocks the IMAP ports; many shared hosts do. A message is marked read and moved to the Imported or Rejected folder once it has been dealt with.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
    </p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="eventcrew_save_mail_fetch">
        <?php wp_nonce_field($email_nonce_action); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="eventcrew-mail-fetch-protocol"><?php esc_html_e('Read the mailbox', 'eventcrew'); ?></label></th>
                <td>
                    <select name="mail_fetch_protocol" id="eventcrew-mail-fetch-protocol">
                        <option value="" <?php selected($mail_fetch['protocol'], ''); ?>><?php esc_html_e('Off', 'eventcrew'); ?></option>
                        <option value="jmap" <?php selected($mail_fetch['protocol'], 'jmap'); ?>><?php esc_html_e('JMAP (over HTTPS)', 'eventcrew'); ?></option>
                        <option value="imap" <?php selected($mail_fetch['protocol'], 'imap'); ?>><?php esc_html_e('IMAP', 'eventcrew'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="eventcrew-mail-fetch-server"><?php esc_html_e('Server', 'eventcrew'); ?></label></th>
                <td>
                    <input type="text" name="mail_fetch_server" id="eventcrew-mail-fetch-server" class="regular-text" value="<?php echo esc_attr($mail_fetch['server']); ?>">
                    <p class="description"><?php esc_html_e('IMAP: the host name, for example imap.example.org. JMAP: the host name, or the full session URL if it is not at /.well-known/jmap.', 'eventcrew'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="eventcrew-mail-fetch-port"><?php esc_html_e('IMAP port and security', 'eventcrew'); ?></label></th>
                <td>
                    <input type="number" name="mail_fetch_port" id="eventcrew-mail-fetch-port" class="small-text" min="1" max="65535" value="<?php echo esc_attr((string) $mail_fetch['port']); ?>">
                    <select name="mail_fetch_security" aria-label="<?php esc_attr_e('Security', 'eventcrew'); ?>">
                        <option value="ssl" <?php selected($mail_fetch['security'], 'ssl'); ?>><?php esc_html_e('SSL/TLS (usually port 993)', 'eventcrew'); ?></option>
                        <option value="starttls" <?php selected($mail_fetch['security'], 'starttls'); ?>><?php esc_html_e('STARTTLS (usually port 143)', 'eventcrew'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Not used for JMAP.', 'eventcrew'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="eventcrew-mail-fetch-username"><?php esc_html_e('User name', 'eventcrew'); ?></label></th>
                <td><input type="text" name="mail_fetch_username" id="eventcrew-mail-fetch-username" class="regular-text" autocomplete="off" value="<?php echo esc_attr($mail_fetch['username']); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="eventcrew-mail-fetch-password"><?php esc_html_e('Password', 'eventcrew'); ?></label></th>
                <td>
                    <input type="password" name="mail_fetch_password" id="eventcrew-mail-fetch-password" class="regular-text" autocomplete="new-password" value="">
                    <p class="description">
                        <?php
                        '' === $mail_fetch['password']
                            ? esc_html_e('No password is saved.', 'eventcrew')
                            : esc_html_e('A password is saved. Leave this empty to keep it.', 'eventcrew');
                        ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="eventcrew-mail-fetch-folder"><?php esc_html_e('Folders', 'eventcrew'); ?></label></th>
                <td>
                    <input type="text" name="mail_fetch_folder" id="eventcrew-mail-fetch-folder" class="regular-text" value="<?php echo esc_attr($mail_fetch['folder']); ?>">
                    <p class="description"><?php esc_html_e('The folder to read. INBOX is the inbox.', 'eventcrew'); ?></p>
                    <input type="text" name="mail_fetch_imported_folder" class="regular-text" aria-label="<?php esc_attr_e('Imported folder', 'eventcrew'); ?>" value="<?php echo esc_attr($mail_fetch['imported_folder']); ?>">
                    <p class="description"><?php esc_html_e('Where imported lists go. Created if missing.', 'eventcrew'); ?></p>
                    <input type="text" name="mail_fetch_rejected_folder" class="regular-text" aria-label="<?php esc_attr_e('Rejected folder', 'eventcrew'); ?>" value="<?php echo esc_attr($mail_fetch['rejected_folder']); ?>">
                    <p class="description"><?php esc_html_e('Where refused messages go. Created if missing.', 'eventcrew'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="eventcrew-mail-fetch-authserv"><?php esc_html_e('Trusted mail server', 'eventcrew'); ?></label></th>
                <td>
                    <input type="text" name="mail_fetch_authserv_id" id="eventcrew-mail-fetch-authserv" class="regular-text" value="<?php echo esc_attr($mail_fetch['authserv_id']); ?>">
                    <p class="description"><?php esc_html_e('The first word of the Authentication-Results header your own mail server adds, for example mx.example.org. Only the top Authentication-Results header is trusted, and only when it names this server. Empty refuses every message.', 'eventcrew'); ?></p>
                </td>
            </tr>
        </table>
        <?php submit_button(__('Save the mailbox settings', 'eventcrew'), 'secondary', 'submit', false); ?>
    </form>
    <?php if (0 !== $mail_fetch_status['time']) : ?>
        <p>
            <?php
            printf(
                /* translators: 1: date and time of the last check, 2: what it found or the error */
                esc_html__('Last check %1$s: %2$s', 'eventcrew'),
                esc_html(wp_date('j M Y H:i', $mail_fetch_status['time'])),
                esc_html($mail_fetch_status['message'])
            );
            ?>
        </p>
    <?php endif; ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="eventcrew_fetch_mail_now">
        <?php wp_nonce_field($email_nonce_action); ?>
        <?php submit_button(__('Check the mailbox now', 'eventcrew'), 'secondary', 'submit', false); ?>
    </form>
    </div>

    <div class="ec-tab-panel" data-ec-tab="privacy">
    <h2><?php esc_html_e('Publish it', 'eventcrew'); ?></h2>
    <?php if (0 === $privacy['page_id']) : ?>
        <p class="description">
            <?php esc_html_e('Creates a published page called “Crew privacy notice” holding the shortcode, and selects it above. Edit or rename it afterwards like any other page — the notice itself comes from the fields on this tab, not from the page’s text.', 'eventcrew'); ?>
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="eventcrew_create_privacy_page">
            <?php wp_nonce_field($privacy['create_nonce_action']); ?>
            <?php submit_button(__('Create the privacy page', 'eventcrew'), 'secondary', 'submit', false); ?>
        </form>
    <?php else : ?>
        <p class="description">
            <?php esc_html_e('A privacy page is chosen. Save any changes above and they appear on it immediately — there is nothing to re-publish.', 'eventcrew'); ?>
        </p>
    <?php endif; ?>

    <h2><?php esc_html_e('Your site’s own privacy policy', 'eventcrew'); ?></h2>
    <p class="description">
        <?php
        printf(
            /* translators: %s: link to the WordPress privacy settings screen, already wrapped in an anchor tag */
            esc_html__('The same notice is offered to WordPress as suggested policy text, so the site’s main policy can cover the crew data too. Review and paste it in under %s. WordPress will tell you there whenever a new version of this plugin rewords it.', 'eventcrew'),
            '<a href="' . esc_url($privacy['core_policy_url']) . '">'
                . esc_html__('Settings → Privacy', 'eventcrew')
                . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
        );
        ?>
    </p>

    <h2><?php esc_html_e('Requests from people', 'eventcrew'); ?></h2>
    <p class="description">
        <?php
        printf(
            /* translators: 1: link to the export tool, 2: link to the erase tool, both already wrapped in anchor tags */
            esc_html__('Crew members are not WordPress users, so this plugin registers its own handlers for %1$s and %2$s: enter a crew member’s email address there and their record is exported or erased along with everything attached to it. Most people never need you to — their own Crew app has a Delete button, and every email links to it.', 'eventcrew'),
            '<a href="' . esc_url(admin_url('export-personal-data.php')) . '">'
                . esc_html__('Tools → Export Personal Data', 'eventcrew')
                . '</a>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
            '<a href="' . esc_url(admin_url('erase-personal-data.php')) . '">'
                . esc_html__('Tools → Erase Personal Data', 'eventcrew')
                . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
        );
        ?>
    </p>
    </div>

    <div class="ec-tab-panel" data-ec-tab="telegram">
    <h2><?php esc_html_e('Webhook', 'eventcrew'); ?></h2>
    <?php
    // Re-installing after the address moved runs in the background.
    $eventcrew_webhook_badge = \EventCrew\Support\BackgroundJobs::badge(
        \EventCrew\Support\BackgroundJobs::latest(\EventCrew\Admin\SettingsPage::WEBHOOK_TARGET)
    );
    if ('' !== $eventcrew_webhook_badge) {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
        echo '<p>' . $eventcrew_webhook_badge . '</p>';
    }
    ?>
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
            $eventcrew_last_error_at = (int) ($eventcrew_info['last_error_date'] ?? 0);
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
                            <td>
                                <?php
                                echo esc_html($eventcrew_last_error);

                                if ($eventcrew_last_error_at > 0) {
                                    printf(
                                        ' <span class="description">%s</span>',
                                        esc_html(sprintf(
                                            /* translators: %s: human-readable time since the last webhook error, e.g. "2 hours" */
                                            __('(%s ago)', 'eventcrew'),
                                            human_time_diff($eventcrew_last_error_at)
                                        ))
                                    );
                                }
                                ?>
                                <p class="description" style="margin-top:.4em">
                                    <?php esc_html_e('Telegram keeps only the most recent error and never clears it on a successful delivery. If Pending updates is 0 and this predates your last fix, it is stale — reinstall the webhook to clear it.', 'eventcrew'); ?>
                                </p>
                            </td>
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
                                : esc_html(\EventCrew\Admin\SettingsPage::noGroupText());
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

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
            <input type="hidden" name="action" value="eventcrew_telegram_setup">
            <?php wp_nonce_field($telegram['setup_nonce_action']); ?>
            <?php submit_button(__('Install / refresh webhook', 'eventcrew'), 'secondary', 'submit', false); ?>
        </form>
        <form
            method="post"
            action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            style="display:inline-block;margin-left:.5em"
            onsubmit="return confirm('<?php echo esc_js(__('Generate a new webhook secret and re-install? The bot is unreachable for the moment in between, and any old copy of the secret stops working.', 'eventcrew')); ?>');">
            <input type="hidden" name="action" value="eventcrew_rotate_webhook_secret">
            <?php wp_nonce_field($telegram['setup_nonce_action']); ?>
            <?php submit_button(__('Rotate the webhook secret', 'eventcrew'), 'secondary', 'submit', false); ?>
        </form>
        <?php if ('' !== $telegram['test_url']) : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:.6em 0 0">
            <input type="hidden" name="action" value="eventcrew_test_webhook">
            <?php wp_nonce_field($telegram['setup_nonce_action']); ?>
            <?php submit_button(__('Test the webhook', 'eventcrew'), 'secondary', 'submit', false); ?>
            <span class="description">
                <?php esc_html_e('The site sends an empty update to its own webhook, with the real secret, and shows the HTTP status. The bot sends no message.', 'eventcrew'); ?>
            </span>
        </form>
        <?php endif; ?>
        <p class="description" style="margin-top:.6em">
            <?php esc_html_e('The secret is what proves an incoming update really came from Telegram — it is the bot’s only authentication. Rotate it if it may have been seen: in a server log, a screenshot, or a support ticket. The bot keeps working; only the old secret stops.', 'eventcrew'); ?>
        </p>

        <h2><?php esc_html_e('The board', 'eventcrew'); ?></h2>
        <?php
        // "Post the board in the group" runs in the background.
        $eventcrew_board_badge = \EventCrew\Support\BackgroundJobs::badge(
            \EventCrew\Support\BackgroundJobs::latest(\EventCrew\Admin\SettingsPage::BOARD_TARGET)
        );
        if ('' !== $eventcrew_board_badge) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
            echo '<p>' . $eventcrew_board_badge . '</p>';
        }
        ?>
        <p class="description">
            <?php esc_html_e('The group’s board redraws itself whenever anything on it changes — someone joins or drops, you edit a task, or the hourly check notices a task has passed. This button does it now instead, which is what you want after upgrading (a new release may lay the board out differently) or to see a change land without waiting.', 'eventcrew'); ?>
        </p>
        <p class="description">
            <?php esc_html_e('Refresh edits the board where it already sits, so nobody in the group is notified. Post the board in the group puts a fresh copy at the bottom of the group, like /board there, and removes the old one when Telegram allows it (within 48 hours). No command in the group is needed.', 'eventcrew'); ?>
        </p>
        <?php if (0 === $telegram['board_chat_id']) : ?>
            <div class="notice notice-warning inline" style="margin:1em 0">
                <p>
                    <?php echo esc_html(\EventCrew\Admin\SettingsPage::noGroupText()); ?>
                </p>
                <?php if ('' !== $telegram['bot_username']) : ?>
                    <p>
                        <a
                            class="button button-primary"
                            href="<?php echo esc_url('https://t.me/' . rawurlencode($telegram['bot_username']) . '?startgroup=board'); ?>"
                            target="_blank"
                            rel="noopener"><?php esc_html_e('Add the bot to your group', 'eventcrew'); ?></a>
                    </p>
                    <p class="description">
                        <?php esc_html_e('Telegram asks which group. Reload this page afterwards to see the board’s group here.', 'eventcrew'); ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php $eventcrew_no_board = 0 === $telegram['board_chat_id'] ? ['disabled' => 'disabled'] : []; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin:1em 0">
            <input type="hidden" name="action" value="eventcrew_refresh_board">
            <?php wp_nonce_field($telegram['setup_nonce_action']); ?>
            <?php submit_button(__('Refresh the board', 'eventcrew'), 'secondary', 'submit', false, $eventcrew_no_board); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin:1em 0 1em .5em">
            <input type="hidden" name="action" value="eventcrew_post_board">
            <?php wp_nonce_field($telegram['setup_nonce_action']); ?>
            <?php submit_button(__('Post the board in the group', 'eventcrew'), 'secondary', 'submit', false, $eventcrew_no_board); ?>
        </form>
    <?php endif; ?>
    </div>

    <script>
    // Progressive enhancement: turn the section groups into tabs. With JS off,
    // every panel stays visible and the page behaves as one long form.
    (function () {
        var nav = document.querySelector('.eventcrew-tabs');
        if (!nav) { return; }

        var panels = document.querySelectorAll('.ec-tab-panel');
        var tabs = nav.querySelectorAll('.nav-tab');

        function show(key) {
            panels.forEach(function (p) {
                p.style.display = p.getAttribute('data-ec-tab') === key ? '' : 'none';
            });
            tabs.forEach(function (t) {
                t.classList.toggle('nav-tab-active', t.getAttribute('data-ec-tab') === key);
            });
            try { localStorage.setItem('eventcrew_settings_tab', key); } catch (e) {}
        }

        tabs.forEach(function (t) {
            t.addEventListener('click', function (e) {
                e.preventDefault();
                show(t.getAttribute('data-ec-tab'));
            });
        });

        // A #hash wins over both, so a link can point at one tab from
        // elsewhere - the unconfigured privacy notice sends an organizer
        // straight to the fields that would fix it, and landing them on
        // whichever tab they happened to use last would waste the link.
        // Otherwise reopen the tab last used (a Save reloads the page), else
        // the first.
        var initial = 'roles';
        var hash = (window.location.hash || '').replace(/^#/, '');

        if (hash && nav.querySelector('.nav-tab[data-ec-tab="' + hash + '"]')) {
            initial = hash;
        } else {
            try {
                var saved = localStorage.getItem('eventcrew_settings_tab');
                if (saved && nav.querySelector('.nav-tab[data-ec-tab="' + saved + '"]')) {
                    initial = saved;
                }
            } catch (e) {}
        }
        show(initial);
    })();
    </script>
</div>

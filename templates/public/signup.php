<?php

/**
 * Public signup board, rendered by the [eventcrew_signup] shortcode / block.
 *
 * @var array<string, mixed> $view The SignupController view model:
 *   person, standing, csrf, groups, and the four ajax action names.
 */



declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/** @var \EventCrew\Models\Person|null $eventcrew_person */
$eventcrew_person = $view['person'];
/** @var \EventCrew\Support\Standing|null $eventcrew_standing */
$eventcrew_standing = $view['standing'];
$eventcrew_csrf = (string) $view['csrf'];
$eventcrew_turnstile_site_key = (string) $view['turnstile_site_key'];
/** @var array<string, string> $eventcrew_ticket_dates date => label a credit can be spent on */
$eventcrew_ticket_dates = is_array($view['ticket_dates'] ?? null) ? $view['ticket_dates'] : [];
$eventcrew_redeem_action = (string) ($view['redeem_action'] ?? '');
/** @var array<string, array{label: string, dm: bool, email: bool}> $eventcrew_notify_matrix */
$eventcrew_notify_matrix = is_array($view['notify_matrix'] ?? null) ? $view['notify_matrix'] : [];
$eventcrew_prefs_action = (string) ($view['prefs_action'] ?? '');
/** @var array{upcoming: array<int, array{label: string, when: string, url: string}>, past: array<int, array{label: string, when: string, url: string}>} $eventcrew_my_tickets */
$eventcrew_my_tickets = is_array($view['my_tickets'] ?? null)
    ? $view['my_tickets']
    : ['upcoming' => [], 'past' => []];
/** @var array<int, array{task_id: int, label: string, when: string, calendar_url: string}> $eventcrew_my_upcoming */
$eventcrew_my_upcoming = is_array($view['my_upcoming'] ?? null) ? $view['my_upcoming'] : [];
/** @var array<int, array{when: string, label: string, status: string}> $eventcrew_my_history */
$eventcrew_my_history = is_array($view['my_history'] ?? null) ? $view['my_history'] : [];
$eventcrew_manage_endpoint = (string) ($view['manage_endpoint'] ?? '');
$eventcrew_manage_token = (string) ($view['manage_token'] ?? '');
/*
 * The Admin tab's content, or null. Null for everyone who is not an organizer:
 * the tab and its panel are then not in the page at all. Hiding them is only
 * tidiness - every admin action checks the organizer flag again on the server.
 */
/** @var array{sales_action: string, board_action: string, gift_action: string, mark_action: string, members: array<int, string>, roster_label: string, roster: array<int, array{task_id: int, title: string, people: array<int, array{assignment_id: int, name: string, status: string, status_label: string, marked_by: string, markable: bool, standing: string, at_risk: bool}>}>, roster_date: string, roster_upcoming: array<string, string>, roster_past: array<string, string>, roster_action: string, door_action: string, guest_action: string, buyers_action: string, mail_action: string, statuses: array<string, string>, max_plus_ones: int, night: array{entrants: list<array{name: string, detail: string, standing: string, at_risk: bool, redemption_id: ?int}>, candidates: list<array{person_id: int, name: string, credit_balance: int}>, closed_by_hand: bool, event_reason: string, guests: list<array{id: int, name: string, plus_ones: int, note: string}>, buyers: list<string>, ticket_mail_buyers: string, ticket_mail_guests: string, ticket_mail_pending: string, followup_mail: string, followup_can_stop: bool, mailbox: string, mail_last: string}, roles_action: string, add_action: string, roles: array<string, string>, add_tasks: array<int, string>, person_action: string, pass_action: string, unban_action: string, people: array{search: string, page: int, pages: int, total: int, list: list<array{id: int, name: string, email: string, verified: bool, organizer: bool, banned: bool, anonymized: bool}>, editing: ?array{person: \EventCrew\Models\Person, standing: string, at_risk: bool, credits: int, given: list<array{credits: int, note: string, granted_by: string, granted_at: string}>, spent: list<array{day: string, event: string, redeemed_at: string}>}}}|null $eventcrew_admin */
$eventcrew_admin = is_array($view['admin'] ?? null) ? $view['admin'] : null;
$eventcrew_ajax = admin_url('admin-ajax.php');
// The URL to return to after each action: this very page. get_permalink() gives
// the clean canonical URL of the page the shortcode sits on - unlike
// add_query_arg([], null), which yields an empty URI (so home_url() fell back to
// the site root) and sent every action to the homepage.
$eventcrew_here = get_permalink();
if (false === $eventcrew_here) {
    $eventcrew_here = home_url('/');
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect-carried status.
$eventcrew_notice_code = isset($_GET['eventcrew_notice']) ? sanitize_key(wp_unslash($_GET['eventcrew_notice'])) : '';
$eventcrew_notice_text = \EventCrew\Web\SignupController::noticeText($eventcrew_notice_code);
?>
<?php
// The stylesheet is registered and enqueued by SignupController; see
// assets/eventcrew.css. It used to live here as an inline <style> block, which
// meant it was neither cacheable nor versioned - and, because the PWA service
// worker caches the page HTML, a restyle never reached an installed app.
?>
<div class="eventcrew-signup">
    <div id="eventcrew-toast" class="eventcrew-toast<?php echo '' !== $eventcrew_notice_text ? ' show' : ''; ?>" role="status" aria-live="polite"><?php echo esc_html($eventcrew_notice_text); ?></div>

    <?php if (null !== $eventcrew_person) : ?>
    <?php
    /*
     * Real anchors, not buttons: with JavaScript off every panel stays
     * visible and these degrade to jump links down one long page, which is
     * the same posture the rest of this page takes. The script upgrades
     * them to a tablist in place. The bar is the first thing on the page and
     * stays at the top while scrolling; signed out there is nothing to tab
     * between, so it is not drawn.
     */
    ?>
    <nav class="eventcrew-tabs" aria-label="<?php esc_attr_e('Your Crew app', 'eventcrew'); ?>">
        <?php
        $eventcrew_tabs = [
            'board' => __('Board', 'eventcrew'),
            'me' => __('Me', 'eventcrew'),
            'settings' => __('Settings', 'eventcrew'),
            'help' => __('Help', 'eventcrew'),
        ];
        $eventcrew_tabs += null !== $eventcrew_admin ? ['admin' => __('Admin', 'eventcrew')] : [];
        foreach ($eventcrew_tabs as $eventcrew_key => $eventcrew_label) :
            ?>
            <a
                class="eventcrew-tab"
                id="eventcrew-tab-<?php echo esc_attr($eventcrew_key); ?>"
                href="#eventcrew-panel-<?php echo esc_attr($eventcrew_key); ?>"
                data-eventcrew-tab="<?php echo esc_attr($eventcrew_key); ?>"
            ><?php echo esc_html($eventcrew_label); ?></a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>

    <?php
    // The Telegram group and crew guide buttons, at the top of the page so
    // they show above every tab. Signed out there is no group link.
    $eventcrew_group_link = null === $eventcrew_person ? '' : (string) ($view['telegram_group_link'] ?? '');
    require EVENTCREW_PLUGIN_DIR . 'templates/public/signup-links.php';
    ?>

    <?php if (null === $eventcrew_person) :
        // Signed out there are only two things - sign in, and look at the board
        // - so there is nothing to tab between and no tab strip is drawn.
        ?>
        <section class="eventcrew-event eventcrew-me-card eventcrew-signin-card">
            <header class="eventcrew-event-head">
                <h2 class="eventcrew-event-title"><?php esc_html_e('Sign in or sign up', 'eventcrew'); ?></h2>
            </header>
            <div class="eventcrew-card-body">
            <?php
            /*
             * The choice between email and Telegram, when the sign-in through
             * the bot is available. It starts hidden and the script below
             * reveals it, so without JavaScript the email form is what shows,
             * exactly as before. The login code is not in the markup:
             * signed-out pages are cached, so the script asks for a fresh one
             * when Telegram is pressed.
             */
            $eventcrew_tg_login = is_array($view['telegram_login'] ?? null) ? $view['telegram_login'] : null;
            ?>
            <?php if (null !== $eventcrew_tg_login) : ?>
                <?php // Back sits above the email field and the waiting text, where the eye starts. ?>
                <p class="eventcrew-signin-back" data-eventcrew-tg-back hidden>
                    <a href="#"><span aria-hidden="true">← </span><?php esc_html_e('Back', 'eventcrew'); ?></a>
                </p>
                <div
                    class="eventcrew-signin-choice"
                    data-eventcrew-tg-login
                    data-ajax="<?php echo esc_url($eventcrew_ajax); ?>"
                    data-here="<?php echo esc_url($eventcrew_here); ?>"
                    data-start-action="<?php echo esc_attr((string) $eventcrew_tg_login['start_action']); ?>"
                    data-status-action="<?php echo esc_attr((string) $eventcrew_tg_login['status_action']); ?>"
                    hidden>
                    <p class="eventcrew-signin-choice-prompt">
                        <?php esc_html_e('How do you want to sign in?', 'eventcrew'); ?>
                    </p>
                    <div class="eventcrew-signin-choice-row">
                        <button type="button" class="wp-element-button" data-eventcrew-tg-email>
                            <?php esc_html_e('Email', 'eventcrew'); ?>
                        </button>
                        <button
                            type="button"
                            class="tg-auth-button"
                            data-eventcrew-tg-start
                            aria-label="<?php esc_attr_e('Sign in with Telegram', 'eventcrew'); ?>">
                            <?php esc_html_e('Sign in with Telegram', 'eventcrew'); ?>
                        </button>
                    </div>
                    <p class="eventcrew-muted eventcrew-hint eventcrew-tg-note" data-eventcrew-tg-note hidden></p>
                </div>
                <div class="eventcrew-tg-wait" data-eventcrew-tg-wait hidden>
                    <p class="eventcrew-tg-wait-text"><?php echo esc_html(\EventCrew\Web\SignupController::noticeText('tg_started')); ?></p>
                    <p class="eventcrew-tg-match" data-eventcrew-tg-match></p>
                    <p>
                        <a href="#" target="_blank" rel="noopener" data-eventcrew-tg-again><?php esc_html_e('Open Telegram again', 'eventcrew'); ?></a>
                    </p>
                </div>
            <?php endif; ?>
            <form class="eventcrew-action eventcrew-signin" data-eventcrew-signin method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr((string) $view['login_action']); ?>">
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                <?php
                /*
                 * The captcha lives inside the row, between the field and the
                 * button, and switches the row to a column. Left beside a
                 * side-by-side field and button it made an L of three things
                 * that do not share an edge; stacked, all three line up and the
                 * order reads the way the form is filled in.
                 */
                ?>
                <div class="eventcrew-signin-row<?php echo '' !== $eventcrew_turnstile_site_key ? ' has-captcha' : ''; ?>">
                    <label for="eventcrew-email" class="screen-reader-text"><?php esc_html_e('Email', 'eventcrew'); ?></label>
                    <input
                        type="email"
                        id="eventcrew-email"
                        name="email"
                        required
                        autocomplete="email"
                        placeholder="<?php esc_attr_e('hello@example.com', 'eventcrew'); ?>"
                        class="wp-element-input"
                    >
                    <?php if ('' !== $eventcrew_turnstile_site_key) : ?>
                        <div
                            class="cf-turnstile eventcrew-turnstile"
                            data-sitekey="<?php echo esc_attr($eventcrew_turnstile_site_key); ?>"
                            data-theme="auto"
                            data-appearance="interaction-only"
                            data-size="flexible"></div>
                        <script>
                        // Turnstile's flexible widget is never narrower than
                        // 300px and spills out of a narrower card. Its compact
                        // size fits 150px. This runs before api.js is loaded,
                        // so the widget is drawn at the right size first time.
                        (function () {
                            var box = document.currentScript.previousElementSibling;
                            if (box && box.clientWidth < 300) {
                                box.setAttribute('data-size', 'compact');
                            }
                        }());
                        </script>
                    <?php endif; ?>
                    <button type="submit" class="wp-element-button"><?php esc_html_e('Email me a sign-in link', 'eventcrew'); ?></button>
                </div>
                <p class="eventcrew-muted eventcrew-hint"><?php esc_html_e('No password — we email you a link, good for 30 minutes.', 'eventcrew'); ?></p>
                <p class="eventcrew-muted eventcrew-hint"><?php esc_html_e('New to the crew? Use the same field. If the address has no account yet, the link creates one.', 'eventcrew'); ?></p>
                <?php
                /*
                 * The privacy line sits with the field it is about, not in a
                 * footer: this is the moment someone hands over an address, and
                 * a notice they have to go looking for afterwards is not a
                 * notice. No tickbox - signing up is what makes the processing
                 * necessary, so there is no consent here to collect, and a box
                 * demanding consent for something we would do anyway would
                 * misdescribe the basis rather than strengthen it.
                 *
                 * The link is dropped entirely when there is no notice to point
                 * at, which is the unconfigured install: a dead link is worse
                 * than the sentence on its own.
                 */
                $eventcrew_privacy_url = \EventCrew\Support\PrivacyPolicy::noticeUrl();
                ?>
                <p class="eventcrew-muted eventcrew-hint">
                    <?php echo esc_html(\EventCrew\Support\PrivacyPolicy::noticeLine()); ?>
                    <?php if ('' !== $eventcrew_privacy_url) : ?>
                        <a href="<?php echo esc_url($eventcrew_privacy_url); ?>">
                            <?php echo esc_html(\EventCrew\Support\PrivacyPolicy::noticeLinkLabel()); ?>
                        </a>
                    <?php endif; ?>
                </p>
                <?php if ('' !== $eventcrew_turnstile_site_key) : ?>
                    <script src="<?php echo esc_url(\EventCrew\Support\Turnstile::SCRIPT_URL); ?>" async defer></script>
                <?php endif; ?>
            </form>
            <p id="eventcrew-signin-sent" class="eventcrew-muted eventcrew-hint" hidden><?php esc_html_e('Check your inbox for the sign-in link — and your spam folder if it’s not there. Press the button again to resend.', 'eventcrew'); ?></p>
            <?php if (null !== $eventcrew_tg_login) : ?>
                <script>
                // Show the choice before the first paint, so the email form and
                // its human check do not flash first. Without JavaScript the
                // email form stays; the script at the end falls back to it too.
                (function () {
                    var c = document.querySelector('[data-eventcrew-tg-login]');
                    var f = document.querySelector('form[data-eventcrew-signin]');
                    if (c && f && window.fetch) { c.hidden = false; f.hidden = true; }
                })();
                </script>
            <?php endif; ?>
            </div>
        </section>

        <div id="eventcrew-board" class="eventcrew-board">
            <h2><?php esc_html_e('Board', 'eventcrew'); ?></h2>
            <?php require EVENTCREW_PLUGIN_DIR . 'templates/public/signup-board.php'; ?>
        </div>
        <?php
    else :
        ?>
        <?php
        /*
         * The header card, under the tab bar: who is signed in, the language
         * switch and Sign out in its top bar, and the person's standing,
         * credits and the score explainer in its body. Sign out is here rather
         * than in Settings because it is the one thing you might want from any
         * tab. The sales desk button that used to sit under it is gone: an
         * organizer opens the desk from the Admin tab, and the crew on a
         * sales-desk task from the Sales desk card at the top of the Me tab.
         */
        ?>
        <section class="eventcrew-event eventcrew-me-card eventcrew-header-card">
            <header class="eventcrew-event-head eventcrew-identity">
                <span class="eventcrew-identity-name">
                    <?php
                    printf(/* translators: %s: person's name */
                        esc_html__('Signed in as %s.', 'eventcrew'),
                        esc_html($eventcrew_person->name())
                    ); ?>
                </span>
                <?php
                /*
                 * The language this page, the bot's DMs and the emails reach
                 * this person in: one button per site language, main language
                 * first. The names stay untranslated: each is written in its
                 * own language so a reader can find theirs whichever is on.
                 */
                ?>
                <?php if ('' !== (string) ($view['language_action'] ?? '')) : ?>
                    <form class="eventcrew-language" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr((string) $view['language_action']); ?>">
                        <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                        <?php $eventcrew_lang_first = true; ?>
                        <?php foreach ((array) ($view['language_choices'] ?? []) as $eventcrew_lang_code => $eventcrew_lang_name) : ?>
                            <?php if (! $eventcrew_lang_first) : ?>
                                <span aria-hidden="true">|</span>
                            <?php endif; ?>
                            <?php $eventcrew_lang_first = false; ?>
                            <button type="submit" name="language"
                                value="<?php echo esc_attr((string) $eventcrew_lang_code); ?>"
                                lang="<?php echo esc_attr((string) $eventcrew_lang_code); ?>"
                                aria-pressed="<?php echo $eventcrew_lang_code === ($view['language'] ?? '') ? 'true' : 'false'; ?>"><?php echo esc_html((string) $eventcrew_lang_name); ?></button>
                        <?php endforeach; ?>
                    </form>
                <?php endif; ?>
                <form class="eventcrew-signout" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr((string) $view['logout_action']); ?>">
                    <?php
                    /*
                     * Signing out is checked like every other action, so this
                     * has to carry the token; without it the button answered
                     * "please sign in" to someone who was signed in.
                     */
                    ?>
                    <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                    <button type="submit" class="eventcrew-textlink"><?php esc_html_e('Sign out', 'eventcrew'); ?></button>
                </form>
            </header>
            <?php if (null !== $eventcrew_standing) : ?>
                <div class="eventcrew-card-body">
                    <p class="eventcrew-standing">
                        <span><?php echo esc_html($eventcrew_standing->ratedSummary()); ?></span>
                        &middot;
                        <span><?php echo esc_html(sprintf(/* translators: %d: number of free-entry credits */
                            _n('%d credit', '%d credits', $eventcrew_standing->creditBalance, 'eventcrew'),
                            $eventcrew_standing->creditBalance
                        )); ?></span>
                    </p>
                    <details class="eventcrew-disclosure eventcrew-score-help">
                        <summary><?php esc_html_e('How your score works', 'eventcrew'); ?></summary>
                        <table>
                            <tbody>
                            <?php foreach (\EventCrew\Support\StandingExplainer::rows() as $eventcrew_label => $eventcrew_percent) : ?>
                                <tr>
                                    <td><?php echo esc_html($eventcrew_label); ?></td>
                                    <td class="eventcrew-num"><?php echo esc_html($eventcrew_percent . '%'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="eventcrew-muted"><?php esc_html_e('Recent tasks count for more than old ones. You’re rated once you’ve completed a few tasks, and you earn one free-entry credit for every two completed tasks.', 'eventcrew'); ?></p>
                    </details>
                </div>
            <?php endif; ?>
        </section>

        <section class="eventcrew-panel" id="eventcrew-panel-board" data-eventcrew-panel="board" aria-labelledby="eventcrew-tab-board">
            <h2 class="eventcrew-panel-title"><?php esc_html_e('Board', 'eventcrew'); ?></h2>
            <div id="eventcrew-board" class="eventcrew-board">
                <?php require EVENTCREW_PLUGIN_DIR . 'templates/public/signup-board.php'; ?>
            </div>
        </section>

        <section class="eventcrew-panel" id="eventcrew-panel-me" data-eventcrew-panel="me" aria-labelledby="eventcrew-tab-me">
            <h2 class="eventcrew-panel-title"><?php esc_html_e('Me', 'eventcrew'); ?></h2>

            <?php
            /*
             * Each part is a card like an event on the board: a header bar with
             * its name, and its rows under it. My tasks and Tickets are always
             * drawn, even empty, because "you are not on anything yet" is worth
             * knowing.
             *
             * Sales desk comes first, and only for someone on tonight's
             * sales-desk task who is not an organizer: an organizer has the
             * same button on the Admin tab. The server checks again on press.
             */
            ?>
            <?php if (! empty($view['sales_desk']) && null === $eventcrew_admin) : ?>
                <section class="eventcrew-event eventcrew-me-card">
                    <header class="eventcrew-event-head">
                        <h3 class="eventcrew-event-title"><?php esc_html_e('Sales desk', 'eventcrew'); ?></h3>
                    </header>
                    <div class="eventcrew-card-body">
                        <form class="eventcrew-sales-desk" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr((string) $view['sales_action']); ?>">
                            <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                            <button type="submit" class="wp-element-button"><?php esc_html_e('Open the sales desk on this device', 'eventcrew'); ?></button>
                        </form>
                    </div>
                </section>
            <?php endif; ?>
            <section class="eventcrew-event eventcrew-me-card">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('My tasks', 'eventcrew'); ?></h3>
                </header>
                <?php if ([] === $eventcrew_my_upcoming) : ?>
                    <p class="eventcrew-muted eventcrew-card-body"><?php esc_html_e('You’re not signed up for anything yet — take a look at the board.', 'eventcrew'); ?></p>
                <?php else : ?>
                    <ul class="eventcrew-plain-list">
                        <?php foreach ($eventcrew_my_upcoming as $eventcrew_mine) : ?>
                            <li>
                                <span class="eventcrew-mine-label"><?php echo esc_html($eventcrew_mine['label']); ?></span>
                                <?php if ('' !== ($eventcrew_mine['dj'] ?? '')) : ?>
                                    <span class="eventcrew-event-dj"><?php echo esc_html($eventcrew_mine['dj']); ?></span>
                                <?php endif; ?>
                                <span class="eventcrew-mine-when">
                                    <?php echo esc_html($eventcrew_mine['when']); ?>
                                    · <a href="<?php echo esc_url((string) $eventcrew_mine['ticket_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Ticket', 'eventcrew'); ?></a>
                                    · <a href="<?php echo esc_url($eventcrew_mine['calendar_url']); ?>"><?php esc_html_e('Add to calendar', 'eventcrew'); ?></a>
                                </span>
                                <?php if (null !== ($eventcrew_mine['used'] ?? null)) : ?>
                                    <span class="eventcrew-used"><?php echo esc_html(\EventCrew\Support\TicketList::usedText((string) $eventcrew_mine['used'])); ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <?php if ([] !== $eventcrew_my_history) : ?>
                <section class="eventcrew-event eventcrew-me-card">
                    <header class="eventcrew-event-head">
                        <h3 class="eventcrew-event-title"><?php esc_html_e('What you’ve done', 'eventcrew'); ?></h3>
                    </header>
                    <ul class="eventcrew-plain-list">
                        <?php foreach ($eventcrew_my_history as $eventcrew_past) : ?>
                            <li>
                                <span class="eventcrew-mine-label"><?php echo esc_html($eventcrew_past['label']); ?></span>
                                <?php if ('' !== ($eventcrew_past['dj'] ?? '')) : ?>
                                    <span class="eventcrew-event-dj"><?php echo esc_html($eventcrew_past['dj']); ?></span>
                                <?php endif; ?>
                                <span class="eventcrew-mine-when">
                                    <?php echo esc_html($eventcrew_past['when']); ?>
                                    · <?php echo esc_html($eventcrew_past['status']); ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <section class="eventcrew-event eventcrew-me-card">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('Tickets', 'eventcrew'); ?></h3>
                </header>
                <div class="eventcrew-card-body">
                    <?php
                    // Spending a credit lives here rather than beside the credit
                    // count: what it produces is a ticket, and this is where the
                    // tickets are.
                    ?>
                    <?php if ('' !== $eventcrew_redeem_action && [] !== $eventcrew_ticket_dates) : ?>
                        <form class="eventcrew-action eventcrew-redeem" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_redeem_action); ?>">
                            <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                            <label for="eventcrew-ticket-date"><?php esc_html_e('Spend a free-entry credit on', 'eventcrew'); ?></label>
                            <select name="ticket_date" id="eventcrew-ticket-date">
                                <?php foreach ($eventcrew_ticket_dates as $eventcrew_date => $eventcrew_date_label) : ?>
                                    <option value="<?php echo esc_attr((string) $eventcrew_date); ?>"><?php echo esc_html($eventcrew_date_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="wp-element-button"><?php esc_html_e('Get my ticket', 'eventcrew'); ?></button>
                        </form>
                    <?php endif; ?>

                    <?php if ([] === $eventcrew_my_tickets['upcoming'] && [] === $eventcrew_my_tickets['past']) : ?>
                        <p class="eventcrew-muted"><?php esc_html_e('Each task’s ticket is on its row under My tasks. A free-entry credit you spend shows here.', 'eventcrew'); ?></p>
                    <?php else : ?>
                        <?php foreach (['upcoming' => __('Upcoming', 'eventcrew'), 'past' => __('Past', 'eventcrew')] as $eventcrew_group => $eventcrew_group_label) : ?>
                            <?php if ([] !== $eventcrew_my_tickets[$eventcrew_group]) : ?>
                                <p class="eventcrew-muted eventcrew-listhead"><strong><?php echo esc_html($eventcrew_group_label); ?></strong></p>
                                <ul class="eventcrew-plain-list eventcrew-ticket-list">
                                    <?php foreach ($eventcrew_my_tickets[$eventcrew_group] as $eventcrew_ticket) : ?>
                                        <li>
                                            <a class="eventcrew-mine-label" href="<?php echo esc_url($eventcrew_ticket['url']); ?>" target="_blank" rel="noopener">
                                                <?php echo esc_html($eventcrew_ticket['label']); ?>
                                            </a>
                                            <span class="eventcrew-mine-when"><?php echo esc_html(\EventCrew\Web\SignupBoardView::humanDate((string) $eventcrew_ticket['when'])); ?></span>
                                            <?php if (null !== ($eventcrew_ticket['used'] ?? null)) : ?>
                                                <span class="eventcrew-used"><?php echo esc_html(\EventCrew\Support\TicketList::usedText((string) $eventcrew_ticket['used'])); ?></span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </section>

        <section class="eventcrew-panel" id="eventcrew-panel-settings" data-eventcrew-panel="settings" aria-labelledby="eventcrew-tab-settings">
            <h2 class="eventcrew-panel-title"><?php esc_html_e('Settings', 'eventcrew'); ?></h2>

            <?php
            $eventcrew_profile_action = (string) ($view['profile_action'] ?? '');

            if ('' !== $eventcrew_profile_action && null !== $eventcrew_person) :
                $eventcrew_profile_email = \EventCrew\Models\Person::isPlaceholderEmail($eventcrew_person->email) ? '' : $eventcrew_person->email;
                ?>
                <section class="eventcrew-event eventcrew-me-card">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('Your details', 'eventcrew'); ?></h3>
                </header>
                <form class="eventcrew-card-body eventcrew-profile" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_profile_action); ?>">
                    <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                    <label for="eventcrew-profile-name"><?php esc_html_e('Name', 'eventcrew'); ?></label>
                    <input type="text" id="eventcrew-profile-name" name="name" required maxlength="191" autocomplete="name"
                        class="wp-element-input" value="<?php echo esc_attr($eventcrew_person->displayName); ?>">
                    <label for="eventcrew-profile-email"><?php esc_html_e('Email', 'eventcrew'); ?></label>
                    <input type="email" id="eventcrew-profile-email" name="email" autocomplete="email"
                        class="wp-element-input" value="<?php echo esc_attr($eventcrew_profile_email); ?>">
                    <p class="eventcrew-muted"><?php esc_html_e('A new email address takes effect when you open the link we send to it.', 'eventcrew'); ?></p>
                    <button type="submit" class="wp-element-button eventcrew-linkbtn eventcrew-settings-btn"><?php esc_html_e('Save details', 'eventcrew'); ?></button>
                </form>
                </section>
            <?php endif; ?>

            <?php if ('' !== $eventcrew_prefs_action && [] !== $eventcrew_notify_matrix) : ?>
                <section class="eventcrew-event eventcrew-me-card">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('Notifications', 'eventcrew'); ?></h3>
                </header>
                <form class="eventcrew-prefs eventcrew-card-body" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_prefs_action); ?>">
                    <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                    <table class="eventcrew-matrix">
                        <thead>
                            <tr>
                                <th></th>
                                <th><?php esc_html_e('Telegram', 'eventcrew'); ?></th>
                                <th><?php esc_html_e('Email', 'eventcrew'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($eventcrew_notify_matrix as $eventcrew_type => $eventcrew_pref) : ?>
                            <tr>
                                <td><?php echo esc_html($eventcrew_pref['label']); ?></td>
                                <td class="eventcrew-tick">
                                    <input type="checkbox" name="prefs[<?php echo esc_attr((string) $eventcrew_type); ?>][dm]" value="1" <?php checked($eventcrew_pref['dm']); ?>>
                                </td>
                                <td class="eventcrew-tick">
                                    <input type="checkbox" name="prefs[<?php echo esc_attr((string) $eventcrew_type); ?>][email]" value="1" <?php checked($eventcrew_pref['email']); ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="eventcrew-muted"><?php esc_html_e('Signup confirmations and task reminders are always sent on both channels.', 'eventcrew'); ?></p>
                    <button type="submit" class="wp-element-button eventcrew-linkbtn eventcrew-settings-btn"><?php esc_html_e('Save preferences', 'eventcrew'); ?></button>
                </form>
                </section>
            <?php endif; ?>

            <?php
            $eventcrew_revoke_action = (string) ($view['revoke_action'] ?? '');

            if ('' !== $eventcrew_revoke_action) :
                ?>
                <section class="eventcrew-event eventcrew-me-card">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('Security', 'eventcrew'); ?></h3>
                </header>
                <div class="eventcrew-card-body">
                <p class="eventcrew-muted">
                    <?php esc_html_e('Signing in leaves this browser signed in for 30 days. If you have used a shared or borrowed device — or you think somebody else has your sign-in link — this signs out every browser and phone at once, including this one. Nothing is lost; you just sign in again.', 'eventcrew'); ?>
                </p>
                <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>"
                    onsubmit="return confirm('<?php echo esc_js(__('Sign out everywhere, including here?', 'eventcrew')); ?>');">
                    <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_revoke_action); ?>">
                    <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                    <button type="submit" class="eventcrew-textlink"><?php esc_html_e('Sign out everywhere', 'eventcrew'); ?></button>
                </form>
                </div>
                </section>
            <?php endif; ?>

            <?php if ('' !== $eventcrew_manage_token) : ?>
                <section class="eventcrew-event eventcrew-me-card">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('Account', 'eventcrew'); ?></h3>
                </header>
                <div class="eventcrew-card-body">
                <p class="eventcrew-muted"><?php esc_html_e('To stop individual emails, use Notifications above. To leave for good, delete your data — this erases your account and history and cannot be undone.', 'eventcrew'); ?></p>
                <form class="eventcrew-danger" method="post" action="<?php echo esc_url($eventcrew_manage_endpoint); ?>"
                    onsubmit="return confirm('<?php echo esc_js(__('Delete your account and all your history? This cannot be undone.', 'eventcrew')); ?>');">
                    <input type="hidden" name="token" value="<?php echo esc_attr($eventcrew_manage_token); ?>">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="eventcrew-textlink eventcrew-textlink-danger"><?php esc_html_e('Delete my data', 'eventcrew'); ?></button>
                </form>
                </div>
                </section>
            <?php endif; ?>

        </section>

        <?php
        /*
         * Help: the welcome text for everyone. An organizer also gets the
         * organizer guide lower down, with a link to it at the top. Both are
         * the same text the welcome message and the organizer guide send.
         */
        ?>
        <section class="eventcrew-panel eventcrew-help" id="<?php echo esc_attr(\EventCrew\Support\HelpPage::PANEL_ANCHOR); ?>" data-eventcrew-panel="help" aria-labelledby="eventcrew-tab-help">
            <h2 class="eventcrew-panel-title"><?php esc_html_e('Help', 'eventcrew'); ?></h2>

            <?php if ($eventcrew_person->isOrganizer) : ?>
                <p class="eventcrew-links eventcrew-help-orglink">
                    <a class="eventcrew-textlink" href="#<?php echo esc_attr(\EventCrew\Support\HelpPage::ORGANIZER_ANCHOR); ?>"><?php esc_html_e('🧭 Organizer guide ↓', 'eventcrew'); ?></a>
                </p>
            <?php endif; ?>

            <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HelpPage escapes every piece of text.
            echo \EventCrew\Support\HelpPage::cards(\EventCrew\Support\WelcomeSender::helpText());
            ?>

            <?php if ($eventcrew_person->isOrganizer) : ?>
                <div class="eventcrew-help-organizer" id="<?php echo esc_attr(\EventCrew\Support\HelpPage::ORGANIZER_ANCHOR); ?>">
                    <h2><?php esc_html_e('Organizer guide', 'eventcrew'); ?></h2>
                    <?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HelpPage escapes every piece of text.
                    echo \EventCrew\Support\HelpPage::cards(\EventCrew\Support\OrganizerGuide::full());

                    /*
                     * How this site is run, as the organizers wrote it in
                     * Settings > Web page. Organizers only: it names the host
                     * and the services behind the site.
                     */
                    $eventcrew_infrastructure = \EventCrew\Support\HelpPage::infrastructure();
                    if ('' !== $eventcrew_infrastructure) {
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HelpPage escapes every piece of text.
                        echo \EventCrew\Support\HelpPage::cards('## 🏗️ ' . __('Infrastructure', 'eventcrew') . "\n\n" . $eventcrew_infrastructure);
                    }
                    ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if (null !== $eventcrew_admin) : ?>
        <section class="eventcrew-panel eventcrew-admin" id="eventcrew-panel-admin" data-eventcrew-panel="admin" aria-labelledby="eventcrew-tab-admin">
            <h2 class="eventcrew-panel-title"><?php esc_html_e('Admin', 'eventcrew'); ?></h2>

            <?php
            /*
             * One card per job, the same frame as the Board and Me tabs, so the
             * tab reads as separate tools instead of one long form. The cards
             * are grouped into screens with a second row of tabs, Roster first:
             * marking the crew is the job done most. Without JavaScript the row
             * is a list of jump links and every group shows, one after another.
             */
            $eventcrew_subtabs = [
                'roster' => _x('Roster', 'Admin tab screen', 'eventcrew'),
                'tasks' => __('Tasks', 'eventcrew'),
                'door' => __('Door', 'eventcrew'),
                'people' => _x('People', 'Admin tab screen', 'eventcrew'),
                'telegram' => __('Telegram', 'eventcrew'),
            ];

            if (isset($eventcrew_admin['newsletter'])) {
                $eventcrew_subtabs['newsletter'] = __('Next events announcement', 'eventcrew');
            }

            if (isset($eventcrew_admin['followup'])) {
                $eventcrew_subtabs['followup'] = __('Post-event email', 'eventcrew');
            }

            if (isset($eventcrew_admin['ai_texts'])) {
                $eventcrew_subtabs['aitexts'] = __('AI texts', 'eventcrew');
            }
            ?>
            <nav class="eventcrew-subtabs" aria-label="<?php esc_attr_e('Admin screens', 'eventcrew'); ?>">
                <?php foreach ($eventcrew_subtabs as $eventcrew_sub_key => $eventcrew_sub_label) : ?>
                    <a class="eventcrew-subtab" href="#eventcrew-admin-<?php echo esc_attr($eventcrew_sub_key); ?>"
                        data-eventcrew-subtab="<?php echo esc_attr($eventcrew_sub_key); ?>"><?php echo esc_html($eventcrew_sub_label); ?></a>
                <?php endforeach; ?>
            </nav>

            <div class="eventcrew-subpanel" id="eventcrew-admin-telegram" data-eventcrew-subpanel="telegram">
            <section class="eventcrew-event eventcrew-admin-card">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('Telegram board', 'eventcrew'); ?></h3>
                </header>
                <div class="eventcrew-card-body">
                    <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['board_action']); ?>">
                        <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                        <button type="submit" class="wp-element-button"><?php esc_html_e('Post a new board in the Telegram group', 'eventcrew'); ?></button>
                    </form>
                </div>
            </section>
            </div>

            <div class="eventcrew-subpanel" id="eventcrew-admin-people" data-eventcrew-subpanel="people">
            <?php
            /*
             * The People screen: the wp-admin People page without the organizer
             * flag and without deleting. The search, the list page and the open
             * person are in the page address, so every form here sends the page
             * back to the same view.
             */
            $eventcrew_people = $eventcrew_admin['people'];
            $eventcrew_editing = $eventcrew_people['editing'];
            $eventcrew_people_args = [
                'people_q' => '' === $eventcrew_people['search'] ? false : $eventcrew_people['search'],
                'people_page' => $eventcrew_people['page'] > 1 ? $eventcrew_people['page'] : false,
            ];
            $eventcrew_people_list = add_query_arg($eventcrew_people_args, $eventcrew_here) . '#eventcrew-admin-people';
            $eventcrew_people_back = null === $eventcrew_editing
                ? $eventcrew_people_list
                : add_query_arg(['person_id' => $eventcrew_editing['person']->id] + $eventcrew_people_args, $eventcrew_here) . '#eventcrew-admin-people';
            // A GET form drops the query of its action, so a permalink like
            // ?page_id=5 goes along as hidden fields.
            $eventcrew_people_query = [];
            parse_str((string) wp_parse_url($eventcrew_here, PHP_URL_QUERY), $eventcrew_people_query);
            unset($eventcrew_people_query['people_q'], $eventcrew_people_query['people_page'], $eventcrew_people_query['person_id']);
            ?>
            <section class="eventcrew-event eventcrew-admin-card">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('People', 'eventcrew'); ?></h3>
                    <span class="eventcrew-muted">
                        <?php
                        /* translators: %d: how many people the list holds */
                        echo esc_html(sprintf(_n('%d person', '%d people', $eventcrew_people['total'], 'eventcrew'), $eventcrew_people['total']));
                        ?>
                    </span>
                </header>
                <div class="eventcrew-card-body">
                    <form method="get" action="<?php echo esc_url($eventcrew_here . '#eventcrew-admin-people'); ?>" class="eventcrew-admin-search">
                        <?php foreach ($eventcrew_people_query as $eventcrew_query_key => $eventcrew_query_value) : ?>
                            <?php if (is_scalar($eventcrew_query_value)) : ?>
                                <input type="hidden" name="<?php echo esc_attr((string) $eventcrew_query_key); ?>" value="<?php echo esc_attr((string) $eventcrew_query_value); ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <input id="eventcrew-admin-people-q" type="search" name="people_q" maxlength="100" aria-label="<?php esc_attr_e('Search people', 'eventcrew'); ?>" value="<?php echo esc_attr($eventcrew_people['search']); ?>">
                        <button type="submit" class="wp-element-button is-style-outline"><?php esc_html_e('Search people', 'eventcrew'); ?></button>
                    </form>
                    <?php if ([] === $eventcrew_people['list']) : ?>
                        <p class="eventcrew-muted"><?php '' === $eventcrew_people['search'] ? esc_html_e('No people yet.', 'eventcrew') : esc_html_e('No people found.', 'eventcrew'); ?></p>
                    <?php else : ?>
                        <ul class="eventcrew-plain-list eventcrew-admin-people-list">
                            <?php foreach ($eventcrew_people['list'] as $eventcrew_row) : ?>
                                <li>
                                    <?php if ($eventcrew_row['anonymized']) : ?>
                                        <span class="eventcrew-muted"><?php echo esc_html($eventcrew_row['name']); ?> (<?php esc_html_e('Anonymised', 'eventcrew'); ?>)</span>
                                    <?php else : ?>
                                        <a href="<?php echo esc_url(add_query_arg(['person_id' => $eventcrew_row['id']] + $eventcrew_people_args, $eventcrew_here) . '#eventcrew-admin-people'); ?>"><strong><?php echo esc_html($eventcrew_row['name']); ?></strong></a>
                                        <?php if ($eventcrew_row['organizer']) : ?>
                                            · <?php esc_html_e('Organizer', 'eventcrew'); ?>
                                        <?php endif; ?>
                                        <?php if ($eventcrew_row['banned']) : ?>
                                            · <span class="eventcrew-admin-at-risk"><?php esc_html_e('Blocked', 'eventcrew'); ?></span>
                                        <?php endif; ?>
                                        <span class="eventcrew-muted eventcrew-admin-hint">
                                            <?php echo esc_html($eventcrew_row['email']); ?>
                                            <?php if (! $eventcrew_row['verified']) : ?>
                                                (<?php esc_html_e('unverified', 'eventcrew'); ?>)
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($eventcrew_people['pages'] > 1) : ?>
                        <p class="eventcrew-admin-actions">
                            <?php if ($eventcrew_people['page'] > 1) : ?>
                                <a class="eventcrew-textlink" href="<?php echo esc_url(add_query_arg(['people_page' => $eventcrew_people['page'] - 1] + $eventcrew_people_args, $eventcrew_here) . '#eventcrew-admin-people'); ?>"><?php esc_html_e('Previous', 'eventcrew'); ?></a>
                            <?php endif; ?>
                            <span class="eventcrew-muted">
                                <?php
                                /* translators: 1: this page of the list, 2: how many pages there are */
                                echo esc_html(sprintf(__('Page %1$d of %2$d', 'eventcrew'), $eventcrew_people['page'], $eventcrew_people['pages']));
                                ?>
                            </span>
                            <?php if ($eventcrew_people['page'] < $eventcrew_people['pages']) : ?>
                                <a class="eventcrew-textlink" href="<?php echo esc_url(add_query_arg(['people_page' => $eventcrew_people['page'] + 1] + $eventcrew_people_args, $eventcrew_here) . '#eventcrew-admin-people'); ?>"><?php esc_html_e('Next', 'eventcrew'); ?></a>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
            </section>

            <?php $eventcrew_edit_person = null === $eventcrew_editing ? null : $eventcrew_editing['person']; ?>
            <section class="eventcrew-event eventcrew-admin-card">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php null === $eventcrew_edit_person ? esc_html_e('Add a person', 'eventcrew') : esc_html_e('Edit person', 'eventcrew'); ?></h3>
                </header>
                <form class="eventcrew-card-body" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['person_action']); ?>">
                    <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_people_back); ?>">
                    <input type="hidden" name="person_id" value="<?php echo esc_attr(null === $eventcrew_edit_person ? '0' : (string) $eventcrew_edit_person->id); ?>">
                    <p>
                        <label for="eventcrew-admin-person-email"><?php esc_html_e('Email', 'eventcrew'); ?></label>
                        <input id="eventcrew-admin-person-email" type="email" name="email" maxlength="191" required value="<?php echo esc_attr(null === $eventcrew_edit_person ? '' : $eventcrew_edit_person->email); ?>">
                        <span class="eventcrew-muted eventcrew-admin-hint"><?php esc_html_e('This is how a person is identified across Telegram and the web.', 'eventcrew'); ?></span>
                    </p>
                    <p>
                        <label for="eventcrew-admin-person-name"><?php esc_html_e('Name', 'eventcrew'); ?></label>
                        <input id="eventcrew-admin-person-name" type="text" name="display_name" maxlength="100" value="<?php echo esc_attr(null === $eventcrew_edit_person ? '' : $eventcrew_edit_person->displayName); ?>">
                    </p>
                    <?php if ([] !== $eventcrew_admin['roles']) : ?>
                        <fieldset class="eventcrew-admin-roles">
                            <legend><?php esc_html_e('Roles', 'eventcrew'); ?></legend>
                            <?php foreach ($eventcrew_admin['roles'] as $eventcrew_role_slug => $eventcrew_role_label) : ?>
                                <label>
                                    <input type="checkbox" name="roles[]" value="<?php echo esc_attr($eventcrew_role_slug); ?>" <?php checked(null !== $eventcrew_edit_person && $eventcrew_edit_person->hasRole($eventcrew_role_slug)); ?>>
                                    <?php echo esc_html($eventcrew_role_label); ?>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <p class="eventcrew-muted eventcrew-admin-hint"><?php esc_html_e('Only people with a role see its tasks and can sign up for them.', 'eventcrew'); ?></p>
                    <?php endif; ?>
                    <p>
                        <label for="eventcrew-admin-person-notes"><?php esc_html_e('Notes', 'eventcrew'); ?></label>
                        <textarea id="eventcrew-admin-person-notes" name="notes" rows="3"><?php echo esc_textarea(null === $eventcrew_edit_person ? '' : $eventcrew_edit_person->notes); ?></textarea>
                    </p>
                    <div class="eventcrew-admin-actions">
                        <button type="submit" class="wp-element-button"><?php null === $eventcrew_edit_person ? esc_html_e('Add person', 'eventcrew') : esc_html_e('Update person', 'eventcrew'); ?></button>
                        <?php if (null !== $eventcrew_edit_person) : ?>
                            <a class="eventcrew-textlink" href="<?php echo esc_url($eventcrew_people_list); ?>"><?php esc_html_e('Cancel', 'eventcrew'); ?></a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>

            <?php if (null !== $eventcrew_edit_person) : ?>
                <section class="eventcrew-event eventcrew-admin-card">
                    <header class="eventcrew-event-head">
                        <h3 class="eventcrew-event-title"><?php esc_html_e('Status', 'eventcrew'); ?></h3>
                        <span class="eventcrew-muted"><?php echo esc_html($eventcrew_edit_person->name()); ?></span>
                    </header>
                    <div class="eventcrew-card-body">
                        <ul class="eventcrew-plain-list">
                            <li><?php esc_html_e('Email verified', 'eventcrew'); ?>:
                                <?php echo $eventcrew_edit_person->isEmailVerified() ? esc_html((string) $eventcrew_edit_person->emailVerifiedAt) : esc_html__('Not yet', 'eventcrew'); ?></li>
                            <li><?php esc_html_e('Telegram linked', 'eventcrew'); ?>:
                                <?php echo $eventcrew_edit_person->hasTelegram() ? esc_html__('Yes', 'eventcrew') : esc_html__('No', 'eventcrew'); ?></li>
                            <?php if ($eventcrew_edit_person->isOrganizer) : ?>
                                <li><?php esc_html_e('Organizer', 'eventcrew'); ?>: <?php esc_html_e('Yes', 'eventcrew'); ?></li>
                            <?php endif; ?>
                            <li><?php esc_html_e('Standing', 'eventcrew'); ?>:
                                <span<?php echo $eventcrew_editing['at_risk'] ? ' class="eventcrew-admin-at-risk"' : ''; ?>><?php echo esc_html($eventcrew_editing['standing']); ?></span></li>
                            <li><?php esc_html_e('Free-entry credits', 'eventcrew'); ?>: <?php echo esc_html((string) $eventcrew_editing['credits']); ?></li>
                        </ul>
                        <p class="eventcrew-muted eventcrew-admin-hint"><?php esc_html_e('Verifying an email and choosing which alerts to receive are both things only the person themselves can do, so neither can be set from this screen.', 'eventcrew'); ?></p>
                    </div>
                </section>

                <?php if ($eventcrew_edit_person->isBanned()) : ?>
                    <section class="eventcrew-event eventcrew-admin-card">
                        <header class="eventcrew-event-head">
                            <h3 class="eventcrew-event-title"><?php esc_html_e('Blocked', 'eventcrew'); ?></h3>
                        </header>
                        <form class="eventcrew-card-body" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['unban_action']); ?>">
                            <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_people_back); ?>">
                            <input type="hidden" name="person_id" value="<?php echo esc_attr((string) $eventcrew_edit_person->id); ?>">
                            <p>
                                <?php
                                printf(
                                    /* translators: %s: date and time the block was set */
                                    esc_html__('Blocked since %s. They cannot sign up for tasks or sign in on the web.', 'eventcrew'),
                                    esc_html((string) $eventcrew_edit_person->bannedAt)
                                );
                                ?>
                            </p>
                            <?php if ('' !== (string) $eventcrew_edit_person->banReason) : ?>
                                <p class="eventcrew-muted"><?php echo esc_html((string) $eventcrew_edit_person->banReason); ?></p>
                            <?php endif; ?>
                            <button type="submit" class="wp-element-button"><?php esc_html_e('Unban', 'eventcrew'); ?></button>
                        </form>
                    </section>
                <?php endif; ?>

                <section class="eventcrew-event eventcrew-admin-card">
                    <header class="eventcrew-event-head">
                        <h3 class="eventcrew-event-title"><?php esc_html_e('Give a free-entry credit', 'eventcrew'); ?></h3>
                    </header>
                    <form class="eventcrew-card-body" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['gift_action']); ?>">
                        <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_people_back); ?>">
                        <input type="hidden" name="person_id" value="<?php echo esc_attr((string) $eventcrew_edit_person->id); ?>">
                        <p class="eventcrew-admin-pair">
                            <span>
                                <label for="eventcrew-admin-person-credits"><?php esc_html_e('Credits', 'eventcrew'); ?></label>
                                <input id="eventcrew-admin-person-credits" type="number" name="credits" min="1" step="1" value="1" required>
                            </span>
                            <span>
                                <label for="eventcrew-admin-person-reason"><?php esc_html_e('Reason (optional)', 'eventcrew'); ?></label>
                                <input id="eventcrew-admin-person-reason" type="text" name="reason" maxlength="200" placeholder="<?php esc_attr_e('e.g. covered a cleaning task', 'eventcrew'); ?>">
                            </span>
                        </p>
                        <button type="submit" class="wp-element-button"><?php esc_html_e('Grant credits', 'eventcrew'); ?></button>
                        <p class="eventcrew-muted eventcrew-admin-hint"><?php esc_html_e('A one-off bonus, on top of the credits earned for completed tasks.', 'eventcrew'); ?></p>
                    </form>
                </section>

                <section class="eventcrew-event eventcrew-admin-card">
                    <header class="eventcrew-event-head">
                        <h3 class="eventcrew-event-title"><?php esc_html_e('One-time at-risk pass', 'eventcrew'); ?></h3>
                    </header>
                    <form class="eventcrew-card-body" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['pass_action']); ?>">
                        <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_people_back); ?>">
                        <input type="hidden" name="person_id" value="<?php echo esc_attr((string) $eventcrew_edit_person->id); ?>">
                        <button type="submit" class="wp-element-button is-style-outline"><?php $eventcrew_edit_person->hasAtRiskPass ? esc_html_e('Pass already granted — re-grant', 'eventcrew') : esc_html_e('Grant a one-time pass', 'eventcrew'); ?></button>
                        <p class="eventcrew-muted eventcrew-admin-hint"><?php esc_html_e('Lets them sign up once even if their standing is at risk. Spent automatically on their next successful signup.', 'eventcrew'); ?></p>
                    </form>
                </section>

                <section class="eventcrew-event eventcrew-admin-card">
                    <header class="eventcrew-event-head">
                        <h3 class="eventcrew-event-title"><?php esc_html_e('Credit log', 'eventcrew'); ?></h3>
                    </header>
                    <div class="eventcrew-card-body">
                        <?php if ([] === $eventcrew_editing['given'] && [] === $eventcrew_editing['spent']) : ?>
                            <p class="eventcrew-muted"><?php esc_html_e('No credits given or spent yet.', 'eventcrew'); ?></p>
                        <?php endif; ?>
                        <?php if ([] !== $eventcrew_editing['given']) : ?>
                            <h4><?php esc_html_e('Credits given', 'eventcrew'); ?></h4>
                            <ul class="eventcrew-plain-list">
                                <?php foreach ($eventcrew_editing['given'] as $eventcrew_given) : ?>
                                    <li>
                                        <strong>+<?php echo esc_html((string) $eventcrew_given['credits']); ?></strong>
                                        <?php echo '' === $eventcrew_given['note'] ? '&mdash;' : esc_html($eventcrew_given['note']); ?>
                                        <span class="eventcrew-muted eventcrew-admin-hint"><?php esc_html_e('Granted by', 'eventcrew'); ?>: <?php echo esc_html($eventcrew_given['granted_by']); ?> · <?php echo esc_html($eventcrew_given['granted_at']); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if ([] !== $eventcrew_editing['spent']) : ?>
                            <h4><?php esc_html_e('Credits spent', 'eventcrew'); ?></h4>
                            <ul class="eventcrew-plain-list">
                                <?php foreach ($eventcrew_editing['spent'] as $eventcrew_spent) : ?>
                                    <li>
                                        <strong>&minus;1</strong>
                                        <?php echo esc_html(trim($eventcrew_spent['day'] . ' ' . $eventcrew_spent['event'])); ?>
                                        <span class="eventcrew-muted eventcrew-admin-hint"><?php esc_html_e('Redeemed', 'eventcrew'); ?>: <?php echo esc_html($eventcrew_spent['redeemed_at']); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>
            </div>

            <div class="eventcrew-subpanel" id="eventcrew-admin-roster" data-eventcrew-subpanel="roster">
            <?php
            /*
             * The Roster and Door screens are for one date, picked in the date
             * bar at the top of each. Both read the same roster_date, so they
             * show the same night. Every form sends the page back with that
             * date and its own screen open.
             */
            $eventcrew_roster_date = $eventcrew_admin['roster_date'];
            $eventcrew_roster_base = '' === $eventcrew_roster_date
                ? $eventcrew_here
                : add_query_arg(['roster_date' => $eventcrew_roster_date], $eventcrew_here);
            $eventcrew_roster_here = $eventcrew_roster_base . '#eventcrew-admin-roster';
            $eventcrew_door_here = $eventcrew_roster_base . '#eventcrew-admin-door';
            $eventcrew_night = $eventcrew_admin['night'];

            if ('' !== $eventcrew_roster_date) {
                $eventcrew_bar_screen = 'roster';
                require EVENTCREW_PLUGIN_DIR . 'templates/public/signup-admin-datebar.php';
            }
            ?>
            <section class="eventcrew-event eventcrew-admin-card eventcrew-admin-crew">
                <?php if ('' === $eventcrew_roster_date) : ?>
                    <p class="eventcrew-card-body eventcrew-muted"><?php esc_html_e('No tasks have been created yet, so there is nothing to take a roster for.', 'eventcrew'); ?></p>
                <?php elseif ([] === $eventcrew_admin['roster']) : ?>
                    <p class="eventcrew-card-body eventcrew-muted"><?php esc_html_e('No tasks on that date.', 'eventcrew'); ?></p>
                <?php endif; ?>
                <?php foreach ($eventcrew_admin['roster'] as $eventcrew_roster_task) : ?>
                    <?php $eventcrew_attend_form = 'eventcrew-admin-attend-' . $eventcrew_roster_task['task_id']; ?>
                    <h4 class="eventcrew-admin-task"><?php echo esc_html($eventcrew_roster_task['title']); ?></h4>
                    <?php if ([] === $eventcrew_roster_task['people']) : ?>
                        <p class="eventcrew-admin-nobody eventcrew-muted"><?php esc_html_e('Nobody signed up.', 'eventcrew'); ?></p>
                    <?php endif; ?>
                    <ul class="eventcrew-plain-list eventcrew-admin-roster">
                        <?php foreach ($eventcrew_roster_task['people'] as $eventcrew_roster_entry) : ?>
                            <li>
                                <span class="eventcrew-admin-who">
                                    <?php echo esc_html($eventcrew_roster_entry['name']); ?>
                                    <?php if ('' !== $eventcrew_roster_entry['signed_up']) : ?>
                                        <span class="eventcrew-muted"><?php
                                            /* translators: %s: when the person signed up, e.g. "2 Nov 14:05" */
                                            echo esc_html(sprintf(__('signed up %s', 'eventcrew'), $eventcrew_roster_entry['signed_up']));
                                        ?></span>
                                    <?php endif; ?>
                                    <?php if ('' !== $eventcrew_roster_entry['standing']) : ?>
                                        <small class="eventcrew-muted<?php echo $eventcrew_roster_entry['at_risk'] ? ' eventcrew-admin-at-risk' : ''; ?>"><?php echo esc_html($eventcrew_roster_entry['standing']); ?></small>
                                    <?php endif; ?>
                                    <?php if ('' !== $eventcrew_roster_entry['marked_by']) : ?>
                                        <small class="eventcrew-muted"><?php echo esc_html($eventcrew_roster_entry['marked_by']); ?></small>
                                    <?php endif; ?>
                                </span>
                                <?php /* Saved as soon as it changes: the picker posts this task's form below, by its form attribute. */ ?>
                                <select name="status[<?php echo esc_attr((string) $eventcrew_roster_entry['assignment_id']); ?>]" form="<?php echo esc_attr($eventcrew_attend_form); ?>" aria-label="<?php esc_attr_e('Status', 'eventcrew'); ?>"
                                    onchange="if (this.form.requestSubmit) { this.form.requestSubmit(); } else { this.form.submit(); }">
                                    <?php foreach ($eventcrew_admin['statuses'] as $eventcrew_status_value => $eventcrew_status_label) : ?>
                                        <option value="<?php echo esc_attr($eventcrew_status_value); ?>"<?php echo $eventcrew_status_value === $eventcrew_roster_entry['status'] ? ' selected' : ''; ?>><?php echo esc_html($eventcrew_status_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="shown[<?php echo esc_attr((string) $eventcrew_roster_entry['assignment_id']); ?>]" value="<?php echo esc_attr($eventcrew_roster_entry['status']); ?>" form="<?php echo esc_attr($eventcrew_attend_form); ?>">
                                <noscript><button type="submit" form="<?php echo esc_attr($eventcrew_attend_form); ?>" class="eventcrew-textlink"><?php esc_html_e('Save', 'eventcrew'); ?></button></noscript>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ([] !== $eventcrew_roster_task['people']) : ?>
                        <form id="<?php echo esc_attr($eventcrew_attend_form); ?>" class="eventcrew-admin-actions eventcrew-admin-attend" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['roster_action']); ?>">
                            <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_roster_here); ?>">
                            <input type="hidden" name="roster_date" value="<?php echo esc_attr($eventcrew_roster_date); ?>">
                            <input type="hidden" name="task_id" value="<?php echo esc_attr((string) $eventcrew_roster_task['task_id']); ?>">
                            <button type="submit" name="mark_all" value="arrived" class="eventcrew-textlink"><?php esc_html_e('Mark all arrived', 'eventcrew'); ?></button>
                            <button type="submit" name="mark_all" value="completed" class="eventcrew-textlink"><?php esc_html_e('Mark all completed', 'eventcrew'); ?></button>
                        </form>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php if ([] !== $eventcrew_admin['add_tasks']) : ?>
                <details class="eventcrew-admin-add">
                    <summary class="eventcrew-textlink">+ <?php esc_html_e('Add a person', 'eventcrew'); ?></summary>
                    <form class="eventcrew-card-body" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['add_action']); ?>">
                        <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                        <p class="eventcrew-muted"><?php esc_html_e('For a member, or for someone who is not a crew member yet. On a task of a role marked "Only people with this role", they get the role too.', 'eventcrew'); ?></p>
                        <fieldset class="eventcrew-admin-tasks">
                            <legend><?php esc_html_e('Tasks (tick one or more)', 'eventcrew'); ?></legend>
                            <?php foreach ($eventcrew_admin['add_tasks'] as $eventcrew_add_task_id => $eventcrew_add_task_label) : ?>
                                <label><input type="checkbox" name="task_ids[]" value="<?php echo esc_attr((string) $eventcrew_add_task_id); ?>"> <?php echo esc_html($eventcrew_add_task_label); ?></label>
                            <?php endforeach; ?>
                        </fieldset>
                        <p>
                            <label for="eventcrew-admin-add-member"><?php esc_html_e('Person', 'eventcrew'); ?></label>
                            <select id="eventcrew-admin-add-member" name="person_id">
                                <option value="0"><?php esc_html_e('New person (fill in the name below)', 'eventcrew'); ?></option>
                                <?php foreach ($eventcrew_admin['members'] as $eventcrew_member_id => $eventcrew_member_label) : ?>
                                    <option value="<?php echo esc_attr((string) $eventcrew_member_id); ?>"><?php echo esc_html($eventcrew_member_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <p>
                            <label for="eventcrew-admin-add-name"><?php esc_html_e('Name (new person only)', 'eventcrew'); ?></label>
                            <input id="eventcrew-admin-add-name" type="text" name="name" maxlength="100">
                        </p>
                        <p>
                            <label for="eventcrew-admin-add-email"><?php esc_html_e('Email (new person only, optional)', 'eventcrew'); ?></label>
                            <input id="eventcrew-admin-add-email" type="email" name="email" maxlength="191">
                            <span class="eventcrew-muted eventcrew-admin-hint"><?php esc_html_e('With an email, they get their ticket by email. An email that belongs to a member adds that member.', 'eventcrew'); ?></span>
                        </p>
                        <p><?php esc_html_e('Count toward free-entry credit?', 'eventcrew'); ?>
                            <span class="eventcrew-muted eventcrew-admin-hint"><?php esc_html_e('Asked only for a role task; every other task always counts.', 'eventcrew'); ?></span></p>
                        <div class="eventcrew-admin-actions">
                            <button type="submit" name="counts_credit" value="1" class="wp-element-button"><?php esc_html_e('Yes', 'eventcrew'); ?></button>
                            <button type="submit" name="counts_credit" value="0" class="eventcrew-textlink"><?php esc_html_e('No', 'eventcrew'); ?></button>
                        </div>
                    </form>
                </details>
            <?php endif; ?>
            </section>
            </div>

            <div class="eventcrew-subpanel" id="eventcrew-admin-door" data-eventcrew-subpanel="door">
            <section class="eventcrew-event eventcrew-admin-card">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('Sales desk', 'eventcrew'); ?></h3>
                </header>
                <div class="eventcrew-card-body eventcrew-admin-actions">
                    <?php $eventcrew_can_open_desk = '' !== (string) ($view['sales_action'] ?? ''); ?>
                    <?php if ($eventcrew_can_open_desk) : ?>
                        <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr((string) $view['sales_action']); ?>">
                            <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                            <button type="submit" class="wp-element-button"><?php esc_html_e('Open the sales desk', 'eventcrew'); ?></button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['sales_action']); ?>">
                        <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                        <button type="submit" class="<?php echo $eventcrew_can_open_desk ? 'eventcrew-textlink' : 'wp-element-button'; ?>"><?php esc_html_e('Send me the link', 'eventcrew'); ?></button>
                    </form>
                </div>
            </section>

            <?php if ('' !== $eventcrew_roster_date) : ?>
                <?php
                $eventcrew_bar_screen = 'door';
                require EVENTCREW_PLUGIN_DIR . 'templates/public/signup-admin-datebar.php';
                ?>
                <section class="eventcrew-event eventcrew-admin-card">
                    <header class="eventcrew-event-head">
                        <h3 class="eventcrew-event-title"><?php esc_html_e('Door list', 'eventcrew'); ?></h3>
                        <span class="eventcrew-muted"><?php echo esc_html($eventcrew_admin['roster_label']); ?></span>
                    </header>
                    <div class="eventcrew-card-body">
                        <p class="eventcrew-muted"><?php esc_html_e('Everyone who gets in free that night — working the event, or spending a credit.', 'eventcrew'); ?></p>
                        <?php if ($eventcrew_night['closed_by_hand']) : ?>
                            <p class="eventcrew-admin-at-risk"><strong><?php esc_html_e('Free entry is closed for this date — no credits can be spent on it.', 'eventcrew'); ?></strong></p>
                        <?php endif; ?>
                        <?php if ('' !== $eventcrew_night['event_reason']) : ?>
                            <?php
                            $eventcrew_reasons = [
                                'unpublished' => __('is not published (removed from the shop)', 'eventcrew'),
                                'disabled' => __('is disabled', 'eventcrew'),
                                'hidden' => __('is hidden', 'eventcrew'),
                                'sold_out' => __('is sold out', 'eventcrew'),
                            ];
                            ?>
                            <p class="eventcrew-admin-at-risk"><strong><?php
                                echo esc_html(sprintf(
                                    /* translators: %s: the reason, e.g. "is sold out". */
                                    __('No credits can be spent on this date: the linked event %s. Task sign-up stays open.', 'eventcrew'),
                                    $eventcrew_reasons[$eventcrew_night['event_reason']] ?? $eventcrew_night['event_reason']
                                ));
                            ?></strong></p>
                        <?php endif; ?>
                        <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['door_action']); ?>">
                            <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_door_here); ?>">
                            <input type="hidden" name="roster_date" value="<?php echo esc_attr($eventcrew_roster_date); ?>">
                            <button type="submit" name="op" value="toggle" class="eventcrew-textlink">
                                <?php echo $eventcrew_night['closed_by_hand']
                                    ? esc_html__('Reopen free entry', 'eventcrew')
                                    : esc_html__('Close free entry (sold out)', 'eventcrew'); ?>
                            </button>
                        </form>
                        <?php if ([] === $eventcrew_night['entrants']) : ?>
                            <p class="eventcrew-muted"><?php esc_html_e('Nobody on the door list yet.', 'eventcrew'); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if ([] !== $eventcrew_night['entrants']) : ?>
                        <ul class="eventcrew-plain-list eventcrew-admin-roster">
                            <?php foreach ($eventcrew_night['entrants'] as $eventcrew_entrant) : ?>
                                <li>
                                    <span class="eventcrew-admin-who">
                                        <?php echo esc_html($eventcrew_entrant['name']); ?>
                                        <span class="eventcrew-muted"><?php echo esc_html($eventcrew_entrant['detail']); ?></span>
                                        <span class="eventcrew-muted<?php echo $eventcrew_entrant['at_risk'] ? ' eventcrew-admin-at-risk' : ''; ?>"><?php echo esc_html($eventcrew_entrant['standing']); ?></span>
                                    </span>
                                    <?php if (null !== $eventcrew_entrant['redemption_id']) : ?>
                                        <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                                            <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['door_action']); ?>">
                                            <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_door_here); ?>">
                                            <input type="hidden" name="roster_date" value="<?php echo esc_attr($eventcrew_roster_date); ?>">
                                            <input type="hidden" name="redemption_id" value="<?php echo esc_attr((string) $eventcrew_entrant['redemption_id']); ?>">
                                            <button type="submit" name="op" value="unredeem" class="eventcrew-textlink eventcrew-textlink-danger"><?php esc_html_e('Remove', 'eventcrew'); ?></button>
                                        </form>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ([] !== $eventcrew_night['candidates'] && ! $eventcrew_night['closed_by_hand']) : ?>
                        <form class="eventcrew-card-body" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['door_action']); ?>">
                            <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_door_here); ?>">
                            <input type="hidden" name="roster_date" value="<?php echo esc_attr($eventcrew_roster_date); ?>">
                            <p>
                                <label for="eventcrew-admin-redeem"><?php esc_html_e('Redeem a credit for', 'eventcrew'); ?></label>
                                <select id="eventcrew-admin-redeem" name="person_id" required>
                                    <?php foreach ($eventcrew_night['candidates'] as $eventcrew_candidate) : ?>
                                        <option value="<?php echo esc_attr((string) $eventcrew_candidate['person_id']); ?>"><?php
                                            echo esc_html(sprintf(
                                                /* translators: 1: person name, 2: credit balance */
                                                __('%1$s (%2$d credits)', 'eventcrew'),
                                                $eventcrew_candidate['name'],
                                                $eventcrew_candidate['credit_balance']
                                            ));
                                        ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </p>
                            <button type="submit" name="op" value="redeem" class="wp-element-button"><?php esc_html_e('Redeem', 'eventcrew'); ?></button>
                        </form>
                    <?php endif; ?>
                </section>

                <section class="eventcrew-event eventcrew-admin-card">
                    <header class="eventcrew-event-head">
                        <h3 class="eventcrew-event-title"><?php esc_html_e('Guest list', 'eventcrew'); ?></h3>
                        <span class="eventcrew-muted"><?php echo esc_html($eventcrew_admin['roster_label']); ?></span>
                    </header>
                    <p class="eventcrew-card-body eventcrew-muted"><?php esc_html_e('People let in free who are not crew members. The bot’s /door command keeps the same list.', 'eventcrew'); ?></p>
                    <ul class="eventcrew-plain-list eventcrew-admin-guests">
                        <?php foreach ($eventcrew_night['guests'] as $eventcrew_guest) : ?>
                            <?php $eventcrew_guest_field = 'eventcrew-admin-guest-' . $eventcrew_guest['id']; ?>
                            <li>
                                <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                                    <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['guest_action']); ?>">
                                    <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_door_here); ?>">
                                    <input type="hidden" name="roster_date" value="<?php echo esc_attr($eventcrew_roster_date); ?>">
                                    <input type="hidden" name="guest_id" value="<?php echo esc_attr((string) $eventcrew_guest['id']); ?>">
                                    <p class="eventcrew-admin-pair">
                                        <span>
                                            <label for="<?php echo esc_attr($eventcrew_guest_field); ?>-name"><?php esc_html_e('Name', 'eventcrew'); ?></label>
                                            <input id="<?php echo esc_attr($eventcrew_guest_field); ?>-name" type="text" name="guest_name" required maxlength="191" value="<?php echo esc_attr($eventcrew_guest['name']); ?>">
                                        </span>
                                        <span>
                                            <label for="<?php echo esc_attr($eventcrew_guest_field); ?>-plus"><?php esc_html_e('Plus-ones', 'eventcrew'); ?></label>
                                            <input id="<?php echo esc_attr($eventcrew_guest_field); ?>-plus" type="number" name="guest_plus_ones" min="0" max="<?php echo esc_attr((string) $eventcrew_admin['max_plus_ones']); ?>" value="<?php echo esc_attr((string) $eventcrew_guest['plus_ones']); ?>">
                                        </span>
                                    </p>
                                    <p>
                                        <label for="<?php echo esc_attr($eventcrew_guest_field); ?>-note"><?php esc_html_e('Note', 'eventcrew'); ?></label>
                                        <input id="<?php echo esc_attr($eventcrew_guest_field); ?>-note" type="text" name="guest_note" maxlength="191" value="<?php echo esc_attr($eventcrew_guest['note']); ?>">
                                    </p>
                                    <p>
                                        <label for="<?php echo esc_attr($eventcrew_guest_field); ?>-email"><?php esc_html_e('Email (a new address gets a new ticket; the old ticket stops working)', 'eventcrew'); ?></label>
                                        <input id="<?php echo esc_attr($eventcrew_guest_field); ?>-email" type="email" name="guest_email" maxlength="191" autocomplete="off" value="<?php echo esc_attr($eventcrew_guest['email']); ?>">
                                    </p>
                                    <div class="eventcrew-admin-actions">
                                        <button type="submit" name="op" value="save" class="eventcrew-textlink"><?php esc_html_e('Save', 'eventcrew'); ?></button>
                                        <button type="submit" name="op" value="remove" formnovalidate class="eventcrew-textlink eventcrew-textlink-danger"><?php esc_html_e('Remove', 'eventcrew'); ?></button>
                                    </div>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ('' !== $eventcrew_night['ticket_mail_guests']) : ?>
                        <form class="eventcrew-card-body" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>"
                            onsubmit="return confirm('<?php echo esc_js(__('Email their tickets now to the guests who have not got one?', 'eventcrew')); ?>');">
                            <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['guest_action']); ?>">
                            <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_roster_here); ?>">
                            <input type="hidden" name="roster_date" value="<?php echo esc_attr($eventcrew_roster_date); ?>">
                            <p class="eventcrew-muted"><?php echo esc_html($eventcrew_night['ticket_mail_guests']); ?></p>
                            <button type="submit" name="op" value="mail_rest" formnovalidate class="eventcrew-textlink"><?php esc_html_e('Send the rest now', 'eventcrew'); ?></button>
                        </form>
                    <?php endif; ?>
                    <form class="eventcrew-card-body" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['guest_action']); ?>">
                        <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_door_here); ?>">
                        <input type="hidden" name="roster_date" value="<?php echo esc_attr($eventcrew_roster_date); ?>">
                        <input type="hidden" name="guest_id" value="0">
                        <p class="eventcrew-admin-pair">
                            <span>
                                <label for="eventcrew-admin-guest-new-name"><?php esc_html_e('Name', 'eventcrew'); ?></label>
                                <input id="eventcrew-admin-guest-new-name" type="text" name="guest_name" required maxlength="191" placeholder="<?php esc_attr_e('New guest’s name', 'eventcrew'); ?>">
                            </span>
                            <span>
                                <label for="eventcrew-admin-guest-new-plus"><?php esc_html_e('Plus-ones', 'eventcrew'); ?></label>
                                <input id="eventcrew-admin-guest-new-plus" type="number" name="guest_plus_ones" min="0" max="<?php echo esc_attr((string) $eventcrew_admin['max_plus_ones']); ?>" value="0">
                            </span>
                        </p>
                        <p>
                            <label for="eventcrew-admin-guest-new-note"><?php esc_html_e('Note', 'eventcrew'); ?></label>
                            <input id="eventcrew-admin-guest-new-note" type="text" name="guest_note" maxlength="191">
                        </p>
                        <p>
                            <label for="eventcrew-admin-guest-new-email"><?php esc_html_e('Email (optional: the ticket is emailed)', 'eventcrew'); ?></label>
                            <input id="eventcrew-admin-guest-new-email" type="email" name="guest_email" maxlength="191" autocomplete="off">
                        </p>
                        <button type="submit" name="op" value="save" class="wp-element-button"><?php esc_html_e('Add guest', 'eventcrew'); ?></button>
                    </form>
                </section>

                <section class="eventcrew-event eventcrew-admin-card">
                    <header class="eventcrew-event-head">
                        <h3 class="eventcrew-event-title"><?php esc_html_e('Holvi ticket buyers', 'eventcrew'); ?></h3>
                        <span class="eventcrew-muted"><?php echo esc_html($eventcrew_admin['roster_label']); ?></span>
                    </header>
                    <div class="eventcrew-card-body">
                        <p class="eventcrew-muted">
                            <?php
                            printf(
                                /* translators: %d: number of imported ticket buyers */
                                esc_html(_n('%d person bought a ticket in Holvi for this date.', '%d people bought a ticket in Holvi for this date.', count($eventcrew_night['buyers']), 'eventcrew')),
                                count($eventcrew_night['buyers'])
                            );
                            ?>
                        </p>
                        <?php if ([] !== $eventcrew_night['buyers']) : ?>
                            <ul class="eventcrew-admin-buyers">
                                <?php foreach ($eventcrew_night['buyers'] as $eventcrew_buyer_name) : ?>
                                    <li><?php echo esc_html($eventcrew_buyer_name); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if ('' !== $eventcrew_night['ticket_mail_pending']) : ?>
                            <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                                <input type="hidden" name="action"
                                    value="<?php echo esc_attr($eventcrew_admin['buyers_action']); ?>">
                                <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                                <input type="hidden" name="redirect_to"
                                    value="<?php echo esc_attr($eventcrew_roster_here); ?>">
                                <input type="hidden" name="roster_date"
                                    value="<?php echo esc_attr($eventcrew_roster_date); ?>">
                                <p><strong><?php echo esc_html($eventcrew_night['ticket_mail_pending']); ?></strong></p>
                                <button type="submit" name="op" value="mail_cancel"
                                    class="eventcrew-textlink eventcrew-textlink-danger">
                                    <?php esc_html_e('Cancel sending', 'eventcrew'); ?>
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php if ('' !== $eventcrew_night['ticket_mail_buyers']) : ?>
                            <p class="eventcrew-muted"><?php echo esc_html($eventcrew_night['ticket_mail_buyers']); ?></p>
                            <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                                <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['buyers_action']); ?>">
                                <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_roster_here); ?>">
                                <input type="hidden" name="roster_date" value="<?php echo esc_attr($eventcrew_roster_date); ?>">
                                <button type="submit" name="op" value="mail_test" class="eventcrew-textlink"><?php esc_html_e('Send a test to me', 'eventcrew'); ?></button>
                            </form>
                            <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>"
                                onsubmit="return confirm('<?php echo esc_js(__('Email their tickets now to the buyers who have not got one?', 'eventcrew')); ?>');">
                                <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['buyers_action']); ?>">
                                <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_roster_here); ?>">
                                <input type="hidden" name="roster_date" value="<?php echo esc_attr($eventcrew_roster_date); ?>">
                                <button type="submit" name="op" value="mail_rest" class="eventcrew-textlink"><?php esc_html_e('Send the rest now', 'eventcrew'); ?></button>
                            </form>
                        <?php endif; ?>
                        <?php if ('' !== $eventcrew_night['followup_mail']) : ?>
                            <p class="eventcrew-muted"><?php echo esc_html($eventcrew_night['followup_mail']); ?></p>
                            <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                                <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['buyers_action']); ?>">
                                <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_roster_here); ?>">
                                <input type="hidden" name="roster_date" value="<?php echo esc_attr($eventcrew_roster_date); ?>">
                                <button type="submit" name="op" value="followup_test" class="eventcrew-textlink"><?php esc_html_e('Send a test to me', 'eventcrew'); ?></button>
                            </form>
                            <?php if ($eventcrew_night['followup_can_stop']) : ?>
                                <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>"
                                    onsubmit="return confirm('<?php echo esc_js(__('Stop this night’s post-event email? It will not be sent.', 'eventcrew')); ?>');">
                                    <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['buyers_action']); ?>">
                                    <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_roster_here); ?>">
                                    <input type="hidden" name="roster_date" value="<?php echo esc_attr($eventcrew_roster_date); ?>">
                                    <button type="submit" name="op" value="followup_stop" class="eventcrew-textlink"><?php esc_html_e('Do not send the post-event email', 'eventcrew'); ?></button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                        <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['buyers_action']); ?>">
                            <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_door_here); ?>">
                            <input type="hidden" name="roster_date" value="<?php echo esc_attr($eventcrew_roster_date); ?>">
                            <p>
                                <label for="eventcrew-admin-buyer-names"><?php esc_html_e('Paste names, one per line', 'eventcrew'); ?></label>
                                <textarea id="eventcrew-admin-buyer-names" name="buyer_names" rows="6"></textarea>
                            </p>
                            <button type="submit" name="op" value="import" class="wp-element-button"><?php esc_html_e('Import', 'eventcrew'); ?></button>
                        </form>
                        <p class="eventcrew-muted eventcrew-admin-hint">
                            <?php esc_html_e('The names go to the date shown above. A name already on the list is skipped.', 'eventcrew'); ?>
                            <?php
                            if ('' !== $eventcrew_night['mailbox']) {
                                printf(
                                    /* translators: %s: the import email address */
                                    esc_html__('You can also email the ticket file (.xlsx or .csv) to %s. It always goes to the next event, and only mail from an organizer’s address is accepted. Names typed in the mail itself go to the guest list; a guest with an email address on the line gets a ticket by email.', 'eventcrew'),
                                    '<code>' . esc_html($eventcrew_night['mailbox']) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
                                );
                            }
                            ?>
                        </p>
                        <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>"
                            onsubmit="return confirm('<?php echo esc_js(__('Delete all imported ticket buyers for this date? Guests and the crew are not affected.', 'eventcrew')); ?>');">
                            <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['buyers_action']); ?>">
                            <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_door_here); ?>">
                            <input type="hidden" name="roster_date" value="<?php echo esc_attr($eventcrew_roster_date); ?>">
                            <button type="submit" name="op" value="reset" class="eventcrew-textlink"><?php esc_html_e('Reset import', 'eventcrew'); ?></button>
                        </form>
                        <?php if ('' !== $eventcrew_night['mail_last']) : ?>
                            <p class="eventcrew-muted"><?php echo esc_html($eventcrew_night['mail_last']); ?></p>
                        <?php endif; ?>
                        <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['mail_action']); ?>">
                            <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_door_here); ?>">
                            <button type="submit" class="eventcrew-textlink"><?php esc_html_e('Check the mailbox now', 'eventcrew'); ?></button>
                        </form>
                    </div>
                </section>
            <?php endif; ?>
            </div>

            <div class="eventcrew-subpanel" id="eventcrew-admin-tasks" data-eventcrew-subpanel="tasks">
            <?php
            /*
             * The wp-admin Tasks page, for one date at a time: the date's tasks
             * with their people, the task form, "Create an event's tasks", the
             * open-task email, and one role's settings. Every form sends the
             * page back here with the same date (and role) and this screen open.
             */
            $eventcrew_tasks = $eventcrew_admin['tasks'];
            $eventcrew_tasks_date = (string) $eventcrew_tasks['date'];
            $eventcrew_tasks_args = ['tasks_date' => $eventcrew_tasks_date];
            $eventcrew_tasks_here = add_query_arg($eventcrew_tasks_args, $eventcrew_here) . '#eventcrew-admin-tasks';
            $eventcrew_task_editing = $eventcrew_tasks['editing'];
            $eventcrew_task_is_edit = $eventcrew_task_editing instanceof \EventCrew\Models\Task;
            $eventcrew_task_events = $eventcrew_tasks['events_available'] ? $eventcrew_tasks['events'] : [];
            $eventcrew_task_other = (string) $eventcrew_tasks['event_other'];
            $eventcrew_task_event = $eventcrew_task_is_edit && null !== $eventcrew_task_editing->eventPostId
                ? (string) $eventcrew_task_editing->eventPostId
                : $eventcrew_task_other;
            // A new task starts on the shown date's event, and its start time is that event's.
            $eventcrew_task_new_event = $eventcrew_task_is_edit || [] === $eventcrew_task_events
                ? null
                : ($eventcrew_tasks['new_task_event'] ?? null);
            if (null !== $eventcrew_task_new_event) {
                $eventcrew_task_event = (string) $eventcrew_task_new_event['id'];
            }
            // datetime-local wants Y-m-d\TH:i; the column holds Y-m-d H:i:s.
            $eventcrew_task_local = static function (?string $stored): string {
                return null === $stored || '' === $stored ? '' : str_replace(' ', 'T', substr($stored, 0, 16));
            };
            // An option cannot break a line: the event's name and DJ line share one (EventName::oneLine()).
            $eventcrew_task_event_label = static function (array $event): string {
                $eventcrew_event_name = \EventCrew\Support\EventName::oneLine((int) $event['id'], \EventCrew\Support\EventName::lang(), (string) $event['title']);

                return '' === $event['date'] ? $eventcrew_event_name : $event['date'] . ' — ' . $eventcrew_event_name;
            };
            $eventcrew_role_editing = $eventcrew_tasks['role_editing'];
            // A GET form drops the query of its action, so a permalink like
            // ?page_id=5 goes along as hidden fields.
            $eventcrew_tasks_query = [];
            parse_str((string) wp_parse_url($eventcrew_here, PHP_URL_QUERY), $eventcrew_tasks_query);
            unset($eventcrew_tasks_query['tasks_date'], $eventcrew_tasks_query['tasks_task'], $eventcrew_tasks_query['tasks_role']);
            $eventcrew_tasks_hidden = static function (array $query): void {
                foreach ($query as $eventcrew_query_key => $eventcrew_query_value) {
                    if (is_scalar($eventcrew_query_value)) {
                        printf(
                            '<input type="hidden" name="%s" value="%s">',
                            esc_attr((string) $eventcrew_query_key),
                            esc_attr((string) $eventcrew_query_value)
                        );
                    }
                }
            };

            // The task form is drawn first while a task is open for editing,
            // so the Edit link lands on it; otherwise under the list.
            ob_start();
            ?>
            <section class="eventcrew-event eventcrew-admin-card" id="eventcrew-admin-task-form">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php echo $eventcrew_task_is_edit ? esc_html__('Edit task', 'eventcrew') : esc_html__('Add a task', 'eventcrew'); ?></h3>
                </header>
                <form class="eventcrew-card-body" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['task_action']); ?>">
                    <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_tasks_here); ?>">
                    <?php if ($eventcrew_task_is_edit) : ?>
                        <input type="hidden" name="task_id" value="<?php echo esc_attr((string) $eventcrew_task_editing->id); ?>">
                    <?php endif; ?>
                    <p>
                        <label for="eventcrew-admin-task-event"><?php esc_html_e('Event', 'eventcrew'); ?></label>
                        <?php if ([] !== $eventcrew_task_events) : ?>
                            <select name="event_choice" id="eventcrew-admin-task-event" data-eventcrew-task-events>
                                <?php foreach ($eventcrew_task_events as $eventcrew_event) : ?>
                                    <option value="<?php echo esc_attr((string) $eventcrew_event['id']); ?>"
                                        data-date="<?php echo esc_attr($eventcrew_event['date']); ?>"
                                        data-starts="<?php echo esc_attr($eventcrew_task_local($eventcrew_event['starts_at'])); ?>"
                                        data-ends="<?php echo esc_attr($eventcrew_task_local($eventcrew_event['ends_at'])); ?>"<?php echo (string) $eventcrew_event['id'] === $eventcrew_task_event ? ' selected' : ''; ?>><?php echo esc_html($eventcrew_task_event_label($eventcrew_event)); ?></option>
                                <?php endforeach; ?>
                                <option value="<?php echo esc_attr($eventcrew_task_other); ?>"<?php echo $eventcrew_task_other === $eventcrew_task_event ? ' selected' : ''; ?>><?php esc_html_e('Other (type a name)', 'eventcrew'); ?></option>
                            </select>
                            <span class="eventcrew-admin-hint eventcrew-muted"><?php esc_html_e('Picking an event fills in the date and times below. Change them afterwards if this task runs to its own schedule.', 'eventcrew'); ?></span>
                        <?php else : ?>
                            <input type="hidden" name="event_choice" value="<?php echo esc_attr($eventcrew_task_other); ?>">
                            <span class="eventcrew-admin-hint eventcrew-muted"><?php esc_html_e('No linkable events found. Type a name below instead.', 'eventcrew'); ?></span>
                        <?php endif; ?>
                    </p>
                    <p data-eventcrew-task-other<?php echo $eventcrew_task_other === $eventcrew_task_event ? '' : ' hidden'; ?>>
                        <label for="eventcrew-admin-task-event-label"><?php esc_html_e('Event name', 'eventcrew'); ?></label>
                        <input type="text" name="event_label" id="eventcrew-admin-task-event-label" value="<?php echo esc_attr($eventcrew_task_is_edit ? $eventcrew_task_editing->eventLabel : ''); ?>">
                    </p>
                    <p>
                        <label for="eventcrew-admin-task-date"><?php esc_html_e('Task date', 'eventcrew'); ?></label>
                        <input type="date" name="task_date" id="eventcrew-admin-task-date" required value="<?php echo esc_attr($eventcrew_task_is_edit ? $eventcrew_task_editing->taskDate : $eventcrew_tasks_date); ?>">
                        <span class="eventcrew-admin-hint eventcrew-muted"><?php esc_html_e('The day this task is filed under. A clean-up at 01:00 after a Saturday event still belongs to Saturday.', 'eventcrew'); ?></span>
                    </p>
                    <p>
                        <label for="eventcrew-admin-task-role"><?php esc_html_e('Role', 'eventcrew'); ?></label>
                        <select name="role_slug" id="eventcrew-admin-task-role" required>
                            <?php foreach ($eventcrew_tasks['roles'] as $eventcrew_task_role) : ?>
                                <option value="<?php echo esc_attr($eventcrew_task_role['slug']); ?>" data-capacity="<?php echo esc_attr((string) $eventcrew_task_role['capacity']); ?>"<?php echo $eventcrew_task_is_edit && $eventcrew_task_editing->roleSlug === $eventcrew_task_role['slug'] ? ' selected' : ''; ?>><?php
                                    echo esc_html(
                                        $eventcrew_task_role['archived']
                                            /* translators: %s: role label */
                                            ? sprintf(__('%s (archived)', 'eventcrew'), $eventcrew_task_role['label'])
                                            : $eventcrew_task_role['label']
                                    );
                                ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p>
                        <label for="eventcrew-admin-task-capacity"><?php esc_html_e('People needed', 'eventcrew'); ?></label>
                        <input type="number" name="capacity" id="eventcrew-admin-task-capacity" min="0" step="1" value="<?php echo esc_attr((string) ($eventcrew_task_is_edit ? $eventcrew_task_editing->capacity : 2)); ?>">
                        <span class="eventcrew-admin-hint eventcrew-muted"><?php esc_html_e('Defaults come from the role in Settings. Set to 0 to take a task off the board without deleting it.', 'eventcrew'); ?></span>
                    </p>
                    <p>
                        <label for="eventcrew-admin-task-plus-ones"><?php esc_html_e('+1 allowed', 'eventcrew'); ?></label>
                        <input type="number" name="plus_ones" id="eventcrew-admin-task-plus-ones" min="0" max="20" step="1" value="<?php echo esc_attr($eventcrew_task_is_edit ? (string) $eventcrew_task_editing->plusOnes : ''); ?>">
                        <span class="eventcrew-admin-hint eventcrew-muted"><?php esc_html_e('How many guests each person on this task may bring in for free. The sales desk adds this up over all of a person\'s tasks that night. Leave empty on a new task to use the role\'s default from Settings.', 'eventcrew'); ?></span>
                    </p>
                    <p>
                        <label for="eventcrew-admin-task-starts"><?php esc_html_e('Starts', 'eventcrew'); ?></label>
                        <input type="datetime-local" name="starts_at" id="eventcrew-admin-task-starts" required value="<?php echo esc_attr($eventcrew_task_local($eventcrew_task_is_edit ? $eventcrew_task_editing->startsAt : ($eventcrew_task_new_event['starts_at'] ?? null))); ?>">
                    </p>
                    <p>
                        <label for="eventcrew-admin-task-ends"><?php esc_html_e('Ends', 'eventcrew'); ?></label>
                        <input type="datetime-local" name="ends_at" id="eventcrew-admin-task-ends" value="<?php echo esc_attr($eventcrew_task_local($eventcrew_task_is_edit ? $eventcrew_task_editing->endsAt : null)); ?>">
                        <span class="eventcrew-admin-hint eventcrew-muted"><?php esc_html_e('The start time is required. Leave the end blank if it is not decided. An end earlier than the start is read as the next morning.', 'eventcrew'); ?></span>
                    </p>
                    <p>
                        <label for="eventcrew-admin-task-notes"><?php esc_html_e('Notes', 'eventcrew'); ?></label>
                        <textarea name="notes" id="eventcrew-admin-task-notes" rows="3"><?php echo esc_textarea($eventcrew_task_is_edit ? $eventcrew_task_editing->notes : ''); ?></textarea>
                    </p>
                    <p class="eventcrew-admin-actions">
                        <button type="submit" class="wp-element-button"><?php echo $eventcrew_task_is_edit ? esc_html__('Update task', 'eventcrew') : esc_html__('Add task', 'eventcrew'); ?></button>
                        <?php if ($eventcrew_task_is_edit) : ?>
                            <a class="eventcrew-textlink" href="<?php echo esc_url($eventcrew_tasks_here); ?>"><?php esc_html_e('Cancel', 'eventcrew'); ?></a>
                        <?php endif; ?>
                    </p>
                </form>
                <?php if ($eventcrew_task_is_edit && [] !== $eventcrew_tasks['signed_up']) : ?>
                    <h4 class="eventcrew-admin-task"><?php esc_html_e('Signed up', 'eventcrew'); ?></h4>
                    <ul class="eventcrew-card-body eventcrew-plain-list">
                        <?php foreach ($eventcrew_tasks['signed_up'] as $eventcrew_task_person) : ?>
                            <li>
                                <?php echo esc_html($eventcrew_task_person['name']); ?>
                                <span class="eventcrew-muted">(<?php echo esc_html($eventcrew_task_person['status']); ?>)</span>
                                <?php if ('' !== $eventcrew_task_person['marked_by']) : ?>
                                    <span class="eventcrew-muted"><?php echo esc_html($eventcrew_task_person['marked_by']); ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
            <?php
            $eventcrew_task_form = (string) ob_get_clean();

            if ($eventcrew_task_is_edit) {
                echo $eventcrew_task_form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above, every part escaped.
            }
            ?>

            <section class="eventcrew-event eventcrew-admin-card">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('Tasks', 'eventcrew'); ?></h3>
                    <span class="eventcrew-muted"><?php echo esc_html($eventcrew_tasks['label']); ?></span>
                </header>
                <?php if ([] !== $eventcrew_tasks['upcoming'] || [] !== $eventcrew_tasks['past']) : ?>
                    <form class="eventcrew-card-body eventcrew-admin-date" method="get" action="<?php echo esc_url($eventcrew_here . '#eventcrew-admin-tasks'); ?>">
                        <?php $eventcrew_tasks_hidden($eventcrew_tasks_query); ?>
                        <label for="eventcrew-admin-tasks-date"><?php esc_html_e('Date', 'eventcrew'); ?></label>
                        <select id="eventcrew-admin-tasks-date" name="tasks_date" onchange="this.form.submit()">
                            <?php if (! isset($eventcrew_tasks['upcoming'][$eventcrew_tasks_date]) && ! isset($eventcrew_tasks['past'][$eventcrew_tasks_date])) : ?>
                                <option value="<?php echo esc_attr($eventcrew_tasks_date); ?>" selected><?php echo esc_html($eventcrew_tasks['label']); ?></option>
                            <?php endif; ?>
                            <?php if ([] !== $eventcrew_tasks['upcoming']) : ?>
                                <optgroup label="<?php esc_attr_e('Upcoming', 'eventcrew'); ?>">
                                    <?php foreach ($eventcrew_tasks['upcoming'] as $eventcrew_date_value => $eventcrew_date_label) : ?>
                                        <option value="<?php echo esc_attr((string) $eventcrew_date_value); ?>"<?php echo (string) $eventcrew_date_value === $eventcrew_tasks_date ? ' selected' : ''; ?>><?php echo esc_html($eventcrew_date_label); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                            <?php if ([] !== $eventcrew_tasks['past']) : ?>
                                <optgroup label="<?php esc_attr_e('Past', 'eventcrew'); ?>">
                                    <?php foreach ($eventcrew_tasks['past'] as $eventcrew_date_value => $eventcrew_date_label) : ?>
                                        <option value="<?php echo esc_attr((string) $eventcrew_date_value); ?>"<?php echo (string) $eventcrew_date_value === $eventcrew_tasks_date ? ' selected' : ''; ?>><?php echo esc_html($eventcrew_date_label); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                        <noscript><button type="submit" class="wp-element-button is-style-outline"><?php esc_html_e('Show', 'eventcrew'); ?></button></noscript>
                    </form>
                <?php endif; ?>
                <form class="eventcrew-card-body eventcrew-admin-date" method="get" action="<?php echo esc_url($eventcrew_here . '#eventcrew-admin-tasks'); ?>">
                    <?php $eventcrew_tasks_hidden($eventcrew_tasks_query); ?>
                    <label for="eventcrew-admin-tasks-new-date"><?php esc_html_e('Another date', 'eventcrew'); ?></label>
                    <input type="date" id="eventcrew-admin-tasks-new-date" name="tasks_date" required value="<?php echo esc_attr($eventcrew_tasks_date); ?>">
                    <button type="submit" class="wp-element-button is-style-outline"><?php esc_html_e('Show', 'eventcrew'); ?></button>
                </form>
                <?php if ([] === $eventcrew_tasks['list']) : ?>
                    <p class="eventcrew-card-body eventcrew-muted"><?php esc_html_e('No tasks on that date.', 'eventcrew'); ?></p>
                <?php endif; ?>
                <?php foreach ($eventcrew_tasks['list'] as $eventcrew_task_row) : ?>
                    <?php
                    $eventcrew_task_item = $eventcrew_task_row['task'];
                    $eventcrew_task_event_name = \EventCrew\Support\EventName::ofTask($eventcrew_task_item, \EventCrew\Support\EventName::lang());
                    ?>
                    <h4 class="eventcrew-admin-task"><?php echo esc_html($eventcrew_task_item->roleDisplay() . ' — ' . $eventcrew_task_event_name['name']); ?><?php if ('' !== $eventcrew_task_event_name['dj']) : ?><span class="eventcrew-event-dj"><?php echo esc_html($eventcrew_task_event_name['dj']); ?></span><?php endif; ?></h4>
                    <p class="eventcrew-admin-nobody eventcrew-muted"><?php
                        echo esc_html(trim(('' === $eventcrew_task_row['time'] ? '' : $eventcrew_task_row['time'] . ' · ') . $eventcrew_task_row['filled']));
                    ?></p>
                    <?php if ([] === $eventcrew_task_row['people']) : ?>
                        <p class="eventcrew-admin-nobody eventcrew-muted"><?php esc_html_e('Nobody signed up.', 'eventcrew'); ?></p>
                    <?php else : ?>
                        <ul class="eventcrew-plain-list eventcrew-admin-roster">
                            <?php foreach ($eventcrew_task_row['people'] as $eventcrew_task_person) : ?>
                                <li>
                                    <span class="eventcrew-admin-who">
                                        <?php echo esc_html($eventcrew_task_person['name']); ?>
                                        <span class="eventcrew-muted">(<?php echo esc_html($eventcrew_task_person['status']); ?>)</span>
                                        <?php if ('' !== $eventcrew_task_person['marked_by']) : ?>
                                            <span class="eventcrew-muted"><?php echo esc_html($eventcrew_task_person['marked_by']); ?></span>
                                        <?php endif; ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <div class="eventcrew-admin-actions eventcrew-admin-attend">
                        <a class="eventcrew-textlink" href="<?php echo esc_url(add_query_arg(['tasks_task' => $eventcrew_task_item->id] + $eventcrew_tasks_args, $eventcrew_here) . '#eventcrew-admin-tasks'); ?>"><?php esc_html_e('Edit', 'eventcrew'); ?></a>
                        <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>"
                            onsubmit="return confirm('<?php echo esc_js(__('Delete this task? Everyone signed up for it is taken off and emailed, and their tickets show DISABLED.', 'eventcrew')); ?>');">
                            <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['task_delete_action']); ?>">
                            <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_tasks_here); ?>">
                            <input type="hidden" name="task_id" value="<?php echo esc_attr((string) $eventcrew_task_item->id); ?>">
                            <button type="submit" class="eventcrew-textlink eventcrew-textlink-danger"><?php esc_html_e('Delete', 'eventcrew'); ?></button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </section>

            <?php
            if (! $eventcrew_task_is_edit) {
                echo $eventcrew_task_form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above, every part escaped.
            }
            ?>

            <?php if ([] !== $eventcrew_task_events && ! $eventcrew_task_is_edit) : ?>
                <section class="eventcrew-event eventcrew-admin-card">
                    <header class="eventcrew-event-head">
                        <h3 class="eventcrew-event-title"><?php esc_html_e('Create an event\'s tasks', 'eventcrew'); ?></h3>
                    </header>
                    <form class="eventcrew-card-body" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                        <p class="eventcrew-muted"><?php esc_html_e('Creates one task per active role, with times worked out from each role\'s offsets in Settings. Roles that already have a task for the event are left alone, so this is safe to run again after adding a role.', 'eventcrew'); ?></p>
                        <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['template_action']); ?>">
                        <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_tasks_here); ?>">
                        <p>
                            <label for="eventcrew-admin-template-event"><?php esc_html_e('Event', 'eventcrew'); ?></label>
                            <select name="template_event" id="eventcrew-admin-template-event">
                                <?php foreach ($eventcrew_task_events as $eventcrew_event) : ?>
                                    <option value="<?php echo esc_attr((string) $eventcrew_event['id']); ?>"><?php echo esc_html($eventcrew_task_event_label($eventcrew_event)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <button type="submit" class="wp-element-button is-style-outline"><?php esc_html_e('Create tasks', 'eventcrew'); ?></button>
                    </form>
                </section>
            <?php endif; ?>

            <?php
            $eventcrew_open_task_count = sprintf(
                /* translators: %d: number of active accounts */
                __('%d active account(s) would receive the open-task email.', 'eventcrew'),
                (int) $eventcrew_tasks['active_recipients']
            );
            ?>
            <section class="eventcrew-event eventcrew-admin-card">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('Open-task email', 'eventcrew'); ?></h3>
                </header>
                <div class="eventcrew-card-body">
                    <p><?php echo esc_html($eventcrew_open_task_count); ?></p>
                    <p class="eventcrew-muted"><?php esc_html_e('Sends to everyone with a verified, switched-on account, skipping anyone already signed up for that date. It only sends when there are open tasks, and never mails the same person twice for the same date. People switch it off by disabling their account (in the bot with /stop, or the link in any email).', 'eventcrew'); ?></p>
                    <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>"
                        onsubmit="return confirm('<?php echo esc_js($eventcrew_open_task_count); ?>');">
                        <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['open_task_action']); ?>">
                        <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_tasks_here); ?>">
                        <button type="submit" class="eventcrew-textlink"><?php esc_html_e('Send open-task email now', 'eventcrew'); ?></button>
                    </form>
                </div>
            </section>

            <section class="eventcrew-event eventcrew-admin-card" id="eventcrew-admin-role-form">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('Roles', 'eventcrew'); ?></h3>
                </header>
                <form class="eventcrew-card-body eventcrew-admin-date" method="get" action="<?php echo esc_url($eventcrew_here . '#eventcrew-admin-tasks'); ?>">
                    <?php $eventcrew_tasks_hidden($eventcrew_tasks_query + $eventcrew_tasks_args); ?>
                    <label for="eventcrew-admin-role-pick"><?php esc_html_e('Role', 'eventcrew'); ?></label>
                    <select id="eventcrew-admin-role-pick" name="tasks_role" onchange="this.form.submit()">
                        <option value=""><?php esc_html_e('Pick a role', 'eventcrew'); ?></option>
                        <?php foreach ($eventcrew_tasks['role_list'] as $eventcrew_pick_role) : ?>
                            <option value="<?php echo esc_attr($eventcrew_pick_role['slug']); ?>"<?php echo null !== $eventcrew_role_editing && $eventcrew_role_editing['slug'] === $eventcrew_pick_role['slug'] ? ' selected' : ''; ?>><?php
                                echo esc_html(
                                    $eventcrew_pick_role['archived']
                                        /* translators: %s: role label */
                                        ? sprintf(__('%s (archived)', 'eventcrew'), \EventCrew\Support\Roles::display($eventcrew_pick_role['slug']))
                                        : \EventCrew\Support\Roles::display($eventcrew_pick_role['slug'])
                                );
                            ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="wp-element-button is-style-outline"><?php esc_html_e('Show', 'eventcrew'); ?></button></noscript>
                </form>
                <?php if (null !== $eventcrew_role_editing) : ?>
                    <?php
                    $eventcrew_role_here = add_query_arg(['tasks_role' => $eventcrew_role_editing['slug']] + $eventcrew_tasks_args, $eventcrew_here) . '#eventcrew-admin-tasks';
                    $eventcrew_role_conditions = $eventcrew_role_editing['conditions'];
                    ?>
                    <form class="eventcrew-card-body" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['role_action']); ?>">
                        <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_role_here); ?>">
                        <input type="hidden" name="role" value="<?php echo esc_attr($eventcrew_role_editing['slug']); ?>">
                        <p>
                            <label for="eventcrew-admin-role-emoji"><?php esc_html_e('Emoji', 'eventcrew'); ?></label>
                            <input type="text" name="role_emoji" id="eventcrew-admin-role-emoji" value="<?php echo esc_attr($eventcrew_role_editing['emoji']); ?>">
                        </p>
                        <p>
                            <label for="eventcrew-admin-role-label"><?php esc_html_e('Label', 'eventcrew'); ?></label>
                            <input type="text" name="role_label" id="eventcrew-admin-role-label" value="<?php echo esc_attr($eventcrew_role_editing['label']); ?>">
                        </p>
                        <p>
                            <label for="eventcrew-admin-role-slug"><?php esc_html_e('Slug', 'eventcrew'); ?></label>
                            <input type="text" id="eventcrew-admin-role-slug" value="<?php echo esc_attr($eventcrew_role_editing['slug']); ?>" readonly>
                        </p>
                        <p>
                            <label for="eventcrew-admin-role-capacity"><?php esc_html_e('People', 'eventcrew'); ?></label>
                            <input type="number" name="role_capacity" id="eventcrew-admin-role-capacity" min="0" step="1" value="<?php echo esc_attr((string) $eventcrew_role_editing['capacity']); ?>">
                        </p>
                        <p>
                            <label for="eventcrew-admin-role-plus-ones"><?php esc_html_e('+1 allowed', 'eventcrew'); ?></label>
                            <input type="number" name="role_plus_ones" id="eventcrew-admin-role-plus-ones" min="0" max="20" step="1" value="<?php echo esc_attr((string) $eventcrew_role_editing['plus_ones']); ?>">
                            <span class="eventcrew-admin-hint eventcrew-muted"><?php esc_html_e('How many guests each person on a new task of this role may bring in for free. New tasks copy this number; change it on a single task on the Tasks screen.', 'eventcrew'); ?></span>
                        </p>
                        <fieldset>
                            <legend><?php esc_html_e('Timing', 'eventcrew'); ?></legend>
                            <p>
                                <label for="eventcrew-admin-role-anchor"><?php esc_html_e('Measured from', 'eventcrew'); ?></label>
                                <select name="role_anchor" id="eventcrew-admin-role-anchor">
                                    <option value="<?php echo esc_attr(\EventCrew\Support\Roles::ANCHOR_START); ?>"<?php echo \EventCrew\Support\Roles::ANCHOR_START === $eventcrew_role_editing['anchor'] ? ' selected' : ''; ?>><?php esc_html_e('Event start', 'eventcrew'); ?></option>
                                    <option value="<?php echo esc_attr(\EventCrew\Support\Roles::ANCHOR_END); ?>"<?php echo \EventCrew\Support\Roles::ANCHOR_END === $eventcrew_role_editing['anchor'] ? ' selected' : ''; ?>><?php esc_html_e('Event end', 'eventcrew'); ?></option>
                                </select>
                            </p>
                            <p class="eventcrew-admin-pair">
                                <span>
                                    <label for="eventcrew-admin-role-start"><?php esc_html_e('Starts', 'eventcrew'); ?></label>
                                    <input type="number" step="15" name="role_start_offset" id="eventcrew-admin-role-start" value="<?php echo esc_attr(null === $eventcrew_role_editing['start_offset'] ? '' : (string) $eventcrew_role_editing['start_offset']); ?>">
                                </span>
                                <span>
                                    <label for="eventcrew-admin-role-end"><?php esc_html_e('Ends', 'eventcrew'); ?></label>
                                    <input type="number" step="15" name="role_end_offset" id="eventcrew-admin-role-end" value="<?php echo esc_attr(null === $eventcrew_role_editing['end_offset'] ? '' : (string) $eventcrew_role_editing['end_offset']); ?>">
                                </span>
                            </p>
                        </fieldset>
                        <fieldset class="eventcrew-admin-roles">
                            <legend><?php esc_html_e('Access', 'eventcrew'); ?></legend>
                            <label><input type="checkbox" name="role_archived" value="1"<?php echo $eventcrew_role_editing['archived'] ? ' checked' : ''; ?>> <?php esc_html_e('Archived (hidden)', 'eventcrew'); ?></label>
                            <label><input type="checkbox" name="role_restricted" value="1"<?php echo $eventcrew_role_editing['restricted'] ? ' checked' : ''; ?>> <?php esc_html_e('Only people with this role', 'eventcrew'); ?></label>
                            <label><input type="checkbox" name="role_every_night" value="1"<?php echo $eventcrew_role_editing['every_night'] ? ' checked' : ''; ?>> <?php esc_html_e('Add to every night', 'eventcrew'); ?></label>
                            <label><input type="checkbox" name="role_sales_desk" value="1"<?php echo $eventcrew_role_editing['sales_desk'] ? ' checked' : ''; ?>> <?php esc_html_e('Opens the sales desk', 'eventcrew'); ?></label>
                            <label><input type="checkbox" name="role_on_board" value="1"<?php echo ($eventcrew_role_editing['on_board'] ?? true) ? ' checked' : ''; ?>> <?php esc_html_e('Show on the guest board (Telegram group and the web page when signed out)', 'eventcrew'); ?></label>
                        </fieldset>
                        <p>
                            <label for="eventcrew-admin-role-title"><?php esc_html_e('Only on events', 'eventcrew'); ?></label>
                            <input type="text" name="role_title_contains" id="eventcrew-admin-role-title" value="<?php echo esc_attr($eventcrew_role_editing['title_contains']); ?>">
                            <span class="eventcrew-admin-hint eventcrew-muted"><?php esc_html_e('New events get a task of this role only when the event title contains this text (upper or lower case does not matter). Leave empty for every event.', 'eventcrew'); ?></span>
                        </p>
                        <fieldset>
                            <legend><?php esc_html_e('Conditions', 'eventcrew'); ?></legend>
                            <p class="eventcrew-muted"><?php esc_html_e('Completed tasks', 'eventcrew'); ?></p>
                            <?php foreach ($eventcrew_tasks['role_list'] as $eventcrew_count_role) : ?>
                                <?php
                                if ($eventcrew_count_role['slug'] === $eventcrew_role_editing['slug']) {
                                    continue;
                                }
                                $eventcrew_count_id = 'eventcrew-admin-role-count-' . $eventcrew_count_role['slug'];
                                ?>
                                <p>
                                    <label for="<?php echo esc_attr($eventcrew_count_id); ?>"><?php echo esc_html(\EventCrew\Support\Roles::display($eventcrew_count_role['slug'])); ?></label>
                                    <input type="number" min="0" step="1" id="<?php echo esc_attr($eventcrew_count_id); ?>" name="role_count[<?php echo esc_attr($eventcrew_count_role['slug']); ?>]" value="<?php echo esc_attr((string) ($eventcrew_role_conditions['counts'][$eventcrew_count_role['slug']] ?? '')); ?>">
                                </p>
                            <?php endforeach; ?>
                            <p class="eventcrew-admin-pair">
                                <span>
                                    <label for="eventcrew-admin-role-min"><?php esc_html_e('At least', 'eventcrew'); ?> <?php esc_html_e('different roles,', 'eventcrew'); ?></label>
                                    <input type="number" min="0" step="1" name="role_min_roles" id="eventcrew-admin-role-min" value="<?php echo esc_attr(0 === $eventcrew_role_conditions['min_roles'] ? '' : (string) $eventcrew_role_conditions['min_roles']); ?>">
                                </span>
                                <span>
                                    <label for="eventcrew-admin-role-times"><?php esc_html_e('times each', 'eventcrew'); ?></label>
                                    <input type="number" min="0" step="1" name="role_times_each" id="eventcrew-admin-role-times" value="<?php echo esc_attr(0 === $eventcrew_role_conditions['times_each'] ? '' : (string) $eventcrew_role_conditions['times_each']); ?>">
                                </span>
                            </p>
                        </fieldset>
                        <p>
                            <label for="eventcrew-admin-role-description"><?php esc_html_e('Description', 'eventcrew'); ?></label>
                            <textarea name="role_description" id="eventcrew-admin-role-description" rows="3"><?php echo esc_textarea($eventcrew_role_editing['description']); ?></textarea>
                        </p>
                        <button type="submit" class="wp-element-button"><?php esc_html_e('Save', 'eventcrew'); ?></button>
                    </form>
                <?php endif; ?>
            </section>
            </div>

            <?php if (isset($eventcrew_admin['newsletter'])) : ?>
                <?php $eventcrew_news = $eventcrew_admin['newsletter']; ?>
            <div class="eventcrew-subpanel" id="eventcrew-admin-newsletter" data-eventcrew-subpanel="newsletter">
            <section class="eventcrew-event eventcrew-admin-card">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('Next events announcement', 'eventcrew'); ?></h3>
                </header>
                <div class="eventcrew-card-body">
                    <?php if (! $eventcrew_news['enabled']) : ?>
                        <p class="eventcrew-muted"><?php esc_html_e('The next events announcement is switched off. An administrator can switch it on in wp-admin under EventCrew > Settings > Email.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>
                    <?php else : ?>
                        <p><?php echo esc_html(sprintf(
                            /* translators: %d: number of days */
                            _n(
                                'The announcement goes out by itself %d day before each event.',
                                'The announcement goes out by itself %d days before each event.',
                                $eventcrew_news['days'],
                                'eventcrew'
                            ),
                            $eventcrew_news['days']
                        )); ?></p>
                    <?php endif; ?>
                    <p><?php echo esc_html(sprintf(
                        /* translators: %d: number of confirmed subscribers */
                        _n('%d subscriber.', '%d subscribers.', $eventcrew_news['subscribers'], 'eventcrew'),
                        $eventcrew_news['subscribers']
                    )); ?></p>
                    <?php if (null !== $eventcrew_news['plan']) : ?>
                        <?php if ($eventcrew_news['plan']['sending']) : ?>
                            <p><?php echo esc_html(sprintf(
                                /* translators: %d: number of emails sent so far */
                                __('The announcement is being sent now: %d emails so far.', 'eventcrew'),
                                $eventcrew_news['plan']['sent']
                            )); ?></p>
                        <?php else : ?>
                            <p><?php echo esc_html(sprintf(
                                /* translators: %s: the send date, like 14.11.2026 */
                                __('Next announcement: %s, about these events:', 'eventcrew'),
                                $eventcrew_news['plan']['send_on']
                            )); ?></p>
                        <?php endif; ?>
                        <ul>
                            <?php foreach ($eventcrew_news['plan']['events'] as $eventcrew_news_event) : ?>
                                <li><?php echo esc_html($eventcrew_news_event); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if (! $eventcrew_news['plan']['sending']) : ?>
                            <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                                <input type="hidden" name="action"
                                    value="<?php echo esc_attr($eventcrew_admin['newsletter_stop_action']); ?>">
                                <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                                <input type="hidden" name="redirect_to"
                                    value="<?php echo esc_attr($eventcrew_here); ?>">
                                <input type="hidden" name="plan_id"
                                    value="<?php echo esc_attr((string) $eventcrew_news['plan']['id']); ?>">
                                <button type="submit" class="wp-element-button">
                                    <?php esc_html_e('Stop this announcement', 'eventcrew'); ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (null !== $eventcrew_news['last']) : ?>
                        <p class="eventcrew-muted"><?php echo esc_html(sprintf(
                            /* translators: 1: date, like 14.11.2026, 2: number of emails sent */
                            __('Last announcement: %1$s, %2$d emails.', 'eventcrew'),
                            $eventcrew_news['last']['at'],
                            $eventcrew_news['last']['sent']
                        )); ?></p>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                        <input type="hidden" name="action"
                            value="<?php echo esc_attr($eventcrew_admin['newsletter_text_action']); ?>">
                        <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                        <p>
                            <label for="eventcrew-admin-newsletter-text">
                                <?php esc_html_e('Text for the next announcement', 'eventcrew'); ?>
                            </label>
                            <textarea name="newsletter_text" id="eventcrew-admin-newsletter-text" rows="5"
                                maxlength="<?php
                                echo (int) \EventCrew\Support\NewsletterText::MAX_LENGTH;
                                ?>"></textarea>
                            <span class="eventcrew-admin-hint eventcrew-muted"><?php esc_html_e('Write it in any language. It is translated into every site language when you save, and removed after the announcement is sent.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></span>
                        </p>
                        <button type="submit" name="op" value="save" class="wp-element-button">
                            <?php esc_html_e('Save', 'eventcrew'); ?>
                        </button>
                        <?php if (null !== $eventcrew_news['text']) : ?>
                            <button type="submit" name="op" value="clear" class="wp-element-button">
                                <?php esc_html_e('Remove the text', 'eventcrew'); ?>
                            </button>
                        <?php endif; ?>
                    </form>
                    <?php if (null !== $eventcrew_news['text']) : ?>
                        <?php foreach ($eventcrew_news['text']['texts'] as $eventcrew_news_lang => $eventcrew_news_words) : ?>
                            <p><strong><?php echo esc_html(\EventCrew\Support\SiteLanguages::label((string) $eventcrew_news_lang) . ':'); ?></strong></p>
                            <p style="white-space:pre-line"><?php echo esc_html($eventcrew_news_words); ?></p>
                        <?php endforeach; ?>
                        <?php if (! $eventcrew_news['text']['translated']) : ?>
                            <p class="eventcrew-muted">
                                <?php esc_html_e('This text is not translated: every language shows it as it was written.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>
            </div>
            <?php endif; ?>

            <?php if (isset($eventcrew_admin['followup'])) : ?>
                <?php
                $eventcrew_follow = $eventcrew_admin['followup'];
                $eventcrew_follow_labels = [
                    \EventCrew\Support\FollowupText::CAME => __('Thank-you text (came)', 'eventcrew'),
                    \EventCrew\Support\FollowupText::MISSED => __('Sorry text (missed)', 'eventcrew'),
                ];
                ?>
            <div class="eventcrew-subpanel" id="eventcrew-admin-followup" data-eventcrew-subpanel="followup">
            <section class="eventcrew-event eventcrew-admin-card">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('Post-event email', 'eventcrew'); ?></h3>
                </header>
                <div class="eventcrew-card-body">
                    <?php if (! $eventcrew_follow['enabled']) : ?>
                        <p class="eventcrew-muted"><?php esc_html_e('The post-event email is switched off. An administrator can switch it on in wp-admin under EventCrew > Settings > Email.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>
                    <?php endif; ?>
                    <p class="eventcrew-muted"><?php esc_html_e('The text between the greeting and the sign-off. You can replace it for the next email only; after that email is sent, or when that night is stopped, the text on the Messages page is used again. {event} becomes the event’s name and {date} its date.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>
                    <?php foreach ($eventcrew_follow_labels as $eventcrew_follow_variant => $eventcrew_follow_label) : ?>
                        <?php
                        $eventcrew_follow_text = $eventcrew_follow['texts'][$eventcrew_follow_variant];
                        $eventcrew_follow_id = 'eventcrew-admin-followup-' . $eventcrew_follow_variant;
                        ?>
                        <h4><?php echo esc_html($eventcrew_follow_label); ?></h4>
                        <p class="eventcrew-muted">
                            <?php
                            if ($eventcrew_follow_text['next']) {
                                esc_html_e('The next email uses a one-time text.', 'eventcrew');
                            } else {
                                esc_html_e('The next email uses the text on the Messages page.', 'eventcrew');
                            }
                            ?>
                        </p>
                        <?php foreach ($eventcrew_follow_text['texts'] as $eventcrew_follow_lang => $eventcrew_follow_words) : ?>
                            <p><strong><?php echo esc_html(\EventCrew\Support\SiteLanguages::label((string) $eventcrew_follow_lang) . ':'); ?></strong></p>
                            <p style="white-space:pre-line"><?php echo esc_html($eventcrew_follow_words); ?></p>
                        <?php endforeach; ?>
                        <?php if (! $eventcrew_follow_text['translated']) : ?>
                            <p class="eventcrew-muted">
                                <?php esc_html_e('This text is not translated: every language shows it as it was written.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                            </p>
                        <?php endif; ?>
                        <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                            <input type="hidden" name="action"
                                value="<?php echo esc_attr($eventcrew_admin['followup_text_action']); ?>">
                            <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                            <input type="hidden" name="variant" value="<?php echo esc_attr($eventcrew_follow_variant); ?>">
                            <p>
                                <label for="<?php echo esc_attr($eventcrew_follow_id); ?>">
                                    <?php esc_html_e('One-time text for the next email', 'eventcrew'); ?>
                                </label>
                                <textarea name="followup_text" id="<?php echo esc_attr($eventcrew_follow_id); ?>" rows="5"
                                    maxlength="<?php
                                    echo (int) \EventCrew\Support\FollowupText::MAX_LENGTH;
                                    ?>"></textarea>
                                <span class="eventcrew-admin-hint eventcrew-muted"><?php esc_html_e('Write it in any language. It is translated into every site language when you save.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></span>
                            </p>
                            <button type="submit" name="op" value="save" class="wp-element-button">
                                <?php esc_html_e('Save', 'eventcrew'); ?>
                            </button>
                            <?php if ($eventcrew_follow_text['next']) : ?>
                                <button type="submit" name="op" value="clear" class="wp-element-button">
                                    <?php esc_html_e('Remove the one-time text', 'eventcrew'); ?>
                                </button>
                            <?php endif; ?>
                        </form>
                    <?php endforeach; ?>
                </div>
            </section>
            </div>
            <?php endif; ?>

            <?php if (isset($eventcrew_admin['ai_texts'])) : ?>
            <div class="eventcrew-subpanel" id="eventcrew-admin-aitexts" data-eventcrew-subpanel="aitexts">
            <section class="eventcrew-event eventcrew-admin-card">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('AI texts', 'eventcrew'); ?></h3>
                </header>
                <div class="eventcrew-card-body">
                    <p class="eventcrew-muted"><?php esc_html_e('The AI writes these the day before each event. A text nobody changes is sent as the AI wrote it. A text you change is sent as you wrote it. When the AI failed, the normal message text is used.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>
                    <?php if ([] === $eventcrew_admin['ai_texts']) : ?>
                        <p class="eventcrew-muted"><?php esc_html_e('No texts are written yet for the coming events.', 'eventcrew'); ?></p>
                    <?php endif; ?>
                    <?php foreach ($eventcrew_admin['ai_texts'] as $eventcrew_ai_event) : ?>
                        <h4><?php echo esc_html($eventcrew_ai_event['date'] . ' · ' . $eventcrew_ai_event['title']); ?></h4>
                        <?php foreach ($eventcrew_ai_event['items'] as $eventcrew_ai_item) : ?>
                            <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                                <input type="hidden" name="action"
                                    value="<?php echo esc_attr($eventcrew_admin['ai_text_action']); ?>">
                                <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                                <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $eventcrew_ai_event['event_id']); ?>">
                                <input type="hidden" name="key" value="<?php echo esc_attr($eventcrew_ai_item['key']); ?>">
                                <p><strong><?php echo esc_html($eventcrew_ai_item['label']); ?></strong></p>
                                <?php if ($eventcrew_ai_item['failed']) : ?>
                                    <p class="eventcrew-muted"><?php esc_html_e('The AI could not write this text. The normal message text is used, unless you write one here.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>
                                <?php endif; ?>
                                <?php foreach (\EventCrew\Support\TranslatedText::languages() as $eventcrew_ai_lang) : ?>
                                    <?php $eventcrew_ai_id = 'eventcrew-admin-aitext-' . $eventcrew_ai_event['event_id'] . '-' . sanitize_key($eventcrew_ai_item['key']) . '-' . $eventcrew_ai_lang; ?>
                                    <p>
                                        <label for="<?php echo esc_attr($eventcrew_ai_id); ?>">
                                            <?php echo esc_html(\EventCrew\Support\TranslatedText::label($eventcrew_ai_lang)); ?>
                                            <?php if ($eventcrew_ai_item['edited'][$eventcrew_ai_lang]) : ?>
                                                <em><?php esc_html_e('(changed by hand)', 'eventcrew'); ?></em>
                                            <?php endif; ?>
                                        </label>
                                        <textarea name="texts[<?php echo esc_attr($eventcrew_ai_lang); ?>]" id="<?php echo esc_attr($eventcrew_ai_id); ?>" rows="4"
                                            maxlength="<?php echo (int) \EventCrew\Support\EventAiText::MAX_TEXT; ?>"><?php echo esc_textarea($eventcrew_ai_item['texts'][$eventcrew_ai_lang]); ?></textarea>
                                    </p>
                                <?php endforeach; ?>
                                <button type="submit" name="op" value="save" class="wp-element-button">
                                    <?php esc_html_e('Save', 'eventcrew'); ?>
                                </button>
                                <?php if (in_array(true, $eventcrew_ai_item['edited'], true) && ! $eventcrew_ai_item['failed']) : ?>
                                    <button type="submit" name="op" value="ai" class="wp-element-button">
                                        <?php esc_html_e('Use the AI text again', 'eventcrew'); ?>
                                    </button>
                                <?php endif; ?>
                            </form>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </section>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
        <?php
    endif; ?>
</div>
<script>
// The address's #part as the page opened: the tab script below replaces it
// with the open tab's, and the Admin screens script needs the original.
var eventcrewStartHash = (window.location.hash || '').slice(1);

/*
 * Progressive enhancement: turn the three sections into tabs.
 *
 * The markup ships as a jump-link nav over three visible panels, so with
 * JavaScript off the page is one readable scroll and every link still goes
 * somewhere. Only when this runs does it become a tablist - which is why the
 * hiding lives behind the .is-tabbed class this adds, and not in the
 * stylesheet's default state.
 *
 * Unlike the admin's tabs, the last-used tab is deliberately NOT remembered:
 * the board is what this page is for, and it must be what you land on.
 */
(function () {
    var root = document.querySelector('.eventcrew-signup');
    var nav = root && root.querySelector('.eventcrew-tabs');

    if (!nav) { return; }  // signed out: nothing to tab between

    var tabs = [].slice.call(nav.querySelectorAll('.eventcrew-tab'));
    var panels = [].slice.call(root.querySelectorAll('[data-eventcrew-panel]'));

    if (tabs.length === 0 || panels.length === 0) { return; }

    nav.setAttribute('role', 'tablist');
    panels.forEach(function (panel) {
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('tabindex', '0');
    });

    function panelFor(key) {
        return panels.filter(function (p) { return p.getAttribute('data-eventcrew-panel') === key; })[0];
    }

    function show(key, focusTab) {
        tabs.forEach(function (tab) {
            var isActive = tab.getAttribute('data-eventcrew-tab') === key;
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            tab.setAttribute('aria-controls', 'eventcrew-panel-' + tab.getAttribute('data-eventcrew-tab'));
            // Roving tabindex: one stop for the whole strip, arrows move within.
            tab.setAttribute('tabindex', isActive ? '0' : '-1');
            tab.classList.toggle('is-active', isActive);
            if (isActive && focusTab) { tab.focus(); }
        });

        panels.forEach(function (panel) {
            panel.classList.toggle('is-active', panel.getAttribute('data-eventcrew-panel') === key);
        });

        // replaceState, not the hash directly: setting location.hash would jump
        // the page to the panel, undoing the point of a tab. Deep links still
        // work, because the hash is read on load below.
        if (window.history && window.history.replaceState) {
            try { window.history.replaceState(null, '', '#eventcrew-panel-' + key); } catch (e) {}
        }
    }

    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function (e) {
            e.preventDefault();
            show(tab.getAttribute('data-eventcrew-tab'), false);
        });

        tab.addEventListener('keydown', function (e) {
            var next = null;
            if (e.key === 'ArrowRight') { next = tabs[(index + 1) % tabs.length]; }
            if (e.key === 'ArrowLeft') { next = tabs[(index - 1 + tabs.length) % tabs.length]; }
            if (e.key === 'Home') { next = tabs[0]; }
            if (e.key === 'End') { next = tabs[tabs.length - 1]; }
            if (!next) { return; }
            e.preventDefault();
            show(next.getAttribute('data-eventcrew-tab'), true);
        });
    });

    /*
     * Which tab opens. The board wins unless something specific says otherwise:
     * a deep link, or a notice from an action that lives on another tab - the
     * preferences and delete forms are full-page posts, so without that second
     * rule saving your preferences would dump you back on the board with no
     * sign it had worked.
     */
    // An element named in the address that lives inside a panel, such as the
    // board's task descriptions: its panel opens, then the page scrolls to it.
    var hashTarget = null;

    function initialKey() {
        var hash = (window.location.hash || '').slice(1);
        var fromHash = hash.replace('eventcrew-panel-', '');
        if (fromHash && panelFor(fromHash)) { return fromHash; }

        var element = hash ? document.getElementById(hash) : null;
        var holder = element && element.closest ? element.closest('[data-eventcrew-panel]') : null;
        if (holder) {
            hashTarget = element;
            return holder.getAttribute('data-eventcrew-panel');
        }

        var notice = (window.location.search.match(/[?&]eventcrew_notice=([a-z_]+)/) || [])[1] || '';
        if (notice === 'prefs_saved' && panelFor('settings')) { return 'settings'; }
        if (notice.indexOf('ticket_') === 0 && panelFor('me')) { return 'me'; }
        if (notice.indexOf('admin_') === 0 && panelFor('admin')) { return 'admin'; }

        return 'board';
    }

    root.classList.add('is-tabbed');
    show(initialKey(), false);
    if (hashTarget) { hashTarget.scrollIntoView(); }
})();

/*
 * The Admin tab's second row of tabs: one screen of cards at a time, Roster
 * first. Every Admin form is a full-page post, so the screen it was sent from
 * is kept in sessionStorage and reopened when the page comes back with an
 * admin_ notice; without that, giving a credit would land you on Roster.
 */
(function () {
    var admin = document.querySelector('.eventcrew-panel.eventcrew-admin');
    var nav = admin && admin.querySelector('.eventcrew-subtabs');
    var root = document.querySelector('.eventcrew-signup');

    if (!nav || !root || !root.classList.contains('is-tabbed')) { return; }

    var links = [].slice.call(nav.querySelectorAll('[data-eventcrew-subtab]'));
    var subs = [].slice.call(admin.querySelectorAll('[data-eventcrew-subpanel]'));
    var store = 'eventcrew-admin-screen';

    function has(key) {
        return subs.some(function (s) { return s.getAttribute('data-eventcrew-subpanel') === key; });
    }

    function remember(key) {
        try { window.sessionStorage.setItem(store, key); } catch (e) {}
    }

    function show(key) {
        if (!has(key)) { key = links[0].getAttribute('data-eventcrew-subtab'); }
        links.forEach(function (link) {
            var on = link.getAttribute('data-eventcrew-subtab') === key;
            link.classList.toggle('is-active', on);
            link.setAttribute('aria-current', on ? 'true' : 'false');
        });
        subs.forEach(function (s) {
            s.classList.toggle('is-active', s.getAttribute('data-eventcrew-subpanel') === key);
        });
    }

    links.forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            var key = link.getAttribute('data-eventcrew-subtab');
            show(key);
            remember(key);
        });
    });

    subs.forEach(function (s) {
        s.addEventListener('submit', function () { remember(s.getAttribute('data-eventcrew-subpanel')); });
    });

    var key = '';
    var hash = eventcrewStartHash;
    if (hash.indexOf('eventcrew-admin-') === 0) { key = hash.replace('eventcrew-admin-', ''); }
    if (!key && /[?&]eventcrew_notice=admin_/.test(window.location.search)) {
        try { key = window.sessionStorage.getItem(store) || ''; } catch (e) {}
    }

    admin.classList.add('has-subtabs');
    show(key);
})();

/*
 * The Admin tab's task form, as on the wp-admin Tasks page: picking an event
 * fills in the date and times, and the event name field shows only for
 * "Other". Only a convenience: without it the form still posts every field.
 */
(function () {
    var picker = document.querySelector('[data-eventcrew-task-events]');
    var otherRow = document.querySelector('[data-eventcrew-task-other]');

    if (!picker) { return; }

    function apply(overwrite) {
        var option = picker.options[picker.selectedIndex];
        var isOther = option.value === <?php echo wp_json_encode(\EventCrew\Web\CrewAdminTasks::EVENT_OTHER); ?>;

        if (otherRow) { otherRow.hidden = !isOther; }
        if (isOther) { return; }

        [
            ['eventcrew-admin-task-date', option.getAttribute('data-date')],
            ['eventcrew-admin-task-starts', option.getAttribute('data-starts')],
            ['eventcrew-admin-task-ends', option.getAttribute('data-ends')]
        ].forEach(function (pair) {
            var field = document.getElementById(pair[0]);

            // Never overwrite a typed value unless the event was just changed.
            if (field && pair[1] && (overwrite || !field.value)) {
                field.value = pair[1];
            }
        });
    }

    picker.addEventListener('change', function () { apply(true); });
    apply(false);
})();

/*
 * Progressive enhancement: send Sign up / Cancel through admin-ajax and swap the
 * board's HTML for the fresh copy the server returns, so the button flips state
 * and the counts update without a page reload or losing scroll position.
 * Delegated from the container, so the replaced buttons keep working.
 */
(function () {
    var root = document.querySelector('.eventcrew-signup');
    var board = document.getElementById('eventcrew-board');
    var toast = document.getElementById('eventcrew-toast');

    // Pin the toast to the viewport, not the widget: a transformed ancestor
    // (which many themes have) would otherwise capture position:fixed and leave
    // it stuck inside the content column.
    if (toast) {
        document.body.appendChild(toast);
    }

    var toastTimer = null;
    function showToast(text) {
        if (!toast) { return; }
        if (!text) { toast.classList.remove('show'); return; }
        toast.textContent = text;
        toast.classList.add('show');
        if (toastTimer) { clearTimeout(toastTimer); }
        toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 4000);
    }

    // A server-rendered toast (from a full-page redirect) auto-hides too.
    if (toast && toast.classList.contains('show')) {
        toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 4000);
    }

    /*
     * Take the notice out of the address bar once it has been shown.
     *
     * The redirect after an action carries its outcome in ?eventcrew_notice,
     * which the page turns into a toast - but the parameter stays in the URL,
     * so a refresh, a bookmark or a shared link says "You're signed out" again
     * to somebody who is not, days later. Nothing is re-run by that: the toast
     * is display only, and the action itself happened before the redirect. It
     * is a message outliving the thing it described.
     *
     * replaceState rewrites the address without a reload and without adding a
     * history entry, so the back button behaves and the refresh that follows
     * asks for a clean URL. Wrapped because a URL the browser will not parse is
     * not worth an exception over a cosmetic tidy-up.
     */
    if (window.history && window.history.replaceState && -1 !== window.location.search.indexOf('eventcrew_notice=')) {
        try {
            var tidy = new URL(window.location.href);
            tidy.searchParams.delete('eventcrew_notice');
            window.history.replaceState({}, '', tidy.toString());
        } catch (e) {}
    }

    // Remember the sign-in email so a return visit (or a resend) doesn't have to
    // retype it - the one bit of friction in a passwordless, leave-and-come-back
    // sign-in. Runs regardless of the fetch path below.
    var EMAIL_KEY = 'eventcrew_email';
    var emailField = document.getElementById('eventcrew-email');
    if (emailField && !emailField.value) {
        try { emailField.value = localStorage.getItem(EMAIL_KEY) || ''; } catch (e) {}
    }

    if (!root || !board || !window.fetch) {
        return;
    }

    var ajaxUrl = <?php echo wp_json_encode($eventcrew_ajax); ?>;

    /*
     * The sign-in button waits for the spam check.
     *
     * Turnstile renders and solves asynchronously and writes its token into a
     * hidden field. Pressing before that lands submits an empty token, which is
     * refused outright - so the press did nothing, and only a second one, by
     * which point the widget had finished, actually sent the link. Rather than
     * let someone spend a press finding that out, the button says what it is
     * waiting for and only becomes live once there is a token to send.
     *
     * The safety valve is the timeout below. Ad blockers and privacy extensions
     * do block the challenge script outright, and a button that stays disabled
     * for ever is a form nobody can sign in through at all. After GIVE_UP the
     * button goes live regardless; the press then reaches the server, which
     * refuses it and says why. A wasted press beats a dead page.
     *
     * None of this runs without the widget, and none of it runs with JavaScript
     * off - the button ships enabled and the form posts normally.
     */
    var CHALLENGE_STEP = 200;
    var CHALLENGE_GIVE_UP = 15000;
    /*
     * How long to wait for a token once somebody has pressed.
     *
     * Ten seconds, not the four this started at: a challenge solving from cold
     * after a reset is slower than the one that solved during page load, and
     * four seconds was short enough that pressing Resend gave up before the new
     * token arrived - so the widget visibly reloaded, nothing was sent, and it
     * took a second press. Still bounded, and the button says what it is doing
     * throughout, so the wait is visible rather than dead.
     */
    var REFRESH_WAIT = 10000;

    /*
     * How long after a send before the widget is quietly re-armed.
     *
     * A spent token has to be replaced before anything can be sent again, and
     * doing it here - while the "check your inbox" message is being read -
     * means Resend usually has a fresh one waiting and sends on the first
     * press. Doing it at the instant of success looked like the form reloading
     * (v1.23.1), and not doing it at all made Resend take two presses. A beat
     * later is neither.
     */
    var REARM_AFTER = 1200;

    /*
     * How long the button holds itself shut after a link has gone.
     *
     * A minute, counted down in the button itself, because the honest answer to
     * "nothing has arrived yet" is almost always "give it a moment" - mail
     * takes time, and a second identical link helps nobody. Showing the wait
     * rather than refusing the press means the button is never a mystery: it
     * says what it is doing and when it will be ready.
     *
     * Presentation, not a rate limit. It lives in the page, so a reload clears
     * it; the actual guard against someone hammering the form is Turnstile.
     */
    var RESEND_COOLDOWN = 60000;
    var resendReadyAt = 0;
    var resendCountdown = <?php echo wp_json_encode(
        /* translators: %s: whole seconds remaining before the link can be sent again */
        __('Resend in %ss', 'eventcrew')
    ); ?>;
    var unsolvedNotice = <?php echo wp_json_encode(
        __('The spam check hasn’t finished yet — give it a moment, then try again.', 'eventcrew')
    ); ?>;

    var signinForm = root.querySelector('form[data-eventcrew-signin]');
    var signinButton = signinForm ? signinForm.querySelector('button') : null;
    // Whatever the button should say when it is live. Becomes "Resend link"
    // after a link has gone, so it is read back rather than assumed.
    var signinLiveLabel = signinButton ? signinButton.textContent : '';
    var waitingLabel = <?php echo wp_json_encode(__('Checking you’re human…', 'eventcrew')); ?>;
    var submitting = false;

    /*
     * The best token available from either place Cloudflare keeps it.
     *
     * There are two, and they are not filled at the same moment: the hidden
     * cf-turnstile-response field lags turnstile.getResponse() by a few
     * seconds. Whichever is set, they hold the same token, so reading the field
     * first and the API second returns a usable one as early as possible.
     *
     * This is only safe because send() now writes the result into the request
     * explicitly. Gating on this while letting FormData take the field on its
     * own is what let the button go live on a token the form did not send.
     */
    function challengeToken(form) {
        var scope = form || signinForm;
        var field = scope ? scope.querySelector('[name="cf-turnstile-response"]') : null;

        if (field && field.value) {
            return field.value;
        }

        try {
            return (window.turnstile && window.turnstile.getResponse()) || '';
        } catch (err) {
            return '';
        }
    }

    function hasChallenge(form) {
        return !!(form && form.querySelector('.cf-turnstile'));
    }

    function setSigninLive(live) {
        if (!signinButton || submitting) {
            return;
        }

        signinButton.disabled = !live;
        signinButton.textContent = live ? signinLiveLabel : waitingLabel;
    }

    /*
     * A Turnstile token goes stale on its own, and the widget does not let on.
     *
     * It solves as soon as the page loads and the tick stays on screen, but the
     * token behind it is only good for about five minutes and is single-use.
     * Anyone who opens the page, reads it, types an address and then presses -
     * or who leaves the tab sitting - sends a token Cloudflare answers with
     * "timeout-or-duplicate", so the widget says success and the server says
     * failure. Pressing again works, because the reset after a submit fetches a
     * fresh one. That is the whole of "it fails the first time".
     *
     * So the age is tracked here and anything past STALE_AFTER is replaced
     * before it is sent. Well under Cloudflare's own expiry, because the gap
     * that matters is between the press and the check reaching them.
     */
    var STALE_AFTER = 100000;
    var tokenSeenAt = 0;
    var lastToken = '';
    var tokenSpent = false;

    function noteToken(token) {
        if (token !== lastToken) {
            lastToken = token;
            tokenSeenAt = token ? Date.now() : 0;
            // A token we have not sent yet.
            tokenSpent = false;
        }
    }

    /*
     * Only a token we actually have can be stale. With none at all there is
     * nothing to replace, and resetting to wait for one would spend the
     * timeout achieving nothing - better to send and let the server say so,
     * which it now does out loud.
     */
    function tokenIsStale() {
        if (!lastToken) {
            return false;
        }

        // Spent counts as stale: a token is good for one send, so the one
        // already used is no more use than an expired one.
        return tokenSpent || (Date.now() - tokenSeenAt) > STALE_AFTER;
    }

    /*
     * Replaces the current token and hands the new one to `done`, or '' if none
     * arrived in time.
     *
     * REFRESH_WAIT, not CHALLENGE_GIVE_UP: the fifteen seconds are the safety
     * valve for the button on page load, where waiting costs nothing because
     * nobody is watching yet. Here somebody has just pressed and is watching,
     * and a challenge that has not answered in a few seconds is not going to.
     * Waiting the full fifteen twice - once before sending, once on the retry -
     * is what turned a refusal into half a minute of nothing followed by a
     * toast.
     *
     * Resetting only when there is something to replace: a widget that has not
     * produced a token yet may be mid-solve or waiting to be clicked, and
     * resetting it throws away whatever progress it had.
     */
    function refreshChallenge(form, done) {
        if (!hasChallenge(form) || !window.turnstile) {
            done('');

            return;
        }

        if (lastToken) {
            try { window.turnstile.reset(); } catch (err) {}
            noteToken('');
        }

        var waited = 0;

        (function poll() {
            var token = challengeToken(form);

            if (token) {
                noteToken(token);
                done(token);

                return;
            }

            if (waited >= REFRESH_WAIT) {
                done('');

                return;
            }

            waited += CHALLENGE_STEP;
            setTimeout(poll, CHALLENGE_STEP);
        })();
    }

    /*
     * The one ticker that owns the sign-in button's state.
     *
     * Two things gate it and they have to agree on who wins: the resend
     * cooldown, and whether the spam check has produced a token. The cooldown
     * takes precedence, because a countdown that flickered back to "Checking
     * you're human..." every time the widget re-armed would say nothing useful
     * about either.
     *
     * It runs whenever there is a button, not only when there is a challenge -
     * an install with no Turnstile keys has no widget to wait for but still
     * has a cooldown to show.
     */
    var starved = 0;

    function tickSigninButton() {
        if (!signinButton || submitting) {
            return;
        }

        var remaining = resendReadyAt - Date.now();

        if (remaining > 0) {
            setSigninButton(true, resendCountdown.replace('%s', String(Math.ceil(remaining / 1000))));

            return;
        }

        if (!hasChallenge(signinForm)) {
            setSigninButton(false, signinLiveLabel);

            return;
        }

        var token = challengeToken(signinForm);
        noteToken(token);

        if (token) {
            starved = 0;
            setSigninLive(true);

            return;
        }

        starved += CHALLENGE_STEP;

        // Past the timeout the button stays live and empty-handed rather
        // than dead; see the note above.
        setSigninLive(starved >= CHALLENGE_GIVE_UP);
    }

    /** Sets the button's state, touching the DOM only when it changes. */
    function setSigninButton(disabled, label) {
        if (signinButton.disabled !== disabled) {
            signinButton.disabled = disabled;
        }

        if (signinButton.textContent !== label) {
            signinButton.textContent = label;
        }
    }

    if (signinButton) {
        if (hasChallenge(signinForm)) {
            setSigninLive(false);
        }

        setInterval(tickSigninButton, CHALLENGE_STEP);
    }

    root.addEventListener('submit', function (e) {
        var form = e.target.closest('form.eventcrew-action');

        if (!form) {
            return;
        }

        e.preventDefault();

        var button = form.querySelector('button');
        if (button) {
            button.disabled = true;
        }

        var isSignin = form.hasAttribute('data-eventcrew-signin');
        if (isSignin && emailField) {
            try { localStorage.setItem(EMAIL_KEY, emailField.value || ''); } catch (e) {}
        }

        // Holds the watcher above off the button until the request is done, so
        // it cannot re-enable it or rewrite its label mid-flight.
        submitting = true;

        /*
         * A token that is spent or has sat on screen a while is replaced before
         * it goes, rather than after Cloudflare has rejected it. And if there
         * is no token at all - the widget has not solved, or is waiting to be
         * clicked - the request is not sent.
         *
         * Sending it anyway was the old behaviour and it cannot succeed: the
         * server refuses an empty token without even asking Cloudflare, so the
         * round trip buys nothing but delay and a line in the log. Saying so
         * here is immediate and tells somebody what to do about it, which
         * "couldn't verify you're human" after half a minute did not.
         */
        if (hasChallenge(form) && (tokenIsStale() || !lastToken)) {
            showWaitingOnButton();

            refreshChallenge(form, function (token) {
                if (!token) {
                    giveUp(button, unsolvedNotice);

                    return;
                }

                send(form, button, isSignin, false);
            });

            return;
        }

        send(form, button, isSignin, false);
    });

    /** Labels the sign-in button as waiting, past the submitting guard. */
    function showWaitingOnButton() {
        if (signinButton) {
            signinButton.textContent = waitingLabel;
        }
    }

    /** Abandons an attempt without sending it, saying why. */
    function giveUp(button, message) {
        submitting = false;
        showToast(message);

        if (button && signinButton === button) {
            tickSigninButton();

            return;
        }

        if (button) {
            button.disabled = false;
        }
    }

    function send(form, button, isSignin, isRetry) {
        var data = new FormData(form);
        data.append('eventcrew_ajax', '1');

        /*
         * Carry the token explicitly, because the two places it lives are not
         * filled at the same moment.
         *
         * Cloudflare hands turnstile.getResponse() the token as soon as the
         * challenge is solved, and writes it into the hidden
         * cf-turnstile-response field a few seconds later. Measured on a real
         * page: getResponse() 645 characters while the field was still empty,
         * then both 645 a moment after. FormData only sees the field - so a
         * press inside that gap submitted nothing, the server refused it for
         * having no token, and the tick was on screen the whole time saying
         * otherwise.
         *
         * It also explains the one clue that made no sense: focusing the email
         * box first "fixed" it, because typing spends exactly the few seconds
         * the field needed to catch up.
         *
         * Setting it here means the request carries whichever copy exists, so
         * what the button was enabled on and what is sent are finally the same
         * value - which is what v1.23.0 set out to do and got backwards, by
         * trusting the field that lags over the API that leads.
         */
        var token = challengeToken(form);

        if (token) {
            data.set('cf-turnstile-response', token);
        }

        fetch(ajaxUrl, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            return r.json();
        }).then(function (res) {
            /*
             * A refused challenge is worth one silent second attempt with a
             * fresh token before it becomes the visitor's problem. The common
             * causes - a token that expired while the page sat open, or one
             * already spent - are both cured by resetting and asking again, and
             * telling somebody "couldn't verify you're human" for either is
             * blaming them for a clock.
             *
             * Once only, and only for the captcha: a second refusal is a real
             * one and gets shown.
             */
            if (!isRetry && res && 'captcha_failed' === res.code && hasChallenge(form)) {
                showWaitingOnButton();

                refreshChallenge(form, function (token) {
                    // No fresh token means the second attempt would be refused
                    // for exactly the reason the first was. Say so instead.
                    if (!token) {
                        giveUp(button, unsolvedNotice);

                        return;
                    }

                    send(form, button, isSignin, true);
                });

                return;
            }

            if (res && typeof res.board === 'string') {
                /*
             * Swapping the board's HTML destroys the button that was just
             * pressed, and with it the focus - dumping a keyboard or screen
             * reader user back at the top of the document with no idea what
             * happened. Put focus on whatever now stands in that row's place,
             * so the next tab press carries on from where they were.
             */
            var focusedTask = form.querySelector('input[name="task_id"]');
            focusedTask = focusedTask ? focusedTask.value : '';

            board.innerHTML = res.board;

            if (focusedTask) {
                var row = board.querySelector('[data-eventcrew-task="' + focusedTask + '"]');
                var target = row && row.querySelector('button, a');
                if (target) { target.focus(); }
            }
            }
            if (res && res.notice) {
                showToast(res.notice);
            }
            /*
             * The sign-in link was actually sent: reveal the "check your inbox"
             * note in place and turn the button into a clear Resend.
             *
             * Gated on the outcome, not on "this was the sign-in form". It used
             * to fire on any response, so a refused captcha or a mistyped
             * address told people to go and check an inbox nothing had been
             * sent to - which is worse than saying nothing, because they then
             * wait instead of pressing the button again.
             */
            if (isSignin && res && 'check_email' === res.code) {
                var sent = document.getElementById('eventcrew-signin-sent');
                if (sent) { sent.hidden = false; }

                // Only a link that actually went starts the clock. A refusal
                // leaves the button ready, because there is nothing to wait for.
                resendReadyAt = Date.now() + RESEND_COOLDOWN;
                // Remembered as the live label too, so the ticker restores
                // "Resend link" rather than the original wording once the
                // cooldown is over and the widget has solved again.
                signinLiveLabel = <?php echo wp_json_encode(__('Resend link', 'eventcrew')); ?>;
            }
            // A redeemed free-entry ticket: open it. Prefer a new tab; if the
            // browser blocks the popup, navigate this one to the ticket instead.
            if (res && res.ticket_url) {
                var opened = window.open(res.ticket_url, '_blank');
                if (!opened) {
                    window.location.assign(res.ticket_url);
                }
            }
            if (button) {
                button.disabled = false;
            }

            /*
             * The token is spent, but the widget is left alone.
             *
             * Resetting here re-ran the challenge the instant the link was
             * sent: the tick vanished and the box visibly worked itself out
             * again, directly under a message saying to go and check an inbox.
             * It reads as the form reloading, and it is asking a question
             * nobody has been posed - there is nothing to submit until they
             * press Resend.
             *
             * Marking it spent instead defers that to the next press, where
             * the refresh happens for a reason and the wait belongs. The
             * button stays live and reading "Resend link" in the meantime,
             * because the field it is gated on still holds a value.
             */
            tokenSpent = true;
            submitting = false;

            // Hand the button straight back to the ticker rather than setting a
            // label here that it would correct a fraction of a second later -
            // which after a successful send would have flashed "Resend link"
            // before the countdown replaced it.
            tickSigninButton();

            // Re-arm in the background so a Resend has a token ready. The
            // watcher picks the new one up and clears the spent flag with it.
            if (hasChallenge(form) && window.turnstile) {
                setTimeout(function () {
                    if (submitting) {
                        return;
                    }

                    try { window.turnstile.reset(); } catch (err) {}
                }, REARM_AFTER);
            }
        }).catch(function () {
            if (button) {
                button.disabled = false;
            }

            submitting = false;
        });
    }
})();

/*
 * Sign in through the Telegram bot. When it is available the sign-in card
 * offers a choice: the email form, or the Telegram button. Pressing Telegram
 * asks the site for a one-time code (the site also sets a cookie that ties
 * the code to this browser), opens the bot in a new tab with that code, and
 * then asks the site every 3 seconds whether the bot has approved the code.
 * Once it has, the site starts the session and the page reloads signed in.
 *
 * The choice shows straight away (a small script under the form swaps it in
 * before the first paint); the email form shows when Email is pressed.
 */
(function () {
    var choice = document.querySelector('[data-eventcrew-tg-login]');
    if (!choice || !window.fetch) { return; }

    var form = document.querySelector('form[data-eventcrew-signin]');
    var sent = document.getElementById('eventcrew-signin-sent');
    var back = document.querySelector('[data-eventcrew-tg-back]');
    var emailButton = choice.querySelector('[data-eventcrew-tg-email]');
    var tgButton = choice.querySelector('[data-eventcrew-tg-start]');
    var note = choice.querySelector('[data-eventcrew-tg-note]');
    var wait = document.querySelector('[data-eventcrew-tg-wait]');
    var again = wait ? wait.querySelector('[data-eventcrew-tg-again]') : null;
    var ajax = choice.getAttribute('data-ajax');
    var failedText = <?php echo wp_json_encode(\EventCrew\Web\SignupController::noticeText('tg_failed')); ?>;
    var expiredText = <?php echo wp_json_encode(\EventCrew\Web\SignupController::noticeText('tg_expired')); ?>;
    var matchBox = wait ? wait.querySelector('[data-eventcrew-tg-match]') : null;
    var matchText = <?php echo wp_json_encode(/* translators: %s: a 4-digit code, one of the bot's buttons */
        __('Your code is %s. In Telegram, press this code.', 'eventcrew')); ?>;
    var POLL_MS = 3000;
    var login = '';       // the code being waited on; '' when not waiting
    var deadline = 0;     // when the code expires, in ms since the epoch
    var timer = null;
    var asking = false;   // a status request is in flight
    var starting = false; // a start request is in flight

    function toast(text) {
        // The board script above moved the toast to <body>; it hides itself.
        var box = document.getElementById('eventcrew-toast');
        if (!box || !text) { return; }
        box.textContent = text;
        box.classList.add('show');
        setTimeout(function () { box.classList.remove('show'); }, 6000);
    }

    // The Telegram button has Telegram's colours (see eventcrew.css). Copy the
    // Email button's shape and font onto it, whatever the theme makes that.
    function matchLook() {
        var tg = choice.querySelector('.tg-auth-button');
        if (!tg || !emailButton || !window.getComputedStyle) { return; }
        var look = window.getComputedStyle(emailButton);
        ['fontFamily', 'fontWeight', 'fontSize', 'letterSpacing', 'textTransform', 'borderRadius',
            'paddingLeft', 'paddingRight', 'height', 'lineHeight'].forEach(function (key) {
            tg.style[key] = look[key];
        });
    }

    function showChoice() {
        stopWaiting();
        choice.hidden = false;
        matchLook();
        if (wait) { wait.hidden = true; }
        if (form) { form.hidden = true; }
        if (sent) { sent.hidden = true; }
        if (back) { back.hidden = true; }
    }

    function showEmail() {
        stopWaiting();
        choice.hidden = true;
        if (wait) { wait.hidden = true; }
        if (form) { form.hidden = false; }
        if (back) { back.hidden = false; }
    }

    function showWaiting(url, match) {
        choice.hidden = true;
        if (matchBox) { matchBox.textContent = matchText.replace('%s', match); }
        if (note) { note.hidden = true; }
        if (again) { again.href = url; }
        if (wait) { wait.hidden = false; }
        if (form) { form.hidden = true; }
        if (back) { back.hidden = false; }
    }

    function stopWaiting() {
        login = '';
        deadline = 0;
        if (timer) { clearTimeout(timer); timer = null; }
    }

    // Back to the choice with a line under the buttons, e.g. "The link expired".
    function resetWith(text) {
        showChoice();
        if (note && text) {
            note.textContent = text;
            note.hidden = false;
        }
    }

    function post(data) {
        var body = new FormData();
        Object.keys(data).forEach(function (key) { body.append(key, data[key]); });

        return fetch(ajax, { method: 'POST', body: body, credentials: 'same-origin', cache: 'no-store' })
            .then(function (response) {
                if (!response.ok) { throw new Error('HTTP ' + response.status); }
                return response.json().catch(function () { throw new Error('reply is not JSON'); });
            });
    }

    function signedIn() {
        stopWaiting();
        // A fresh address, so a cached signed-out copy is not shown again.
        var url = new URL(choice.getAttribute('data-here'), window.location.href);
        url.searchParams.set('eventcrew_notice', 'signed_in');
        window.location.assign(url.toString());
    }

    function schedule() {
        if (timer) { clearTimeout(timer); }
        timer = login ? setTimeout(check, POLL_MS) : null;
    }

    // One status request. Runs on the timer while the page is visible, and
    // straight away when the person comes back to this tab from Telegram.
    function check() {
        timer = null;
        if (!login) { return; }

        if (Date.now() >= deadline) {
            resetWith(expiredText);
            return;
        }

        if (document.visibilityState === 'hidden') { return; } // resumes on visibilitychange
        if (asking) { schedule(); return; }

        asking = true;
        var waitingFor = login;
        post({ action: choice.getAttribute('data-status-action'), login: waitingFor }).then(function (data) {
            asking = false;
            if (login !== waitingFor) { return; } // Back was pressed meanwhile

            var code = data && data.code ? data.code : 'tg_failed';

            if (code === 'signed_in') { signedIn(); return; }
            if (code === 'tg_pending') { schedule(); return; }
            if (code === 'tg_expired') { resetWith(expiredText); return; }

            resetWith('');
            toast((data && data.notice) || failedText);
        }).catch(function () {
            // A dropped request is not the end: try again on the next tick.
            asking = false;
            schedule();
        });
    }

    function start() {
        if (starting) { return; }
        starting = true;
        if (note) { note.hidden = true; }

        // Opened now, inside the click, so the browser does not block it;
        // the bot's address is filled in when the site answers.
        var tab = null;
        try { tab = window.open('', '_blank'); } catch (err) { tab = null; }

        post({ action: choice.getAttribute('data-start-action') }).then(function (data) {
            starting = false;
            var code = data && data.code ? data.code : 'tg_failed';

            if (code !== 'tg_started' || !data.login || !data.url || !data.match) {
                if (tab) { tab.close(); }
                toast((data && data.notice) || failedText);
                return;
            }

            login = String(data.login);
            deadline = Date.now() + (parseInt(data.expires_in, 10) || 1800) * 1000;
            showWaiting(data.url, String(data.match));

            if (tab) {
                try { tab.opener = null; } catch (err) { /* nothing to do */ }
                tab.location.href = data.url;
            }

            schedule();
        }).catch(function () {
            starting = false;
            if (tab) { tab.close(); }
            toast(failedText);
        });
    }

    if (emailButton) {
        emailButton.addEventListener('click', function () { showEmail(); });
    }

    if (tgButton) {
        tgButton.addEventListener('click', function () { start(); });
    }

    if (back) {
        back.addEventListener('click', function (event) {
            event.preventDefault();
            showChoice();
        });
    }

    // Coming back from Telegram: ask at once instead of waiting for the timer.
    function onReturn() {
        if (login && document.visibilityState !== 'hidden') { check(); }
    }
    document.addEventListener('visibilitychange', onReturn);
    window.addEventListener('focus', onReturn);

    showChoice();
})();
</script>

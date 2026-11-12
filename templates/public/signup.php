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
$eventcrew_pending = (int) ($view['pending_credits'] ?? 0);
$eventcrew_csrf = (string) $view['csrf'];
$eventcrew_turnstile_site_key = (string) $view['turnstile_site_key'];
/** @var array<string, string> $eventcrew_ticket_dates date => label a credit can be spent on */
$eventcrew_ticket_dates = is_array($view['ticket_dates'] ?? null) ? $view['ticket_dates'] : [];
$eventcrew_ticket_held = is_array($view['ticket_held'] ?? null) ? $view['ticket_held'] : [];
$eventcrew_redeem_action = (string) ($view['redeem_action'] ?? '');
/** @var array<string, array{label: string, dm: bool, email: bool, push: bool, channels?: list<string>}> $eventcrew_notify_matrix */
$eventcrew_notify_matrix = is_array($view['notify_matrix'] ?? null) ? $view['notify_matrix'] : [];
$eventcrew_prefs_action = (string) ($view['prefs_action'] ?? '');
/** @var array{upcoming: array<int, array{label: string, what: string, event: string, dj: string, when: string, url: string}>, past: array<int, array{label: string, what: string, event: string, dj: string, when: string, url: string}>} $eventcrew_my_tickets */
$eventcrew_my_tickets = is_array($view['my_tickets'] ?? null)
    ? $view['my_tickets']
    : ['upcoming' => [], 'past' => []];
/** @var array<int, array{task_id: int, day: string, event: string, dj: string, role: string, time: string, calendar_url: string}> $eventcrew_my_upcoming */
$eventcrew_my_upcoming = is_array($view['my_upcoming'] ?? null) ? $view['my_upcoming'] : [];
/** @var array<int, array{day: string, event: string, dj: string, role: string, time: string, status: string}> $eventcrew_my_history */
$eventcrew_my_history = is_array($view['my_history'] ?? null) ? $view['my_history'] : [];
// Each paged list's page and page count, by its query argument (signup-pager.php).
/** @var array<string, array{page: int, pages: int}> $eventcrew_pages */
$eventcrew_pages = [
    \EventCrew\Web\SignupBoardView::TASKS_PAGE_ARG => is_array($view['my_upcoming_paging'] ?? null) ? $view['my_upcoming_paging'] : ['page' => 1, 'pages' => 1],
    \EventCrew\Web\SignupBoardView::PAST_PAGE_ARG => is_array($view['my_history_paging'] ?? null) ? $view['my_history_paging'] : ['page' => 1, 'pages' => 1],
];
$eventcrew_manage_endpoint = (string) ($view['manage_endpoint'] ?? '');
$eventcrew_manage_token = (string) ($view['manage_token'] ?? '');
/*
 * The Admin tab's content, or null. Null for everyone who is not an organizer:
 * the tab and its panel are then not in the page at all. Hiding them is only
 * tidiness - every admin action checks the organizer flag again on the server.
 */
/** @var array{sales_action: string, board_action: string, gift_action: string, mark_action: string, members: array<int, string>, roster_label: string, roster: array<int, array{task_id: int, title: string, people: array<int, array{assignment_id: int, name: string, status: string, status_label: string, marked_by: string, markable: bool, standing: string, at_risk: bool}>}>, roster_date: string, roster_upcoming: array<string, string>, roster_past: array<string, string>, roster_action: string, door_action: string, guest_action: string, buyers_action: string, mail_action: string, statuses: array<string, string>, max_plus_ones: int, night: array{entrants: list<array{name: string, detail: string, standing: string, at_risk: bool, redemption_id: ?int, added_by: string}>, candidates: list<array{person_id: int, name: string, credit_balance: int}>, closed_by_hand: bool, event_reason: string, guests: list<array{id: int, name: string, plus_ones: int, note: string, email: string, added_by: string}>, buyers: list<string>, ticket_mail_buyers: string, ticket_mail_guests: string, ticket_mail_pending: string, followup_mail: string, followup_can_stop: bool, mailbox: string, mail_last: string}, roles_action: string, add_action: string, roles: array<string, string>, add_tasks: array<int, string>, person_action: string, pass_action: string, unban_action: string, newcomer_action?: string, newcomers?: list<array{id: int, name: string, email: string, since: string, answers?: string}>, people: array{search: string, page: int, pages: int, total: int, list: list<array{id: int, name: string, email: string, verified: bool, organizer: bool, banned: bool, anonymized: bool}>, editing: ?array{person: \EventCrew\Models\Person, standing: string, at_risk: bool, credits: int, given: list<array{credits: int, note: string, granted_by: string, granted_at: string}>, spent: list<array{day: string, event: string, redeemed_at: string}>}}}|null $eventcrew_admin */
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

/*
 * A used or expired sign-in link lands here signed out. The card then says so
 * in a line above the form instead of the toast, and the form sends the new
 * link back to the page the old one opened (this address without the notice;
 * same-site only, else this page).
 */
$eventcrew_link_expired = null === $eventcrew_person && 'bad_link' === $eventcrew_notice_code;
$eventcrew_signin_return = $eventcrew_here;
if ($eventcrew_link_expired) {
    $eventcrew_notice_text = '';
    $eventcrew_signin_return = wp_validate_redirect(remove_query_arg('eventcrew_notice'), $eventcrew_here);
}
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
        ];
        // The group chat on the web (signup-groupchat.php), for the crew it is open to.
        $eventcrew_tabs += \EventCrew\Telegram\GroupRelay::visibleTo($eventcrew_person) ? ['chat' => __('Chat', 'eventcrew')] : [];
        $eventcrew_tabs += [
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
    // The Telegram group button, at the top of the page so it shows above
    // every tab, signed in only. The crew guide sits under the person's
    // details in the header card; signed out it is not shown (Lou 2026-11-08).
    $eventcrew_group_link = null === $eventcrew_person ? '' : (string) ($view['telegram_group_link'] ?? '');
    $eventcrew_links_guide = false;
    require EVENTCREW_PLUGIN_DIR . 'templates/public/signup-links.php';
    ?>

    <?php if (null === $eventcrew_person) :
        // Signed out there are only two things - sign in, and look at the board
        // - so there is nothing to tab between and no tab strip is drawn.
        ?>
        <?php
        // The banner the Telegram board message carries (BoardBanner::url()),
        // behind the card; none: the plain card.
        $eventcrew_signin_banner = (string) ($view['signin_banner'] ?? '');
        ?>
        <section class="eventcrew-event eventcrew-me-card eventcrew-signin-card<?php echo '' !== $eventcrew_signin_banner ? ' has-image' : ''; ?>" id="eventcrew-signin"<?php
        if ('' !== $eventcrew_signin_banner) {
            printf(' style="%s"', esc_attr('--eventcrew-event-image: url("' . esc_url($eventcrew_signin_banner) . '")'));
        }
        ?>>
            <header class="eventcrew-event-head">
                <h2 class="eventcrew-event-title"><?php esc_html_e('Sign in or sign up', 'eventcrew'); ?></h2>
            </header>
            <div class="eventcrew-card-body">
            <?php if ($eventcrew_link_expired) : ?>
                <p class="eventcrew-signin-expired" role="status"><?php echo esc_html(\EventCrew\Web\SignupController::noticeText('bad_link')); ?></p>
            <?php endif; ?>
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
                <?php
                /*
                 * The code first, Telegram second: the card shows the code in
                 * two pairs and Telegram opens only on Open Telegram, a plain
                 * link pressed by the person (no blocked pop-up, and on a phone
                 * t.me opens the app). The digits are filled in by the script;
                 * they are never in the cached markup.
                 */
                ?>
                <div class="eventcrew-tg-wait" data-eventcrew-tg-wait hidden>
                    <p class="eventcrew-tg-code-label"><?php esc_html_e('Your code', 'eventcrew'); ?></p>
                    <p class="eventcrew-tg-code" data-eventcrew-tg-code>
                        <span class="eventcrew-tg-pair" data-eventcrew-tg-pair></span>
                        <span class="eventcrew-tg-pair" data-eventcrew-tg-pair></span>
                    </p>
                    <p class="eventcrew-tg-hint">
                        <?php esc_html_e('In Telegram, press Start. It then shows three codes: press this one.', 'eventcrew'); ?>
                    </p>
                    <p class="eventcrew-tg-open" data-eventcrew-tg-open-row>
                        <a class="tg-auth-button" href="#" target="_blank" rel="noopener" data-eventcrew-tg-open>
                            <?php esc_html_e('Open Telegram', 'eventcrew'); ?>
                        </a>
                    </p>
                    <div class="eventcrew-tg-opened" data-eventcrew-tg-opened hidden>
                        <p class="eventcrew-tg-waiting" role="status">
                            <?php esc_html_e('Waiting for Telegram…', 'eventcrew'); ?>
                        </p>
                        <p>
                            <a href="#" target="_blank" rel="noopener" data-eventcrew-tg-again>
                                <?php esc_html_e('Open Telegram again', 'eventcrew'); ?>
                            </a>
                        </p>
                    </div>
                </div>
            <?php endif; ?>
            <form class="eventcrew-action eventcrew-signin" data-eventcrew-signin method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr((string) $view['login_action']); ?>">
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_signin_return); ?>">
                <?php
                /*
                 * The captcha lives inside the row, between the field and the
                 * button, and switches the row to a column. Left beside a
                 * side-by-side field and button it made an L of three things
                 * that do not share an edge; stacked, all three line up and the
                 * order reads the way the form is filled in.
                 */
                ?>
                <?php
                /*
                 * The newcomer's questions (JoinAnswers, Owner 2026-11-07), for
                 * the organizers who say OK to a new account. All optional and
                 * kept only when the address has no account yet. The numbers
                 * show once Yes is ticked (:has(); a browser without it shows
                 * them all the time, so every question the bot asks can still
                 * be answered, and a No drops them).
                 */
                ?>
                <fieldset class="eventcrew-join<?php echo '' !== $eventcrew_turnstile_site_key ? ' has-captcha' : ''; ?>">
                    <legend><?php esc_html_e('Have you worked on the crew here before?', 'eventcrew'); ?></legend>
                    <p class="eventcrew-muted eventcrew-hint"><?php esc_html_e('Only for a new account, and optional.', 'eventcrew'); ?></p>
                    <div class="eventcrew-join-choice">
                        <label><input type="radio" name="<?php echo esc_attr(\EventCrew\Support\JoinAnswers::FIELD_BEFORE); ?>" value="<?php echo esc_attr(\EventCrew\Support\JoinAnswers::YES); ?>"> <?php esc_html_e('Yes', 'eventcrew'); ?></label>
                        <label><input type="radio" name="<?php echo esc_attr(\EventCrew\Support\JoinAnswers::FIELD_BEFORE); ?>" value="<?php echo esc_attr(\EventCrew\Support\JoinAnswers::NO); ?>"> <?php esc_html_e('No', 'eventcrew'); ?></label>
                    </div>
                    <div class="eventcrew-join-more">
                        <label>
                            <?php esc_html_e('About how many times?', 'eventcrew'); ?>
                            <input type="number" class="wp-element-input" inputmode="numeric" name="<?php echo esc_attr(\EventCrew\Support\JoinAnswers::FIELD_TIMES); ?>" min="<?php echo (int) \EventCrew\Support\JoinAnswers::MIN_TIMES; ?>" max="<?php echo (int) \EventCrew\Support\JoinAnswers::MAX_TIMES; ?>" step="1">
                        </label>
                        <label>
                            <?php esc_html_e('How many free-entry tickets do you think you should have?', 'eventcrew'); ?>
                            <input type="number" class="wp-element-input" inputmode="numeric" name="<?php echo esc_attr(\EventCrew\Support\JoinAnswers::FIELD_TICKETS); ?>" min="<?php echo (int) \EventCrew\Support\JoinAnswers::MIN_TICKETS; ?>" max="<?php echo (int) \EventCrew\Support\JoinAnswers::MAX_TICKETS; ?>" step="1">
                        </label>
                    </div>
                </fieldset>
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
                    <?php
                    // Cloudflare's script loads when the form is first used, not with the page.
                    echo \EventCrew\Support\Turnstile::loaderScript(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed inline script, strings JSON-escaped.
                    ?>
                <?php endif; ?>
            </form>
            <p id="eventcrew-signin-sent" class="eventcrew-muted eventcrew-hint" hidden><?php esc_html_e('Check your inbox for the sign-in link — and your spam folder if it’s not there. Press the button again to resend.', 'eventcrew'); ?></p>
            <?php
            /*
             * The code from the sign-in email, typed here instead of opening
             * the link: an app on an iPhone home screen has cookies of its
             * own, so the link would sign in Safari, not the app. Shown by
             * the script once a link was sent (and again after a reload, for
             * the life of the link), so the cached page is the same for
             * everyone; the address comes from the form above.
             */
            ?>
            <form class="eventcrew-signin-code" data-eventcrew-code method="post" action="<?php echo esc_url($eventcrew_ajax); ?>" hidden>
                <input type="hidden" name="action" value="<?php echo esc_attr((string) ($view['code_action'] ?? '')); ?>">
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_signin_return); ?>">
                <input type="hidden" name="email" value="">
                <label for="eventcrew-code"><?php esc_html_e('Or type the code from the email', 'eventcrew'); ?></label>
                <span class="eventcrew-signin-code-row">
                    <input
                        type="text"
                        id="eventcrew-code"
                        name="code"
                        required
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        pattern="[0-9 ]*"
                        maxlength="<?php echo (int) (\EventCrew\Support\LoginCode::LENGTH + 1); ?>"
                        size="<?php echo (int) (\EventCrew\Support\LoginCode::LENGTH + 1); ?>"
                        class="wp-element-input">
                    <button type="submit" class="eventcrew-textlink"><?php esc_html_e('Sign in', 'eventcrew'); ?></button>
                </span>
            </form>
            <script>
            (function () {
                var form = document.querySelector('form[data-eventcrew-code]');
                if (!form || !window.fetch || !window.FormData) { return; }
                var WAIT_KEY = 'eventcrew_code_wait';
                var EMAIL_KEY = 'eventcrew_email';
                // As long as the emailed link and code are good.
                var WAIT_MS = <?php echo (int) (30 * 60 * 1000); ?>;
                var field = form.querySelector('input[name="code"]');
                var email = form.querySelector('input[name="email"]');
                var busy = false;

                function toast(text) {
                    var box = document.getElementById('eventcrew-toast');
                    if (!box || !text) { return; }
                    box.textContent = text;
                    box.classList.add('show');
                    clearTimeout(box.eventcrewCodeTimer);
                    box.eventcrewCodeTimer = setTimeout(function () { box.classList.remove('show'); }, 6000);
                }

                function forget() {
                    try { localStorage.removeItem(WAIT_KEY); } catch (e) {}
                }

                // Called by the sign-in form's script when a link was sent.
                window.eventcrewShowCode = function (address) {
                    if (!address) { return; }
                    email.value = address;
                    form.hidden = false;
                    try { localStorage.setItem(WAIT_KEY, String(Date.now() + WAIT_MS)); } catch (e) {}
                };

                // Back after the phone reloaded the app (it often does when
                // the mail app was opened): the field is there again.
                try {
                    var until = parseInt(localStorage.getItem(WAIT_KEY) || '0', 10);
                    var address = localStorage.getItem(EMAIL_KEY) || '';
                    if (until > Date.now() && address) {
                        email.value = address;
                        form.hidden = false;
                        var sent = document.getElementById('eventcrew-signin-sent');
                        if (sent) { sent.hidden = false; }
                    } else if (until) {
                        forget();
                    }
                } catch (e) {}

                function send() {
                    if (busy || !field.value.replace(/\D/g, '')) { return; }
                    busy = true;
                    var data = new FormData(form);
                    data.append('eventcrew_ajax', '1');
                    fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store' })
                        .then(function (response) { return response.json(); })
                        .then(function (res) {
                            busy = false;
                            if (res && 'signed_in' === res.code) {
                                forget();
                                var url = new URL(form.querySelector('input[name="redirect_to"]').value, window.location.href);
                                url.searchParams.set('eventcrew_notice', 'signed_in');
                                window.location.assign(url.toString());
                                return;
                            }
                            if (res && 'code_locked' === res.code) {
                                forget();
                                form.hidden = true;
                            }
                            field.value = '';
                            toast(res && res.notice);
                        })
                        .catch(function () { busy = false; });
                }

                form.addEventListener('submit', function (event) {
                    event.preventDefault();
                    send();
                });
                // Six digits typed or pasted: no need to press Sign in.
                field.addEventListener('input', function () {
                    if (field.value.replace(/\D/g, '').length === <?php echo (int) \EventCrew\Support\LoginCode::LENGTH; ?>) {
                        send();
                    }
                });
            })();
            </script>
            <script>
            // A board row's Sign up, signed out, jumps here (#eventcrew-signin).
            // The sign-in, by email link or Telegram, then comes back to
            // that task's row instead of the top of the page.
            //
            // With JavaScript the jump is a smooth scroll (a plain jump when
            // the visitor asks for less motion), the card glows once, and a
            // toast says to sign in first. Without it the link still jumps.
            document.addEventListener('click', function (event) {
                var link = event.target.closest ? event.target.closest('[data-eventcrew-signin-task], a[href="#eventcrew-signin"]') : null;
                if (!link) { return; }
                var task = link.getAttribute('data-eventcrew-signin-task');
                if (task) {
                    var back = new URL(<?php echo wp_json_encode($eventcrew_signin_return); ?>, window.location.href);
                    back.hash = <?php echo wp_json_encode(\EventCrew\Support\SlotFreedNotice::ANCHOR_PREFIX); ?> + task;
                    var field = document.querySelector('form[data-eventcrew-signin] input[name="redirect_to"]');
                    var choice = document.querySelector('[data-eventcrew-tg-login]');
                    if (field) { field.value = back.toString(); }
                    if (choice) { choice.setAttribute('data-here', back.toString()); }
                }

                var card = document.getElementById('eventcrew-signin');
                if (!card || !card.scrollIntoView) { return; }
                event.preventDefault();
                var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                card.scrollIntoView({ behavior: still ? 'auto' : 'smooth', block: 'start' });
                if (!card.hasAttribute('tabindex')) { card.setAttribute('tabindex', '-1'); }
                try { card.focus({ preventScroll: true }); } catch (e) { /* an old browser: no focus move */ }

                // The glow once the card is in view: on scrollend, or after a
                // moment where that event does not exist.
                var glowed = false;
                function glow() {
                    if (glowed) { return; }
                    glowed = true;
                    window.removeEventListener('scrollend', glow);
                    card.classList.remove('is-calling');
                    void card.offsetWidth; // restart the animation on a second press
                    card.classList.add('is-calling');
                    setTimeout(function () { card.classList.remove('is-calling'); }, 1200);
                }
                if (still) { glow(); } else {
                    window.addEventListener('scrollend', glow);
                    setTimeout(glow, 700);
                }

                // The board script moves the toast to <body>; it is a polite
                // live region, so the text is read out without taking focus.
                var toast = document.getElementById('eventcrew-toast');
                if (toast) {
                    toast.textContent = <?php echo wp_json_encode(__('Sign in first to take a task.', 'eventcrew')); ?>;
                    toast.classList.add('show');
                    clearTimeout(toast.eventcrewSigninTimer);
                    toast.eventcrewSigninTimer = setTimeout(function () { toast.classList.remove('show'); }, 3000);
                }
            });
            </script>
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
                    <span class="eventcrew-standing-icon" aria-hidden="true">👤</span>
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
                 *
                 * On the Crew app's own page the switch goes up into the bar
                 * beside "← Website", on the opposite side, so the two take
                 * one line (Owner 2026-11-08, CrewAppPage::putInBar()); inside
                 * a theme page it stays here.
                 */
                ?>
                <?php if ('' !== (string) ($view['language_action'] ?? '')) : ?>
                    <?php ob_start(); ?>
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
                    <?php
                    $eventcrew_language_form = (string) ob_get_clean();

                    if (! \EventCrew\Web\CrewAppPage::putInBar($eventcrew_language_form)) {
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it was written above.
                        echo $eventcrew_language_form;
                    }
                    ?>
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
                    <?php
                    /*
                     * One line each with an icon (Owner 2026-11-08): the
                     * standing, the completed tasks and the free-entry
                     * credits. The same counts and words as the bot's My info
                     * (Owner 2026-11-08), so one translation serves both.
                     */
                    ?>
                    <ul class="eventcrew-standing">
                        <li><span class="eventcrew-standing-icon" aria-hidden="true">🌟</span><span><?php echo esc_html($eventcrew_standing->ratedSummary()); ?></span></li>
                        <li><span class="eventcrew-standing-icon" aria-hidden="true">✅</span><span><?php echo esc_html(sprintf(/* translators: %d: completed tasks */
                            __('Completed tasks: %d', 'eventcrew'),
                            $eventcrew_standing->completedCount
                        )); ?></span></li>
                        <li><span class="eventcrew-standing-icon" aria-hidden="true">🎟️</span><span><?php echo esc_html(sprintf(/* translators: %d: free-entry credits */
                            __('Free-entry credits: %d', 'eventcrew'),
                            $eventcrew_standing->creditBalance
                        ) . \EventCrew\Support\ExtraTaskCredits::pendingSuffix((int) ($eventcrew_pending ?? 0))); ?></span></li>
                    </ul>
                    <?php
                    /*
                     * The standing as one bar once rated, green, orange or
                     * red by where the score is, a small mark at the limit
                     * and a short text (StandingBar, Owner Q382). Before
                     * that, how many tasks to go.
                     */
                    ?>
                    <?php if ($eventcrew_standing->isRated()) : ?>
                        <?php
                        $eventcrew_bar_percent = $eventcrew_standing->scorePercent();
                        $eventcrew_bar_limit = \EventCrew\Support\StandingBar::limit();
                        ?>
                        <div class="eventcrew-standing-bar eventcrew-standing-bar--<?php echo esc_attr(\EventCrew\Support\StandingBar::zone($eventcrew_bar_percent, $eventcrew_bar_limit)); ?>"
                            role="img"
                            aria-label="<?php echo esc_attr(\EventCrew\Support\StandingBar::label($eventcrew_bar_percent, $eventcrew_bar_limit)); ?>"
                            style="<?php echo esc_attr(sprintf('--eventcrew-bar-fill:%d%%;--eventcrew-bar-limit:%d%%', $eventcrew_bar_percent, $eventcrew_bar_limit)); ?>">
                            <span class="eventcrew-standing-bar-track"><span class="eventcrew-standing-bar-fill"></span></span>
                            <span class="eventcrew-standing-bar-limit"></span>
                        </div>
                        <p class="eventcrew-muted eventcrew-standing-bar-label"><strong><?php echo esc_html($eventcrew_bar_percent . '%'); ?></strong> · <?php echo esc_html(\EventCrew\Support\StandingBar::state($eventcrew_bar_percent, $eventcrew_bar_limit)); ?></p>
                    <?php else : ?>
                        <p class="eventcrew-muted"><?php echo esc_html(\EventCrew\Support\StandingBar::untilRated($eventcrew_standing)); ?></p>
                    <?php endif; ?>
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
            <?php
            // The crew guide under the person's details, centred (Owner
            // 2026-11-08: the 🎟 Tickets button that stood above it is gone;
            // tickets are on the Me tab). Signed out there is no guide link.
            $eventcrew_header_guide = \EventCrew\Support\RoleGuide::emailAction();
            ?>
            <?php if (null !== $eventcrew_header_guide) : ?>
                <div class="eventcrew-card-body eventcrew-header-guide">
                    <p>
                        <a class="eventcrew-textlink" href="<?php echo esc_url($eventcrew_header_guide['url']); ?>" target="_blank" rel="noopener">
                            <?php echo esc_html($eventcrew_header_guide['label']); ?>
                        </a>
                    </p>
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
             * its name, and its rows under it. In order (Owner 2026-11-08):
             * Get a ticket, Tickets, Tasks, Past tickets, Past tasks. Tickets,
             * Tasks and Past tasks are always drawn, even empty, because "you
             * are not on anything yet" is worth knowing.
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
            <?php
            /*
             * Get a ticket: spending a credit, its own card, only while there
             * is a credit and an event to spend it on. It sits above the
             * tickets because what it produces is a ticket.
             */
            ?>
            <?php if ('' !== $eventcrew_redeem_action && [] !== $eventcrew_ticket_dates) : ?>
                <section class="eventcrew-event eventcrew-me-card" id="eventcrew-get-ticket">
                    <header class="eventcrew-event-head">
                        <h3 class="eventcrew-event-title"><?php esc_html_e('Get a ticket', 'eventcrew'); ?></h3>
                    </header>
                    <div class="eventcrew-card-body">
                        <form class="eventcrew-action eventcrew-redeem" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>"
                            data-eventcrew-confirm="<?php echo esc_attr(\EventCrew\Telegram\TicketRedemptionService::creditQuestion('%s', null === $eventcrew_standing ? 0 : $eventcrew_standing->creditBalance)); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_redeem_action); ?>">
                            <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                            <label for="eventcrew-ticket-date"><?php esc_html_e('Spend a free-entry credit on', 'eventcrew'); ?></label>
                            <select name="ticket_date" id="eventcrew-ticket-date">
                                <?php foreach ($eventcrew_ticket_dates as $eventcrew_date => $eventcrew_date_label) : ?>
                                    <option value="<?php echo esc_attr((string) $eventcrew_date); ?>"><?php echo esc_html($eventcrew_date_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php foreach ($eventcrew_ticket_dates as $eventcrew_date => $eventcrew_date_label) : ?>
                                <input type="hidden" name="ticket_held[<?php echo esc_attr((string) $eventcrew_date); ?>]" value="<?php echo esc_attr((string) ($eventcrew_ticket_held[$eventcrew_date] ?? 0)); ?>">
                            <?php endforeach; ?>
                            <button type="submit" class="wp-element-button"><?php esc_html_e('Get my ticket', 'eventcrew'); ?></button>
                        </form>
                    </div>
                </section>
            <?php endif; ?>

            <?php
            /*
             * The coming tickets, task tickets too, as on the bot's Tickets
             * screen. Always drawn, like Tasks. Each entry: day, event, DJs,
             * what the ticket is, then text links (Ticket, Give away / Cancel
             * offer, as on the bot, Owner 2026-11-08), so the card has no
             * button at all; Give away opens its form under the link.
             */
            ?>
            <section class="eventcrew-event eventcrew-me-card" id="eventcrew-my-tickets">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('Tickets', 'eventcrew'); ?></h3>
                </header>
                <?php if ([] === $eventcrew_my_tickets['upcoming']) : ?>
                    <p class="eventcrew-muted eventcrew-card-body">
                        <?php
                        echo esc_html(
                            [] === $eventcrew_my_tickets['past']
                                ? __('No tickets yet — sign up for a task or spend a credit.', 'eventcrew')
                                : __('No coming tickets.', 'eventcrew')
                        );
                        ?>
                    </p>
                <?php else : ?>
                    <ul class="eventcrew-plain-list eventcrew-me-list">
                        <?php foreach ($eventcrew_my_tickets['upcoming'] as $eventcrew_ticket) : ?>
                            <?php
                            $eventcrew_row = [
                                'day' => \EventCrew\Web\SignupBoardView::humanDate((string) $eventcrew_ticket['when']),
                                'event' => (string) ($eventcrew_ticket['event'] ?? ''),
                                'dj' => (string) ($eventcrew_ticket['dj'] ?? ''),
                                'what' => (string) ($eventcrew_ticket['what'] ?? $eventcrew_ticket['label']),
                                'used' => $eventcrew_ticket['used'] ?? null,
                            ];
                            $eventcrew_rid = (int) ($eventcrew_ticket['redemption_id'] ?? 0);
                            $eventcrew_offer_to = (string) ($eventcrew_ticket['offer_to'] ?? '');
                            ?>
                            <li class="eventcrew-me-row">
                                <?php require EVENTCREW_PLUGIN_DIR . 'templates/public/signup-me-row.php'; ?>
                                <?php if ('' !== $eventcrew_offer_to) : ?>
                                    <span class="eventcrew-me-what">
                                        <?php
                                        /* translators: %s: the name of the person the ticket was offered to */
                                        echo esc_html(sprintf(__('Waiting for %s to accept', 'eventcrew'), $eventcrew_offer_to));
                                        ?>
                                    </span>
                                <?php endif; ?>
                                <span class="eventcrew-me-actions">
                                    <a class="eventcrew-textlink" href="<?php echo esc_url((string) $eventcrew_ticket['url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Ticket', 'eventcrew'); ?></a>
                                    <?php if ('' !== $eventcrew_offer_to) : ?>
                                        <form class="eventcrew-action eventcrew-inline-form" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>"
                                            data-eventcrew-reload="eventcrew-my-tickets">
                                            <input type="hidden" name="action" value="<?php echo esc_attr((string) ($view['cancel_offer_action'] ?? '')); ?>">
                                            <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                                            <input type="hidden" name="redemption_id" value="<?php echo esc_attr((string) $eventcrew_rid); ?>">
                                            <button type="submit" class="eventcrew-textlink"><?php esc_html_e('Cancel offer', 'eventcrew'); ?></button>
                                        </form>
                                    <?php elseif (! empty($eventcrew_ticket['give'])) : ?>
                                        <details class="eventcrew-give">
                                            <summary class="eventcrew-textlink"><?php esc_html_e('Give away', 'eventcrew'); ?></summary>
                                            <form class="eventcrew-action eventcrew-give-form" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>"
                                                data-eventcrew-reload="eventcrew-my-tickets">
                                                <input type="hidden" name="action" value="<?php echo esc_attr((string) ($view['give_action'] ?? '')); ?>">
                                                <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                                                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                                                <input type="hidden" name="redemption_id" value="<?php echo esc_attr((string) $eventcrew_rid); ?>">
                                                <label for="eventcrew-give-email-<?php echo esc_attr((string) $eventcrew_rid); ?>"><?php esc_html_e('Email', 'eventcrew'); ?></label>
                                                <input type="email" class="wp-element-input" name="email" required autocomplete="off"
                                                    id="eventcrew-give-email-<?php echo esc_attr((string) $eventcrew_rid); ?>">
                                                <label for="eventcrew-give-name-<?php echo esc_attr((string) $eventcrew_rid); ?>"><?php esc_html_e('Their name', 'eventcrew'); ?></label>
                                                <input type="text" class="wp-element-input" name="name" autocomplete="off"
                                                    maxlength="<?php echo esc_attr((string) \EventCrew\Support\TicketTransferService::NAME_MAX); ?>"
                                                    id="eventcrew-give-name-<?php echo esc_attr((string) $eventcrew_rid); ?>">
                                                <button type="submit" class="eventcrew-textlink"><?php esc_html_e('Send the offer', 'eventcrew'); ?></button>
                                            </form>
                                        </details>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="eventcrew-event eventcrew-me-card" id="eventcrew-my-tasks">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('Tasks', 'eventcrew'); ?></h3>
                </header>
                <?php
                // Card titles and the empty text follow the bot's Tasks and
                // Past tasks (Owner 2026-11-08); the web keeps a link to the board.
                ?>
                <?php if ([] === $eventcrew_my_upcoming) : ?>
                    <p class="eventcrew-muted eventcrew-card-body">
                        <?php esc_html_e('No coming tasks.', 'eventcrew'); ?>
                        <a class="eventcrew-textlink" href="#eventcrew-panel-board" data-eventcrew-goto="eventcrew-panel-board"><?php esc_html_e('See open tasks', 'eventcrew'); ?></a>
                    </p>
                <?php else : ?>
                    <ul class="eventcrew-plain-list eventcrew-me-list">
                        <?php foreach ($eventcrew_my_upcoming as $eventcrew_mine) : ?>
                            <?php
                            $eventcrew_row = [
                                'day' => (string) $eventcrew_mine['day'],
                                'side' => (string) $eventcrew_mine['time'],
                                'event' => (string) $eventcrew_mine['event'],
                                'dj' => (string) $eventcrew_mine['dj'],
                                'what' => (string) $eventcrew_mine['role'],
                                'used' => $eventcrew_mine['used'] ?? null,
                            ];
                            ?>
                            <li class="eventcrew-me-row">
                                <?php require EVENTCREW_PLUGIN_DIR . 'templates/public/signup-me-row.php'; ?>
                                <span class="eventcrew-me-actions">
                                    <a class="eventcrew-textlink" href="<?php echo esc_url((string) $eventcrew_mine['ticket_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Ticket', 'eventcrew'); ?></a>
                                    <a class="eventcrew-textlink" href="<?php echo esc_url($eventcrew_mine['calendar_url']); ?>"><?php esc_html_e('Add to calendar', 'eventcrew'); ?></a>
                                    <?php
                                    /*
                                     * Cancel here too, as a text link (Owner 2026-11-08):
                                     * the board's drop action with the board's question.
                                     * The page reloads after it, so this card and the
                                     * Tickets card show the change.
                                     */
                                    ?>
                                    <form class="eventcrew-action eventcrew-inline-form" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>"
                                        data-eventcrew-confirm="<?php echo esc_attr((string) ($eventcrew_mine['cancel_ask'] ?? '')); ?>"
                                        data-eventcrew-reload="eventcrew-my-tasks">
                                        <input type="hidden" name="action" value="<?php echo esc_attr(\EventCrew\Web\SignupController::DROP_ACTION); ?>">
                                        <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                                        <input type="hidden" name="task_id" value="<?php echo esc_attr((string) $eventcrew_mine['task_id']); ?>">
                                        <button type="submit" class="eventcrew-textlink"><?php esc_html_e('Cancel', 'eventcrew'); ?></button>
                                    </form>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php
                    $eventcrew_pager = ['arg' => \EventCrew\Web\SignupBoardView::TASKS_PAGE_ARG, 'anchor' => 'eventcrew-my-tasks'];
                    require EVENTCREW_PLUGIN_DIR . 'templates/public/signup-pager.php';
                    ?>
                <?php endif; ?>
            </section>

            <?php
            /*
             * Past tickets: only listed, as on the bot, since there is no door
             * left to show them at; the card is left out until there is one.
             */
            ?>
            <?php if ([] !== $eventcrew_my_tickets['past']) : ?>
                <section class="eventcrew-event eventcrew-me-card" id="eventcrew-past-tickets">
                    <header class="eventcrew-event-head">
                        <h3 class="eventcrew-event-title"><?php esc_html_e('Past tickets', 'eventcrew'); ?></h3>
                    </header>
                    <ul class="eventcrew-plain-list eventcrew-me-list">
                        <?php foreach ($eventcrew_my_tickets['past'] as $eventcrew_ticket) : ?>
                            <?php
                            $eventcrew_row = [
                                'day' => \EventCrew\Web\SignupBoardView::humanDate((string) $eventcrew_ticket['when']),
                                'event' => (string) ($eventcrew_ticket['event'] ?? ''),
                                'dj' => (string) ($eventcrew_ticket['dj'] ?? ''),
                                'what' => (string) ($eventcrew_ticket['what'] ?? $eventcrew_ticket['label']),
                                'used' => $eventcrew_ticket['used'] ?? null,
                            ];
                            ?>
                            <li class="eventcrew-me-row">
                                <?php require EVENTCREW_PLUGIN_DIR . 'templates/public/signup-me-row.php'; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <?php
            /*
             * Every past task, a page at a time, and the card drawn even
             * before the first one with the bot's words (Owner 2026-11-08:
             * like Telegram's Past tasks). The status sits where a coming
             * task shows its times.
             */
            ?>
            <section class="eventcrew-event eventcrew-me-card" id="eventcrew-past-tasks">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('Past tasks', 'eventcrew'); ?></h3>
                </header>
                <?php if ([] === $eventcrew_my_history) : ?>
                    <p class="eventcrew-muted eventcrew-card-body"><?php echo esc_html(\EventCrew\Telegram\ProfileService::noPastTasksText()); ?></p>
                <?php else : ?>
                    <ul class="eventcrew-plain-list eventcrew-me-list">
                        <?php foreach ($eventcrew_my_history as $eventcrew_past) : ?>
                            <?php
                            $eventcrew_row = [
                                'day' => (string) $eventcrew_past['day'],
                                'side' => (string) $eventcrew_past['status'],
                                'side_class' => 'eventcrew-me-status',
                                'event' => (string) $eventcrew_past['event'],
                                'dj' => (string) $eventcrew_past['dj'],
                                'what' => implode(' · ', array_filter([(string) $eventcrew_past['role'], (string) $eventcrew_past['time']], static fn (string $part): bool => '' !== $part)),
                            ];
                            ?>
                            <li class="eventcrew-me-row">
                                <?php require EVENTCREW_PLUGIN_DIR . 'templates/public/signup-me-row.php'; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php
                    $eventcrew_pager = ['arg' => \EventCrew\Web\SignupBoardView::PAST_PAGE_ARG, 'anchor' => 'eventcrew-past-tasks'];
                    require EVENTCREW_PLUGIN_DIR . 'templates/public/signup-pager.php';
                    ?>
                <?php endif; ?>
            </section>
        </section>

        <?php if (\EventCrew\Telegram\GroupRelay::visibleTo($eventcrew_person)) : ?>
            <?php require EVENTCREW_PLUGIN_DIR . 'templates/public/signup-groupchat.php'; ?>
        <?php endif; ?>

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
                <section class="eventcrew-event eventcrew-me-card" id="eventcrew-notifications">
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
                                <th><?php echo esc_html(_x('App', 'notification channel', 'eventcrew')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($eventcrew_notify_matrix as $eventcrew_type => $eventcrew_pref) : ?>
                            <tr>
                                <td><?php echo esc_html($eventcrew_pref['label']); ?></td>
                                <?php foreach (['dm', 'email', 'push'] as $eventcrew_channel) : ?>
                                    <td class="eventcrew-tick">
                                        <?php if (in_array($eventcrew_channel, (array) ($eventcrew_pref['channels'] ?? ['dm', 'email', 'push']), true)) : ?>
                                            <input type="checkbox" name="prefs[<?php echo esc_attr((string) $eventcrew_type); ?>][<?php echo esc_attr($eventcrew_channel); ?>]" value="1" <?php checked($eventcrew_pref[$eventcrew_channel] ?? false); ?>>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="eventcrew-muted"><?php esc_html_e('Signup confirmations and task reminders are always sent on every channel.', 'eventcrew'); ?></p>
                    <button type="submit" class="wp-element-button eventcrew-linkbtn eventcrew-settings-btn"><?php esc_html_e('Save preferences', 'eventcrew'); ?></button>
                </form>
                <?php require EVENTCREW_PLUGIN_DIR . 'templates/public/signup-push.php'; ?>
                </section>
            <?php endif; ?>

            <?php
            /*
             * Language and Telegram, as under the bot's Settings (Owner
             * 2026-11-08: Settings alike on both, in the same order - details,
             * notifications, language, the other side, sign out everywhere,
             * delete). Language posts to the header bar's own handler.
             */
            ?>
            <?php if ('' !== (string) ($view['language_action'] ?? '') && count((array) ($view['language_choices'] ?? [])) > 1) : ?>
                <section class="eventcrew-event eventcrew-me-card" id="eventcrew-settings-language">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('Language', 'eventcrew'); ?></h3>
                </header>
                <form class="eventcrew-card-body eventcrew-language eventcrew-language-settings" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr((string) $view['language_action']); ?>">
                    <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here . '#eventcrew-panel-settings'); ?>">
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
                </section>
            <?php endif; ?>

            <?php $eventcrew_tg_link = is_array($view['telegram_link'] ?? null) ? $view['telegram_link'] : null; ?>
            <?php if (null !== $eventcrew_tg_link) : ?>
                <section class="eventcrew-event eventcrew-me-card" id="eventcrew-settings-telegram">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('Telegram', 'eventcrew'); ?></h3>
                </header>
                <div class="eventcrew-card-body">
                    <?php if (! empty($eventcrew_tg_link['linked'])) : ?>
                        <p class="eventcrew-muted"><?php esc_html_e('Your Telegram is linked to this account.', 'eventcrew'); ?></p>
                        <a class="eventcrew-textlink" href="<?php echo esc_url((string) $eventcrew_tg_link['url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open in Telegram', 'eventcrew'); ?></a>
                    <?php else : ?>
                        <p class="eventcrew-muted"><?php esc_html_e('The bot asks for your email and sends a link there; opening it links your Telegram to this account.', 'eventcrew'); ?></p>
                        <a class="eventcrew-textlink" href="<?php echo esc_url((string) $eventcrew_tg_link['url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Sign in with Telegram', 'eventcrew'); ?></a>
                    <?php endif; ?>
                </div>
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
         * Help, with the same parts and names as the bot's Help menu (Owner
         * 2026-11-08): How this works? (the welcome text), Commands (the bot's
         * commands, only when there is a bot to use them in) and FAQ (Faq, one
         * disclosure per question). An organizer also gets the organizer guide
         * lower down. A row of text links at the top jumps to each part and
         * opens the crew guide.
         */
        $eventcrew_help_bot = \EventCrew\Support\TelegramLogin::botUsername();
        $eventcrew_help_guide = \EventCrew\Support\RoleGuide::emailAction();
        ?>
        <section class="eventcrew-panel eventcrew-help" id="<?php echo esc_attr(\EventCrew\Support\HelpPage::PANEL_ANCHOR); ?>" data-eventcrew-panel="help" aria-labelledby="eventcrew-tab-help">
            <h2 class="eventcrew-panel-title"><?php esc_html_e('Help', 'eventcrew'); ?></h2>

            <p class="eventcrew-links eventcrew-help-orglink">
                <a class="eventcrew-textlink" href="#eventcrew-help-guide"><?php echo esc_html('📖 ' . __('How this works?', 'eventcrew')); ?></a>
                <?php if ('' !== $eventcrew_help_bot) : ?>
                    · <a class="eventcrew-textlink" href="#eventcrew-help-commands"><?php echo esc_html('⌨️ ' . __('Commands', 'eventcrew')); ?></a>
                <?php endif; ?>
                · <a class="eventcrew-textlink" href="#eventcrew-help-faq"><?php echo esc_html('💡 ' . __('FAQ', 'eventcrew')); ?></a>
                <?php if (null !== $eventcrew_help_guide) : ?>
                    · <a class="eventcrew-textlink" href="<?php echo esc_url($eventcrew_help_guide['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($eventcrew_help_guide['label']); ?></a>
                <?php endif; ?>
                <?php if ($eventcrew_person->isOrganizer) : ?>
                    · <a class="eventcrew-textlink" href="#<?php echo esc_attr(\EventCrew\Support\HelpPage::ORGANIZER_ANCHOR); ?>"><?php esc_html_e('🧭 Organizer guide ↓', 'eventcrew'); ?></a>
                <?php endif; ?>
            </p>

            <h2 class="eventcrew-help-part" id="eventcrew-help-guide"><?php echo esc_html('📖 ' . __('How this works?', 'eventcrew')); ?></h2>
            <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HelpPage escapes every piece of text.
            echo \EventCrew\Support\HelpPage::cards(\EventCrew\Support\WelcomeSender::helpText());
            ?>

            <?php if ('' !== $eventcrew_help_bot) : ?>
                <h2 class="eventcrew-help-part" id="eventcrew-help-commands"><?php echo esc_html('⌨️ ' . __('Commands', 'eventcrew')); ?></h2>
                <section class="eventcrew-event eventcrew-help-card">
                    <div class="eventcrew-card-body">
                        <p><?php esc_html_e('In a private chat with the bot you can also use these commands, or just write what you need in your own words.', 'eventcrew'); ?></p>
                        <ul class="eventcrew-help-list">
                            <?php foreach (\EventCrew\Telegram\HelpService::everyoneLines() as $eventcrew_help_line) : ?>
                                <li><?php echo esc_html($eventcrew_help_line); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <p><a class="eventcrew-textlink" href="<?php echo esc_url('https://t.me/' . rawurlencode($eventcrew_help_bot)); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open in Telegram', 'eventcrew'); ?></a></p>
                    </div>
                </section>
            <?php endif; ?>

            <h2 class="eventcrew-help-part" id="eventcrew-help-faq"><?php echo esc_html('💡 ' . __('FAQ', 'eventcrew')); ?></h2>
            <section class="eventcrew-event eventcrew-help-card eventcrew-faq">
                <div class="eventcrew-card-body">
                    <?php foreach (\EventCrew\Support\Faq::items() as $eventcrew_faq) : ?>
                        <details class="eventcrew-faq-item">
                            <summary><?php echo esc_html($eventcrew_faq['q']); ?></summary>
                            <p><?php echo esc_html($eventcrew_faq['a']); ?></p>
                        </details>
                    <?php endforeach; ?>
                </div>
            </section>

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

            // The texts sent to people (the announcement, the post-event
            // email, the AI texts per event) are one screen, Messages, as on
            // the wp-admin Messages page.
            $eventcrew_has_messages = isset($eventcrew_admin['newsletter'])
                || isset($eventcrew_admin['followup'])
                || isset($eventcrew_admin['ai_texts']);

            if ($eventcrew_has_messages) {
                $eventcrew_subtabs['messages'] = __('Messages', 'eventcrew');
            }

            /*
             * The (i) after a card's title: the card's longer explanation opens
             * in a bubble, like the (i) after a task's name on the board, so
             * the card itself keeps to its rows and one line of text. Without
             * popover support the text shows in the header as small print.
             */
            $eventcrew_admin_about = static function (string $id, string $title, string ...$texts): void {
                $parts = '';
                foreach ($texts as $text) {
                    $parts .= '<span class="eventcrew-about-p">' . esc_html($text) . '</span>';
                }
                printf(
                    '<button type="button" class="eventcrew-about-btn" popovertarget="%1$s" aria-label="%2$s" title="%2$s">i</button>'
                    . '<span class="eventcrew-about" id="%1$s" popover><strong>%3$s</strong>%4$s</span>',
                    esc_attr($id),
                    esc_attr__('Help', 'eventcrew'),
                    esc_html($title),
                    $parts // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piece by piece above.
                );
            };
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
            <?php
            // Newcomers who signed themselves up and wait for the team's OK
            // (NewcomerApproval). Shown only while someone is waiting.
            $eventcrew_newcomers = $eventcrew_admin['newcomers'] ?? [];
            ?>
            <?php if ([] !== $eventcrew_newcomers) : ?>
            <section class="eventcrew-event eventcrew-admin-card" id="eventcrew-admin-newcomers">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('Waiting for an OK', 'eventcrew'); ?></h3>
                </header>
                <p class="eventcrew-muted eventcrew-card-body eventcrew-admin-lead"><?php esc_html_e('They signed in for the first time. They can take tasks after an OK.', 'eventcrew'); ?></p>
                <ul class="eventcrew-plain-list eventcrew-me-list eventcrew-admin-people-list">
                    <?php foreach ($eventcrew_newcomers as $eventcrew_newcomer) : ?>
                        <li class="eventcrew-me-row">
                            <span class="eventcrew-me-row-head">
                                <span class="eventcrew-me-day"><?php echo esc_html($eventcrew_newcomer['name']); ?></span>
                                <?php if ('' !== $eventcrew_newcomer['since']) : ?>
                                    <span class="eventcrew-me-time"><?php echo esc_html($eventcrew_newcomer['since']); ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="eventcrew-me-what"><?php echo esc_html($eventcrew_newcomer['email']); ?></span>
                            <?php // What they said when joining, as in the "wants to join" email and DM (JoinAnswers). ?>
                            <?php if ('' !== (string) ($eventcrew_newcomer['answers'] ?? '')) : ?>
                                <span class="eventcrew-me-what eventcrew-join-answers"><?php echo esc_html((string) $eventcrew_newcomer['answers']); ?></span>
                            <?php endif; ?>
                            <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>" class="eventcrew-me-actions">
                                <input type="hidden" name="action" value="<?php echo esc_attr((string) ($eventcrew_admin['newcomer_action'] ?? '')); ?>">
                                <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_people_list); ?>">
                                <input type="hidden" name="person_id" value="<?php echo esc_attr((string) $eventcrew_newcomer['id']); ?>">
                                <button type="submit" name="decision" value="ok" class="eventcrew-textlink"><?php esc_html_e('OK', 'eventcrew'); ?></button>
                                <button type="submit" name="decision" value="no" class="eventcrew-textlink eventcrew-textlink-danger"><?php esc_html_e('No', 'eventcrew'); ?></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>
            <?php $eventcrew_edit_person = null === $eventcrew_editing ? null : $eventcrew_editing['person']; ?>
            <?php
            /*
             * The person form: open in its own card while a person is open,
             * otherwise folded under "+ Add a person" at the foot of the list,
             * since adding someone by hand is rare (people sign themselves up).
             */
            ob_start();
            ?>
                <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>" class="eventcrew-admin-form">
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
                        <textarea id="eventcrew-admin-person-notes" name="notes" rows="2"><?php echo esc_textarea(null === $eventcrew_edit_person ? '' : $eventcrew_edit_person->notes); ?></textarea>
                    </p>
                    <div class="eventcrew-admin-actions">
                        <button type="submit" class="wp-element-button"><?php null === $eventcrew_edit_person ? esc_html_e('Add person', 'eventcrew') : esc_html_e('Update person', 'eventcrew'); ?></button>
                        <?php if (null !== $eventcrew_edit_person) : ?>
                            <a class="eventcrew-textlink" href="<?php echo esc_url($eventcrew_people_list); ?>"><?php esc_html_e('Cancel', 'eventcrew'); ?></a>
                        <?php endif; ?>
                    </div>
                </form>
            <?php
            $eventcrew_person_form = (string) ob_get_clean();
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
                        <input id="eventcrew-admin-people-q" type="search" name="people_q" maxlength="100" aria-label="<?php esc_attr_e('Search people', 'eventcrew'); ?>" placeholder="<?php esc_attr_e('Search people', 'eventcrew'); ?>" value="<?php echo esc_attr($eventcrew_people['search']); ?>">
                        <button type="submit" class="eventcrew-textlink"><?php esc_html_e('Search', 'eventcrew'); ?></button>
                    </form>
                </div>
                <?php if ([] === $eventcrew_people['list']) : ?>
                    <p class="eventcrew-muted eventcrew-card-body"><?php '' === $eventcrew_people['search'] ? esc_html_e('No people yet.', 'eventcrew') : esc_html_e('No people found.', 'eventcrew'); ?></p>
                <?php else : ?>
                    <ul class="eventcrew-plain-list eventcrew-me-list eventcrew-admin-people-list">
                        <?php foreach ($eventcrew_people['list'] as $eventcrew_row) : ?>
                            <li class="eventcrew-me-row">
                                <?php if ($eventcrew_row['anonymized']) : ?>
                                    <span class="eventcrew-muted"><?php echo esc_html($eventcrew_row['name']); ?> (<?php esc_html_e('Anonymised', 'eventcrew'); ?>)</span>
                                <?php else : ?>
                                    <span class="eventcrew-me-row-head">
                                        <a class="eventcrew-me-day" href="<?php echo esc_url(add_query_arg(['person_id' => $eventcrew_row['id']] + $eventcrew_people_args, $eventcrew_here) . '#eventcrew-admin-people'); ?>"><?php echo esc_html($eventcrew_row['name']); ?></a>
                                        <?php if ($eventcrew_row['banned']) : ?>
                                            <span class="eventcrew-me-status eventcrew-admin-at-risk"><?php esc_html_e('Blocked', 'eventcrew'); ?></span>
                                        <?php elseif ($eventcrew_row['organizer']) : ?>
                                            <span class="eventcrew-me-status"><?php esc_html_e('Organizer', 'eventcrew'); ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="eventcrew-me-what">
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
                    <p class="eventcrew-pager eventcrew-card-body">
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
                <?php if (null === $eventcrew_edit_person) : ?>
                    <details class="eventcrew-admin-fold eventcrew-card-body">
                        <summary class="eventcrew-textlink">+ <?php esc_html_e('Add a person', 'eventcrew'); ?></summary>
                        <?php echo $eventcrew_person_form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built above. ?>
                    </details>
                <?php endif; ?>
            </section>

            <?php if (null !== $eventcrew_edit_person) : ?>
                <section class="eventcrew-event eventcrew-admin-card">
                    <header class="eventcrew-event-head">
                        <h3 class="eventcrew-event-title"><?php esc_html_e('Edit person', 'eventcrew'); ?></h3>
                        <?php $eventcrew_admin_about('eventcrew-admin-about-person', __('Edit person', 'eventcrew'), __('Verifying an email and choosing which alerts to receive are both things only the person themselves can do, so neither can be set from this screen.', 'eventcrew')); ?>
                        <span class="eventcrew-muted"><?php echo esc_html($eventcrew_edit_person->name()); ?></span>
                    </header>
                    <ul class="eventcrew-plain-list eventcrew-admin-facts" aria-label="<?php esc_attr_e('Status', 'eventcrew'); ?>">
                        <li><span><?php esc_html_e('Email verified', 'eventcrew'); ?></span>
                            <span><?php echo $eventcrew_edit_person->isEmailVerified() ? esc_html((string) $eventcrew_edit_person->emailVerifiedAt) : esc_html__('Not yet', 'eventcrew'); ?></span></li>
                        <li><span><?php esc_html_e('Telegram linked', 'eventcrew'); ?></span>
                            <span><?php echo $eventcrew_edit_person->hasTelegram() ? esc_html__('Yes', 'eventcrew') : esc_html__('No', 'eventcrew'); ?></span></li>
                        <?php if ($eventcrew_edit_person->isOrganizer) : ?>
                            <li><span><?php esc_html_e('Organizer', 'eventcrew'); ?></span> <span><?php esc_html_e('Yes', 'eventcrew'); ?></span></li>
                        <?php endif; ?>
                        <li><span><?php esc_html_e('Standing', 'eventcrew'); ?></span>
                            <span<?php echo $eventcrew_editing['at_risk'] ? ' class="eventcrew-admin-at-risk"' : ''; ?>><?php echo esc_html($eventcrew_editing['standing']); ?></span></li>
                        <li><span><?php esc_html_e('Free-entry credits', 'eventcrew'); ?></span> <span><?php echo esc_html((string) $eventcrew_editing['credits'] . \EventCrew\Support\ExtraTaskCredits::pendingSuffix((int) ($eventcrew_editing['pending'] ?? 0))); ?></span></li>
                    </ul>
                    <?php if ($eventcrew_edit_person->isBanned()) : ?>
                        <form class="eventcrew-card-body eventcrew-admin-blocked" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['unban_action']); ?>">
                            <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_people_back); ?>">
                            <input type="hidden" name="person_id" value="<?php echo esc_attr((string) $eventcrew_edit_person->id); ?>">
                            <span class="eventcrew-admin-at-risk">
                                <?php
                                printf(
                                    /* translators: %s: date and time the block was set */
                                    esc_html__('Blocked since %s. They cannot sign up for tasks or sign in on the web.', 'eventcrew'),
                                    esc_html((string) $eventcrew_edit_person->bannedAt)
                                );
                                ?>
                            </span>
                            <?php if ('' !== (string) $eventcrew_edit_person->banReason) : ?>
                                <span class="eventcrew-muted"><?php echo esc_html((string) $eventcrew_edit_person->banReason); ?></span>
                            <?php endif; ?>
                            <button type="submit" class="eventcrew-textlink"><?php esc_html_e('Unban', 'eventcrew'); ?></button>
                        </form>
                    <?php endif; ?>
                    <div class="eventcrew-card-body">
                        <?php echo $eventcrew_person_form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built above. ?>
                    </div>
                </section>

                <section class="eventcrew-event eventcrew-admin-card">
                    <header class="eventcrew-event-head">
                        <h3 class="eventcrew-event-title"><?php esc_html_e('Credit log', 'eventcrew'); ?></h3>
                    </header>
                    <?php if ([] === $eventcrew_editing['given'] && [] === $eventcrew_editing['spent']) : ?>
                        <p class="eventcrew-muted eventcrew-card-body"><?php esc_html_e('No credits given or spent yet.', 'eventcrew'); ?></p>
                    <?php endif; ?>
                    <?php if ([] !== $eventcrew_editing['given'] || [] !== $eventcrew_editing['spent']) : ?>
                        <ul class="eventcrew-plain-list eventcrew-me-list">
                            <?php foreach ($eventcrew_editing['given'] as $eventcrew_given) : ?>
                                <li class="eventcrew-me-row">
                                    <span class="eventcrew-me-row-head">
                                        <span class="eventcrew-me-day">+<?php echo esc_html((string) $eventcrew_given['credits']); ?> · <?php esc_html_e('Credits given', 'eventcrew'); ?></span>
                                        <span class="eventcrew-me-time"><?php echo esc_html($eventcrew_given['granted_at']); ?></span>
                                    </span>
                                    <?php if ('' !== $eventcrew_given['note']) : ?>
                                        <span class="eventcrew-me-event"><?php echo esc_html($eventcrew_given['note']); ?></span>
                                    <?php endif; ?>
                                    <span class="eventcrew-me-what"><?php esc_html_e('Granted by', 'eventcrew'); ?>: <?php echo esc_html($eventcrew_given['granted_by']); ?></span>
                                </li>
                            <?php endforeach; ?>
                            <?php foreach ($eventcrew_editing['spent'] as $eventcrew_spent) : ?>
                                <li class="eventcrew-me-row">
                                    <span class="eventcrew-me-row-head">
                                        <span class="eventcrew-me-day">&minus;1 · <?php esc_html_e('Credits spent', 'eventcrew'); ?></span>
                                        <span class="eventcrew-me-time"><?php echo esc_html($eventcrew_spent['redeemed_at']); ?></span>
                                    </span>
                                    <span class="eventcrew-me-event"><?php echo esc_html(trim($eventcrew_spent['day'] . ' ' . $eventcrew_spent['event'])); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <div class="eventcrew-card-body eventcrew-admin-folds">
                        <details class="eventcrew-admin-fold">
                            <summary class="eventcrew-textlink"><?php esc_html_e('Give a free-entry credit', 'eventcrew'); ?></summary>
                            <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>" class="eventcrew-admin-form">
                                <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['gift_action']); ?>">
                                <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_people_back); ?>">
                                <input type="hidden" name="person_id" value="<?php echo esc_attr((string) $eventcrew_edit_person->id); ?>">
                                <span class="eventcrew-muted eventcrew-admin-hint"><?php esc_html_e('A one-off bonus, on top of the credits earned for completed tasks.', 'eventcrew'); ?></span>
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
                            </form>
                        </details>
                        <details class="eventcrew-admin-fold">
                            <summary class="eventcrew-textlink"><?php esc_html_e('One-time at-risk pass', 'eventcrew'); ?></summary>
                            <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>" class="eventcrew-admin-form">
                                <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['pass_action']); ?>">
                                <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_people_back); ?>">
                                <input type="hidden" name="person_id" value="<?php echo esc_attr((string) $eventcrew_edit_person->id); ?>">
                                <span class="eventcrew-muted eventcrew-admin-hint"><?php esc_html_e('Lets them sign up once even if their standing is at risk. Spent automatically on their next successful signup.', 'eventcrew'); ?></span>
                                <button type="submit" class="wp-element-button is-style-outline"><?php $eventcrew_edit_person->hasAtRiskPass ? esc_html_e('Pass already granted — re-grant', 'eventcrew') : esc_html_e('Grant a one-time pass', 'eventcrew'); ?></button>
                            </form>
                        </details>
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
                    <ul class="eventcrew-plain-list eventcrew-me-list eventcrew-admin-roster">
                        <?php foreach ($eventcrew_roster_task['people'] as $eventcrew_roster_entry) : ?>
                            <li class="eventcrew-me-row">
                                <span class="eventcrew-me-row-head">
                                <span class="eventcrew-me-day eventcrew-admin-who"><?php echo esc_html($eventcrew_roster_entry['name']); ?></span>
                                <?php /* Saved as soon as it changes: the picker posts this task's form below, by its form attribute. */ ?>
                                <select name="status[<?php echo esc_attr((string) $eventcrew_roster_entry['assignment_id']); ?>]" form="<?php echo esc_attr($eventcrew_attend_form); ?>" aria-label="<?php esc_attr_e('Status', 'eventcrew'); ?>"
                                    onchange="if (this.form.requestSubmit) { this.form.requestSubmit(); } else { this.form.submit(); }">
                                    <?php foreach ($eventcrew_admin['statuses'] as $eventcrew_status_value => $eventcrew_status_label) : ?>
                                        <option value="<?php echo esc_attr($eventcrew_status_value); ?>"<?php echo $eventcrew_status_value === $eventcrew_roster_entry['status'] ? ' selected' : ''; ?>><?php echo esc_html($eventcrew_status_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                </span>
                                <input type="hidden" name="shown[<?php echo esc_attr((string) $eventcrew_roster_entry['assignment_id']); ?>]" value="<?php echo esc_attr($eventcrew_roster_entry['status']); ?>" form="<?php echo esc_attr($eventcrew_attend_form); ?>">
                                <?php // When they signed up, their standing and who marked them, on one small line. ?>
                                <span class="eventcrew-me-what">
                                    <?php if ('' !== $eventcrew_roster_entry['signed_up']) : ?>
                                        <span><?php
                                            /* translators: %s: when the person signed up, e.g. "2 Nov 14:05" */
                                            echo esc_html(sprintf(__('signed up %s', 'eventcrew'), $eventcrew_roster_entry['signed_up']));
                                        ?></span>
                                    <?php endif; ?>
                                    <?php if ('' !== $eventcrew_roster_entry['standing']) : ?>
                                        <span<?php echo $eventcrew_roster_entry['at_risk'] ? ' class="eventcrew-admin-at-risk"' : ''; ?>><?php echo esc_html($eventcrew_roster_entry['standing']); ?></span>
                                    <?php endif; ?>
                                    <?php if ('' !== $eventcrew_roster_entry['marked_by']) : ?>
                                        <span><?php echo esc_html($eventcrew_roster_entry['marked_by']); ?></span>
                                    <?php endif; ?>
                                </span>
                                <noscript><button type="submit" form="<?php echo esc_attr($eventcrew_attend_form); ?>" class="eventcrew-textlink"><?php esc_html_e('Save', 'eventcrew'); ?></button></noscript>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ([] !== $eventcrew_roster_task['people']) : ?>
                        <form id="<?php echo esc_attr($eventcrew_attend_form); ?>" class="eventcrew-me-actions eventcrew-admin-attend" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
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
                    <form class="eventcrew-admin-form" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['add_action']); ?>">
                        <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                        <p class="eventcrew-muted eventcrew-admin-hint"><?php esc_html_e('For a member, or for someone who is not a crew member yet. On a task of a role marked "Only people with this role", they get the role too.', 'eventcrew'); ?></p>
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
                        <?php $eventcrew_admin_about('eventcrew-admin-about-door', __('Door list', 'eventcrew'), __('Everyone who gets in free that night — working the event, or spending a credit.', 'eventcrew')); ?>
                        <span class="eventcrew-muted"><?php echo esc_html($eventcrew_admin['roster_label']); ?></span>
                    </header>
                    <?php if ($eventcrew_night['closed_by_hand'] || '' !== $eventcrew_night['event_reason']) : ?>
                        <div class="eventcrew-card-body">
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
                        </div>
                    <?php endif; ?>
                    <?php if ([] === $eventcrew_night['entrants']) : ?>
                        <p class="eventcrew-card-body eventcrew-muted"><?php esc_html_e('Nobody on the door list yet.', 'eventcrew'); ?></p>
                    <?php else : ?>
                        <ul class="eventcrew-plain-list eventcrew-me-list eventcrew-admin-roster">
                            <?php foreach ($eventcrew_night['entrants'] as $eventcrew_entrant) : ?>
                                <li class="eventcrew-me-row">
                                    <span class="eventcrew-me-day eventcrew-admin-who"><?php echo esc_html($eventcrew_entrant['name']); ?></span>
                                    <span class="eventcrew-me-what">
                                        <span><?php echo esc_html($eventcrew_entrant['detail']); ?></span>
                                        <span<?php echo $eventcrew_entrant['at_risk'] ? ' class="eventcrew-admin-at-risk"' : ''; ?>><?php echo esc_html($eventcrew_entrant['standing']); ?></span>
                                    </span>
                                    <?php if ('' !== (string) ($eventcrew_entrant['added_by'] ?? '')) : ?>
                                        <span class="eventcrew-me-what"><?php echo esc_html((string) $eventcrew_entrant['added_by']); ?></span>
                                    <?php endif; ?>
                                    <?php if (null !== $eventcrew_entrant['redemption_id']) : ?>
                                        <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>" class="eventcrew-me-actions">
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
                        <form class="eventcrew-card-body eventcrew-admin-form" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
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
                    <form class="eventcrew-card-body" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
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
                </section>

                <section class="eventcrew-event eventcrew-admin-card">
                    <header class="eventcrew-event-head">
                        <h3 class="eventcrew-event-title"><?php esc_html_e('Guest list', 'eventcrew'); ?></h3>
                        <?php $eventcrew_admin_about('eventcrew-admin-about-guests', __('Guest list', 'eventcrew'), __('People let in free who are not crew members. The bot’s /door command keeps the same list.', 'eventcrew')); ?>
                        <span class="eventcrew-muted"><?php echo esc_html($eventcrew_admin['roster_label']); ?></span>
                    </header>
                    <?php if ([] !== $eventcrew_night['guests']) : ?>
                    <ul class="eventcrew-plain-list eventcrew-me-list eventcrew-admin-guests">
                        <?php foreach ($eventcrew_night['guests'] as $eventcrew_guest) : ?>
                            <?php $eventcrew_guest_field = 'eventcrew-admin-guest-' . $eventcrew_guest['id']; ?>
                            <li class="eventcrew-me-row">
                                <span class="eventcrew-me-row-head">
                                    <span class="eventcrew-me-day"><?php echo esc_html($eventcrew_guest['name']); ?></span>
                                    <?php if ((int) $eventcrew_guest['plus_ones'] > 0) : ?>
                                        <span class="eventcrew-me-time">+<?php echo esc_html((string) $eventcrew_guest['plus_ones']); ?></span>
                                    <?php endif; ?>
                                </span>
                                <?php if ('' !== (string) $eventcrew_guest['note'] || '' !== (string) $eventcrew_guest['email']) : ?>
                                    <span class="eventcrew-me-what">
                                        <?php if ('' !== (string) $eventcrew_guest['note']) : ?>
                                            <span><?php echo esc_html((string) $eventcrew_guest['note']); ?></span>
                                        <?php endif; ?>
                                        <?php if ('' !== (string) $eventcrew_guest['email']) : ?>
                                            <span><?php echo esc_html((string) $eventcrew_guest['email']); ?></span>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ('' !== (string) ($eventcrew_guest['added_by'] ?? '')) : ?>
                                    <span class="eventcrew-me-what"><?php echo esc_html((string) $eventcrew_guest['added_by']); ?></span>
                                <?php endif; ?>
                                <div class="eventcrew-me-actions">
                                <details class="eventcrew-give eventcrew-admin-fold">
                                <summary class="eventcrew-textlink"><?php esc_html_e('Edit', 'eventcrew'); ?></summary>
                                <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>" class="eventcrew-admin-form">
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
                                </details>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                    <?php if ('' !== $eventcrew_night['ticket_mail_guests']) : ?>
                        <form class="eventcrew-card-body" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>"
                            onsubmit="return confirm('<?php echo esc_js(__('Email their tickets now to the guests who have not got one?', 'eventcrew')); ?>');">
                            <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['guest_action']); ?>">
                            <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_roster_here); ?>">
                            <input type="hidden" name="roster_date" value="<?php echo esc_attr($eventcrew_roster_date); ?>">
                            <span class="eventcrew-muted eventcrew-admin-hint"><?php echo esc_html($eventcrew_night['ticket_mail_guests']); ?></span>
                            <button type="submit" name="op" value="mail_rest" formnovalidate class="eventcrew-textlink"><?php esc_html_e('Send the rest now', 'eventcrew'); ?></button>
                        </form>
                    <?php endif; ?>
                    <details class="eventcrew-card-body eventcrew-admin-fold"<?php echo [] === $eventcrew_night['guests'] ? ' open' : ''; ?>>
                    <summary class="eventcrew-textlink">+ <?php esc_html_e('Add guest', 'eventcrew'); ?></summary>
                    <form class="eventcrew-admin-form" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
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
                    </details>
                </section>

                <section class="eventcrew-event eventcrew-admin-card">
                    <header class="eventcrew-event-head">
                        <h3 class="eventcrew-event-title"><?php esc_html_e('Holvi ticket buyers', 'eventcrew'); ?></h3>
                        <?php
                        if ('' !== $eventcrew_night['mailbox']) {
                            $eventcrew_admin_about(
                                'eventcrew-admin-about-buyers',
                                __('Holvi ticket buyers', 'eventcrew'),
                                sprintf(
                                    /* translators: %s: the import email address */
                                    __('You can also email the ticket file (.xlsx or .csv) to %s. It always goes to the next event, and only mail from an organizer’s address is accepted. Names typed in the mail itself go to the guest list; a guest with an email address on the line gets a ticket by email.', 'eventcrew'),
                                    $eventcrew_night['mailbox']
                                )
                            );
                        }
                        ?>
                        <span class="eventcrew-muted"><?php echo esc_html($eventcrew_admin['roster_label']); ?></span>
                    </header>
                    <div class="eventcrew-card-body">
                        <p class="eventcrew-admin-lead">
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
                    </div>
                    <?php if ('' !== $eventcrew_night['ticket_mail_pending'] || '' !== $eventcrew_night['ticket_mail_buyers'] || '' !== $eventcrew_night['followup_mail']) : ?>
                    <div class="eventcrew-card-body">
                        <?php if ('' !== $eventcrew_night['ticket_mail_pending']) : ?>
                            <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>" class="eventcrew-admin-links">
                                <input type="hidden" name="action"
                                    value="<?php echo esc_attr($eventcrew_admin['buyers_action']); ?>">
                                <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                                <input type="hidden" name="redirect_to"
                                    value="<?php echo esc_attr($eventcrew_roster_here); ?>">
                                <input type="hidden" name="roster_date"
                                    value="<?php echo esc_attr($eventcrew_roster_date); ?>">
                                <strong class="eventcrew-admin-line"><?php echo esc_html($eventcrew_night['ticket_mail_pending']); ?></strong>
                                <button type="submit" name="op" value="mail_cancel"
                                    class="eventcrew-textlink eventcrew-textlink-danger">
                                    <?php esc_html_e('Cancel sending', 'eventcrew'); ?>
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php if ('' !== $eventcrew_night['ticket_mail_buyers']) : ?>
                            <div class="eventcrew-admin-links">
                            <span class="eventcrew-muted eventcrew-admin-line"><?php echo esc_html($eventcrew_night['ticket_mail_buyers']); ?></span>
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
                            </div>
                        <?php endif; ?>
                        <?php if ('' !== $eventcrew_night['followup_mail']) : ?>
                            <div class="eventcrew-admin-links">
                            <span class="eventcrew-muted eventcrew-admin-line"><?php echo esc_html($eventcrew_night['followup_mail']); ?></span>
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
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="eventcrew-card-body">
                        <details class="eventcrew-admin-fold">
                            <summary class="eventcrew-textlink"><?php esc_html_e('Paste names, one per line', 'eventcrew'); ?></summary>
                            <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>" class="eventcrew-admin-form">
                                <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['buyers_action']); ?>">
                                <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_door_here); ?>">
                                <input type="hidden" name="roster_date" value="<?php echo esc_attr($eventcrew_roster_date); ?>">
                                <textarea id="eventcrew-admin-buyer-names" name="buyer_names" rows="6" aria-label="<?php esc_attr_e('Paste names, one per line', 'eventcrew'); ?>"></textarea>
                                <span class="eventcrew-muted eventcrew-admin-hint"><?php esc_html_e('The names go to the date shown above. A name already on the list is skipped.', 'eventcrew'); ?></span>
                                <button type="submit" name="op" value="import" class="wp-element-button"><?php esc_html_e('Import', 'eventcrew'); ?></button>
                            </form>
                        </details>
                        <div class="eventcrew-admin-links">
                            <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                                <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['mail_action']); ?>">
                                <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_door_here); ?>">
                                <button type="submit" class="eventcrew-textlink"><?php esc_html_e('Check the mailbox now', 'eventcrew'); ?></button>
                            </form>
                            <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>"
                                onsubmit="return confirm('<?php echo esc_js(__('Delete all imported ticket buyers for this date? Guests and the crew are not affected.', 'eventcrew')); ?>');">
                                <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['buyers_action']); ?>">
                                <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_door_here); ?>">
                                <input type="hidden" name="roster_date" value="<?php echo esc_attr($eventcrew_roster_date); ?>">
                                <button type="submit" name="op" value="reset" class="eventcrew-textlink eventcrew-textlink-danger"><?php esc_html_e('Reset import', 'eventcrew'); ?></button>
                            </form>
                        </div>
                        <?php if ('' !== $eventcrew_night['mail_last']) : ?>
                            <span class="eventcrew-muted eventcrew-admin-hint"><?php echo esc_html($eventcrew_night['mail_last']); ?></span>
                        <?php endif; ?>
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

            // The task form's field notes live in the (i) of its card; the
            // fields themselves stay one line each.
            $eventcrew_task_help = [
                __('Picking an event fills in the date and times below. Change them afterwards if this task runs to its own schedule.', 'eventcrew'),
                __('The day this task is filed under. A clean-up at 01:00 after a Saturday event still belongs to Saturday.', 'eventcrew'),
                __('Defaults come from the role in Settings. Set to 0 to take a task off the board without deleting it.', 'eventcrew'),
                __('How many guests each person on this task may bring in for free. The sales desk adds this up over all of a person\'s tasks that night. Leave empty on a new task to use the role\'s default from Settings.', 'eventcrew'),
                __('The start time is required. Leave the end blank if it is not decided. An end earlier than the start is read as the next morning.', 'eventcrew'),
            ];

            // The task form: its own card while a task is open for editing (the
            // Edit link lands on it), else folded under "+ Add a task" in the
            // Tasks card.
            ob_start();
            ?>
                <form class="eventcrew-admin-form" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
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
                        <?php else : ?>
                            <input type="hidden" name="event_choice" value="<?php echo esc_attr($eventcrew_task_other); ?>">
                            <span class="eventcrew-admin-hint eventcrew-muted"><?php esc_html_e('No linkable events found. Type a name below instead.', 'eventcrew'); ?></span>
                        <?php endif; ?>
                    </p>
                    <p data-eventcrew-task-other<?php echo $eventcrew_task_other === $eventcrew_task_event ? '' : ' hidden'; ?>>
                        <label for="eventcrew-admin-task-event-label"><?php esc_html_e('Event name', 'eventcrew'); ?></label>
                        <input type="text" name="event_label" id="eventcrew-admin-task-event-label" value="<?php echo esc_attr($eventcrew_task_is_edit ? $eventcrew_task_editing->eventLabel : ''); ?>">
                    </p>
                    <p class="eventcrew-admin-pair">
                        <span>
                            <label for="eventcrew-admin-task-date"><?php esc_html_e('Task date', 'eventcrew'); ?></label>
                            <input type="date" name="task_date" id="eventcrew-admin-task-date" required value="<?php echo esc_attr($eventcrew_task_is_edit ? $eventcrew_task_editing->taskDate : $eventcrew_tasks_date); ?>">
                        </span>
                        <span>
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
                        </span>
                    </p>
                    <p class="eventcrew-admin-pair">
                        <span>
                            <label for="eventcrew-admin-task-capacity"><?php esc_html_e('People needed', 'eventcrew'); ?></label>
                            <input type="number" name="capacity" id="eventcrew-admin-task-capacity" min="0" step="1" value="<?php echo esc_attr((string) ($eventcrew_task_is_edit ? $eventcrew_task_editing->capacity : 2)); ?>">
                        </span>
                        <span>
                            <label for="eventcrew-admin-task-plus-ones"><?php esc_html_e('+1 allowed', 'eventcrew'); ?></label>
                            <input type="number" name="plus_ones" id="eventcrew-admin-task-plus-ones" min="0" max="20" step="1" value="<?php echo esc_attr($eventcrew_task_is_edit ? (string) $eventcrew_task_editing->plusOnes : ''); ?>">
                        </span>
                    </p>
                    <p class="eventcrew-admin-pair">
                        <span>
                            <label for="eventcrew-admin-task-starts"><?php esc_html_e('Starts', 'eventcrew'); ?></label>
                            <input type="datetime-local" name="starts_at" id="eventcrew-admin-task-starts" required value="<?php echo esc_attr($eventcrew_task_local($eventcrew_task_is_edit ? $eventcrew_task_editing->startsAt : ($eventcrew_task_new_event['starts_at'] ?? null))); ?>">
                        </span>
                        <span>
                            <label for="eventcrew-admin-task-ends"><?php esc_html_e('Ends', 'eventcrew'); ?></label>
                            <input type="datetime-local" name="ends_at" id="eventcrew-admin-task-ends" value="<?php echo esc_attr($eventcrew_task_local($eventcrew_task_is_edit ? $eventcrew_task_editing->endsAt : null)); ?>">
                        </span>
                    </p>
                    <p>
                        <label for="eventcrew-admin-task-notes"><?php esc_html_e('Notes', 'eventcrew'); ?></label>
                        <textarea name="notes" id="eventcrew-admin-task-notes" rows="2"><?php echo esc_textarea($eventcrew_task_is_edit ? $eventcrew_task_editing->notes : ''); ?></textarea>
                    </p>
                    <p class="eventcrew-admin-actions">
                        <button type="submit" class="wp-element-button"><?php echo $eventcrew_task_is_edit ? esc_html__('Update task', 'eventcrew') : esc_html__('Add task', 'eventcrew'); ?></button>
                        <?php if ($eventcrew_task_is_edit) : ?>
                            <a class="eventcrew-textlink" href="<?php echo esc_url($eventcrew_tasks_here); ?>"><?php esc_html_e('Cancel', 'eventcrew'); ?></a>
                        <?php endif; ?>
                    </p>
                </form>
            <?php
            $eventcrew_task_form = (string) ob_get_clean();
            ?>

            <?php if ($eventcrew_task_is_edit) : ?>
            <section class="eventcrew-event eventcrew-admin-card" id="eventcrew-admin-task-form">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('Edit task', 'eventcrew'); ?></h3>
                    <?php $eventcrew_admin_about('eventcrew-admin-about-task', __('Edit task', 'eventcrew'), ...$eventcrew_task_help); ?>
                </header>
                <div class="eventcrew-card-body">
                    <?php echo $eventcrew_task_form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above, every part escaped. ?>
                </div>
                <?php if ([] !== $eventcrew_tasks['signed_up']) : ?>
                    <p class="eventcrew-card-body eventcrew-admin-lead eventcrew-muted"><?php esc_html_e('Signed up', 'eventcrew'); ?></p>
                    <ul class="eventcrew-plain-list eventcrew-me-list">
                        <?php foreach ($eventcrew_tasks['signed_up'] as $eventcrew_task_person) : ?>
                            <li class="eventcrew-me-row">
                                <span class="eventcrew-me-row-head">
                                    <span class="eventcrew-me-day"><?php echo esc_html($eventcrew_task_person['name']); ?></span>
                                    <span class="eventcrew-me-status"><?php echo esc_html($eventcrew_task_person['status']); ?></span>
                                </span>
                                <?php if ('' !== $eventcrew_task_person['marked_by']) : ?>
                                    <span class="eventcrew-me-what"><?php echo esc_html($eventcrew_task_person['marked_by']); ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <section class="eventcrew-event eventcrew-admin-card">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('Tasks', 'eventcrew'); ?></h3>
                    <?php
                    if (! $eventcrew_task_is_edit) {
                        $eventcrew_admin_about(
                            'eventcrew-admin-about-tasks',
                            __('Tasks', 'eventcrew'),
                            ...array_merge(
                                $eventcrew_task_help,
                                [] !== $eventcrew_task_events
                                    ? [__('Creates one task per active role, with times worked out from each role\'s offsets in Settings. Roles that already have a task for the event are left alone, so this is safe to run again after adding a role.', 'eventcrew')]
                                    : []
                            )
                        );
                    }
                    ?>
                    <span class="eventcrew-muted"><?php echo esc_html($eventcrew_tasks['label']); ?></span>
                </header>
                <div class="eventcrew-card-body eventcrew-admin-folds">
                    <?php if ([] !== $eventcrew_tasks['upcoming'] || [] !== $eventcrew_tasks['past']) : ?>
                        <form class="eventcrew-admin-search eventcrew-admin-date" method="get" action="<?php echo esc_url($eventcrew_here . '#eventcrew-admin-tasks'); ?>">
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
                            <noscript><button type="submit" class="eventcrew-textlink"><?php esc_html_e('Show', 'eventcrew'); ?></button></noscript>
                        </form>
                    <?php endif; ?>
                    <details class="eventcrew-admin-fold"<?php echo [] === $eventcrew_tasks['upcoming'] && [] === $eventcrew_tasks['past'] ? ' open' : ''; ?>>
                        <summary class="eventcrew-textlink"><?php esc_html_e('Another date', 'eventcrew'); ?></summary>
                        <form class="eventcrew-admin-search eventcrew-admin-date" method="get" action="<?php echo esc_url($eventcrew_here . '#eventcrew-admin-tasks'); ?>">
                            <?php $eventcrew_tasks_hidden($eventcrew_tasks_query); ?>
                            <input type="date" id="eventcrew-admin-tasks-new-date" name="tasks_date" required value="<?php echo esc_attr($eventcrew_tasks_date); ?>" aria-label="<?php esc_attr_e('Another date', 'eventcrew'); ?>">
                            <button type="submit" class="eventcrew-textlink"><?php esc_html_e('Show', 'eventcrew'); ?></button>
                        </form>
                    </details>
                </div>
                <?php if ([] === $eventcrew_tasks['list']) : ?>
                    <p class="eventcrew-card-body eventcrew-muted"><?php esc_html_e('No tasks on that date.', 'eventcrew'); ?></p>
                <?php else : ?>
                <ul class="eventcrew-plain-list eventcrew-me-list eventcrew-admin-tasklist">
                    <?php foreach ($eventcrew_tasks['list'] as $eventcrew_task_row) : ?>
                        <?php
                        $eventcrew_task_item = $eventcrew_task_row['task'];
                        $eventcrew_task_event_name = \EventCrew\Support\EventName::ofTask($eventcrew_task_item, \EventCrew\Support\EventName::lang());
                        ?>
                        <li class="eventcrew-me-row">
                            <span class="eventcrew-me-row-head">
                                <span class="eventcrew-me-day"><?php echo esc_html($eventcrew_task_item->roleDisplay()); ?></span>
                                <span class="eventcrew-me-time"><?php echo esc_html(trim(('' === $eventcrew_task_row['time'] ? '' : $eventcrew_task_row['time'] . ' · ') . $eventcrew_task_row['filled'])); ?></span>
                            </span>
                            <span class="eventcrew-me-event"><?php echo esc_html($eventcrew_task_event_name['name']); ?><?php if ('' !== $eventcrew_task_event_name['dj']) : ?><span class="eventcrew-event-dj"><?php echo esc_html($eventcrew_task_event_name['dj']); ?></span><?php endif; ?></span>
                            <span class="eventcrew-me-what">
                                <?php if ([] === $eventcrew_task_row['people']) : ?>
                                    <span><?php esc_html_e('Nobody signed up.', 'eventcrew'); ?></span>
                                <?php endif; ?>
                                <?php foreach ($eventcrew_task_row['people'] as $eventcrew_task_person) : ?>
                                    <span><?php echo esc_html(trim($eventcrew_task_person['name'] . ' (' . $eventcrew_task_person['status'] . ') ' . $eventcrew_task_person['marked_by'])); ?></span>
                                <?php endforeach; ?>
                            </span>
                            <div class="eventcrew-me-actions">
                                <a class="eventcrew-textlink" href="<?php echo esc_url(add_query_arg(['tasks_task' => $eventcrew_task_item->id] + $eventcrew_tasks_args, $eventcrew_here) . '#eventcrew-admin-tasks'); ?>"><?php esc_html_e('Edit', 'eventcrew'); ?></a>
                                <form class="eventcrew-inline-form" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>"
                                    onsubmit="return confirm('<?php echo esc_js(__('Delete this task? Everyone signed up for it is taken off and emailed, and their tickets show DISABLED.', 'eventcrew')); ?>');">
                                    <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['task_delete_action']); ?>">
                                    <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_tasks_here); ?>">
                                    <input type="hidden" name="task_id" value="<?php echo esc_attr((string) $eventcrew_task_item->id); ?>">
                                    <button type="submit" class="eventcrew-textlink eventcrew-textlink-danger"><?php esc_html_e('Delete', 'eventcrew'); ?></button>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <?php if (! $eventcrew_task_is_edit) : ?>
                <div class="eventcrew-card-body eventcrew-admin-folds">
                    <details class="eventcrew-admin-fold" id="eventcrew-admin-task-form">
                        <summary class="eventcrew-textlink">+ <?php esc_html_e('Add a task', 'eventcrew'); ?></summary>
                        <?php echo $eventcrew_task_form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above, every part escaped. ?>
                    </details>
                    <?php if ([] !== $eventcrew_task_events) : ?>
                        <details class="eventcrew-admin-fold">
                            <summary class="eventcrew-textlink"><?php esc_html_e('Create an event\'s tasks', 'eventcrew'); ?></summary>
                            <form class="eventcrew-admin-form" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
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
                        </details>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </section>

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
                    <?php $eventcrew_admin_about('eventcrew-admin-about-open-task', __('Open-task email', 'eventcrew'), __('Sends to everyone with a verified, switched-on account, skipping anyone already signed up for that date. It only sends when there are open tasks, and never mails the same person twice for the same date. People switch it off by disabling their account (in the bot with /stop, or the link in any email).', 'eventcrew')); ?>
                </header>
                <div class="eventcrew-card-body eventcrew-admin-links">
                    <span class="eventcrew-admin-line"><?php echo esc_html($eventcrew_open_task_count); ?></span>
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
                <form class="eventcrew-card-body eventcrew-admin-search eventcrew-admin-date" method="get" action="<?php echo esc_url($eventcrew_here . '#eventcrew-admin-tasks'); ?>">
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
                    <noscript><button type="submit" class="eventcrew-textlink"><?php esc_html_e('Show', 'eventcrew'); ?></button></noscript>
                </form>
                <?php if (null !== $eventcrew_role_editing) : ?>
                    <?php
                    $eventcrew_role_here = add_query_arg(['tasks_role' => $eventcrew_role_editing['slug']] + $eventcrew_tasks_args, $eventcrew_here) . '#eventcrew-admin-tasks';
                    $eventcrew_role_conditions = $eventcrew_role_editing['conditions'];
                    ?>
                    <form class="eventcrew-card-body eventcrew-admin-form" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_admin['role_action']); ?>">
                        <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_role_here); ?>">
                        <input type="hidden" name="role" value="<?php echo esc_attr($eventcrew_role_editing['slug']); ?>">
                        <p class="eventcrew-admin-pair">
                            <span>
                                <label for="eventcrew-admin-role-emoji"><?php esc_html_e('Emoji', 'eventcrew'); ?></label>
                                <input type="text" name="role_emoji" id="eventcrew-admin-role-emoji" value="<?php echo esc_attr($eventcrew_role_editing['emoji']); ?>">
                            </span>
                            <span>
                                <label for="eventcrew-admin-role-label"><?php esc_html_e('Label', 'eventcrew'); ?></label>
                                <input type="text" name="role_label" id="eventcrew-admin-role-label" value="<?php echo esc_attr($eventcrew_role_editing['label']); ?>">
                            </span>
                        </p>
                        <p class="eventcrew-admin-pair">
                            <span>
                                <label for="eventcrew-admin-role-capacity"><?php esc_html_e('People', 'eventcrew'); ?></label>
                                <input type="number" name="role_capacity" id="eventcrew-admin-role-capacity" min="0" step="1" value="<?php echo esc_attr((string) $eventcrew_role_editing['capacity']); ?>">
                            </span>
                            <span>
                                <label for="eventcrew-admin-role-plus-ones"><?php esc_html_e('+1 allowed', 'eventcrew'); ?></label>
                                <input type="number" name="role_plus_ones" id="eventcrew-admin-role-plus-ones" min="0" max="20" step="1" value="<?php echo esc_attr((string) $eventcrew_role_editing['plus_ones']); ?>">
                            </span>
                        </p>
                        <span class="eventcrew-admin-hint eventcrew-muted"><?php esc_html_e('How many guests each person on a new task of this role may bring in for free. New tasks copy this number; change it on a single task on the Tasks screen.', 'eventcrew'); ?></span>
                        <p>
                            <label for="eventcrew-admin-role-description"><?php esc_html_e('Description', 'eventcrew'); ?></label>
                            <textarea name="role_description" id="eventcrew-admin-role-description" rows="3"><?php echo esc_textarea($eventcrew_role_editing['description']); ?></textarea>
                        </p>
                        <div class="eventcrew-admin-folds">
                        <details class="eventcrew-admin-fold">
                            <summary class="eventcrew-textlink"><?php esc_html_e('Timing', 'eventcrew'); ?></summary>
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
                        </details>
                        <details class="eventcrew-admin-fold">
                            <summary class="eventcrew-textlink"><?php esc_html_e('Access', 'eventcrew'); ?></summary>
                            <div class="eventcrew-admin-roles">
                                <label><input type="checkbox" name="role_archived" value="1"<?php echo $eventcrew_role_editing['archived'] ? ' checked' : ''; ?>> <?php esc_html_e('Archived (hidden)', 'eventcrew'); ?></label>
                                <label><input type="checkbox" name="role_restricted" value="1"<?php echo $eventcrew_role_editing['restricted'] ? ' checked' : ''; ?>> <?php esc_html_e('Only people with this role', 'eventcrew'); ?></label>
                                <label><input type="checkbox" name="role_every_night" value="1"<?php echo $eventcrew_role_editing['every_night'] ? ' checked' : ''; ?>> <?php esc_html_e('Add to every night', 'eventcrew'); ?></label>
                                <label><input type="checkbox" name="role_sales_desk" value="1"<?php echo $eventcrew_role_editing['sales_desk'] ? ' checked' : ''; ?>> <?php esc_html_e('Opens the sales desk', 'eventcrew'); ?></label>
                                <?php // Guest sign up greys out while Guest display and Members display are off (eventcrew.css). ?>
                                <label><input type="checkbox" name="role_on_board" value="1"<?php echo ($eventcrew_role_editing['on_board'] ?? true) ? ' checked' : ''; ?>> <?php esc_html_e('Guest display: people without this role see its tasks', 'eventcrew'); ?></label>
                                <label><input type="checkbox" name="role_members_display" value="1"<?php echo ($eventcrew_role_editing['members_display'] ?? false) ? ' checked' : ''; ?>> <?php esc_html_e('Members display: signed-in members see its tasks, even without the role', 'eventcrew'); ?></label>
                                <label class="eventcrew-needs-display"><input type="checkbox" name="role_guest_signup" value="1"<?php echo ($eventcrew_role_editing['guest_signup'] ?? true) ? ' checked' : ''; ?>> <?php esc_html_e('Guest sign up: people without this role can sign up (needs Guest or Members display)', 'eventcrew'); ?></label>
                                <label><input type="checkbox" name="role_anonymous" value="1"<?php echo ($eventcrew_role_editing['anonymous'] ?? false) ? ' checked' : ''; ?>> <?php esc_html_e('Anonymous display: hide the names from people without this role', 'eventcrew'); ?></label>
                            </div>
                        </details>
                        <details class="eventcrew-admin-fold">
                            <summary class="eventcrew-textlink"><?php esc_html_e('Only on events', 'eventcrew'); ?></summary>
                            <p>
                                <input type="text" name="role_title_contains" id="eventcrew-admin-role-title" value="<?php echo esc_attr($eventcrew_role_editing['title_contains']); ?>" aria-label="<?php esc_attr_e('Only on events', 'eventcrew'); ?>">
                                <span class="eventcrew-admin-hint eventcrew-muted"><?php esc_html_e('New events get a task of this role only when the event title contains this text (upper or lower case does not matter). Leave empty for every event.', 'eventcrew'); ?></span>
                            </p>
                        </details>
                        <?php $eventcrew_role_has_conditions = [] !== array_filter((array) $eventcrew_role_conditions['counts']) || 0 !== $eventcrew_role_conditions['min_roles']; ?>
                        <details class="eventcrew-admin-fold"<?php echo $eventcrew_role_has_conditions ? ' open' : ''; ?>>
                            <summary class="eventcrew-textlink"><?php esc_html_e('Conditions', 'eventcrew'); ?></summary>
                            <p class="eventcrew-muted"><?php esc_html_e('Completed tasks', 'eventcrew'); ?></p>
                            <?php foreach ($eventcrew_tasks['role_list'] as $eventcrew_count_role) : ?>
                                <?php
                                if ($eventcrew_count_role['slug'] === $eventcrew_role_editing['slug']) {
                                    continue;
                                }
                                $eventcrew_count_id = 'eventcrew-admin-role-count-' . $eventcrew_count_role['slug'];
                                ?>
                                <p class="eventcrew-admin-count">
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
                        </details>
                        </div>
                        <p>
                            <label for="eventcrew-admin-role-slug"><?php esc_html_e('Slug', 'eventcrew'); ?></label>
                            <input type="text" id="eventcrew-admin-role-slug" value="<?php echo esc_attr($eventcrew_role_editing['slug']); ?>" readonly>
                        </p>
                        <button type="submit" class="wp-element-button"><?php esc_html_e('Save', 'eventcrew'); ?></button>
                    </form>
                <?php endif; ?>
            </section>
            </div>

            <?php if ($eventcrew_has_messages) : ?>
            <div class="eventcrew-subpanel" id="eventcrew-admin-messages" data-eventcrew-subpanel="messages">
            <?php endif; ?>

            <?php if (isset($eventcrew_admin['newsletter'])) : ?>
                <?php $eventcrew_news = $eventcrew_admin['newsletter']; ?>
            <div id="eventcrew-admin-newsletter">
            <section class="eventcrew-event eventcrew-admin-card" id="eventcrew-admin-newsletter-card">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('Next events announcement', 'eventcrew'); ?></h3>
                    <?php $eventcrew_admin_about('eventcrew-admin-about-newsletter', __('Next events announcement', 'eventcrew'), __('Write it in any language. It is translated into every site language when you save, and removed after the announcement is sent.', 'eventcrew')); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                </header>
                <div class="eventcrew-card-body eventcrew-admin-links">
                    <?php if (! $eventcrew_news['enabled']) : ?>
                        <span class="eventcrew-admin-line eventcrew-muted"><?php esc_html_e('The next events announcement is switched off. An administrator can switch it on in wp-admin under EventCrew > Settings > Email.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></span>
                    <?php else : ?>
                        <span class="eventcrew-admin-line"><?php echo esc_html(sprintf(
                            /* translators: %d: number of days */
                            _n(
                                'The announcement goes out by itself %d day before each event.',
                                'The announcement goes out by itself %d days before each event.',
                                $eventcrew_news['days'],
                                'eventcrew'
                            ),
                            $eventcrew_news['days']
                        )); ?></span>
                    <?php endif; ?>
                    <span class="eventcrew-admin-line"><?php echo esc_html(sprintf(
                        /* translators: %d: number of confirmed subscribers */
                        _n('%d subscriber.', '%d subscribers.', $eventcrew_news['subscribers'], 'eventcrew'),
                        $eventcrew_news['subscribers']
                    )); ?></span>
                    <?php if (null !== $eventcrew_news['last']) : ?>
                        <span class="eventcrew-admin-line eventcrew-muted"><?php echo esc_html(sprintf(
                            /* translators: 1: date, like 14.11.2026, 2: number of emails sent */
                            __('Last announcement: %1$s, %2$d emails.', 'eventcrew'),
                            $eventcrew_news['last']['at'],
                            $eventcrew_news['last']['sent']
                        )); ?></span>
                    <?php endif; ?>
                </div>
                <?php if (null !== $eventcrew_news['plan']) : ?>
                    <div class="eventcrew-card-body">
                        <p class="eventcrew-admin-lead"><?php
                        if ($eventcrew_news['plan']['sending']) {
                            echo esc_html(sprintf(
                                /* translators: %d: number of emails sent so far */
                                __('The announcement is being sent now: %d emails so far.', 'eventcrew'),
                                $eventcrew_news['plan']['sent']
                            ));
                        } else {
                            echo esc_html(sprintf(
                                /* translators: %s: the send date, like 14.11.2026 */
                                __('Next announcement: %s, about these events:', 'eventcrew'),
                                $eventcrew_news['plan']['send_on']
                            ));
                        }
                        ?></p>
                        <ul class="eventcrew-admin-events">
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
                                <button type="submit" class="eventcrew-textlink eventcrew-textlink-danger">
                                    <?php esc_html_e('Stop this announcement', 'eventcrew'); ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($eventcrew_news['translating']) : ?>
                    <p class="eventcrew-card-body eventcrew-muted" role="status"
                        data-eventcrew-translating="<?php echo esc_attr(\EventCrew\Admin\AiPromptsPage::NEWSLETTER_TARGET); ?>"
                        data-card="eventcrew-admin-newsletter-card"
                        data-url="<?php echo esc_url($eventcrew_ajax); ?>"
                        data-action="<?php echo esc_attr($eventcrew_admin['text_status_action']); ?>"
                        data-csrf="<?php echo esc_attr($eventcrew_csrf); ?>"><?php
                        echo esc_html(\EventCrew\Support\BackgroundJobs::doing(\EventCrew\Admin\AiPromptsPage::NEWSLETTER_JOB));
                        ?></p>
                <?php endif; ?>
                <?php if (null !== $eventcrew_news['text']) : ?>
                    <ul class="eventcrew-plain-list eventcrew-me-list eventcrew-admin-texts">
                        <?php foreach ($eventcrew_news['text']['texts'] as $eventcrew_news_lang => $eventcrew_news_words) : ?>
                            <li class="eventcrew-me-row">
                                <span class="eventcrew-me-day"><?php echo esc_html(\EventCrew\Support\SiteLanguages::label((string) $eventcrew_news_lang)); ?></span>
                                <span class="eventcrew-admin-text"><?php echo esc_html($eventcrew_news_words); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (! $eventcrew_news['text']['translated']) : ?>
                        <p class="eventcrew-card-body eventcrew-muted">
                            <?php esc_html_e('This text is not translated: every language shows it as it was written.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
                <details class="eventcrew-card-body eventcrew-admin-fold" id="eventcrew-admin-newsletter-fold">
                    <summary class="eventcrew-textlink"><?php esc_html_e('Text for the next announcement', 'eventcrew'); ?></summary>
                    <form class="eventcrew-admin-form" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                        <input type="hidden" name="action"
                            value="<?php echo esc_attr($eventcrew_admin['newsletter_text_action']); ?>">
                        <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                        <textarea name="newsletter_text" id="eventcrew-admin-newsletter-text" rows="5"
                            aria-label="<?php esc_attr_e('Text for the next announcement', 'eventcrew'); ?>"
                            maxlength="<?php
                            echo (int) \EventCrew\Support\NewsletterText::MAX_LENGTH;
                            ?>"></textarea>
                        <?php if (null !== $eventcrew_news['text']) : ?>
                            <?php if ('' !== $eventcrew_news['text']['set_by']) : ?>
                                <span class="eventcrew-admin-hint eventcrew-muted"><?php echo esc_html($eventcrew_news['text']['set_by']); ?></span>
                            <?php endif; ?>
                            <label>
                                <input type="checkbox" name="replace" value="1" required>
                                <?php esc_html_e('Replace the current text', 'eventcrew'); ?>
                            </label>
                        <?php endif; ?>
                        <p class="eventcrew-admin-actions">
                            <button type="submit" name="op" value="save" class="wp-element-button">
                                <?php esc_html_e('Save', 'eventcrew'); ?>
                            </button>
                            <?php if (null !== $eventcrew_news['text']) : ?>
                                <button type="submit" name="op" value="clear" formnovalidate class="eventcrew-textlink">
                                    <?php esc_html_e('Reset to default', 'eventcrew'); ?>
                                </button>
                            <?php endif; ?>
                        </p>
                    </form>
                </details>
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
            <div id="eventcrew-admin-followup">
            <section class="eventcrew-event eventcrew-admin-card" id="eventcrew-admin-followup-card">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('Post-event email', 'eventcrew'); ?></h3>
                    <?php $eventcrew_admin_about('eventcrew-admin-about-followup', __('Post-event email', 'eventcrew'), __('The text between the greeting and the sign-off. You can replace it for the next email only; after that email is sent, or when that night is stopped, the text on the Messages page is used again. {event} becomes the event’s name and {date} its date.', 'eventcrew'), __('Write it in any language. It is translated into every site language when you save.', 'eventcrew')); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                </header>
                <?php if (! $eventcrew_follow['enabled']) : ?>
                    <p class="eventcrew-card-body eventcrew-admin-lead eventcrew-muted"><?php esc_html_e('The post-event email is switched off. An administrator can switch it on in wp-admin under EventCrew > Settings > Email.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>
                <?php endif; ?>
                <?php foreach ($eventcrew_follow_labels as $eventcrew_follow_variant => $eventcrew_follow_label) : ?>
                    <?php
                    $eventcrew_follow_text = $eventcrew_follow['texts'][$eventcrew_follow_variant];
                    $eventcrew_follow_id = 'eventcrew-admin-followup-' . $eventcrew_follow_variant;
                    ?>
                    <h4 class="eventcrew-admin-sub"><?php echo esc_html($eventcrew_follow_label); ?></h4>
                    <p class="eventcrew-card-body eventcrew-admin-lead eventcrew-muted">
                        <?php
                        if ($eventcrew_follow_text['next']) {
                            esc_html_e('The next email uses a one-time text.', 'eventcrew');
                        } else {
                            esc_html_e('The next email uses the text on the Messages page.', 'eventcrew');
                        }
                        ?>
                    </p>
                    <?php if ($eventcrew_follow_text['translating']) : ?>
                        <p class="eventcrew-card-body eventcrew-muted" role="status"
                            data-eventcrew-translating="<?php echo esc_attr(\EventCrew\Admin\AiPromptsPage::followupTarget($eventcrew_follow_variant)); ?>"
                            data-card="eventcrew-admin-followup-card"
                            data-url="<?php echo esc_url($eventcrew_ajax); ?>"
                            data-action="<?php echo esc_attr($eventcrew_admin['text_status_action']); ?>"
                            data-csrf="<?php echo esc_attr($eventcrew_csrf); ?>"><?php
                            echo esc_html(\EventCrew\Support\BackgroundJobs::doing(\EventCrew\Admin\AiPromptsPage::FOLLOWUP_JOB));
                            ?></p>
                    <?php endif; ?>
                    <?php if ([] !== $eventcrew_follow_text['texts']) : ?>
                        <ul class="eventcrew-plain-list eventcrew-me-list eventcrew-admin-texts">
                            <?php foreach ($eventcrew_follow_text['texts'] as $eventcrew_follow_lang => $eventcrew_follow_words) : ?>
                                <li class="eventcrew-me-row">
                                    <span class="eventcrew-me-day"><?php echo esc_html(\EventCrew\Support\SiteLanguages::label((string) $eventcrew_follow_lang)); ?></span>
                                    <span class="eventcrew-admin-text"><?php echo esc_html($eventcrew_follow_words); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if (! $eventcrew_follow_text['translated']) : ?>
                        <p class="eventcrew-card-body eventcrew-muted">
                            <?php esc_html_e('This text is not translated: every language shows it as it was written.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                        </p>
                    <?php endif; ?>
                    <details class="eventcrew-card-body eventcrew-admin-fold" id="<?php echo esc_attr($eventcrew_follow_id); ?>-fold">
                        <summary class="eventcrew-textlink"><?php esc_html_e('One-time text for the next email', 'eventcrew'); ?></summary>
                        <form class="eventcrew-admin-form" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                            <input type="hidden" name="action"
                                value="<?php echo esc_attr($eventcrew_admin['followup_text_action']); ?>">
                            <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                            <input type="hidden" name="variant" value="<?php echo esc_attr($eventcrew_follow_variant); ?>">
                            <textarea name="followup_text" id="<?php echo esc_attr($eventcrew_follow_id); ?>" rows="5"
                                aria-label="<?php esc_attr_e('One-time text for the next email', 'eventcrew'); ?>"
                                maxlength="<?php
                                echo (int) \EventCrew\Support\FollowupText::MAX_LENGTH;
                                ?>"></textarea>
                            <?php if ($eventcrew_follow_text['next']) : ?>
                                <?php if ('' !== $eventcrew_follow_text['set_by']) : ?>
                                    <span class="eventcrew-admin-hint eventcrew-muted"><?php echo esc_html($eventcrew_follow_text['set_by']); ?></span>
                                <?php endif; ?>
                                <label>
                                    <input type="checkbox" name="replace" value="1" required>
                                    <?php esc_html_e('Replace the current text', 'eventcrew'); ?>
                                </label>
                            <?php endif; ?>
                            <p class="eventcrew-admin-actions">
                                <button type="submit" name="op" value="save" class="wp-element-button">
                                    <?php esc_html_e('Save', 'eventcrew'); ?>
                                </button>
                                <?php if ($eventcrew_follow_text['next']) : ?>
                                    <button type="submit" name="op" value="clear" formnovalidate class="eventcrew-textlink">
                                        <?php esc_html_e('Reset to default', 'eventcrew'); ?>
                                    </button>
                                <?php endif; ?>
                            </p>
                        </form>
                    </details>
                <?php endforeach; ?>
            </section>
            </div>
            <?php endif; ?>

            <?php if (isset($eventcrew_admin['ai_texts'])) : ?>
            <div id="eventcrew-admin-aitexts">
            <section class="eventcrew-event eventcrew-admin-card">
                <header class="eventcrew-event-head">
                    <h3 class="eventcrew-event-title"><?php esc_html_e('AI texts', 'eventcrew'); ?></h3>
                    <?php $eventcrew_admin_about('eventcrew-admin-about-aitexts', __('AI texts', 'eventcrew'), __('The AI writes these the day before each event. A text nobody changes is sent as the AI wrote it. A text you change is sent as you wrote it. When the AI failed, the normal message text is used.', 'eventcrew')); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                </header>
                <?php if ([] === $eventcrew_admin['ai_texts']) : ?>
                    <p class="eventcrew-card-body eventcrew-muted"><?php esc_html_e('No texts are written yet for the coming events.', 'eventcrew'); ?></p>
                <?php endif; ?>
                <?php foreach ($eventcrew_admin['ai_texts'] as $eventcrew_ai_event) : ?>
                    <h4 class="eventcrew-admin-sub"><?php echo esc_html($eventcrew_ai_event['date'] . ' · ' . $eventcrew_ai_event['title']); ?></h4>
                    <ul class="eventcrew-plain-list eventcrew-me-list">
                    <?php foreach ($eventcrew_ai_event['items'] as $eventcrew_ai_item) : ?>
                        <?php $eventcrew_ai_edited = in_array(true, $eventcrew_ai_item['edited'], true); ?>
                        <li class="eventcrew-me-row">
                            <span class="eventcrew-me-row-head">
                                <span class="eventcrew-me-day"><?php echo esc_html($eventcrew_ai_item['label']); ?></span>
                                <?php if ($eventcrew_ai_edited) : ?>
                                    <span class="eventcrew-me-status"><?php esc_html_e('(changed by hand)', 'eventcrew'); ?></span>
                                <?php endif; ?>
                            </span>
                            <?php if ($eventcrew_ai_item['failed']) : ?>
                                <span class="eventcrew-me-what"><?php esc_html_e('The AI could not write this text. The normal message text is used, unless you write one here.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></span>
                            <?php endif; ?>
                            <div class="eventcrew-me-actions">
                            <details class="eventcrew-admin-fold">
                            <summary class="eventcrew-textlink"><?php esc_html_e('Edit', 'eventcrew'); ?></summary>
                            <form class="eventcrew-admin-form" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                                <input type="hidden" name="action"
                                    value="<?php echo esc_attr($eventcrew_admin['ai_text_action']); ?>">
                                <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                                <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $eventcrew_ai_event['event_id']); ?>">
                                <input type="hidden" name="key" value="<?php echo esc_attr($eventcrew_ai_item['key']); ?>">
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
                                <p class="eventcrew-admin-actions">
                                    <button type="submit" name="op" value="save" class="wp-element-button">
                                        <?php esc_html_e('Save', 'eventcrew'); ?>
                                    </button>
                                    <?php if ($eventcrew_ai_edited && ! $eventcrew_ai_item['failed']) : ?>
                                        <button type="submit" name="op" value="ai" class="eventcrew-textlink">
                                            <?php esc_html_e('Use the AI text again', 'eventcrew'); ?>
                                        </button>
                                    <?php endif; ?>
                                </p>
                            </form>
                            </details>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
            </section>
            </div>
            <?php endif; ?>

            <?php if ($eventcrew_has_messages) : ?>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
        <?php
    endif; ?>
    <?php
    // The "Get the app" pop-in, signed in only (nothing when signed out).
    $eventcrew_app_install = is_array($view['app_install'] ?? null) ? $view['app_install'] : null;
    require EVENTCREW_PLUGIN_DIR . 'templates/public/signup-app-install.php';
    ?>
    <?php
    /*
     * "Install the app": a text link, hidden in the HTML and shown by the
     * script only in a browser tab (not in the installed app). On Android
     * (Chrome) it opens the browser's own install prompt once the browser
     * offers one; on an iPhone or iPad, where no page can install itself, it
     * shows the one line on how. Elsewhere it stays hidden. The same HTML for
     * everyone, so the cached page is fine.
     */
    ?>
    <p class="eventcrew-install eventcrew-muted eventcrew-hint" data-eventcrew-install hidden>
        <button type="button" class="eventcrew-textlink"><?php esc_html_e('Install the app', 'eventcrew'); ?></button>
        <span data-eventcrew-install-ios hidden><?php esc_html_e('Tap Share, then Add to Home Screen.', 'eventcrew'); ?></span>
    </p>
    <script>
    (function () {
        var box = document.querySelector('[data-eventcrew-install]');
        if (!box) { return; }
        var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
            || true === window.navigator.standalone;
        if (standalone) { return; }
        var link = box.querySelector('button');
        var how = box.querySelector('[data-eventcrew-install-ios]');
        var ua = navigator.userAgent || '';
        // iPadOS Safari says it is a Mac; a Mac has no touch screen.
        var ios = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
        var offer = null;

        if (ios) {
            box.hidden = false;
            link.addEventListener('click', function () {
                how.hidden = false;
                link.hidden = true;
            });
            return;
        }

        window.addEventListener('beforeinstallprompt', function (event) {
            event.preventDefault();
            offer = event;
            box.hidden = false;
        });
        window.addEventListener('appinstalled', function () { box.hidden = true; });
        link.addEventListener('click', function () {
            if (!offer) { return; }
            offer.prompt();
            offer = null;
            box.hidden = true;
        });
    })();
    </script>
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

    // A link to a card on another tab, such as See open tasks on the Me
    // tab: its tab opens, then the page scrolls to the card.
    root.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('[data-eventcrew-goto]') : null;
        var target = link ? document.getElementById(link.getAttribute('data-eventcrew-goto')) : null;
        var holder = target && target.closest('[data-eventcrew-panel]');
        if (!holder) { return; }
        e.preventDefault();
        show(holder.getAttribute('data-eventcrew-panel'), false);
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

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

    // A key or link of a screen that is now part of another (newsletter,
    // followup and aitexts are in Messages): the screen that holds it.
    function holder(key) {
        if (!key || has(key)) { return key; }
        var node = document.getElementById('eventcrew-admin-' + key);
        var sub = node && node.closest('[data-eventcrew-subpanel]');
        return sub ? sub.getAttribute('data-eventcrew-subpanel') : key;
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
    show(holder(key));
})();

/*
 * A text in the Admin tab that is being translated shows "Translating…".
 * While one does, the page asks the site every few seconds (which also runs
 * the job); when a text is done, its card is fetched again and put in
 * place, so every language shows what is stored, without a reload. A text
 * typed into the card's fields and not yet saved is kept.
 */
(function () {
    var timer = null;

    function marks() {
        return [].slice.call(document.querySelectorAll('[data-eventcrew-translating]'));
    }

    function refresh(ids) {
        return window.fetch(window.location.href, { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.text() : ''; })
            .then(function (html) {
                if (!html) { return; }
                var doc = new window.DOMParser().parseFromString(html, 'text/html');
                ids.forEach(function (id) {
                    var old = document.getElementById(id);
                    var fresh = doc.getElementById(id);
                    if (!old || !fresh) { return; }
                    [].slice.call(old.querySelectorAll('textarea[id], input[type="text"][id]')).forEach(function (f) {
                        var twin = fresh.querySelector('#' + f.id);
                        if (twin && f.value) { twin.value = f.value; }
                    });
                    // A folded form left open stays open.
                    [].slice.call(old.querySelectorAll('details[id][open]')).forEach(function (d) {
                        var twin = fresh.querySelector('#' + d.id);
                        if (twin) { twin.setAttribute('open', ''); }
                    });
                    old.replaceWith(document.importNode(fresh, true));
                });
            });
    }

    function poll() {
        timer = null;
        var list = marks();
        if (!list.length) { return; }
        var first = list[0];
        var body = new window.FormData();
        body.append('action', first.getAttribute('data-action'));
        body.append('csrf', first.getAttribute('data-csrf'));
        window.fetch(first.getAttribute('data-url'), { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (answer) {
                if (!answer || !answer.success) { return null; }
                var active = answer.data.active || [];
                var cards = [];
                marks().forEach(function (m) {
                    var card = m.getAttribute('data-card');
                    if (active.indexOf(m.getAttribute('data-eventcrew-translating')) < 0 && cards.indexOf(card) < 0) {
                        cards.push(card);
                    }
                });
                return cards.length ? refresh(cards) : true;
            })
            .then(function (goOn) {
                if (goOn !== null) { timer = window.setTimeout(poll, 4000); }
            })
            .catch(function () { timer = window.setTimeout(poll, 15000); });
    }

    if (marks().length && window.fetch && window.DOMParser) { timer = window.setTimeout(poll, 3000); }
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

    // A toast carried over a reload by a Me-tab form (data-eventcrew-reload).
    try {
        var carried = sessionStorage.getItem('eventcrew_toast');
        if (carried) {
            sessionStorage.removeItem('eventcrew_toast');
            showToast(carried);
        }
    } catch (err) {}

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
     * The sign-in button and the spam check.
     *
     * Cloudflare's script is not loaded with the page: Turnstile::loaderScript()
     * adds it when the visitor first touches, focuses or types in the form, and
     * the widget then solves in the background. A press that comes before the
     * token is held by that loader (the button says "Checking…") and the form
     * is sent again the moment the token is there, so this script only ever
     * sees a submit with a token - or, when the challenge script is blocked
     * (ad blockers do that), one without, which it refuses here with a notice
     * rather than sending.
     *
     * So the button is never disabled just for want of a token: a button that
     * went dead the instant a press started loading the script could swallow
     * that very press.
     *
     * None of this runs without the widget, and none of it runs with JavaScript
     * off - the button ships enabled and the form posts normally.
     */
    var CHALLENGE_STEP = 200;
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
     * REFRESH_WAIT is short on purpose: somebody has just pressed and is
     * watching, and a challenge that has not answered in a few seconds is not
     * going to. Long waits, once before sending and once on the retry, are
     * what turned a refusal into half a minute of nothing followed by a toast.
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
     * Only the resend cooldown gates it now; the spam check no longer does
     * (see the note at the top). It still watches the token, so its age is
     * known when the next press comes. While the loader holds a press for
     * its token (data-ts-wait on the form) the button is the loader's.
     *
     * It runs whenever there is a button, not only when there is a challenge -
     * an install with no Turnstile keys has no widget to wait for but still
     * has a cooldown to show.
     */
    function tickSigninButton() {
        if (!signinButton || submitting || signinForm.hasAttribute('data-ts-wait')) {
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

        noteToken(challengeToken(signinForm));
        setSigninLive(true);
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
        setInterval(tickSigninButton, CHALLENGE_STEP);
    }

    root.addEventListener('submit', function (e) {
        var form = e.target.closest('form.eventcrew-action');

        if (!form) {
            return;
        }

        e.preventDefault();

        // A cancel or a spent credit asks first, saying what it costs. %s is
        // the night chosen in the form's list.
        var ask = form.getAttribute('data-eventcrew-confirm') || '';
        var night = form.querySelector('select');

        if (night && night.selectedIndex >= 0) {
            ask = ask.replace('%s', night.options[night.selectedIndex].text);
        }

        if (ask && !window.confirm(ask)) {
            return;
        }

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
             * A Me-tab form (Cancel, Give away, Take someone's task) changes
             * cards the board swap does not redraw: reload onto its card, and
             * carry the toast over the reload. res.changed === false means
             * nothing happened (a typo in the email), so the form stays filled.
             */
            var reloadTo = form.getAttribute('data-eventcrew-reload');
            if (reloadTo && res && false !== res.changed) {
                try { sessionStorage.setItem('eventcrew_toast', res.notice || ''); } catch (err) {}
                history.replaceState(null, '', window.location.pathname + window.location.search + '#' + reloadTo);
                window.location.reload();

                return;
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
                // The field for the code in the same email.
                if (window.eventcrewShowCode && emailField) { window.eventcrewShowCode(emailField.value); }

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
 * the code to this browser) and shows the 4-digit code in two pairs, with an
 * Open Telegram button. Nothing opens until that button is pressed: it is a
 * plain link to the bot, so no pop-up blocker stops it and a phone opens the
 * Telegram app. From when the code shows, the page asks the site every 3
 * seconds whether the bot has approved the code. Once it has, the site
 * starts the session and the page reloads signed in.
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
    var pairs = wait ? wait.querySelectorAll('[data-eventcrew-tg-pair]') : [];
    var openRow = wait ? wait.querySelector('[data-eventcrew-tg-open-row]') : null;
    var openButton = wait ? wait.querySelector('[data-eventcrew-tg-open]') : null;
    var opened = wait ? wait.querySelector('[data-eventcrew-tg-opened]') : null;
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

    // The Telegram buttons have Telegram's colours (see eventcrew.css). Copy
    // the Email button's shape and font onto them, whatever the theme makes
    // that. Run while the Email button shows, so its height is a real one.
    function matchLook() {
        if (!emailButton || !window.getComputedStyle) { return; }
        var look = window.getComputedStyle(emailButton);
        [tgButton, openButton].forEach(function (tg) {
            if (!tg) { return; }
            ['fontFamily', 'fontWeight', 'fontSize', 'letterSpacing', 'textTransform', 'borderRadius',
                'paddingLeft', 'paddingRight', 'height', 'lineHeight'].forEach(function (key) {
                tg.style[key] = look[key];
            });
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

    // The code in two pairs, "47" "19": easier to hold in mind than "4719",
    // and the bot's buttons show it the same way.
    function showCode(url, match) {
        choice.hidden = true;
        if (pairs.length === 2) {
            var whole = match.length === 4;
            pairs[0].textContent = whole ? match.slice(0, 2) : match;
            pairs[1].textContent = whole ? match.slice(2) : '';
            pairs[1].hidden = !whole;
        }
        if (note) { note.hidden = true; }
        if (openButton) { openButton.href = url; }
        if (again) { again.href = url; }
        if (openRow) { openRow.hidden = false; }
        if (opened) { opened.hidden = true; }
        if (wait) { wait.hidden = false; }
        if (form) { form.hidden = true; }
        if (back) { back.hidden = false; }
    }

    // Open Telegram was pressed: the link itself opens the bot. Swap the
    // button for the waiting line, keeping the code and an "again" link.
    function showOpened() {
        if (!login) { return; }
        if (openRow) { openRow.hidden = true; }
        if (opened) { opened.hidden = false; }
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

    // While the site makes the code: a spinner on the button, and no second press.
    function busy(on) {
        if (!tgButton) { return; }
        tgButton.disabled = on;
        tgButton.classList.toggle('is-busy', on);
        if (on) { tgButton.setAttribute('aria-busy', 'true'); } else { tgButton.removeAttribute('aria-busy'); }
    }

    function start() {
        if (starting) { return; }
        starting = true;
        busy(true);
        if (note) { note.hidden = true; }

        post({ action: choice.getAttribute('data-start-action') }).then(function (data) {
            starting = false;
            busy(false);
            var code = data && data.code ? data.code : 'tg_failed';

            if (code !== 'tg_started' || !data.login || !data.url || !data.match) {
                toast((data && data.notice) || failedText);
                return;
            }

            login = String(data.login);
            deadline = Date.now() + (parseInt(data.expires_in, 10) || 1800) * 1000;
            showCode(data.url, String(data.match));
            schedule();
        }).catch(function () {
            starting = false;
            busy(false);
            toast(failedText);
        });
    }

    if (emailButton) {
        emailButton.addEventListener('click', function () { showEmail(); });
    }

    if (tgButton) {
        tgButton.addEventListener('click', function () { start(); });
    }

    // Not prevented: the link opens the bot, in the Telegram app on a phone.
    if (openButton) {
        openButton.addEventListener('click', function () { showOpened(); });
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

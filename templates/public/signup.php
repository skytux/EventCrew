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
$eventcrew_manage_endpoint = (string) ($view['manage_endpoint'] ?? '');
$eventcrew_manage_token = (string) ($view['manage_token'] ?? '');
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
<style>
/*
 * Background-agnostic on purpose: the widget inherits the theme's text colour,
 * dims secondary text with opacity rather than a fixed grey, and tints the
 * notice with a translucent overlay - so it reads on a light or a dark theme
 * without knowing which it is on.
 */
.eventcrew-signup .eventcrew-muted { opacity: .7; }

/*
 * The action feedback is a toast pinned to the viewport, so it stays visible
 * after an in-place update no matter where the list is scrolled - which the old
 * inline notice, sitting at the top of the board, did not.
 */
.eventcrew-toast {
    position: fixed; left: 50%; bottom: 1.5em; transform: translateX(-50%) translateY(.5em);
    z-index: 100000; max-width: 90vw;
    padding: .6em 1.1em; border-radius: 8px;
    background: #222; color: #fff; font-weight: 600; box-shadow: 0 4px 16px rgba(0, 0, 0, .28);
    opacity: 0; visibility: hidden; transition: opacity .18s ease, transform .18s ease;
}
.eventcrew-toast.show { opacity: 1; visibility: visible; transform: translateX(-50%) translateY(0); }
.eventcrew-signup .eventcrew-tglink {
    display: inline-flex; align-items: center; gap: .4em;
    padding: .5em .9em; margin: .5em 0; border-radius: 6px;
    background: #229ED9; color: #fff; text-decoration: none; font-weight: 600;
}
.eventcrew-signup .eventcrew-tglink:hover { filter: brightness(1.08); color: #fff; }

/*
 * Keep the task list narrow so each row's action sits close to its text while
 * still lining up in a column down the right edge, instead of being flung to
 * the far side of a full-width content column.
 */
.eventcrew-signup ul { list-style: none; padding: 0; max-width: 30em; }
.eventcrew-signup li { display: flex; gap: .6em; align-items: center; margin: .4em 0; }
.eventcrew-signup .eventcrew-task { flex: 1; }
.eventcrew-signup form { margin: 0; }

/* Sign out is a quiet action, not a button - styled as a plain inline link. */
.eventcrew-signup .eventcrew-linkbtn {
    background: none; border: 0; padding: 0; font: inherit;
    color: inherit; text-decoration: underline; cursor: pointer; opacity: .8;
}

/*
 * Sign up reads go, Cancel reads stop. Deep, dark shades so white text stays
 * legible and they sit calmly on a light or dark theme alike.
 */
.eventcrew-signup .eventcrew-btn-go,
.eventcrew-signup .eventcrew-btn-stop,
.eventcrew-signup .eventcrew-btn-full {
    color: #fff; border: 0; border-radius: 5px; cursor: pointer;
    padding: .28em .8em; font: inherit; font-size: .8em; font-weight: 600; line-height: 1.5;
}
.eventcrew-signup form.eventcrew-action button[disabled] { opacity: .6; cursor: default; }
.eventcrew-signup .eventcrew-btn-go { background: #1b5e20; }
.eventcrew-signup .eventcrew-btn-go:hover { background: #164a1a; color: #fff; }
.eventcrew-signup .eventcrew-btn-stop { background: #8e1616; }
.eventcrew-signup .eventcrew-btn-stop:hover { background: #741212; color: #fff; }
.eventcrew-signup .eventcrew-btn-full { background: #6b7280; opacity: .8; cursor: not-allowed; }

/* The free-entry redeem control and the collapsible score explainer. */
.eventcrew-signup .eventcrew-redeem { display: flex; gap: .5em; align-items: center; flex-wrap: wrap; margin: .5em 0; }
.eventcrew-signup .eventcrew-score-help { margin: .3em 0 .8em; font-size: .9em; }
.eventcrew-signup .eventcrew-score-help summary { cursor: pointer; opacity: .8; }
.eventcrew-signup .eventcrew-score-help table { border-collapse: collapse; margin: .4em 0; }
.eventcrew-signup .eventcrew-score-help td { padding: .1em .8em .1em 0; }

.eventcrew-signin-row {
    display: flex;
    align-items: stretch;
    gap: 0.75em;
    flex-wrap: wrap;
}

.eventcrew-signin-row .wp-element-input,
.eventcrew-signin-row .wp-element-button {
    box-sizing: border-box;
    height: 3em;              /* the shared source of truth */
    padding-block: 0;         /* kill vertical padding differences */
    padding-inline: 1em;
    font-size: 1rem;
    line-height: 1;
    border-radius: var(--wp--custom--border-radius, 6px);
    border: 1px solid var(--wp--preset--color--contrast, #333);
}

.eventcrew-signin-row .wp-element-input {
    background-color: var(--wp--preset--color--base, #fff);
    color: var(--wp--preset--color--contrast, #111);
    min-width: 16em;
    flex: 1 1 16em;
}

.eventcrew-signin-row .wp-element-input:focus {
    outline: none;
    border-color: var(--wp--preset--color--primary, #0073aa);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--wp--preset--color--primary, #0073aa) 25%, transparent);
}

.eventcrew-signin-row .wp-element-input::placeholder {
    color: var(--wp--preset--color--contrast, #333);
    opacity: 0.5;
}
</style>
<div class="eventcrew-signup">
    <div id="eventcrew-toast" class="eventcrew-toast<?php echo '' !== $eventcrew_notice_text ? ' show' : ''; ?>" role="status" aria-live="polite"><?php echo esc_html($eventcrew_notice_text); ?></div>

    <?php if (null === $eventcrew_person) :
        ?>
        <h2><?php esc_html_e('Sign in', 'eventcrew'); ?></h2>
        <form class="eventcrew-action" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>" style="margin:1em 0">
            <input type="hidden" name="action" value="<?php echo esc_attr((string) $view['login_action']); ?>">
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
            <div class="eventcrew-signin-row">
                <label for="eventcrew-email" class="screen-reader-text"><?php esc_html_e('Email', 'eventcrew'); ?></label>
                <input
                    type="email"
                    id="eventcrew-email"
                    name="email"
                    required
                    placeholder="<?php esc_attr_e('hello@example.com', 'eventcrew'); ?>"
                    class="wp-element-input"
                >
                <button type="submit" class="wp-element-button"><?php esc_html_e('Email me a sign-in link', 'eventcrew'); ?></button>
            </div>
            <?php if ('' !== $eventcrew_turnstile_site_key) : ?>
                <div
                    class="cf-turnstile"
                    style="margin-top:.75em"
                    data-sitekey="<?php echo esc_attr($eventcrew_turnstile_site_key); ?>"
                    data-theme="auto"></div>
                <script src="<?php echo esc_url(\EventCrew\Support\Turnstile::SCRIPT_URL); ?>" async defer></script>
            <?php endif; ?>
        <?php
    else :
        ?>
        <h2><?php esc_html_e('Welcome', 'eventcrew'); ?></h2>
        <p>
            <?php
            printf(/* translators: %s: person's name */
                esc_html__('Signed in as %s.', 'eventcrew'),
                esc_html($eventcrew_person->name())
            ); ?>
        </p>
        <?php if (null !== $eventcrew_standing) : ?>
            <p>
                <span><?php echo esc_html($eventcrew_standing->ratedSummary()); ?></span>
                &middot;
                <span><?php echo esc_html(sprintf(/* translators: %d: number of free-entry credits */
                    _n('%d credit', '%d credits', $eventcrew_standing->creditBalance, 'eventcrew'),
                    $eventcrew_standing->creditBalance
                )); ?></span>
            </p>
            <details class="eventcrew-score-help">
                <summary><?php esc_html_e('How your score works', 'eventcrew'); ?></summary>
                <table>
                    <tbody>
                    <?php foreach (\EventCrew\Support\StandingExplainer::rows() as $eventcrew_label => $eventcrew_percent) : ?>
                        <tr>
                            <td><?php echo esc_html($eventcrew_label); ?></td>
                            <td style="text-align:right"><?php echo esc_html($eventcrew_percent . '%'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="eventcrew-muted"><?php esc_html_e('Recent tasks count for more than old ones. You’re rated once you’ve completed a few tasks, and you earn one free-entry credit for every two completed tasks.', 'eventcrew'); ?></p>
            </details>
        <?php endif; ?>

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

        <?php if ([] !== $eventcrew_my_tickets['upcoming'] || [] !== $eventcrew_my_tickets['past']) : ?>
            <details class="eventcrew-score-help">
                <summary><?php esc_html_e('My tickets', 'eventcrew'); ?></summary>
                <?php foreach (['upcoming' => __('Upcoming', 'eventcrew'), 'past' => __('Past', 'eventcrew')] as $eventcrew_group => $eventcrew_group_label) : ?>
                    <?php if ([] !== $eventcrew_my_tickets[$eventcrew_group]) : ?>
                        <p class="eventcrew-muted" style="margin:.4em 0 .2em"><strong><?php echo esc_html($eventcrew_group_label); ?></strong></p>
                        <ul style="margin:0 0 .4em;padding-left:1.2em">
                            <?php foreach ($eventcrew_my_tickets[$eventcrew_group] as $eventcrew_ticket) : ?>
                                <li>
                                    <a href="<?php echo esc_url($eventcrew_ticket['url']); ?>" target="_blank" rel="noopener">
                                        <?php echo esc_html($eventcrew_ticket['label']); ?>
                                    </a>
                                    — <?php echo esc_html($eventcrew_ticket['when']); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endforeach; ?>
            </details>
        <?php endif; ?>

        <?php if ('' !== $eventcrew_prefs_action && [] !== $eventcrew_notify_matrix) : ?>
            <details class="eventcrew-score-help">
                <summary><?php esc_html_e('Notifications', 'eventcrew'); ?></summary>
                <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr($eventcrew_prefs_action); ?>">
                    <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                    <table>
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
                                <td style="text-align:center">
                                    <input type="checkbox" name="prefs[<?php echo esc_attr((string) $eventcrew_type); ?>][dm]" value="1" <?php checked($eventcrew_pref['dm']); ?>>
                                </td>
                                <td style="text-align:center">
                                    <input type="checkbox" name="prefs[<?php echo esc_attr((string) $eventcrew_type); ?>][email]" value="1" <?php checked($eventcrew_pref['email']); ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="eventcrew-muted"><?php esc_html_e('Signup confirmations and task reminders are always sent on both channels.', 'eventcrew'); ?></p>
                    <button type="submit" class="wp-element-button"><?php esc_html_e('Save preferences', 'eventcrew'); ?></button>
                </form>
            </details>
        <?php endif; ?>

        <?php if ('' !== $eventcrew_manage_token) : ?>
            <details class="eventcrew-score-help">
                <summary><?php esc_html_e('Account', 'eventcrew'); ?></summary>
                <p class="eventcrew-muted"><?php esc_html_e('Pause all emails (turn your account back on any time with /start in the bot), or delete your data for good.', 'eventcrew'); ?></p>
                <form method="post" action="<?php echo esc_url($eventcrew_manage_endpoint); ?>" style="margin:.4em 0">
                    <input type="hidden" name="token" value="<?php echo esc_attr($eventcrew_manage_token); ?>">
                    <input type="hidden" name="action" value="disable">
                    <button type="submit" class="eventcrew-linkbtn"><?php esc_html_e('Pause emails (switch account off)', 'eventcrew'); ?></button>
                </form>
                <form method="post" action="<?php echo esc_url($eventcrew_manage_endpoint); ?>" style="margin:.4em 0"
                    onsubmit="return confirm('<?php echo esc_js(__('Delete your account and all your history? This cannot be undone.', 'eventcrew')); ?>');">
                    <input type="hidden" name="token" value="<?php echo esc_attr($eventcrew_manage_token); ?>">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="eventcrew-linkbtn" style="color:#b32d2e"><?php esc_html_e('Delete my data', 'eventcrew'); ?></button>
                </form>
            </details>
        <?php endif; ?>

        <p>
            <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>" style="display:inline">
                <input type="hidden" name="action" value="<?php echo esc_attr((string) $view['logout_action']); ?>">
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                <button type="submit" class="eventcrew-linkbtn"><?php esc_html_e('Sign out', 'eventcrew'); ?></button>
            </form>
        </p>
        <?php
    endif; ?>

    <?php if ('' !== (string) $view['telegram_group_link']) :
        ?>
        <p>
            <a class="eventcrew-tglink" href="<?php echo esc_url((string) $view['telegram_group_link']); ?>" target="_blank" rel="noopener">
                <?php esc_html_e('Open our group in Telegram', 'eventcrew'); ?> →
            </a>
        </p>
        <?php
    endif; ?>

    <div id="eventcrew-board" class="eventcrew-board">
        <h2><?php esc_html_e('Board', 'eventcrew'); ?></h2>
        <?php require EVENTCREW_PLUGIN_DIR . 'templates/public/signup-board.php'; ?>
    </div>
</div>
<script>
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

    if (!root || !board || !window.fetch) {
        return;
    }

    var ajaxUrl = <?php echo wp_json_encode($eventcrew_ajax); ?>;

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

        var data = new FormData(form);
        data.append('eventcrew_ajax', '1');

        fetch(ajaxUrl, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            return r.json();
        }).then(function (res) {
            if (res && typeof res.board === 'string') {
                board.innerHTML = res.board;
            }
            if (res && res.notice) {
                showToast(res.notice);
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
            // A Turnstile token is single-use; reset the widget so a second
            // sign-in attempt (or a rejected one) can be solved afresh.
            if (window.turnstile && form.querySelector('.cf-turnstile')) {
                try { window.turnstile.reset(); } catch (e) {}
            }
        }).catch(function () {
            if (button) {
                button.disabled = false;
            }
        });
    });
})();
</script>

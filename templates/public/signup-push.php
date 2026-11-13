<?php

/**
 * App notifications (Web Push) for this device, in the Notifications card:
 * one switch that shows whether they are on here and turns them on or off,
 * and the line that says why it cannot when it cannot. The App column of the table above says which
 * notices a device that turned them on gets.
 *
 * All hidden in the HTML; the script shows what fits this browser:
 * - push supported: the switch, on when this browser has a subscription (sent to the server again on each visit,
 *   so a renewed one stays reachable);
 * - permission blocked: the line on how to allow it;
 * - an iPhone or iPad outside the Home Screen app: the line on adding it
 *   there first (Safari has push only in the installed app, iOS 16.4+).
 *
 * Every outcome of a tap shows (Owner 2026-11-13: a tap once did nothing
 * visible): the switch waits disabled with its knob pulsing; a question the phone did not
 * show (dismissed, Chrome's quiet permission UI, or no answer in 8 seconds)
 * says where to allow it; blocked shows the blocked line; an error shows the
 * failed line with the error's name, for a report. A service worker not
 * ready after 5 seconds still shows the switch, and a tap says to reload.
 *
 * Turning them on here with Telegram linked and the question never asked
 * also asks where notifications should come (signup-channels.php).
 *
 * The page is only shown signed in, which is never cached (the CSRF token).
 *
 * Expects $eventcrew_ajax and $eventcrew_csrf from signup.php.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/** @var string $eventcrew_ajax */
/** @var string $eventcrew_csrf */

$eventcrew_push_key = \EventCrew\Support\VapidKeys::publicKey();

if ('' === $eventcrew_push_key) {
    return;
}
?>
<div class="eventcrew-push eventcrew-card-body" data-eventcrew-push
    data-ajax="<?php echo esc_url($eventcrew_ajax); ?>"
    data-action="<?php echo esc_attr(\EventCrew\Web\PushController::ACTION); ?>"
    data-csrf="<?php echo esc_attr($eventcrew_csrf); ?>"
    data-key="<?php echo esc_attr($eventcrew_push_key); ?>">
    <p class="eventcrew-push-row" data-eventcrew-push-toggle hidden>
        <button type="button" class="eventcrew-switch" role="switch" aria-checked="false" data-eventcrew-push-on>
            <span class="eventcrew-switch-label"><?php esc_html_e('Notifications on this device', 'eventcrew'); ?></span>
            <span class="eventcrew-switch-track" aria-hidden="true"><span class="eventcrew-switch-knob"></span></span>
        </button>
    </p>
    <p class="eventcrew-muted" data-eventcrew-push-blocked hidden><?php esc_html_e('Notifications are blocked for this site. Allow them in the browser settings.', 'eventcrew'); ?></p>
    <p class="eventcrew-muted" data-eventcrew-push-ios hidden><?php esc_html_e('On iPhone, notifications work after you add this page to your Home Screen.', 'eventcrew'); ?></p>
    <p class="eventcrew-muted" data-eventcrew-push-dismissed hidden><?php esc_html_e("Your phone did not show the question. Allow notifications for this site in the browser's site settings, then try again.", 'eventcrew'); ?></p>
    <p class="eventcrew-muted" data-eventcrew-push-loading hidden><?php esc_html_e('The app is still loading. Reload the page and try again.', 'eventcrew'); ?></p>
    <p class="eventcrew-muted" data-eventcrew-push-stuck hidden><?php esc_html_e("The browser did not finish turning on notifications. In the phone's Settings → Apps → your browser → Notifications, allow them, then try again.", 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>
    <p class="eventcrew-muted" data-eventcrew-push-failed hidden><?php esc_html_e('Could not turn on notifications. Try again.', 'eventcrew'); ?> <span class="eventcrew-push-error" data-eventcrew-push-error></span></p>
</div>
<script>
(function () {
    var box = document.querySelector('[data-eventcrew-push]');
    if (!box) { return; }
    var part = function (name) { return box.querySelector('[data-eventcrew-push-' + name + ']'); };
    var toggle = part('toggle'), on = part('on');
    var isOn = function () { return 'true' === on.getAttribute('aria-checked'); };
    var ua = navigator.userAgent || '';
    var ios = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
    var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
        || true === window.navigator.standalone;

    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        if (ios && !standalone) { part('ios').hidden = false; }
        return;
    }

    var key = function () {
        var s = box.dataset.key.replace(/-/g, '+').replace(/_/g, '/');
        var raw = atob(s + '='.repeat((4 - s.length % 4) % 4));
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
        return out;
    };
    var post = function (op, sub) {
        var j = sub.toJSON();
        var data = new FormData();
        data.append('action', box.dataset.action);
        data.append('csrf', box.dataset.csrf);
        data.append('op', op);
        data.append('endpoint', j.endpoint || '');
        data.append('p256dh', (j.keys && j.keys.p256dh) || '');
        data.append('auth', (j.keys && j.keys.auth) || '');
        return fetch(box.dataset.ajax, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) { throw new Error(String(r.status)); } return r; });
    };
    // The lines under the switch: at most one of these shows at a time.
    var lines = ['failed', 'dismissed', 'loading', 'stuck'];
    var only = function (name) {
        lines.forEach(function (n) { part(n).hidden = n !== name; });
    };
    var show = function (subscribed) {
        only('');
        part('blocked').hidden = 'denied' !== Notification.permission;
        toggle.hidden = 'denied' === Notification.permission && !subscribed;
        on.setAttribute('aria-checked', subscribed ? 'true' : 'false');
    };
    // Waiting: the switch disabled, its knob pulsing.
    var busy = function (link, waiting) {
        link.disabled = waiting;
        link.classList.toggle('is-busy', waiting);
        link.setAttribute('aria-busy', waiting ? 'true' : 'false');
    };
    // The failed line, with the error's name ("(NotAllowedError)") to report.
    var failed = function (err) {
        var name = err && err.name ? String(err.name) : '';
        if ('Error' === name && err.message) { name += ' ' + String(err.message); }
        part('error').textContent = name ? '(' + name + ')' : '';
        busy(on, false);
        only('failed');
    };

    var reg = null;

    // The Crew app's service worker. Not ready in 5 seconds: the switch shows
    // anyway, and a tap says to reload (below), rather than nothing at all.
    var notReady = setTimeout(function () { if (!reg) { show(false); } }, 5000);
    navigator.serviceWorker.ready.then(function (ready) {
        clearTimeout(notReady);
        reg = ready;
        only('');
        reg.pushManager.getSubscription().then(function (sub) {
            show(!!sub);
            if (sub && 'granted' === Notification.permission) { post('subscribe', sub).catch(function () {}); }
        });
    });

    var turnOff = function () {
        busy(on, true);
        reg.pushManager.getSubscription().then(function (sub) {
            if (!sub) { return null; }
            return post('unsubscribe', sub).catch(function () {}).then(function () { return sub.unsubscribe(); });
        }).then(function () { busy(on, false); show(false); }).catch(failed);
    };

    on.addEventListener('click', function () {
        if (on.disabled) { return; }
        if (!reg) { only('loading'); return; }
        only('');
        if (isOn()) { turnOff(); return; }
        busy(on, true);
        // No answer in 8 seconds: the phone did not show the question.
        var answered = false;
        var wait = setTimeout(function () {
            if (answered) { return; }
            busy(on, false);
            only('dismissed');
        }, 8000);
        var asked;
        try {
            // Old Safari answers only through a callback and returns nothing.
            asked = Promise.resolve(Notification.requestPermission()).then(function (p) { return p || Notification.permission; });
        } catch (err) {
            asked = Promise.reject(err);
        }
        asked.then(function (permission) {
            answered = true;
            clearTimeout(wait);
            if ('granted' !== permission) {
                busy(on, false);
                show(false);
                // 'denied' shows the blocked line (show); 'default' is a
                // question dismissed or never shown.
                if ('denied' !== permission) { only('dismissed'); }
                return null;
            }
            only('');
            busy(on, true);
            // Some browsers (Samsung Internet without Android's permission for
            // the browser app) never answer subscribe(): stop after 5 seconds.
            return new Promise(function (resolve, reject) {
                var stuck = setTimeout(function () {
                    var err = new Error('subscribe');
                    err.name = 'Timeout';
                    reject(err);
                }, 5000);
                reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key() }).then(
                    function (sub) { clearTimeout(stuck); resolve(sub); },
                    function (err) { clearTimeout(stuck); reject(err); }
                );
            })
                .then(function (sub) { return post('subscribe', sub); })
                .then(function (r) { return r.json().catch(function () { return {}; }); })
                .then(function (body) {
                    busy(on, false);
                    show(true);
                    // Telegram linked, not asked yet: "Where do you want
                    // them?" (signup-channels.php).
                    if (body && body.ask_channels) { document.dispatchEvent(new CustomEvent('eventcrew:ask-channels')); }
                });
        }).catch(function (err) {
            answered = true;
            clearTimeout(wait);
            if (err && 'Timeout' === err.name) {
                busy(on, false);
                only('stuck');
                return;
            }
            failed(err);
        });
    });

})();
</script>

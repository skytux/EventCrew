<?php

/**
 * App notifications (Web Push) for this device, in the Notifications card:
 * one text link that turns them on or off here, and the line that says why
 * it cannot when it cannot. The App column of the table above says which
 * notices a device that turned them on gets.
 *
 * All hidden in the HTML; the script shows what fits this browser:
 * - push supported: the link, as Turn on or Turn off by whether this
 *   browser has a subscription (sent to the server again on each visit,
 *   so a renewed one stays reachable);
 * - permission blocked: the line on how to allow it;
 * - an iPhone or iPad outside the Home Screen app: the line on adding it
 *   there first (Safari has push only in the installed app, iOS 16.4+).
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
    <p class="eventcrew-hint" data-eventcrew-push-toggle hidden>
        <button type="button" class="eventcrew-textlink" data-eventcrew-push-on><?php esc_html_e('Turn on notifications on this device', 'eventcrew'); ?></button>
        <button type="button" class="eventcrew-textlink" data-eventcrew-push-off hidden><?php esc_html_e('Turn off notifications on this device', 'eventcrew'); ?></button>
    </p>
    <p class="eventcrew-muted" data-eventcrew-push-blocked hidden><?php esc_html_e('Notifications are blocked for this site. Allow them in the browser settings.', 'eventcrew'); ?></p>
    <p class="eventcrew-muted" data-eventcrew-push-ios hidden><?php esc_html_e('On iPhone, notifications work after you add this page to your Home Screen.', 'eventcrew'); ?></p>
    <p class="eventcrew-muted" data-eventcrew-push-failed hidden><?php esc_html_e('Could not turn on notifications. Try again.', 'eventcrew'); ?></p>
</div>
<script>
(function () {
    var box = document.querySelector('[data-eventcrew-push]');
    if (!box) { return; }
    var part = function (name) { return box.querySelector('[data-eventcrew-push-' + name + ']'); };
    var toggle = part('toggle'), on = part('on'), off = part('off');
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
    var show = function (subscribed) {
        part('failed').hidden = true;
        part('blocked').hidden = 'denied' !== Notification.permission;
        toggle.hidden = 'denied' === Notification.permission && !subscribed;
        on.hidden = subscribed;
        off.hidden = !subscribed;
    };
    var failed = function () {
        part('failed').hidden = false;
        on.disabled = false;
        off.disabled = false;
    };

    // The Crew app's service worker; on a page without one nothing shows.
    navigator.serviceWorker.ready.then(function (reg) {
        reg.pushManager.getSubscription().then(function (sub) {
            show(!!sub);
            if (sub && 'granted' === Notification.permission) { post('subscribe', sub).catch(function () {}); }
        });

        on.addEventListener('click', function () {
            on.disabled = true;
            Notification.requestPermission().then(function (permission) {
                if ('granted' !== permission) { on.disabled = false; show(false); return null; }
                return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key() })
                    .then(function (sub) { return post('subscribe', sub); })
                    .then(function () { on.disabled = false; show(true); });
            }).catch(failed);
        });

        off.addEventListener('click', function () {
            off.disabled = true;
            reg.pushManager.getSubscription().then(function (sub) {
                if (!sub) { return null; }
                return post('unsubscribe', sub).catch(function () {}).then(function () { return sub.unsubscribe(); });
            }).then(function () { off.disabled = false; show(false); }).catch(failed);
        });
    });
})();
</script>

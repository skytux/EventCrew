<?php

/**
 * "Where do you want them?" (ChannelChoice, Owner 2026-11-13): a small
 * modal card for a signed-in person with Telegram linked who gets app
 * notifications too, with three buttons, App only, Telegram only and Both,
 * and a close ×. Asked once:
 * - right after they turn app notifications on, on the Notifications card
 *   (signup-push.php sends the eventcrew:ask-channels event when the server
 *   says the question was never asked);
 * - or else on a visit to the page while it is due (Telegram linked, a
 *   device with app notifications on, never asked), a moment after the page
 *   loads, unless the "Get the app" pop-in showed on this visit or another
 *   dialog is open; then it waits for another visit.
 *
 * Opening it stores that it was asked (op=asked), so × or Esc leaves Both;
 * a button stores the answer (op=choose) and shows the short confirmation.
 * The answer is changed later under Notifications, "When both are on".
 *
 * Expects $eventcrew_channels (SignupController::channelChoiceView() or
 * null), $eventcrew_ajax and $eventcrew_csrf from signup.php.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/** @var array{applies: bool, choice: string, due: bool, asked: bool, telegram: bool, action: string}|null $eventcrew_channels */
/** @var string $eventcrew_ajax */
/** @var string $eventcrew_csrf */

// Without Telegram there is nothing to choose between.
if (null === $eventcrew_channels || ! $eventcrew_channels['telegram'] || $eventcrew_channels['asked']) {
    return;
}
?>
<div class="eventcrew-channels-box" data-eventcrew-channels
    data-ajax="<?php echo esc_url($eventcrew_ajax); ?>"
    data-action="<?php echo esc_attr($eventcrew_channels['action']); ?>"
    data-csrf="<?php echo esc_attr($eventcrew_csrf); ?>"
    data-due="<?php echo $eventcrew_channels['due'] ? '1' : '0'; ?>">
<dialog class="eventcrew-app-dialog" aria-labelledby="eventcrew-channels-question">
    <div class="eventcrew-app-card eventcrew-channels-card">
        <button type="button" class="eventcrew-app-close" data-eventcrew-channels-close
            aria-label="<?php esc_attr_e('Close', 'eventcrew'); ?>"><span aria-hidden="true">×</span></button>
        <div class="eventcrew-app-step">
            <p id="eventcrew-channels-question"><?php echo esc_html(\EventCrew\Support\ChannelChoice::question()); ?></p>
            <div class="eventcrew-app-actions" data-eventcrew-channels-buttons>
                <?php foreach (\EventCrew\Support\ChannelChoice::labels() as $eventcrew_channel_value => $eventcrew_channel_label) : ?>
                    <button type="button" class="eventcrew-settings-btn" data-eventcrew-channels-choice="<?php echo esc_attr($eventcrew_channel_value); ?>"><?php echo esc_html($eventcrew_channel_label); ?></button>
                <?php endforeach; ?>
            </div>
            <p role="status" data-eventcrew-channels-done hidden></p>
        </div>
    </div>
</dialog>
</div>
<script>
(function () {
    var box = document.querySelector('[data-eventcrew-channels]');
    var dialog = box && box.querySelector('dialog');
    if (!dialog || 'function' !== typeof dialog.showModal) { return; }
    var buttons = box.querySelector('[data-eventcrew-channels-buttons]');
    var done = box.querySelector('[data-eventcrew-channels-done]');
    var asked = false, returnTo = null;

    var post = function (op, choice) {
        var data = new FormData();
        data.append('action', box.dataset.action);
        data.append('csrf', box.dataset.csrf);
        data.append('op', op);
        if (choice) { data.append('choice', choice); }
        return fetch(box.dataset.ajax, { method: 'POST', body: data, credentials: 'same-origin', keepalive: true });
    };
    var open = function () {
        if (asked || dialog.open) { return; }
        asked = true;
        post('asked').catch(function () {});
        returnTo = document.activeElement;
        dialog.showModal();
        var first = buttons.querySelector('button');
        if (first) { first.focus(); }
    };
    dialog.addEventListener('close', function () {
        if (returnTo && returnTo.focus && document.contains(returnTo)) { returnTo.focus({ preventScroll: true }); }
    });
    box.querySelector('[data-eventcrew-channels-close]').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (e) { if (e.target === dialog) { dialog.close(); } });
    [].forEach.call(box.querySelectorAll('[data-eventcrew-channels-choice]'), function (b) {
        b.addEventListener('click', function () {
            var choice = b.getAttribute('data-eventcrew-channels-choice');
            [].forEach.call(buttons.querySelectorAll('button'), function (x) { x.disabled = true; });
            post('choose', choice)
                .then(function (r) { return r.json(); })
                .then(function (body) {
                    if (!body || !body.ok) { throw new Error('choice'); }
                    // The Notifications card's "When both are on" row, if shown.
                    var radio = document.querySelector('input[name="channels"][value="' + choice + '"]');
                    if (radio) { radio.checked = true; }
                    buttons.hidden = true;
                    done.textContent = body.text || '';
                    done.hidden = false;
                    setTimeout(function () { dialog.close(); }, 1800);
                })
                .catch(function () {
                    [].forEach.call(buttons.querySelectorAll('button'), function (x) { x.disabled = false; });
                });
        });
    });

    // Right after app notifications went on here (signup-push.php).
    document.addEventListener('eventcrew:ask-channels', open);

    if ('1' !== box.dataset.due) { return; }
    var later = function () {
        setTimeout(function () {
            var app = document.querySelector('[data-eventcrew-app]');
            // The "Get the app" pop-in had this visit; one pop-in per visit.
            if (app && '1' === app.dataset.shown) { return; }
            if (document.querySelector('dialog[open]')) { return; }
            open();
        }, 1500);
    };
    if ('complete' === document.readyState) { later(); } else { window.addEventListener('load', later); }
})();
</script>

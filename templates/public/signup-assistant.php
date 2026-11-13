<?php

/**
 * The helper box of the Crew app page (Owner 2026-11-12), under the tabs,
 * for a signed-in person only, and only while the bot's language-model
 * helper is on: one line to write (or speak) what you want to do. The
 * answer opens in a pop-in with the conversation of this page visit and a
 * line to write more.
 *
 * Asked through admin-ajax (AssistantWebController) with the page's CSRF
 * token. What the model wrote is shown with textContent and link hrefs
 * only, never as HTML. Signing up for or cancelling a task shows Confirm
 * and Cancel first; something the page has opens that tab (and Admin
 * screen, for an organizer); a credit spent here gets one Open my ticket
 * button (Lou 2026-11-14); anything else gets no button. The page never
 * offers to go to Telegram (Owner 2026-11-13).
 *
 * Speaking uses the browser's own speech recognition (the mic is shown only
 * where there is one); the browser's speech service turns the voice into
 * text, the page never sends sound anywhere. A one-line note says so on the
 * first use.
 *
 * Expects $eventcrew_assistant (AssistantWebController::view() or null),
 * $eventcrew_ajax and $eventcrew_csrf from signup.php.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/** @var array{action: string, speech: string}|null $eventcrew_assistant */
/** @var string $eventcrew_ajax */
/** @var string $eventcrew_csrf */

if (null === $eventcrew_assistant) {
    return;
}

$eventcrew_ai_placeholder = __('Tell me what you want to do…', 'eventcrew');
$eventcrew_ai_max = \EventCrew\Web\AssistantWebController::MAX_CHARS;

/*
 * One line with a send and a mic button in it; drawn twice, under the tabs
 * and at the foot of the pop-in.
 */
$eventcrew_ai_line = static function (string $where) use ($eventcrew_ai_placeholder, $eventcrew_ai_max): void {
    ?>
    <form class="eventcrew-ai-line" data-eventcrew-ai-form="<?php echo esc_attr($where); ?>">
        <span class="eventcrew-ai-icon" aria-hidden="true">
            <img src="<?php echo esc_url(plugins_url('assets/images/bot-icon.webp', EVENTCREW_PLUGIN_FILE)); ?>" width="28" height="28" alt="" decoding="async">
        </span>
        <input type="text" class="eventcrew-ai-input" name="text" maxlength="<?php echo (int) $eventcrew_ai_max; ?>"
            autocomplete="off" enterkeyhint="send"
            placeholder="<?php echo esc_attr($eventcrew_ai_placeholder); ?>"
            aria-label="<?php echo esc_attr($eventcrew_ai_placeholder); ?>">
        <button type="button" class="eventcrew-ai-btn eventcrew-ai-mic" data-eventcrew-ai-mic hidden aria-pressed="false"
            aria-label="<?php esc_attr_e('Speak', 'eventcrew'); ?>" title="<?php esc_attr_e('Speak', 'eventcrew'); ?>"><span aria-hidden="true">🎤</span></button>
        <button type="submit" class="eventcrew-ai-btn eventcrew-ai-send"
            aria-label="<?php esc_attr_e('Send', 'eventcrew'); ?>" title="<?php esc_attr_e('Send', 'eventcrew'); ?>">
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M4 12 20 4l-6 16-2.5-6.5L4 12Z"/></svg>
        </button>
    </form>
    <?php
};
?>
<div class="eventcrew-ai" data-eventcrew-ai
    data-ajax="<?php echo esc_url($eventcrew_ajax); ?>"
    data-action="<?php echo esc_attr($eventcrew_assistant['action']); ?>"
    data-csrf="<?php echo esc_attr($eventcrew_csrf); ?>"
    data-speech="<?php echo esc_attr($eventcrew_assistant['speech']); ?>"
    data-thinking="<?php esc_attr_e('Thinking…', 'eventcrew'); ?>"
    data-error="<?php esc_attr_e('Sorry, I could not answer just now. Please try again.', 'eventcrew'); ?>"
    <?php /* translators: %s: a tab of the page, like "Settings". */ ?>
    data-goto="<?php esc_attr_e('Go to %s', 'eventcrew'); ?>">
    <?php $eventcrew_ai_line('box'); ?>
    <p class="eventcrew-ai-note" data-eventcrew-ai-note hidden><?php esc_html_e('Your browser’s speech service (Google or Apple) turns your voice into text.', 'eventcrew'); ?></p>
    <dialog class="eventcrew-app-dialog eventcrew-ai-dialog" aria-labelledby="eventcrew-ai-title">
        <div class="eventcrew-app-card eventcrew-ai-card">
            <button type="button" class="eventcrew-app-close" data-eventcrew-ai-close
                aria-label="<?php esc_attr_e('Close', 'eventcrew'); ?>"><span aria-hidden="true">×</span></button>
            <h2 class="eventcrew-app-title eventcrew-ai-title" id="eventcrew-ai-title"><?php esc_html_e('Assistant', 'eventcrew'); ?></h2>
            <div class="eventcrew-ai-log" data-eventcrew-ai-log aria-live="polite"></div>
            <?php $eventcrew_ai_line('dialog'); ?>
        </div>
    </dialog>
</div>
<script>
(function () {
    var box = document.querySelector('[data-eventcrew-ai]');
    var dialog = box && box.querySelector('dialog');
    var log = box && box.querySelector('[data-eventcrew-ai-log]');
    if (!dialog || !log || !window.fetch || 'function' !== typeof dialog.showModal) { if (box) { box.hidden = true; } return; }
    var busy = false, reload = false, returnTo = null;
    var Speech = window.SpeechRecognition || window.webkitSpeechRecognition;

    var el = function (tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (null != text) { n.textContent = text; }
        return n;
    };
    // The answer as text and links: never HTML (AssistantWebActions::segments()).
    var fill = function (node, segments) {
        (segments || []).forEach(function (s) {
            if (s && 'string' === typeof s.u && /^https?:\/\//i.test(s.u)) {
                var a = el('a', null, String(s.t || s.u));
                a.href = s.u;
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                node.appendChild(a);
            } else if (s && null != s.t) {
                node.appendChild(document.createTextNode(String(s.t)));
            }
        });
        return node;
    };
    var bubble = function (who, text) {
        var b = el('div', 'eventcrew-ai-msg is-' + who, text);
        log.appendChild(b);
        b.scrollIntoView({ block: 'nearest' });
        return b;
    };
    var open = function () {
        if (dialog.open) { return; }
        returnTo = document.activeElement;
        dialog.showModal();
    };
    var post = function (fields) {
        var data = new FormData();
        data.append('action', box.dataset.action);
        data.append('csrf', box.dataset.csrf);
        Object.keys(fields).forEach(function (k) { data.append(k, fields[k]); });
        var stop = window.AbortController ? new AbortController() : null;
        var timer = stop ? setTimeout(function () { stop.abort(); }, 40000) : 0;
        return fetch(box.dataset.ajax, { method: 'POST', body: data, credentials: 'same-origin', signal: stop ? stop.signal : undefined })
            .then(function (r) { return r.json(); })
            .then(function (json) { clearTimeout(timer); if (!json || !json.ok) { throw new Error('answer'); } return json; })
            .catch(function (e) { clearTimeout(timer); throw e; });
    };
    var failed = function (holder) { holder.textContent = box.dataset.error; holder.classList.remove('is-thinking'); };

    // "Admin → Roster" for a card on one of the Admin tab's screens.
    var tabName = function (target) {
        var panel = target.closest('[data-eventcrew-panel]');
        // Outside every tab (Open sales desk at the top): its own words.
        if (!panel) { return target.textContent.replace(/\s+/g, ' ').trim(); }
        var tab = panel && document.getElementById('eventcrew-tab-' + panel.getAttribute('data-eventcrew-panel'));
        var name = tab ? tab.textContent.trim() : '';
        var sub = target.closest('[data-eventcrew-subpanel]');
        var subTab = sub && document.querySelector('[data-eventcrew-subtab="' + sub.getAttribute('data-eventcrew-subpanel') + '"]');
        return subTab ? name + ' → ' + subTab.textContent.trim() : name;
    };
    var act = function (holder, action) {
        if (!action) { return; }
        var row = el('div', 'eventcrew-ai-actions');
        if ('goto' === action.kind) {
            var target = null;
            (action.targets || []).some(function (id) { target = document.getElementById(id); return !!target; });
            if (!target) { return; }
            var go = el('button', 'eventcrew-ai-choice', box.dataset['goto'].replace('%s', tabName(target)));
            go.type = 'button';
            var query = action.query && 'object' === typeof action.query ? action.query : null;
            if (query && window.URL) {
                // A screen that reads the page address (the Roster's date, the
                // People search): the page loads again with it, on that card.
                go.addEventListener('click', function () {
                    var to = new URL(window.location.href);
                    Object.keys(query).forEach(function (k) { to.searchParams.set(k, String(query[k])); });
                    to.hash = target.id;
                    window.location.assign(to.toString());
                });
            } else {
                go.setAttribute('data-eventcrew-goto', target.id);
                // Closes first; the click then reaches the page's own handler,
                // which opens the tab and scrolls to the card.
                go.addEventListener('click', function () { returnTo = null; dialog.close(); });
            }
            row.appendChild(go);
        } else if ('confirm' === action.kind) {
            if (action.summary) {
                holder.appendChild(el('p', 'eventcrew-ai-summary', '➡️ ' + action.summary));
            }
            var yes = el('button', 'eventcrew-ai-choice', action.confirm);
            var no = el('button', 'eventcrew-ai-choice', action.cancel);
            yes.type = no.type = 'button';
            var answer = function (op) {
                yes.disabled = no.disabled = true;
                var reply = bubble('bot', box.dataset.thinking);
                reply.classList.add('is-thinking');
                post({ op: op, id: action.id }).then(function (json) {
                    reply.textContent = '';
                    reply.classList.remove('is-thinking');
                    fill(reply, json.reply);
                    if (json.changed) { reload = true; }
                    var t = json.action;
                    // A credit just spent: one button that opens the ticket,
                    // as the Tickets tab's Ticket link does (a new tab).
                    if (t && 'ticket' === t.kind && 'string' === typeof t.url && /^https?:\/\//i.test(t.url)) {
                        var show = el('button', 'eventcrew-ai-choice', String(t.label || ''));
                        show.type = 'button';
                        show.addEventListener('click', function () {
                            var opened = window.open(t.url, '_blank');
                            if (opened) { opened.opener = null; } else { window.location.assign(t.url); }
                        });
                        var line = el('div', 'eventcrew-ai-actions');
                        line.appendChild(show);
                        reply.appendChild(line);
                    }
                }, function () { failed(reply); });
            };
            yes.addEventListener('click', function () { answer('confirm'); });
            no.addEventListener('click', function () { answer('cancel'); });
            row.appendChild(yes);
            row.appendChild(no);
        } else {
            return;
        }
        holder.appendChild(row);
    };

    var ask = function (form) {
        var input = form.querySelector('input');
        var text = input.value.trim();
        if (!text || busy) { return; }
        busy = true;
        input.value = '';
        open();
        bubble('person', text);
        var reply = bubble('bot', box.dataset.thinking);
        reply.classList.add('is-thinking');
        post({ op: 'ask', text: text }).then(function (json) {
            reply.textContent = '';
            reply.classList.remove('is-thinking');
            fill(reply, json.reply);
            act(reply, json.action);
            reply.scrollIntoView({ block: 'nearest' });
        }, function () { failed(reply); }).then(function () {
            busy = false;
            var next = dialog.querySelector('input');
            if (next && dialog.open) { next.focus(); }
        });
    };

    [].forEach.call(box.querySelectorAll('[data-eventcrew-ai-form]'), function (form) {
        form.addEventListener('submit', function (e) { e.preventDefault(); ask(form); });
        var mic = form.querySelector('[data-eventcrew-ai-mic]');
        if (!Speech || !mic) { return; }
        mic.hidden = false;
        var heard = null;
        mic.addEventListener('click', function () {
            if (heard) { heard.stop(); return; }
            var note = box.querySelector('[data-eventcrew-ai-note]');
            try {
                if (!window.localStorage.getItem('eventcrew-ai-speech-note')) {
                    if (note && 'box' === form.getAttribute('data-eventcrew-ai-form')) { note.hidden = false; }
                    window.localStorage.setItem('eventcrew-ai-speech-note', '1');
                }
            } catch (err) { if (note) { note.hidden = false; } }
            var input = form.querySelector('input');
            heard = new Speech();
            heard.lang = box.dataset.speech;
            heard.interimResults = true;
            heard.continuous = false;
            heard.onresult = function (event) {
                var said = '';
                for (var i = 0; i < event.results.length; i++) { said += event.results[i][0].transcript; }
                input.value = said.slice(0, input.maxLength > 0 ? input.maxLength : 500);
            };
            heard.onend = function () {
                heard = null;
                mic.setAttribute('aria-pressed', 'false');
                mic.classList.remove('is-on');
                input.focus();
            };
            heard.onerror = heard.onend;
            mic.setAttribute('aria-pressed', 'true');
            mic.classList.add('is-on');
            try { heard.start(); } catch (err) { heard.onend(); }
        });
    });

    box.querySelector('[data-eventcrew-ai-close]').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (e) { if (e.target === dialog) { dialog.close(); } });
    dialog.addEventListener('close', function () {
        // A sign-up or cancel changed the board: show it as it is now.
        if (reload) { window.location.reload(); return; }
        if (returnTo && returnTo.focus && document.contains(returnTo)) { returnTo.focus({ preventScroll: true }); }
    });
})();
</script>

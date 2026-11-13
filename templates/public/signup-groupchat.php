<?php

/**
 * The Group chat tab of the Crew app: the crew's Telegram group, read and
 * written on the web (Telegram\GroupRelay).
 *
 * The messages are never in the HTML: the script loads them from
 * Web\GroupChatController with the person's session and CSRF token, when
 * the tab opens and every POLL seconds while it is open and the page is
 * visible, so no cached page ever holds a group message. What is written
 * here the bot posts to the group as "Name (web): text".
 *
 * It sits in a card like the Tasks and Tickets cards and reads like a
 * messaging app: bubbles, the person's own on the right in the accent
 * colour, a day line between days, the list scrolling inside the card and
 * the composer at its bottom.
 *
 * Shown signed in only, when GroupRelay::visibleTo() the person; a page
 * signed in is never cached (the CSRF token).
 *
 * Expects $eventcrew_person, $eventcrew_ajax and $eventcrew_csrf from
 * signup.php.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/** @var \EventCrew\Models\Person $eventcrew_person */
/** @var string $eventcrew_ajax */
/** @var string $eventcrew_csrf */

$eventcrew_chat_text = [
    'web' => __('(web)', 'eventcrew'),
    'edited' => __('edited', 'eventcrew'),
    'reply' => __('Reply', 'eventcrew'),
    'hide' => __('Hide', 'eventcrew'),
    'hideAsk' => __('Hide this message? A message sent from the web is also deleted in the group.', 'eventcrew'),
    'empty' => __('No messages in the last 30 days.', 'eventcrew'),
    'today' => __('Today', 'eventcrew'),
    'yesterday' => __('Yesterday', 'eventcrew'),
    'loadFailed' => __('The messages could not be loaded. Trying again soon.', 'eventcrew'),
    'name' => __('Add your name in Settings first: the group sees it with your message.', 'eventcrew'),
    'rate' => __('That is many messages in a short time. Wait a few minutes.', 'eventcrew'),
    /* translators: %d: the most characters a message can have */
    'long' => sprintf(__('That is too long: at most %d characters.', 'eventcrew'), \EventCrew\Telegram\GroupRelay::MAX_LENGTH),
    'session' => __('You are signed out. Reload the page.', 'eventcrew'),
    'failed' => __('Something went wrong. Please try again.', 'eventcrew'),
];
?>
<section class="eventcrew-panel eventcrew-groupchat" id="eventcrew-panel-chat" data-eventcrew-panel="chat" aria-labelledby="eventcrew-tab-chat"
    data-eventcrew-chat
    data-ajax="<?php echo esc_url($eventcrew_ajax); ?>"
    data-action="<?php echo esc_attr(\EventCrew\Web\GroupChatController::ACTION); ?>"
    data-csrf="<?php echo esc_attr($eventcrew_csrf); ?>"
    data-organizer="<?php echo $eventcrew_person->isOrganizer ? '1' : '0'; ?>"
    data-text="<?php echo esc_attr((string) wp_json_encode($eventcrew_chat_text)); ?>">
    <h2 class="eventcrew-panel-title"><?php esc_html_e('Group chat', 'eventcrew'); ?></h2>
    <section class="eventcrew-event eventcrew-me-card eventcrew-chat-card" aria-labelledby="eventcrew-chat-title">
        <header class="eventcrew-event-head">
            <h3 class="eventcrew-event-title" id="eventcrew-chat-title"><?php esc_html_e('Group chat', 'eventcrew'); ?></h3>
        </header>
        <p class="eventcrew-chat-intro" data-eventcrew-chat-intro><?php esc_html_e('The crew’s Telegram group. What you send here, the bot posts to the group with your name.', 'eventcrew'); ?></p>
        <noscript><p class="eventcrew-chat-intro"><?php esc_html_e('The group chat needs JavaScript.', 'eventcrew'); ?></p></noscript>
        <div class="eventcrew-chat-viewport">
            <ol class="eventcrew-chat-list" data-eventcrew-chat-list role="log" aria-live="polite"
                aria-labelledby="eventcrew-chat-title" tabindex="0"></ol>
            <p class="eventcrew-chat-empty" data-eventcrew-chat-empty hidden></p>
            <button type="button" class="eventcrew-chat-newpill" data-eventcrew-chat-new hidden>
                <?php esc_html_e('New messages', 'eventcrew'); ?> <span aria-hidden="true">↓</span>
            </button>
        </div>
        <form class="eventcrew-chat-form" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>" data-eventcrew-chat-form hidden>
            <input type="hidden" name="action" value="<?php echo esc_attr(\EventCrew\Web\GroupChatController::ACTION); ?>">
            <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
            <input type="hidden" name="op" value="post">
            <input type="hidden" name="reply_to" value="0">
            <p class="eventcrew-chat-error" data-eventcrew-chat-error role="status" hidden></p>
            <div class="eventcrew-chat-replying" data-eventcrew-chat-replying hidden>
                <span class="eventcrew-chat-replying-to" data-eventcrew-chat-replying-to></span>
                <button type="button" class="eventcrew-chat-unreply" data-eventcrew-chat-unreply
                    aria-label="<?php esc_attr_e('Cancel', 'eventcrew'); ?>"><span aria-hidden="true">×</span></button>
            </div>
            <div class="eventcrew-chat-compose">
                <label class="screen-reader-text" for="eventcrew-chat-text"><?php esc_html_e('Message', 'eventcrew'); ?></label>
                <textarea id="eventcrew-chat-text" name="text" rows="1" class="eventcrew-chat-field"
                    placeholder="<?php esc_attr_e('Message', 'eventcrew'); ?>"
                    maxlength="<?php echo (int) \EventCrew\Telegram\GroupRelay::MAX_LENGTH; ?>" required></textarea>
                <button type="submit" class="eventcrew-chat-send"
                    aria-label="<?php esc_attr_e('Send', 'eventcrew'); ?>" title="<?php esc_attr_e('Send', 'eventcrew'); ?>">
                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">
                        <path fill="currentColor" d="M3.4 20.4 20.85 12.9a1 1 0 0 0 0-1.8L3.4 3.6a.99.99 0 0 0-1.39.91L2 9.12
                            c0 .5.37.93.87.99L17 12 2.87 13.88c-.5.07-.87.5-.87 1l.01 4.61c0 .71.73 1.2 1.39.91z"/>
                    </svg>
                </button>
            </div>
        </form>
    </section>
</section>
<script>
(function () {
    var box = document.querySelector('[data-eventcrew-chat]');
    if (!box || !window.fetch || !window.FormData) { return; }
    var POLL = 15000;
    var text = JSON.parse(box.getAttribute('data-text') || '{}');
    var organizer = '1' === box.getAttribute('data-organizer');
    var list = box.querySelector('[data-eventcrew-chat-list]');
    var empty = box.querySelector('[data-eventcrew-chat-empty]');
    var pill = box.querySelector('[data-eventcrew-chat-new]');
    var form = box.querySelector('[data-eventcrew-chat-form]');
    var error = box.querySelector('[data-eventcrew-chat-error]');
    var replying = box.querySelector('[data-eventcrew-chat-replying]');
    var replyingTo = box.querySelector('[data-eventcrew-chat-replying-to]');
    var field = form.querySelector('textarea');
    var send = form.querySelector('[type="submit"]');
    var replyField = form.querySelector('[name="reply_to"]');
    // The intro goes to the top of the list, as a note the messages follow.
    var intro = box.querySelector('[data-eventcrew-chat-intro]');
    var note = intro ? intro.textContent : '';
    if (intro) { intro.hidden = true; }
    var shown = '';
    var lastId = 0;
    var timer = null;
    var busy = false;
    // What a photo, a file and the rest show in their chip (Telegram's own kinds).
    var ICONS = {
        photo: '📷', video: '🎬', video_note: '🎬', animation: '🎞️', sticker: '🏷️',
        voice: '🎤', audio: '🎵', document: '📎', poll: '📊', location: '📍', venue: '📍', contact: '👤'
    };

    form.hidden = false;

    function el(tag, className, content) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (content) { node.textContent = content; }
        return node;
    }

    // The text with its web addresses as links; everything else stays text.
    function linked(parent, value) {
        var parts = String(value).split(/(https?:\/\/[^\s<>"]+)/);
        parts.forEach(function (part, i) {
            if (i % 2 === 1) {
                var a = el('a', '', part);
                a.href = part;
                a.rel = 'noopener nofollow ugc';
                a.target = '_blank';
                parent.appendChild(a);
            } else if (part) {
                parent.appendChild(document.createTextNode(part));
            }
        });
    }

    function call(data) {
        data.append('action', box.getAttribute('data-action'));
        data.append('csrf', box.getAttribute('data-csrf'));
        return fetch(box.getAttribute('data-ajax'), { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (response) {
                return response.json().catch(function () { return { ok: false, error: 'failed' }; });
            });
    }

    function say(code) {
        error.textContent = code ? (text[code] || text.failed) : '';
        error.hidden = !code;
    }

    function grow() {
        field.style.height = 'auto';
        field.style.height = (field.scrollHeight + field.offsetHeight - field.clientHeight) + 'px';
    }

    function reply(message) {
        replyField.value = String(message.id);
        replyingTo.textContent = '';
        replyingTo.appendChild(el('strong', '', message.name));
        replyingTo.appendChild(document.createTextNode(' ' + (message.text || message.kind).slice(0, 80)));
        replying.hidden = false;
        field.focus();
    }

    function unreply() {
        replyField.value = '0';
        replying.hidden = true;
    }

    function hide(message) {
        if (!window.confirm(text.hideAsk)) { return; }
        var data = new FormData();
        data.append('op', 'hide');
        data.append('id', String(message.id));
        call(data).then(function (answer) {
            if (answer.ok) { load(); } else { say(answer.error); }
        }, function () { say('failed'); });
    }

    // "Today", "Yesterday" or the date, by the site's day (answer.today).
    function dayLabel(day, today) {
        if (!day) { return ''; }
        if (day === today) { return text.today; }
        var at = Date.parse(day + 'T00:00:00Z');
        if (today && Date.parse(today + 'T00:00:00Z') - at === 864e5) { return text.yesterday; }
        try {
            return new Date(at).toLocaleDateString(document.documentElement.lang || undefined,
                { weekday: 'short', day: 'numeric', month: 'numeric', timeZone: 'UTC' });
        } catch (e) {
            return day;
        }
    }

    function atBottom() {
        return list.scrollHeight - list.scrollTop - list.clientHeight < 40;
    }

    function toBottom(smooth) {
        if (smooth && list.scrollTo) {
            list.scrollTo({ top: list.scrollHeight, behavior: 'smooth' });
        } else {
            list.scrollTop = list.scrollHeight;
        }
        pill.hidden = true;
    }

    // Same writer as the message before, the same day: no name over it.
    function sameWriter(a, b) {
        return !!a && !!b && a.day === b.day && a.name === b.name && a.mine === b.mine && a.web === b.web;
    }

    function bubble(message, prev, next) {
        var classes = 'eventcrew-chat-msg' + (message.mine ? ' is-mine' : '');
        classes += sameWriter(prev, message) ? ' is-cont' : ' is-first';
        if (!sameWriter(message, next)) { classes += ' is-last'; }
        var item = el('li', classes);
        item.setAttribute('data-id', String(message.id));
        if (!message.mine && !sameWriter(prev, message)) {
            var who = el('p', 'eventcrew-chat-name');
            who.appendChild(el('strong', '', message.name));
            if (message.web) { who.appendChild(el('span', 'eventcrew-chat-web', ' ' + text.web)); }
            item.appendChild(who);
        }
        var inner = el('div', 'eventcrew-chat-bubble');
        // A tap on the bubble shows its Reply (and Hide); hover and focus do on a computer.
        inner.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('a')) { return; }
            var was = item.classList.contains('is-open');
            [].forEach.call(list.querySelectorAll('.is-open'), function (node) { node.classList.remove('is-open'); });
            item.classList.toggle('is-open', !was);
        });
        if (message.reply) {
            var quote = el('blockquote', 'eventcrew-chat-quote');
            quote.appendChild(el('strong', '', message.reply.name));
            quote.appendChild(el('span', '', message.reply.text));
            inner.appendChild(quote);
        }
        if (message.kind) {
            var chip = el('span', 'eventcrew-chat-chip');
            chip.appendChild(el('span', '', ICONS[message.type] || '💬')).setAttribute('aria-hidden', 'true');
            chip.appendChild(document.createTextNode(' ' + message.kind));
            inner.appendChild(chip);
        }
        if (message.text) {
            var body = el('p', 'eventcrew-chat-text');
            linked(body, message.text);
            inner.appendChild(body);
        }
        // The time sits in the bubble's corner; an invisible copy at the end
        // of the last line keeps the text from running under it.
        var stamp = (message.edited ? text.edited + ' · ' : '') + (message.clock || message.time);
        var pad = el('span', 'eventcrew-chat-time-pad', stamp);
        pad.setAttribute('aria-hidden', 'true');
        var last = inner.lastChild;
        (last && 'P' === last.tagName ? last : inner).appendChild(pad);
        var when = el('span', 'eventcrew-chat-time', stamp);
        when.title = message.time;
        inner.appendChild(when);
        item.appendChild(inner);
        var actions = el('p', 'eventcrew-chat-actions');
        var answer = el('button', 'eventcrew-chat-action', text.reply);
        answer.type = 'button';
        answer.addEventListener('click', function () { reply(message); });
        actions.appendChild(answer);
        if (organizer) {
            var remove = el('button', 'eventcrew-chat-action', text.hide);
            remove.type = 'button';
            remove.addEventListener('click', function () { hide(message); });
            actions.appendChild(remove);
        }
        item.appendChild(actions);
        return item;
    }

    function render(messages, today) {
        var first = '' === shown;
        var bottom = atBottom();
        // Off the bottom, the message at the top of the view stays where it is.
        var anchor = null;
        var offset = 0;
        if (!bottom) {
            [].some.call(list.querySelectorAll('[data-id]'), function (node) {
                if (node.offsetTop + node.offsetHeight > list.scrollTop) {
                    anchor = node.getAttribute('data-id');
                    offset = node.offsetTop - list.scrollTop;
                    return true;
                }
                return false;
            });
        }
        var newest = messages.length ? messages[messages.length - 1] : null;
        var arrived = !first && !!newest && newest.id > lastId;
        lastId = newest ? newest.id : 0;

        list.textContent = '';
        if (note && messages.length) { list.appendChild(el('li', 'eventcrew-chat-note', note)); }
        var day = null;
        messages.forEach(function (message, i) {
            if (message.day !== day) {
                day = message.day;
                var label = dayLabel(day, today);
                if (label) {
                    var line = el('li', 'eventcrew-chat-day');
                    line.appendChild(el('span', '', label));
                    list.appendChild(line);
                }
            }
            list.appendChild(bubble(message, messages[i - 1], messages[i + 1]));
        });
        empty.textContent = text.empty;
        if (note) { empty.appendChild(el('small', '', note)); }
        empty.classList.remove('is-error');
        empty.hidden = messages.length > 0;

        if (first || bottom || (arrived && newest.mine)) {
            toBottom(false);
        } else {
            var back = anchor ? list.querySelector('[data-id="' + anchor + '"]') : null;
            if (back) { list.scrollTop = back.offsetTop - offset; }
            if (arrived) { pill.hidden = false; }
        }
    }

    function load() {
        if (busy) { return; }
        busy = true;
        var data = new FormData();
        data.append('op', 'list');
        call(data).then(function (answer) {
            busy = false;
            if (!answer.ok) { say(answer.error); return; }
            var json = JSON.stringify([answer.messages || [], answer.today || '']);
            if (json !== shown) {
                render(answer.messages || [], answer.today || '');
                shown = json;
            }
        }, function () {
            busy = false;
            // Nothing shown yet: say so where the messages would be.
            if ('' === shown) {
                empty.textContent = text.loadFailed;
                empty.classList.add('is-error');
                empty.hidden = false;
            }
        });
    }

    // Loaded and polled only while the tab is open and the page is in view.
    function open() { return box.offsetParent !== null && !document.hidden; }

    function tick() {
        clearTimeout(timer);
        if (open()) { load(); }
        timer = setTimeout(tick, POLL);
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if ('' === field.value.trim()) { field.focus(); return; }
        var data = new FormData();
        data.append('op', 'post');
        data.append('text', field.value);
        data.append('reply_to', replyField.value);
        send.disabled = true;
        call(data).then(function (answer) {
            send.disabled = false;
            if (!answer.ok) { say(answer.error); return; }
            say('');
            field.value = '';
            grow();
            unreply();
            shown = '';
            load();
        }, function () { send.disabled = false; say('failed'); });
    });

    // With a keyboard, Enter sends and Shift+Enter starts a new line; on a
    // phone Enter is a new line and the send button sends.
    var keyboard = window.matchMedia && window.matchMedia('(pointer: fine)').matches;
    field.addEventListener('keydown', function (e) {
        if (keyboard && e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
        }
    });
    field.addEventListener('input', grow);
    // The on-screen keyboard shrinks the page: keep the composer in view.
    field.addEventListener('focus', function () {
        setTimeout(function () { form.scrollIntoView({ block: 'nearest' }); }, 300);
    });

    list.addEventListener('scroll', function () { if (atBottom()) { pill.hidden = true; } });
    pill.addEventListener('click', function () { toBottom(true); });
    box.querySelector('[data-eventcrew-chat-unreply]').addEventListener('click', function () {
        unreply();
        field.focus();
    });
    document.addEventListener('visibilitychange', function () { if (open()) { tick(); } });

    if (window.MutationObserver) {
        new MutationObserver(function () { if (open()) { tick(); } })
            .observe(box, { attributes: true, attributeFilter: ['class'] });
    }

    // After the tab script below has picked the open tab.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tick);
    } else {
        tick();
    }
}());
</script>

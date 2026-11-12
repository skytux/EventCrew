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
    <p class="eventcrew-muted"><?php esc_html_e('The crew’s Telegram group. What you send here, the bot posts to the group with your name.', 'eventcrew'); ?></p>
    <noscript><p class="eventcrew-muted"><?php esc_html_e('The group chat needs JavaScript.', 'eventcrew'); ?></p></noscript>
    <ol class="eventcrew-chat-list" data-eventcrew-chat-list aria-live="polite"></ol>
    <p class="eventcrew-muted" data-eventcrew-chat-empty hidden></p>
    <form class="eventcrew-chat-form" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>" data-eventcrew-chat-form hidden>
        <input type="hidden" name="action" value="<?php echo esc_attr(\EventCrew\Web\GroupChatController::ACTION); ?>">
        <input type="hidden" name="csrf" value="<?php echo esc_attr($eventcrew_csrf); ?>">
        <input type="hidden" name="op" value="post">
        <input type="hidden" name="reply_to" value="0">
        <p class="eventcrew-chat-replying" data-eventcrew-chat-replying hidden>
            <span data-eventcrew-chat-replying-to></span>
            <button type="button" class="eventcrew-textlink" data-eventcrew-chat-unreply><?php esc_html_e('Cancel', 'eventcrew'); ?></button>
        </p>
        <label class="screen-reader-text" for="eventcrew-chat-text"><?php esc_html_e('Message', 'eventcrew'); ?></label>
        <textarea id="eventcrew-chat-text" name="text" rows="2" class="wp-element-input"
            maxlength="<?php echo (int) \EventCrew\Telegram\GroupRelay::MAX_LENGTH; ?>" required></textarea>
        <p class="eventcrew-chat-error" data-eventcrew-chat-error role="status" hidden></p>
        <button type="submit" class="wp-element-button eventcrew-linkbtn"><?php esc_html_e('Send', 'eventcrew'); ?></button>
    </form>
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
    var form = box.querySelector('[data-eventcrew-chat-form]');
    var error = box.querySelector('[data-eventcrew-chat-error]');
    var replying = box.querySelector('[data-eventcrew-chat-replying]');
    var replyingTo = box.querySelector('[data-eventcrew-chat-replying-to]');
    var field = form.querySelector('textarea');
    var replyField = form.querySelector('[name="reply_to"]');
    var shown = '';
    var timer = null;
    var busy = false;

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

    function reply(message) {
        replyField.value = String(message.id);
        replyingTo.textContent = '↩ ' + message.name + ': ' + (message.text || message.kind).slice(0, 80);
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

    function render(messages) {
        var atBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 40;
        list.textContent = '';
        messages.forEach(function (message) {
            var item = el('li', 'eventcrew-chat-msg' + (message.mine ? ' is-mine' : ''));
            var meta = el('p', 'eventcrew-chat-meta');
            meta.appendChild(el('strong', '', message.name));
            if (message.web) { meta.appendChild(document.createTextNode(' ' + text.web)); }
            meta.appendChild(document.createTextNode(' · '));
            meta.appendChild(el('span', 'eventcrew-muted', message.time + (message.edited ? ' · ' + text.edited : '')));
            item.appendChild(meta);
            if (message.reply) {
                var quote = el('blockquote', 'eventcrew-chat-quote');
                quote.appendChild(el('strong', '', message.reply.name));
                quote.appendChild(document.createTextNode(' ' + message.reply.text));
                item.appendChild(quote);
            }
            var body = el('p', 'eventcrew-chat-text');
            if (message.kind) {
                body.appendChild(el('em', '', '[' + message.kind + ']'));
                if (message.text) { body.appendChild(document.createTextNode(' ')); }
            }
            linked(body, message.text);
            item.appendChild(body);
            var actions = el('p', 'eventcrew-chat-actions');
            var answer = el('button', 'eventcrew-textlink', text.reply);
            answer.type = 'button';
            answer.addEventListener('click', function () { reply(message); });
            actions.appendChild(answer);
            if (organizer) {
                var remove = el('button', 'eventcrew-textlink', text.hide);
                remove.type = 'button';
                remove.addEventListener('click', function () { hide(message); });
                actions.appendChild(document.createTextNode(' · '));
                actions.appendChild(remove);
            }
            item.appendChild(actions);
            list.appendChild(item);
        });
        empty.textContent = text.empty;
        empty.hidden = messages.length > 0;
        if (atBottom || '' === shown) { list.scrollTop = list.scrollHeight; }
    }

    function load() {
        if (busy) { return; }
        busy = true;
        var data = new FormData();
        data.append('op', 'list');
        call(data).then(function (answer) {
            busy = false;
            if (!answer.ok) { say(answer.error); return; }
            var json = JSON.stringify(answer.messages || []);
            if (json !== shown) {
                render(answer.messages || []);
                shown = json;
            }
        }, function () { busy = false; });
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
        if ('' === field.value.trim()) { return; }
        var data = new FormData();
        data.append('op', 'post');
        data.append('text', field.value);
        data.append('reply_to', replyField.value);
        var send = form.querySelector('[type="submit"]');
        send.disabled = true;
        call(data).then(function (answer) {
            send.disabled = false;
            if (!answer.ok) { say(answer.error); return; }
            say('');
            field.value = '';
            unreply();
            shown = '';
            load();
        }, function () { send.disabled = false; say('failed'); });
    });

    // With a keyboard, Enter sends and Shift+Enter starts a new line; on a
    // phone Enter is a new line and Send sends.
    var keyboard = window.matchMedia && window.matchMedia('(pointer: fine)').matches;
    field.addEventListener('keydown', function (e) {
        if (keyboard && e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
        }
    });

    box.querySelector('[data-eventcrew-chat-unreply]').addEventListener('click', unreply);
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

<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Support\WebSession;
use EventCrew\Telegram\GroupRelay;

/**
 * The Group chat tab of the Crew app page: the group's messages, a message
 * to the group, and an organizer's hide (Telegram\GroupRelay).
 *
 * One admin-ajax action, ACTION, with op=list, op=post or op=hide, for the
 * signed-in person the group chat is open to (GroupRelay::visibleTo()),
 * with the web session cookie and its CSRF token, as the page's own forms.
 * Never cached: the messages are only ever in these answers, not in a page.
 */
final class GroupChatController
{
    public const ACTION = 'eventcrew_group_chat';

    public function __construct(
        private readonly GroupRelay $relay,
        private readonly SessionPerson $session
    ) {
    }

    public function boot(): void
    {
        add_action('wp_ajax_' . self::ACTION, [$this, 'dispatch']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [$this, 'dispatch']);
    }

    /**
     * The admin-ajax entry point: reads the request, answers JSON. The
     * untested-by-design boundary; handle() is the tested part.
     */
    public function dispatch(): void
    {
        if (! defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        nocache_headers();

        $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the web session's CSRF token is checked in handle().
        $posted = [
            'op' => isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '',
            'csrf' => isset($_POST['csrf']) ? sanitize_text_field(wp_unslash($_POST['csrf'])) : '',
            // Line breaks kept; the text is escaped where it is shown.
            'text' => isset($_POST['text']) ? sanitize_textarea_field(wp_unslash($_POST['text'])) : '',
            'reply_to' => isset($_POST['reply_to']) ? (string) absint(wp_unslash($_POST['reply_to'])) : '0',
            'id' => isset($_POST['id']) ? (string) absint(wp_unslash($_POST['id'])) : '0',
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ('post' !== $method) {
            wp_send_json(['ok' => false, 'error' => 'method'], 405);
        }

        [$status, $body] = $this->handle($this->session->current(), $posted);
        wp_send_json($body, $status);
    }

    /**
     * @param array<string, string> $posted op, csrf, text, reply_to, id
     * @return array{0: int, 1: array<string, mixed>} status code and JSON body
     */
    public function handle(?Person $person, array $posted): array
    {
        if (null === $person) {
            return [401, ['ok' => false, 'error' => 'session']];
        }

        if (! WebSession::verifyCsrf($person->id, (string) ($posted['csrf'] ?? ''))) {
            return [403, ['ok' => false, 'error' => 'session']];
        }

        if (! GroupRelay::visibleTo($person)) {
            return [403, ['ok' => false, 'error' => 'access']];
        }

        $op = (string) ($posted['op'] ?? '');

        if ('list' === $op) {
            // today: the site's date, so Today and Yesterday follow the site's
            // clock like the messages' own days, not the device's.
            return [200, [
                'ok' => true,
                'messages' => $this->relay->view($person),
                'today' => (string) current_time('Y-m-d'),
            ]];
        }

        if ('post' === $op) {
            $result = $this->relay->post($person, (string) ($posted['text'] ?? ''), (int) ($posted['reply_to'] ?? 0));

            if ($result['ok']) {
                return [200, ['ok' => true]];
            }

            return ['rate' === $result['error'] ? 429 : 400, ['ok' => false, 'error' => $result['error']]];
        }

        if ('hide' === $op) {
            if (! $person->isOrganizer) {
                return [403, ['ok' => false, 'error' => 'access']];
            }

            return $this->relay->hide($person, (int) ($posted['id'] ?? 0))
                ? [200, ['ok' => true]]
                : [404, ['ok' => false, 'error' => 'failed']];
        }

        return [400, ['ok' => false, 'error' => 'op']];
    }
}

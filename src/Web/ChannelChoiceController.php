<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\ChannelChoice;
use EventCrew\Support\WebSession;

/**
 * Stores what the Crew app page's "Where do you want them?" pop-in
 * (ChannelChoice) was told: op=asked when it opens (so it is asked once),
 * op=choose with choice=app|telegram|both when a button is pressed.
 *
 * One admin-ajax action, ACTION, for the signed-in person only (the web
 * session cookie and its CSRF token, as the page's own forms).
 */
final class ChannelChoiceController
{
    public const ACTION = 'eventcrew_channel_choice';

    public function __construct(
        private readonly PersonRepository $people,
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
            'choice' => isset($_POST['choice']) ? sanitize_key(wp_unslash($_POST['choice'])) : '',
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ('post' !== $method) {
            wp_send_json(['ok' => false, 'error' => 'method'], 405);
        }

        [$status, $body] = $this->handle($this->session->current(), $posted);
        wp_send_json($body, $status);
    }

    /**
     * @param array<string, string> $posted op, csrf, choice
     * @return array{0: int, 1: array<string, mixed>} status code and JSON body
     */
    public function handle(?Person $person, array $posted): array
    {
        if (null === $person) {
            return [401, ['ok' => false, 'error' => 'session']];
        }

        if (! WebSession::verifyCsrf($person->id, (string) ($posted['csrf'] ?? ''))) {
            return [403, ['ok' => false, 'error' => 'csrf']];
        }

        $now = (string) current_time('mysql');
        $op = (string) ($posted['op'] ?? '');

        if (ChannelChoice::OP_ASKED === $op) {
            $entries = ChannelChoice::askedEntries($person, $now);

            if ([] !== $entries) {
                $this->people->addRoleNotices($person->id, $entries);
            }

            return [200, ['ok' => true]];
        }

        if (ChannelChoice::OP_CHOOSE !== $op) {
            return [400, ['ok' => false, 'error' => 'op']];
        }

        $choice = ChannelChoice::parse((string) ($posted['choice'] ?? ''));

        if (null === $choice) {
            return [400, ['ok' => false, 'error' => 'choice']];
        }

        $this->people->addRoleNotices($person->id, ChannelChoice::entriesFor($choice, $now));

        return [200, ['ok' => true, 'text' => ChannelChoice::confirmation($choice)]];
    }
}

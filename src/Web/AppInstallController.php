<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\AppInstallPrompt;
use EventCrew\Support\WebSession;

/**
 * Stores what the person did with the "Get the app" pop-in of the Crew app
 * page (AppInstallPrompt): closed it, Remind me later, installed the app,
 * or saw the installed app's offer to turn on notifications.
 *
 * One admin-ajax action, ACTION, with op=close|later|installed|offered, for
 * the signed-in person only (the web session cookie and its CSRF token, as
 * the page's own forms).
 */
final class AppInstallController
{
    public const ACTION = 'eventcrew_app_install';

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
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        $cookie = isset($_COOKIE[WebSession::COOKIE]) ? (string) $_COOKIE[WebSession::COOKIE] : '';

        if ('post' !== $method) {
            wp_send_json(['ok' => false, 'error' => 'method'], 405);
        }

        [$status, $body] = $this->handle(
            $this->session->current(),
            $posted,
            '' === $cookie ? null : WebSession::issuedAt($cookie),
            time()
        );
        wp_send_json($body, $status);
    }

    /**
     * @param array<string, string> $posted op, csrf
     * @return array{0: int, 1: array<string, mixed>} status code and JSON body
     */
    public function handle(?Person $person, array $posted, ?int $sessionIssuedAt, int $now): array
    {
        if (null === $person) {
            return [401, ['ok' => false, 'error' => 'session']];
        }

        if (! WebSession::verifyCsrf($person->id, (string) ($posted['csrf'] ?? ''))) {
            return [403, ['ok' => false, 'error' => 'csrf']];
        }

        $entries = AppInstallPrompt::entriesFor(
            (string) ($posted['op'] ?? ''),
            $person,
            $sessionIssuedAt,
            $now,
            (string) current_time('mysql'),
            AppInstallPrompt::remindDays()
        );

        if (null === $entries) {
            return [400, ['ok' => false, 'error' => 'op']];
        }

        if ([] !== $entries) {
            $this->people->addRoleNotices($person->id, $entries);
        }

        return [200, ['ok' => true]];
    }
}

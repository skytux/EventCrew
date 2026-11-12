<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Repositories\PushSubscriptionRepository;
use EventCrew\Support\PushNotifier;
use EventCrew\Support\WebPush;
use EventCrew\Support\WebSession;

/**
 * Turns app notifications (Web Push) on and off for one device, from the
 * Notifications card of the Crew app page.
 *
 * One admin-ajax action, ACTION, with op=subscribe or op=unsubscribe, for
 * the signed-in person only (the web session cookie and its CSRF token, as
 * the page's own forms). Subscribe stores what the browser's
 * PushManager.subscribe() gave (endpoint and keys) with a device label read
 * from the browser's name, so the person can tell their devices apart; the
 * page also sends it again on each visit, so a browser that renewed its
 * subscription stays reachable.
 */
final class PushController
{
    public const ACTION = 'eventcrew_web_push';

    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
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
            'endpoint' => isset($_POST['endpoint']) ? esc_url_raw(wp_unslash($_POST['endpoint']), ['https']) : '',
            'p256dh' => isset($_POST['p256dh']) ? sanitize_text_field(wp_unslash($_POST['p256dh'])) : '',
            'auth' => isset($_POST['auth']) ? sanitize_text_field(wp_unslash($_POST['auth'])) : '',
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        $agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        if ('post' !== $method) {
            wp_send_json(['ok' => false, 'error' => 'method'], 405);
        }

        [$status, $body] = $this->handle($this->session->current(), $posted, $agent);
        wp_send_json($body, $status);
    }

    /**
     * @param array<string, string> $posted op, csrf, endpoint, p256dh, auth
     * @return array{0: int, 1: array<string, mixed>} status code and JSON body
     */
    public function handle(?Person $person, array $posted, string $userAgent): array
    {
        if (null === $person) {
            return [401, ['ok' => false, 'error' => 'session']];
        }

        if (! WebSession::verifyCsrf($person->id, (string) ($posted['csrf'] ?? ''))) {
            return [403, ['ok' => false, 'error' => 'csrf']];
        }

        $endpoint = (string) ($posted['endpoint'] ?? '');

        if ('' === $endpoint || strlen($endpoint) > 2000 || ! PushNotifier::endpointAllowed($endpoint)) {
            return [400, ['ok' => false, 'error' => 'endpoint']];
        }

        $op = (string) ($posted['op'] ?? '');

        if ('unsubscribe' === $op) {
            $this->subscriptions->deleteEndpoint($endpoint, $person->id);

            return [200, ['ok' => true]];
        }

        if ('subscribe' !== $op) {
            return [400, ['ok' => false, 'error' => 'op']];
        }

        $p256dh = (string) ($posted['p256dh'] ?? '');
        $auth = (string) ($posted['auth'] ?? '');
        $point = WebPush::unb64($p256dh);

        if (65 !== strlen($point) || "\x04" !== $point[0] || 16 !== strlen(WebPush::unb64($auth))) {
            return [400, ['ok' => false, 'error' => 'keys']];
        }

        $ok = $this->subscriptions->save(
            $person->id,
            $endpoint,
            WebPush::b64($point),
            WebPush::b64(WebPush::unb64($auth)),
            self::deviceLabel($userAgent)
        );

        return $ok ? [200, ['ok' => true]] : [500, ['ok' => false, 'error' => 'store']];
    }

    /**
     * A short name for the device from the browser's user agent, such as
     * "iPhone", "Android Chrome" or "Windows Firefox"; '' when unknown.
     */
    public static function deviceLabel(string $userAgent): string
    {
        $systems = [
            'iPhone' => 'iPhone',
            'iPad' => 'iPad',
            'Android' => 'Android',
            'Windows' => 'Windows',
            'Mac OS X' => 'Mac',
            'Macintosh' => 'Mac',
            'CrOS' => 'ChromeOS',
            'Linux' => 'Linux',
        ];
        $browsers = [
            'Edg/' => 'Edge',
            'OPR/' => 'Opera',
            'SamsungBrowser' => 'Samsung Internet',
            'Firefox/' => 'Firefox',
            'FxiOS' => 'Firefox',
            'CriOS' => 'Chrome',
            'Chrome/' => 'Chrome',
            'Safari/' => 'Safari',
        ];
        $system = '';
        $browser = '';

        foreach ($systems as $needle => $name) {
            if (str_contains($userAgent, $needle)) {
                $system = $name;
                break;
            }
        }

        foreach ($browsers as $needle => $name) {
            if (str_contains($userAgent, $needle)) {
                $browser = $name;
                break;
            }
        }

        // On an iPhone or iPad every browser is Safari underneath, and only
        // the Home Screen app gets notifications: the device says it all.
        if ('iPhone' === $system || 'iPad' === $system) {
            return $system;
        }

        return mb_substr(trim($system . ' ' . $browser), 0, 64);
    }
}

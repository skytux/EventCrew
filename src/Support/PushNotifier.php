<?php

declare(strict_types=1);

namespace EventCrew\Support;

use Closure;
use EventCrew\Models\Person;
use EventCrew\Repositories\PushSubscriptionRepository;
use EventCrew\Web\PwaController;

/**
 * App notifications (Web Push) to a person's devices: the one helper every
 * notifier calls next to its Telegram DM, with the same text.
 *
 *     PushNotifier::notify($person, NotificationPreferences::CREDIT, $dmText);
 *
 * Sends only what NotificationPreferences::pushAllowed() allows, which needs
 * a device that turned app notifications on: nothing is pushed before that.
 * The title is the app's name, the body the text made plain and short, and
 * a tap opens the Crew app.
 *
 * Sent at once, in the request that sends the DM, as the DMs are: the
 * background jobs queue (BackgroundJobs) is the wp-admin's, shown on its
 * pages, and no place for personal notices. Each device is one short POST
 * (TIMEOUT seconds at most); a push service answers in well under a second,
 * and most people have one device.
 *
 * A push service that says the device is gone (404, 410) has its row
 * deleted. Any other failure is ignored, like a DM that does not arrive.
 */
class PushNotifier
{
    /** Seconds one send may take. */
    public const TIMEOUT = 3;

    /** How long a push service keeps a message for a device that is offline. */
    public const TTL = 12 * HOUR_IN_SECONDS;

    /** The body's length, in characters. */
    public const BODY_LENGTH = 200;

    /** The push services a browser can name; a subscription elsewhere is refused. */
    private const HOSTS = [
        'fcm.googleapis.com',
        'android.googleapis.com',
        'updates.push.services.mozilla.com',
        '.push.apple.com',
        '.notify.windows.com',
    ];

    /** The notifier notify() uses; null (before boot, and in tests) sends nothing. */
    private static ?self $instance = null;

    /** @var Closure(string, array<string, mixed>): int */
    private readonly Closure $post;

    /** @var Closure(): array<string, string> */
    private readonly Closure $app;

    /**
     * @param (Closure(string, array<string, mixed>): int)|null $post the HTTP POST, answering the status code (0: no answer)
     * @param (Closure(): array<string, string>)|null $app name, start_url and icon_url of the installed app
     */
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        ?Closure $post = null,
        ?Closure $app = null,
        private readonly ?Closure $key = null
    ) {
        $this->post = $post ?? static function (string $url, array $args): int {
            $response = wp_safe_remote_post($url, $args);

            return is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        };
        $this->app = $app ?? static fn (): array => (new PwaController())->manifestConfig();
    }

    public static function use(?self $notifier): void
    {
        self::$instance = $notifier;
    }

    /**
     * Pushes $text to the person's devices when they allow app notifications
     * of $type. $text may be a Telegram DM (HTML); $url is where a tap goes,
     * the Crew app when ''. Returns how many devices it reached.
     */
    public static function notify(Person $person, string $type, string $text, string $url = ''): int
    {
        if (null === self::$instance) {
            return 0;
        }

        try {
            return self::$instance->send($person, $type, $text, $url);
        } catch (\Throwable) {
            // A notice that does not arrive never stops what sent it.
            return 0;
        }
    }

    /** Sends to each device; returns how many push services accepted it. */
    public function send(Person $person, string $type, string $text, string $url = ''): int
    {
        if (! (new NotificationPreferences())->pushAllowed($person, $type)) {
            return 0;
        }

        $devices = $this->subscriptions->forPerson($person->id);
        $key = null === $this->key ? VapidKeys::privateKey() : ($this->key)();

        if ([] === $devices || null === $key) {
            return 0;
        }

        $app = ($this->app)();
        $payload = (string) wp_json_encode([
            'title' => (string) ($app['name'] ?? ''),
            'body' => self::plain($text),
            'url' => '' === $url ? (string) ($app['start_url'] ?? '') : $url,
            'icon' => add_query_arg('size', 192, (string) ($app['icon_url'] ?? '')),
            'tag' => $type,
        ]);
        $subject = VapidKeys::subject();
        $sent = 0;

        foreach ($devices as $device) {
            if (! self::endpointAllowed($device['endpoint'])) {
                continue;
            }

            try {
                $body = WebPush::encrypt($payload, WebPush::unb64($device['p256dh']), WebPush::unb64($device['auth']));
                $code = ($this->post)($device['endpoint'], [
                    'timeout' => self::TIMEOUT,
                    'redirection' => 0,
                    'headers' => [
                        'Authorization' => WebPush::vapidHeader($device['endpoint'], $key, $subject, time() + self::TTL),
                        'Content-Encoding' => 'aes128gcm',
                        'Content-Type' => 'application/octet-stream',
                        'TTL' => (string) self::TTL,
                        'Urgency' => 'normal',
                    ],
                    'body' => $body,
                ]);
            } catch (\Throwable) {
                continue;
            }

            if ($code >= 200 && $code < 300) {
                $this->subscriptions->markOk($device['id']);
                ++$sent;
            } elseif (404 === $code || 410 === $code) {
                // The browser unsubscribed, or the device is gone for good.
                $this->subscriptions->delete($device['id']);
            }
        }

        return $sent;
    }

    /**
     * A DM as a notification body: the HTML tags out, entities decoded,
     * empty lines and runs of spaces closed up, at most BODY_LENGTH
     * characters.
     */
    public static function plain(string $text): string
    {
        $text = (string) preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_values(array_filter(
            array_map(static fn (string $line): string => trim((string) preg_replace('/[ \t]+/', ' ', $line)), explode("\n", $text)),
            static fn (string $line): bool => '' !== $line
        ));
        $text = implode("\n", $lines);

        if (mb_strlen($text) > self::BODY_LENGTH) {
            $text = rtrim(mb_substr($text, 0, self::BODY_LENGTH - 1)) . '…';
        }

        return $text;
    }

    /** Whether $endpoint is an https address of a known push service. */
    public static function endpointAllowed(string $endpoint): bool
    {
        $parts = wp_parse_url($endpoint);

        if (! is_array($parts) || 'https' !== ($parts['scheme'] ?? '') || '' === (string) ($parts['host'] ?? '')) {
            return false;
        }

        $host = strtolower((string) $parts['host']);

        /** Lets a site allow another push service, by host or ".domain" suffix. */
        $hosts = (array) apply_filters('eventcrew_push_hosts', self::HOSTS);

        foreach ($hosts as $allowed) {
            $allowed = strtolower((string) $allowed);

            if ($host === $allowed || (str_starts_with($allowed, '.') && str_ends_with($host, $allowed))) {
                return true;
            }
        }

        return false;
    }
}

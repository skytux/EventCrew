<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\PushSubscriptionRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TakeoverRepository;
use EventCrew\Support\NotificationPreferences;
use EventCrew\Support\PersonEraser;
use EventCrew\Support\PushNotifier;
use EventCrew\Support\WebPush;
use EventCrew\Tests\TestCase;
use OpenSSLAsymmetricKey;

/**
 * App notifications: who gets one, what the push service is sent, and what
 * happens to a device the push service says is gone.
 */
final class PushNotifierTest extends TestCase
{
    /** @var list<array{url: string, args: array<string, mixed>}> */
    private array $posts = [];

    /** @var array<string, int> status code per endpoint */
    private array $answers = [];

    private OpenSSLAsymmetricKey $deviceKey;

    private string $auth;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('add_query_arg')->alias(
            static fn (string $key, mixed $value, string $url): string => $url . '?' . $key . '=' . $value
        );
        Functions\when('wp_parse_url')->alias(static fn (string $url, int $part = -1): mixed => parse_url($url, $part));

        $this->deviceKey = WebPush::newKey();
        $this->auth = random_bytes(16);
    }

    private function notifier(): PushNotifier
    {
        $siteKey = WebPush::newKey();

        return new PushNotifier(
            new PushSubscriptionRepository(),
            function (string $url, array $args): int {
                $this->posts[] = ['url' => $url, 'args' => $args];

                return $this->answers[$url] ?? 201;
            },
            static fn (): array => [
                'name' => 'EDH Crew',
                'start_url' => 'https://site.test/crew/',
                'icon_url' => 'https://site.test/icon',
            ],
            static fn (): OpenSSLAsymmetricKey => $siteKey
        );
    }

    /** @return array{id: int, endpoint: string, p256dh: string, auth: string, device: string} */
    private function device(int $id, string $endpoint): array
    {
        return [
            'id' => $id,
            'endpoint' => $endpoint,
            'p256dh' => WebPush::b64(WebPush::rawPublicKey($this->deviceKey)),
            'auth' => WebPush::b64($this->auth),
            'device' => 'Android Chrome',
        ];
    }

    private function person(array $row = []): Person
    {
        return Person::fromRow($row + ['id' => 7, 'email' => 'sam@example.com']);
    }

    /** The browser's side of RFC 8291: the JSON the service worker gets. */
    private function decrypt(string $body): array
    {
        $public = WebPush::rawPublicKey($this->deviceKey);
        $salt = substr($body, 0, 16);
        $serverPublic = substr($body, 21, 65);
        $shared = openssl_pkey_derive(WebPush::publicKeyFromRaw($serverPublic), $this->deviceKey, 32);
        $ikm = hash_hkdf('sha256', (string) $shared, 32, "WebPush: info\x00" . $public . $serverPublic, $this->auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);
        $plain = (string) openssl_decrypt(substr($body, 86, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($body, -16));

        return (array) json_decode(substr($plain, 0, -1), true);
    }

    public function testNothingIsSentToAPersonWithoutADevice(): void
    {
        NotificationPreferences::useDeviceLookup(static fn (int $id): bool => false);

        self::assertSame(0, $this->notifier()->send($this->person(), NotificationPreferences::CREDIT, 'Hi'));
        self::assertSame([], $this->posts);
        self::assertSame([], $this->wpdb->queries);
    }

    public function testNothingIsSentWhenTheTypesAppSwitchIsOff(): void
    {
        NotificationPreferences::useDeviceLookup(static fn (int $id): bool => true);
        $person = $this->person(['notify_prefs' => json_encode(['credit' => ['push' => false]])]);

        self::assertSame(0, $this->notifier()->send($person, NotificationPreferences::CREDIT, 'Hi'));
        self::assertSame([], $this->posts);
    }

    public function testEachDeviceGetsTheTextAndAGoneDeviceIsDeleted(): void
    {
        NotificationPreferences::useDeviceLookup(static fn (int $id): bool => true);
        $kept = 'https://fcm.googleapis.com/fcm/send/abc';
        $gone = 'https://updates.push.services.mozilla.com/wpush/v2/def';
        $this->answers[$gone] = 410;
        $this->wpdb->nextResults[] = [
            $this->device(3, $kept),
            $this->device(4, $gone),
            // Not a push service: never posted to.
            $this->device(5, 'https://attacker.example/push'),
        ];

        $sent = $this->notifier()->send(
            $this->person(),
            NotificationPreferences::CREDIT,
            "<b>Free entry</b> given &amp; saved.\n\n\nSee you there."
        );

        self::assertSame(1, $sent);
        self::assertSame([$kept, $gone], array_column($this->posts, 'url'));

        $args = $this->posts[0]['args'];
        self::assertSame('aes128gcm', $args['headers']['Content-Encoding']);
        self::assertSame((string) PushNotifier::TTL, $args['headers']['TTL']);
        self::assertStringStartsWith('vapid t=', $args['headers']['Authorization']);
        self::assertSame(0, $args['redirection']);
        self::assertSame([
            'title' => 'EDH Crew',
            'body' => "Free entry given & saved.\nSee you there.",
            'url' => 'https://site.test/crew/',
            'icon' => 'https://site.test/icon?size=192',
            'tag' => 'credit',
        ], $this->decrypt($args['body']));

        // The accepted one is marked, the gone one deleted.
        self::assertSame(['id' => 3], $this->wpdb->updates[0]['where']);
        self::assertArrayHasKey('last_ok_at', $this->wpdb->updates[0]['data']);
        self::assertSame([['table' => 'wp_eventcrew_push_subscriptions', 'where' => ['id' => 4]]], $this->wpdb->deletes);
    }

    public function testATapOpensTheGivenAddress(): void
    {
        NotificationPreferences::useDeviceLookup(static fn (int $id): bool => true);
        $this->wpdb->nextResults[] = [$this->device(3, 'https://web.push.apple.com/abc')];

        $this->notifier()->send($this->person(), NotificationPreferences::TICKET, 'Ticket', 'https://site.test/t/1');

        self::assertSame('https://site.test/t/1', $this->decrypt($this->posts[0]['args']['body'])['url']);
    }

    public function testNotifyIsSilentBeforeBootAndWhenSendingFails(): void
    {
        self::assertSame(0, PushNotifier::notify($this->person(), NotificationPreferences::CREDIT, 'Hi'));

        PushNotifier::use(new class (new PushSubscriptionRepository()) extends PushNotifier {
            public function send(Person $person, string $type, string $text, string $url = ''): int
            {
                throw new \RuntimeException('down');
            }
        });

        self::assertSame(0, PushNotifier::notify($this->person(), NotificationPreferences::CREDIT, 'Hi'));
    }

    public function testTheBodyIsPlainAndShort(): void
    {
        self::assertSame("Line one\nLine two", PushNotifier::plain("<i>Line   one</i><br>\n\n  Line two  "));

        $long = PushNotifier::plain(str_repeat('word ', 100));
        self::assertSame(PushNotifier::BODY_LENGTH, mb_strlen($long));
        self::assertStringEndsWith('…', $long);
    }

    public function testOnlyHttpsAddressesOfKnownPushServicesAreAllowed(): void
    {
        self::assertTrue(PushNotifier::endpointAllowed('https://fcm.googleapis.com/fcm/send/x'));
        self::assertTrue(PushNotifier::endpointAllowed('https://web.push.apple.com/x'));
        self::assertTrue(PushNotifier::endpointAllowed('https://wns2-db5p.notify.windows.com/w/?token=x'));
        self::assertFalse(PushNotifier::endpointAllowed('http://fcm.googleapis.com/fcm/send/x'));
        self::assertFalse(PushNotifier::endpointAllowed('https://push.apple.com.attacker.example/x'));
        self::assertFalse(PushNotifier::endpointAllowed('https://evilpush.apple.com/x'));
        self::assertFalse(PushNotifier::endpointAllowed('https://127.0.0.1/x'));
        self::assertFalse(PushNotifier::endpointAllowed('not a url'));
    }

    public function testErasingAPersonDeletesTheirDevices(): void
    {
        $eraser = new PersonEraser(
            new PersonRepository(),
            new AssignmentRepository(),
            new RedemptionRepository(),
            new CreditGrantRepository(),
            new AuthTokenRepository(),
            new NotificationsRepository(),
            new TakeoverRepository(),
            push: new PushSubscriptionRepository()
        );

        $eraser->erase(7);

        self::assertContains(
            ['table' => 'wp_eventcrew_push_subscriptions', 'where' => ['person_id' => 7]],
            $this->wpdb->deletes
        );
    }
}

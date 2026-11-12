<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\PushSubscriptionRepository;
use EventCrew\Support\ChannelChoice;
use EventCrew\Support\WebPush;
use EventCrew\Support\WebSession;
use EventCrew\Tests\TestCase;
use EventCrew\Web\PushController;
use EventCrew\Web\SessionPerson;

/**
 * Turning app notifications on and off for one device, from the Crew app page.
 */
final class PushControllerTest extends TestCase
{
    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc';

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('wp_parse_url')->alias(static fn (string $url, int $part = -1): mixed => parse_url($url, $part));
    }

    private function controller(): PushController
    {
        return new PushController(new PushSubscriptionRepository(), new SessionPerson(new PersonRepository()));
    }

    private function person(): Person
    {
        return Person::fromRow(['id' => 7, 'email' => 'sam@example.com']);
    }

    /** @return array<string, string> */
    private function posted(array $over = []): array
    {
        return $over + [
            'op' => 'subscribe',
            'csrf' => WebSession::csrfToken(7),
            'endpoint' => self::ENDPOINT,
            'p256dh' => WebPush::b64(WebPush::rawPublicKey(WebPush::newKey())),
            'auth' => WebPush::b64(random_bytes(16)),
        ];
    }

    public function testSubscribingStoresTheDeviceWithItsLabel(): void
    {
        $this->wpdb->nextQueryResults[] = 1;
        $posted = $this->posted();
        $agent = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';

        [$status, $body] = $this->controller()->handle($this->person(), $posted, $agent);

        self::assertSame([200, ['ok' => true]], [$status, $body]);
        $insert = $this->wpdb->queries[0];
        self::assertStringContainsString('INSERT INTO wp_eventcrew_push_subscriptions', $insert);
        self::assertStringContainsString("'" . self::ENDPOINT . "'", $insert);
        self::assertStringContainsString("'" . PushSubscriptionRepository::hash(self::ENDPOINT) . "'", $insert);
        self::assertStringContainsString("'" . $posted['p256dh'] . "'", $insert);
        self::assertStringContainsString("'Android Chrome'", $insert);
    }

    public function testWithTelegramLinkedAndNeverAskedThePageAsksWhereNotificationsGo(): void
    {
        $agent = 'Mozilla/5.0 (Linux; Android 14) Chrome/130.0 Mobile Safari/537.36';
        $linked = Person::fromRow(['id' => 7, 'email' => 'sam@example.com', 'telegram_user_id' => 555, 'telegram_chat_id' => 999]);
        $answered = Person::fromRow([
            'id' => 7,
            'email' => 'sam@example.com',
            'telegram_user_id' => 555,
            'telegram_chat_id' => 999,
            'role_notices' => (string) json_encode([ChannelChoice::KEY => ChannelChoice::APP]),
        ]);

        $this->wpdb->nextQueryResults[] = 1;
        self::assertSame([200, ['ok' => true, 'ask_channels' => true]], $this->controller()->handle($linked, $this->posted(), $agent));

        $this->wpdb->nextQueryResults[] = 1;
        self::assertSame([200, ['ok' => true]], $this->controller()->handle($answered, $this->posted(), $agent), 'Asked once.');
    }

    public function testUnsubscribingDeletesOnlyThePersonsOwnDevice(): void
    {
        [$status] = $this->controller()->handle($this->person(), $this->posted(['op' => 'unsubscribe']), '');

        self::assertSame(200, $status);
        self::assertSame(
            [['table' => 'wp_eventcrew_push_subscriptions', 'where' => [
                'endpoint_hash' => PushSubscriptionRepository::hash(self::ENDPOINT),
                'person_id' => 7,
            ]]],
            $this->wpdb->deletes
        );
    }

    public function testRefusals(): void
    {
        $controller = $this->controller();

        self::assertSame(401, $controller->handle(null, $this->posted(), '')[0]);
        self::assertSame(403, $controller->handle($this->person(), $this->posted(['csrf' => 'x']), '')[0]);
        self::assertSame(
            'endpoint',
            $controller->handle($this->person(), $this->posted(['endpoint' => 'https://attacker.example/p']), '')[1]['error']
        );
        self::assertSame('op', $controller->handle($this->person(), $this->posted(['op' => 'list']), '')[1]['error']);
        self::assertSame('keys', $controller->handle($this->person(), $this->posted(['p256dh' => 'abc']), '')[1]['error']);
        self::assertSame('keys', $controller->handle($this->person(), $this->posted(['auth' => 'abc']), '')[1]['error']);
        self::assertSame([], $this->wpdb->queries);
        self::assertSame([], $this->wpdb->deletes);
    }

    public function testTheDeviceLabelIsTheSystemAndBrowser(): void
    {
        // phpcs:disable Generic.Files.LineLength.TooLong -- real user agents.
        self::assertSame('iPhone', PushController::deviceLabel('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'));
        self::assertSame('Windows Edge', PushController::deviceLabel('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 Edg/130.0'));
        self::assertSame('Mac Firefox', PushController::deviceLabel('Mozilla/5.0 (Macintosh; Intel Mac OS X 14.5; rv:131.0) Gecko/20100101 Firefox/131.0'));
        self::assertSame('Linux Chrome', PushController::deviceLabel('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'));
        // phpcs:enable Generic.Files.LineLength.TooLong
        self::assertSame('', PushController::deviceLabel(''));
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\AppInstallPrompt;
use EventCrew\Support\WebSession;
use EventCrew\Tests\TestCase;
use EventCrew\Web\AppInstallController;
use EventCrew\Web\SessionPerson;

/**
 * The Crew app's "Get the app" pop-in posting what the person chose.
 */
final class AppInstallControllerTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const SESSION = 1_799_990_000;

    private function controller(): AppInstallController
    {
        $people = new PersonRepository();

        return new AppInstallController($people, new SessionPerson($people));
    }

    private function person(): Person
    {
        return Person::fromRow(['id' => 7, 'email' => 'sam@example.com']);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function post(string $op): array
    {
        return $this->controller()->handle(
            $this->person(),
            ['op' => $op, 'csrf' => WebSession::csrfToken(7)],
            self::SESSION,
            self::NOW
        );
    }

    /** @return array<string, mixed> the role_notices the last update wrote */
    private function written(): array
    {
        $update = end($this->wpdb->updates);
        self::assertIsArray($update, 'Nothing was written.');
        self::assertSame(['id' => 7], $update['where']);

        return (array) json_decode((string) $update['data']['role_notices'], true);
    }

    public function testCloseStoresThisSession(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com'];

        $answer = $this->controller()->handle(
            $this->person(),
            ['op' => 'close', 'csrf' => WebSession::csrfToken(7)],
            self::SESSION,
            self::NOW
        );

        self::assertSame([200, ['ok' => true]], $answer);
        self::assertSame([AppInstallPrompt::KEY_CLOSED => (string) self::SESSION], $this->written());
    }

    public function testRemindMeLaterStoresTheDateFourteenDaysOn(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com'];

        $this->post('later');

        self::assertSame(
            [AppInstallPrompt::KEY_REMIND_AFTER => (string) (self::NOW + 14 * DAY_IN_SECONDS)],
            $this->written()
        );
    }

    public function testInstalledIsStoredAndKeepsTheRestOfTheRecord(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'role_notices' => (string) json_encode(['newcomer:before' => 'yes']),
        ];

        $this->post('installed');

        self::assertSame(
            ['newcomer:before' => 'yes', AppInstallPrompt::KEY_INSTALLED => '2026-07-20 12:00:00'],
            $this->written()
        );
    }

    public function testRefusals(): void
    {
        $controller = $this->controller();
        $csrf = WebSession::csrfToken(7);
        $sam = $this->person();
        $close = ['op' => 'close', 'csrf' => $csrf];

        self::assertSame(401, $controller->handle(null, $close, self::SESSION, self::NOW)[0]);
        self::assertSame(403, $controller->handle($sam, ['op' => 'close', 'csrf' => 'x'], self::SESSION, self::NOW)[0]);
        self::assertSame(403, $controller->handle($sam, ['op' => 'close'], self::SESSION, self::NOW)[0]);
        self::assertSame(
            [400, ['ok' => false, 'error' => 'op']],
            $controller->handle($sam, ['op' => 'wipe', 'csrf' => $csrf], self::SESSION, self::NOW)
        );
        self::assertSame(400, $controller->handle($sam, $close, null, self::NOW)[0], 'Close needs the session.');
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->wpdb->queries);
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\AppInstallPrompt;
use EventCrew\Support\AppStats;
use EventCrew\Support\SiteStats;
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

    /** @param array<string, string> $record the person's role_notices */
    private function person(array $record = []): Person
    {
        return Person::fromRow([
            'id' => 7,
            'email' => 'sam@example.com',
            'role_notices' => [] === $record ? null : (string) json_encode($record),
        ]);
    }

    /**
     * Posts an op for Sam, whose stored role_notices are $record (as the
     * session reads them and the repository finds them again).
     *
     * @param array<string, string> $record
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function post(string $op, array $record = []): array
    {
        return $this->controller()->handle(
            $this->person($record),
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
            [
                'newcomer:before' => 'yes',
                AppInstallPrompt::KEY_INSTALLED => '2026-07-20 12:00:00',
                // Counted as a try and an install this month (AppStats).
                AppInstallPrompt::KEY_TRIED_MONTH => '2026-07',
                AppInstallPrompt::KEY_INSTALLED_MONTH => '2026-07',
            ],
            $this->written()
        );
    }

    public function testOpenedRecordsTheOpeningAndSettlesAPendingInstall(): void
    {
        $record = [
            AppInstallPrompt::KEY_INSTALLED => '2026-01-01 10:00:00',
            AppInstallPrompt::KEY_NOTIFY_OFFERED => '2026-01-01 10:01:00',
            AppInstallPrompt::KEY_INSTALL_PENDING => '2026-07-20 11:00:00',
        ];
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'role_notices' => (string) json_encode($record),
        ];

        self::assertSame([200, ['ok' => true]], $this->post('opened', $record));
        self::assertSame(
            [
                AppInstallPrompt::KEY_INSTALLED => '2026-01-01 10:00:00',
                AppInstallPrompt::KEY_NOTIFY_OFFERED => '',
                AppInstallPrompt::KEY_INSTALL_PENDING => '',
                AppInstallPrompt::KEY_LAST_OPEN => '2026-07-20 12:00:00',
                AppInstallPrompt::KEY_TRIED_MONTH => '2026-07',
                AppInstallPrompt::KEY_INSTALLED_MONTH => '2026-07',
            ],
            $this->written()
        );
    }

    public function testOpenedAgainTheSameDayWritesNothing(): void
    {
        $record = [
            AppInstallPrompt::KEY_INSTALLED => '2026-01-01 10:00:00',
            AppInstallPrompt::KEY_LAST_OPEN => '2026-07-20 09:00:00',
        ];

        self::assertSame([200, ['ok' => true]], $this->post('opened', $record));
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->wpdb->queries);
    }

    public function testInstallingMarksThePendingInstall(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com'];

        $this->post('installing');

        self::assertSame(
            [
                AppInstallPrompt::KEY_INSTALL_PENDING => '2026-07-20 12:00:00',
                AppInstallPrompt::KEY_TRIED_MONTH => '2026-07',
            ],
            $this->written()
        );
    }

    /**
     * Options kept in $options for the test: the monthly counters and when
     * counting began.
     *
     * @param array<string, mixed> $options
     */
    private function keepOptions(array &$options): void
    {
        Functions\when('get_option')->alias(
            static function (string $name, mixed $default = false) use (&$options): mixed {
                return $options[$name] ?? $default;
            }
        );
        Functions\when('update_option')->alias(static function (string $name, mixed $value) use (&$options): bool {
            $options[$name] = $value;

            return true;
        });
        Functions\when('add_option')->alias(static function (string $name, mixed $value) use (&$options): bool {
            if (isset($options[$name])) {
                return false;
            }

            $options[$name] = $value;

            return true;
        });
    }

    public function testTriesAndInstallsAreCountedOncePerPersonAMonth(): void
    {
        $options = [];
        $this->keepOptions($options);

        // Install the app tapped: a try; the first count notes when counting began.
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com'];
        self::assertSame([200, ['ok' => true]], $this->post('tapped'));
        self::assertSame([AppInstallPrompt::KEY_TRIED_MONTH => '2026-07'], $this->written());
        self::assertSame('2026-07-20 12:00:00', $options[AppStats::SINCE_OPTION]);

        // Tapped again, or the steps by hand shown, the same month: not again.
        $tried = [AppInstallPrompt::KEY_TRIED_MONTH => '2026-07'];
        $this->wpdb->updates = [];
        self::assertSame([200, ['ok' => true]], $this->post('tapped', $tried));
        self::assertSame([], $this->wpdb->updates);

        // Installed: an install, and no second try.
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com'];
        $this->post('installed', $tried);
        self::assertSame('2026-07', $this->written()[AppInstallPrompt::KEY_INSTALLED_MONTH]);

        // A try in an earlier month counts again now.
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com'];
        $this->post('installing', [AppInstallPrompt::KEY_TRIED_MONTH => '2026-06']);

        // Closing, Remind me later and the notifications offer count nothing.
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com'];
        $this->post('later');
        self::assertArrayNotHasKey(AppInstallPrompt::KEY_TRIED_MONTH, $this->written());

        self::assertSame(['tried' => 2, 'installed' => 1], SiteStats::month('2026-07')['app']);
    }

    public function testTheFirstOpeningAfterTheStepsIsAnInstall(): void
    {
        $options = [AppStats::SINCE_OPTION => '2026-07-01 00:00:00'];
        $this->keepOptions($options);
        $pending = [AppInstallPrompt::KEY_INSTALL_PENDING => '2026-07-20 11:00:00'];

        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com'];
        $this->post('opened', $pending);
        self::assertSame(['tried' => 1, 'installed' => 1], SiteStats::month('2026-07')['app']);

        // An ordinary opening of the app, a day later, counts nothing.
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com'];
        $this->post('opened', [
            AppInstallPrompt::KEY_INSTALLED => '2026-07-10 10:00:00',
            AppInstallPrompt::KEY_LAST_OPEN => '2026-07-19 10:00:00',
        ]);
        self::assertSame(['tried' => 1, 'installed' => 1], SiteStats::month('2026-07')['app']);
    }

    public function testAnInstallFromBeforeCountingIsNotCountedTwice(): void
    {
        // Installed on 5.7., counting began on 10.7.: the monthly numbers
        // count them from the install time already (AppStats::month()).
        $options = [AppStats::SINCE_OPTION => '2026-07-10 00:00:00'];
        $this->keepOptions($options);

        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com'];
        $this->post('installed', [AppInstallPrompt::KEY_INSTALLED => '2026-07-05 10:00:00']);

        self::assertSame('2026-07', $this->written()[AppInstallPrompt::KEY_INSTALLED_MONTH]);
        self::assertSame(['tried' => 0, 'installed' => 0], SiteStats::month('2026-07')['app']);
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

<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\ChannelChoice;
use EventCrew\Support\NotificationPreferences;
use EventCrew\Support\WebSession;
use EventCrew\Tests\TestCase;
use EventCrew\Web\ChannelChoiceController;
use EventCrew\Web\SessionPerson;
use EventCrew\Web\SignupController;

/**
 * The Crew app page's "Where do you want them?" pop-in posting that it was
 * asked, and the answer.
 */
final class ChannelChoiceControllerTest extends TestCase
{
    private const NOW = '2026-07-20 12:00:00';

    private function controller(): ChannelChoiceController
    {
        $people = new PersonRepository();

        return new ChannelChoiceController($people, new SessionPerson($people));
    }

    /** @param array<string, string> $notices */
    private function person(array $notices = []): Person
    {
        return Person::fromRow([
            'id' => 7,
            'email' => 'sam@example.com',
            'telegram_user_id' => 555,
            'role_notices' => [] === $notices ? null : (string) json_encode($notices),
        ]);
    }

    /** @return array<string, mixed> the role_notices the last update wrote */
    private function written(): array
    {
        $update = end($this->wpdb->updates);
        self::assertIsArray($update, 'Nothing was written.');
        self::assertSame(['id' => 7], $update['where']);

        return (array) json_decode((string) $update['data']['role_notices'], true);
    }

    public function testOpeningStoresThatItWasAsked(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'role_notices' => (string) json_encode(['newcomer:before' => 'yes'])];

        $answer = $this->controller()->handle($this->person(), ['op' => 'asked', 'csrf' => WebSession::csrfToken(7)]);

        self::assertSame([200, ['ok' => true]], $answer);
        self::assertSame(['newcomer:before' => 'yes', ChannelChoice::KEY_ASKED => self::NOW], $this->written());
    }

    public function testAskedAgainWritesNothing(): void
    {
        $sam = $this->person([ChannelChoice::KEY_ASKED => '2026-07-01 10:00:00']);

        self::assertSame([200, ['ok' => true]], $this->controller()->handle($sam, ['op' => 'asked', 'csrf' => WebSession::csrfToken(7)]));
        self::assertSame([], $this->wpdb->updates);
    }

    public function testAChoiceIsStoredAndConfirmed(): void
    {
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com'];

        $answer = $this->controller()->handle(
            $this->person(),
            ['op' => 'choose', 'choice' => 'app', 'csrf' => WebSession::csrfToken(7)]
        );

        self::assertSame([200, ['ok' => true, 'text' => 'Saved. Notifications come to the app.']], $answer);
        self::assertSame([ChannelChoice::KEY => 'app', ChannelChoice::KEY_ASKED => self::NOW], $this->written());
    }

    /** The page's inputs: due once, on a visit with a device, then never again. */
    public function testThePageAsksOnlyWhileDue(): void
    {
        $view = SignupController::channelChoiceView($this->person());
        self::assertFalse($view['applies'], 'No device with app notifications yet.');
        self::assertFalse($view['due']);
        self::assertTrue($view['telegram'], 'Still listening for notifications turned on here.');
        self::assertSame(ChannelChoiceController::ACTION, $view['action']);

        NotificationPreferences::useDeviceLookup(static fn (int $id): bool => true);

        $view = SignupController::channelChoiceView($this->person());
        self::assertTrue($view['applies']);
        self::assertTrue($view['due']);
        self::assertSame(ChannelChoice::BOTH, $view['choice']);

        $view = SignupController::channelChoiceView($this->person([ChannelChoice::KEY_ASKED => '2026-07-01 10:00:00']));
        self::assertFalse($view['due'], 'Closed with × once: not asked again.');
        self::assertTrue($view['asked']);
        self::assertTrue($view['applies'], 'The settings row still shows.');

        $none = SignupController::channelChoiceView(Person::fromRow(['id' => 8, 'email' => 'kim@example.com']));
        self::assertFalse($none['applies'], 'Without Telegram there is nothing to choose.');
        self::assertFalse($none['due']);
    }

    public function testRefusals(): void
    {
        $controller = $this->controller();
        $csrf = WebSession::csrfToken(7);
        $sam = $this->person();

        self::assertSame(401, $controller->handle(null, ['op' => 'asked', 'csrf' => $csrf])[0]);
        self::assertSame(403, $controller->handle($sam, ['op' => 'asked', 'csrf' => 'x'])[0]);
        self::assertSame([400, ['ok' => false, 'error' => 'op']], $controller->handle($sam, ['op' => 'wipe', 'csrf' => $csrf]));
        self::assertSame(
            [400, ['ok' => false, 'error' => 'choice']],
            $controller->handle($sam, ['op' => 'choose', 'choice' => 'email', 'csrf' => $csrf])
        );
        self::assertSame([], $this->wpdb->updates);
    }
}

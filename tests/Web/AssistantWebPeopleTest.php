<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Tests\TestCase;
use EventCrew\Web\AssistantWebPeople;
use EventCrew\Web\CrewAdminController;
use EventCrew\Web\CrewAdminPeople;

/**
 * The helper box's questions for the Admin tab's people changes (Lou
 * 2026-11-14): asked from the Admin tab's own rules, nothing run before
 * Confirm. Only the questions are tested here; Confirm runs the Admin
 * tab's methods, tested in CrewAdminControllerTest.
 */
final class AssistantWebPeopleTest extends TestCase
{
    private function people(): AssistantWebPeople
    {
        // The questions never reach the Admin tab's objects: no constructor needed.
        $admin = (new \ReflectionClass(CrewAdminController::class))->newInstanceWithoutConstructor();
        $screen = (new \ReflectionClass(CrewAdminPeople::class))->newInstanceWithoutConstructor();

        return new AssistantWebPeople(new PersonRepository(), $admin, $screen);
    }

    /** @return array<string, mixed> */
    private static function row(int $id, string $name, bool $organizer = false): array
    {
        return [
            'id' => $id,
            'email' => 'p' . $id . '@example.com',
            'display_name' => $name,
            'email_verified_at' => '2026-07-01 00:00:00',
            'is_organizer' => $organizer ? 1 : 0,
            'role_slugs' => '',
        ];
    }

    private static function me(): Person
    {
        return Person::fromRow(self::row(1, 'Lou H', true));
    }

    public function testMakingSomeoneAnOrganizerIsAsked(): void
    {
        $this->wpdb->nextResults[] = [self::row(1, 'Lou H', true), self::row(5, 'Heidi K')];
        $this->wpdb->nextRows[] = self::row(5, 'Heidi K');

        $asked = $this->people()->prepare(self::me(), AssistantWebPeople::ORGANIZER, 'on Heidi');

        self::assertTrue($asked['ok']);
        self::assertSame('Make Heidi K an organizer? They are told.', $asked['text']);
        self::assertEquals(['op' => AssistantWebPeople::ORGANIZER, 'on' => true, 'person' => 5], $asked['state']);
    }

    public function testAnOrganizerAlreadyIsSaid(): void
    {
        $this->wpdb->nextRows[] = self::row(5, 'Heidi K', true);

        $asked = $this->people()->choose(self::me(), ['op' => AssistantWebPeople::ORGANIZER, 'on' => true, 'person' => 5]);

        self::assertFalse($asked['ok']);
        self::assertSame('Heidi K is already an organizer.', $asked['text']);
    }

    public function testNeverOnesOwnOrganizerRoleNorTheLastOrganizers(): void
    {
        $this->wpdb->nextRows[] = self::row(1, 'Lou H', true);
        $self = $this->people()->choose(self::me(), ['op' => AssistantWebPeople::ORGANIZER, 'on' => false, 'person' => 1]);
        self::assertFalse($self['ok']);

        $this->wpdb->nextRows[] = self::row(5, 'Heidi K', true);
        $this->wpdb->nextResults[] = [self::row(5, 'Heidi K', true)];
        $last = $this->people()->choose(self::me(), ['op' => AssistantWebPeople::ORGANIZER, 'on' => false, 'person' => 5]);
        self::assertFalse($last['ok']);

        $this->wpdb->nextRows[] = self::row(5, 'Heidi K', true);
        $this->wpdb->nextResults[] = [self::row(1, 'Lou H', true), self::row(5, 'Heidi K', true)];
        $off = $this->people()->choose(self::me(), ['op' => AssistantWebPeople::ORGANIZER, 'on' => false, 'person' => 5]);
        self::assertTrue($off['ok']);
        self::assertSame('Take the organizer role from Heidi K?', $off['text']);
    }

    public function testDeletingWarnsAndNeverDeletesOneself(): void
    {
        $this->wpdb->nextRows[] = self::row(1, 'Lou H', true);
        self::assertFalse($this->people()->choose(self::me(), ['op' => AssistantWebPeople::DELETE, 'person' => 1])['ok']);

        $this->wpdb->nextRows[] = self::row(8, 'Sam K');
        $asked = $this->people()->choose(self::me(), ['op' => AssistantWebPeople::DELETE, 'person' => 8]);

        self::assertTrue($asked['ok']);
        self::assertSame('Delete Sam K? Their data and history are erased. This cannot be undone.', $asked['text']);
    }

    public function testSeveralMatchesAreChoices(): void
    {
        $this->wpdb->nextResults[] = [self::row(5, 'Sam K'), self::row(6, 'Sam L')];

        $asked = $this->people()->prepare(self::me(), AssistantWebPeople::DELETE, 'Sam');

        self::assertFalse($asked['ok']);
        $people = array_map(static fn (array $c): int => (int) $c['state']['person'], $asked['choices'] ?? []);
        sort($people);
        self::assertSame([5, 6], $people);
    }
}

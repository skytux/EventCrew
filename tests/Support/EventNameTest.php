<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Task;
use EventCrew\Support\EventName;
use EventCrew\Support\Language;
use EventCrew\Tests\TestCase;

/**
 * The clean event name and the DJ line from EventMesh, and the title as
 * written when EventMesh has none.
 */
final class EventNameTest extends TestCase
{
    use FakeEventNames;

    private const TITLE = 'ECSTATIC DANCE 14.11.2026 / Esimerkki';

    public function testWithoutEventMeshTheTitleIsKeptAndThereIsNoDjLine(): void
    {
        self::assertSame(self::TITLE, EventName::name(7, 'en', self::TITLE));
        self::assertSame('', EventName::djLine(7));
        self::assertSame(self::TITLE, EventName::oneLine(7, 'en', self::TITLE, 40));
    }

    public function testDjsWithoutACleanNameKeepTheTitleAndNoDjLine(): void
    {
        $this->fakeEventNames([], [7 => ['Esimerkki']]);

        self::assertSame(self::TITLE, EventName::name(7, 'fi', self::TITLE));
        // The title as written already names its DJs.
        self::assertSame('', EventName::djLine(7));
    }

    public function testTheCleanNameInTheLanguageAskedFor(): void
    {
        $this->fakeEventNames([7 => ['en' => 'Ecstatic Dance', 'fi' => 'Ekstaattinen tanssi']]);

        self::assertSame('Ecstatic Dance', EventName::name(7, 'en', self::TITLE));
        self::assertSame('Ekstaattinen tanssi', EventName::name(7, 'fi', self::TITLE));
    }

    public function testTheOtherLanguagesNameWhenOneIsMissing(): void
    {
        $this->fakeEventNames([7 => ['en' => 'Ecstatic Dance'], 8 => ['fi' => 'Tanssi-ilta']]);

        self::assertSame('Ecstatic Dance', EventName::name(7, 'fi', self::TITLE));
        self::assertSame('Tanssi-ilta', EventName::name(8, 'en', 'Old title'));
    }

    public function testNoPostKeepsTheFallback(): void
    {
        $this->fakeEventNames([0 => ['en' => 'Never']], [0 => ['Never']]);

        self::assertSame('Label', EventName::name(0, 'en', 'Label'));
        self::assertSame('', EventName::djLine(0));
    }

    public function testTheDjLineForOneTwoAndMoreDjs(): void
    {
        $this->fakeEventNames(
            [1 => ['en' => 'A'], 2 => ['en' => 'B'], 3 => ['fi' => 'C']],
            [
                1 => ['Esimerkki'],
                2 => ['Aino Testinen', ' Bo Kokeilu '],
                3 => ['A', '', 'B', 'C'],
            ]
        );

        self::assertSame('DJ Esimerkki', EventName::djLine(1));
        self::assertSame('DJs Aino Testinen & Bo Kokeilu', EventName::djLine(2));
        self::assertSame('DJs A, B & C', EventName::djLine(3));
    }

    public function testTheDjLineIsTheSameInFinnish(): void
    {
        $this->fakeEventNames([1 => ['en' => 'A'], 2 => ['en' => 'B']], [1 => ['X'], 2 => ['X', 'Y']]);

        self::assertSame('DJ X', Language::run(Language::FINNISH, static fn (): string => EventName::djLine(1)));
        self::assertSame('DJs X & Y', Language::run(Language::FINNISH, static fn (): string => EventName::djLine(2)));
    }

    public function testOneLineJoinsTheNameAndTheDjsOrKeepsTheNameWhenTooLong(): void
    {
        $this->fakeEventNames([7 => ['en' => 'Ecstatic Dance']], [7 => ['Aino Testinen', 'Bo Kokeilu']]);

        self::assertSame(
            'Ecstatic Dance · DJs Aino Testinen & Bo Kokeilu',
            EventName::oneLine(7, 'en', self::TITLE)
        );
        self::assertSame('Ecstatic Dance', EventName::oneLine(7, 'en', self::TITLE, 40));
    }

    public function testTheLanguageIsTheOneBeingWrittenIn(): void
    {
        self::assertSame('en', EventName::lang());
        self::assertSame('fi', Language::run(Language::FINNISH, static fn (): string => EventName::lang()));
    }

    public function testATaskWithoutAnEventKeepsItsLabel(): void
    {
        $this->fakeEventNames([7 => ['en' => 'Ecstatic Dance']], [7 => ['X']]);

        $task = new Task(1, '2026-11-14', 'door', 2, null, 'Picnic');

        self::assertSame(['name' => 'Picnic', 'dj' => ''], EventName::ofTask($task, 'en'));
    }

    public function testATaskWithAnEventGetsTheCleanNameAndDjs(): void
    {
        $this->fakeEventNames([7 => ['en' => 'Ecstatic Dance']], [7 => ['X']]);
        Functions\when('get_the_title')->justReturn(self::TITLE);

        $task = new Task(1, '2026-11-14', 'door', 2, 7);

        self::assertSame(['name' => 'Ecstatic Dance', 'dj' => 'DJ X'], EventName::ofTask($task, 'en'));
    }
}

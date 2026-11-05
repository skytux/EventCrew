<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\EventSource;
use EventCrew\Tests\TestCase;

final class EventSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('post_type_exists')->justReturn(true);

        // Deliberately a timezone that is NOT UTC: if any conversion crept
        // back into the consumption path, a +03:00 shift would show up in the
        // asserted times and fail the test. That it does not is the point.
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('Europe/Helsinki'));
    }

    private function stubEvent(string $startsAt, string $endsAt): void
    {
        $post = (object) [
            'ID' => 12,
            'post_type' => 'eventmesh_event',
            'post_title' => 'Synced Event',
        ];

        Functions\when('get_post')->justReturn($post);
        Functions\when('get_post_meta')->alias(
            static fn (int $id, string $key): string => match ($key) {
                '_eventmesh_starts_at' => $startsAt,
                '_eventmesh_ends_at' => $endsAt,
                default => '',
            }
        );
    }

    /**
     * EventMesh stores a naive wall-clock; EventCrew must take it verbatim.
     * A source "doors open 21:00" stays 21:00 - never shifted to 00:00 by a
     * timezone conversion of a value that was never in UTC to begin with.
     */
    /** Q209: the event a new task's form starts on. */
    public function testANewTaskStartsOnTheNextEventOrTheEventOfTheShownDate(): void
    {
        $event = static fn (int $id, string $date, string $starts): array => [
            'id' => $id,
            'title' => 'E' . $id,
            'starts_at' => '' === $starts ? '' : $date . ' ' . $starts,
            'ends_at' => '',
            'date' => $date,
        ];
        $events = [
            $event(1, '2026-07-10', '21:00:00'),
            $event(2, '2026-07-25', ''),
            $event(3, '2026-08-01', '20:00:00'),
        ];

        // The next event with a start, from today on.
        self::assertSame(3, EventSource::forNewTask($events, '2026-07-20')['id'] ?? null);
        // The event of the shown date, also a past one.
        self::assertSame(1, EventSource::forNewTask($events, '2026-07-20', '2026-07-10')['id'] ?? null);
        // No event on the shown date, or none ahead: none.
        self::assertNull(EventSource::forNewTask($events, '2026-07-20', '2026-07-11'));
        self::assertNull(EventSource::forNewTask($events, '2026-08-02'));
    }

    public function testTakesTheStoredWallClockVerbatim(): void
    {
        $this->stubEvent('2026-08-01T21:00:00', '2026-08-02T01:00:00');

        $event = EventSource::describe(12);

        self::assertNotNull($event);
        self::assertSame('2026-08-01 21:00:00', $event['starts_at']);
        self::assertSame('2026-08-02 01:00:00', $event['ends_at']);
        self::assertSame('2026-08-01', $event['date']);
    }

    /**
     * A value still stored in the shorter "…T18:00" form (no seconds) is
     * normalised to the MySQL DATETIME shape without moving the clock.
     */
    public function testNormalisesAShortFormWithoutShifting(): void
    {
        $this->stubEvent('2026-08-01T18:00', '');

        $event = EventSource::describe(12);

        self::assertNotNull($event);
        self::assertSame('2026-08-01 18:00:00', $event['starts_at']);
        self::assertSame('', $event['ends_at']);
    }

    /**
     * A hand-typed override on the event still wins over the scraped value,
     * and is likewise taken verbatim.
     */
    public function testAManualOverrideStillWinsAndIsNotShifted(): void
    {
        $post = (object) ['ID' => 12, 'post_type' => 'eventmesh_event', 'post_title' => 'E'];

        Functions\when('get_post')->justReturn($post);
        Functions\when('get_post_meta')->alias(
            static fn (int $id, string $key): string => match ($key) {
                '_eventmesh_manual_starts_at' => '2026-08-01T19:30:00',
                '_eventmesh_starts_at' => '2026-08-01T21:00:00',
                default => '',
            }
        );

        $event = EventSource::describe(12);

        self::assertNotNull($event);
        self::assertSame('2026-08-01 19:30:00', $event['starts_at']);
    }

    /**
     * EventMesh's Finnish title is used only while it was made from the
     * current title and text (the stored hash matches), and never for an
     * event that is already in Finnish.
     */
    public function testTheTranslatedTitleIsUsedOnlyWhileItIsCurrent(): void
    {
        $post = (object) [
            'ID' => 12,
            'post_type' => 'eventmesh_event',
            'post_title' => 'Summer Dance',
            'post_content' => 'Body',
        ];
        $meta = [
            '_eventmesh_original_lang' => 'en',
            '_eventmesh_tr_fi_hash' => md5("Summer Dance\0Body"),
            '_eventmesh_tr_fi_title' => 'Kesätanssit',
        ];

        Functions\when('get_post')->justReturn($post);
        Functions\when('get_post_meta')->alias(
            static function (int $id, string $key) use (&$meta): string {
                return $meta[$key] ?? '';
            }
        );

        self::assertSame('Kesätanssit', EventSource::translatedTitle(12, 'fi'));
        self::assertSame('', EventSource::translatedTitle(12, 'en'));

        $meta['_eventmesh_tr_fi_hash'] = md5("Old Title\0Body");
        self::assertSame('', EventSource::translatedTitle(12, 'fi'));
    }

    /**
     * @param array<string, string> $meta
     */
    private function stubCardEvent(string $content, string $excerpt, array &$meta): void
    {
        $post = (object) [
            'ID' => 12,
            'post_type' => 'eventmesh_event',
            'post_title' => 'Summer Dance',
            'post_content' => $content,
            'post_excerpt' => $excerpt,
        ];

        Functions\when('get_post')->justReturn($post);
        Functions\when('get_post_meta')->alias(
            static function (int $id, string $key) use (&$meta): string {
                return $meta[$key] ?? '';
            }
        );
    }

    /**
     * The card's description is the whole text, not EventMesh's excerpt,
     * which is cut to 55 words. The excerpt is used only when there is no
     * text.
     */
    public function testTheCardShowsTheWholeTextNotTheExcerpt(): void
    {
        $long = implode(' ', array_fill(0, 80, 'dance')) . ' until the end.';
        $meta = ['_eventmesh_original_lang' => 'en'];
        $this->stubCardEvent('<p>' . $long . '</p>', implode(' ', array_fill(0, 55, 'dance')), $meta);

        $card = EventSource::card(12);

        self::assertSame($long, $card['excerpt_en']);
        self::assertSame('', $card['excerpt']);

        $this->stubCardEvent('', 'Only the excerpt.', $meta);

        self::assertSame('Only the excerpt.', EventSource::card(12)['excerpt_en']);
    }

    /**
     * A <br> and the end of a paragraph, div, list item or heading become a
     * line break; spaces inside a line are squeezed, each line is trimmed,
     * and more than one blank line becomes one.
     */
    public function testTheCardKeepsTheLineBreaks(): void
    {
        $content = "<h2>Summer  Dance</h2>\n\n<p>Doors \t open at 21.<br>Music &amp; dancing.</p>\n\n\n\n"
            . "<ul><li>Lesson first</li><li> Then  the party </li></ul>\n<div>The end</div>";
        $meta = [];
        $this->stubCardEvent($content, '', $meta);

        self::assertSame(
            "Summer Dance\n\nDoors open at 21.\nMusic & dancing.\n\nLesson first\nThen the party\n\nThe end",
            EventSource::card(12)['excerpt']
        );
    }

    /**
     * The other language is EventMesh's translation of the whole text, kept
     * with its lines, under the same current-hash rule as the title. Its
     * translated excerpt is used only when there is no translated text.
     */
    public function testTheCardUsesTheTranslatedText(): void
    {
        $content = '<p>We dance all night.</p><p>Bring shoes.</p>';
        $meta = [
            '_eventmesh_original_lang' => 'en',
            '_eventmesh_tr_fi_hash' => md5("Summer Dance\0" . $content),
            '_eventmesh_tr_fi_content' => "<p>Tanssimme koko yön.</p>\n\n<p>Ota kengät mukaan.</p>",
            '_eventmesh_tr_fi_excerpt' => 'Tanssimme',
        ];
        $this->stubCardEvent($content, 'We dance', $meta);

        $card = EventSource::card(12);

        self::assertSame("We dance all night.\nBring shoes.", $card['excerpt_en']);
        self::assertSame("Tanssimme koko yön.\n\nOta kengät mukaan.", $card['excerpt_fi']);

        $meta['_eventmesh_tr_fi_content'] = '';
        self::assertSame('Tanssimme', EventSource::card(12)['excerpt_fi']);

        $meta['_eventmesh_tr_fi_content'] = 'Tanssimme koko yön.';
        $meta['_eventmesh_tr_fi_hash'] = md5("Summer Dance\0Old text");
        self::assertSame('', EventSource::card(12)['excerpt_fi']);
    }
}

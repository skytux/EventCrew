<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\EmailBody;
use EventCrew\Support\Standing;
use EventCrew\Support\StandingBar;
use EventCrew\Tests\TestCase;

/**
 * The standing bar: one bar coloured by the zone around the good-standing
 * limit (60% by default, 65% live; orange up to ten points above it), the
 * text bar for Telegram, the HTML bar the email draws from it, and the
 * before/after sentence.
 */
final class StandingBarTest extends TestCase
{
    public function testTheZonesSitAroundTheLimit(): void
    {
        self::assertSame(60, StandingBar::limit());
        self::assertSame(70, StandingBar::warningTop());
        self::assertSame(StandingBar::ZONE_DANGER, StandingBar::zone(0));
        self::assertSame(StandingBar::ZONE_DANGER, StandingBar::zone(59));
        self::assertSame(StandingBar::ZONE_WARNING, StandingBar::zone(60));
        self::assertSame(StandingBar::ZONE_WARNING, StandingBar::zone(69));
        self::assertSame(StandingBar::ZONE_GOOD, StandingBar::zone(70));
        self::assertSame(StandingBar::ZONE_GOOD, StandingBar::zone(100));
    }

    public function testTheBoundariesAtTheLiveLimit(): void
    {
        self::assertSame(StandingBar::ZONE_DANGER, StandingBar::zone(64, 65));
        self::assertSame(StandingBar::ZONE_WARNING, StandingBar::zone(65, 65));
        self::assertSame(StandingBar::ZONE_WARNING, StandingBar::zone(74, 65));
        self::assertSame(StandingBar::ZONE_GOOD, StandingBar::zone(75, 65));
        self::assertSame(StandingBar::ZONE_GOOD, StandingBar::zone(85, 65));
    }

    public function testTheStateSaysWhereTheScoreIs(): void
    {
        self::assertSame('85% · Good standing', StandingBar::label(85, 65));
        self::assertSame('70% · Close to the 65% limit', StandingBar::label(70, 65));
        self::assertSame('65% · Close to the 65% limit', StandingBar::label(65, 65));
        self::assertSame('60% · Under the 65% limit', StandingBar::label(60, 65));
        self::assertSame('Good standing', StandingBar::state(75, 65));
    }

    public function testTheTextBarFillsACellPerTenPercentInTheZoneColour(): void
    {
        self::assertSame('🟩🟩🟩🟩🟩🟩🟩🟩⬜⬜ 85% · Good standing', StandingBar::text(85, 65));
        self::assertSame('🟧🟧🟧🟧🟧🟧🟧⬜⬜⬜ 70% · Close to the 65% limit', StandingBar::text(70, 65));
        self::assertSame('🟥🟥🟥🟥🟥🟥⬜⬜⬜⬜ 60% · Under the 65% limit', StandingBar::text(60, 65));
        self::assertSame('🟥🟥🟥🟥🟥🟥⬜⬜⬜⬜ 64% · Under the 65% limit', StandingBar::text(64, 65));
        self::assertSame('🟧🟧🟧🟧🟧🟧🟧⬜⬜⬜ 74% · Close to the 65% limit', StandingBar::text(74, 65));
        self::assertSame('🟩🟩🟩🟩🟩🟩🟩⬜⬜⬜ 75% · Good standing', StandingBar::text(75, 65));
        self::assertSame('⬜⬜⬜⬜⬜⬜⬜⬜⬜⬜ 0% · Under the 60% limit', StandingBar::text(0));
        self::assertSame('🟩🟩🟩🟩🟩🟩🟩🟩🟩🟩 100% · Good standing', StandingBar::text(100));
        // No separate limit mark in the text form.
        self::assertStringNotContainsString('│', StandingBar::text(85, 65));
    }

    public function testATextBarIsReadBackOnlyWhenTheLineIsOne(): void
    {
        foreach ([0, 59, 60, 65, 74, 75, 85, 100] as $percent) {
            self::assertSame($percent, StandingBar::parse(StandingBar::text($percent)));
        }

        self::assertNull(StandingBar::parse('Standing 74%.'));
        self::assertNull(StandingBar::parse('🟩 hello 74%'));
        self::assertNull(StandingBar::parse('🟩🟩 74% · Good standing'));
    }

    public function testTheEmailDrawsOneBarInTheZoneColourWithATick(): void
    {
        $html = EmailBody::toHtml("Hi Sam,\n\n" . StandingBar::text(85) . "\n\nStanding 82% → 85%.");

        self::assertStringContainsString('<table role="presentation"', $html);
        self::assertStringContainsString('background:#2e9d4a', $html);   // green fill
        self::assertStringNotContainsString('background:#d9534f', $html); // no zones strip
        self::assertStringNotContainsString('background:#ee8a1a', $html);
        self::assertStringNotContainsString('#d4a017', $html);            // no gold
        self::assertStringContainsString('border-left:2px solid', $html); // the limit
        self::assertStringContainsString('85%</strong> &nbsp;·&nbsp; Good standing', $html);
        self::assertStringNotContainsString('🟩', $html);
        // One row of cells only.
        self::assertSame(1, preg_match_all('/<tr><td width=/', $html));
        // The plain-text part keeps the text bar.
        self::assertStringContainsString('🟩', EmailBody::toText(StandingBar::text(85)));
    }

    public function testTheEmailBarColourFollowsTheZone(): void
    {
        self::assertStringContainsString('background:#ee8a1a', StandingBar::html(70, 65));
        self::assertStringContainsString('Close to the 65% limit', StandingBar::html(70, 65));
        self::assertStringContainsString('background:#d9534f', StandingBar::html(60, 65));
        self::assertStringContainsString('Under the 65% limit', StandingBar::html(60, 65));
        self::assertStringContainsString('background:#2e9d4a', StandingBar::html(85, 65));
    }

    public function testTheEmailBarWidthsAddUpAtTheEdges(): void
    {
        foreach ([0, 59, 60, 65, 85, 100] as $percent) {
            preg_match_all('/<tr>(<td width=.*?)<\/tr>/', StandingBar::html($percent), $rows);
            preg_match_all('/width="(\d+)%"/', $rows[1][0], $widths);

            self::assertSame(100, array_sum(array_map('intval', $widths[1])), (string) $percent);
        }
    }

    public function testTheChangeGivesBeforeAfterAndWhatItMeans(): void
    {
        $before = new Standing(Standing::GOOD, 0.82, 6, 2, 7);

        self::assertSame(
            'Standing 82% → 74%. Good standing is 60% or more, so every task is open to you.',
            StandingBar::change($before, new Standing(Standing::GOOD, 0.74, 6, 2, 8))
        );
        self::assertSame(
            'Standing 82% → 65%. Still good standing, but close to the 60% limit.',
            StandingBar::change($before, new Standing(Standing::GOOD, 0.65, 6, 2, 8))
        );
        self::assertSame(
            'Standing 82% → 59%. Below 60%, you cannot sign up for new tasks until you talk to an organizer.',
            StandingBar::change($before, new Standing(Standing::AT_RISK, 0.59, 6, 2, 8))
        );
        // Nothing to compare with: the score alone.
        self::assertStringStartsWith('Standing 74%. ', StandingBar::change(null, new Standing(Standing::GOOD, 0.74, 3, 1, 3)));
    }

    public function testNoBarAndHowManyTasksToGoWhileNotRated(): void
    {
        $unrated = new Standing(Standing::UNRATED, 0.0, 1, 0, 1);

        self::assertSame('', StandingBar::forStanding($unrated));
        self::assertSame('You get a standing score after 2 more tasks.', StandingBar::change(null, $unrated));
        self::assertSame(
            'You get a standing score after 1 more task.',
            StandingBar::change(null, new Standing(Standing::UNRATED, 0.0, 2, 1, 2))
        );
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\EmailBody;
use EventCrew\Support\Standing;
use EventCrew\Support\StandingBar;
use EventCrew\Tests\TestCase;

/**
 * The standing bar: the zones around the good-standing limit (60% by
 * default, warning up to 70%), the text bar for Telegram, the HTML bar the
 * email draws from it, and the before/after sentence.
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
        self::assertSame(StandingBar::ZONE_WARNING, StandingBar::zone(65));
        self::assertSame(StandingBar::ZONE_GOOD, StandingBar::zone(70));
        self::assertSame(StandingBar::ZONE_GOOD, StandingBar::zone(100));
    }

    public function testTheTextBarFillsACellPerTenPercentAndMarksTheLimit(): void
    {
        self::assertSame('⬜⬜⬜⬜⬜⬜│⬜⬜⬜⬜ 0%', StandingBar::text(0));
        self::assertSame('🟥🟥🟥🟥🟥⬜│⬜⬜⬜⬜ 59%', StandingBar::text(59));
        self::assertSame('🟧🟧🟧🟧🟧🟧│⬜⬜⬜⬜ 60%', StandingBar::text(60));
        self::assertSame('🟧🟧🟧🟧🟧🟧│⬜⬜⬜⬜ 65%', StandingBar::text(65));
        self::assertSame('🟨🟨🟨🟨🟨🟨│🟨🟨🟨🟨 100%', StandingBar::text(100));
        self::assertSame('🟨🟨🟨🟨🟨🟨│🟨⬜⬜⬜ 74%', StandingBar::text(74));
        // Under 20 visible characters.
        self::assertLessThan(20, mb_strlen(StandingBar::text(100)));
    }

    public function testATextBarIsReadBackOnlyWhenTheLineIsOne(): void
    {
        foreach ([0, 59, 60, 65, 100] as $percent) {
            self::assertSame($percent, StandingBar::parse(StandingBar::text($percent)));
        }

        self::assertNull(StandingBar::parse('Standing 74%.'));
        self::assertNull(StandingBar::parse('🟨 hello 74%'));
    }

    public function testTheEmailDrawsTheBarAsATableWithZonesAndATick(): void
    {
        $html = EmailBody::toHtml("Hi Sam,\n\n" . StandingBar::text(74) . "\n\nStanding 82% → 74%.");

        self::assertStringContainsString('<table role="presentation"', $html);
        self::assertStringContainsString('background:#d4a017', $html);   // gold fill
        self::assertStringContainsString('background:#d9534f', $html);   // danger zone
        self::assertStringContainsString('background:#f0ad4e', $html);   // warning zone
        self::assertStringContainsString('border-left:2px solid', $html); // the limit
        self::assertStringContainsString('Good standing from 60%', $html);
        self::assertStringNotContainsString('🟨', $html);
        // The plain-text part keeps the text bar.
        self::assertStringContainsString('🟨', EmailBody::toText(StandingBar::text(74)));
    }

    public function testTheEmailBarWidthsAddUpAtTheEdges(): void
    {
        foreach ([0, 59, 60, 65, 100] as $percent) {
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

<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\HelpPage;
use EventCrew\Tests\TestCase;

/**
 * The Help tab draws the welcome text and the organizer guide as page HTML.
 */
final class HelpPageTest extends TestCase
{
    public function testHeadingsListsAndParagraphs(): void
    {
        $html = HelpPage::html("Intro <b>.\n\n## 🎟️ Tickets\n\n- 🔹 One\n- 🔹 Two\n\n- plain");

        self::assertStringContainsString('<p>Intro &lt;b&gt;.</p>', $html);
        self::assertStringContainsString('<h3 class="eventcrew-help-heading" id="ec-tickets">🎟️ Tickets</h3>', $html);
        self::assertStringContainsString('<ul class="eventcrew-help-list is-emoji"><li>🔹 One</li><li>🔹 Two</li></ul>', $html);
        self::assertStringContainsString('<ul class="eventcrew-help-list"><li>plain</li></ul>', $html);
    }

    public function testAContentsLinkBecomesAPageLink(): void
    {
        self::assertStringContainsString(
            '<a href="#ec-tickets"',
            HelpPage::html('- 🎟️ [Tickets](#ec-tickets)')
        );
    }

    public function testCardsPutEachSectionInItsOwnCard(): void
    {
        $html = HelpPage::cards("Intro.\n\n## 🎟️ Tickets\n\nOne.\n\n## 🎁 Credits\n\n- Two");

        self::assertSame(3, substr_count($html, '<section class="eventcrew-event eventcrew-help-card">'));
        // Text before the first heading: a card without a header.
        self::assertStringStartsWith('<section class="eventcrew-event eventcrew-help-card"><div class="eventcrew-card-body"><p>Intro.</p>', $html);
        // The heading keeps the id contents links point at.
        self::assertStringContainsString(
            '<header class="eventcrew-event-head"><h3 class="eventcrew-event-title eventcrew-help-heading" id="ec-tickets">🎟️ Tickets</h3></header><div class="eventcrew-card-body"><p>One.</p></div>',
            $html
        );
        self::assertStringContainsString('<ul class="eventcrew-help-list"><li>Two</li></ul>', $html);
    }

    public function testTheInfrastructureTextIsTheTrimmedOption(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => HelpPage::INFRASTRUCTURE_OPTION === $name ? "  Hosted here.\n" : $default
        );

        self::assertSame('Hosted here.', HelpPage::infrastructure());
    }

    public function testTheUrlNeedsACrewPage(): void
    {
        Functions\when('get_option')->justReturn(0);
        self::assertSame('', HelpPage::url());

        Functions\when('get_option')->justReturn(7);
        Functions\when('get_permalink')->justReturn('https://site.test/crew/');
        self::assertSame('https://site.test/crew/#eventcrew-help-organizer', HelpPage::url(HelpPage::ORGANIZER_ANCHOR));
    }
}

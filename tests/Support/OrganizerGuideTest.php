<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\OrganizerGuide;
use EventCrew\Support\ReputationSettings;
use EventCrew\Support\SignupService;
use EventCrew\Tests\Telegram\TelegramTestCase;

/**
 * The organizer guide: an "In short" part, then the details, with the live
 * numbers filled in.
 */
final class OrganizerGuideTest extends TelegramTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Brain\Monkey\Functions\when('admin_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-admin/' . $path
        );
    }

    public function testTheShortPartComesFirstUnderItsHeading(): void
    {
        self::assertStringStartsWith('## ⚡ In short', OrganizerGuide::short());
    }

    public function testEveryContentsLinkPointsAtAHeadingOfTheGuide(): void
    {
        preg_match_all('/\(#(ec-[a-z0-9-]+)\)/', OrganizerGuide::contents(), $links);
        preg_match_all('/^## (.+)$/m', OrganizerGuide::short() . "\n\n" . OrganizerGuide::details(), $headings);
        $anchors = array_map([\EventCrew\Support\EmailBody::class, 'anchor'], $headings[1]);

        self::assertCount(8, $links[1]);
        self::assertSame($anchors, $links[1]);
        self::assertStringStartsWith('## 📑 Contents', OrganizerGuide::full());
    }

    public function testTheGuideLinksToWpAdmin(): void
    {
        $url = 'https://site.test/wp-admin/admin.php?page=eventcrew';

        self::assertStringContainsString('Open EventCrew in wp-admin: ' . $url, OrganizerGuide::short());
        self::assertStringContainsString('Open EventCrew in wp-admin: ' . $url, OrganizerGuide::details());
    }

    public function testTheGuideExplainsTheTicketBuyerImportByEmail(): void
    {
        self::assertStringContainsString('email the list of names to import.edh@haissous.name', OrganizerGuide::short());
        self::assertStringContainsString('Or email the list to import.edh@haissous.name', OrganizerGuide::full());
        self::assertStringContainsString('how many names were added and how many were skipped', OrganizerGuide::full());
    }

    public function testTheShortPartUsesTheLiveCreditSetting(): void
    {
        $this->options[ReputationSettings::TASKS_PER_CREDIT_OPTION] = 6;

        self::assertStringContainsString('1 free-entry credit per 6 completed tasks', OrganizerGuide::short());
    }

    public function testTheDetailsUseTheLiveNoticeHours(): void
    {
        $this->options[SignupService::NOTICE_HOURS_OPTION] = 36;

        self::assertStringContainsString('Cancelling less than 36 hours before', OrganizerGuide::details());
    }

    public function testTheGateSentenceOnlyWhileTheGateIsOn(): void
    {
        $this->options[SignupService::GATE_OPTION] = '0';
        self::assertStringNotContainsString('The sign-up gate is on', OrganizerGuide::details());

        $this->options[SignupService::GATE_OPTION] = '1';
        self::assertStringContainsString('The sign-up gate is on', OrganizerGuide::details());
    }
}

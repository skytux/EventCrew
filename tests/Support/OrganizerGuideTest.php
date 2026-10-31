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
    public function testTheShortPartComesFirstUnderItsHeading(): void
    {
        self::assertStringStartsWith('## In short', OrganizerGuide::short());
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

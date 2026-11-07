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

        self::assertCount(10, $links[1]);
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
        $this->options[\EventCrew\MailImport\MailedListImport::MAILBOX_OPTION] = 'import@example.org';

        self::assertStringContainsString('email the ticket file to import@example.org', OrganizerGuide::short());
        self::assertStringContainsString('Or email the Holvi ticket file (.xlsx or .csv) to import@example.org', OrganizerGuide::full());
        self::assertStringContainsString('go to that event’s guest list instead; write "+2" after a name for two plus-ones', OrganizerGuide::full());
        self::assertStringContainsString('are emailed to the buyers', OrganizerGuide::short());
        self::assertStringContainsString('the guest gets a ticket by email', OrganizerGuide::full());
    }

    public function testTheGuideSaysTicketsAreEmailedAutomaticallyAfterImport(): void
    {
        $full = OrganizerGuide::full();

        self::assertStringContainsString('every imported ticket with an email address is emailed to its buyer automatically', $full);
        self::assertStringContainsString('"Send a test to me"', $full);
        self::assertStringContainsString('"Send the rest now"', $full);
    }

    public function testWithoutAnImportMailboxTheGuideLeavesTheEmailImportOut(): void
    {
        unset($this->options[\EventCrew\MailImport\MailedListImport::MAILBOX_OPTION]);

        self::assertStringNotContainsString('📧', OrganizerGuide::short());
        self::assertStringNotContainsString('Or email the Holvi ticket file', OrganizerGuide::full());
        self::assertStringContainsString('paste the names, one per line', OrganizerGuide::full());
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

    public function testTheGuideAndTheDmHaveTheAiSection(): void
    {
        $full = OrganizerGuide::full();

        self::assertStringContainsString('## 🤖 AI in EventCrew', $full);
        self::assertStringContainsString('Off: untick "Answer plain messages with the language model"', $full);
        self::assertStringContainsString('Off: untick "Texts by the language model" in Settings > Email', $full);
        self::assertStringContainsString('EventCrew > Messages > Coming events', $full);
        self::assertStringContainsString('The ticket file import by email does not use AI.', $full);
        self::assertStringContainsString('## 🤖 AI in EventCrew', OrganizerGuide::dm());
        self::assertStringContainsString('as a preview you can stop', OrganizerGuide::dm());
        self::assertStringStartsWith(OrganizerGuide::short(false), OrganizerGuide::dm());
    }

    public function testTheNotSetUpLineOnlyWithoutAnAiService(): void
    {
        self::assertStringContainsString('No AI service is set up at the moment', OrganizerGuide::details());

        $this->options[\EventCrew\Support\AssistantSettings::URL_OPTION] = 'https://llm.test/v1';
        $this->options[\EventCrew\Support\AssistantSettings::MODEL_OPTION] = 'test-model';

        self::assertStringNotContainsString('No AI service is set up at the moment', OrganizerGuide::details());
    }
}

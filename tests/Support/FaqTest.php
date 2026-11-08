<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\ExtraTaskCredits;
use EventCrew\Support\Faq;
use EventCrew\Support\Language;
use EventCrew\Support\ReputationSettings;
use EventCrew\Support\SecondThoughts;
use EventCrew\Support\SignupService;
use EventCrew\Support\TicketTransferService;
use EventCrew\Tests\TestCase;

/**
 * The crew FAQ (Owner 2026-11-08): one source for the bot and the web, its
 * numbers from the live settings and its lines following the switches.
 */
final class FaqTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
    }

    /**
     * @return array<string, string> question => answer
     */
    private function faq(): array
    {
        return array_column(Faq::items(), 'a', 'q');
    }

    public function testWithTheDefaultsItHasEveryQuestion(): void
    {
        $faq = $this->faq();

        self::assertCount(16, $faq);
        self::assertSame('How do I sign up for a task?', array_key_first($faq));
        self::assertArrayHasKey('Why is a credit pending?', $faq);
        self::assertStringContainsString('tap Give away', $faq['Can I cancel a free-entry ticket?']);
        self::assertStringContainsString('Below that, you cannot sign up', $faq['How does standing work?']);
        self::assertStringContainsString('Within 5 minutes of taking a task', $faq['How do I cancel a task?']);
        self::assertStringContainsString('Cancelling closes 5 minutes before', $faq['How do I cancel a task?']);

        foreach (Faq::items() as $item) {
            self::assertNotSame('', trim($item['q']));
            self::assertNotSame('', trim($item['a']));
        }
    }

    public function testTheNumbersComeFromTheSettings(): void
    {
        $this->options[SignupService::NOTICE_HOURS_OPTION] = 24;
        $this->options[ReputationSettings::TASKS_PER_CREDIT_OPTION] = 3;

        $faq = $this->faq();

        self::assertStringStartsWith('Cancelling 24 hours or more before has no effect.', $faq['What if I cancel late?']);
        self::assertStringStartsWith('Every 3 completed tasks earn 1 free-entry credit.', $faq['How do I get free entry?']);
    }

    public function testSwitchedOffFeaturesLeaveTheirLinesOut(): void
    {
        $this->options[ExtraTaskCredits::OPTION] = '0';
        $this->options[TicketTransferService::OPTION] = '0';
        $this->options[SignupService::GATE_OPTION] = '0';
        $this->options[SecondThoughts::OPTION] = 0;
        $this->options[SignupService::SIGNOUT_LOCK_OPTION] = 0;

        $faq = $this->faq();

        self::assertCount(15, $faq);
        self::assertArrayNotHasKey('Why is a credit pending?', $faq);
        self::assertSame('No, and the credit stays spent.', $faq['Can I cancel a free-entry ticket?']);
        self::assertStringNotContainsString('give', $faq['I already have a ticket for that night. Can I still take a task?']);
        self::assertStringNotContainsString('Below that', $faq['How does standing work?']);
        self::assertSame('Open Tasks and tap Cancel next to the task.', $faq['How do I cancel a task?']);
    }

    public function testItIsTranslated(): void
    {
        $items = Language::run('fi', static fn (): array => Faq::items());

        self::assertSame('Miten ilmoittaudun tehtävään?', $items[0]['q']);
    }
}

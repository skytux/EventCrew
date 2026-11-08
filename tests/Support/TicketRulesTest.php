<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\ExtraTaskCredits;
use EventCrew\Support\TicketRules;
use EventCrew\Support\TicketTransferService;
use EventCrew\Tests\TestCase;

/**
 * The ticket and credit rules follow the live settings in every place that
 * explains them.
 */
final class TicketRulesTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
    }

    public function testWithBothSettingsOnEveryRuleIsListed(): void
    {
        $member = implode("\n", TicketRules::memberLines());

        self::assertStringContainsString('working ticket covers all your tasks', $member);
        self::assertStringContainsString('you can give it to someone else', $member);
        self::assertStringContainsString('Each extra task on the same night', $member);
        self::assertStringEndsWith(TicketRules::policyLine(), $member);
        self::assertCount(5, TicketRules::organizerLines());
        foreach (TicketRules::organizerLines() as $line) {
            self::assertStringContainsString('Why:', $line);
        }
        self::assertStringContainsString('extra task', implode("\n", TicketRules::assistantFacts()));
    }

    public function testWithBothSettingsOffTheirLinesAreLeftOut(): void
    {
        $this->options[TicketTransferService::OPTION] = '0';
        $this->options[ExtraTaskCredits::OPTION] = '0';

        $member = implode("\n", TicketRules::memberLines());

        self::assertStringContainsString('A free-entry ticket can’t be cancelled.', $member);
        self::assertStringNotContainsString('someone else', $member);
        self::assertStringNotContainsString('extra task', $member);
        self::assertCount(4, TicketRules::organizerLines());
        self::assertStringNotContainsString('extra task', implode("\n", TicketRules::assistantFacts()));
    }
}

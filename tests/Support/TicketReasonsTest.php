<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\Language;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\TicketReasons;
use EventCrew\Tests\TestCase;

/**
 * The four {reason} lines of the ticket email (Q285): the built-in lines,
 * the lines saved in Settings > Email, and an empty field falling back to
 * the built-in line. Options live in an array.
 */
final class TicketReasonsTest extends TestCase
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
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);

            return true;
        });
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_text_field')->alias(static fn (string $value): string => trim($value));
    }

    private function reasons(): TicketReasons
    {
        return new TicketReasons(new LlmClient(new Logger()), new Logger());
    }

    public function testWithNothingSavedEachKindHasItsBuiltInLineInEachLanguage(): void
    {
        self::assertSame(
            'Thank you for buying a ticket!',
            TicketReasons::text(TicketReasons::BOUGHT, Language::ENGLISH)
        );
        self::assertSame('Kiitos, että ostit lipun!', TicketReasons::text(TicketReasons::BOUGHT, Language::FINNISH));
        self::assertSame(
            'Thank you for coming! You are on the guest list.',
            TicketReasons::text(TicketReasons::GUEST, Language::ENGLISH)
        );
        self::assertSame('Kiitos, että otit tehtävän!', TicketReasons::text(TicketReasons::TASK, Language::FINNISH));
        self::assertSame(
            'You spent a free-entry credit on this night.',
            TicketReasons::text(TicketReasons::CREDIT, Language::ENGLISH)
        );
        self::assertSame(
            TicketReasons::defaultValue(TicketReasons::GUEST),
            TicketReasons::settings(TicketReasons::GUEST)
        );
        self::assertCount(4, TicketReasons::translationRows());
    }

    public function testAnEditedLineIsStoredAndAnEmptyLanguageGetsTheBuiltInLine(): void
    {
        $this->options[\EventCrew\Support\TranslatedText::AUTO_OPTION] = '0';

        $error = $this->reasons()->saveSettings([
            TicketReasons::TASK => [
                'source' => 'Thanks for helping!',
                'lang' => Language::ENGLISH,
                'texts' => [Language::ENGLISH => 'Thanks for helping!', Language::FINNISH => 'Kiitos avusta!'],
            ],
        ]);

        self::assertSame('', $error);
        self::assertSame('Thanks for helping!', TicketReasons::text(TicketReasons::TASK, Language::ENGLISH));
        self::assertSame('Kiitos avusta!', TicketReasons::text(TicketReasons::TASK, Language::FINNISH));
        // Kinds not posted are not stored.
        self::assertArrayNotHasKey(TicketReasons::option(TicketReasons::BOUGHT), $this->options);

        // A language field emptied in the stored value: the built-in line in that language.
        $stored = $this->options[TicketReasons::option(TicketReasons::TASK)];
        $stored['texts'][Language::FINNISH] = '';
        $this->options[TicketReasons::option(TicketReasons::TASK)] = $stored;
        self::assertSame('Kiitos, että otit tehtävän!', TicketReasons::text(TicketReasons::TASK, Language::FINNISH));
    }

    public function testSavingTheBuiltInLineOrNothingStoresNothing(): void
    {
        $default = TicketReasons::defaultValue(TicketReasons::CREDIT);

        $this->reasons()->saveSettings([
            TicketReasons::CREDIT => ['source' => $default['source'], 'texts' => $default['texts']],
        ]);
        self::assertArrayNotHasKey(TicketReasons::option(TicketReasons::CREDIT), $this->options);

        $this->options[TicketReasons::option(TicketReasons::CREDIT)] = [
            'source' => 'Old',
            'texts' => ['fi' => 'Vanha', 'en' => 'Old'],
            'manual' => ['fi' => false, 'en' => false],
        ];
        $this->reasons()->saveSettings([
            TicketReasons::CREDIT => ['source' => '', 'texts' => ['fi' => '', 'en' => '']],
        ]);
        self::assertArrayNotHasKey(TicketReasons::option(TicketReasons::CREDIT), $this->options);
        self::assertSame(
            'You spent a free-entry credit on this night.',
            TicketReasons::text(TicketReasons::CREDIT, Language::ENGLISH)
        );
    }
}

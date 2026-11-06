<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\Language;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\TicketText;
use EventCrew\Tests\TestCase;

/**
 * The ticket email's text: the built-in text, the text in Settings, and the
 * placeholders. Options live in an array; the language model is a stub of
 * wp_remote_post.
 */
final class TicketTextTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    private string $answer = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->answer = '';

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
        Functions\when('sanitize_textarea_field')->alias(static fn (string $value): string => trim($value));
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string => (string) json_encode($value));
        Functions\when('wp_remote_post')->alias(fn (): array => ['answer' => $this->answer]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(
            static fn (array $response): string => (string) json_encode(
                ['choices' => [['message' => ['content' => $response['answer']]]]]
            )
        );
    }

    private function text(): TicketText
    {
        return new TicketText(new LlmClient(new Logger()), new Logger());
    }

    /** @return array<string, string> */
    private function values(array $override = []): array
    {
        return $override + [
            TicketText::FIRST_NAME_TAG => 'Anna',
            TicketText::EVENT_TAG => 'Salsa Night',
            TicketText::DATE_TAG => '14.11.2026',
            TicketText::REASON_TAG => 'Thank you for buying a ticket!',
            TicketText::TASKS_TAG => '',
            TicketText::PLUS_ONES_TAG => '',
        ];
    }

    /** With nothing saved, the text is the ticket email's old wording, in each language. */
    public function testWithNothingSavedTheTextIsTheOldWording(): void
    {
        self::assertSame(
            "*Hi {first_name},*\n\n{reason}\n\n"
                . "Here is your ticket to {event} on {date}. Show the QR code below at the door.\n\n"
                . "{venue}\n\n"
                . "Your tasks on this night:\n{tasks}\n\n"
                . 'You can bring {plus_ones} with you.',
            TicketText::text(Language::ENGLISH)
        );
        self::assertStringStartsWith('*Hei {first_name},*', TicketText::text(Language::FINNISH));
        self::assertStringContainsString('Mukaasi saa tulla {plus_ones}.', TicketText::text(Language::FINNISH));
        // The settings field shows the built-in text until one is saved.
        self::assertSame(TicketText::defaultValue(), TicketText::settings());
        self::assertSame(TicketText::text(Language::ENGLISH), TicketText::settings()['source']);
    }

    /** A buyer: the reason line, no tasks paragraph, no plus-ones paragraph. */
    public function testFillLeavesOutTheParagraphsOfEmptyPlaceholders(): void
    {
        self::assertSame(
            "*Hi Anna,*\n\nThank you for buying a ticket!\n\n"
                . 'Here is your ticket to Salsa Night on 14.11.2026. Show the QR code below at the door.',
            TicketText::fill(TicketText::text(Language::ENGLISH), $this->values())
        );
    }

    /** A crew member: the tasks become a list of their own under the line before them. */
    public function testFillPutsTheTasksInAListOfTheirOwn(): void
    {
        $filled = TicketText::fill(
            TicketText::text(Language::ENGLISH),
            $this->values([TicketText::TASKS_TAG => "- Bar, 22:00\n- Decorate, 18:00"])
        );

        self::assertStringContainsString("Your tasks on this night:\n\n- Bar, 22:00\n- Decorate, 18:00", $filled);
        self::assertStringNotContainsString('{', $filled);
    }

    /** A guest: how many people they may bring. */
    public function testFillWritesThePlusOnes(): void
    {
        $filled = TicketText::fill(
            TicketText::text(Language::ENGLISH),
            $this->values([TicketText::PLUS_ONES_TAG => '2 people'])
        );

        self::assertStringEndsWith("\n\nYou can bring 2 people with you.", $filled);
    }

    /** No first name: the name goes with the space before it, the comma stays. */
    public function testAnEmptyFirstNameLeavesNoGap(): void
    {
        $filled = TicketText::fill(
            "*Hi {first_name},*\n\n{first_name} welcome!\n\nSee you, {first_name}.",
            $this->values([TicketText::FIRST_NAME_TAG => ''])
        );

        self::assertSame("*Hi,*\n\nwelcome!\n\nSee you.", $filled);
    }

    /** A placeholder written inside a sentence, empty: the whole paragraph goes. */
    public function testAnEmptyPlaceholderInASentenceDropsTheParagraph(): void
    {
        $filled = TicketText::fill(
            "Hello.\n\nYou may bring {plus_ones}, and your tasks are {tasks}.\n\nBye.",
            $this->values()
        );

        self::assertSame("Hello.\n\nBye.", $filled);
    }

    public function testSavingTheBuiltInTextOrNothingStoresNothing(): void
    {
        $default = TicketText::defaultValue();

        $error = $this->text()->saveSettings(['source' => $default['source'], 'texts' => $default['texts']]);

        self::assertSame('', $error);
        self::assertArrayNotHasKey(TicketText::OPTION, $this->options);

        $this->options[TicketText::OPTION] = [
            'source' => 'Old',
            'texts' => ['fi' => 'Vanha', 'en' => 'Old'],
            'manual' => ['fi' => false, 'en' => false],
        ];
        $this->text()->saveSettings(['source' => '', 'texts' => ['fi' => '', 'en' => '']]);

        self::assertArrayNotHasKey(TicketText::OPTION, $this->options);
        // Not posted: nothing changes.
        $this->options[TicketText::OPTION] = ['source' => 'Kept'];
        self::assertSame('', $this->text()->saveSettings(null));
        self::assertSame(['source' => 'Kept'], $this->options[TicketText::OPTION]);
    }

    /** A new text is translated, stored and used in each language. */
    public function testANewTextIsTranslatedStoredAndUsed(): void
    {
        $this->options[AssistantSettings::URL_OPTION] = 'https://llm.example.test/v1';
        $this->options[AssistantSettings::MODEL_OPTION] = 'test-model';
        $this->answer = '{"lang": "en", "fi": "*Moi {first_name}!*\n\nLippusi iltaan {event}."}';

        $error = $this->text()->saveSettings([
            'source' => "*Hey {first_name}!*\n\nYour ticket to {event}.",
            'texts' => TicketText::defaultValue()['texts'],
        ]);

        self::assertSame('', $error);
        self::assertSame("*Hey {first_name}!*\n\nYour ticket to {event}.", TicketText::text(Language::ENGLISH));
        self::assertSame("*Moi {first_name}!*\n\nLippusi iltaan {event}.", TicketText::text(Language::FINNISH));
        self::assertSame(
            "*Moi Anna!*\n\nLippusi iltaan Salsa Night.",
            TicketText::fill(TicketText::text(Language::FINNISH), $this->values())
        );
    }
}

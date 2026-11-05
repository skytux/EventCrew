<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\HelpPage;
use EventCrew\Support\Language;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\Signature;
use EventCrew\Support\SiteTexts;
use EventCrew\Support\TranslatedText;
use EventCrew\Telegram\TicketController;
use EventCrew\Tests\TestCase;

/**
 * The signature, ticket note, infrastructure note and app name as
 * written-once texts. No language model is set up, so nothing is sent.
 */
final class SiteTextsTest extends TestCase
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
        Functions\when('wp_unslash')->returnArg();
        Functions\when('sanitize_textarea_field')->alias(static fn (string $text): string => trim($text));
        Functions\when('sanitize_text_field')->alias(static fn (string $text): string => trim($text));
    }

    /** A plain string stored before the change is still read, in every language. */
    public function testAnOldPlainOptionKeepsWorking(): void
    {
        $this->options[Signature::OPTION] = "  Barefoot and with love\n";
        $this->options[TicketController::NOTE_OPTION] = 'Ovikoodi 1234';

        self::assertSame('Barefoot and with love', Signature::text());
        self::assertSame('Barefoot and with love', Language::run('en', static fn (): string => Signature::text()));
        self::assertSame(['fi' => 'Ovikoodi 1234'], SiteTexts::allLanguages('ticket_note'));
        self::assertSame('', HelpPage::infrastructure());
    }

    /** A translated text is read in the reader's language, else the main language. */
    public function testTheReaderGetsTheirLanguage(): void
    {
        $this->options[HelpPage::INFRASTRUCTURE_OPTION] = [
            'source' => 'Hosted at home',
            'lang' => 'en',
            'detected' => true,
            'texts' => ['fi' => 'Kotona', 'en' => 'Hosted at home'],
            'manual' => ['fi' => false, 'en' => false],
        ];

        self::assertSame('Kotona', HelpPage::infrastructure());
        self::assertSame('Hosted at home', Language::run('en', static fn (): string => HelpPage::infrastructure()));
        self::assertSame('Kotona', SiteTexts::text('infrastructure_note', 'fi'));
    }

    /**
     * Saving the form stores each posted field as a value; a field that was
     * not posted is left alone. Without a model the source fills the empty
     * languages and the error says why.
     */
    public function testSaveStoresPostedFieldsOnly(): void
    {
        $this->options[TicketController::NOTE_OPTION] = 'Old note';
        $translator = new TranslatedText(new LlmClient(new Logger()), new Logger());

        $error = SiteTexts::save($translator, ['signature' => ['source' => 'The crew', 'lang' => '']]);

        self::assertSame(LlmClient::ERROR_NOT_CONFIGURED, $error);
        self::assertSame('The crew', $this->options[Signature::OPTION]['source']);
        self::assertSame(['fi' => 'The crew', 'en' => 'The crew'], $this->options[Signature::OPTION]['texts']);
        self::assertSame('Old note', $this->options[TicketController::NOTE_OPTION]);
    }
}

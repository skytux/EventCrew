<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\NewsletterText;
use EventCrew\Support\SiteLanguages;
use EventCrew\Telegram\NewsletterCommand;

/**
 * /newsletter. The language model is not set up here, so a saved text is
 * stored as written in every language and the answer says so.
 */
final class NewsletterCommandTest extends TelegramTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);

            return true;
        });
    }

    private function command(): NewsletterCommand
    {
        return new NewsletterCommand(
            new PersonRepository(),
            new NewsletterText(new LlmClient(new Logger()), new Logger()),
            $this->client()
        );
    }

    private function queuePerson(bool $organizer): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 9,
            'email' => 'org@example.test',
            'display_name' => 'Org',
            'telegram_user_id' => 555,
            'is_organizer' => $organizer ? 1 : 0,
        ];
    }

    private function lastReply(): string
    {
        $last = end($this->telegramCalls);

        return false === $last ? '' : (string) ($last['body']['text'] ?? '');
    }

    public function testItIsRefusedInAGroup(): void
    {
        $this->command()->onCommand(555, -100, false, 'Hello');

        self::assertStringContainsString('private chat', $this->lastReply());
        self::assertArrayNotHasKey(NewsletterText::OPTION, $this->options);
        self::assertSame([], $this->wpdb->queries);
    }

    public function testItIsRefusedToSomeoneWhoIsNotAnOrganizer(): void
    {
        $this->queuePerson(false);

        $this->command()->onCommand(555, 555, true, 'Hello');

        self::assertStringContainsString('Only organizers', $this->lastReply());
        self::assertArrayNotHasKey(NewsletterText::OPTION, $this->options);
    }

    public function testATextIsSavedAndTheUntranslatedNoteShown(): void
    {
        $this->queuePerson(true);

        $this->command()->onCommand(555, 555, true, 'See you on the dance floor!');

        $reply = $this->lastReply();
        self::assertStringContainsString('Saved.', $reply);
        self::assertStringContainsString('could not be translated', $reply);
        self::assertSame('See you on the dance floor!', $this->options[NewsletterText::OPTION]['en']);
        self::assertSame(9, $this->options[NewsletterText::OPTION]['by']);
    }

    public function testTheCommandAloneShowsTheText(): void
    {
        $this->options[NewsletterText::OPTION] = ['fi' => 'Moi', 'en' => 'Hi', 'translated' => true, 'rev' => 1];
        $this->queuePerson(true);

        $this->command()->onCommand(555, 555, true, '');

        $reply = $this->lastReply();
        self::assertStringContainsString("Finnish:\nMoi", $reply);
        self::assertStringContainsString("English:\nHi", $reply);
        self::assertStringContainsString('/newsletter reset', $reply);
        self::assertSame('nl:reset', $this->callbackOf($this->lastCallTo('sendMessage') ?? [], '↩️ Reset to default'));
    }

    /**
     * A third site language is listed with its own name. A text stored when
     * the site had only Finnish and English shows the main language's text
     * for it.
     */
    public function testTheCommandAloneListsEverySiteLanguage(): void
    {
        $this->options[SiteLanguages::OPTION] = ['fi', 'en', 'sv'];
        $this->options[NewsletterText::OPTION] = ['fi' => 'Moi', 'en' => 'Hi', 'translated' => true, 'rev' => 1];
        $this->queuePerson(true);

        $this->command()->onCommand(555, 555, true, '');

        self::assertStringContainsString("Finnish:\nMoi\n\nEnglish:\nHi\n\nSvenska:\nMoi", $this->lastReply());
    }

    public function testClearRemovesTheText(): void
    {
        $this->options[NewsletterText::OPTION] = ['fi' => 'Moi', 'en' => 'Hi', 'translated' => true, 'rev' => 1];
        $this->queuePerson(true);

        $this->command()->onCommand(555, 555, true, 'CLEAR');

        self::assertStringContainsString('removed', $this->lastReply());
        self::assertArrayNotHasKey(NewsletterText::OPTION, $this->options);
    }
}

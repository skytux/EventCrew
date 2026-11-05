<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\FollowupText;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Telegram\FollowupCommand;

/**
 * /followup. The language model is not set up here, so a saved text is
 * stored as written in both languages and the answer says so.
 */
final class FollowupCommandTest extends TelegramTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);

            return true;
        });
    }

    private function command(): FollowupCommand
    {
        return new FollowupCommand(
            new PersonRepository(),
            new FollowupText(new LlmClient(new Logger()), new Logger()),
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

    /** @param array<string, mixed> $value */
    private function storeNext(string $variant, array $value): void
    {
        $this->options[FollowupText::NEXT_OPTIONS[$variant]] = $value + [
            'source' => 'en',
            'translated' => true,
            'by' => 9,
            'at' => '2026-11-10 10:00:00',
            'rev' => 1,
        ];
    }

    public function testItIsRefusedInAGroup(): void
    {
        $this->command()->onCommand(555, -100, false, 'came Hello');

        self::assertStringContainsString('private chat', $this->lastReply());
        self::assertArrayNotHasKey(FollowupText::NEXT_OPTIONS[FollowupText::CAME], $this->options);
        self::assertSame([], $this->wpdb->queries);
    }

    public function testItIsRefusedToSomeoneWhoIsNotAnOrganizer(): void
    {
        $this->queuePerson(false);

        $this->command()->onCommand(555, 555, true, 'came Hello');

        self::assertStringContainsString('Only organizers', $this->lastReply());
        self::assertArrayNotHasKey(FollowupText::NEXT_OPTIONS[FollowupText::CAME], $this->options);
    }

    public function testATextIsSavedForOneVersion(): void
    {
        $this->queuePerson(true);

        $this->command()->onCommand(555, 555, true, "MISSED See you\nnext time!");

        $reply = $this->lastReply();
        self::assertStringContainsString('Sorry text (missed)', $reply);
        self::assertStringContainsString('next post-event email only', $reply);
        self::assertStringContainsString('could not be translated', $reply);
        $stored = $this->options[FollowupText::NEXT_OPTIONS[FollowupText::MISSED]];
        self::assertSame("See you\nnext time!", $stored['en']);
        self::assertSame(9, $stored['by']);
        self::assertArrayNotHasKey(FollowupText::NEXT_OPTIONS[FollowupText::CAME], $this->options);
    }

    public function testAnUnknownVersionOrNoTextShowsTheUsage(): void
    {
        $this->queuePerson(true);
        $this->command()->onCommand(555, 555, true, 'maybe Hello');
        self::assertStringContainsString('/followup came', $this->lastReply());

        $this->queuePerson(true);
        $this->command()->onCommand(555, 555, true, 'came');
        self::assertStringContainsString('/followup came', $this->lastReply());

        self::assertArrayNotHasKey(FollowupText::NEXT_OPTIONS[FollowupText::CAME], $this->options);
    }

    public function testTheCommandAloneShowsBothTexts(): void
    {
        $this->storeNext(FollowupText::CAME, ['fi' => 'Moi', 'en' => 'Hi']);
        $this->queuePerson(true);

        $this->command()->onCommand(555, 555, true, '');

        $reply = $this->lastReply();
        self::assertStringContainsString("In Finnish:\nMoi", $reply);
        self::assertStringContainsString("In English:\nHi", $reply);
        self::assertStringContainsString('uses a one-time text', $reply);
        self::assertStringContainsString('uses the text on the Messages page', $reply, 'the sorry text');
        self::assertStringContainsString('We missed you at {event}', $reply);
    }

    public function testClearRemovesOneOrBoth(): void
    {
        $this->storeNext(FollowupText::CAME, ['fi' => 'Moi', 'en' => 'Hi']);
        $this->storeNext(FollowupText::MISSED, ['fi' => 'Harmi', 'en' => 'Sorry']);

        $this->queuePerson(true);
        $this->command()->onCommand(555, 555, true, 'came CLEAR');
        self::assertStringContainsString('removed', $this->lastReply());
        self::assertArrayNotHasKey(FollowupText::NEXT_OPTIONS[FollowupText::CAME], $this->options);
        self::assertArrayHasKey(FollowupText::NEXT_OPTIONS[FollowupText::MISSED], $this->options);

        $this->queuePerson(true);
        $this->command()->onCommand(555, 555, true, 'clear');
        self::assertStringContainsString('removed', $this->lastReply());
        self::assertArrayNotHasKey(FollowupText::NEXT_OPTIONS[FollowupText::MISSED], $this->options);
    }
}

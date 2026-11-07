<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Telegram\TelegramClient;

/**
 * A button tap on the bot's last message in a private chat answers in that
 * message instead of posting a new one (TelegramClient::replaceNextIn()).
 */
final class ReplaceInPlaceTest extends TelegramTestCase
{
    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->transients = [];

        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);

            return true;
        });
    }

    /** @return list<string> */
    private function methods(): array
    {
        return array_column($this->telegramCalls, 'method');
    }

    public function testASentMessageBecomesTheLastOneInThatChat(): void
    {
        $this->telegramResults['sendMessage'] = ['message_id' => 41];

        $this->client()->sendMessage(500, 'Hello');

        self::assertSame(41, $this->transients[TelegramClient::LAST_PREFIX . '500']);
    }

    public function testATapOnTheLastMessageReplacesIt(): void
    {
        $this->transients[TelegramClient::LAST_PREFIX . '500'] = 41;
        $this->telegramResults['editMessageText'] = ['message_id' => 41];
        $client = $this->client();

        $client->replaceNextIn(500, 41);
        $client->sendMessage(500, 'Tasks', ['inline_keyboard' => []]);

        self::assertSame(['editMessageText'], $this->methods());
        self::assertSame(41, $this->telegramCalls[0]['body']['message_id']);
        self::assertSame('Tasks', $this->telegramCalls[0]['body']['text']);
    }

    public function testOnlyTheFirstAnswerReplacesIt(): void
    {
        $this->transients[TelegramClient::LAST_PREFIX . '500'] = 41;
        $client = $this->client();

        $client->replaceNextIn(500, 41);
        $client->sendMessage(500, 'One');
        $client->sendMessage(500, 'Two');

        self::assertSame(['editMessageText', 'sendMessage'], $this->methods());
    }

    public function testATapOnAnOlderMessagePostsANewOne(): void
    {
        $this->transients[TelegramClient::LAST_PREFIX . '500'] = 45;
        $client = $this->client();

        $client->replaceNextIn(500, 41);
        $client->sendMessage(500, 'Tasks');

        self::assertSame(['sendMessage'], $this->methods());
    }

    public function testAfterThePersonWritesTheTapPostsANewOne(): void
    {
        $this->transients[TelegramClient::LAST_PREFIX . '500'] = 41;
        $client = $this->client();

        $client->forgetLast(500);
        $client->replaceNextIn(500, 41);
        $client->sendMessage(500, 'Tasks');

        self::assertSame(['sendMessage'], $this->methods());
    }

    public function testARecordIsNeverAReplacementAndIsNotReplacedLater(): void
    {
        $this->transients[TelegramClient::LAST_PREFIX . '500'] = 41;
        $this->telegramResults['sendMessage'] = ['message_id' => 42];
        $client = $this->client();

        $client->replaceNextIn(500, 41);
        $client->record()->sendMessage(500, '🎟 Your ticket');

        self::assertSame(['sendMessage'], $this->methods());
        self::assertSame(0, $this->transients[TelegramClient::LAST_PREFIX . '500']);

        // A tap on the ticket's own button posts a new message.
        $client->replaceNextIn(500, 42);
        $client->sendMessage(500, 'Something');

        self::assertSame(['sendMessage', 'sendMessage'], $this->methods());
    }

    public function testTheRecordMarkIsUsedUpByOneSend(): void
    {
        $this->telegramResults['sendMessage'] = ['message_id' => 43];
        $client = $this->client();

        $client->record()->sendMessage(-100, 'In the group');
        $client->sendMessage(500, 'Private');

        self::assertSame(43, $this->transients[TelegramClient::LAST_PREFIX . '500']);
    }

    public function testARefusedEditFallsBackToANewMessage(): void
    {
        $this->transients[TelegramClient::LAST_PREFIX . '500'] = 41;
        $this->telegramErrors['editMessageText'] = 'Bad Request: there is no text in the message to edit';
        $client = $this->client();

        $client->replaceNextIn(500, 41);
        $client->sendMessage(500, 'Tasks');

        self::assertSame(['editMessageText', 'sendMessage'], $this->methods());
    }

    public function testAHandlerThatEditsTheTappedMessageItselfKeepsItsOwnEdit(): void
    {
        $this->transients[TelegramClient::LAST_PREFIX . '500'] = 41;
        $client = $this->client();

        $client->replaceNextIn(500, 41);
        $client->editMessageText(500, 41, 'Board, updated');
        $client->sendMessage(500, 'You are signed up');

        self::assertSame(['editMessageText', 'sendMessage'], $this->methods());
    }

    public function testGroupChatsAreLeftAlone(): void
    {
        $this->transients[TelegramClient::LAST_PREFIX . '-100'] = 41;
        $client = $this->client();

        $client->replaceNextIn(-100, 41);
        $client->sendMessage(-100, 'Board');

        self::assertSame(['sendMessage'], $this->methods());
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\SiteLanguages;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\MenuButtons;
use EventCrew\Telegram\SayCommand;

/**
 * /say: an organizer's message for the group, shown first with Confirm and
 * Cancel, posted with its formatting.
 */
final class SayCommandTest extends TelegramTestCase
{
    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->transients = [];
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => -100777, 'message_id' => 5];

        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);

            return true;
        });
    }

    private function command(): SayCommand
    {
        return new SayCommand(new PersonRepository(), $this->client());
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

    /**
     * @param array<int, array<string, mixed>> $entities
     * @return array<string, mixed>
     */
    private static function message(string $text, array $entities = [], string $type = 'private'): array
    {
        return [
            'message_id' => 70,
            'from' => ['id' => 555],
            'chat' => ['id' => 'private' === $type ? 555 : -100777, 'type' => $type],
            'text' => $text,
            'entities' => $entities,
        ];
    }

    /** @return array<string, mixed> */
    private static function tap(string $data, int $from = 555): array
    {
        return [
            'id' => 'cb1',
            'from' => ['id' => $from],
            'message' => ['message_id' => 71, 'chat' => ['id' => $from, 'type' => 'private']],
            'data' => $data,
        ];
    }

    private function draftId(): string
    {
        $markup = $this->lastCallTo('sendMessage')['reply_markup'] ?? [];
        $data = (string) ($markup['inline_keyboard'][0][0]['callback_data'] ?? '');

        return substr($data, strlen(SayCommand::PREFIX . 'ok:'));
    }

    public function testItIsRefusedInAGroup(): void
    {
        $this->command()->onCommand(self::message('/say Hello', [], 'supergroup'), false);

        self::assertStringContainsString('private chat', (string) $this->lastCallTo('sendMessage')['text']);
        self::assertSame([], $this->transients);
    }

    public function testItIsRefusedToSomeoneWhoIsNotAnOrganizer(): void
    {
        $this->queuePerson(false);

        $this->command()->onCommand(self::message('/say Hello'), true);

        self::assertStringContainsString('Only organizers', (string) $this->lastCallTo('sendMessage')['text']);
        self::assertSame([], $this->transients);
    }

    public function testWithoutTextItSaysHowToUseIt(): void
    {
        $this->queuePerson(true);

        $this->command()->onCommand(self::message('/say'), true);

        self::assertStringContainsString('Send /say followed by', (string) $this->lastCallTo('sendMessage')['text']);
    }

    public function testThePreviewKeepsTheFormattingAndNothingGoesToTheGroupYet(): void
    {
        $this->queuePerson(true);

        // "/say " is 5 units; "Hi everyone" is bold, "here" is a link.
        $this->command()->onCommand(self::message("/say Hi everyone\nmore here", [
            ['type' => 'bot_command', 'offset' => 0, 'length' => 4],
            ['type' => 'bold', 'offset' => 5, 'length' => 11],
            ['type' => 'text_link', 'offset' => 22, 'length' => 4, 'url' => 'https://example.test/'],
        ]), true);

        $preview = $this->lastCallTo('sendMessage');

        self::assertSame(555, $preview['chat_id']);
        self::assertSame('HTML', $preview['parse_mode']);
        self::assertStringEndsWith(
            "<b>Hi everyone</b>\nmore <a href=\"https://example.test/\">here</a>",
            (string) $preview['text']
        );
        self::assertSame(16, strlen($this->draftId()));
        self::assertNotContains(-100777, array_map(
            static fn (array $call): mixed => $call['body']['chat_id'] ?? null,
            $this->telegramCalls
        ));
    }

    public function testConfirmPostsTheMessageInTheGroup(): void
    {
        $this->queuePerson(true);
        $bold = [['type' => 'bold', 'offset' => 5, 'length' => 5]];
        $this->command()->onCommand(self::message('/say Hello', $bold), true);
        $id = $this->draftId();

        $this->queuePerson(true);
        $this->command()->onConfirm(self::tap(SayCommand::PREFIX . 'ok:' . $id));

        $posted = array_values(array_filter(
            $this->telegramCalls,
            static fn (array $call): bool => -100777 === ($call['body']['chat_id'] ?? null)
        ));

        self::assertCount(1, $posted);
        self::assertSame('<b>Hello</b>', $posted[0]['body']['text']);
        self::assertSame('HTML', $posted[0]['body']['parse_mode']);
        self::assertStringContainsString('Sent to the group', (string) $this->lastCallTo('sendMessage')['text']);
        self::assertSame([], $this->transients);
        self::assertContains('editMessageReplyMarkup', $this->calledMethods());
    }

    public function testCancelPostsNothing(): void
    {
        $this->queuePerson(true);
        $this->command()->onCommand(self::message('/say Hello'), true);
        $id = $this->draftId();

        $this->command()->onConfirm(self::tap(SayCommand::PREFIX . 'no:' . $id));

        self::assertStringContainsString('Nothing was sent', (string) $this->lastCallTo('sendMessage')['text']);
        self::assertSame([], $this->transients);
        self::assertNotContains(-100777, array_map(
            static fn (array $call): mixed => $call['body']['chat_id'] ?? null,
            $this->telegramCalls
        ));
    }

    public function testSomeoneElseCannotConfirmTheDraft(): void
    {
        $this->queuePerson(true);
        $this->command()->onCommand(self::message('/say Hello'), true);
        $id = $this->draftId();

        $this->command()->onConfirm(self::tap(SayCommand::PREFIX . 'ok:' . $id, 666));

        self::assertStringContainsString('expired', (string) $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertCount(1, $this->transients);
    }

    public function testAFormerOrganizerCannotConfirm(): void
    {
        $this->queuePerson(true);
        $this->command()->onCommand(self::message('/say Hello'), true);
        $id = $this->draftId();

        $this->queuePerson(false);
        $this->command()->onConfirm(self::tap(SayCommand::PREFIX . 'ok:' . $id));

        self::assertNotContains(-100777, array_map(
            static fn (array $call): mixed => $call['body']['chat_id'] ?? null,
            $this->telegramCalls
        ));
    }

    public function testFormattingIsCarriedOntoTheHelpersLine(): void
    {
        // "Post this: Hi all" with "Hi" bold; the helper's line is "/say Hi all".
        $original = self::message('Post this: Hi all', [['type' => 'bold', 'offset' => 11, 'length' => 2]]);

        self::assertSame(
            [['type' => 'bold', 'offset' => 5, 'length' => 2]],
            SayCommand::carryEntities('/say Hi all', $original)
        );
    }

    public function testNoFormattingIsCarriedWhenTheTextWasReworded(): void
    {
        $original = self::message('Post this: Hi all', [['type' => 'bold', 'offset' => 11, 'length' => 2]]);

        self::assertSame([], SayCommand::carryEntities('/say Hello all', $original));
    }

    public function testAppInviteIsRefusedInAGroupAndToNonOrganizers(): void
    {
        $this->command()->onCommand(self::message('/appinvite Hi', [], 'supergroup'), false, true);
        self::assertStringContainsString('/appinvite', (string) $this->lastCallTo('sendMessage')['text']);
        self::assertStringContainsString('private chat', (string) $this->lastCallTo('sendMessage')['text']);

        $this->queuePerson(false);
        $this->command()->onCommand(self::message('/appinvite Hi'), true, true);
        self::assertStringContainsString('Only organizers', (string) $this->lastCallTo('sendMessage')['text']);
        self::assertSame([], $this->transients);
    }

    public function testAppInviteWithoutTextPreviewsTheDefaultTextAndNamesTheButton(): void
    {
        $this->options[SiteLanguages::OPTION] = ['en'];
        $this->queuePerson(true);

        $this->command()->onCommand(self::message('/appinvite'), true, true);

        $preview = (string) $this->lastCallTo('sendMessage')['text'];
        self::assertStringContainsString('The Crew app is here', $preview);
        self::assertStringContainsString('With the button: 📱 Get the app', $preview);
        self::assertSame(16, strlen($this->draftId()));
    }

    public function testAppInviteConfirmPostsTheTextWithTheGetTheAppButton(): void
    {
        $this->options[SiteLanguages::OPTION] = ['en'];
        $this->queuePerson(true);
        $this->command()->onCommand(self::message('/appinvite Install it today'), true, true);
        $id = $this->draftId();

        $this->queuePerson(true);
        $this->command()->onConfirm(self::tap(SayCommand::PREFIX . 'ok:' . $id));

        $posted = array_values(array_filter(
            $this->telegramCalls,
            static fn (array $call): bool => -100777 === ($call['body']['chat_id'] ?? null)
        ));

        self::assertCount(1, $posted);
        self::assertSame('Install it today', $posted[0]['body']['text']);
        $button = $posted[0]['body']['reply_markup']['inline_keyboard'][0][0];
        self::assertSame(MenuButtons::APP, $button['callback_data']);
        self::assertSame('📱 Get the app', $button['text']);
        self::assertStringContainsString('Sent to the group', (string) $this->lastCallTo('sendMessage')['text']);
    }
}

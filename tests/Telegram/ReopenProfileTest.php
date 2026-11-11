<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\History;
use EventCrew\Telegram\MenuButtons;
use EventCrew\Telegram\ProfileService;
use EventCrew\Telegram\ReopenProfile;
use EventCrew\Telegram\TelegramClient;

/**
 * My info again after an action (Owner 2026-11-11): an action finished in a
 * /me flow in the private chat shows the person's /me again; anything else
 * does not.
 */
final class ReopenProfileTest extends TelegramTestCase
{
    private const TG = 555;

    private TelegramClient $shared;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_the_title')->justReturn('');
        $this->shared = $this->client();
    }

    protected function tearDown(): void
    {
        History::reset();
        TelegramClient::unwatch();

        parent::tearDown();
    }

    private function reopen(): ReopenProfile
    {
        $people = new PersonRepository();

        return new ReopenProfile(
            $this->shared,
            new ProfileService(
                $people,
                new AssignmentRepository(),
                new TaskRepository(),
                $this->standing(),
                $this->shared
            ),
            $people
        );
    }

    /** @return array<string, mixed> */
    private function personRow(): array
    {
        return [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => self::TG,
        ];
    }

    /** @return array<string, mixed> */
    private function tap(string $data, int $messageId): array
    {
        return ['callback_query' => [
            'id' => 'cb1',
            'data' => $data,
            'from' => ['id' => self::TG],
            'message' => ['message_id' => $messageId, 'chat' => ['id' => self::TG, 'type' => 'private']],
        ]];
    }

    /**
     * Runs one update through begin/finish/end, with $handler as the
     * update's handler.
     *
     * @param array<string, mixed> $update
     */
    private function handle(ReopenProfile $reopen, array $update, callable $handler): void
    {
        $reopen->begin($update);

        try {
            $handler();
            $reopen->finish();
        } finally {
            $reopen->end();
        }
    }

    private function signedUp(): void
    {
        History::observed(['kind' => History::SIGNED_UP, 'person_id' => 7, 'task_id' => 3]);
    }

    public function testASignUpFromMeShowsMeAgain(): void
    {
        set_transient(ReopenProfile::TAGGED_PREFIX . self::TG, [50], DAY_IN_SECONDS);
        $this->wpdb->nextRows[] = $this->personRow(); // finish(): the person
        $this->wpdb->nextRows[] = $this->personRow(); // onMe
        $this->telegramResults['sendMessage'] = ['message_id' => 60];

        $this->handle($this->reopen(), $this->tap('j:3:2026-07-20', 50), function (): void {
            $this->signedUp();
        });

        self::assertSame(['editMessageReplyMarkup', 'sendMessage'], $this->calledMethods());
        self::assertSame(50, $this->lastCallTo('editMessageReplyMarkup')['message_id']);
        self::assertSame(self::TG, $this->lastCallTo('sendMessage')['chat_id']);
        self::assertSame(60, get_transient(ReopenProfile::SHOWN_PREFIX . self::TG));
        self::assertContains(60, get_transient(ReopenProfile::TAGGED_PREFIX . self::TG), 'the new /me is kept');
    }

    public function testNothingFromAMessageOutsideMe(): void
    {
        set_transient(ReopenProfile::TAGGED_PREFIX . self::TG, [50], DAY_IN_SECONDS);

        $this->handle($this->reopen(), $this->tap('j:3:2026-07-20', 51), function (): void {
            $this->signedUp();
        });

        self::assertSame([], $this->telegramCalls);
        self::assertFalse(get_transient(ReopenProfile::SHOWN_PREFIX . self::TG));
    }

    public function testNothingFromAGroupChat(): void
    {
        $update = $this->tap(MenuButtons::ME, 50);
        $update['callback_query']['message']['chat'] = ['id' => -100, 'type' => 'supergroup'];

        $this->handle($this->reopen(), $update, function (): void {
            $this->signedUp();
        });

        self::assertSame([], $this->telegramCalls);
    }

    public function testOpeningAMeScreenKeepsWhatItSentButShowsNothingMore(): void
    {
        $this->telegramResults['sendMessage'] = ['message_id' => 70];

        $this->handle($this->reopen(), $this->tap(MenuButtons::TICKETS, 40), function (): void {
            $this->shared->sendMessage(self::TG, 'Tickets');
        });

        self::assertSame(['sendMessage'], $this->calledMethods());
        self::assertSame([70], get_transient(ReopenProfile::TAGGED_PREFIX . self::TG));
    }

    public function testATappedMessageTheHandlerEditedKeepsItsButtons(): void
    {
        set_transient(ReopenProfile::TAGGED_PREFIX . self::TG, [50], DAY_IN_SECONDS);
        $this->wpdb->nextRows[] = $this->personRow();
        $this->wpdb->nextRows[] = $this->personRow();

        $this->handle($this->reopen(), $this->tap('j:3:2026-07-20', 50), function (): void {
            $this->shared->editMessageText(self::TG, 50, 'Signed up.');
            $this->signedUp();
        });

        self::assertSame(['editMessageText', 'sendMessage'], $this->calledMethods());
    }

    public function testNotificationsNeedNoHistoryRow(): void
    {
        set_transient(ReopenProfile::TAGGED_PREFIX . self::TG, [50], DAY_IN_SECONDS);
        $this->wpdb->nextRows[] = $this->personRow();
        $this->wpdb->nextRows[] = $this->personRow();

        $this->handle($this->reopen(), $this->tap('np:x', 50), function (): void {
            $this->shared->editMessageText(self::TG, 50, 'Notifications');
        });

        self::assertSame(['editMessageText', 'sendMessage'], $this->calledMethods());
    }

    public function testARowAboutSomeoneElseShowsNothing(): void
    {
        set_transient(ReopenProfile::TAGGED_PREFIX . self::TG, [50], DAY_IN_SECONDS);
        $this->wpdb->nextRows[] = $this->personRow();

        $this->handle($this->reopen(), $this->tap('j:3:2026-07-20', 50), static function (): void {
            History::observed(['kind' => History::SIGNED_UP, 'person_id' => 8, 'task_id' => 3]);
        });

        self::assertSame([], $this->telegramCalls);
    }

    public function testDoneByAHandlerShowsMe(): void
    {
        $this->wpdb->nextRows[] = $this->personRow();
        $this->wpdb->nextRows[] = $this->personRow();

        $this->handle($this->reopen(), $this->tap(MenuButtons::REPLACE, 40), static function (): void {
            ReopenProfile::done();
        });

        self::assertContains('sendMessage', $this->calledMethods());
    }

    public function testTheShownMeIsRedrawnInPlaceWhenStillTheLast(): void
    {
        set_transient(ReopenProfile::TAGGED_PREFIX . self::TG, [50, 60], DAY_IN_SECONDS);
        set_transient(ReopenProfile::SHOWN_PREFIX . self::TG, 60, DAY_IN_SECONDS);
        set_transient(TelegramClient::LAST_PREFIX . self::TG, 60, DAY_IN_SECONDS);
        $this->wpdb->nextRows[] = $this->personRow();
        $this->wpdb->nextRows[] = $this->personRow();

        // A sign-up from the shown /me's board, answered by a toast only.
        $this->handle($this->reopen(), $this->tap('j:3:2026-07-20', 60), function (): void {
            $this->shared->answerCallbackQuery('cb1', 'Signed up.');
            $this->signedUp();
        });

        self::assertSame(['answerCallbackQuery', 'editMessageReplyMarkup', 'editMessageText'], $this->calledMethods());
        self::assertSame(60, $this->lastCallTo('editMessageText')['message_id']);
        self::assertSame(60, get_transient(ReopenProfile::SHOWN_PREFIX . self::TG));
    }

    public function testAnAnswerToAKeptQuestionIsPartOfTheFlow(): void
    {
        set_transient(ReopenProfile::TAGGED_PREFIX . self::TG, [50], DAY_IN_SECONDS);
        set_transient(TelegramClient::LAST_PREFIX . self::TG, 50, DAY_IN_SECONDS);
        $this->wpdb->nextRows[] = $this->personRow();
        $this->wpdb->nextRows[] = $this->personRow();
        $message = ['message' => [
            'text' => '@kim',
            'from' => ['id' => self::TG],
            'chat' => ['id' => self::TG, 'type' => 'private'],
        ]];

        $this->handle($this->reopen(), $message, static function (): void {
            History::observed(['kind' => History::TICKET_OFFERED, 'person_id' => 7, 'other_person_id' => 8]);
        });

        self::assertSame(['sendMessage'], $this->calledMethods());
    }
}

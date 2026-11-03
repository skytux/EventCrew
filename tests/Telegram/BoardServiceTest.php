<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Language;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\Roles;
use EventCrew\Support\SignupService;
use EventCrew\Telegram\BoardRenderer;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\TelegramClient;

final class BoardServiceTest extends TelegramTestCase
{
    /** @var array<int, array{to: string, body: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];

        // eventName() reaches for the linked post's title; empty makes it fall
        // back to the typed label, which keeps these tests free of posts.
        Functions\when('get_the_title')->justReturn('');
        Functions\when('rest_url')->alias(static fn (string $path = ''): string => 'https://example.test/wp-json/' . $path);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'body' => $body];

            return true;
        });
    }

    private function board(): BoardService
    {
        return new BoardService(
            new TaskRepository(),
            new AssignmentRepository(),
            new PersonRepository(),
            $this->client(),
            new Logger(),
            new ClaimNotifier(new TaskRepository(), new AssignmentRepository(), new Mailer(new Logger(), new EmailTemplate(new Logger())), $this->client(), $this->standing()),
            $this->signup()
        );
    }

    public function testAPublicGroupsUsernameBecomesTheLink(): void
    {
        $this->options[TelegramClient::TOKEN_OPTION] = 'token';
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => -1001234, 'message_id' => 0];
        $this->telegramResults['getChat'] = ['username' => 'ourcrew'];

        self::assertSame('https://t.me/ourcrew', $this->board()->refreshGroupLink(true));
    }

    public function testAPrivateGroupFallsBackToCreatingAnInviteLink(): void
    {
        // No username and no primary invite_link visible, so a link is made.
        // Never exportChatInviteLink, which would revoke the crew's existing one.
        $this->options[TelegramClient::TOKEN_OPTION] = 'token';
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => -1001234, 'message_id' => 0];
        $this->telegramResults['getChat'] = ['type' => 'supergroup'];
        $this->telegramResults['createChatInviteLink'] = ['invite_link' => 'https://t.me/+abc123'];

        self::assertSame('https://t.me/+abc123', $this->board()->refreshGroupLink(true));
        self::assertContains('createChatInviteLink', $this->calledMethods());
        self::assertNotContains('exportChatInviteLink', $this->calledMethods());
    }

    public function testAHandTypedLinkIsNeverOverwrittenOrLookedUp(): void
    {
        $this->options[TelegramClient::TOKEN_OPTION] = 'token';
        $this->options[BoardService::GROUP_LINK_OPTION] = 'https://t.me/typed';
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => -1001234, 'message_id' => 0];

        self::assertSame('https://t.me/typed', $this->board()->refreshGroupLink(true));
        // Not even asked: the organizer has already answered the question.
        self::assertNotContains('getChat', $this->calledMethods());
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(int $id, ?int $eventPostId, string $label, string $date = '2026-08-01'): array
    {
        return [
            'id' => $id,
            'task_date' => $date,
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_post_id' => $eventPostId,
            'event_label' => $label,
            'starts_at' => null,
            'ends_at' => null,
            'notes' => '',
            'created_at' => '2026-07-01 00:00:00',
        ];
    }

    private function verifiedPerson(int $telegramId): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => $telegramId,
        ];
    }

    // --- render -------------------------------------------------------------

    /**
     * Each event is introduced by two heading rows in the keyboard - 📅 and the
     * event's name, then the date under it - and the task buttons follow.
     * Headings appear for a single event too: a board that changes shape when a
     * second event opens is one people have to re-learn.
     */
    public function testRenderHeadsEachEventWithItsNameThenItsDate(): void
    {
        $this->wpdb->nextResults[] = [
            $this->taskRow(1, 100, 'Party'),
            $this->taskRow(2, 100, 'Party'),
        ];
        $this->wpdb->nextResults[] = []; // occupancyFor

        $rendered = $this->board()->render();

        // Name, date, then one row per task. No deep-link row (no username).
        self::assertCount(4, $rendered['keyboard']);
        self::assertSame('📅 Party', $rendered['keyboard'][0][0]['text']);
        self::assertStringNotContainsString('📅', $rendered['keyboard'][1][0]['text']);

        // The headings are no-ops, and the tasks are not.
        self::assertSame(BoardRenderer::SPACER_DATA, $rendered['keyboard'][0][0]['callback_data']);
        self::assertSame(BoardRenderer::SPACER_DATA, $rendered['keyboard'][1][0]['callback_data']);
        self::assertSame('t:1', $rendered['keyboard'][2][0]['callback_data']);
    }

    /**
     * The job's name is what a person chooses between, so it leads the row -
     * the times under one heading are all the same evening. Its role emoji is
     * also what tells the row apart from a heading, which carries 📅 or
     * nothing.
     */
    public function testTaskButtonsLeadWithTheRoleThenTimeThenCount(): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow(1, 100, 'Party')];
        $this->wpdb->nextResults[] = [];

        $rendered = $this->board()->render();

        // Rows 0 and 1 are the headings; the task follows.
        self::assertSame('🎈 Decorate · 0/2', $rendered['keyboard'][2][0]['text']);
    }

    /**
     * The group board is read by everyone in the group, so the organizers-only
     * task is never on it.
     */
    public function testTheGroupBoardLeavesOutTheOrganizeTask(): void
    {
        $this->wpdb->nextResults[] = [
            $this->taskRow(1, 100, 'Party'),
            array_merge($this->taskRow(2, 100, 'Party'), ['role_slug' => 'organize', 'capacity' => 1]),
        ];
        $this->wpdb->nextResults[] = [];

        $rendered = $this->board()->render();

        $callbacks = array_column(array_map(static fn (array $row): array => $row[0], $rendered['keyboard']), 'callback_data');
        self::assertContains('t:1', $callbacks);
        self::assertNotContains('t:2', $callbacks);
    }

    public function testTheGroupBoardLeavesOutEveryRestrictedRoleTask(): void
    {
        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'decorate', 'label' => 'Decorate'],
            ['slug' => 'dj', 'label' => 'DJ', 'emoji' => '🎧', 'restricted' => true],
        ];
        $this->wpdb->nextResults[] = [
            $this->taskRow(1, 100, 'Party'),
            array_merge($this->taskRow(2, 100, 'Party'), ['role_slug' => 'dj', 'capacity' => 1]),
        ];
        $this->wpdb->nextResults[] = [];

        $rendered = $this->board()->render();

        $callbacks = array_column(array_map(static fn (array $row): array => $row[0], $rendered['keyboard']), 'callback_data');
        self::assertContains('t:1', $callbacks);
        self::assertNotContains('t:2', $callbacks);
        self::assertStringNotContainsString('DJ', $rendered['text']);
    }

    public function testTheGroupBoardLeavesOutARoleSwitchedOffTheBoard(): void
    {
        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'decorate', 'label' => 'Decorate'],
            ['slug' => 'random', 'label' => 'Random', 'emoji' => '🎲', 'on_board' => false],
        ];
        $this->wpdb->nextResults[] = [
            $this->taskRow(1, 100, 'Party'),
            array_merge($this->taskRow(2, 100, 'Party'), ['role_slug' => 'random', 'capacity' => 2]),
        ];
        $this->wpdb->nextResults[] = [];

        $rendered = $this->board()->render();

        $callbacks = array_column(array_map(static fn (array $row): array => $row[0], $rendered['keyboard']), 'callback_data');
        self::assertContains('t:1', $callbacks);
        self::assertNotContains('t:2', $callbacks);
        self::assertStringNotContainsString('Random', $rendered['text']);
        self::assertTrue(Roles::onBoard('decorate'));
        self::assertFalse(Roles::onBoard('random'));
    }

    /**
     * The board is one message the whole group reads, so it stays English even
     * when it is re-rendered inside a tap from someone who chose Finnish.
     */
    public function testTheBoardStaysEnglishInsideAFinnishTap(): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow(1, 100, 'Party')];
        $this->wpdb->nextResults[] = [];

        $after = '';
        $rendered = Language::run(Language::FINNISH, function () use (&$after): array {
            $rendered = $this->board()->render();
            $after = __('Saved.', 'eventcrew');

            return $rendered;
        });

        self::assertStringContainsString('Open tasks', $rendered['text']);
        self::assertSame('🎈 Decorate · 0/2', $rendered['keyboard'][2][0]['text']);
        // The rest of the tap (its popup) is Finnish again once the board is built.
        self::assertSame('Tallennettu.', $after);
    }

    public function testRenderHeadsEveryEventSeparately(): void
    {
        $this->wpdb->nextResults[] = [
            $this->taskRow(1, 100, 'Party A'),
            $this->taskRow(2, 200, 'Party B'),
        ];
        $this->wpdb->nextResults[] = [];

        $rendered = $this->board()->render();

        $labels = array_map(static fn (array $row): string => $row[0]['text'], $rendered['keyboard']);

        // Two events, each two heading rows and one task: six rows.
        self::assertCount(6, $rendered['keyboard']);
        self::assertContains('📅 Party A', $labels);
        self::assertContains('📅 Party B', $labels);
    }

    /**
     * Tapping a heading must not be mistaken for a task: SPACER_DATA does not
     * parse as an action, so it is answered and dropped without a write.
     */
    public function testTappingAHeadingDoesNothing(): void
    {
        $this->verifiedPerson(555);

        $this->board()->onJoinLeave($this->callbackQuery(BoardRenderer::SPACER_DATA));

        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->wpdb->updates);
        self::assertContains('answerCallbackQuery', $this->calledMethods());
        self::assertNotContains('editMessageText', $this->calledMethods());
    }

    /**
     * The links at the foot lead out of the board rather than into a slot, so a
     * rule separates them from the last task. It is only drawn when there is
     * something under it - otherwise a bot with no cached username would end
     * the board on a divider followed by nothing.
     */
    public function testARuleSeparatesTheFootLinksFromTheTasks(): void
    {
        $this->options[BoardService::USERNAME_OPTION] = 'eventcrew_bot';
        $this->wpdb->nextResults[] = [$this->taskRow(1, 100, 'Party')];
        $this->wpdb->nextResults[] = [];

        $rendered = $this->board()->render();
        $labels = array_map(static fn (array $row): string => $row[0]['text'], $rendered['keyboard']);

        // Name, date, task, rule, then the two links.
        self::assertSame(BoardRenderer::DIVIDER, $labels[3]);
        self::assertCount(6, $labels);
        self::assertSame(BoardRenderer::SPACER_DATA, $rendered['keyboard'][3][0]['callback_data']);
    }

    public function testNoRuleIsDrawnWhenThereAreNoFootLinks(): void
    {
        // No username cached, so both deep links are dropped.
        $this->wpdb->nextResults[] = [$this->taskRow(1, 100, 'Party')];
        $this->wpdb->nextResults[] = [];

        $labels = array_map(
            static fn (array $row): string => $row[0]['text'],
            $this->board()->render()['keyboard']
        );

        self::assertNotContains(BoardRenderer::DIVIDER, $labels);
    }

    public function testRenderAddsTheDeepLinkButtonWhenTheUsernameIsKnown(): void
    {
        $this->options[BoardService::USERNAME_OPTION] = 'eventcrew_bot';
        $this->wpdb->nextResults[] = [$this->taskRow(1, 100, 'Party')];
        $this->wpdb->nextResults[] = [];

        $rendered = $this->board()->render();

        $urls = array_map(static fn (array $row): string => $row[0]['url'] ?? '', $rendered['keyboard']);
        self::assertContains('https://t.me/eventcrew_bot?start=onboard', $urls);

        // Both deep links open with an emoji, like the buttons under them.
        $labels = array_map(static fn (array $row): string => $row[0]['text'], $rendered['keyboard']);
        self::assertContains('👋 New here? Sign up →', $labels);
        self::assertContains('👤 See my info →', $labels);
    }

    public function testRenderReportsAnEmptyBoard(): void
    {
        $this->wpdb->nextResults[] = []; // no upcoming tasks

        $rendered = $this->board()->render();

        self::assertSame([], $rendered['keyboard']);
    }

    public function testRepostPostsAfreshBoardAndDeletesThePrevious(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->wpdb->nextResults[] = [$this->taskRow(1, 100, 'Party')]; // render: upcoming
        $this->wpdb->nextResults[] = []; // occupancyFor

        $this->board()->repost();

        // A new board is posted, and the old one is removed.
        self::assertContains('sendMessage', $this->calledMethods());
        self::assertSame(5, $this->lastCallTo('deleteMessage')['message_id']);
    }

    // --- onJoinLeave --------------------------------------------------------

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function callbackQuery(string $data, int $telegramId = 555): array
    {
        return [
            'id' => 'cbq1',
            'from' => ['id' => $telegramId],
            'data' => $data,
        ];
    }

    public function testJoinRefusesAnUnverifiedPerson(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'email_verified_at' => null,
            'telegram_user_id' => 555,
        ];

        $this->board()->onJoinLeave($this->callbackQuery('j:5'));

        self::assertSame([], $this->wpdb->inserts);
        self::assertContains('answerCallbackQuery', $this->calledMethods());
        self::assertTrue((bool) $this->lastCallTo('answerCallbackQuery')['show_alert']);
    }

    public function testJoinRefusesAnUnknownPerson(): void
    {
        // No person row queued -> findByTelegramUserId returns null.
        $this->board()->onJoinLeave($this->callbackQuery('j:5'));

        self::assertSame([], $this->wpdb->inserts);
        self::assertContains('answerCallbackQuery', $this->calledMethods());
    }

    /**
     * A slot whose time runs into one the person already holds is no longer
     * refused. Two jobs at once is their call to make, and the board's job is
     * to get the slot filled.
     */
    public function testJoinAllowsASlotThatClashesWithAnExistingOne(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->verifiedPerson(555);
        $this->wpdb->nextVars[] = 2; // taskCapacity
        $this->wpdb->nextQueryResults[] = 1; // conditional insert wrote one row

        $this->board()->onJoinLeave($this->callbackQuery('j:5'));

        self::assertContains('answerCallbackQuery', $this->calledMethods());
        self::assertContains('editMessageText', $this->calledMethods());
    }

    /**
     * The organize task is not on the group board, but a join button for it
     * could still arrive (a forged or old callback). The claim is refused.
     */
    public function testJoinRefusesTheOrganizeTaskToSomeoneWhoIsNotAnOrganizer(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->verifiedPerson(555);                                                                    // findByTelegramUserId
        $this->wpdb->nextRows[] = array_merge($this->taskRow(5, 100, 'Party'), ['role_slug' => 'organize']); // claim: tasks->find
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'is_organizer' => 0];     // claim: people->find
        // What a join would read if it got that far, so only the check stops it.
        $this->wpdb->nextVars[] = 1;
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextQueryResults[] = 1;

        $this->board()->onJoinLeave($this->callbackQuery('j:5'));

        self::assertStringNotContainsString('INSERT', implode("\n", $this->wpdb->queries));
        self::assertSame('That task is no longer available.', $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertNotContains('editMessageText', $this->calledMethods());
    }

    public function testJoinClaimsTheSlotAndRefreshesTheBoard(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->verifiedPerson(555);
        $this->wpdb->nextVars[] = 2; // taskCapacity
        $this->wpdb->nextQueryResults[] = 1; // conditional insert wrote one row

        $this->board()->onJoinLeave($this->callbackQuery('j:5'));

        self::assertContains('answerCallbackQuery', $this->calledMethods());
        // A successful change edits the existing board message in place.
        self::assertContains('editMessageText', $this->calledMethods());
    }

    /**
     * @param int $completed number of recent completed tasks
     * @param int $noShows number of recent no-shows
     */
    private function queueStandingHistory(int $completed, int $noShows): void
    {
        $rows = [];
        $id = 1;

        for ($i = 0; $i < $completed; $i++, $id++) {
            $rows[] = ['id' => $id, 'task_id' => $id, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-07-20'];
        }

        for ($i = 0; $i < $noShows; $i++, $id++) {
            $rows[] = ['id' => $id, 'task_id' => $id, 'person_id' => 7, 'status' => 'no_show', 'task_date' => '2026-07-20'];
        }

        $this->wpdb->nextResults[] = $rows; // historyFor()
        $this->wpdb->nextVars[] = 0;        // countFor() redemptions
        $this->wpdb->nextVars[] = 0;        // sumFor() credit grants
    }

    public function testTheGateBlocksAnAtRiskMemberFromSigningUp(): void
    {
        $this->options[SignupService::GATE_OPTION] = '1';
        $this->verifiedPerson(555);
        // Three completions and three no-shows: rated, and a 0.5 score sits
        // under the 0.6 threshold.
        $this->queueStandingHistory(3, 3);

        $this->board()->onJoinLeave($this->callbackQuery('j:5'));

        self::assertSame([], $this->wpdb->inserts);
        self::assertStringNotContainsString('INSERT INTO', implode("\n", $this->wpdb->queries));
        self::assertTrue((bool) $this->lastCallTo('answerCallbackQuery')['show_alert']);
    }

    public function testTheGateLetsAGoodStandingMemberJoin(): void
    {
        $this->options[SignupService::GATE_OPTION] = '1';
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->verifiedPerson(555);
        $this->queueStandingHistory(4, 0); // rated, perfect score
        $this->wpdb->nextVars[] = 2;        // taskCapacity
        $this->wpdb->nextRows[] = null;     // join findFor
        $this->wpdb->nextQueryResults[] = 1; // conditional insert

        $this->board()->onJoinLeave($this->callbackQuery('j:5'));

        self::assertContains('editMessageText', $this->calledMethods());
    }

    public function testWithTheGateOffAnAtRiskMemberCanStillJoin(): void
    {
        // Gate off (the base default): the standing history is never read, so
        // only the ordinary join queue is consumed.
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->verifiedPerson(555);
        $this->wpdb->nextVars[] = 2;         // taskCapacity
        $this->wpdb->nextRows[] = null;      // join findFor
        $this->wpdb->nextQueryResults[] = 1; // conditional insert

        $this->board()->onJoinLeave($this->callbackQuery('j:5'));

        self::assertContains('editMessageText', $this->calledMethods());
    }

    public function testLeaveCancelsTheSlotWithoutDeletingAndRefreshes(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->verifiedPerson(555);
        // cancel(): findFor -> an occupying row, then the task's start time.
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];
        $this->wpdb->nextRows[] = ['starts_at' => '2026-09-01 20:00:00', 'task_date' => '2026-09-01'];

        $this->board()->onJoinLeave($this->callbackQuery('l:5'));

        // The row is updated (status), never deleted.
        self::assertSame([], $this->wpdb->deletes);
        self::assertNotSame([], $this->wpdb->updates);
        self::assertContains('editMessageText', $this->calledMethods());
    }

    public function testSignupSendsAConfirmationEmailWithATicketLink(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->verifiedPerson(555);
        $this->wpdb->nextVars[] = 2; // taskCapacity
        // claim(): tasks->find for the role check (not a role task)
        $this->wpdb->nextRows[] = $this->taskRow(5, 100, 'Party');
        // claim(): people->find for the ban check (not banned)
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com'];
        // join(): findFor -> null, then the conditional insert
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextQueryResults[] = 1;
        // sendSignupEmail: tasks->find, then findFor for the ticket id
        $this->wpdb->nextRows[] = $this->taskRow(5, 100, 'Party');
        $this->wpdb->nextRows[] = ['id' => 9, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];

        $this->board()->onJoinLeave($this->callbackQuery('j:5'));

        self::assertCount(1, $this->mails);
        self::assertSame('sam@example.com', $this->mails[0]['to']);
        self::assertStringContainsString('wp-json/eventcrew/v1/ticket?token=', $this->mails[0]['body']);
    }


    public function testToggleJoinsWhenNotYetSignedUp(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->verifiedPerson(555);
        // findFor (toggle) -> null (unqueued), then the join path:
        $this->wpdb->nextVars[] = 2; // taskCapacity
        $this->wpdb->nextQueryResults[] = 1; // conditional insert

        $this->board()->onJoinLeave($this->callbackQuery('t:5'));

        self::assertContains('editMessageText', $this->calledMethods());
    }

    public function testATapOnAPrivateCopyRedrawsThatCopyToo(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->verifiedPerson(555);
        $this->wpdb->nextVars[] = 2; // taskCapacity
        $this->wpdb->nextQueryResults[] = 1; // conditional insert

        $query = $this->callbackQuery('t:5');
        $query['message'] = ['message_id' => 42, 'chat' => ['id' => 555, 'type' => 'private']];
        $this->board()->onJoinLeave($query);

        $edited = array_map(
            static fn (array $call): array => [$call['body']['chat_id'], $call['body']['message_id']],
            array_values(array_filter($this->telegramCalls,static fn (array $c): bool => 'editMessageText' === $c['method']))
        );
        self::assertSame([[100, 5], [555, 42]], $edited);
    }

    public function testToggleCancelsWhenAlreadySignedUp(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->verifiedPerson(555);
        // findFor (toggle occupancy check) -> an occupying row, so it's a cancel.
        $this->wpdb->nextRows[] = ['id' => 3, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];
        // cancel() re-reads findFor, then the task's start time.
        $this->wpdb->nextRows[] = ['id' => 3, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];
        $this->wpdb->nextRows[] = ['starts_at' => '2026-09-01 20:00:00', 'task_date' => '2026-09-01'];

        $this->board()->onJoinLeave($this->callbackQuery('t:5'));

        self::assertSame([], $this->wpdb->deletes);
        self::assertNotSame([], $this->wpdb->updates);
        self::assertContains('editMessageText', $this->calledMethods());
    }

    public function testToggleReactivatesAFreedRow(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->verifiedPerson(555);
        // Toggle occupancy check -> a cancelled (non-occupying) row, so it's a
        // join; join() then reactivates it.
        $this->wpdb->nextRows[] = ['id' => 3, 'task_id' => 5, 'person_id' => 7, 'status' => 'cancelled'];
        $this->wpdb->nextVars[] = 2; // taskCapacity
        // claim(): tasks->find for the role check (not a role task)
        $this->wpdb->nextRows[] = $this->taskRow(5, 100, 'Party');
        // claim(): people->find for the ban check (not banned)
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com'];
        // join(): findFor -> the same cancelled row, then the guarded UPDATE.
        $this->wpdb->nextRows[] = ['id' => 3, 'task_id' => 5, 'person_id' => 7, 'status' => 'cancelled'];
        $this->wpdb->nextQueryResults[] = 1;

        $this->board()->onJoinLeave($this->callbackQuery('t:5'));

        // Reactivated via a guarded UPDATE statement, never a fresh INSERT.
        self::assertSame([], $this->wpdb->inserts);
        self::assertStringContainsString('UPDATE', implode("\n", $this->wpdb->queries));
        self::assertContains('editMessageText', $this->calledMethods());
    }

    // --- one day at a time ---------------------------------------------------

    /**
     * Three event days: 1 Aug (Party A), 8 Aug (Party B), 15 Aug (Party C).
     */
    private function queueThreeDays(): void
    {
        $this->wpdb->nextResults[] = [
            $this->taskRow(1, 100, 'Party A', '2026-08-01'),
            $this->taskRow(2, 200, 'Party B', '2026-08-08'),
            $this->taskRow(3, 300, 'Party C', '2026-08-15'),
        ];
        $this->wpdb->nextResults[] = []; // occupancyFor
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $keyboard
     * @return array<int, array<string, mixed>>
     */
    private function buttons(array $keyboard): array
    {
        return array_merge(...$keyboard);
    }

    public function testByDefaultTheBoardShowsOnlyTheNextEvent(): void
    {
        $this->queueThreeDays();

        $rendered = $this->board()->render();
        $labels = array_column($this->buttons($rendered['keyboard']), 'text');

        self::assertContains('📅 Party A', $labels);
        self::assertNotContains('📅 Party B', $labels);
        self::assertNotContains('📅 Party C', $labels);
        // On the next event the task buttons keep the plain form.
        self::assertContains('t:1', array_column($this->buttons($rendered['keyboard']), 'callback_data'));
    }

    public function testEveryDayIsAButtonAndTheShownDayIsMarked(): void
    {
        $this->queueThreeDays();

        $data = array_column($this->buttons($this->board()->render()['keyboard']), 'callback_data', 'text');

        self::assertSame('bd:2026-08-01', $data['▸ Sat 1 Aug'] ?? null);
        self::assertSame('bd:2026-08-08', $data['Sat 8 Aug'] ?? null);
        self::assertSame('bd:2026-08-15', $data['Sat 15 Aug'] ?? null);
        self::assertArrayHasKey('🗓 Dates', $data);
        self::assertNotContains('bd:', $data);
    }

    public function testTheDayButtonsStayInTheSamePlacesWhenAnotherDayIsShown(): void
    {
        $this->queueThreeDays();
        $first = array_column($this->buttons($this->board()->render()['keyboard']), 'callback_data');
        $this->queueThreeDays();
        $later = array_column($this->buttons($this->board()->render('2026-08-15')['keyboard']), 'callback_data');

        $days = static fn (array $data): array => array_values(
            array_filter($data, static fn (string $d): bool => str_starts_with($d, 'bd:'))
        );
        self::assertSame(['bd:2026-08-01', 'bd:2026-08-08', 'bd:2026-08-15'], $days($first));
        self::assertSame($days($first), $days($later));
    }

    public function testASingleDayHasNoDayButtons(): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow(1, 100, 'Party A', '2026-08-01')];
        $this->wpdb->nextResults[] = [];

        $labels = array_column($this->buttons($this->board()->render()['keyboard']), 'text');

        self::assertNotContains('🗓 Dates', $labels);
    }

    public function testAPickedDayShowsOnlyThatDayAndIsMarked(): void
    {
        $this->queueThreeDays();

        $rendered = $this->board()->render('2026-08-08');
        $data = array_column($this->buttons($rendered['keyboard']), 'callback_data', 'text');

        self::assertArrayHasKey('📅 Party B', $data);
        self::assertArrayNotHasKey('📅 Party A', $data);
        self::assertArrayNotHasKey('📅 Party C', $data);
        // Its task button carries the day, so a join redraws the same day.
        self::assertContains('t:2:2026-08-08', $data);
        self::assertSame('bd:2026-08-01', $data['Sat 1 Aug'] ?? null);
        self::assertSame('bd:2026-08-08', $data['▸ Sat 8 Aug'] ?? null);
        self::assertArrayNotHasKey('⬅ Back to the next event', $data);
    }

    public function testADayWithNoOpenTasksFallsBackToTheNextEvent(): void
    {
        $this->queueThreeDays();

        $labels = array_column($this->buttons($this->board()->render('2026-09-30')['keyboard']), 'text');

        self::assertContains('📅 Party A', $labels);
        self::assertContains('▸ Sat 1 Aug', $labels);
    }

    public function testTappingADayEditsTheTappedMessageToThatDay(): void
    {
        $this->queueThreeDays();

        $query = $this->callbackQuery('bd:2026-08-15');
        $query['message'] = ['message_id' => 5, 'chat' => ['id' => 100, 'type' => 'supergroup']];
        $this->board()->onPickDate($query);

        $edit = $this->lastCallTo('editMessageText');
        self::assertSame(100, $edit['chat_id']);
        self::assertSame(5, $edit['message_id']);
        $labels = array_column($this->buttons($edit['reply_markup']['inline_keyboard']), 'text');
        self::assertContains('📅 Party C', $labels);
        self::assertNotContains('📅 Party A', $labels);
        self::assertContains('answerCallbackQuery', $this->calledMethods());
    }

    public function testJoiningOnAPickedDayRedrawsTheGroupBoardOnThatDay(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->verifiedPerson(555);
        $this->wpdb->nextVars[] = 2; // taskCapacity
        $this->wpdb->nextQueryResults[] = 1; // conditional insert
        $this->queueThreeDays(); // the redraw

        $query = $this->callbackQuery('t:2:2026-08-08');
        $query['message'] = ['message_id' => 5, 'chat' => ['id' => 100, 'type' => 'supergroup']];
        $this->board()->onJoinLeave($query);

        self::assertSame('You’re in! See you there.', $this->lastCallTo('answerCallbackQuery')['text']);
        $labels = array_column($this->buttons($this->lastCallTo('editMessageText')['reply_markup']['inline_keyboard']), 'text');
        self::assertContains('📅 Party B', $labels);
        self::assertNotContains('📅 Party A', $labels);
    }

    public function testJoiningOnAPickedDayInADmKeepsTheGroupBoardOnTheNextEvent(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->verifiedPerson(555);
        $this->wpdb->nextVars[] = 2; // taskCapacity
        $this->wpdb->nextQueryResults[] = 1; // conditional insert
        $this->queueThreeDays(); // the group board's redraw
        $this->queueThreeDays(); // the private copy's redraw

        $query = $this->callbackQuery('t:2:2026-08-08');
        $query['message'] = ['message_id' => 42, 'chat' => ['id' => 555, 'type' => 'private']];
        $this->board()->onJoinLeave($query);

        $edits = array_values(array_filter($this->telegramCalls, static fn (array $c): bool => 'editMessageText' === $c['method']));
        self::assertCount(2, $edits);
        $group = array_column($this->buttons($edits[0]['body']['reply_markup']['inline_keyboard']), 'text');
        $private = array_column($this->buttons($edits[1]['body']['reply_markup']['inline_keyboard']), 'text');
        self::assertSame(100, $edits[0]['body']['chat_id']);
        self::assertContains('📅 Party A', $group);
        self::assertSame(555, $edits[1]['body']['chat_id']);
        self::assertContains('📅 Party B', $private);
    }

    /**
     * Boards posted before the day buttons carry j:/l:/t:<id> with no day;
     * they still join, and the redraw shows the next event.
     */
    public function testOldCallbackDataStillWorks(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->verifiedPerson(555);
        $this->wpdb->nextVars[] = 2; // taskCapacity
        $this->wpdb->nextQueryResults[] = 1; // conditional insert
        $this->queueThreeDays();

        $query = $this->callbackQuery('j:2');
        $query['message'] = ['message_id' => 5, 'chat' => ['id' => 100, 'type' => 'supergroup']];
        $this->board()->onJoinLeave($query);

        self::assertSame('You’re in! See you there.', $this->lastCallTo('answerCallbackQuery')['text']);
        $labels = array_column($this->buttons($this->lastCallTo('editMessageText')['reply_markup']['inline_keyboard']), 'text');
        self::assertContains('📅 Party A', $labels);
    }

    public function testTheAutomaticRedrawGoesBackToTheNextEvent(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->queueThreeDays();

        $this->board()->refresh();

        $labels = array_column($this->buttons($this->lastCallTo('editMessageText')['reply_markup']['inline_keyboard']), 'text');
        self::assertContains('📅 Party A', $labels);
        self::assertNotContains('📅 Party B', $labels);
    }

    public function testIgnoresMalformedCallbackData(): void
    {
        $this->board()->onJoinLeave($this->callbackQuery('nonsense'));

        self::assertSame([], $this->wpdb->inserts);
        // Still answers, so the tapping client's spinner stops.
        self::assertSame(['answerCallbackQuery'], $this->calledMethods());
    }

    // --- refresh / setBoardChat ---------------------------------------------

    public function testRefreshPostsAndStoresTheMessageIdWhenNoBoardExists(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 0];
        $this->telegramResults['sendMessage'] = ['message_id' => 77];
        $this->wpdb->nextResults[] = []; // no tasks

        self::assertTrue($this->board()->refresh());

        self::assertContains('sendMessage', $this->calledMethods());
        self::assertSame(77, $this->options[BoardService::BOARD_OPTION]['message_id']);
    }

    public function testRefreshIsInertWithoutABoardChat(): void
    {
        self::assertFalse($this->board()->refresh());

        self::assertSame([], $this->telegramCalls);
    }

    public function testRefreshIsInertWithoutAToken(): void
    {
        $this->options[TelegramClient::TOKEN_OPTION] = '';
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];

        self::assertFalse($this->board()->refresh());

        self::assertSame([], $this->telegramCalls);
    }

    /**
     * The Settings button reports what happened, so a failed edit - a board
     * deleted from the group, most likely - has to come back false rather than
     * as a silent success.
     */
    public function testRefreshReportsAFailedEdit(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->telegramErrors['editMessageText'] = 'Bad Request: message to edit not found';
        $this->wpdb->nextResults[] = []; // no tasks

        self::assertFalse($this->board()->refresh());
    }

    /**
     * An unchanged board is still a successful refresh. Telegram answers a
     * no-op edit with an error, which TelegramClient already classes as benign;
     * reporting that to the organizer as a failure would send them looking for
     * a problem that does not exist.
     */
    public function testRefreshTreatsAnUnchangedBoardAsSuccess(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->telegramErrors['editMessageText'] = 'Bad Request: message is not modified';
        $this->wpdb->nextResults[] = []; // no tasks

        self::assertTrue($this->board()->refresh());
    }

    public function testHasHomeReportsWhetherTheBoardHasAChat(): void
    {
        self::assertFalse($this->board()->hasHome());

        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];

        self::assertTrue($this->board()->hasHome());
    }

    public function testSetBoardChatStoresTheChatAndClearsAnyMessageId(): void
    {
        $this->board()->setBoardChat(321);

        self::assertSame(
            ['chat_id' => 321, 'message_id' => 0],
            $this->options[BoardService::BOARD_OPTION]
        );
    }

    public function testRefreshIfChangedEditsWhenTheBoardHasChanged(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->wpdb->nextResults[] = []; // render() -> no tasks
        $this->wpdb->nextResults[] = []; // refresh() re-renders -> no tasks

        $this->board()->refreshIfChanged();

        self::assertContains('editMessageText', $this->calledMethods());
        self::assertArrayHasKey(BoardService::BOARD_HASH_OPTION, $this->options);
    }

    public function testRefreshIfChangedDoesNothingWhenUnchanged(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->wpdb->nextResults[] = []; // first render
        $this->wpdb->nextResults[] = []; // first refresh re-render
        $this->board()->refreshIfChanged();

        $editsAfterFirst = count(array_filter($this->calledMethods(), static fn (string $m): bool => 'editMessageText' === $m));

        // Same content again: the stored hash matches, so no second edit.
        $this->wpdb->nextResults[] = []; // second render only
        $this->board()->refreshIfChanged();

        $editsAfterSecond = count(array_filter($this->calledMethods(), static fn (string $m): bool => 'editMessageText' === $m));
        self::assertSame($editsAfterFirst, $editsAfterSecond);
    }

    // --- onBotMembershipChange ----------------------------------------------

    public function testBotAddedToAGroupCapturesItAndPostsTheBoard(): void
    {
        $this->wpdb->nextResults[] = []; // refresh render: no tasks

        $this->board()->onBotMembershipChange([
            'chat' => ['id' => -1002000, 'type' => 'supergroup'],
            'new_chat_member' => ['status' => 'member'],
        ]);

        self::assertSame(-1002000, $this->options[BoardService::BOARD_OPTION]['chat_id']);
        self::assertContains('sendMessage', $this->calledMethods());
    }

    public function testBotRemovedFromAGroupDoesNotCaptureIt(): void
    {
        $this->board()->onBotMembershipChange([
            'chat' => ['id' => -1002000, 'type' => 'supergroup'],
            'new_chat_member' => ['status' => 'left'],
        ]);

        self::assertArrayNotHasKey(BoardService::BOARD_OPTION, $this->options);
        self::assertSame([], $this->telegramCalls);
    }

    public function testBotAddedToAPrivateChatIsIgnored(): void
    {
        $this->board()->onBotMembershipChange([
            'chat' => ['id' => 555, 'type' => 'private'],
            'new_chat_member' => ['status' => 'member'],
        ]);

        self::assertArrayNotHasKey(BoardService::BOARD_OPTION, $this->options);
    }

    // --- group lock ---------------------------------------------------------

    public function testLockKeepsTheBoardInTheFirstGroupWhenAddedToAnother(): void
    {
        // The lock defaults on, so a board already bound to one group is not
        // moved when the bot is added to a second - and the bot leaves the
        // second group.
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => -100, 'message_id' => 5];

        $this->board()->onBotMembershipChange([
            'chat' => ['id' => -200, 'type' => 'supergroup'],
            'new_chat_member' => ['status' => 'member'],
        ]);

        self::assertSame(-100, $this->options[BoardService::BOARD_OPTION]['chat_id']);
        self::assertSame(
            [['method' => 'leaveChat', 'body' => ['chat_id' => -200]]],
            $this->telegramCalls
        );
    }

    public function testLockStillCapturesTheveryFirstGroup(): void
    {
        // No board bound yet: even locked, the first group to add the bot is
        // captured - the lock only blocks a move, not the initial home.
        $this->wpdb->nextResults[] = []; // refresh render: no tasks

        $this->board()->onBotMembershipChange([
            'chat' => ['id' => -100, 'type' => 'supergroup'],
            'new_chat_member' => ['status' => 'member'],
        ]);

        self::assertSame(-100, $this->options[BoardService::BOARD_OPTION]['chat_id']);
        self::assertNotContains('leaveChat', $this->calledMethods());
    }

    public function testTheSameGroupAddingTheBotAgainIsNotLeft(): void
    {
        // Locked, and the group re-adding the bot is the one holding the board:
        // it stays the board's group and the bot stays in it.
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => -100, 'message_id' => 5];
        $this->wpdb->nextResults[] = []; // refresh render: no tasks

        $this->board()->onBotMembershipChange([
            'chat' => ['id' => -100, 'type' => 'supergroup'],
            'new_chat_member' => ['status' => 'member'],
        ]);

        self::assertSame(-100, $this->options[BoardService::BOARD_OPTION]['chat_id']);
        self::assertNotContains('leaveChat', $this->calledMethods());
    }

    public function testUnlockedBoardMovesToTheNewGroup(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => -100, 'message_id' => 5];
        $this->options[BoardService::LOCK_OPTION] = '0'; // unlocked to move it
        $this->wpdb->nextResults[] = []; // refresh render

        $this->board()->onBotMembershipChange([
            'chat' => ['id' => -200, 'type' => 'supergroup'],
            'new_chat_member' => ['status' => 'member'],
        ]);

        self::assertSame(-200, $this->options[BoardService::BOARD_OPTION]['chat_id']);
        self::assertNotContains('leaveChat', $this->calledMethods());
    }

    public function testLockAlsoBlocksBoardCommandInAnotherGroup(): void
    {
        // /board run in a different group cannot move a locked board either.
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => -100, 'message_id' => 5];

        $this->board()->repostInto(-200);

        self::assertSame(-100, $this->options[BoardService::BOARD_OPTION]['chat_id']);
        self::assertSame([], $this->telegramCalls);
    }
}

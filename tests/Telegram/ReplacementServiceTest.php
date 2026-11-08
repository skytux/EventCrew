<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MarkedBy;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\ReplacementService;

final class ReplacementServiceTest extends TelegramTestCase
{
    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->transients = [];

        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);

            return true;
        });
        Functions\when('get_the_title')->justReturn('');
        Functions\when('wp_mail')->justReturn(true);
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
    }

    private function service(): ReplacementService
    {
        return new ReplacementService(
            new AssignmentRepository(),
            new TaskRepository(),
            new PersonRepository(),
            $this->client(),
            $this->takeoverService()
        );
    }

    private function verifiedPersonRow(int $id, int $telegramId): array
    {
        return [
            'id' => $id,
            'email' => sprintf('p%d@example.com', $id),
            'display_name' => 'Person ' . $id,
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => $telegramId,
        ];
    }

    private function taskRow(int $id): array
    {
        return [
            'id' => $id,
            'task_date' => '2026-08-01',
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_post_id' => null,
            'event_label' => 'Ecstatic Dance',
            'starts_at' => '2026-08-01 20:00:00',
            'ends_at' => null,
        ];
    }

    public function testStartAsksWhoIsBeingCovered(): void
    {
        $this->wpdb->nextRows[] = $this->verifiedPersonRow(7, 555); // the cover

        $this->service()->start(555, 555);

        self::assertArrayHasKey('eventcrew_tg_await_replace_target_555', $this->transients);
        self::assertContains('sendMessage', $this->calledMethods());
    }

    public function testCaptureTargetListsTheNamedPersonsUpcomingTasks(): void
    {
        // people->all() name search returns the target (Alice, id 9)
        $this->wpdb->nextResults[] = [$this->verifiedPersonRow(9, 999)];
        // assignments->forPerson(9)
        $this->wpdb->nextResults[] = [
            ['id' => 3, 'task_id' => 5, 'person_id' => 9, 'status' => AssignmentStatus::SIGNED_UP],
        ];
        // tasks->find(5)
        $this->wpdb->nextRows[] = $this->taskRow(5);

        $this->service()->captureTarget(555, 555, 'Person 9', []);

        $body = $this->lastCallTo('sendMessage');
        self::assertSame('rep:3', $body['reply_markup']['inline_keyboard'][0][0]['callback_data']);
    }

    public function testCaptureTargetResolvesATextMentionExactly(): void
    {
        // No name-search results queued; the text_mention resolves by user id.
        $this->wpdb->nextRows[] = $this->verifiedPersonRow(9, 999); // findByTelegramUserId(999)
        $this->wpdb->nextResults[] = [                              // forPerson(9)
            ['id' => 3, 'task_id' => 5, 'person_id' => 9, 'status' => AssignmentStatus::SIGNED_UP],
        ];
        $this->wpdb->nextRows[] = $this->taskRow(5);                // tasks->find(5)

        $this->service()->captureTarget(555, 555, 'Alice', [
            ['type' => 'text_mention', 'user' => ['id' => 999]],
        ]);

        self::assertSame('rep:3', $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'][0][0]['callback_data']);
    }

    public function testSelectOnlySendsTheHolderARequestAndMovesNothing(): void
    {
        // findByTelegramUserId(555) -> the cover (id 7)
        $this->wpdb->nextRows[] = array_merge($this->verifiedPersonRow(7, 555), ['telegram_chat_id' => 555]);
        // find(assignment 3) -> the original's occupying slot on task 5
        $this->wpdb->nextRows[] = ['id' => 3, 'task_id' => 5, 'person_id' => 9, 'status' => AssignmentStatus::SIGNED_UP];
        // tasks->find(5)
        $this->wpdb->nextRows[] = $this->taskRow(5);
        // requestFor: findFor(5,7) -> null (not already on)
        $this->wpdb->nextRows[] = null;
        // request: people->find(9) -> the holder, then no pending request yet
        $this->wpdb->nextRows[] = array_merge($this->verifiedPersonRow(9, 999), ['telegram_chat_id' => 999]);
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextQueryResults[] = 1; // the request row is stored

        $this->service()->onSelect([
            'id' => 'cbq', 'from' => ['id' => 555], 'data' => 'rep:3',
        ]);

        // Nothing moved: no status change, and the only insert is the request.
        self::assertSame([], $this->wpdb->updates);
        $inserts = array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_starts_with(ltrim($sql), 'INSERT')
        ));
        self::assertCount(1, $inserts);
        self::assertStringContainsString('eventcrew_takeovers', $inserts[0]);

        // The holder got the two buttons.
        $ask = $this->telegramCalls[0]['body'];
        self::assertSame(999, $ask['chat_id']);
        self::assertSame('tko:y:1', $ask['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        self::assertSame('tko:n:1', $ask['reply_markup']['inline_keyboard'][0][1]['callback_data']);

        // The cover was told the request went out, with the deadline.
        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(555, $sent['chat_id']);
        self::assertStringContainsString('Thu 23 Jul 12:00', $sent['text']);
    }

    public function testTheHoldersButtonAnswersAndReplacesTheButtons(): void
    {
        // findByTelegramUserId(999) -> the holder
        $this->wpdb->nextRows[] = array_merge($this->verifiedPersonRow(9, 999), ['telegram_chat_id' => 999]);
        // answer(): takeover, assignment, task, cover, holder
        $this->wpdb->nextRows[] = [
            'id' => 1, 'assignment_id' => 3, 'task_id' => 5, 'holder_id' => 9, 'cover_id' => 7,
            'status' => 'pending', 'deadline_at' => '2026-07-23 12:00:00',
        ];
        $this->wpdb->nextRows[] = ['id' => 3, 'task_id' => 5, 'person_id' => 9, 'status' => AssignmentStatus::SIGNED_UP];
        $this->wpdb->nextRows[] = $this->taskRow(5);
        $this->wpdb->nextRows[] = $this->verifiedPersonRow(7, 555);
        $this->wpdb->nextRows[] = $this->verifiedPersonRow(9, 999);
        $this->wpdb->nextQueryResults[] = 1; // resolve(refused)

        $this->service()->onAnswer([
            'id' => 'cbq',
            'from' => ['id' => 999],
            'data' => 'tko:n:1',
            'message' => ['message_id' => 42, 'chat' => ['id' => 999], 'text' => 'The request'],
        ]);

        self::assertSame([], $this->wpdb->updates, 'refusing moves nothing');
        $edit = $this->lastCallTo('editMessageText');
        self::assertSame(42, $edit['message_id']);
        self::assertStringStartsWith("The request\n\nYou keep your task", $edit['text']);
        self::assertArrayNotHasKey('reply_markup', $edit, 'the buttons are gone');
    }

    /**
     * The organize task is not offered to a cover who is not an organizer;
     * their other tasks still are.
     */
    public function testCaptureTargetLeavesOutTheOrganizeTaskForACoverWhoIsNotAnOrganizer(): void
    {
        $this->wpdb->nextResults[] = [$this->verifiedPersonRow(9, 999)]; // name search
        $this->wpdb->nextResults[] = [                                    // forPerson(9)
            ['id' => 3, 'task_id' => 5, 'person_id' => 9, 'status' => AssignmentStatus::SIGNED_UP],
            ['id' => 4, 'task_id' => 6, 'person_id' => 9, 'status' => AssignmentStatus::SIGNED_UP],
        ];
        $this->wpdb->nextRows[] = $this->taskRow(5);                                      // tasks->find(5)
        $this->wpdb->nextRows[] = array_merge($this->taskRow(6), ['role_slug' => 'organize']); // tasks->find(6)
        $this->wpdb->nextRows[] = $this->verifiedPersonRow(7, 555);                       // the cover, not an organizer

        $this->service()->captureTarget(555, 555, 'Person 9', []);

        $keyboard = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'];
        self::assertSame(['rep:3'], array_map(static fn (array $row): string => $row[0]['callback_data'], $keyboard));
    }

    public function testCaptureTargetOffersTheOrganizeTaskToACoverWhoIsAnOrganizer(): void
    {
        $this->wpdb->nextResults[] = [$this->verifiedPersonRow(9, 999)];
        $this->wpdb->nextResults[] = [
            ['id' => 4, 'task_id' => 6, 'person_id' => 9, 'status' => AssignmentStatus::SIGNED_UP],
        ];
        $this->wpdb->nextRows[] = array_merge($this->taskRow(6), ['role_slug' => 'organize']);
        $this->wpdb->nextRows[] = array_merge($this->verifiedPersonRow(7, 555), ['is_organizer' => 1]);

        $this->service()->captureTarget(555, 555, 'Person 9', []);

        self::assertSame('rep:4', $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'][0][0]['callback_data']);
    }

    public function testSelectRefusesTheOrganizeTaskToACoverWhoIsNotAnOrganizer(): void
    {
        $this->wpdb->nextRows[] = $this->verifiedPersonRow(7, 555);
        $this->wpdb->nextRows[] = ['id' => 4, 'task_id' => 6, 'person_id' => 9, 'status' => AssignmentStatus::SIGNED_UP];
        $this->wpdb->nextRows[] = array_merge($this->taskRow(6), ['role_slug' => 'organize']);

        $this->service()->onSelect([
            'id' => 'cbq', 'from' => ['id' => 555], 'data' => 'rep:4',
        ]);

        self::assertSame([], $this->wpdb->updates);
        self::assertStringNotContainsString('INSERT INTO', implode("\n", $this->wpdb->queries));
        $answer = $this->lastCallTo('answerCallbackQuery');
        self::assertTrue((bool) $answer['show_alert']);
        self::assertSame('That task is no longer available.', $answer['text']);
    }

    /**
     * A role with Guest sign up off (shown by Guest or Members display) is
     * not taken over by someone without it either.
     */
    public function testATaskClosedToGuestsIsNotOfferedOrGivenToACoverWithoutTheRole(): void
    {
        $this->options[\EventCrew\Support\Roles::OPTION_NAME] = [[
            'slug' => 'decorate', 'label' => 'Decorate', 'on_board' => false,
            'members_display' => true, 'guest_signup' => false,
        ]];

        $this->wpdb->nextResults[] = [$this->verifiedPersonRow(9, 999)]; // name search
        $this->wpdb->nextResults[] = [                                    // forPerson(9)
            ['id' => 3, 'task_id' => 5, 'person_id' => 9, 'status' => AssignmentStatus::SIGNED_UP],
        ];
        $this->wpdb->nextRows[] = $this->taskRow(5);                      // tasks->find(5)
        $this->wpdb->nextRows[] = $this->verifiedPersonRow(7, 555);       // the cover, no role

        $this->service()->captureTarget(555, 555, 'Person 9', []);

        self::assertStringNotContainsString(
            'rep:3',
            (string) json_encode($this->lastCallTo('sendMessage')['reply_markup'] ?? [])
        );

        $this->wpdb->nextRows[] = $this->verifiedPersonRow(7, 555);
        $this->wpdb->nextRows[] = ['id' => 3, 'task_id' => 5, 'person_id' => 9, 'status' => AssignmentStatus::SIGNED_UP];
        $this->wpdb->nextRows[] = $this->taskRow(5);

        $this->service()->onSelect(['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'rep:3']);

        self::assertSame([], $this->wpdb->updates);
        self::assertSame('That task is no longer available.', $this->lastCallTo('answerCallbackQuery')['text']);
    }

    public function testSelectRefusesCoveringYourOwnTask(): void
    {
        // The original slot belongs to person 7, and the cover is also 7.
        $this->wpdb->nextRows[] = $this->verifiedPersonRow(7, 555);
        $this->wpdb->nextRows[] = ['id' => 3, 'task_id' => 5, 'person_id' => 7, 'status' => AssignmentStatus::SIGNED_UP];
        $this->wpdb->nextRows[] = $this->taskRow(5);

        $this->service()->onSelect([
            'id' => 'cbq', 'from' => ['id' => 555], 'data' => 'rep:3',
        ]);

        self::assertSame([], $this->wpdb->updates);
        self::assertTrue((bool) $this->lastCallTo('answerCallbackQuery')['show_alert']);
    }

    /**
     * Owner 2026-11-08: a full task's 🔁 Take someone's task (rep:t:<id>)
     * lists its holders by first name in the DM, each a rep:<assignment>
     * button that asks that holder.
     */
    public function testTakeSomeonesTaskListsTheHoldersOfAFullTask(): void
    {
        $this->wpdb->nextRows[] = array_merge($this->verifiedPersonRow(7, 555), ['telegram_chat_id' => 555]);
        $this->wpdb->nextRows[] = $this->taskRow(5);                         // tasks->find(5)
        $this->wpdb->nextResults[] = [['task_id' => 5, 'taken' => 2]];       // occupancyFor: full
        $this->wpdb->nextResults[] = [                                       // forTask(5)
            ['id' => 3, 'task_id' => 5, 'person_id' => 8, 'status' => AssignmentStatus::SIGNED_UP],
            ['id' => 4, 'task_id' => 5, 'person_id' => 9, 'status' => AssignmentStatus::SIGNED_UP],
        ];
        $this->wpdb->nextRows[] = array_merge($this->verifiedPersonRow(8, 888), ['display_name' => 'Anna Virtanen']);
        $this->wpdb->nextRows[] = $this->verifiedPersonRow(9, 999);

        $this->service()->onSelect([
            'id' => 'cbq', 'from' => ['id' => 555], 'data' => ReplacementService::TASK_PREFIX . '5',
            'message' => ['message_id' => 1, 'chat' => ['id' => 555, 'type' => 'private']],
        ]);

        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(555, $sent['chat_id']);
        self::assertSame('Whose task do you want to take? They decide.', $sent['text']);
        $keyboard = $sent['reply_markup']['inline_keyboard'];
        self::assertSame(['rep:3', 'rep:4'], array_map(static fn (array $row): string => $row[0]['callback_data'], $keyboard));
        self::assertStringStartsWith('Anna · ', $keyboard[0][0]['text']);
        self::assertStringStartsWith('Person · ', $keyboard[1][0]['text']);
        self::assertSame([], $this->wpdb->inserts, 'listing asks nobody yet');
    }

    /** The board's row (rep:d:<date>) covers that day's full tasks only. */
    public function testTheBoardsTakeSomeonesTaskListsTheDaysFullTasks(): void
    {
        $this->wpdb->nextRows[] = array_merge($this->verifiedPersonRow(7, 555), ['telegram_chat_id' => 555]);
        $this->wpdb->nextResults[] = [$this->taskRow(5), $this->taskRow(6)];   // upcoming
        $this->wpdb->nextResults[] = [['task_id' => 6, 'taken' => 2]];          // only 6 is full
        $this->wpdb->nextResults[] = [
            ['id' => 4, 'task_id' => 6, 'person_id' => 9, 'status' => AssignmentStatus::SIGNED_UP],
        ];
        $this->wpdb->nextRows[] = $this->verifiedPersonRow(9, 999);

        $this->service()->onSelect([
            'id' => 'cbq', 'from' => ['id' => 555], 'data' => ReplacementService::DAY_PREFIX . '2026-08-01',
        ]);

        $keyboard = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'];
        self::assertSame(['rep:4'], array_map(static fn (array $row): string => $row[0]['callback_data'], $keyboard));
    }

    /** The cover already on the task, or nobody to ask: a popup, no list. */
    public function testTakeSomeonesTaskOnATaskYouAreOnSaysThereIsNothingToTake(): void
    {
        $this->wpdb->nextRows[] = $this->verifiedPersonRow(7, 555);
        $this->wpdb->nextRows[] = $this->taskRow(5);
        $this->wpdb->nextResults[] = [['task_id' => 5, 'taken' => 2]];
        $this->wpdb->nextResults[] = [
            ['id' => 3, 'task_id' => 5, 'person_id' => 7, 'status' => AssignmentStatus::SIGNED_UP],
            ['id' => 4, 'task_id' => 5, 'person_id' => 9, 'status' => AssignmentStatus::SIGNED_UP],
        ];

        $this->service()->onSelect([
            'id' => 'cbq', 'from' => ['id' => 555], 'data' => ReplacementService::TASK_PREFIX . '5',
        ]);

        $answer = $this->lastCallTo('answerCallbackQuery');
        self::assertTrue((bool) $answer['show_alert']);
        self::assertSame('No task here can be taken over.', $answer['text']);
        self::assertNull($this->lastCallTo('sendMessage'));
    }
}

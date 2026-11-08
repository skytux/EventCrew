<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Assignment;
use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\MarkedBy;
use EventCrew\Support\TakeoverDeadline;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\MenuButtons;
use EventCrew\Tests\Telegram\TelegramTestCase;

/**
 * A takeover only happens when the holder agrees: the request, the holder's
 * yes and no, the deadline running out, and the request closing when the
 * holder's place goes away.
 *
 * The clock is fixed at 2026-07-20 12:00:00 by TestCase.
 */
final class TakeoverServiceTest extends TelegramTestCase
{
    /** @var array<int, array{to: string, subject: string, body: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];
        Functions\when('get_the_title')->justReturn('');
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

            return true;
        });
    }

    /** @return array<string, mixed> */
    private static function personRow(int $id, ?int $chatId = null): array
    {
        return [
            'id' => $id,
            'email' => sprintf('p%d@example.com', $id),
            'display_name' => 'Person ' . $id,
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => $chatId,
            'telegram_chat_id' => $chatId,
        ];
    }

    /** @return array<string, mixed> */
    private static function taskRow(string $startsAt = '2026-08-01 20:00:00'): array
    {
        return [
            'id' => 5,
            'task_date' => substr($startsAt, 0, 10),
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_post_id' => null,
            'event_label' => 'Ecstatic Dance',
            'starts_at' => $startsAt,
            'ends_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    private static function slotRow(string $status = AssignmentStatus::SIGNED_UP): array
    {
        return ['id' => 3, 'task_id' => 5, 'person_id' => 9, 'status' => $status];
    }

    /** @return array<string, mixed> */
    private static function takeoverRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'assignment_id' => 3,
            'task_id' => 5,
            'holder_id' => 9,
            'cover_id' => 7,
            'status' => 'pending',
            'deadline_at' => '2026-07-23 12:00:00',
            'created_at' => '2026-07-20 12:00:00',
        ], $overrides);
    }

    /** @return array<int, string> */
    private function statementsLike(string $start): array
    {
        return array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_starts_with(ltrim($sql), $start)
        ));
    }

    /** @return array{ok: bool, text: string} */
    private function request(string $startsAt = '2026-08-01 20:00:00'): array
    {
        return $this->takeoverService()->request(
            Assignment::fromRow(self::slotRow()),
            Task::fromRow(self::taskRow($startsAt)),
            Person::fromRow(self::personRow(7, 555))
        );
    }

    /** Queues the five reads answer() starts with. */
    private function queueAnswerReads(array $takeover = [], string $slotStatus = AssignmentStatus::SIGNED_UP): void
    {
        $this->wpdb->nextRows[] = self::takeoverRow($takeover);
        $this->wpdb->nextRows[] = self::slotRow($slotStatus);
        $this->wpdb->nextRows[] = self::taskRow();
        $this->wpdb->nextRows[] = self::personRow(7, 555); // cover
        $this->wpdb->nextRows[] = self::personRow(9, 999); // holder
    }

    /** requestFor() (web and the bot's rep:<id>) refuses with the bot's words. */
    public function testRequestForRefusesYourOwnAGoneAndADoubleTask(): void
    {
        $this->wpdb->nextRows[] = self::slotRow();
        $this->wpdb->nextRows[] = self::taskRow();
        $own = $this->takeoverService()->requestFor(3, Person::fromRow(self::personRow(9)));
        self::assertSame(['ok' => false, 'text' => 'That’s your own task.'], $own);

        $this->wpdb->nextRows[] = self::slotRow(AssignmentStatus::CANCELLED);
        $this->wpdb->nextRows[] = self::taskRow();
        $gone = $this->takeoverService()->requestFor(3, Person::fromRow(self::personRow(7)));
        self::assertSame(['ok' => false, 'text' => 'That task is no longer available.'], $gone);

        $this->wpdb->nextRows[] = self::slotRow();
        $this->wpdb->nextRows[] = self::taskRow();
        $this->wpdb->nextRows[] = ['id' => 4, 'task_id' => 5, 'person_id' => 7, 'status' => AssignmentStatus::SIGNED_UP];
        $double = $this->takeoverService()->requestFor(3, Person::fromRow(self::personRow(7)));
        self::assertSame(['ok' => false, 'text' => 'You’re already signed up for that one.'], $double);
        self::assertSame([], $this->wpdb->inserts);
    }

    /** holdersFor() lists each person on a task by first name, and nobody for one of them. */
    public function testHoldersForListsThePeopleOnATaskButNotForOneOfThem(): void
    {
        $task = Task::fromRow(self::taskRow());
        $onIt = [
            ['id' => 3, 'task_id' => 5, 'person_id' => 8, 'status' => AssignmentStatus::SIGNED_UP],
            ['id' => 4, 'task_id' => 5, 'person_id' => 9, 'status' => AssignmentStatus::SIGNED_UP],
        ];

        $this->wpdb->nextResults[] = $onIt;
        $this->wpdb->nextRows[] = array_merge(self::personRow(8), ['display_name' => 'Anna Virtanen']);
        $this->wpdb->nextRows[] = array_merge(self::personRow(9), ['display_name' => 'Bo']);
        self::assertSame(
            [['assignment_id' => 3, 'name' => 'Anna'], ['assignment_id' => 4, 'name' => 'Bo']],
            $this->takeoverService()->holdersFor($task, Person::fromRow(self::personRow(7)))
        );

        $this->wpdb->nextResults[] = $onIt;
        $this->wpdb->nextRows[] = self::personRow(8);
        self::assertSame([], $this->takeoverService()->holdersFor($task, Person::fromRow(self::personRow(9))));
    }

    public function testARequestAsksTheHolderByTelegramAndByEmailWithTheDeadline(): void
    {
        $this->options[BoardService::USERNAME_OPTION] = 'crewbot';
        $this->wpdb->nextRows[] = self::personRow(9, 999); // the holder
        $this->wpdb->nextRows[] = null;                    // no pending request
        $this->wpdb->nextQueryResults[] = 1;               // stored

        $result = $this->request();

        self::assertTrue($result['ok']);
        // Task 12 days away: 72 hours to answer.
        self::assertStringContainsString("'2026-07-23 12:00:00'", $this->statementsLike('INSERT')[0]);
        self::assertSame([], $this->wpdb->updates, 'nothing moves yet');

        // The cover's message: the deadline, and who decides.
        self::assertStringContainsString('Thu 23 Jul 12:00', $result['text']);
        self::assertStringContainsString('If they do not answer by then, they keep the task.', $result['text']);

        // The holder's DM: two buttons, the deadline, the process.
        $dm = $this->lastCallTo('sendMessage');
        self::assertSame(999, $dm['chat_id']);
        self::assertStringContainsString('Thu 23 Jul 12:00', $dm['text']);
        self::assertStringContainsString('If you do not answer by then, you keep the task.', $dm['text']);
        self::assertSame('tko:y:1', $dm['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        self::assertSame('tko:n:1', $dm['reply_markup']['inline_keyboard'][0][1]['callback_data']);

        // And always an email, with the link to the answer page.
        self::assertCount(1, $this->mails);
        self::assertSame('p9@example.com', $this->mails[0]['to']);
        self::assertStringContainsString('Person 7 asks to take over your task', $this->mails[0]['subject']);
        self::assertStringContainsString('eventcrew/v1/takeover?token=', $this->mails[0]['body']);
        // The holder has Telegram: an Open in Telegram link shows the same question.
        self::assertStringContainsString('https://t.me/crewbot?start=tov-', $this->mails[0]['body']);
    }

    public function testAHolderWithoutTelegramIsAskedByEmailOnly(): void
    {
        $this->wpdb->nextRows[] = self::personRow(9);
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextQueryResults[] = 1;

        self::assertTrue($this->request()['ok']);
        self::assertSame([], $this->calledMethods());
        self::assertCount(1, $this->mails);
    }

    public function testTheDeadlineBandFollowsHowFarAwayTheTaskIs(): void
    {
        $this->wpdb->nextRows[] = self::personRow(9);
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextQueryResults[] = 1;

        // Starts in 30 hours: 24 hours to answer.
        $result = $this->request('2026-07-21 18:00:00');

        self::assertStringContainsString("'2026-07-21 12:00:00'", $this->statementsLike('INSERT')[0]);
        self::assertStringContainsString('Tue 21 Jul 12:00', $result['text']);
    }

    public function testARequestTooCloseToTheStartIsRefused(): void
    {
        $this->wpdb->nextRows[] = self::personRow(9, 999);

        // Starts in 50 minutes: the 1-hour cutoff has already passed.
        $result = $this->request('2026-07-20 12:50:00');

        self::assertFalse($result['ok']);
        self::assertSame(
            'It is too late to ask: a takeover must be answered at least 1 hour before the task starts.',
            $result['text']
        );
        self::assertSame([], $this->statementsLike('INSERT'));
        self::assertSame([], $this->calledMethods());
        self::assertSame([], $this->mails);
    }

    public function testASecondCoverIsToldARequestIsAlreadyPending(): void
    {
        $this->wpdb->nextRows[] = self::personRow(9, 999);
        $this->wpdb->nextRows[] = self::takeoverRow(['cover_id' => 8]); // someone else asked first

        $result = $this->request();

        self::assertFalse($result['ok']);
        self::assertSame(
            'Someone else has already asked to take over this task. Wait until that request is answered.',
            $result['text']
        );
        self::assertSame([], $this->statementsLike('INSERT'));
        self::assertSame([], $this->mails);
    }

    public function testTheSameCoverAskingAgainIsRemindedOfTheDeadline(): void
    {
        $this->wpdb->nextRows[] = self::personRow(9, 999);
        $this->wpdb->nextRows[] = self::takeoverRow();

        $result = $this->request();

        self::assertFalse($result['ok']);
        self::assertSame(
            'You already asked to take over this task. Person 9 has until Thu 23 Jul 12:00 to answer.',
            $result['text']
        );
    }

    public function testTheInsertItselfAllowsOnlyOnePendingRequestPerTask(): void
    {
        // The check found nothing, but another request got in first: the
        // conditional insert stores no row.
        $this->wpdb->nextRows[] = self::personRow(9, 999);
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextQueryResults[] = 0;

        $result = $this->request();

        self::assertFalse($result['ok']);
        self::assertStringContainsString('Someone else has already asked', $result['text']);
        $insert = $this->statementsLike('INSERT')[0];
        self::assertStringContainsString('WHERE NOT EXISTS', $insert);
        self::assertStringContainsString("existing.task_id = 5 AND existing.status = 'pending'", $insert);
        self::assertSame([], $this->calledMethods(), 'the holder is not asked');
        self::assertSame([], $this->mails);
    }

    public function testAgreeingMovesTheTaskToTheCover(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => -100, 'message_id' => 1];
        $this->queueAnswerReads();
        $this->wpdb->nextQueryResults[] = 1; // resolve(accepted)
        $this->wpdb->nextVars[] = 2;         // join: task capacity
        $this->wpdb->nextRows[] = null;      // join: cover not on the task
        $this->wpdb->nextQueryResults[] = 1; // join: inserted
        $this->wpdb->nextRows[] = self::taskRow();  // confirmSignup: the task
        $this->wpdb->nextRows[] = ['id' => 11, 'task_id' => 5, 'person_id' => 7, 'status' => AssignmentStatus::SIGNED_UP];

        $text = $this->takeoverService()->answer(1, true, 9);

        self::assertStringStartsWith('✅ Done: Person 7 now has your task', $text);
        self::assertStringContainsString("SET status = 'accepted'", $this->statementsLike('UPDATE')[0]);

        // The holder's place became `replaced`, credited to the cover.
        self::assertSame(AssignmentStatus::REPLACED, $this->wpdb->updates[0]['data']['status']);
        self::assertSame(['id' => 3], $this->wpdb->updates[0]['where']);
        self::assertSame(7, $this->wpdb->updates[0]['data']['changed_by']);
        self::assertSame(MarkedBy::PERSON, $this->wpdb->updates[0]['data']['changed_by_kind']);
        // The cover joined the task.
        self::assertCount(1, $this->statementsLike('INSERT'));

        // The cover got the normal signup confirmation; the holder a short note.
        $dms = array_values(array_filter($this->telegramCalls, static fn (array $c): bool => 'sendMessage' === $c['method']));
        $byChat = [];

        foreach ($dms as $dm) {
            $byChat[$dm['body']['chat_id']][] = $dm['body']['text'];
        }

        self::assertStringStartsWith('✅ Signed up:', $byChat[555][0]);
        self::assertStringStartsWith('✅ Done: Person 7 now has your task', $byChat[999][0]);
        // The group gets one message, in Finnish and then in English.
        self::assertSame(
            "Person 7 tuuraa henkilöä Person 9: Somistus, Ecstatic Dance.\n"
            . 'Person 7 is covering for Person 9: Decorate at Ecstatic Dance.',
            $byChat[-100][0]
        );
        self::assertContains('Your task moved to Person 7', array_column($this->mails, 'subject'));
    }

    public function testAgreeingInTheBotSendsTheHolderNoExtraMessage(): void
    {
        $this->queueAnswerReads();
        $this->wpdb->nextQueryResults[] = 1;
        $this->wpdb->nextVars[] = 2;
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextQueryResults[] = 1;

        $this->takeoverService()->answer(1, true, 9, true);

        $holderDms = array_filter(
            $this->telegramCalls,
            static fn (array $c): bool => 'sendMessage' === $c['method'] && 999 === $c['body']['chat_id']
        );
        self::assertSame([], $holderDms);
        self::assertContains('Your task moved to Person 7', array_column($this->mails, 'subject'));
    }

    public function testWhenTheSlotIsLostTheHolderIsPutBack(): void
    {
        $this->queueAnswerReads();
        $this->wpdb->nextQueryResults[] = 1; // resolve(accepted)
        $this->wpdb->nextVars[] = 2;
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextQueryResults[] = 0; // the slot filled up

        $text = $this->takeoverService()->answer(1, true, 9);

        self::assertSame('The task could not be moved, so you keep it.', $text);
        self::assertSame(AssignmentStatus::REPLACED, $this->wpdb->updates[0]['data']['status']);
        self::assertSame(AssignmentStatus::SIGNED_UP, $this->wpdb->updates[1]['data']['status']);
        self::assertSame(['status' => 'cancelled'], $this->wpdb->updates[2]['data']);
        self::assertStringContainsString('could not be moved to you', $this->lastCallTo('sendMessage')['text']);
    }

    public function testRefusingKeepsTheTaskAndTellsTheCover(): void
    {
        $this->queueAnswerReads();
        $this->wpdb->nextQueryResults[] = 1; // resolve(refused)

        $text = $this->takeoverService()->answer(1, false, 9);

        self::assertSame('You keep your task: Decorate at Ecstatic Dance, Sat 1 Aug 20:00. Person 7 has been told.', $text);
        self::assertStringContainsString("SET status = 'refused'", $this->statementsLike('UPDATE')[0]);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->statementsLike('INSERT'));

        $toCover = $this->lastCallTo('sendMessage');
        self::assertSame(555, $toCover['chat_id']);
        self::assertStringContainsString('Person 9 keeps their task', $toCover['text']);
        // No other open task here: ask someone else, or the board.
        self::assertSame(
            [MenuButtons::REPLACE, MenuButtons::forVerb('board')],
            array_map(static fn (array $row): string => $row[0]['callback_data'], $toCover['reply_markup']['inline_keyboard'])
        );
        self::assertSame('🔁 Take over a task', $toCover['reply_markup']['inline_keyboard'][0][0]['text']);
        self::assertSame('p7@example.com', $this->mails[0]['to']);
    }

    public function testOnlyTheHolderCanAnswer(): void
    {
        $this->wpdb->nextRows[] = self::takeoverRow();

        self::assertSame('This link is no longer valid.', $this->takeoverService()->answer(1, true, 7));
        self::assertSame([], $this->statementsLike('UPDATE'));
    }

    public function testAnAnsweredRequestCannotBeAnsweredAgain(): void
    {
        $this->wpdb->nextRows[] = self::takeoverRow(['status' => 'refused']);

        self::assertSame('You already kept this task.', $this->takeoverService()->answer(1, true, 9));
        self::assertSame([], $this->statementsLike('UPDATE'));
        self::assertSame([], $this->wpdb->updates);
    }

    public function testAnAnswerAfterTheDeadlineExpiresTheRequest(): void
    {
        $this->queueAnswerReads(['deadline_at' => '2026-07-20 11:00:00']);
        $this->wpdb->nextQueryResults[] = 1;

        $text = $this->takeoverService()->answer(1, true, 9);

        self::assertSame('The time to answer has passed, so you keep your task.', $text);
        self::assertStringContainsString("SET status = 'expired'", $this->statementsLike('UPDATE')[0]);
        self::assertSame([], $this->wpdb->updates, 'nothing moved');
        self::assertStringContainsString('did not answer in time', $this->lastCallTo('sendMessage')['text']);
    }

    public function testTheSweepExpiresOverdueRequestsAndStopsAtTheFirstOpenOne(): void
    {
        $this->wpdb->nextResults[] = [
            self::takeoverRow(['deadline_at' => '2026-07-20 11:00:00']),
            self::takeoverRow(['id' => 2, 'deadline_at' => '2026-07-21 11:00:00']),
        ];
        // First row: slot, task, cover, holder.
        $this->wpdb->nextRows[] = self::slotRow();
        $this->wpdb->nextRows[] = self::taskRow();
        $this->wpdb->nextRows[] = self::personRow(7, 555);
        $this->wpdb->nextRows[] = self::personRow(9, 999);
        $this->wpdb->nextQueryResults[] = 1;
        // Second row: slot and task only, then the sweep stops.
        $this->wpdb->nextRows[] = self::slotRow();
        $this->wpdb->nextRows[] = self::taskRow();

        self::assertSame(1, $this->takeoverService()->expireDue(25));

        $updates = $this->statementsLike('UPDATE');
        self::assertCount(1, $updates);
        self::assertStringContainsString("SET status = 'expired'", $updates[0]);
        self::assertStringContainsString('WHERE id = 1', $updates[0]);
        self::assertSame([], $this->wpdb->updates, 'the holder keeps the task');
        self::assertStringContainsString(
            'Person 9 did not answer in time, so they keep their task',
            $this->lastCallTo('sendMessage')['text']
        );
    }

    public function testTheSweepClosesARequestWhoseTaskWasCancelled(): void
    {
        // TaskRepository::delete() cancels the places in bulk, past the
        // status hook, so the sweep is what notices.
        $this->wpdb->nextResults[] = [self::takeoverRow()];
        $this->wpdb->nextRows[] = self::slotRow(AssignmentStatus::CANCELLED);
        $this->wpdb->nextRows[] = self::taskRow();
        $this->wpdb->nextRows[] = self::personRow(7, 555);
        $this->wpdb->nextRows[] = self::personRow(9, 999);
        $this->wpdb->nextQueryResults[] = 1;

        self::assertSame(1, $this->takeoverService()->expireDue(25));
        self::assertStringContainsString("SET status = 'cancelled'", $this->statementsLike('UPDATE')[0]);
        self::assertStringContainsString('is closed, because the task changed or was cancelled', $this->lastCallTo('sendMessage')['text']);
    }

    public function testTheHolderCancellingClosesTheRequest(): void
    {
        $this->wpdb->nextRows[] = self::takeoverRow();          // pendingForAssignment(3)
        $this->wpdb->nextRows[] = self::taskRow();
        $this->wpdb->nextRows[] = self::personRow(7, 555);
        $this->wpdb->nextRows[] = self::personRow(9, 999);
        $this->wpdb->nextQueryResults[] = 1;

        $this->takeoverService()->onStatusChanged(3, AssignmentStatus::CANCELLED);

        self::assertStringContainsString('WHERE assignment_id = 3', $this->wpdb->queries[0]);
        self::assertStringContainsString("SET status = 'cancelled'", $this->statementsLike('UPDATE')[0]);
        self::assertSame(555, $this->lastCallTo('sendMessage')['chat_id']);
    }

    public function testAStatusThatKeepsThePlaceLeavesTheRequestOpen(): void
    {
        $this->takeoverService()->onStatusChanged(3, AssignmentStatus::ARRIVED);

        self::assertSame([], $this->wpdb->queries);
    }

    public function testTheAnswerLinkIsSigned(): void
    {
        $url = \EventCrew\Support\TakeoverService::answerUrl(4);

        self::assertStringStartsWith('https://site.test/wp-json/eventcrew/v1/takeover?token=', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame(4, \EventCrew\Support\SignedLink::verify('takeover', (string) $query['token']));
        self::assertNull(\EventCrew\Support\SignedLink::verify('ticket', (string) $query['token']));
        self::assertSame(TakeoverDeadline::DEFAULT_CUTOFF_HOURS, TakeoverDeadline::cutoffHours());
    }
}

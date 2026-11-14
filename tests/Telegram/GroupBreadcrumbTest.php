<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\RosterAssembler;
use EventCrew\Telegram\DmNote;
use EventCrew\Telegram\ReplacementService;
use EventCrew\Telegram\RosterService;

/**
 * The "📬 Sent you a DM." note (GroupBreadcrumb, DmNote): a toast on a group
 * button tap, a group message that deletes itself a minute later after a
 * typed group command, nothing in a private chat.
 */
final class GroupBreadcrumbTest extends TelegramTestCase
{
    /** @var list<array{time: int, hook: string, args: array<int, mixed>}> */
    private array $scheduled = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->scheduled = [];
        DmNote::takeTap();

        Functions\when('wp_schedule_single_event')->alias(function (int $time, string $hook, array $args = []): bool {
            $this->scheduled[] = ['time' => $time, 'hook' => $hook, 'args' => $args];

            return true;
        });
        Functions\when('get_the_title')->justReturn('');
        Functions\when('wp_mail')->justReturn(true);
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
    }

    private function replacement(): ReplacementService
    {
        return new ReplacementService(
            new AssignmentRepository(),
            new TaskRepository(),
            new PersonRepository(),
            $this->client(),
            $this->takeoverService()
        );
    }

    private function roster(): RosterService
    {
        return new RosterService(
            new RosterAssembler(
                new TaskRepository(),
                new AssignmentRepository(),
                new PersonRepository(),
                $this->standing()
            ),
            new TaskRepository(),
            new PersonRepository(),
            new AssignmentRepository(),
            $this->client()
        );
    }

    /** @return array<string, mixed> */
    private function personRow(int $id, int $telegramId, array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'email' => sprintf('p%d@example.com', $id),
            'display_name' => 'Person ' . $id,
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => $telegramId,
        ], $extra);
    }

    /** A full task 5 with one holder (Person 9), for rep:t:5. */
    private function queueFullTask(): void
    {
        $this->wpdb->nextRows[] = $this->personRow(7, 555, ['telegram_chat_id' => 555]);
        $this->wpdb->nextRows[] = [
            'id' => 5,
            'task_date' => '2026-08-01',
            'role_slug' => 'decorate',
            'capacity' => 1,
            'event_post_id' => null,
            'event_label' => 'Ecstatic Dance',
        ];
        $this->wpdb->nextResults[] = [['task_id' => 5, 'taken' => 1]];
        $this->wpdb->nextResults[] = [
            ['id' => 4, 'task_id' => 5, 'person_id' => 9, 'status' => AssignmentStatus::SIGNED_UP],
        ];
        $this->wpdb->nextRows[] = $this->personRow(9, 999);
    }

    /** @return list<array<string, mixed>> */
    private function callsTo(string $method): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call['body'],
            array_filter($this->telegramCalls, static fn (array $call): bool => $method === $call['method'])
        ));
    }

    public function testAGroupTapAnswersWithTheNoteAsAToastAndPostsNothingInTheGroup(): void
    {
        $this->queueFullTask();

        $this->replacement()->onSelect([
            'id' => 'cbq', 'from' => ['id' => 555], 'data' => ReplacementService::TASK_PREFIX . '5',
            'message' => ['message_id' => 1, 'chat' => ['id' => -100, 'type' => 'supergroup']],
        ]);

        $sends = $this->callsTo('sendMessage');
        self::assertCount(1, $sends, 'only the list, in the DM');
        self::assertSame(555, $sends[0]['chat_id']);

        $answers = $this->callsTo('answerCallbackQuery');
        self::assertCount(1, $answers, 'a tap is answered once');
        self::assertSame('cbq', $answers[0]['callback_query_id']);
        self::assertSame('📬 Sent you a DM.', $answers[0]['text']);
        self::assertFalse((bool) $answers[0]['show_alert']);
        self::assertSame([], $this->scheduled);
    }

    public function testAPrivateTapAnswersEmptyWithNoNote(): void
    {
        $this->queueFullTask();

        $this->replacement()->onSelect([
            'id' => 'cbq', 'from' => ['id' => 555], 'data' => ReplacementService::TASK_PREFIX . '5',
            'message' => ['message_id' => 1, 'chat' => ['id' => 555, 'type' => 'private']],
        ]);

        self::assertCount(1, $this->callsTo('sendMessage'));
        $answers = $this->callsTo('answerCallbackQuery');
        self::assertCount(1, $answers);
        self::assertSame('', $answers[0]['text']);
        self::assertSame([], $this->scheduled);
    }

    public function testATypedGroupCommandPostsNothingInTheGroup(): void
    {
        $this->telegramResults['sendMessage'] = ['message_id' => 77];
        $this->wpdb->nextRows[] = $this->personRow(7, 555, ['is_organizer' => 1]);
        $this->wpdb->nextCols[] = ['2026-07-20'];
        $this->wpdb->nextResults[] = [
            ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 3],
        ];
        $this->wpdb->nextResults[] = [];

        $this->roster()->onRosterCommand(555, -100, false);

        self::assertSame([], array_filter(array_column($this->telegramCalls, 'body'), static fn (array $b): bool => -100 === ($b['chat_id'] ?? null)));
        self::assertSame([], $this->scheduled);
        self::assertSame([], $this->callsTo('answerCallbackQuery'));
    }

    public function testATypedPrivateCommandHasNoNote(): void
    {
        $this->telegramResults['sendMessage'] = ['message_id' => 77];
        $this->wpdb->nextRows[] = $this->personRow(7, 555, ['is_organizer' => 1]);
        $this->wpdb->nextCols[] = ['2026-07-20'];
        $this->wpdb->nextResults[] = [
            ['id' => 5, 'task_date' => '2026-07-20', 'role_slug' => 'clean', 'capacity' => 3],
        ];
        $this->wpdb->nextResults[] = [];

        $this->roster()->onRosterCommand(555, 555, true);

        self::assertCount(1, $this->callsTo('sendMessage'));
        self::assertSame([], $this->scheduled);
    }

    public function testTheCronEventDeletesTheNote(): void
    {
        (new DmNote($this->client()))->delete(-100, 77);

        $delete = $this->lastCallTo('deleteMessage');
        self::assertSame(-100, $delete['chat_id']);
        self::assertSame(77, $delete['message_id']);
    }

    public function testANoteAlreadyGoneIsLeftAlone(): void
    {
        $this->telegramErrors['deleteMessage'] = 'Bad Request: message to delete not found';

        (new DmNote($this->client()))->delete(-100, 77);

        self::assertSame(['deleteMessage'], $this->calledMethods());
    }

    public function testAGroupMenuTapThatAnswersByDmGetsTheToastOnce(): void
    {
        $organizer = $this->personRow(7, 555, ['is_organizer' => 1, 'telegram_chat_id' => 555]);
        $this->wpdb->nextRows[] = $organizer;
        $this->wpdb->nextRows[] = $organizer;
        $this->wpdb->nextRows[] = $organizer;

        $this->updateRouter()->dispatch(['callback_query' => [
            'id' => 'cbq',
            'from' => ['id' => 555],
            'message' => ['message_id' => 3, 'chat' => ['id' => -100, 'type' => 'supergroup']],
            'data' => 'mn:roles',
        ]]);

        foreach ($this->callsTo('sendMessage') as $send) {
            self::assertSame(555, $send['chat_id'], 'nothing is posted in the group');
        }

        $answers = $this->callsTo('answerCallbackQuery');
        self::assertCount(1, $answers);
        self::assertSame('📬 Sent you a DM.', $answers[0]['text']);
        self::assertSame('', DmNote::takeTap(), 'the tap is not left held');
    }

    public function testAGroupMenuTapAnsweredInTheGroupIsAnsweredEmptyOnce(): void
    {
        // Unlinked: the refusal goes back to the group, so no toast.
        $this->updateRouter()->dispatch(['callback_query' => [
            'id' => 'cbq',
            'from' => ['id' => 555],
            'message' => ['message_id' => 3, 'chat' => ['id' => -100, 'type' => 'supergroup']],
            'data' => 'mn:roles',
        ]]);

        $answers = $this->callsTo('answerCallbackQuery');
        self::assertCount(1, $answers);
        self::assertSame('', $answers[0]['text']);
        self::assertSame('Only organizers can change roles.', $this->lastCallTo('sendMessage')['text']);
    }
}

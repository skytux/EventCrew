<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Models\Task;
use EventCrew\Support\Mailer;
use EventCrew\Support\MessageActions;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\Roles;
use EventCrew\Support\SlotFreedNotice;
use EventCrew\Telegram\DohResolver;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Tests\TestCase;

final class SlotFreedNoticeTest extends TestCase
{
    use AiTextOptions;

    /** @var array<int, array{to: string, body: string}> */
    private array $mails = [];

    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];
        $this->options = [];
        Functions\when('get_option')->alias(
            fn (string $key, mixed $default = false): mixed => $this->options[$key] ?? $default
        );
        Functions\when('update_option')->alias(function (string $key, mixed $value): bool {
            $this->options[$key] = $value;

            return true;
        });
        Functions\when('get_the_title')->justReturn('');
        Functions\when('get_permalink')->justReturn('https://site.test/crew/');
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'body' => $body];

            return true;
        });
    }

    private function notice(): SlotFreedNotice
    {
        return new SlotFreedNotice(
            new TaskRepository(),
            new PersonRepository(),
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            new TelegramClient(new Logger(), new DohResolver(new Logger()))
        );
    }

    /** @param array<string, mixed> $overrides */
    private function taskRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 5, 'task_date' => '2026-08-01', 'role_slug' => 'clean', 'capacity' => 3,
            'event_post_id' => null, 'event_label' => 'Party', 'starts_at' => null, 'ends_at' => null,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function person(int $id, string $email, array $overrides = []): array
    {
        return array_merge(
            ['id' => $id, 'email' => $email, 'email_verified_at' => '2026-07-01 00:00:00'],
            $overrides
        );
    }

    /** Queues task 5 as freed by $cancellerId, then sets up one send run. */
    private function queueAndPrepare(array $taskRow, int $taken, array $recipients, int $cancellerId = 7): void
    {
        $this->notice()->queue(5, $cancellerId);

        $this->wpdb->nextRows[] = $taskRow; // tasks->findLive
        $this->wpdb->nextResults[] = [['task_id' => 5, 'taken' => $taken]]; // occupancyFor
        $this->wpdb->nextResults[] = $recipients; // activeEmailRecipients
    }

    public function testBootQueuesOnTheSlotFreedAction(): void
    {
        $notice = $this->notice();
        Actions\expectAdded('eventcrew/slot_freed')->once()->with([$notice, 'queue'], 10, 2);

        $notice->boot();

        self::assertTrue(true);
    }

    public function testACancellationQueuesTheTaskAndSendsNothing(): void
    {
        $this->notice()->queue(5, 7);

        self::assertSame([], $this->mails);
        self::assertSame([], $this->wpdb->queries);
        $queue = $this->options[SlotFreedNotice::QUEUE_OPTION];
        self::assertSame([5], array_keys($queue));
        self::assertSame([7], $queue[5]['cancellers']);
        self::assertIsInt($queue[5]['queued_at']);
    }

    public function testTheRunSendsToEveryoneExceptTheCancellerAndEmptiesTheQueue(): void
    {
        $this->queueAndPrepare($this->taskRow(), 2, [
            $this->person(7, 'canceller@example.com'),
            $this->person(8, 'working@example.com'),
            $this->person(9, 'free@example.com'),
        ]);

        $this->notice()->sendDue(25);

        // Everyone but the canceller is told - including person 8, who is already
        // working that day and may want a second, non-overlapping task.
        $recipients = array_column($this->mails, 'to');
        self::assertSame(['working@example.com', 'free@example.com'], $recipients);
        self::assertStringContainsString('A spot just opened: ', $this->mails[0]['body']);
        self::assertSame([], $this->options[SlotFreedNotice::QUEUE_OPTION]);
    }

    public function testABlockedPersonIsNotTold(): void
    {
        $this->queueAndPrepare($this->taskRow(), 2, [
            $this->person(8, 'blocked@example.com', ['banned_at' => '2026-07-10 10:00:00']),
            $this->person(9, 'free@example.com'),
        ]);

        $this->notice()->sendDue(25);

        self::assertSame(['free@example.com'], array_column($this->mails, 'to'));
    }

    public function testAGuestIsNotToldOfARoleWithGuestSignUpOff(): void
    {
        $this->options['eventcrew_roles'] = [['slug' => 'clean', 'label' => 'Clean', 'guest_signup' => false]];
        $this->queueAndPrepare($this->taskRow(), 2, [
            $this->person(8, 'guest@example.com'),
            $this->person(9, 'cleaner@example.com', ['role_slugs' => '["clean"]']),
            $this->person(10, 'organizer@example.com', ['is_organizer' => 1]),
        ]);

        $this->notice()->sendDue(25);

        self::assertSame(['cleaner@example.com', 'organizer@example.com'], array_column($this->mails, 'to'));
    }

    public function testATaskFullAgainDropsTheNotice(): void
    {
        $this->queueAndPrepare($this->taskRow(), 3, [$this->person(9, 'free@example.com')]);

        $this->notice()->sendDue(25);

        self::assertSame([], $this->mails);
        self::assertSame([], $this->options[SlotFreedNotice::QUEUE_OPTION]);
    }

    public function testADeletedTaskDropsTheNotice(): void
    {
        $this->notice()->queue(5, 7);
        // findLive finds no row: the task was deleted.

        $this->notice()->sendDue(25);

        self::assertSame([], $this->mails);
        self::assertStringContainsString('deleted_at IS NULL', $this->wpdb->queries[0]);
        self::assertSame([], $this->options[SlotFreedNotice::QUEUE_OPTION]);
    }

    public function testACancelledOrganizeSlotIsNotAnnounced(): void
    {
        $this->queueAndPrepare(
            $this->taskRow(['role_slug' => 'organize', 'capacity' => 1]),
            0,
            [$this->person(8, 'member@example.com')]
        );

        $this->notice()->sendDue(25);

        self::assertSame([], $this->mails);
    }

    /** The test clock is 2026-07-20 12:00. */
    public function testATaskThatHasStartedIsNotAnnounced(): void
    {
        $this->queueAndPrepare(
            $this->taskRow([
                'task_date' => '2026-07-20',
                'starts_at' => '2026-07-20 11:30:00', 'ends_at' => '2026-07-20 14:00:00',
            ]),
            0,
            [$this->person(8, 'member@example.com')]
        );

        $this->notice()->sendDue(25);

        self::assertSame([], $this->mails);
        self::assertSame([], $this->options[SlotFreedNotice::QUEUE_OPTION]);
    }

    public function testAnUntimedTaskOnAPastDateIsNotAnnounced(): void
    {
        $this->queueAndPrepare(
            $this->taskRow(['task_date' => '2026-07-19']),
            0,
            [$this->person(8, 'member@example.com')]
        );

        $this->notice()->sendDue(25);

        self::assertSame([], $this->mails);
    }

    public function testATaskStartingLaterTodayIsStillAnnounced(): void
    {
        $this->queueAndPrepare(
            $this->taskRow([
                'task_date' => '2026-07-20',
                'starts_at' => '2026-07-20 18:00:00', 'ends_at' => '2026-07-20 20:00:00',
            ]),
            0,
            [$this->person(8, 'member@example.com')]
        );

        $this->notice()->sendDue(25);

        self::assertCount(1, $this->mails);
    }

    /** A batch limit leaves the rest in the queue; the next run tells only them. */
    public function testTheBatchLimitCarriesTheRestToTheNextRun(): void
    {
        $people = [
            $this->person(8, 'a@example.com'),
            $this->person(9, 'b@example.com'),
            $this->person(10, 'c@example.com'),
        ];
        $this->queueAndPrepare($this->taskRow(), 0, $people);

        $this->notice()->sendDue(2);

        self::assertSame(['a@example.com', 'b@example.com'], array_column($this->mails, 'to'));
        self::assertSame([5], array_keys($this->options[SlotFreedNotice::QUEUE_OPTION]));

        $this->mails = [];
        $this->wpdb->nextRows[] = $this->taskRow();
        $this->wpdb->nextResults[] = [['task_id' => 5, 'taken' => 0]];
        $this->wpdb->nextResults[] = $people;

        $this->notice()->sendDue(2);

        self::assertSame(['c@example.com'], array_column($this->mails, 'to'));
        self::assertSame([], $this->options[SlotFreedNotice::QUEUE_OPTION]);
    }

    public function testNothingQueuedQueriesNothing(): void
    {
        $this->notice()->sendDue(25);

        self::assertSame([], $this->wpdb->queries);
        self::assertSame([], $this->mails);
    }

    /** MessageAiText: the event's AI text when there is one, else the template's text. */
    public function testTheEmailUsesTheEventsAiTextWhenThereIsOne(): void
    {
        $recipients = [$this->person(8, 'bo@example.com')];

        foreach ([['slot_freed' => ['en' => 'AI: {role} is free.', 'fi' => 'AI FI']], []] as $texts) {
            $this->options = self::aiTextOptions(['slot_freed'], 42, $texts);
            $this->queueAndPrepare($this->taskRow(['event_post_id' => 42]), 0, $recipients);

            $this->notice()->sendDue(25);
        }

        self::assertCount(2, $this->mails);
        self::assertStringContainsString('AI: Clean is free.', $this->mails[0]['body']);
        // The tick is on but nothing was written for the event: the template's text.
        self::assertStringContainsString('A spot just opened', $this->mails[1]['body']);
        self::assertStringNotContainsString('AI:', $this->mails[1]['body']);
    }

    public function testTheNoticeHasTheTaskDetailsAndTheOtherOpenTasks(): void
    {
        $task = $this->taskRow([
            'starts_at' => '2026-08-01 18:00:00', 'ends_at' => '2026-08-01 20:00:00', 'notes' => 'Bring gloves.',
        ]);
        $this->queueAndPrepare($task, 1, [$this->person(8, 'bo@example.com', ['display_name' => 'Bo Person'])]);
        $this->wpdb->nextResults[] = [ // forDate: the night's tasks
            $task,
            $this->taskRow(['id' => 6, 'role_slug' => 'welcome', 'capacity' => 2,
                'starts_at' => '2026-08-01 20:00:00', 'ends_at' => '2026-08-01 22:00:00']),
            $this->taskRow(['id' => 7, 'role_slug' => 'decorate', 'capacity' => 1]),   // full
            $this->taskRow(['id' => 8, 'role_slug' => 'organize', 'capacity' => 1]),   // restricted
            $this->taskRow(['id' => 9, 'event_label' => 'Another party']),            // another event
        ];
        $this->wpdb->nextResults[] = [['task_id' => 6, 'taken' => 1], ['task_id' => 7, 'taken' => 1]];

        $this->notice()->sendDue(25);

        $body = $this->mails[0]['body'];
        self::assertStringContainsString('Task: ' . Roles::display('clean'), $body);
        self::assertStringContainsString('Event: Party', $body);
        self::assertStringContainsString('When: Sat 1 Aug 18:00–20:00', $body);
        self::assertStringContainsString('2 of 3 places free', $body);
        self::assertStringContainsString('Bring gloves.', $body);
        self::assertStringContainsString('Other open tasks at Party:', $body);
        self::assertStringContainsString(Roles::display('welcome') . ' 20:00–22:00<br>1 of 2 places free', $body);
        self::assertStringNotContainsString(Roles::display('decorate'), $body);
        self::assertStringNotContainsString(Roles::display('organize'), $body);
    }

    /**
     * The event's picture (Owner, 2026-11-08): in the email between the task's
     * details and the other open tasks, with nothing of it in the plain text;
     * in the DM as a large preview above the text.
     */
    public function testTheEventsPictureIsInTheEmailAndAboveTheDm(): void
    {
        Functions\when('post_type_exists')->justReturn(false);
        Functions\when('get_the_post_thumbnail_url')->justReturn('https://site.test/night.jpg');
        $dms = [];
        Functions\when('wp_remote_post')->alias(function (string $url, array $args) use (&$dms): string {
            $dms[] = (array) json_decode((string) $args['body'], true);

            return (string) json_encode(['ok' => true, 'result' => ['message_id' => 4]]);
        });
        $this->options[TelegramClient::TOKEN_OPTION] = 'token';
        Functions\when('wp_json_encode')->alias(static fn (mixed $data): string => (string) json_encode($data));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->returnArg(1);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        $task = $this->taskRow(['event_post_id' => 42]);
        $this->queueAndPrepare($task, 1, [$this->person(8, 'bo@example.com', ['telegram_chat_id' => 99])]);
        $this->wpdb->nextResults[] = [
            $task,
            $this->taskRow(['id' => 6, 'role_slug' => 'welcome', 'capacity' => 2, 'event_post_id' => 42]),
        ];
        $this->wpdb->nextResults[] = [];

        $this->notice()->sendDue(25);

        $body = $this->mails[0]['body'];
        $picture = strpos($body, 'https://site.test/night.jpg');
        self::assertNotFalse($picture);
        self::assertLessThan($picture, strpos($body, 'places free'), 'under the task details');
        self::assertGreaterThan($picture, strpos($body, 'Other open tasks'), 'above the other open tasks');
        self::assertStringNotContainsString(Mailer::TICKETS_MARK, $body);

        $dm = array_values(array_filter($dms, static fn (array $d): bool => 99 === ($d['chat_id'] ?? null)));
        self::assertNotEmpty($dm);
        self::assertSame(
            ['url' => 'https://site.test/night.jpg', 'prefer_large_media' => true, 'show_above_text' => true],
            $dm[0]['link_preview_options'] ?? null
        );
    }

    /** No picture: no gap where it would be. */
    public function testWithoutAPictureTheEmailHasNoGap(): void
    {
        $text = MessageTemplates::find('slot_freed')->fill(
            "Hi.{event_picture}\n\n{event_description}{other_tasks}",
            ['event_picture' => '']
        );
        self::assertSame('Hi.', $text);
    }

    /** A saved email gets the picture once, with its DJ text moved under it. */
    public function testASavedEmailGetsThePictureWithTheDjTextUnderIt(): void
    {
        self::assertSame(
            "Hi {name},\n\nOpen: {when}.{task_details}{event_picture}\n\n{event_description}{other_tasks}",
            \EventCrew\Support\EventDetailsText::withPicture(
                "Hi {name},\n\nOpen: {when}.\n\n{event_description}{task_details}{other_tasks}"
            )
        );
        self::assertSame("Hi.\n\nThanks.{event_picture}", \EventCrew\Support\EventDetailsText::withPicture("Hi.\n\nThanks."));
        $done = "A{event_picture}{other_tasks}";
        self::assertSame($done, \EventCrew\Support\EventDetailsText::withPicture($done));
        self::assertStringEndsWith(
            "{task_details}{event_picture}\n\n{event_description}{other_tasks}",
            MessageTemplates::find('slot_freed')->defaultBody()
        );
    }

    public function testTheOtherTasksAreOnlyRolesTimesAndCounts(): void
    {
        $task = Task::fromRow($this->taskRow(['starts_at' => '2026-08-01 18:00:00']));
        $rows = [
            ['task' => Task::fromRow($this->taskRow(['id' => 6, 'role_slug' => 'welcome', 'capacity' => 2,
                'starts_at' => '2026-08-01 20:00:00', 'ends_at' => '2026-08-01 22:00:00'])), 'free' => 1],
            ['task' => Task::fromRow($this->taskRow(['id' => 7, 'task_date' => '2026-08-02', 'role_slug' => 'decorate'])), 'free' => 3],
        ];

        self::assertSame(
            "Other open tasks at Party:\n📅 Sat 1 Aug\n\n"
            . Roles::display('welcome') . " 20:00–22:00\n1 of 2 places free\n\n📅 Sun 2 Aug\n\n"
            . Roles::display('decorate') . "\n3 of 3 places free",
            SlotFreedNotice::others($task, $rows)
        );
        self::assertSame('', SlotFreedNotice::others($task, []));
    }

    public function testTheDescriptionFallsBackToTheRolesOwn(): void
    {
        $details = SlotFreedNotice::details(Task::fromRow($this->taskRow()), 1);

        self::assertStringEndsWith("\n\n" . Roles::description('clean'), $details);
        self::assertStringContainsString("Event: Party\nWhen: Sat 1 Aug\n1 of 3 places free", $details);
    }

    public function testWithNoOtherOpenTasksTheSectionLeavesNoEmptyLines(): void
    {
        $this->options[MessageTemplates::OPTION_PREFIX . 'slot_freed_dm'] = [];
        $context = ['task' => Task::fromRow($this->taskRow(['notes' => 'Bring gloves.'])), 'free' => 1, 'other_rows' => []];

        $text = MessageTemplates::body('slot_freed_dm', $context);

        self::assertStringEndsWith("1 of 3 places free\n\nBring gloves.", $text);
        self::assertStringNotContainsString('Other open tasks', $text);
        self::assertStringNotContainsString("\n\n\n", $text);
    }

    public function testBothChannelsOfferTheSameActions(): void
    {
        $this->options['eventcrew_signup_page_id'] = 3;
        $this->options['eventcrew_telegram_bot_username'] = 'crewbot';
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()));
        $context = ['task' => Task::fromRow($this->taskRow()), 'free' => 1, 'other_rows' => []];
        $ids = static fn (string $kind): array => array_map(
            static fn ($action): string => $action->id,
            MessageActions::of($kind, $context, $mailer)
        );

        self::assertSame(['signup', 'board'], $ids('slot_freed'));
        self::assertSame($ids('slot_freed'), $ids('slot_freed_dm'));

        // The DM: the join-only callback the board's sign-up runs.
        $keyboard = MessageActions::keyboard(MessageActions::of('slot_freed_dm', $context, $mailer));
        self::assertSame('j:5', $keyboard[0][0]['callback_data']);

        // The email: the crew page at the task, and the bot showing it.
        $email = MessageActions::email(MessageActions::of('slot_freed', $context, $mailer));
        self::assertSame('https://site.test/crew/#eventcrew-task-5', $email[0]['url']);
        self::assertContains('https://t.me/crewbot?start=task-5', array_column($email, 'url'));
        self::assertTrue(MessageActions::isPayload(SlotFreedNotice::startPayload(5)));
        self::assertSame(5, SlotFreedNotice::taskFromPayload('task-5'));
        self::assertSame(0, SlotFreedNotice::taskFromPayload('task-x'));
    }

    public function testTheMigrationAddsThePlaceholdersOnceToEveryStoredText(): void
    {
        $option = MessageTemplates::OPTION_PREFIX . 'slot_freed';
        $this->options[$option] = [
            'subject' => ['source' => 'Spot!'],
            'body' => [
                'source' => "Hi {name},\n\nA spot opened.\n",
                'lang' => 'en',
                'texts' => ['fi' => 'Hei {name}, paikka vapautui.{task_details}', 'sv' => ''],
                'manual' => ['fi' => true],
            ],
            'settings' => ['ai' => true],
        ];
        $this->options[MessageTemplates::OPTION_PREFIX . 'slot_freed_dm'] = ['body' => 'Spot: {role}'];

        SlotFreedNotice::migrateStoredTexts();
        $once = $this->options;
        // Even with the done flag gone, a second run changes nothing.
        unset($this->options[SlotFreedNotice::MIGRATED_OPTION]);
        SlotFreedNotice::migrateStoredTexts();

        self::assertSame($once, $this->options);
        $body = $this->options[$option]['body'];
        self::assertSame("Hi {name},\n\nA spot opened.{task_details}{other_tasks}", $body['source']);
        self::assertSame('Hei {name}, paikka vapautui.{task_details}{other_tasks}', $body['texts']['fi']);
        self::assertSame('', $body['texts']['sv']);
        self::assertSame(['fi' => true], $body['manual']);
        self::assertSame(['source' => 'Spot!'], $this->options[$option]['subject']);
        self::assertSame(['ai' => true], $this->options[$option]['settings']);
        self::assertSame(
            'Spot: {role}{task_details}{other_tasks}',
            $this->options[MessageTemplates::OPTION_PREFIX . 'slot_freed_dm']['body']
        );
        self::assertSame('1', $this->options[SlotFreedNotice::MIGRATED_OPTION]);
    }

    public function testTheMigrationLeavesBuiltInTextsAlone(): void
    {
        SlotFreedNotice::migrateStoredTexts();

        self::assertArrayNotHasKey(MessageTemplates::OPTION_PREFIX . 'slot_freed', $this->options);
        self::assertStringEndsWith(
            SlotFreedNotice::NEW_PLACEHOLDERS,
            MessageTemplates::raw('slot_freed_dm', MessageTemplates::BODY)
        );
    }
}

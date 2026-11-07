<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Telegram\TellCommand;

/**
 * /tell: an organizer writes to everyone on one task. Task 5 (bar) is on
 * 2026-07-25; Milla (7) has a Telegram chat, Kim (2) does not.
 */
final class TellCommandTest extends TelegramTestCase
{
    use CommandTestHelpers;

    /** @var array<int, array{to: string, subject: string, body: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommandTest();

        $this->mails = [];
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

            return true;
        });
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
    }

    private function command(): TellCommand
    {
        return new TellCommand(
            new PersonRepository(),
            new TaskRepository(),
            new AssignmentRepository(),
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            $this->client()
        );
    }

    /**
     * The rows read to find who is on task 5: the organizer is on it too and
     * gets nothing.
     */
    private function queueCrew(): void
    {
        $this->wpdb->nextResults[] = [
            ['id' => 40, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'],
            ['id' => 41, 'task_id' => 5, 'person_id' => 2, 'status' => 'signed_up'],
            ['id' => 42, 'task_id' => 5, 'person_id' => 1, 'status' => 'signed_up'],
            ['id' => 43, 'task_id' => 5, 'person_id' => 9, 'status' => 'cancelled'],
        ];
        $this->wpdb->nextRows[] = $this->person(7, 'Milla Virtanen');
        $this->wpdb->nextRows[] = $this->person(2, 'Kim Lee', ['telegram_user_id' => null, 'telegram_chat_id' => null]);
    }

    public function testTheMessageIsShownFirstAndThenGoesToEveryoneOnTheTask(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$this->task(5, '2026-07-25')];
        $this->wpdb->nextRows[] = $this->task(5, '2026-07-25');
        $this->queueCrew();

        $this->command()->onCommand(555, 555, '/tell bar ; Please come 15 minutes early.', true);

        $text = $this->lastText();
        self::assertStringStartsWith('Send this to 2 people on bar · Sat 25 Jul?', $text);
        self::assertStringEndsWith("Owner Organizer wrote to the crew of bar · Sat 25 Jul:\nPlease come 15 minutes early.", $text);
        $id = $this->requestId('tl:');
        self::assertSame(['tl:y:' . $id, 'tl:x:' . $id], $this->buttonData());
        self::assertSame([], $this->mails);
        $sentBefore = count($this->telegramCalls);

        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextRows[] = $this->task(5, '2026-07-25');
        $this->queueCrew();

        $this->command()->onCallback($this->tap('tl:y:' . $id));

        $dms = array_values(array_filter(
            array_slice($this->telegramCalls, $sentBefore),
            static fn (array $c): bool => 'sendMessage' === $c['method'] && 607 === (int) ($c['body']['chat_id'] ?? 0)
        ));
        self::assertCount(1, $dms);
        self::assertStringContainsString('Owner Organizer wrote to the crew of bar · Sat 25 Jul:', $dms[0]['body']['text']);
        self::assertStringContainsString('Please come 15 minutes early.', $dms[0]['body']['text']);
        self::assertCount(1, $this->mails);
        self::assertSame('p2@example.com', $this->mails[0]['to']);
        self::assertStringContainsString('Owner Organizer', $this->mails[0]['subject']);
        self::assertStringStartsWith('✅ bar · Sat 25 Jul', $this->lastText());
        self::assertStringEndsWith('Sent to 2 people (Telegram 1, email 1).', $this->lastText());
        self::assertCount(1, $this->transients[array_key_last($this->transients)]);
    }

    public function testAnOrganizerCanSendOnlySoManyAnHour(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();
        $this->transients['eventcrew_tg_tell_sent_1'] = array_fill(0, TellCommand::RATE_LIMIT, time());

        $this->command()->onCommand(555, 555, '/tell bar ; Hi', true);

        self::assertSame('You can send 5 messages an hour. Try again later.', $this->lastText());
    }

    public function testWithoutAMessageTheUsageIsShown(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();

        $this->command()->onCommand(555, 555, '/tell bar', true);

        self::assertSame(TellCommand::usage(), $this->lastText());
    }

    public function testAnUnknownTaskIsSaid(): void
    {
        $this->wpdb->nextRows[] = $this->organizer();
        $this->wpdb->nextResults[] = [$this->task(5, '2026-07-25')];

        $this->command()->onCommand(555, 555, '/tell kitchen ; Hi', true);

        self::assertSame('No task called “kitchen” was found.', $this->lastText());
    }

    public function testSomeoneWhoIsNotAnOrganizerIsRefused(): void
    {
        $this->wpdb->nextRows[] = $this->person(7, 'Milla', ['telegram_user_id' => 555]);

        $this->command()->onCommand(555, 555, '/tell bar ; Hi', true);

        self::assertSame('Only organizers can use /tell.', $this->lastText());
    }

    public function testTheCommandIsRefusedInAGroup(): void
    {
        $this->command()->onCommand(-100, 555, '/tell bar ; Hi', false);

        self::assertSame('Use /tell in a private chat with me.', $this->lastText());
        self::assertSame([], $this->wpdb->queries);
    }
}

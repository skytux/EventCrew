<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Assignment;
use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\Language;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\TakeoverService;
use EventCrew\Tests\Telegram\TelegramTestCase;

/**
 * The takeover messages on the Messages page: with nothing stored, each kind
 * gives the text TakeoverService sent before it read the templates (the
 * expected strings were captured from that code); a stored text is what
 * TakeoverService sends.
 *
 * The clock is fixed at 2026-07-20 12:00:00 by TestCase.
 */
final class TakeoverMessagesTest extends TelegramTestCase
{
    /** The built-in description of the Decorate role. */
    private const DECORATE = 'Set up before the dance: close the curtains, check the bathrooms, place the LED candles,'
        . ' decorate the DJ table, prepare the water and fruit, and open the movable walls.';

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
    private static function taskRow(): array
    {
        return [
            'id' => 5,
            'task_date' => '2026-08-01',
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_post_id' => null,
            'event_label' => 'Ecstatic Dance',
            'starts_at' => '2026-08-01 20:00:00',
            'ends_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    private static function context(string $kind): array
    {
        $holder = Person::fromRow(self::personRow(9));
        $cover = Person::fromRow(self::personRow(7));
        $reader = str_starts_with($kind, 'takeover_ask') || str_starts_with($kind, 'takeover_done') ? $holder : $cover;

        return [
            'person' => $reader,
            'cover' => $cover,
            'holder' => $holder,
            'task' => Task::fromRow(self::taskRow()),
            'deadline' => '2026-07-23 12:00:00',
        ];
    }

    /**
     * Each kind: [id, subject ('' for Telegram), text], as the old code sent them.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function kinds(): array
    {
        $task = 'Decorate at Ecstatic Dance, Sat 1 Aug 20:00';
        // The request now names what the task is (its role's description).
        $ask = '🔁 Person 7 asks to take over your task: ' . $task . ".\n\n" . self::DECORATE
            . "\n\nYou decide: let them take it, or keep it."
            . ' Please answer by Thu 23 Jul 12:00. If you do not answer by then, you keep the task.';
        $done = '✅ Done: Person 7 now has your task: ' . $task . '.';
        $refused = 'Person 9 keeps their task: ' . $task . '. Your request to take it over was declined.';
        $expired = 'Person 9 did not answer in time, so they keep their task: ' . $task . '.';
        $failed = 'Person 9 agreed, but their task could not be moved to you: ' . $task . '. Nothing changed.';
        $closed = 'Your request to take over the task of Person 9 is closed,'
            . ' because the task changed or was cancelled: ' . $task . '.';
        $about = 'About your takeover request';

        return [
            'takeover_ask' => ['takeover_ask', 'Person 7 asks to take over your task', "Hi Person,\n\n" . $ask],
            'takeover_ask_dm' => ['takeover_ask_dm', '', $ask],
            'takeover_done' => ['takeover_done', 'Your task moved to Person 7', "Hi Person,\n\n" . $done],
            'takeover_done_dm' => ['takeover_done_dm', '', $done],
            'takeover_refused' => ['takeover_refused', $about, "Hi Person,\n\n" . $refused],
            'takeover_refused_dm' => ['takeover_refused_dm', '', $refused],
            'takeover_expired' => ['takeover_expired', $about, "Hi Person,\n\n" . $expired],
            'takeover_expired_dm' => ['takeover_expired_dm', '', $expired],
            'takeover_failed' => ['takeover_failed', $about, "Hi Person,\n\n" . $failed],
            'takeover_failed_dm' => ['takeover_failed_dm', '', $failed],
            'takeover_closed' => ['takeover_closed', $about, "Hi Person,\n\n" . $closed],
            'takeover_closed_dm' => ['takeover_closed_dm', '', $closed],
        ];
    }

    /** @dataProvider kinds */
    public function testTheBuiltInTextIsTheOldText(string $id, string $subject, string $body): void
    {
        self::assertNotNull(MessageTemplates::find($id));
        self::assertTrue(MessageTemplates::find($id)->wired);
        self::assertSame($subject, MessageTemplates::subject($id, self::context($id)));
        self::assertSame($body, MessageTemplates::body($id, self::context($id)));
    }

    public function testTheBuiltInTextKeepsItsFinnishTranslation(): void
    {
        self::assertSame(
            '🔁 Person 7 pyytää saada ottaa tehtäväsi: Somistus, Ecstatic Dance, la 1.8. 20:00.'
                . "\n\nValmistelu ennen tansseja: sulje verhot, tarkista vessat, aseta LED-kynttilät, somista DJ-pöytä,"
                . ' valmistele vesi ja hedelmät ja avaa siirtoseinät.'
                . "\n\nSinä päätät: annat tehtävän hänelle tai pidät sen. Vastaa viimeistään to 23.7. 12:00."
                . ' Jos et vastaa siihen mennessä, pidät tehtävän.',
            Language::run(
                Language::FINNISH,
                static fn (): string => MessageTemplates::body('takeover_ask_dm', self::context('takeover_ask_dm'))
            )
        );
        self::assertSame(
            'Tehtävän siirtopyyntösi',
            Language::run(
                Language::FINNISH,
                static fn (): string => MessageTemplates::subject('takeover_closed', self::context('takeover_closed'))
            )
        );
    }

    /**
     * The request says who is asking: how many tasks they have done, on the
     * line under the task.
     */
    public function testTheRequestSaysHowManyTasksTheAskerHasDone(): void
    {
        $context = ['cover_record' => TakeoverService::coverRecord('Person 7', 3)] + self::context('takeover_ask_dm');

        self::assertStringStartsWith(
            '🔁 Person 7 asks to take over your task: Decorate at Ecstatic Dance, Sat 1 Aug 20:00.'
                . "\nPerson 7 has done 3 tasks.\n\n" . self::DECORATE . "\n\nYou decide:",
            MessageTemplates::body('takeover_ask_dm', $context)
        );
        self::assertSame('Sam has done 1 task.', TakeoverService::coverRecord('Sam', 1));
        self::assertSame('Sam has not done a task yet.', TakeoverService::coverRecord('Sam', 0));
    }

    /** @param array<string, string> $parts */
    private function store(string $id, array $parts): void
    {
        $stored = [];

        foreach ($parts as $part => $text) {
            $stored[$part] = ['source' => $text, 'texts' => ['en' => $text, 'fi' => ''], 'manual' => []];
        }

        $this->options[MessageTemplates::OPTION_PREFIX . $id] = $stored;
    }

    public function testAStoredRequestTextIsSentToTheHolder(): void
    {
        $this->store('takeover_ask', [
            MessageTemplates::SUBJECT => '{cover} wants your {role}',
            MessageTemplates::BODY => 'Dear {name}, {cover} asks for {role} at {event}, {when}. Answer by {deadline}.',
        ]);
        $this->store('takeover_ask_dm', [MessageTemplates::BODY => '{cover} wants {role}. Answer by {deadline}.']);
        $this->wpdb->nextRows[] = self::personRow(9, 999); // the holder
        $this->wpdb->nextRows[] = null;                    // no pending request
        $this->wpdb->nextQueryResults[] = 1;               // stored

        $result = $this->takeoverService()->request(
            Assignment::fromRow(['id' => 3, 'task_id' => 5, 'person_id' => 9, 'status' => AssignmentStatus::SIGNED_UP]),
            Task::fromRow(self::taskRow()),
            Person::fromRow(self::personRow(7, 555))
        );

        self::assertTrue($result['ok']);
        $dm = $this->lastCallTo('sendMessage');
        self::assertSame('Person 7 wants Decorate. Answer by Thu 23 Jul 12:00.', $dm['text']);
        self::assertSame('tko:y:1', $dm['reply_markup']['inline_keyboard'][0][0]['callback_data'], 'the buttons stay');

        self::assertCount(1, $this->mails);
        self::assertSame('Person 7 wants your Decorate', $this->mails[0]['subject']);
        self::assertStringContainsString(
            'Dear Person, Person 7 asks for Decorate at Ecstatic Dance, Sat 1 Aug 20:00. Answer by Thu 23 Jul 12:00.',
            $this->mails[0]['body']
        );
        self::assertStringContainsString(
            'eventcrew/v1/takeover?token=',
            $this->mails[0]['body'],
            'the answer button stays'
        );
    }

    /** Queues the reads and writes of a holder agreeing and the task moving. */
    private function queueAgree(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 1, 'assignment_id' => 3, 'task_id' => 5, 'holder_id' => 9, 'cover_id' => 7, 'status' => 'pending',
            'deadline_at' => '2026-07-23 12:00:00', 'created_at' => '2026-07-20 12:00:00',
        ];
        $this->wpdb->nextRows[] = [
            'id' => 3, 'task_id' => 5, 'person_id' => 9, 'status' => AssignmentStatus::SIGNED_UP,
        ];
        $this->wpdb->nextRows[] = self::taskRow();
        $this->wpdb->nextRows[] = self::personRow(7, 555); // cover
        $this->wpdb->nextRows[] = self::personRow(9, 999); // holder
        $this->wpdb->nextQueryResults[] = 1;               // resolve(accepted)
        $this->wpdb->nextVars[] = 2;                       // join: task capacity
        $this->wpdb->nextRows[] = null;                    // join: cover not on the task
        $this->wpdb->nextQueryResults[] = 1;               // join: inserted
    }

    public function testAgreeingInTheBotShowsTheBuiltInDoneText(): void
    {
        $this->queueAgree();

        self::assertSame(
            '✅ Done: Person 7 now has your task: Decorate at Ecstatic Dance, Sat 1 Aug 20:00.',
            $this->takeoverService()->answer(1, true, 9, true)
        );
    }

    public function testAgreeingInTheBotShowsTheStoredDoneText(): void
    {
        $this->store('takeover_done_dm', [MessageTemplates::BODY => '{cover} has {role} on {when} now.']);
        $this->queueAgree();

        self::assertSame(
            'Person 7 has Decorate on Sat 1 Aug 20:00 now.',
            $this->takeoverService()->answer(1, true, 9, true)
        );
    }

    public function testAStoredOutcomeTextIsSentToTheCover(): void
    {
        $this->store('takeover_refused', [
            MessageTemplates::SUBJECT => 'No from {holder}',
            MessageTemplates::BODY => 'Sorry {name}, {holder} keeps {role}.',
        ]);
        $this->store('takeover_refused_dm', [MessageTemplates::BODY => '{holder} said no to {role} on {when}.']);
        $this->wpdb->nextRows[] = [
            'id' => 1, 'assignment_id' => 3, 'task_id' => 5, 'holder_id' => 9, 'cover_id' => 7, 'status' => 'pending',
            'deadline_at' => '2026-07-23 12:00:00', 'created_at' => '2026-07-20 12:00:00',
        ];
        $this->wpdb->nextRows[] = [
            'id' => 3, 'task_id' => 5, 'person_id' => 9, 'status' => AssignmentStatus::SIGNED_UP,
        ];
        $this->wpdb->nextRows[] = self::taskRow();
        $this->wpdb->nextRows[] = self::personRow(7, 555); // cover
        $this->wpdb->nextRows[] = self::personRow(9, 999); // holder
        $this->wpdb->nextQueryResults[] = 1;               // resolve(refused)

        $this->takeoverService()->answer(1, false, 9);

        $dm = $this->lastCallTo('sendMessage');
        self::assertSame(555, $dm['chat_id']);
        self::assertSame('Person 9 said no to Decorate on Sat 1 Aug 20:00.', $dm['text']);
        self::assertSame('No from Person 9', $this->mails[0]['subject']);
        self::assertStringContainsString('Sorry Person, Person 9 keeps Decorate.', $this->mails[0]['body']);
    }
}

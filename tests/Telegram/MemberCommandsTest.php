<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\PersonEraser;
use EventCrew\Support\SignupService;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\Turnstile;
use EventCrew\Telegram\ManageController;
use EventCrew\Telegram\MemberCommands;
use EventCrew\Telegram\TicketRedemptionService;
use EventCrew\Web\SignupController;

/**
 * /crew, /name and /email: a member, in a private chat. Milla is person 7,
 * Telegram user 607; the clock reads 2026-07-20 12:00.
 */
final class MemberCommandsTest extends TelegramTestCase
{
    use CommandTestHelpers;

    /** @var array<int, string> */
    private array $mailedTo = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommandTest();

        $this->mailedTo = [];
        Functions\when('wp_mail')->alias(function (string $to): bool {
            $this->mailedTo[] = $to;

            return true;
        });
        Functions\when('sanitize_email')->alias(static fn (string $e): string => trim($e));
        Functions\when('is_email')->alias(
            static fn (string $e): bool => 1 === preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $e)
        );
        Functions\when('admin_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-admin/' . $p);
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('wp_validate_redirect')->alias(static fn (string $u, string $d): string => '' === $u ? $d : $u);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url = ''): string => $url . '?' . http_build_query($args)
        );
    }

    private function command(): MemberCommands
    {
        $people = new PersonRepository();
        $assignments = new AssignmentRepository();
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()));
        $standing = new StandingCalculator($assignments, new RedemptionRepository(), new CreditGrantRepository());

        $signup = new SignupController(
            $people,
            new AuthTokenRepository(),
            new TaskRepository(),
            $assignments,
            new SignupService($assignments, $standing, $people, new TaskRepository()),
            $standing,
            $mailer,
            new ClaimNotifier(new TaskRepository(), $assignments, $mailer, $this->client(), $standing),
            new Turnstile(new Logger()),
            new TicketRedemptionService($people, new TaskRepository(), new RedemptionRepository(), $standing, new FreeEntryGate(), $this->client(), $mailer)
        );

        $manage = new ManageController(
            $people,
            new PersonEraser($people, $assignments, new RedemptionRepository(), new CreditGrantRepository(), new AuthTokenRepository(), new NotificationsRepository()),
            new AuthTokenRepository(),
            $mailer
        );

        return new MemberCommands($people, new TaskRepository(), $assignments, $signup, $this->client(), $manage);
    }

    /**
     * @return array<string, mixed>
     */
    private function milla(): array
    {
        return $this->person(7, 'Milla Virtanen');
    }

    public function testCrewShowsTheComingNightsWithTheirCrew(): void
    {
        $this->wpdb->nextRows[] = $this->milla();
        // Milla's history: a coming task, a cancelled one, a past one.
        $this->wpdb->nextResults[] = [
            ['id' => 40, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up', 'task_date' => '2026-07-25'],
            ['id' => 41, 'task_id' => 8, 'person_id' => 7, 'status' => 'cancelled', 'task_date' => '2026-08-01'],
            ['id' => 30, 'task_id' => 3, 'person_id' => 7, 'status' => 'completed', 'task_date' => '2026-06-01'],
        ];
        // The night's tasks: bar and an organizers-only task.
        $this->wpdb->nextResults[] = [
            $this->task(5, '2026-07-25'),
            ['role_slug' => 'organize', 'id' => 6] + $this->task(6, '2026-07-25'),
        ];
        $this->wpdb->nextResults[] = [
            ['id' => 40, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'],
            ['id' => 44, 'task_id' => 5, 'person_id' => 2, 'status' => 'arrived'],
            ['id' => 45, 'task_id' => 5, 'person_id' => 3, 'status' => 'cancelled'],
        ];
        $this->wpdb->nextRows[] = $this->milla();
        $this->wpdb->nextRows[] = $this->person(2, 'Kim Lee');

        $this->command()->onCommand(607, 607, '/crew', true, MemberCommands::CREW);

        self::assertSame("Sat 25 Jul · Party\n• bar: Milla Virtanen, Kim Lee", $this->lastText());
        self::assertSame([], $this->transients);
    }

    public function testCrewWithNoComingTask(): void
    {
        $this->wpdb->nextRows[] = $this->milla();

        $this->command()->onCommand(607, 607, '/crew', true, MemberCommands::CREW);

        self::assertSame('You have no coming tasks. See the open ones with /board.', $this->lastText());
    }

    public function testSomeoneNotSetUpIsToldToStart(): void
    {
        $this->wpdb->nextRows[] = null;

        $this->command()->onCommand(607, 607, '/crew', true, MemberCommands::CREW);

        self::assertSame('Set yourself up first: send /start to the bot.', $this->lastText());
    }

    public function testNameChangesAfterTheTap(): void
    {
        $this->wpdb->nextRows[] = $this->milla();

        $this->command()->onCommand(607, 607, '/name Milla V', true, MemberCommands::NAME);

        self::assertSame('Change your name from Milla Virtanen to Milla V?', $this->lastText());
        $id = $this->requestId('mc:');
        self::assertSame(['mc:y:' . $id, 'mc:x:' . $id], $this->buttonData());
        self::assertSame([], $this->wpdb->updates);

        $this->wpdb->nextRows[] = $this->milla();
        $this->command()->onCallback($this->tap('mc:y:' . $id, 607));

        self::assertSame('Milla V', $this->wpdb->updates[0]['data']['display_name']);
        self::assertSame('✅ Your name is now Milla V.', $this->lastText());
    }

    public function testNameWithoutTextShowsTheCurrentOne(): void
    {
        $this->wpdb->nextRows[] = $this->milla();

        $this->command()->onCommand(607, 607, '/name', true, MemberCommands::NAME);

        self::assertSame("Your name: Milla Virtanen\nChange it: /name New Name", $this->lastText());
        // Settings' Change name opens this too, so Back goes to Settings.
        self::assertSame(['mn:set'], $this->buttonData());
    }

    public function testEmailSendsALinkAndChangesNothingYet(): void
    {
        $this->wpdb->nextRows[] = $this->milla();

        $this->command()->onCommand(607, 607, '/email milla@new.example', true, MemberCommands::EMAIL);

        self::assertSame('Change your email to milla@new.example? A link goes there first; the email changes when you open it.', $this->lastText());
        $id = $this->requestId('mc:');

        $this->wpdb->nextRows[] = $this->milla();
        $this->command()->onCallback($this->tap('mc:y:' . $id, 607));

        self::assertSame(['milla@new.example'], $this->mailedTo);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame('✅ A link is on its way to milla@new.example. Your email changes when you open it.', $this->lastText());
        self::assertCount(1, $this->transients['eventcrew_tg_email_sent_7']);
    }

    public function testABadEmailIsRefused(): void
    {
        $this->wpdb->nextRows[] = $this->milla();

        $this->command()->onCommand(607, 607, '/email not-an-address', true, MemberCommands::EMAIL);

        self::assertSame('That is not a valid email address.', $this->lastText());
        self::assertSame([], $this->transients);
    }

    public function testTheSameEmailIsSaid(): void
    {
        $this->wpdb->nextRows[] = $this->milla();

        $this->command()->onCommand(607, 607, '/email P7@example.com', true, MemberCommands::EMAIL);

        self::assertSame('That is already your email.', $this->lastText());
    }

    public function testEmailLinksAreLimited(): void
    {
        $this->transients['eventcrew_tg_email_sent_7'] = array_fill(0, MemberCommands::RATE_LIMIT, time());
        $this->wpdb->nextRows[] = $this->milla();

        $this->command()->onCommand(607, 607, '/email milla@new.example', true, MemberCommands::EMAIL);

        self::assertSame('You asked for a link a few times already. Try again in an hour.', $this->lastText());
    }

    /**
     * Owner 2026-11-08: Settings' Sign out everywhere asks first, then does
     * what the web's does - every web session stops working.
     */
    public function testSignOutEverywhereAsksThenRevokesTheWebSessions(): void
    {
        $this->wpdb->nextRows[] = $this->milla();

        $this->command()->askSignOut(607, 607, true);

        self::assertSame(MemberCommands::signOutQuestion(), $this->lastText());
        $id = $this->requestId('mc:');
        self::assertSame(['mc:y:' . $id, 'mc:x:' . $id], $this->buttonData());
        self::assertSame([], $this->wpdb->updates);

        $this->wpdb->nextRows[] = $this->milla();
        $this->command()->onCallback($this->tap('mc:y:' . $id, 607));

        self::assertCount(1, $this->wpdb->updates);
        self::assertArrayHasKey('sessions_valid_from', $this->wpdb->updates[0]['data']);
        self::assertSame(
            '✅ Signed out of the web page on every browser and phone.',
            $this->lastText()
        );
        self::assertSame(['mn:set'], $this->buttonData());
    }

    /**
     * Owner 2026-11-08: Settings' Delete my data says what goes, asks, and
     * then - like the web's - only mails the one-time delete link; nothing
     * is erased from the chat.
     */
    public function testDeleteMyDataAsksThenMailsTheOneTimeLink(): void
    {
        $this->wpdb->nextRows[] = $this->milla();

        $this->command()->askDelete(607, 607, true);

        self::assertSame(
            "Delete your account and all your history? This cannot be undone.\n\n"
            . 'A link goes to your email first; your data is deleted only when you open it.',
            $this->lastText()
        );
        $id = $this->requestId('mc:');
        self::assertSame('✅ Delete my data', $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'][0][0]['text']);

        $this->wpdb->nextRows[] = $this->milla();
        $this->command()->onCallback($this->tap('mc:y:' . $id, 607));

        self::assertSame(['p7@example.com'], $this->mailedTo);
        self::assertSame([], $this->wpdb->deletes);
        self::assertSame('We sent a link to p7@example.com. Open it within 30 minutes to delete your data.', $this->lastText());
    }

    public function testCancellingDeleteMyDataChangesNothing(): void
    {
        $this->wpdb->nextRows[] = $this->milla();
        $this->command()->askDelete(607, 607, true);
        $id = $this->requestId('mc:');

        $this->command()->onCallback($this->tap('mc:x:' . $id, 607));

        self::assertSame([], $this->mailedTo);
        self::assertSame([], $this->wpdb->deletes);
        self::assertSame('✖️ Cancelled. Nothing was changed.', $this->lastText());
    }

    public function testATapFromAnotherUserChangesNothing(): void
    {
        $this->transients[MemberCommands::STATE_PREFIX . 'aaaaaaaaaaaaaaaa'] = ['user' => 607, 'op' => 'name', 'value' => 'X'];

        $this->command()->onCallback($this->tap('mc:y:aaaaaaaaaaaaaaaa', 555));

        self::assertSame('This request has expired. Please send it again.', $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertSame([], $this->wpdb->updates);
    }
}

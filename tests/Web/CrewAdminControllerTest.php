<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Admin\PeoplePage;
use EventCrew\Admin\View;
use EventCrew\MailImport\Mailbox;
use EventCrew\MailImport\MailboxException;
use EventCrew\MailImport\MailFetchSettings;
use EventCrew\MailImport\MailImporter;
use EventCrew\MailImport\MailedListImport;
use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\BuyerImport;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\CreditGrantNotifier;
use EventCrew\Support\DoorAccess;
use EventCrew\Support\DoorList;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FollowupText;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MarkedBy;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\NewcomerApproval;
use EventCrew\Support\OpenTaskCall;
use EventCrew\Support\OrganizeTaskBackfill;
use EventCrew\Support\PersonEraser;
use EventCrew\Support\RoleConditions;
use EventCrew\Support\RoleTaskService;
use EventCrew\Support\Roles;
use EventCrew\Support\RosterAssembler;
use EventCrew\Support\SignupService;
use EventCrew\Support\TaskTemplateApplier;
use EventCrew\Support\TicketMail;
use EventCrew\Support\Turnstile;
use EventCrew\Support\WebSession;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\TicketRedemptionService;
use EventCrew\Tests\Support\SalesDeskTestCase;
use EventCrew\Web\CrewAdminController;
use EventCrew\Web\CrewAdminFollowup;
use EventCrew\Web\CrewAdminPeople;
use EventCrew\Web\CrewAdminRoster;
use EventCrew\Web\CrewAdminTasks;
use EventCrew\Web\SessionPerson;
use EventCrew\Web\SignupController;

/**
 * The crew page's Admin tab: an organizer runs each action; a signed-in member
 * who is not an organizer sends one anyway and is blocked, with every organizer
 * told; a request with no session is refused and nothing else happens; and a
 * blocked account can neither sign up nor sign in until it is unbanned.
 *
 * Person 1 is an organizer with a verified email, person 2 an organizer with
 * neither email verified nor a chat, person 8 a member. The clock reads
 * 2026-07-20 12:00.
 */
final class CrewAdminControllerTest extends SalesDeskTestCase
{
    private const IP = '203.0.113.9';

    /** @var array<int, array{to: string, subject: string, body: string}> */
    private array $mails = [];

    /** Whether the stand-in mailbox fails to connect. */
    private bool $mailboxFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        $_COOKIE = [];
        $this->mails = [];

        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        // No events plugin: the Tasks screen's event picker is empty.
        Functions\when('post_type_exists')->justReturn(false);
        Functions\when('sanitize_email')->alias(static fn (string $e): string => trim($e));
        Functions\when('sanitize_textarea_field')->alias(static fn (string $text): string => trim($text));
        Functions\when('is_email')->alias(
            static fn (string $e): bool => 1 === preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $e)
        );
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

            return true;
        });
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];

        parent::tearDown();
    }

    private function mailer(): Mailer
    {
        return new Mailer(new Logger(), new EmailTemplate(new Logger()));
    }

    private function admin(): CrewAdminController
    {
        return new CrewAdminController(
            new PersonRepository(),
            new SessionPerson(new PersonRepository()),
            $this->salesDeskGate(),
            $this->doorLinkSender(),
            $this->boardService(),
            new CreditGrantRepository(),
            new CreditGrantNotifier($this->mailer(), $this->client()),
            new RosterAssembler(new TaskRepository(), new AssignmentRepository(), new PersonRepository(), $this->standing()),
            new AssignmentRepository(),
            $this->client(),
            $this->mailer(),
            new Logger(),
            $this->roleTasks(),
            $this->rosterScreen(),
            new CrewAdminPeople(new PersonRepository(), new CreditGrantRepository(), new RedemptionRepository(), $this->standing()),
            $this->tasksScreen(),
            null,
            new CrewAdminFollowup(new FollowupText(new LlmClient(new Logger()), new Logger())),
            null,
            new NewcomerApproval(new PersonRepository(), $this->mailer(), $this->client())
        );
    }

    /** The Tasks screen over the fake wpdb, with the real template applier and open-task call. */
    private function tasksScreen(): CrewAdminTasks
    {
        $tasks = new TaskRepository();
        $assignments = new AssignmentRepository();

        return new CrewAdminTasks(
            $tasks,
            $assignments,
            new PersonRepository(),
            new TaskTemplateApplier($tasks),
            new OpenTaskCall(
                $tasks,
                $assignments,
                new PersonRepository(),
                new NotificationsRepository(),
                $this->mailer(),
                $this->client()
            )
        );
    }

    /**
     * The Roster screen over the fake wpdb. Free entry has no task lookup here,
     * so only a date closed by hand is closed. The mailbox is a stand-in that
     * holds no mail, or fails when $mailboxFails is set.
     */
    private function rosterScreen(): CrewAdminRoster
    {
        $tasks = new TaskRepository();
        $assignments = new AssignmentRepository();
        $buyers = new TicketBuyerRepository();
        $buyerImport = new BuyerImport($buyers, new DoorAccess($tasks, $assignments));
        $mailbox = $this->emptyMailbox($this->mailboxFails);

        return new CrewAdminRoster(
            $tasks,
            $assignments,
            new DoorList($tasks, $assignments, new PersonRepository(), new RedemptionRepository(), $this->standing()),
            new RedemptionRepository(),
            $this->standing(),
            new FreeEntryGate(),
            new TicketRedemptionService(
                new PersonRepository(),
                $tasks,
                new RedemptionRepository(),
                $this->standing(),
                new FreeEntryGate(),
                $this->client(),
                $this->mailer()
            ),
            new GuestRepository(),
            $buyers,
            $buyerImport,
            new MailImporter(
                new MailedListImport(new PersonRepository(), $buyerImport, $this->mailer(), new GuestRepository()),
                new Logger(),
                static fn (): Mailbox => $mailbox
            )
        );
    }

    private function emptyMailbox(bool $fails): Mailbox
    {
        return new class ($fails) implements Mailbox {
            public function __construct(private bool $fails)
            {
            }

            public function unread(int $limit): array
            {
                if ($this->fails) {
                    throw new MailboxException('Could not reach mail.example.org: timed out');
                }

                return [];
            }

            public function moveToImported(string $id): void
            {
            }

            public function moveToRejected(string $id): void
            {
            }

            public function close(): void
            {
            }
        };
    }

    private function roleTasks(): RoleTaskService
    {
        $assignments = new AssignmentRepository();

        return new RoleTaskService(
            new PersonRepository(),
            new TaskRepository(),
            $assignments,
            $this->signup(),
            new ClaimNotifier(new TaskRepository(), $assignments, $this->mailer(), $this->client(), $this->standing())
        );
    }

    private function signupController(): SignupController
    {
        $assignments = new AssignmentRepository();

        return new SignupController(
            new PersonRepository(),
            new AuthTokenRepository(),
            new TaskRepository(),
            $assignments,
            $this->signup(),
            $this->standing(),
            $this->mailer(),
            new ClaimNotifier(new TaskRepository(), $assignments, $this->mailer(), $this->client(), $this->standing()),
            new Turnstile(new Logger()),
            new TicketRedemptionService(
                new PersonRepository(),
                new TaskRepository(),
                new RedemptionRepository(),
                $this->standing(),
                new FreeEntryGate(),
                $this->client(),
                $this->mailer()
            ),
            $this->salesDeskGate(),
            $this->doorLinkSender(),
            $this->admin()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function organizerRow(int $id): array
    {
        return ['email_verified_at' => '2026-07-01 00:00:00'] + $this->personRow($id, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function bannedRow(int $id): array
    {
        return ['banned_at' => '2026-07-19 10:00:00', 'ban_reason' => 'test'] + $this->personRow($id);
    }

    /**
     * Signs $row in on the cookie and queues the row SessionPerson reads.
     *
     * @param array<string, mixed> $row
     */
    private function signIn(array $row): void
    {
        $_COOKIE[WebSession::COOKIE] = WebSession::mint((int) $row['id']);
        $this->wpdb->nextRows[] = $row;
    }

    /**
     * @param array<string, mixed> $post
     * @return array{code: string, notice: string}
     */
    private function post(string $action, array $post = [], int $csrfFor = 1): array
    {
        return $this->admin()->handle(
            $action,
            'POST',
            $post + ['csrf' => WebSession::csrfToken($csrfFor)],
            self::IP
        );
    }

    /**
     * @return array<int, array{table: string, data: array<string, mixed>, where: array<string, mixed>}>
     */
    private function banWrites(): array
    {
        return array_values(array_filter(
            $this->wpdb->updates,
            static fn (array $u): bool => array_key_exists('banned_at', $u['data'])
        ));
    }

    private function creditInserts(): int
    {
        return count(array_filter(
            $this->wpdb->inserts,
            static fn (array $i): bool => str_contains($i['table'], 'credit')
        ));
    }

    // --- an organizer runs each action -----------------------------------

    public function testAnOrganizerGetsTheSalesDeskLinkSent(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextCols[] = [self::NIGHT];   // nextNight

        $result = $this->post(CrewAdminController::SALES_ACTION);

        self::assertSame(['code' => CrewAdminController::OK, 'notice' => 'admin_sales_sent'], $result);
        self::assertSame(501, $this->lastCallTo('sendMessage')['chat_id']);
        self::assertSame('door', $this->wpdb->inserts[0]['data']['purpose']);
        self::assertSame('p1@example.com', $this->mails[0]['to']);
        self::assertSame([], $this->banWrites());
    }

    public function testAnOrganizerPostsANewBoard(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 5];
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextResults[] = [];   // render: upcoming
        $this->wpdb->nextResults[] = [];   // occupancyFor

        $result = $this->post(CrewAdminController::BOARD_ACTION);

        self::assertSame('admin_board_posted', $result['notice']);
        self::assertSame(100, $this->lastCallTo('sendMessage')['chat_id']);
        self::assertSame(5, $this->lastCallTo('deleteMessage')['message_id']);
    }

    public function testWithNoBoardGroupNothingIsPosted(): void
    {
        $this->signIn($this->organizerRow(1));

        self::assertSame('admin_board_no_group', $this->post(CrewAdminController::BOARD_ACTION)['notice']);
        self::assertSame([], $this->telegramCalls);
    }

    public function testAnOrganizerGivesACreditAndTheMemberIsTold(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = $this->personRow(8);   // the recipient

        $result = $this->post(CrewAdminController::GIFT_ACTION, ['person_id' => '8', 'credits' => '3', 'reason' => 'set up the bar']);

        self::assertSame('admin_credit_given', $result['notice']);
        $grant = $this->wpdb->inserts[0];
        self::assertStringContainsString('credit', $grant['table']);
        self::assertSame(8, $grant['data']['person_id']);
        self::assertSame(3, $grant['data']['credits']);
        self::assertSame('set up the bar', $grant['data']['note']);
        self::assertSame(1, $grant['data']['granted_by_person_id']);
        // The member's DM, the same notice /gift sends.
        self::assertSame(508, $this->lastCallTo('sendMessage')['chat_id']);
    }

    public function testACreditOfMoreThanFiveIsGivenWithNoUpperLimit(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = $this->personRow(8);   // the recipient

        $result = $this->post(CrewAdminController::GIFT_ACTION, ['person_id' => '8', 'credits' => '12']);

        self::assertSame('admin_credit_given', $result['notice']);
        self::assertSame(1, $this->creditInserts());
        self::assertSame(12, $this->wpdb->inserts[0]['data']['credits']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function badCreditCounts(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-2'],
            'a fraction' => ['1.5'],
            'an exponent' => ['2e3'],
            'words' => ['three'],
            'empty' => [''],
            'a list' => [['3']],
        ];
    }

    /**
     * @dataProvider badCreditCounts
     */
    public function testACreditCountThatIsNotAWholeNumberFromOneUpIsRefused(mixed $credits): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = $this->personRow(8);   // the recipient, if it got that far

        self::assertSame(
            'admin_bad_credits',
            $this->post(CrewAdminController::GIFT_ACTION, ['person_id' => '8', 'credits' => $credits])['notice']
        );
        self::assertSame(0, $this->creditInserts());
        self::assertSame('Pick a number of credits, 1 or more.', CrewAdminController::noticeText('admin_bad_credits'));
    }

    public function testAnOrganizerMarksAttendance(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 6, 8, 'signed_up');

        $result = $this->post(CrewAdminController::MARK_ACTION, ['assignment_id' => '30', 'mark' => 'no_show']);

        self::assertSame('admin_marked', $result['notice']);
        self::assertSame('no_show', $this->wpdb->updates[0]['data']['status']);
        self::assertSame(['id' => 30], $this->wpdb->updates[0]['where']);
        // Recorded as the organizer's EventCrew person id.
        self::assertSame(1, $this->wpdb->updates[0]['data']['changed_by']);
        self::assertSame(MarkedBy::PERSON, $this->wpdb->updates[0]['data']['changed_by_kind']);
    }

    public function testAnUnknownMarkWritesNothing(): void
    {
        $this->signIn($this->organizerRow(1));

        $result = $this->post(CrewAdminController::MARK_ACTION, ['assignment_id' => '30', 'mark' => 'cancelled']);

        self::assertSame('admin_no_entry', $result['notice']);
        self::assertSame([], $this->wpdb->updates);
    }

    // --- the People screen: list, add, edit, pass, unban, credit log ------

    /**
     * One request of each People screen action that would write if it ran.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function peopleScreenRequests(): array
    {
        $person = ['email' => 'new@example.com', 'display_name' => 'New', 'notes' => '', 'is_organizer' => '1'];

        return [
            'add a person' => [CrewAdminController::PERSON_ACTION, ['person_id' => '0'] + $person],
            'edit a person' => [CrewAdminController::PERSON_ACTION, ['person_id' => '9'] + $person],
            'grant a pass' => [CrewAdminController::PASS_ACTION, ['person_id' => '9']],
            'unban' => [CrewAdminController::UNBAN_ACTION, ['person_id' => '9']],
            'newcomer' => [CrewAdminController::NEWCOMER_ACTION, ['person_id' => '9', 'decision' => 'ok']],
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @dataProvider peopleScreenRequests
     */
    public function testAMemberSendingAPeopleScreenRequestIsBlockedAndNothingIsWritten(string $action, array $post): void
    {
        $this->signIn($this->personRow(8));
        $this->wpdb->nextResults[] = [$this->organizerRow(1)];   // organizers() for the alert
        $this->wpdb->nextRows[] = $this->bannedRow(9);           // the target, if it got that far

        $result = $this->post($action, $post, 8);

        self::assertSame(CrewAdminController::FORGED, $result['code']);
        self::assertSame(501, $this->lastCallTo('sendMessage')['chat_id']);
        // The one write is the sender's own block; nobody is added, changed,
        // passed or unbanned.
        self::assertCount(1, $this->wpdb->updates);
        self::assertSame(['id' => 8], $this->wpdb->updates[0]['where']);
        self::assertNotNull($this->wpdb->updates[0]['data']['banned_at']);
        self::assertSame([], $this->wpdb->inserts);
    }

    /**
     * @param array<string, mixed> $post
     * @dataProvider peopleScreenRequests
     */
    public function testAPeopleScreenGetRequestIsRefusedAndNothingIsWritten(string $action, array $post): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = $this->bannedRow(9);   // the target, if it got that far

        $result = $this->admin()->handle($action, 'GET', $post + ['csrf' => WebSession::csrfToken(1)], self::IP);

        self::assertSame(CrewAdminController::REFUSED, $result['code']);
        self::assertSame([], $this->telegramCalls);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testAnOrganizerSetsAndRemovesAOneTimePostEventText(): void
    {
        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);

            return true;
        });
        $option = FollowupText::NEXT_OPTIONS[FollowupText::CAME];

        $this->signIn($this->organizerRow(1));
        $result = $this->post(
            CrewAdminController::FOLLOWUP_TEXT_ACTION,
            ['variant' => 'came', 'followup_text' => 'Thanks for coming!']
        );

        self::assertSame(['code' => CrewAdminController::OK, 'notice' => 'admin_followup_saved_untranslated'], $result);
        self::assertSame('Thanks for coming!', $this->options[$option]['en'] ?? null);
        self::assertSame(1, $this->options[$option]['by'] ?? null);

        $this->signIn($this->organizerRow(1));
        $result = $this->post(CrewAdminController::FOLLOWUP_TEXT_ACTION, ['variant' => 'came', 'op' => 'clear']);

        self::assertSame('admin_followup_cleared', $result['notice']);
        self::assertArrayNotHasKey($option, $this->options);

        $this->signIn($this->organizerRow(1));
        $result = $this->post(
            CrewAdminController::FOLLOWUP_TEXT_ACTION,
            ['variant' => 'maybe', 'followup_text' => 'Thanks!']
        );

        self::assertSame('admin_followup_unknown', $result['notice']);
        self::assertSame([], array_intersect_key($this->options, array_flip(FollowupText::NEXT_OPTIONS)));
    }

    public function testAMemberCannotSetAPostEventText(): void
    {
        $this->signIn($this->personRow(8));
        $this->wpdb->nextResults[] = [$this->organizerRow(1)];   // organizers() for the alert

        $result = $this->post(
            CrewAdminController::FOLLOWUP_TEXT_ACTION,
            ['variant' => 'came', 'followup_text' => 'Thanks!'],
            8
        );

        self::assertSame(CrewAdminController::FORGED, $result['code']);
        self::assertArrayNotHasKey(FollowupText::NEXT_OPTIONS[FollowupText::CAME], $this->options);
    }

    public function testAnOrganizerEditsAPersonAndTheOrganizerFlagIsNeverWritten(): void
    {
        $this->djTask();
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = ['role_slugs' => 'archived'] + $this->personRow(8);   // find()
        $this->wpdb->nextRows[] = $this->personRow(8);                                   // findByEmail(): their own

        $result = $this->post(CrewAdminController::PERSON_ACTION, [
            'person_id' => '8',
            'email' => 'p8@example.com',
            'display_name' => ' Anna ',
            'notes' => 'Bar on Fridays',
            'roles' => ['dj'],
            'is_organizer' => '1',
        ]);

        self::assertSame(['code' => CrewAdminController::OK, 'notice' => 'admin_person_updated'], $result);
        self::assertCount(1, $this->wpdb->updates);
        $update = $this->wpdb->updates[0];
        self::assertSame(['id' => 8], $update['where']);
        self::assertSame('p8@example.com', $update['data']['email']);
        self::assertSame('Anna', $update['data']['display_name']);
        self::assertSame('Bar on Fridays', $update['data']['notes']);
        // A role the form does not show (archived) is kept, as in wp-admin.
        self::assertSame('archived,dj', $update['data']['role_slugs']);
        self::assertArrayNotHasKey('is_organizer', $update['data']);
        self::assertSame('Person updated.', CrewAdminController::noticeText('admin_person_updated'));
    }

    public function testEditingAnOrganizerLeavesThemAnOrganizer(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = $this->personRow(2, true);   // find()

        $result = $this->post(CrewAdminController::PERSON_ACTION, [
            'person_id' => '2',
            'email' => 'p2@example.com',
            'display_name' => 'Person 2',
        ]);

        self::assertSame('admin_person_updated', $result['notice']);
        self::assertArrayNotHasKey('is_organizer', $this->wpdb->updates[0]['data']);
    }

    public function testAnOrganizerAddsAPersonWhoIsNeitherAnOrganizerNorVerified(): void
    {
        $this->djTask();
        $this->options[Roles::OPTION_NAME][] = ['slug' => 'vj', 'label' => 'VJ', 'restricted' => true];
        $this->signIn($this->organizerRow(1));
        $this->wpdb->insert_id = 40;

        $result = $this->post(CrewAdminController::PERSON_ACTION, [
            'person_id' => '0',
            'email' => 'new@example.com',
            'display_name' => 'Anna',
            'notes' => '',
            'roles' => ['dj', 'vj', 'not-a-role'],
            'is_organizer' => '1',
        ]);

        self::assertSame(['code' => CrewAdminController::OK, 'notice' => 'admin_person_added'], $result);
        self::assertCount(1, $this->wpdb->inserts);
        $insert = $this->wpdb->inserts[0]['data'];
        self::assertSame('new@example.com', $insert['email']);
        self::assertSame('Anna', $insert['display_name']);
        self::assertSame(0, $insert['is_organizer']);
        self::assertNull($insert['email_verified_at']);
        self::assertSame('dj,vj', $insert['role_slugs']);
        self::assertSame('Person added.', CrewAdminController::noticeText('admin_person_added'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badEmails(): array
    {
        return ['empty' => [''], 'no at sign' => ['anna.example.com'], 'no domain' => ['anna@']];
    }

    /**
     * @dataProvider badEmails
     */
    public function testAPersonWithNoValidEmailIsNotSaved(string $email): void
    {
        foreach (['0', '8'] as $personId) {
            $this->signIn($this->organizerRow(1));
            if ('8' === $personId) {
                $this->wpdb->nextRows[] = $this->personRow(8);   // find()
            }

            $result = $this->post(CrewAdminController::PERSON_ACTION, ['person_id' => $personId, 'email' => $email]);

            self::assertSame('admin_person_bad_email', $result['notice'], $personId);
        }

        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame(
            'A valid email address is required - it is how a person is identified.',
            CrewAdminController::noticeText('admin_person_bad_email')
        );
    }

    public function testAnEmailThatBelongsToSomeoneElseIsNotSaved(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = $this->personRow(8);   // find()
        $this->wpdb->nextRows[] = $this->personRow(9);   // findByEmail(): somebody else

        $edit = $this->post(CrewAdminController::PERSON_ACTION, ['person_id' => '8', 'email' => 'p9@example.com']);

        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = $this->personRow(9);   // findByEmail()

        $add = $this->post(CrewAdminController::PERSON_ACTION, ['person_id' => '0', 'email' => 'p9@example.com']);

        self::assertSame('admin_person_email_taken', $edit['notice']);
        self::assertSame('admin_person_email_taken', $add['notice']);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame(
            'Another person already uses that email address.',
            CrewAdminController::noticeText('admin_person_email_taken')
        );
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function peopleScreenTargets(): array
    {
        return [
            'edit' => [CrewAdminController::PERSON_ACTION, ['person_id' => '9', 'email' => 'p9@example.com']],
            'pass' => [CrewAdminController::PASS_ACTION, ['person_id' => '9']],
            'unban' => [CrewAdminController::UNBAN_ACTION, ['person_id' => '9']],
            'newcomer' => [CrewAdminController::NEWCOMER_ACTION, ['person_id' => '9', 'decision' => 'ok']],
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @dataProvider peopleScreenTargets
     */
    public function testAPersonWhoIsGoneOrAnonymisedIsNotChanged(string $action, array $post): void
    {
        $this->signIn($this->organizerRow(1));
        // find() answers nothing: the person is gone.

        $gone = $this->post($action, $post);

        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = ['anonymized_at' => '2026-01-01 00:00:00'] + $this->bannedRow(9);

        $anonymised = $this->post($action, $post);

        self::assertSame('admin_no_person', $gone['notice']);
        self::assertSame('admin_no_person', $anonymised['notice']);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->wpdb->inserts);
    }

    /**
     * Records the actions fired from now on.
     *
     * @return \ArrayObject<int, array{0: string, 1: array<int, mixed>}>
     */
    private function recordActions(): \ArrayObject
    {
        $fired = new \ArrayObject();
        Functions\when('do_action')->alias(static function (string $hook, mixed ...$args) use ($fired): void {
            $fired[] = [$hook, $args];
        });

        return $fired;
    }

    public function testAnOrganizerGrantsAOneTimePass(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = $this->personRow(8);
        $fired = $this->recordActions();

        $result = $this->post(CrewAdminController::PASS_ACTION, ['person_id' => '8']);

        self::assertSame(['code' => CrewAdminController::OK, 'notice' => 'admin_pass_granted'], $result);
        self::assertCount(1, $this->wpdb->updates);
        self::assertSame(['id' => 8], $this->wpdb->updates[0]['where']);
        self::assertSame(1, $this->wpdb->updates[0]['data']['at_risk_pass']);
        self::assertSame('One-time sign-up pass granted.', CrewAdminController::noticeText('admin_pass_granted'));
        // The person is told (AccessNotifier listens).
        self::assertContains([\EventCrew\Support\AccessNotifier::PASS_HOOK, [8]], (array) $fired);
    }

    public function testAnOrganizerSaysOkToANewcomer(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = ['approval' => Person::APPROVAL_WAITING] + $this->personRow(8);
        $this->wpdb->nextQueryResults[] = 1;

        $result = $this->post(CrewAdminController::NEWCOMER_ACTION, ['person_id' => '8', 'decision' => 'ok']);

        self::assertSame(['code' => CrewAdminController::OK, 'notice' => 'admin_newcomer_ok'], $result);
        $update = array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_starts_with(ltrim($sql), 'UPDATE') && str_contains($sql, 'approval')
        ));
        self::assertCount(1, $update);
        self::assertStringContainsString("SET approval = 'ok'", $update[0]);
        self::assertSame(
            'OK given. They can take tasks now, and they are told.',
            CrewAdminController::noticeText('admin_newcomer_ok')
        );
    }

    public function testALaterAnswerForANewcomerSaysSomeoneAlreadyAnswered(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = ['approval' => Person::APPROVAL_OK] + $this->personRow(8);
        $this->wpdb->nextQueryResults[] = 0;

        $result = $this->post(CrewAdminController::NEWCOMER_ACTION, ['person_id' => '8', 'decision' => 'no']);

        self::assertSame(['code' => CrewAdminController::OK, 'notice' => 'admin_newcomer_already'], $result);
        self::assertSame([], $this->telegramCalls);
    }

    public function testAnUnknownNewcomerDecisionChangesNothing(): void
    {
        $this->signIn($this->organizerRow(1));

        $result = $this->post(CrewAdminController::NEWCOMER_ACTION, ['person_id' => '8', 'decision' => 'maybe']);

        self::assertSame('admin_no_person', $result['notice']);
        self::assertSame([], $this->wpdb->updates);
    }

    public function testAnOrganizerUnbansAPerson(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = $this->bannedRow(8);

        $result = $this->post(CrewAdminController::UNBAN_ACTION, ['person_id' => '8']);

        self::assertSame(['code' => CrewAdminController::OK, 'notice' => 'admin_person_unbanned'], $result);
        self::assertCount(1, $this->wpdb->updates);
        self::assertSame(['id' => 8], $this->wpdb->updates[0]['where']);
        self::assertNull($this->wpdb->updates[0]['data']['banned_at']);
        self::assertNull($this->wpdb->updates[0]['data']['ban_reason']);
        self::assertSame(
            'Unbanned. They can sign in and sign up again.',
            CrewAdminController::noticeText('admin_person_unbanned')
        );
    }

    public function testThePeopleListIsOnePageOfTheSearch(): void
    {
        $this->wpdb->nextVars[] = 45;   // count(): three pages of 20
        $this->wpdb->nextResults[] = [$this->bannedRow(8), ['anonymized_at' => '2026-01-01 00:00:00'] + $this->personRow(9)];

        $view = $this->peopleScreen()->view(' anna ', 7, 0);

        self::assertSame('anna', $view['search']);
        self::assertSame(3, $view['page']);   // past the end: the last page
        self::assertSame(3, $view['pages']);
        self::assertSame(45, $view['total']);
        self::assertNull($view['editing']);
        self::assertSame(8, $view['list'][0]['id']);
        self::assertTrue($view['list'][0]['banned']);
        self::assertFalse($view['list'][0]['verified']);
        self::assertTrue($view['list'][1]['anonymized']);
        $listQuery = implode("\n", array_filter($this->wpdb->queries, static fn (string $q): bool => str_contains($q, 'LIMIT')));
        self::assertStringContainsString("LIKE '%anna%'", $listQuery);
        self::assertStringContainsString('LIMIT 20 OFFSET 40', $listQuery);
    }

    public function testThePersonOpenForEditingHasTheirCreditLog(): void
    {
        $this->wpdb->nextResults[] = [];                    // the list: nobody
        $this->wpdb->nextRows[] = $this->personRow(8);      // find()
        $this->wpdb->nextResults[] = [];                    // standing: task history
        $this->wpdb->nextResults[] = [                      // the credit-grant log
            ['credits' => 3, 'note' => 'set up the bar', 'granted_by' => null, 'granted_by_person_id' => 1, 'granted_at' => '2026-07-19 20:00:00'],
            ['credits' => 1, 'note' => '', 'granted_by' => null, 'granted_by_person_id' => null, 'granted_at' => '2026-07-01 20:00:00'],
        ];
        $this->wpdb->nextResults[] = [                      // the redemptions
            ['id' => 40, 'person_id' => 8, 'redeemed_for' => '2026-07-13', 'event_label' => 'Milonga', 'redeemed_at' => '2026-07-13 21:00:00'],
        ];
        $this->wpdb->nextRows[] = $this->organizerRow(1);   // the granter of the first line

        $view = $this->peopleScreen()->view('', 1, 8);

        self::assertNotNull($view['editing']);
        self::assertSame(8, $view['editing']['person']->id);
        self::assertSame('Person 1', $view['editing']['given'][0]['granted_by']);
        self::assertSame('the system', $view['editing']['given'][1]['granted_by']);
        self::assertSame(3, $view['editing']['given'][0]['credits']);
        self::assertSame('Milonga', $view['editing']['spent'][0]['event']);
        self::assertNotSame('', $view['editing']['spent'][0]['day']);
    }

    public function testAnAnonymisedPersonIsNotOpenedForEditing(): void
    {
        $this->wpdb->nextRows[] = ['anonymized_at' => '2026-01-01 00:00:00'] + $this->personRow(8);

        self::assertNull($this->peopleScreen()->view('', 1, 8)['editing']);
    }

    private function peopleScreen(): CrewAdminPeople
    {
        return new CrewAdminPeople(new PersonRepository(), new CreditGrantRepository(), new RedemptionRepository(), $this->standing());
    }

    // --- the Roster screen: date picker, attendance, door, guests, buyers ---

    /**
     * One request of each Roster screen action that would write if it ran.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function rosterScreenRequests(): array
    {
        $date = self::NIGHT;

        return [
            'save attendance' => [CrewAdminController::ROSTER_ACTION, ['roster_date' => $date, 'status' => ['30' => 'arrived']]],
            'mark all' => [CrewAdminController::ROSTER_ACTION, ['roster_date' => $date, 'task_id' => '6', 'mark_all' => 'arrived']],
            'close free entry' => [CrewAdminController::DOOR_ACTION, ['roster_date' => $date, 'op' => 'toggle']],
            'redeem' => [CrewAdminController::DOOR_ACTION, ['roster_date' => $date, 'op' => 'redeem', 'person_id' => '8']],
            'unredeem' => [CrewAdminController::DOOR_ACTION, ['roster_date' => $date, 'op' => 'unredeem', 'redemption_id' => '40']],
            'add guest' => [CrewAdminController::GUEST_ACTION, ['roster_date' => $date, 'op' => 'save', 'guest_name' => 'Anna']],
            'remove guest' => [CrewAdminController::GUEST_ACTION, ['roster_date' => $date, 'op' => 'remove', 'guest_id' => '5']],
            'import buyers' => [CrewAdminController::BUYERS_ACTION, ['roster_date' => $date, 'op' => 'import', 'buyer_names' => 'Anna']],
            'reset buyers' => [CrewAdminController::BUYERS_ACTION, ['roster_date' => $date, 'op' => 'reset']],
            'check mail' => [CrewAdminController::MAIL_ACTION, []],
        ];
    }

    /**
     * Every write the fake wpdb saw apart from the ban itself, and the options
     * the free-entry switch and the mail check write.
     *
     * @return array<string, mixed>
     */
    private function rosterScreenWrites(): array
    {
        $banOnly = static fn (array $u): bool => ! array_key_exists('banned_at', $u['data']);

        return [
            'inserts' => $this->wpdb->inserts,
            'updates' => array_values(array_filter($this->wpdb->updates, $banOnly)),
            'deletes' => $this->wpdb->deletes,
            'statements' => array_values(array_filter(
                $this->wpdb->queries,
                static fn (string $sql): bool => 1 === preg_match('/^\s*(INSERT|UPDATE|DELETE)\s/i', $sql)
            )),
            'closed' => $this->options[FreeEntryGate::OPTION] ?? null,
            'mail' => $this->options[MailFetchSettings::STATUS_OPTION] ?? null,
        ];
    }

    private function switchMailOn(): void
    {
        $this->options[MailFetchSettings::OPTION] = [
            'protocol' => 'imap',
            'server' => 'mail.example.org',
            'username' => 'door@example.org',
            'password' => 'secret',
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @dataProvider rosterScreenRequests
     */
    public function testAMemberSendingARosterScreenRequestIsBlockedAndNothingIsWritten(string $action, array $post): void
    {
        $this->switchMailOn();
        $this->signIn($this->personRow(8));
        $this->wpdb->nextResults[] = [$this->organizerRow(1)];   // organizers() for the alert

        $result = $this->post($action, $post, 8);

        self::assertSame(CrewAdminController::FORGED, $result['code']);
        self::assertCount(1, $this->banWrites());
        self::assertSame(501, $this->lastCallTo('sendMessage')['chat_id']);
        self::assertSame(
            ['inserts' => [], 'updates' => [], 'deletes' => [], 'statements' => [], 'closed' => null, 'mail' => null],
            $this->rosterScreenWrites()
        );
    }

    /**
     * @param array<string, mixed> $post
     * @dataProvider rosterScreenRequests
     */
    public function testARosterScreenGetRequestIsRefusedAndNothingIsWritten(string $action, array $post): void
    {
        $this->switchMailOn();
        $this->signIn($this->organizerRow(1));

        $result = $this->admin()->handle($action, 'GET', $post + ['csrf' => WebSession::csrfToken(1)], self::IP);

        self::assertSame(CrewAdminController::REFUSED, $result['code']);
        self::assertSame([], $this->banWrites());
        self::assertSame([], $this->telegramCalls);
        self::assertSame(
            ['inserts' => [], 'updates' => [], 'deletes' => [], 'statements' => [], 'closed' => null, 'mail' => null],
            $this->rosterScreenWrites()
        );
    }

    public function testADateThatIsNotARealDayChangesNothing(): void
    {
        $requests = [
            [CrewAdminController::ROSTER_ACTION, ['status' => ['30' => 'arrived']], 'admin_change_failed'],
            [CrewAdminController::ROSTER_ACTION, ['task_id' => '6', 'mark_all' => 'arrived'], 'admin_roster_bulk_failed'],
            [CrewAdminController::DOOR_ACTION, ['op' => 'toggle'], 'admin_change_failed'],
            [CrewAdminController::DOOR_ACTION, ['op' => 'redeem', 'person_id' => '8'], 'admin_door_failed'],
            [CrewAdminController::GUEST_ACTION, ['op' => 'save', 'guest_name' => 'Anna'], 'admin_change_failed'],
            [CrewAdminController::BUYERS_ACTION, ['op' => 'import', 'buyer_names' => 'Anna'], 'admin_buyers_failed'],
            [CrewAdminController::BUYERS_ACTION, ['op' => 'reset'], 'admin_change_failed'],
        ];

        foreach (['2026-02-30', '20-07-2026', '', '2026-07-20 OR 1=1'] as $date) {
            foreach ($requests as [$action, $post, $notice]) {
                $this->signIn($this->organizerRow(1));
                $this->wpdb->nextRows[] = $this->assignmentRow(30, 6, 8, 'signed_up');

                $result = $this->post($action, $post + ['roster_date' => $date]);

                self::assertSame($notice, $result['notice'], $action . ' ' . $date);
                // The queued assignment was never read, so nothing was looked up.
                self::assertNotNull(array_shift($this->wpdb->nextRows), $action . ' ' . $date);
            }
        }

        self::assertSame(
            ['inserts' => [], 'updates' => [], 'deletes' => [], 'statements' => [], 'closed' => null, 'mail' => null],
            $this->rosterScreenWrites()
        );
    }

    public function testThePickerListsUpcomingDatesNearestFirstAndPastDatesMostRecentFirst(): void
    {
        $dates = ['2026-08-10', '2026-07-27', self::NIGHT, '2026-07-13', '2026-07-06'];   // most recent first

        $this->wpdb->nextCols[] = $dates;
        $picked = $this->rosterScreen()->dates('');

        self::assertSame(self::NIGHT, $picked['date']);
        self::assertSame([self::NIGHT, '2026-07-27', '2026-08-10'], array_keys($picked['upcoming']));
        self::assertSame(['2026-07-13', '2026-07-06'], array_keys($picked['past']));
        self::assertSame('Mon 20 Jul', $picked['upcoming'][self::NIGHT]);

        // A past date with tasks is shown; a date with none falls back.
        $this->wpdb->nextCols[] = $dates;
        self::assertSame('2026-07-13', $this->rosterScreen()->dates('2026-07-13')['date']);
        $this->wpdb->nextCols[] = $dates;
        self::assertSame(self::NIGHT, $this->rosterScreen()->dates('2026-07-14')['date']);

        // Nothing upcoming: the most recent past date.
        $this->wpdb->nextCols[] = ['2026-07-13', '2026-07-06'];
        self::assertSame('2026-07-13', $this->rosterScreen()->dates('')['date']);
    }

    public function testSavingAttendanceWritesOnlyTheRowsThatChanged(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 6, 8, 'signed_up');   // changed, still as shown
        $this->wpdb->nextRows[] = null;                                          // 33: not found

        $result = $this->post(CrewAdminController::ROSTER_ACTION, [
            'roster_date' => self::NIGHT,
            'status' => ['30' => 'completed', '31' => 'arrived', '32' => 'bogus', '33' => 'no_show'],
            'shown' => ['30' => 'signed_up', '31' => 'arrived', '32' => 'signed_up', '33' => 'signed_up'],
        ]);

        self::assertSame('admin_roster_updated_1', $result['notice']);
        self::assertCount(1, $this->wpdb->updates);
        self::assertSame(['id' => 30], $this->wpdb->updates[0]['where']);
        self::assertSame('completed', $this->wpdb->updates[0]['data']['status']);
        self::assertSame(1, $this->wpdb->updates[0]['data']['changed_by']);
        self::assertSame(MarkedBy::PERSON, $this->wpdb->updates[0]['data']['changed_by_kind']);
        // The unchanged row and the invalid status were skipped before any lookup.
        self::assertSame([], $this->wpdb->nextRows);
        self::assertSame([], array_filter($this->wpdb->queries, static fn (string $sql): bool => str_contains($sql, 'WHERE id = 31')));
        self::assertSame([], array_filter($this->wpdb->queries, static fn (string $sql): bool => str_contains($sql, 'WHERE id = 32')));
        self::assertSame('1 person updated.', CrewAdminController::noticeText($result['notice']));
    }

    public function testSavingAttendanceWithNothingChangedWritesNothing(): void
    {
        $this->signIn($this->organizerRow(1));
        // Checked in at the door after the page was opened: the page still
        // shows "Signed up", and the organizer did not touch this row.
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 6, 8, 'arrived');

        $result = $this->post(CrewAdminController::ROSTER_ACTION, [
            'roster_date' => self::NIGHT,
            'status' => ['30' => 'signed_up'],
            'shown' => ['30' => 'signed_up'],
        ]);

        self::assertSame('admin_roster_none', $result['notice']);
        self::assertSame([], $this->wpdb->updates);
    }

    public function testSavingAttendanceSkipsARowThatChangedAfterThePageWasOpened(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 6, 8, 'cancelled');   // was signed_up on the page
        $this->wpdb->nextRows[] = $this->assignmentRow(31, 6, 9, 'signed_up');   // still as shown

        $result = $this->post(CrewAdminController::ROSTER_ACTION, [
            'roster_date' => self::NIGHT,
            'status' => ['30' => 'arrived', '31' => 'arrived'],
            'shown' => ['30' => 'signed_up', '31' => 'signed_up'],
        ]);

        self::assertSame('admin_roster_saved_1_1', $result['notice']);
        self::assertSame([['id' => 31]], array_column($this->wpdb->updates, 'where'));
        self::assertSame(
            '1 person updated. 1 mark was not saved because it changed after you opened the page; reload to see it.',
            CrewAdminController::noticeText($result['notice'])
        );
    }

    public function testSavingAttendanceWithOnlyStaleRowsNamesThemAll(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 6, 8, 'replaced');
        $this->wpdb->nextRows[] = $this->assignmentRow(31, 6, 9, 'arrived');

        $result = $this->post(CrewAdminController::ROSTER_ACTION, [
            'roster_date' => self::NIGHT,
            'status' => ['30' => 'no_show', '31' => 'no_show'],
            'shown' => ['30' => 'signed_up', '31' => 'signed_up'],
        ]);

        self::assertSame('admin_roster_saved_0_2', $result['notice']);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame(
            '2 marks were not saved because they changed after you opened the page; reload to see them.',
            CrewAdminController::noticeText($result['notice'])
        );
    }

    public function testMarkAllSkipsCancelledAndAbsentPeople(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextResults[] = [
            $this->assignmentRow(30, 6, 8, 'signed_up'),
            $this->assignmentRow(31, 6, 9, 'arrived'),
            $this->assignmentRow(32, 6, 10, 'cancelled'),
            $this->assignmentRow(33, 6, 11, 'no_show'),
            $this->assignmentRow(34, 6, 12, 'completed'),
        ];

        $result = $this->post(
            CrewAdminController::ROSTER_ACTION,
            ['roster_date' => self::NIGHT, 'task_id' => '6', 'mark_all' => 'completed']
        );

        self::assertSame('admin_roster_updated_2', $result['notice']);
        self::assertSame([['id' => 30], ['id' => 31]], array_column($this->wpdb->updates, 'where'));
        self::assertSame(['completed', 'completed'], array_column(array_column($this->wpdb->updates, 'data'), 'status'));
        self::assertSame([1, 1], array_column(array_column($this->wpdb->updates, 'data'), 'changed_by'));
    }

    public function testMarkAllRefusesAnyStatusButArrivedOrCompleted(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextResults[] = [$this->assignmentRow(30, 6, 8, 'signed_up')];

        $result = $this->post(
            CrewAdminController::ROSTER_ACTION,
            ['roster_date' => self::NIGHT, 'task_id' => '6', 'mark_all' => 'no_show']
        );

        self::assertSame('admin_roster_bulk_failed', $result['notice']);
        self::assertSame([], $this->wpdb->updates);
    }

    public function testTheFreeEntrySwitchClosesAndReopensTheDate(): void
    {
        $this->signIn($this->organizerRow(1));
        $result = $this->post(CrewAdminController::DOOR_ACTION, ['roster_date' => self::NIGHT, 'op' => 'toggle']);

        self::assertSame('admin_door_closed_now', $result['notice']);
        self::assertSame([self::NIGHT], $this->options[FreeEntryGate::OPTION]);

        $this->signIn($this->organizerRow(1));
        $result = $this->post(CrewAdminController::DOOR_ACTION, ['roster_date' => self::NIGHT, 'op' => 'toggle']);

        self::assertSame('admin_door_reopened', $result['notice']);
        self::assertSame([], $this->options[FreeEntryGate::OPTION]);
    }

    /** The INSERTs of credit redemptions the fake wpdb saw. */
    private function redemptionInserts(): array
    {
        return array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => 1 === preg_match('/^\s*INSERT\s.*redemption/is', $sql)
        ));
    }

    /**
     * Queues person 8's standing: no tasks, no redemptions, $grants credits
     * given by hand.
     */
    private function queueStanding(int $grants): void
    {
        $this->wpdb->nextResults[] = [];               // historyFor
        $this->wpdb->nextVars[] = '0';                 // redemptions countFor
        $this->wpdb->nextVars[] = (string) $grants;    // grants sumFor
    }

    public function testRedeemingWhenFreeEntryIsClosedRecordsNothing(): void
    {
        $this->options[FreeEntryGate::OPTION] = [self::NIGHT];
        $this->signIn($this->organizerRow(1));
        $this->queueStanding(2);

        $result = $this->post(CrewAdminController::DOOR_ACTION, ['roster_date' => self::NIGHT, 'op' => 'redeem', 'person_id' => '8']);

        self::assertSame('admin_door_closed', $result['notice']);
        self::assertSame([], $this->redemptionInserts());
        self::assertSame([], $this->telegramCalls);
    }

    public function testRedeemingWithNoCreditLeftRecordsNothing(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->queueStanding(0);

        $result = $this->post(CrewAdminController::DOOR_ACTION, ['roster_date' => self::NIGHT, 'op' => 'redeem', 'person_id' => '8']);

        self::assertSame('admin_door_no_credit', $result['notice']);
        self::assertSame([], $this->redemptionInserts());
        self::assertSame([], $this->telegramCalls);
    }

    public function testRedeemingTwiceForOneDateSendsNoSecondTicket(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->queueStanding(2);
        $this->wpdb->nextResults[] = [$this->taskRow(6, 'decorate')];   // the event context
        $this->wpdb->nextQueryResults[] = 0;                             // already redeemed
        $this->wpdb->nextRows[] = $this->personRow(8);

        $result = $this->post(CrewAdminController::DOOR_ACTION, ['roster_date' => self::NIGHT, 'op' => 'redeem', 'person_id' => '8']);

        self::assertSame('admin_door_already', $result['notice']);
        self::assertCount(1, $this->redemptionInserts());
        self::assertSame([], $this->telegramCalls);
        self::assertSame([], $this->mails);
    }

    public function testRedeemingACreditPutsThemOnTheDoorListAndSendsTheTicket(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->queueStanding(2);
        $this->wpdb->nextResults[] = [$this->taskRow(6, 'decorate')];   // the event context
        $this->wpdb->nextQueryResults[] = 1;                             // recorded
        $this->wpdb->nextRows[] = $this->personRow(8);                   // the ticket goes to them

        $result = $this->post(CrewAdminController::DOOR_ACTION, ['roster_date' => self::NIGHT, 'op' => 'redeem', 'person_id' => '8']);

        self::assertSame('admin_door_redeemed', $result['notice']);
        $inserts = $this->redemptionInserts();
        self::assertCount(1, $inserts);
        self::assertStringContainsString("8, '" . self::NIGHT . "'", $inserts[0]);
        $dm = $this->lastCallTo('sendMessage');
        self::assertSame(508, $dm['chat_id']);
        self::assertStringContainsString('eventcrew/v1/ticket', (string) json_encode($dm, JSON_UNESCAPED_SLASHES));
    }

    public function testRemovingARedemptionHandsTheCreditBack(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = ['id' => 40, 'person_id' => 8, 'redeemed_for' => self::NIGHT];

        $result = $this->post(CrewAdminController::DOOR_ACTION, ['roster_date' => self::NIGHT, 'op' => 'unredeem', 'redemption_id' => '40']);

        self::assertSame('admin_door_returned', $result['notice']);
        $cancels = array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_contains($sql, 'SET cancelled_at')
        ));
        self::assertCount(1, $cancels);
        self::assertStringContainsString('WHERE id = 40', $cancels[0]);
    }

    public function testRemovingARedemptionThatIsGoneChangesNothing(): void
    {
        $this->signIn($this->organizerRow(1));

        $result = $this->post(CrewAdminController::DOOR_ACTION, ['roster_date' => self::NIGHT, 'op' => 'unredeem', 'redemption_id' => '40']);

        self::assertSame('admin_change_failed', $result['notice']);
        self::assertSame([], array_filter($this->wpdb->queries, static fn (string $sql): bool => str_contains($sql, 'SET cancelled_at')));
    }

    public function testAnOrganizerAddsAGuest(): void
    {
        $this->signIn($this->organizerRow(1));

        $result = $this->post(CrewAdminController::GUEST_ACTION, [
            'roster_date' => self::NIGHT,
            'op' => 'save',
            'guest_id' => '0',
            'guest_name' => 'Anna',
            'guest_plus_ones' => '25',
            'guest_note' => 'DJ’s partner',
        ]);

        self::assertSame('admin_guest_added', $result['notice']);
        self::assertCount(1, $this->wpdb->inserts);
        $guest = $this->wpdb->inserts[0]['data'];
        self::assertSame(self::NIGHT, $guest['event_date']);
        self::assertSame('Anna', $guest['name']);
        self::assertSame(GuestRepository::MAX_PLUS_ONES, $guest['plus_ones']);
        self::assertSame('DJ’s partner', $guest['note']);
        self::assertSame(1, $guest['added_by']);
    }

    /** Decision 3: the optional email field on the crew app's guest form. */
    public function testAGuestAddedWithAnEmailIsStoredWithIt(): void
    {
        $this->signIn($this->organizerRow(1));

        $result = $this->post(CrewAdminController::GUEST_ACTION, [
            'roster_date' => self::NIGHT,
            'op' => 'save',
            'guest_id' => '0',
            'guest_name' => 'Anna',
            'guest_email' => ' anna@example.com ',
        ]);

        self::assertSame('admin_guest_added', $result['notice']);
        self::assertSame('anna@example.com', $this->wpdb->inserts[0]['data']['email']);
    }

    public function testAGuestWithAnInvalidEmailIsNotAdded(): void
    {
        $this->signIn($this->organizerRow(1));

        $result = $this->post(CrewAdminController::GUEST_ACTION, [
            'roster_date' => self::NIGHT,
            'op' => 'save',
            'guest_id' => '0',
            'guest_name' => 'Anna',
            'guest_email' => 'anna@',
        ]);

        self::assertSame('admin_guest_bad_email', $result['notice']);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testAGuestWithNoNameIsNotAdded(): void
    {
        $this->signIn($this->organizerRow(1));

        $result = $this->post(CrewAdminController::GUEST_ACTION, ['roster_date' => self::NIGHT, 'op' => 'save', 'guest_name' => '  ']);

        self::assertSame('admin_guest_no_name', $result['notice']);
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testAnOrganizerEditsAGuest(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = ['id' => 5, 'event_date' => self::NIGHT, 'name' => 'Ana', 'plus_ones' => 0];

        $result = $this->post(CrewAdminController::GUEST_ACTION, [
            'roster_date' => self::NIGHT,
            'op' => 'save',
            'guest_id' => '5',
            'guest_name' => 'Anna',
            'guest_plus_ones' => '2',
            'guest_note' => '',
        ]);

        self::assertSame('admin_guest_saved', $result['notice']);
        self::assertSame([], $this->wpdb->inserts);
        self::assertCount(1, $this->wpdb->updates);
        self::assertSame(['id' => 5], $this->wpdb->updates[0]['where']);
        self::assertSame(['name' => 'Anna', 'plus_ones' => 2, 'note' => ''], $this->wpdb->updates[0]['data']);
    }

    public function testEditingAGuestWhoIsGoneChangesNothing(): void
    {
        $this->signIn($this->organizerRow(1));

        $result = $this->post(
            CrewAdminController::GUEST_ACTION,
            ['roster_date' => self::NIGHT, 'op' => 'save', 'guest_id' => '5', 'guest_name' => 'Anna']
        );

        self::assertSame('admin_change_failed', $result['notice']);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->wpdb->inserts);
    }

    /**
     * A changed address makes a new ticket: the rev goes up, the mail status
     * is cleared, and the 10-minute send is started for the new address.
     */
    public function testChangingAGuestsEmailMakesANewTicket(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = ['id' => 5, 'event_date' => self::NIGHT, 'name' => 'Anna', 'email' => 'old@example.com'];

        $result = $this->post(CrewAdminController::GUEST_ACTION, [
            'roster_date' => self::NIGHT,
            'op' => 'save',
            'guest_id' => '5',
            'guest_name' => 'Anna',
            'guest_email' => 'new@example.com',
        ]);

        self::assertSame('admin_guest_email_changed', $result['notice']);
        self::assertCount(1, $this->changeEmailQueries());
        self::assertStringContainsString("NULLIF('new@example.com', '')", $this->changeEmailQueries()[0]);
        self::assertArrayHasKey(TicketMail::PENDING_OPTION, $this->options);
    }

    /** The same address in other capitals is no change: the ticket stays. */
    public function testTheSameGuestEmailKeepsTheTicket(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = ['id' => 5, 'event_date' => self::NIGHT, 'name' => 'Anna', 'email' => 'anna@example.com'];

        $result = $this->post(CrewAdminController::GUEST_ACTION, [
            'roster_date' => self::NIGHT,
            'op' => 'save',
            'guest_id' => '5',
            'guest_name' => 'Anna',
            'guest_email' => 'Anna@Example.com',
        ]);

        self::assertSame('admin_guest_saved', $result['notice']);
        self::assertSame([], $this->changeEmailQueries());
        self::assertArrayNotHasKey(TicketMail::PENDING_OPTION, $this->options);
    }

    /** An emptied address takes the ticket away and sends nothing. */
    public function testEmptyingAGuestsEmailTakesTheTicketAway(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = ['id' => 5, 'event_date' => self::NIGHT, 'name' => 'Anna', 'email' => 'anna@example.com'];

        $result = $this->post(CrewAdminController::GUEST_ACTION, [
            'roster_date' => self::NIGHT,
            'op' => 'save',
            'guest_id' => '5',
            'guest_name' => 'Anna',
            'guest_email' => '',
        ]);

        self::assertSame('admin_guest_email_cleared', $result['notice']);
        self::assertCount(1, $this->changeEmailQueries());
        self::assertArrayNotHasKey(TicketMail::PENDING_OPTION, $this->options);
    }

    public function testAnInvalidNewGuestEmailChangesNothing(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = ['id' => 5, 'event_date' => self::NIGHT, 'name' => 'Anna', 'email' => 'anna@example.com'];

        $result = $this->post(CrewAdminController::GUEST_ACTION, [
            'roster_date' => self::NIGHT,
            'op' => 'save',
            'guest_id' => '5',
            'guest_name' => 'Anna',
            'guest_email' => 'anna@',
        ]);

        self::assertSame('admin_guest_bad_email', $result['notice']);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->changeEmailQueries());
    }

    /** @return array<int, string> */
    private function changeEmailQueries(): array
    {
        return array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_contains($sql, 'ticket_rev = ticket_rev + 1')
        ));
    }

    public function testAnOrganizerRemovesAGuest(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = ['id' => 5, 'event_date' => self::NIGHT, 'name' => 'Anna'];

        $result = $this->post(CrewAdminController::GUEST_ACTION, ['roster_date' => self::NIGHT, 'op' => 'remove', 'guest_id' => '5']);

        self::assertSame('admin_guest_removed', $result['notice']);
        self::assertCount(1, $this->wpdb->deletes);
        self::assertSame(['id' => 5], $this->wpdb->deletes[0]['where']);
    }

    public function testRemovingAGuestWhoIsGoneDeletesNothing(): void
    {
        $this->signIn($this->organizerRow(1));

        $result = $this->post(CrewAdminController::GUEST_ACTION, ['roster_date' => self::NIGHT, 'op' => 'remove', 'guest_id' => '5']);

        self::assertSame('admin_change_failed', $result['notice']);
        self::assertSame([], $this->wpdb->deletes);
    }

    public function testAPastedListOfTicketBuyersIsImported(): void
    {
        $this->signIn($this->organizerRow(1));
        // Anna is already on the list.
        $this->wpdb->nextResults[] = [['id' => 1, 'event_date' => self::NIGHT, 'name' => 'Anna', 'source' => 'paste']];

        $result = $this->post(
            CrewAdminController::BUYERS_ACTION,
            ['roster_date' => self::NIGHT, 'op' => 'import', 'buyer_names' => "Anna\nBen\n\nCleo\n"]
        );

        self::assertSame('admin_buyers_added_2_1', $result['notice']);
        self::assertSame(['Ben', 'Cleo'], array_column(array_column($this->wpdb->inserts, 'data'), 'name'));
        self::assertSame(['paste', 'paste'], array_column(array_column($this->wpdb->inserts, 'data'), 'source'));
        self::assertSame(
            '2 ticket buyers added, 1 skipped (already on the list).',
            CrewAdminController::noticeText($result['notice'])
        );
    }

    public function testResetImportDeletesTheImportedBuyersOfThatDate(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextQueryResults[] = 3;

        $result = $this->post(CrewAdminController::BUYERS_ACTION, ['roster_date' => self::NIGHT, 'op' => 'reset']);

        self::assertSame('admin_buyers_deleted_3', $result['notice']);
        $deletes = array_values(array_filter($this->wpdb->queries, static fn (string $sql): bool => str_starts_with($sql, 'DELETE')));
        self::assertCount(1, $deletes);
        self::assertStringContainsString("event_date = '" . self::NIGHT . "'", $deletes[0]);
        self::assertSame('3 ticket buyers deleted.', CrewAdminController::noticeText($result['notice']));
    }

    /** A ticket send waiting, in the options map. */
    private function aTicketSendIsWaiting(): void
    {
        $this->options[TicketMail::PENDING_OPTION] = ['at' => time() + 300, 'rest' => []];

        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);

            return true;
        });
        Functions\when('wp_clear_scheduled_hook')->justReturn(1);
    }

    public function testAnOrganizerCancelsTheWaitingTicketSend(): void
    {
        $this->aTicketSendIsWaiting();
        $this->signIn($this->organizerRow(1));

        $result = $this->post(
            CrewAdminController::BUYERS_ACTION,
            ['roster_date' => self::NIGHT, 'op' => 'mail_cancel']
        );

        self::assertSame('admin_tickets_cancelled', $result['notice']);
        self::assertNull(TicketMail::pending());
        self::assertSame(
            'Sending cancelled. No ticket email was sent.',
            CrewAdminController::noticeText($result['notice'])
        );
    }

    public function testCancelSendingWithABadTokenOrFromAMemberIsRefused(): void
    {
        $this->aTicketSendIsWaiting();
        $this->signIn($this->organizerRow(1));

        $stale = $this->admin()->handle(
            CrewAdminController::BUYERS_ACTION,
            'POST',
            ['roster_date' => self::NIGHT, 'op' => 'mail_cancel', 'csrf' => 'stale'],
            self::IP
        );

        self::assertSame(CrewAdminController::REFUSED, $stale['code']);
        self::assertNotNull(TicketMail::pending());

        $this->signIn($this->personRow(8));
        $this->wpdb->nextResults[] = [$this->organizerRow(1)];

        $member = $this->post(
            CrewAdminController::BUYERS_ACTION,
            ['roster_date' => self::NIGHT, 'op' => 'mail_cancel'],
            8
        );

        self::assertSame(CrewAdminController::FORGED, $member['code']);
        self::assertNotNull(TicketMail::pending());
    }

    public function testAnUnknownOpChangesNothing(): void
    {
        foreach ([CrewAdminController::DOOR_ACTION, CrewAdminController::GUEST_ACTION, CrewAdminController::BUYERS_ACTION] as $action) {
            $this->signIn($this->organizerRow(1));

            self::assertSame('admin_change_failed', $this->post($action, ['roster_date' => self::NIGHT, 'op' => 'drop'])['notice']);
        }

        self::assertSame(
            ['inserts' => [], 'updates' => [], 'deletes' => [], 'statements' => [], 'closed' => null, 'mail' => null],
            $this->rosterScreenWrites()
        );
    }

    public function testTheMailboxCheckIsRefusedWhenReadingMailIsOff(): void
    {
        $this->signIn($this->organizerRow(1));

        $result = $this->post(CrewAdminController::MAIL_ACTION);

        self::assertSame('admin_mail_off', $result['notice']);
        self::assertArrayNotHasKey(MailFetchSettings::STATUS_OPTION, $this->options);
    }

    public function testTheMailboxCheckRunsAndSaysWhatItFound(): void
    {
        $this->switchMailOn();
        $this->signIn($this->organizerRow(1));

        $result = $this->post(CrewAdminController::MAIL_ACTION);

        self::assertSame('admin_mail_checked', $result['notice']);
        self::assertTrue($this->options[MailFetchSettings::STATUS_OPTION]['ok']);
        self::assertSame(
            // switchMailOn() sets no trusted mail server, and the status says so.
            'Mailbox checked: 0 imported, 0 rejected, 0 left to try again'
            . ' (no trusted mail server is set, so every message is refused).',
            CrewAdminController::noticeText($result['notice'])
        );
    }

    public function testAFailedMailboxCheckSaysWhy(): void
    {
        $this->mailboxFails = true;
        $this->switchMailOn();
        $this->signIn($this->organizerRow(1));

        $result = $this->post(CrewAdminController::MAIL_ACTION);

        self::assertSame('admin_mail_failed', $result['notice']);
        self::assertStringContainsString('timed out', CrewAdminController::noticeText($result['notice']));
    }

    // --- restricted roles: adding a person to a role task ------------------

    /**
     * Settings > Roles with a "DJ" role set to "Only people with this role",
     * and task 9 a DJ task tonight.
     *
     * @return array<string, mixed> The DJ task row.
     */
    private function djTask(): array
    {
        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'decorate', 'label' => 'Decorate'],
            ['slug' => 'dj', 'label' => 'DJ', 'emoji' => '🎧', 'restricted' => true],
        ];
        Functions\when('wp_generate_password')->justReturn('AbCdEf123456');

        return ['capacity' => 1] + $this->taskRow(9, 'dj');
    }

    /**
     * Queues what adding a new person (id 40) to the DJ task reads after the
     * task and the email lookup: the new row, the signup, the confirmation.
     *
     * @param array<string, mixed> $task
     * @param array<string, mixed> $created
     */
    private function queueNewPersonSignup(array $task, array $created): void
    {
        $this->wpdb->insert_id = 40;
        $this->wpdb->nextRows[] = $created;                                  // people->find (new)
        $this->wpdb->nextRows[] = $task;                                     // claim: tasks->find
        $this->wpdb->nextRows[] = ['role_slugs' => 'dj'] + $created;        // claim: people->find
        $this->wpdb->nextVars[] = 1;                                         // join: capacity
        $this->wpdb->nextRows[] = null;                                      // join: no row yet
        $this->wpdb->nextQueryResults[] = 1;                                 // join: inserted
        $this->wpdb->nextRows[] = $task;                                     // confirmation: task
        $this->wpdb->nextRows[] = $this->assignmentRow(50, 9, 40, 'signed_up');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function personInserts(): array
    {
        return array_values(array_filter(
            $this->wpdb->inserts,
            static fn (array $i): bool => str_ends_with($i['table'], 'people')
        ));
    }

    public function testAnOrganizerAddsANonMemberWithAnEmailAndTheyGetTheirTicket(): void
    {
        $task = $this->djTask();
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = $task;   // addPerson: tasks->find
        $this->wpdb->nextRows[] = null;    // findByEmail: nobody yet
        $created = ['id' => 40, 'email' => 'kim@example.com', 'display_name' => 'Kim'];
        $this->queueNewPersonSignup($task, $created);
        $fired = $this->recordActions();

        $result = $this->post(
            CrewAdminController::ADD_ACTION,
            ['task_ids' => ['9'], 'name' => 'Kim', 'email' => 'kim@example.com', 'counts_credit' => '0']
        );

        // Adding someone to a task of a role they lack gives them the role,
        // and they are told about it (AccessNotifier listens).
        $given = array_values(array_filter(
            (array) $fired,
            static fn (array $f): bool => \EventCrew\Support\RoleNotifier::GIVEN_HOOK === $f[0]
        ));
        self::assertSame([[40, 'dj']], array_map(static fn (array $f): array => array_slice($f[1], 0, 2), $given));

        self::assertSame('admin_add_added_mail', $result['notice']);
        $people = $this->personInserts();
        self::assertCount(1, $people);
        self::assertSame('kim@example.com', $people[0]['data']['email']);
        self::assertSame('Kim', $people[0]['data']['display_name']);
        // Given the role, then signed up with the answer "No".
        self::assertSame('dj', $this->wpdb->updates[0]['data']['role_slugs']);
        self::assertStringContainsString("SELECT 9, 40, 'signed_up', '2026-07-20 12:00:00', 0", implode("\n", $this->wpdb->queries));
        self::assertSame(['kim@example.com'], array_column($this->mails, 'to'));
    }

    public function testAnOrganizerAddsANonMemberWithNoEmailAndNothingIsSent(): void
    {
        $task = $this->djTask();
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = $task;   // addPerson: tasks->find
        $created = ['id' => 40, 'email' => 'added-abcdef123456@added.invalid', 'display_name' => 'Kim'];
        $this->queueNewPersonSignup($task, $created);

        $result = $this->post(
            CrewAdminController::ADD_ACTION,
            ['task_ids' => ['9'], 'name' => 'Kim', 'email' => '', 'counts_credit' => '1']
        );

        self::assertSame('admin_add_added', $result['notice']);
        self::assertSame('added-abcdef123456@added.invalid', $this->personInserts()[0]['data']['email']);
        self::assertStringContainsString("SELECT 9, 40, 'signed_up', '2026-07-20 12:00:00', 1", implode("\n", $this->wpdb->queries));
        self::assertSame([], $this->mails);
    }

    /**
     * Queues one organizer sign-up of an existing person on a task: the
     * service's task and person reads, the claim's two reads, then the join.
     * $inserted 0 makes the join find the task full.
     *
     * @param array<string, mixed> $task
     * @param array<string, mixed> $person
     */
    private function queueMemberSignup(array $task, array $person, int $inserted = 1): void
    {
        $this->wpdb->nextRows[] = $task;                 // addPerson: tasks->find
        $this->wpdb->nextRows[] = $person;               // addPerson: people->find
        $this->wpdb->nextRows[] = $task;                 // claim: tasks->find
        $this->wpdb->nextRows[] = $person;               // claim: people->find
        $this->wpdb->nextVars[] = (int) $task['capacity'];  // join: capacity
        $this->wpdb->nextRows[] = null;                  // join: no row yet
        $this->wpdb->nextQueryResults[] = $inserted;     // join: inserted or full
    }

    public function testAnOrganizerPicksAMemberAndTicksSeveralTasks(): void
    {
        $this->djTask();
        $this->signIn($this->organizerRow(1));
        $member = ['email' => 'added-zzz@added.invalid'] + $this->personRow(8);
        $this->queueMemberSignup($this->taskRow(11, 'decorate'), $member);
        $this->queueMemberSignup($this->taskRow(12, 'decorate'), $member);
        $this->queueMemberSignup($this->taskRow(13, 'decorate'), $member, 0);

        $result = $this->post(
            CrewAdminController::ADD_ACTION,
            ['task_ids' => ['11', '12', '13'], 'person_id' => '8', 'name' => '', 'email' => '', 'counts_credit' => '1']
        );

        self::assertSame('admin_add_many_2_3', $result['notice']);
        self::assertSame([], $this->personInserts());
        $sql = implode("\n", $this->wpdb->queries);
        self::assertStringContainsString('SELECT 11, 8,', $sql);
        self::assertStringContainsString('SELECT 12, 8,', $sql);
        self::assertSame([], $this->mails);
        self::assertSame(
            'Added to 2 of 3 tasks. The others were skipped: they were already on the task, the task was full,'
            . ' or it is no longer available. They have no email address here, so nothing was sent.',
            CrewAdminController::noticeText($result['notice'])
        );
    }

    public function testANewPersonOnSeveralTasksIsCreatedOnce(): void
    {
        $this->djTask();
        $this->signIn($this->organizerRow(1));
        $created = ['id' => 40, 'email' => 'added-abcdef123456@added.invalid', 'display_name' => 'Kim'];
        $first = $this->taskRow(11, 'decorate');
        $this->wpdb->insert_id = 40;
        $this->wpdb->nextRows[] = $first;     // addPerson: tasks->find
        $this->wpdb->nextRows[] = $created;   // people->find (new)
        $this->wpdb->nextRows[] = $first;     // claim: tasks->find
        $this->wpdb->nextRows[] = $created;   // claim: people->find
        $this->wpdb->nextVars[] = 3;          // join: capacity
        $this->wpdb->nextRows[] = null;       // join: no row yet
        $this->wpdb->nextQueryResults[] = 1;  // join: inserted
        $this->queueMemberSignup($this->taskRow(12, 'decorate'), $created);

        $result = $this->post(
            CrewAdminController::ADD_ACTION,
            ['task_ids' => ['11', '12'], 'person_id' => '0', 'name' => 'Kim', 'email' => '', 'counts_credit' => '1']
        );

        self::assertSame('admin_add_many_2_2', $result['notice']);
        self::assertCount(1, $this->personInserts());
        $sql = implode("\n", $this->wpdb->queries);
        self::assertStringContainsString('SELECT 11, 40,', $sql);
        self::assertStringContainsString('SELECT 12, 40,', $sql);
    }

    public function testAddingWithNoTaskTickedAsksForOne(): void
    {
        $this->djTask();
        $this->signIn($this->organizerRow(1));

        $result = $this->post(
            CrewAdminController::ADD_ACTION,
            ['person_id' => '8', 'name' => '', 'email' => '', 'counts_credit' => '1']
        );

        self::assertSame('admin_add_no_task', $result['notice']);
        self::assertSame('Tick at least one task.', CrewAdminController::noticeText($result['notice']));
    }

    public function testAPickedMemberWhoIsGoneIsNotFound(): void
    {
        $this->djTask();
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = $this->taskRow(11, 'decorate');   // addPerson: tasks->find
        $this->wpdb->nextRows[] = null;                             // people->find: gone

        $result = $this->post(
            CrewAdminController::ADD_ACTION,
            ['task_ids' => ['11', '12'], 'person_id' => '8', 'name' => '', 'email' => '', 'counts_credit' => '1']
        );

        self::assertSame('admin_add_no_person', $result['notice']);
        self::assertSame('That member was not found.', CrewAdminController::noticeText($result['notice']));
    }

    public function testAMemberCannotAddAPersonToARoleTask(): void
    {
        $this->djTask();
        $this->signIn($this->personRow(8));
        $this->wpdb->nextResults[] = [$this->organizerRow(1)];   // organizers() for the alert

        $result = $this->post(
            CrewAdminController::ADD_ACTION,
            ['task_ids' => ['9'], 'name' => 'Kim', 'email' => 'kim@example.com', 'counts_credit' => '1'],
            8
        );

        self::assertSame(CrewAdminController::FORGED, $result['code']);
        self::assertSame([], $this->personInserts());
        self::assertStringNotContainsString('counts_credit)', implode("\n", $this->wpdb->queries));
    }

    public function testAnOrganizerGivesAndTakesAwayARole(): void
    {
        $this->djTask();
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = $this->personRow(8);

        $result = $this->post(CrewAdminController::ROLES_ACTION, ['person_id' => '8', 'add_role' => 'dj']);

        self::assertSame('admin_roles_saved', $result['notice']);
        self::assertSame('dj', $this->wpdb->updates[0]['data']['role_slugs']);

        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = ['role_slugs' => 'dj'] + $this->personRow(8);
        $fired = $this->recordActions();

        $this->post(CrewAdminController::ROLES_ACTION, ['person_id' => '8', 'roles' => []]);

        self::assertSame('', $this->wpdb->updates[1]['data']['role_slugs']);
        // Taking a role away tells nobody.
        self::assertNotContains(\EventCrew\Support\RoleNotifier::GIVEN_HOOK, array_column((array) $fired, 0));
    }

    public function testTheRoleGivenOnTheCrewPageCarriesTheOrganizersName(): void
    {
        $this->djTask();
        $this->signIn(['display_name' => 'Owner'] + $this->organizerRow(1));
        $this->wpdb->nextRows[] = $this->personRow(8);

        $fired = [];
        Functions\when('do_action')->alias(static function (string $hook, mixed ...$args) use (&$fired): void {
            $fired[] = [$hook, $args];
        });

        $this->post(CrewAdminController::ROLES_ACTION, ['person_id' => '8', 'add_role' => 'dj']);

        self::assertContains([\EventCrew\Support\RoleNotifier::GIVEN_HOOK, [8, 'dj', 'Owner']], $fired);
    }

    // --- a forged request from a signed-in member -------------------------

    public function testAMemberSendingAnAdminRequestIsBlockedAndOrganizersAreTold(): void
    {
        $this->signIn($this->personRow(8));
        // organizers(): one with a chat and a verified email, one with neither.
        $this->wpdb->nextResults[] = [
            $this->organizerRow(1),
            ['telegram_chat_id' => null, 'telegram_user_id' => null] + $this->personRow(2, true),
        ];

        // Their own valid token: still forged, they are not an organizer.
        $result = $this->post(
            CrewAdminController::GIFT_ACTION,
            ['person_id' => '8', 'credits' => '5'],
            8
        );

        self::assertSame(CrewAdminController::FORGED, $result['code']);
        // The action did not run.
        self::assertSame(0, $this->creditInserts());

        // The block: banned, and every web session signed out.
        $bans = $this->banWrites();
        self::assertCount(1, $bans);
        self::assertSame(['id' => 8], $bans[0]['where']);
        self::assertSame('2026-07-20 12:00:00', $bans[0]['data']['banned_at']);
        self::assertSame('2026-07-20 12:00:00', $bans[0]['data']['sessions_valid_from']);

        // Organizer 1 by Telegram: who, when, which action, from where.
        $dm = $this->lastCallTo('sendMessage');
        self::assertSame(501, $dm['chat_id']);
        self::assertSame(['sendMessage'], $this->calledMethods());
        foreach (['Person 8', 'p8@example.com', '2026-07-20 12:00:00', 'Give a credit', self::IP] as $detail) {
            self::assertStringContainsString($detail, $dm['text']);
        }

        // And by email; organizer 2 has no verified address, so gets none.
        self::assertCount(1, $this->mails);
        self::assertSame('p1@example.com', $this->mails[0]['to']);
        self::assertStringContainsString(self::IP, $this->mails[0]['body']);
    }

    public function testTheForgedRequestAlertIsTheBuiltInTextWhenNothingIsStored(): void
    {
        $this->options[EmailTemplate::HTML_OPTION] = false;
        $this->signIn($this->personRow(8));
        $this->wpdb->nextResults[] = [$this->organizerRow(1)];

        $this->post(CrewAdminController::GIFT_ACTION, ['person_id' => '8', 'credits' => '5'], 8);

        $text = "A member who is not an organizer sent an admin request from the Crew app. The request was not carried out and their account is now blocked: they cannot sign up for tasks or sign in on the web.\n\nWho: Person 8 (p8@example.com)\nWhen: 2026-07-20 12:00:00\nAction: Give a credit\nIP address: " . self::IP . "\n\nTo lift the block, open EventCrew > People in wp-admin, edit the person and press Unban.";
        self::assertSame($text, $this->lastCallTo('sendMessage')['text']);
        self::assertSame('Crew app: a member is blocked after a forged admin request', $this->mails[0]['subject']);
        self::assertStringStartsWith($text . "\n\n", $this->mails[0]['body']);
    }

    public function testTheForgedRequestAlertUsesAStoredText(): void
    {
        $this->options[EmailTemplate::HTML_OPTION] = false;
        $this->options[MessageTemplates::OPTION_PREFIX . 'forged_request'] = [
            MessageTemplates::SUBJECT => ['source' => 'Blocked: {name}'],
            MessageTemplates::BODY => ['source' => '{name} ({email}) tried {action} at {when} from {ip}.'],
        ];
        $this->options[MessageTemplates::OPTION_PREFIX . 'forged_request_dm'] = [
            MessageTemplates::BODY => ['source' => 'Blocked {name}.'],
        ];
        $this->signIn($this->personRow(8));
        $this->wpdb->nextResults[] = [$this->organizerRow(1)];

        $this->post(CrewAdminController::GIFT_ACTION, ['person_id' => '8', 'credits' => '5'], 8);

        self::assertSame('Blocked Person 8.', $this->lastCallTo('sendMessage')['text']);
        self::assertSame('Blocked: Person 8', $this->mails[0]['subject']);
        self::assertStringStartsWith(
            'Person 8 (p8@example.com) tried Give a credit at 2026-07-20 12:00:00 from ' . self::IP . ".\n\n",
            $this->mails[0]['body']
        );
    }

    public function testAMemberWithABadTokenIsStillBlocked(): void
    {
        $this->signIn($this->personRow(8));
        $this->wpdb->nextResults[] = [$this->organizerRow(1)];

        $result = $this->admin()->handle(CrewAdminController::BOARD_ACTION, 'POST', ['csrf' => 'forged'], self::IP);

        self::assertSame(CrewAdminController::FORGED, $result['code']);
        self::assertCount(1, $this->banWrites());
        self::assertSame(501, $this->lastCallTo('sendMessage')['chat_id']);
    }

    /**
     * A link somebody planted on another site still carries the SameSite=Lax
     * cookie on a GET; acting on it would let anyone get a member blocked.
     */
    public function testAGetRequestIsRefusedWithoutABan(): void
    {
        $this->signIn($this->personRow(8));

        $result = $this->admin()->handle(CrewAdminController::BOARD_ACTION, 'GET', [], self::IP);

        self::assertSame(CrewAdminController::REFUSED, $result['code']);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->telegramCalls);
    }

    // --- no session ---------------------------------------------------------

    public function testNoSessionIsRefusedWithNoBanAndNoAlert(): void
    {
        foreach (array_keys(CrewAdminController::actionLabels()) as $action) {
            $result = $this->admin()->handle(
                $action,
                'POST',
                ['csrf' => WebSession::csrfToken(1), 'person_id' => '8', 'credits' => '1', 'assignment_id' => '30', 'mark' => 'arrived'],
                self::IP
            );

            self::assertSame(CrewAdminController::REFUSED, $result['code'], $action);
        }

        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->telegramCalls);
        self::assertSame([], $this->mails);
    }

    public function testAnOrganizerWithABadTokenIsRefusedAndNotBlocked(): void
    {
        $this->signIn($this->organizerRow(1));

        $result = $this->admin()->handle(CrewAdminController::BOARD_ACTION, 'POST', ['csrf' => 'stale'], self::IP);

        self::assertSame(CrewAdminController::REFUSED, $result['code']);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->telegramCalls);
    }

    // --- a blocked account --------------------------------------------------

    public function testABlockedPersonCannotSignUp(): void
    {
        $this->wpdb->nextRows[] = $this->taskRow(6, 'decorate');   // tasks->find
        $this->wpdb->nextRows[] = $this->bannedRow(8);              // people->find

        self::assertSame(SignupService::GATED, $this->signup()->claim(8, 6));
        self::assertSame([], $this->wpdb->inserts);
    }

    public function testABlockedPersonsSessionCountsAsSignedOut(): void
    {
        $this->signIn($this->bannedRow(8));

        self::assertNull((new SessionPerson(new PersonRepository()))->current());
    }

    public function testABlockedPersonGetsNoSignInLink(): void
    {
        // The sign-in form's rate limit counts in transients.
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        $this->wpdb->nextRows[] = $this->bannedRow(8);   // findByEmail

        // The same answer as for any address, so the form does not tell which
        // addresses are blocked; but no link is made or mailed.
        self::assertSame('check_email', $this->signupController()->loginByEmail('p8@example.com'));
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->mails);
        self::assertSame('Your account is blocked. Talk to an organizer.', SignupController::noticeText('blocked'));
    }

    public function testASignInLinkMailedBeforeTheBlockStartsNoSession(): void
    {
        $this->wpdb->nextRows[] = ['id' => 1, 'person_id' => 8, 'expires_at' => '2026-12-31 00:00:00', 'used_at' => null];
        $this->wpdb->nextQueryResults[] = 1; // the conditional UPDATE that spends the link
        $this->wpdb->nextRows[] = $this->bannedRow(8);

        $outcome = $this->signupController()->magicLinkSignIn('rawtoken');

        self::assertSame(['person_id' => null, 'notice' => 'blocked'], $outcome);
        // The token is spent, but the email is not marked verified.
        foreach ($this->wpdb->updates as $update) {
            self::assertArrayNotHasKey('email_verified_at', $update['data']);
        }
    }

    public function testUnbanOnThePeoplePageLiftsTheBlock(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_admin_referer')->justReturn(1);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('wp_safe_redirect')->alias(static function (): void {
            throw new \RuntimeException('redirected');
        });
        $_POST = ['person_id' => '8'];
        $this->wpdb->nextRows[] = $this->bannedRow(8);

        try {
            $this->peoplePage()->unban();
            self::fail('Expected the redirect.');
        } catch (\RuntimeException $e) {
            self::assertSame('redirected', $e->getMessage());
        } finally {
            $_POST = [];
        }

        $bans = $this->banWrites();
        self::assertCount(1, $bans);
        self::assertSame(['id' => 8], $bans[0]['where']);
        self::assertNull($bans[0]['data']['banned_at']);
        self::assertNull($bans[0]['data']['ban_reason']);

        // Unbanned, the same row signs in and may sign up again.
        $this->signIn(['banned_at' => null] + $this->personRow(8));
        self::assertInstanceOf(Person::class, (new SessionPerson(new PersonRepository()))->current());
    }

    public function testUnbanNeedsThePeoplePagePermission(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('wp_die')->alias(static function (): void {
            throw new \RuntimeException('denied');
        });
        $_POST = ['person_id' => '8'];

        try {
            $this->peoplePage()->unban();
            self::fail('Expected wp_die.');
        } catch (\RuntimeException $e) {
            self::assertSame('denied', $e->getMessage());
        } finally {
            $_POST = [];
        }

        self::assertSame([], $this->wpdb->updates);
    }

    // --- the tab itself -----------------------------------------------------

    public function testTheAdminViewIsOnlyForOrganizers(): void
    {
        self::assertNull($this->admin()->viewFor(null));
        self::assertNull($this->admin()->viewFor(Person::fromRow($this->personRow(8))));
        self::assertNull($this->signupController()->adminView(Person::fromRow($this->personRow(8))));

        $this->wpdb->nextResults[] = [$this->personRow(8), $this->organizerRow(1)];   // members
        $this->wpdb->nextCols[] = [self::NIGHT];                                       // dates with tasks
        $this->wpdb->nextResults[] = [$this->taskRow(6, 'decorate')];                  // roster tasks
        $this->wpdb->nextResults[] = [$this->assignmentRow(30, 6, 8, 'signed_up')];    // its people
        $this->wpdb->nextRows[] = $this->personRow(8);

        $view = $this->admin()->viewFor(Person::fromRow($this->organizerRow(1)));

        self::assertNotNull($view);
        self::assertSame([8 => 'Person 8 (p8@example.com)', 1 => 'Person 1 (p1@example.com)'], $view['members']);
        self::assertSame(30, $view['roster'][0]['people'][0]['assignment_id']);
        self::assertTrue($view['roster'][0]['people'][0]['markable']);
        self::assertSame('', $view['roster'][0]['people'][0]['marked_by']);
    }

    public function testTheAdminRosterSaysWhoMarkedEachPersonAndWhen(): void
    {
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('UTC'));

        $this->wpdb->nextResults[] = [];                                // members
        $this->wpdb->nextCols[] = [self::NIGHT];                        // dates with tasks
        $this->wpdb->nextResults[] = [$this->taskRow(6, 'decorate')];   // roster tasks
        $this->wpdb->nextResults[] = [[                                 // its people
            'status_changed_at' => self::NIGHT . ' 21:42:00',
            'changed_by' => 1,
            'changed_by_kind' => MarkedBy::PERSON,
        ] + $this->assignmentRow(30, 6, 8, 'arrived')];
        $this->wpdb->nextRows[] = $this->personRow(8);                  // the crew member
        $this->wpdb->nextRows[] = $this->organizerRow(1);               // who marked them

        $view = $this->admin()->viewFor(Person::fromRow($this->organizerRow(1)));

        self::assertNotNull($view);
        self::assertSame('marked by Person 1 at 21:42', $view['roster'][0]['people'][0]['marked_by']);
    }

    public function testTheTabIsNotInThePageForAMember(): void
    {
        $html = $this->renderAs($this->personRow(8));

        self::assertStringContainsString('id="eventcrew-panel-settings"', $html);
        self::assertStringNotContainsString('eventcrew-panel-admin', $html);
        self::assertStringNotContainsString('eventcrew-tab-admin', $html);
        self::assertStringNotContainsString(CrewAdminController::GIFT_ACTION, $html);
    }

    /**
     * The tab bar is the first thing on the page: above the Telegram and
     * guide buttons and above the "Signed in as" card.
     */
    public function testTheTabBarComesFirstOnThePage(): void
    {
        $html = $this->renderAs($this->personRow(8));

        $nav = strpos($html, '<nav class="eventcrew-tabs"');
        $card = strpos($html, 'eventcrew-header-card');

        self::assertNotFalse($nav);
        self::assertNotFalse($card);
        self::assertLessThan($card, $nav);
    }

    public function testTheTabIsInThePageForAnOrganizer(): void
    {
        $html = $this->renderAs($this->organizerRow(1));

        self::assertStringContainsString('id="eventcrew-tab-admin"', $html);
        self::assertStringContainsString('id="eventcrew-panel-admin"', $html);
        self::assertStringContainsString(CrewAdminController::PERSON_ACTION, $html);
        self::assertStringContainsString('Send me the link', $html);
    }

    public function testTheRosterScreenShowsEveryCardForTheDate(): void
    {
        // Dates with tasks: read once for the sales desk night, then by the picker.
        $this->wpdb->nextCols[] = ['2026-07-27', self::NIGHT, '2026-07-13'];
        $this->wpdb->nextCols[] = ['2026-07-27', self::NIGHT, '2026-07-13'];

        $html = $this->renderAs($this->organizerRow(1));

        self::assertStringContainsString('name="roster_date"', $html);
        self::assertMatchesRegularExpression('/<option value="2026-07-20" selected>/', $html);
        self::assertStringContainsString('<option value="2026-07-13"', $html);
        foreach (
            [
                CrewAdminController::DOOR_ACTION,
                CrewAdminController::GUEST_ACTION,
                CrewAdminController::BUYERS_ACTION,
                CrewAdminController::MAIL_ACTION,
            ] as $action
        ) {
            self::assertStringContainsString('value="' . $action . '"', $html, $action);
        }
        self::assertStringContainsString('Reset import', $html);
        self::assertStringContainsString('Check the mailbox now', $html);
    }

    /**
     * The whole crew page for an organizer, Admin tab included, renders
     * through to its last line: a fatal error or an exception anywhere in the
     * Admin part of templates/public/signup.php fails this test instead of
     * reaching the live page. The organizer twin of SignupControllerTest's
     * testTheWholePageRendersForASignedInPerson.
     */
    public function testTheWholePageRendersForAnOrganizer(): void
    {
        // Dates with tasks: read once for the sales desk night, then by the picker.
        $this->wpdb->nextCols[] = [self::NIGHT];
        $this->wpdb->nextCols[] = [self::NIGHT];

        $html = $this->renderAs($this->organizerRow(1));

        self::assertStringContainsString('id="eventcrew-tab-admin"', $html);
        self::assertStringContainsString('id="eventcrew-panel-admin"', $html);
        self::assertStringContainsString('<nav class="eventcrew-subtabs"', $html);
        foreach (['roster', 'tasks', 'door', 'people', 'telegram', 'followup'] as $screen) {
            self::assertStringContainsString('data-eventcrew-subpanel="' . $screen . '"', $html, $screen);
        }
        foreach (
            [
                CrewAdminController::BOARD_ACTION,
                CrewAdminController::PERSON_ACTION,
                CrewAdminController::DOOR_ACTION,
                CrewAdminController::GUEST_ACTION,
                CrewAdminController::SALES_ACTION,
            ] as $action
        ) {
            self::assertStringContainsString('value="' . $action . '"', $html, $action);
        }
        // The template's last line came out: nothing stopped it half-way.
        self::assertStringEndsWith('</script>', rtrim($html));
    }

    /**
     * The Door list, Guest list and Holvi ticket buyers cards are on the Door
     * screen, after the Sales desk and the date bar, and every form there
     * sends the page back to the Door screen. The Roster screen has the crew
     * only.
     */
    public function testTheDoorCardsAreOnTheDoorScreenNotTheRosterScreen(): void
    {
        $this->wpdb->nextCols[] = ['2026-07-27', self::NIGHT, '2026-07-13'];
        $this->wpdb->nextCols[] = ['2026-07-27', self::NIGHT, '2026-07-13'];

        $html = $this->renderAs($this->organizerRow(1));
        $door = $this->subpanel($html, 'door');
        $roster = $this->subpanel($html, 'roster');

        foreach (['Door list', 'Guest list', 'Holvi ticket buyers'] as $card) {
            self::assertStringContainsString('>' . $card . '</h3>', $door, $card);
            self::assertStringNotContainsString('>' . $card . '</h3>', $roster, $card);
        }
        foreach ([CrewAdminController::DOOR_ACTION, CrewAdminController::GUEST_ACTION, CrewAdminController::BUYERS_ACTION] as $action) {
            self::assertStringContainsString('value="' . $action . '"', $door, $action);
            self::assertStringNotContainsString('value="' . $action . '"', $roster, $action);
        }

        // Sales desk first, then the date bar, then the cards.
        $sales = strpos($door, 'Sales desk');
        $bar = strpos($door, 'eventcrew-admin-datebar');
        $list = strpos($door, 'Door list');
        self::assertNotFalse($sales);
        self::assertNotFalse($bar);
        self::assertNotFalse($list);
        self::assertLessThan($bar, $sales);
        self::assertLessThan($list, $bar);

        self::assertStringNotContainsString('#eventcrew-admin-roster"', $door);
        self::assertMatchesRegularExpression(
            '/name="redirect_to" value="[^"]*\?roster_date=2026-07-20#eventcrew-admin-door"/',
            $door
        );
        self::assertStringNotContainsString('Add a person to a task', $html);
        self::assertStringNotContainsString('Crew and attendance', $html);
    }

    /**
     * One date bar on each of the Roster and Door screens: "‹ date ›", the
     * arrows to the dates with tasks just before and just after, each back
     * to its own screen.
     */
    public function testTheDateBarHasArrowsToThePreviousAndNextDate(): void
    {
        $this->wpdb->nextCols[] = ['2026-07-27', self::NIGHT, '2026-07-13'];
        $this->wpdb->nextCols[] = ['2026-07-27', self::NIGHT, '2026-07-13'];

        $html = $this->renderAs($this->organizerRow(1));

        foreach (['roster', 'door'] as $screen) {
            $panel = $this->subpanel($html, $screen);

            self::assertSame(1, substr_count($panel, '<form class="eventcrew-admin-datebar"'), $screen);
            self::assertStringContainsString('id="eventcrew-admin-' . $screen . '-date" name="roster_date"', $panel);
            self::assertMatchesRegularExpression(
                '/data-eventcrew-date-prev\s+href="[^"]*\?roster_date=2026-07-13#eventcrew-admin-' . $screen . '"/',
                $panel
            );
            self::assertMatchesRegularExpression(
                '/data-eventcrew-date-next\s+href="[^"]*\?roster_date=2026-07-27#eventcrew-admin-' . $screen . '"/',
                $panel
            );
            self::assertMatchesRegularExpression('/<option value="2026-07-20" selected>/', $panel);
        }
    }

    public function testTheDateBarHasNoArrowWhereThereIsNoOtherDate(): void
    {
        $this->wpdb->nextCols[] = [self::NIGHT];
        $this->wpdb->nextCols[] = [self::NIGHT];

        $html = $this->renderAs($this->organizerRow(1));

        self::assertStringContainsString('<form class="eventcrew-admin-datebar"', $html);
        self::assertStringNotContainsString('data-eventcrew-date-prev', $html);
        self::assertStringNotContainsString('data-eventcrew-date-next', $html);
    }

    /**
     * Each person on the roster: name, when they signed up, and one status
     * menu that saves on change. No Arrived / Completed / No-show buttons per
     * person and no "Update attendance"; "Mark all" stays under each task.
     */
    public function testTheRosterLineShowsTheSignUpTimeAndOneStatusMenu(): void
    {
        // No wp_date stub: once defined it outlives the test, and the
        // gmdate fallback gives the same text. The task is also upcoming, so the public board shows it too.
        Functions\when('date_i18n')->alias(static fn (string $format, int $timestamp): string => gmdate($format, $timestamp));

        $html = $this->renderAs($this->organizerRow(1), function (): void {
            $this->routeRosterReads(
                $this->taskRow(6, 'decorate', '19:00', '22:00'),
                ['signed_up_at' => '2026-07-02 14:05:00'] + $this->assignmentRow(30, 6, 8, 'signed_up')
            );
        });
        $roster = $this->subpanel($html, 'roster');

        self::assertStringContainsString('Person 8', $roster);
        self::assertStringContainsString('signed up 2 Jul 14:05', $roster);
        self::assertMatchesRegularExpression('/<h4 class="eventcrew-admin-task">[^<]* · 19:00–22:00 · 1 of 3<\/h4>/u', $roster);
        self::assertStringContainsString('name="status[30]" form="eventcrew-admin-attend-6"', $roster);
        self::assertStringContainsString('requestSubmit', $roster);
        self::assertStringContainsString('<noscript><button type="submit" form="eventcrew-admin-attend-6"', $roster);
        self::assertStringNotContainsString('name="mark"', $roster);
        self::assertStringNotContainsString('value="' . CrewAdminController::MARK_ACTION . '"', $roster);
        self::assertStringNotContainsString('>No-show</button>', $roster);
        self::assertStringNotContainsString('Update attendance', $roster);
        self::assertStringContainsString('Mark all arrived', $roster);
        self::assertStringContainsString('Mark all completed', $roster);
        // "+ Add a person" is a text link under the crew that opens the form.
        self::assertMatchesRegularExpression(
            '/<details class="eventcrew-admin-add">\s*<summary class="eventcrew-textlink">\+ Add a person<\/summary>/',
            $roster
        );
        self::assertLessThan(
            strpos($roster, '<details class="eventcrew-admin-add">'),
            strpos($roster, 'Mark all completed')
        );
        self::assertStringContainsString('value="' . CrewAdminController::ADD_ACTION . '"', $roster);
    }

    public function testThePeopleScreenHasTheListAndTheAddForm(): void
    {
        $html = $this->renderAs($this->organizerRow(1));

        self::assertStringContainsString('name="people_q"', $html);
        self::assertStringContainsString('value="' . CrewAdminController::PERSON_ACTION . '"', $html);
        self::assertStringContainsString('Add a person', $html);
        self::assertStringNotContainsString('Edit person', $html);
        self::assertStringNotContainsString('name="is_organizer"', $html);
        // The older "Give a credit" and "Roles" cards are gone: opening a
        // person does both.
        self::assertStringNotContainsString('value="' . CrewAdminController::GIFT_ACTION . '"', $html);
        self::assertStringNotContainsString('value="' . CrewAdminController::ROLES_ACTION . '"', $html);
        self::assertStringNotContainsString('Give the role', $html);
    }

    public function testThePeopleScreenOpensAPersonForEditing(): void
    {
        Functions\when('absint')->alias(static fn (mixed $n): int => abs((int) $n));
        Functions\when('wp_unslash')->returnArg();
        $_GET =['person_id' => '8', 'people_q' => 'p8'];

        try {
            $html = $this->renderAs($this->organizerRow(1), function (): void {
                // The page reads the signed-in organizer a second time first.
                $this->wpdb->nextRows[] = $this->organizerRow(1);
                $this->wpdb->nextRows[] = ['at_risk_pass' => 1] + $this->bannedRow(8);
            });
        } finally {
            $_GET = [];
        }

        self::assertStringContainsString('Edit person', $html);
        self::assertStringContainsString('value="p8@example.com"', $html);
        self::assertStringContainsString('value="' . CrewAdminController::PASS_ACTION . '"', $html);
        self::assertStringContainsString('Pass already granted — re-grant', $html);
        self::assertStringContainsString('value="' . CrewAdminController::UNBAN_ACTION . '"', $html);
        self::assertStringContainsString('Credit log', $html);
        self::assertMatchesRegularExpression('/name="credits" min="1" step="1" value="1" required>/', $html);
        self::assertStringNotContainsString('max="5"', $html);
        self::assertStringNotContainsString('name="is_organizer"', $html);
        // Every form sends the page back to this person.
        // (The add_query_arg stub keeps a false arg as 0; WordPress drops it.)
        self::assertMatchesRegularExpression(
            '/name="redirect_to" value="[^"]*\?person_id=8&amp;people_q=p8[^"]*#eventcrew-admin-people"/',
            $html
        );
    }

    public function testWithNoTasksTheRosterScreenHasNoDateCards(): void
    {
        $html = $this->renderAs($this->organizerRow(1));

        self::assertStringContainsString('nothing to take a roster for', $html);
        self::assertStringNotContainsString('value="' . CrewAdminController::DOOR_ACTION . '"', $html);
        self::assertStringNotContainsString('value="' . CrewAdminController::GUEST_ACTION . '"', $html);
    }

    // --- the Tasks screen: tasks, template, open-task email, roles ----------

    /**
     * One request of each Tasks screen action that would write or send if it
     * ran.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function tasksScreenRequests(): array
    {
        $task = ['task_date' => self::NIGHT, 'role_slug' => 'decorate', 'capacity' => '2', 'starts_at' => '20:00'];

        return [
            'add task' => [CrewAdminController::TASK_ACTION, $task],
            'edit task' => [CrewAdminController::TASK_ACTION, ['task_id' => '6'] + $task],
            'delete task' => [CrewAdminController::TASK_DELETE_ACTION, ['task_id' => '6']],
            'create tasks' => [CrewAdminController::TEMPLATE_ACTION, ['template_event' => '70']],
            'open-task email' => [CrewAdminController::OPEN_TASK_ACTION, []],
            'edit role' => [CrewAdminController::ROLE_ACTION, ['role' => 'decorate', 'role_label' => 'Changed']],
        ];
    }

    /**
     * Every write the fake wpdb saw apart from the ban itself.
     *
     * @return array<string, mixed>
     */
    private function tasksScreenWrites(): array
    {
        $banOnly = static fn (array $u): bool => ! array_key_exists('banned_at', $u['data']);

        return [
            'inserts' => $this->wpdb->inserts,
            'updates' => array_values(array_filter($this->wpdb->updates, $banOnly)),
            'deletes' => $this->wpdb->deletes,
            'statements' => array_values(array_filter(
                $this->wpdb->queries,
                static fn (string $sql): bool => 1 === preg_match('/^\s*(INSERT|UPDATE|DELETE)\s/i', $sql)
            )),
        ];
    }

    /** Queues one open date for the open-task email: its task, then its occupancy. */
    private function queueOpenTaskDate(): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow(6, 'decorate', '19:00', '21:00')];
        $this->wpdb->nextResults[] = [];
    }

    /** Records every do_action call, by hook name. */
    private function recordHooks(): \ArrayObject
    {
        $hooks = new \ArrayObject();
        Functions\when('do_action')->alias(static function (string $hook) use ($hooks): void {
            $hooks[] = $hook;
        });

        return $hooks;
    }

    /**
     * @param array<string, mixed> $post
     * @dataProvider tasksScreenRequests
     */
    public function testAMemberSendingATasksScreenRequestIsBlockedAndNothingIsWritten(string $action, array $post): void
    {
        $this->signIn($this->personRow(8));
        $this->wpdb->nextResults[] = [$this->organizerRow(1)];   // organizers() for the alert
        // What each action would read if it ran: task 6, an event, a date with
        // an open task and someone to mail. Nothing may read them.
        $this->stubEvent(70);
        $this->wpdb->nextRows[] = $this->taskRow(6, 'decorate');
        $this->wpdb->nextCols[] = [self::NIGHT];
        $this->queueOpenTaskDate();
        $this->queueOpenTaskDate();
        $this->wpdb->nextResults[] = [['id' => 7, 'email' => 'sam@example.com', 'email_verified_at' => '2026-07-01 00:00:00']];

        $result = $this->post($action, $post, 8);

        self::assertSame(CrewAdminController::FORGED, $result['code']);
        self::assertCount(1, $this->banWrites());
        self::assertSame(501, $this->lastCallTo('sendMessage')['chat_id']);
        self::assertSame(['inserts' => [], 'updates' => [], 'deletes' => [], 'statements' => []], $this->tasksScreenWrites());
        // The block itself writes a log line; the roles are not touched.
        self::assertArrayNotHasKey(Roles::OPTION_NAME, $this->options);
        self::assertArrayNotHasKey(OrganizeTaskBackfill::PENDING_OPTION, $this->options);
        // The only mail is the alert to the organizer; no open-task email.
        self::assertSame(['p1@example.com'], array_column($this->mails, 'to'));
        self::assertStringContainsString('blocked', $this->mails[0]['subject']);
    }

    /**
     * @param array<string, mixed> $post
     * @dataProvider tasksScreenRequests
     */
    public function testATasksScreenGetRequestIsRefusedAndNothingIsWritten(string $action, array $post): void
    {
        $this->signIn($this->organizerRow(1));
        $options = $this->options;

        $result = $this->admin()->handle($action, 'GET', $post + ['csrf' => WebSession::csrfToken(1)], self::IP);

        self::assertSame(CrewAdminController::REFUSED, $result['code']);
        self::assertSame([], $this->banWrites());
        self::assertSame([], $this->telegramCalls);
        self::assertSame(['inserts' => [], 'updates' => [], 'deletes' => [], 'statements' => []], $this->tasksScreenWrites());
        self::assertSame($options, $this->options);
        self::assertSame([], $this->mails);
    }

    public function testATaskWithADateThatIsNotARealDayIsNotSaved(): void
    {
        foreach (['2026-02-30', '', '20-07-2026', '2026-07-20 OR 1=1'] as $date) {
            $this->signIn($this->organizerRow(1));

            $result = $this->post(CrewAdminController::TASK_ACTION, ['task_date' => $date, 'role_slug' => 'decorate']);

            self::assertSame('admin_task_bad_date', $result['notice'], $date);
        }

        self::assertSame(['inserts' => [], 'updates' => [], 'deletes' => [], 'statements' => []], $this->tasksScreenWrites());
        self::assertSame(
            'That date could not be read. Use the date picker and try again.',
            CrewAdminController::noticeText('admin_task_bad_date')
        );
    }

    public function testATaskWithARoleThatDoesNotExistIsNotSaved(): void
    {
        $this->signIn($this->organizerRow(1));

        $result = $this->post(CrewAdminController::TASK_ACTION, ['task_date' => self::NIGHT, 'role_slug' => 'juggle']);

        self::assertSame('admin_task_bad_role', $result['notice']);
        self::assertSame(['inserts' => [], 'updates' => [], 'deletes' => [], 'statements' => []], $this->tasksScreenWrites());
        self::assertSame('Pick a role that still exists in Settings.', CrewAdminController::noticeText('admin_task_bad_role'));
    }

    /** Q229: a task without a start time, or with one that cannot be read, is not saved. */
    public function testATaskWithoutAStartTimeIsNotSaved(): void
    {
        foreach (['', '25:00', 'soon'] as $start) {
            $this->signIn($this->organizerRow(1));

            $result = $this->post(CrewAdminController::TASK_ACTION, [
                'task_date' => self::NIGHT,
                'role_slug' => 'decorate',
                'starts_at' => $start,
                'ends_at' => '23:00',
            ]);

            self::assertSame('admin_task_no_start', $result['notice'], $start);
        }

        // An existing task is not saved without one either.
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = $this->taskRow(6, 'decorate');
        $edit = $this->post(CrewAdminController::TASK_ACTION, ['task_id' => '6', 'task_date' => self::NIGHT, 'role_slug' => 'decorate']);

        self::assertSame('admin_task_no_start', $edit['notice']);
        self::assertSame(['inserts' => [], 'updates' => [], 'deletes' => [], 'statements' => []], $this->tasksScreenWrites());
        self::assertSame(
            'Set a start time. A task cannot be saved without one.',
            CrewAdminController::noticeText('admin_task_no_start')
        );
    }

    public function testEditingOrDeletingATaskThatIsGoneChangesNothing(): void
    {
        $hooks = $this->recordHooks();

        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = null;   // task 6: not found
        $edit = $this->post(CrewAdminController::TASK_ACTION, ['task_id' => '6', 'task_date' => self::NIGHT, 'role_slug' => 'decorate']);

        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = null;
        $delete = $this->post(CrewAdminController::TASK_DELETE_ACTION, ['task_id' => '6']);

        $this->signIn($this->organizerRow(1));
        $none = $this->post(CrewAdminController::TASK_DELETE_ACTION, []);

        self::assertSame('admin_task_gone', $edit['notice']);
        self::assertSame('admin_task_gone', $delete['notice']);
        self::assertSame('admin_task_gone', $none['notice']);
        self::assertSame(['inserts' => [], 'updates' => [], 'deletes' => [], 'statements' => []], $this->tasksScreenWrites());
        self::assertSame([], $hooks->getArrayCopy());
        self::assertSame('That task was not found.', CrewAdminController::noticeText('admin_task_gone'));
    }

    public function testAnOrganizerAddsATaskThatEndsAfterMidnight(): void
    {
        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'decorate', 'label' => 'Decorate', 'plus_ones' => 2],
            ['slug' => 'clean', 'label' => 'Clean'],
        ];
        $hooks = $this->recordHooks();
        $this->signIn($this->organizerRow(1));

        $result = $this->post(CrewAdminController::TASK_ACTION, [
            'task_date' => self::NIGHT,
            'role_slug' => 'decorate',
            'starts_at' => self::NIGHT . 'T22:00',
            'ends_at' => self::NIGHT . 'T01:00',
            'event_choice' => CrewAdminTasks::EVENT_OTHER,
            'event_label' => 'Summer party',
            'capacity' => '4',
            'plus_ones' => '',
            'notes' => 'Bring tape',
        ]);

        self::assertSame('admin_task_added', $result['notice']);
        self::assertCount(1, $this->wpdb->inserts);
        $data = $this->wpdb->inserts[0]['data'];
        self::assertSame('decorate', $data['role_slug']);
        self::assertSame(self::NIGHT, $data['task_date']);
        self::assertSame(self::NIGHT . ' 22:00:00', $data['starts_at']);
        self::assertSame('2026-07-21 01:00:00', $data['ends_at']);
        self::assertSame('Summer party', $data['event_label']);
        self::assertNull($data['event_post_id']);
        self::assertSame(4, $data['capacity']);
        self::assertSame(2, $data['plus_ones']);   // the role's default
        self::assertSame('Bring tape', $data['notes']);
        self::assertSame(['eventcrew/board_stale'], $hooks->getArrayCopy());
        self::assertSame('Task added.', CrewAdminController::noticeText($result['notice']));
    }

    public function testAnOrganizerEditsATask(): void
    {
        $hooks = $this->recordHooks();

        // A blank +1 field leaves the task's own +1s as they are.
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = $this->taskRow(6, 'decorate');
        $blank = $this->post(CrewAdminController::TASK_ACTION, [
            'task_id' => '6',
            'task_date' => self::NIGHT,
            'role_slug' => 'clean',
            'starts_at' => '23:00',
            'capacity' => '-3',
            'plus_ones' => '',
        ]);

        // A number is clamped to 0-20.
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = $this->taskRow(6, 'decorate');
        $clamped = $this->post(CrewAdminController::TASK_ACTION, [
            'task_id' => '6',
            'task_date' => self::NIGHT,
            'role_slug' => 'clean',
            'starts_at' => '23:00',
            'plus_ones' => '99',
        ]);

        self::assertSame('admin_task_updated', $blank['notice']);
        self::assertSame('admin_task_updated', $clamped['notice']);
        self::assertSame([], $this->wpdb->inserts);
        self::assertCount(2, $this->wpdb->updates);
        self::assertSame(['id' => 6], $this->wpdb->updates[0]['where']);
        self::assertSame('clean', $this->wpdb->updates[0]['data']['role_slug']);
        self::assertSame(self::NIGHT . ' 23:00:00', $this->wpdb->updates[0]['data']['starts_at']);
        self::assertNull($this->wpdb->updates[0]['data']['ends_at']);
        self::assertSame(0, $this->wpdb->updates[0]['data']['capacity']);
        self::assertArrayNotHasKey('plus_ones', $this->wpdb->updates[0]['data']);
        self::assertSame(20, $this->wpdb->updates[1]['data']['plus_ones']);
        self::assertSame(['eventcrew/board_stale', 'eventcrew/board_stale'], $hooks->getArrayCopy());
        self::assertSame('Task updated.', CrewAdminController::noticeText('admin_task_updated'));
    }

    /**
     * The emails to the people taken off are sent by TaskCancelledNotice, on
     * the eventcrew/task_deleted action; the controller itself sends nothing.
     */
    public function testDeletingATaskWithPeopleOnItTakesThemOffAndAnnouncesIt(): void
    {
        $hooks = $this->recordHooks();
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextRows[] = $this->taskRow(6, 'decorate');
        $this->wpdb->nextCols[] = ['8'];   // the people still holding a place

        $result = $this->post(CrewAdminController::TASK_DELETE_ACTION, ['task_id' => '6']);

        self::assertSame('admin_task_deleted', $result['notice']);
        $writes = $this->tasksScreenWrites()['statements'];
        self::assertCount(2, $writes);
        self::assertStringContainsString("status = 'cancelled'", $writes[0]);
        self::assertStringContainsString('task_id = 6', $writes[0]);
        self::assertStringContainsString('deleted_at', $writes[1]);
        self::assertStringContainsString('id = 6', $writes[1]);
        self::assertSame([], $this->telegramCalls);
        self::assertSame([], $this->mails);
        self::assertSame(['eventcrew/task_deleted', 'eventcrew/board_stale'], $hooks->getArrayCopy());
        self::assertSame('Task deleted.', CrewAdminController::noticeText($result['notice']));
    }

    public function testCreatingTasksForAnEventWithNoStartCreatesNothing(): void
    {
        $this->signIn($this->organizerRow(1));

        $result = $this->post(CrewAdminController::TEMPLATE_ACTION, ['template_event' => '70']);

        self::assertSame('admin_template_no_start', $result['notice']);
        self::assertSame([], $this->wpdb->inserts);
    }

    /** EventSource::describe() reads these; see TaskTemplateApplierTest. */
    private function stubEvent(int $postId): void
    {
        Functions\when('post_type_exists')->justReturn(true);
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('Europe/Helsinki'));
        Functions\when('get_post')->justReturn(
            (object) ['ID' => $postId, 'post_type' => 'eventmesh_event', 'post_title' => 'Synced Event']
        );
        Functions\when('get_post_meta')->alias(static fn (int $id, string $key): string => match ($key) {
            '_eventmesh_starts_at' => '2026-08-01T21:00:00',
            '_eventmesh_ends_at' => '2026-08-02T01:00:00',
            default => '',
        });
    }

    public function testAnOrganizerCreatesAnEventsTasks(): void
    {
        $this->stubEvent(70);
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextResults[] = [];   // no tasks on that date yet

        $result = $this->post(CrewAdminController::TEMPLATE_ACTION, ['template_event' => '70']);

        // The three shipped roles plus the organize task.
        self::assertSame('admin_template_created_4_0', $result['notice']);
        self::assertCount(4, $this->wpdb->inserts);
        self::assertSame(70, $this->wpdb->inserts[0]['data']['event_post_id']);
        self::assertSame('4 tasks created.', CrewAdminController::noticeText($result['notice']));
    }

    public function testCreatingTasksWhenEveryRoleHasOneCreatesNothing(): void
    {
        $this->stubEvent(70);
        $existing = [];
        foreach (['decorate', 'welcome', 'clean', 'organize'] as $i => $role) {
            $existing[] = ['task_date' => '2026-08-01', 'event_post_id' => 70] + $this->taskRow(10 + $i, $role);
        }
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextResults[] = $existing;

        $result = $this->post(CrewAdminController::TEMPLATE_ACTION, ['template_event' => '70']);

        self::assertSame('admin_template_none', $result['notice']);
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame(
            'Every active role already has a task for that event.',
            CrewAdminController::noticeText($result['notice'])
        );
    }

    public function testTheTemplateNoticeSaysHowManyTasksHaveNoTimes(): void
    {
        self::assertSame('1 task created.', CrewAdminController::noticeText('admin_template_created_1_0'));
        self::assertSame(
            '3 tasks created, 2 without times - those roles have no offsets, or the event has no end recorded.',
            CrewAdminController::noticeText('admin_template_created_3_2')
        );
        self::assertSame(
            '1 task created, 1 without times - the role has no offsets, or the event has no end recorded.',
            CrewAdminController::noticeText('admin_template_created_1_1')
        );
    }

    public function testTheOpenTaskEmailWithNothingOpenSendsNothing(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextCols[] = [];   // no upcoming dates

        $result = $this->post(CrewAdminController::OPEN_TASK_ACTION);

        self::assertSame('admin_open_task_none', $result['notice']);
        self::assertSame([], $this->mails);
        self::assertSame(['inserts' => [], 'updates' => [], 'deletes' => [], 'statements' => []], $this->tasksScreenWrites());
    }

    public function testTheOpenTaskEmailIsSentAndTheNoticeCountsThePeople(): void
    {
        $this->signIn($this->organizerRow(1));
        $this->wpdb->nextCols[] = [self::NIGHT];          // upcoming dates
        $this->queueOpenTaskDate();                       // hasOpenSlotsOn
        $this->queueOpenTaskDate();                       // sendForDate
        $this->wpdb->nextResults[] = [['id' => 7, 'email' => 'sam@example.com', 'email_verified_at' => '2026-07-01 00:00:00']];
        $this->wpdb->nextVars[] = null;                   // not sent yet
        $this->wpdb->nextVars[] = 0;                      // recap: completed
        $this->wpdb->nextResults[] = [];                  // recap: history

        $result = $this->post(CrewAdminController::OPEN_TASK_ACTION);

        self::assertSame('admin_open_task_sent_1', $result['notice']);
        self::assertCount(1, $this->mails);
        self::assertSame('sam@example.com', $this->mails[0]['to']);
        self::assertSame('Open-task email sent to 1 person.', CrewAdminController::noticeText($result['notice']));
        self::assertSame('Open-task email sent to 3 people.', CrewAdminController::noticeText('admin_open_task_sent_3'));
    }

    public function testAnOrganizerSavesARoleAndTheOtherRolesStayAsTheyWere(): void
    {
        Roles::save(Roles::defaults());
        $before = $this->options[Roles::OPTION_NAME];
        $this->signIn($this->organizerRow(1));

        $result = $this->post(CrewAdminController::ROLE_ACTION, [
            'role' => 'welcome',
            'role_label' => 'Door',
            'role_emoji' => '🚪',
            'role_capacity' => '5',
            'role_restricted' => '1',
            'role_sales_desk' => '1',
            'role_title_contains' => 'party',
            'role_plus_ones' => '1',
            'role_count' => ['decorate' => '2'],
            'role_min_roles' => '1',
            'role_times_each' => '0',
            'role_anchor' => Roles::ANCHOR_END,
            'role_start_offset' => '-15',
            'role_end_offset' => 'soon',
            'role_description' => 'Stand at the door.',
        ]);

        self::assertSame('admin_role_saved', $result['notice']);
        $saved = $this->options[Roles::OPTION_NAME];
        self::assertSame(['decorate', 'welcome', 'clean'], array_column($saved, 'slug'));
        self::assertSame($before[0], $saved[0]);
        self::assertSame($before[2], $saved[2]);
        $role = Roles::find('welcome');
        self::assertNotNull($role);
        self::assertSame('Door', $role['label']);
        self::assertSame(5, $role['capacity']);
        self::assertTrue($role['restricted']);
        self::assertFalse($role['every_night']);
        self::assertTrue($role['sales_desk']);
        self::assertFalse($role['archived']);
        self::assertSame('party', $role['title_contains']);
        self::assertSame(1, $role['plus_ones']);
        self::assertSame(2, $role['conditions']['counts']['decorate'] ?? null);
        self::assertSame(Roles::ANCHOR_END, $role['anchor']);
        self::assertSame(-15, $role['start_offset']);
        self::assertNull($role['end_offset']);
        self::assertSame('Stand at the door.', $role['description']);
        self::assertSame('Settings saved.', CrewAdminController::noticeText($result['notice']));
    }

    public function testSavingARoleWithItsTextsUnchangedKeepsTheirOtherLanguages(): void
    {
        $this->storeTranslatedWelcome();
        $shown = Roles::find('welcome');
        self::assertNotNull($shown);
        $this->signIn($this->organizerRow(1));

        $result = $this->post(CrewAdminController::ROLE_ACTION, [
            'role' => 'welcome',
            'role_label' => $shown['label'],
            'role_capacity' => '3',
            'role_description' => $shown['description'],
        ]);

        self::assertSame('admin_role_saved', $result['notice']);
        $role = Roles::find('welcome');
        self::assertNotNull($role);
        self::assertSame(3, $role['capacity']);
        self::assertSame('Welcome', $role['label_text']['source']);
        self::assertSame('Tervetuloa', $role['label_text']['texts']['fi'] ?? null);
        self::assertSame('Greet people at the door.', $role['description_text']['source']);
        self::assertSame('Toivota ihmiset tervetulleiksi ovella.', $role['description_text']['texts']['fi'] ?? null);
    }

    public function testSavingARoleWithAChangedTextStoresItAsTheSource(): void
    {
        $this->storeTranslatedWelcome();
        $shown = Roles::find('welcome');
        self::assertNotNull($shown);
        $this->signIn($this->organizerRow(1));

        $this->post(CrewAdminController::ROLE_ACTION, [
            'role' => 'welcome',
            'role_label' => 'Door',
            'role_capacity' => '3',
            'role_description' => $shown['description'],
        ]);

        $role = Roles::find('welcome');
        self::assertNotNull($role);
        self::assertSame('Door', $role['label_text']['source']);
        self::assertNotSame('Tervetuloa', $role['label_text']['texts']['fi'] ?? null);
        $stored = array_column($this->options[Roles::OPTION_NAME], null, 'slug');
        self::assertSame('Door', $stored['welcome']['label']);
        // The unchanged description keeps its Finnish text.
        self::assertSame('Toivota ihmiset tervetulleiksi ovella.', $role['description_text']['texts']['fi'] ?? null);
    }

    /** Stores the default roles, with the welcome role's texts in English and Finnish. */
    private function storeTranslatedWelcome(): void
    {
        $roles = Roles::defaults();

        foreach ($roles as $i => $role) {
            if ('welcome' !== $role['slug']) {
                continue;
            }

            $roles[$i]['label_text'] = [
                'source' => 'Welcome',
                'lang' => 'en',
                'detected' => true,
                'texts' => ['en' => 'Welcome', 'fi' => 'Tervetuloa'],
                'manual' => ['en' => false, 'fi' => false],
            ];
            $roles[$i]['description_text'] = [
                'source' => 'Greet people at the door.',
                'lang' => 'en',
                'detected' => true,
                'texts' => ['en' => 'Greet people at the door.', 'fi' => 'Toivota ihmiset tervetulleiksi ovella.'],
                'manual' => ['en' => false, 'fi' => false],
            ];
        }

        Roles::save($roles);
    }

    public function testTheOrganizeRoleOrAnUnknownRoleIsNotSaved(): void
    {
        foreach ([Roles::ORGANIZE_SLUG, 'juggle', ''] as $slug) {
            $this->signIn($this->organizerRow(1));

            $result = $this->post(CrewAdminController::ROLE_ACTION, ['role' => $slug, 'role_label' => 'Taken over']);

            self::assertSame('admin_role_gone', $result['notice'], $slug);
        }

        self::assertArrayNotHasKey(Roles::OPTION_NAME, $this->options);
        self::assertSame('That role was not found.', CrewAdminController::noticeText('admin_role_gone'));
    }

    public function testTheTasksScreenHasItsTabFormsAndTheOpenTaskCount(): void
    {
        $html = $this->renderAs($this->organizerRow(1));

        self::assertStringContainsString('href="#eventcrew-admin-tasks"', $html);
        self::assertStringContainsString('id="eventcrew-admin-tasks"', $html);
        self::assertStringContainsString('name="tasks_date"', $html);
        self::assertStringContainsString('name="tasks_role"', $html);
        self::assertStringContainsString('value="' . CrewAdminController::TASK_ACTION . '"', $html);
        self::assertStringContainsString('value="' . CrewAdminController::OPEN_TASK_ACTION . '"', $html);
        self::assertStringContainsString('Add a task', $html);
        self::assertStringNotContainsString('Edit task', $html);
        // The button asks first, with the count in the question.
        self::assertStringContainsString(
            'onsubmit="return confirm(\'0 active account(s) would receive the open-task email.\');"',
            $html
        );
        // No events plugin: no "Create an event's tasks" form.
        self::assertStringNotContainsString('value="' . CrewAdminController::TEMPLATE_ACTION . '"', $html);
    }

    public function testTheTasksScreenOpensATaskForEditing(): void
    {
        Functions\when('absint')->alias(static fn (mixed $n): int => abs((int) $n));
        Functions\when('wp_unslash')->returnArg();
        $_GET = ['tasks_task' => '6', 'tasks_date' => self::NIGHT];

        try {
            $html = $this->renderAs($this->organizerRow(1), function (): void {
                // The page reads the signed-in organizer a second time first.
                $this->wpdb->nextRows[] = $this->organizerRow(1);
                $this->wpdb->nextRows[] = $this->taskRow(6, 'clean', '23:00', '23:30');
            });
        } finally {
            $_GET = [];
        }

        self::assertStringContainsString('Edit task', $html);
        self::assertStringContainsString('Update task', $html);
        self::assertMatchesRegularExpression('/name="task_id" value="6"/', $html);
        self::assertStringContainsString('value="' . self::NIGHT . 'T23:30"', $html);
        self::assertMatchesRegularExpression(
            '/name="redirect_to" value="[^"]*tasks_date=2026-07-20[^"]*#eventcrew-admin-tasks"/',
            $html
        );
    }

    /** Q209: a new task starts on the shown date's event, with its start time. */
    public function testANewTaskStartsAtTheEventsStart(): void
    {
        Functions\when('absint')->alias(static fn (mixed $n): int => abs((int) $n));
        Functions\when('wp_unslash')->returnArg();
        $this->stubEvent(70);
        Functions\when('get_posts')->justReturn([(object) ['ID' => 70]]);
        $_GET = ['tasks_date' => '2026-08-01'];

        try {
            $html = $this->renderAs($this->organizerRow(1));
        } finally {
            $_GET = [];
        }

        self::assertMatchesRegularExpression('/<option value="70"[^>]* selected>/', $html);
        self::assertStringContainsString(
            'id="eventcrew-admin-task-starts" required value="2026-08-01T21:00"',
            $html
        );
    }

    public function testTheTasksScreenOpensARoleForEditing(): void
    {
        Functions\when('absint')->alias(static fn (mixed $n): int => abs((int) $n));
        Functions\when('wp_unslash')->returnArg();
        $_GET = ['tasks_role' => 'welcome'];

        try {
            $html = $this->renderAs($this->organizerRow(1));
        } finally {
            $_GET = [];
        }

        self::assertStringContainsString('value="' . CrewAdminController::ROLE_ACTION . '"', $html);
        self::assertMatchesRegularExpression('/name="role" value="welcome"/', $html);
        self::assertStringContainsString('name="role_label"', $html);
        self::assertStringContainsString('name="role_description"', $html);
        self::assertStringContainsString('name="role_count[decorate]"', $html);
    }

    /**
     * The full page for a signed-in $row, with an empty board.
     *
     * @param array<string, mixed> $row
     * @param ?callable $afterSignIn Queues what the page reads after the session.
     */
    private function renderAs(array $row, ?callable $afterSignIn = null): string
    {
        Functions\when('get_permalink')->justReturn('https://site.test/signup/');
        Functions\when('wp_parse_url')->alias(static fn (string $url, int $part = -1): mixed => parse_url($url, $part));
        Functions\when('wp_style_is')->justReturn(false);
        Functions\when('wp_register_style')->justReturn(true);
        Functions\when('wp_add_inline_style')->justReturn(true);
        Functions\when('wp_enqueue_style')->justReturn(null);
        Functions\when('plugins_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-content/plugins/eventcrew/' . $path
        );
        Functions\when('checked')->justReturn('');
        foreach (['esc_html_e', 'esc_attr_e', '_e'] as $echoingHelper) {
            Functions\when($echoingHelper)->alias(static function (string $text): void {
                echo $text;
            });
        }

        // Every other read answers empty: no tasks, no members, no next night.
        $this->signIn($row);

        if (null !== $afterSignIn) {
            $afterSignIn();
        }

        return $this->signupController()->renderShortcode();
    }

    /**
     * One Admin sub-panel's markup: from its opening tag to the next
     * sub-panel (they are not nested) or the end of the page.
     */
    private function subpanel(string $html, string $key): string
    {
        $start = strpos($html, 'data-eventcrew-subpanel="' . $key . '"');
        self::assertNotFalse($start, $key);

        $end = strpos($html, '<div class="eventcrew-subpanel"', $start);

        return false === $end ? substr($html, $start) : substr($html, $start, $end - $start);
    }

    /**
     * Answers the reads behind the Roster screen by what they ask for rather
     * than in queue order, since the page makes many reads before them: the
     * dates with tasks, $task on its date and among the upcoming tasks (for
     * the add form), $assignment on that task, and the
     * people by id. Every other read falls through to the queue.
     *
     * @param array<string, mixed> $task
     * @param array<string, mixed> $assignment
     */
    private function routeRosterReads(array $task, array $assignment): void
    {
        $rows = [
            1 => $this->organizerRow(1),
            (int) $assignment['person_id'] => $this->personRow((int) $assignment['person_id']),
        ];
        $wpdb = new class ($task, $assignment, $rows) extends \FakeWpdb {
            /**
             * @param array<string, mixed> $task
             * @param array<string, mixed> $assignment
             * @param array<int, array<string, mixed>> $people
             */
            public function __construct(
                private readonly array $task,
                private readonly array $assignment,
                private readonly array $people
            ) {
            }

            public function get_col(string $sql): mixed
            {
                return str_contains($sql, 'DISTINCT task_date') ? [$this->task['task_date']] : parent::get_col($sql);
            }

            public function get_results(string $sql, string $output = 'OBJECT'): mixed
            {
                if (
                    str_contains($sql, 'eventcrew_tasks WHERE task_date = \'' . $this->task['task_date'] . '\'')
                    || (str_contains($sql, 'eventcrew_tasks') && str_contains($sql, 'WHERE capacity > 0'))
                ) {
                    $this->queries[] = $sql;

                    return [$this->task];
                }

                if (str_contains($sql, 'eventcrew_assignments WHERE task_id = ' . $this->assignment['task_id'] . ' ')) {
                    $this->queries[] = $sql;

                    return [$this->assignment];
                }

                return parent::get_results($sql, $output);
            }

            public function get_row(string $sql, string $output = 'OBJECT'): mixed
            {
                if (1 === preg_match('/eventcrew_people WHERE id = (\d+)$/', $sql, $m) && isset($this->people[(int) $m[1]])) {
                    $this->queries[] = $sql;

                    return $this->people[(int) $m[1]];
                }

                return parent::get_row($sql, $output);
            }
        };
        $this->wpdb = $wpdb;
        $GLOBALS['wpdb'] = $wpdb;
    }

    private function peoplePage(): PeoplePage
    {
        $assignments = new AssignmentRepository();

        return new PeoplePage(
            new View(),
            new PersonRepository(),
            $assignments,
            $this->standing(),
            new CreditGrantRepository(),
            new CreditGrantNotifier($this->mailer(), $this->client()),
            new RoleConditions($assignments, new PersonRepository()),
            new PersonEraser(
                new PersonRepository(),
                $assignments,
                new RedemptionRepository(),
                new CreditGrantRepository(),
                new AuthTokenRepository(),
                new NotificationsRepository()
            )
        );
    }
}

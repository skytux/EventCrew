<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Admin\PeoplePage;
use EventCrew\Admin\View;
use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\CreditGrantNotifier;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MarkedBy;
use EventCrew\Support\PersonEraser;
use EventCrew\Support\RoleConditions;
use EventCrew\Support\RoleTaskService;
use EventCrew\Support\Roles;
use EventCrew\Support\RosterAssembler;
use EventCrew\Support\SignupService;
use EventCrew\Support\Turnstile;
use EventCrew\Support\WebSession;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\TicketRedemptionService;
use EventCrew\Tests\Support\SalesDeskTestCase;
use EventCrew\Web\CrewAdminController;
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

    protected function setUp(): void
    {
        parent::setUp();

        $_COOKIE = [];
        $this->mails = [];

        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('sanitize_email')->alias(static fn (string $e): string => trim($e));
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
            $this->roleTasks()
        );
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

    public function testACreditOutsideOneToFiveIsRefused(): void
    {
        $this->signIn($this->organizerRow(1));

        self::assertSame(
            'admin_bad_credits',
            $this->post(CrewAdminController::GIFT_ACTION, ['person_id' => '8', 'credits' => '6'])['notice']
        );
        self::assertSame(0, $this->creditInserts());
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

        $result = $this->post(
            CrewAdminController::ADD_ACTION,
            ['task_id' => '9', 'name' => 'Kim', 'email' => 'kim@example.com', 'counts_credit' => '0']
        );

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
            ['task_id' => '9', 'name' => 'Kim', 'email' => '', 'counts_credit' => '1']
        );

        self::assertSame('admin_add_added', $result['notice']);
        self::assertSame('added-abcdef123456@added.invalid', $this->personInserts()[0]['data']['email']);
        self::assertStringContainsString("SELECT 9, 40, 'signed_up', '2026-07-20 12:00:00', 1", implode("\n", $this->wpdb->queries));
        self::assertSame([], $this->mails);
    }

    public function testAMemberCannotAddAPersonToARoleTask(): void
    {
        $this->djTask();
        $this->signIn($this->personRow(8));
        $this->wpdb->nextResults[] = [$this->organizerRow(1)];   // organizers() for the alert

        $result = $this->post(
            CrewAdminController::ADD_ACTION,
            ['task_id' => '9', 'name' => 'Kim', 'email' => 'kim@example.com', 'counts_credit' => '1'],
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

        $this->post(CrewAdminController::ROLES_ACTION, ['person_id' => '8', 'roles' => []]);

        self::assertSame('', $this->wpdb->updates[1]['data']['role_slugs']);
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
        $this->wpdb->nextRows[] = $this->bannedRow(8);   // findByEmail

        self::assertSame('blocked', $this->signupController()->loginByEmail('p8@example.com'));
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->mails);
        self::assertSame('Your account is blocked. Talk to an organizer.', SignupController::noticeText('blocked'));
    }

    public function testASignInLinkMailedBeforeTheBlockStartsNoSession(): void
    {
        $this->wpdb->nextRows[] = ['id' => 1, 'person_id' => 8, 'expires_at' => '2026-12-31 00:00:00', 'used_at' => null];
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
        $this->wpdb->nextCols[] = [self::NIGHT];                                       // nextNight
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
        $this->wpdb->nextCols[] = [self::NIGHT];                        // nextNight
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

    public function testTheTabIsInThePageForAnOrganizer(): void
    {
        $html = $this->renderAs($this->organizerRow(1));

        self::assertStringContainsString('id="eventcrew-tab-admin"', $html);
        self::assertStringContainsString('id="eventcrew-panel-admin"', $html);
        self::assertStringContainsString(CrewAdminController::GIFT_ACTION, $html);
        self::assertStringContainsString('Send me the sales desk link', $html);
    }

    /**
     * The full page for a signed-in $row, with an empty board.
     *
     * @param array<string, mixed> $row
     */
    private function renderAs(array $row): string
    {
        Functions\when('get_permalink')->justReturn('https://site.test/signup/');
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

        return $this->signupController()->renderShortcode();
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

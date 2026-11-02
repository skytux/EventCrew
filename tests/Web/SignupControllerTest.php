<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\Roles;
use EventCrew\Support\SignupService;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\Turnstile;
use EventCrew\Support\WebSession;
use EventCrew\Telegram\DohResolver;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Telegram\TicketRedemptionService;
use EventCrew\Tests\TestCase;
use EventCrew\Web\SignupController;

final class SignupControllerTest extends TestCase
{
    /** @var array<int, string> */
    private array $mails = [];

    /** @var array<int, array{to: string, subject: string}> */
    private array $sent = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];
        $this->sent = [];
        $this->transients = [];
        $_COOKIE = [];
        $_REQUEST = [];

        Functions\when('get_option')->justReturn(false);
        Functions\when('get_the_title')->justReturn('');
        Functions\when('sanitize_email')->alias(static fn (string $e): string => trim($e));
        Functions\when('is_email')->alias(
            static fn (string $e): bool => 1 === preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $e)
        );
        Functions\when('admin_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-admin/' . $p);
        Functions\when('home_url')->alias(static fn (string $p = '/'): string => 'https://site.test' . $p);
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('wp_validate_redirect')->alias(static fn (string $u, string $d): string => '' === $u ? $d : $u);
        Functions\when('add_query_arg')->alias(
            static function ($args, $url = ''): string {
                if (is_array($args)) {
                    return $url . '?' . http_build_query($args);
                }

                return $url . '?' . $args . '=x';
            }
        );
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = $body;
            $this->sent[] = ['to' => $to, 'subject' => $subject];

            return true;
        });
        Functions\when('set_transient')->alias(function (string $key, $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('get_transient')->alias(fn (string $key) => $this->transients[$key] ?? false);
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);

            return true;
        });
    }

    private function controller(): SignupController
    {
        $people = new PersonRepository();
        $assignments = new AssignmentRepository();

        return new SignupController(
            $people,
            new AuthTokenRepository(),
            new TaskRepository(),
            $assignments,
            new SignupService($assignments, new StandingCalculator($assignments, new RedemptionRepository(), new CreditGrantRepository()), new PersonRepository(), new TaskRepository()),
            new StandingCalculator($assignments, new RedemptionRepository(), new CreditGrantRepository()),
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            new ClaimNotifier(
                new TaskRepository(),
                $assignments,
                new Mailer(new Logger(), new EmailTemplate(new Logger())),
                new TelegramClient(new Logger(), new DohResolver(new Logger())),
                new StandingCalculator($assignments, new RedemptionRepository(), new CreditGrantRepository())
            ),
            new Turnstile(new Logger()),
            new TicketRedemptionService(
                $people,
                new TaskRepository(),
                new RedemptionRepository(),
                new StandingCalculator($assignments, new RedemptionRepository(), new CreditGrantRepository()),
                new FreeEntryGate(),
                new TelegramClient(new Logger(), new DohResolver(new Logger())),
                new Mailer(new Logger(), new EmailTemplate(new Logger()))
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(): array
    {
        return [
            'id' => 5,
            'task_date' => '2026-08-01',
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_post_id' => null,
            'event_label' => 'Party',
            'starts_at' => null,
            'ends_at' => null,
            'notes' => '',
            'created_at' => '2026-07-01 00:00:00',
        ];
    }

    /**
     * The two things a restructure of the public template is most likely to
     * break without anyone noticing: the element the AJAX board swap targets,
     * and the rule that a logged-out visitor is shown nothing personal.
     */
    public function testTheRenderedPageKeepsTheBoardTargetAndHidesTheGroupFromStrangers(): void
    {
        $this->stubPageRender();

        $this->wpdb->nextResults[] = [];   // upcoming dates: an empty board is enough
        $this->wpdb->nextResults[] = [];

        $html = $this->controller()->renderShortcode();

        // The JS assigns the fresh board HTML to this element on every claim
        // and drop; lose the id and the page silently stops updating in place.
        self::assertStringContainsString('id="eventcrew-board"', $html);

        // Signed out there is nothing to tab between, and the group invite is
        // not for passers-by.
        self::assertStringNotContainsString('https://t.me/+secretcrew', $html);
        // The markup, not the class name: the tab script mentions the selector
        // whether or not there is a strip to upgrade.
        self::assertStringNotContainsString('<nav class="eventcrew-tabs"', $html);
    }

    /**
     * Each task has an (i) button opening its description in a bubble, the
     * event's picture is behind its card header, and the Telegram "What each
     * task involves" link still has its anchor to land on - now the board.
     */
    public function testTheBoardShowsTheEventPictureAndAnInfoBubblePerTask(): void
    {
        $this->stubPageRender();
        Functions\when('get_the_post_thumbnail_url')->justReturn('https://site.test/night.jpg');
        Functions\when('get_the_title')->justReturn('Ecstatic Dance');

        $row = $this->taskRow();
        $row['event_post_id'] = 42;
        $this->wpdb->nextResults[] = [$row]; // upcoming
        $this->wpdb->nextResults[] = [];     // occupancyFor

        $html = $this->controller()->renderShortcode();

        self::assertStringContainsString('class="eventcrew-event-head has-image"', $html);
        self::assertStringContainsString('--eventcrew-event-image: url(&quot;https://site.test/night.jpg&quot;)', $html);
        self::assertStringContainsString('popovertarget="eventcrew-about-5"', $html);
        self::assertStringContainsString('id="eventcrew-about-5" popover', $html);
        self::assertStringContainsString('Set up before the dance', $html);
        self::assertStringContainsString('id="eventcrew-tasks-info"', $html);
        // The list at the bottom is gone: the bubbles replace it.
        self::assertStringNotContainsString('What each task involves', $html);
    }

    public function testAnEventWithoutAPictureKeepsThePlainHeader(): void
    {
        $this->stubPageRender();
        Functions\when('get_the_post_thumbnail_url')->justReturn(false);
        Functions\when('get_the_title')->justReturn('Ecstatic Dance');

        $row = $this->taskRow();
        $row['event_post_id'] = 42;
        $this->wpdb->nextResults[] = [$row];
        $this->wpdb->nextResults[] = [];

        $html = $this->controller()->renderShortcode();

        self::assertStringContainsString('<header class="eventcrew-event-head">', $html);
        self::assertStringNotContainsString('has-image', $html);
    }

    /** The WordPress functions a full page render touches. */
    private function stubPageRender(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name): mixed => 'eventcrew_telegram_group_link' === $name
                ? 'https://t.me/+secretcrew'
                : false
        );
        Functions\when('get_permalink')->justReturn('https://site.test/signup/');
        Functions\when('date_i18n')->alias(static fn (string $format, int $time): string => gmdate($format, $time));

        // The stylesheet plumbing. wp_style_is returns false so the render path
        // takes its registration branch - the one that exists because an
        // enqueue of an unregistered handle fails silently.
        Functions\when('wp_style_is')->justReturn(false);
        Functions\when('wp_register_style')->justReturn(true);
        Functions\when('wp_add_inline_style')->justReturn(true);
        Functions\when('wp_enqueue_style')->justReturn(null);
        Functions\when('plugins_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-content/plugins/eventcrew/' . $path
        );
        Functions\when('sanitize_hex_color')->returnArg(1);

        // The echoing translation helpers the template uses; TestCase stubs
        // only the returning ones, which every other test gets by with.
        foreach (['esc_html_e', 'esc_attr_e', '_e'] as $echoingHelper) {
            Functions\when($echoingHelper)->alias(static function (string $text): void {
                echo $text;
            });
        }
    }

    public function testViewModelForALoggedOutVisitorShowsTheBoardAndNoPerson(): void
    {
        $this->wpdb->nextResults[] = [$this->taskRow()]; // upcoming
        $this->wpdb->nextResults[] = [];                 // occupancyFor

        $view = $this->controller()->viewModel();

        self::assertNull($view['person']);
        self::assertSame('', $view['csrf']);
        self::assertCount(1, $view['groups']);
        self::assertSame(1, count($view['groups'][0]['tasks']));
    }

    public function testViewModelForASignedInPersonResolvesThemAndTheirStanding(): void
    {
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);

        // currentPerson -> find(7)
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam', 'email_verified_at' => '2026-07-01 00:00:00'];
        $this->wpdb->nextResults[] = [$this->taskRow()]; // upcoming
        $this->wpdb->nextResults[] = [];                 // occupancyFor
        $this->wpdb->nextResults[] = [];                 // forPerson (occupiedTaskIds)
        $this->wpdb->nextResults[] = [];                 // standing historyFor
        $this->wpdb->nextVars[] = 0;                     // standing countFor

        $view = $this->controller()->viewModel();

        self::assertNotNull($view['person']);
        self::assertSame('Sam', $view['person']->name());
        self::assertNotSame('', $view['csrf']);
        self::assertNotNull($view['standing']);
    }

    public function testLoginByEmailIssuesATokenAndMailsALink(): void
    {
        $this->wpdb->nextRows[] = null; // findByEmail -> not found -> create

        $code = $this->controller()->loginByEmail('sam@example.com');

        self::assertSame('check_email', $code);
        // A person row and an auth token row were inserted.
        self::assertGreaterThanOrEqual(2, count($this->wpdb->inserts));
        self::assertCount(1, $this->mails);
        self::assertStringContainsString('action=' . SignupController::LOGIN_ACTION, $this->mails[0]);
    }

    public function testANewAddressIsToldTheLinkCreatesAnAccount(): void
    {
        $this->wpdb->nextRows[] = null; // findByEmail -> not found -> create

        $this->controller()->loginByEmail('new@example.com');

        self::assertSame('Create your crew account', $this->sent[0]['subject']);
        self::assertStringContainsString('There is no crew account for this email address yet.', $this->mails[0]);
        self::assertStringContainsString('Create my account', $this->mails[0]);
    }

    public function testAnExistingAccountGetsThePlainSignInEmail(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
        ];

        $this->controller()->loginByEmail('sam@example.com');

        self::assertNotSame('Create your crew account', $this->sent[0]['subject']);
        self::assertStringNotContainsString('There is no crew account', $this->mails[0]);
    }

    private function sam(): \EventCrew\Models\Person
    {
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
        ];

        return (new PersonRepository())->find(7);
    }

    public function testANewNameIsSavedAtOnce(): void
    {
        $sam = $this->sam();

        self::assertSame('profile_saved', $this->controller()->updateProfile($sam, 'Sammy', 'sam@example.com'));
        self::assertSame('Sammy', $this->wpdb->updates[0]['data']['display_name']);
        self::assertSame([], $this->mails);
    }

    public function testAnEmptyNameIsRefused(): void
    {
        $sam = $this->sam();

        self::assertSame('profile_no_name', $this->controller()->updateProfile($sam, '  ', 'sam@example.com'));
        self::assertSame([], $this->wpdb->updates);
    }

    public function testANewEmailIsOnlyMailedAConfirmLinkAndNotSavedYet(): void
    {
        // With a public page, toPerson()'s footer would be a sign-in link.
        Functions\when('get_option')->alias(
            static fn (string $name, $default = false) => 'eventcrew_signup_page_id' === $name ? 12 : $default
        );
        Functions\when('get_permalink')->justReturn('https://site.test/crew/');
        $sam = $this->sam();
        $this->wpdb->nextRows[] = null; // findByEmail(new) -> free

        self::assertSame('email_sent', $this->controller()->updateProfile($sam, 'Sam', 'sam2@example.com'));
        self::assertSame([], $this->wpdb->updates);
        self::assertSame('sam2@example.com', $this->sent[0]['to']);
        self::assertSame('Confirm your new email address', $this->sent[0]['subject']);
        self::assertStringContainsString('action=' . SignupController::PROFILE_ACTION, $this->mails[0]);
        // The new address is not confirmed yet, so no sign-in link goes to it.
        self::assertStringNotContainsString(SignupController::LOGIN_ACTION, $this->mails[0]);
        self::assertStringNotContainsString('eventcrew_login', $this->mails[0]);
        self::assertSame(['sam2@example.com'], array_values($this->transients));
    }

    public function testAnEmailThatBelongsToSomeoneElseIsRefused(): void
    {
        $sam = $this->sam();
        $this->wpdb->nextRows[] = ['id' => 9, 'email' => 'kim@example.com', 'display_name' => 'Kim'];

        self::assertSame('email_taken', $this->controller()->updateProfile($sam, 'Sam', 'kim@example.com'));
        self::assertSame([], $this->mails);
        self::assertSame([], $this->transients);
    }

    public function testTheConfirmLinkMovesTheAccountAndTellsTheOldAddress(): void
    {
        $this->transients['eventcrew_email_change_' . substr(hash('sha256', 'raw1'), 0, 32)] = 'sam2@example.com';
        $this->wpdb->nextRows[] = ['id' => 1, 'person_id' => 7, 'expires_at' => '2099-01-01 00:00:00', 'used_at' => null];
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];
        $this->wpdb->nextRows[] = null; // findByEmail(new) -> still free

        self::assertSame('email_changed', $this->controller()->confirmEmailChange('raw1'));

        self::assertContains('sam2@example.com', array_column(array_column($this->wpdb->updates, 'data'), 'email'));
        self::assertSame([], $this->transients);
        self::assertSame('sam@example.com', $this->sent[0]['to']);
        self::assertStringContainsString('sam2@example.com', $this->mails[0]);
    }

    public function testAConfirmLinkWithNothingPendingChangesNothing(): void
    {
        // A valid token alone is not enough: the pending address is gone.
        $this->wpdb->nextRows[] = ['id' => 1, 'person_id' => 7, 'expires_at' => '2099-01-01 00:00:00', 'used_at' => null];
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];

        self::assertSame('bad_email_link', $this->controller()->confirmEmailChange('raw1'));
        self::assertSame([], array_filter(array_column(array_column($this->wpdb->updates, 'data'), 'email')));
        self::assertSame([], $this->mails);
    }

    private function accentStyle(): string
    {
        Functions\when('sanitize_hex_color')->returnArg(1);

        return (string) (new \ReflectionMethod(SignupController::class, 'accentStyle'))->invoke($this->controller());
    }

    public function testTheAccentSettingColoursTheTabsNotTheThemeColour(): void
    {
        Functions\when('get_option')->alias(static fn (string $name, $default = false) => match ($name) {
            'eventcrew_app_theme_color' => '#2a1f4f',
            'eventcrew_app_accent_color' => '#f9b364',
            default => $default,
        });

        self::assertStringContainsString('--eventcrew-accent:#f9b364;', $this->accentStyle());
    }

    public function testWithoutAnAccentSettingTheThemeColourIsTheAccent(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, $default = false) => 'eventcrew_app_theme_color' === $name ? '#2a1f4f' : $default
        );

        self::assertStringContainsString('--eventcrew-accent:#2a1f4f;', $this->accentStyle());
    }

    public function testLoginByEmailRejectsABadAddress(): void
    {
        $code = $this->controller()->loginByEmail('not-an-email');

        self::assertSame('bad_email', $code);
        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->mails);
    }

    public function testConsumeMagicLinkVerifiesAndReturnsThePersonId(): void
    {
        // AuthTokenRepository::consume -> a live, unused token for person 7.
        $this->wpdb->nextRows[] = [
            'id' => 1,
            'person_id' => 7,
            'expires_at' => '2026-12-31 00:00:00',
            'used_at' => null,
        ];

        $personId = $this->controller()->consumeMagicLink('rawtoken');

        self::assertSame(7, $personId);
        // used_at burned, and the person marked verified: two updates.
        self::assertGreaterThanOrEqual(2, count($this->wpdb->updates));
    }

    public function testConsumeMagicLinkReturnsNullForABadToken(): void
    {
        // No token row queued -> consume returns null.
        self::assertNull($this->controller()->consumeMagicLink('nope'));
    }

    public function testClaimForMapsAJoinToAClaimedNotice(): void
    {
        // Gate off, no overlap, capacity free, insert succeeds.
        $this->wpdb->nextVars[] = 2; // taskCapacity
        $this->wpdb->nextRows[] = null; // join findFor
        $this->wpdb->nextQueryResults[] = 1; // insert

        self::assertSame('claimed', $this->controller()->claimFor(7, 5));
    }

    public function testClaimForRefusesTheOrganizeTaskToSomeoneWhoIsNotAnOrganizer(): void
    {
        $this->wpdb->nextRows[] = array_merge($this->taskRow(), ['role_slug' => 'organize', 'capacity' => 1]); // tasks->find
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'is_organizer' => 0];              // people->find
        // What a join would read if it got that far, so only the check stops it.
        $this->wpdb->nextVars[] = 1;
        $this->wpdb->nextRows[] = null;
        $this->wpdb->nextQueryResults[] = 1;

        self::assertSame('unavailable', $this->controller()->claimFor(7, 5));
        self::assertStringNotContainsString('INSERT', implode("\n", $this->wpdb->queries));
    }

    public function testTheBoardHidesTheOrganizeTaskFromALoggedOutVisitor(): void
    {
        $this->wpdb->nextResults[] = [
            $this->taskRow(),
            array_merge($this->taskRow(), ['id' => 6, 'role_slug' => 'organize', 'capacity' => 1]),
        ];
        $this->wpdb->nextResults[] = [];

        $view = $this->controller()->viewModel();

        self::assertSame([5], array_map(static fn (array $row): int => $row['task']->id, $view['groups'][0]['tasks']));
    }

    public function testTheBoardShowsTheOrganizeTaskToAnOrganizer(): void
    {
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);

        $this->wpdb->nextRows[] = [
            'id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00', 'is_organizer' => 1,
        ];
        $this->wpdb->nextResults[] = [
            $this->taskRow(),
            array_merge($this->taskRow(), ['id' => 6, 'role_slug' => 'organize', 'capacity' => 1]),
        ];
        $this->wpdb->nextResults[] = [['task_id' => 6, 'taken' => 3]];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextVars[] = 0;

        $view = $this->controller()->viewModel();

        self::assertSame([5, 6], array_map(static fn (array $row): int => $row['task']->id, $view['groups'][0]['tasks']));
    }

    /**
     * Settings > Roles with a "DJ" role set to "Only people with this role".
     * Every other option reads as unset, as in setUp().
     */
    private function withDjRole(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => Roles::OPTION_NAME === $name
                ? [
                    ['slug' => 'decorate', 'label' => 'Decorate'],
                    ['slug' => 'dj', 'label' => 'DJ', 'emoji' => '🎧', 'restricted' => true],
                    ['slug' => 'random', 'label' => 'Random', 'emoji' => '🎲', 'on_board' => false],
                ]
                : false
        );
    }

    /**
     * The crew page board for person 7, with a decorate task (5), a DJ
     * task (6) already holding three people and a Random task (8), a role
     * left off the guest board.
     *
     * @return array<int, int> The task ids on the board.
     */
    private function boardFor(string $roleSlugs, int $organizer = 0): array
    {
        return array_keys($this->boardRowsFor($roleSlugs, $organizer));
    }

    /**
     * @return array<int, array<string, mixed>> The board rows by task id.
     */
    private function boardRowsFor(string $roleSlugs, int $organizer = 0): array
    {
        $this->withDjRole();
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);

        $this->wpdb->nextRows[] = [
            'id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00', 'is_organizer' => $organizer, 'role_slugs' => $roleSlugs,
        ];
        $this->wpdb->nextResults[] = [
            $this->taskRow(),
            array_merge($this->taskRow(), ['id' => 6, 'role_slug' => 'dj', 'capacity' => 1]),
            array_merge($this->taskRow(), ['id' => 8, 'role_slug' => 'random', 'capacity' => 2]),
        ];
        $this->wpdb->nextResults[] = [['task_id' => 6, 'taken' => 3]];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextVars[] = 0;

        $view = $this->controller()->viewModel();

        return array_combine(
            array_map(static fn (array $row): int => $row['task']->id, $view['groups'][0]['tasks']),
            $view['groups'][0]['tasks']
        );
    }

    public function testTheBoardShowsARestrictedTaskOnlyToSomeoneWithItsRole(): void
    {
        self::assertSame([5], $this->boardFor(''));
        self::assertSame([5, 6], $this->boardFor('dj'));
    }

    public function testAnOrganizerSeesEveryTaskWithARoleOnlyButtonOnRolesTheyLack(): void
    {
        $rows = $this->boardRowsFor('', 1);

        self::assertSame([5, 6, 8], array_keys($rows));
        self::assertFalse($rows[5]['role_only']);
        self::assertTrue($rows[6]['role_only']);
        self::assertFalse($rows[8]['role_only']);

        self::assertFalse($this->boardRowsFor('dj', 1)[6]['role_only']);
    }

    public function testSignedOutTheBoardShowsWhatTheTelegramGroupBoardShows(): void
    {
        $this->withDjRole();
        $this->wpdb->nextResults[] = [
            $this->taskRow(),
            array_merge($this->taskRow(), ['id' => 6, 'role_slug' => 'dj', 'capacity' => 1]),
            array_merge($this->taskRow(), ['id' => 8, 'role_slug' => 'random', 'capacity' => 2]),
        ];
        $this->wpdb->nextResults[] = [];

        $view = $this->controller()->viewModel();

        self::assertSame([5], array_map(static fn (array $row): int => $row['task']->id, $view['groups'][0]['tasks']));
    }

    public function testClaimingARestrictedTaskNeedsTheRoleThenTheCreditAnswer(): void
    {
        $this->withDjRole();
        $dj = array_merge($this->taskRow(), ['role_slug' => 'dj', 'capacity' => 1]);
        $member = ['id' => 7, 'email' => 'sam@example.com', 'is_organizer' => 0];
        $holder = array_merge($member, ['role_slugs' => 'dj']);

        $this->wpdb->nextRows[] = $dj;
        $this->wpdb->nextRows[] = $member;
        self::assertSame('unavailable', $this->controller()->claimFor(7, 5, true));

        $this->wpdb->nextRows[] = $dj;
        $this->wpdb->nextRows[] = $holder;
        self::assertSame('credit_question', $this->controller()->claimFor(7, 5));
        self::assertStringNotContainsString('INSERT', implode("\n", $this->wpdb->queries));

        $this->wpdb->nextRows[] = $dj;
        $this->wpdb->nextRows[] = $holder;
        $this->wpdb->nextVars[] = 1;            // taskCapacity
        $this->wpdb->nextRows[] = null;         // join findFor
        $this->wpdb->nextQueryResults[] = 1;    // insert

        self::assertSame('claimed', $this->controller()->claimFor(7, 5, false));
        self::assertStringContainsString("SELECT 5, 7, 'signed_up', '2026-07-20 12:00:00', 0", $this->wpdb->lastQuery());
    }

    public function testDropForMapsACancellationToADroppedNotice(): void
    {
        $this->wpdb->nextRows[] = ['id' => 3, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up'];
        $this->wpdb->nextRows[] = ['starts_at' => '2026-09-01 20:00:00', 'task_date' => '2026-09-01'];

        self::assertSame('dropped', $this->controller()->dropFor(7, 5));
    }

    /**
     * The Suomi | English switch on the page saves the choice to the
     * signed-in person's record.
     */
    public function testTheLanguageSwitchSavesTheChoiceToThePerson(): void
    {
        self::assertSame('language_saved', $this->controller()->chooseLanguage(7, 'fi'));

        self::assertCount(1, $this->wpdb->updates);
        self::assertSame('fi', $this->wpdb->updates[0]['data']['language']);
        self::assertSame(['id' => 7], $this->wpdb->updates[0]['where']);
    }

    public function testAnUnknownLanguageIsIgnored(): void
    {
        self::assertSame('', $this->controller()->chooseLanguage(7, 'sv'));
        self::assertSame([], $this->wpdb->updates);
    }

    public function testTheStylesheetIsKeptOutOfW3TotalCacheMinify(): void
    {
        $controller = $this->controller();
        $own = "<link rel='stylesheet' id='eventcrew-signup-css' href='x/eventcrew.css?ver=1' />";
        $inline = "<style id='eventcrew-signup-inline-css'>a{}</style>";
        $other = "<link rel='stylesheet' id='theme-css' href='x/style.css' />";

        self::assertFalse($controller->keepStyleOutOfMinify(true, $own));
        self::assertTrue($controller->keepStyleOutOfMinify(true, $inline));
        self::assertTrue($controller->keepStyleOutOfMinify(true, $other));
        self::assertTrue($controller->keepStyleOutOfMinify(true));
    }
}

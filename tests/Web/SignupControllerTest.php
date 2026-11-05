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
use EventCrew\Support\SiteLanguages;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\TelegramLogin;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\Turnstile;
use EventCrew\Support\WebSession;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\DohResolver;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Telegram\TicketRedemptionService;
use EventCrew\Tests\Support\FakeEventNames;
use EventCrew\Tests\TestCase;
use EventCrew\Web\SignupController;

final class SignupControllerTest extends TestCase
{
    use FakeEventNames;

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

    /** S-W8: any page, not only one with the crew app block, while the cookie is there. */
    public function testASignedInVisitorGetsNoCacheHeadersOnAnyPage(): void
    {
        $calls = 0;
        Functions\when('nocache_headers')->alias(function () use (&$calls): void {
            ++$calls;
        });
        // A page without the block: get_post() and has_block() are no longer asked.
        Functions\expect('get_post')->never();

        $this->controller()->preventCachingWhenSignedIn();
        self::assertSame(0, $calls, 'A signed-out visitor sees a public page worth caching.');

        $_COOKIE[WebSession::COOKIE] = 'session';
        $this->controller()->preventCachingWhenSignedIn();
        self::assertSame(1, $calls);
        self::assertTrue(defined('DONOTCACHEPAGE'));
    }

    private function controller(?TelegramLogin $telegramLogin = null): SignupController
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
            ),
            null,
            null,
            null,
            $telegramLogin
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

    /** With EventMesh's clean name the card header shows it, and the DJ line on its own line. */
    public function testTheBoardShowsTheCleanNameAndTheDjLine(): void
    {
        $this->stubPageRender();
        Functions\when('get_the_post_thumbnail_url')->justReturn(false);
        Functions\when('get_the_title')->justReturn('ECSTATIC DANCE 14.11.2026 / Esimerkki');
        $this->fakeEventNames([42 => ['en' => 'Ecstatic Dance']], [42 => ['Esimerkki']]);

        $row = $this->taskRow();
        $row['event_post_id'] = 42;
        $this->wpdb->nextResults[] = [$row];
        $this->wpdb->nextResults[] = [];

        $html = $this->controller()->renderShortcode();

        self::assertStringContainsString(
            '<h3 class="eventcrew-event-title">Ecstatic Dance<span class="eventcrew-event-dj">Esimerkki</span></h3>',
            $html
        );
        self::assertStringNotContainsString('ECSTATIC DANCE', $html);
    }

    /**
     * The whole crew page for a signed-in person renders through to its last
     * line: a fatal error or an exception anywhere in templates/public/signup.php
     * fails this test instead of reaching the live page.
     */
    public function testTheWholePageRendersForASignedInPerson(): void
    {
        $this->stubPageRender();
        Functions\when('wp_unslash')->returnArg(1);
        // WordPress's checked() prints the attribute; the template uses only that form.
        Functions\when('checked')->alias(static function ($checked, $current = true): void {
            if ((string) $checked === (string) $current) {
                echo " checked='checked'";
            }
        });
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);

        $person = [
            'id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
        ];
        $this->wpdb->nextRows[] = $person;               // pageLanguage: currentPerson
        $this->wpdb->nextRows[] = $person;               // viewModel: currentPerson
        $this->wpdb->nextResults[] = [$this->taskRow()]; // upcoming
        $this->wpdb->nextResults[] = [];                 // occupancyFor
        $this->wpdb->nextResults[] = [];                 // forPerson (occupiedTaskIds)
        $this->wpdb->nextResults[] = [];                 // standing historyFor
        $this->wpdb->nextVars[] = 0;                     // standing countFor

        $html = $this->controller()->renderShortcode();

        self::assertStringContainsString('id="eventcrew-board"', $html);
        self::assertStringContainsString('<nav class="eventcrew-tabs"', $html);
        self::assertStringContainsString('Sam', $html);
        // The template's last line came out: nothing stopped it half-way.
        self::assertStringEndsWith('</script>', rtrim($html));
    }

    /**
     * Q298: the language switch on the crew page has one button per site
     * language, main language first, each in its own name, and the person's
     * chosen language is the pressed one.
     */
    public function testTheLanguageSwitchOffersEverySiteLanguage(): void
    {
        $this->stubPageRender();
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => match ($name) {
                SiteLanguages::OPTION => ['fi', 'en', 'sv'],
                'eventcrew_telegram_group_link' => 'https://t.me/+secretcrew',
                default => false,
            }
        );
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('checked')->justReturn(null);
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);

        $person = [
            'id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00', 'language' => 'sv',
        ];
        $this->wpdb->nextRows[] = $person;               // pageLanguage: currentPerson
        $this->wpdb->nextRows[] = $person;               // viewModel: currentPerson
        $this->wpdb->nextResults[] = [$this->taskRow()]; // upcoming
        $this->wpdb->nextResults[] = [];                 // occupancyFor
        $this->wpdb->nextResults[] = [];                 // forPerson (occupiedTaskIds)
        $this->wpdb->nextResults[] = [];                 // standing historyFor
        $this->wpdb->nextVars[] = 0;                     // standing countFor

        $html = $this->controller()->renderShortcode();

        self::assertSame(1, preg_match('~<form class="eventcrew-language".*?</form>~s', $html, $form));
        $button = '~<button type="submit" name="language"\s+value="(\w+)".*?aria-pressed="(\w+)">([^<]+)</button>~s';
        preg_match_all($button, $form[0], $buttons);
        self::assertSame(['fi', 'en', 'sv'], $buttons[1]);
        self::assertSame(['Suomi', 'English', 'Svenska'], $buttons[3]);
        self::assertSame(['false', 'false', 'true'], $buttons[2]);
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

    public function testASignedOutVisitorGetsTheBrowsersLanguage(): void
    {
        Functions\when('wp_unslash')->returnArg(1);

        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fi-FI,fi;q=0.9,en;q=0.8';
        self::assertSame('fi', $this->controller()->pageLanguage());

        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9,fi;q=0.8';
        self::assertNull($this->controller()->pageLanguage());

        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
        self::assertNull($this->controller()->pageLanguage());
    }

    public function testASignedOutVisitorWithAFinnishBrowserSeesTheCrewPageInFinnish(): void
    {
        $this->stubPageRender();
        Functions\when('wp_unslash')->returnArg(1);
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fi';

        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];

        try {
            $html = $this->controller()->renderShortcode();
        } finally {
            unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
        }

        // The sign-in form's "Checking you're human" label.
        self::assertStringContainsString('Tarkistetaan', $html);
        self::assertStringNotContainsString('Checking you', $html);
    }

    /**
     * The board's dates follow the page's language, not WordPress's: a Finnish
     * browser reads the card's day in Finnish, an English one as before.
     */
    public function testTheBoardDateIsInThePagesLanguage(): void
    {
        $this->stubPageRender();
        Functions\when('wp_unslash')->returnArg(1);

        $render = function (string $browser): string {
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $browser;
            $this->wpdb->nextResults[] = [$this->taskRow()]; // upcoming (Sat 2026-08-01)
            $this->wpdb->nextResults[] = [];                 // occupancyFor

            try {
                return $this->controller()->renderShortcode();
            } finally {
                unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            }
        };

        $finnish = $render('fi');
        self::assertStringContainsString('<span class="eventcrew-event-date">lauantai 1. elokuuta</span>', $finnish);
        self::assertStringNotContainsString('Saturday', $finnish);

        $english = $render('en');
        self::assertStringContainsString('<span class="eventcrew-event-date">Saturday 1 August</span>', $english);
    }

    public function testASignedInPersonsOwnChoiceWinsOverTheBrowser(): void
    {
        Functions\when('wp_unslash')->returnArg(1);
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fi';
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);
        // currentPerson -> find(7): has not chosen, so English.
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam', 'email_verified_at' => '2026-07-01 00:00:00'];

        try {
            self::assertNull($this->controller()->pageLanguage());
        } finally {
            unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
        }
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

    public function testAnEmailThatBelongsToSomeoneElseGetsTheSameAnswerAsAFreeOne(): void
    {
        $sam = $this->sam();

        // The form does not look the address up, so it cannot tell whether
        // kim@example.com is registered: the answer is "check the inbox".
        self::assertSame('email_sent', $this->controller()->updateProfile($sam, 'Sam', 'kim@example.com'));
        self::assertSame([], $this->wpdb->updates);
        self::assertSame('kim@example.com', $this->sent[0]['to']);
        self::assertSame(['kim@example.com'], array_values($this->transients));
    }

    public function testTheConfirmLinkForAnAddressOfAnotherAccountChangesNothing(): void
    {
        $this->transients['eventcrew_email_change_' . substr(hash('sha256', 'raw1'), 0, 32)] = 'kim@example.com';
        $this->wpdb->nextRows[] = ['id' => 1, 'person_id' => 7, 'expires_at' => '2099-01-01 00:00:00', 'used_at' => null];
        $this->wpdb->nextQueryResults[] = 1; // the conditional UPDATE that spends the link
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];
        $this->wpdb->nextRows[] = ['id' => 9, 'email' => 'kim@example.com', 'display_name' => 'Kim'];

        // Only whoever reads kim@example.com's mailbox sees this answer.
        self::assertSame('email_taken', $this->controller()->confirmEmailChange('raw1'));
        self::assertNotContains('kim@example.com', array_column(array_column($this->wpdb->updates, 'data'), 'email'));
    }

    public function testTheConfirmLinkMovesTheAccountAndTellsTheOldAddress(): void
    {
        $this->transients['eventcrew_email_change_' . substr(hash('sha256', 'raw1'), 0, 32)] = 'sam2@example.com';
        $this->wpdb->nextRows[] = ['id' => 1, 'person_id' => 7, 'expires_at' => '2099-01-01 00:00:00', 'used_at' => null];
        $this->wpdb->nextQueryResults[] = 1; // the conditional UPDATE that spends the link
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];
        $this->wpdb->nextRows[] = null; // findByEmail(new) -> still free

        self::assertSame('email_changed', $this->controller()->confirmEmailChange('raw1'));

        self::assertContains('sam2@example.com', array_column(array_column($this->wpdb->updates, 'data'), 'email'));
        self::assertSame([], $this->transients);
        self::assertSame('sam@example.com', $this->sent[0]['to']);
        self::assertStringContainsString('sam2@example.com', $this->mails[0]);
    }

    public function testAnEmailChangeLinkOpenedTwiceAtOnceChangesTheAddressOnlyOnce(): void
    {
        $pendingKey = 'eventcrew_email_change_' . substr(hash('sha256', 'raw1'), 0, 32);
        $token = ['id' => 1, 'person_id' => 7, 'expires_at' => '2099-01-01 00:00:00', 'used_at' => null];

        $this->transients[$pendingKey] = 'sam2@example.com';
        $this->wpdb->nextRows[] = $token;
        $this->wpdb->nextQueryResults[] = 1; // the first one's UPDATE changes the row
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];
        $this->wpdb->nextRows[] = null;      // findByEmail(new) -> still free
        $first = $this->controller()->confirmEmailChange('raw1');
        $updatesAfterFirst = count($this->wpdb->updates);

        // The second request read the pending address and the unused token
        // before the first one finished.
        $this->transients[$pendingKey] = 'sam2@example.com';
        $this->wpdb->nextRows[] = $token;
        $this->wpdb->nextQueryResults[] = 0; // the second one's UPDATE changes nothing
        $second = $this->controller()->confirmEmailChange('raw1');

        self::assertSame('email_changed', $first);
        self::assertSame('bad_email_link', $second);
        self::assertCount($updatesAfterFirst, $this->wpdb->updates);
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

    // ---- The Messages page templates (sign_in_link, account_create_link,
    // email_change_confirm, email_changed) -----------------------------------

    /** The sent mail with its one-time token replaced by TOKEN, so it can be compared. */
    private function sentMail(int $index = 0): string
    {
        return $this->sent[$index]['subject'] . "\n---\n"
            . (string) preg_replace('/token=[A-Za-z0-9_-]+/', 'token=TOKEN', $this->mails[$index]);
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function storeTemplate(string $id, array $stored): void
    {
        // The plain-text mail, as with no options at all.
        $options = [
            \EventCrew\Support\MessageTemplates::OPTION_PREFIX . $id => $stored,
            EmailTemplate::HTML_OPTION => false,
        ];
        Functions\when('get_option')->alias(
            static fn (string $name, $default = false) => $options[$name] ?? $default
        );
    }

    /**
     * @return array{source: string, texts: array<string, string>, manual: array<string, bool>}
     */
    private function storedText(string $text): array
    {
        return ['source' => $text, 'texts' => ['en' => $text], 'manual' => ['en' => false]];
    }

    public function testTheSignInEmailIsUnchangedWhenNothingIsStored(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
        ];

        $this->controller()->loginByEmail('sam@example.com');

        self::assertSame(
            "Your EventCrew sign-in link\n---\nHi Sam,\n\n"
                . "Use the button below to sign in and manage your tasks.\n\n"
                . "The link is good for 30 minutes, and works once.\n\n"
                . 'Sign in: https://site.test/wp-admin/admin-ajax.php?action=eventcrew_web_login&token=TOKEN'
                . "&redirect_to=https%253A%252F%252Fsite.test%252F\n\n—\nManage your account or stop these emails:"
                . ' https://site.test/wp-json/eventcrew/v1/manage?token=TOKEN.2e7412c689f539f6489b9a1e298151a2431bfc264f90e62776a73bce4d9e9398',
            $this->sentMail()
        );
    }

    public function testTheCreateAccountEmailIsUnchangedWhenNothingIsStored(): void
    {
        $this->wpdb->nextRows[] = null; // findByEmail -> not found -> create

        $this->controller()->loginByEmail('new@example.com');

        self::assertSame(
            "Create your crew account\n---\nHi,\n\n"
                . "There is no crew account for this email address yet. The button below"
                . " creates your account and signs you in.\n\nThe link is good for 30 minutes, and works once. If you"
                . " did not ask for this, ignore this email.\n\nWe store your email to run the crew.\n\n"
                . 'Create my account: https://site.test/wp-admin/admin-ajax.php?action=eventcrew_web_login&token=TOKEN'
                . "&redirect_to=https%253A%252F%252Fsite.test%252F\n\n—\nManage your account or stop these emails:"
                . ' https://site.test/wp-json/eventcrew/v1/manage?token=TOKEN.12c6aa29e718d0f450ae312aadd7b4a5574cbd732e15063ef00e534d9e56e15b',
            $this->sentMail()
        );
    }

    public function testTheEmailChangeConfirmationIsUnchangedWhenNothingIsStored(): void
    {
        $sam = $this->sam();

        $this->controller()->updateProfile($sam, 'Sam', 'sam2@example.com');

        self::assertSame(
            "Confirm your new email address\n---\nHi Sam,\n\n"
                . "Use the button below to make this the email address of your crew account.\n\n"
                . "The link is good for 24 hours, and works once. Until you use it, your account keeps its"
                . " old address. If you did not ask for this, ignore this email.\n\n"
                . 'Confirm new email: https://site.test/wp-admin/admin-ajax.php?action=eventcrew_web_profile&token=TOKEN'
                . "&redirect_to=https%253A%252F%252Fsite.test%252F\n\n—\nManage your account or stop these emails: ",
            $this->sentMail()
        );
    }

    public function testTheEmailChangedNoticeIsUnchangedWhenNothingIsStored(): void
    {
        $this->transients['eventcrew_email_change_' . substr(hash('sha256', 'raw1'), 0, 32)] = 'sam2@example.com';
        $this->wpdb->nextRows[] = ['id' => 1, 'person_id' => 7, 'expires_at' => '2099-01-01 00:00:00', 'used_at' => null];
        $this->wpdb->nextQueryResults[] = 1;
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];
        $this->wpdb->nextRows[] = null;

        $this->controller()->confirmEmailChange('raw1');

        self::assertSame(
            "Your crew account email was changed\n---\nHi Sam,\n\nThe email address of your crew account is now"
                . " sam2@example.com. Mail from the crew goes there from now on.\n\nIf you did not do this, tell an"
                . " organizer.\n\n—\nManage your account or stop these emails:"
                . ' https://site.test/wp-json/eventcrew/v1/manage?token=TOKEN.2e7412c689f539f6489b9a1e298151a2431bfc264f90e62776a73bce4d9e9398',
            $this->sentMail()
        );
    }

    public function testTheSignInEmailUsesTheStoredTextAndKeepsTheButton(): void
    {
        $this->storeTemplate('sign_in_link', [
            'subject' => $this->storedText('Sign in, please'),
            'body' => $this->storedText('Press the button.'),
        ]);
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
        ];

        $this->controller()->loginByEmail('sam@example.com');

        self::assertStringStartsWith(
            "Sign in, please\n---\nHi Sam,\n\nPress the button.\n\n"
                . 'Sign in: https://site.test/wp-admin/admin-ajax.php?action=eventcrew_web_login&token=TOKEN',
            $this->sentMail()
        );
    }

    public function testTheCreateAccountEmailUsesTheStoredTextAndKeepsThePrivacyLine(): void
    {
        $this->storeTemplate('account_create_link', [
            'subject' => $this->storedText('Welcome'),
            'body' => $this->storedText('Press the button to join.'),
        ]);
        $this->wpdb->nextRows[] = null;

        $this->controller()->loginByEmail('new@example.com');

        self::assertStringStartsWith(
            "Welcome\n---\nHi,\n\nPress the button to join.\n\nWe store your email to run the crew.\n\n"
                . 'Create my account: https://site.test/wp-admin/admin-ajax.php?action=eventcrew_web_login&token=TOKEN',
            $this->sentMail()
        );
    }

    public function testTheEmailChangeConfirmationUsesTheStoredText(): void
    {
        $this->storeTemplate('email_change_confirm', [
            'subject' => $this->storedText('Is this you?'),
            'body' => $this->storedText('Confirm below.'),
        ]);
        $sam = $this->sam();

        $this->controller()->updateProfile($sam, 'Sam', 'sam2@example.com');

        self::assertStringStartsWith(
            "Is this you?\n---\nHi Sam,\n\nConfirm below.\n\n"
                . 'Confirm new email: https://site.test/wp-admin/admin-ajax.php?action=eventcrew_web_profile&token=TOKEN',
            $this->sentMail()
        );
    }

    public function testTheEmailChangedNoticeUsesTheStoredText(): void
    {
        $this->storeTemplate('email_changed', [
            'subject' => $this->storedText('Address moved'),
            'body' => $this->storedText('Now {email}.'),
        ]);
        $this->transients['eventcrew_email_change_' . substr(hash('sha256', 'raw1'), 0, 32)] = 'sam2@example.com';
        $this->wpdb->nextRows[] = ['id' => 1, 'person_id' => 7, 'expires_at' => '2099-01-01 00:00:00', 'used_at' => null];
        $this->wpdb->nextQueryResults[] = 1;
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam'];
        $this->wpdb->nextRows[] = null;

        $this->controller()->confirmEmailChange('raw1');

        self::assertStringStartsWith("Address moved\n---\nHi Sam,\n\nNow sam2@example.com.\n\n", $this->sentMail());
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

    public function testOneAddressGetsFiveSignInLinksAnHourAndThenNoMore(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->wpdb->nextRows[] = null; // findByEmail -> not found -> create
            self::assertSame('check_email', $this->controller()->loginByEmail('sam@example.com', '203.0.113.' . $i));
        }

        $queries = count($this->wpdb->queries);
        $inserts = count($this->wpdb->inserts);

        // The same address from yet another IP, in another letter case.
        self::assertSame('login_busy', $this->controller()->loginByEmail('Sam@Example.com', '203.0.113.99'));
        self::assertCount(5, $this->mails);
        // Nothing is looked up or made for a refused request.
        self::assertCount($queries, $this->wpdb->queries);
        self::assertCount($inserts, $this->wpdb->inserts);
        self::assertSame(
            'Too many sign-in links were asked for. Wait an hour and try again.',
            SignupController::noticeText('login_busy')
        );
    }

    public function testOneIpAddressGetsThirtySignInLinksAnHour(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->wpdb->nextRows[] = null;
            $code = $this->controller()->loginByEmail('p' . $i . '@example.com', '203.0.113.7');
            self::assertSame('check_email', $code);
        }

        self::assertSame('login_busy', $this->controller()->loginByEmail('p31@example.com', '203.0.113.7'));
        // Another IP address is not affected.
        $this->wpdb->nextRows[] = null;
        self::assertSame('check_email', $this->controller()->loginByEmail('p31@example.com', '198.51.100.1'));
        self::assertCount(31, $this->mails);
    }

    public function testTheSignInLimitStartsAgainAfterAnHour(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->wpdb->nextRows[] = null;
            $this->controller()->loginByEmail('sam@example.com', '203.0.113.1');
        }

        // The counter began over an hour ago.
        foreach ($this->transients as $key => $value) {
            if (str_starts_with($key, 'eventcrew_login_rate_')) {
                $this->transients[$key]['since'] = time() - HOUR_IN_SECONDS - 1;
            }
        }

        $this->wpdb->nextRows[] = null;
        self::assertSame('check_email', $this->controller()->loginByEmail('sam@example.com', '203.0.113.1'));
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
        // AuthTokenRepository::consumeOnce -> a live, unused token for person 7,
        // and the conditional UPDATE that spends it changes one row.
        $this->wpdb->nextRows[] = [
            'id' => 1,
            'person_id' => 7,
            'expires_at' => '2026-12-31 00:00:00',
            'used_at' => null,
        ];
        $this->wpdb->nextQueryResults[] = 1;

        $personId = $this->controller()->consumeMagicLink('rawtoken');

        self::assertSame(7, $personId);
        // used_at burned by the conditional UPDATE, and the person marked verified.
        self::assertStringContainsString('used_at IS NULL', implode("\n", $this->wpdb->queries));
        self::assertGreaterThanOrEqual(1, count($this->wpdb->updates));
    }

    public function testASignInLinkOpenedTwiceAtOnceSignsInOnlyOnce(): void
    {
        $row = ['id' => 1, 'person_id' => 7, 'expires_at' => '2026-12-31 00:00:00', 'used_at' => null];

        // Both requests read the row while it is still unused.
        $this->wpdb->nextRows[] = $row;
        $this->wpdb->nextQueryResults[] = 1;  // the first one's UPDATE changes the row
        $first = $this->controller()->magicLinkSignIn('rawtoken');

        $this->wpdb->nextRows[] = $row;
        $this->wpdb->nextQueryResults[] = 0;  // the second one's UPDATE changes nothing
        $second = $this->controller()->magicLinkSignIn('rawtoken');

        self::assertSame(7, $first['person_id']);
        self::assertSame(['person_id' => null, 'notice' => 'bad_link'], $second);
    }

    /**
     * Runs dispatch() for one request and answers how it ended: 'page 200'
     * when it sent the sign-in button page, or the URL it redirected to.
     * status_header() and wp_safe_redirect() throw, so the exit is never hit.
     *
     * @param array<string, string> $get
     * @param array<string, string> $post
     */
    private function dispatchSignIn(array $get, array $post): string
    {
        $_GET = $get;
        $_POST = $post;
        $_REQUEST = ['action' => SignupController::LOGIN_ACTION] + $get + $post;
        $this->stubSignInExits();

        try {
            $this->controller()->dispatch();
        } catch (\RuntimeException $answer) {
            return $answer->getMessage();
        } finally {
            $_GET = [];
            $_POST = [];
            $_REQUEST = [];
        }

        self::fail('The controller sent no answer.');
    }

    private function stubSignInExits(): void
    {
        Functions\when('nocache_headers')->justReturn(null);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('is_ssl')->justReturn(true);
        Functions\when('status_header')->alias(static function (int $code): void {
            throw new \RuntimeException('page ' . $code);
        });
        Functions\when('wp_safe_redirect')->alias(static function (string $url): void {
            throw new \RuntimeException($url);
        });
        // redirect() calls the three-argument form: key, value, URL.
        Functions\when('add_query_arg')->alias(
            static fn (string $key, string $value, string $url): string => $url . '?' . $key . '=' . $value
        );
    }

    /** @return array<string, mixed> */
    private static function liveSignInToken(?string $usedAt = null): array
    {
        return ['id' => 1, 'person_id' => 7, 'expires_at' => '2999-12-31 00:00:00', 'used_at' => $usedAt];
    }

    public function testOpeningTheEmailedSignInLinkShowsAButtonAndDoesNotUseTheToken(): void
    {
        // A mail scanner's GET: were the token consumed, this row would be read.
        $this->wpdb->nextRows[] = self::liveSignInToken();
        $this->wpdb->nextQueryResults[] = 1;

        $answer = $this->dispatchSignIn(['token' => 'rawtoken'], []);

        self::assertSame('page 200', $answer);
        self::assertCount(1, $this->wpdb->nextRows, 'The token row was never read.');
        self::assertSame([], $this->wpdb->queries, 'No UPDATE spent the token.');
        self::assertSame([], $this->wpdb->updates, 'Nobody was marked verified.');
    }

    public function testOpeningTheAccountLinkInAnEmailShowsAButtonAndDoesNotUseTheToken(): void
    {
        $this->wpdb->nextRows[] = self::liveSignInToken();
        $this->wpdb->nextQueryResults[] = 1;
        $_GET = ['eventcrew_login' => 'rawtoken'];
        Functions\when('remove_query_arg')->justReturn('https://site.test/crew/');
        $this->stubSignInExits();

        try {
            $this->controller()->maybeSignInFromEmailLink();
            self::fail('The controller sent no answer.');
        } catch (\RuntimeException $answer) {
            self::assertSame('page 200', $answer->getMessage());
        } finally {
            $_GET = [];
        }

        self::assertCount(1, $this->wpdb->nextRows, 'The token row was never read.');
        self::assertSame([], $this->wpdb->queries);
        self::assertSame([], $this->wpdb->updates);
    }

    public function testTheSignInButtonPostsTheTokenBackToTheSignInAction(): void
    {
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);

        $page = $this->controller()->signInButtonPage('rawtoken', 'https://site.test/crew/');

        self::assertStringContainsString(
            '<form method="post" action="https://site.test/wp-admin/admin-ajax.php">',
            $page
        );
        self::assertStringContainsString('name="action" value="' . SignupController::LOGIN_ACTION . '"', $page);
        self::assertStringContainsString('name="token" value="rawtoken"', $page);
        self::assertStringContainsString(
            'name="redirect_to" value="' . rawurlencode('https://site.test/crew/') . '"',
            $page
        );
        self::assertStringContainsString('>Sign in</button>', $page);
    }

    public function testTheSignInButtonSignsInOnceAndASecondPressFails(): void
    {
        // First POST: a live token, spent by the conditional UPDATE.
        $this->wpdb->nextRows[] = self::liveSignInToken();
        $this->wpdb->nextQueryResults[] = 1;
        $first = $this->dispatchSignIn([], ['token' => 'rawtoken', 'redirect_to' => 'https://site.test/crew/']);

        // Second POST of the same token: the row is now stamped used.
        $this->wpdb->nextRows[] = self::liveSignInToken('2026-11-05 10:00:00');
        $second = $this->dispatchSignIn([], ['token' => 'rawtoken', 'redirect_to' => 'https://site.test/crew/']);

        self::assertStringContainsString('eventcrew_notice=signed_in', $first);
        self::assertStringContainsString('eventcrew_notice=bad_link', $second);
        self::assertSame(1, substr_count(implode("\n", $this->wpdb->queries), 'used_at IS NULL'));
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

    /**
     * Q298: a site language other than Finnish and English can be chosen on
     * the page and is stored on the person.
     */
    public function testTheLanguageSwitchSavesAnyOtherSiteLanguage(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => SiteLanguages::OPTION === $name
                ? ['fi', 'en', 'sv']
                : $default
        );

        self::assertSame('language_saved', $this->controller()->chooseLanguage(7, 'sv'));

        self::assertCount(1, $this->wpdb->updates);
        self::assertSame('sv', $this->wpdb->updates[0]['data']['language']);
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

    /**
     * The Telegram sign-in switched on, with a bot token and username.
     * $options adds to or overrides those options.
     *
     * @param array<string, mixed> $options
     */
    private function telegramOn(array $options = []): void
    {
        $options += [
            TelegramLogin::ENABLED_OPTION => true,
            TelegramClient::TOKEN_OPTION => '123456:test-token',
            BoardService::USERNAME_OPTION => 'crewbot',
        ];

        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => $options[$name] ?? $default
        );
        Functions\when('update_option')->justReturn(true);
    }

    private function telegramLogin(): TelegramLogin
    {
        return new TelegramLogin(new Logger());
    }

    /**
     * A code started on the page for this browser and approved in the bot by
     * Telegram user 4242 for person 7. Returns the code and the browser's
     * secret.
     *
     * @return array{0: string, 1: string}
     */
    private function approvedCode(SignupController $controller, TelegramLogin $login): array
    {
        $started = $controller->startTelegramSignIn('203.0.113.7');
        self::assertSame('tg_started', $started['notice']);

        self::assertTrue($login->claimForTelegramUser($started['code'], 4242));
        self::assertTrue($login->approve($started['code'], 4242, 7));

        return [$started['code'], $started['secret']];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function linkedPersonRow(array $overrides = []): array
    {
        return $overrides + [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => 4242,
        ];
    }

    public function testPressingTelegramMakesACodeAndABotLinkForThisBrowser(): void
    {
        $this->telegramOn();

        $started = $this->controller($this->telegramLogin())->startTelegramSignIn('203.0.113.7');

        self::assertSame('tg_started', $started['notice']);
        self::assertTrue(TelegramLogin::isCode($started['code']));
        self::assertSame('https://t.me/crewbot?start=login-' . $started['code'], $started['url']);
        self::assertSame(30 * 60, $started['expires_in']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $started['secret']);
        // The 4-digit code the page shows; the bot shows the same one.
        self::assertMatchesRegularExpression('/^[0-9]{4}$/', $started['match']);
        self::assertSame($started['match'], $this->telegramLogin()->matchCode($started['code']));

        $stored = serialize($this->transients);
        self::assertStringNotContainsString($started['secret'], $stored, 'only a hash of the secret is stored');
        self::assertStringContainsString(hash('sha256', $started['secret']), $stored);
        self::assertSame([], $this->wpdb->queries, 'making a code touches no table');
    }

    public function testPressingTelegramTooOftenFromOneAddressIsRefused(): void
    {
        $this->telegramOn();
        $controller = $this->controller($this->telegramLogin());

        for ($i = 0; $i < 10; $i++) {
            self::assertSame('tg_started', $controller->startTelegramSignIn('203.0.113.7')['notice']);
        }

        self::assertSame(['notice' => 'tg_busy'], $controller->startTelegramSignIn('203.0.113.7'));
        self::assertSame('tg_started', $controller->startTelegramSignIn('203.0.113.8')['notice']);
    }

    public function testPressingTelegramFailsWhenTheSignInIsSwitchedOff(): void
    {
        $this->telegramOn([TelegramLogin::ENABLED_OPTION => false]);

        self::assertSame(
            ['notice' => 'tg_failed'],
            $this->controller($this->telegramLogin())->startTelegramSignIn('203.0.113.7')
        );
        self::assertSame([], $this->transients);
    }

    public function testTheStatusIsPendingUntilTheBotApprovesAndReadsNoTable(): void
    {
        $this->telegramOn();
        $started = $this->controller($this->telegramLogin())->startTelegramSignIn('203.0.113.7');

        self::assertSame(
            ['person_id' => null, 'notice' => 'tg_pending'],
            $this->controller($this->telegramLogin())->telegramStatus($started['code'], $started['secret'])
        );
        self::assertSame([], $this->wpdb->queries);
    }

    public function testAnApprovedCodeSignsInThePersonOnceAndWritesNothing(): void
    {
        $this->telegramOn();
        $login = $this->telegramLogin();
        $controller = $this->controller($login);
        [$code, $secret] = $this->approvedCode($controller, $login);
        $this->wpdb->nextRows[] = $this->linkedPersonRow();

        self::assertSame(['person_id' => 7, 'notice' => 'signed_in'], $controller->telegramStatus($code, $secret));
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->wpdb->inserts);

        self::assertSame(
            ['person_id' => null, 'notice' => 'tg_expired'],
            $controller->telegramStatus($code, $secret),
            'a used code does not sign in a second time'
        );
    }

    public function testAWrongCookieCannotSignIn(): void
    {
        $this->telegramOn();
        $login = $this->telegramLogin();
        $controller = $this->controller($login);
        [$code] = $this->approvedCode($controller, $login);
        $this->wpdb->nextRows[] = $this->linkedPersonRow();

        self::assertSame(
            ['person_id' => null, 'notice' => 'tg_expired'],
            $controller->telegramStatus($code, str_repeat('0', 64))
        );
        self::assertSame(
            ['person_id' => null, 'notice' => 'tg_expired'],
            $controller->telegramStatus($code, '')
        );
        self::assertSame([], $this->wpdb->queries, 'nobody is looked up for a wrong cookie');
    }

    public function testAnExpiredCodeSaysExpired(): void
    {
        $this->telegramOn();
        $login = $this->telegramLogin();
        $controller = $this->controller($login);
        [$code, $secret] = $this->approvedCode($controller, $login);

        foreach ($this->transients as $key => $value) {
            if (is_array($value) && isset($value['created'])) {
                $this->transients[$key]['created'] -= TelegramLogin::TTL + 1;
            }
        }

        self::assertSame(['person_id' => null, 'notice' => 'tg_expired'], $controller->telegramStatus($code, $secret));
        self::assertSame(
            'The link expired. Press Telegram again.',
            SignupController::noticeText('tg_expired')
        );
    }

    public function testAnApprovedCodeDoesNotSignInAPersonBlockedSince(): void
    {
        $this->telegramOn();
        $login = $this->telegramLogin();
        $controller = $this->controller($login);
        [$code, $secret] = $this->approvedCode($controller, $login);
        $this->wpdb->nextRows[] = $this->linkedPersonRow(['banned_at' => '2026-08-01 00:00:00']);

        self::assertSame(['person_id' => null, 'notice' => 'blocked'], $controller->telegramStatus($code, $secret));
    }

    public function testAnApprovedCodeDoesNotSignInAPersonWithoutAConfirmedEmail(): void
    {
        $this->telegramOn();
        $login = $this->telegramLogin();
        $controller = $this->controller($login);
        [$code, $secret] = $this->approvedCode($controller, $login);
        $this->wpdb->nextRows[] = $this->linkedPersonRow(['email_verified_at' => null]);

        self::assertSame(['person_id' => null, 'notice' => 'tg_failed'], $controller->telegramStatus($code, $secret));
    }

    public function testTheSignedOutViewOffersTelegramWhenTheLoginIsAvailable(): void
    {
        $this->telegramOn();
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];

        $login = $this->controller($this->telegramLogin())->viewModel()['telegram_login'];

        self::assertSame(
            [
                'start_action' => SignupController::TG_START_ACTION,
                'status_action' => SignupController::TG_STATUS_ACTION,
            ],
            $login
        );
    }

    /**
     * Signed out with the Telegram sign-in on, the card has the code panel:
     * "Your code", two empty boxes the script fills, the line on what to do
     * and the Open Telegram link. The page is cached, so no code and no bot
     * link are in it, and nothing opens a window by itself.
     */
    public function testTheSignedOutCardHasTheCodePanelWithoutACode(): void
    {
        $this->stubPageRender();
        Functions\when('wp_unslash')->returnArg(1);
        $this->telegramOn();
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];

        $html = $this->controller($this->telegramLogin())->renderShortcode();

        self::assertStringContainsString('data-eventcrew-tg-wait hidden', $html);
        self::assertStringContainsString('>Your code<', $html);
        self::assertSame(2, substr_count($html, '<span class="eventcrew-tg-pair" data-eventcrew-tg-pair></span>'));
        self::assertStringContainsString('In Telegram, press Start. It then shows three codes: press this one.', $html);
        self::assertMatchesRegularExpression(
            '/<a class="tg-auth-button" href="#" target="_blank" rel="noopener" data-eventcrew-tg-open>\s*'
            . 'Open Telegram\s*<\/a>/',
            $html
        );
        self::assertStringContainsString('Waiting for Telegram…', $html);
        self::assertStringNotContainsString(
            't.me/crewbot',
            $html,
            'the bot link comes with the code, not in the page'
        );
        self::assertStringNotContainsString(
            "window.open('', '_blank')",
            $html,
            'Telegram opens only from the button'
        );
        self::assertStringEndsWith('</script>', rtrim($html));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function telegramUnavailable(): array
    {
        return [
            'switch off' => [[TelegramLogin::ENABLED_OPTION => false]],
            'no bot token' => [[TelegramClient::TOKEN_OPTION => '']],
            'no bot username' => [[BoardService::USERNAME_OPTION => '']],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @dataProvider telegramUnavailable
     */
    public function testTheSignedOutViewHasNoTelegramLoginWhenItIsUnavailable(array $options): void
    {
        $this->telegramOn($options);
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];

        self::assertNull($this->controller($this->telegramLogin())->viewModel()['telegram_login']);
    }

    public function testTheNewNoticesHaveWords(): void
    {
        foreach (['tg_started', 'tg_expired', 'tg_busy', 'tg_failed'] as $code) {
            self::assertNotSame('', SignupController::noticeText($code), $code);
        }

        self::assertSame(
            'Open Telegram, press Start, then press the code shown on this page. This page signs you in by itself.',
            SignupController::noticeText('tg_started')
        );
    }

    public function testTheTelegramWidgetIsGone(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/src/*/*.php') ?: [],
            glob($root . '/templates/*/*.php') ?: [],
            glob($root . '/assets/*.css') ?: []
        );

        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            foreach (['oauth.telegram.org', 'telegram-login.js', 'id_token', 'jwks', 'Telegram.Login', 'eventcrew_tg_nonce'] as $gone) {
                self::assertStringNotContainsStringIgnoringCase($gone, $source, $gone . ' in ' . $file);
            }
        }
    }
}

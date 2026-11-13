<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Support\AppInstallPrompt;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CheckinRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TakeoverRepository;
use EventCrew\Repositories\TicketTransferRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\NotificationPreferences;
use EventCrew\Support\PageLanguage;
use EventCrew\Support\Roles;
use EventCrew\Support\SignupService;
use EventCrew\Support\SiteLanguages;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\TakeoverService;
use EventCrew\Support\TelegramLogin;
use EventCrew\Support\TicketTransferService;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\JoinAnswers;
use EventCrew\Support\LoginCode;
use EventCrew\Support\Turnstile;
use EventCrew\Support\WebSession;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\BoardBanner;
use EventCrew\Telegram\DohResolver;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Telegram\TicketRedemptionService;
use EventCrew\Tests\Support\FakeEventNames;
use EventCrew\Tests\Support\MeTabWpdb;
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

    private function controller(
        ?TelegramLogin $telegramLogin = null,
        ?TelegramClient $telegram = null,
        ?TicketTransferService $transfers = null,
        ?TakeoverService $takeovers = null
    ): SignupController
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
            $telegramLogin,
            $telegram,
            $transfers,
            $takeovers
        );
    }

    /** A real TakeoverService over the test's wpdb, for the board's Take someone's task. */
    private function takeovers(): TakeoverService
    {
        $assignments = new AssignmentRepository();
        $standing = new StandingCalculator($assignments, new RedemptionRepository(), new CreditGrantRepository());
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()));
        $telegram = new TelegramClient(new Logger(), new DohResolver(new Logger()));
        $notifier = new ClaimNotifier(new TaskRepository(), $assignments, $mailer, $telegram, $standing);

        return new TakeoverService(
            new TakeoverRepository(),
            $assignments,
            new TaskRepository(),
            new PersonRepository(),
            $notifier,
            new BoardService(
                new TaskRepository(),
                $assignments,
                new PersonRepository(),
                $telegram,
                new Logger(),
                $notifier,
                new SignupService($assignments, $standing, new PersonRepository(), new TaskRepository())
            ),
            $telegram,
            $mailer
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
     * With a site key the sign-in form gets the widget box and the small
     * loader, never Cloudflare's script tag itself: api.js is added only when
     * the visitor starts using the form.
     */
    public function testWithASiteKeyTheSignInFormLoadsCloudflareOnlyOnDemand(): void
    {
        $this->stubPageRender();
        Functions\when('get_option')->alias(
            static fn (string $name): mixed => Turnstile::SITE_KEY_OPTION === $name ? 'site-key' : false
        );
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];

        $html = $this->controller()->renderShortcode();

        self::assertStringContainsString('class="cf-turnstile eventcrew-turnstile"', $html);
        self::assertSame(1, substr_count($html, 'if(window.eventcrewTs){return;}'));
        self::assertStringNotContainsString('<script src="' . Turnstile::SCRIPT_URL, $html);
        self::assertStringNotContainsString('challenges.cloudflare.com/turnstile/v0/api.js"', $html);
    }

    /** Without a site key there is no widget and no loader: nothing changes. */
    public function testWithoutASiteKeyTheSignInFormHasNoCloudflareCode(): void
    {
        $this->stubPageRender();
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];

        $html = $this->controller()->renderShortcode();

        self::assertStringContainsString('data-eventcrew-signin', $html);
        self::assertStringNotContainsString('class="cf-turnstile', $html);
        self::assertStringNotContainsString('if(window.eventcrewTs){return;}', $html);
        self::assertStringNotContainsString('challenges.cloudflare', $html);
    }

    public function testAnExpiredSignInLinkSaysSoAboveTheFormAndKeepsTheTarget(): void
    {
        $this->stubPageRender();
        $_GET = ['eventcrew_notice' => 'bad_link'];
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('remove_query_arg')->justReturn('/signup/?tab=me');
        Functions\when('wp_validate_redirect')->returnArg(1);

        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];

        try {
            $html = $this->controller()->renderShortcode();
        } finally {
            $_GET = [];
        }

        self::assertStringContainsString(
            '<p class="eventcrew-signin-expired" role="status">This sign-in link has expired.</p>',
            $html
        );
        // The line, not the toast.
        self::assertStringContainsString('aria-live="polite"></div>', $html);
        // The new link goes back to the page the old one opened.
        self::assertStringContainsString('name="redirect_to" value="/signup/?tab=me"', $html);
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

    /**
     * Signed out, the sign-in card wears the banner the Telegram board message
     * carries (BoardBanner::url()), not an event's picture.
     */
    public function testSignedOutTheSignInCardShowsTheBoardBanner(): void
    {
        $this->stubPageRender();
        $this->chooseBannerPage();
        Functions\when('get_the_post_thumbnail_url')->justReturn('https://site.test/night.jpg');

        $this->wpdb->nextResults[] = [$this->taskRow()]; // upcoming
        $this->wpdb->nextResults[] = [];                 // occupancyFor

        $html = $this->controller()->renderShortcode();

        self::assertMatchesRegularExpression(
            '~class="eventcrew-event eventcrew-me-card eventcrew-signin-card has-image" id="eventcrew-signin"'
            . ' style="--eventcrew-event-image: url\(&quot;https://site\.test/uploads/eventcrew/board-banner-823-[0-9a-f]+\.jpg&quot;\)"~',
            $html
        );
    }

    public function testWithoutABannerTheSignInCardIsPlain(): void
    {
        $this->stubPageRender();
        Functions\when('get_the_post_thumbnail_url')->justReturn('https://site.test/night.jpg');

        $this->wpdb->nextResults[] = [$this->taskRow()];
        $this->wpdb->nextResults[] = [];

        $html = $this->controller()->renderShortcode();

        self::assertStringContainsString(
            '<section class="eventcrew-event eventcrew-me-card eventcrew-signin-card" id="eventcrew-signin">',
            $html
        );
    }

    /**
     * Chooses page 822 for the board banner, with featured image 823 on file;
     * the strip is "made" by a stub image editor.
     */
    private function chooseBannerPage(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'ecb');

        Functions\when('get_option')->alias(
            static fn (string $name): mixed => match ($name) {
                'eventcrew_telegram_group_link' => 'https://t.me/+secretcrew',
                BoardBanner::PAGE_OPTION => 822,
                default => false,
            }
        );
        Functions\when('get_post')->alias(static function (int $id): ?object {
            if (822 !== $id) {
                return null;
            }

            $post = new \WP_Post();
            $post->ID = 822;
            $post->post_status = 'publish';

            return $post;
        });
        Functions\when('parse_blocks')->justReturn([]);
        Functions\when('get_post_thumbnail_id')->justReturn(823);
        Functions\when('get_attached_file')->justReturn($file);
        Functions\when('wp_upload_dir')->justReturn([
            'basedir' => sys_get_temp_dir() . '/ec-signin-banner-' . getmypid(),
            'baseurl' => 'https://site.test/uploads',
            'error' => false,
        ]);
        Functions\when('trailingslashit')->alias(static fn (string $path): string => rtrim($path, '/') . '/');
        Functions\when('wp_mkdir_p')->justReturn(true);
        Functions\when('wp_delete_file')->justReturn(null);
        Functions\when('wp_get_image_editor')->justReturn(new class () {
            /** @return array{width: int, height: int} */
            public function get_size(): array // phpcs:ignore PSR1.Methods.CamelCapsMethodName
            {
                return ['width' => 1200, 'height' => 800];
            }

            public function crop(): bool
            {
                return true;
            }

            public function set_quality(): bool // phpcs:ignore PSR1.Methods.CamelCapsMethodName
            {
                return true;
            }

            public function save(): bool
            {
                return true;
            }
        });
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
     * Signed in, the crew guide sits under the person's details in the header
     * card, not at the top of the page (Owner 2026-11-08).
     */
    public function testSignedInTheCrewGuideSitsUnderThePersonsDetails(): void
    {
        $this->stubPageRender();
        $this->setGuideUrl();
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('checked')->justReturn(null);
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

        // The Telegram group is a text link at the bottom (Owner 2026-11-13).
        self::assertSame(1, preg_match('~<p class="eventcrew-grouplink">.*?</p>~s', $html, $links));
        self::assertStringContainsString('https://t.me/+secretcrew', $links[0]);
        self::assertStringContainsString('class="eventcrew-textlink"', $links[0]);
        self::assertStringNotContainsString('Crew guide', $links[0]);
        self::assertGreaterThan(strrpos($html, 'data-eventcrew-panel='), strpos($html, 'eventcrew-grouplink'));
        // The Summary is on the Board tab only, at its top (Owner 2026-11-13).
        self::assertMatchesRegularExpression('~data-eventcrew-panel="board"[^>]*>\s*<section class="eventcrew-event eventcrew-me-card eventcrew-header-card" id="eventcrew-summary">~', $html);
        self::assertSame(1, preg_match('~<section class="eventcrew-event eventcrew-me-card eventcrew-header-card" id="eventcrew-summary">.*?</section>~s', $html, $card));
        self::assertStringContainsString('class="eventcrew-card-body eventcrew-header-guide"', $card[0]);
        self::assertStringContainsString('href="https://docs.example/guide"', $card[0]);
        // Once under the details, once in the Help tab (Owner 2026-11-08).
        self::assertSame(2, substr_count($html, 'Crew guide'));
    }

    /**
     * Owner 2026-11-08: the Help tab has the bot's Help menu parts and names:
     * How this works?, Commands (with a bot to use them in) and FAQ as
     * disclosures, with the crew guide linked from the top.
     */
    public function testTheHelpTabHasTheBotsHelpParts(): void
    {
        $this->stubPageRender();
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => match ($name) {
                Roles::GUIDE_URL_OPTION => 'https://docs.example/guide',
                'eventcrew_telegram_bot_username' => '@CrewBot',
                default => $default,
            }
        );
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('checked')->justReturn(null);
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

        $start = strpos($html, 'data-eventcrew-panel="help"');
        self::assertNotFalse($start);
        $help = substr($html, $start);
        self::assertSame(1, preg_match('~<p class="eventcrew-links eventcrew-help-orglink">(.*?)</p>~s', $help, $links));
        self::assertStringContainsString('href="#eventcrew-help-guide">📖 How this works?</a>', $links[1]);
        self::assertStringContainsString('href="#eventcrew-help-commands">⌨️ Commands</a>', $links[1]);
        self::assertStringContainsString('href="#eventcrew-help-faq">💡 FAQ</a>', $links[1]);
        self::assertStringContainsString('href="https://docs.example/guide"', $links[1]);
        self::assertStringNotContainsString('Organizer guide', $help);

        $guide = strpos($help, 'id="eventcrew-help-guide"');
        $commands = strpos($help, 'id="eventcrew-help-commands"');
        $faq = strpos($help, 'id="eventcrew-help-faq"');
        self::assertNotFalse($guide);
        self::assertGreaterThan($guide, $commands);
        self::assertGreaterThan($commands, $faq);
        self::assertStringContainsString('<li>/help – how this works, commands and FAQ</li>', $help);
        self::assertStringContainsString('href="https://t.me/CrewBot"', $help);
        self::assertStringContainsString('<summary>How do I sign up for a task?</summary>', $help);
        self::assertSame(count(\EventCrew\Support\Faq::items()), substr_count($help, '<details class="eventcrew-faq-item">'));
    }

    public function testWithoutABotTheHelpTabHasNoCommands(): void
    {
        $this->stubPageRender();
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('checked')->justReturn(null);
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);

        $person = [
            'id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
        ];
        $this->wpdb->nextRows[] = $person;
        $this->wpdb->nextRows[] = $person;
        $this->wpdb->nextResults[] = [$this->taskRow()];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextVars[] = 0;

        $html = $this->controller()->renderShortcode();

        self::assertStringContainsString('id="eventcrew-help-guide"', $html);
        self::assertStringContainsString('id="eventcrew-help-faq"', $html);
        self::assertStringNotContainsString('eventcrew-help-commands', $html);
    }

    /**
     * Owner 2026-11-08: the header card has no 🎟 Tickets button any more,
     * only the crew guide link, centred; the Tickets card is on the Me tab.
     */
    public function testSignedInTheHeaderCardHasOnlyTheCentredGuideLink(): void
    {
        $this->stubPageRender();
        $this->setGuideUrl();
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('checked')->justReturn(null);
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);

        $person = [
            'id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
        ];
        $this->wpdb->nextRows[] = $person;
        $this->wpdb->nextRows[] = $person;
        $this->wpdb->nextResults[] = [$this->taskRow()];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextVars[] = 0;

        $html = $this->controller()->renderShortcode();

        self::assertSame(1, preg_match('~<section class="eventcrew-event eventcrew-me-card eventcrew-header-card" id="eventcrew-summary">.*?</section>~s', $html, $card));
        // The 🎟️ in front of the credits line is an icon, not the old button.
        self::assertDoesNotMatchRegularExpression('~<a[^>]*>\s*🎟~u', $card[0]);
        self::assertStringNotContainsString('eventcrew-my-tickets-btn', $html);
        self::assertStringNotContainsString('wp-element-button', $card[0]);
        self::assertMatchesRegularExpression('~<div class="eventcrew-card-body eventcrew-header-guide">\s*<p>\s*<a class="eventcrew-textlink"[^>]*>[^<]*Crew guide~', $card[0]);
        self::assertStringContainsString('id="eventcrew-my-tickets"', $html);
    }

    /**
     * Owner 2026-11-08: the web uses the bot's words. The header card shows
     * "Completed tasks: N" and "Free-entry credits: N"; the Tasks card is
     * titled and emptied like the bot's Tasks, with a link to the board.
     */
    public function testSignedInTheMeTabUsesTheBotsWords(): void
    {
        $this->stubPageRender();
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('checked')->justReturn(null);
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);

        $person = [
            'id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
        ];
        $this->wpdb->nextRows[] = $person;
        $this->wpdb->nextRows[] = $person;
        $this->wpdb->nextResults[] = [$this->taskRow()];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextVars[] = 0;

        $html = $this->controller()->renderShortcode();

        self::assertSame(1, preg_match('~<section class="eventcrew-event eventcrew-me-card eventcrew-header-card" id="eventcrew-summary">.*?</section>~s', $html, $card));
        self::assertStringContainsString('Completed tasks: 0', $card[0]);
        self::assertStringContainsString('Free-entry credits: 0', $card[0]);
        // One line each with an icon (Owner 2026-11-08), each a link to its
        // card; the standing itself is shown once, above (Owner 2026-11-13).
        self::assertMatchesRegularExpression('~<ul class="eventcrew-standing">\s*<li><span class="eventcrew-standing-icon" aria-hidden="true">✅</span><a class="eventcrew-textlink" href="#eventcrew-past-tasks" data-eventcrew-goto="eventcrew-past-tasks">Completed tasks: 0</a></li>\s*<li><span class="eventcrew-standing-icon" aria-hidden="true">🎟️</span><span><a class="eventcrew-textlink" href="#eventcrew-my-tickets" data-eventcrew-goto="eventcrew-my-tickets">Free-entry credits: 0</a></span></li>\s*</ul>~s', $card[0]);
        self::assertStringContainsString('<h2 class="eventcrew-event-title">Summary</h2>', $card[0]);
        self::assertStringNotContainsString('🌟', $card[0]);
        self::assertStringNotContainsString('👤', $card[0]);
        self::assertStringNotContainsString('Signed in as', $html);
        self::assertStringNotContainsString('&middot;', $card[0]);
        self::assertStringNotContainsString('0 credits', $html);
        self::assertStringContainsString('<h3 class="eventcrew-event-title">Tasks</h3>', $html);
        self::assertStringNotContainsString('My tasks', $html);
        self::assertMatchesRegularExpression('~No coming tasks\.\s*<a class="eventcrew-textlink" href="#eventcrew-panel-board" data-eventcrew-goto="eventcrew-panel-board">See open tasks</a>~', $html);
    }

    /**
     * Owner 2026-11-08: each row of the Me tab's Tasks card has a Cancel text
     * link, the board's drop action with the board's question, which reloads
     * the page onto the card afterwards.
     */
    public function testTheTasksCardHasACancelLinkOnEachRow(): void
    {
        $this->stubPageRender();
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('checked')->justReturn(null);
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);
        $this->useMeTabWpdb();

        $html = $this->controller()->renderShortcode();

        self::assertSame(1, preg_match('~<section class="eventcrew-event eventcrew-me-card" id="eventcrew-my-tasks">.*?</section>~s', $html, $card));
        self::assertStringContainsString('data-eventcrew-reload="eventcrew-my-tasks"', $card[0]);
        self::assertStringContainsString('value="' . SignupController::DROP_ACTION . '"', $card[0]);
        self::assertStringContainsString('name="task_id" value="5"', $card[0]);
        self::assertMatchesRegularExpression('~data-eventcrew-confirm="[^"]*Cancel[^"]+"~', $card[0]);
        self::assertStringContainsString('<button type="submit" class="eventcrew-textlink">Cancel</button>', $card[0]);
    }

    /**
     * Owner 2026-11-12: a signed-in person gets the "Get the app" pop-in
     * (its script shows it only where the app can be installed), with the
     * app's short name; a signed-out visitor gets none of it.
     */
    public function testTheAppPopInIsOnTheSignedInPageOnly(): void
    {
        $this->stubPageRender();
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('checked')->justReturn(null);
        Functions\when('get_bloginfo')->justReturn('Ecstatic Dance Helsinki');
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);
        $this->useMeTabWpdb();

        $html = $this->controller()->renderShortcode();

        self::assertStringContainsString('data-popin="1"', $html);
        self::assertStringContainsString('<dialog class="eventcrew-app-dialog"', $html);
        self::assertStringContainsString('Get the EDH Crew app', $html);
        self::assertStringContainsString('data-action="eventcrew_app_install"', $html);
        self::assertStringContainsString('id="eventcrew-notifications"', $html);
        self::assertStringContainsString('data-eventcrew-goto="eventcrew-notifications"', $html);
        // Owner 2026-11-13: after an install the tab says so, with Open the
        // app (the app's start address), and the Open in the app line is a
        // clear line with the icon, no longer muted small print.
        self::assertStringContainsString('data-eventcrew-app-step="installed"', $html);
        self::assertStringContainsString('Open EDH Crew from your home screen.', $html);
        self::assertStringContainsString('data-eventcrew-app-launch>Open the app</button>', $html);
        self::assertMatchesRegularExpression('/data-start="https?:[^"]*eventcrew_app=[^"]*"/', $html);
        self::assertStringContainsString('<p class="eventcrew-app-openline" data-eventcrew-app-open hidden>', $html);
        // Owner 2026-11-13: the group's Get the app link opens the pop-in
        // (data-invite-param), which says how small the app is and has the
        // manual steps for Safari, Firefox and desktop browsers.
        self::assertStringContainsString('data-invite-param="eventcrew_app_invite"', $html);
        self::assertStringContainsString('Tiny: smaller than one photo.', $html);
        self::assertStringContainsString('data-eventcrew-app-how="ios"', $html);
        self::assertStringContainsString('data-eventcrew-app-how="menu"', $html);
        self::assertStringContainsString('data-eventcrew-app-how="desktop"', $html);

        unset($_COOKIE[WebSession::COOKIE]);
        $this->wpdb = new \FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = [];

        self::assertStringNotContainsString('data-eventcrew-app', $this->controller()->renderShortcode());
    }

    /**
     * Owner 2026-11-13: a tap on Turn on notifications once showed nothing.
     * Every outcome has its line now: the question not shown, the app still
     * loading, and the failed line with a place for the error's name; the
     * script waits 8 seconds for an answer and 5 for the service worker.
     */
    public function testTurnOnNotificationsHasALineForEveryOutcome(): void
    {
        $this->stubPageRender();
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => \EventCrew\Support\VapidKeys::OPTION === $name
                ? ['private' => 'pem', 'public' => 'BPublicKey']
                : $default
        );
        $eventcrew_ajax = 'https://site.test/wp-admin/admin-ajax.php';
        $eventcrew_csrf = 'token';

        ob_start();
        require dirname(__DIR__, 2) . '/templates/public/signup-push.php';
        $html = (string) ob_get_clean();

        self::assertStringContainsString('data-key="BPublicKey"', $html);
        // One on/off switch (Owner 2026-11-13), not two links.
        self::assertStringContainsString('role="switch" aria-checked="false" data-eventcrew-push-on>', $html);
        self::assertStringContainsString('Notifications on this device', $html);
        self::assertStringNotContainsString('data-eventcrew-push-off', $html);
        self::assertStringContainsString('data-eventcrew-push-stuck hidden>', $html);
        self::assertStringContainsString('data-eventcrew-push-dismissed hidden>Your phone did not show the question. Allow notifications for this site in the browser', $html);
        self::assertStringContainsString('data-eventcrew-push-loading hidden>The app is still loading. Reload the page and try again.</p>', $html);
        self::assertStringContainsString('Could not turn on notifications. Try again. <span class="eventcrew-push-error" data-eventcrew-push-error></span>', $html);
        self::assertStringContainsString('}, 8000);', $html);
        self::assertStringContainsString('}, 5000);', $html);
        // After subscribing it may still ask where notifications go.
        self::assertStringContainsString("new CustomEvent('eventcrew:ask-channels')", $html);
    }

    /** The signed-in person 7 (Sam), on task 5 (2026-08-01), through MeTabWpdb. */
    private function useMeTabWpdb(): MeTabWpdb
    {
        $db = new MeTabWpdb();
        $db->people[7] = [
            'id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
        ];
        $db->tasks[] = $this->taskRow();
        $db->assignments[] = [
            'id' => 70, 'task_id' => 5, 'person_id' => 7, 'status' => 'signed_up',
            'signed_up_at' => '2026-07-01 10:00:00',
        ];
        $this->wpdb = $db;
        $GLOBALS['wpdb'] = $db;

        return $db;
    }

    /** Signed out, a full task offers Take someone's task, through the sign-in card. */
    public function testSignedOutAFullTaskOffersTakeSomeonesTask(): void
    {
        $this->stubPageRender();
        $this->wpdb->nextResults[] = [$this->taskRow()];
        $this->wpdb->nextResults[] = [['task_id' => 5, 'taken' => 2]];

        $html = $this->controller()->renderShortcode();

        self::assertStringContainsString(
            '<a class="eventcrew-textlink" href="#eventcrew-signin" data-eventcrew-signin-task="5">Take someone’s task</a>',
            $html
        );
        // A text link on its own line under the row, not a button beside the count.
        self::assertStringContainsString('<span class="eventcrew-task-takeover">', $html);
        self::assertStringNotContainsString('eventcrew-btn-full', $html);
    }

    /**
     * Signed in, a full task the person is not on lists its holders as small
     * Take someone's task forms (Owner 2026-11-08); the person's own full
     * task keeps its Cancel.
     */
    public function testSignedInAFullTaskListsItsHoldersToTakeOver(): void
    {
        $this->stubPageRender();
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('checked')->justReturn(null);
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);
        $db = $this->useMeTabWpdb();
        $db->people[8] = ['id' => 8, 'email' => 'a@example.com', 'display_name' => 'Anna Virtanen'];
        $db->people[9] = ['id' => 9, 'email' => 'b@example.com', 'display_name' => 'Bo'];
        $db->tasks[] = array_merge($this->taskRow(), ['id' => 6]);
        $db->assignments[] = ['id' => 80, 'task_id' => 6, 'person_id' => 8, 'status' => 'signed_up', 'signed_up_at' => '2026-07-01 10:00:00'];
        $db->assignments[] = ['id' => 90, 'task_id' => 6, 'person_id' => 9, 'status' => 'signed_up', 'signed_up_at' => '2026-07-02 10:00:00'];

        $html = $this->controller(null, null, null, $this->takeovers())->renderShortcode();

        self::assertSame(1, preg_match('~<li[^>]*id="eventcrew-task-6".*?</li>~s', $html, $row));
        self::assertStringContainsString('<summary class="eventcrew-textlink">Take someone’s task</summary>', $row[0]);
        self::assertStringContainsString('<details class="eventcrew-credit-ask eventcrew-task-takeover">', $row[0]);
        self::assertStringContainsString('value="' . SignupController::TAKEOVER_ACTION . '"', $row[0]);
        self::assertStringContainsString('name="assignment_id" value="80"', $row[0]);
        self::assertStringContainsString('<button type="submit" class="eventcrew-textlink">Anna</button>', $row[0]);
        self::assertStringContainsString('<button type="submit" class="eventcrew-textlink">Bo</button>', $row[0]);
        self::assertSame(1, preg_match('~<li[^>]*id="eventcrew-task-5".*?</li>~s', $html, $own));
        self::assertStringNotContainsString(SignupController::TAKEOVER_ACTION, $own[0]);
        // Full: Take someone's task stands in for Full, as before.
        self::assertStringNotContainsString('eventcrew-btn-full', $row[0]);
        self::assertStringNotContainsString('value="' . SignupController::CLAIM_ACTION . '"', $row[0]);
    }

    /**
     * Signed in, a task with room and someone on it keeps Sign up and offers
     * Take someone's task under the row as well, as the bot's board does
     * (Owner 2026-11-11); a task with nobody on it offers no takeover.
     */
    public function testSignedInATaskWithRoomListsItsHoldersBesideSignUp(): void
    {
        $this->stubPageRender();
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('checked')->justReturn(null);
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);
        $db = $this->useMeTabWpdb();
        $db->people[8] = ['id' => 8, 'email' => 'a@example.com', 'display_name' => 'Anna Virtanen'];
        $db->tasks[] = array_merge($this->taskRow(), ['id' => 6]);
        $db->tasks[] = array_merge($this->taskRow(), ['id' => 8]);
        $db->assignments[] = ['id' => 80, 'task_id' => 6, 'person_id' => 8, 'status' => 'signed_up', 'signed_up_at' => '2026-07-01 10:00:00'];

        $html = $this->controller(null, null, null, $this->takeovers())->renderShortcode();

        self::assertSame(1, preg_match('~<li[^>]*id="eventcrew-task-6".*?</li>~s', $html, $row));
        self::assertStringContainsString('value="' . SignupController::CLAIM_ACTION . '"', $row[0]);
        self::assertStringContainsString('<button type="submit" class="wp-element-button eventcrew-btn-go">Sign up</button>', $row[0]);
        self::assertStringContainsString('<details class="eventcrew-credit-ask eventcrew-task-takeover">', $row[0]);
        self::assertStringContainsString('name="assignment_id" value="80"', $row[0]);
        self::assertStringContainsString('<button type="submit" class="eventcrew-textlink">Anna</button>', $row[0]);
        self::assertSame(1, preg_match('~<li[^>]*id="eventcrew-task-8".*?</li>~s', $html, $empty));
        self::assertStringContainsString('value="' . SignupController::CLAIM_ACTION . '"', $empty[0]);
        self::assertStringNotContainsString(SignupController::TAKEOVER_ACTION, $empty[0]);
        self::assertSame(1, preg_match('~<li[^>]*id="eventcrew-task-5".*?</li>~s', $html, $own));
        self::assertStringNotContainsString(SignupController::TAKEOVER_ACTION, $own[0]);
    }

    /** Signed out, a task with room and someone on it shows only Sign up. */
    public function testSignedOutATaskWithRoomOffersNoTakeover(): void
    {
        $this->stubPageRender();
        $this->wpdb->nextResults[] = [$this->taskRow()];
        $this->wpdb->nextResults[] = [['task_id' => 5, 'taken' => 1]];

        $html = $this->controller(null, null, null, $this->takeovers())->renderShortcode();

        self::assertStringContainsString('data-eventcrew-signin-task="5">Sign up</a>', $html);
        self::assertStringNotContainsString('Take someone’s task', $html);
        self::assertStringNotContainsString('eventcrew-task-takeover', $html);
    }

    /**
     * The Tickets card gives a free-entry ticket away, or shows the offer
     * waiting on it with Cancel offer (Owner 2026-11-08), as text links.
     */
    public function testTheTicketsCardOffersGiveAwayAndCancelOffer(): void
    {
        $this->stubPageRender();
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('checked')->justReturn(null);
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);
        $db = $this->useMeTabWpdb();
        $db->redemptions[] = self::redemptionRow(10, '2026-08-01');
        $db->redemptions[] = self::redemptionRow(11, '2026-08-02');
        $db->transfers[] = [
            'id' => 1, 'redemption_id' => 11, 'event_date' => '2026-08-02', 'giver_person_id' => 7,
            'receiver_person_id' => null, 'name' => 'Dee', 'email' => 'dee@example.com', 'status' => 'pending',
        ];

        Functions\when('get_option')->alias(static fn (string $name, mixed $default = false): mixed => $default);
        $html = $this->controller(null, null, $this->transfers())->renderShortcode();

        self::assertSame(1, preg_match('~<section class="eventcrew-event eventcrew-me-card" id="eventcrew-my-tickets">.*?</section>~s', $html, $card));
        self::assertSame(1, substr_count($card[0], '<summary class="eventcrew-textlink">Give away</summary>'));
        self::assertStringContainsString('value="' . SignupController::GIVE_ACTION . '"', $card[0]);
        self::assertStringContainsString('name="redemption_id" value="10"', $card[0]);
        self::assertStringContainsString('Waiting for Dee to accept', $card[0]);
        self::assertStringContainsString('value="' . SignupController::CANCEL_OFFER_ACTION . '"', $card[0]);
        self::assertStringContainsString('name="redemption_id" value="11"', $card[0]);
        self::assertStringContainsString('<button type="submit" class="eventcrew-textlink">Cancel offer</button>', $card[0]);
        self::assertSame(2, substr_count($card[0], 'data-eventcrew-reload="eventcrew-my-tickets"'));
    }

    /**
     * The Tasks and Tickets tabs' cards in order (Owner 2026-11-13): Tasks,
     * Past tasks, then Tickets, Past tickets; each entry shows its day, the event's clean name
     * and its DJs, and its actions as text links.
     */
    public function testTheMeTabCardsComeInOrderWithTheDjNames(): void
    {
        $this->stubPageRender();
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('checked')->justReturn(null);
        Functions\when('get_the_title')->justReturn('ECSTATIC DANCE / Esimerkki');
        Functions\when('get_option')->alias(static fn (string $name, mixed $default = false): mixed => $default);
        $this->fakeEventNames([42 => ['en' => 'Ecstatic Dance']], [42 => ['Esimerkki', 'Toinen']]);
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);
        $db = $this->useMeTabWpdb();
        $past = self::redemptionRow(10, '2026-07-01');
        $past['event_post_id'] = 42;
        $coming = self::redemptionRow(11, '2026-08-02');
        $coming['event_post_id'] = 42;
        $db->redemptions[] = $past;
        $db->redemptions[] = $coming;

        $html = $this->controller(null, null, $this->transfers())->renderShortcode();

        $at = static fn (string $id): int|false => strpos($html, 'id="' . $id . '"');
        // The Tasks tab (Tasks, Past tasks), then the Tickets tab (Tickets,
        // Past tickets) (Owner 2026-11-13).
        self::assertNotFalse($at('eventcrew-my-tickets'));
        self::assertLessThan($at('eventcrew-past-tasks'), $at('eventcrew-my-tasks'));
        self::assertLessThan($at('eventcrew-panel-tickets'), $at('eventcrew-past-tasks'));
        self::assertLessThan($at('eventcrew-my-tickets'), $at('eventcrew-panel-tickets'));
        self::assertLessThan($at('eventcrew-past-tickets'), $at('eventcrew-my-tickets'));

        self::assertSame(1, preg_match('~<section class="eventcrew-event eventcrew-me-card" id="eventcrew-my-tickets">.*?</section>~s', $html, $card));
        self::assertStringContainsString('<span class="eventcrew-me-event">Ecstatic Dance</span>', $card[0]);
        self::assertStringContainsString('<span class="eventcrew-event-dj eventcrew-me-dj">Esimerkki &amp; Toinen</span>', $card[0]);
        self::assertStringContainsString('<span class="eventcrew-me-what">Free entry</span>', $card[0]);
        self::assertStringContainsString('<span class="eventcrew-me-actions">', $card[0]);
        self::assertStringNotContainsString('wp-element-button', $card[0]);
        self::assertStringNotContainsString('ECSTATIC DANCE', $card[0]);

        self::assertSame(1, preg_match('~<section class="eventcrew-event eventcrew-me-card" id="eventcrew-past-tickets">.*?</section>~s', $html, $card));
        self::assertStringContainsString('Esimerkki &amp; Toinen', $card[0]);
        self::assertStringNotContainsString('eventcrew-me-actions', $card[0]);
    }

    /** No past tickets: no Past tickets card; only past ones: "No coming tickets.". */
    public function testPastTicketsIsLeftOutWhenEmptyAndTicketsSaysNoComingOnes(): void
    {
        $this->stubPageRender();
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('checked')->justReturn(null);
        Functions\when('get_option')->alias(static fn (string $name, mixed $default = false): mixed => $default);
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);
        $db = $this->useMeTabWpdb();
        $db->redemptions[] = self::redemptionRow(11, '2026-08-02');

        $html = $this->controller(null, null, $this->transfers())->renderShortcode();

        self::assertStringNotContainsString('id="eventcrew-past-tickets"', $html);
        // No post, so no DJs to name: the DJ line is left out.
        self::assertSame(1, preg_match('~<section class="eventcrew-event eventcrew-me-card" id="eventcrew-my-tickets">.*?</section>~s', $html, $card));
        self::assertStringContainsString('<span class="eventcrew-me-event">Party</span>', $card[0]);
        self::assertStringNotContainsString('eventcrew-me-dj', $card[0]);

        $db->assignments = [];
        $db->redemptions = [self::redemptionRow(10, '2026-07-01')];
        $html = $this->controller(null, null, $this->transfers())->renderShortcode();

        self::assertStringContainsString('id="eventcrew-past-tickets"', $html);
        self::assertSame(1, preg_match('~<section class="eventcrew-event eventcrew-me-card" id="eventcrew-my-tickets">.*?</section>~s', $html, $card));
        self::assertStringContainsString('No coming tickets.', $card[0]);
    }

    /** Give away and Cancel offer answer with the bot's texts. */
    public function testGiveAwayAndCancelOfferUseTheBotsTexts(): void
    {
        Functions\when('is_email')->alias(
            static fn (string $email): string|false => false === filter_var($email, FILTER_VALIDATE_EMAIL) ? false : $email
        );
        $db = $this->useMeTabWpdb();
        $db->redemptions[] = self::redemptionRow(10, '2026-08-01');
        $sam = Person::fromRow($db->people[7]);
        Functions\when('get_option')->alias(static fn (string $name, mixed $default = false): mixed => $default);
        $controller = $this->controller(null, null, $this->transfers());

        self::assertSame(
            ['ok' => false, 'code' => 'transfer_bad_email', 'text' => TicketTransferService::message(TicketTransferService::BAD_EMAIL)],
            $controller->giveTicket($sam, 10, 'not an address', 'Dee')
        );
        self::assertSame(
            ['ok' => false, 'code' => 'transfer_gone', 'text' => 'This offer is no longer open.'],
            $controller->cancelTicketOffer($sam, 10)
        );
        self::assertSame('This offer is no longer open.', SignupController::noticeText('transfer_gone'));
        self::assertSame('Offer cancelled. Your ticket is yours again.', SignupController::noticeText('offer_cancelled'));

        // Without the service nothing can be given.
        self::assertSame(
            ['ok' => false, 'code' => 'transfer_disabled', 'text' => 'Giving tickets away is turned off.'],
            $this->controller()->giveTicket($sam, 10, 'dee@example.com', 'Dee')
        );
    }

    /** @return array<string, mixed> */
    private static function redemptionRow(int $id, string $date): array
    {
        return [
            'id' => $id, 'person_id' => 7, 'redeemed_for' => $date, 'event_post_id' => null,
            'event_label' => 'Party', 'redeemed_at' => '2026-07-10 10:00:00', 'note' => '',
            'cancelled_at' => null, 'ticket_mailed_at' => null, 'transferred_to' => null,
        ];
    }

    private function transfers(): TicketTransferService
    {
        return new TicketTransferService(
            new TicketTransferRepository(),
            new RedemptionRepository(),
            new PersonRepository(),
            new TaskRepository(),
            new CheckinRepository()
        );
    }

    /** Without a TakeoverService (or an assignment id) the web action says the task is gone. */
    public function testTheWebTakeoverWithoutTheServiceSaysTheTaskIsGone(): void
    {
        $person = Person::fromRow(['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam']);

        self::assertSame(
            ['ok' => false, 'text' => 'That task is no longer available.'],
            $this->controller()->takeoverFor($person, 70)
        );
        self::assertSame('Request sent.', SignupController::noticeText('takeover_sent'));
    }

    public function testSignedOutTheCrewGuideIsNotShown(): void
    {
        $this->stubPageRender();
        $this->setGuideUrl();
        $this->wpdb->nextResults[] = [$this->taskRow()];
        $this->wpdb->nextResults[] = [];

        $html = $this->controller()->renderShortcode();

        self::assertStringNotContainsString('Crew guide', $html);
        self::assertStringNotContainsString('https://docs.example/guide', $html);
        self::assertStringNotContainsString('class="eventcrew-links"', $html);
        self::assertStringNotContainsString('eventcrew-header-guide', $html);
    }

    private function setGuideUrl(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => match ($name) {
                'eventcrew_telegram_group_link' => 'https://t.me/+secretcrew',
                Roles::GUIDE_URL_OPTION => 'https://docs.example/guide',
                default => $default,
            }
        );
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

        // Only in Settings (Owner 2026-11-13).
        self::assertSame(1, preg_match_all('~<form[^>]*eventcrew-language~', $html));
        self::assertSame(1, preg_match('~<form class="eventcrew-card-body eventcrew-language eventcrew-language-settings".*?</form>~s', $html, $form));
        $button = '~<button type="submit" name="language"\s+value="(\w+)".*?aria-pressed="(\w+)">([^<]+)</button>~s';
        preg_match_all($button, $form[0], $buttons);
        self::assertSame(['fi', 'en', 'sv'], $buttons[1]);
        self::assertSame(['Suomi', 'English', 'Svenska'], $buttons[3]);
        self::assertSame(['false', 'false', 'true'], $buttons[2]);
    }

    /**
     * Owner 2026-11-13: signed in, a "Hi <first name> ☰" menu holds Settings,
     * Help and Sign out; the tabs are the board, Tasks and Tickets, and the
     * old Me tab is gone.
     */
    public function testSignedInTheMenuHoldsSettingsHelpAndSignOut(): void
    {
        $html = $this->signedInPage(['display_name' => 'Sam Example']);

        self::assertSame(1, preg_match('~<div class="eventcrew-usermenu" data-eventcrew-usermenu>.*?</ul>\s*</div>~s', $html, $menu));
        self::assertMatchesRegularExpression('~<button type="button" class="eventcrew-usermenu-button" aria-expanded="false" aria-controls="eventcrew-usermenu-list">\s*<span class="eventcrew-usermenu-hi">Hi Sam</span>~', $menu[0]);
        self::assertStringContainsString('id="eventcrew-usermenu-list"', $menu[0]);
        self::assertStringContainsString('<a id="eventcrew-tab-settings" href="#eventcrew-panel-settings" data-eventcrew-goto="eventcrew-panel-settings">Settings</a>', $menu[0]);
        self::assertStringContainsString('id="eventcrew-tab-help"', $menu[0]);
        self::assertStringContainsString('class="eventcrew-signout"', $menu[0]);
        self::assertStringContainsString('Sign out', $menu[0]);
        self::assertSame(1, substr_count($html, 'class="eventcrew-signout"'));

        preg_match_all('~class="eventcrew-tab"\s+id="eventcrew-tab-(\w+)"~', $html, $tabs);
        self::assertSame(['board', 'tasks', 'tickets'], $tabs[1]);
        self::assertStringNotContainsString('eventcrew-panel-me"', $html);
        self::assertStringContainsString('data-eventcrew-panel="tasks"', $html);
        self::assertStringContainsString('data-eventcrew-panel="tickets"', $html);
        self::assertStringContainsString('data-eventcrew-panel="settings"', $html);
        self::assertStringContainsString('data-eventcrew-panel="help"', $html);
    }

    /** Without a display name the menu greets the person by their email name. */
    public function testWithoutANameTheMenuUsesTheEmailName(): void
    {
        $html = $this->signedInPage(['display_name' => '']);

        self::assertStringContainsString('<span class="eventcrew-usermenu-hi">Hi sam</span>', $html);
    }

    /**
     * Owner 2026-11-13: the Summary links to the person's coming tasks; the
     * Door scanner is not there for someone who may not open the sales desk,
     * nor the old Me tab sales card.
     */
    public function testTheSummaryLinksComingTasksAndHidesTheDoorScanner(): void
    {
        $this->stubPageRender();
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('checked')->justReturn(null);
        Functions\when('get_option')->alias(static fn (string $name, mixed $default = false): mixed => $default);
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);
        $this->useMeTabWpdb();

        $html = $this->controller()->renderShortcode();

        self::assertSame(1, preg_match('~<section class="eventcrew-event eventcrew-me-card eventcrew-header-card" id="eventcrew-summary">.*?</section>~s', $html, $card));
        self::assertMatchesRegularExpression('~<li class="eventcrew-summary-coming">.*?<a class="eventcrew-textlink" href="#eventcrew-my-tasks" data-eventcrew-goto="eventcrew-my-tasks">\s*1 task coming\s*</a>~s', $card[0]);
        self::assertStringNotContainsString('eventcrew-door-scanner', $html);
        self::assertStringNotContainsString('🚪</span> Door scanner', $html);
        self::assertStringNotContainsString('eventcrew-me-sales', $html);
    }

    /**
     * Owner 2026-11-13: someone who may open the sales desk for the next
     * night gets the 🚪 Door scanner button at the top, posting the sales
     * desk action (the server checks again when it is pressed).
     */
    public function testTheDoorScannerIsAtTheTopForSalesDeskCrew(): void
    {
        $html = $this->signedInPage([], ['sales_desk' => true]);

        self::assertSame(1, preg_match('~<form class="eventcrew-links eventcrew-sales-desk" id="eventcrew-door-scanner".*?</form>~s', $html, $form));
        self::assertStringContainsString('value="' . SignupController::SALES_ACTION . '"', $form[0]);
        self::assertMatchesRegularExpression('~<button type="submit" class="wp-element-button">\s*<span aria-hidden="true">🚪</span> Door scanner\s*</button>~u', $form[0]);
        self::assertLessThan(strpos($html, 'id="eventcrew-summary"'), strpos($html, 'id="eventcrew-door-scanner"'));
        self::assertSame(1, substr_count($html, 'id="eventcrew-door-scanner"'));
    }

    /**
     * The signed-in page for person 7, rendered from the view model with
     * $person merged into the person row and $view into the view.
     *
     * @param array<string, mixed> $person
     * @param array<string, mixed> $view
     */
    private function signedInPage(array $person = [], array $view = []): string
    {
        $this->stubPageRender();
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('checked')->justReturn(null);
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);

        $this->wpdb->nextRows[] = array_merge([
            'id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
        ], $person);
        $this->wpdb->nextResults[] = [$this->taskRow()]; // upcoming
        $this->wpdb->nextResults[] = [];                 // occupancyFor
        $this->wpdb->nextResults[] = [];                 // forPerson (occupiedTaskIds)
        $this->wpdb->nextResults[] = [];                 // standing historyFor
        $this->wpdb->nextVars[] = 0;                     // standing countFor

        $controller = $this->controller();
        $model = array_merge($controller->viewModel(), ['admin' => null], $view);

        return (string) \Closure::bind(
            function (array $view): string {
                ob_start();
                include EVENTCREW_PLUGIN_DIR . 'templates/public/signup.php';

                return (string) ob_get_clean();
            },
            $controller,
            SignupController::class
        )($model);
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

    /**
     * Q306: the language of the website page the visitor came from (the
     * link's marker, or the session cookie that keeps it) wins over the
     * browser's.
     */
    public function testTheWebsitePagesLanguageWinsOverTheBrowsers(): void
    {
        Functions\when('wp_unslash')->returnArg(1);
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en';

        try {
            $_GET = [PageLanguage::MARKER => 'fi'];
            self::assertSame('fi', $this->controller()->pageLanguage());

            $_GET = [];
            $_COOKIE[PageLanguage::COOKIE] = 'fi';
            self::assertSame('fi', $this->controller()->pageLanguage());

            $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fi';
            $_COOKIE[PageLanguage::COOKIE] = 'en';
            self::assertSame('en', $this->controller()->pageLanguage());
        } finally {
            unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            $_GET = [];
        }
    }

    /**
     * A signed-in person's own choice stays first; without one, the website
     * page's language applies.
     */
    public function testASignedInPersonsChoiceComesBeforeTheWebsitePagesLanguage(): void
    {
        Functions\when('wp_unslash')->returnArg(1);
        $_GET = [PageLanguage::MARKER => 'fi'];
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);

        try {
            $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'language' => 'en'];
            self::assertSame('en', $this->controller()->pageLanguage());

            $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com'];
            self::assertSame('fi', $this->controller()->pageLanguage());

            $_GET = [];
            $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com'];
            self::assertNull($this->controller()->pageLanguage());
        } finally {
            $_GET = [];
        }
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
        // Someone signing themselves up waits for the team's OK.
        self::assertSame('waiting', $this->wpdb->inserts[0]['data']['approval']);
        self::assertStringContainsString('There is no crew account for this email address yet.', $this->mails[0]);
        self::assertStringContainsString('Create my account', $this->mails[0]);
    }

    /**
     * Q330: a new person signing up from a Finnish page (the link's marker,
     * the same rule the crew page is shown by) gets Finnish as their message
     * language, and the link mail is in Finnish.
     */
    public function testANewPersonFromAFinnishPageGetsFinnish(): void
    {
        Functions\when('wp_unslash')->returnArg(1);
        $_GET = [PageLanguage::MARKER => 'fi'];
        $this->wpdb->nextRows[] = null; // findByEmail -> not found -> create

        try {
            $this->controller()->loginByEmail('new@example.com');
        } finally {
            $_GET = [];
        }

        $saved = array_values(array_filter(
            $this->wpdb->updates,
            static fn (array $u): bool => array_key_exists('language', $u['data'])
        ));
        self::assertCount(1, $saved);
        self::assertSame('fi', $saved[0]['data']['language']);
        self::assertStringContainsString('Luo tilini', $this->mails[0]);
    }

    /** An English page leaves the new person's language unset, as before. */
    public function testANewPersonFromAnEnglishPageKeepsNoLanguage(): void
    {
        Functions\when('wp_unslash')->returnArg(1);
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en';
        $this->wpdb->nextRows[] = null; // findByEmail -> not found -> create

        try {
            $this->controller()->loginByEmail('new@example.com');
        } finally {
            unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
        }

        foreach ($this->wpdb->updates as $update) {
            self::assertArrayNotHasKey('language', $update['data']);
        }
        self::assertStringContainsString('Create my account', $this->mails[0]);
    }

    /** Someone who already has a record keeps their own language. */
    public function testAnExistingPersonsLanguageIsNotChangedBySigningIn(): void
    {
        Functions\when('wp_unslash')->returnArg(1);
        $_GET = [PageLanguage::MARKER => 'fi'];
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'language' => 'en',
        ];

        try {
            $this->controller()->loginByEmail('sam@example.com');
        } finally {
            $_GET = [];
        }

        foreach ($this->wpdb->updates as $update) {
            self::assertArrayNotHasKey('language', $update['data']);
        }
        self::assertStringContainsString('Use the button below to sign in', $this->mails[0]);
    }

    /** @return list<array<string, string>|null> Every role_notices write, decoded. */
    private function noticeWrites(): array
    {
        $writes = [];

        foreach ($this->wpdb->updates as $update) {
            if (array_key_exists('role_notices', $update['data'])) {
                $writes[] = json_decode((string) $update['data']['role_notices'], true);
            }
        }

        return $writes;
    }

    /** The join questions' answers are kept on a new person, for the organizers' notice. */
    public function testANewPersonsJoinAnswersAreStored(): void
    {
        $this->wpdb->nextRows[] = null; // findByEmail -> not found -> create
        $this->wpdb->nextRows[] = ['id' => 1, 'email' => 'new@example.com', 'approval' => 'waiting'];

        $answers = ['newcomer:before' => 'yes', 'newcomer:times' => '4', 'newcomer:tickets' => '2'];
        $this->controller()->loginByEmail('new@example.com', '', $answers);

        self::assertSame([$answers], $this->noticeWrites());
        self::assertCount(1, $this->mails);
    }

    /** A waiting newcomer whose link was not opened yet may change the answers. */
    public function testAnUnconfirmedNewcomerMayChangeTheAnswers(): void
    {
        $row = [
            'id' => 7,
            'email' => 'sam@example.com',
            'approval' => 'waiting',
            'role_notices' => '{"newcomer:before":"yes","newcomer:times":"4"}',
        ];
        $this->wpdb->nextRows[] = $row;
        $this->wpdb->nextRows[] = $row;

        $this->controller()->loginByEmail('sam@example.com', '', ['newcomer:before' => 'no']);

        self::assertSame([['newcomer:before' => 'no']], $this->noticeWrites());
    }

    /** Someone confirmed already (the organizers were asked) keeps what they said. */
    public function testAConfirmedPersonsAnswersAreNotChanged(): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 7,
            'email' => 'sam@example.com',
            'email_verified_at' => '2026-07-01 00:00:00',
            'approval' => 'waiting',
        ];

        $this->controller()->loginByEmail('sam@example.com', '', ['newcomer:before' => 'no']);

        self::assertSame([], $this->noticeWrites());
        self::assertCount(1, $this->mails);
    }

    public function testNoAnswersWriteNothing(): void
    {
        $this->wpdb->nextRows[] = null; // findByEmail -> not found -> create

        $this->controller()->loginByEmail('new@example.com', '', []);

        self::assertSame([], $this->noticeWrites());
    }

    /**
     * The crew page's sign-up form asks every question the bot asks a
     * newcomer, in the bot's words, with the same ranges, posted under the
     * names JoinAnswers::fromPost() reads.
     */
    public function testTheSignUpFormAsksTheBotsJoinQuestions(): void
    {
        $this->stubPageRender();
        $this->wpdb->nextResults[] = [];   // upcoming dates: an empty board is enough
        $this->wpdb->nextResults[] = [];

        $html = $this->controller()->renderShortcode();

        // The bot: "... Have you worked on the crew here before?", "About how
        // many times? Tap a number or type one.", "How many free-entry
        // tickets do you think you should have? Tap a number or type one."
        foreach (
            [
                'Have you worked on the crew here before?',
                'About how many times?',
                'How many free-entry tickets do you think you should have?',
            ] as $question
        ) {
            self::assertStringContainsString($question, $html);
        }
        self::assertStringContainsString('name="' . JoinAnswers::FIELD_BEFORE . '" value="yes"', $html);
        self::assertStringContainsString('name="' . JoinAnswers::FIELD_BEFORE . '" value="no"', $html);
        self::assertStringContainsString(
            'name="' . JoinAnswers::FIELD_TIMES . '" min="' . JoinAnswers::MIN_TIMES . '" max="' . JoinAnswers::MAX_TIMES . '"',
            $html
        );
        self::assertStringContainsString(
            'name="' . JoinAnswers::FIELD_TICKETS . '" min="' . JoinAnswers::MIN_TICKETS . '" max="'
                . JoinAnswers::MAX_TICKETS . '"',
            $html
        );
        // The yes/no and both numbers are in the sign-in form, before its email field.
        $form = (int) strpos($html, 'data-eventcrew-signin method="post"');
        self::assertGreaterThan(0, $form);
        self::assertGreaterThan($form, strpos($html, 'name="' . JoinAnswers::FIELD_TICKETS . '"'));
        self::assertLessThan(strpos($html, 'id="eventcrew-email"'), strpos($html, 'name="' . JoinAnswers::FIELD_TICKETS . '"'));
    }

    /**
     * The numbers show once Yes is ticked; a browser without :has() shows them
     * all the time, so it can still answer every question the bot asks.
     */
    public function testTheJoinNumbersStayReachableWithoutHas(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/eventcrew.css');
        $supports = (int) strpos($css, '@supports selector(:has(*))');

        self::assertGreaterThan(0, $supports);
        $outside = substr($css, 0, $supports);
        self::assertMatchesRegularExpression('/\.eventcrew-join-more \{ display: flex;/', $outside);
        self::assertStringNotContainsString('.eventcrew-join-more { display: none', $outside);
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

    /**
     * The Telegram twin of the email-changed notice: a security message, so it
     * reaches the linked chat even with every DM type and the bot muted.
     *
     * @return array<int, array<string, mixed>> the Telegram sends
     */
    private function confirmEmailChangeWithTelegram(array $personRow): array
    {
        $sends = [];
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed
                => TelegramClient::TOKEN_OPTION === $name ? 'BOT:TOKEN' : $default
        );
        Functions\when('get_bloginfo')->justReturn('');
        Functions\when('wp_json_encode')->alias(static fn (mixed $d): string => (string) json_encode($d));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->returnArg(1);
        Functions\when('wp_remote_post')->alias(static function (string $url, array $args) use (&$sends): string {
            $sends[] = (array) json_decode((string) $args['body'], true);

            return (string) json_encode(['ok' => true, 'result' => true]);
        });

        $this->transients['eventcrew_email_change_' . substr(hash('sha256', 'raw1'), 0, 32)] = 'sam2@example.com';
        $token = ['id' => 1, 'person_id' => 7, 'expires_at' => '2099-01-01 00:00:00', 'used_at' => null];
        $this->wpdb->nextRows[] = $token;
        $this->wpdb->nextQueryResults[] = 1;
        $this->wpdb->nextRows[] = $personRow;
        $this->wpdb->nextRows[] = null;

        $telegram = new TelegramClient(new Logger(), new DohResolver(new Logger()));
        self::assertSame('email_changed', $this->controller(null, $telegram)->confirmEmailChange('raw1'));

        return $sends;
    }

    public function testTheEmailChangedNoticeAlsoGoesToTheLinkedTelegramChatEvenWhenMuted(): void
    {
        $sends = $this->confirmEmailChangeWithTelegram([
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'telegram_chat_id' => 4242,
            'notify_muted' => 1,
            'notify_prefs' => (string) json_encode(
                array_fill_keys(array_keys(NotificationPreferences::labels()), ['dm' => false, 'email' => false])
            ),
        ]);

        self::assertCount(1, $sends);
        self::assertSame(4242, $sends[0]['chat_id']);
        self::assertSame(
            "Hi Sam,\n\n📧 Your crew account email is now sam2@example.com. If you did not do this, tell an organizer.",
            $sends[0]['text']
        );
        // The email to the old address still goes too.
        self::assertSame('sam@example.com', $this->sent[0]['to']);
    }

    public function testTheEmailChangedNoticeSendsNoTelegramMessageWithoutALinkedChat(): void
    {
        $sends = $this->confirmEmailChangeWithTelegram(
            ['id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam']
        );

        self::assertSame([], $sends);
        self::assertSame('sam@example.com', $this->sent[0]['to']);
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
        // The sign-in code is random: CODE stands for it.
        $body = (string) preg_replace('/(code for the app: )\d{6}/', '$1CODE', $this->mails[$index]);

        return $this->sent[$index]['subject'] . "\n---\n"
            . (string) preg_replace('/token=[A-Za-z0-9_-]+/', 'token=TOKEN', $body);
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
            "Your Test Site sign-in link\n---\nHi Sam,\n\n"
                . "Use the button below to sign in and manage your tasks.\n\n"
                . "The link is good for 30 minutes, and works once.\n\n"
                . "Sign-in code for the app: CODE\n\n"
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
                . " did not ask for this, ignore this email.\n\nSign-in code for the app: CODE\n\n"
                . "We store your email to run the crew.\n\n"
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
            "Sign in, please\n---\nHi Sam,\n\nPress the button.\n\nSign-in code for the app: CODE\n\n"
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
            "Welcome\n---\nHi,\n\nPress the button to join.\n\nSign-in code for the app: CODE\n\n"
                . "We store your email to run the crew.\n\n"
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

    /** @return array<string, mixed> */
    private static function samRow(?string $sessionsValidFrom = null): array
    {
        return [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'sessions_valid_from' => $sessionsValidFrom,
        ];
    }

    public function testTheSignInEmailCarriesASixDigitCode(): void
    {
        $this->wpdb->nextRows[] = null; // findByEmail -> not found -> create

        $this->controller()->loginByEmail('sam@example.com');

        self::assertMatchesRegularExpression('/Sign-in code for the app: \d{6}\b/', $this->mails[0]);
    }

    public function testTheEmailedCodeSignsInAndSpendsTheLinkToo(): void
    {
        $code = LoginCode::issue('sam@example.com', 'rawtoken', 1800);

        $this->wpdb->nextRows[] = self::liveSignInToken(); // consumeHashOnce reads the link's token
        $this->wpdb->nextQueryResults[] = 1;                // and spends it
        $this->wpdb->nextRows[] = self::samRow();           // find(7)

        self::assertSame(
            ['person_id' => 7, 'notice' => 'signed_in'],
            $this->controller()->signInWithCode('sam@example.com', $code)
        );
        self::assertStringContainsString('used_at IS NULL', implode("\n", $this->wpdb->queries));

        // The link is spent now: its row reads as used.
        $this->wpdb->nextRows[] = self::liveSignInToken('2026-07-20 12:00:00');
        self::assertSame(
            ['person_id' => null, 'notice' => 'bad_link'],
            $this->controller()->magicLinkSignIn('rawtoken')
        );
        // And the code does not work twice.
        self::assertSame('bad_code', $this->controller()->signInWithCode('sam@example.com', $code)['notice']);
    }

    public function testOpeningTheLinkRemovesTheCode(): void
    {
        $code = LoginCode::issue('sam@example.com', 'rawtoken', 1800);

        $this->wpdb->nextRows[] = self::liveSignInToken();
        $this->wpdb->nextQueryResults[] = 1;
        $this->wpdb->nextRows[] = self::samRow();
        self::assertSame('signed_in', $this->controller()->magicLinkSignIn('rawtoken')['notice']);

        self::assertSame(
            ['person_id' => null, 'notice' => 'bad_code'],
            $this->controller()->signInWithCode('sam@example.com', $code)
        );
        self::assertSame([], $this->transients);
    }

    public function testAWrongCodeSignsNobodyIn(): void
    {
        $code = LoginCode::issue('sam@example.com', 'rawtoken', 1800);
        $wrong = str_pad((string) (((int) $code + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);

        self::assertSame(
            ['person_id' => null, 'notice' => 'bad_code'],
            $this->controller()->signInWithCode('sam@example.com', $wrong)
        );
        self::assertSame([], $this->wpdb->queries, 'No token was spent.');
    }

    public function testTheFifthWrongCodeLocksTheCodesOut(): void
    {
        $code = LoginCode::issue('sam@example.com', 'rawtoken', 1800);
        $wrong = str_pad((string) (((int) $code + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);

        for ($try = 1; $try < LoginCode::MAX_TRIES; ++$try) {
            self::assertSame('bad_code', $this->controller()->signInWithCode('sam@example.com', $wrong)['notice']);
        }

        self::assertSame('code_locked', $this->controller()->signInWithCode('sam@example.com', $wrong)['notice']);
        self::assertSame('bad_code', $this->controller()->signInWithCode('sam@example.com', $code)['notice']);
        self::assertSame([], $this->wpdb->queries, 'No token was spent.');
    }

    public function testAnExpiredCodeSignsNobodyIn(): void
    {
        $code = LoginCode::issue('sam@example.com', 'rawtoken', 1800, time() - 1801);

        self::assertSame('bad_code', $this->controller()->signInWithCode('sam@example.com', $code)['notice']);
        self::assertSame([], $this->wpdb->queries, 'No token was spent.');
    }

    public function testACodeFromAnotherSiteIsRefused(): void
    {
        $code = LoginCode::issue('sam@example.com', 'rawtoken', 1800);
        Functions\when('wp_parse_url')->alias(static fn (string $url, int $part = -1): mixed => parse_url($url, $part));
        $_SERVER['HTTP_ORIGIN'] = 'https://elsewhere.test';
        $post = ['email' => 'sam@example.com', 'code' => $code, 'redirect_to' => 'https://site.test/crew/'];
        $_POST = $post;
        $_REQUEST = ['action' => SignupController::CODE_ACTION] + $post;
        $this->stubSignInExits();

        try {
            $this->controller()->dispatch();
            self::fail('The controller sent no answer.');
        } catch (\RuntimeException $answer) {
            self::assertStringContainsString('eventcrew_notice=bad_code', $answer->getMessage());
        } finally {
            unset($_SERVER['HTTP_ORIGIN']);
            $_POST = [];
            $_REQUEST = [];
        }

        self::assertSame([], $this->wpdb->queries, 'No token was spent.');
        self::assertNotSame([], $this->transients, 'The code is still waiting.');
    }

    public function testASessionADayOldIsRenewed(): void
    {
        Functions\when('is_ssl')->justReturn(true);
        $issued = time() - 2 * DAY_IN_SECONDS;
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7, $issued);
        $this->wpdb->nextRows[] = self::samRow();

        $fresh = $this->controller()->renewSession();

        self::assertNotNull($fresh);
        self::assertSame(7, WebSession::read($fresh));
        self::assertGreaterThan($issued, WebSession::issuedAt($fresh));
        self::assertSame($fresh, $_COOKIE[WebSession::COOKIE]);
    }

    /** A "Get the app" pop-in closed in this session stays closed across the daily renewal. */
    public function testRenewalCarriesAClosedAppPopInOver(): void
    {
        Functions\when('is_ssl')->justReturn(true);
        $issued = time() - 2 * DAY_IN_SECONDS;
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7, $issued);
        $row = self::samRow() + ['role_notices' => (string) json_encode([AppInstallPrompt::KEY_CLOSED => '5,' . $issued])];
        $this->wpdb->nextRows[] = $row;
        $this->wpdb->nextRows[] = $row; // addRoleNotices() reads the row fresh

        $fresh = (string) $this->controller()->renewSession();

        $written = json_decode((string) end($this->wpdb->updates)['data']['role_notices'], true);
        self::assertSame('5,' . WebSession::issuedAt($fresh), $written[AppInstallPrompt::KEY_CLOSED]);
    }

    public function testAFreshSessionIsNotRenewed(): void
    {
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7, time() - 60);
        $this->wpdb->nextRows[] = self::samRow();

        self::assertNull($this->controller()->renewSession());
        self::assertCount(1, $this->wpdb->nextRows, 'The person was not even read.');
    }

    /** Sign out everywhere still ends an old session: it is not renewed past the revocation. */
    public function testARevokedSessionIsNotRenewed(): void
    {
        Functions\when('get_gmt_from_date')->returnArg(1);
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7, time() - 2 * DAY_IN_SECONDS);
        $this->wpdb->nextRows[] = self::samRow(gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS));

        self::assertNull($this->controller()->renewSession());
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

    public function testAnExpiredLinkOnASignedInDeviceGoesStraightToThePage(): void
    {
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'email_verified_at' => '2026-07-01 00:00:00'];
        // The token row: expired.
        $this->wpdb->nextRows[] = ['person_id' => 7, 'expires_at' => '2026-01-01 00:00:00', 'used_at' => null];

        try {
            $answer = $this->dispatchSignIn(['token' => 'rawtoken', 'redirect_to' => 'https://site.test/crew/'], []);
        } finally {
            $_COOKIE = [];
        }

        // No button page and no notice: just the page.
        self::assertSame('https://site.test/crew/', $answer);
        self::assertStringNotContainsString('UPDATE', implode("\n", $this->wpdb->queries), 'Nothing was spent.');
    }

    public function testALiveLinkForSomeoneElseOnASignedInDeviceStillShowsTheButton(): void
    {
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'email_verified_at' => '2026-07-01 00:00:00'];
        $this->wpdb->nextRows[] = ['person_id' => 8, 'expires_at' => '2999-12-31 00:00:00', 'used_at' => null];

        try {
            $answer = $this->dispatchSignIn(['token' => 'rawtoken', 'redirect_to' => 'https://site.test/crew/'], []);
        } finally {
            $_COOKIE = [];
        }

        self::assertSame('page 200', $answer);
    }

    public function testAUsedLinkPressedOnASignedInDeviceOpensThePageWithNoMessage(): void
    {
        $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);
        // The token row, already used; then the person for currentPerson().
        $this->wpdb->nextRows[] = self::liveSignInToken('2026-11-05 10:00:00');
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'email_verified_at' => '2026-07-01 00:00:00'];

        try {
            $answer = $this->dispatchSignIn([], ['token' => 'rawtoken', 'redirect_to' => 'https://site.test/crew/']);
        } finally {
            $_COOKIE = [];
        }

        self::assertSame('https://site.test/crew/', $answer);
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

    // --- the guest ticks and the web board (Owner 2026-11-07) -----------------

    /**
     * Settings > Roles with Decorate set as the test gives.
     *
     * @param array<string, mixed> $ticks
     */
    private function withDecorateTicks(array $ticks): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => Roles::OPTION_NAME === $name
                ? [['slug' => 'decorate', 'label' => 'Decorate'] + $ticks]
                : false
        );
    }

    /**
     * The board rows for person 7 (or signed out), with task 5 holding Anna
     * Virtanen and Ben.
     *
     * @param array<string, mixed>|null $person
     * @return array<int, array<string, mixed>>
     */
    private function guestTickRows(?array $person): array
    {
        if (null !== $person) {
            $_COOKIE[WebSession::COOKIE] = WebSession::mint(7);
            $this->wpdb->nextRows[] = $person + [
                'id' => 7, 'email' => 'sam@example.com', 'display_name' => 'Sam',
                'email_verified_at' => '2026-07-01 00:00:00', 'is_organizer' => 0,
            ];
        }

        $this->wpdb->nextResults[] = [$this->taskRow()];               // upcoming
        $this->wpdb->nextResults[] = [['task_id' => 5, 'taken' => 2]]; // occupancyFor

        if (null !== $person) {
            $this->wpdb->nextResults[] = []; // forPerson
            $this->wpdb->nextResults[] = []; // historyFor
            $this->wpdb->nextVars[] = 0;
            $this->wpdb->nextResults[] = [
                ['task_id' => 5, 'display_name' => 'Anna Virtanen'],
                ['task_id' => 5, 'display_name' => 'Ben'],
            ];                               // occupantsFor
        }

        $view = $this->controller()->viewModel();

        return array_combine(
            array_map(static fn (array $row): int => $row['task']->id, $view['groups'][0]['tasks']),
            $view['groups'][0]['tasks']
        );
    }

    public function testASignedInApprovedPersonSeesTheNamesOnTheBoard(): void
    {
        $this->withDecorateTicks([]);

        self::assertSame(['Anna', 'Ben'], $this->guestTickRows([])[5]['names']);
    }

    public function testSignedOutOrWaitingForTheOkTheBoardShowsNoNames(): void
    {
        $this->withDecorateTicks([]);

        self::assertSame([], $this->guestTickRows(null)[5]['names']);
        self::assertSame([], $this->guestTickRows(['approval' => 'waiting'])[5]['names']);
    }

    public function testAnonymousDisplayHidesTheNamesFromAGuestButNotFromTheRole(): void
    {
        $this->withDecorateTicks(['anonymous' => true]);

        self::assertSame([], $this->guestTickRows([])[5]['names']);
        self::assertSame(['Anna', 'Ben'], $this->guestTickRows(['role_slugs' => 'decorate'])[5]['names']);
    }

    public function testGuestSignUpOffMarksTheRowCrewOnlyForAGuest(): void
    {
        $this->withDecorateTicks(['guest_signup' => false]);

        self::assertTrue($this->guestTickRows(null)[5]['crew_only']);
        self::assertTrue($this->guestTickRows([])[5]['crew_only']);
        self::assertFalse($this->guestTickRows(['role_slugs' => 'decorate'])[5]['crew_only']);
    }

    /**
     * Members display (Owner 2026-11-08): a role off the guest board shows to
     * anyone signed in, not to the page signed out. With Guest sign up off,
     * a non-holder gets the row as crew only.
     */
    public function testMembersDisplayShowsTheTaskToAnyoneSignedIn(): void
    {
        $this->withDecorateTicks(['on_board' => false, 'members_display' => true]);
        self::assertFalse($this->guestTickRows([])[5]['crew_only']);

        $this->withDecorateTicks(['on_board' => false, 'members_display' => true, 'guest_signup' => false]);
        self::assertTrue($this->guestTickRows([])[5]['crew_only']);
        self::assertFalse($this->guestTickRows(['role_slugs' => 'decorate'])[5]['crew_only']);

        unset($_COOKIE[WebSession::COOKIE]);                           // signed out
        $this->wpdb->nextResults[] = [$this->taskRow()];               // upcoming
        $this->wpdb->nextResults[] = [['task_id' => 5, 'taken' => 2]]; // occupancyFor
        self::assertSame([], $this->controller()->viewModel()['groups']);
    }

    public function testClaimForMapsAGuestSignUpRefusalToCrewOnly(): void
    {
        $this->withDecorateTicks(['guest_signup' => false]);
        $this->wpdb->nextRows[] = $this->taskRow();
        $this->wpdb->nextRows[] = ['id' => 7, 'email' => 'sam@example.com', 'is_organizer' => 0];

        self::assertSame('crew_only', $this->controller()->claimFor(7, 5));
        self::assertStringNotContainsString('INSERT', implode("\n", $this->wpdb->queries));
    }

    /**
     * Signed out, Sign up stays on the row and leads to the sign-in card,
     * which also makes an account; the script there sends the sign-in back
     * to the task's row.
     */
    public function testSignedOutTheBoardHasASignUpLinkToTheSignInCard(): void
    {
        $this->stubPageRender();
        $this->wpdb->nextResults[] = [
            $this->taskRow(),
            array_merge($this->taskRow(), ['id' => 6]),
        ];
        $this->wpdb->nextResults[] = [['task_id' => 6, 'taken' => 2]];

        $html = $this->controller()->renderShortcode();

        self::assertStringContainsString('id="eventcrew-signin"', $html);
        self::assertStringContainsString('href="#eventcrew-signin" data-eventcrew-signin-task="5">Sign up</a>', $html);
        // Task 6 is full: Take someone's task, also through the sign-in card (Owner 2026-11-08).
        self::assertStringContainsString('data-eventcrew-signin-task="6">Take someone’s task</a>', $html);
        self::assertStringContainsString('back.hash = "eventcrew-task-" + task;', $html);
    }

    /**
     * Signed out, Sign up scrolls back to the sign-in card (a jump with less
     * motion), makes it glow once and says in the toast to sign in first;
     * without JavaScript the link still jumps there.
     */
    public function testSignedOutSignUpLeadsBackToTheSignInCardWithAToast(): void
    {
        $this->stubPageRender();
        $this->wpdb->nextResults[] = [$this->taskRow()];
        $this->wpdb->nextResults[] = [];

        $html = $this->controller()->renderShortcode();

        self::assertStringContainsString('href="#eventcrew-signin" data-eventcrew-signin-task="5"', $html);
        self::assertStringContainsString("matchMedia('(prefers-reduced-motion: reduce)')", $html);
        self::assertStringContainsString("behavior: still ? 'auto' : 'smooth'", $html);
        self::assertStringContainsString("card.classList.add('is-calling')", $html);
        self::assertStringContainsString('toast.textContent = "Sign in first to take a task.";', $html);
        self::assertStringContainsString('id="eventcrew-toast" class="eventcrew-toast" role="status" aria-live="polite"', $html);
    }

    public function testThePendingButtonTextIsShort(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/public/signup-board.php');

        self::assertStringContainsString("esc_html_e('Awaiting approval', 'eventcrew')", $template);
        self::assertStringNotContainsString('Waiting for the team’s OK', $template);
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

    /** Q351: the page's notice after a cancel says what it means for the standing. */
    public function testTheCancelNoticesSayWhatTheCancelMeans(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed
                => SignupService::NOTICE_HOURS_OPTION === $name ? 24 : $default
        );

        self::assertSame(
            'You’ve cancelled that task. This is an early cancellation: it is more than 24 hours before the task,'
                . ' so it has no effect on your standing.',
            SignupController::noticeText('dropped_early')
        );
        self::assertSame(
            'You’ve cancelled that task. This close to the event it counts as a late cancellation.'
                . ' Sign up for it again while the place is free and the late cancellation is removed.',
            SignupController::noticeText('dropped_late')
        );
        self::assertSame('You’ve cancelled that task.', SignupController::noticeText('dropped'));
    }

}

<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\Newsletter;
use EventCrew\Support\NewsletterText;
use EventCrew\Support\SignedLink;
use EventCrew\Support\Turnstile;
use EventCrew\Tests\TestCase;
use EventCrew\Web\NewsletterController;

/**
 * The sign-up form and the signed links. wp_mail records each message;
 * nothing is sent. TestCase pins now to 2026-07-20 12:00:00.
 */
final class NewsletterControllerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<array{to: string, subject: string, body: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->mails = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('get_permalink')->justReturn('https://site.test/crew/');
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('is_email')->alias(
            static fn (string $email): bool => 1 === preg_match('/^[^@\s]+@[^@\s]+\.[a-z]+$/', $email)
        );
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

            return true;
        });
    }

    private function controller(): NewsletterController
    {
        $logger = new Logger();
        $mailer = new Mailer($logger, new EmailTemplate($logger));

        return new NewsletterController(
            new NewsletterSubscriberRepository(),
            $mailer,
            new Newsletter(
                new NewsletterSubscriberRepository(),
                new PersonRepository(),
                new NewsletterText(new LlmClient($logger), $logger),
                $mailer,
                $logger
            ),
            new Turnstile($logger)
        );
    }

    /** @return array<string, mixed> */
    private static function row(int $id, string $status, string $createdAt = '2026-07-19 10:00:00'): array
    {
        return [
            'id' => $id,
            'email' => 'fan@example.test',
            'status' => $status,
            'created_at' => $createdAt,
            'confirmed_at' => null,
        ];
    }

    /** A new address gets a pending row and one confirmation email with the signed link. */
    public function testANewAddressGetsTheConfirmationEmail(): void
    {
        $result = $this->controller()->join('Fan@Example.test', '', '');

        self::assertSame(NewsletterController::RESULT_SENT, $result);
        self::assertSame('pending', $this->wpdb->inserts[0]['data']['status']);
        self::assertCount(1, $this->mails);
        self::assertSame('fan@example.test', $this->mails[0]['to']);
        self::assertStringContainsString('do=confirm', $this->mails[0]['body']);
        self::assertStringContainsString('Vahvista', $this->mails[0]['subject']);
    }

    /** A filled honeypot looks like success to the bot and does nothing. */
    public function testTheHoneypotDoesNothing(): void
    {
        $result = $this->controller()->join('fan@example.test', 'https://spam.example', '');

        self::assertSame(NewsletterController::RESULT_SENT, $result);
        self::assertSame([], $this->wpdb->queries);
        self::assertSame([], $this->mails);
    }

    public function testAnInvalidAddressIsRefused(): void
    {
        self::assertSame(NewsletterController::RESULT_INVALID, $this->controller()->join('not an address', '', ''));
        self::assertSame([], $this->mails);
    }

    /** With Turnstile set up, a missing token is refused before anything is stored. */
    public function testTurnstileIsCheckedWhenItIsSetUp(): void
    {
        $this->options[Turnstile::SITE_KEY_OPTION] = 'site-key';
        $this->options[Turnstile::SECRET_KEY_OPTION] = 'secret';

        self::assertSame(NewsletterController::RESULT_CAPTCHA, $this->controller()->join('fan@example.test', '', ''));
        self::assertSame([], $this->wpdb->queries);
    }

    /** A pending sign-up less than an hour old gets no second email. */
    public function testNoSecondEmailWithinAnHour(): void
    {
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_PENDING, '2026-07-20 11:30:00');

        self::assertSame(NewsletterController::RESULT_SENT, $this->controller()->join('fan@example.test', '', ''));
        self::assertSame([], $this->mails);
        self::assertSame([], $this->wpdb->updates);
    }

    /** A confirmed address answers the same and gets no email. */
    public function testAConfirmedAddressGetsNoEmail(): void
    {
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);

        self::assertSame(NewsletterController::RESULT_SENT, $this->controller()->join('fan@example.test', '', ''));
        self::assertSame([], $this->mails);
    }

    public function testTheConfirmLinkConfirms(): void
    {
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_PENDING);

        $page = $this->controller()->respond('confirm', 'GET', SignedLink::sign(Newsletter::PURPOSE_CONFIRM, 5));

        self::assertSame(200, $page['status']);
        self::assertSame('confirmed', $this->wpdb->updates[0]['data']['status']);
    }

    /** A token signed for another purpose is not accepted. */
    public function testAnUnsubscribeTokenDoesNotConfirm(): void
    {
        $page = $this->controller()->respond('confirm', 'GET', SignedLink::sign(Newsletter::PURPOSE_UNSUBSCRIBE, 5));

        self::assertSame(404, $page['status']);
        self::assertSame([], $this->wpdb->updates);
    }

    public function testTheUnsubscribeLinkWorksByGetAndByPost(): void
    {
        $token = SignedLink::sign(Newsletter::PURPOSE_UNSUBSCRIBE, 5);

        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);
        self::assertSame(200, $this->controller()->respond('unsubscribe', 'GET', $token)['status']);

        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);
        self::assertSame(200, $this->controller()->respond('unsubscribe', 'POST', $token)['status']);

        self::assertCount(2, $this->wpdb->updates);
        self::assertSame('unsubscribed', $this->wpdb->updates[1]['data']['status']);
    }

    /** Opening the stop link only shows the button; the POST stops. */
    public function testTheStopLinkNeedsThePost(): void
    {
        $this->options[Newsletter::STATE_OPTION] = [
            'ledger' => [],
            'plan' => [
                'id' => 3,
                'status' => Newsletter::PLAN_PLANNED,
                'send_on' => '2026-07-21',
                'events' => [['id' => 1, 'date' => '2026-07-27']],
            ],
            'last' => null,
            'next_id' => 4,
        ];
        $token = SignedLink::sign(Newsletter::PURPOSE_STOP, 3);

        $page = $this->controller()->respond('stop', 'GET', $token);
        self::assertStringContainsString('<form method="post"', $page['html']);
        self::assertNotNull($this->options[Newsletter::STATE_OPTION]['plan']);

        $page = $this->controller()->respond('stop', 'POST', $token);
        self::assertStringContainsString('The announcement is stopped.', $page['html']);
        self::assertNull($this->options[Newsletter::STATE_OPTION]['plan']);
    }

    /** Stubs for drawing the form and the bar on a public page; $post is the page shown. */
    private function onPublicPage(?\WP_Post $post = null, bool $admin = false): void
    {
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('admin_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-admin/' . $p);
        Functions\when('remove_query_arg')->returnArg(2);
        Functions\when('set_url_scheme')->alias(static fn (string $url): string => 'https:' . $url);
        Functions\when('is_admin')->justReturn($admin);
        Functions\when('is_feed')->justReturn(false);
        Functions\when('is_embed')->justReturn(false);
        Functions\when('is_singular')->justReturn(null !== $post);
        Functions\when('get_queried_object')->justReturn($post);
        Functions\when('has_shortcode')->alias(
            static fn (string $content, string $tag): bool => str_contains($content, '[' . $tag)
        );
        Functions\when('has_block')->justReturn(false);
    }

    private static function bar(NewsletterController $controller): string
    {
        ob_start();
        $controller->renderBar();

        return (string) ob_get_clean();
    }

    protected function tearDown(): void
    {
        unset($_GET[NewsletterController::RESULT_ARG]);
        parent::tearDown();
    }

    /** With the newsletter on, the bar is printed hidden (its script shows it), with a close button. */
    public function testTheBarIsPrintedWhenTheNewsletterIsOn(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->onPublicPage();

        $html = self::bar($this->controller());

        self::assertStringContainsString('id="' . NewsletterController::BAR_ANCHOR . '" hidden', $html);
        self::assertStringContainsString('data-close-days="30"', $html);
        self::assertStringContainsString('name="anchor" value="' . NewsletterController::BAR_ANCHOR . '"', $html);
        self::assertStringContainsString('eventcrew-nl-bar-close', $html);
        self::assertStringContainsString('localStorage', $html);
        // It stays shown while focus is in it.
        self::assertStringContainsString('!b.contains(document.activeElement)', $html);
        // It fades in and out, gets hidden after the fade, and does not move with reduced motion.
        self::assertStringContainsString(
            '.eventcrew-nl-bar.is-out{opacity:0;transform:translateY(10px);pointer-events:none}',
            $html
        );
        self::assertStringContainsString('setTimeout(function(){b.hidden=true;},250)', $html);
        self::assertStringContainsString(
            '@media (prefers-reduced-motion:reduce){.eventcrew-nl-bar{transition:none}}',
            $html
        );
        // Fallback for a keyboard drawn over the page: lifts only past 100px, once per frame.
        self::assertStringContainsString('document.documentElement.clientHeight-vv.height-vv.offsetTop', $html);
        self::assertStringContainsString('c>100?', $html);
        self::assertStringContainsString('requestAnimationFrame(lift)', $html);
        // It waits for the first interaction, except with an answer to a bar sign-up.
        self::assertStringContainsString('wait=!b.hasAttribute("data-message")', $html);
        self::assertStringContainsString('ev=["scroll","wheel","touchstart","pointerdown","keydown"]', $html);
        self::assertStringContainsString('var hide=off||wait||', $html);
    }

    /** On a page with the bar, a block theme's viewport meta lets the keyboard resize the page. */
    public function testTheBarPageGetsTheResizingViewport(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->onPublicPage();
        add_action('wp_head', '_block_template_viewport_meta_tag', 0);
        $controller = $this->controller();

        self::assertSame('page.php', $controller->resizingViewport('page.php'));
        self::assertFalse(has_action('wp_head', '_block_template_viewport_meta_tag'));
        self::assertSame(0, has_action('wp_head', [$controller, 'printViewportMeta']));

        ob_start();
        $controller->printViewportMeta();
        self::assertStringContainsString('interactive-widget=resizes-content', (string) ob_get_clean());
    }

    /** Without the bar (or on a classic theme) the viewport meta is left alone. */
    public function testOtherPagesKeepTheirViewport(): void
    {
        $this->onPublicPage();
        add_action('wp_head', '_block_template_viewport_meta_tag', 0);
        $controller = $this->controller();
        $controller->resizingViewport('page.php');

        self::assertSame(0, has_action('wp_head', '_block_template_viewport_meta_tag'));
        self::assertFalse(has_action('wp_head', [$controller, 'printViewportMeta']));
    }

    public function testNoBarWhenTheNewsletterIsOff(): void
    {
        $this->onPublicPage();

        self::assertSame('', self::bar($this->controller()));
    }

    /** With an image chosen in Settings, the bar shows it under a dark layer. */
    public function testTheBarShowsTheChosenImage(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->options[Newsletter::BAR_IMAGE_OPTION] = 21;
        Functions\when('wp_get_attachment_image_url')->alias(
            static fn (int $id): string => 'https://site.test/uploads/image-' . $id . '.jpg'
        );
        $this->onPublicPage();

        $html = self::bar($this->controller());

        self::assertStringContainsString('class="eventcrew-nl-bar has-image"', $html);
        self::assertStringContainsString('linear-gradient(rgba(0,0,0,.55),rgba(0,0,0,.55))', $html);
        self::assertStringContainsString('url(https://site.test/uploads/image-21.jpg)', $html);
    }

    /** With the full form on the page, the bar has an "i" that leads to it; both use the outline button. */
    public function testTheBarLinksToTheFullFormWithAnInfoIcon(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->onPublicPage();
        $controller = $this->controller();

        $form = $controller->renderShortcode();
        $html = self::bar($controller);

        self::assertStringContainsString('class="eventcrew-nl-bar-info" href="#' . NewsletterController::FORM_ANCHOR . '"', $html);
        self::assertStringContainsString('<button type="submit" class="eventcrew-nl-button">Subscribe</button>', $form);
        self::assertStringContainsString('<button type="submit" class="eventcrew-nl-button">Subscribe</button>', $html);
        // The shared style is printed once per page, with the first form.
        self::assertStringContainsString('.eventcrew-nl-button{', $form);
        self::assertStringNotContainsString('.eventcrew-nl-button{', $html);
    }

    /** Both email fields use a name of our own, so a browser does not fill in a street address. */
    public function testTheEmailFieldsUseTheirOwnName(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->onPublicPage();
        $controller = $this->controller();

        foreach ([$controller->renderShortcode(), self::bar($controller)] as $html) {
            self::assertStringContainsString(
                'name="' . NewsletterController::EMAIL_FIELD . '" required autocomplete="email"',
                $html
            );
            self::assertStringNotContainsString('name="email"', $html);
        }
    }

    /** Without the full form on the page there is nothing for the "i" to lead to. */
    public function testNoInfoIconWithoutTheFullForm(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->onPublicPage();

        self::assertStringNotContainsString('class="eventcrew-nl-bar-info"', self::bar($this->controller()));
    }

    /** Without an image, the bar has its plain dark background and no image style. */
    public function testTheBarHasNoImageWhenNoneIsChosen(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->onPublicPage();

        $html = self::bar($this->controller());

        self::assertStringContainsString('<div class="eventcrew-nl-bar" id=', $html);
        self::assertStringNotContainsString('background-image', $html);
    }

    /** The form is also a block, so it can sit in a template part where a shortcode is not expanded. */
    public function testTheFormIsRegisteredAsABlock(): void
    {
        $registered = [];
        Functions\when('wp_register_script')->justReturn(true);
        Functions\when('plugins_url')->justReturn('https://site.test/editor.js');
        Functions\when('register_block_type')->alias(
            static function (string $name, array $args) use (&$registered): bool {
                $registered[$name] = $args;

                return true;
            }
        );

        $this->controller()->registerBlock();

        self::assertArrayHasKey(NewsletterController::BLOCK, $registered);
        self::assertSame('eventcrew/newsletter', NewsletterController::BLOCK);
        self::assertIsCallable($registered[NewsletterController::BLOCK]['render_callback']);
    }

    public function testNoBarInWpAdmin(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->onPublicPage(null, true);

        self::assertSame('', self::bar($this->controller()));
    }

    /** The crew app and sign-in page (the eventcrew_signup shortcode) has no bar. */
    public function testNoBarOnTheCrewAppPage(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $page = new \WP_Post();
        $page->post_content = '<!-- wp:shortcode -->[eventcrew_signup]<!-- /wp:shortcode -->';
        $this->onPublicPage($page);

        self::assertSame('', self::bar($this->controller()));
    }

    /** Without a full form on the page, the answer to a sign-up shows in the bar, even if it was closed. */
    public function testTheAnswerShowsInTheBarWhenThePageHasNoForm(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->onPublicPage();
        $_GET[NewsletterController::RESULT_ARG] = NewsletterController::RESULT_SENT;

        $html = self::bar($this->controller());

        self::assertStringContainsString('data-message="1"', $html);
        self::assertStringContainsString('<p class="eventcrew-nl-bar-message"', $html);
    }

    /** With a full form on the page, the answer shows there once, and the bar sends its answer to that form. */
    public function testTheAnswerShowsInTheFullFormWhenThePageHasOne(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->onPublicPage();
        $_GET[NewsletterController::RESULT_ARG] = NewsletterController::RESULT_SENT;
        $controller = $this->controller();

        $form = $controller->renderShortcode();
        $html = self::bar($controller);

        self::assertStringContainsString('eventcrew-newsletter-message', $form);
        self::assertStringNotContainsString('<p class="eventcrew-nl-bar-message"', $html);
        self::assertStringNotContainsString(' data-message="1"', $html);
        self::assertStringContainsString('name="anchor" value="' . NewsletterController::FORM_ANCHOR . '"', $html);
    }

    /** With Turnstile set up, its script tag is printed once even with both the form and the bar. */
    public function testTheTurnstileScriptIsAddedOnce(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->options[Turnstile::SITE_KEY_OPTION] = 'site-key';
        $this->options[Turnstile::SECRET_KEY_OPTION] = 'secret';
        $this->onPublicPage();
        $controller = $this->controller();

        $all = $controller->renderShortcode() . self::bar($controller);

        self::assertSame(1, substr_count($all, Turnstile::SCRIPT_URL));
        self::assertSame(2, substr_count($all, 'class="cf-turnstile"'));
        // Hidden in both unless Cloudflare needs a click.
        self::assertSame(2, substr_count($all, 'data-appearance="interaction-only"'));
    }
}

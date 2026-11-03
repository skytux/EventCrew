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
use EventCrew\Support\NewsletterConfirmMail;
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

    /** @var array<string, mixed> */
    private array $transients = [];

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
        $this->transients = [];
        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
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

    /** The confirmation is the gold card: both headings, the promise, the button with the link. */
    public function testTheConfirmationEmailIsTheCard(): void
    {
        $this->controller()->join('fan@example.test', '', '');
        $body = $this->mails[0]['body'];

        self::assertStringContainsString('Enää yksi askel', $body);
        self::assertStringContainsString('One step left', $body);
        self::assertStringContainsString('Ei muuta.', $body);
        self::assertStringContainsString('Vahvista / Confirm</a>', $body);
        self::assertStringContainsString('✦', $body);
        self::assertStringContainsString('announcement form on', $body);
        self::assertStringNotContainsString('border-radius:15px 15px 0 0', $body, 'no bar picture set, no picture');
    }

    /** The plain-text part writes the link out. */
    public function testTheConfirmationTextWritesTheLinkOut(): void
    {
        $fi = ['subject' => 's', 'heading' => 'Enää', 'promise' => 'Lupaus', 'body' => 'Teksti',
            'button' => 'Vahvista'];
        $en = ['subject' => 's', 'heading' => 'Left', 'promise' => 'Promise', 'body' => 'Text', 'button' => 'Confirm'];

        $text = NewsletterConfirmMail::text($fi, $en, 'https://site.test/c');
        $html = NewsletterConfirmMail::html($fi, $en, 'https://site.test/c', 'https://site.test/bar.jpg');

        self::assertStringContainsString("Vahvista / Confirm: https://site.test/c", $text);
        self::assertStringContainsString("Teksti", $text);
        self::assertStringContainsString("\nText", $text);
        self::assertStringContainsString('<img src="https://site.test/bar.jpg"', $html);
        self::assertLessThan(strpos($html, 'Confirm</a>'), strpos($html, 'Teksti'), 'Finnish text above the button');
        self::assertLessThan(strpos($html, 'Confirm</a>'), strpos($html, '>Text<'), 'English text above it too');
        self::assertLessThan(strpos($html, '>Text<'), strpos($html, 'Teksti'), 'Finnish first');
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
    /** A confirmed address is told so once an hour, with the site's email to write to. */
    public function testAConfirmedAddressGetsOneAlreadySubscribedEmail(): void
    {
        $this->options['eventcrew_privacy_contact'] = 'crew@site.test';
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);

        self::assertSame(NewsletterController::RESULT_SENT, $this->controller()->join('fan@example.test', '', ''));
        self::assertSame(NewsletterController::RESULT_SENT, $this->controller()->join('fan@example.test', '', ''));
        self::assertCount(1, $this->mails);
        self::assertSame('fan@example.test', $this->mails[0]['to']);
        self::assertStringContainsString('You are already subscribed', $this->mails[0]['subject']);
        self::assertStringContainsString('mailto:crew@site.test', $this->mails[0]['body']);
        self::assertStringNotContainsString('confirm', strtolower($this->mails[0]['body']));
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

    // ---- The second step: what to call the person ------------------------

    /** A new sign-up leaves a token naming its own row; the browser's language is stored. */
    public function testANewSignUpGetsATokenForItsOwnRow(): void
    {
        $controller = $this->controller();

        $controller->join('fan@example.test', '', '', '', 'en');

        self::assertSame(1, NewsletterController::nameTokenRow($controller->nameToken()));
        self::assertSame('en', $this->wpdb->inserts[0]['data']['language']);
    }

    /**
     * An address that is already subscribed, a repeat within the hour and the
     * honeypot all get a token too, naming no row, so the next page looks
     * the same whatever the address.
     */
    public function testOtherAnswersGetATokenThatNamesNoRow(): void
    {
        $controller = $this->controller();

        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);
        $controller->join('fan@example.test', '', '');
        self::assertSame(0, NewsletterController::nameTokenRow($controller->nameToken()));

        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_PENDING, '2026-07-20 11:30:00');
        $controller->join('fan@example.test', '', '');
        self::assertSame(0, NewsletterController::nameTokenRow($controller->nameToken()));

        $controller->join('fan@example.test', 'bot', '');
        self::assertSame(0, NewsletterController::nameTokenRow($controller->nameToken()));

        // A refused address gets no second step at all.
        $controller->join('not an address', '', '');
        self::assertSame('', $controller->nameToken());
    }

    public function testTheNameTokenExpiresAndCannotBeForged(): void
    {
        $token = NewsletterController::signNameToken(5, 1_000_000);

        self::assertSame(5, NewsletterController::nameTokenRow($token, 1_000_000 + 1799));
        self::assertNull(NewsletterController::nameTokenRow($token, 1_000_000 + 1801), 'after 30 minutes');
        self::assertNull(NewsletterController::nameTokenRow($token . 'x', 1_000_000));
        self::assertNull(NewsletterController::nameTokenRow(SignedLink::sign(Newsletter::PURPOSE_MANAGE, 5)));
    }

    /** The name is saved on the pending row the token names. */
    public function testTheSecondStepNamesThePendingRow(): void
    {
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_PENDING);

        $result = $this->controller()->saveName(NewsletterController::signNameToken(5), '  Anna ');

        self::assertSame(NewsletterController::RESULT_NAMED, $result);
        self::assertSame(['name' => 'Anna'], $this->wpdb->updates[0]['data']);
        self::assertSame(['id' => 5], $this->wpdb->updates[0]['where']);
    }

    /**
     * Nothing is written for a token naming no row, for a row that is no
     * longer pending, or for a forged token - and the first two answer just
     * like a real save.
     */
    public function testTheSecondStepWritesNothingElse(): void
    {
        $controller = $this->controller();

        $named = NewsletterController::RESULT_NAMED;

        self::assertSame($named, $controller->saveName(NewsletterController::signNameToken(0), 'Anna'));

        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);
        self::assertSame($named, $controller->saveName(NewsletterController::signNameToken(5), 'Anna'));

        $forged = SignedLink::signPayload('other', "5\n9999999999");
        self::assertSame(NewsletterController::RESULT_SENT, $controller->saveName($forged, 'Anna'));
        self::assertSame(
            NewsletterController::RESULT_SENT,
            $controller->saveName(NewsletterController::signNameToken(5, time() - 3600), 'Anna'),
            'expired'
        );

        self::assertSame([], $this->wpdb->updates);
    }

    /** After a sign-up in the full form, the form asks what to call the person. */
    public function testTheFullFormAsksForTheNameAfterTheSignUp(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->onPublicPage();
        $token = NewsletterController::signNameToken(5);
        $_GET[NewsletterController::RESULT_ARG] = NewsletterController::RESULT_SENT;
        $_GET[NewsletterController::NAME_TOKEN_ARG] = $token;
        $controller = $this->controller();

        $form = $controller->renderShortcode();
        $bar = self::bar($controller);

        self::assertStringContainsString('What should we call you?', $form);
        self::assertStringContainsString('name="' . NewsletterController::NAME_FIELD . '"', $form);
        self::assertStringContainsString('autocomplete="given-name"', $form);
        self::assertStringContainsString('name="action" value="' . NewsletterController::NAME_ACTION . '"', $form);
        self::assertStringContainsString('name="token" value="' . $token . '"', $form);
        self::assertStringContainsString('class="eventcrew-nl-skip"', $form);
        self::assertStringNotContainsString('name="' . NewsletterController::EMAIL_FIELD . '"', $form);
        self::assertStringNotContainsString('What should we call you?', $bar, 'asked once, in the full form');
    }

    /** Without the full form on the page, the bar asks. */
    public function testTheBarAsksForTheNameWhenThePageHasNoForm(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->onPublicPage();
        $_GET[NewsletterController::RESULT_ARG] = NewsletterController::RESULT_SENT;
        $_GET[NewsletterController::NAME_TOKEN_ARG] = NewsletterController::signNameToken(0);

        $bar = self::bar($this->controller());

        self::assertStringContainsString('data-message="1"', $bar);
        self::assertStringContainsString('<label for="eventcrew-nl-bar-name"', $bar);
        self::assertStringContainsString('name="anchor" value="' . NewsletterController::BAR_ANCHOR . '"', $bar);
        self::assertStringNotContainsString('name="' . NewsletterController::EMAIL_FIELD . '"', $bar);
    }

    /** A forged or expired token shows the plain answer, with no second step. */
    public function testABadNameTokenShowsNoSecondStep(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->onPublicPage();
        $_GET[NewsletterController::RESULT_ARG] = NewsletterController::RESULT_SENT;
        $_GET[NewsletterController::NAME_TOKEN_ARG] = NewsletterController::signNameToken(5, time() - 3600);

        $form = $this->controller()->renderShortcode();

        self::assertStringNotContainsString('What should we call you?', $form);
        self::assertStringContainsString('eventcrew-newsletter-message', $form);
        self::assertStringNotContainsString('name="' . NewsletterController::EMAIL_FIELD . '"', $form);
    }

    /** After a sign-up that went through, only the thank-you stays: no form and no footnote. */
    public function testAFinishedSignupShowsOnlyTheThankYou(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->onPublicPage();

        $finished = [
            NewsletterController::RESULT_SENT,
            NewsletterController::RESULT_NAMED,
            NewsletterController::RESULT_JOINED,
        ];

        foreach ($finished as $result) {
            $_GET[NewsletterController::RESULT_ARG] = $result;
            $form = $this->controller()->renderShortcode();

            self::assertStringContainsString('eventcrew-newsletter-message', $form, $result);
            self::assertStringNotContainsString('<form', $form, $result);
            self::assertStringNotContainsString('<p class="eventcrew-muted">', $form, $result);
            self::assertStringEndsWith('</div>', $form, $result);
        }
    }

    /** A wrong address or a failed bot check keeps the form, to try again. */
    public function testAFailedSignupKeepsTheForm(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->onPublicPage();

        foreach ([NewsletterController::RESULT_INVALID, NewsletterController::RESULT_CAPTCHA] as $result) {
            $_GET[NewsletterController::RESULT_ARG] = $result;
            $form = $this->controller()->renderShortcode();

            self::assertStringContainsString('eventcrew-newsletter-message', $form, $result);
            self::assertStringContainsString('name="' . NewsletterController::EMAIL_FIELD . '"', $form, $result);
            self::assertStringContainsString('<p class="eventcrew-muted">', $form, $result);
        }
    }

    // ---- The answer without a new page ------------------------------------

    /** The box for the script: the answer, the form again, back to the page it came from; no style or script. */
    public function testTheAnswerBoxKeepsTheFormAfterAWrongAddress(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->onPublicPage();
        $page = 'https://example.test/fi/';

        $html = $this->controller()->answerHtml(
            NewsletterController::RESULT_INVALID,
            '',
            $page,
            NewsletterController::FORM_ANCHOR
        );

        self::assertStringContainsString('That does not look like an email address.', $html);
        self::assertStringContainsString('name="' . NewsletterController::EMAIL_FIELD . '"', $html);
        self::assertStringContainsString('name="redirect_to" value="' . $page . '"', $html);
        self::assertStringNotContainsString('<style', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    /** After a sign-up the answer box asks for the name, and Skip leads back to the page. */
    public function testTheAnswerBoxAsksForTheName(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->onPublicPage();
        $token = NewsletterController::signNameToken(5);

        $html = $this->controller()->answerHtml(
            NewsletterController::RESULT_SENT,
            $token,
            'https://example.test/',
            NewsletterController::FORM_ANCHOR
        );

        self::assertStringContainsString('eventcrew-newsletter-message', $html);
        self::assertStringContainsString('name="token" value="' . $token . '"', $html);
        self::assertStringContainsString('href="https://example.test/?eventcrew_newsletter=sent#', $html);
        self::assertStringNotContainsString('name="' . NewsletterController::EMAIL_FIELD . '"', $html);
    }

    /** The bar's answer: the message and the second step inside the bar, with its close button. */
    public function testTheAnswerBarHoldsTheMessageAndTheSecondStep(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->onPublicPage();

        $html = $this->controller()->answerHtml(
            NewsletterController::RESULT_SENT,
            NewsletterController::signNameToken(0),
            'https://example.test/',
            NewsletterController::BAR_ANCHOR
        );

        self::assertStringContainsString('eventcrew-nl-bar-message', $html);
        self::assertStringContainsString('<label for="eventcrew-nl-bar-name"', $html);
        self::assertStringContainsString('eventcrew-nl-bar-close', $html);
        self::assertStringNotContainsString('id="' . NewsletterController::BAR_ANCHOR . '"', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    /** The page gets the sending script once, with the first form. */
    public function testThePageGetsTheSendingScriptOnce(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->onPublicPage();
        $controller = $this->controller();

        $page = $controller->renderShortcode() . self::bar($controller);

        self::assertSame(1, substr_count($page, 'window.eventcrewNlAjax=1'));
    }

    /**
     * The form has a hidden field named "action", so form.action is that field and
     * not the address. The script must read the attribute.
     */
    public function testTheSendingScriptReadsTheFormAddressAttribute(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->onPublicPage();

        $page = $this->controller()->renderShortcode();

        self::assertStringContainsString('<input type="hidden" name="action"', $page);
        self::assertStringContainsString('fetch(f.getAttribute("action")', $page);
        self::assertStringNotContainsString('fetch(f.action', $page);
    }

    /**
     * The script is printed in the block's HTML, where WordPress turns "&" into
     * "&#038;" (after a "<" it reads as a tag), which breaks the script.
     */
    public function testTheSendingScriptHasNoAmpersandOrLessThan(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->onPublicPage();

        $page = $this->controller()->renderShortcode();
        $start = strpos($page, '<script>(function(){if(window.eventcrewNlAjax)');
        self::assertNotFalse($start);
        $script = substr($page, $start + 8, strpos($page, '</script>', $start) - $start - 8);

        self::assertStringNotContainsString('&', $script);
        self::assertStringNotContainsString('<', $script);
    }

    // ---- The manage page --------------------------------------------------

    /** Opening the link only shows the page, with the name and language filled in. */
    public function testTheManagePageShowsTheNameAndLanguage(): void
    {
        $this->wpdb->nextRows[] = ['name' => 'Anna <b>', 'language' => 'fi']
            + self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);
        $token = SignedLink::sign(Newsletter::PURPOSE_MANAGE, 5);

        $page = $this->controller()->respond('manage', 'GET', $token);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('id="eventcrew-manage-name" name="name"', $page['html']);
        self::assertStringContainsString('value="Anna &lt;b&gt;"', $page['html']);
        self::assertStringContainsString('name="language" value="fi" checked', $page['html']);
        self::assertStringContainsString('name="language" value="en">', $page['html']);
        self::assertStringContainsString('name="act" value="leave"', $page['html']);
        self::assertStringContainsString('value="' . $token . '"', $page['html']);
        self::assertSame([], $this->wpdb->updates);
    }

    /** A GET with act=leave (a link scanner) changes nothing. */
    public function testTheManagePageNeedsThePostToChange(): void
    {
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);

        $token = SignedLink::sign(Newsletter::PURPOSE_MANAGE, 5);

        $this->controller()->respond('manage', 'GET', $token, ['act' => 'leave']);

        self::assertSame([], $this->wpdb->updates);
    }

    public function testSavingSetsTheNameAndTheLanguage(): void
    {
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);
        $this->wpdb->nextRows[] = ['name' => 'Annie', 'language' => 'en']
            + self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);

        $page = $this->controller()->respond(
            'manage',
            'POST',
            SignedLink::sign(Newsletter::PURPOSE_MANAGE, 5),
            ['act' => 'save', 'name' => 'Annie', 'language' => 'en']
        );

        self::assertStringContainsString('Saved.', $page['html']);
        self::assertSame([['name' => 'Annie'], ['language' => 'en']], array_column($this->wpdb->updates, 'data'));
        self::assertStringContainsString('name="language" value="en" checked', $page['html']);
    }

    public function testAnUnknownLanguageIsNotSaved(): void
    {
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);

        $this->controller()->respond(
            'manage',
            'POST',
            SignedLink::sign(Newsletter::PURPOSE_MANAGE, 5),
            ['act' => 'save', 'name' => '', 'language' => 'sv']
        );

        self::assertSame([['name' => null]], array_column($this->wpdb->updates, 'data'));
    }

    public function testLeavingUnsubscribesAndForgetsTheName(): void
    {
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);
        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_CONFIRMED);

        $page = $this->controller()->respond(
            'manage',
            'POST',
            SignedLink::sign(Newsletter::PURPOSE_MANAGE, 5),
            ['act' => 'leave']
        );

        self::assertStringContainsString('You have left the announcement.', $page['html']);
        self::assertSame(['status' => 'unsubscribed', 'name' => null], $this->wpdb->updates[0]['data']);
    }

    /** A left subscriber sees that they left; a pending row and a foreign token are not valid. */
    public function testTheManagePageRefusesWhatItCannotManage(): void
    {
        $controller = $this->controller();

        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_UNSUBSCRIBED);
        $left = $controller->respond('manage', 'GET', SignedLink::sign(Newsletter::PURPOSE_MANAGE, 5));
        self::assertStringContainsString('You have left the announcement.', $left['html']);
        self::assertStringNotContainsString('eventcrew-manage-name', $left['html']);

        $this->wpdb->nextRows[] = self::row(5, NewsletterSubscriberRepository::STATUS_PENDING);
        $pending = $controller->respond('manage', 'GET', SignedLink::sign(Newsletter::PURPOSE_MANAGE, 5));
        self::assertSame(404, $pending['status']);

        $foreign = $controller->respond('manage', 'GET', SignedLink::sign(Newsletter::PURPOSE_UNSUBSCRIBE, 5));
        self::assertSame(404, $foreign['status']);
        self::assertSame([], $this->wpdb->updates);
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
        unset($_GET[NewsletterController::RESULT_ARG], $_GET[NewsletterController::NAME_TOKEN_ARG]);
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
        self::assertStringContainsString('<a href="#top">Back to top ↑</a>', $html);
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
        // A link back to the top of the page follows the answer.
        self::assertStringContainsString('<p class="eventcrew-nl-top"><a href="#top">Back to top ↑</a></p>', $form);
        self::assertStringNotContainsString('<p class="eventcrew-nl-bar-message"', $html);
        self::assertStringNotContainsString(' data-message="1"', $html);
        self::assertStringContainsString('name="anchor" value="' . NewsletterController::FORM_ANCHOR . '"', $html);
    }

    /**
     * With Turnstile set up and the full form on the page, the Cloudflare check
     * is only in the full form; the bar hands its address to that form.
     */
    public function testWithTheFullFormTheCheckIsOnlyInTheFullForm(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->options[Turnstile::SITE_KEY_OPTION] = 'site-key';
        $this->options[Turnstile::SECRET_KEY_OPTION] = 'secret';
        $this->onPublicPage();
        $controller = $this->controller();

        $form = $controller->renderShortcode();
        $bar = self::bar($controller);

        self::assertSame(1, substr_count($form . $bar, Turnstile::SCRIPT_URL));
        self::assertSame(1, substr_count($form, 'class="cf-turnstile"'));
        self::assertSame(0, substr_count($bar, 'class="cf-turnstile"'));
        // Hidden unless Cloudflare needs a click.
        self::assertStringContainsString('data-appearance="interaction-only"', $form);
        // The bar's script sends its own form with the full form's token, answer in the bar.
        self::assertStringContainsString('ff.querySelector("[name=cf-turnstile-response]")', $bar);
        self::assertStringContainsString('a.value="eventcrew-nl-bar"', $bar);
        self::assertStringContainsString('bf.requestSubmit()', $bar);
        // When the full form has no token in 4 seconds, it scrolls there and submits that form.
        self::assertStringContainsString('n++>20){there();}', $bar);
        self::assertStringContainsString('ff.requestSubmit()', $bar);
    }

    /** Without the full form on the page, the bar keeps its own Cloudflare check. */
    public function testWithoutTheFullFormTheBarHasTheCheck(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->options[Turnstile::SITE_KEY_OPTION] = 'site-key';
        $this->options[Turnstile::SECRET_KEY_OPTION] = 'secret';
        $this->onPublicPage();

        $bar = self::bar($this->controller());

        self::assertSame(1, substr_count($bar, 'class="cf-turnstile"'));
        self::assertSame(1, substr_count($bar, Turnstile::SCRIPT_URL));
    }
}

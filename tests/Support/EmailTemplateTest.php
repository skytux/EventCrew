<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\EmailBody;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\Signature;
use EventCrew\Tests\TestCase;

/**
 * The HTML email pipeline: what a notifier's plain text turns into, and what
 * the editable wrapper is allowed to do to it.
 */
final class EmailTemplateTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<int, array{subject: string, body: string, headers: mixed}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = ['eventcrew_email_html' => '1'];
        $this->mails = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('rest_url')->alias(static fn (string $p): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_mail')->alias(
            function (string $to, string $subject, string $body, mixed $headers = []): bool {
                $this->mails[] = ['subject' => $subject, 'body' => $body, 'headers' => $headers];

                return true;
            }
        );
    }

    private function mailer(): Mailer
    {
        return new Mailer(new Logger(), new EmailTemplate(new Logger()));
    }

    public function testAMessageIsSentAsHtmlWithAButtonForEachAction(): void
    {
        $this->mailer()->toPerson(
            7,
            'sam@example.com',
            'Your ticket',
            "Hi Sam,\n\nYou're on the door.",
            [
                ['label' => 'Show your ticket', 'url' => 'https://site.test/ticket'],
                ['label' => 'Add to calendar', 'url' => 'https://site.test/calendar'],
            ]
        );

        $body = $this->mails[0]['body'];

        self::assertStringStartsWith('<!DOCTYPE html>', $body);
        self::assertContains('Content-Type: text/html; charset=UTF-8', $this->mails[0]['headers']);
        self::assertStringContainsString('You&#039;re on the door.', $body);

        // Both actions are white buttons, and only the first has the gold glow.
        self::assertStringContainsString('href="https://site.test/ticket"', $body);
        self::assertStringContainsString('href="https://site.test/calendar"', $body);
        self::assertSame(2, substr_count($body, '<td bgcolor="#ffffff" style="border-radius:28px;background:#ffffff'));
        self::assertSame(1, substr_count($body, 'box-shadow:0 0 18px rgba(255,190,120,.55)'));
    }

    public function testAnActionWithNoUrlIsDroppedRatherThanDrawn(): void
    {
        // A Telegram-only install has no public page, so boardUrl() is empty and
        // the "see open tasks" button has nowhere to go.
        $this->mailer()->toPerson(
            7,
            'sam@example.com',
            'A spot opened',
            'Someone dropped out.',
            [['label' => 'See open tasks', 'url' => '']]
        );

        self::assertStringNotContainsString('See open tasks', $this->mails[0]['body']);
    }

    public function testTextFromANotifierCannotInjectMarkup(): void
    {
        // Event and role names reach the body unescaped from the database, so
        // the body is the one place markup must not survive.
        $this->mailer()->toPerson(7, 'sam@example.com', 'Hi', 'Party <script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', $this->mails[0]['body']);
        self::assertStringContainsString('&lt;script&gt;', $this->mails[0]['body']);
    }

    public function testPlainTextIsSentWholeWhenHtmlIsTurnedOff(): void
    {
        $this->options['eventcrew_email_html'] = '';

        $this->mailer()->toPerson(
            7,
            'sam@example.com',
            'Your ticket',
            'You are on the door.',
            [['label' => 'Show your ticket', 'url' => 'https://site.test/ticket']]
        );

        $body = $this->mails[0]['body'];

        // No wrapper, and the action comes back as the labelled line these
        // bodies carried before buttons existed.
        self::assertStringNotContainsString('<!DOCTYPE html>', $body);
        self::assertStringContainsString('Show your ticket: https://site.test/ticket', $body);
    }

    public function testAnEditedWrapperWithoutTheContentTagFallsBackToTheDefault(): void
    {
        // A wrapper that has lost {{content}} would send an email with no
        // message in it, which is worse than ignoring the edit.
        $this->options['eventcrew_email_template_id'] = 5;
        Functions\when('get_post_type')->justReturn(EmailTemplate::POST_TYPE);
        Functions\when('get_post')->justReturn(
            (object) ['post_status' => 'publish', 'post_content' => '<p>Only a header, no slot.</p>']
        );

        $wrapper = (new EmailTemplate(new Logger()))->wrapper();

        self::assertStringContainsString('{{content}}', $wrapper);
        self::assertStringNotContainsString('Only a header', $wrapper);
    }

    public function testTheSiteLogoIsLinkedAndScaledToFitTheMasthead(): void
    {
        // Linked, not attached: embedding rides on phpmailer_init, which an
        // install sending through a relay never reaches, and a cid: with no
        // part behind it is a broken image rather than a missing one.
        Functions\when('get_theme_mod')->justReturn(9);
        Functions\when('wp_get_attachment_image_src')->justReturn(
            ['https://site.test/wp-content/uploads/logo.png', 400, 100]
        );

        $html = EmailTemplate::logoHtml();

        self::assertStringContainsString('src="https://site.test/wp-content/uploads/logo.png"', $html);
        self::assertStringNotContainsString('cid:', $html);
        // 400x100 is scaled down to the 230px-wide logo space above the card.
        self::assertStringContainsString('width="230" height="58"', $html);

        Functions\when('wp_get_attachment_image_src')->justReturn(
            ['https://site.test/wp-content/uploads/small.png', 200, 50]
        );

        // A smaller picture is never drawn larger than it is.
        self::assertStringContainsString('width="200" height="50"', EmailTemplate::logoHtml());
    }

    /**
     * The logo above the card is up to 230px wide and 140px high and keeps its
     * proportions, with width and height attributes for Outlook and
     * max-width:100% for a phone.
     */
    public function testTheBannerIsBigAndKeepsItsProportions(): void
    {
        Functions\when('get_theme_mod')->justReturn(9);
        Functions\when('wp_get_attachment_image_src')->justReturn(
            ['https://site.test/wp-content/uploads/banner.png', 1600, 400]
        );

        $html = EmailTemplate::logoHtml();

        self::assertStringContainsString('width="230" height="58"', $html);
        self::assertStringContainsString('width:230px;max-width:100%;height:auto', $html);

        Functions\when('wp_get_attachment_image_src')->justReturn(
            ['https://site.test/wp-content/uploads/tall.png', 600, 600]
        );

        self::assertStringContainsString('width="140" height="140"', EmailTemplate::logoHtml());
    }

    public function testAnExplicitLogoUrlWins(): void
    {
        $this->options['eventcrew_email_logo'] = 'https://cdn.example.com/logo.png';

        self::assertStringContainsString('src="https://cdn.example.com/logo.png"', EmailTemplate::logoHtml());
    }

    public function testTheLogoSitsOnTheNightBackgroundAboveTheCreamCard(): void
    {
        // A white logo - what a site usually has ready for a dark header - is
        // drawn on the dark background, never on the cream card.
        $html = (new EmailTemplate(new Logger()))->render('Hi', '<p>Body</p>', '<p>Footer</p>');

        self::assertStringContainsString('<body style="margin:0;padding:0;background:#0e091e;">', $html);
        self::assertStringContainsString('bgcolor="#0e091e"', $html);
        self::assertStringContainsString('bgcolor="#fbf6ec"', $html);
        self::assertLessThan(strpos($html, 'bgcolor="#fbf6ec"'), strpos($html, 'Test Site'));
        self::assertStringNotContainsString('{{', $html);
    }

    public function testTheBackdropHasThePurpleFadeAndThePictureWhenOneIsSet(): void
    {
        $plain = EmailTemplate::backdrop();

        self::assertStringContainsString('bgcolor="#0e091e"', $plain);
        self::assertStringContainsString('linear-gradient(180deg,#2b1d57 0%,#160f2e 60%,#0e091e 100%)', $plain);
        self::assertStringNotContainsString('url(', $plain);

        $this->options[EmailTemplate::BACKGROUND_OPTION] = 'https://site.test/crew.jpg';
        $photo = EmailTemplate::backdrop();

        // The background attribute is for Outlook; the fade is the layer
        // behind the photo, shown when the photo does not load.
        self::assertStringContainsString('background="https://site.test/crew.jpg"', $photo);
        self::assertStringContainsString(
            'background-image:url(\'https://site.test/crew.jpg\'),linear-gradient(180deg,#2b1d57',
            $photo
        );
    }

    public function testTheTaglineIsTheSiteDescriptionEscaped(): void
    {
        Functions\when('get_bloginfo')->alias(
            static fn (string $what = ''): string => 'description' === $what ? 'Dance <to> express' : 'Test Site'
        );

        self::assertStringContainsString('Dance &lt;to&gt; express', EmailTemplate::taglineHtml());

        Functions\when('get_bloginfo')->justReturn('');

        self::assertSame('', EmailTemplate::taglineHtml());
    }

    public function testACustomTemplateStillGetsTheAccent(): void
    {
        $this->options['eventcrew_app_theme_color'] = '#123456';
        $post = new \WP_Post();
        $post->post_status = 'publish';
        $post->post_content = '<div style="color:{{accent}}">{{content}}</div>';
        Functions\when('get_post_type')->justReturn(EmailTemplate::POST_TYPE);
        Functions\when('get_post')->justReturn($post);

        $html = (new EmailTemplate(new Logger()))->render('Hi', '<p>Body</p>', '<p>Footer</p>');

        self::assertStringContainsString('color:#123456', $html);
    }

    public function testLabelsFlipToDarkTextOnALightAccent(): void
    {
        // A site branded beige would otherwise get white text on near-white.
        self::assertSame(EmailBody::TEXT_COLOR, EmailBody::readableOn('#f6f0e4'));
        self::assertSame('#ffffff', EmailBody::readableOn('#111111'));
    }

    public function testTheMastheadFallsBackToTheSiteNameWithNoLogoAnywhere(): void
    {
        // No explicit URL, no Customizer logo and no Site Icon: the site's name,
        // set as a wordmark, beats an empty space at the top of the email.
        self::assertStringContainsString('Test Site', EmailTemplate::logoHtml());
        self::assertStringNotContainsString('<img', EmailTemplate::logoHtml());
    }

    public function testAHeadingLineBecomesAHeadingAndLosesItsMarks(): void
    {
        $html = EmailBody::toHtml("Intro.\n\n## Tickets\n\nShow it at the door.");

        self::assertStringContainsString('font-weight:700', $html);
        self::assertStringContainsString('>Tickets</p>', $html);
        self::assertStringNotContainsString('##', $html);
        self::assertSame("Intro.\n\nTickets\n\nShow it at the door.", EmailBody::toText("Intro.\n\n## Tickets\n\nShow it at the door."));
    }

    public function testRunsOfDashedLinesBecomeAList(): void
    {
        // The open-task roll-up and the recent-task recap both write these.
        $html = EmailBody::toHtml("Still open:\n\n- Door at Party\n- Bar at Party");

        self::assertStringContainsString('<ul', $html);
        self::assertStringContainsString('<li style="margin:0 0 6px">Door at Party</li>', $html);
    }

    public function testALineStartingWithAnEmojiUsesItAsTheBullet(): void
    {
        $html = EmailBody::toHtml("- 🎟️ Tickets\n- 🔹 Credits");

        self::assertStringContainsString('list-style:none', $html);
        self::assertStringContainsString('<li style="margin:0 0 12px">🎟️ Tickets</li>', $html);
    }

    public function testAPlainDashedListKeepsItsDots(): void
    {
        $html = EmailBody::toHtml("- 🎟️ Tickets\n- Credits");

        self::assertStringNotContainsString('list-style:none', $html);
    }

    public function testAHeadingGetsAnIdAndAContentsLinkPointsAtIt(): void
    {
        $html = EmailBody::toHtml("- 🎟️ [Tickets and credits](#ec-tickets-and-credits)\n\n## 🎟️ Tickets and credits");

        self::assertStringContainsString('<a href="#ec-tickets-and-credits"', $html);
        self::assertStringContainsString('id="ec-tickets-and-credits"', $html);
        self::assertSame('ec-tickets-and-credits', EmailBody::anchor('🎟️ Tickets and credits'));
    }

    public function testPlainTextKeepsTheWordsOfAContentsLink(): void
    {
        self::assertSame('- 🎟️ Tickets', EmailBody::toText('- 🎟️ [Tickets](#ec-tickets)'));
    }

    public function testTheDmVersionPutsABlankLineBetweenEmojiLines(): void
    {
        self::assertSame(
            "Tickets\n\n🔹 One\n\n🔹 Two\n- plain\n- plain",
            EmailBody::forDm("## Tickets\n\n- 🔹 One\n- 🔹 Two\n- plain\n- plain")
        );
    }

    public function testCentredCentresEveryButton(): void
    {
        $html = EmailBody::centred(EmailBody::toHtml('', [
            ['label' => 'One', 'url' => 'https://example.org/1'],
            ['label' => 'Two', 'url' => 'https://example.org/2'],
        ]));

        self::assertSame(2, substr_count($html, '<table role="presentation" align="center"'));
        self::assertStringContainsString('style="margin:8px auto 12px"', $html);
        self::assertStringContainsString('style="margin:0 auto 12px"', $html);
    }

    /** {{year}} is the year of the moment the email is for; without one, this year. */
    public function testTheYearIsTheYearOfTheGivenMoment(): void
    {
        $this->options[EmailTemplate::POST_OPTION] = 5;
        $post = new \WP_Post();
        $post->post_status = 'publish';
        $post->post_content = '{{content}} (c) {{year}}';
        Functions\when('get_post_type')->justReturn(EmailTemplate::POST_TYPE);
        Functions\when('get_post')->justReturn($post);
        $template = new EmailTemplate(new Logger());

        self::assertStringContainsString(
            'Hello (c) 2031',
            $template->render('S', 'Hello', '', false, new \DateTimeImmutable('2031-01-01 10:00:00'))
        );
        self::assertStringContainsString('Hello (c) ' . gmdate('Y'), $template->render('S', 'Hello', ''));
    }

    /**
     * The newsletter preview is the subscriber's email with the red stop
     * button and one line under the whole email, in the HTML and in the
     * plain text, and no List-Unsubscribe header.
     */
    public function testTheNewsletterPreviewIsTheSubscribersEmailWithTheStopButtonUnderIt(): void
    {
        $altBody = '';
        Functions\when('add_action')->alias(static function (string $hook, callable $callback) use (&$altBody): bool {
            if ('phpmailer_init' === $hook) {
                $mailer = new \stdClass();
                $callback($mailer);
                $altBody = (string) $mailer->AltBody;
            }

            return true;
        });
        $mailer = $this->mailer();
        $stop = ['label' => 'Do Not Send This', 'url' => 'https://site.test/stop'];

        self::assertTrue($mailer->toOrganizerAsSubscriber(
            'org@example.test',
            '[Preview] Coming events',
            '<p>The events</p>',
            'The events',
            'Leave the announcement',
            '#',
            'Crew note',
            $stop,
            'Goes out on 21.7.2026 at 09:00 to 3 subscribers.'
        ));

        $html = $this->mails[0]['body'];
        $subscriber = $mailer->subscriberHtml(
            '[Preview] Coming events',
            '<p>The events</p>',
            'Leave the announcement',
            '#',
            'Crew note'
        );
        $button = strpos($html, 'Do Not Send This');

        self::assertNotFalse($button);
        self::assertStringStartsWith(substr($subscriber, 0, (int) strpos($subscriber, '</body>')), $html);
        self::assertGreaterThan(strpos($html, 'Leave the announcement'), $button);
        self::assertStringContainsString('background:' . Mailer::STOP_COLOR, $html);
        self::assertGreaterThan($button, strpos($html, 'Goes out on 21.7.2026 at 09:00 to 3 subscribers.'));
        self::assertSame(['Content-Type: text/html; charset=UTF-8'], $this->mails[0]['headers']);
        self::assertStringStartsWith("The events\n\n—\nCrew note\nLeave the announcement: #", $altBody);
        self::assertStringEndsWith(
            "Do Not Send This: https://site.test/stop\nGoes out on 21.7.2026 at 09:00 to 3 subscribers.",
            $altBody
        );
    }

    /** **Bold** and *italic* in a text become strong and em. */
    public function testBoldAndItalicMarksBecomeStrongAndEm(): void
    {
        $html = EmailBody::toHtml("Bring **your ticket** and *a smile*.\n\n- **Doors** at 19");

        self::assertStringContainsString('Bring <strong>your ticket</strong> and <em>a smile</em>.', $html);
        self::assertStringContainsString('<strong>Doors</strong> at 19', $html);
        self::assertStringNotContainsString('*', $html);
    }

    /**
     * The marks are applied after escaping, so a mark around markup still
     * shows the markup as text, and they never touch a link or a command.
     */
    public function testBoldAndItalicAreEscapeSafeAndLeaveLinksAndCodeAlone(): void
    {
        $html = EmailBody::toHtml('**<script>x</script>** and *<b>*');

        self::assertStringContainsString('<strong>&lt;script&gt;x&lt;/script&gt;</strong>', $html);
        self::assertStringContainsString('<em>&lt;b&gt;</em>', $html);
        self::assertStringNotContainsString('<script>', $html);

        $html = EmailBody::toHtml('See https://site.test/a*b*c and `x*y*z` here.');

        self::assertStringContainsString('href="https://site.test/a*b*c"', $html);
        self::assertStringContainsString('>x*y*z</code>', $html);
        self::assertStringNotContainsString('<em>', $html);
    }

    /** A lone star, a sum or a star inside a word is left as it is. */
    public function testAStarThatIsNotAMarkStays(): void
    {
        $html = EmailBody::toHtml('2 * 3 = 6, file*name and ** alone');

        self::assertStringContainsString('2 * 3 = 6, file*name and ** alone', $html);
        self::assertStringNotContainsString('<em>', $html);
        self::assertStringNotContainsString('<strong>', $html);
    }

    /** The plain-text part drops the marks. */
    public function testThePlainTextDropsTheMarks(): void
    {
        self::assertSame('Bring your ticket and a smile.', EmailBody::plainHeadings('Bring **your ticket** and *a smile*.'));
    }

    /** A greeting as the first paragraph is its own line, in italics. */
    public function testTheGreetingIsItsOwnItalicLine(): void
    {
        $html = EmailBody::toHtml("Hi <Sam>,\n\nYou're on the door.");

        self::assertStringStartsWith(
            '<p style="margin:0 0 18px;font-family:' . EmailBody::HEAD_FONT . ';font-size:18px;font-style:italic;',
            $html
        );
        self::assertStringContainsString('>Hi &lt;Sam&gt;,</p>', $html);
        self::assertSame(1, substr_count($html, 'font-style:italic'));

        // A first paragraph that is a sentence is not a greeting.
        self::assertStringNotContainsString('font-style:italic', EmailBody::toHtml('You are on the door, Sam.'));
    }

    public function testWithGreetingPutsTheFirstNameOnTheFirstLine(): void
    {
        self::assertSame("Hi Sam,\n\nThe body.", EmailBody::withGreeting('Sam Smith', 'The body.'));
        self::assertSame("Hi,\n\nThe body.", EmailBody::withGreeting('  ', 'The body.'));
    }

    /**
     * The sign-off is a centred closing: the first line in italics, the rest
     * in gold, and one line is split after its first comma.
     */
    public function testTheSignOffIsACentredClosingSplitAtTheComma(): void
    {
        $this->options[Signature::OPTION] = 'With love, the <Ecstatic Dance Helsinki> crew';

        $html = Signature::html();

        self::assertMatchesRegularExpression(
            '~^<div [^>]*><p [^>]*font-style:italic;[^>]*text-align:center">With love,</p>'
                . '<p [^>]*color:#a8641f;text-align:center">the &lt;Ecstatic Dance Helsinki&gt; crew</p></div>$~',
            $html
        );

        $this->options[Signature::OPTION] = '';

        self::assertSame('', Signature::html());
    }

    /**
     * A two-language email shows each line once per language, one language
     * per row; the plain text has both sign-offs. A one-language email has
     * that language's sign-off. A blank Finnish sign-off uses the main one.
     */
    public function testTheSignOffInBothLanguages(): void
    {
        $this->options[Signature::OPTION] = "With love,\nthe crew";
        $this->options[Signature::FINNISH_OPTION] = "Rakkaudella,\nkrew";

        self::assertStringContainsString('>Rakkaudella,<br>With love,</p>', Signature::html(true));
        self::assertStringContainsString('>krew<br>the crew</p>', Signature::html(true));
        self::assertStringNotContainsString(' · ', Signature::html(true));
        self::assertStringNotContainsString('Rakkaudella', Signature::html(['en']));
        self::assertSame("With love,\nthe crew", Signature::bothText(['en']));
        self::assertSame("Rakkaudella,\nkrew\n\nWith love,\nthe crew", Signature::bothText());
        self::assertSame("Rakkaudella,\nkrew", Signature::text(\EventCrew\Support\Language::FINNISH));

        $this->options[Signature::FINNISH_OPTION] = '';

        self::assertSame("With love,\nthe crew", Signature::bothText());
        self::assertStringNotContainsString(' · ', Signature::html(true));
    }

    /** The default template draws the closing and the site name under the card. */
    public function testTheDefaultTemplateHasTheClosingAndTheSiteNameAtTheBottom(): void
    {
        $this->options[Signature::OPTION] = 'With love, the crew';

        $html = (new EmailTemplate(new Logger()))->render('Hi', '<p>Body</p>', '<p>Footer</p>');

        self::assertLessThan(strpos($html, 'Footer'), strpos($html, 'With love,'));
        self::assertStringContainsString('&#10022;&nbsp;&nbsp;Test Site&nbsp;&nbsp;&#10022;', $html);
        self::assertStringContainsString('@supports', $html);
        self::assertStringContainsString('prefers-reduced-motion:no-preference', $html);
    }
}

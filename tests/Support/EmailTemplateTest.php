<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\EmailBody;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
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

        // Both actions are buttons, and the first is the filled one - the accent
        // colour appears as its background, not merely as link text.
        self::assertStringContainsString('href="https://site.test/ticket"', $body);
        self::assertStringContainsString('href="https://site.test/calendar"', $body);
        self::assertStringContainsString('background:#4a6cf7', $body);
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
        // 400x100 fits the 260x72 masthead by width, so it lands at 260.
        self::assertStringContainsString('width="260"', $html);
    }

    public function testAnExplicitLogoUrlWins(): void
    {
        $this->options['eventcrew_email_logo'] = 'https://cdn.example.com/logo.png';

        self::assertStringContainsString('src="https://cdn.example.com/logo.png"', EmailTemplate::logoHtml());
    }

    public function testTheMastheadSitsOnTheAccentColour(): void
    {
        // A white logo - what a site usually has ready for a dark header - is
        // invisible on a white card, and reads as a broken image.
        $this->options['eventcrew_app_theme_color'] = '#123456';

        $html = (new EmailTemplate(new Logger()))->render('Hi', '<p>Body</p>', '<p>Footer</p>');

        self::assertStringContainsString('background:#123456', $html);
        self::assertStringNotContainsString('{{accent}}', $html);
    }

    public function testTheMastheadFallsBackToTheSiteNameWithNoLogoAnywhere(): void
    {
        // No explicit URL, no Customizer logo and no Site Icon: the site's name,
        // set as a wordmark, beats an empty space at the top of the email.
        self::assertStringContainsString('Test Site', EmailTemplate::logoHtml());
        self::assertStringNotContainsString('<img', EmailTemplate::logoHtml());
    }

    public function testRunsOfDashedLinesBecomeAList(): void
    {
        // The open-task roll-up and the recent-task recap both write these.
        $html = EmailBody::toHtml("Still open:\n\n- Door at Party\n- Bar at Party");

        self::assertStringContainsString('<ul', $html);
        self::assertStringContainsString('<li style="margin:0 0 6px">Door at Party</li>', $html);
    }
}

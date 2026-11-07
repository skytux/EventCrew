<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Support\EmailHero;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Tests\TestCase;

/**
 * The "Thank you" hero at the top of the sign-up email (Owner, 2026-11-07):
 * only that kind has one, it is drawn above the text in the HTML email with
 * a plain colour under every gradient, and the plain-text part leaves it out.
 */
final class EmailHeroTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = ['eventcrew_email_html' => '1'];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('rest_url')->alias(static fn (string $p): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('get_the_title')->justReturn('');
    }

    /** @return array<string, mixed> */
    private function context(string $name = 'Anna Virtanen'): array
    {
        return [
            'person' => Person::fromRow(['id' => 8, 'display_name' => $name, 'email' => '']),
            'task' => new Task(5, '2026-08-01', 'decorate', 2, null, 'Party', '2026-08-01 18:00:00', '2026-08-01 20:00:00'),
        ];
    }

    public function testTheHeroAndSubjectUseTheFirstNameWithoutAComma(): void
    {
        $context = $this->context('Anna Virtanen');

        self::assertSame('Thank you Anna!', EmailHero::for('signup_confirmed', $context)?->title);
        self::assertStringStartsWith(
            'Thank you Anna! ✨',
            \EventCrew\Support\MessageTemplates::subject('signup_confirmed', $context)
        );
    }

    public function testOnlyTheSignupEmailHasAHero(): void
    {
        $hero = EmailHero::for('signup_confirmed', $this->context());

        self::assertNotNull($hero);
        self::assertSame('Thank you Anna!', $hero->title);
        self::assertSame('Decorate · Sat 1 Aug 18:00–20:00', $hero->line);

        foreach (['signup_confirmed_dm', 'cancel_confirmed', 'reminder', 'slot_freed', 'nope'] as $id) {
            self::assertNull(EmailHero::for($id, $this->context()), $id);
        }
    }

    public function testWithoutANameTheTitleIsAPlainThankYou(): void
    {
        self::assertSame('Thank you!', EmailHero::for('signup_confirmed', $this->context(''))?->title);
        self::assertSame('Thank you!', EmailHero::for('signup_confirmed', ['task' => $this->context()['task']])?->title);
    }

    public function testTheHeroIsEmailSafeAndEscaped(): void
    {
        $html = (new EmailHero('Thank you, <b>Anna</b>!', 'Door & bar'))->html('#c0f');

        self::assertStringContainsString('Thank you, &lt;b&gt;Anna&lt;/b&gt;!', $html);
        self::assertStringContainsString('Door &amp; bar', $html);
        self::assertStringNotContainsString('<b>', $html);
        // A plain colour under the gradient, for the apps that draw none.
        self::assertStringContainsString('bgcolor="#2b1d57"', $html);
        self::assertMatchesRegularExpression('/background-color:#2b1d57;background-image:linear-gradient\(/', $html);
        // The accent tints the glow; a short hex is read in full.
        self::assertStringContainsString('rgba(204,0,255,0.55)', $html);
        // Sparkles are characters, not pictures.
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&#10024;', $html);
        // No line, no empty paragraph for it.
        self::assertStringNotContainsString('#e4d8ff', (new EmailHero('Thank you!'))->html('#4a6cf7'));
    }

    public function testTheMailerDrawsTheHeroAboveTheTextButNotInThePlainText(): void
    {
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()), true);

        $mailer->toPerson(7, 'anna@example.com', 'Thanks', "Hi Anna,\n\nThank you for joining the crew!", [], new EmailHero('Thank you, Anna!', 'Door'));
        $mailer->toPerson(7, 'anna@example.com', 'Other', "Hi Anna,\n\nSomething else.");

        [$with, $without] = $mailer->captured();

        self::assertStringContainsString('class="ec-shimmer-light"', $with['message']);
        self::assertLessThan(strpos($with['message'], 'Thank you for joining'), strpos($with['message'], 'Thank you, Anna!'));
        self::assertStringNotContainsString('Thank you, Anna!', $with['text']);
        self::assertStringStartsWith("Hi Anna,\n\nThank you for joining the crew!", $with['text']);

        self::assertStringNotContainsString('ec-shimmer-light" style="margin:14px', $without['message']);
        self::assertStringNotContainsString('#2b1d57;background-image:linear-gradient(135deg', $without['message']);
    }

    public function testAPlainTextEmailHasNoHero(): void
    {
        $this->options['eventcrew_email_html'] = '';
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()), true);

        $mailer->toPerson(7, 'anna@example.com', 'Thanks', 'Hi Anna,', [], new EmailHero('Thank you, Anna!'));

        self::assertStringNotContainsString('Thank you, Anna!', $mailer->captured()[0]['message']);
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Models\TicketBuyer;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\HybridPolicy;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\SiteLanguages;
use EventCrew\Support\TicketMail;
use EventCrew\Support\TicketQr;
use EventCrew\Tests\TestCase;

/**
 * HybridPolicy: when a message goes out in every site language, and in
 * which one language it goes out otherwise.
 */
final class HybridPolicyTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
    }

    /** @param list<string> $languages */
    private function siteLanguages(array $languages): void
    {
        $this->options[SiteLanguages::OPTION] = $languages;
    }

    private function person(?string $language): Person
    {
        return new Person(8, 'mia@example.test', 'Mia Crew', '2026-01-01 00:00:00', language: $language);
    }

    public function testTwoLanguagesAreFinnishAndEnglishWithFinnishAsTheMainLanguage(): void
    {
        self::assertSame(['fi', 'en'], HybridPolicy::siteLanguages());
        self::assertSame('fi', HybridPolicy::mainLanguage());
        self::assertTrue(HybridPolicy::isAllowed());
        self::assertSame(['fi', 'en'], HybridPolicy::languagesFor(HybridPolicy::TICKET));
    }

    public function testThreeLanguagesStillAllowHybrid(): void
    {
        $this->siteLanguages(['fi', 'en', 'sv']);

        self::assertTrue(HybridPolicy::isAllowed());
        self::assertTrue(HybridPolicy::isOn(HybridPolicy::TICKET));
        self::assertSame(['fi', 'en', 'sv'], HybridPolicy::languagesFor(HybridPolicy::TICKET, $this->person('en')));
    }

    public function testFourLanguagesSwitchHybridOffForEveryKind(): void
    {
        $this->siteLanguages(['fi', 'en', 'sv', 'de']);

        self::assertFalse(HybridPolicy::isAllowed());

        foreach (HybridPolicy::KINDS as $kind) {
            self::assertTrue(HybridPolicy::ticked($kind), 'the stored tick stays on');
            self::assertFalse(HybridPolicy::isOn($kind), $kind);
        }

        self::assertSame(['sv'], HybridPolicy::languagesFor(HybridPolicy::TICKET, $this->person('sv')));
        self::assertSame(['fi'], HybridPolicy::languagesFor(HybridPolicy::TICKET));
        self::assertStringContainsString('4 languages', HybridPolicy::offWarning());
    }

    public function testWithTheTickOffUnknownLanguagesGetTheMainLanguage(): void
    {
        $this->options[HybridPolicy::OPTION] = [HybridPolicy::TASK_CANCELLED => false];

        self::assertSame(['en'], HybridPolicy::languagesFor(HybridPolicy::TASK_CANCELLED, $this->person('en')));
        self::assertSame(['fi'], HybridPolicy::languagesFor(HybridPolicy::TASK_CANCELLED, $this->person(null)));
        self::assertSame(['fi'], HybridPolicy::languagesFor(HybridPolicy::TASK_CANCELLED, 'xx'), 'not a site language');
        self::assertSame(['fi'], HybridPolicy::languagesFor(HybridPolicy::TASK_CANCELLED, null));
        self::assertSame(['en'], HybridPolicy::languagesFor(HybridPolicy::TASK_CANCELLED, 'en'));
    }

    public function testEachKindHasItsOwnTickAndAllAreOnByDefault(): void
    {
        foreach (HybridPolicy::KINDS as $kind) {
            self::assertTrue(HybridPolicy::isOn($kind), $kind);
        }

        $this->options[HybridPolicy::OPTION] = HybridPolicy::fromPost([HybridPolicy::TICKET => '1']);

        self::assertTrue(HybridPolicy::isOn(HybridPolicy::TICKET));
        self::assertFalse(HybridPolicy::isOn(HybridPolicy::FOLLOWUP));
        self::assertFalse(HybridPolicy::isOn(HybridPolicy::TAKEOVER_GROUP));
        self::assertFalse(HybridPolicy::isOn('no_such_kind'));
        self::assertSame(HybridPolicy::KINDS, array_keys(HybridPolicy::labels()));
    }

    public function testJoinAndTags(): void
    {
        self::assertSame('Hei / Hi', HybridPolicy::join(['fi' => 'Hei', 'en' => 'Hi']));
        self::assertSame('Hi', HybridPolicy::join(['fi' => 'Hi', 'en' => 'Hi']));
        self::assertSame(
            ['fi' => 'FI', 'en' => 'EN', 'sv' => 'SV'],
            HybridPolicy::tags(['fi' => 'a', 'en' => 'b', 'sv' => 'b'])
        );
        self::assertSame(['fi' => '', 'en' => ''], HybridPolicy::tags(['fi' => 'a', 'en' => 'a']));
        self::assertSame(['en' => ''], HybridPolicy::tags(['en' => 'a']));
    }

    /** The ticket email: Finnish and English with the tick on, Finnish only with it off. */
    public function testTheTicketEmailFollowsTheTick(): void
    {
        Functions\when('get_the_title')->justReturn('');
        Functions\when('get_permalink')->justReturn('https://site.test/crew/');
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        $mail = new TicketMail(
            new TicketBuyerRepository(),
            new GuestRepository(),
            new TaskRepository(),
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            new TicketQr(),
            new AssignmentRepository(),
            new RedemptionRepository()
        );
        $buyer = TicketBuyer::fromRow([
            'id' => 1, 'event_date' => '2026-11-14', 'name' => 'Anna Virtanen', 'source' => 'holvi',
            'batch' => 'b1', 'email' => 'anna@example.test', 'ticket_code' => 'AB12CD1', 'ticket_mail_status' => '',
        ]);

        $hybrid = $mail->compose($buyer);

        self::assertSame('Lippusi / Your ticket – 14.11.2026', $hybrid['subject']);
        self::assertStringContainsString('>FI</span>', $hybrid['html']);
        self::assertStringContainsString('>EN</span>', $hybrid['html']);
        self::assertStringContainsString('Hei Anna', $hybrid['text']);
        self::assertStringContainsString('Hi Anna', $hybrid['text']);
        self::assertSame(
            'Saat tämän viestin, koska sinulla on lippu tähän tapahtumaan.'
                . ' / You get this email because you have a ticket to this event.',
            $mail->footer()
        );

        $this->options[HybridPolicy::OPTION] = [HybridPolicy::TICKET => false];
        $single = $mail->compose($buyer);

        self::assertSame('Lippusi – 14.11.2026', $single['subject']);
        self::assertStringNotContainsString('>FI</span>', $single['html']);
        self::assertStringContainsString('Hei Anna', $single['text']);
        self::assertStringNotContainsString('Hi Anna', $single['text']);
        self::assertStringNotContainsString(' / ', $mail->footer());
        self::assertStringNotContainsString('You get this email', $mail->footer());
        self::assertStringContainsString('You get this email', $mail->footer('en'), 'a known language is kept');
    }
}

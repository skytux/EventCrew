<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\Language;
use EventCrew\Support\PrivacyPolicy;
use EventCrew\Support\Turnstile;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Tests\TestCase;

final class PrivacyPolicyTest extends TestCase
{
    /**
     * @param array<string, mixed> $options
     */
    private function withOptions(array $options): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => $options[$name] ?? $default
        );
    }

    /**
     * The notice is worth nothing if it cannot name who is answerable, so the
     * page and the policy suggestion both refuse until it can.
     */
    public function testIsNotConfiguredUntilBothControllerAndContactAreSet(): void
    {
        $this->withOptions([]);
        self::assertFalse(PrivacyPolicy::isConfigured());

        $this->withOptions([PrivacyPolicy::CONTROLLER_OPTION => 'Dance Club']);
        self::assertFalse(PrivacyPolicy::isConfigured());

        $this->withOptions([
            PrivacyPolicy::CONTROLLER_OPTION => 'Dance Club',
            PrivacyPolicy::CONTACT_OPTION => 'privacy@example.test',
        ]);
        self::assertTrue(PrivacyPolicy::isConfigured());
    }

    /**
     * A DPO is published on the strength of a contact address, not a name: a
     * name with nowhere to write to is not a designation anyone can use.
     */
    public function testADpoCountsAsDesignatedOnlyWithAContact(): void
    {
        $this->withOptions([PrivacyPolicy::DPO_NAME_OPTION => 'Ada Lovelace']);
        self::assertFalse(PrivacyPolicy::hasDpo());

        $this->withOptions([PrivacyPolicy::DPO_CONTACT_OPTION => 'dpo@example.test']);
        self::assertTrue(PrivacyPolicy::hasDpo());
    }

    public function testTheNoticeNamesTheControllerAndTheOfficer(): void
    {
        $this->withOptions([
            PrivacyPolicy::CONTROLLER_OPTION => 'Dance Club',
            PrivacyPolicy::CONTACT_OPTION => 'privacy@example.test',
            PrivacyPolicy::ADDRESS_OPTION => '1 High Street, Helsinki',
            PrivacyPolicy::DPO_NAME_OPTION => 'Ada Lovelace',
            PrivacyPolicy::DPO_CONTACT_OPTION => 'dpo@example.test',
        ]);

        $text = $this->flatten(PrivacyPolicy::sections());

        self::assertStringContainsString('Dance Club', $text);
        self::assertStringContainsString('1 High Street, Helsinki', $text);
        self::assertStringContainsString('privacy@example.test', $text);
        self::assertStringContainsString('Ada Lovelace', $text);
        self::assertStringContainsString('dpo@example.test', $text);
    }

    /**
     * With no officer designated the notice says so and explains why, rather
     * than leaving the reader to wonder whether the question was forgotten.
     */
    public function testTheNoticeExplainsTheAbsenceOfAnOfficer(): void
    {
        $this->withOptions([
            PrivacyPolicy::CONTROLLER_OPTION => 'Dance Club',
            PrivacyPolicy::CONTACT_OPTION => 'privacy@example.test',
        ]);

        self::assertStringContainsString(
            'not designated a data protection officer',
            $this->flatten(PrivacyPolicy::sections())
        );
    }

    /**
     * The recipients section must describe this install, not a generic one: an
     * install with no bot has no business telling people their data reaches
     * Telegram.
     */
    public function testTelegramAndCloudflareAreListedOnlyWhenInUse(): void
    {
        $this->withOptions([
            PrivacyPolicy::CONTROLLER_OPTION => 'Dance Club',
            PrivacyPolicy::CONTACT_OPTION => 'privacy@example.test',
        ]);

        $text = $this->flatten(PrivacyPolicy::sections());

        self::assertStringNotContainsString('Telegram', $text);
        self::assertStringNotContainsString('Cloudflare', $text);

        $this->withOptions([
            PrivacyPolicy::CONTROLLER_OPTION => 'Dance Club',
            PrivacyPolicy::CONTACT_OPTION => 'privacy@example.test',
            TelegramClient::TOKEN_OPTION => '123:abc',
            Turnstile::SITE_KEY_OPTION => 'site',
            Turnstile::SECRET_KEY_OPTION => 'secret',
        ]);

        $text = $this->flatten(PrivacyPolicy::sections());

        self::assertStringContainsString('Telegram', $text);
        self::assertStringContainsString('Cloudflare', $text);
    }

    /**
     * The Telegram sign-in sentences appear only when the sign-in is on: an
     * install with a bot but no Telegram sign-in must not describe one.
     */
    public function testTheTelegramSignInIsDescribedOnlyWhenItIsEnabled(): void
    {
        $options = [
            PrivacyPolicy::CONTROLLER_OPTION => 'Dance Club',
            PrivacyPolicy::CONTACT_OPTION => 'privacy@example.test',
            TelegramClient::TOKEN_OPTION => '123:abc',
            \EventCrew\Telegram\BoardService::USERNAME_OPTION => 'crewbot',
        ];
        $held = 'If you sign in with Telegram, the Crew app makes a one-time code and puts a cookie in your browser';
        $processor = 'and signing in with Telegram goes through a chat with the bot';

        $this->withOptions($options);
        $text = $this->flatten(PrivacyPolicy::sections());

        self::assertStringContainsString('If you link a Telegram account', $text);
        self::assertStringNotContainsString($held, $text);
        self::assertStringNotContainsString($processor, $text);

        $this->withOptions($options + [\EventCrew\Support\TelegramLogin::ENABLED_OPTION => true]);
        $text = $this->flatten(PrivacyPolicy::sections());

        self::assertStringContainsString($held, $text);
        self::assertStringContainsString($processor, $text);
        self::assertSame(1, substr_count($text, 'messages the bot sends you pass through their service'));
    }

    /**
     * The published retention period has to match the one the purge enforces,
     * and an out-of-range option must not silently become a shorter one.
     */
    public function testTheRetentionPeriodIsPublishedAndClampedToTheDefault(): void
    {
        $this->withOptions([
            PrivacyPolicy::CONTROLLER_OPTION => 'Dance Club',
            PrivacyPolicy::CONTACT_OPTION => 'privacy@example.test',
            PrivacyPolicy::RETENTION_OPTION => 5,
        ]);

        self::assertSame(5, PrivacyPolicy::retentionYears());
        self::assertStringContainsString('5 years', $this->flatten(PrivacyPolicy::sections()));

        $this->withOptions([PrivacyPolicy::RETENTION_OPTION => 0]);
        self::assertSame(PrivacyPolicy::RETENTION_DEFAULT, PrivacyPolicy::retentionYears());

        $this->withOptions([PrivacyPolicy::RETENTION_OPTION => 999]);
        self::assertSame(PrivacyPolicy::RETENTION_DEFAULT, PrivacyPolicy::retentionYears());
    }

    /**
     * What the notice promises about deletion has to depend on whether the
     * purge is actually switched on - promising automatic erasure that never
     * happens is the one failure mode here that matters.
     */
    public function testTheNoticePromisesAutomaticErasureOnlyWhenThePurgeIsOn(): void
    {
        $base = [
            PrivacyPolicy::CONTROLLER_OPTION => 'Dance Club',
            PrivacyPolicy::CONTACT_OPTION => 'privacy@example.test',
        ];

        $this->withOptions($base);
        self::assertStringContainsString('cleared by hand', $this->flatten(PrivacyPolicy::sections()));

        $this->withOptions($base + [PrivacyPolicy::PURGE_OPTION => '1']);
        self::assertStringContainsString('anonymised automatically', $this->flatten(PrivacyPolicy::sections()));
    }

    /**
     * Names are shown to other crew (the bot's /roster on the same night, the
     * group's takeover message and, with the card format on, the group
     * board), while standing stays with the organizers and the sales desk.
     * The notice has to say exactly that, and nothing about Telegram when the
     * bot is not set up.
     */
    public function testTheNoticeSaysWhoSeesNamesAndStanding(): void
    {
        $base = [
            PrivacyPolicy::CONTROLLER_OPTION => 'Dance Club',
            PrivacyPolicy::CONTACT_OPTION => 'privacy@example.test',
        ];
        $standing = 'No other crew member sees your standing or your cancel and no-show marks.';
        $covers = 'when someone covers a task for you, or you cover one for someone else';

        $this->withOptions($base);
        $text = $this->flatten(PrivacyPolicy::sections());
        self::assertStringContainsString($standing, $text);
        self::assertStringNotContainsString('never by whom', $text);
        self::assertStringNotContainsString('/roster', $text);

        $this->withOptions($base + [TelegramClient::TOKEN_OPTION => '123:abc']);
        $text = $this->flatten(PrivacyPolicy::sections());
        self::assertStringContainsString($standing, $text);
        self::assertStringContainsString(
            'Other crew members on the same night can see your name and the tasks you are on, in the bot’s /roster.',
            $text
        );
        self::assertStringContainsString($covers, $text);
        self::assertStringNotContainsString('first name', $text);

        $this->withOptions($base + [TelegramClient::TOKEN_OPTION => '123:abc', BoardService::CARDS_OPTION => '1']);
        $text = $this->flatten(PrivacyPolicy::sections());
        self::assertStringContainsString('Other crew members can see your name and the tasks you are on.', $text);
        self::assertStringContainsString(
            'shows the first name of each person on a task for the next three events',
            $text
        );
        self::assertStringContainsString($covers, $text);
        self::assertStringNotContainsString('never by whom', $text);
    }

    /**
     * A link to a page that does not exist is worse than no link, so the
     * callers are handed an empty string to check rather than a broken URL.
     */
    public function testTheNoticeUrlFallsBackToCoreAndThenToNothing(): void
    {
        Functions\when('get_post_status')->justReturn('publish');
        Functions\when('get_permalink')->alias(
            static fn (int $id): string => 'https://site.test/?p=' . $id
        );

        $this->withOptions([PrivacyPolicy::PAGE_OPTION => 12]);
        self::assertSame('https://site.test/?p=12', PrivacyPolicy::noticeUrl());

        $this->withOptions(['wp_page_for_privacy_policy' => 3]);
        self::assertSame('https://site.test/?p=3', PrivacyPolicy::noticeUrl());

        $this->withOptions([]);
        self::assertSame('', PrivacyPolicy::noticeUrl());
    }

    /**
     * Q306: in Finnish the link goes to the notice page's Finnish translation
     * when the site has one, and a translated notice says the English
     * original applies.
     */
    public function testInFinnishTheNoticeLinkGoesToTheFinnishPage(): void
    {
        Functions\when('get_post_status')->justReturn('publish');
        Functions\when('get_permalink')->alias(static fn (int $id): string => 'https://site.test/?p=' . $id);
        Functions\when('pll_get_post')->alias(static fn (int $id, string $l): int => 'fi' === $l && 12 === $id ? 40 : 0);
        $this->withOptions([PrivacyPolicy::PAGE_OPTION => 12]);

        self::assertSame('https://site.test/?p=12', PrivacyPolicy::noticeUrl());
        self::assertSame('', PrivacyPolicy::translationNote());
        self::assertSame('https://site.test/?p=40', Language::run('fi', static fn () => PrivacyPolicy::noticeUrl()));
        self::assertSame('https://site.test/?p=12', Language::run('en', static fn () => PrivacyPolicy::noticeUrl()));
        self::assertSame('', Language::run('en', static fn () => PrivacyPolicy::translationNote()));
        self::assertSame(
            'Jos käännös ja englanninkielinen alkuperäinen eroavat, noudatetaan englanninkielistä alkuperäistä.',
            Language::run('fi', static fn () => PrivacyPolicy::translationNote())
        );
    }

    /**
     * A page in the trash is not a published notice, and linking to it would
     * send people to a 404.
     */
    public function testAnUnpublishedPageIsNotLinked(): void
    {
        Functions\when('get_post_status')->justReturn('draft');
        Functions\when('get_permalink')->justReturn('https://site.test/?p=12');

        $this->withOptions([PrivacyPolicy::PAGE_OPTION => 12]);

        self::assertSame('', PrivacyPolicy::noticeUrl());
    }

    /**
     * The ticket buyer notice says what is taken from the shop export, that
     * the address can be a dash, and that the email, name and ticket code go
     * 60 days after the event unless the buyer joined the newsletter (Q057).
     */
    public function testTheTicketBuyerNoticeStatesWhatWhyAndHowLong(): void
    {
        $this->withOptions([
            PrivacyPolicy::CONTROLLER_OPTION => 'Dance Club',
            PrivacyPolicy::CONTACT_OPTION => 'privacy@example.test',
        ]);

        $text = $this->flatten(PrivacyPolicy::buyerSections());

        self::assertStringContainsString('ticket buyer records described here is Dance Club', $text);
        self::assertStringContainsString('privacy@example.test', $text);
        self::assertStringContainsString('your name, your email address, and your order or ticket code', $text);
        self::assertStringContainsString('date of the event', $text);
        self::assertStringContainsString('a dash ("-") in the address field', $text);
        self::assertStringContainsString('check you in at the door', $text);
        self::assertStringContainsString('invites you to join our next events announcement', $text);
        self::assertStringContainsString('We delete your email address, your name and your ticket code 60 days after the event', $text);
        self::assertStringContainsString('unless you have joined our next events announcement', $text);
        self::assertStringContainsString('You can leave it at any time', $text);
        self::assertStringContainsString('we email you a thank-you message with your ticket', $text);
        self::assertStringContainsString('guest list with your email address', $text);
        self::assertStringContainsString('A guest\'s email address is deleted 60 days after the event too', $text);
        self::assertStringContainsString('we keep your email address on a do-not-send list, so that we send you no more of these messages', $text);
        // Not the crew notice: nothing about tasks or the crew record.
        self::assertStringNotContainsString('crew records', $text);
        self::assertStringNotContainsString('claim a task', $text);
    }

    public function testTheTicketBuyerNoticeHasAFinnishVersion(): void
    {
        $this->withOptions([
            PrivacyPolicy::CONTROLLER_OPTION => 'Dance Club',
            PrivacyPolicy::CONTACT_OPTION => 'privacy@example.test',
        ]);

        $text = Language::run(
            Language::FINNISH,
            fn (): string => $this->flatten(PrivacyPolicy::buyerSections())
        );

        self::assertStringContainsString('viivan ("-")', $text);
        self::assertStringContainsString('Poistamme sähköpostiosoitteesi, nimesi ja lippukoodisi 60 päivän kuluttua tapahtumasta', $text);
        self::assertStringContainsString('lipunostajien tietojen rekisterinpitäjä on Dance Club', $text);
        self::assertStringContainsString('säilytämme sähköpostiosoitteesi ei-lähetetä-listalla', $text);
    }

    public function testTheTicketBuyerNoticeUrlUsesItsOwnPageThenCore(): void
    {
        Functions\when('get_post_status')->justReturn('publish');
        Functions\when('get_permalink')->alias(
            static fn (int $id): string => 'https://site.test/?p=' . $id
        );

        $this->withOptions([PrivacyPolicy::BUYER_PAGE_OPTION => 14, PrivacyPolicy::PAGE_OPTION => 12]);
        self::assertSame('https://site.test/?p=14', PrivacyPolicy::buyerNoticeUrl());

        $this->withOptions(['wp_page_for_privacy_policy' => 3]);
        self::assertSame('https://site.test/?p=3', PrivacyPolicy::buyerNoticeUrl());

        $this->withOptions([]);
        self::assertSame('', PrivacyPolicy::buyerNoticeUrl());
    }

    /**
     * @param array<int, array{heading: string, paragraphs: array<int, string>}> $sections
     */
    private function flatten(array $sections): string
    {
        $text = '';

        foreach ($sections as $section) {
            $text .= $section['heading'] . "\n" . implode("\n", $section['paragraphs']) . "\n";
        }

        return $text;
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\PrivacyPolicy;
use EventCrew\Support\Turnstile;
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

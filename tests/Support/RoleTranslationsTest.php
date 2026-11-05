<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\Language;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\Roles;
use EventCrew\Support\RoleTranslations;
use EventCrew\Support\TranslatedText;
use EventCrew\Support\TranslationRegistry;
use EventCrew\Support\TranslationRow;
use EventCrew\Tests\TestCase;

/**
 * Q307: the role names and descriptions are translated by the language
 * model in the background, a hand edit is kept, and a reader gets their
 * language or the original. The model is a stub of wp_remote_post that
 * "translates" each text into Finnish as "FI <text>"; nothing leaves the
 * test.
 */
final class RoleTranslationsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<list<array<string, mixed>>> The texts each request sent. */
    private array $sent = [];

    /** @var list<string> */
    private array $scheduled = [];

    private bool $cron = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sent = [];
        $this->scheduled = [];
        $this->cron = true;
        $this->options = [
            AssistantSettings::URL_OPTION => 'https://llm.example.test/v1',
            AssistantSettings::MODEL_OPTION => 'test-model',
            // An old-shape role: plain strings, never translated.
            Roles::OPTION_NAME => [
                ['slug' => 'decorate', 'label' => 'Decorate', 'description' => 'Hang the lights.', 'capacity' => 2],
                [
                    'slug' => 'welcome',
                    'label' => 'Welcome',
                    'label_text' => [
                        'source' => 'Welcome',
                        'lang' => 'en',
                        'texts' => ['en' => 'Welcome', 'fi' => 'Vastaanotto'],
                        'manual' => ['fi' => true],
                    ],
                    'description' => '',
                ],
            ],
        ];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('wp_doing_cron')->alias(fn (): bool => $this->cron);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string => (string) json_encode($value));
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->alias(function (int $at, string $hook): bool {
            $this->scheduled[] = $hook;

            return true;
        });
        Functions\when('wp_remote_post')->alias(function (string $url, array $args): array {
            $body = json_decode((string) $args['body'], true);
            $texts = json_decode((string) $body['messages'][1]['content'], true);
            $this->sent[] = $texts;

            return ['answer' => (string) json_encode(array_map(
                static fn (array $text): array => ['id' => $text['id'], 'lang' => 'en', 'fi' => 'FI ' . $text['text']],
                $texts
            )), 'status' => 200];
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(
            static fn (array $response): int => $response['status']
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            static fn (array $response): string => (string) json_encode(
                ['choices' => [['message' => ['content' => $response['answer']]]]]
            )
        );
    }

    private function translations(): RoleTranslations
    {
        $registry = new TranslationRegistry(new TranslatedText(new LlmClient(new Logger()), new Logger()));
        $registry->add([Roles::class, 'translationRows']);
        $registry->add(static fn (): array => [
            new TranslationRow(
                'other.text',
                'Some other text',
                TranslationRow::GROUP_TEXTS,
                ['source' => 'Other', 'lang' => 'en', 'texts' => ['en' => 'Other', 'fi' => '']],
                static function (): void {
                    self::fail('Only the role texts are translated here.');
                }
            ),
        ]);

        return new RoleTranslations($registry);
    }

    public function testTheBackgroundRunTranslatesTheRoleTextsAndKeepsAHandEdit(): void
    {
        $done = $this->translations()->run();

        self::assertCount(1, $this->sent);
        self::assertSame(['Decorate', 'Hang the lights.'], array_column($this->sent[0], 'text'));
        self::assertSame(['role.decorate.label', 'role.decorate.description'], $done);

        Language::run('fi', function (): void {
            self::assertSame('FI Decorate', Roles::label('decorate'));
            self::assertSame('FI Hang the lights.', Roles::description('decorate'));
            self::assertSame('Vastaanotto', Roles::label('welcome'));
        });
        Language::run('en', function (): void {
            self::assertSame('Decorate', Roles::label('decorate'));
            self::assertSame('Hang the lights.', Roles::description('decorate'));
        });
    }

    public function testNothingIsAskedOutsideTheBackgroundOrWithTranslationOff(): void
    {
        $this->cron = false;
        self::assertSame([], $this->translations()->run());

        $this->cron = true;
        $this->options[TranslatedText::AUTO_OPTION] = '0';
        self::assertSame([], $this->translations()->run());

        self::assertSame([], $this->sent);
    }

    public function testAFailedTranslationLeavesTheOriginalForReaders(): void
    {
        Functions\when('wp_remote_post')->justReturn(['answer' => 'not json', 'status' => 200]);

        self::assertSame([], $this->translations()->run());

        Language::run('fi', static function (): void {
            self::assertSame('Decorate', Roles::label('decorate'));
            self::assertSame('Hang the lights.', Roles::description('decorate'));
        });
    }

    /** Q333: a role text whose translation is a queued background job is left to that job. */
    public function testATextWithAQueuedTranslationIsLeftToItsJob(): void
    {
        \EventCrew\Support\BackgroundJobs::enqueue(
            TranslatedText::JOB,
            TranslatedText::jobTarget('role.decorate.label'),
            ['row' => 'role.decorate.label', 'source' => 'Decorate', 'texts' => []]
        );

        $done = $this->translations()->run();

        self::assertSame(['Hang the lights.'], array_column($this->sent[0], 'text'));
        self::assertSame(['role.decorate.description'], $done);
    }

    public function testSavingTheRolesAsksForARunSoon(): void
    {
        $this->translations()->soon();

        self::assertSame([RoleTranslations::HOOK], $this->scheduled);
    }
}

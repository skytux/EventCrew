<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\EventAiText;
use EventCrew\Support\Logger;
use EventCrew\Support\MessagePreview;
use EventCrew\Support\MessageTemplates;
use EventCrew\Tests\Telegram\FakeEvents;
use EventCrew\Tests\TestCase;

/**
 * The data of the Messages page's placeholder picker: each kind's
 * placeholders with their values for the preview's sample person and task.
 */
final class MessagePreviewPlaceholderSamplesTest extends TestCase
{
    use FakeEvents;

    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeEvents();
        $this->options = [];
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('admin_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-admin/' . $path
        );
        Functions\when('rest_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-json/' . $path
        );
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('network_home_url')->justReturn('https://site.test/');
        Functions\when('wp_parse_url')->alias(
            static fn (string $url, int $component = -1): mixed => parse_url($url, $component)
        );
        Functions\when('get_the_title')->justReturn('');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('wp_mail')->alias(static function (): bool {
            throw new \LogicException('The picker data sent an email.');
        });
        Functions\expect('update_option')->never();
    }

    public function testEveryKindListsEachOfItsPlaceholdersOnceWithASample(): void
    {
        $samples = $this->preview()->placeholderSamples();

        foreach (MessageTemplates::all() as $id => $template) {
            self::assertArrayHasKey($id, $samples);
            $tags = array_column($samples[$id], 'tag');
            $expected = array_map(
                static fn (string $name): string => '{' . $name . '}',
                array_keys($template->placeholders)
            );

            self::assertSame($expected, $tags, $id);

            foreach ($samples[$id] as $item) {
                self::assertIsString($item['sample'], $id . ' ' . $item['tag']);
                self::assertNotSame('', $item['description'], $id . ' ' . $item['tag']);
                self::assertLessThanOrEqual(MessagePreview::SAMPLE_LENGTH, mb_strlen($item['sample']));
            }
        }
    }

    public function testTheSamplesAreThoseOfTheSamplePersonAndTask(): void
    {
        $this->event(12, '2026-07-25', 'Dance night');
        $this->wpdb->nextResults[] = [[
            'id' => 40,
            'task_date' => '2026-07-25',
            'role_slug' => 'door',
            'capacity' => 2,
            'event_post_id' => 12,
            'starts_at' => '2026-07-25 18:00:00',
            'ends_at' => '2026-07-25 20:00:00',
        ]];

        $samples = $this->byTag($this->preview()->placeholderSamples()['reminder']);

        self::assertSame('Sample', $samples['{name}']['sample']);
        self::assertNotSame('', $samples['{event}']['sample']);
        self::assertStringNotContainsString('{', $samples['{when}']['sample']);
        self::assertNotSame('', $samples['{role}']['sample']);
    }

    public function testEveryKindAboutATaskOffersTheTaskDescriptionAndTheEventTimes(): void
    {
        foreach (MessageTemplates::all() as $id => $template) {
            if (! isset($template->placeholders['role'], $template->placeholders['event'])) {
                continue;
            }

            self::assertArrayHasKey('description', $template->placeholders, $id);
            self::assertArrayHasKey('event_times', $template->placeholders, $id);
        }

        foreach (['takeover_ask_dm', 'task_cancelled', 'cancel_confirmed'] as $id) {
            self::assertArrayHasKey('description', MessageTemplates::find($id)->placeholders, $id);
        }
    }

    public function testTheOrganizersOwnAiPlaceholdersAreOfferedInTheTextOfTheAiKindsOnly(): void
    {
        $this->options[EventAiText::PLACEHOLDERS_OPTION] = [
            ['name' => 'vibe', 'prompt' => 'Describe the night.', 'fallback' => 'A warm night.'],
        ];

        $samples = $this->preview()->placeholderSamples();
        $reminder = $this->byTag($samples['reminder']);

        self::assertSame('A warm night.', $reminder['{vibe}']['sample']);
        self::assertTrue($reminder['{vibe}']['body_only']);
        self::assertFalse($reminder['{name}']['body_only']);
        self::assertArrayNotHasKey('{vibe}', $this->byTag($samples['sign_in_link']));
    }

    public function testALongSampleIsCutToOneShortLine(): void
    {
        $short = MessagePreview::shortSample("Line one\n\n" . str_repeat('word ', 100));

        self::assertStringNotContainsString("\n", $short);
        self::assertLessThanOrEqual(MessagePreview::SAMPLE_LENGTH, mb_strlen($short));
        self::assertGreaterThan(MessagePreview::SAMPLE_LENGTH - 5, mb_strlen($short));
        self::assertStringEndsWith('…', $short);
        self::assertSame('as is', MessagePreview::shortSample('  as   is '));
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, array<string, mixed>>
     */
    private function byTag(array $items): array
    {
        return array_column($items, null, 'tag');
    }

    private function preview(): MessagePreview
    {
        return new MessagePreview(new Logger(), new TaskRepository());
    }
}

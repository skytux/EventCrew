<?php

declare(strict_types=1);

namespace EventCrew\Tests\Admin;

use Brain\Monkey\Functions;
use EventCrew\Admin\AiPromptsPage;
use EventCrew\Admin\MessagesPage;
use EventCrew\Admin\View;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\EventAiText;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\MessagePreview;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\TranslatedText;
use EventCrew\Tests\Telegram\FakeEvents;
use EventCrew\Tests\TestCase;

/**
 * The preview links of the Messages page: the nonce-checked address, the
 * organizer-only handler, and the page data and view for the list and for
 * one kind.
 */
final class MessagesPagePreviewTest extends TestCase
{
    use FakeEvents;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('admin_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-admin/' . $path
        );
        Functions\when('rest_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-json/' . $path
        );
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_nonce_url')->alias(
            static fn (string $url, string $action): string => $url . '&_wpnonce=nonce-' . $action
        );
        Functions\when('wp_create_nonce')->alias(static fn (string $action): string => 'nonce-' . $action);
        Functions\when('wp_nonce_field')->alias(static function (string $action): void {
            echo '<input type="hidden" name="_wpnonce" value="nonce-' . esc_attr($action) . '">';
        });
        Functions\when('selected')->alias(static function (mixed $selected, mixed $current = true): string {
            if ((string) $selected !== (string) $current) {
                return '';
            }

            echo " selected='selected'";

            return " selected='selected'";
        });
        Functions\when('get_permalink')->justReturn('https://site.test/crew/');
        Functions\when('network_home_url')->justReturn('https://site.test/');
        Functions\when('wp_parse_url')->alias(
            static fn (string $url, int $component = -1): mixed => parse_url($url, $component)
        );
        Functions\when('get_the_title')->justReturn('');
        Functions\when('get_transient')->justReturn(false);
        Functions\when('esc_html_e')->alias(static function (string $text): void {
            echo esc_html($text);
        });
        Functions\when('esc_attr_e')->alias(static function (string $text): void {
            echo esc_attr($text);
        });
        Functions\when('wp_mail')->alias(static function (): bool {
            throw new \LogicException('A preview sent an email.');
        });
    }

    protected function tearDown(): void
    {
        $_GET = [];

        parent::tearDown();
    }

    public function testThePreviewAddressCarriesTheKindTheLanguageAndANonce(): void
    {
        $url = MessagesPage::previewUrl('reminder', 'fi');

        self::assertStringStartsWith('https://site.test/wp-admin/admin-post.php?', $url);
        self::assertStringContainsString('action=' . MessagesPage::PREVIEW_ACTION, $url);
        self::assertStringContainsString('kind=reminder', $url);
        self::assertStringContainsString('lang=fi', $url);
        self::assertStringContainsString('_wpnonce=nonce-' . MessagesPage::PREVIEW_ACTION, $url);
        self::assertStringNotContainsString('kind=', MessagesPage::previewUrl());
    }

    public function testOnlyAnOrganizerGetsThePreview(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\expect('check_admin_referer')->never();
        Functions\when('wp_die')->alias(static function (): void {
            throw new \RuntimeException('died');
        });

        $this->expectExceptionMessage('died');
        $this->page()->preview();
    }

    public function testThePreviewChecksItsNonce(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\expect('check_admin_referer')->once()->with(MessagesPage::PREVIEW_ACTION)->andReturnUsing(
            static function (): void {
                throw new \RuntimeException('nonce checked');
            }
        );

        $this->expectExceptionMessage('nonce checked');
        $this->page()->preview();
    }

    public function testTheListShowsEveryKindWithItsLink(): void
    {
        $data = $this->page()->previewData();

        self::assertNull($data['message']);
        self::assertSame(array_keys(MessageTemplates::all()), array_column($data['entries'], 'id'));

        $html = $this->view($data);

        foreach ($data['entries'] as $entry) {
            self::assertStringContainsString('kind=' . $entry['id'] . '&', $html);
        }
    }

    public function testOneKindIsShownInTheLanguageAsked(): void
    {
        $_GET = ['kind' => 'sign_in_link', 'lang' => 'xx'];
        $data = $this->page()->previewData();

        self::assertSame('', $data['lang'], 'not a site language');
        self::assertSame('sign_in_link', $data['kind']);
        self::assertNotNull($data['message']);
        self::assertSame('', $data['message']['error']);

        $html = $this->view($data);

        self::assertStringContainsString('<iframe', $html);
        self::assertStringContainsString('sandbox=', $html);
        self::assertStringContainsString(MessagePreview::SAMPLE_EMAIL, $html);
    }

    public function testATelegramKindShowsItsTextAndButtons(): void
    {
        $_GET = ['kind' => 'takeover_ask_dm'];
        $data = $this->page()->previewData();
        $html = $this->view($data);

        self::assertNotNull($data['message']);
        self::assertNotSame([], $data['message']['buttons']);
        self::assertStringContainsString('class="ec-bubble"', $html);
        self::assertStringContainsString(esc_html($data['message']['buttons'][0]), $html);
    }

    public function testAnUnknownKindShowsTheList(): void
    {
        $_GET = ['kind' => 'no_such_kind'];
        $data = $this->page()->previewData();

        self::assertNull($data['message']);
        self::assertSame('', $data['kind']);
    }

    public function testThePickersShowTheEventAndTaskPickedAndWriteItNowWhenTheAiTextIsMissing(): void
    {
        $this->fakeEvents();
        $this->event(12, '2026-07-25', 'Dance night');
        $this->event(13, '2026-08-01', 'Next night');
        $this->aiTickOn('reminder_dm');
        // find(41), then the event's tasks for the preview, then for the task picker.
        $this->wpdb->nextRows[] = $this->taskRow(41, 12, 'bar');
        $this->wpdb->nextResults[] = [$this->taskRow(40, 12, 'door'), $this->taskRow(41, 12, 'bar')];
        $this->wpdb->nextResults[] = [$this->taskRow(40, 12, 'door'), $this->taskRow(41, 12, 'bar')];
        $_GET = ['kind' => 'reminder_dm', 'event_id' => '12', 'task_id' => '41'];

        $data = $this->page()->previewData();

        self::assertSame(12, $data['message']['event_id']);
        self::assertSame(41, $data['message']['task_id']);
        self::assertSame([12, 13], array_column($data['events'], 'id'));
        self::assertSame([40, 41], array_map(static fn ($task): int => $task->id, $data['tasks']));
        self::assertSame(MessagePreview::AI_MISSING, $data['message']['ai']);

        $html = $this->view($data);

        self::assertStringContainsString('name="event_id"', $html);
        self::assertStringContainsString('name="task_id"', $html);
        self::assertMatchesRegularExpression('/value="41"\s+selected/', $html);
        self::assertStringContainsString('value="' . MessagesPage::PREVIEW_WRITE_ACTION . '"', $html);
        self::assertStringContainsString('Write it now', $html);
    }

    public function testAKindNotAboutATaskHasNoTaskPickerAndNoWriteItNow(): void
    {
        $this->fakeEvents();
        $this->event(12, '2026-07-25', 'Dance night');
        $_GET = ['kind' => 'sign_in_link', 'event_id' => '12'];

        $data = $this->page()->previewData();
        $html = $this->view($data);

        self::assertSame([], $data['tasks']);
        self::assertStringContainsString('name="event_id"', $html);
        self::assertStringNotContainsString('name="task_id"', $html);
        self::assertStringNotContainsString(MessagesPage::PREVIEW_WRITE_ACTION, $html);
    }

    public function testWriteItNowAsksTheAiForThatKindAndEventAndGoesBackToItsPreview(): void
    {
        $this->fakeEvents();
        $this->event(12, '2026-07-25', 'Dance night');
        $this->aiTickOn('reminder_dm');
        Functions\expect('wp_remote_post')->never();

        $url = $this->page(true)->applyPreviewWrite(
            ['kind' => 'reminder_dm', 'lang' => '', 'event_id' => '12', 'task_id' => '41']
        );

        self::assertStringContainsString('kind=reminder_dm', $url);
        self::assertStringContainsString('event_id=12', $url);
        self::assertStringContainsString('task_id=41', $url);
        // The AI is not set up in the tests: nothing is written.
        self::assertStringContainsString('written=failed', $url);
    }

    public function testWriteItNowChecksItsNonce(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\expect('check_admin_referer')->once()->with(MessagesPage::PREVIEW_WRITE_ACTION)->andReturnUsing(
            static function (): void {
                throw new \RuntimeException('nonce checked');
            }
        );

        $this->expectExceptionMessage('nonce checked');
        $this->page()->previewWrite();
    }

    public function testTheMessagesTabHasThePlaceholderPickerDataOfEachKind(): void
    {
        $data = $this->page()->data(MessagesPage::TAB_MESSAGES);

        self::assertSame(array_column($data['entries'], 'id'), array_keys($data['samples']));
        self::assertContains('{name}', array_column($data['samples']['reminder'], 'tag'));
    }

    private function aiTickOn(string $id): void
    {
        $options = [MessageTemplates::OPTION_PREFIX . $id => ['settings' => [
            EventAiText::SETTING_AI_EACH_EVENT => true,
            EventAiText::SETTING_AI_PROMPT => 'Write the reminder.',
        ]]];
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed => $options[$name] ?? $default
        );
    }

    /** @return array<string, mixed> */
    private function taskRow(int $id, int $eventId, string $role): array
    {
        return [
            'id' => $id,
            'task_date' => '2026-07-25',
            'role_slug' => $role,
            'capacity' => 2,
            'event_post_id' => $eventId,
            'starts_at' => '2026-07-25 18:00:00',
            'ends_at' => '2026-07-25 20:00:00',
        ];
    }

    private function page(bool $withAi = false): MessagesPage
    {
        $logger = new Logger();

        return new MessagesPage(
            new View(),
            new MessageTemplates(new TranslatedText(new LlmClient($logger), $logger)),
            $withAi ? new AiPromptsPage(new EventAiText(new LlmClient($logger), $logger)) : null,
            null,
            null,
            new MessagePreview($logger, new TaskRepository())
        );
    }

    /** @param array<string, mixed> $data */
    private function view(array $data): string
    {
        ob_start();

        try {
            (new View())->render('message-preview', $data);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }
}

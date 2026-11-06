<?php

declare(strict_types=1);

namespace EventCrew\Tests\Admin;

use Brain\Monkey\Functions;
use EventCrew\Admin\MessagesPage;
use EventCrew\Admin\View;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\MessagePreview;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\TranslatedText;
use EventCrew\Tests\TestCase;

/**
 * The preview links of the Messages page: the nonce-checked address, the
 * organizer-only handler, and the page data and view for the list and for
 * one kind.
 */
final class MessagesPagePreviewTest extends TestCase
{
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

    private function page(): MessagesPage
    {
        $logger = new Logger();

        return new MessagesPage(
            new View(),
            new MessageTemplates(new TranslatedText(new LlmClient($logger), $logger)),
            null,
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

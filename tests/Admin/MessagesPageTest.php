<?php

declare(strict_types=1);

namespace EventCrew\Tests\Admin;

use Brain\Monkey\Functions;
use EventCrew\Admin\MessagesPage;
use EventCrew\Admin\View;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\MessageTemplate;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\TranslatedText;
use EventCrew\Tests\TestCase;

/**
 * The Messages page: what it lists for each kind, the formatting line, and
 * its save and reset handlers. Options live in an array; the redirect at the
 * end of a handler is a RuntimeException that carries its query args.
 */
final class MessagesPageTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $redirectArgs = [];

    /** @var list<string> */
    private array $nonces = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->redirectArgs = [];
        $this->nonces = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);

            return true;
        });
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_admin_referer')->alias(function (string $action): int {
            $this->nonces[] = $action;

            return 1;
        });
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_textarea_field')->alias(static fn (string $value): string => trim($value));
        Functions\when('sanitize_text_field')->alias(static fn (string $value): string => trim($value));
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string => (string) json_encode($value));
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(3);
        Functions\when('admin_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-admin/' . $path
        );
        Functions\when('add_query_arg')->alias(function (array $args): string {
            $this->redirectArgs = $args;

            return 'https://site.test/wp-admin/admin.php';
        });
        Functions\when('wp_safe_redirect')->alias(static function (): void {
            throw new \RuntimeException('redirected');
        });
    }

    protected function tearDown(): void
    {
        $_POST = [];

        parent::tearDown();
    }

    private function page(): MessagesPage
    {
        return new MessagesPage(
            new View(),
            new MessageTemplates(new TranslatedText(new LlmClient(new Logger()), new Logger()))
        );
    }

    private function submit(callable $action): void
    {
        try {
            $action();
            self::fail('Expected the redirect.');
        } catch (\RuntimeException $e) {
            self::assertSame('redirected', $e->getMessage());
        }
    }

    public function testEntriesListEveryKindWithItsTextsAndPlaceholders(): void
    {
        $entries = MessagesPage::entries();

        self::assertSame(array_keys(MessageTemplates::all()), array_column($entries, 'id'));

        $byId = array_column($entries, null, 'id');
        $email = $byId['credit_granted'];
        self::assertSame(MessageTemplate::CHANNEL_EMAIL, $email['channel']);
        self::assertSame('You’ve been given free entry', $email['subject']['source']);
        self::assertStringStartsWith('Hi {name},', $email['body']['source']);
        self::assertArrayHasKey('{credits}', $email['placeholders']);
        self::assertFalse($email['changed']);
        self::assertTrue($email['wired']);
        self::assertSame([], $email['settings']);
        self::assertStringContainsString('**bold**', $email['formatting']);

        $dm = $byId['credit_granted_dm'];
        self::assertNull($dm['subject'], 'a Telegram message has no subject field');
        self::assertStringContainsString('plain text', $dm['formatting']);

        self::assertFalse($byId['newsletter']['wired']);
    }

    public function testTheFormattingLineNamesEachMark(): void
    {
        $line = MessagesPage::formatting(MessageTemplate::CHANNEL_EMAIL);

        foreach (['**bold**', '*italic*', '"- "', '"## "'] as $mark) {
            self::assertStringContainsString($mark, $line);
        }
    }

    public function testSaveStoresTheTextAndReturnsToTheSameKind(): void
    {
        $_POST = [
            'kind' => 'slot_freed_dm',
            MessageTemplates::POST_NAME => [
                MessageTemplates::BODY => [
                    'source' => 'Free: {role} at {event}',
                    'texts' => ['en' => 'Free: {role} at {event}', 'fi' => 'Vapaa: {role}, {event}'],
                ],
            ],
        ];

        $page = $this->page();
        $this->submit(static fn () => $page->save());

        self::assertSame([MessagesPage::SAVE_ACTION], $this->nonces);
        self::assertSame(['page' => MessagesPage::PAGE_SLUG, 'kind' => 'slot_freed_dm'], $this->redirectArgs);
        self::assertSame(
            'Free: {role} at {event}',
            MessageTemplates::stored('slot_freed_dm')[MessageTemplates::BODY]['source']
        );
        self::assertTrue(MessageTemplates::isChanged('slot_freed_dm'));
    }

    public function testSaveRefusesAnUnknownKind(): void
    {
        $_POST = ['kind' => 'nope', MessageTemplates::POST_NAME => [MessageTemplates::BODY => ['source' => 'x']]];

        $page = $this->page();
        $this->submit(static fn () => $page->save());

        self::assertSame(['page' => MessagesPage::PAGE_SLUG], $this->redirectArgs);
        self::assertSame([], $this->options);
    }

    public function testAStoredTextWithoutARequiredPlaceholderIsRefusedWithANotice(): void
    {
        $notices = [];
        Functions\when('set_transient')->alias(static function (string $key, mixed $value) use (&$notices): bool {
            $notices[] = $value;

            return true;
        });
        $_POST = [
            'kind' => 'email_changed',
            MessageTemplates::POST_NAME => [
                MessageTemplates::SUBJECT => ['source' => 'Moved', 'texts' => ['en' => 'Moved']],
                MessageTemplates::BODY => [
                    'source' => 'Your address changed.',
                    'texts' => ['en' => 'Your address changed.'],
                ],
            ],
        ];

        $page = $this->page();
        $this->submit(static fn () => $page->save());

        self::assertSame(['page' => MessagesPage::PAGE_SLUG, 'kind' => 'email_changed'], $this->redirectArgs);
        self::assertSame(
            [[
                'type' => 'error',
                'message' => 'Not saved: the text must contain {email}, in every language. The message keeps the text it had.',
            ]],
            $notices
        );
        self::assertSame([], $this->options, 'nothing is stored, not even the subject');
        self::assertFalse(MessageTemplates::isChanged('email_changed'));
    }

    public function testATranslationWithoutARequiredPlaceholderIsRefusedToo(): void
    {
        $_POST = [
            'kind' => 'email_changed',
            MessageTemplates::POST_NAME => [
                MessageTemplates::BODY => [
                    'source' => 'Now {email}.',
                    'texts' => ['en' => 'Now {email}.', 'fi' => 'Osoite vaihtui.'],
                ],
            ],
        ];

        $page = $this->page();
        $this->submit(static fn () => $page->save());

        self::assertSame([], $this->options);
    }

    public function testATextWithTheRequiredPlaceholderIsSaved(): void
    {
        $_POST = [
            'kind' => 'email_changed',
            MessageTemplates::POST_NAME => [
                MessageTemplates::BODY => [
                    'source' => 'Now {email}.',
                    'texts' => ['en' => 'Now {email}.', 'fi' => 'Nyt {email}.'],
                ],
            ],
        ];

        $page = $this->page();
        $this->submit(static fn () => $page->save());

        self::assertSame('Now {email}.', MessageTemplates::stored('email_changed')[MessageTemplates::BODY]['source']);
    }

    public function testEntriesNameTheRequiredPlaceholders(): void
    {
        $byId = array_column(MessagesPage::entries(), null, 'id');

        self::assertSame(['{email}'], $byId['email_changed']['required']);
        self::assertSame([], $byId['credit_granted']['required']);
    }

    public function testResetForgetsTheText(): void
    {
        $this->options[MessageTemplates::OPTION_PREFIX . 'reminder'] = [
            MessageTemplates::SUBJECT => ['source' => 'Tomorrow!', 'texts' => ['en' => 'Tomorrow!'], 'manual' => []],
        ];
        $_POST = ['kind' => 'reminder'];

        $page = $this->page();
        $this->submit(static fn () => $page->reset());

        self::assertSame([MessagesPage::RESET_ACTION], $this->nonces);
        self::assertSame(['page' => MessagesPage::PAGE_SLUG, 'kind' => 'reminder'], $this->redirectArgs);
        self::assertFalse(MessageTemplates::isChanged('reminder'));
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Tests\Admin;

use Brain\Monkey\Functions;
use EventCrew\Admin\JobsStatus;
use EventCrew\Admin\MessagesPage;
use EventCrew\Admin\View;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\BackgroundJobs;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\MessageTemplate;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\TicketReasons;
use EventCrew\Support\TicketText;
use EventCrew\Support\TranslatedText;
use EventCrew\Support\TranslationRegistry;
use EventCrew\Tests\Support\JsonAnswer;
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
        self::assertSame(['page' => MessagesPage::PAGE_SLUG, 'tab' => 'messages', 'kind' => 'slot_freed_dm'], $this->redirectArgs);
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

        self::assertSame(['page' => MessagesPage::PAGE_SLUG, 'tab' => 'messages', 'kind' => 'email_changed'], $this->redirectArgs);
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
        self::assertSame(['page' => MessagesPage::PAGE_SLUG, 'tab' => 'messages', 'kind' => 'reminder'], $this->redirectArgs);
        self::assertFalse(MessageTemplates::isChanged('reminder'));
    }

    /** @var list<string> The bodies sent to the language model. */
    private array $requests = [];

    /**
     * A language model that answers $answer to every request (a stub of
     * wp_remote_post; nothing leaves the test), and the poll's JSON answer
     * thrown as a JsonAnswer.
     */
    private function withModel(string $answer): void
    {
        $this->requests = [];
        $this->options[AssistantSettings::URL_OPTION] = 'https://llm.example.test/v1';
        $this->options[AssistantSettings::MODEL_OPTION] = 'test-model';
        Functions\when('wp_remote_post')->alias(function (string $url, array $args) use ($answer): array {
            $this->requests[] = (string) $args['body'];

            return ['answer' => $answer];
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(
            static fn (array $response): string => (string) json_encode(
                ['choices' => [['message' => ['content' => $response['answer']]]]]
            )
        );
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('wp_send_json_success')->alias(static function (mixed $data): never {
            throw new JsonAnswer($data);
        });
    }

    /** The page as wired on the site: its saves queue the translation. */
    private function backgroundPage(): MessagesPage
    {
        return new MessagesPage(
            new View(),
            new MessageTemplates(new TranslatedText(new LlmClient(new Logger()), new Logger(), true))
        );
    }

    /** The admin-ajax poll, with the runner of the translation jobs. */
    private function poll(): JobsStatus
    {
        $registry = new TranslationRegistry(new TranslatedText(new LlmClient(new Logger()), new Logger()));
        $registry->add([MessageTemplates::class, 'translationRows']);
        $jobs = new BackgroundJobs();
        $jobs->handle(TranslatedText::JOB, [$registry, 'runJob']);

        return new JobsStatus($jobs, $registry);
    }

    /** "Translate again" on the Telegram message $kind, posted by the page's JavaScript. */
    private function pressTranslateAgain(string $kind, string $source): array
    {
        $_POST = [
            'action' => MessagesPage::SAVE_ACTION,
            'kind' => $kind,
            MessageTemplates::POST_NAME => [
                MessageTemplates::BODY => [
                    'source' => $source,
                    'texts' => ['fi' => '', 'en' => $source],
                    'again' => '1',
                ],
            ],
        ];

        try {
            $this->backgroundPage()->saveAjax();
            self::fail('The save answers JSON.');
        } catch (JsonAnswer $answer) {
            return $answer->data;
        }
    }

    /**
     * Lou's report: translating one message and then another translated
     * both again. The first run was cut off when the page was left (the
     * poll running it was dropped), stayed "running" and was run once more
     * later. The poll now runs its job to the end, and the second press
     * queues and translates only the second message.
     */
    public function testTranslatingOneMessageThenAnotherTranslatesOnlyTheSecond(): void
    {
        $this->withModel('{"lang": "en", "fi": "Käännetty", "en": "Translated"}');
        $first = $this->pressTranslateAgain('slot_freed_dm', 'First {role} at {event}');
        $status = $this->poll();

        ignore_user_abort(false);
        $answer = $status->answer(['ids' => implode(',', array_map(
            static fn (string $html): string => (string) preg_replace('/.*data-ec-job="([^"]+)".*/s', '$1', $html),
            $first['jobs']
        ))]);

        self::assertSame(1, ignore_user_abort(), 'the run goes on when the page is left');
        self::assertCount(1, $this->requests);
        self::assertSame(0, $answer['active']);

        $done = array_values($answer['jobs'])[0];
        self::assertSame('message.slot_freed_dm.body', $done['row'], 'the poll names the field');
        self::assertSame('Käännetty', $done['value']['texts']['fi'], 'and gives its new text');
        self::assertSame(
            TranslatedText::fillValue(MessageTemplates::value('slot_freed_dm', MessageTemplates::BODY)),
            $done['value'],
            'every language, the marks and the language menu, as stored'
        );
        self::assertStringNotContainsString('data-ec-job', $done['done'], 'a "Done" in place is not polled again');
        self::assertStringNotContainsString('ec-job-reload', $done['done']);

        $second = $this->pressTranslateAgain('credit_granted_dm', 'Second {credits}');

        self::assertSame(['message.credit_granted_dm.body'], array_keys($second['jobs']));
        self::assertSame(1, BackgroundJobs::activeCount(), 'only the second message waits');

        $status->answer(['ids' => '']);
        $status->answer(['ids' => '']);

        self::assertCount(2, $this->requests, 'one model request per press');
        self::assertStringContainsString('Second', $this->requests[1]);
        self::assertStringNotContainsString('First', $this->requests[1]);
    }

    /** The second press while the first still waits: each message is translated once. */
    public function testASecondPressWhileTheFirstWaitsTranslatesEachOnce(): void
    {
        $this->withModel('{"lang": "en", "fi": "Käännetty", "en": "Translated"}');
        $this->pressTranslateAgain('slot_freed_dm', 'First {role} at {event}');
        $this->pressTranslateAgain('credit_granted_dm', 'Second {credits}');
        $status = $this->poll();

        self::assertSame(2, BackgroundJobs::activeCount());

        for ($i = 0; $i < 4; $i++) {
            $status->answer(['ids' => '']);
        }

        self::assertSame(0, BackgroundJobs::activeCount());
        self::assertCount(2, $this->requests);
        self::assertStringContainsString('First', $this->requests[0]);
        self::assertStringContainsString('Second', $this->requests[1]);
    }

    /** The press answers at once: it only queues; the model is asked later, by the poll. */
    public function testThePressOnlyQueuesTheTranslation(): void
    {
        $this->withModel('{"lang": "en", "fi": "Käännetty", "en": "Translated"}');

        $answer = $this->pressTranslateAgain('slot_freed_dm', 'Free {role} at {event}');

        self::assertSame([], $this->requests, 'no model request inside the press');
        self::assertSame('success', $answer['type']);
        self::assertSame(['message.slot_freed_dm.body'], array_keys($answer['jobs']));
        self::assertStringContainsString('Translating…', $answer['jobs']['message.slot_freed_dm.body']);
        self::assertSame(
            BackgroundJobs::QUEUED,
            BackgroundJobs::latest(TranslatedText::jobTarget('message.slot_freed_dm.body'))['status'] ?? null
        );
        self::assertSame([], $answer['texts'], 'a Telegram message has no subject to send back');
    }

    /**
     * A save with nothing to translate (one language changed by hand)
     * answers the stored text: every language, the hand-edited mark and the
     * language menu, so the fields show exactly what is stored.
     */
    public function testASaveWithNothingToTranslateAnswersTheStoredText(): void
    {
        $this->withModel('{}');
        $this->options[MessageTemplates::OPTION_PREFIX . 'slot_freed_dm'] = [
            MessageTemplates::BODY => [
                'source' => 'Free {role} at {event}',
                'lang' => 'en',
                'detected' => true,
                'texts' => ['en' => 'Free {role} at {event}', 'fi' => 'Vapaa {role}, {event}'],
            ],
        ];
        $_POST = [
            'kind' => 'slot_freed_dm',
            MessageTemplates::POST_NAME => [
                MessageTemplates::BODY => [
                    'source' => 'Free {role} at {event}',
                    'lang' => '',
                    'texts' => ['en' => 'Free {role} at {event}', 'fi' => 'Vapaana {role}, {event}'],
                ],
            ],
        ];

        try {
            $this->backgroundPage()->saveAjax();
            self::fail('The save answers JSON.');
        } catch (JsonAnswer $answer) {
            $fill = $answer->data['texts']['message.slot_freed_dm.body'] ?? null;

            self::assertSame([], $answer->data['jobs']);
            self::assertSame([], $this->requests);
            self::assertSame(
                TranslatedText::fillValue(MessageTemplates::value('slot_freed_dm', MessageTemplates::BODY)),
                $fill
            );
            self::assertSame('Vapaana {role}, {event}', $fill['texts']['fi']);
            self::assertTrue($fill['manual']['fi']);
            self::assertSame('', $fill['by_hand']);
        }
    }

    /**
     * The ticket email's text and {reason} lines moved from Settings > Email
     * to the Ticket entry here: its save queues the translation of what
     * changed and answers the other lines as stored.
     */
    public function testTheTicketEntrySavesTheTicketEmailText(): void
    {
        $this->withModel('{}');
        $_POST = [
            'action' => MessagesPage::TICKET_ACTION,
            TicketText::POST_NAME => [
                'source' => 'Hi {first_name}, see you at {event}.',
                'lang' => '',
                'texts' => ['fi' => '', 'en' => ''],
            ],
        ];

        try {
            $this->backgroundPage()->saveTicketAjax();
            self::fail('The save answers JSON.');
        } catch (JsonAnswer $answer) {
            self::assertSame('success', $answer->data['type']);
            self::assertSame([], $this->requests, 'no model request inside the save');
            self::assertSame(['email.ticket'], array_keys($answer->data['jobs']));
            self::assertSame(
                BackgroundJobs::QUEUED,
                BackgroundJobs::latest(TranslatedText::jobTarget('email.ticket'))['status'] ?? null
            );

            foreach (TicketReasons::KINDS as $kind) {
                self::assertSame(
                    TranslatedText::fillValue(TicketReasons::settings($kind)),
                    $answer->data['texts']['email.ticket_reason.' . $kind]
                );
            }
        }

        self::assertSame(
            TicketText::settings(),
            $this->backgroundPage()->data(MessagesPage::TAB_MESSAGES)['ticket']['text']
        );
    }

    /** A refused text sends no stored text back, so the form keeps what was typed. */
    public function testARefusedPressKeepsTheTypedText(): void
    {
        $this->withModel('{}');
        $_POST = [
            'kind' => 'email_changed',
            MessageTemplates::POST_NAME => [
                MessageTemplates::BODY => ['source' => 'No placeholder', 'texts' => ['en' => 'No placeholder']],
            ],
        ];

        try {
            $this->backgroundPage()->saveAjax();
            self::fail('The save answers JSON.');
        } catch (JsonAnswer $answer) {
            self::assertSame('error', $answer->data['type']);
            self::assertSame([], $answer->data['texts']);
            self::assertSame([], $answer->data['jobs']);
        }
    }

    /** The press needs the capability. */
    public function testThePressNeedsTheCapability(): void
    {
        $this->withModel('{}');
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('wp_send_json_error')->alias(static function (mixed $data, int $code): never {
            throw new \RuntimeException('error ' . $code);
        });
        $_POST = ['kind' => 'slot_freed_dm'];

        try {
            $this->backgroundPage()->saveAjax();
            self::fail('Refused.');
        } catch (\RuntimeException $e) {
            self::assertSame('error 403', $e->getMessage());
        }

        self::assertSame(0, BackgroundJobs::activeCount());
    }
}

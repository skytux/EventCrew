<?php

declare(strict_types=1);

namespace EventCrew\Tests\Admin;

use Brain\Monkey\Functions;
use EventCrew\Admin\AiPromptsPage;
use EventCrew\Admin\MessagesPage;
use EventCrew\Admin\SettingsPage;
use EventCrew\Admin\View;
use EventCrew\Support\AiPrompts;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FollowupText;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\Newsletter;
use EventCrew\Support\NewsletterText;
use EventCrew\Support\TicketReasons;
use EventCrew\Support\TranslatedText;
use EventCrew\Tests\TestCase;
use EventCrew\Web\CrewAdminFollowup;
use EventCrew\Web\CrewAdminNewsletter;

/**
 * The Messages page's tabs: Messages, AI (the old AI prompts page), Coming
 * events and Email look. Each handler returns to its own tab (and, on the
 * Coming events tab, to the card it came from); the old AI prompts address
 * redirects to the tabs. Options live in an array; a redirect is a
 * RuntimeException, and the address it went to is kept in $redirectUrl.
 */
final class MessagesTabsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    private string $redirectUrl = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->redirectUrl = '';

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
        Functions\when('check_admin_referer')->justReturn(1);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_textarea_field')->alias(static fn (string $value): string => trim($value));
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string => (string) json_encode($value));
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(3);
        Functions\when('admin_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-admin/' . $path
        );
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_safe_redirect')->alias(function (string $url): void {
            $this->redirectUrl = $url;

            throw new \RuntimeException('redirected');
        });
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];

        parent::tearDown();
    }

    private function submit(callable $action): string
    {
        try {
            $action();
            self::fail('Expected the redirect.');
        } catch (\RuntimeException $e) {
            self::assertSame('redirected', $e->getMessage());
        }

        return $this->redirectUrl;
    }

    private function followup(): CrewAdminFollowup
    {
        return new CrewAdminFollowup(new FollowupText(new LlmClient(new Logger()), new Logger()));
    }

    private function newsletter(): CrewAdminNewsletter
    {
        // Saving the text does not touch the newsletter itself.
        $newsletter = (new \ReflectionClass(Newsletter::class))->newInstanceWithoutConstructor();

        return new CrewAdminNewsletter($newsletter, new NewsletterText(new LlmClient(new Logger()), new Logger()));
    }

    private function page(?AiPromptsPage $ai = null): MessagesPage
    {
        return new MessagesPage(
            new View(),
            new MessageTemplates(new TranslatedText(new LlmClient(new Logger()), new Logger())),
            $ai ?? new AiPromptsPage()
        );
    }

    public function testTheTabsAndTheAddressOfEach(): void
    {
        self::assertSame(['messages', 'ai', 'events', 'email'], array_keys(MessagesPage::tabs()));
        self::assertSame(
            'https://site.test/wp-admin/admin.php?page=eventcrew-messages&tab=events#ec-event-12',
            MessagesPage::url(MessagesPage::TAB_EVENTS, [], AiPromptsPage::eventAnchor(12))
        );

        $_GET = ['tab' => 'nope'];
        self::assertSame(MessagesPage::TAB_MESSAGES, MessagesPage::currentTab());
        $_GET = ['tab' => 'ai'];
        self::assertSame(MessagesPage::TAB_AI, MessagesPage::currentTab());
    }

    public function testEachTabGetsItsOwnData(): void
    {
        $page = $this->page();

        self::assertArrayHasKey('entries', $page->data(MessagesPage::TAB_MESSAGES));
        self::assertCount(count(AiPrompts::DEFAULTS), $page->data(MessagesPage::TAB_AI)['prompts']);

        $email = $page->data(MessagesPage::TAB_EMAIL);
        self::assertFalse($email['html_on'] && '' !== $email['edit_url'], 'No template object: no edit link.');
        self::assertSame(SettingsPage::EMAIL_NONCE_ACTION, $email['nonce_action']);
    }

    public function testTheComingEventsTabHasACardPerComingEvent(): void
    {
        $data = (new AiPromptsPage())->eventsData([
            ['id' => 5, 'title' => 'Past', 'date' => '2026-07-19'],
            ['id' => 6, 'title' => 'Tomorrow', 'date' => '2026-07-21'],
            ['id' => 7, 'title' => 'Today', 'date' => '2026-07-20'],
        ]);

        self::assertSame([7, 6], array_column($data['events'], 'event_id'), 'Coming only, by date.');
        self::assertSame([], $data['events'][0]['items']);
        self::assertFalse($data['can_write'], 'No template has the AI tick on and there are no placeholders.');
        self::assertNull($data['followup']);
    }

    public function testTheTabsRenderWithTheirForms(): void
    {
        $html = $this->rendered(
            ['tabs' => MessagesPage::tabs(), 'tab' => MessagesPage::TAB_AI] + (new AiPromptsPage())->promptsData()
        );

        self::assertStringContainsString('nav-tab nav-tab-active', $html);
        self::assertStringContainsString('tab=events', $html);
        self::assertStringContainsString('value="' . AiPromptsPage::PROMPTS_ACTION . '"', $html);
        self::assertStringContainsString('value="' . AiPromptsPage::PLACEHOLDERS_ACTION . '"', $html);
        self::assertStringNotContainsString('AI prompts</h1>', $html);

        $html = $this->rendered([
            'tabs' => MessagesPage::tabs(),
            'tab' => MessagesPage::TAB_EVENTS,
            'can_write' => true,
            'events' => [[
                'event_id' => 6,
                'date' => '2026-07-21',
                'title' => 'Party',
                'items' => [[
                    'key' => 'reminder',
                    'label' => 'Crew reminder',
                    'ai' => ['fi' => 'Huomenna.', 'en' => 'Tomorrow.'],
                    'texts' => ['fi' => 'Oma.', 'en' => 'Tomorrow.'],
                    'edited' => ['fi' => true, 'en' => false],
                    'failed' => false,
                ]],
            ]],
            'followup' => ['enabled' => true, 'night' => '21.7.2026 · Party'] + $this->followup()->view(),
            'newsletter' => [
                'enabled' => true, 'days' => 7, 'subscribers' => 0, 'plan' => null, 'last' => null, 'text' => null,
            ],
        ]);

        self::assertStringContainsString('id="ec-event-6"', $html);
        self::assertStringContainsString('Write them again', $html);
        self::assertStringContainsString('This replaces your edit.', $html, 'An edited text asks first.');
        self::assertStringContainsString('action=' . AiPromptsPage::REWRITE_ACTION . '&event_id=6&key=reminder', $html);
        self::assertStringContainsString('value="' . AiPromptsPage::FOLLOWUP_TEXT_ACTION . '"', $html);
        self::assertStringContainsString('value="' . AiPromptsPage::NEWSLETTER_TEXT_ACTION . '"', $html);
        self::assertStringContainsString('The next post-event email is for: 21.7.2026 · Party', $html);
        self::assertStringContainsString('No announcement is planned yet.', $html);

        $html = $this->rendered([
            'tabs' => MessagesPage::tabs(),
            'tab' => MessagesPage::TAB_EMAIL,
            'html_on' => true,
            'edit_url' => 'https://site.test/wp-admin/post.php?post=4&action=edit',
            'nonce_action' => SettingsPage::EMAIL_NONCE_ACTION,
        ]);

        self::assertStringContainsString('post.php?post=4', $html);
        self::assertStringContainsString('name="back" value="messages"', $html);

        $html = $this->rendered([
            'tabs' => MessagesPage::tabs(),
            'tab' => MessagesPage::TAB_EMAIL,
            'html_on' => false,
            'edit_url' => 'https://site.test/wp-admin/post.php?post=4&action=edit',
            'nonce_action' => SettingsPage::EMAIL_NONCE_ACTION,
        ]);

        self::assertStringNotContainsString('post.php?post=4', $html, 'Plain-text email: no editor link.');
    }

    /** The ticket email's text and {reason} lines are edited in the Ticket entry, with their own save. */
    public function testTheTicketEntryHoldsTheTicketEmailText(): void
    {
        Functions\when('selected')->alias(
            static fn (mixed $one, mixed $two = true): string => (string) $one === (string) $two ? ' selected' : ''
        );
        $page = new MessagesPage(
            new View(),
            new MessageTemplates(new TranslatedText(new LlmClient(new Logger()), new Logger()))
        );
        $html = $this->rendered($page->data(MessagesPage::TAB_MESSAGES));

        self::assertStringContainsString('value="' . MessagesPage::TICKET_ACTION . '"', $html);
        self::assertStringContainsString('data-ec-row="email.ticket"', $html);

        foreach (TicketReasons::KINDS as $kind) {
            self::assertStringContainsString('data-ec-row="email.ticket_reason.' . $kind . '"', $html);
        }

        // Q341: the unused message text of the Ticket entry is gone, with its warning; the others keep theirs.
        self::assertStringNotContainsString('data-ec-row="message.ticket.', $html);
        self::assertStringNotContainsString('name="kind" value="ticket"', $html);
        self::assertStringContainsString('data-ec-row="message.slot_freed_dm.body"', $html);
    }

    public function testTheAiFormsReturnToTheAiTab(): void
    {
        $_POST = ['prompts' => [AiPrompts::BOARD_DJ => 'Board text.']];

        $url = $this->submit(static fn () => (new AiPromptsPage())->savePrompts());

        self::assertStringContainsString('page=eventcrew-messages&tab=ai', $url);

        $_POST = ['placeholders' => [['name' => 'dj_intro', 'prompt' => 'Introduce the DJ.', 'fallback' => '']]];

        $url = $this->submit(static fn () => (new AiPromptsPage())->savePlaceholders());

        self::assertStringContainsString('page=eventcrew-messages&tab=ai', $url);
    }

    public function testAnEventTextReturnsToItsCard(): void
    {
        $_POST = ['event_id' => '6', 'key' => 'reminder', 'texts' => ['fi' => 'X']];
        // No events plugin: the event is not found, and the form still returns to its card.
        Functions\when('post_type_exists')->justReturn(false);

        $url = $this->submit(static fn () => (new AiPromptsPage())->saveEventText());

        self::assertStringEndsWith('page=eventcrew-messages&tab=events#ec-event-6', $url);
    }

    public function testTheOneTimeTextsAreSavedTheCrewAppsWay(): void
    {
        $page = new AiPromptsPage(null, $this->followup(), $this->newsletter());

        // Translation is off in the tests: the text is kept as written.
        self::assertSame(
            'admin_followup_saved_untranslated',
            $page->applyFollowupText(['variant' => FollowupText::CAME, 'op' => 'save', 'followup_text' => 'Kiitos!'])
        );
        self::assertSame('Kiitos!', $this->options[FollowupText::NEXT_OPTIONS[FollowupText::CAME]]['fi'] ?? '');
        self::assertSame(0, $this->options[FollowupText::NEXT_OPTIONS[FollowupText::CAME]]['by']);
        self::assertSame(
            'admin_followup_unknown',
            $page->applyFollowupText(['variant' => 'other', 'followup_text' => 'X'])
        );
        self::assertSame(
            'admin_followup_cleared',
            $page->applyFollowupText(['variant' => FollowupText::CAME, 'op' => 'clear'])
        );
        self::assertArrayNotHasKey(FollowupText::NEXT_OPTIONS[FollowupText::CAME], $this->options);

        self::assertSame(
            'admin_newsletter_saved_untranslated',
            $page->applyNewsletterText(['op' => 'save', 'newsletter_text' => 'Tervetuloa.'])
        );
        self::assertSame('Tervetuloa.', $this->options[NewsletterText::OPTION]['fi'] ?? '');
        self::assertSame('admin_newsletter_cleared', $page->applyNewsletterText(['op' => 'clear']));
        self::assertArrayNotHasKey(NewsletterText::OPTION, $this->options);
    }

    public function testTheOneTimeTextFormsReturnToTheComingEventsTab(): void
    {
        $page = new AiPromptsPage(null, $this->followup(), $this->newsletter());

        $_POST = ['variant' => FollowupText::MISSED, 'op' => 'save', 'followup_text' => 'Harmi.'];
        $url = $this->submit(static fn () => $page->saveFollowupText());
        self::assertStringEndsWith('tab=events#' . AiPromptsPage::ONE_TIME_ANCHOR, $url);

        $_POST = ['op' => 'save', 'newsletter_text' => 'Tervetuloa.'];
        $url = $this->submit(static fn () => $page->saveNewsletterText());
        self::assertStringEndsWith('tab=events#' . AiPromptsPage::ONE_TIME_ANCHOR, $url);
    }

    public function testWriteAgainReturnsToItsCard(): void
    {
        // A link: the event and the text come in the address.
        $_GET = ['event_id' => '6', 'key' => 'reminder'];

        $url = $this->submit(static fn () => (new AiPromptsPage())->rewriteEventText());

        self::assertStringEndsWith('page=eventcrew-messages&tab=events#ec-event-6', $url);
    }

    public function testTheOldAiPromptsAddressRedirectsToTheTabs(): void
    {
        $_GET = ['page' => AiPromptsPage::PAGE_SLUG];
        $url = $this->submit(static fn () => AiPromptsPage::redirectOldPage());
        self::assertSame('https://site.test/wp-admin/admin.php?page=eventcrew-messages&tab=ai', $url);

        $_GET = ['page' => AiPromptsPage::PAGE_SLUG, 'event_id' => '12'];
        $url = $this->submit(static fn () => AiPromptsPage::redirectOldPage());
        self::assertSame('https://site.test/wp-admin/admin.php?page=eventcrew-messages&tab=events#ec-event-12', $url);
    }

    public function testTheEmailTemplateResetFromTheEmailLookTabReturnsThere(): void
    {
        $_POST = ['back' => 'messages'];
        $this->options[EmailTemplate::POST_OPTION] = 4;
        Functions\when('get_post_type')->justReturn(EmailTemplate::POST_TYPE);
        Functions\when('wp_update_post')->justReturn(4);
        Functions\when('is_wp_error')->justReturn(false);
        $template = new EmailTemplate(new Logger());
        $settings = (new \ReflectionClass(SettingsPage::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(SettingsPage::class, 'emailTemplate');
        $property->setValue($settings, $template);

        $url = $this->submit(static fn () => $settings->resetEmailTemplate());

        self::assertStringEndsWith('page=eventcrew-messages&tab=email', $url);
    }

    /** @param array<string, mixed> $data */
    private function rendered(array $data): string
    {
        Functions\when('esc_html_e')->alias(static function (string $text): void {
            echo esc_html($text);
        });
        Functions\when('esc_attr_e')->alias(static function (string $text): void {
            echo esc_attr($text);
        });
        Functions\when('wp_nonce_field')->alias(static function (string $action): void {
            echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(md5($action)) . '">';
        });
        Functions\when('wp_nonce_url')->alias(static fn (string $url): string => $url . '&_wpnonce=x');
        Functions\when('submit_button')->alias(static function (string $text): void {
            echo '<button type="submit" class="button button-primary">' . esc_html($text) . '</button>';
        });

        ob_start();

        try {
            (new View())->render('messages', $data);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }
}

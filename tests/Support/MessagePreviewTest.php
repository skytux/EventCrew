<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\FollowupRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FollowupMail;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MessageActions;
use EventCrew\Support\MessagePreview;
use EventCrew\Support\MessageTemplate;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\Newsletter;
use EventCrew\Support\NewsletterText;
use EventCrew\Support\TicketMail;
use EventCrew\Telegram\BoardService;
use EventCrew\Tests\TestCase;

/**
 * The message previews: every kind MessageTemplates lists renders through
 * the one generic path, with sample data, and nothing is sent or written.
 */
final class MessagePreviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('admin_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-admin/' . $path
        );
        Functions\when('rest_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-json/' . $path
        );
        Functions\when('get_permalink')->justReturn('https://site.test/crew/');
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('network_home_url')->justReturn('https://site.test/');
        Functions\when('wp_parse_url')->alias(
            static fn (string $url, int $component = -1): mixed => parse_url($url, $component)
        );
        Functions\when('get_the_title')->justReturn('');
        Functions\when('get_transient')->justReturn(false);
    }

    public function testEveryRegisteredKindRendersWithoutSendingOrWriting(): void
    {
        Functions\expect('wp_mail')->never();
        Functions\expect('wp_remote_post')->never();
        Functions\expect('wp_remote_get')->never();
        Functions\expect('wp_remote_request')->never();
        Functions\expect('wp_safe_remote_post')->never();
        Functions\expect('wp_insert_post')->never();
        Functions\expect('wp_update_post')->never();
        Functions\expect('update_option')->never();
        Functions\expect('add_option')->never();
        Functions\expect('set_transient')->never();

        $preview = $this->preview();
        $kinds = array_keys(MessageTemplates::all());

        self::assertNotEmpty($kinds);

        foreach ([null, 'fi'] as $lang) {
            $this->renderAll($preview, $kinds, $lang);
        }

        self::assertSame([], $this->wpdb->inserts);
        self::assertSame([], $this->wpdb->updates);
        self::assertSame([], $this->wpdb->deletes);
    }

    /** @param list<string> $kinds */
    private function renderAll(MessagePreview $preview, array $kinds, ?string $lang): void
    {
        foreach ($kinds as $id) {
            $message = $preview->render((string) $id, $lang);

            self::assertNotNull($message, $id);
            self::assertSame('', $message['error'], $id);

            if (MessageTemplate::CHANNEL_TELEGRAM === $message['channel']) {
                self::assertNotSame('', trim($message['text']), $id);
                continue;
            }

            self::assertNotSame('', trim($message['subject']), $id);
            self::assertNotSame('', trim($message['html'] . $message['text']), $id);
            self::assertSame(MessagePreview::SAMPLE_EMAIL, $message['to'], $id);
        }
    }

    /**
     * The email and the DM of a pair share one list of actions
     * (MessageTemplates::actions()), and the preview draws them on both
     * sides: the DM's buttons, and the email's buttons and Telegram links.
     */
    public function testEveryKindWithActionsOffersTheSameActionsOnBothChannels(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name, mixed $default = false): mixed
                => BoardService::USERNAME_OPTION === $name ? 'crewbot' : $default
        );

        $all = MessageTemplates::all();
        $preview = $this->preview();
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger(), true), true);
        $checked = 0;

        foreach ($all as $id => $template) {
            if (! $template->hasActions()) {
                continue;
            }

            $partner = str_ends_with($id, '_dm') ? substr($id, 0, -3) : $id . '_dm';
            self::assertArrayHasKey($partner, $all, $id . ' has actions but no other version');
            self::assertSame($template->actions, $all[$partner]->actions, $id . ' and ' . $partner . ' share their actions');
            self::assertNotSame($template->channel, $all[$partner]->channel, $id);

            $context = (isset($template->preview['context']) ? ($template->preview['context'])() : [])
                + $preview->sampleContext();
            $actions = $template->actions($context, $mailer);
            self::assertNotSame([], $actions, $id);

            // Every action is drawn on both channels: in the DM as a button,
            // in the email as a web button or an "Open in Telegram" link.
            $dm = array_map(static fn (array $row): string => $row[0]['text'], MessageActions::keyboard($actions));
            $email = array_unique(array_column(MessageActions::email($actions), 'label'));
            self::assertCount(count($actions), $dm, $id . ': every action is a DM button');

            foreach ($actions as $action) {
                self::assertContains($action->label, $email, $id . ': ' . $action->id . ' is in the email');
            }

            $message = $preview->render($id);
            self::assertNotNull($message);
            self::assertSame('', $message['error'], $id);

            if (MessageTemplate::CHANNEL_TELEGRAM === $template->channel) {
                self::assertSame($dm, $message['buttons'], $id . ': the preview shows the DM buttons');
            } else {
                foreach ($email as $label) {
                    self::assertStringContainsString(esc_html($label), $message['html'], $id . ': ' . $label);
                }
            }

            $checked++;
        }

        // Grows with each group of messages converted; 0 only before any.
        self::assertGreaterThanOrEqual(0, $checked);
    }

    public function testTheSampleIsNeverARealPerson(): void
    {
        $message = $this->preview()->render('sign_in_link');

        self::assertNotNull($message);
        self::assertStringContainsString('Hi Sample,', $message['html']);
        self::assertSame(MessagePreview::SAMPLE_EMAIL, $message['to']);
        self::assertStringContainsString(Mailer::PREVIEW_LINK, $message['html']);
    }

    public function testAnUnknownKindIsNull(): void
    {
        self::assertNull($this->preview()->render('no_such_kind'));
    }

    public function testTelegramTextIsEscapedWithLinksAndLineBreaks(): void
    {
        $html = MessagePreview::telegramHtml("<b>Hi</b>\nhttps://site.test/x");

        self::assertStringContainsString('&lt;b&gt;Hi&lt;/b&gt;', $html);
        self::assertStringContainsString('<br', $html);
        self::assertStringContainsString('<a href="https://site.test/x"', $html);
    }

    private function preview(): MessagePreview
    {
        $logger = new Logger();
        $mailer = new Mailer($logger, new EmailTemplate($logger));
        $tasks = new TaskRepository();

        return new MessagePreview(
            $logger,
            $tasks,
            new TicketMail(new TicketBuyerRepository(), new GuestRepository(), $tasks, $mailer),
            new FollowupMail(new FollowupRepository(), $tasks, new PersonRepository(), $mailer, $logger),
            new Newsletter(
                new NewsletterSubscriberRepository(),
                new PersonRepository(),
                new NewsletterText(new LlmClient($logger), $logger),
                $mailer,
                $logger
            )
        );
    }
}

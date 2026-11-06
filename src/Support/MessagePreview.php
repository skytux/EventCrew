<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\CrewTicket;
use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\TaskRepository;

/**
 * The Preview link of each kind on the Messages page in wp-admin: any kind
 * MessageTemplates lists, rendered by its id with sample data, through the
 * same text, placeholder and email template code the real message uses.
 * Nothing is sent and nothing is written.
 *
 * One path for every kind:
 * - the context is sampleContext(): Sample Person (sample@example.invalid),
 *   the next real task or event (else a sample one), sample people for the
 *   other placeholders, sample reasons, plus what the kind declares in its
 *   preview 'context' (MessageTemplate::$preview);
 * - the text is the one the sender reads (MessageAiText::body(), which is
 *   MessageTemplates::body() for a kind without AI text), wrapped the way
 *   the kind's preview declaration says: greeting, DM frame, buttons;
 * - an email goes through a preview Mailer, which keeps the mail instead of
 *   calling wp_mail and puts a link that does nothing where a sign-in token
 *   would be. A kind with its own layout (the ticket, the post-event email,
 *   the announcement) names its composer in 'send', and that composer
 *   (TicketMail, FollowupMail, Newsletter) builds it as for a real send.
 *
 * A new kind in MessageTemplates gets a preview with no code here; one that
 * needs more declares it in MessageTemplates::previews().
 */
final class MessagePreview
{
    public const SAMPLE_EMAIL = 'sample@example.invalid';

    public function __construct(
        private readonly Logger $logger,
        private readonly ?TaskRepository $tasks = null,
        private readonly ?TicketMail $tickets = null,
        private readonly ?FollowupMail $followups = null,
        private readonly ?Newsletter $newsletter = null
    ) {
    }

    /**
     * Kind $id as it would be sent in $lang (null: the current language), or
     * null for an unknown kind.
     *
     * For an email: subject, to, from, headers, html (the whole email, or ''
     * when it goes out as plain text) and text (the plain-text part). For a
     * DM: text (what Telegram shows) and buttons (their labels). 'error' is
     * a line saying why the kind could not be previewed, '' when it could.
     *
     * @return array{
     *     id: string, label: string, channel: string, subject: string, to: string, from: string,
     *     headers: list<string>, html: string, text: string, buttons: list<string>, error: string
     * }|null
     */
    public function render(string $id, ?string $lang = null): ?array
    {
        return Language::run($lang, function () use ($id, $lang): ?array {
            $template = MessageTemplates::find($id);

            if (null === $template) {
                return null;
            }

            $result = [
                'id' => $id,
                'label' => $template->label,
                'channel' => $template->channel,
                'subject' => '',
                'to' => '',
                'from' => '',
                'headers' => [],
                'html' => '',
                'text' => '',
                'buttons' => [],
                'error' => '',
            ];

            try {
                return $this->compose($template, $lang ?? Language::current()) + $result;
            } catch (\Throwable $error) {
                $this->logger->warning(sprintf('Message preview "%s" failed: %s', $id, get_class($error)));

                return ['error' => sprintf(
                    /* translators: %s: the kind of error, like TypeError */
                    __('This message could not be previewed (%s).', 'eventcrew'),
                    get_class($error)
                )] + $result;
            }
        });
    }

    /**
     * The sample values every kind is filled from. Never a real person's
     * data: the people are made up; the task and event are the next real
     * ones when there are any.
     *
     * @return array<string, mixed>
     */
    public function sampleContext(?string $lang = null): array
    {
        $person = self::samplePerson(__('Sample Person', 'eventcrew'), self::SAMPLE_EMAIL, $lang);
        $task = $this->sampleTask();
        $date = TicketMail::shortDate($task->taskDate);
        $standing = new Standing(Standing::UNRATED, 0.0, 0, 1);

        return [
            'person' => $person,
            'task' => $task,
            'event' => $task->eventName(),
            'date' => $date,
            'first_name' => DoorDesk::firstName($person->name()),
            'name' => $person->name(),
            'email' => $person->email,
            'credits' => 2,
            'standing' => sprintf(
                /* translators: 1: standing level and score, 2: number of free-entry credits */
                __('Your standing: %1$s · %2$d free-entry credits.', 'eventcrew'),
                $standing->ratedSummary(),
                $standing->creditBalance
            ),
            'free_cancel' => '',
            'about' => '',
            'note' => __('Thanks for the notice.', 'eventcrew'),
            'cancelled' => __('❌ Cancelled', 'eventcrew'),
            'open_tasks' => '- 📅 ' . sprintf(
                /* translators: 1: day, 2: time range or '', 3: role with emoji, 4: free places, 5: places in total */
                __('%1$s%2$s: %3$s (%4$d of %5$d places free)', 'eventcrew'),
                Dates::dayLabel($task->taskDate),
                '' === $task->timeRange() ? '' : ' ' . $task->timeRange(),
                $task->roleDisplay(),
                2,
                max(2, $task->capacity)
            ),
            'recap' => __('This will be your first task — welcome!', 'eventcrew'),
            'thanks' => '',
            'member' => self::samplePerson(__('Sample Newcomer', 'eventcrew'), 'newcomer@example.invalid', $lang),
            'cover' => self::samplePerson(__('Sample Helper', 'eventcrew'), 'helper@example.invalid', $lang),
            'holder' => self::samplePerson(__('Sample Holder', 'eventcrew'), 'holder@example.invalid', $lang),
            'giver' => __('Sample Organizer', 'eventcrew'),
            'role' => $task->roleSlug,
            'when' => Dates::dayLabel($task->taskDate),
            'deadline' => $task->startsAt ?? $task->taskDate . ' 12:00:00',
            'action' => __('Sample action', 'eventcrew'),
            'ip' => '192.0.2.1',
            'added' => 12,
            'skipped' => 1,
            'plus_ones' => 2,
            'day' => Dates::dayLabel($task->taskDate),
            'from' => 'tickets@example.invalid',
            'sending_at' => '21:40',
            'reason' => __('Sample reason: the file has no email column.', 'eventcrew'),
            'kind' => 'feedback',
            'transcript' => sprintf(
                "[%1\$s 12:00] %2\$s: %3\$s\n\n[%1\$s 12:01] %4\$s: %5\$s",
                $task->taskDate,
                $person->name(),
                __('Sample message to the bot.', 'eventcrew'),
                __('Bot', 'eventcrew'),
                __('Sample answer from the bot.', 'eventcrew')
            ),
            'help_line' => '',
            'guide' => '',
            'group_line' => '',
            'events' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compose(MessageTemplate $template, ?string $lang): array
    {
        $preview = $template->preview;
        $context = (isset($preview['context']) ? ($preview['context'])() : []) + $this->sampleContext($lang);
        $buttons = $preview['buttons'] ?? [];
        $buttons = array_values($buttons instanceof \Closure ? $buttons($context) : $buttons);
        $task = $context['task'];
        $eventId = $task instanceof Task ? $task->eventPostId : null;

        if (MessageTemplate::CHANNEL_TELEGRAM === $template->channel) {
            $text = MessageAiText::body($template->id, $context, $eventId);
            $text = true === ($preview['dm_format'] ?? false) ? EmailBody::forDm($text) : $text;
            $frame = $preview['frame'] ?? MessageTemplate::FRAME_PLAIN;

            if (MessageTemplate::FRAME_NONE !== $frame) {
                $text = DmBody::frame($context['person'], $text, MessageTemplate::FRAME_SIGNED === $frame);
            }

            return ['text' => $text, 'buttons' => $buttons];
        }

        $mailer = new Mailer($this->logger, new EmailTemplate($this->logger, true), true);
        $this->send($mailer, $template, $preview['send'] ?? MessageTemplate::PREVIEW_PERSON, $context, $buttons, $lang);
        $mail = $mailer->captured()[0] ?? null;

        if (null === $mail) {
            return ['error' => __('This message could not be previewed: nothing was made.', 'eventcrew')];
        }

        $html = in_array('Content-Type: text/html; charset=UTF-8', $mail['headers'], true);

        return [
            'subject' => $mail['subject'],
            'to' => $mail['to'],
            'from' => self::from(),
            'headers' => array_values(array_filter(
                $mail['headers'],
                static fn (string $header): bool => ! str_starts_with($header, 'Content-Type:')
            )),
            'html' => $html ? $mail['message'] : '',
            'text' => $mail['text'],
            'buttons' => $buttons,
        ];
    }

    /**
     * Hands the sample message to $mailer the way the kind's sender does.
     *
     * @param array<string, mixed> $context
     * @param list<string> $buttons
     */
    private function send(
        Mailer $mailer,
        MessageTemplate $template,
        string $send,
        array $context,
        array $buttons,
        ?string $lang
    ): void {
        /** @var Person $person */
        $person = $context['person'];
        /** @var Task $task */
        $task = $context['task'];
        $to = $person->email;

        switch ($send) {
            case MessageTemplate::PREVIEW_TICKET:
                $ticket = $this->composer($this->tickets)->compose(
                    new CrewTicket(CrewTicket::KIND_CREW, 0, $task->taskDate, $person->name(), $to, [$task], $lang)
                );
                $mailer->toTicketHolder(
                    $to,
                    $ticket['subject'],
                    $ticket['html'],
                    $ticket['text'],
                    $this->composer($this->tickets)->footer($lang),
                    true,
                    $ticket['corner']
                );

                return;
            case MessageTemplate::PREVIEW_TICKET_CANCELLED:
                $mail = $this->composer($this->tickets)
                    ->composeCancelled($person->name(), $task->taskDate, null, $lang);
                $mailer->toTicketHolder(
                    $to,
                    $mail['subject'],
                    $mail['html'],
                    $mail['text'],
                    TicketMail::noticeFooter($lang)
                );

                return;
            case MessageTemplate::PREVIEW_FOLLOWUP_CAME:
            case MessageTemplate::PREVIEW_FOLLOWUP_MISSED:
                $mail = $this->composer($this->followups)->compose(
                    $task->taskDate,
                    MessageTemplate::PREVIEW_FOLLOWUP_CAME === $send,
                    FollowupMail::sampleName(),
                    FollowupMail::KIND_SAMPLE,
                    0
                );
                $mailer->toTicketHolder(
                    $to,
                    $mail['subject'],
                    $mail['html'],
                    $mail['text'],
                    FollowupMail::footer(),
                    false,
                    '',
                    $mail['headers']
                );

                return;
            case MessageTemplate::PREVIEW_NEWSLETTER:
                $version = in_array($lang, HybridPolicy::siteLanguages(), true) ? (string) $lang : '';
                $mail = $this->composer($this->newsletter)->previewMail($version);
                $greeting = Newsletter::greeting(DoorDesk::firstName($person->name()), $version);
                $mailer->toSubscriber(
                    $to,
                    $mail['subject'],
                    $greeting['html'] . $mail['html'],
                    $greeting['text'] . "\n\n" . $mail['text'],
                    $mail['footer'],
                    Newsletter::sampleUnsubscribeUrl(),
                    Newsletter::unsubscribeHeaders(Newsletter::sampleUnsubscribeUrl()),
                    $mail['crew_note']
                );

                return;
        }

        $eventId = $task->eventPostId;
        $body = MessageAiText::body($template->id, $context, $eventId);
        $body = true === ($template->preview['greeting'] ?? false)
            ? EmailBody::withGreeting($person->name(), $body)
            : $body;
        $actions = array_map(
            static fn (string $label): array => ['label' => $label, 'url' => Mailer::PREVIEW_LINK],
            $buttons
        );
        $subject = MessageTemplates::subject($template->id, $context);

        if (MessageTemplate::PREVIEW_ADDRESS === $send) {
            $mailer->toUnconfirmedAddress($to, $subject, $body, $actions);

            return;
        }

        $mailer->toPerson($person->id, $to, $subject, $body, $actions);
    }

    /**
     * @template T of object
     * @param T|null $composer
     * @return T
     */
    private function composer(?object $composer): object
    {
        if (null === $composer) {
            throw new \RuntimeException('The composer of this message is not available.');
        }

        return $composer;
    }

    /**
     * The next task (a real one, which holds no personal data), else one for
     * the next event, else a sample task a week from today.
     */
    private function sampleTask(): Task
    {
        $role = (string) (Roles::active()[0]['slug'] ?? 'door');
        $next = null === $this->tasks ? [] : $this->tasks->upcoming(1);

        if ([] !== $next) {
            return array_values($next)[0];
        }

        $today = (string) current_time('Y-m-d');

        foreach (EventSource::upcoming(5) as $event) {
            if ($event['date'] >= $today) {
                return new Task(0, $event['date'], $role, 4, $event['id'], '', $event['starts_at'], $event['ends_at']);
            }
        }

        $day = (new \DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d');

        $name = __('Sample event', 'eventcrew');

        return new Task(0, $day, $role, 4, null, $name, $day . ' 18:00:00', $day . ' 20:00:00');
    }

    private static function samplePerson(string $name, string $email, ?string $lang): Person
    {
        return new Person(
            id: 0,
            email: $email,
            displayName: $name,
            emailVerifiedAt: '2026-01-01 00:00:00',
            telegramUserId: 1,
            telegramChatId: 1,
            language: $lang
        );
    }

    /**
     * The From line of the email as wp_mail() makes it: its defaults, through
     * the wp_mail_from and wp_mail_from_name filters a mail plugin may set.
     */
    private static function from(): string
    {
        $host = strtolower((string) wp_parse_url(network_home_url(), PHP_URL_HOST));
        $address = (string) apply_filters('wp_mail_from', 'wordpress@' . preg_replace('#^www\.#', '', $host));
        $name = (string) apply_filters('wp_mail_from_name', 'WordPress');

        return '' === $name ? $address : $name . ' <' . $address . '>';
    }

    /**
     * $text as the Telegram app shows it: the DMs are sent as plain text, so
     * every mark stays as typed, line breaks are kept and addresses become
     * links.
     */
    public static function telegramHtml(string $text): string
    {
        $html = (string) preg_replace_callback(
            '#https?://[^\s<>"]+#',
            static fn (array $m): string => '<a href="' . esc_url(html_entity_decode($m[0]))
                . '" target="_blank" rel="noopener">' . $m[0] . '</a>',
            esc_html($text)
        );

        return nl2br($html);
    }
}

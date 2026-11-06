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

    /** The kind has no AI text per event (MessageAiText::offers()). */
    public const AI_NONE = '';

    /** The kind's AI tick is off: the template's text is sent. */
    public const AI_OFF = 'off';

    /** The event has an AI text for the kind (written, or changed by hand). */
    public const AI_WRITTEN = 'written';

    /** The kind's AI tick is on, but the event has no AI text for it yet. */
    public const AI_MISSING = 'missing';

    /** How many past events the event picker offers, after the coming ones. */
    public const PAST_EVENTS = 3;

    /** The longest sample value the placeholder picker shows. */
    public const SAMPLE_LENGTH = 160;

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
     * The event and task (the preview's pickers): $taskId when it is a task
     * of $eventId (or $eventId is 0), else the first task of $eventId, else
     * a sample task on that event, else the next task (sampleTask()). The
     * text is the one a real send for that event uses: its AI text, changed
     * by hand or not, when the kind's AI tick is on (MessageAiText::body()).
     * event_id and task_id say which were used (0: none, a sample), and
     * ai is one of the AI_ states for that event and kind.
     *
     * @return array{
     *     id: string, label: string, channel: string, subject: string, to: string, from: string,
     *     headers: list<string>, html: string, text: string, buttons: list<string>, error: string,
     *     event_id: int, task_id: int, about_task: bool, ai: string
     * }|null
     */
    public function render(string $id, ?string $lang = null, int $eventId = 0, int $taskId = 0): ?array
    {
        return Language::run($lang, function () use ($id, $lang, $eventId, $taskId): ?array {
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
                'event_id' => 0,
                'task_id' => 0,
                'about_task' => self::aboutTask($template),
                'ai' => self::AI_NONE,
            ];

            try {
                $task = $this->resolveTask($eventId, $taskId);
                $result['event_id'] = (int) $task->eventPostId;
                $result['task_id'] = $task->id;
                $result['ai'] = self::aiState($id, $result['event_id']);

                return $this->compose($template, $lang ?? Language::current(), $task) + $result;
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
    public function sampleContext(?string $lang = null, ?Task $task = null): array
    {
        $person = self::samplePerson(__('Sample Person', 'eventcrew'), self::SAMPLE_EMAIL, $lang);
        $task ??= $this->sampleTask();
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
            'description' => '' !== RoleGuide::taskDescription($task)
                ? RoleGuide::taskDescription($task)
                : __('Sample description: what the task involves.', 'eventcrew'),
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
            OpenTaskList::CONTEXT_KEY => [
                ['task' => $task, 'free' => $task->hasNoLimit() ? null : max(1, $task->capacity - 1)],
            ],
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
    private function compose(MessageTemplate $template, ?string $lang, ?Task $task = null): array
    {
        $preview = $template->preview;
        $context = (isset($preview['context']) ? ($preview['context'])() : []) + $this->sampleContext($lang, $task);
        $buttons = $preview['buttons'] ?? [];
        $buttons = array_values($buttons instanceof \Closure ? $buttons($context) : $buttons);
        $mailer = new Mailer($this->logger, new EmailTemplate($this->logger, true), true);
        $emailActions = array_map(
            static fn (string $label): array => ['label' => $label, 'url' => Mailer::PREVIEW_LINK],
            $buttons
        );

        if ($template->hasActions()) {
            // The same actions the sender draws: the DM's keyboard, or the
            // email's buttons and "Open in Telegram" links, leading nowhere.
            $actions = $template->actions($context, $mailer);
            $buttons = array_map(
                static fn (array $row): string => $row[0]['text'],
                MessageActions::keyboard($actions)
            );
            $emailActions = array_map(
                static fn (array $action): array => ['url' => Mailer::PREVIEW_LINK] + $action,
                MessageActions::email($actions)
            );
        }

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

        $send = $preview['send'] ?? MessageTemplate::PREVIEW_PERSON;
        $this->send($mailer, $template, $send, $context, $emailActions, $lang);
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
            'buttons' => $template->hasActions() ? array_column($emailActions, 'label') : $buttons,
        ];
    }

    /**
     * Hands the sample message to $mailer the way the kind's sender does.
     *
     * @param array<string, mixed> $context
     * @param list<array{label: string, url: string, style?: string}> $actions
     */
    private function send(
        Mailer $mailer,
        MessageTemplate $template,
        string $send,
        array $context,
        array $actions,
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
     * The placeholder picker of the Messages page, per kind: each
     * placeholder the kind's text can use, what it becomes, and its value in
     * the preview's sample context (the next task, Sample Person), in the
     * language being composed in. The organizers' own AI placeholders
     * (EventAiText::placeholders()) are listed for the kinds that fill them
     * (MessageAiText::offers()), in the text only, with their fallback as
     * the sample. Nothing is sent or written.
     *
     * @return array<string, list<array{tag: string, description: string, sample: string, body_only: bool}>>
     */
    public function placeholderSamples(?string $lang = null): array
    {
        return Language::run($lang, function () use ($lang): array {
            $base = $this->sampleContext($lang);
            $own = EventAiText::placeholders();
            $all = [];

            foreach (MessageTemplates::all() as $id => $template) {
                $context = (isset($template->preview['context']) ? ($template->preview['context'])() : []) + $base;
                $list = [];

                foreach ($template->placeholders as $name => $placeholder) {
                    try {
                        $sample = (string) ($placeholder['resolve'])($context);
                    } catch (\Throwable $error) {
                        $sample = '';
                    }

                    $list[] = [
                        'tag' => '{' . $name . '}',
                        'description' => $placeholder['description'],
                        'sample' => self::shortSample($sample),
                        'body_only' => false,
                    ];
                }

                foreach (MessageAiText::offers((string) $id) ? $own : [] as $placeholder) {
                    $list[] = [
                        'tag' => '{' . $placeholder['name'] . '}',
                        'description' => __('Your own AI placeholder (Messages > AI), written for each event.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal.
                        'sample' => self::shortSample((string) $placeholder['fallback']),
                        'body_only' => true,
                    ];
                }

                $all[(string) $id] = $list;
            }

            return $all;
        });
    }

    /** $text on one line, cut to SAMPLE_LENGTH characters with "…". */
    public static function shortSample(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (mb_strlen($text) <= self::SAMPLE_LENGTH) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, self::SAMPLE_LENGTH - 1)) . '…';
    }

    /**
     * Whether kind $template is about one task (a reminder, a sign-up, a
     * takeover), so the preview offers the event's tasks to choose from.
     */
    public static function aboutTask(MessageTemplate $template): bool
    {
        $names = $template->placeholders;

        return isset($names['task']) || (isset($names['role']) && isset($names['event']));
    }

    /**
     * Where kind $id stands with its AI text for event $eventId, in the
     * language being composed in: AI_NONE (no AI text per event, or no
     * event), AI_OFF, AI_WRITTEN or AI_MISSING.
     */
    public static function aiState(string $id, int $eventId): string
    {
        if ($eventId <= 0 || ! MessageAiText::offers($id)) {
            return self::AI_NONE;
        }

        $settings = MessageTemplates::settings($id);
        $prompt = $settings[EventAiText::SETTING_AI_PROMPT] ?? null;

        if (empty($settings[EventAiText::SETTING_AI_EACH_EVENT]) || ! is_string($prompt) || '' === trim($prompt)) {
            return self::AI_OFF;
        }

        $text = EventAiText::text($id, $eventId, Language::current() ?? Language::ENGLISH, $settings);

        return null === $text ? self::AI_MISSING : self::AI_WRITTEN;
    }

    /**
     * The task a preview is made with (see render()): $taskId when it
     * belongs to $eventId (or $eventId is 0), else the first task of
     * $eventId, else a task on that event made up for the preview, else the
     * next task (sampleTask()).
     */
    public function resolveTask(int $eventId, int $taskId): Task
    {
        $task = $taskId > 0 && null !== $this->tasks ? $this->tasks->find($taskId) : null;

        if (null !== $task && ($eventId <= 0 || $task->eventPostId === $eventId)) {
            return $task;
        }

        if ($eventId > 0) {
            $first = $this->tasksOf($eventId)[0] ?? null;

            if (null !== $first) {
                return $first;
            }

            $event = EventSource::describe($eventId);

            if (null !== $event && '' !== $event['date']) {
                $role = (string) (Roles::active()[0]['slug'] ?? 'door');

                return new Task(0, $event['date'], $role, 4, $eventId, '', $event['starts_at'], $event['ends_at']);
            }
        }

        return $this->sampleTask();
    }

    /**
     * The event picker of the preview: the last PAST_EVENTS past events,
     * then every coming one, earliest first.
     *
     * @return list<array{id: int, title: string, date: string, past: bool}>
     */
    public function events(): array
    {
        $today = (string) current_time('Y-m-d');
        $past = [];
        $coming = [];

        foreach (EventSource::upcoming() as $event) {
            if ('' === $event['date']) {
                continue;
            }

            $row = [
                'id' => (int) $event['id'],
                'title' => EventName::name((int) $event['id'], EventName::lang(), $event['title']),
                'date' => $event['date'],
                'past' => $event['date'] < $today,
            ];

            if ($row['past']) {
                $past[] = $row;
            } else {
                $coming[] = $row;
            }
        }

        return array_merge(array_slice($past, -self::PAST_EVENTS), $coming);
    }

    /**
     * The task picker of the preview: the tasks of $eventId, earliest first.
     *
     * @return list<Task>
     */
    public function tasksOf(int $eventId): array
    {
        return $eventId <= 0 || null === $this->tasks ? [] : array_values($this->tasks->forEvent($eventId));
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

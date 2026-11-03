<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Repositories\PersonRepository;

/**
 * The newsletter: an email about the coming events, sent by itself a set
 * number of days (the "days" setting, default 7) before each event to every
 * confirmed subscriber.
 *
 * How one newsletter happens:
 *
 * 1. The hourly heartbeat calls run(). It finds the earliest coming event that
 *    no newsletter has carried yet. When that event is $days days away plus
 *    one (the preview day), it makes a plan: the send day ($days before the
 *    event, or tomorrow if that day has already gone) and every coming event
 *    in the $days days after the send day. One event is in one newsletter at
 *    most, so events that fall in the same window share one newsletter.
 * 2. At the same moment every organizer gets a preview email with the exact
 *    content and a "Stop this newsletter" link. The crew app's Admin tab has
 *    the same Stop button. A stopped newsletter is not sent, and its events
 *    are not put into a later one.
 * 3. On the send day, inside the send window (SendWindow), run() starts the
 *    sending. A WP-Cron event (HOOK) sends BATCH emails per run and schedules
 *    itself again until every confirmed subscriber has had it. Then the
 *    organizers' text (NewsletterText) is cleared.
 *
 * An event that starts before a newsletter could still be previewed and sent
 * (for example, the setting was switched on the day before it) is marked
 * missed and gets no newsletter.
 *
 * Every email carries Finnish and English: the Finnish part first, then a
 * line, then the English part. The fixed strings come from gettext, rendered
 * once inside Language::run('fi') and once inside Language::run('en'). In the
 * Finnish part an event's title is EventMesh's Finnish translation when there
 * is a current one, otherwise the title as written.
 *
 * Everything the newsletter remembers is in one option (STATE_OPTION):
 * the ledger (event id => sent, stopped or missed, with the event's date;
 * entries go once the date has passed), the current plan, the last
 * newsletter sent, and the next plan id.
 *
 * run() also deletes newsletter sign-ups that were never confirmed, PENDING_DAYS
 * after they were made, whether or not the newsletter is switched on.
 */
final class Newsletter
{
    public const ENABLED_OPTION = 'eventcrew_newsletter_enabled';

    public const DAYS_OPTION = 'eventcrew_newsletter_days';

    public const STATE_OPTION = 'eventcrew_newsletter_state';

    /** The Media Library image behind the sign-up bar (an attachment id; 0 = none). */
    public const BAR_IMAGE_OPTION = 'eventcrew_newsletter_bar_image';

    public const DEFAULT_DAYS = 7;

    public const MIN_DAYS = 1;

    public const MAX_DAYS = 60;

    /** The cron event that sends one batch. */
    public const HOOK = 'eventcrew_send_newsletter';

    /** Emails per cron run. */
    public const BATCH = 20;

    /** Days an unconfirmed sign-up is kept. */
    public const PENDING_DAYS = 7;

    /** The REST route of the confirm, unsubscribe and stop links (Web\NewsletterController). */
    public const ROUTE = 'eventcrew/v1/newsletter';

    public const PURPOSE_CONFIRM = 'newsletter_confirm';

    public const PURPOSE_UNSUBSCRIBE = 'newsletter_unsubscribe';

    public const PURPOSE_STOP = 'newsletter_stop';

    /** stop()'s answers. */
    public const STOPPED = 'stopped';
    public const ALREADY = 'already';
    public const TOO_LATE = 'too_late';

    public const PLAN_PLANNED = 'planned';
    public const PLAN_SENDING = 'sending';

    public const LEDGER_SENT = 'sent';
    public const LEDGER_STOPPED = 'stopped';
    public const LEDGER_MISSED = 'missed';

    /** The closed reasons that keep an event out, as in AssistantKnowledge. */
    private const HIDDEN_REASONS = ['unpublished', 'disabled', 'hidden'];

    /** Events read from EventMesh per run: -1 is all of them. */
    private const EVENT_SCAN = -1;

    /** MySQL lock name shared by run() and sendBatch(). */
    private const LOCK = 'eventcrew_newsletter';

    public function __construct(
        private readonly NewsletterSubscriberRepository $subscribers,
        private readonly PersonRepository $people,
        private readonly NewsletterText $text,
        private readonly Mailer $mailer,
        private readonly Logger $logger
    ) {
    }

    public function boot(): void
    {
        add_action(self::HOOK, [$this, 'sendBatch']);
        add_action(NewsletterText::CHANGED_ACTION, [$this, 'onTextChanged']);
    }

    public static function enabled(): bool
    {
        return '1' === (string) get_option(self::ENABLED_OPTION, '0');
    }

    /** The "days before the event" setting, within MIN_DAYS..MAX_DAYS. */
    public static function days(): int
    {
        $days = (int) get_option(self::DAYS_OPTION, self::DEFAULT_DAYS);

        return $days < self::MIN_DAYS || $days > self::MAX_DAYS ? self::DEFAULT_DAYS : $days;
    }

    /** The sign-up bar's background image: an attachment id, or 0 for none. */
    public static function barImageId(): int
    {
        return max(0, (int) get_option(self::BAR_IMAGE_OPTION, 0));
    }

    /** A typed value cut into MIN_DAYS..MAX_DAYS, for the settings form. */
    public static function clampDays(int $days): int
    {
        return max(self::MIN_DAYS, min(self::MAX_DAYS, $days));
    }

    /** Asks WP-Cron to send the next batch, unless that is already asked. */
    public static function schedule(): void
    {
        if (! function_exists('wp_next_scheduled') || ! function_exists('wp_schedule_single_event')) {
            return;
        }

        if (false === wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time(), self::HOOK);
        }
    }

    public static function confirmUrl(int $subscriberId): string
    {
        return self::link('confirm', SignedLink::sign(self::PURPOSE_CONFIRM, $subscriberId));
    }

    public static function unsubscribeUrl(int $subscriberId): string
    {
        return self::link('unsubscribe', SignedLink::sign(self::PURPOSE_UNSUBSCRIBE, $subscriberId));
    }

    public static function stopUrl(int $planId): string
    {
        return self::link('stop', SignedLink::sign(self::PURPOSE_STOP, $planId));
    }

    /**
     * The heartbeat's part: delete expired sign-ups, then plan, preview and
     * start the next newsletter when it is due. Called on every heartbeat.
     */
    public function run(): void
    {
        $this->subscribers->purgePending(self::daysAgo(self::PENDING_DAYS));

        $this->locked(function (): void {
            $this->runLocked();
        });
    }

    /**
     * The cron event: sends the current newsletter to the next BATCH
     * subscribers. Returns how many emails were tried.
     */
    public function sendBatch(): int
    {
        $tried = 0;

        $this->locked(function () use (&$tried): void {
            $tried = $this->sendBatchLocked();
        });

        return $tried;
    }

    /**
     * Stops the planned newsletter $planId. Its events get no newsletter.
     *
     * @return string STOPPED, ALREADY (no such plan: it was stopped or sent
     *     before, or never existed) or TOO_LATE (it is being sent).
     */
    public function stop(int $planId): string
    {
        $answer = self::ALREADY;

        $this->locked(function () use ($planId, &$answer): void {
            $state = $this->state();
            $plan = $state['plan'];

            if (null === $plan || $plan['id'] !== $planId) {
                return;
            }

            if (self::PLAN_SENDING === $plan['status']) {
                $answer = self::TOO_LATE;

                return;
            }

            foreach ($plan['events'] as $event) {
                $state['ledger'][$event['id']] = ['s' => self::LEDGER_STOPPED, 'd' => $event['date']];
            }

            $state['plan'] = null;
            $this->save($state);
            $answer = self::STOPPED;
        });

        return $answer;
    }

    /**
     * After the organizers' text changed: a newsletter that is planned and not
     * sent yet carries the new text, so the organizers get the preview again.
     */
    public function onTextChanged(): void
    {
        $plan = $this->state()['plan'];

        if (null !== $plan && self::PLAN_PLANNED === $plan['status'] && self::enabled()) {
            $this->sendPreview($plan);
        }
    }

    /**
     * What the Admin tab shows.
     *
     * @return array{
     *     enabled: bool,
     *     days: int,
     *     subscribers: int,
     *     plan: array<string, mixed>|null,
     *     last: array<string, mixed>|null,
     *     text: array<string, mixed>|null
     * }
     */
    public function status(): array
    {
        $state = $this->state();

        return [
            'enabled' => self::enabled(),
            'days' => self::days(),
            'subscribers' => $this->subscribers->countConfirmed(),
            'plan' => $state['plan'],
            'last' => $state['last'],
            'text' => $this->text->get(),
        ];
    }

    /**
     * The newsletter's subject and both bodies: one card per event in
     * $events, the organizers' $text above them, and the $later events as
     * short lines under them (NewsletterLayout). The picture, price, ticket
     * link and description of each event are read from EventMesh now, so a
     * change made since the plan is in the email.
     *
     * @param array<int, array<string, mixed>> $events Plan events.
     * @param array{fi: string, en: string}|null $text
     * @param array<int, array<string, mixed>> $later Other coming events, in the plan events' shape.
     * @return array{subject: string, html: string, text: string}
     */
    public function compose(array $events, ?array $text, array $later = []): array
    {
        $cards = array_map([self::class, 'withCard'], array_values($events));
        $later = array_map([self::class, 'withCard'], array_values($later));

        return [
            'subject' => self::both(static fn (): string => __('Coming events', 'eventcrew'))
                . ' – ' . (string) get_bloginfo('name'),
            'html' => NewsletterLayout::html($cards, $later, $text, EmailTemplate::accent()),
            'text' => NewsletterLayout::text($cards, $later, $text),
        ];
    }

    /** The footer line, in both languages: the way out of the newsletter. */
    public static function footer(): string
    {
        return self::both(
            static fn (): string => __(
                'You get this email because you joined the next events announcement. Leave the announcement',
                'eventcrew'
            )
        );
    }

    private function runLocked(): void
    {
        $state = $this->state();
        $today = (string) current_time('Y-m-d');

        // Entries of events that are over can never matter again.
        $state['ledger'] = array_filter(
            $state['ledger'],
            static fn (array $entry): bool => (string) ($entry['d'] ?? '') > $today
        );

        if (! self::enabled()) {
            // A plan that has not started is dropped, so switching the
            // newsletter on again makes a fresh one with a fresh preview. One
            // being sent is kept: it goes on when the newsletter is switched on.
            if (null !== $state['plan'] && self::PLAN_PLANNED === $state['plan']['status']) {
                $state['plan'] = null;
            }

            $this->save($state);

            return;
        }

        $plan = $state['plan'];

        if (null !== $plan && self::PLAN_SENDING === $plan['status']) {
            // Heals a lost cron event: the batches go on.
            self::schedule();
            $this->save($state);

            return;
        }

        if (null !== $plan && ! $this->hasComingEvent($plan, $today)) {
            foreach ($plan['events'] as $event) {
                $state['ledger'][$event['id']] = ['s' => self::LEDGER_MISSED, 'd' => $event['date']];
            }

            $state['plan'] = null;
            $plan = null;
        }

        if (! SendWindow::isOpen()) {
            $this->save($state);

            return;
        }

        if (null !== $plan) {
            if ($today >= $plan['send_on']) {
                $state = $this->startSending($state, $today);
            }

            $this->save($state);

            return;
        }

        $state = $this->plan($state, $today);
        $this->save($state);

        if (null !== $state['plan']) {
            $this->sendPreview($state['plan']);
        }
    }

    /**
     * Makes the next plan when its preview day has come, marking events it is
     * too late for as missed.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function plan(array $state, string $today): array
    {
        $days = self::days();
        $tomorrow = self::addDays($today, 1);
        $events = $this->eligibleEvents($state['ledger'], $today);

        foreach ($events as $index => $first) {
            if ($today < self::addDays($first['date'], -$days - 1)) {
                // The earliest event is not due yet, so no later one is.
                return $state;
            }

            $sendOn = max(self::addDays($first['date'], -$days), $tomorrow);

            if ($sendOn >= $first['date']) {
                // There is no day left to send a previewed newsletter.
                $state['ledger'][$first['id']] = ['s' => self::LEDGER_MISSED, 'd' => $first['date']];

                continue;
            }

            $until = self::addDays($sendOn, $days);
            $chosen = [];

            foreach (array_slice($events, $index) as $event) {
                if ($event['date'] > $sendOn && $event['date'] <= $until) {
                    $chosen[] = $event;
                }
            }

            $state['plan'] = [
                'id' => $state['next_id'],
                'status' => self::PLAN_PLANNED,
                'send_on' => $sendOn,
                'events' => $chosen,
                'cursor' => 0,
                'sent' => 0,
                'failed' => 0,
                'text' => null,
            ];
            $state['next_id']++;

            return $state;
        }

        return $state;
    }

    /**
     * Turns the plan into a sending one: drops events that were hidden or
     * moved into the past since the preview, keeps the organizers' text as it
     * is now, and starts the batches.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function startSending(array $state, string $today): array
    {
        $plan = $state['plan'];
        $events = [];

        foreach ($plan['events'] as $event) {
            if ($event['date'] <= $today) {
                $state['ledger'][$event['id']] = ['s' => self::LEDGER_MISSED, 'd' => $event['date']];

                continue;
            }

            if (in_array(EventSource::closedReason($event['id']), self::HIDDEN_REASONS, true)) {
                continue;
            }

            $events[] = $event;
        }

        if ([] === $events) {
            $state['plan'] = null;

            return $state;
        }

        $text = $this->text->get();
        $plan['events'] = $events;
        $plan['later'] = $this->laterEvents($events, $today);
        $plan['status'] = self::PLAN_SENDING;
        $plan['text'] = null === $text ? null : ['fi' => $text['fi'], 'en' => $text['en'], 'rev' => $text['rev']];
        $state['plan'] = $plan;

        self::schedule();

        return $state;
    }

    private function sendBatchLocked(): int
    {
        if (! self::enabled()) {
            return 0;
        }

        $state = $this->state();
        $plan = $state['plan'];

        if (null === $plan || self::PLAN_SENDING !== $plan['status']) {
            return 0;
        }

        $rows = $this->subscribers->confirmedAfter((int) $plan['cursor'], self::BATCH);
        $mail = $this->compose($plan['events'], $plan['text'], $plan['later'] ?? []);
        $footer = self::footer();

        foreach ($rows as $row) {
            $unsubscribe = self::unsubscribeUrl($row['id']);
            $sent = $this->mailer->toSubscriber(
                $row['email'],
                $mail['subject'],
                $mail['html'],
                $mail['text'],
                $footer,
                $unsubscribe,
                [
                    'List-Unsubscribe: <' . $unsubscribe . '>',
                    'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
                ]
            );

            $plan['cursor'] = $row['id'];
            $plan[$sent ? 'sent' : 'failed']++;
            $state['plan'] = $plan;
            $this->save($state);
        }

        if (count($rows) >= self::BATCH) {
            self::schedule();

            return count($rows);
        }

        foreach ($plan['events'] as $event) {
            $state['ledger'][$event['id']] = ['s' => self::LEDGER_SENT, 'd' => $event['date']];
        }

        $state['last'] = [
            'at' => (string) current_time('mysql'),
            'sent' => $plan['sent'],
            'failed' => $plan['failed'],
            'events' => count($plan['events']),
        ];
        $state['plan'] = null;
        $this->save($state);

        if (null !== $plan['text']) {
            $this->text->clearIfRevision((int) $plan['text']['rev']);
        }

        $this->logger->info(sprintf(
            'Newsletter sent: %d emails, %d failed.',
            $plan['sent'],
            $plan['failed']
        ));

        return count($rows);
    }

    /**
     * Mails every organizer the preview of $plan, in their own language, with
     * the exact newsletter below (as HTML, the way subscribers get it) and the
     * Stop button. Returns how many went.
     *
     * @param array<string, mixed> $plan
     */
    private function sendPreview(array $plan): int
    {
        $mail = $this->compose(
            $plan['events'],
            $this->text->get(),
            $this->laterEvents($plan['events'], (string) current_time('Y-m-d'))
        );
        $count = $this->subscribers->countConfirmed();
        $stopUrl = self::stopUrl($plan['id']);
        $sentTo = 0;

        foreach ($this->people->organizers() as $person) {
            $words = Language::forPerson($person, static fn (): array => [
                'subject' => sprintf(
                    /* translators: %s: the date the newsletter will be sent, like 14.11.2026 */
                    __('Next events announcement preview: it goes out on %s', 'eventcrew'),
                    TicketMail::shortDate($plan['send_on'])
                ),
                'intro' => sprintf(
                    /* translators: 1: the send date, like 14.11.2026, 2: number of subscribers */
                    __('This announcement goes out on %1$s to %2$d subscribers. If it should not go out, stop it with the button at the end, or in the Admin tab of the crew app. To change the text, send /newsletter to the bot in a private chat, or use the Admin tab.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                    TicketMail::shortDate($plan['send_on']),
                    $count
                ),
                'subject_line' => __('Subject', 'eventcrew'),
                'stop' => __('Do not send this announcement', 'eventcrew'),
            ]);

            $intro = $words['intro'] . "\n\n" . $words['subject_line'] . ': ' . $mail['subject'];

            $sent = $this->mailer->toPersonWithContent(
                $person->id,
                $person->email,
                $words['subject'],
                $intro,
                $mail['html'],
                $mail['text'],
                [['label' => $words['stop'], 'url' => $stopUrl]]
            );

            if ($sent) {
                $sentTo++;
            }
        }

        return $sentTo;
    }

    /**
     * A plan event with EventSource::card()'s fields added (picture, price,
     * ticket link, music page, description).
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private static function withCard(array $event): array
    {
        return array_merge(EventSource::card((int) ($event['id'] ?? 0)), $event);
    }

    /**
     * The coming events that are not in $planEvents, earliest first: the
     * "Later" list. An event that an earlier announcement carried is listed
     * again here.
     *
     * @param array<int, array<string, mixed>> $planEvents
     * @return array<int, array<string, mixed>>
     */
    private function laterEvents(array $planEvents, string $today): array
    {
        $planned = array_map('intval', array_column($planEvents, 'id'));

        return array_values(array_filter(
            $this->comingEvents($today),
            static fn (array $event): bool => ! in_array($event['id'], $planned, true)
        ));
    }

    /**
     * The coming events no newsletter has carried, earliest first.
     *
     * @param array<int|string, mixed> $ledger
     * @return array<int, array{
     *     id: int,
     *     date: string,
     *     time: string,
     *     title: string,
     *     title_fi: string,
     *     url: string,
     *     sold_out: bool
     * }>
     */
    private function eligibleEvents(array $ledger, string $today): array
    {
        return array_values(array_filter(
            $this->comingEvents($today),
            static fn (array $event): bool => ! isset($ledger[$event['id']])
        ));
    }

    /**
     * Every coming event that is not hidden, earliest first, in the plan
     * events' shape.
     *
     * @return array<int, array{
     *     id: int,
     *     date: string,
     *     time: string,
     *     title: string,
     *     title_fi: string,
     *     url: string,
     *     sold_out: bool
     * }>
     */
    private function comingEvents(string $today): array
    {
        $events = [];

        foreach (EventSource::upcoming(self::EVENT_SCAN) as $event) {
            if ('' === $event['date'] || $event['date'] <= $today) {
                continue;
            }

            $closed = EventSource::closedReason($event['id']);

            if (in_array($closed, self::HIDDEN_REASONS, true)) {
                continue;
            }

            $url = get_permalink($event['id']);

            $events[] = [
                'id' => $event['id'],
                'date' => $event['date'],
                'time' => substr($event['starts_at'], 11, 5),
                'title' => $event['title'],
                'title_fi' => EventSource::translatedTitle($event['id'], Language::FINNISH),
                'url' => is_string($url) ? $url : '',
                'sold_out' => 'sold_out' === $closed,
            ];
        }

        return $events;
    }

    /** @param array<string, mixed> $plan */
    private function hasComingEvent(array $plan, string $today): bool
    {
        foreach ($plan['events'] as $event) {
            if ($event['date'] > $today) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *     ledger: array<int|string, array{s: string, d: string}>,
     *     plan: array<string, mixed>|null,
     *     last: array<string, mixed>|null,
     *     next_id: int
     * }
     */
    private function state(): array
    {
        $stored = get_option(self::STATE_OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        return [
            'ledger' => is_array($stored['ledger'] ?? null) ? $stored['ledger'] : [],
            'plan' => is_array($stored['plan'] ?? null) ? $stored['plan'] : null,
            'last' => is_array($stored['last'] ?? null) ? $stored['last'] : null,
            'next_id' => max(1, (int) ($stored['next_id'] ?? 1)),
        ];
    }

    /** @param array<string, mixed> $state */
    private function save(array $state): void
    {
        update_option(self::STATE_OPTION, $state, false);
    }

    /**
     * Runs $work while holding the newsletter's MySQL lock, or not at all when
     * another request holds it.
     */
    private function locked(callable $work): void
    {
        global $wpdb;

        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::LOCK))) {
            return;
        }

        try {
            $work();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::LOCK));
        }
    }

    /** "fi / en" from a callback run in each language; one when both agree. */
    private static function both(callable $words): string
    {
        $fi = (string) Language::run(Language::FINNISH, $words);
        $en = (string) Language::run(Language::ENGLISH, $words);

        return $fi === $en ? $en : $fi . ' / ' . $en;
    }

    private static function link(string $do, string $token): string
    {
        return add_query_arg(['do' => $do, 'token' => $token], rest_url(self::ROUTE));
    }

    private static function addDays(string $ymd, int $days): string
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

        return false === $day ? $ymd : $day->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    /** The MySQL datetime $days days before now (site time). */
    private static function daysAgo(int $days): string
    {
        $now = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) current_time('mysql'));

        return false === $now ? '' : $now->modify(sprintf('-%d days', $days))->format('Y-m-d H:i:s');
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\NewsletterHistoryRepository;
use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Telegram\NewsletterPreviewDm;

/**
 * The newsletter: an email about the coming events, sent by itself a set
 * number of days (the "days" setting, default 7) before each event to every
 * confirmed subscriber.
 *
 * How one newsletter happens (all times are the site's; NewsletterSchedule
 * has the settings and the arithmetic):
 *
 * 1. The hourly heartbeat calls run(). It finds the earliest coming event that
 *    no newsletter has carried yet, and works out two times for it:
 *    - the send time: $days days before the event, at the send time setting
 *      (HH:MM, by default the hour the send window opens);
 *    - the preview time: either some hours (default 0) after the previous
 *      event ends - the latest event, not hidden, that starts before this
 *      one - or a set number of days before this event at a set HH:MM. The
 *      first falls back to the second when there is no previous event or it
 *      has no end time.
 * 2. At the first run at or after the preview time, but no later than one
 *    day before the send time, run() makes a plan: the send time (or
 *    tomorrow at the send time, if a plan made late would otherwise send
 *    sooner) and every coming event in the $days days after the send day.
 *    One event is in one newsletter at most, so events that fall in the same
 *    window share one newsletter.
 * 3. Every organizer gets a preview email, at the first run at or after the
 *    preview time: the email a subscriber will get, as it will look at the
 *    send time, with a "Do Not Send This" button at the bottom.
 *    When the preview time is not before the send time, the preview goes
 *    when the plan is made instead. If the send time comes before the
 *    preview has gone (a run was skipped), the preview goes first and the
 *    sending starts on the next run. The crew app's Admin tab has the same
 *    Stop button. A stopped newsletter is not sent, and its events are not
 *    put into a later one. A text change re-sends a preview that has gone.
 *    When the language model writes the texts (NewsletterWriter), they are
 *    written into the plan before the preview, so the preview and the
 *    newsletter carry the same texts.
 * 4. At the first run at or after the send time, run() starts the sending.
 *    A WP-Cron event (HOOK) sends BATCH emails per run and schedules itself
 *    again until every confirmed subscriber has had it. Then the organizers'
 *    text (NewsletterText) is cleared.
 *
 * An event that starts before a newsletter could still be previewed and sent
 * (for example, the setting was switched on the day before it) is marked
 * missed and gets no newsletter.
 *
 * With the Hybrid tick on, every email carries every site language, the main
 * language first, each part after a line; with it off, only the subscriber's
 * language (HybridPolicy). The fixed strings come from gettext, rendered once
 * inside Language::run() per language, and the organizers' text and the
 * written texts are the ones stored for that language. In the Finnish part
 * an event's title is EventMesh's Finnish translation when there
 * is a current one, otherwise the title as written.
 *
 * Everything the newsletter remembers is in one option (STATE_OPTION):
 * the ledger (event id => sent, stopped or missed, with the event's date
 * and, for a sent event, the text and the practical information the language
 * model wrote for it; entries go once the date has passed), the current plan,
 * the last newsletter sent, and the next plan id. The post-event email
 * (FollowupMail) uses those too (writtenTexts()).
 *
 * run() also deletes newsletter sign-ups that were never confirmed, PENDING_DAYS
 * after they were made, whether or not the newsletter is switched on.
 */
final class Newsletter
{
    /** Put in front of every preview email's subject (Owner, 2026-11-03). */
    public const PREVIEW_PREFIX = '[Preview] ';

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

    /** The subscriber's own page: change the name and language, or leave. */
    public const PURPOSE_MANAGE = 'newsletter_manage';

    /** stop()'s answers. */
    public const STOPPED = 'stopped';
    public const ALREADY = 'already';
    public const TOO_LATE = 'too_late';

    public const PLAN_PLANNED = 'planned';
    public const PLAN_SENDING = 'sending';

    public const LEDGER_SENT = 'sent';
    public const LEDGER_STOPPED = 'stopped';
    public const LEDGER_MISSED = 'missed';

    /** Stands in for the subscriber's own link in the kept copy of a sent newsletter. */
    public const COPY_LINK = '#';

    /**
     * The subscriber id in the preview's sample unsubscribe link: no row has
     * it, so a POST to that link unsubscribes nobody.
     */
    public const SAMPLE_ID = 0;

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
        private readonly Logger $logger,
        private readonly ?NewsletterWriter $writer = null,
        private readonly ?NewsletterHistoryRepository $history = null,
        // Optional: without it the preview is the email alone.
        private readonly ?NewsletterPreviewDm $previewDm = null
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

    /**
     * The List-Unsubscribe headers of one announcement for its unsubscribe
     * link $url (RFC 2369 and RFC 8058): mail apps show their own unsubscribe
     * button, and a one-click POST to $url unsubscribes at once. None when
     * $url is not https, which one-click requires.
     *
     * @return list<string>
     */
    public static function unsubscribeHeaders(string $url): array
    {
        return FollowupMail::unsubscribeHeaders($url);
    }

    /**
     * The unsubscribe link of the organizers' preview: a sample link that
     * unsubscribes nobody (no subscriber has id SAMPLE_ID).
     */
    public static function sampleUnsubscribeUrl(): string
    {
        return self::unsubscribeUrl(self::SAMPLE_ID);
    }

    /** The footer link of one subscriber's announcement: their manage page. */
    public static function manageUrl(int $subscriberId): string
    {
        return self::link('manage', SignedLink::sign(self::PURPOSE_MANAGE, $subscriberId));
    }

    /**
     * The greeting at the top of one recipient's announcement, in $lang
     * ('fi', 'en', or '' for both; see NewsletterLayout::greeting()).
     *
     * @return array{html: string, text: string}
     */
    public static function greeting(?string $name, string $lang = ''): array
    {
        return NewsletterLayout::greeting($name, $lang);
    }

    public static function stopUrl(int $planId): string
    {
        return self::link('stop', SignedLink::sign(self::PURPOSE_STOP, $planId));
    }

    /**
     * The heartbeat's part: delete expired sign-ups, then plan, preview and
     * start the next newsletter when it is due. Called on every heartbeat.
     *
     * With $send false (outside the send window, when the organizers have
     * their emails at any hour) only the organizers' part runs: planning and
     * the preview. A newsletter whose send time has come starts at the first
     * run with $send true.
     */
    public function run(bool $send = true): void
    {
        $this->subscribers->purgePending(self::daysAgo(self::PENDING_DAYS));

        $this->locked(function () use ($send): void {
            $this->runLocked($send);
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
     * How many would get the newsletter if an organizer sent it now
     * (sendNow()): every confirmed subscriber. 0 while it is switched off
     * (its batches wait for the switch), while one is being sent, when there
     * is no coming event to carry, or with no confirmed subscriber.
     */
    public function sendNowCount(): int
    {
        if (! self::enabled()) {
            return 0;
        }

        $state = $this->state();
        $today = (string) current_time('Y-m-d');
        $plan = $state['plan'];

        if (null !== $plan && self::PLAN_SENDING === $plan['status']) {
            return 0;
        }

        $carries = null !== $plan && $this->hasComingEvent($plan, $today);

        if (! $carries && [] === $this->eligibleEvents($state['ledger'], $today, EventSource::upcoming(self::EVENT_SCAN))) {
            return 0;
        }

        return $this->subscribers->countConfirmed();
    }

    /**
     * Sends the next newsletter now, without waiting for its send time (the
     * organizer asked, Owner 2026-11-14): the planned one, or, with none
     * planned, one planned now for the earliest coming events no newsletter
     * has carried, with the texts the language model writes for it. No
     * preview goes; the batches start as at the send time (startSending()).
     * Returns how many confirmed subscribers get it, 0 when it did not start.
     */
    public function sendNow(): int
    {
        $count = 0;

        $this->locked(function () use (&$count): void {
            $count = $this->sendNowLocked();
        });

        return $count;
    }

    /**
     * After the organizers' text changed: a newsletter that is planned and not
     * sent yet carries the new text, so the organizers get the preview again.
     */
    public function onTextChanged(): void
    {
        $plan = $this->state()['plan'];

        // A plan whose preview has not gone yet carries the new text when it goes.
        if (
            null !== $plan
            && self::PLAN_PLANNED === $plan['status']
            && (bool) ($plan['previewed'] ?? true)
            && self::enabled()
        ) {
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
     * short lines under them (NewsletterLayout). The picture, price and ticket
     * link of each event are read from EventMesh now, so a change made since
     * the plan is in the email. From what the language model wrote ($written,
     * NewsletterWriter), the cards show each event's practical information,
     * and its opening text stands in for a missing organizers' text. The
     * cards are the announcement's own (NewsletterLayout::html()'s
     * $announcement: the alt text with the DJ, the event, the day and the
     * place, and only the doors close time under the picture), and the crew
     * part of the thank-you email (crewPart()) comes after the events.
     *
     * $lang is the subscriber's language: a site language makes the email in
     * that language only, '' in every language (Owner, 2026-11-03). A
     * one-language email in another language than the main language whose
     * text above the cards was translated by the language model ends with
     * the "Automatically translated" note, in that language.
     *
     * @param array<int, array<string, mixed>> $events Plan events.
     * @param array<string, mixed>|null $text The organizers' text: language code => text, with source and translated.
     * @param array<int, array<string, mixed>> $later Other coming events, in the plan events' shape.
     * @param array<string, mixed>|null $written The plan's 'written'.
     * @return array{subject: string, html: string, text: string}
     */
    public function compose(
        array $events,
        ?array $text,
        array $later = [],
        ?array $written = null,
        string $lang = ''
    ): array {
        $opening = null === $text ? NewsletterWriter::opening($written) : null;
        $mail = [
            'subject' => self::inLanguage(static fn (): string => __('Coming events', 'eventcrew'), $lang)
                . ' – ' . (string) get_bloginfo('name'),
        ] + self::content(
            NewsletterWriter::apply(array_map([self::class, 'withCard'], array_values($events)), $written),
            array_map([self::class, 'withCard'], array_values($later)),
            $text ?? $opening,
            $lang,
            false,
            HybridPolicy::NEWSLETTER,
            true
        );

        // Before the sign-off: the crew, and how to join it (Owner, 2026-11-08).
        $crew = self::crewPart($lang);
        $mail['html'] .= $crew['html'];
        $mail['text'] .= $crew['text'];

        if (
            in_array($lang, HybridPolicy::siteLanguages(), true)
            && HybridPolicy::mainLanguage() !== $lang
            && self::translatedInto($lang, $text, $opening)
        ) {
            $note = NewsletterLayout::translatedNote($lang);
            $mail['html'] .= $note['html'];
            $mail['text'] .= "\n\n" . $note['text'];
        }

        return $mail;
    }

    /**
     * Whether the $lang text above the cards was translated by the language
     * model: the organizers' text when it was written in another language
     * than $lang and has a $lang text, or the language model's opening,
     * which is written in Finnish (NewsletterWriter::WRITES_IN) and
     * translated. An organizers' text kept from before the source was stored
     * counts as translated when its $lang text differs from its Finnish one.
     *
     * @param array<string, mixed>|null $text
     * @param array<string, string>|null $opening Language code => text.
     */
    private static function translatedInto(string $lang, ?array $text, ?array $opening): bool
    {
        if (null === $text) {
            return null !== $opening
                && NewsletterWriter::WRITES_IN !== $lang
                && '' !== trim((string) ($opening[$lang] ?? ''));
        }

        if (! array_key_exists('source', $text)) {
            return (string) ($text[Language::FINNISH] ?? '') !== (string) ($text[$lang] ?? '');
        }

        return ! empty($text['translated'])
            && $lang !== $text['source']
            && '' !== trim((string) ($text[$lang] ?? ''));
    }

    /**
     * The newsletter's body, HTML and plain text: the heading, the
     * organizers' $text, one card per event in $cards and the $later events
     * as short lines (NewsletterLayout). The post-event email (FollowupMail)
     * shows the coming events with this too.
     *
     * @param array<int, array<string, mixed>> $cards Events through withCard().
     * @param array<int, array<string, mixed>> $later Events through withCard().
     * @param array<string, string>|null $text Keyed by language.
     * @param string $lang A site language for one language; '' (the default, which FollowupMail
     *        uses) for every language.
     * @param bool $band True for the heading as a dark band (FollowupMail); see NewsletterLayout::html().
     * @param string $kind The message kind whose Hybrid tick decides the every-language version
     *        (HybridPolicy): NEWSLETTER, or FOLLOWUP from the post-event email.
     * @param bool $announcement True for the announcement's own cards (compose()); see NewsletterLayout::html().
     * @param bool $quietTickets True for outlined ticket buttons (FollowupMail, under its invitation to join);
     *        see NewsletterLayout::html().
     * @return array{html: string, text: string}
     */
    public static function content(
        array $cards,
        array $later,
        ?array $text,
        string $lang = '',
        bool $band = false,
        string $kind = HybridPolicy::NEWSLETTER,
        bool $announcement = false,
        bool $quietTickets = false
    ): array {
        return [
            'html' => NewsletterLayout::html(
                $cards,
                $later,
                $text,
                EmailTemplate::accent(),
                $lang,
                $band,
                $kind,
                $announcement,
                $quietTickets
            ),
            'text' => NewsletterLayout::text($cards, $later, $text, $lang, $kind, $announcement),
        ];
    }

    /**
     * The announcement's crew part, HTML and plain text: the post-event
     * email's (FollowupMail::crewPart()) with its first sentence about the
     * nights in general, in the email's languages ($lang a site language for
     * that one, '' for the every-language version). The crew page link
     * carries utm_campaign=newsletter; the Telegram link is the bare start
     * link. Empty when neither link is set.
     *
     * @return array{html: string, text: string}
     */
    private static function crewPart(string $lang): array
    {
        $langs = in_array($lang, HybridPolicy::siteLanguages(), true)
            ? [$lang]
            : HybridPolicy::languagesFor(HybridPolicy::NEWSLETTER);
        $parts = HybridPolicy::inEach($langs, static fn (): array => FollowupMail::crewWords(true));

        return FollowupMail::crewPart(
            HybridPolicy::field($parts, 'crew'),
            static fn (string $key): string => HybridPolicy::rows(HybridPolicy::field($parts, $key)),
            EmailTemplate::accent(),
            static fn (string $url): string => FollowupMail::withUtm($url, 'newsletter'),
            static fn (string $url): string => $url
        );
    }

    /**
     * The footer line, in both languages, or in $lang alone: the link to the
     * subscriber's own page, where they change their name or leave the
     * newsletter. $crew is true for a crew member (they were put on the
     * announcement as crew, NewsletterCrew), false for anyone else (they
     * joined it themselves).
     */
    public static function footer(string $lang = '', bool $crew = false): string
    {
        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literals; splitting them breaks extraction.
        return self::inLanguage(
            static fn (): string => $crew
                ? __(
                    'You get this email because you are in the crew. Change your details or leave the announcement',
                    'eventcrew'
                )
                : __(
                    'You get this email because you joined the next events announcement. Change your details or leave the announcement',
                    'eventcrew'
                ),
            $lang
        );
        // phpcs:enable Generic.Files.LineLength.TooLong
    }

    /**
     * The line above the footer link of a crew member's announcement, in
     * both languages, or in $lang alone: leaving the announcement changes no
     * other crew message. Other subscribers get no such line.
     */
    public static function crewNote(string $lang = ''): string
    {
        return self::inLanguage(
            static fn (): string => __(
                'Leaving the announcement does not stop your other crew messages.',
                'eventcrew'
            ),
            $lang
        );
    }

    /**
     * The footer and the line above it for one kind of subscriber: a crew
     * member ($crew) or anyone else.
     *
     * @return array{footer: string, crew_note: string}
     */
    private static function footerParts(string $lang, bool $crew): array
    {
        return ['footer' => self::footer($lang, $crew), 'crew_note' => $crew ? self::crewNote($lang) : ''];
    }

    private function runLocked(bool $send = true): void
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
            // Heals a lost cron event: the batches go on. Not outside the
            // window, as before the organizers' emails could go at any hour.
            if ($send) {
                self::schedule();
            }

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

        $now = (string) current_time('mysql');

        if (null !== $plan) {
            // The same send time the preview names (sendAtOf()). A plan from
            // before the preview and send times were settings was previewed
            // when it was made.
            $sendAt = self::sendAtOf($plan);

            if ($now < $sendAt) {
                // Texts the language model has not written yet (an earlier
                // run ran out of time, or the organizers removed their text):
                // written now. When the preview has already gone, the
                // organizers get the new one if one of them could be written.
                // Practical information written now is saved without a new
                // preview (it is not in NewsletterWriter::shown()).
                $written = $this->written($plan, null === $this->text->get());

                if ($written !== $plan) {
                    $changed = NewsletterWriter::shown($written) !== NewsletterWriter::shown($plan);
                    $plan = $written;
                    $state['plan'] = $plan;

                    if ($changed && (bool) ($plan['previewed'] ?? true)) {
                        $this->save($state);
                        $this->sendPreview($plan);

                        return;
                    }
                }
            }

            if (
                ! (bool) ($plan['previewed'] ?? true)
                && ($now >= (string) ($plan['preview_at'] ?? '') || $now >= $sendAt)
            ) {
                // The preview first; when the send time has come too, the
                // sending starts on the next run.
                $plan['previewed'] = true;
                $state['plan'] = $plan;
                $this->save($state);
                $this->sendPreview($plan);

                return;
            }

            if ($now >= $sendAt && $send) {
                $state = $this->startSending($state, $today);
            }

            $this->save($state);

            return;
        }

        $state = $this->plan($state, $today, $now);

        if (null !== $state['plan']) {
            $state['plan'] = $this->written($state['plan'], null === $this->text->get());
        }

        $this->save($state);

        if (null !== $state['plan'] && $state['plan']['previewed']) {
            $this->sendPreview($state['plan']);
        }
    }

    private function sendNowLocked(): int
    {
        $count = $this->sendNowCount();

        if ($count < 1) {
            return 0;
        }

        $state = $this->state();
        $today = (string) current_time('Y-m-d');
        $now = (string) current_time('mysql');

        if (null !== $state['plan'] && ! $this->hasComingEvent($state['plan'], $today)) {
            foreach ($state['plan']['events'] as $event) {
                $state['ledger'][$event['id']] = ['s' => self::LEDGER_MISSED, 'd' => $event['date']];
            }

            $state['plan'] = null;
        }

        if (null === $state['plan']) {
            $state = $this->planNow($state, $today, $now);
        }

        if (null === $state['plan']) {
            $this->save($state);

            return 0;
        }

        // Sent now: the send time is now, and no preview goes any more.
        $state['plan'] = array_merge($state['plan'], [
            'send_on' => $today,
            'send_at' => $now,
            'previewed' => true,
            'sent_now' => true,
        ]);
        $state = $this->startSending($state, $today);
        $this->save($state);

        if (null === $state['plan'] || self::PLAN_SENDING !== $state['plan']['status']) {
            return 0;
        }

        $this->logger->info(sprintf('Newsletter sent now by an organizer: %d subscribers.', $count));

        return $count;
    }

    /**
     * A plan made now for sendNow(): the earliest coming event no newsletter
     * has carried, and every other one in the days() days from today.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function planNow(array $state, string $today, string $now): array
    {
        $events = $this->eligibleEvents($state['ledger'], $today, EventSource::upcoming(self::EVENT_SCAN));

        if ([] === $events) {
            return $state;
        }

        $until = max(self::addDays($today, self::days()), (string) $events[0]['date']);
        $chosen = [];

        foreach ($events as $event) {
            if ($event['date'] <= $until) {
                unset($event['starts_at']);
                $chosen[] = $event;
            }
        }

        $state['plan'] = [
            'id' => $state['next_id'],
            'status' => self::PLAN_PLANNED,
            'send_on' => $today,
            'send_at' => $now,
            'preview_at' => $now,
            'previewed' => true,
            'events' => $chosen,
            'cursor' => 0,
            'sent' => 0,
            'failed' => 0,
            'text' => null,
        ];
        $state['next_id']++;
        $state['plan'] = $this->written($state['plan'], null === $this->text->get());

        return $state;
    }

    /**
     * Makes the next plan when its time has come (NewsletterSchedule::planAt()),
     * marking events it is too late for as missed. The plan's previewed is
     * true when its preview is due now: its preview time has passed, or it
     * is not before the send time.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function plan(array $state, string $today, string $now): array
    {
        $days = self::days();
        $all = EventSource::upcoming(self::EVENT_SCAN);
        $earliestSend = NewsletterSchedule::at(self::addDays($today, 1), NewsletterSchedule::sendTime());
        $events = $this->eligibleEvents($state['ledger'], $today, $all);

        foreach ($events as $index => $first) {
            $previewAt = NewsletterSchedule::previewAt($first, self::previousEvent($all, $first['starts_at']));
            $sendAt = NewsletterSchedule::sendAt($first['date']);

            if ($now < NewsletterSchedule::planAt($previewAt, $sendAt)) {
                // The earliest event is not due yet, so no later one is.
                return $state;
            }

            // A plan made late goes no sooner than tomorrow, so the
            // organizers always have time to read the preview.
            $sendAt = max($sendAt, $earliestSend);
            $sendOn = substr($sendAt, 0, 10);

            if ($sendOn >= $first['date']) {
                // There is no day left to send a previewed newsletter.
                $state['ledger'][$first['id']] = ['s' => self::LEDGER_MISSED, 'd' => $first['date']];

                continue;
            }

            $until = self::addDays($sendOn, $days);
            $chosen = [];

            foreach (array_slice($events, $index) as $event) {
                if ($event['date'] > $sendOn && $event['date'] <= $until) {
                    unset($event['starts_at']);
                    $chosen[] = $event;
                }
            }

            $state['plan'] = [
                'id' => $state['next_id'],
                'status' => self::PLAN_PLANNED,
                'send_on' => $sendOn,
                'send_at' => $sendAt,
                'preview_at' => $previewAt,
                'previewed' => $now >= $previewAt || $previewAt >= $sendAt,
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
        // Only what the preview did not have yet, such as an event added since.
        $plan = $this->written($plan, null === $text);
        $plan['later'] = $this->laterEvents($events, $today);
        $plan['status'] = self::PLAN_SENDING;
        // The source language too, so an English-only email knows whether
        // its English was translated by the language model.
        $plan['text'] = null === $text ? null : $text['texts'] + [
            'rev' => $text['rev'],
            'source' => (string) ($text['source'] ?? ''),
            'translated' => (bool) ($text['translated'] ?? false),
        ];
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
        $mails = [];

        foreach ($rows as $row) {
            // The subscriber's language: a site language gets that language
            // only, anything else every language (Owner, 2026-11-03), or the
            // main language alone when the Hybrid tick is off (HybridPolicy).
            $lang = in_array($row['language'] ?? '', HybridPolicy::siteLanguages(), true)
                ? $row['language']
                : self::unknownLanguage();
            // Composed once per batch and language; only the greeting is the recipient's own.
            $mails[$lang] ??= $this->compose(
                $plan['events'],
                $plan['text'],
                $plan['later'] ?? [],
                $plan['written'] ?? null,
                $lang
            ) + self::footerParts($lang, false);
            // The footer matches the subscriber's kind: crew member or not.
            $mail = self::footerParts($lang, (bool) ($row['crew'] ?? false)) + $mails[$lang];

            if (null !== $this->history && ! isset($plan['copies'][$lang])) {
                $plan['copies'][$lang] = [
                    'id' => $this->keepCopy((int) $plan['id'], $lang, $mails[$lang]),
                    'sent' => 0,
                    'failed' => 0,
                ];
            }

            $greeting = self::greeting($row['name'] ?? null, $lang);
            $unsubscribe = self::unsubscribeUrl($row['id']);
            $sent = $this->mailer->toSubscriber(
                $row['email'],
                $mail['subject'],
                $greeting['html'] . $mail['html'],
                $greeting['text'] . "\n\n" . $mail['text'],
                $mail['footer'],
                self::manageUrl($row['id']),
                self::unsubscribeHeaders($unsubscribe),
                $mail['crew_note']
            );

            $plan['cursor'] = $row['id'];
            $plan[$sent ? 'sent' : 'failed']++;

            if (isset($plan['copies'][$lang])) {
                $plan['copies'][$lang][$sent ? 'sent' : 'failed']++;
            }

            $state['plan'] = $plan;
            $this->save($state);
        }

        foreach ($plan['copies'] ?? [] as $copy) {
            $this->history?->setCounts((int) $copy['id'], (int) $copy['sent'], (int) $copy['failed']);
        }

        if (count($rows) >= self::BATCH) {
            self::schedule();

            return count($rows);
        }

        $shown = NewsletterWriter::shown($plan)['events'];
        $practical = self::practical($plan);

        foreach ($plan['events'] as $event) {
            $state['ledger'][$event['id']] = ['s' => self::LEDGER_SENT, 'd' => $event['date']]
                + (isset($shown[(int) $event['id']]) ? ['w' => $shown[(int) $event['id']]] : [])
                + (isset($practical[(int) $event['id']]) ? ['p' => $practical[(int) $event['id']]] : []);
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

        try {
            $this->writer?->remember($plan);
        } catch (\Throwable $e) {
            $this->logger->warning('Newsletter texts: the sent texts could not be kept (' . get_class($e) . ').');
        }

        $this->logger->info(sprintf(
            'Newsletter sent: %d emails, %d failed.',
            $plan['sent'],
            $plan['failed']
        ));

        return count($rows);
    }

    /**
     * Keeps a copy of one version of the newsletter for the Newsletter page
     * in wp-admin, as its first email goes, and returns the copy's id (0 when
     * it could not be kept). The copy is the email a subscriber who is not crew gets, made
     * the same way, but with the greeting that has no name and with "#" in
     * place of the subscriber's own link, so it holds no personal data.
     *
     * @param array{subject: string, html: string, footer: string, crew_note: string} $mail
     */
    private function keepCopy(int $planId, string $lang, array $mail): int
    {
        try {
            $html = $this->mailer->subscriberHtml(
                $mail['subject'],
                self::greeting(null, $lang)['html'] . $mail['html'],
                $mail['footer'],
                self::COPY_LINK,
                $mail['crew_note']
            );

            return (int) $this->history?->addSend($planId, $lang, $mail['subject'], $html);
        } catch (\Throwable $e) {
            $this->logger->warning('Newsletter: the copy of the email could not be kept (' . get_class($e) . ').');

            return 0;
        }
    }

    /**
     * Mails every organizer the preview of $plan: the email a subscriber
     * will get, made the same way as in sendBatchLocked(), with only these
     * differences (Owner, 2026-11-04): PREVIEW_PREFIX in front of the subject,
     * a red "Do Not Send This" button (the stop link) at the very bottom, and
     * under it one line with the send date and time and how many subscribers
     * get this version. The button and the line are in the organizer's own
     * language. The greeting has the organizer's first name, and the footer
     * link is COPY_LINK ("#") in place of a subscriber's own link. Returns
     * how many went.
     *
     * The email is made as it will be on the send day: the send time is
     * "now" for everything that depends on the date (previewContent(), and
     * the year in the email template).
     *
     * Subscribers get the announcement in their own language (Owner,
     * 2026-11-03), so each organizer gets one preview per version that has at
     * least one subscriber: Finnish only, English only, and both languages
     * for those whose language is not known. With no subscribers at all, the
     * one preview is the two-language version.
     *
     * @param array<string, mixed> $plan
     */
    private function sendPreview(array $plan): int
    {
        $sendAt = self::sendAtOf($plan);
        $now = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $sendAt);
        $now = false === $now ? null : $now;
        $organizers = $this->people->organizers();
        $byLanguage = $this->subscribers->countConfirmedByLanguage();

        // With the Hybrid tick off, subscribers with no language get the main language.
        if ('' !== self::unknownLanguage() && isset($byLanguage[''])) {
            $byLanguage[self::unknownLanguage()] = ($byLanguage[self::unknownLanguage()] ?? 0) + $byLanguage[''];
            unset($byLanguage['']);
        }

        $variants = array_keys(array_filter($byLanguage, static fn (int $n): bool => $n > 0));
        $variants = [] === $variants ? [self::unknownLanguage()] : array_map('strval', $variants);
        $stopUrl = self::stopUrl($plan['id']);
        $mails = [];
        $sentTo = 0;

        foreach ($organizers as $person) {
            foreach ($variants as $lang) {
                $mails[$lang] ??= $this->previewContent($plan, $sendAt, $lang);
                $mail = $mails[$lang];
                $n = $byLanguage[$lang] ?? 0;
                $words = Language::forPerson($person, static fn (): array => [
                    'stop' => __('Do Not Send This', 'eventcrew'),
                    'line' => sprintf(
                        /* translators: 1: date, like 14.11.2026, 2: time, like 10:00, 3: number of subscribers */
                        _n(
                            'Goes out on %1$s at %2$s to %3$d subscriber.',
                            'Goes out on %1$s at %2$s to %3$d subscribers.',
                            $n,
                            'eventcrew'
                        ),
                        TicketMail::shortDate(substr($sendAt, 0, 10)),
                        substr($sendAt, 11, 5),
                        $n
                    ),
                ]);
                // The greeting a subscriber gets, with the organizer's own name.
                $greeting = self::greeting(DoorDesk::firstName($person->name()), $lang);

                $sent = $this->mailer->toOrganizerAsSubscriber(
                    $person->email,
                    self::PREVIEW_PREFIX . $mail['subject'],
                    $greeting['html'] . $mail['html'],
                    $greeting['text'] . "\n\n" . $mail['text'],
                    $mail['footer'],
                    self::COPY_LINK,
                    $mail['crew_note'],
                    ['label' => $words['stop'], 'url' => $stopUrl],
                    $words['line'],
                    $now,
                    // The headers a subscriber gets, with a sample link that unsubscribes nobody.
                    self::unsubscribeHeaders(self::sampleUnsubscribeUrl())
                );

                if ($sent) {
                    $sentTo++;
                }
            }

            // The same announcement on Telegram, in the organizer's own language.
            $this->previewDm?->send(
                $person,
                array_map(
                    static fn (array $event): int => (int) $event['id'],
                    $this->previewEvents($plan, substr($sendAt, 0, 10))
                ),
                $this->previewIntro($plan),
                $sendAt,
                array_sum($byLanguage),
                $stopUrl
            );
        }

        return $sentTo;
    }

    /**
     * The newsletter in version $lang for the message preview in wp-admin
     * (MessagePreview), in sendBatchLocked()'s shape with the footer of a
     * subscriber who is not crew: the plan's, as the preview email shows it,
     * when there is a plan; else the coming events within the days the
     * newsletter covers (at least the next one), with the organizers' text
     * there is now. Reads only: nothing is planned, written or saved.
     *
     * @return array{subject: string, html: string, text: string, footer: string, crew_note: string}
     */
    public function previewMail(string $lang): array
    {
        $plan = $this->state()['plan'];

        if (null !== $plan) {
            return $this->previewContent($plan, self::sendAtOf($plan), $lang);
        }

        $today = (string) current_time('Y-m-d');
        $until = self::addDays($today, self::days() + 1);
        $events = [];
        $later = [];

        foreach (self::comingEvents($today) as $event) {
            if ([] === $events || $event['date'] <= $until) {
                $events[] = $event;
            } else {
                $later[] = $event;
            }
        }

        return $this->compose($events, $this->text->get(), $later, null, $lang) + self::footerParts($lang, false);
    }

    /**
     * The newsletter of $plan in version $lang as it will be sent at
     * $sendAt ("Y-m-d H:i:s", site time), in sendBatchLocked()'s shape: the
     * send day is "today". As startSending() does on that day, the events
     * dated on or before the send day and the hidden ones are left out, and
     * the "Later" list is the events after the send day. The organizers'
     * text is the one there is now. The footer is the one of a subscriber who is not crew.
     *
     * @param array<string, mixed> $plan
     * @return array{subject: string, html: string, text: string, footer: string, crew_note: string}
     */
    private function previewContent(array $plan, string $sendAt, string $lang): array
    {
        $today = substr($sendAt, 0, 10);
        $events = $this->previewEvents($plan, $today);

        return $this->compose(
            $events,
            $this->text->get(),
            $this->laterEvents($events, $today),
            $plan['written'] ?? null,
            $lang
        ) + self::footerParts($lang, false);
    }

    /**
     * The plan's events as they go out on $today ("Y-m-d", the send day):
     * the ones after it that are not hidden.
     *
     * @param array<string, mixed> $plan
     * @return array<int, array<string, mixed>>
     */
    private function previewEvents(array $plan, string $today): array
    {
        return array_values(array_filter(
            $plan['events'],
            static fn (array $event): bool => $event['date'] > $today
                && ! in_array(EventSource::closedReason($event['id']), self::HIDDEN_REASONS, true)
        ));
    }

    /**
     * The text above the cards, keyed by language: the organizers' text, or
     * else the language model's opening; null when there is neither.
     *
     * @param array<string, mixed> $plan
     * @return array<string, string>|null
     */
    private function previewIntro(array $plan): ?array
    {
        $stored = $this->text->get();

        if (null !== $stored && is_array($stored['texts'] ?? null)) {
            return array_map('strval', $stored['texts']);
        }

        return NewsletterWriter::opening($plan['written'] ?? null);
    }

    /**
     * The plan's send time, "Y-m-d H:i:s". A plan from before the send time
     * was a setting goes at the send time on its send day.
     *
     * @param array<string, mixed> $plan
     */
    private static function sendAtOf(array $plan): string
    {
        return (string) ($plan['send_at'] ?? NewsletterSchedule::at(
            (string) $plan['send_on'],
            NewsletterSchedule::sendTime()
        ));
    }

    /**
     * $plan with the language model's missing texts written (NewsletterWriter),
     * the opening only when $wantOpening (the organizers wrote none). Any
     * failure leaves $plan as it was.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function written(array $plan, bool $wantOpening): array
    {
        if (
            null === $this->writer
            || ! NewsletterWriter::enabled()
            || ! NewsletterWriter::missing($plan, $wantOpening)
        ) {
            return $plan;
        }

        try {
            return $this->writer->fill($plan, $wantOpening);
        } catch (\Throwable $e) {
            $this->logger->warning('Newsletter texts: writing the texts failed (' . get_class($e) . ').');

            return $plan;
        }
    }

    /**
     * A plan event with EventSource::card()'s fields added (picture, price,
     * ticket link, music page, description).
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public static function withCard(array $event): array
    {
        return array_merge(EventSource::card((int) ($event['id'] ?? 0)), $event);
    }

    /**
     * The texts and practical information the language model wrote for
     * coming events, in the shape of a plan's 'written'
     * (NewsletterWriter::apply()): those of the events a sent announcement
     * carried (kept in the ledger), and those of the current plan. The
     * post-event email (FollowupMail) shows them, so both emails show the
     * same cards.
     *
     * @return array{
     *     events: array<int, array{fi: string, en: string}>,
     *     practical: array<int, array<string, string>>
     * }
     */
    public static function writtenTexts(): array
    {
        $stored = get_option(self::STATE_OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $texts = [];
        $practical = [];

        foreach (is_array($stored['ledger'] ?? null) ? $stored['ledger'] : [] as $id => $entry) {
            if (is_array($entry) && is_array($entry['w'] ?? null)) {
                $texts[(int) $id] = $entry['w'];
            }

            $info = is_array($entry) ? NewsletterWriter::practicalOf($entry['p'] ?? null) : null;

            if (null !== $info) {
                $practical[(int) $id] = $info;
            }
        }

        if (is_array($stored['plan'] ?? null)) {
            $texts = NewsletterWriter::shown($stored['plan'])['events'] + $texts;
            $practical = self::practical($stored['plan']) + $practical;
        }

        return ['events' => $texts, 'practical' => $practical];
    }

    /**
     * The practical information in $plan that knows something, by event id.
     *
     * @param array<string, mixed> $plan
     * @return array<int, array<string, string>>
     */
    private static function practical(array $plan): array
    {
        $stored = $plan['written']['practical'] ?? null;
        $practical = [];

        foreach (is_array($stored) ? $stored : [] as $id => $info) {
            $info = NewsletterWriter::practicalOf($info);

            if (null !== $info) {
                $practical[(int) $id] = $info;
            }
        }

        return $practical;
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
     * The coming events no newsletter has carried, earliest first, each with
     * its start (starts_at, "Y-m-d H:i:s").
     *
     * @param array<int|string, mixed> $ledger
     * @param array<int, array{id: int, starts_at: string, ends_at: string, date: string}> $all
     *     EventSource::upcoming(EVENT_SCAN).
     * @return array<int, array{
     *     id: int,
     *     date: string,
     *     time: string,
     *     title: string,
     *     title_fi: string,
     *     url: string,
     *     sold_out: bool,
     *     starts_at: string
     * }>
     */
    private function eligibleEvents(array $ledger, string $today, array $all): array
    {
        $starts = array_column($all, 'starts_at', 'id');
        $events = [];

        foreach ($this->comingEvents($today, $all) as $event) {
            if (! isset($ledger[$event['id']])) {
                $events[] = $event + ['starts_at' => (string) ($starts[$event['id']] ?? '')];
            }
        }

        return $events;
    }

    /**
     * The latest event that starts before $startsAt and is not hidden, or
     * null: the event whose end the preview may wait for. AssistantStatus
     * uses it too, to tell when the next preview is due.
     *
     * @param array<int, array{id: int, starts_at: string, ends_at: string}> $all Sorted by start.
     * @return array{starts_at: string, ends_at: string}|null
     */
    public static function previousEvent(array $all, string $startsAt): ?array
    {
        foreach (array_reverse($all) as $event) {
            if (
                $event['starts_at'] < $startsAt
                && '' !== $event['starts_at']
                && ! in_array(EventSource::closedReason($event['id']), self::HIDDEN_REASONS, true)
            ) {
                return ['starts_at' => $event['starts_at'], 'ends_at' => $event['ends_at']];
            }
        }

        return null;
    }

    /**
     * Every coming event that is not hidden, earliest first, in the plan
     * events' shape: the events dated after $today. Also what the post-event
     * email (FollowupMail) lists.
     *
     * @param array<int, array<string, mixed>>|null $all EventSource::upcoming(EVENT_SCAN)
     *     when the caller has read it already.
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
    public static function comingEvents(string $today, ?array $all = null): array
    {
        $events = [];

        foreach ($all ?? EventSource::upcoming(self::EVENT_SCAN) as $event) {
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

    /**
     * The version for subscribers whose language is not known: '' (every
     * language) while the newsletter's Hybrid tick is on, else the main
     * language (HybridPolicy).
     */
    private static function unknownLanguage(): string
    {
        return HybridPolicy::isOn(HybridPolicy::NEWSLETTER) ? '' : HybridPolicy::mainLanguage();
    }

    /**
     * "fi / en" from a callback run in each language of the newsletter's
     * every-language version (HybridPolicy), each different text once.
     */
    private static function both(callable $words): string
    {
        return HybridPolicy::join(array_map(
            'strval',
            HybridPolicy::inEach(HybridPolicy::languagesFor(HybridPolicy::NEWSLETTER), $words)
        ));
    }

    /** $words run in $lang alone when it is a site language, or both() for any other $lang. */
    private static function inLanguage(callable $words, string $lang): string
    {
        return in_array($lang, HybridPolicy::siteLanguages(), true)
            ? (string) Language::run($lang, $words)
            : self::both($words);
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

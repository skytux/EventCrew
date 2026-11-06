<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\FollowupRepository;
use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Web\NewsletterController;

/**
 * The post-event email: one short email to every ticket holder of a night
 * (imported ticket buyers, door and desk sales, and guests) who has an email
 * address, the day after the event at 12:00 site time.
 *
 * - Someone who was checked in at the door gets a thank-you and an invitation
 *   to secure their spot at the next event.
 * - Someone who was not gets a kind "sorry we missed you" that hopes to see
 *   them soon.
 *
 * Both are in every site language, the main language first, like the ticket
 * email; with the Hybrid tick off, only in the recipient's language
 * (HybridPolicy). The main text of each comes from FollowupText, in that
 * language: a one-time text an organizer
 * set for the next post-event email, else the text on the Messages page
 * (followup_came, followup_missed), else the built-in text. The subject is
 * the Messages page subject. The one-time texts are removed once the next night's
 * emails are all sent, or when that night (its preview sent) is stopped or
 * left too late to send.
 * Under the text are the coming events the way the next events announcement
 * shows them (Newsletter::content()): its heading, the next one as a card
 * with its picture and the rest as short lines with small pictures under
 * "Later". The card never shows the event's description. It shows a short
 * text the language model wrote for this email only (two sentences in the
 * style of the event's DJ, in English and in Finnish) and the event's
 * practical information. Both are written once per night in a background
 * run before the preview (writeNight()) and kept in the state option, so the
 * preview and every recipient get the same; without them the card shows the
 * text and the practical information the announcement wrote for the event
 * ahead of time (Newsletter::writtenTexts()), when there are some, else no
 * text. Nothing is written while an email is put together. A newly written text
 * sends no second preview: the preview goes out once per night.
 * Then, while the announcement is switched on, an invitation to join it.
 * Its button (?eventcrew_join=<token>) carries a signed link to this one
 * ticket holder's row: pressing it subscribes that row's address as
 * confirmed, with the first word of the ticket holder's name as the name to
 * greet them with (joinClick()). The press comes from the mailbox the email
 * went to, so no confirmation email follows. A preview's or a test's button
 * subscribes nobody.
 * At the end, a "Don't send me these" link (?eventcrew_optout=<token>)
 * carries a signed link to the same row: pressing it puts the row's address
 * on the do-not-send list (FollowupOptOut, optOutClick()), with no sign-in.
 * The email is never sent to an address on that list, nor to one that left
 * the announcement; such a row is marked 'optout'. A preview's or a test's
 * link puts nobody on the list.
 *
 * Several rows of one night with the same address (one person who bought two
 * tickets) get one email. Its row is the first of them; the others are
 * marked 'duplicate'. The person counts as came when any of the rows was
 * checked in.
 *
 * Every link in the email carries utm_source=eventcrew, utm_medium=email and
 * utm_campaign=thanks-YYYY-MM-DD or missed-YYYY-MM-DD, so the shop's and the
 * site's statistics can tell which sales came from these emails. The link
 * itself goes through this site first (?eventcrew_go=<token>), which counts
 * the first click per row: only the time, in followup_clicked_at. The token
 * is signed, so the redirect cannot be used to send anyone to a page of
 * someone else's choosing, and it only ever leads to an http(s) address.
 *
 * Timing, all in the site's timezone, driven by the hourly heartbeat:
 *
 * - From 12:00 the day before the event, each organizer gets a preview with
 *   both versions (a sample name, the events as they are then) and a Stop
 *   link. The preview goes out once per night, and only when the night has
 *   a ticket holder with an email address.
 * - From 12:00 the day after the event, the emails go out in batches of
 *   BATCH, the rest in WP-Cron events of their own. Only a night whose
 *   preview went out is sent, and only an event whose day began after the
 *   setting was switched on (covers()), so switching this on never mails
 *   the people of an event that is over. A night more than LATE_DAYS late
 *   is not sent at all.
 * - An organizer can stop a night's email from the preview's link or from
 *   the Roster page until it starts going out. A stopped night's rows are
 *   marked 'stopped'.
 *
 * Switched off by default (Settings > Email).
 */
final class FollowupMail
{
    public const ENABLED_OPTION = 'eventcrew_followup_enabled';

    /** When the setting was last switched on (a Unix time; 0 when off). */
    public const ENABLED_AT_OPTION = 'eventcrew_followup_enabled_at';

    /** Which nights were previewed, stopped, started and finished. */
    public const STATE_OPTION = 'eventcrew_followup_state';

    /** The single cron event that sends one more batch. */
    public const HOOK = 'eventcrew_send_followup';

    /** Emails per batch. */
    public const BATCH = 25;

    /** The query argument of the counted link. */
    public const GO_ARG = 'eventcrew_go';

    /** The REST route of the Stop page (Web\FollowupController). */
    public const ROUTE = 'eventcrew/v1/followup';

    /** The query argument of the subscribe button. */
    public const JOIN_ARG = 'eventcrew_join';

    /** The query argument of the "Don't send me these" link. */
    public const OPTOUT_ARG = 'eventcrew_optout';

    public const PURPOSE_STOP = 'followup_stop';
    public const PURPOSE_GO = 'followup_go';
    public const PURPOSE_JOIN = 'followup_join';
    public const PURPOSE_OPTOUT = 'followup_optout';

    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_DUPLICATE = 'duplicate';

    /** Not sent: the address is on the do-not-send list or left the announcement. */
    public const STATUS_OPTOUT = 'optout';

    /** stop()'s answers. */
    public const STOPPED = 'stopped';
    public const ALREADY = 'already';
    public const TOO_LATE = 'too_late';

    /** sendTest()'s answers. */
    public const TEST_SENT = 'sent';
    public const TEST_FAILED = 'failed';

    /** The hour of the preview (the day before) and of the send (the day after). */
    public const SEND_HOUR = 12;

    /** How many days after its send time a night is still sent. */
    public const LATE_DAYS = 2;

    /** How many coming events the email shows as cards; the rest are short lines (Lou, 2026-11-03). */
    public const EVENTS = 1;

    /** The link kind of a preview's or a test's links: not counted. */
    public const KIND_SAMPLE = 't';

    /** How long a night stays in the state option. */
    private const KEEP_DAYS = 30;

    private const LOCK = 'eventcrew_followup';

    /** @var array<string, array<int, array<string, mixed>>> The coming events per night. */
    private array $events = [];

    private readonly FollowupText $text;

    private readonly NewsletterWriter $writer;

    private readonly NewsletterSubscriberRepository $subscribers;

    private readonly FollowupOptOut $optOut;

    public function __construct(
        private readonly FollowupRepository $rows,
        private readonly TaskRepository $tasks,
        private readonly PersonRepository $people,
        private readonly Mailer $mailer,
        private readonly ?Logger $logger = null,
        ?FollowupText $text = null,
        ?NewsletterWriter $writer = null,
        ?NewsletterSubscriberRepository $subscribers = null,
        ?FollowupOptOut $optOut = null
    ) {
        $textLogger = $logger ?? new Logger();
        $this->text = $text ?? new FollowupText(new LlmClient($textLogger), $textLogger);
        $this->writer = $writer ?? new NewsletterWriter(new LlmClient($textLogger), $textLogger);
        $this->subscribers = $subscribers ?? new NewsletterSubscriberRepository();
        $this->optOut = $optOut ?? new FollowupOptOut($this->subscribers);
    }

    public function boot(): void
    {
        add_action(self::HOOK, [$this, 'sendBatch']);
        // One time only: the text from the old Settings fields moves to the
        // Messages page. On init after the text domain (priority 10), so the
        // added greeting and sign-off are in each language.
        add_action('init', [FollowupText::class, 'moveSettingsText'], 20);
    }

    public static function enabled(): bool
    {
        return '1' === (string) get_option(self::ENABLED_OPTION, '0');
    }

    /**
     * Switches the email on or off. Switching it on notes the moment, so
     * only the events whose day begins after it are emailed: an event that
     * is already over (or under way) when the setting goes on is never
     * mailed late. Saving the settings again while it is on keeps the
     * first moment.
     */
    public static function setEnabled(bool $on, ?int $now = null): void
    {
        if ($on && ! self::enabled()) {
            update_option(self::ENABLED_AT_OPTION, $now ?? time());
        }

        if (! $on) {
            update_option(self::ENABLED_AT_OPTION, 0);
        }

        update_option(self::ENABLED_OPTION, $on ? '1' : '0');
    }

    /**
     * Whether $date's event is one the email covers: the setting was on
     * before 00:00 of that day, site time.
     */
    public static function covers(string $date): bool
    {
        $since = (int) get_option(self::ENABLED_AT_OPTION, 0);

        return $since > 0 && $since < self::atHour($date)->setTime(0, 0)->getTimestamp();
    }

    /**
     * The heartbeat's call: sends the previews that are due and starts (or
     * goes on with) the nights whose send time has come. Does nothing while
     * switched off.
     */
    public function run(?\DateTimeImmutable $now = null): void
    {
        if (! self::enabled()) {
            return;
        }

        $now = $now ?? self::now();

        // Switched on some other way than the settings page (an option
        // import, WP-CLI): count from now, so past events are still left out.
        if (0 === (int) get_option(self::ENABLED_AT_OPTION, 0)) {
            update_option(self::ENABLED_AT_OPTION, $now->getTimestamp());
        }

        $this->locked(function () use ($now): void {
            $this->runLocked($now);
        });
    }

    /** The cron event: one more batch of every night that is being sent. */
    public function sendBatch(): void
    {
        if (! self::enabled()) {
            return;
        }

        $this->locked(function (): void {
            $state = $this->state();

            foreach (array_keys($state['started']) as $date) {
                if (! isset($state['done'][$date]) && ! isset($state['stopped'][$date])) {
                    $state = $this->sendNight((string) $date, $state);
                }
            }

            $this->save($state);
        });
    }

    /**
     * Stops $date's post-event email. Returns STOPPED, ALREADY (stopped
     * before) or TOO_LATE (it has started going out).
     */
    public function stop(string $date): string
    {
        $answer = self::TOO_LATE;

        $this->locked(function () use ($date, &$answer): void {
            $state = $this->state();

            if (isset($state['stopped'][$date])) {
                $answer = self::ALREADY;

                return;
            }

            if (isset($state['started'][$date])) {
                $answer = self::TOO_LATE;

                return;
            }

            $state['stopped'][$date] = true;
            $this->save($state);
            $this->rows->markUnsent($date, self::STATUS_STOPPED);

            // The night whose preview went out was the next send: its
            // one-time texts are not kept for a later one.
            if (isset($state['previewed'][$date])) {
                $this->text->clearNext();
            }

            $this->log(sprintf('Post-event email for %s stopped.', $date));
            $answer = self::STOPPED;
        });

        return $answer;
    }

    /**
     * Where $date's email stands: 'off' (switched off), 'stopped', 'sending'
     * (started, not finished), 'sent' (finished), 'not_covered' (the setting
     * went on after the event's day began), or 'planned'.
     */
    public function nightStatus(string $date): string
    {
        $state = $this->state();

        return match (true) {
            isset($state['stopped'][$date]) => 'stopped',
            isset($state['done'][$date]) => 'sent',
            isset($state['started'][$date]) => 'sending',
            ! self::enabled() => 'off',
            ! self::covers($date) => 'not_covered',
            default => 'planned',
        };
    }

    /** Whether $date's email can still be stopped. */
    public function canStop(string $date, ?\DateTimeImmutable $now = null): bool
    {
        $now = $now ?? self::now();

        return 'planned' === $this->nightStatus($date)
            && $now->getTimestamp() < self::sendAt($date)->getTimestamp() + self::LATE_DAYS * DAY_IN_SECONDS;
    }

    /**
     * The night whose post-event email is the next one to start, so the one
     * the one-time texts (FollowupText) go into: a night whose preview went
     * out and that has not started, else the first coming event the email
     * covers. '' while the email is switched off or no night is known.
     *
     * @param list<string>|null $coming The coming event dates (Y-m-d); null reads them from EventSource.
     */
    public function nextNight(?\DateTimeImmutable $now = null, ?array $coming = null): string
    {
        if (! self::enabled()) {
            return '';
        }

        $now = $now ?? self::now();
        $at = $now->getTimestamp();
        $today = $now->setTimezone(self::timezone())->format('Y-m-d');
        $state = $this->state();
        $nights = [];

        foreach (array_keys($state['previewed']) as $date) {
            $date = (string) $date;

            if (
                ! isset($state['started'][$date])
                && ! isset($state['stopped'][$date])
                && ! isset($state['expired'][$date])
                && $at < self::sendAt($date)->getTimestamp() + self::LATE_DAYS * DAY_IN_SECONDS
            ) {
                $nights[] = $date;
            }
        }

        $coming ??= array_column(EventSource::upcoming(), 'date');

        foreach ($coming as $date) {
            $date = (string) $date;

            if (
                '' !== $date
                && $date >= $today
                && ! isset($state['stopped'][$date])
                && ! isset($state['started'][$date])
                && self::covers($date)
            ) {
                $nights[] = $date;
            }
        }

        sort($nights);

        return $nights[0] ?? '';
    }

    /**
     * How many of $date's emails were sent, failed and clicked.
     *
     * @return array{sent: int, failed: int, stopped: int, unsent: int, clicked: int}
     */
    public function counts(string $date): array
    {
        return $this->rows->counts($date);
    }

    /**
     * "Post-event email: 38 sent · 2 failed · 6 clicked", or a word on what
     * will happen; '' when the night has nobody to email.
     *
     * @param array{sent: int, failed: int, stopped: int, unsent: int, clicked: int} $counts
     */
    public static function statusLine(array $counts, string $nightStatus, string $date): string
    {
        if (0 === $counts['sent'] + $counts['failed'] + $counts['stopped'] + $counts['unsent']) {
            return '';
        }

        if ($counts['sent'] + $counts['failed'] > 0) {
            return sprintf(
                /* translators: 1: emails sent, 2: emails that failed, 3: emails with a link opened */
                __('Post-event email: %1$d sent · %2$d failed · %3$d clicked', 'eventcrew'),
                $counts['sent'],
                $counts['failed'],
                $counts['clicked']
            );
        }

        return match ($nightStatus) {
            'stopped' => __('Post-event email: stopped', 'eventcrew'),
            'off' => __('Post-event email: switched off', 'eventcrew'),
            'not_covered' => __('Post-event email: not sent for this event (switched on after it)', 'eventcrew'),
            default => sprintf(
                /* translators: 1: the date it goes out, like 15.11.2026, 2: the time, like 12:00 */
                __('Post-event email: goes out on %1$s at %2$s', 'eventcrew'),
                TicketMail::shortDate(self::addDays($date, 1)),
                sprintf('%02d:00', self::SEND_HOUR)
            ),
        };
    }

    /**
     * Sends both versions to $to, built with a sample name and $date's
     * coming events. Their links are not counted. Returns TEST_SENT when both
     * went, TEST_FAILED otherwise.
     */
    public function sendTest(string $date, string $to): string
    {
        if (! is_email($to)) {
            return self::TEST_FAILED;
        }

        $sent = 0;

        foreach ([true, false] as $came) {
            $mail = $this->compose($date, $came, self::sampleName(), self::KIND_SAMPLE, 0);

            $one = $this->mailer->toTicketHolder(
                $to,
                $mail['subject'],
                $mail['html'],
                $mail['text'],
                self::footer(),
                false,
                '',
                $mail['headers'],
                HybridPolicy::languagesFor(HybridPolicy::FOLLOWUP)
            );

            if ($one) {
                $sent++;
            }
        }

        return 2 === $sent ? self::TEST_SENT : self::TEST_FAILED;
    }

    /**
     * When $date's preview may go out: 12:00 the day before, site time.
     */
    public static function previewAt(string $date): \DateTimeImmutable
    {
        return self::atHour(self::addDays($date, -1));
    }

    /** When $date's emails go out: 12:00 the day after, site time. */
    public static function sendAt(string $date): \DateTimeImmutable
    {
        return self::atHour(self::addDays($date, 1));
    }

    /**
     * $url with utm_source, utm_medium and utm_campaign set. Other query
     * arguments and a #fragment stay as they are; earlier utm_source,
     * utm_medium and utm_campaign values are replaced.
     */
    public static function withUtm(string $url, string $campaign): string
    {
        $fragment = null;
        $hash = strpos($url, '#');

        if (false !== $hash) {
            $fragment = substr($url, $hash + 1);
            $url = substr($url, 0, $hash);
        }

        $query = '';
        $mark = strpos($url, '?');

        if (false !== $mark) {
            $query = substr($url, $mark + 1);
            $url = substr($url, 0, $mark);
        }

        $pairs = array_values(array_filter(
            explode('&', $query),
            static fn (string $pair): bool => '' !== $pair
                && 1 !== preg_match('/^utm_(?:source|medium|campaign)(?:=|$)/i', $pair)
        ));
        $pairs[] = 'utm_source=eventcrew';
        $pairs[] = 'utm_medium=email';
        $pairs[] = 'utm_campaign=' . rawurlencode($campaign);

        return $url . '?' . implode('&', $pairs) . (null === $fragment ? '' : '#' . $fragment);
    }

    /** "thanks-2026-11-14" or "missed-2026-11-14". */
    public static function campaign(string $date, bool $came): string
    {
        return ($came ? 'thanks-' : 'missed-') . $date;
    }

    /**
     * The counted link to $target for one row ($kind 'b' for a ticket buyer,
     * 'g' for a guest, 't' for a preview or a test, which is not counted).
     */
    public static function trackedUrl(string $target, string $kind, int $rowId): string
    {
        $token = SignedLink::signPayload(self::PURPOSE_GO, $kind . "\n" . $rowId . "\n" . $target);

        return add_query_arg([self::GO_ARG => $token], home_url('/'));
    }

    /**
     * Where a counted link leads, or null when the token is not one this
     * site signed or its address is not http(s). Records the row's first
     * click.
     */
    public function clickTarget(string $token): ?string
    {
        $payload = SignedLink::verifyPayload(self::PURPOSE_GO, $token);

        if (null === $payload) {
            return null;
        }

        $parts = explode("\n", $payload, 3);

        if (3 !== count($parts) || ! ctype_digit($parts[1])) {
            return null;
        }

        [$kind, $id, $target] = $parts;
        $scheme = strtolower((string) parse_url($target, PHP_URL_SCHEME));
        $host = (string) parse_url($target, PHP_URL_HOST);

        if (! in_array($scheme, ['http', 'https'], true) || '' === $host || 1 === preg_match('/[\s\\\\]/', $target)) {
            return null;
        }

        $kinds = ['b' => FollowupRepository::KIND_BUYER, 'g' => FollowupRepository::KIND_GUEST];

        if (isset($kinds[$kind]) && (int) $id > 0) {
            $this->rows->recordClick($kinds[$kind], (int) $id);
        }

        return $target;
    }

    /**
     * The subscribe button's link for one row ($kind 'b', 'g', or 't' for a
     * preview or a test, which subscribes nobody). $campaign goes into the
     * UTM arguments of the page it leads to.
     */
    public static function joinUrl(string $kind, int $rowId, string $campaign): string
    {
        $token = SignedLink::signPayload(self::PURPOSE_JOIN, $kind . "\n" . $rowId . "\n" . $campaign);

        return add_query_arg([self::JOIN_ARG => $token], home_url('/'));
    }

    /**
     * What pressing the subscribe button does: records the row's first click
     * (like any counted link), subscribes the row's address as confirmed with
     * the ticket holder's first name and $language ('fi', 'en' or ''), and
     * answers with the page to send the browser to - the front page's
     * subscribe form, showing that it worked. Null when the token is not one
     * this site signed.
     *
     * A sample link (a preview's or a test's) only leads to the page. So
     * does the link of a row whose email was deleted since.
     */
    public function joinClick(string $token, string $language = ''): ?string
    {
        $parts = self::joinPayload($token);

        if (null === $parts) {
            return null;
        }

        [$kind, $id, $campaign] = $parts;
        $kinds = ['b' => FollowupRepository::KIND_BUYER, 'g' => FollowupRepository::KIND_GUEST];
        $page = home_url('/');

        if (isset($kinds[$kind]) && (int) $id > 0) {
            $this->rows->recordClick($kinds[$kind], (int) $id);
            $holder = $this->rows->holder($kinds[$kind], (int) $id);

            if (
                null !== $holder
                && $this->subscribers->addFromTicket(
                    $holder['email'],
                    DoorDesk::firstName($holder['name']),
                    $language
                ) > 0
            ) {
                $page = add_query_arg(
                    [NewsletterController::RESULT_ARG => NewsletterController::RESULT_JOINED],
                    $page
                );
            }
        }

        return self::withUtm($page, $campaign) . '#' . NewsletterController::FORM_ANCHOR;
    }

    /**
     * Whether $token is a subscribe button token this site signed. Checks
     * the signature only: records nothing and subscribes nobody.
     */
    public static function isJoinToken(string $token): bool
    {
        return null !== self::joinPayload($token);
    }

    /**
     * The kind, row id and campaign signed into a subscribe button token,
     * or null when the token does not verify.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    private static function joinPayload(string $token): ?array
    {
        $payload = SignedLink::verifyPayload(self::PURPOSE_JOIN, $token);

        if (null === $payload) {
            return null;
        }

        $parts = explode("\n", $payload, 3);

        if (3 !== count($parts) || ! ctype_digit($parts[1])) {
            return null;
        }

        return [$parts[0], $parts[1], $parts[2]];
    }

    /**
     * The "Don't send me these" link for one row ($kind 'b', 'g', or 't' for
     * a preview or a test, which puts nobody on the list).
     */
    public static function optOutUrl(string $kind, int $rowId): string
    {
        $token = SignedLink::signPayload(self::PURPOSE_OPTOUT, $kind . "\n" . $rowId);

        return add_query_arg([self::OPTOUT_ARG => $token], home_url('/'));
    }

    /**
     * What pressing "Don't send me these" does: puts the row's address on
     * the do-not-send list. True when the token is one this site signed
     * (a sample link, or a row whose email was deleted since, adds nothing),
     * null when it is not.
     */
    public function optOutClick(string $token): ?bool
    {
        $parts = self::optOutPayload($token);

        if (null === $parts) {
            return null;
        }

        [$kind, $id] = $parts;
        $kinds = ['b' => FollowupRepository::KIND_BUYER, 'g' => FollowupRepository::KIND_GUEST];

        if (isset($kinds[$kind]) && $id > 0) {
            $holder = $this->rows->holder($kinds[$kind], $id);

            if (null !== $holder) {
                $this->optOut->add($holder['email']);
            }
        }

        return true;
    }

    /**
     * The List-Unsubscribe headers for the "Don't send me these" link $url
     * (RFC 2369 and RFC 8058): mail apps show their own unsubscribe button,
     * and a one-click POST to $url puts the address on the do-not-send list.
     * None when $url is not https, which one-click requires.
     *
     * @return list<string>
     */
    public static function unsubscribeHeaders(string $url): array
    {
        if (! str_starts_with($url, 'https://')) {
            return [];
        }

        return [
            'List-Unsubscribe: <' . $url . '>',
            'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
        ];
    }

    /** Whether $token is a "Don't send me these" token this site signed. Changes nothing. */
    public static function isOptOutToken(string $token): bool
    {
        return null !== self::optOutPayload($token);
    }

    /**
     * The kind and row id signed into a "Don't send me these" token, or null
     * when the token does not verify.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function optOutPayload(string $token): ?array
    {
        $payload = SignedLink::verifyPayload(self::PURPOSE_OPTOUT, $token);

        if (null === $payload) {
            return null;
        }

        $parts = explode("\n", $payload, 2);

        if (2 !== count($parts) || ! ctype_digit($parts[1])) {
            return null;
        }

        return [$parts[0], (int) $parts[1]];
    }

    /** The organizers' Stop link for $date's email. */
    public static function stopUrl(string $date): string
    {
        return add_query_arg(
            ['do' => 'stop', 'token' => self::stopToken($date)],
            rest_url(self::ROUTE)
        );
    }

    public static function stopToken(string $date): string
    {
        return SignedLink::sign(self::PURPOSE_STOP, (int) str_replace('-', '', $date));
    }

    /** The night a Stop token is for, or null when it does not verify. */
    public static function dateOfStopToken(string $token): ?string
    {
        $id = SignedLink::verify(self::PURPOSE_STOP, $token);

        if (null === $id) {
            return null;
        }

        $day = \DateTimeImmutable::createFromFormat('!Ymd', (string) $id);

        return false === $day ? null : $day->format('Y-m-d');
    }

    /**
     * One email: subject, the HTML content for the email template, the
     * plain-text alternative and the List-Unsubscribe headers of its "Don't
     * send me these" link. $kind and $rowId go into the counted links.
     *
     * @return array{subject: string, html: string, text: string, headers: list<string>}
     */
    public function compose(string $date, bool $came, string $name, string $kind, int $rowId): array
    {
        $shortDate = TicketMail::shortDate($date);
        $variant = $came ? FollowupText::CAME : FollowupText::MISSED;
        // Ticket holders have no stored language: every site language when
        // the Hybrid tick is on, else the main language (HybridPolicy).
        $languages = HybridPolicy::languagesFor(HybridPolicy::FOLLOWUP);
        $coming = $this->comingEvents($date);
        $hasEvents = [] !== $coming;
        $parts = [];
        $eventId = $this->eventId($date);

        foreach ($languages as $lang) {
            $text = $this->text->text($variant, $lang);
            $event = $this->eventName($date, $lang);
            $parts[$lang] = Language::run(
                $lang,
                static fn (): array => self::words($variant, $name, $text, $event, $shortDate, $hasEvents, $eventId)
            );
        }

        $both = static fn (string $key): string => HybridPolicy::join(HybridPolicy::field($parts, $key));
        // The join button and the opt-out link: one language per row.
        $pair = static fn (string $key): string => HybridPolicy::rows(HybridPolicy::field($parts, $key));

        $campaign = self::campaign($date, $came);
        $link = static fn (string $url): string => '' === $url
            ? ''
            : self::trackedUrl(self::withUtm($url, $campaign), $kind, $rowId);
        $accent = EmailTemplate::accent();

        // Each language's text starts with its FI / EN tag, when they differ.
        $bodies = HybridPolicy::field($parts, 'body');
        $html = HybridPolicy::bodiesHtml($bodies, $accent);
        $text = implode("\n\n", $bodies);

        $events = array_map(
            static fn (array $card): array => array_merge($card, [
                'url' => $link((string) ($card['url'] ?? '')),
                'ticket_url' => $link((string) ($card['ticket_url'] ?? '')),
                'listen' => $link((string) ($card['listen'] ?? '')),
            ]),
            $coming
        );

        // No coming events, no events part: the newsletter is not sent then.
        if ([] !== $events) {
            $coming = Newsletter::content(
                $this->withWritten($date, array_slice($events, 0, self::EVENTS)),
                array_slice($events, self::EVENTS),
                null,
                1 === count($languages) ? $languages[0] : '',
                true,
                HybridPolicy::FOLLOWUP
            );
            $html .= '<div style="margin:28px 0 0">' . $coming['html'] . '</div>';
            $text .= "\n\n" . $coming['text'];
        }

        if (Newsletter::enabled()) {
            $invites = HybridPolicy::field($parts, 'invite');
            $join = [[
                'label' => $pair('join'),
                // Not a counted link: the join link counts the click itself,
                // and subscribes this row's address (joinClick()).
                'url' => self::joinUrl($kind, $rowId, $campaign),
            ]];
            $html .= '<hr style="border:0;border-top:1px solid #e3e3e8;margin:8px 0 20px">'
                . (HybridPolicy::differ($invites)
                    ? implode('', array_map(
                        static fn (string $invite, string $tag): string
                            => EmailBody::toHtml($invite, [], $accent, $tag),
                        $invites,
                        HybridPolicy::tags($invites)
                    ))
                    : EmailBody::toHtml(implode("\n\n", $invites)))
                . EmailBody::centred(EmailBody::toHtml('', $join, $accent));
            $text .= "\n\n————————\n\n" . implode("\n\n", $invites)
                . "\n\n" . EmailBody::toText('', $join);
        }

        // Last, small: the link that puts this address on the do-not-send list.
        $optOut = self::optOutUrl($kind, $rowId);
        $html .= sprintf(
            '<p style="margin:28px 0 0;text-align:center;font-family:%s;font-size:13px;color:#5b6470">'
                . '<a href="%s" style="color:inherit;text-decoration:underline">%s</a></p>',
            EmailBody::FONT,
            esc_url($optOut),
            str_replace("\n", '<br>', esc_html($pair('optout')))
        );
        $text .= "\n\n" . $pair('optout') . ': ' . $optOut;

        return [
            'subject' => $both('subject') . ' – ' . $shortDate,
            'html' => $html,
            'text' => $text,
            'headers' => self::unsubscribeHeaders($optOut),
        ];
    }

    /** The footer line, in the post-event email's languages (HybridPolicy). */
    public static function footer(): string
    {
        $words = static fn (): string => __('You get this email because you had a ticket to this event.', 'eventcrew');

        return HybridPolicy::join(HybridPolicy::inEach(HybridPolicy::languagesFor(HybridPolicy::FOLLOWUP), $words));
    }

    /** The name the preview and the test greet. */
    public static function sampleName(): string
    {
        return __('Alex', 'eventcrew');
    }

    private function runLocked(\DateTimeImmutable $now): void
    {
        $state = $this->state();
        $today = $now->setTimezone(self::timezone())->format('Y-m-d');
        $dates = $this->rows->datesWithRecipients(
            self::addDays($today, -(self::LATE_DAYS + 1)),
            self::addDays($today, 1)
        );
        $at = $now->getTimestamp();

        foreach ($dates as $date) {
            if (isset($state['stopped'][$date])) {
                continue;
            }

            // An event whose day had begun before the setting went on is
            // never mailed, not even late.
            if (! isset($state['started'][$date]) && ! self::covers($date)) {
                continue;
            }

            $sendAt = self::sendAt($date)->getTimestamp();

            // A previewed night that never started and is now too late to
            // send: its one-time texts are not kept for a later night.
            if (
                isset($state['previewed'][$date])
                && ! isset($state['started'][$date])
                && ! isset($state['expired'][$date])
                && $at >= $sendAt + self::LATE_DAYS * DAY_IN_SECONDS
            ) {
                $state['expired'][$date] = true;
                $this->text->clearNext();
                continue;
            }

            if (! isset($state['previewed'][$date]) && $at >= self::previewAt($date)->getTimestamp() && $at < $sendAt) {
                $state = $this->writeNight($date, $state);
                $state['previewed'][$date] = true;
                $this->save($state);
                $this->sendPreview($date);
                continue;
            }

            if (
                isset($state['previewed'][$date])
                && ! isset($state['done'][$date])
                && $at >= $sendAt
                && ($at < $sendAt + self::LATE_DAYS * DAY_IN_SECONDS || isset($state['started'][$date]))
            ) {
                if (! isset($state['started'][$date])) {
                    // The one-time texts this night starts with; removed when it is done.
                    $state['texts'][$date] = $this->text->revisions();
                    // The first event's text, when the preview's run could not write it.
                    $state = $this->writeNight($date, $state);
                }

                $state['started'][$date] = true;
                $this->save($state);
                $state = $this->sendNight($date, $state);
            }
        }

        $this->save(self::pruned($state, $today));
    }

    /**
     * Sends up to BATCH of $date's emails, records each outcome, and asks
     * WP-Cron for another batch when some are left; marks the night done
     * when none are.
     *
     * @param array<string, array<string, mixed>> $state
     * @return array<string, array<string, mixed>>
     */
    private function sendNight(string $date, array $state): array
    {
        $checkedIn = $this->rows->checkedIn($date);
        $blocked = $this->optOut->blocked();
        $groups = [];

        foreach ($this->rows->recipients($date) as $row) {
            $key = function_exists('mb_strtolower') ? mb_strtolower($row['email']) : strtolower($row['email']);
            $groups[$key][] = $row;
        }

        $tried = 0;
        $failed = 0;
        $more = false;

        foreach ($groups as $group) {
            $handled = array_filter($group, static fn (array $row): bool => '' !== $row['status']);

            if ([] !== $handled) {
                // The address had its email already; a row added since gets none.
                foreach ($group as $row) {
                    if ('' === $row['status']) {
                        $this->rows->setStatus($row['kind'], $row['id'], self::STATUS_DUPLICATE);
                    }
                }

                continue;
            }

            // On the do-not-send list, or left the announcement: no email.
            if (isset($blocked[NewsletterSubscriberRepository::normalize($group[0]['email'])])) {
                foreach ($group as $row) {
                    $this->rows->setStatus($row['kind'], $row['id'], self::STATUS_OPTOUT);
                }

                continue;
            }

            if ($tried >= self::BATCH) {
                $more = true;
                break;
            }

            $came = false;

            foreach ($group as $row) {
                $came = $came || isset($checkedIn[$row['kind'] . ':' . $row['id']]);
            }

            $first = $group[0];
            $mail = $this->compose(
                $date,
                $came,
                $first['name'],
                FollowupRepository::KIND_GUEST === $first['kind'] ? 'g' : 'b',
                $first['id']
            );
            $sent = $this->mailer->toTicketHolder(
                $first['email'],
                $mail['subject'],
                $mail['html'],
                $mail['text'],
                self::footer(),
                false,
                '',
                $mail['headers'],
                HybridPolicy::languagesFor(HybridPolicy::FOLLOWUP)
            );
            $tried++;
            $failed += $sent ? 0 : 1;

            $this->rows->setStatus($first['kind'], $first['id'], $sent ? self::STATUS_SENT : self::STATUS_FAILED);

            foreach (array_slice($group, 1) as $row) {
                $this->rows->setStatus($row['kind'], $row['id'], self::STATUS_DUPLICATE);
            }
        }

        if ($tried > 0) {
            $this->log(sprintf('Post-event email for %s: %d tried, %d failed.', $date, $tried, $failed));
        }

        if ($more) {
            if (false === wp_next_scheduled(self::HOOK)) {
                wp_schedule_single_event(time(), self::HOOK);
            }
        } else {
            $state['done'][$date] = true;
            $this->text->clearIfRevisions(
                is_array($state['texts'][$date] ?? null) ? $state['texts'][$date] : $this->text->revisions()
            );
        }

        return $state;
    }

    /**
     * Mails every organizer one preview of $date's email with both versions
     * and the Stop button. Returns how many went.
     */
    private function sendPreview(string $date): int
    {
        $came = $this->compose($date, true, self::sampleName(), self::KIND_SAMPLE, 0);
        $missed = $this->compose($date, false, self::sampleName(), self::KIND_SAMPLE, 0);
        $cameNext = $this->text->hasNext(FollowupText::CAME);
        $missedNext = $this->text->hasNext(FollowupText::MISSED);
        $blocked = $this->optOut->blocked();
        $count = count(array_diff_key(array_flip(array_map(
            static fn (array $row): string => NewsletterSubscriberRepository::normalize($row['email']),
            $this->rows->recipients($date)
        )), $blocked));
        $sendAt = self::sendAt($date);
        $sentTo = 0;
        $adminUrl = HelpPage::url(DoorLinkSender::ADMIN_ANCHOR);

        foreach ($this->people->organizers() as $person) {
            $words = Language::forPerson($person, static fn (): array => [
                'subject' => Newsletter::PREVIEW_PREFIX . sprintf(
                    /* translators: %s: the date the post-event email goes out, like 15.11.2026 */
                    __('Post-event email preview: it goes out on %s', 'eventcrew'),
                    TicketMail::shortDate($sendAt->format('Y-m-d'))
                ),
                'intro' => sprintf(
                    /* translators: 1: event date, 14.11.2026, 2: send date, 3: time, 12:00, 4: number of addresses */
                    __('After the event on %1$s, the post-event email goes out on %2$s at %3$s to %4$d addresses: a thank-you to those who came, and a sorry-we-missed-you to those who did not. Both versions are below, with a sample name and the coming events as they are now. If it should not go out, stop it with the button at the end, or on the Roster page.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                    TicketMail::shortDate($date),
                    TicketMail::shortDate($sendAt->format('Y-m-d')),
                    $sendAt->format('H:i'),
                    $count
                ),
                'came' => __('To those who came', 'eventcrew'),
                'missed' => __('To those who did not come', 'eventcrew'),
                'subject_line' => __('Subject', 'eventcrew'),
                'stop' => __('Do not send the post-event email', 'eventcrew'),
                'admin' => DoorLinkSender::adminButtonLabel(),
                'text_next' => __('Text: a one-time text an organizer set for this email. After this send, the text on the Messages page is used again.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                'text_settings' => __('Text: the one on the Messages page. An organizer can set a one-time text for this email with /followup in the bot, or in the crew app’s Admin tab.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            ]);

            // The Messages-page sentence names the crew page's Admin tab: a
            // link to it follows, as a link rather than a second button. No
            // crew page, no link.
            $adminLink = static fn (string $which): bool => '' !== $adminUrl && $which === $words['text_settings'];
            $heading = static fn (string $label, string $subject, string $which): string => sprintf(
                '<p style="margin:0 0 16px;font-family:%s;font-size:13px;font-weight:700;letter-spacing:.04em;'
                    . 'text-transform:uppercase;color:#5b6470">%s</p>'
                    . '<p style="margin:0 0 8px;font-family:%s;font-size:15px;color:#1f2328">%s: %s</p>'
                    . '<p style="margin:0 0 20px;font-family:%s;font-size:13px;color:#5b6470">%s%s</p>',
                EmailBody::FONT,
                esc_html($label),
                EmailBody::FONT,
                esc_html($words['subject_line']),
                esc_html($subject),
                EmailBody::FONT,
                esc_html($which),
                $adminLink($which)
                    ? ' <a href="' . esc_url($adminUrl) . '" style="color:inherit;text-decoration:underline">'
                        . esc_html($words['admin']) . '</a>'
                    : ''
            );
            $whichText = static fn (string $which): string => $adminLink($which)
                ? $which . "\n" . $words['admin'] . ': ' . $adminUrl
                : $which;
            $cameWhich = $cameNext ? $words['text_next'] : $words['text_settings'];
            $missedWhich = $missedNext ? $words['text_next'] : $words['text_settings'];
            $html = $heading($words['came'], $came['subject'], $cameWhich) . $came['html']
                . '<hr style="border:0;border-top:3px solid #e3e3e8;margin:32px 0">'
                . $heading($words['missed'], $missed['subject'], $missedWhich) . $missed['html'];
            $text = mb_strtoupper($words['came']) . "\n" . $words['subject_line'] . ': ' . $came['subject'] . "\n"
                . $whichText($cameWhich) . "\n\n"
                . $came['text'] . "\n\n————————\n\n"
                . mb_strtoupper($words['missed']) . "\n" . $words['subject_line'] . ': ' . $missed['subject'] . "\n"
                . $whichText($missedWhich) . "\n\n"
                . $missed['text'];

            if (
                $this->mailer->toPersonWithContent(
                    $person->id,
                    $person->email,
                    $words['subject'],
                    $words['intro'],
                    $html,
                    $text,
                    [['label' => $words['stop'], 'url' => self::stopUrl($date)]]
                )
            ) {
                $sentTo++;
            }
        }

        $this->log(sprintf('Post-event email preview for %s sent to %d organizers.', $date, $sentTo));

        return $sentTo;
    }

    /**
     * Writes, once, the text and the practical information of the first
     * coming event for $date's email (NewsletterWriter::writeFollowup()) and
     * keeps them in $state['written'][$date] with the event's id, so the
     * preview and every recipient get the same. Written again only when the
     * first coming event has become another one, and never once the night
     * has started. Nothing is stored when nothing may be written now (not a
     * background run, no model, the switch off); a failed text is stored as
     * [] and not asked for again.
     *
     * @param array<string, array<string, mixed>> $state
     * @return array<string, array<string, mixed>>
     */
    private function writeNight(string $date, array $state): array
    {
        $first = array_values($this->comingEvents($date))[0] ?? null;

        if (isset($state['started'][$date]) || null === $first) {
            return $state;
        }

        $id = (int) ($first['id'] ?? 0);
        $stored = $state['written'][$date] ?? null;

        if (is_array($stored) && (int) ($stored['id'] ?? 0) === $id) {
            return $state;
        }

        $written = $this->writer->writeFollowup($first);

        if (null === $written) {
            return $state;
        }

        $state['written'][$date] = ['id' => $id] + $written;
        $this->save($state);

        return $state;
    }

    /**
     * $cards (the first coming event) with what was written for $date's
     * email (writeNight()) when it is for that event: its text in each
     * language (text_fi, text_en, text_sv …), and its practical information. Without a text or practical
     * information of its own, a card takes what the next events announcement
     * wrote and found for the event (Newsletter::writtenTexts()). A card
     * never shows the description.
     *
     * @param array<int, array<string, mixed>> $cards
     * @return array<int, array<string, mixed>>
     */
    private function withWritten(string $date, array $cards): array
    {
        if (! NewsletterWriter::enabled()) {
            return $cards;
        }

        $announced = Newsletter::writtenTexts();
        $cards = NewsletterWriter::apply($cards, ['practical' => $announced['practical']]);
        $written = $this->state()['written'][$date] ?? null;

        foreach ($cards as $index => $card) {
            $own = is_array($written) && (int) ($card['id'] ?? 0) === (int) ($written['id'] ?? 0);
            // This email's own text, else the one the announcement wrote for
            // the event ahead of time; neither is written here.
            $text = $own ? NewsletterWriter::followupText($written['text'] ?? null) : null;
            $text ??= NewsletterWriter::followupText($announced['events'][(int) ($card['id'] ?? 0)] ?? null);

            foreach ($text ?? [] as $lang => $words) {
                $cards[$index]['text_' . $lang] = $words;
            }

            if (! $own) {
                continue;
            }

            $info = NewsletterWriter::practicalOf($written['practical'] ?? null);

            if (null !== $info) {
                $cards[$index]['practical'] = $info;
            }
        }

        return $cards;
    }

    /**
     * Every coming event after $date, as the newsletter reads it
     * (Newsletter::comingEvents() and withCard()).
     *
     * @return array<int, array<string, mixed>>
     */
    private function comingEvents(string $date): array
    {
        if (! isset($this->events[$date])) {
            $this->events[$date] = array_map(
                [Newsletter::class, 'withCard'],
                Newsletter::comingEvents($date)
            );
        }

        return $this->events[$date];
    }

    /**
     * One language's words, composed in the current language. $text is the
     * email's text in that language (FollowupText::text()). The Messages page
     * text is the whole text; the one-time text and the built-in text get
     * the greeting and the sign-off around them. The subject is the Messages
     * page subject. $eventId is the night's event ({event_description}).
     *
     * @param array{text: string, next: bool, template: bool} $text
     * @return array{body: string, subject: string, join: string, invite: string, optout: string}
     */
    private static function words(
        string $variant,
        string $name,
        array $text,
        string $event,
        string $date,
        bool $hasEvents = true,
        ?int $eventId = null
    ): array {
        $id = FollowupText::TEMPLATES[$variant];
        $first = DoorDesk::firstName($name);
        $context = ['first_name' => $first, 'event' => $event, 'date' => $date, 'event_id' => $eventId];

        if ($text['template']) {
            // No first name: the line with {first_name} (the greeting) is
            // dropped, with the empty lines after it, so it never reads "Hi ,".
            $main = '' === $first
                ? (string) preg_replace('/^[^\n]*\{first_name\}[^\n]*\n?(?:[ \t]*\n)*/m', '', $text['text'])
                : $text['text'];
            $body = trim((string) MessageTemplates::find($id)?->fill($main, $context));
        } else {
            $hello = '' === $first
                ? __('Hi,', 'eventcrew')
                /* translators: %s: the ticket holder's first name */
                : sprintf(__('Hi %s,', 'eventcrew'), $first);

            $main = self::withEventDescription($text['text'], $eventId);
            $lines = [$hello, trim(FollowupText::fill($main, $event, $date))];
            $lines[] = __('Warmly,', 'eventcrew') . "\n" . Organization::name();
            $body = implode("\n\n", $lines);
        }

        if (! $hasEvents) {
            $body = self::withoutBelow($body);
        }

        return [
            'body' => $body,
            'subject' => MessageTemplates::subject($id, $context),
            'join' => __('Join the announcement', 'eventcrew'),
            'optout' => __("Don't send me these", 'eventcrew'),
            'invite' => __('Would you like to hear about new events first? Join the next events announcement: we email you when new events come out. One press of the button is enough, and you can leave at any time.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
        ];
    }

    /**
     * The one-time text or the built-in text ($text, not a Messages page
     * text) with the event's short description (EventDescription) as its own
     * paragraph: where the text has {event_description}, else after the
     * paragraph with {event} (as on the Messages page), else at the end.
     * Without a description, the text comes back without the placeholder.
     */
    private static function withEventDescription(string $text, ?int $eventId): string
    {
        $short = EventDescription::short($eventId);

        if ('' === $short) {
            return (string) preg_replace('/[ \t]*(?:\r?\n)*[ \t]*\{event_description\}/', '', $text);
        }

        $with = EventDetailsText::withEventDescription($text);

        if (! str_contains($with, '{event_description}')) {
            $with = rtrim($with) . "\n\n{event_description}";
        }

        return str_replace('{event_description}', $short, $with);
    }

    /**
     * $body without the sentence that points to the coming events below it,
     * for an email that has no coming events (in the current language).
     */
    private static function withoutBelow(string $body): string
    {
        $below = __('The next ones are below.', 'eventcrew');

        return str_replace([' ' . $below, $below . ' ', $below], '', $body);
    }

    /**
     * The EventMesh event of the night on $date: its first task's event, or
     * null when the night has no task of a linked event.
     */
    private function eventId(string $date): ?int
    {
        foreach ($this->tasks->forDate($date) as $task) {
            if (null !== $task->eventPostId && $task->eventPostId > 0) {
                return $task->eventPostId;
            }
        }

        return null;
    }

    /**
     * The name of the event on $date in $lang: its first task's event, by
     * its clean name (EventName) or else its title as written, or the site's
     * name when the night has no tasks.
     */
    private function eventName(string $date, string $lang): string
    {
        foreach ($this->tasks->forDate($date) as $task) {
            $name = trim($task->eventName());

            if ('' !== $name) {
                // The clean name in $lang (EventName), else the title as written.
                $clean = trim(EventName::ofTask($task, $lang)['name']);

                return '' === $clean ? $name : $clean;
            }
        }

        return (string) get_bloginfo('name');
    }

    /**
     * texts holds, per started night, the revisions of the one-time texts it
     * started with (FollowupText::revisions()); expired holds the previewed
     * nights that were left too late to send; written holds, per night, the
     * text and the practical information written for its first coming event
     * (writeNight()).
     *
     * @return array{
     *     previewed: array<string, bool>,
     *     stopped: array<string, bool>,
     *     started: array<string, bool>,
     *     done: array<string, bool>,
     *     texts: array<string, array<string, int>>,
     *     expired: array<string, bool>,
     *     written: array<string, array<string, mixed>>
     * }
     */
    private function state(): array
    {
        $stored = get_option(self::STATE_OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $state = [];

        foreach (['previewed', 'stopped', 'started', 'done', 'texts', 'expired', 'written'] as $key) {
            $state[$key] = is_array($stored[$key] ?? null) ? $stored[$key] : [];
        }

        return $state;
    }

    /** @param array<string, array<string, mixed>> $state */
    private function save(array $state): void
    {
        update_option(self::STATE_OPTION, $state, false);
    }

    /**
     * $state without the nights more than KEEP_DAYS before $today.
     *
     * @param array<string, array<string, mixed>> $state
     * @return array<string, array<string, mixed>>
     */
    private static function pruned(array $state, string $today): array
    {
        $cutoff = self::addDays($today, -self::KEEP_DAYS);

        foreach ($state as $key => $dates) {
            $state[$key] = array_filter(
                $dates,
                static fn (string|int $date): bool => (string) $date >= $cutoff,
                ARRAY_FILTER_USE_KEY
            );
        }

        return $state;
    }

    /**
     * Runs $work while holding the post-event email's MySQL lock, or not at
     * all when another request holds it.
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

    private function log(string $message): void
    {
        $this->logger?->info($message);
    }

    private static function atHour(string $ymd): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, self::timezone());

        if (false === $day) {
            $day = new \DateTimeImmutable('today', self::timezone());
        }

        return $day->setTime(self::SEND_HOUR, 0);
    }

    private static function timezone(): \DateTimeZone
    {
        return function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::timezone());
    }

    private static function addDays(string $ymd, int $days): string
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

        return false === $day ? $ymd : $day->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Support\EventSource;
use EventCrew\Support\FollowupMail;
use EventCrew\Support\HybridPolicy;
use EventCrew\Support\Logger;
use EventCrew\Support\ScheduledMessages;

/**
 * The thank-you card in the crew's Telegram channel (ChannelChat, Owner
 * 2026-11-14, Q399 and Q400): the day after each event night, at the
 * post-event email's send time (FollowupMail::sendAt()), one post with the
 * event's picture (the one its card shows), the email's thank-you to those
 * who came in each shown language (FollowupMail::thanksText(), Finnish and
 * English on this site) and one link button to the next event's tickets,
 * worded as the card's. No names, no counts.
 *
 * Once per night (STATE_OPTION). Like the email, a night is given up
 * FollowupMail::LATE_DAYS after its send time. Only nights with an event
 * (an EventMesh event on that date that is not unpublished, disabled or
 * hidden). It does not depend on the post-event email's switch; it has its
 * own (ScheduledMessages::THANKS).
 */
final class ChannelThanks
{
    /** The nights posted: Y-m-d => true, pruned after KEEP_DAYS. */
    public const STATE_OPTION = 'eventcrew_telegram_channel_thanks';

    /** How long a posted night is remembered, in days. */
    private const KEEP_DAYS = 30;

    /** The closed reasons that keep an event's night out, as EventCard::coming(). */
    private const HIDDEN_REASONS = ['unpublished', 'disabled', 'hidden'];

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly FollowupMail $followup,
        private readonly ?Logger $logger = null
    ) {
    }

    /**
     * The hourly run (Support\Scheduler): posts the thank-you of each night
     * whose send time has come and that was not posted yet, unless it is
     * LATE_DAYS past its send time. Only between BoardService::CARD_FROM_HOUR
     * and CARD_UNTIL_HOUR site time. Returns how many were posted.
     */
    public function run(?\DateTimeImmutable $now = null): int
    {
        if (! ScheduledMessages::isOn(ScheduledMessages::THANKS) || ! $this->canPost()) {
            return 0;
        }

        $now = ($now ?? new \DateTimeImmutable('now', self::timezone()))->setTimezone(self::timezone());
        $hour = (int) $now->format('G');

        if ($hour < BoardService::CARD_FROM_HOUR || $hour >= BoardService::CARD_UNTIL_HOUR) {
            return 0;
        }

        $posted = $this->posted();
        $at = $now->getTimestamp();
        $count = 0;

        foreach ($this->nights($now) as $date => $eventId) {
            $sendAt = FollowupMail::sendAt($date)->getTimestamp();

            if (
                isset($posted[$date])
                || $at < $sendAt
                || $at >= $sendAt + FollowupMail::LATE_DAYS * DAY_IN_SECONDS
            ) {
                continue;
            }

            if ($this->post($date, $eventId)) {
                $posted[$date] = true;
                ++$count;
            }
        }

        if ($count > 0) {
            $this->save($posted, $now);
        }

        return $count;
    }

    /**
     * The night "Send now" posts: the latest night before today within
     * LATE_DAYS + 1 days, posted already or not. '' when there is none.
     */
    public function sendNowNight(?\DateTimeImmutable $now = null): string
    {
        $nights = $this->nights(($now ?? new \DateTimeImmutable('now', self::timezone()))->setTimezone(self::timezone()));

        return [] === $nights ? '' : (string) array_key_last($nights);
    }

    /** Whether "Send now" has a channel and a night to post. */
    public function canSendNow(?\DateTimeImmutable $now = null): bool
    {
        return $this->canPost() && '' !== $this->sendNowNight($now);
    }

    /**
     * Posts sendNowNight()'s thank-you now (the organizer's "Send now"),
     * whatever the hour and also when it was posted already. Counts as that
     * night's post for run(). Returns whether it was posted.
     */
    public function sendNow(?\DateTimeImmutable $now = null): bool
    {
        if (! $this->canPost()) {
            return false;
        }

        $now = ($now ?? new \DateTimeImmutable('now', self::timezone()))->setTimezone(self::timezone());
        $nights = $this->nights($now);

        if ([] === $nights) {
            return false;
        }

        $date = (string) array_key_last($nights);

        if (! $this->post($date, $nights[$date])) {
            return false;
        }

        $posted = $this->posted();
        $posted[$date] = true;
        $this->save($posted, $now);

        return true;
    }

    /**
     * The post for $date's night: the picture of $eventId (as its card
     * shows it), the thank-you in each shown language, and the ticket
     * button of the next event.
     *
     * @return array{photo: string, caption: string, buttons: array<int, array<int, array{text: string, url: string}>>}
     */
    public function compose(string $date, int $eventId): array
    {
        $card = EventCard::forEvent($eventId, '');
        $ticket = $this->nextTicketButton();
        $texts = [];

        foreach (self::languages() as $lang) {
            $texts[$lang] = trim($this->followup->thanksText($date, $lang, $eventId, null !== $ticket));
        }

        $texts = array_filter($texts, static fn (string $text): bool => '' !== $text);
        $tags = HybridPolicy::tags($texts);
        $parts = [];

        foreach (array_unique($texts) as $lang => $text) {
            $escaped = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
            $parts[] = '' === $tags[$lang] ? $escaped : '<b>' . $tags[$lang] . "</b>\n" . $escaped;
        }

        $caption = implode("\n\n", $parts);
        $photo = null === $card ? '' : $card['photo'];

        // Longer than a photo's caption may be: a text message instead.
        if (EntityHtml::units(html_entity_decode(strip_tags($caption), ENT_QUOTES | ENT_HTML5, 'UTF-8')) > EventCard::CAPTION_LIMIT) {
            $photo = '';
        }

        return [
            'photo' => $photo,
            'caption' => $caption,
            'buttons' => null === $ticket ? [] : [[$ticket]],
        ];
    }

    private function post(string $date, int $eventId): bool
    {
        $sent = EventCard::send($this->telegram, ChannelChat::id(), $this->compose($date, $eventId));

        if (null === $sent) {
            $this->logger?->warning(sprintf('Thank-you card for %s not posted in the Telegram channel.', $date));

            return false;
        }

        $this->logger?->info(sprintf('Thank-you card for %s posted in the Telegram channel.', $date));

        return true;
    }

    private function canPost(): bool
    {
        return $this->telegram->isConfigured() && 0 !== ChannelChat::id();
    }

    /**
     * The event nights before today, LATE_DAYS + 1 days back at most,
     * earliest first: date => the night's event (the first one that day).
     *
     * @return array<string, int>
     */
    private function nights(\DateTimeImmutable $now): array
    {
        $today = $now->format('Y-m-d');
        $from = $now->modify(sprintf('-%d days', FollowupMail::LATE_DAYS + 1))->format('Y-m-d');
        $nights = [];

        foreach (EventSource::upcoming(-1) as $event) {
            $date = $event['date'];

            if ('' === $date || $date < $from || $date >= $today || isset($nights[$date])) {
                continue;
            }

            if (in_array(EventSource::closedReason($event['id']), self::HIDDEN_REASONS, true)) {
                continue;
            }

            $nights[$date] = $event['id'];
        }

        ksort($nights);

        return $nights;
    }

    /**
     * The ticket button of the first coming event (after today) that sells
     * tickets, as its card words it; null when there is none.
     *
     * @return array{text: string, url: string}|null
     */
    private function nextTicketButton(): ?array
    {
        foreach (EventCard::coming() as $card) {
            foreach ($card['buttons'] as $row) {
                $button = $row[0] ?? null;

                if (is_array($button) && str_starts_with((string) $button['text'], '🎟 ')) {
                    return $button;
                }
            }
        }

        return null;
    }

    /**
     * The languages of the post: the card's (HybridPolicy::shown(), Finnish
     * and English here), the main language alone when hybrid is not allowed.
     *
     * @return list<string>
     */
    private static function languages(): array
    {
        return HybridPolicy::isAllowed() ? HybridPolicy::shown() : [HybridPolicy::mainLanguage()];
    }

    /** @return array<string, bool> */
    private function posted(): array
    {
        $stored = get_option(self::STATE_OPTION, []);

        return is_array($stored) ? $stored : [];
    }

    /** @param array<string, bool> $posted */
    private function save(array $posted, \DateTimeImmutable $now): void
    {
        $cutoff = $now->modify(sprintf('-%d days', self::KEEP_DAYS))->format('Y-m-d');
        $posted = array_filter(
            $posted,
            static fn (string|int $date): bool => (string) $date >= $cutoff,
            ARRAY_FILTER_USE_KEY
        );

        update_option(self::STATE_OPTION, $posted, false);
    }

    private static function timezone(): \DateTimeZone
    {
        return function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
    }
}

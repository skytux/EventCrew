<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\Mailer;
use EventCrew\Support\SignedLink;
use EventCrew\Support\StandingCalculator;

/**
 * Self-service free entry: a person spends one of their earned credits on an
 * upcoming event and gets a door-ticket link back, without an organizer having
 * to redeem it for them.
 *
 * The eligibility and the spend live here, not in the bot, so the web profile's
 * "Redeem a credit" control shares exactly the same rules through redeem() -
 * there is one place that decides a credit may be spent and records it.
 */
final class TicketRedemptionService
{
    /** redeem() outcomes. */
    public const TICKET_READY = 'ticket_ready';
    public const NO_CREDIT = 'no_credit';
    public const ENTRY_CLOSED = 'entry_closed';
    public const ALREADY_REDEEMED = 'already_redeemed';
    public const NOT_ELIGIBLE = 'not_eligible';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly TaskRepository $tasks,
        private readonly RedemptionRepository $redemptions,
        private readonly StandingCalculator $standing,
        private readonly FreeEntryGate $freeEntry,
        private readonly TelegramClient $telegram,
        private readonly Mailer $mailer
    ) {
    }

    /**
     * /ticket in a DM: offer the upcoming events a credit can be spent on, as
     * tappable buttons, or explain why there is nothing to offer.
     */
    public function onTicketCommand(int $telegramUserId, int $chatId): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isEmailVerified()) {
            $this->telegram->sendMessage(
                $chatId,
                __('Set yourself up first with /start, then /ticket lets you spend a free-entry credit.', 'eventcrew')
            );

            return;
        }

        $balance = $this->standing->for($person->id)->creditBalance;

        if ($balance < 1) {
            $this->telegram->sendMessage(
                $chatId,
                __('You have no free-entry credits yet — you earn one for every two tasks you complete.', 'eventcrew')
            );

            return;
        }

        $dates = $this->eligibleDatesFor($person->id);

        if ([] === $dates) {
            $this->telegram->sendMessage(
                $chatId,
                __('No upcoming events to spend a credit on right now — check back when the next one is on the board.', 'eventcrew')
            );

            return;
        }

        $keyboard = [];

        foreach ($dates as $date => $label) {
            $keyboard[] = [['text' => $label, 'callback_data' => 'tkt:' . $date]];
        }

        $this->telegram->sendMessage(
            $chatId,
            sprintf(
                /* translators: %d: number of free-entry credits */
                _n(
                    'You have %d free-entry credit. Pick an event to spend it on:',
                    'You have %d free-entry credits. Pick an event to spend one on:',
                    $balance,
                    'eventcrew'
                ),
                $balance
            ),
            ['inline_keyboard' => $keyboard]
        );
    }

    /**
     * A tap on one of those event buttons: spend the credit (re-checking every
     * rule, never trusting the button) and DM the ticket link, or explain why
     * not.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onSelect(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $telegramUserId = (int) ($callbackQuery['from']['id'] ?? 0);
        $date = substr((string) ($callbackQuery['data'] ?? ''), strlen('tkt:'));

        $person = 0 === $telegramUserId ? null : $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isEmailVerified()) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('Set yourself up first with /start.', 'eventcrew'),
                true
            );

            return;
        }

        $result = $this->redeem($person->id, $date);

        if (self::TICKET_READY !== $result['code']) {
            $this->telegram->answerCallbackQuery($callbackId, $this->noticeText($result['code']), true);

            return;
        }

        // redeem() already DM'd (and emailed) the ticket link; the callback answer
        // just closes the tap so the button stops spinning.
        $this->telegram->answerCallbackQuery($callbackId, __('Credit spent — check your DM for the ticket.', 'eventcrew'));
    }

    /**
     * The one place a credit is spent, shared by the bot and the web profile.
     * Every guard is re-checked here so a stale button or page cannot overspend.
     *
     * @return array{code: string, url: string}
     */
    public function redeem(int $personId, string $date, string $note = 'self-service'): array
    {
        if (! $this->isUpcomingEventDate($date)) {
            return ['code' => self::NOT_ELIGIBLE, 'url' => ''];
        }

        if ($this->freeEntry->isClosed($date)) {
            return ['code' => self::ENTRY_CLOSED, 'url' => ''];
        }

        if ($this->alreadyRedeemed($personId, $date)) {
            return ['code' => self::ALREADY_REDEEMED, 'url' => ''];
        }

        if ($this->standing->for($personId)->creditBalance < 1) {
            return ['code' => self::NO_CREDIT, 'url' => ''];
        }

        [$eventPostId, $eventLabel] = $this->eventContext($date);
        $redemptionId = $this->redemptions->record($personId, $date, $eventPostId, $eventLabel, $note);
        $url = $this->ticketUrl($redemptionId);

        // The ticket link goes to both of the person's channels here, in the one
        // place a credit is spent, so a claim from the bot and a claim from the
        // web profile both leave the same email and DM record to fall back on.
        $this->deliverTicket($personId, $date, $url);

        return ['code' => self::TICKET_READY, 'url' => $url];
    }

    /**
     * Sends the just-claimed ticket link to the person's Telegram DM and email,
     * so they have it even if they close the page. Both sends are best-effort:
     * TicketRedemptionService already recorded the redemption, and a missed
     * confirmation must never undo a spent credit.
     */
    private function deliverTicket(int $personId, string $date, string $url): void
    {
        $person = $this->people->find($personId);

        if (null === $person) {
            return;
        }

        $when = $this->shortDate($date);

        if (null !== $person->telegramChatId && $person->wantsBotDms()) {
            $this->telegram->sendMessage(
                $person->telegramChatId,
                sprintf(
                    /* translators: 1: event date, 2: the ticket link */
                    __('🎟 Your free-entry ticket for %1$s — show this at the door:%2$s', 'eventcrew'),
                    $when,
                    "\n" . $url
                )
            );
        }

        if ($person->isDisabled()) {
            return;
        }

        $this->mailer->toPerson(
            $person->id,
            $person->email,
            __('Your free-entry ticket', 'eventcrew'),
            sprintf(
                /* translators: 1: name, 2: event date, 3: the ticket link */
                __("Hi %1\$s,\n\nHere is your free-entry ticket for %2\$s. Show it at the door:\n%3\$s", 'eventcrew'),
                $person->name(),
                $when,
                $url
            )
        );
    }

    /**
     * Upcoming event dates this person can still spend a credit on: has tasks,
     * not closed to free entry, and not already redeemed by them. Keyed by date
     * with a short label, soonest first.
     *
     * @return array<string, string> date (Y-m-d) => button label
     */
    public function eligibleDatesFor(int $personId): array
    {
        $redeemed = $this->redeemedDates($personId);
        $dates = [];

        foreach ($this->tasks->upcomingDates() as $date) {
            if ($this->freeEntry->isClosed($date) || isset($redeemed[$date])) {
                continue;
            }

            $dates[$date] = $this->dateLabel($date);
        }

        return $dates;
    }

    /**
     * The door-ticket URL for a redemption, signed so it cannot be forged and
     * reads the redemption's live state on each load.
     */
    public function ticketUrl(int $redemptionId): string
    {
        return add_query_arg(
            ['token' => SignedLink::sign('credit_ticket', $redemptionId)],
            rest_url('eventcrew/v1/ticket')
        );
    }

    /**
     * A human line for one of redeem()'s non-success outcomes.
     */
    public function noticeText(string $code): string
    {
        return match ($code) {
            self::NO_CREDIT => __('You have no free-entry credits to spend.', 'eventcrew'),
            self::ENTRY_CLOSED => __('Free entry is closed for that date.', 'eventcrew'),
            self::ALREADY_REDEEMED => __('You already have a free-entry ticket for that night.', 'eventcrew'),
            default => __('That event is no longer available.', 'eventcrew'),
        };
    }

    private function isUpcomingEventDate(string $date): bool
    {
        return in_array($date, $this->tasks->upcomingDates(), true);
    }

    private function alreadyRedeemed(int $personId, string $date): bool
    {
        return isset($this->redeemedDates($personId)[$date]);
    }

    /**
     * @return array<string, true> the dates this person already holds a redemption for
     */
    private function redeemedDates(int $personId): array
    {
        $dates = [];

        foreach ($this->redemptions->forPerson($personId) as $redemption) {
            if (null !== $redemption->redeemedFor) {
                $dates[$redemption->redeemedFor] = true;
            }
        }

        return $dates;
    }

    /**
     * The event a redemption on $date is recorded against: the first task's
     * event that day.
     *
     * @return array{0: ?int, 1: string}
     */
    private function eventContext(string $date): array
    {
        foreach ($this->tasks->forDate($date) as $task) {
            return [$task->eventPostId, $task->eventName()];
        }

        return [null, ''];
    }

    private function dateLabel(string $date): string
    {
        [, $eventLabel] = $this->eventContext($date);
        $when = $this->shortDate($date);

        return '' === $eventLabel ? $when : $when . ' · ' . $eventLabel;
    }

    private function shortDate(string $date): string
    {
        $timestamp = strtotime($date . ' 12:00:00');

        if (false === $timestamp) {
            return $date;
        }

        return function_exists('wp_date')
            ? (string) wp_date('D j M', $timestamp)
            : gmdate('D j M', $timestamp);
    }
}

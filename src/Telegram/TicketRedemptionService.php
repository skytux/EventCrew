<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Dates;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\Mailer;
use EventCrew\Support\NotificationPreferences;
use EventCrew\Support\ReputationSettings;
use EventCrew\Support\SignedLink;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\TicketMail;

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
    use GroupBreadcrumb;

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
        private readonly Mailer $mailer,
        private readonly ?TicketMail $ticketMail = null
    ) {
    }

    /**
     * 🎟 Free-entry ticket (a button under /me): offer the upcoming events a credit can be spent on, as
     * tappable buttons, or explain why there is nothing to offer.
     */
    public function onTicketCommand(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isEmailVerified()) {
            // A harmless nudge, so it goes back to wherever they asked.
            $this->telegram->sendMessage($chatId, SetupNudge::text(! $isPrivate));

            return;
        }

        // The rest names credits and offers spendable buttons, so it always goes
        // to the DM - never into the group - with a breadcrumb pointing there.
        $balance = $this->standing->for($person->id)->creditBalance;

        if ($balance < 1) {
            $perCredit = (new ReputationSettings())->tasksPerCredit();
            // phpcs:disable WordPress.WP.I18n.MissingSingularPlaceholder -- the singular is only used for 1.
            $this->telegram->sendMessage(
                $telegramUserId,
                sprintf(
                    /* translators: %d: tasks a person completes to earn one free-entry credit */
                    _n(
                        'You have no free-entry credits yet — you earn one for every task you complete.',
                        'You have no free-entry credits yet — you earn one for every %d tasks you complete.',
                        $perCredit,
                        'eventcrew'
                    ),
                    $perCredit
                )
            );
            // phpcs:enable WordPress.WP.I18n.MissingSingularPlaceholder
            $this->sentDmNote($chatId, $isPrivate);

            return;
        }

        $dates = $this->eligibleDatesFor($person->id);

        if ([] === $dates) {
            $this->telegram->sendMessage(
                $telegramUserId,
                __('No upcoming events to spend a credit on right now — check back when the next one is on the board.', 'eventcrew')
            );
            $this->sentDmNote($chatId, $isPrivate);

            return;
        }

        $keyboard = [];

        foreach ($dates as $date => $label) {
            $keyboard[] = [['text' => $label, 'callback_data' => 'tkt:' . $date]];
        }

        $this->telegram->sendMessage(
            $telegramUserId,
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
        $this->sentDmNote($chatId, $isPrivate);
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
            $this->telegram->answerCallbackQuery($callbackId, SetupNudge::text(), true);

            return;
        }

        $result = $this->redeem($person->id, $date);

        if (self::TICKET_READY !== $result['code']) {
            $this->telegram->answerCallbackQuery($callbackId, $this->noticeText($result['code']), true);

            return;
        }

        // redeem() already sent the ticket link on the channels the person
        // allows. The buttons only appear in the private chat, so when ticket
        // DMs are on, the ticket is the next message here; when they are off,
        // the person is pointed at /me tickets instead.
        $this->telegram->answerCallbackQuery(
            $callbackId,
            (new NotificationPreferences())->dmAllowed($person, NotificationPreferences::TICKET)
                ? __('Credit spent — your ticket is in the next message.', 'eventcrew')
                : __('Credit spent — send /me tickets to see your ticket.', 'eventcrew')
        );
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

        return $this->spend($personId, $date, $note);
    }

    /**
     * The door page's "Give ticket": the same spend as redeem(), for someone
     * standing at the door. It skips two of redeem()'s gates on purpose. The
     * free-entry closed gate is for people booking ahead, not for the door
     * crew deciding on the night. The upcoming-date check would refuse the
     * night itself once the clock passes midnight, while the door is still
     * open; the date comes from a signed door session, so it is a real night.
     * The credit balance and the one-per-night rule still apply.
     *
     * @return array{code: string, url: string}
     */
    public function redeemAtDoor(int $personId, string $date): array
    {
        return $this->spend($personId, $date, 'door');
    }

    /**
     * @return array{code: string, url: string}
     */
    private function spend(int $personId, string $date, string $note): array
    {
        if ($this->alreadyRedeemed($personId, $date)) {
            return ['code' => self::ALREADY_REDEEMED, 'url' => ''];
        }

        if ($this->standing->for($personId)->creditBalance < 1) {
            return ['code' => self::NO_CREDIT, 'url' => ''];
        }

        [$eventPostId, $eventLabel] = $this->eventContext($date);
        $redemptionId = $this->redemptions->record($personId, $date, $eventPostId, $eventLabel, $note);

        // 0 means the write found a redemption already there - a second request
        // that got past the check above while this one was in flight. The
        // answer is the same one the check would have given.
        if (0 === $redemptionId) {
            return ['code' => self::ALREADY_REDEEMED, 'url' => ''];
        }

        $url = $this->ticketUrl($redemptionId);

        // The ticket link goes to both of the person's channels here, in the one
        // place a credit is spent, so a claim from the bot and a claim from the
        // web profile both leave the same email and DM record to fall back on.
        $this->deliverTicket($personId, $date, $redemptionId);

        return ['code' => self::TICKET_READY, 'url' => $url];
    }

    /**
     * Sends the just-claimed ticket link to the person's Telegram DM and email,
     * so they have it even if they close the page, and then the "Your ticket"
     * email with the QR code (TicketMail), the same one ticket buyers get. All
     * sends are best-effort: TicketRedemptionService already recorded the
     * redemption, and a missed confirmation must never undo a spent credit.
     */
    private function deliverTicket(int $personId, string $date, int $redemptionId): void
    {
        $url = $this->ticketUrl($redemptionId);
        $person = $this->people->find($personId);

        if (null === $person) {
            return;
        }

        $when = $this->shortDate($date);
        $prefs = new NotificationPreferences();

        if ($prefs->dmAllowed($person, NotificationPreferences::TICKET)) {
            $this->telegram->sendMessage(
                $person->telegramChatId,
                sprintf(
                    /* translators: %s: event date */
                    __('🎟 Your free-entry ticket for %s — show it at the door.', 'eventcrew'),
                    $when
                ),
                ['inline_keyboard' => [[['text' => __('🎟 Show your ticket', 'eventcrew'), 'url' => $url]]]]
            );
        }

        if (! $prefs->emailAllowed($person, NotificationPreferences::TICKET)) {
            return;
        }

        $this->mailer->toPerson(
            $person->id,
            $person->email,
            __('Your free-entry ticket', 'eventcrew'),
            sprintf(
                /* translators: 1: name, 2: event date */
                __("Hi %1\$s,\n\nHere is your free-entry ticket for %2\$s. Show it at the door.", 'eventcrew'),
                $person->name(),
                $when
            ),
            [['label' => __('Show your ticket', 'eventcrew'), 'url' => $url]]
        );

        $this->ticketMail?->sendCreditTicket($person, $redemptionId, $date);
    }

    /**
     * Cancels a redemption (the credit goes back to the person) and, when its
     * ticket was emailed, emails the person that the ticket is cancelled. The
     * one place an organizer or the door desk undoes a spent credit.
     */
    public function cancelRedemption(int $redemptionId): void
    {
        $redemption = null === $this->ticketMail ? null : $this->redemptions->find($redemptionId);
        $this->redemptions->cancel($redemptionId);

        if (null === $redemption || $redemption->isCancelled() || null === $redemption->ticketMailedAt) {
            return;
        }

        $person = $this->people->find($redemption->personId);

        if (null !== $person) {
            $this->ticketMail?->crewCancelled(
                $person,
                $redemption->ticketMailedAt,
                (string) $redemption->redeemedFor
            );
        }
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
     * Sends the ticket link for an already-recorded redemption to the person, on
     * both channels they allow. Used when an organizer redeems a credit at the
     * door list, so the person gets the same DM + email the self-service flow
     * delivers.
     */
    public function notifyRedemption(int $personId, string $date, int $redemptionId): void
    {
        $this->deliverTicket($personId, $date, $redemptionId);
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
        return Dates::dayLabel($date);
    }
}

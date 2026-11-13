<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Dates;
use EventCrew\Support\EventName;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\Mailer;
use EventCrew\Support\MessageAiText;
use EventCrew\Support\NotificationPreferences;
use EventCrew\Support\PushNotifier;
use EventCrew\Support\ReputationSettings;
use EventCrew\Support\SeveralTickets;
use EventCrew\Support\SignedLink;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\TicketMail;
use EventCrew\Support\TicketTransferService;

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

    /** The start payload tkt-<Ymd> (MessageActions::startUrl()): one night to spend a credit on. */
    public const START_VERB = 'tkt';

    /** How many nights a credit message offers as buttons. */
    public const NIGHTS_MAX = 3;

    /** The longest picker button text before the emoji, in characters. */
    public const BUTTON_MAX = 58;

    /** Whether the last deliverTicket() sent the ticket as a DM. */
    private bool $ticketDmSent = false;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly TaskRepository $tasks,
        private readonly RedemptionRepository $redemptions,
        private readonly StandingCalculator $standing,
        private readonly FreeEntryGate $freeEntry,
        private readonly TelegramClient $telegram,
        private readonly Mailer $mailer,
        private readonly ?TicketMail $ticketMail = null,
        private readonly ?TicketTransferService $transfers = null
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
                ),
                MenuButtons::back(MenuButtons::TICKETS)
            );
            // phpcs:enable WordPress.WP.I18n.MissingSingularPlaceholder
            $this->sentDmNote($chatId, $isPrivate);

            return;
        }

        $dates = $this->eligibleDatesFor($person->id);

        if ([] === $dates) {
            $this->telegram->sendMessage(
                $telegramUserId,
                __('No upcoming events to spend a credit on right now — check back when the next one is on the board.', 'eventcrew'),
                MenuButtons::back(MenuButtons::TICKETS)
            );
            $this->sentDmNote($chatId, $isPrivate);

            return;
        }

        $keyboard = [];

        foreach (array_keys($dates) as $date) {
            $keyboard[] = [['text' => $this->pickerLabel($date), 'callback_data' => 'tkt:' . $date]];
        }

        $keyboard[] = MenuButtons::backRow(MenuButtons::TICKETS);

        $this->telegram->sendMessage($telegramUserId, self::pickerText($balance), ['inline_keyboard' => $keyboard]);
        $this->sentDmNote($chatId, $isPrivate);
    }

    /**
     * The link tkt-<Ymd> (a free-entry message's "Open in Telegram"): that
     * night's button, the same one as under 🎟 Free-entry ticket. It only
     * shows: the credit is spent when the button is tapped. A night that
     * cannot take a credit any more falls back to the whole picker, which
     * says why.
     */
    public function onNightLink(int $telegramUserId, int $chatId, string $ymd): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);
        $date = 1 === preg_match('/^(\d{4})(\d{2})(\d{2})$/', $ymd, $m) ? $m[1] . '-' . $m[2] . '-' . $m[3] : '';
        $balance = null === $person ? 0 : $this->standing->for($person->id)->creditBalance;
        $label = null === $person || $balance < 1 ? null : ($this->eligibleDatesFor($person->id)[$date] ?? null);

        if (null === $label) {
            $this->onTicketCommand($telegramUserId, $chatId);

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            self::pickerText($balance),
            ['inline_keyboard' => [[['text' => $this->pickerLabel($date), 'callback_data' => 'tkt:' . $date]]]]
        );
    }

    /**
     * The text above the event buttons (Owner, Q344 2026-11-07): a title line,
     * the credits, and what to do, each block apart.
     */
    public static function pickerText(int $balance): string
    {
        return '✨ ' . __('Free-entry ticket', 'eventcrew') . " ✨\n\n"
            . '🎟 ' . sprintf(
                /* translators: %d: free-entry credits */
                __('Free-entry credits: %d', 'eventcrew'),
                $balance
            ) . "\n\n"
            . __('Pick an event:', 'eventcrew');
    }

    /**
     * One event's button in the picker: "✨ Ecstatic Dance · Esimerkki ·
     * Sat 14 Nov", the event's name with its DJs (EventName::djLine()), then
     * the day (Owner, Q344 2026-11-07).
     */
    private function pickerLabel(string $date): string
    {
        [$eventId, $name] = $this->eventContext($date);

        $djs = null === $eventId ? '' : EventName::djLine($eventId);

        return '✨ ' . self::buttonText($name, $djs, $this->shortDate($date));
    }

    /**
     * "Name · DJs · day", at most BUTTON_MAX characters: a long DJ list is
     * cut with "…", and left out when not even a few of its letters fit.
     * The day alone when there is no name.
     */
    public static function buttonText(string $name, string $djs, string $when): string
    {
        if ('' === $name) {
            return $when;
        }

        $plain = $name . ' · ' . $when;

        if ('' === $djs) {
            return $plain;
        }

        $line = $name . ' · ' . $djs . ' · ' . $when;

        if (mb_strlen($line) <= self::BUTTON_MAX) {
            return $line;
        }

        // Room for the DJs: the whole minus the name, the day and two " · ".
        $room = self::BUTTON_MAX - mb_strlen($plain) - 3;

        if ($room < 5) {
            return $plain;
        }

        return $name . ' · ' . rtrim(mb_substr($djs, 0, $room - 1), " ,&") . '… · ' . $when;
    }

    /**
     * The next nights a credit can be spent on, for a free-entry message:
     * up to NIGHTS_MAX, date (Y-m-d) => label.
     *
     * @return array<string, string>
     */
    public function nextNights(int $personId): array
    {
        return array_slice($this->eligibleDatesFor($personId), 0, self::NIGHTS_MAX, true);
    }

    /**
     * A tap on one of those event buttons (tkt:<date>) asks first, in a new
     * message, what it costs (creditQuestion()). Its buttons: tkt:<date>:y
     * spends the credit (re-checking every rule, never trusting the button)
     * and DMs the ticket link, or explains why not; tkt:<date>:k keeps it.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onSelect(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $telegramUserId = (int) ($callbackQuery['from']['id'] ?? 0);
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chatId = (int) ($message['chat']['id'] ?? $telegramUserId);
        $messageId = (int) ($message['message_id'] ?? 0);
        $data = substr((string) ($callbackQuery['data'] ?? ''), strlen('tkt:'));
        [$date, $step] = array_pad(explode(':', $data, 2), 2, '');

        $person = 0 === $telegramUserId ? null : $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isEmailVerified()) {
            $this->telegram->answerCallbackQuery($callbackId, SetupNudge::text(), true);

            return;
        }

        if ('y' !== substr($step, 0, 1)) {
            $this->askCredit($callbackId, $person->id, $date, $chatId, $messageId, 'k' === $step);

            return;
        }

        // y<N>: the person held N tickets for the night when asked, so a
        // double-tap cannot buy two (a plain y, from before, is 0).
        $result = $this->redeem($person->id, $date, 'self-service', (int) substr($step, 1));

        if (self::TICKET_READY !== $result['code']) {
            $this->telegram->answerCallbackQuery($callbackId, $this->noticeText($result['code']), true);
            $this->closeQuestion($chatId, $messageId, $this->noticeText($result['code']));

            return;
        }

        $this->closeQuestion($chatId, $messageId, '✅ ' . sprintf(
            /* translators: %s: the night, like "Sat 14 Nov · Dance Night" */
            __('Credit spent on %s.', 'eventcrew'),
            $this->dateLabel($date)
        ));

        // redeem() already sent the ticket link on the channels the person
        // allows. The buttons only appear in the private chat, so when the
        // ticket went as a DM, it is the next message here; when it did not
        // (ticket DMs off, or the app only, ChannelChoice), the person is
        // pointed at /me tickets instead.
        $this->telegram->answerCallbackQuery(
            $callbackId,
            $this->ticketDmSent
                ? __('Credit spent — your ticket is in the next message.', 'eventcrew')
                : __('Credit spent — send /me tickets to see your ticket.', 'eventcrew')
        );
    }

    /**
     * The question before a credit is spent, or (when $keep) its "kept"
     * answer in place of the question. A date that can no longer take a
     * credit is said at once, with no question.
     */
    private function askCredit(
        string $callbackId,
        int $personId,
        string $date,
        int $chatId,
        int $messageId,
        bool $keep
    ): void {
        $balance = $this->standing->for($personId)->creditBalance;

        if ($keep) {
            $this->telegram->answerCallbackQuery($callbackId);
            $this->closeQuestion($chatId, $messageId, '👍 ' . sprintf(
                /* translators: %d: number of free-entry credits */
                _n('Kept: you have %d free-entry credit.', 'Kept: you have %d free-entry credits.', $balance, 'eventcrew'),
                $balance
            ));

            return;
        }

        $held = $this->heldFor($personId, $date);
        $refusal = $this->refusal($date, $balance, $held);

        if ('' !== $refusal) {
            $this->telegram->answerCallbackQuery($callbackId, $this->noticeText($refusal), true);

            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);
        // y<N> carries the tickets already held (redeem()); plain y is none.
        $yes = 'tkt:' . $date . ':y' . ($held > 0 ? $held : '');
        $this->telegram->sendMessage(
            $chatId,
            self::creditQuestion($this->dateLabel($date), $balance),
            ['inline_keyboard' => [
                [['text' => '✅ ' . __('Yes, use a credit', 'eventcrew'), 'callback_data' => $yes]],
                [['text' => '👍 ' . __('Keep my credit', 'eventcrew'), 'callback_data' => 'tkt:' . $date . ':k']],
            ]]
        );
    }

    /**
     * "Spend a free-entry credit on Sat 14 Nov · Dance Night?" and what it
     * costs. The same words on the web page's Get my ticket (where $night is
     * "%s", filled in with the chosen night).
     */
    public static function creditQuestion(string $night, int $balance): string
    {
        return sprintf(
            /* translators: %s: the night, like "Sat 14 Nov · Dance Night" */
            __('Spend a free-entry credit on %s?', 'eventcrew'),
            $night
        ) . "\n\n" . sprintf(
            // phpcs:disable WordPress.WP.I18n.MissingSingularPlaceholder -- the singular is only used for 1.
            /* translators: %d: the person's free-entry credits now */
            _n(
                'This uses your only free-entry credit.',
                'This uses 1 of your %d free-entry credits.',
                $balance,
                'eventcrew'
            ),
            // phpcs:enable WordPress.WP.I18n.MissingSingularPlaceholder
            $balance
        );
    }

    /** redeem()'s refusal for this date now, or '' when a credit can be spent on it. */
    private function refusal(string $date, int $balance, int $held): string
    {
        return match (true) {
            ! $this->isUpcomingEventDate($date) => self::NOT_ELIGIBLE,
            $this->freeEntry->isClosed($date) => self::ENTRY_CLOSED,
            $held > 0 && ! SeveralTickets::isOn() => self::ALREADY_REDEEMED,
            $balance < 1 => self::NO_CREDIT,
            default => '',
        };
    }

    /** The question's text becomes $text, and its buttons go. */
    private function closeQuestion(int $chatId, int $messageId, string $text): void
    {
        if (0 === $chatId || 0 === $messageId) {
            return;
        }

        $this->telegram->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => []]);
    }

    /**
     * The one place a credit is spent, shared by the bot and the web profile.
     * Every guard is re-checked here so a stale button or page cannot overspend.
     * $held is how many tickets for the night the person held when they asked
     * (SeveralTickets): another ticket is only bought when that is still true,
     * so a double-tap or two open pages cannot buy two.
     *
     * @return array{code: string, url: string}
     */
    public function redeem(int $personId, string $date, string $note = 'self-service', int $held = 0): array
    {
        if (! $this->isUpcomingEventDate($date)) {
            return ['code' => self::NOT_ELIGIBLE, 'url' => ''];
        }

        if ($this->freeEntry->isClosed($date)) {
            return ['code' => self::ENTRY_CLOSED, 'url' => ''];
        }

        return $this->spend($personId, $date, $note, SeveralTickets::isOn() ? $held : null);
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
        return $this->spend($personId, $date, 'door', null);
    }

    /**
     * $held null: one ticket per night. Otherwise another ticket, when the
     * person still holds exactly $held for the night (redeem()).
     *
     * @return array{code: string, url: string}
     */
    private function spend(int $personId, string $date, string $note, ?int $held): array
    {
        $count = $this->heldFor($personId, $date);

        if (null === $held ? $count > 0 : $count !== $held) {
            return ['code' => self::ALREADY_REDEEMED, 'url' => ''];
        }

        if ($this->standing->for($personId)->creditBalance < 1) {
            return ['code' => self::NO_CREDIT, 'url' => ''];
        }

        [$eventPostId, $eventLabel] = $this->eventContext($date);
        $redemptionId = $this->redemptions->record($personId, $date, $eventPostId, $eventLabel, $note, $count);

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
        $this->deliverTicket($personId, $date, $redemptionId, $eventPostId);

        return ['code' => self::TICKET_READY, 'url' => $url];
    }

    /**
     * Sends the just-claimed ticket link to the person's Telegram DM, so they
     * have it even if they close the page, and the "Your ticket" email with
     * the QR code (TicketMail), the same one ticket buyers and guests get.
     * Only without TicketMail does a plain email carry the link instead. All
     * sends are best-effort: TicketRedemptionService already recorded the
     * redemption, and a missed confirmation must never undo a spent credit.
     * $eventPostId is the night's event, for its AI text (MessageAiText).
     */
    private function deliverTicket(int $personId, string $date, int $redemptionId, ?int $eventPostId): void
    {
        $this->ticketDmSent = false;
        $url = $this->ticketUrl($redemptionId);
        $person = $this->people->find($personId);

        if (null === $person) {
            return;
        }

        $when = $this->shortDate($date);
        $prefs = new NotificationPreferences();

        $dmOk = $prefs->dmAllowed($person, NotificationPreferences::TICKET);
        $dmText = '';
        $pushed = 0;

        if ($dmOk || $prefs->pushAllowed($person, NotificationPreferences::TICKET)) {
            // The night's event and its times, for the DM's own lines.
            [$dayEventId, $eventName] = $this->eventContext($date);
            $context = [
                'person' => $person,
                'date' => $when,
                'event' => $eventName,
                'event_id' => $eventPostId ?? $dayEventId,
            ];
            // The Messages page template (MessageTemplates), made once for
            // the DM and the app (it may be AI text).
            $dmText = MessageAiText::body('free_ticket_dm', $context, $eventPostId);
            // A tap on the app notification opens the ticket.
            $pushed = PushNotifier::notify($person, NotificationPreferences::TICKET, $dmText, $url);
        }

        $this->ticketDmSent = $dmOk && $prefs->dmWanted($person, NotificationPreferences::TICKET, $pushed);

        if ($this->ticketDmSent) {
            // The ticket is a record: it stays in the chat as its own message.
            $this->telegram->record()->sendMessage(
                $person->telegramChatId,
                $dmText,
                ['inline_keyboard' => array_merge(
                    [[['text' => __('🎟 Show your ticket', 'eventcrew'), 'url' => $url]]],
                    // Giving the ticket away (TransferBot), when that is on.
                    null === $this->transfers || ! TicketTransferService::isOn()
                        ? []
                        : [TransferBot::giveRow($redemptionId)]
                )]
            );
        }

        // The one ticket email (it follows the same email choice itself).
        if (null !== $this->ticketMail) {
            $this->ticketMail->sendCreditTicket($person, $redemptionId, $date);

            return;
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
    }

    /**
     * What a member is told when they try to cancel their free-entry ticket,
     * in the current language. Members can't cancel one; while giving away is
     * on, they can give it to someone else. Organizers keep their tools.
     */
    public static function noCancelText(): string
    {
        return TicketTransferService::isOn()
            ? __('A free-entry ticket can’t be cancelled. You can give it to someone else.', 'eventcrew')
            : __('A free-entry ticket can’t be cancelled.', 'eventcrew');
    }

    /**
     * Cancels a redemption (the credit goes back to the person) and, when its
     * ticket was emailed, emails the person that the ticket is cancelled. The
     * one place an organizer undoes a spent credit; members can't
     * (noCancelText()).
     *
     * A ticket that was given away is the receiver's now: cancelling it
     * cancels the receiver's ticket (TicketTransferService::cancelIssued())
     * and the giver's credit stays spent. An offer waiting on the ticket
     * ends with it.
     *
     * True only when the database changed: the row was cancelled now (or,
     * for a given-away ticket, the receiver's ticket). A row that is gone or
     * already cancelled gives false, and nobody is emailed.
     */
    public function cancelRedemption(int $redemptionId): bool
    {
        $redemption = null === $this->ticketMail && null === $this->transfers
            ? null
            : $this->redemptions->find($redemptionId);

        if (null !== $this->transfers && null !== $redemption && $redemption->isTransferred()) {
            return $this->transfers->cancelIssued((int) $redemption->transferredTo);
        }

        if (! $this->redemptions->cancel($redemptionId)) {
            return false;
        }

        $this->transfers?->dropOffer($redemptionId);

        if (null === $redemption || $redemption->isCancelled() || null === $redemption->ticketMailedAt) {
            return true;
        }

        $person = $this->people->find($redemption->personId);

        if (null !== $person) {
            $this->ticketMail?->crewCancelled(
                $person,
                $redemption->ticketMailedAt,
                (string) $redemption->redeemedFor
            );
        }

        return true;
    }

    /**
     * Upcoming event dates this person can still spend a credit on: has tasks,
     * not closed to free entry, and not already redeemed by them (unless
     * SeveralTickets is on). Keyed by date with a short label, soonest first.
     *
     * @return array<string, string> date (Y-m-d) => button label
     */
    public function eligibleDatesFor(int $personId): array
    {
        return array_map(
            static fn (array $night): string => $night['label'],
            $this->eligibleNights($personId)
        );
    }

    /**
     * eligibleDatesFor() with how many tickets the person already holds for
     * each night, which the web page sends back with Get a ticket (redeem()).
     *
     * The label carries the event's DJs (EventName::djLine()) after the day
     * and name, as the bot's picker does (Owner 2026-11-13).
     *
     * @return array<string, array{label: string, held: int}>
     */
    public function eligibleNights(int $personId): array
    {
        $held = $this->heldCounts($personId);
        $several = SeveralTickets::isOn();
        $nights = [];

        foreach ($this->tasks->upcomingDates() as $date) {
            $count = $held[$date] ?? 0;

            if ($this->freeEntry->isClosed($date) || ($count > 0 && ! $several)) {
                continue;
            }

            // One lookup for both the name and the DJs (the tests script it).
            [$eventId, $eventLabel] = $this->eventContext($date);
            $djs = null === $eventId ? '' : EventName::djLine((int) $eventId);
            $label = $this->shortDate($date) . ('' === $eventLabel ? '' : ' · ' . $eventLabel)
                . ('' === $djs ? '' : ' · ' . $djs);
            $nights[$date] = ['label' => $label, 'held' => $count];
        }

        return $nights;
    }

    /**
     * Sends the ticket link for an already-recorded redemption to the person, on
     * both channels they allow. Used when an organizer redeems a credit at the
     * door list, so the person gets the same DM + email the self-service flow
     * delivers. $eventPostId is the event the redemption was recorded
     * against, for its AI text (MessageAiText).
     */
    public function notifyRedemption(int $personId, string $date, int $redemptionId, ?int $eventPostId = null): void
    {
        $this->deliverTicket($personId, $date, $redemptionId, $eventPostId);
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

    /** How many live free-entry tickets the person holds for $date. */
    private function heldFor(int $personId, string $date): int
    {
        return $this->heldCounts($personId)[$date] ?? 0;
    }

    /**
     * @return array<string, int> date => how many live tickets this person holds for it
     */
    private function heldCounts(int $personId): array
    {
        $dates = [];

        foreach ($this->redemptions->forPerson($personId) as $redemption) {
            if (null !== $redemption->redeemedFor) {
                $dates[$redemption->redeemedFor] = ($dates[$redemption->redeemedFor] ?? 0) + 1;
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

<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Models\Redemption;
use EventCrew\Models\TicketTransfer;
use EventCrew\Repositories\CheckinRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketTransferRepository;
use EventCrew\Telegram\TelegramClient;

/**
 * Giving a free-entry ticket away: the one place that decides a ticket may
 * be given and moves it, shared by the bot (TransferBot) and the web pages
 * (TransferPage).
 *
 * Only a credit ticket (a Redemption) can be given, once: the receiver's
 * ticket is a TicketTransfer and cannot be given on. To a member it moves at
 * once. To an email address it waits as a pending offer until the receiver
 * accepts it from the emailed link; the giver's ticket keeps working until
 * then, the giver may cancel the offer, and it lapses at door opening.
 *
 * When the ticket moves, the original is cancelled and linked to the
 * transfer (RedemptionRepository::markTransferred()): its QR code and link
 * stop working, and the credit stays spent. The receiver gets a ticket with
 * a new code.
 */
final class TicketTransferService
{
    public const OK = 'ok';
    public const NOT_GIVABLE = 'not_givable';
    public const PENDING = 'pending';
    public const SELF = 'self';
    public const BAD_EMAIL = 'bad_email';
    public const TOO_MANY = 'too_many';
    public const TOO_LATE = 'too_late';
    public const GONE = 'gone';
    public const NO_NAME = 'no_name';
    public const DISABLED = 'disabled';
    public const NO_THEIR_NAME = 'no_their_name';

    /** The longest name a transfer keeps. */
    public const NAME_MAX = 100;

    /**
     * The setting "Members can give away their ticket" (Settings, Ticket
     * page): '1' or '0', on when unset. Off, nothing offers giving a ticket
     * away and every give or accept is refused; tickets already given stay
     * valid.
     */
    public const OPTION = 'eventcrew_ticket_giving';

    /** Most pending offers to email addresses one person may have at a time. */
    public const MAX_EMAIL_OFFERS = 3;

    /** The door opening used for a night with no start time. */
    private const FALLBACK_OPENING = '17:00:00';

    /** The purpose the accept link is signed with. */
    public const ACCEPT_PURPOSE = 'xfer_accept';

    public function __construct(
        private readonly TicketTransferRepository $transfers,
        private readonly RedemptionRepository $redemptions,
        private readonly PersonRepository $people,
        private readonly TaskRepository $tasks,
        private readonly CheckinRepository $checkins,
        private readonly ?TicketMail $ticketMail = null,
        private readonly ?TelegramClient $telegram = null,
        private readonly ?SalesDeskGate $gate = null
    ) {
    }

    /** Whether members may give their tickets away now (the OPTION setting). */
    public static function isOn(): bool
    {
        return (bool) get_option(self::OPTION, true);
    }

    /** Saves the OPTION setting. */
    public static function save(bool $on): void
    {
        update_option(self::OPTION, $on ? '1' : '0');
    }

    /**
     * When the doors open on $date, as a local "Y-m-d H:i:s": the night's
     * start (SalesDeskGate::startOf()), else 17:00. A pending offer lapses
     * then.
     */
    public function lapseAt(string $date): string
    {
        $start = null === $this->gate ? '' : $this->gate->startOf($this->tasks->forDate($date));

        return '' === $start ? $date . ' ' . self::FALLBACK_OPENING : $start;
    }

    /**
     * Why this person cannot give this ticket now, or '' when they can: it
     * must be their own credit ticket, not cancelled or given, for tonight or
     * later, not used at the door, with no offer waiting. Nothing can be
     * given while the setting is off.
     */
    public function refusal(?Redemption $redemption, int $giverPersonId): string
    {
        if (! self::isOn()) {
            return self::DISABLED;
        }

        if (null === $redemption || $redemption->personId !== $giverPersonId || $giverPersonId <= 0) {
            return self::NOT_GIVABLE;
        }

        $date = (string) $redemption->redeemedFor;

        if ($redemption->isCancelled() || '' === $date || $date < (string) current_time('Y-m-d')) {
            return self::NOT_GIVABLE;
        }

        // This ticket's own check-in: a person may hold several for a night
        // (SeveralTickets) and give away one that was not used.
        [$entryKind, $entryRef] = $this->redemptions->checkinEntry($redemption);

        if (null !== $this->checkins->inAt($date, [$entryKind], $entryRef)) {
            return self::NOT_GIVABLE;
        }

        if (null !== $this->pendingOffer($redemption->id)) {
            return self::PENDING;
        }

        return '';
    }

    /** Whether the ticket can be given now (refusal() is ''). */
    public function canGive(?Redemption $redemption, int $giverPersonId): bool
    {
        return '' === $this->refusal($redemption, $giverPersonId);
    }

    /**
     * The offer waiting on this ticket, or null. An offer past door opening
     * is marked lapsed here, the first time anyone looks, and the ticket stays
     * with the giver.
     */
    public function pendingOffer(int $redemptionId): ?TicketTransfer
    {
        $offer = $this->transfers->pendingForRedemption($redemptionId);

        if (null === $offer) {
            return null;
        }

        if ((string) current_time('mysql') >= $this->lapseAt($offer->eventDate)) {
            $this->transfers->end($offer->id, TicketTransfer::STATUS_LAPSED, TicketTransfer::STATUS_PENDING);

            return null;
        }

        return $offer;
    }

    /**
     * Gives the ticket to a member at once. The receiver gets the ticket
     * email and a DM; the caller tells the giver.
     *
     * @return array{code: string, transfer: ?TicketTransfer}
     */
    public function giveToMember(int $redemptionId, int $giverPersonId, int $receiverPersonId): array
    {
        $redemption = $this->redemptions->find($redemptionId);
        $refusal = $this->refusal($redemption, $giverPersonId);

        if ('' !== $refusal || null === $redemption) {
            return ['code' => '' === $refusal ? self::NOT_GIVABLE : $refusal, 'transfer' => null];
        }

        if ($receiverPersonId === $giverPersonId) {
            return ['code' => self::SELF, 'transfer' => null];
        }

        $receiver = $this->people->find($receiverPersonId);

        if (null === $receiver || $receiver->isAnonymized() || $receiver->isBanned()) {
            return ['code' => self::GONE, 'transfer' => null];
        }

        $id = $this->transfers->create([
            'redemption_id' => $redemption->id,
            'event_date' => (string) $redemption->redeemedFor,
            'event_post_id' => $redemption->eventPostId,
            'giver_person_id' => $giverPersonId,
            'receiver_person_id' => $receiver->id,
            'name' => $receiver->name(),
            'email' => null,
            'language' => $receiver->language,
            'status' => TicketTransfer::STATUS_ISSUED,
        ]);

        if ($id <= 0) {
            return ['code' => self::GONE, 'transfer' => null];
        }

        // Two taps at once: only one of them cancels the original, and the
        // other's transfer is ended at once so no second ticket exists.
        if (! $this->redemptions->markTransferred($redemption->id, $id)) {
            $this->transfers->end($id, TicketTransfer::STATUS_CANCELLED, TicketTransfer::STATUS_ISSUED);

            return ['code' => self::NOT_GIVABLE, 'transfer' => null];
        }

        $transfer = $this->transfers->find($id) ?? $this->fallback($id, $redemption, $giverPersonId, $receiver);
        $this->deliver($transfer, $receiver);

        return ['code' => self::OK, 'transfer' => $transfer];
    }

    /**
     * Offers the ticket to an email address, for the person named $name.
     * The address must be real (not a placeholder), the name not empty, the
     * night's doors not yet open, and the giver may have at most
     * MAX_EMAIL_OFFERS offers waiting. An address that belongs to a member
     * gives the ticket to that member at once instead, and the name is not
     * needed.
     *
     * @return array{code: string, transfer: ?TicketTransfer}
     */
    public function offerToEmail(int $redemptionId, int $giverPersonId, string $email, string $name): array
    {
        $email = strtolower(trim($email));
        $redemption = $this->redemptions->find($redemptionId);
        $refusal = $this->refusal($redemption, $giverPersonId);

        if ('' !== $refusal || null === $redemption) {
            return ['code' => '' === $refusal ? self::NOT_GIVABLE : $refusal, 'transfer' => null];
        }

        if ('' === $email || ! is_email($email) || Person::isPlaceholderEmail($email)) {
            return ['code' => self::BAD_EMAIL, 'transfer' => null];
        }

        $member = $this->people->findByEmail($email);

        if (null !== $member) {
            return $this->giveToMember($redemptionId, $giverPersonId, $member->id);
        }

        $name = self::cleanName($name);

        if ('' === $name) {
            return ['code' => self::NO_THEIR_NAME, 'transfer' => null];
        }

        $date = (string) $redemption->redeemedFor;

        if ((string) current_time('mysql') >= $this->lapseAt($date)) {
            return ['code' => self::TOO_LATE, 'transfer' => null];
        }

        $waiting = array_filter(
            $this->transfers->pendingToEmailsBy($giverPersonId),
            fn (TicketTransfer $offer): bool => null !== $this->pendingOffer($offer->redemptionId)
        );

        if (count($waiting) >= self::MAX_EMAIL_OFFERS) {
            return ['code' => self::TOO_MANY, 'transfer' => null];
        }

        $id = $this->transfers->create([
            'redemption_id' => $redemption->id,
            'event_date' => $date,
            'event_post_id' => $redemption->eventPostId,
            'giver_person_id' => $giverPersonId,
            'receiver_person_id' => null,
            'name' => $name,
            'email' => $email,
            'language' => null,
            'status' => TicketTransfer::STATUS_PENDING,
        ]);

        if ($id <= 0) {
            return ['code' => self::GONE, 'transfer' => null];
        }

        $this->ticketMail?->sendTransferOffer(
            $email,
            $this->nameOf($giverPersonId),
            $date,
            self::acceptUrl($id),
            $name
        );

        return ['code' => self::OK, 'transfer' => $this->transfers->find($id)];
    }

    /**
     * The giver cancels their waiting offer. Only while it is pending: a
     * ticket that has moved cannot be taken back by the giver.
     */
    public function cancelOffer(int $redemptionId, int $giverPersonId): string
    {
        $offer = $this->pendingOffer($redemptionId);

        if (null === $offer || $offer->giverPersonId !== $giverPersonId) {
            return self::GONE;
        }

        return $this->transfers->end($offer->id, TicketTransfer::STATUS_CANCELLED, TicketTransfer::STATUS_PENDING)
            ? self::OK
            : self::GONE;
    }

    /**
     * Ends the offer waiting on this ticket, whoever asks: an organizer
     * cancelled the giver's ticket, so there is nothing left to give.
     */
    public function dropOffer(int $redemptionId): void
    {
        $offer = $this->transfers->pendingForRedemption($redemptionId);

        if (null !== $offer) {
            $this->transfers->end($offer->id, TicketTransfer::STATUS_CANCELLED, TicketTransfer::STATUS_PENDING);
        }
    }

    /**
     * The receiver accepts an offer from the emailed link, with their name
     * (the giver's, or as they corrected it).
     * The ticket moves now: the giver's ticket is cancelled, the receiver is
     * emailed their own, and the giver is told. Refused while the setting
     * is off: the offer waits and the giver's ticket keeps working.
     *
     * @return array{code: string, transfer: ?TicketTransfer}
     */
    public function accept(int $transferId, string $name): array
    {
        if (! self::isOn()) {
            return ['code' => self::DISABLED, 'transfer' => null];
        }

        $name = self::cleanName($name);

        if ('' === $name) {
            return ['code' => self::NO_NAME, 'transfer' => null];
        }

        $offer = $this->transfers->find($transferId);

        if (null === $offer || ! $offer->isPending() || null === $this->pendingOffer($offer->redemptionId)) {
            return ['code' => self::GONE, 'transfer' => null];
        }

        if (! $this->transfers->markIssued($offer->id, $name)) {
            return ['code' => self::GONE, 'transfer' => null];
        }

        if (! $this->redemptions->markTransferred($offer->redemptionId, $offer->id)) {
            // The giver's ticket was cancelled meanwhile: there is nothing to give.
            $this->transfers->end($offer->id, TicketTransfer::STATUS_CANCELLED, TicketTransfer::STATUS_ISSUED);

            return ['code' => self::GONE, 'transfer' => null];
        }

        $transfer = $this->transfers->find($offer->id) ?? new TicketTransfer(
            $offer->id,
            $offer->redemptionId,
            $offer->eventDate,
            $offer->eventPostId,
            $offer->giverPersonId,
            null,
            $name,
            $offer->email,
            $offer->language,
            TicketTransfer::STATUS_ISSUED,
            $offer->createdAt
        );

        $this->deliver($transfer, null);
        $this->tellGiverAccepted($transfer);

        return ['code' => self::OK, 'transfer' => $transfer];
    }

    /**
     * An organizer cancels a ticket that was given away (the Remove on the
     * door list, the door desk's return). The receiver's ticket stops
     * working and they are told; the giver's credit stays spent.
     */
    public function cancelIssued(int $transferId): bool
    {
        $transfer = $this->transfers->find($transferId);

        if (
            null === $transfer
            || ! $this->transfers->end($transfer->id, TicketTransfer::STATUS_CANCELLED, TicketTransfer::STATUS_ISSUED)
        ) {
            return false;
        }

        $receiver = null === $transfer->receiverPersonId ? null : $this->people->find($transfer->receiverPersonId);
        $date = $transfer->eventDate;

        if (null !== $transfer->ticketMailedAt) {
            $this->ticketMail?->sendCancelled(
                null === $receiver ? $transfer->name : $receiver->name(),
                null === $receiver ? $transfer->email : $receiver->email,
                $date,
                null,
                null === $receiver
                    ? ('' === $transfer->language ? null : $transfer->language)
                    : HybridPolicy::personLanguage($receiver)
            );
        }

        if (null !== $receiver) {
            $this->dm($receiver, fn (): string => sprintf(
                /* translators: %s: the night, like "Sat 14 Nov · Dance Night" */
                __('The organizers cancelled the free-entry ticket you were given for %s.', 'eventcrew'),
                $this->nightLabel($date)
            ));
        }

        return true;
    }

    /** The transfer $id, or null. */
    public function find(int $id): ?TicketTransfer
    {
        return $id > 0 ? $this->transfers->find($id) : null;
    }

    /** The credit ticket $id, or null. */
    public function redemption(int $id): ?Redemption
    {
        return $id > 0 ? $this->redemptions->find($id) : null;
    }

    /**
     * The given-away tickets a member holds (issued to them).
     *
     * @return list<TicketTransfer>
     */
    public function heldBy(int $personId): array
    {
        return $personId > 0 ? $this->transfers->heldBy($personId) : [];
    }

    /** The issued transfer a given-away credit ticket moved to, or null. */
    public function movedTo(Redemption $redemption): ?TicketTransfer
    {
        return null === $redemption->transferredTo ? null : $this->transfers->find($redemption->transferredTo);
    }

    /**
     * The receiver of a transfer: the member's name, or the name typed on
     * the accept page.
     */
    public function receiverName(TicketTransfer $transfer): string
    {
        $receiver = null === $transfer->receiverPersonId ? null : $this->people->find($transfer->receiverPersonId);

        return null === $receiver ? $transfer->name : $receiver->name();
    }

    /**
     * What the giver sees after the offer email went out, or after a
     * member's email address moved the ticket at once. Shared by the ticket
     * page's give form and the crew page's Tickets card (Owner 2026-11-08).
     */
    public function sentText(TicketTransfer $transfer): string
    {
        if ($transfer->isIssued()) {
            return sprintf(
                /* translators: %s: the receiver's name */
                __('Done. %s has your ticket now. Your own ticket no longer works.', 'eventcrew'),
                $this->receiverName($transfer)
            );
        }

        return sprintf(
            /* translators: %s: the name of the person the ticket was offered to */
            __('Sent. When %s accepts, the ticket moves to them. Until then your own ticket works.', 'eventcrew'),
            $transfer->offeredTo()
        );
    }

    /**
     * A typed name as a transfer keeps it: tags stripped, spaces collapsed,
     * at most NAME_MAX characters.
     */
    public static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', sanitize_text_field($name)) ?? '');

        return trim(mb_substr($name, 0, self::NAME_MAX));
    }

    /** The name of the person behind $personId, '' when not found. */
    public function nameOf(int $personId): string
    {
        $person = $personId > 0 ? $this->people->find($personId) : null;

        return null === $person ? '' : $person->name();
    }

    /** The link in the offer email: the accept page of transfer $transferId. */
    public static function acceptUrl(int $transferId): string
    {
        return add_query_arg(
            ['offer' => SignedLink::sign(self::ACCEPT_PURPOSE, $transferId)],
            rest_url('eventcrew/v1/ticket/accept')
        );
    }

    /** The transfer id in an accept link's token, or null. */
    public static function acceptId(string $token): ?int
    {
        return SignedLink::verify(self::ACCEPT_PURPOSE, $token);
    }

    /** "Sat 14 Nov · Dance Night": the night, as the bot names it. */
    public function nightLabel(string $date): string
    {
        $event = '';

        foreach ($this->tasks->forDate($date) as $task) {
            $event = $task->eventName();
            break;
        }

        $when = Dates::dayLabel($date);

        return '' === $event ? $when : $when . ' · ' . $event;
    }

    /**
     * A human line for one of the outcomes, in the current language.
     */
    public static function message(string $code): string
    {
        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literals; splitting them breaks extraction.
        return match ($code) {
            self::OK => __('Done.', 'eventcrew'),
            self::PENDING => __('This ticket is already offered to someone. Cancel that offer first.', 'eventcrew'),
            self::SELF => __('That is you. Pick someone else.', 'eventcrew'),
            self::BAD_EMAIL => __('That is not an email address. Write a name or an email address.', 'eventcrew'),
            self::TOO_MANY => __('You already have 3 offers waiting. Cancel one first.', 'eventcrew'),
            self::TOO_LATE => __('The doors are already open. Give the ticket to a member instead.', 'eventcrew'),
            self::NO_NAME => __('Please write your name.', 'eventcrew'),
            self::NO_THEIR_NAME => __('Write the name of the person who gets the ticket.', 'eventcrew'),
            self::GONE => __('This offer is no longer open.', 'eventcrew'),
            self::DISABLED => __('Giving tickets away is turned off.', 'eventcrew'),
            default => __('This ticket cannot be given away.', 'eventcrew'),
        };
        // phpcs:enable Generic.Files.LineLength.TooLong
    }

    /**
     * The receiver's ticket: the ticket email, and for a member a DM with
     * the ticket link. Best effort: the ticket has already moved.
     */
    private function deliver(TicketTransfer $transfer, ?Person $receiver): void
    {
        $from = $this->nameOf($transfer->giverPersonId);
        $this->ticketMail?->sendTransferTicket($transfer, $receiver, $from);

        if (null === $receiver) {
            return;
        }

        $text = fn (): string => sprintf(
            /* translators: 1: the giver's name, 2: the night, like "Sat 14 Nov · Dance Night" */
            __('%1$s gave you their free-entry ticket for %2$s.', 'eventcrew'),
            '' === $from ? __('Someone', 'eventcrew') : $from,
            $this->nightLabel($transfer->eventDate)
        );

        // A tap on the app notification opens the ticket.
        PushNotifier::notify(
            $receiver,
            NotificationPreferences::TICKET,
            (string) Language::forPerson($receiver, $text),
            TicketMail::transferUrl($transfer->id)
        );

        if (
            null === $this->telegram
            || ! (new NotificationPreferences())->dmAllowed($receiver, NotificationPreferences::TICKET)
        ) {
            return;
        }

        $this->dm(
            $receiver,
            $text,
            static fn (): array => ['inline_keyboard' => [[[
                'text' => __('🎟 Show your ticket', 'eventcrew'),
                'url' => TicketMail::transferUrl($transfer->id),
            ]]]],
            true
        );
    }

    /** After an accept: a DM to the giver that their ticket moved. */
    private function tellGiverAccepted(TicketTransfer $transfer): void
    {
        $giver = $this->people->find($transfer->giverPersonId);

        if (null === $giver) {
            return;
        }

        $this->dm($giver, fn (): string => sprintf(
            /* translators: 1: the receiver's name, 2: the night, like "Sat 14 Nov · Dance Night" */
            __('%1$s accepted your free-entry ticket for %2$s. Your own ticket no longer works.', 'eventcrew'),
            $transfer->name,
            $this->nightLabel($transfer->eventDate)
        ));
    }

    /**
     * An unprompted DM: framed (DmBody), its text and buttons written in the
     * person's language. Nothing without Telegram.
     *
     * @param callable(): string                     $body
     * @param (callable(): array<string, mixed>)|null $markup
     */
    private function dm(Person $person, callable $body, ?callable $markup = null, bool $record = false): void
    {
        if (null === $this->telegram || null === $person->telegramChatId) {
            return;
        }

        $client = $record ? $this->telegram->record() : $this->telegram;
        $chatId = $person->telegramChatId;

        Language::forPerson($person, static function () use ($client, $chatId, $person, $body, $markup): void {
            $client->sendMessage($chatId, DmBody::frame($person, $body()), null === $markup ? null : $markup());
        });
    }

    private function fallback(int $id, Redemption $redemption, int $giverId, Person $receiver): TicketTransfer
    {
        return new TicketTransfer(
            $id,
            $redemption->id,
            (string) $redemption->redeemedFor,
            $redemption->eventPostId,
            $giverId,
            $receiver->id,
            $receiver->name(),
            '',
            (string) $receiver->language,
            TicketTransfer::STATUS_ISSUED,
            (string) current_time('mysql')
        );
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Models\TicketTransfer;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\TicketTransferService;

/**
 * 🎁 Give away: the bot side of giving a free-entry ticket to someone else.
 *
 * The button sits on the credit ticket DM and under /me tickets. A tap asks
 * for a member's name or an email address; a name gets up to five matching
 * members as buttons, an email address of someone who is not a member gets
 * a short "What is their name?", then a ✅ / ✖️ confirm. A member gets the ticket at
 * once; an email address gets an offer to accept (TicketTransferService).
 * While an offer waits, ✖️ Cancel offer takes it back.
 *
 * Every answer is a reply to the person's own tap or message, so none is
 * framed (DmBody). The bot always answers in the DM.
 */
final class TransferBot
{
    /** Callback data prefix: gv:g:<redemption>, gv:p:<person>, gv:y, gv:n, gv:x:<redemption>. */
    public const PREFIX = 'gv:';

    /**
     * Holds the ticket being given while the bot waits for a name or an email
     * address: the credit ticket id, or ['rid' => id, 'email' => address]
     * while it waits for the name of the person at that address.
     */
    private const AWAIT_PREFIX = 'eventcrew_tg_await_give_';

    /** Holds the ticket and the chosen receiver between the pick and the ✅. */
    private const STATE_PREFIX = 'eventcrew_tg_give_';

    public function __construct(
        private readonly TicketTransferService $transfers,
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram
    ) {
    }

    /**
     * The 🎁 Give away row for credit ticket $redemptionId; $label, when
     * given, follows the words (the night, under /me tickets).
     *
     * @return list<array{text: string, callback_data: string}>
     */
    public static function giveRow(int $redemptionId, string $label = ''): array
    {
        return [[
            'text' => '🎁 ' . __('Give away', 'eventcrew') . ('' === $label ? '' : ' · ' . $label),
            'callback_data' => self::PREFIX . 'g:' . $redemptionId,
        ]];
    }

    /**
     * The ✖️ Cancel offer row for credit ticket $redemptionId.
     *
     * @return list<array{text: string, callback_data: string}>
     */
    public static function cancelRow(int $redemptionId, string $label = ''): array
    {
        return [[
            'text' => '✖️ ' . __('Cancel offer', 'eventcrew') . ('' === $label ? '' : ' · ' . $label),
            'callback_data' => self::PREFIX . 'x:' . $redemptionId,
        ]];
    }

    public function isAwaiting(int $telegramUserId): bool
    {
        return false !== get_transient(self::AWAIT_PREFIX . $telegramUserId);
    }

    /** The Cancel button: forgets the question. True when it was waiting. */
    public function cancelPending(int $telegramUserId): bool
    {
        $waiting = $this->isAwaiting($telegramUserId);
        delete_transient(self::AWAIT_PREFIX . $telegramUserId);
        delete_transient(self::STATE_PREFIX . $telegramUserId);

        return $waiting;
    }

    /**
     * @param array<string, mixed> $callbackQuery
     */
    public function onSelect(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $telegramUserId = (int) ($callbackQuery['from']['id'] ?? 0);
        $data = substr((string) ($callbackQuery['data'] ?? ''), strlen(self::PREFIX));
        $giver = 0 === $telegramUserId ? null : $this->people->findByTelegramUserId($telegramUserId);

        if (null === $giver) {
            $this->telegram->answerCallbackQuery($callbackId, TicketTransferService::message(TicketTransferService::NOT_GIVABLE), true);

            return;
        }

        [$action, $id] = array_pad(explode(':', $data, 2), 2, '0');

        // An old 🎁 Give away button, or a give half done when the setting
        // was turned off: refused. Cancelling a waiting offer still works.
        if (in_array($action, ['g', 'p', 'y'], true) && ! TicketTransferService::isOn()) {
            $this->cancelPending($telegramUserId);
            $this->telegram->answerCallbackQuery($callbackId);
            $this->telegram->sendMessage(
                $telegramUserId,
                TicketTransferService::message(TicketTransferService::DISABLED)
            );

            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        match ($action) {
            'g' => $this->start($giver, $telegramUserId, (int) $id),
            'p' => $this->pick($giver, $telegramUserId, (int) $id),
            'y' => $this->confirm($giver, $telegramUserId),
            'n' => $this->keep($telegramUserId),
            'x' => $this->cancelOffer($giver, $telegramUserId, (int) $id),
            default => null,
        };
    }

    /** 🎁 Give away: checks the ticket, then asks who gets it. */
    private function start(Person $giver, int $chatId, int $redemptionId): void
    {
        if (! $this->opens($giver, $chatId, $redemptionId)) {
            return;
        }

        set_transient(self::AWAIT_PREFIX . $chatId, $redemptionId, 15 * MINUTE_IN_SECONDS);
        $this->telegram->sendMessage($chatId, TransferPage::askText(), MenuButtons::cancel());
    }

    /**
     * Whether ticket $redemptionId can be given now; when not, says why
     * (with ✖️ Cancel offer while an offer waits). Forgets a give half done.
     */
    private function opens(Person $giver, int $chatId, int $redemptionId): bool
    {
        $this->cancelPending($chatId);
        $redemption = $this->transfers->redemption($redemptionId);
        $refusal = $this->transfers->refusal($redemption, $giver->id);

        if ('' === $refusal) {
            return true;
        }

        $this->telegram->sendMessage(
            $chatId,
            TicketTransferService::message($refusal),
            TicketTransferService::PENDING === $refusal ? ['inline_keyboard' => [self::cancelRow($redemptionId)]] : null
        );

        return false;
    }

    // parityB: /giveaway (ParityCommands), the typed form of 🎁 Give away.

    /**
     * /giveaway with the receiver already written: as 🎁 Give away and then
     * the answer to "Who gets your ticket?". An email address of someone who
     * is not a member, with $theirName, goes straight to the ✅ / ✖️
     * confirm. Without $who the bot asks, as the button does.
     */
    public function begin(Person $giver, int $chatId, int $redemptionId, string $who, string $theirName = ''): void
    {
        if (! $this->opens($giver, $chatId, $redemptionId)) {
            return;
        }

        $who = trim($who);

        if ('' === $who) {
            set_transient(self::AWAIT_PREFIX . $chatId, $redemptionId, 15 * MINUTE_IN_SECONDS);
            $this->telegram->sendMessage($chatId, TransferPage::askText(), MenuButtons::cancel());

            return;
        }

        $email = strtolower($who);

        if ('' !== trim($theirName) && is_email($email) && null === $this->people->findByEmail($email)) {
            self::awaitName($chatId, $redemptionId, $email);
            $this->capture($chatId, $chatId, $theirName);

            return;
        }

        set_transient(self::AWAIT_PREFIX . $chatId, $redemptionId, 15 * MINUTE_IN_SECONDS);
        $this->capture($chatId, $chatId, $who);
    }

    /** /giveaway cancel: takes back the waiting offer of ticket $redemptionId. */
    public function cancelFor(Person $giver, int $chatId, int $redemptionId): void
    {
        $this->cancelOffer($giver, $chatId, $redemptionId);
    }

    /**
     * The reply to "Who gets your ticket?": a member's email address goes
     * straight to the confirm, another address to "What is their name?"; a
     * name gets the matching members as buttons. The reply to "What is their
     * name?" goes to the confirm.
     *
     * @param array<int, array<string, mixed>> $entities Telegram message entities.
     */
    public function capture(int $telegramUserId, int $chatId, string $text, array $entities = []): void
    {
        $waiting = get_transient(self::AWAIT_PREFIX . $telegramUserId);
        $redemptionId = is_array($waiting) ? (int) ($waiting['rid'] ?? 0) : (int) $waiting;
        delete_transient(self::AWAIT_PREFIX . $telegramUserId);
        $giver = $this->people->findByTelegramUserId($telegramUserId);
        $redemption = $this->transfers->redemption($redemptionId);

        if (null === $giver || null === $redemption) {
            $this->telegram->sendMessage($chatId, self::expired());

            return;
        }

        if (! TicketTransferService::isOn()) {
            delete_transient(self::STATE_PREFIX . $telegramUserId);
            $this->telegram->sendMessage($chatId, TicketTransferService::message(TicketTransferService::DISABLED));

            return;
        }

        $night = $this->transfers->nightLabel((string) $redemption->redeemedFor);
        $text = trim($text);

        if (is_array($waiting) && '' !== (string) ($waiting['email'] ?? '')) {
            $this->captureName($telegramUserId, $chatId, $redemptionId, (string) $waiting['email'], $text, $night);

            return;
        }

        if (str_contains($text, '@') && ! str_starts_with($text, '@')) {
            $email = strtolower($text);

            if (! is_email($email) || Person::isPlaceholderEmail($email)) {
                $this->askAgain($telegramUserId, $chatId, $redemptionId, TicketTransferService::BAD_EMAIL);

                return;
            }

            $member = $this->people->findByEmail($email);

            if (null !== $member && $member->id === $giver->id) {
                $this->askAgain($telegramUserId, $chatId, $redemptionId, TicketTransferService::SELF);

                return;
            }

            if (null !== $member) {
                $this->askConfirm($telegramUserId, $chatId, ['rid' => $redemptionId, 'person' => $member->id], TransferPage::confirmMemberText($member->name(), $night));

                return;
            }

            self::awaitName($telegramUserId, $redemptionId, $email);
            $this->telegram->sendMessage($chatId, self::nameText(), MenuButtons::cancel());

            return;
        }

        $matches = TransferPage::matches($this->people, $text, $giver->id, $entities);

        if ([] === $matches) {
            set_transient(self::AWAIT_PREFIX . $telegramUserId, $redemptionId, 15 * MINUTE_IN_SECONDS);
            $this->telegram->sendMessage($chatId, TransferPage::noMatchText(), MenuButtons::cancel());

            return;
        }

        set_transient(self::STATE_PREFIX . $telegramUserId, ['rid' => $redemptionId], 15 * MINUTE_IN_SECONDS);
        $buttons = array_map(
            static fn (Person $person): array => [[
                'text' => $person->name(),
                'callback_data' => self::PREFIX . 'p:' . $person->id,
            ]],
            $matches
        );

        $this->telegram->sendMessage(
            $chatId,
            __('Who gets your ticket?', 'eventcrew'),
            ['inline_keyboard' => array_merge($buttons, [MenuButtons::cancelRow()])]
        );
    }

    /** "What is their name?": asked after the email address of someone who is not a member. */
    public static function nameText(): string
    {
        return __('What is their name?', 'eventcrew');
    }

    /** The reply to "What is their name?": the confirm, or the question again when it is empty. */
    private function captureName(
        int $telegramUserId,
        int $chatId,
        int $redemptionId,
        string $email,
        string $text,
        string $night
    ): void {
        $name = TicketTransferService::cleanName($text);

        if ('' === $name) {
            self::awaitName($telegramUserId, $redemptionId, $email);
            $this->telegram->sendMessage(
                $chatId,
                TicketTransferService::message(TicketTransferService::NO_THEIR_NAME),
                MenuButtons::cancel()
            );

            return;
        }

        $this->askConfirm(
            $telegramUserId,
            $chatId,
            ['rid' => $redemptionId, 'email' => $email, 'name' => $name],
            TransferPage::confirmEmailText($name . ' (' . $email . ')', $night)
        );
    }

    /** Waits for the name of the person at $email. */
    private static function awaitName(int $telegramUserId, int $redemptionId, string $email): void
    {
        set_transient(
            self::AWAIT_PREFIX . $telegramUserId,
            ['rid' => $redemptionId, 'email' => $email],
            15 * MINUTE_IN_SECONDS
        );
    }

    /** A member button: the ✅ / ✖️ confirm. */
    private function pick(Person $giver, int $chatId, int $personId): void
    {
        $state = get_transient(self::STATE_PREFIX . $chatId);
        $member = $this->people->find($personId);
        $redemption = is_array($state) ? $this->transfers->redemption((int) ($state['rid'] ?? 0)) : null;

        if (null === $member || null === $redemption || $redemption->personId !== $giver->id) {
            $this->telegram->sendMessage($chatId, self::expired());

            return;
        }

        $this->askConfirm(
            $chatId,
            $chatId,
            ['rid' => $redemption->id, 'person' => $member->id],
            TransferPage::confirmMemberText($member->name(), $this->transfers->nightLabel((string) $redemption->redeemedFor))
        );
    }

    /**
     * @param array{rid: int, person?: int, email?: string, name?: string} $state
     */
    private function askConfirm(int $telegramUserId, int $chatId, array $state, string $question): void
    {
        set_transient(self::STATE_PREFIX . $telegramUserId, $state, 15 * MINUTE_IN_SECONDS);
        $this->telegram->sendMessage($chatId, $question, ['inline_keyboard' => [[
            ['text' => '✅ ' . __('Give', 'eventcrew'), 'callback_data' => self::PREFIX . 'y'],
            ['text' => '✖️ ' . __('Keep it', 'eventcrew'), 'callback_data' => self::PREFIX . 'n'],
        ]]]);
    }

    /** ✅: gives the ticket to the member, or sends the email offer. */
    private function confirm(Person $giver, int $chatId): void
    {
        $state = get_transient(self::STATE_PREFIX . $chatId);
        delete_transient(self::STATE_PREFIX . $chatId);

        if (! is_array($state) || empty($state['rid']) || (empty($state['person']) && empty($state['email']))) {
            $this->telegram->sendMessage($chatId, self::expired());

            return;
        }

        $redemptionId = (int) $state['rid'];
        $name = (string) ($state['name'] ?? '');
        $result = empty($state['person'])
            ? $this->transfers->offerToEmail($redemptionId, $giver->id, (string) $state['email'], $name)
            : $this->transfers->giveToMember($redemptionId, $giver->id, (int) $state['person']);
        $transfer = $result['transfer'];

        if (TicketTransferService::OK !== $result['code'] || null === $transfer) {
            $this->telegram->sendMessage($chatId, TicketTransferService::message($result['code']));

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            $this->doneText($transfer),
            $transfer->isPending() ? ['inline_keyboard' => [self::cancelRow($redemptionId)]] : null
        );
    }

    /** The short confirmation after ✅. */
    private function doneText(TicketTransfer $transfer): string
    {
        return self::doneFor($this->transfers, $transfer);
    }

    /** The confirmation after ✅, also for the Crew app's helper box (parityB). */
    public static function doneFor(TicketTransferService $transfers, TicketTransfer $transfer): string
    {
        $night = $transfers->nightLabel($transfer->eventDate);

        if ($transfer->isIssued()) {
            return sprintf(
                /* translators: 1: the receiver's name, 2: the night, like "Sat 14 Nov · Dance Night" */
                __('Done. %1$s has your ticket for %2$s now. Your own ticket no longer works.', 'eventcrew'),
                $transfers->receiverName($transfer),
                $night
            );
        }

        return sprintf(
            /* translators: %s: the name of the person the ticket was offered to */
            __('Sent. When %s accepts, the ticket moves to them. Until then your own ticket works.', 'eventcrew'),
            $transfer->offeredTo()
        );
    }

    /** ✖️ Keep it. */
    private function keep(int $chatId): void
    {
        $this->cancelPending($chatId);
        $this->telegram->sendMessage($chatId, __('Kept. The ticket stays with you.', 'eventcrew'));
    }

    /** ✖️ Cancel offer. */
    private function cancelOffer(Person $giver, int $chatId, int $redemptionId): void
    {
        $code = $this->transfers->cancelOffer($redemptionId, $giver->id);

        $this->telegram->sendMessage(
            $chatId,
            TicketTransferService::OK === $code
                ? __('Offer cancelled. Your ticket is yours again.', 'eventcrew')
                : TicketTransferService::message($code)
        );
    }

    private function askAgain(int $telegramUserId, int $chatId, int $redemptionId, string $code): void
    {
        set_transient(self::AWAIT_PREFIX . $telegramUserId, $redemptionId, 15 * MINUTE_IN_SECONDS);
        $this->telegram->sendMessage($chatId, TicketTransferService::message($code), MenuButtons::cancel());
    }

    private static function expired(): string
    {
        return __('This request has expired. Please send it again.', 'eventcrew');
    }
}

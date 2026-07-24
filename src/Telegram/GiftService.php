<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\CreditGrantNotifier;
use EventCrew\Support\StandingCalculator;

/**
 * /gift - an organizer hands someone a free-entry credit at their discretion,
 * from the bot, the same one-credit grant the People page offers.
 *
 * The flow mirrors /replace: /gift asks who, the organizer sends a name or
 * @mention, the bot offers the matches as buttons, and a tap records the grant.
 * It is organizer-only and always answers in the DM - who is being handed
 * credits is not group business - with a breadcrumb when asked in a group.
 */
final class GiftService
{
    use GroupBreadcrumb;

    private const AWAIT_PREFIX = 'eventcrew_tg_await_gift_target_';

    /** Holds the parsed amount + note between naming someone and tapping them. */
    private const AMOUNT_PREFIX = 'eventcrew_tg_gift_amount_';

    /** Callback data prefix for a gift pick: gift:<person_id>. */
    private const PICK_PREFIX = 'gift:';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly CreditGrantRepository $grants,
        private readonly StandingCalculator $standing,
        private readonly TelegramClient $telegram,
        private readonly CreditGrantNotifier $notifier
    ) {
    }

    /**
     * /gift: asks the organizer who should get a credit. Refuses anyone who is
     * not a linked organizer.
     */
    public function start(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        $organizer = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $organizer || ! $organizer->isOrganizer) {
            // A harmless refusal, so it goes back to wherever they asked.
            $this->telegram->sendMessage(
                $chatId,
                __('Only organizers can give out free-entry credits.', 'eventcrew')
            );

            return;
        }

        set_transient(self::AWAIT_PREFIX . $telegramUserId, true, 15 * MINUTE_IN_SECONDS);
        $this->telegram->sendMessage(
            $telegramUserId,
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __('Who should get a free-entry credit? Send their name (or @mention). For more than one, add a number — "Sam 3" — and any words after it become the note.', 'eventcrew')
        );
        $this->sentDmNote($chatId, $isPrivate);
    }

    public function isAwaitingTarget(int $telegramUserId): bool
    {
        return false !== get_transient(self::AWAIT_PREFIX . $telegramUserId);
    }

    /**
     * The reply naming who to gift. Resolves them and offers the matches as
     * buttons; a tap on one records the grant.
     *
     * @param array<int, array<string, mixed>> $entities Telegram message entities.
     */
    public function captureTarget(int $telegramUserId, int $chatId, string $text, array $entities): void
    {
        delete_transient(self::AWAIT_PREFIX . $telegramUserId);

        // Pull a trailing "<n> [note]" off the reply so it is not searched as
        // part of the name; the amount and note ride a short-lived transient to
        // the tap, since callback data can't safely carry free text.
        [$query, $credits, $note] = $this->parseAmount($text);
        set_transient(
            self::AMOUNT_PREFIX . $telegramUserId,
            ['credits' => $credits, 'note' => $note],
            15 * MINUTE_IN_SECONDS
        );

        $buttons = [];

        foreach (PersonResolver::matching($this->people, $query, $entities) as $person) {
            $buttons[] = [[
                'text' => sprintf(
                    /* translators: 1: person's name, 2: their current credit balance */
                    __('%1$s (%2$d credits now)', 'eventcrew'),
                    $person->name(),
                    $this->standing->for($person->id)->creditBalance
                ),
                'callback_data' => self::PICK_PREFIX . $person->id,
            ]];
        }

        if ([] === $buttons) {
            $this->telegram->sendMessage(
                $chatId,
                __('I couldn’t find anyone by that name. Try their exact name or @mention them.', 'eventcrew')
            );

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            __('Who should get the credit?', 'eventcrew'),
            ['inline_keyboard' => $buttons]
        );
    }

    /**
     * A pick (callback gift:<person_id>): re-check the organizer, record the
     * grant, and tell both the organizer and - if they take DMs - the recipient.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onSelect(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $organizerTelegramId = (int) ($callbackQuery['from']['id'] ?? 0);
        $personId = (int) substr((string) ($callbackQuery['data'] ?? ''), strlen(self::PICK_PREFIX));

        $organizer = 0 === $organizerTelegramId
            ? null
            : $this->people->findByTelegramUserId($organizerTelegramId);

        if (null === $organizer || ! $organizer->isOrganizer) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('Only organizers can give out free-entry credits.', 'eventcrew'),
                true
            );

            return;
        }

        $recipient = $this->people->find($personId);

        if (null === $recipient) {
            $this->telegram->answerCallbackQuery($callbackId, __('That person is gone.', 'eventcrew'), true);

            return;
        }

        [$credits, $note] = $this->pendingAmount($organizerTelegramId);

        // A discretionary bot grant carries no WordPress user id, so it is
        // recorded as granted_by null - the ledger still shows the credit.
        $this->grants->record($recipient->id, $credits, $note, 0);
        $balance = $this->standing->for($recipient->id)->creditBalance;

        $this->telegram->answerCallbackQuery(
            $callbackId,
            sprintf(
                /* translators: 1: recipient's name, 2: credits granted, 3: their new balance */
                _n(
                    'Gave %1$s %2$d credit — they now have %3$d.',
                    'Gave %1$s %2$d credits — they now have %3$d.',
                    $credits,
                    'eventcrew'
                ),
                $recipient->name(),
                $credits,
                $balance
            )
        );

        // The recipient hears about it on both channels, the same as a gift from
        // the People page.
        $this->notifier->notify($recipient, $credits);
    }

    /**
     * The credits and note the organizer set when they named this person, or the
     * defaults if the short-lived transient has expired.
     *
     * @return array{0: int, 1: string}
     */
    private function pendingAmount(int $organizerTelegramId): array
    {
        $pending = get_transient(self::AMOUNT_PREFIX . $organizerTelegramId);
        delete_transient(self::AMOUNT_PREFIX . $organizerTelegramId);

        $credits = is_array($pending) && isset($pending['credits']) ? max(1, (int) $pending['credits']) : 1;
        $note = is_array($pending) && isset($pending['note']) && '' !== (string) $pending['note']
            ? (string) $pending['note']
            : 'gift via bot';

        return [$credits, $note];
    }

    /**
     * Splits a reply like "Sam 3 covered setup" into the name to search, the
     * credit count and the note. With no trailing number it is all name, one
     * credit, the default note.
     *
     * @return array{0: string, 1: int, 2: string}
     */
    private function parseAmount(string $text): array
    {
        $query = trim($text);

        if (1 === preg_match('/^(.*?)\s+(\d{1,3})(?:\s+(.+\S))?\s*$/u', $query, $m)) {
            $note = isset($m[3]) && '' !== trim($m[3]) ? trim($m[3]) : 'gift via bot';

            return [trim($m[1]), max(1, (int) $m[2]), $note];
        }

        return [$query, 1, 'gift via bot'];
    }
}

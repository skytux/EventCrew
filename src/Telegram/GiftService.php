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
            __('Who should get a free-entry credit? Send their name, or @mention them.', 'eventcrew')
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

        $buttons = [];

        foreach (PersonResolver::matching($this->people, $text, $entities) as $person) {
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

        // A discretionary bot grant carries no WordPress user id, so it is
        // recorded as granted_by null - the ledger still shows the credit.
        $this->grants->record($recipient->id, 1, 'gift via bot', 0);
        $balance = $this->standing->for($recipient->id)->creditBalance;

        $this->telegram->answerCallbackQuery(
            $callbackId,
            sprintf(
                /* translators: 1: recipient's name, 2: their new credit balance */
                __('Gave %1$s a credit — they now have %2$d.', 'eventcrew'),
                $recipient->name(),
                $balance
            )
        );

        // The recipient hears about it on both channels, the same as a gift from
        // the People page.
        $this->notifier->notify($recipient);
    }
}

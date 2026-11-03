<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\PersonRepository;

/**
 * /events: an organizer has the bot post the coming events in the crew group,
 * one picture card per event (EventCard).
 *
 * - "/events" in a private chat with the bot sends the cards of the next
 *   events (at most EventCard::MAX_EVENTS) in that chat, exactly as the group
 *   will see them, then a message with Confirm and Cancel buttons.
 * - Confirm posts the same cards in the group the board lives in; Cancel
 *   drops them.
 *
 * Works like /say (SayCommand): refused in a group, so a preview is never
 * shown to the whole crew, and refused to anyone who is not an organizer, at
 * the command and again at the Confirm tap.
 */
final class EventsCommand
{
    /** Callback data prefix of the Confirm and Cancel buttons. */
    public const PREFIX = 'events:';

    /** Transient key prefix of the cards waiting for Confirm. */
    public const DRAFT_PREFIX = 'eventcrew_events_';

    /** How long the cards wait for Confirm. */
    public const DRAFT_TTL = DAY_IN_SECONDS;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram
    ) {
    }

    public function onCommand(int $fromId, int $chatId, bool $isPrivate): void
    {
        if (! $isPrivate) {
            $this->telegram->sendMessage($chatId, __('Use /events in a private chat with me.', 'eventcrew'));

            return;
        }

        if (! $this->isOrganizer($fromId)) {
            $this->telegram->sendMessage($chatId, __('Only organizers can use /events.', 'eventcrew'));

            return;
        }

        if (0 === $this->groupChatId()) {
            $this->telegram->sendMessage($chatId, __('I am not in the crew group yet.', 'eventcrew'));

            return;
        }

        $cards = EventCard::coming();

        if ([] === $cards) {
            $this->telegram->sendMessage($chatId, __('There are no coming events.', 'eventcrew'));

            return;
        }

        foreach ($cards as $card) {
            if (null === EventCard::send($this->telegram, $chatId, $card)) {
                $this->telegram->sendMessage(
                    $chatId,
                    __('Telegram did not accept these events. Please try again.', 'eventcrew')
                );

                return;
            }
        }

        $id = bin2hex(random_bytes(8));
        set_transient(self::DRAFT_PREFIX . $id, ['user' => $fromId, 'cards' => $cards], self::DRAFT_TTL);

        $count = count($cards);
        // phpcs:disable WordPress.WP.I18n.MissingSingularPlaceholder -- the singular is only used for 1.
        $sent = $this->telegram->sendMessage(
            $chatId,
            sprintf(
                /* translators: %d: number of events */
                _n(
                    'The event above goes to the group when you tap Confirm.',
                    'The %d events above go to the group when you tap Confirm.',
                    $count,
                    'eventcrew'
                ),
                $count
            ),
            ['inline_keyboard' => [[
                ['text' => '✅ ' . __('Confirm', 'eventcrew'), 'callback_data' => self::PREFIX . 'ok:' . $id],
                ['text' => '✖️ ' . __('Cancel', 'eventcrew'), 'callback_data' => self::PREFIX . 'no:' . $id],
            ]]]
        );
        // phpcs:enable WordPress.WP.I18n.MissingSingularPlaceholder

        if (null === $sent) {
            delete_transient(self::DRAFT_PREFIX . $id);
        }
    }

    /**
     * A Confirm or Cancel tap under the preview.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onConfirm(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $fromId = (int) ($callbackQuery['from']['id'] ?? 0);
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chatId = (int) ($message['chat']['id'] ?? $fromId);
        $messageId = (int) ($message['message_id'] ?? 0);

        if (1 !== preg_match('/^events:(ok|no):([0-9a-f]{16})$/', (string) ($callbackQuery['data'] ?? ''), $m)) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $key = self::DRAFT_PREFIX . $m[2];
        $draft = get_transient($key);

        if (! is_array($draft) || 0 === $fromId || (int) ($draft['user'] ?? 0) !== $fromId) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('This preview has expired. Please send /events again.', 'eventcrew'),
                true
            );

            return;
        }

        // Used once, whichever button it was.
        delete_transient($key);
        $this->removeButtons($chatId, $messageId);

        if ('no' === $m[1]) {
            $this->telegram->answerCallbackQuery($callbackId, __('Cancelled.', 'eventcrew'));
            $this->telegram->sendMessage($chatId, '✖️ ' . __('Cancelled. Nothing was sent to the group.', 'eventcrew'));

            return;
        }

        if (! $this->isOrganizer($fromId)) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('Only organizers can use /events.', 'eventcrew'),
                true
            );

            return;
        }

        $cards = is_array($draft['cards'] ?? null) ? $draft['cards'] : [];
        $group = $this->groupChatId();
        $posted = 0;

        foreach ($cards as $card) {
            if (0 !== $group && null !== EventCard::send($this->telegram, $group, $card)) {
                $posted++;
            }
        }

        $this->telegram->answerCallbackQuery($callbackId, $posted > 0 ? __('Sent.', 'eventcrew') : '');

        if (0 === $posted) {
            $this->telegram->sendMessage(
                $chatId,
                __('The events could not be posted in the group. Please send /events again.', 'eventcrew')
            );
        } elseif ($posted < count($cards)) {
            $this->telegram->sendMessage(
                $chatId,
                sprintf(
                    /* translators: 1: events posted, 2: events in the preview */
                    __('%1$d of %2$d events were posted in the group. The others could not be posted.', 'eventcrew'),
                    $posted,
                    count($cards)
                )
            );
        } else {
            $this->telegram->sendMessage($chatId, '✅ ' . __('Sent to the group.', 'eventcrew'));
        }
    }

    private function isOrganizer(int $telegramUserId): bool
    {
        if (0 === $telegramUserId) {
            return false;
        }

        $person = $this->people->findByTelegramUserId($telegramUserId);

        return null !== $person && $person->isOrganizer;
    }

    /** The crew group: the chat the board lives in. 0 when there is none. */
    private function groupChatId(): int
    {
        $board = get_option(BoardService::BOARD_OPTION, []);

        return is_array($board) ? (int) ($board['chat_id'] ?? 0) : 0;
    }

    private function removeButtons(int $chatId, int $messageId): void
    {
        if (0 === $chatId || 0 === $messageId) {
            return;
        }

        $this->telegram->call('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => ['inline_keyboard' => []],
        ]);
    }
}

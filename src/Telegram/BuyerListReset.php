<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\Dates;
use EventCrew\Web\CrewAdminRoster;

/**
 * The "🗑 Reset the list" button under the bot's "tickets were imported"
 * message to the organizers (MailedListImport).
 *
 * - The button asks first, in a new message with "Yes, delete" and "Cancel",
 *   naming how many imported ticket buyers the night has now.
 * - "Yes, delete" deletes every imported ticket buyer of that night, as the
 *   Admin tab's "Reset import" does (TicketBuyerRepository::clearForDate()),
 *   and the question becomes how many were deleted.
 * - "Cancel" leaves the list as it is.
 *
 * A message can be forwarded, so every tap checks that the person tapping is
 * an organizer. The callback data is the prefix, the step and the night's
 * date, at most 19 bytes.
 */
final class BuyerListReset
{
    /** Callback data prefix of the three buttons. */
    public const PREFIX = 'tbr:';

    public const ASK = 'ask';
    public const YES = 'yes';
    public const NO = 'no';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly TicketBuyerRepository $ticketBuyers,
        private readonly TelegramClient $telegram
    ) {
    }

    /**
     * The button that goes under the "tickets were imported" message, in the
     * current language.
     *
     * @return array<string, mixed>
     */
    public static function markup(string $date): array
    {
        return ['inline_keyboard' => [[
            ['text' => '🗑 ' . __('Reset the list', 'eventcrew'), 'callback_data' => self::data(self::ASK, $date)],
        ]]];
    }

    public static function data(string $step, string $date): string
    {
        return self::PREFIX . $step . ':' . $date;
    }

    /**
     * A tap on one of the three buttons.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onTap(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $fromId = (int) ($callbackQuery['from']['id'] ?? 0);
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chatId = (int) ($message['chat']['id'] ?? $fromId);
        $messageId = (int) ($message['message_id'] ?? 0);
        $data = (string) ($callbackQuery['data'] ?? '');

        if (
            1 !== preg_match('/^tbr:(ask|yes|no):(\d{4}-\d{2}-\d{2})$/', $data, $m)
            || ! CrewAdminRoster::isValidDate($m[2])
        ) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        [, $step, $date] = $m;

        if (! $this->isOrganizer($fromId)) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('Only organizers can reset the ticket list.', 'eventcrew'),
                true
            );

            return;
        }

        $day = Dates::dayLabel($date);

        if (self::ASK === $step) {
            $this->ask($callbackId, $chatId, $date, $day);

            return;
        }

        if (self::NO === $step) {
            $this->telegram->answerCallbackQuery($callbackId, __('Cancelled.', 'eventcrew'));
            $this->replace($chatId, $messageId, '✖️ ' . __('Cancelled. The ticket list was not changed.', 'eventcrew'));

            return;
        }

        $deleted = $this->ticketBuyers->clearForDate($date);

        $this->telegram->answerCallbackQuery($callbackId);
        $this->replace(
            $chatId,
            $messageId,
            '🗑 ' . sprintf(
                /* translators: 1: number of ticket buyers deleted, 2: the event day, e.g. "Sat 1 Aug" */
                __('Deleted %1$d ticket buyers for %2$s.', 'eventcrew'),
                $deleted,
                $day
            )
        );
    }

    /** The question, with the number of imported ticket buyers the night has now. */
    private function ask(string $callbackId, int $chatId, string $date, string $day): void
    {
        $count = $this->ticketBuyers->countImportedForDate($date);
        $this->telegram->answerCallbackQuery($callbackId);

        if (0 === $count) {
            $this->telegram->sendMessage(
                $chatId,
                sprintf(
                    /* translators: %s: the event day, e.g. "Sat 1 Aug" */
                    __('There are no imported ticket buyers for %s.', 'eventcrew'),
                    $day
                )
            );

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            sprintf(
                /* translators: 1: number of imported ticket buyers, 2: the event day, e.g. "Sat 1 Aug" */
                __('Delete all %1$d imported ticket buyers for %2$s? Their tickets stop working.', 'eventcrew'),
                $count,
                $day
            ),
            ['inline_keyboard' => [[
                ['text' => '🗑 ' . __('Yes, delete', 'eventcrew'), 'callback_data' => self::data(self::YES, $date)],
                ['text' => '✖️ ' . __('Cancel', 'eventcrew'), 'callback_data' => self::data(self::NO, $date)],
            ]]]
        );
    }

    /** The question's text becomes $text, and its buttons go. */
    private function replace(int $chatId, int $messageId, string $text): void
    {
        if (0 === $chatId || 0 === $messageId) {
            $this->telegram->sendMessage($chatId, $text);

            return;
        }

        $this->telegram->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => []]);
    }

    private function isOrganizer(int $telegramUserId): bool
    {
        if (0 === $telegramUserId) {
            return false;
        }

        $person = $this->people->findByTelegramUserId($telegramUserId);

        return null !== $person && $person->isOrganizer;
    }
}

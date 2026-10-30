<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\Dates;
use EventCrew\Support\DoorAccess;
use EventCrew\Support\DoorList;

/**
 * /tickets: who has a ticket for one event night - everyone working it and
 * everyone who spent a free-entry credit on it, the same list as the wp-admin
 * door list.
 *
 * Organizers can look at any night; the night's leader and Welcome crew can
 * look at their own night. The list always goes to the asker's DM. A date can
 * be given ("/tickets 1.8."), and buttons under the list switch between the
 * nearby nights by editing the message in place.
 */
final class DoorListService
{
    use GroupBreadcrumb;

    /** Callback data prefix for a night button: dl:<Y-m-d>. */
    private const SELECT_PREFIX = 'dl:';

    /** Night buttons per keyboard row. */
    private const BUTTONS_PER_ROW = 2;

    public function __construct(
        private readonly DoorList $doorList,
        private readonly DoorAccess $access,
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram
    ) {
    }

    public function onTicketsCommand(int $telegramUserId, int $chatId, bool $isPrivate, string $payload = ''): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person) {
            $this->telegram->sendMessage($chatId, $this->deniedMessage());

            return;
        }

        if ('' !== $payload) {
            $date = Dates::parseUserDate($payload, current_time('Y-m-d'));

            if ('' === $date) {
                $this->telegram->sendMessage(
                    $chatId,
                    __('I could not read that date. Try /tickets 1.8. or /tickets 2026-08-01.', 'eventcrew')
                );

                return;
            }
        } else {
            $date = $this->access->defaultDate();

            if ('' === $date) {
                $this->telegram->sendMessage($chatId, __('No tasks are scheduled yet.', 'eventcrew'));

                return;
            }
        }

        if (! $this->access->canView($person, $date)) {
            $this->telegram->sendMessage($chatId, $this->deniedMessage());

            return;
        }

        $this->telegram->sendMessage($telegramUserId, $this->render($date), $this->markup($person, $date));
        $this->sentDmNote($chatId, $isPrivate);
    }

    /**
     * A night button tap: shows that night's list in the same message.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onSelect(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $telegramUserId = (int) ($callbackQuery['from']['id'] ?? 0);
        $data = (string) ($callbackQuery['data'] ?? '');
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);

        $date = substr($data, strlen(self::SELECT_PREFIX));

        if (1 !== preg_match('#^\d{4}-\d{2}-\d{2}$#', $date) || 0 === $chatId || 0 === $messageId) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $this->access->canView($person, $date)) {
            $this->telegram->answerCallbackQuery($callbackId, $this->deniedMessage(), true);

            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);
        $this->telegram->editMessageText($chatId, $messageId, $this->render($date), $this->markup($person, $date));
    }

    private function render(string $date): string
    {
        $entrants = $this->doorList->entrantsFor($date);
        $lines = ['🎟 ' . sprintf(
            /* translators: %s: event day, e.g. "Sat 1 Aug" */
            __('Tickets — %s', 'eventcrew'),
            Dates::dayLabel($date)
        )];

        if ([] === $entrants) {
            $lines[] = __('Nobody has a ticket yet.', 'eventcrew');

            return implode("\n", $lines);
        }

        $lines[] = sprintf(
            /* translators: %d: number of people */
            _n('%d person', '%d people', count($entrants), 'eventcrew'),
            count($entrants)
        );
        $lines[] = '';

        foreach ($entrants as $entrant) {
            $lines[] = sprintf('• %s — %s', $entrant['name'], $entrant['detail']);
        }

        return implode("\n", $lines);
    }

    /**
     * Buttons for the other nearby nights this person may see, or null when
     * there is none to switch to.
     *
     * @return array{inline_keyboard: array<int, array<int, array{text: string, callback_data: string}>>}|null
     */
    private function markup(Person $person, string $shown): ?array
    {
        $buttons = [];

        foreach ($this->access->nearbyDates() as $date) {
            if ($date === $shown || ! $this->access->canView($person, $date)) {
                continue;
            }

            $buttons[] = ['text' => Dates::dayLabel($date), 'callback_data' => self::SELECT_PREFIX . $date];
        }

        return [] === $buttons ? null : ['inline_keyboard' => array_chunk($buttons, self::BUTTONS_PER_ROW)];
    }

    private function deniedMessage(): string
    {
        return __('Only organizers, and that night’s leader and Welcome crew, can see who has a ticket.', 'eventcrew');
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Guest;
use EventCrew\Models\Person;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\Dates;
use EventCrew\Support\DoorAccess;
use EventCrew\Support\GuestCommand;

/**
 * /guest: the guest list of one event night - people let in free who are not
 * crew members.
 *
 * Organizers add, edit and remove guests; people on a night's task of a
 * sales-desk role can see the list for their night. Every answer goes to the
 * asker's DM. See GuestCommand for the syntax.
 */
final class GuestListService
{
    use GroupBreadcrumb;

    /** Callback data prefix for a night button: gl:<Y-m-d>. */
    private const SELECT_PREFIX = 'gl:';

    /** Night buttons per keyboard row. */
    private const BUTTONS_PER_ROW = 2;

    public function __construct(
        private readonly GuestRepository $guests,
        private readonly DoorAccess $access,
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram
    ) {
    }

    public function onGuestCommand(int $telegramUserId, int $chatId, bool $isPrivate, string $arguments): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person) {
            $this->telegram->sendMessage($chatId, $this->deniedViewMessage());

            return;
        }

        $command = GuestCommand::parse($arguments, current_time('Y-m-d'));

        if ('' !== $command['error']) {
            $this->telegram->sendMessage($chatId, $this->errorMessage($command['error']));

            return;
        }

        $date = '' !== $command['date'] ? $command['date'] : $this->access->defaultDate();

        if ('' === $date) {
            $this->telegram->sendMessage(
                $chatId,
                __('No event is scheduled yet. Give a date: /guest add 1.8. Name', 'eventcrew')
            );

            return;
        }

        if (GuestCommand::SHOW !== $command['action'] && ! $person->isOrganizer) {
            $this->telegram->sendMessage($chatId, __('Only organizers can change the guest list.', 'eventcrew'));

            return;
        }

        if (! $this->access->canView($person, $date)) {
            $this->telegram->sendMessage($chatId, $this->deniedViewMessage());

            return;
        }

        $done = match ($command['action']) {
            GuestCommand::ADD => $this->add($person, $date, $command),
            GuestCommand::EDIT => $this->edit($date, $command),
            GuestCommand::REMOVE => $this->remove($date, $command['number']),
            default => '',
        };

        // A number that matched nobody is answered where it was asked, like any
        // other mistake, and changes nothing.
        if (null === $done) {
            $this->telegram->sendMessage($chatId, $this->noSuchNumberMessage($command['number'], $date));

            return;
        }

        $text = '' === $done ? $this->render($person, $date) : $done . "\n\n" . $this->render($person, $date);
        $this->telegram->sendMessage($telegramUserId, $text, $this->markup($person, $date));
        $this->sentDmNote($chatId, $isPrivate);
    }

    /**
     * A night button tap: shows that night's guest list in the same message.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onSelect(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $telegramUserId = (int) ($callbackQuery['from']['id'] ?? 0);
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);
        $date = substr((string) ($callbackQuery['data'] ?? ''), strlen(self::SELECT_PREFIX));

        if (1 !== preg_match('#^\d{4}-\d{2}-\d{2}$#', $date) || 0 === $chatId || 0 === $messageId) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $this->access->canView($person, $date)) {
            $this->telegram->answerCallbackQuery($callbackId, $this->deniedViewMessage(), true);

            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);
        $this->telegram->editMessageText(
            $chatId,
            $messageId,
            $this->render($person, $date),
            $this->markup($person, $date)
        );
    }

    /**
     * @param array{name: ?string, plus_ones: ?int, note: ?string} $command
     */
    private function add(Person $person, string $date, array $command): string
    {
        $name = (string) $command['name'];
        $this->guests->add($date, $name, (int) $command['plus_ones'], (string) $command['note'], $person->id);

        return sprintf(
            /* translators: 1: guest name, 2: event day */
            __('✅ Added %1$s to %2$s.', 'eventcrew'),
            $name,
            Dates::dayLabel($date)
        );
    }

    /**
     * @param array{number: int, name: ?string, plus_ones: ?int, note: ?string} $command
     * @return string|null null when the number matches nobody
     */
    private function edit(string $date, array $command): ?string
    {
        $guest = $this->numbered($date, $command['number']);

        if (null === $guest) {
            return null;
        }

        $name = $command['name'] ?? $guest->name;
        $this->guests->update(
            $guest->id,
            $name,
            $command['plus_ones'] ?? $guest->plusOnes,
            $command['note'] ?? $guest->note
        );

        /* translators: %s: guest name */
        return sprintf(__('✏️ Changed %s.', 'eventcrew'), $name);
    }

    /**
     * @return string|null null when the number matches nobody
     */
    private function remove(string $date, int $number): ?string
    {
        $guest = $this->numbered($date, $number);

        if (null === $guest) {
            return null;
        }

        $this->guests->delete($guest->id);

        /* translators: %s: guest name */
        return sprintf(__('🗑 Removed %s.', 'eventcrew'), $guest->name);
    }

    /** The guest shown as $number (from 1) in the night's list, or null. */
    private function numbered(string $date, int $number): ?Guest
    {
        return $this->guests->forDate($date)[$number - 1] ?? null;
    }

    private function render(Person $person, string $date): string
    {
        $guests = $this->guests->forDate($date);
        $lines = ['🎫 ' . sprintf(
            /* translators: %s: event day, e.g. "Sat 1 Aug" */
            __('Guest list — %s', 'eventcrew'),
            Dates::dayLabel($date)
        )];

        if ([] === $guests) {
            $lines[] = __('No guests yet.', 'eventcrew');
        } else {
            $people = array_sum(array_map(static fn (Guest $guest): int => $guest->headcount(), $guests));
            $lines[] = sprintf(
                /* translators: %d: number of guests */
                _n('%d guest', '%d guests', count($guests), 'eventcrew'),
                count($guests)
            ) . ', ' . sprintf(
                /* translators: %d: number of people including plus-ones */
                _n('%d person in all', '%d people in all', $people, 'eventcrew'),
                $people
            );
            $lines[] = '';

            foreach ($guests as $index => $guest) {
                $lines[] = sprintf('%d. %s', $index + 1, $guest->line());
            }
        }

        if ($person->isOrganizer) {
            $lines[] = '';
            $lines[] = __('Add: /guest add Name ; note  (just the guest)', 'eventcrew');
            $lines[] = __('Add with extra people: /guest add Name +2 ; note  (the guest and 2 more)', 'eventcrew');
            $lines[] = __('No +N, or +0, means no extra people.', 'eventcrew');
            $lines[] = __('Change: /guest edit 2 +3  (number from the list; give only what changes)', 'eventcrew');
            $lines[] = __('Remove: /guest remove 2', 'eventcrew');
            $lines[] = __('Another night: put the date first, e.g. /guest add 1.8. Name', 'eventcrew');
        }

        return implode("\n", $lines);
    }

    /**
     * Buttons for the other nearby nights this person may see, or null.
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

    private function errorMessage(string $error): string
    {
        return match ($error) {
            'date' => UnreadableDate::text(),
            'number' => __('Give the guest’s number from the list, e.g. /guest remove 2', 'eventcrew'),
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            'name' => __('Give the guest’s name, e.g. /guest add Anna Virtanen ; DJ’s partner (no extra people), or /guest add Anna Virtanen +2 ; DJ’s partner (Anna and 2 more)', 'eventcrew'),
            'nothing' => __('Say what to change, e.g. /guest edit 2 +3 or /guest edit 2 ; new note', 'eventcrew'),
            default => __('To see the list send /guest, or /guest 1.8. for another night.', 'eventcrew'),
        };
    }

    private function noSuchNumberMessage(int $number, string $date): string
    {
        return sprintf(
            /* translators: 1: a list number, 2: event day */
            __('There is no guest number %1$d on %2$s. Send /guest to see the list.', 'eventcrew'),
            $number,
            Dates::dayLabel($date)
        );
    }

    private function deniedViewMessage(): string
    {
        return __('Only organizers and that night’s welcome crew can see the guest list.', 'eventcrew');
    }
}

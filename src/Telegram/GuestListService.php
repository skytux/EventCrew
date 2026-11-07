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
use EventCrew\Support\TicketMail;

/**
 * The guest list (👥 under /door; /door add|edit|remove changes it): the guest
 * list of one event night - people let in free who are not crew members.
 *
 * Organizers add, edit and remove guests; people on a night's task of a
 * sales-desk role can see the list for their night. Every answer goes to the
 * asker's DM. See GuestCommand for the syntax.
 *
 * A list too long for one Telegram message is split into pages: the message
 * shows one page, with previous and next buttons (gl:<Y-m-d>:<page>) above
 * the night buttons. A list that fits shows as before. The guest numbers run
 * on across pages, so /door edit and /door remove take the same numbers.
 */
final class GuestListService
{
    use GroupBreadcrumb;

    /** Callback data prefix for a night or page button: gl:<Y-m-d>[:<page>]. */
    private const SELECT_PREFIX = 'gl:';

    /** Callback data of the button that cancels the waiting ticket send. */
    private const CANCEL_DATA = 'gl:cancel';

    /** Characters kept free on every page for the page note. */
    private const RESERVED_TEXT = 100;

    /** Characters kept free for the "Ticket emails go out at ..." line under an add. */
    private const RESERVED_PENDING = 200;

    public function __construct(
        private readonly GuestRepository $guests,
        private readonly DoorAccess $access,
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram,
        private readonly ?TicketMail $ticketMail = null
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
                __('No event is scheduled yet. Give a date: /door add 1.8. Name', 'eventcrew')
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

        $withEmail = GuestCommand::ADD === $command['action'] && '' !== (string) ($command['email'] ?? '');
        // Room for the line above the list and the pending line under it.
        $reserved = ('' === $done ? 0 : MessagePages::textLength([$done, '']))
            + ($withEmail ? self::RESERVED_PENDING : 0);
        $rendered = $this->render($person, $date, 0, $reserved);
        $text = '' === $done ? $rendered['text'] : $done . "\n\n" . $rendered['text'];
        // A guest added with an email address starts the waiting ticket send:
        // say when it goes out, with a button that cancels it.
        $pending = $withEmail ? $this->ticketMail?->pendingSummary() : null;
        $extra = [];

        if (null !== $pending) {
            $text .= "\n\n" . TicketMail::pendingLine($pending);
            $extra[] = [['text' => __('Cancel sending', 'eventcrew'), 'callback_data' => self::CANCEL_DATA]];
        }

        $markup = $this->markup($person, $date, $rendered['pages'], $extra);

        $this->telegram->sendMessage($telegramUserId, $text, $markup);
        $this->sentDmNote($chatId, $isPrivate);
    }

    /**
     * A night or page button tap: shows that night's guest list, at that
     * page, in the same message.
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
        $data = substr((string) ($callbackQuery['data'] ?? ''), strlen(self::SELECT_PREFIX));

        if (self::CANCEL_DATA === (string) ($callbackQuery['data'] ?? '')) {
            $this->onCancel($callbackId, $telegramUserId, $message);

            return;
        }

        $valid = 1 === preg_match('#^(\d{4}-\d{2}-\d{2})(?::(\d{1,3}))?$#', $data, $matches);

        if (! $valid || 0 === $chatId || 0 === $messageId) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $date = $matches[1];
        $page = (int) ($matches[2] ?? 0);

        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $this->access->canView($person, $date)) {
            $this->telegram->answerCallbackQuery($callbackId, $this->deniedViewMessage(), true);

            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);
        $rendered = $this->render($person, $date, $page);
        $this->telegram->editMessageText(
            $chatId,
            $messageId,
            $rendered['text'],
            $this->markup($person, $date, $rendered['pages'])
        );
    }

    /**
     * Cancel under a guest added with an email address: cancels the waiting
     * ticket send, as Cancel on the Roster page does. Organizers only, the
     * same people who can add a guest here. Afterwards the Cancel button is
     * taken off the message it was under; the night buttons stay.
     *
     * @param array<string, mixed> $message the message the button was under
     */
    private function onCancel(string $callbackId, int $telegramUserId, array $message): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isOrganizer) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('Only organizers can change the guest list.', 'eventcrew'),
                true
            );

            return;
        }

        $this->telegram->answerCallbackQuery(
            $callbackId,
            TicketMail::cancel()
                ? __('Sending cancelled. No ticket email was sent.', 'eventcrew')
                : __('No send was waiting. It may have gone out already.', 'eventcrew'),
            true
        );
        $this->removeCancelButton($message);
    }

    /**
     * Edits the message's buttons to the same buttons without Cancel sending.
     * No buttons left gives null, which removes the keyboard.
     *
     * @param array<string, mixed> $message
     */
    private function removeCancelButton(array $message): void
    {
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);

        if (0 === $chatId || 0 === $messageId) {
            return;
        }

        $rows = [];

        foreach ((array) ($message['reply_markup']['inline_keyboard'] ?? []) as $row) {
            $kept = array_values(array_filter(
                (array) $row,
                static fn ($button): bool => self::CANCEL_DATA !== (string) ($button['callback_data'] ?? '')
            ));

            if ([] !== $kept) {
                $rows[] = $kept;
            }
        }

        $markup = [] === $rows ? null : ['inline_keyboard' => $rows];
        $this->telegram->editMessageReplyMarkup($chatId, $messageId, $markup);
    }

    /**
     * @param array{name: ?string, plus_ones: ?int, note: ?string, email?: string} $command
     */
    private function add(Person $person, string $date, array $command): string
    {
        $name = (string) $command['name'];
        $email = (string) ($command['email'] ?? '');
        $this->guests->add($date, $name, (int) $command['plus_ones'], (string) $command['note'], $person->id, $email);

        // With an email address the guest is emailed their ticket, by the
        // next run of the ticket email's cron batch.
        if ('' !== $email) {
            TicketMail::schedule();
        }

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
        // "Your ticket is cancelled", when the guest was emailed a ticket.
        $this->ticketMail?->guestCancelled($guest);

        /* translators: %s: guest name */
        return sprintf(__('🗑 Removed %s.', 'eventcrew'), $guest->name);
    }

    /** The guest shown as $number (from 1) in the night's list, or null. */
    private function numbered(string $date, int $number): ?Guest
    {
        return $this->guests->forDate($date)[$number - 1] ?? null;
    }

    /**
     * The guest list for $date at page $page (from 0; past the end means the
     * last), with "page 2 of 3" in its first line when it takes more than one
     * page. $reserved characters are kept free for text the caller adds.
     * 'pages' is the previous/next row, empty when the list fits.
     *
     * @return array{text: string, pages: array<int, array{text: string, callback_data: string}>}
     */
    private function render(Person $person, string $date, int $page = 0, int $reserved = 0): array
    {
        $guests = $this->guests->forDate($date);
        $lines = ['🎫 ' . sprintf(
            /* translators: %s: event day, e.g. "Sat 1 Aug" */
            __('Guest list — %s', 'eventcrew'),
            Dates::dayLabel($date)
        )];

        $numbered = [];

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
                $numbered[] = sprintf('%d. %s', $index + 1, $guest->line());
            }
        }

        $help = [];

        if ($person->isOrganizer) {
            $help[] = '';
            $help[] = __('Add: /door add Name ; note  (just the guest)', 'eventcrew');
            $help[] = __('Add with extra people: /door add Name +2 ; note  (the guest and 2 more)', 'eventcrew');
            $help[] = __('No +N, or +0, means no extra people.', 'eventcrew');
            $help[] = __('Email the ticket: /door add Name name@example.com', 'eventcrew');
            $help[] = __('Change: /door edit 2 +3  (number from the list; give only what changes)', 'eventcrew');
            $help[] = __('Remove: /door remove 2', 'eventcrew');
            $help[] = __('Another night: put the date first, e.g. /door add 1.8. Name', 'eventcrew');
        }

        $maxText = MessagePages::MAX_TEXT - MessagePages::textLength(array_merge($lines, $help))
            - self::RESERVED_TEXT - $reserved;
        $pages = MessagePages::split([['heading' => [], 'lines' => $numbered]], $maxText);
        $count = count($pages);
        $page = max(0, min($page, $count - 1));

        if ($count > 1) {
            $lines[0] .= MessagePages::note($page, $count);
        }

        return [
            'text' => implode("\n", array_merge($lines, $pages[$page], $help)),
            'pages' => $count > 1 ? MessagePages::buttons(self::SELECT_PREFIX . $date, $page, $count) : [],
        ];
    }

    /**
     * The previous/next row of a paged list ($pages), then the date selector
     * (DayPicker): the shown night marked, with arrows to the nights before
     * and after it that this person may see, then the $extra rows and Back
     * to /door.
     *
     * @param array<int, array{text: string, callback_data: string}> $pages
     * @param array<int, array<int, array{text: string, callback_data: string}>> $extra
     * @return array{inline_keyboard: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    private function markup(Person $person, string $shown, array $pages = [], array $extra = []): array
    {
        $rows = array_merge([] === $pages ? [] : [$pages], DayPicker::rows(
            $this->access->dates(),
            $shown,
            self::SELECT_PREFIX,
            fn (string $date): bool => $this->access->canView($person, $date)
        ), $extra);

        // Back to /door, where 👥 Guest list is, as the last row.
        $rows[] = MenuButtons::backRow(MenuButtons::DOOR);

        return ['inline_keyboard' => $rows];
    }

    private function errorMessage(string $error): string
    {
        return match ($error) {
            'date' => UnreadableDate::text(),
            'number' => __('Give the guest’s number from the list, e.g. /door remove 2', 'eventcrew'),
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            'name' => __('Give the guest’s name, e.g. /door add Anna Virtanen ; DJ’s partner (no extra people), or /door add Anna Virtanen +2 ; DJ’s partner (Anna and 2 more)', 'eventcrew'),
            'nothing' => __('Say what to change, e.g. /door edit 2 +3 or /door edit 2 ; new note', 'eventcrew'),
            'email' => __('That email address is not valid. Correct it or leave it empty.', 'eventcrew'),
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            default => __('To see the list send /door and tap 👥 Guest list, or /door 1.8. for another night.', 'eventcrew'),
        };
    }

    private function noSuchNumberMessage(int $number, string $date): string
    {
        return sprintf(
            /* translators: 1: a list number, 2: event day */
            __('There is no guest number %1$d on %2$s. Send /door and tap 👥 Guest list to see the list.', 'eventcrew'),
            $number,
            Dates::dayLabel($date)
        );
    }

    private function deniedViewMessage(): string
    {
        return __('Only organizers and that night’s welcome crew can see the guest list.', 'eventcrew');
    }
}

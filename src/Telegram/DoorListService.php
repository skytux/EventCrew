<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Guest;
use EventCrew\Models\Person;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\Dates;
use EventCrew\Support\DoorAccess;
use EventCrew\Support\DoorList;

/**
 * 📋 Door list (a button under /door): who has a ticket for one event night - everyone working it and
 * everyone who spent a free-entry credit on it, the same list as the wp-admin
 * door list - followed by the night's guest list.
 *
 * Organizers can look at any night; people on a night's task of a sales-desk
 * role can look at their own night. The list always goes to the asker's DM.
 * A date can be given ("/door 1.8."), and buttons under the list switch between the
 * nearby nights by editing the message in place.
 *
 * A list too long for one Telegram message is split into pages: the message
 * shows one page, with previous and next buttons (dl:<Y-m-d>:<page>) above
 * the night buttons. A night that fits shows as before.
 */
final class DoorListService
{
    use GroupBreadcrumb;

    /** The start payload door-<Ymd> shows the door list of that night. */
    public const START_VERB = 'door';

    /** Callback data prefix for a night or page button: dl:<Y-m-d>[:<page>]. */
    private const SELECT_PREFIX = 'dl:';

    /** Characters kept free on every page for the page note. */
    private const RESERVED_TEXT = 100;

    public function __construct(
        private readonly DoorList $doorList,
        private readonly GuestRepository $guests,
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
                $this->telegram->sendMessage($chatId, UnreadableDate::text());

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

        $rendered = $this->render($date);
        $this->telegram->sendMessage(
            $telegramUserId,
            $rendered['text'],
            $this->markup($person, $date, $rendered['pages'])
        );
        $this->sentDmNote($chatId, $isPrivate);
    }

    /**
     * A night or page button tap: shows that night's list, at that page, in
     * the same message.
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

        $data = substr($data, strlen(self::SELECT_PREFIX));
        $valid = 1 === preg_match('#^(\d{4}-\d{2}-\d{2})(?::(\d{1,3}))?$#', $data, $matches);

        if (! $valid || 0 === $chatId || 0 === $messageId) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $date = $matches[1];
        $page = (int) ($matches[2] ?? 0);

        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $this->access->canView($person, $date)) {
            $this->telegram->answerCallbackQuery($callbackId, $this->deniedMessage(), true);

            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);
        $rendered = $this->render($date, $page);
        $this->telegram->editMessageText(
            $chatId,
            $messageId,
            $rendered['text'],
            $this->markup($person, $date, $rendered['pages'])
        );
    }

    /**
     * The list for $date at page $page (from 0; past the end means the last),
     * with "page 2 of 3" in its first line when it takes more than one page.
     * 'pages' is the previous/next row, empty when the list fits.
     *
     * @return array{text: string, pages: array<int, array{text: string, callback_data: string}>}
     */
    private function render(string $date, int $page = 0): array
    {
        $entrants = $this->doorList->entrantsFor($date);
        $guests = $this->guests->forDate($date);
        $lines = ['🎟 ' . sprintf(
            /* translators: %s: event day, e.g. "Sat 1 Aug" */
            __('Tickets — %s', 'eventcrew'),
            Dates::dayLabel($date)
        )];

        if ([] === $entrants && [] === $guests) {
            $lines[] = __('Nobody has a ticket yet.', 'eventcrew');

            return ['text' => implode("\n", $lines), 'pages' => []];
        }

        // Everyone let in: the people below plus each guest and their plus-ones.
        $total = count($entrants)
            + array_sum(array_map(static fn (Guest $guest): int => $guest->headcount(), $guests));
        $lines[] = sprintf(
            /* translators: %d: number of people */
            _n('%d person', '%d people', $total, 'eventcrew'),
            $total
        );
        $blocks = [];

        if ([] !== $entrants) {
            $blocks[] = [
                'heading' => [''],
                'lines' => array_map(
                    static fn (array $entrant): string => sprintf('• %s — %s', $entrant['name'], $entrant['detail']),
                    $entrants
                ),
            ];
        }

        if ([] !== $guests) {
            // A blank line, then the heading, both after the people above
            // and straight under the total.
            $blocks[] = [
                'heading' => ['', '🎫 ' . __('Guests', 'eventcrew')],
                'lines' => array_map(static fn (Guest $guest): string => '• ' . $guest->line(), $guests),
            ];
        }

        $maxText = MessagePages::MAX_TEXT - MessagePages::textLength($lines) - self::RESERVED_TEXT;
        $pages = MessagePages::split($blocks, $maxText);
        $count = count($pages);
        $page = max(0, min($page, $count - 1));

        if ($count > 1) {
            $lines[0] .= MessagePages::note($page, $count);
        }

        return [
            'text' => implode("\n", array_merge($lines, $pages[$page])),
            'pages' => $count > 1 ? MessagePages::buttons(self::SELECT_PREFIX . $date, $page, $count) : [],
        ];
    }

    /**
     * The previous/next row of a paged list ($pages), then the date selector
     * (DayPicker): the shown night marked, with arrows to the nights before
     * and after it that this person may see. Null when there is none.
     *
     * @param array<int, array{text: string, callback_data: string}> $pages
     * @return array{inline_keyboard: array<int, array<int, array{text: string, callback_data: string}>>}|null
     */
    private function markup(Person $person, string $shown, array $pages = []): ?array
    {
        $rows = array_merge([] === $pages ? [] : [$pages], DayPicker::rows(
            $this->access->dates(),
            $shown,
            self::SELECT_PREFIX,
            fn (string $date): bool => $this->access->canView($person, $date)
        ));

        return [] === $rows ? null : ['inline_keyboard' => $rows];
    }

    private function deniedMessage(): string
    {
        return __('Only organizers and that night’s welcome crew can see who has a ticket.', 'eventcrew');
    }
}

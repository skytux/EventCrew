<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\DoorAccess;
use EventCrew\Support\Language;

/**
 * /help and /cancel.
 *
 * /help lists the commands the asker may use. In a private chat that is the
 * Everyone section, the Welcome crew section for organizers and for people on
 * a nearby night's sales-desk task, and the Organizers section for organizers.
 * In the group it is only the Everyone section, in English, with a pointer to
 * the private chat for the rest.
 *
 * /cancel only answers here; the router drops the pending questions and tells
 * this class whether there was one.
 */
final class HelpService
{
    public function __construct(
        private readonly PersonRepository $people,
        private readonly DoorAccess $doorAccess,
        private readonly TelegramClient $telegram
    ) {
    }

    public function onHelp(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        if (! $isPrivate) {
            $this->telegram->sendMessage(
                $chatId,
                Language::run(Language::ENGLISH, fn (): string => $this->groupText())
            );

            return;
        }

        $person = 0 !== $telegramUserId ? $this->people->findByTelegramUserId($telegramUserId) : null;
        $organizer = null !== $person && $person->isOrganizer;
        $welcomeCrew = $organizer || (null !== $person && $this->doorAccess->isWelcomeCrew($person));

        $sections = [$this->title(), $this->everyone()];

        if ($welcomeCrew) {
            $sections[] = $this->welcomeCrew();
        }

        if ($organizer) {
            $sections[] = $this->organizers();
        }

        $this->telegram->sendMessage($chatId, implode("\n\n", $sections));
    }

    /**
     * The answer to /cancel. $stopped is true when at least one question was
     * waiting for an answer and has now been dropped.
     */
    public function onCancel(int $chatId, bool $isPrivate, bool $stopped): void
    {
        $text = static fn (): string => $stopped
            ? __('Stopped. Nothing is waiting for an answer.', 'eventcrew')
            : __('Nothing was waiting for an answer.', 'eventcrew');

        $this->telegram->sendMessage($chatId, $isPrivate ? $text() : Language::run(Language::ENGLISH, $text));
    }

    private function groupText(): string
    {
        return implode("\n\n", [
            $this->title(),
            $this->everyone(),
            __('Send me /help in a private chat for more.', 'eventcrew'),
        ]);
    }

    private function title(): string
    {
        return __('EventCrew bot – commands', 'eventcrew');
    }

    private function everyone(): string
    {
        return implode("\n", [
            __('Everyone', 'eventcrew'),
            __('/board – show the open tasks', 'eventcrew'),
            __('/me – your tasks, credits and standing', 'eventcrew'),
            __('/me history – your past tasks', 'eventcrew'),
            __('/me tickets – your tickets for coming nights', 'eventcrew'),
            __('/ticket – spend a credit on a free-entry ticket', 'eventcrew'),
            __('/replace – take over someone’s task', 'eventcrew'),
            __('/tasks – tasks for your roles', 'eventcrew'),
            __('/web – a sign-in link for the crew web page', 'eventcrew'),
            __('/notifications – choose which messages you get', 'eventcrew'),
            __('/language – change the language', 'eventcrew'),
            __('/cancel – stop the question I asked you', 'eventcrew'),
        ]);
    }

    private function welcomeCrew(): string
    {
        return implode("\n", [
            __('Welcome crew  (date: 1.8. or 2026-08-01; default: next night)', 'eventcrew'),
            __('/roster [date] – who works that night; mark ✓ done, ⏰ late, ✗ no-show', 'eventcrew'),
            __('/tickets [date] – the door list', 'eventcrew'),
            __('/guest [date] – the guest list', 'eventcrew'),
            __('/sales – open the sales desk', 'eventcrew'),
        ]);
    }

    private function organizers(): string
    {
        return implode("\n", [
            __('Organizers', 'eventcrew'),
            __('/guest add [date] Name +N ; note – add a guest (also edit, remove)', 'eventcrew'),
            __('/gift – give free-entry credits', 'eventcrew'),
            __('/roles – give or take roles, organizer status and passes', 'eventcrew'),
            __('/eligible – who may hold each role', 'eventcrew'),
            __('/tasks – also ➕ to put a person on a task', 'eventcrew'),
        ]);
    }
}

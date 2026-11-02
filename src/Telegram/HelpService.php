<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\DoorAccess;
use EventCrew\Support\Language;

/**
 * /help, /door, /people and the answer to the Cancel button.
 *
 * /help lists the commands the asker may use. In a private chat that is the
 * Everyone section, the Welcome crew section for organizers and for people on
 * a nearby night's sales-desk task, and the Organizers section for organizers.
 * In the group it is only the Everyone section, in English, with a pointer to
 * the private chat for the rest.
 *
 * /door and /people answer in the private chat with MenuButtons; each button
 * opens a service that checks again who may use it.
 *
 * The Cancel button only gets its answer here; the router drops the pending
 * questions and tells this class whether there was one.
 */
final class HelpService
{
    use GroupBreadcrumb;

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
     * /door: the door list, guest list and sales desk buttons, in the DM.
     * $date is what was typed after /door ("" = the next night).
     */
    public function onDoor(int $telegramUserId, int $chatId, bool $isPrivate, string $date): void
    {
        $person = 0 !== $telegramUserId ? $this->people->findByTelegramUserId($telegramUserId) : null;

        if (null === $person || ! $person->isEmailVerified()) {
            $this->telegram->sendMessage($chatId, SetupNudge::text(! $isPrivate));

            return;
        }

        if (! $person->isOrganizer && ! $this->doorAccess->isWelcomeCrew($person)) {
            $this->telegram->sendMessage(
                $chatId,
                __('Only organizers and the welcome crew of a coming night can use /door.', 'eventcrew')
            );

            return;
        }

        $date = trim($date);
        $text = '' === $date
            ? __('🚪 The door: choose what to open.', 'eventcrew')
            /* translators: %s: the night as typed after /door, e.g. 1.8. */
            : sprintf(__('🚪 The door on %s: choose what to open.', 'eventcrew'), $date);

        $this->telegram->sendMessage($telegramUserId, $text, MenuButtons::door($date));
        $this->sentDmNote($chatId, $isPrivate);
    }

    /**
     * /people (organizers only): the credits, roles and eligibility buttons,
     * in the DM.
     */
    public function onPeople(int $telegramUserId, int $chatId, bool $isPrivate): void
    {
        $person = 0 !== $telegramUserId ? $this->people->findByTelegramUserId($telegramUserId) : null;

        if (null === $person || ! $person->isOrganizer) {
            $this->telegram->sendMessage($chatId, __('Only organizers can use /people.', 'eventcrew'));

            return;
        }

        $this->telegram->sendMessage(
            $telegramUserId,
            __('👥 People: choose what to do.', 'eventcrew'),
            MenuButtons::people()
        );
        $this->sentDmNote($chatId, $isPrivate);
    }

    /** Stops the spinner on a tapped MenuButtons button. */
    public function answerTap(string $callbackQueryId): void
    {
        if ('' !== $callbackQueryId) {
            $this->telegram->answerCallbackQuery($callbackQueryId);
        }
    }

    /**
     * The answer to the Cancel button. $stopped is true when at least one question was
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
            __('/board – the open tasks; in a private chat also the tasks of your roles', 'eventcrew'),
            __('/me – your tasks, credits and standing', 'eventcrew'),
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __('   buttons: 🎟 free-entry ticket, 🔁 take over a task, 🔑 web sign-in, 🔔 notifications, 🌐 language', 'eventcrew'),
            __('/me history – your past tasks', 'eventcrew'),
            __('/me tickets – your tickets for coming nights', 'eventcrew'),
            __('/help – this list', 'eventcrew'),
        ]);
    }

    private function welcomeCrew(): string
    {
        return implode("\n", [
            __('Welcome crew  (date: 1.8. or 2026-08-01; default: next night)', 'eventcrew'),
            __('/roster [date] – who works that night; mark ✓ done, ⏰ late, ✗ no-show', 'eventcrew'),
            __('/door [date] – buttons: 📋 door list, 👥 guest list, 💳 sales desk', 'eventcrew'),
        ]);
    }

    private function organizers(): string
    {
        return implode("\n", [
            __('Organizers', 'eventcrew'),
            __('/door add [date] Name +N ; note – add a guest (also edit, remove)', 'eventcrew'),
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __('/people – buttons: 🎁 give credits, 🏷 roles, organizers and passes, ✅ who may hold each role', 'eventcrew'),
            __('/board in a private chat – also ➕ to put a person on a task', 'eventcrew'),
        ]);
    }
}

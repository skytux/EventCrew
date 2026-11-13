<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Telegram\GuestListService;
use EventCrew\Telegram\NightCommands;
use EventCrew\Telegram\PersonCommands;
use EventCrew\Telegram\TaskCommand;
use EventCrew\Telegram\TicketRedemptionService;

/**
 * The changes the Crew app's helper box runs on Confirm: an organizer's
 * (AssistantWebActions::CHANGES and /door add, edit or remove), and
 * anyone's own free-entry ticket (menu:ticket [date]). Each goes
 * to the bot command that does it in the chat, with the same checks,
 * question and services, so the page and the bot never differ. Where the
 * bot would show choice buttons (several people, tasks or events match),
 * the page asks to write it more exactly instead.
 */
final class AssistantWebChanges implements WebChanges
{
    public function __construct(
        private readonly PersonCommands $people,
        private readonly NightCommands $nights,
        private readonly TaskCommand $tasks,
        private readonly GuestListService $guests,
        private readonly TicketRedemptionService $tickets
    ) {
    }

    public function prepare(Person $organizer, string $action): array
    {
        [$head, $rest] = self::split($action);

        // Anyone's own ticket, not an organizer command.
        if (AssistantWebActions::OWN_TICKET === $head) {
            return $this->tickets->prepareWeb($organizer, self::date($rest));
        }

        if (! $organizer->isOrganizer || ! AssistantWebActions::isChange('/' . $head, $rest)) {
            return ['ok' => false, 'text' => __('That can no longer be done.', 'eventcrew'), 'state' => []];
        }

        return match (true) {
            'door' === $head => $this->guests->prepareWeb($organizer, $rest),
            in_array($head, PersonCommands::WEB, true) => $this->people->prepareWeb($head, $rest),
            in_array($head, NightCommands::COMMANDS, true) => $this->nights->prepareWeb($head, $rest),
            default => $this->tasks->prepareWeb($head, $rest),
        };
    }

    public function run(Person $organizer, string $action, array $state): array
    {
        [$head, $rest] = self::split($action);

        if (AssistantWebActions::OWN_TICKET === $head) {
            return $this->tickets->runWeb($organizer, $state);
        }

        if (! $organizer->isOrganizer || ! AssistantWebActions::isChange('/' . $head, $rest)) {
            return ['changed' => false, 'text' => __('That can no longer be done.', 'eventcrew')];
        }

        return match (true) {
            'door' === $head => $this->guests->runWeb($organizer, $state),
            in_array($head, PersonCommands::WEB, true) => $this->people->runWeb($organizer, $state),
            in_array($head, NightCommands::COMMANDS, true) => $this->nights->runWeb($state),
            default => $this->tasks->runWeb($state),
        };
    }

    /**
     * "Several match: A, B. Write it more exactly." The page has no choice
     * buttons, so a command that matches more than one person, task or event
     * is answered with this instead of a question.
     *
     * @param list<string> $labels
     */
    public static function several(array $labels): string
    {
        /* translators: %s: the matching people, tasks or events, comma separated. */
        return sprintf(__('Several match: %s. Write it more exactly.', 'eventcrew'), implode(', ', $labels));
    }

    /** A checked line's date (Y-m-d, as AssistantActions writes it), or ''. */
    private static function date(string $rest): string
    {
        return 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $rest) ? $rest : '';
    }

    /**
     * The command without its slash, lowercased, and the rest of the line.
     *
     * @return array{0: string, 1: string}
     */
    private static function split(string $action): array
    {
        $words = explode(' ', trim((string) preg_replace('/\s+/u', ' ', $action)), 2);

        return [strtolower(ltrim($words[0], '/')), trim($words[1] ?? '')];
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Telegram\AssignCommand;
use EventCrew\Telegram\FollowupCommand;
use EventCrew\Telegram\GuestListService;
use EventCrew\Telegram\MemberCommands;
use EventCrew\Telegram\NewsletterCommand;
use EventCrew\Telegram\NightCommands;
use EventCrew\Telegram\PersonCommands;
use EventCrew\Telegram\SayCommand;
use EventCrew\Telegram\SubscribeCommand;
use EventCrew\Telegram\TaskCommand;
use EventCrew\Telegram\TellCommand;
use EventCrew\Telegram\TicketRedemptionService;

/**
 * The changes the Crew app's helper box runs on Confirm: an organizer's
 * (AssistantWebActions::CHANGES, CHANGES_WITH_TEXT with their text and
 * /door add, edit or remove; the person changes of AssistantWebPeople),
 * and anyone's own (menu:ticket [date], and /name or menu:language with
 * the new value; the parityB lines of AssistantWebParity). Each goes to the bot
 * command that does it in the chat, with the same checks, question and
 * services, so the page and the bot never differ. Where the bot would show
 * choice buttons (several people, tasks or marks match), the page shows
 * them too, at most WebChanges::MAX_CHOICES; more asks to write it more
 * exactly.
 */
final class AssistantWebChanges implements WebChanges
{
    /** Commands whose text keeps its line breaks (as AssistantActions::validate() keeps them). */
    private const TEXT_COMMANDS = ['say', 'tell', 'newsletter', 'followup'];

    public function __construct(
        private readonly PersonCommands $people,
        private readonly NightCommands $nights,
        private readonly TaskCommand $tasks,
        private readonly GuestListService $guests,
        private readonly TicketRedemptionService $tickets,
        private readonly AssignCommand $assign,
        private readonly SayCommand $say,
        private readonly TellCommand $tell,
        private readonly SubscribeCommand $subscribe,
        private readonly NewsletterCommand $newsletter,
        private readonly FollowupCommand $followup,
        private readonly MemberCommands $members,
        private readonly AssistantWebPeople $personChanges,
        // parityA: the Admin tab's other changes.
        private readonly ?AssistantWebAdmin $adminChanges = null,
        private readonly ?AssistantWebParity $parity = null
    ) {
    }

    public function prepare(Person $organizer, string $action): array
    {
        [$head, $rest] = self::split($action);

        // parityB
        if (null !== $this->parity && AssistantWebParity::handles(self::command($head), $rest, $organizer->isOrganizer)) {
            return $this->parity->prepare($organizer, self::command($head), $rest);
        }

        // Anyone's own ticket, name, email or language: not organizer commands.
        if (AssistantWebActions::OWN_TICKET === $head) {
            return $this->tickets->prepareWeb($organizer, self::date($rest));
        }

        if (in_array(self::command($head), AssistantWebActions::OWN_CHANGES, true)) {
            return '' === $rest ? self::gone() : $this->members->prepareWeb($organizer, $head, $rest);
        }

        if (! $organizer->isOrganizer || ! AssistantWebActions::isChange(self::command($head), $rest)) {
            return self::gone();
        }

        return match (true) {
            'door' === $head => $this->guests->prepareWeb($organizer, $rest),
            in_array($head, [AssignCommand::ADD, AssignCommand::REMOVE], true) => $this->assign->prepareWeb($head, $rest),
            in_array($head, PersonCommands::WEB, true) => $this->people->prepareWeb($head, $rest),
            in_array($head, NightCommands::COMMANDS, true) => $this->nights->prepareWeb($head, $rest),
            'say' === $head => $this->say->prepareWeb($rest),
            TellCommand::COMMAND === $head => $this->tell->prepareWeb($organizer, $rest),
            SubscribeCommand::COMMAND === $head => $this->subscribe->prepareWeb($rest),
            'newsletter' === $head => $this->newsletter->prepareWeb($rest),
            'followup' === $head => $this->followup->prepareWeb($rest),
            in_array($head, AssistantWebPeople::COMMANDS, true) => $this->personChanges->prepare($organizer, $head, $rest),
            // parityA
            in_array($head, AssistantWebAdmin::COMMANDS, true) => null === $this->adminChanges
                ? self::gone()
                : $this->adminChanges->prepare($organizer, $head, $rest),
            default => $this->tasks->prepareWeb($head, $rest),
        };
    }

    public function choose(Person $organizer, string $action, array $state): array
    {
        [$head, $rest] = self::split($action);

        // parityB
        if (null !== $this->parity && AssistantWebParity::handles(self::command($head), $rest, $organizer->isOrganizer)) {
            return $this->parity->choose($organizer, self::command($head), $state);
        }

        if (! $organizer->isOrganizer || ! AssistantWebActions::isChange(self::command($head), $rest)) {
            return self::gone();
        }

        return match (true) {
            in_array($head, [AssignCommand::ADD, AssignCommand::REMOVE], true) => $this->assign->chooseWeb($state),
            in_array($head, PersonCommands::WEB, true) => $this->people->chooseWeb($state),
            TellCommand::COMMAND === $head => $this->tell->chooseWeb($organizer, $state),
            in_array($head, AssistantWebPeople::COMMANDS, true) => $this->personChanges->choose($organizer, $state),
            // parityA
            in_array($head, AssistantWebAdmin::COMMANDS, true) && null !== $this->adminChanges
                => $this->adminChanges->choose($organizer, $state),
            default => self::gone(),
        };
    }

    public function run(Person $organizer, string $action, array $state): array
    {
        [$head, $rest] = self::split($action);

        // parityB
        if (null !== $this->parity && AssistantWebParity::handles(self::command($head), $rest, $organizer->isOrganizer)) {
            return $this->parity->run($organizer, self::command($head), $state);
        }

        if (AssistantWebActions::OWN_TICKET === $head) {
            return $this->tickets->runWeb($organizer, $state);
        }

        if (in_array(self::command($head), AssistantWebActions::OWN_CHANGES, true)) {
            return $this->members->runWeb($organizer, $state);
        }

        if (! $organizer->isOrganizer || ! AssistantWebActions::isChange(self::command($head), $rest)) {
            return ['changed' => false, 'text' => __('That can no longer be done.', 'eventcrew')];
        }

        return match (true) {
            'door' === $head => $this->guests->runWeb($organizer, $state),
            in_array($head, [AssignCommand::ADD, AssignCommand::REMOVE], true) => $this->assign->runWeb($organizer, $state),
            in_array($head, PersonCommands::WEB, true) => $this->people->runWeb($organizer, $state),
            in_array($head, NightCommands::COMMANDS, true) => $this->nights->runWeb($state),
            'say' === $head => $this->say->runWeb($state),
            TellCommand::COMMAND === $head => $this->tell->runWeb($organizer, $state),
            SubscribeCommand::COMMAND === $head => $this->subscribe->runWeb($state),
            'newsletter' === $head => $this->newsletter->runWeb($organizer, $state),
            'followup' === $head => $this->followup->runWeb($organizer, $state),
            in_array($head, AssistantWebPeople::COMMANDS, true) => $this->personChanges->run($organizer, $state),
            // parityA
            in_array($head, AssistantWebAdmin::COMMANDS, true) => null === $this->adminChanges
                ? ['changed' => false, 'text' => __('That can no longer be done.', 'eventcrew')]
                : $this->adminChanges->run($organizer, $state),
            default => $this->tasks->runWeb($state),
        };
    }

    /**
     * "Several match: A, B. Write it more exactly.": the answer when more
     * people, tasks or events match than the page shows buttons for, or
     * where the command has no choice step.
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
     * The command without its slash, lowercased, and the rest of the line:
     * a message or newsletter text keeps its line breaks, anything else is
     * one line.
     *
     * @return array{0: string, 1: string}
     */
    private static function split(string $action): array
    {
        if (1 !== preg_match('/^\s*(\S+)\s*(.*)$/su', $action, $m)) {
            return ['', ''];
        }

        $head = strtolower(ltrim($m[1], '/'));
        $rest = trim($m[2]);

        if (! in_array($head, self::TEXT_COMMANDS, true)) {
            $rest = trim((string) preg_replace('/\s+/u', ' ', $rest));
        }

        return [$head, $rest];
    }

    /** The command as AssistantWebActions writes it: "/say", "menu:gift". */
    private static function command(string $head): string
    {
        return str_starts_with($head, 'menu:') ? $head : '/' . $head;
    }

    /** @return array{ok: bool, text: string, state: array<string, mixed>} */
    private static function gone(): array
    {
        return ['ok' => false, 'text' => __('That can no longer be done.', 'eventcrew'), 'state' => []];
    }
}

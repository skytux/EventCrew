<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\TakeoverService;
use EventCrew\Support\TicketTransferService;
use EventCrew\Telegram\CommandFlow;
use EventCrew\Telegram\MemberCommands;
use EventCrew\Telegram\ParityCommands;
use EventCrew\Telegram\TransferBot;
use EventCrew\Telegram\TransferPage;

/**
 * The helper box's changes for the Crew app actions it only linked to, or
 * had no line for (parityB), each asked with Confirm and run here through
 * the same services as the page's own buttons:
 * - menu:signout: Sign out everywhere else (this browser stays signed in);
 * - menu:replace <task>: ask to take over someone's place on a task;
 * - menu:notifications (or /notify) <type> <channel> <on|off>, or
 *   <app|telegram|both>: one notification switch, or where they go. These
 *   run at once, with no Confirm, as /notify does: easy to undo (flow1114);
 * - /giveaway [date] [person or email] [; their name], /giveaway cancel
 *   [date]: 🎁 Give away and ✖️ Cancel offer;
 * - organizers: /markall, /buyers, /testmail, /checkmail (ParityCommands).
 *
 * menu:sales is not here: on the page it shows the Open sales desk button
 * (AssistantWebActions::PLACES, flow1114).
 */
final class AssistantWebParity
{
    /** Lines that run here with a value after them; alone, each opens its place. */
    public const WITH_VALUE = ['menu:replace', 'menu:notifications'];

    /** Organizer lines, run through ParityCommands. */
    private const ORGANIZER = ['/markall', '/buyers', '/testmail', '/checkmail'];

    public function __construct(
        private readonly PersonRepository $people,
        private readonly TaskRepository $tasks,
        private readonly TakeoverService $takeovers,
        private readonly TicketTransferService $transfers,
        private readonly ParityCommands $parity
    ) {
    }

    /** Whether the line ($head lowercased, with its slash) is one of these. */
    public static function handles(string $head, string $rest, bool $organizer): bool
    {
        return match ($head) {
            'menu:signout' => '' === $rest,
            'menu:replace' => 1 === preg_match('/^\d{1,10}$/', $rest),
            'menu:notifications', '/notify' => '' !== $rest,
            '/giveaway' => true,
            default => $organizer && in_array($head, self::ORGANIZER, true),
        };
    }

    /**
     * A notification line runs here at once (flow1114): the answer then has
     * "ran" true, "changed" whether it was set, and no state to confirm.
     *
     * @return array{ok: bool, text: string, state: array<string, mixed>, choices?: list<array{label: string, state: array<string, mixed>}>, ran?: bool, changed?: bool}
     */
    public function prepare(Person $person, string $head, string $rest): array
    {
        if (! self::handles($head, $rest, $person->isOrganizer)) {
            return self::gone();
        }

        switch ($head) {
            case 'menu:signout':
                return self::ask(MemberCommands::signOutWebQuestion(), ['op' => 'signout']);

            case 'menu:replace':
                return $this->prepareReplace($person, (int) $rest);

            case 'menu:notifications':
            case '/notify':
                // flow1114: set at once, like /notify; the answer says what changed.
                if ('' === ParityCommands::notifyQuestion($person, $rest)) {
                    return self::gone();
                }

                $done = $this->parity->notify($person, $rest);

                return ['ok' => false, 'text' => $done['text'], 'state' => [], 'ran' => true, 'changed' => $done['done']];

            case '/giveaway':
                return $this->prepareGiveAway($person, $rest);
        }

        return $this->parity->prepareWeb(ltrim($head, '/'), $rest);
    }

    /**
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, state: array<string, mixed>, choices?: list<array{label: string, state: array<string, mixed>}>}
     */
    public function choose(Person $person, string $head, array $state): array
    {
        if ('/giveaway' === $head) {
            return $this->giveAwayStep($person, $state);
        }

        if ('menu:replace' === $head) {
            return self::ask($this->replaceQuestion($state), $state);
        }

        if ($person->isOrganizer && in_array($head, self::ORGANIZER, true)) {
            return $this->parity->chooseWeb($state);
        }

        return self::gone();
    }

    /**
     * @param array<string, mixed> $state
     * @return array{changed: bool, text: string}
     */
    public function run(Person $person, string $head, array $state): array
    {
        switch ((string) ($state['op'] ?? '')) {
            case 'signout':
                // flow1114: every other session ends; this browser gets a fresh cookie.
                OtherSessions::signOut($this->people, $person);

                return ['changed' => true, 'text' => SignupController::noticeText('sessions_revoked')];

            case 'replace':
                $result = $this->takeovers->requestFor((int) ($state['assignment'] ?? 0), $person);

                return ['changed' => $result['ok'], 'text' => $result['text']];

            case 'notify':
                $done = $this->parity->notify($person, (string) ($state['args'] ?? ''));

                return ['changed' => $done['done'], 'text' => $done['text']];

            case 'give':
                return $this->runGive($person, $state);

            case 'cancel_offer':
                $code = $this->transfers->cancelOffer((int) ($state['rid'] ?? 0), $person->id);

                return TicketTransferService::OK === $code
                    ? ['changed' => true, 'text' => __('Offer cancelled. Your ticket is yours again.', 'eventcrew')]
                    : ['changed' => false, 'text' => TicketTransferService::message($code)];
        }

        if ($person->isOrganizer && in_array($head, self::ORGANIZER, true)) {
            return $this->parity->runWeb($person, $state);
        }

        return ['changed' => false, 'text' => __('That can no longer be done.', 'eventcrew')];
    }

    /**
     * @return array{ok: bool, text: string, state: array<string, mixed>, choices?: list<array{label: string, state: array<string, mixed>}>}
     */
    private function prepareReplace(Person $person, int $taskId): array
    {
        $task = $this->tasks->find($taskId);

        if (! $task instanceof Task) {
            return self::refused(__('That task is no longer available.', 'eventcrew'));
        }

        $holders = $this->takeovers->holdersFor($task, $person);

        if ([] === $holders) {
            return self::refused(__('No task here can be taken over.', 'eventcrew'));
        }

        $states = [];

        foreach ($holders as $holder) {
            $states[] = ['op' => 'replace', 'assignment' => $holder['assignment_id'], 'name' => $holder['name'], 'task' => $task->id];
        }

        if (1 === count($states)) {
            return self::ask($this->replaceQuestion($states[0]), $states[0]);
        }

        return self::choices(
            __('Whose task do you want to take? They decide.', 'eventcrew'),
            array_map(static fn (array $state): string => (string) $state['name'], $states),
            $states
        );
    }

    /** @param array<string, mixed> $state */
    private function replaceQuestion(array $state): string
    {
        $task = $this->tasks->find((int) ($state['task'] ?? 0));

        return sprintf(
            /* translators: 1: a person's name, 2: a task with its day and time. */
            __('Ask %1$s to let you take over their task %2$s? They decide.', 'eventcrew'),
            (string) ($state['name'] ?? ''),
            null === $task ? '' : CommandFlow::taskLabel($task)
        );
    }

    /**
     * /giveaway: the ticket (the night) first, then who gets it.
     *
     * @return array{ok: bool, text: string, state: array<string, mixed>, choices?: list<array{label: string, state: array<string, mixed>}>}
     */
    private function prepareGiveAway(Person $giver, string $rest): array
    {
        $args = ParityCommands::giveAwayArgs($rest);

        if (! TicketTransferService::isOn()) {
            return self::refused(TicketTransferService::message(TicketTransferService::DISABLED));
        }

        if (! $args['cancel'] && '' === $args['who']) {
            return self::refused(TransferPage::askText());
        }

        $tickets = $this->parity->tickets($giver, $args['date'], $args['cancel']);

        if ([] === $tickets) {
            return self::refused(
                $args['cancel']
                    ? __('No offer of your tickets is waiting.', 'eventcrew')
                    : TicketTransferService::message(TicketTransferService::NOT_GIVABLE)
            );
        }

        $base = ['op' => $args['cancel'] ? 'cancel_offer' : 'night', 'who' => $args['who'], 'their' => $args['their']];
        $states = [];
        $labels = [];

        foreach ($tickets as $ticket) {
            $states[] = ['rid' => $ticket->id] + $base;
            $labels[] = $this->transfers->nightLabel((string) $ticket->redeemedFor);
        }

        if (1 === count($states)) {
            return $this->giveAwayStep($giver, $states[0]);
        }

        return self::choices(__('Which night?', 'eventcrew'), $labels, $states);
    }

    /**
     * The next step once the ticket is known: the question to cancel the
     * offer, or who gets it (members to choose from, or the question).
     *
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, state: array<string, mixed>, choices?: list<array{label: string, state: array<string, mixed>}>}
     */
    private function giveAwayStep(Person $giver, array $state): array
    {
        $ticket = $this->transfers->redemption((int) ($state['rid'] ?? 0));

        if (null === $ticket || $ticket->personId !== $giver->id) {
            return self::gone();
        }

        $night = $this->transfers->nightLabel((string) $ticket->redeemedFor);
        $op = (string) ($state['op'] ?? '');

        if ('cancel_offer' === $op) {
            return self::ask(ParityCommands::cancelQuestion($night), ['op' => 'cancel_offer', 'rid' => $ticket->id]);
        }

        if ('give' === $op && ! empty($state['person'])) {
            $member = $this->people->find((int) $state['person']);

            return null === $member
                ? self::gone()
                : self::ask(TransferPage::confirmMemberText($member->name(), $night), $state);
        }

        if ('night' !== $op) {
            return self::gone();
        }

        $who = (string) ($state['who'] ?? '');
        $base = ['op' => 'give', 'rid' => $ticket->id];

        if (str_contains($who, '@') && ! str_starts_with($who, '@')) {
            $email = strtolower($who);

            if (! is_email($email) || Person::isPlaceholderEmail($email)) {
                return self::refused(TicketTransferService::message(TicketTransferService::BAD_EMAIL));
            }

            $member = $this->people->findByEmail($email);

            if (null !== $member && $member->id === $giver->id) {
                return self::refused(TicketTransferService::message(TicketTransferService::SELF));
            }

            if (null !== $member) {
                return self::ask(TransferPage::confirmMemberText($member->name(), $night), $base + ['person' => $member->id]);
            }

            $name = TicketTransferService::cleanName((string) ($state['their'] ?? ''));

            if ('' === $name) {
                return self::refused(
                    TicketTransferService::message(TicketTransferService::NO_THEIR_NAME)
                        /* translators: %s: an email address. */
                        . "\n" . sprintf(__('Write it after a semicolon: %s ; Their Name', 'eventcrew'), $email)
                );
            }

            return self::ask(
                TransferPage::confirmEmailText($name . ' (' . $email . ')', $night),
                $base + ['email' => $email, 'name' => $name]
            );
        }

        $matches = TransferPage::matches($this->people, $who, $giver->id);

        if ([] === $matches) {
            return self::refused(TransferPage::noMatchText());
        }

        if (1 === count($matches)) {
            return self::ask(TransferPage::confirmMemberText($matches[0]->name(), $night), $base + ['person' => $matches[0]->id]);
        }

        return self::choices(
            __('Who gets your ticket?', 'eventcrew'),
            array_map(static fn (Person $member): string => $member->name(), $matches),
            array_map(static fn (Person $member): array => $base + ['person' => $member->id], $matches)
        );
    }

    /**
     * @param array<string, mixed> $state
     * @return array{changed: bool, text: string}
     */
    private function runGive(Person $giver, array $state): array
    {
        $redemptionId = (int) ($state['rid'] ?? 0);
        $result = empty($state['person'])
            ? $this->transfers->offerToEmail($redemptionId, $giver->id, (string) ($state['email'] ?? ''), (string) ($state['name'] ?? ''))
            : $this->transfers->giveToMember($redemptionId, $giver->id, (int) $state['person']);

        if (TicketTransferService::OK !== $result['code'] || null === $result['transfer']) {
            return ['changed' => false, 'text' => TicketTransferService::message($result['code'])];
        }

        return ['changed' => true, 'text' => TransferBot::doneFor($this->transfers, $result['transfer'])];
    }


    /**
     * @param list<string> $labels
     * @param list<array<string, mixed>> $states
     * @return array{ok: bool, text: string, state: array<string, mixed>, choices?: list<array{label: string, state: array<string, mixed>}>}
     */
    private static function choices(string $question, array $labels, array $states): array
    {
        if (count($states) > WebChanges::MAX_CHOICES) {
            return self::refused(AssistantWebChanges::several($labels));
        }

        $choices = [];

        foreach ($states as $i => $state) {
            $choices[] = ['label' => $labels[$i], 'state' => $state];
        }

        return ['ok' => false, 'text' => $question, 'state' => [], 'choices' => $choices];
    }

    /**
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, state: array<string, mixed>}
     */
    private static function ask(string $text, array $state): array
    {
        return ['ok' => true, 'text' => $text, 'state' => $state];
    }

    /** @return array{ok: bool, text: string, state: array<string, mixed>} */
    private static function refused(string $text): array
    {
        return ['ok' => false, 'text' => $text, 'state' => []];
    }

    /** @return array{ok: bool, text: string, state: array<string, mixed>} */
    private static function gone(): array
    {
        return self::refused(__('That can no longer be done.', 'eventcrew'));
    }
}

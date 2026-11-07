<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;

/**
 * Restricted-role tasks (Organize, and every role set to "Only people with this
 * role", such as DJ or VJ), for the bot and the crew page alike.
 *
 * Two jobs: the list of upcoming restricted tasks one person may take, and an
 * organizer putting someone on any upcoming task - a member, or a person who is
 * not a crew member yet. That second person is created here with a name and an
 * optional email and signed up; on a restricted task they are also given the
 * role. An organizer's sign-up skips the role check and the standing gate. With
 * an email they get the normal signup email with their ticket; without one
 * nothing is sent, as there is nowhere to send it.
 */
final class RoleTaskService
{
    public const ADDED = 'added';
    public const ALREADY_ON = 'already_on';
    public const NO_NAME = 'no_name';
    public const BAD_EMAIL = 'bad_email';
    public const NOT_RESTRICTED = 'not_restricted';
    public const ORGANIZERS_ONLY = 'organizers_only';
    public const FULL = 'full';
    public const FAILED = 'failed';
    public const NO_PERSON = 'no_person';

    /**
     * The domain of the placeholder address a person added without an email
     * gets. The people table needs a unique email; ".invalid" is reserved and
     * can never deliver, and Mailer refuses to send to it.
     */
    private const PLACEHOLDER_DOMAIN = 'added.invalid';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly SignupService $signup,
        private readonly ClaimNotifier $notifier
    ) {
    }

    /**
     * The upcoming restricted tasks this person holds the role for, soonest
     * first. Empty for anyone without a restricted role.
     *
     * @return list<Task>
     */
    public function tasksFor(Person $person): array
    {
        return self::ownOf($person, $this->tasks->upcoming());
    }

    /**
     * The restricted tasks of $tasks this person holds the role for, in the
     * order given (tasksFor() over a list already read).
     *
     * @param array<int, Task> $tasks
     * @return list<Task>
     */
    public static function ownOf(Person $person, array $tasks): array
    {
        return array_values(array_filter(
            $tasks,
            static fn (Task $task): bool => $task->isRestricted() && $person->hasRole($task->roleSlug)
        ));
    }

    /**
     * Every upcoming task, for an organizer adding someone. Organize tasks are
     * left out: putting a new person on one would make them an organizer,
     * which is done in wp-admin or with /people, not here.
     *
     * @return list<Task>
     */
    public function addableTasks(): array
    {
        return self::addableOf($this->tasks->upcoming());
    }

    /**
     * The tasks of $tasks an organizer can add someone to (addableTasks()
     * over a list already read).
     *
     * @param array<int, Task> $tasks
     * @return list<Task>
     */
    public static function addableOf(array $tasks): array
    {
        return array_values(array_filter(
            $tasks,
            static fn (Task $task): bool => Roles::ORGANIZE_SLUG !== $task->roleSlug
        ));
    }

    /**
     * Whether this person is on this task now (signed up or arrived).
     */
    public function isOn(int $taskId, int $personId): bool
    {
        $assignment = $this->assignments->findFor($taskId, $personId);

        return null !== $assignment && $assignment->isOccupying();
    }

    /**
     * An organizer puts someone on a task (any but Organize). An email that
     * already belongs to a member reuses that member (the name is then
     * ignored); otherwise a new person is made with the name, and the email if
     * one was given. On a restricted task the person gets the task's role.
     * They are then signed up past the role check and the standing gate, with
     * the organizer's answer to "Count toward free-entry credit?" (only used
     * on a restricted task; every other task always counts).
     *
     * With $personId above 0 that existing person is added and the name and
     * email are not used (the crew page's member picker).
     *
     * @return array{code: string, person: ?Person, emailed: bool}
     */
    public function addPerson(
        Person $organizer,
        int $taskId,
        string $name,
        string $email,
        bool $countsCredit,
        int $personId = 0
    ): array {
        $fail = static fn (string $code): array => ['code' => $code, 'person' => null, 'emailed' => false];

        if (! $organizer->isOrganizer) {
            return $fail(self::ORGANIZERS_ONLY);
        }

        $task = $taskId > 0 ? $this->tasks->find($taskId) : null;

        if (null === $task || Roles::ORGANIZE_SLUG === $task->roleSlug) {
            return $fail(self::NOT_RESTRICTED);
        }

        if ($personId > 0) {
            $person = $this->people->find($personId);

            if (null === $person || $person->isAnonymized()) {
                return $fail(self::NO_PERSON);
            }

            return $this->signUp($person, $task, $countsCredit, $organizer->name());
        }

        $name = trim(sanitize_text_field($name));
        $email = trim($email);

        if ('' !== $email) {
            $email = sanitize_email($email);

            if ('' === $email || ! is_email($email)) {
                return $fail(self::BAD_EMAIL);
            }
        }

        $person = '' !== $email ? $this->people->findByEmail($email) : null;

        if (null === $person) {
            if ('' === $name) {
                return $fail(self::NO_NAME);
            }

            $personId = $this->people->create([
                'email' => '' !== $email ? $email : $this->placeholderEmail(),
                'display_name' => $name,
            ]);
            $person = $personId > 0 ? $this->people->find($personId) : null;
        }

        if (null === $person) {
            return $fail(self::FAILED);
        }

        return $this->signUp($person, $task, $countsCredit, $organizer->name());
    }

    /**
     * Gives the task's role if needed, signs the person up, and emails the
     * ticket when they have a real address.
     *
     * @return array{code: string, person: ?Person, emailed: bool}
     */
    private function signUp(Person $person, Task $task, bool $countsCredit, string $giverName): array
    {
        if ($task->isRestricted() && ! $person->hasRole($task->roleSlug)) {
            $roles = array_merge($person->roleSlugs, [$task->roleSlug]);
            $this->people->setRoles($person->id, $roles);
            RoleNotifier::rolesChanged($person->id, $person->roleSlugs, $roles, $giverName);
        }

        $outcome = $this->signup->claimByOrganizer($person->id, $task->id, $countsCredit);

        if (AssignmentRepository::JOIN_DUPLICATE === $outcome) {
            return ['code' => self::ALREADY_ON, 'person' => $person, 'emailed' => false];
        }

        if (AssignmentRepository::JOIN_FULL === $outcome) {
            return ['code' => self::FULL, 'person' => $person, 'emailed' => false];
        }

        if (! in_array($outcome, [AssignmentRepository::JOIN_OK, AssignmentRepository::JOIN_REJOINED], true)) {
            return ['code' => self::FAILED, 'person' => $person, 'emailed' => false];
        }

        $emailed = $person->hasRealEmail() && ! $person->isAnonymized();

        if ($emailed) {
            $this->notifier->confirmSignup($person, $task->id);
        }

        return ['code' => self::ADDED, 'person' => $person, 'emailed' => $emailed];
    }

    /**
     * A plain sentence for an addPerson() answer.
     */
    public static function text(string $code, bool $emailed = false): string
    {
        return match ($code) {
            self::ADDED => $emailed
                ? __('Added to the task. They got an email with their ticket.', 'eventcrew')
                : __('Added to the task. No email was given, so nothing was sent.', 'eventcrew'),
            self::ALREADY_ON => __('That person is already on this task.', 'eventcrew'),
            self::FULL => __('That task is full.', 'eventcrew'),
            self::NO_PERSON => __('That member was not found.', 'eventcrew'),
            self::NO_NAME => __('Give their name.', 'eventcrew'),
            self::BAD_EMAIL => __('That email address does not look right.', 'eventcrew'),
            self::NOT_RESTRICTED => __('That task is no longer available.', 'eventcrew'),
            self::ORGANIZERS_ONLY => __('Only organizers can add people to a task.', 'eventcrew'),
            default => __('The person could not be added. Try again.', 'eventcrew'),
        };
    }

    /**
     * A unique address that can never receive mail, for a person added with
     * no email.
     */
    private function placeholderEmail(): string
    {
        return 'added-' . strtolower(wp_generate_password(12, false, false)) . '@' . self::PLACEHOLDER_DOMAIN;
    }
}

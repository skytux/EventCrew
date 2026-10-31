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
 * organizer putting someone on such a task - a member, or a person who is not a
 * crew member yet. That second person is created here with a name and an
 * optional email, given the role, and signed up. With an email they get the
 * normal signup email with their ticket; without one nothing is sent, as there
 * is nowhere to send it.
 */
final class RoleTaskService
{
    public const ADDED = 'added';
    public const ALREADY_ON = 'already_on';
    public const NO_NAME = 'no_name';
    public const BAD_EMAIL = 'bad_email';
    public const NOT_RESTRICTED = 'not_restricted';
    public const ORGANIZERS_ONLY = 'organizers_only';
    public const FAILED = 'failed';

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
        return array_values(array_filter(
            $this->tasks->upcoming(),
            static fn (Task $task): bool => $task->isRestricted() && $person->hasRole($task->roleSlug)
        ));
    }

    /**
     * Every upcoming restricted task, for an organizer adding someone. Organize
     * tasks are left out: putting a new person on one would make them an
     * organizer, which is done in wp-admin or with /roles, not here.
     *
     * @return list<Task>
     */
    public function addableTasks(): array
    {
        return array_values(array_filter(
            $this->tasks->upcoming(),
            static fn (Task $task): bool => $task->isRestricted() && Roles::ORGANIZE_SLUG !== $task->roleSlug
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
     * An organizer puts someone on a restricted task. An email that already
     * belongs to a member reuses that member (the name is then ignored);
     * otherwise a new person is made with the name, and the email if one was
     * given. The person gets the task's role, then is signed up with the
     * organizer's answer to "Count toward free-entry credit?".
     *
     * @return array{code: string, person: ?Person, emailed: bool}
     */
    public function addPerson(Person $organizer, int $taskId, string $name, string $email, bool $countsCredit): array
    {
        $fail = static fn (string $code): array => ['code' => $code, 'person' => null, 'emailed' => false];

        if (! $organizer->isOrganizer) {
            return $fail(self::ORGANIZERS_ONLY);
        }

        $task = $taskId > 0 ? $this->tasks->find($taskId) : null;

        if (null === $task || ! $task->isRestricted() || Roles::ORGANIZE_SLUG === $task->roleSlug) {
            return $fail(self::NOT_RESTRICTED);
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

        if (! $person->hasRole($task->roleSlug)) {
            $this->people->setRoles($person->id, array_merge($person->roleSlugs, [$task->roleSlug]));
        }

        $outcome = $this->signup->claim($person->id, $task->id, $countsCredit);

        if (AssignmentRepository::JOIN_DUPLICATE === $outcome) {
            return ['code' => self::ALREADY_ON, 'person' => $person, 'emailed' => false];
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

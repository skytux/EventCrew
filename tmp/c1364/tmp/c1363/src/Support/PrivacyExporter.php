<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;

/**
 * Hands a person everything this plugin knows about them, through WordPress's
 * own Tools -> Export Personal Data.
 *
 * The counterpart to a delete that already existed. Deleting was the urgent
 * half - someone who wants out should not have to ask twice - but "what do you
 * actually hold on me" is the question that comes first, and until now the only
 * answer was a database client.
 *
 * Crew are not WordPress users, so none of this is reachable by core's own
 * exporters: identity here is a verified email address on the plugin's own
 * table, which is exactly what the export tool passes in.
 */
final class PrivacyExporter
{
    private const GROUP = 'eventcrew';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly AssignmentRepository $assignments,
        private readonly TaskRepository $tasks,
        private readonly RedemptionRepository $redemptions,
        private readonly CreditGrantRepository $credits
    ) {
    }

    public function boot(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [$this, 'register']);
    }

    /**
     * @param array<string, mixed> $exporters
     *
     * @return array<string, mixed>
     */
    public function register(array $exporters): array
    {
        $exporters['eventcrew'] = [
            'exporter_friendly_name' => __('EventCrew', 'eventcrew'),
            'callback' => [$this, 'export'],
        ];

        return $exporters;
    }

    /**
     * Everything held against one email address.
     *
     * Returned in a single page: a person's whole history here is a handful of
     * rows - the tasks they signed up for and the tickets they were given - not
     * the sort of volume that needs paging, and pretending otherwise would add
     * a resumption path with nothing to resume.
     *
     * @return array{data: array<int, array<string, mixed>>, done: bool}
     */
    public function export(string $email, int $page = 1): array
    {
        $person = $this->people->findByEmail($email);

        if (null === $person || $page > 1) {
            return ['data' => [], 'done' => true];
        }

        $data = [$this->profile($person->id, $email)];

        foreach ($this->history($person->id) as $item) {
            $data[] = $item;
        }

        foreach ($this->tickets($person->id) as $item) {
            $data[] = $item;
        }

        return ['data' => $data, 'done' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(int $personId, string $email): array
    {
        $person = $this->people->find($personId);
        $standing = null;

        if (null !== $person) {
            $standing = (new StandingCalculator(
                $this->assignments,
                $this->redemptions,
                $this->credits
            ))->for($personId);
        }

        $fields = [
            ['name' => __('Name', 'eventcrew'), 'value' => null === $person ? '' : $person->name()],
            ['name' => __('Email', 'eventcrew'), 'value' => $email],
            [
                'name' => __('Telegram account linked', 'eventcrew'),
                'value' => null !== $person && null !== $person->telegramUserId
                    ? __('Yes', 'eventcrew')
                    : __('No', 'eventcrew'),
            ],
        ];

        if (null !== $standing) {
            $fields[] = ['name' => __('Standing', 'eventcrew'), 'value' => $standing->ratedSummary()];
            $fields[] = [
                'name' => __('Free-entry credits', 'eventcrew'),
                'value' => (string) $standing->creditBalance,
            ];
            $fields[] = [
                'name' => __('Tasks completed', 'eventcrew'),
                'value' => (string) $standing->completedCount,
            ];
        }

        return [
            'group_id' => self::GROUP . '-profile',
            'group_label' => __('EventCrew profile', 'eventcrew'),
            'item_id' => self::GROUP . '-profile',
            'data' => $fields,
        ];
    }

    /**
     * Every task they ever took, and how each one turned out - which is the
     * whole of what their standing is calculated from.
     *
     * @return array<int, array<string, mixed>>
     */
    private function history(int $personId): array
    {
        $items = [];

        foreach ($this->assignments->historyFor($personId) as $index => $entry) {
            $assignment = $entry['assignment'];
            $task = $this->tasks->find($assignment->taskId);

            $items[] = [
                'group_id' => self::GROUP . '-tasks',
                'group_label' => __('EventCrew tasks', 'eventcrew'),
                'item_id' => self::GROUP . '-task-' . $index,
                'data' => [
                    ['name' => __('Date', 'eventcrew'), 'value' => (string) $entry['task_date']],
                    [
                        'name' => __('Task', 'eventcrew'),
                        'value' => null === $task ? '' : $task->roleLabel() . ' · ' . $task->eventName(),
                    ],
                    [
                        'name' => __('Outcome', 'eventcrew'),
                        'value' => AssignmentStatus::label($assignment->status),
                    ],
                    ['name' => __('Signed up', 'eventcrew'), 'value' => $assignment->signedUpAt],
                ],
            ];
        }

        return $items;
    }

    /**
     * Free-entry credits spent. The grants themselves are part of the standing
     * summary above; this is where each one went.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tickets(int $personId): array
    {
        $items = [];

        foreach ($this->redemptions->forPerson($personId) as $index => $redemption) {
            $items[] = [
                'group_id' => self::GROUP . '-tickets',
                'group_label' => __('EventCrew free entry', 'eventcrew'),
                'item_id' => self::GROUP . '-ticket-' . $index,
                'data' => [
                    ['name' => __('Event date', 'eventcrew'), 'value' => (string) $redemption->redeemedFor],
                    ['name' => __('Event', 'eventcrew'), 'value' => $redemption->eventLabel],
                    ['name' => __('Redeemed', 'eventcrew'), 'value' => $redemption->redeemedAt],
                ],
            ];
        }

        return $items;
    }
}

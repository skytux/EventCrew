<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\DoorNoteRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\TicketTransferRepository;
use EventCrew\Models\TicketTransfer;
use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\TicketBuyerRepository;

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
        private readonly CreditGrantRepository $credits,
        private readonly TicketBuyerRepository $buyers = new TicketBuyerRepository(),
        private readonly GuestRepository $guests = new GuestRepository(),
        private readonly NewsletterSubscriberRepository $subscribers = new NewsletterSubscriberRepository(),
        private readonly ?TicketTransferRepository $transfers = null,
        private readonly ?DoorNoteRepository $doorNotes = null
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
        if ($page > 1) {
            return ['data' => [], 'done' => true];
        }

        $person = $this->people->findByEmail($email);
        $data = [];

        if (null !== $person) {
            $data[] = $this->profile($person->id, $email);

            foreach ($this->history($person->id) as $item) {
                $data[] = $item;
            }

            foreach ($this->tickets($person->id) as $item) {
                $data[] = $item;
            }
        }

        // A ticket buyer from the web shop is usually not a crew member, so
        // their ticket rows are looked up by email whether or not a crew
        // record exists.
        foreach ($this->boughtTickets($email) as $item) {
            $data[] = $item;
        }

        // Guests from a mailed guest list that carried their email address.
        foreach ($this->guestTickets($email) as $item) {
            $data[] = $item;
        }

        // Free-entry tickets given to this address or to this member.
        foreach ($this->givenTickets($email, $person?->id ?? 0) as $item) {
            $data[] = $item;
        }

        // Names and addresses the door crew noted for someone who came in on
        // a given-away ticket or as a +1.
        foreach ($this->doorNotes($email) as $item) {
            $data[] = $item;
        }

        // The next events announcement: anyone can sign up with just an
        // address, so this too is looked up by email alone.
        $subscriber = $this->subscribers->findByEmail($email);

        if (null !== $subscriber) {
            $data[] = $this->subscription($subscriber);
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

        // What they said when joining, while the record keeps it (JoinAnswers).
        if (null !== $person && null !== JoinAnswers::of($person)['before']) {
            $fields[] = ['name' => __('Said when joining', 'eventcrew'), 'value' => JoinAnswers::line($person)];
        }

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
     * Tickets bought in the web shop, or added at the sales desk, under this
     * email address: one item per row of the ticket buyers table.
     *
     * @return array<int, array<string, mixed>>
     */
    private function boughtTickets(string $email): array
    {
        $items = [];

        foreach ($this->buyers->rowsForEmail($email) as $index => $row) {
            $items[] = [
                'group_id' => self::GROUP . '-bought-tickets',
                'group_label' => __('EventCrew bought tickets', 'eventcrew'),
                'item_id' => self::GROUP . '-bought-ticket-' . $index,
                'data' => [
                    ['name' => __('Event date', 'eventcrew'), 'value' => (string) ($row['event_date'] ?? '')],
                    ['name' => __('Name', 'eventcrew'), 'value' => (string) ($row['name'] ?? '')],
                    ['name' => __('Email', 'eventcrew'), 'value' => (string) ($row['email'] ?? '')],
                    ['name' => __('Ticket code', 'eventcrew'), 'value' => (string) ($row['ticket_code'] ?? '')],
                ],
            ];
        }

        return $items;
    }

    /**
     * Free-entry tickets given to this address or to member $personId: one
     * item per transfer.
     *
     * @return array<int, array<string, mixed>>
     */
    private function givenTickets(string $email, int $personId): array
    {
        if (null === $this->transfers) {
            return [];
        }

        $rows = [];

        foreach (array_merge($this->transfers->rowsForEmail($email), $this->transfers->heldBy($personId)) as $transfer) {
            $rows[$transfer->id] = $transfer;
        }

        $items = [];

        foreach (array_values($rows) as $index => $transfer) {
            /** @var TicketTransfer $transfer */
            $items[] = [
                'group_id' => self::GROUP . '-given-tickets',
                'group_label' => __('EventCrew given tickets', 'eventcrew'),
                'item_id' => self::GROUP . '-given-' . $index,
                'data' => [
                    ['name' => __('Event date', 'eventcrew'), 'value' => $transfer->eventDate],
                    ['name' => __('Name', 'eventcrew'), 'value' => $transfer->name],
                    ['name' => __('Email', 'eventcrew'), 'value' => $transfer->email],
                    ['name' => __('Status', 'eventcrew'), 'value' => $transfer->status],
                ],
            ];
        }

        return $items;
    }

    /**
     * Door notes under this email address: one item per note.
     *
     * @return array<int, array<string, mixed>>
     */
    private function doorNotes(string $email): array
    {
        if (null === $this->doorNotes) {
            return [];
        }

        $items = [];

        foreach ($this->doorNotes->rowsForEmail($email) as $index => $row) {
            $items[] = [
                'group_id' => self::GROUP . '-door-notes',
                'group_label' => __('EventCrew door notes', 'eventcrew'),
                'item_id' => self::GROUP . '-door-note-' . $index,
                'data' => [
                    ['name' => __('Event date', 'eventcrew'), 'value' => $row['event_date']],
                    ['name' => __('Name', 'eventcrew'), 'value' => $row['name']],
                    ['name' => __('Email', 'eventcrew'), 'value' => $row['email']],
                    [
                        'name' => __('Came in on', 'eventcrew'),
                        'value' => DoorNoteRepository::ROLE_RECEIVER === $row['role']
                            ? __('Given-away ticket', 'eventcrew')
                            : __('+1 guest', 'eventcrew'),
                    ],
                    [
                        'name' => __('Asked for the newsletter at the door', 'eventcrew'),
                        'value' => $row['newsletter'] ? __('Yes', 'eventcrew') : __('No', 'eventcrew'),
                    ],
                ],
            ];
        }

        return $items;
    }

    /**
     * Guest list places under this email address: one item per guest row.
     *
     * @return array<int, array<string, mixed>>
     */
    private function guestTickets(string $email): array
    {
        $items = [];

        foreach ($this->guests->rowsForEmail($email) as $index => $guest) {
            $items[] = [
                'group_id' => self::GROUP . '-guest-list',
                'group_label' => __('EventCrew guest list', 'eventcrew'),
                'item_id' => self::GROUP . '-guest-' . $index,
                'data' => [
                    ['name' => __('Event date', 'eventcrew'), 'value' => $guest->eventDate],
                    ['name' => __('Name', 'eventcrew'), 'value' => $guest->name],
                    ['name' => __('Email', 'eventcrew'), 'value' => $guest->email],
                    ['name' => __('Plus-ones', 'eventcrew'), 'value' => (string) $guest->plusOnes],
                ],
            ];
        }

        return $items;
    }

    /**
     * The announcement subscription: the address, its state, the name it is
     * greeted with and the language.
     *
     * @param array{email: string, status: string, created_at: string, confirmed_at: ?string,
     *     name: ?string, language: string} $row
     * @return array<string, mixed>
     */
    private function subscription(array $row): array
    {
        return [
            'group_id' => self::GROUP . '-announcement',
            'group_label' => __('EventCrew next events announcement', 'eventcrew'),
            'item_id' => self::GROUP . '-announcement',
            'data' => [
                ['name' => __('Email', 'eventcrew'), 'value' => $row['email']],
                ['name' => __('Status', 'eventcrew'), 'value' => $row['status']],
                ['name' => __('Name', 'eventcrew'), 'value' => (string) ($row['name'] ?? '')],
                ['name' => __('Language', 'eventcrew'), 'value' => $row['language']],
                ['name' => __('Signed up', 'eventcrew'), 'value' => $row['created_at']],
                ['name' => __('Confirmed', 'eventcrew'), 'value' => (string) ($row['confirmed_at'] ?? '')],
            ],
        ];
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

        foreach ($this->redemptions->allForPerson($personId) as $index => $redemption) {
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

<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Guest;
use EventCrew\Repositories\HistoryRepository;
use EventCrew\Repositories\PersonRepository;

/**
 * Who put someone on a night's door list, and when, as one short line:
 * "added by Lou · 9 Nov 14:02" (Owner 2026-11-11). The wp-admin door list,
 * the crew page's door list and the bot's door list show it under the entry.
 *
 * Only an entry someone else added gets a line; people who signed up or
 * spent a credit themselves get none, and so do ticket buyers (they bought
 * it themselves) and ticket transfers (their line already names the giver).
 *
 * - Crew: the history's sign-up row for each task they are on that night
 *   (assigned by someone else, or signed up themselves). Of their tasks, the
 *   one they joined first decides.
 * - Credits: the history's latest "credit spent" row for that person and night.
 * - Guests: the guest's added_by (a person) and created_at.
 *
 * One query per list: the night's history rows at once, and the guests'
 * adders at once. Who acted in wp-admin shows the WordPress user's display
 * name. An actor whose name is gone (erased, deleted) gives no line.
 */
final class DoorAddedBy
{
    public function __construct(
        private readonly HistoryRepository $history,
        private readonly PersonRepository $people
    ) {
    }

    /**
     * The lines for the crew and the credit spenders of $date.
     *
     * @param array<int, array<int, int>> $workerTasks person id => the ids of the tasks they are on that night
     * @param array<int, int> $creditPeople the person ids of the night's credit entries
     * @return array{workers: array<int, string>, credits: array<int, string>} person id => line ('' or left out: none)
     */
    public function forNight(string $date, array $workerTasks, array $creditPeople): array
    {
        $lines = ['workers' => [], 'credits' => []];

        if ([] === $workerTasks && [] === $creditPeople) {
            return $lines;
        }

        $joins = [];
        $credits = [];

        // Oldest first, so a later row (a rejoin, a second spend) replaces an earlier one.
        foreach ($this->history->doorAdds($date) as $row) {
            $personId = (int) ($row['person_id'] ?? 0);

            if ($personId <= 0) {
                continue;
            }

            if (History::CREDIT_REDEEMED === ($row['kind'] ?? '')) {
                $credits[$personId] = $row;

                continue;
            }

            $joins[$personId][(int) ($row['task_id'] ?? 0)] = $row;
        }

        foreach ($workerTasks as $personId => $taskIds) {
            $first = null;

            foreach ($taskIds as $taskId) {
                $row = $joins[$personId][$taskId] ?? null;

                if (null !== $row && (null === $first || (string) $row['created_at'] < (string) $first['created_at'])) {
                    $first = $row;
                }
            }

            $line = null === $first ? '' : $this->rowLine($first);

            if ('' !== $line) {
                $lines['workers'][$personId] = $line;
            }
        }

        foreach ($creditPeople as $personId) {
            $line = isset($credits[$personId]) ? $this->rowLine($credits[$personId]) : '';

            if ('' !== $line) {
                $lines['credits'][$personId] = $line;
            }
        }

        return $lines;
    }

    /**
     * The lines for a night's guests, keyed by guest id: a guest whose adder
     * is not known (added in wp-admin, or the adder is gone) has none.
     *
     * @param array<int, Guest> $guests
     * @return array<int, string>
     */
    public function forGuests(array $guests): array
    {
        $ids = [];

        foreach ($guests as $guest) {
            if (null !== $guest->addedBy) {
                $ids[] = $guest->addedBy;
            }
        }

        if ([] === $ids) {
            return [];
        }

        $people = $this->people->findMany($ids);
        $lines = [];

        foreach ($guests as $guest) {
            $person = null === $guest->addedBy ? null : ($people[$guest->addedBy] ?? null);

            if (null !== $person) {
                $line = self::line($person->name(), $guest->createdAt);

                if ('' !== $line) {
                    $lines[$guest->id] = $line;
                }
            }
        }

        return $lines;
    }

    /**
     * "added by Lou · 9 Nov 14:02" ("lisännyt Lou · 9.11. 14:02"), or '' when
     * the name or the time is missing. $createdAt is site time, as stored.
     */
    public static function line(string $name, string $createdAt): string
    {
        $when = Dates::signedUpLabel($createdAt);

        if ('' === $name || '' === $when) {
            return '';
        }

        /* translators: 1: name of who put the person on the door list, 2: day and time, like "9 Nov 14:02" */
        return sprintf(__('added by %1$s · %2$s', 'eventcrew'), $name, $when);
    }

    /**
     * The line for one history row, or '' when the person did it themselves
     * or who did it is not known.
     *
     * @param array<string, mixed> $row
     */
    private function rowLine(array $row): string
    {
        $personId = (int) ($row['person_id'] ?? 0);
        $actorPerson = (int) ($row['actor_person_id'] ?? 0);
        $actorWpUser = (int) ($row['actor_wp_user_id'] ?? 0);
        $createdAt = (string) ($row['created_at'] ?? '');

        if (History::ACTOR_WP_USER === ($row['actor_kind'] ?? '') && $actorWpUser > 0) {
            $user = function_exists('get_userdata') ? get_userdata($actorWpUser) : false;

            return false === $user ? '' : self::line((string) $user->display_name, $createdAt);
        }

        if ($actorPerson <= 0 || $actorPerson === $personId) {
            return '';
        }

        $name = (string) ($row['actor_name'] ?? '');

        if ('' === $name) {
            // As Person::name(): the email's local part when no name is set.
            $local = strstr((string) ($row['actor_email'] ?? ''), '@', true);
            $name = false === $local ? '' : $local;
        }

        return self::line($name, $createdAt);
    }
}

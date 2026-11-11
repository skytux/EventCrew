<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The words for one history row (HistoryRepository::page()), shared by the
 * History page in wp-admin and the bot's "Current status": what happened,
 * who did it, the task and the night.
 *
 * A person whose ids were emptied by an erasure is "someone (erased)"; a row
 * the schema 37 update made from older data, which cannot say who acted, has
 * no actor.
 */
final class HistoryText
{
    /**
     * What happened, without who did it: "Sam cancelled late",
     * "Kim took over from Sam", "Task changed: capacity".
     *
     * @param array<string, mixed> $row
     */
    public static function action(array $row): string
    {
        $kind = (string) ($row['kind'] ?? '');
        $details = self::details($row);
        $person = self::person($row, 'person_id', 'person_name');
        $other = self::person($row, 'other_person_id', 'other_name');

        switch ($kind) {
            case History::SIGNED_UP:
                /* translators: %s: a person's name */
                return sprintf(__('%s signed up', 'eventcrew'), $person);
            case History::ASSIGNED:
                /* translators: %s: a person's name */
                return sprintf(__('%s was put on the task', 'eventcrew'), $person);
            case History::CANCELLED:
                /* translators: %s: a person's name */
                return sprintf(__('%s cancelled', 'eventcrew'), $person);
            case History::LATE_CANCEL:
                /* translators: %s: a person's name */
                return sprintf(__('%s cancelled late', 'eventcrew'), $person);
            case History::TAKEN_OFF:
                return ! empty($details['task_deleted'])
                    /* translators: %s: a person's name */
                    ? sprintf(__('%s was taken off: the task was deleted', 'eventcrew'), $person)
                    /* translators: %s: a person's name */
                    : sprintf(__('%s was taken off the task', 'eventcrew'), $person);
            case History::REPLACED:
                /* translators: 1: name of who took the task, 2: name of who had it */
                return sprintf(__('%1$s took over from %2$s', 'eventcrew'), $other, $person);
            case History::MARKED:
                return sprintf(
                    /* translators: 1: a person's name, 2: an attendance status, like "Arrived" */
                    __('%1$s marked: %2$s', 'eventcrew'),
                    $person,
                    AssignmentStatus::label((string) ($details['status'] ?? ''))
                );
            case History::NO_SHOW:
                /* translators: %s: a person's name */
                return sprintf(__('%s marked as no-show', 'eventcrew'), $person);
            case History::TASK_CREATED:
                return __('Task added', 'eventcrew');
            case History::TASK_CHANGED:
                /* translators: %s: names of the task's fields that changed, like "capacity, notes" */
                return sprintf(__('Task changed: %s', 'eventcrew'), self::names($details['fields'] ?? []));
            case History::TASK_DELETED:
                $places = (int) ($details['places'] ?? 0);

                if (0 === $places) {
                    return __('Task deleted', 'eventcrew');
                }

                return sprintf(
                    /* translators: %d: how many people's places on the task were cancelled */
                    _n('Task deleted, %d place cancelled', 'Task deleted, %d places cancelled', $places, 'eventcrew'),
                    $places
                );
            case History::CHECKED_IN:
                /* translators: %s: a person's name, or "a guest", "a ticket buyer" */
                return sprintf(__('%s checked in', 'eventcrew'), self::doorEntry($row, $details, $person));
            case History::CHECKIN_UNDONE:
                /* translators: %s: a person's name, or "a guest", "a ticket buyer" */
                return sprintf(__('Check-in undone: %s', 'eventcrew'), self::doorEntry($row, $details, $person));
            case History::CREDIT_GRANTED:
                if (! empty($details['extra_task'])) {
                    /* translators: %s: a person's name */
                    return sprintf(__('%s got a credit for an extra task', 'eventcrew'), $person);
                }

                $credits = max(1, (int) ($details['credits'] ?? 1));

                return sprintf(
                    /* translators: 1: a person's name, 2: number of credits */
                    _n('%1$s got %2$d credit', '%1$s got %2$d credits', $credits, 'eventcrew'),
                    $person,
                    $credits
                );
            case History::CREDIT_REMOVED:
                /* translators: %s: a person's name */
                return sprintf(__('%s lost the credit for an extra task', 'eventcrew'), $person);
            case History::CREDIT_REDEEMED:
                /* translators: %s: a person's name */
                return sprintf(__('%s used a credit for free entry', 'eventcrew'), $person);
            case History::REDEMPTION_CANCELLED:
                /* translators: %s: a person's name */
                return sprintf(__('Free entry cancelled: %s', 'eventcrew'), $person);
            case History::TICKET_OFFERED:
                return null === self::id($row['other_person_id'] ?? null)
                    /* translators: %s: a person's name */
                    ? sprintf(__('%s offered their ticket', 'eventcrew'), $person)
                    /* translators: 1: name of who gives the ticket, 2: name of who gets it */
                    : sprintf(__('%1$s offered their ticket to %2$s', 'eventcrew'), $person, $other);
            case History::TICKET_GIVEN:
                return null === self::id($row['other_person_id'] ?? null)
                    /* translators: %s: a person's name */
                    ? sprintf(__('%s gave their ticket away', 'eventcrew'), $person)
                    /* translators: 1: name of who gave the ticket, 2: name of who got it */
                    : sprintf(__('%1$s gave their ticket to %2$s', 'eventcrew'), $person, $other);
            case History::TICKET_OFFER_ENDED:
                return 'lapsed' === ($details['status'] ?? '')
                    /* translators: %s: a person's name */
                    ? sprintf(__('Ticket offer lapsed: %s', 'eventcrew'), $person)
                    /* translators: %s: a person's name */
                    : sprintf(__('Ticket gift cancelled: %s', 'eventcrew'), $person);
            case History::PERSON_ADDED:
                /* translators: %s: a person's name */
                return sprintf(__('%s added', 'eventcrew'), $person);
            case History::ROLES_CHANGED:
                $changes = [];

                foreach ((array) ($details['added'] ?? []) as $slug) {
                    $changes[] = '+' . Roles::label((string) $slug);
                }

                foreach ((array) ($details['removed'] ?? []) as $slug) {
                    $changes[] = '-' . Roles::label((string) $slug);
                }

                /* translators: 1: a person's name, 2: roles added and removed, like "+Bar, -Door" */
                return sprintf(__('%1$s roles: %2$s', 'eventcrew'), $person, implode(', ', $changes));
            case History::ORGANIZER_CHANGED:
                return ! empty($details['organizer'])
                    /* translators: %s: a person's name */
                    ? sprintf(__('%s made organizer', 'eventcrew'), $person)
                    /* translators: %s: a person's name */
                    : sprintf(__('%s no longer organizer', 'eventcrew'), $person);
            case History::PERSON_ERASED:
                return ! empty($details['aged_out'])
                    ? __('A person was erased (record aged out)', 'eventcrew')
                    : __('A person was erased', 'eventcrew');
            case History::SETTINGS_CHANGED:
                $names = self::names($details['options'] ?? []);
                $more = (int) ($details['more'] ?? 0);

                if ($more > 0) {
                    /* translators: 1: names of settings, 2: how many more settings changed */
                    $names = sprintf(__('%1$s and %2$d more', 'eventcrew'), $names, $more);
                }

                /* translators: %s: names of the settings that changed (never their values) */
                return sprintf(__('Settings changed: %s', 'eventcrew'), $names);
        }

        return $kind;
    }

    /**
     * Who did it: a name, "Lou (wp-admin)", "automatic", "someone (erased)",
     * or '' when not known (rows made from older data). With the channel:
     * "Kim, bot".
     *
     * @param array<string, mixed> $row
     */
    public static function actor(array $row): string
    {
        $kind = (string) ($row['actor_kind'] ?? '');
        $channel = self::channel((string) ($row['channel'] ?? ''));

        switch ($kind) {
            case History::ACTOR_WP_USER:
                $id = (int) ($row['actor_wp_user_id'] ?? 0);
                $user = $id > 0 && function_exists('get_userdata') ? get_userdata($id) : false;
                $name = is_object($user) && '' !== (string) ($user->display_name ?? '')
                    ? (string) $user->display_name
                    /* translators: %d: a WordPress user's number */
                    : sprintf(__('user #%d', 'eventcrew'), $id);
                break;
            case History::ACTOR_SYSTEM:
                $name = __('automatic', 'eventcrew');
                $channel = '';
                break;
            case History::ACTOR_PERSON:
            case History::ACTOR_ORGANIZER:
                $name = self::person($row, 'actor_person_id', 'actor_name');
                break;
            default:
                return '';
        }

        return '' === $channel ? $name : $name . ', ' . $channel;
    }

    /**
     * The task: its role and time, like "Bar 20:00", or '' for a row with no
     * task (or a task no longer there).
     *
     * @param array<string, mixed> $row
     */
    public static function task(array $row): string
    {
        $slug = (string) ($row['task_role_slug'] ?? '');

        if ('' === $slug) {
            return '';
        }

        $starts = (string) ($row['task_starts_at'] ?? '');
        $time = 1 === preg_match('/(\d{2}:\d{2})/', $starts, $m) ? ' ' . $m[1] : '';

        return Roles::label($slug) . $time;
    }

    /**
     * The night, like "14.11.", or '' for none.
     *
     * @param array<string, mixed> $row
     */
    public static function night(array $row): string
    {
        $date = (string) ($row['event_date'] ?? '');

        return 1 === preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $date, $m)
            ? (int) $m[2] . '.' . (int) $m[1] . '.'
            : '';
    }

    /**
     * When it happened, like "14.11. 21:42".
     *
     * @param array<string, mixed> $row
     */
    public static function when(array $row): string
    {
        $at = (string) ($row['created_at'] ?? '');

        return 1 === preg_match('/^\d{4}-(\d{2})-(\d{2}) (\d{2}:\d{2})/', $at, $m)
            ? (int) $m[2] . '.' . (int) $m[1] . '. ' . $m[3]
            : $at;
    }

    /** Where it was done: "bot", "web", "wp-admin", or '' for the system or none. */
    public static function channel(string $channel): string
    {
        return match ($channel) {
            History::CHANNEL_BOT => __('bot', 'eventcrew'),
            History::CHANNEL_WEB => __('web', 'eventcrew'),
            History::CHANNEL_ADMIN => __('wp-admin', 'eventcrew'),
            default => '',
        };
    }

    /**
     * A person in a row: their name, "person #12" when they have none, or
     * "someone (erased)" when the id was emptied.
     *
     * @param array<string, mixed> $row
     */
    private static function person(array $row, string $idKey, string $nameKey): string
    {
        $id = self::id($row[$idKey] ?? null);

        if (null === $id) {
            return __('someone (erased)', 'eventcrew');
        }

        $name = trim((string) ($row[$nameKey] ?? ''));

        /* translators: %d: a person's number */
        return '' !== $name ? $name : sprintf(__('person #%d', 'eventcrew'), $id);
    }

    /**
     * Who came in at the door: the person for a task or credit ticket, "a
     * guest" or "a ticket buyer", or the giver of a ticket given away.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $details
     */
    private static function doorEntry(array $row, array $details, string $person): string
    {
        return match ((string) ($details['list'] ?? '')) {
            'guest' => __('a guest', 'eventcrew'),
            'buyer' => __('a ticket buyer', 'eventcrew'),
            /* translators: %s: name of who gave the ticket away */
            'transfer' => sprintf(__('a ticket from %s', 'eventcrew'), $person),
            default => $person,
        };
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function details(array $row): array
    {
        $decoded = json_decode((string) ($row['details'] ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function names(mixed $names): string
    {
        $list = array_map(
            static fn ($name): string => str_replace('_', ' ', (string) $name),
            is_array($names) ? $names : []
        );

        return implode(', ', $list);
    }

    private static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}

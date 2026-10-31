<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;

/**
 * The sales desk's "Other device" button: login links made on the page itself,
 * shown as a QR code for a second phone to scan.
 *
 * "Open on other device" makes a link for the person holding the session.
 * "Login as" makes one for someone else who may run the door tonight: an
 * organizer, or someone on the night's task of a sales-desk role once marked arrived - the
 * same people DoorLinkSender sends the link to, by SalesDeskGate's rule.
 *
 * Each link is an ordinary door login link (DoorSession::loginUrl, spent by
 * DoorDesk::login) over an auth token of its own purpose, stored hashed, that
 * works once and only for 15 minutes. Spending it gives that device the
 * person's normal door cookie. Only a valid, open door session may make one:
 * every method here reads the session from the cookie first.
 */
final class DoorHandoff
{
    /** link() answers. */
    public const OK = 'ok';
    public const NO_SESSION = 'no_session';
    public const NOT_ALLOWED = 'not_allowed';

    public function __construct(
        private readonly DoorDesk $desk,
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly PersonRepository $people,
        private readonly AuthTokenRepository $tokens,
        private readonly SalesDeskGate $gate
    ) {
    }

    /**
     * The people the "Login as" list offers, sorted by name, leaving out the
     * person holding the session. Null when $cookie is not an open session.
     *
     * @return array<int, array{id: int, name: string}>|null
     */
    public function people(string $cookie): ?array
    {
        $session = $this->openSession($cookie);

        if (null === $session) {
            return null;
        }

        $list = [];

        foreach ($this->allowed($session['date']) as $person) {
            if ($person->id !== $session['person']->id) {
                $list[] = ['id' => $person->id, 'name' => $person->name()];
            }
        }

        usort($list, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $list;
    }

    /**
     * A one-time login link for $personId, or - when $personId is 0 - for the
     * person holding the session. 'code' is one of the class constants; 'url'
     * is '' unless it is OK.
     *
     * @return array{code: string, url: string, name: string}
     */
    public function link(string $cookie, int $personId = 0): array
    {
        $session = $this->openSession($cookie);

        if (null === $session) {
            return ['code' => self::NO_SESSION, 'url' => '', 'name' => ''];
        }

        $date = $session['date'];
        $person = $session['person'];

        if (0 !== $personId && $personId !== $person->id) {
            $person = $this->allowed($date)[$personId] ?? null;
        }

        if (null === $person) {
            return ['code' => self::NOT_ALLOWED, 'url' => '', 'name' => ''];
        }

        $seconds = min(DoorSession::HANDOFF_SECONDS, $this->gate->secondsLeft($person, $date));

        if ($seconds <= 0) {
            return ['code' => self::NOT_ALLOWED, 'url' => '', 'name' => ''];
        }

        $raw = $this->tokens->issue($person->id, DoorSession::HANDOFF_PURPOSE, $seconds);

        return ['code' => self::OK, 'url' => DoorSession::loginUrl($raw, $date), 'name' => $person->name()];
    }

    /**
     * The plain sentence for a link() answer that is not OK.
     */
    public static function message(string $code): string
    {
        return match ($code) {
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            self::NO_SESSION => __('This device is not logged in to the sales desk. Ask an organizer for a new link (/sales in Telegram).', 'eventcrew'),
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            default => __('This person cannot run the sales desk tonight. Only organizers, and the crew marked arrived on tonight’s tasks that open the sales desk, can.', 'eventcrew'),
        };
    }

    /**
     * Everyone who may run the door on $date right now, by id: organizers,
     * and the crew on sales-desk tasks who are marked arrived - asked of
     * SalesDeskGate person by person, as DoorLinkSender does.
     *
     * @return array<int, Person>
     */
    private function allowed(string $date): array
    {
        $candidates = [];

        foreach ($this->people->organizers() as $organizer) {
            $candidates[$organizer->id] = $organizer;
        }

        foreach ($this->tasks->forDate($date) as $task) {
            if (! SalesDeskGate::isDoorRole($task->roleSlug)) {
                continue;
            }

            foreach ($this->assignments->forTask($task->id) as $assignment) {
                if (! SalesDeskGate::hasArrived($assignment->status) || isset($candidates[$assignment->personId])) {
                    continue;
                }

                $person = $this->people->find($assignment->personId);

                if (null !== $person) {
                    $candidates[$person->id] = $person;
                }
            }
        }

        $allowed = [];

        foreach ($candidates as $id => $person) {
            if (! $person->isAnonymized() && SalesDeskGate::OK === $this->gate->check($person, $date)) {
                $allowed[$id] = $person;
            }
        }

        return $allowed;
    }

    /**
     * @return array{person: Person, date: string, open: bool}|null
     */
    private function openSession(string $cookie): ?array
    {
        $session = $this->desk->session($cookie);

        return null === $session || ! $session['open'] ? null : $session;
    }
}

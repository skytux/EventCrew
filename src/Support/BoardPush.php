<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Telegram\BoardService;

/**
 * The automated board reminder: as an open event draws near, re-post the board
 * into the group so it resurfaces at the foot of the chat instead of sitting
 * buried and stale. It fires at two leads - a week out and again a couple of
 * days out - both configurable, and both on by default.
 *
 * This is a group post, so it reaches everyone in the group and cannot be
 * muted per person (a per-person opt-out only makes sense for the DM notices);
 * the whole feature is switched off site-wide from Settings instead.
 *
 * The send-once ledger keyed on (kind, 0, task_date) is the guard: person id 0
 * stands in because the audience is the group, not one person, and the per-lead
 * kind lets the same date legitimately trigger once at a week out and once
 * again closer in.
 */
final class BoardPush
{
    /** Whether the automated re-post runs at all. Default on. */
    public const ENABLED_OPTION = 'eventcrew_board_push_enabled';

    /** The nearer lead, in hours. Default 48. */
    public const LEAD_SOON_OPTION = 'eventcrew_board_push_lead_soon';

    /** The further lead, in hours. Default 168 (one week). */
    public const LEAD_WEEK_OPTION = 'eventcrew_board_push_lead_week';

    public const LEAD_SOON_DEFAULT = 48;
    public const LEAD_WEEK_DEFAULT = 168;

    private const KIND_PREFIX = 'board_push_';

    /** The group is not a person; this sentinel keys its ledger rows. */
    private const GROUP_AUDIENCE = 0;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly NotificationsRepository $ledger,
        private readonly BoardService $board
    ) {
    }

    /**
     * Re-posts the board at most once this run, then records every date that
     * newly entered a lead window so it is not re-posted for that lead again.
     * One re-post covers the lot, since the board already lists every open task.
     */
    public function run(): void
    {
        if (! $this->enabled()) {
            return;
        }

        $reposted = false;

        foreach ([$this->leadWeekHours(), $this->leadSoonHours()] as $leadHours) {
            $due = $this->dueDatesFor($leadHours);

            if ([] === $due) {
                continue;
            }

            if (! $reposted) {
                $this->board->repost();
                $reposted = true;
            }

            foreach ($due as $date) {
                $this->ledger->recordSent(self::KIND_PREFIX . $leadHours, self::GROUP_AUDIENCE, $date);
            }
        }
    }

    /**
     * Upcoming dates with open slots that have crossed into $leadHours of their
     * day and not yet been pushed for this lead.
     *
     * @return array<int, string>
     */
    private function dueDatesFor(int $leadHours): array
    {
        if ($leadHours <= 0) {
            return [];
        }

        $cutoff = strtotime((string) current_time('mysql')) + $leadHours * HOUR_IN_SECONDS;
        $kind = self::KIND_PREFIX . $leadHours;
        $due = [];

        foreach ($this->tasks->upcomingDates() as $date) {
            $dateStart = strtotime($date . ' 00:00:00');

            // Not yet within the window - and since upcomingDates is ascending,
            // nothing further out will be either.
            if (false === $dateStart || $dateStart > $cutoff) {
                break;
            }

            if (! $this->tasks->hasOpenSlotsOn($date)) {
                continue;
            }

            if ($this->ledger->hasSent($kind, self::GROUP_AUDIENCE, $date)) {
                continue;
            }

            $due[] = $date;
        }

        return $due;
    }

    private function enabled(): bool
    {
        return (bool) get_option(self::ENABLED_OPTION, true);
    }

    private function leadSoonHours(): int
    {
        return max(0, (int) get_option(self::LEAD_SOON_OPTION, self::LEAD_SOON_DEFAULT));
    }

    private function leadWeekHours(): int
    {
        return max(0, (int) get_option(self::LEAD_WEEK_OPTION, self::LEAD_WEEK_DEFAULT));
    }
}

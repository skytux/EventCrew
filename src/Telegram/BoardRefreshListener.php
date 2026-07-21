<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

/**
 * Keeps the Telegram board in step with the tasks behind it.
 *
 * Anything that changes the open-task set - the "Create an event's tasks"
 * button, an EventMesh sync, a hand-edited or deleted task - fires
 * `eventcrew/board_stale`, and this reposts or edits the board in place. It
 * boots unconditionally, on the front and cron paths too, because an EventMesh
 * sync that creates tasks runs under WP-Cron with nobody in wp-admin; when the
 * bot is not configured, BoardService::refresh() is simply a no-op.
 */
final class BoardRefreshListener
{
    /** Fired wherever the open-task set may have changed. */
    public const HOOK = 'eventcrew/board_stale';

    public function __construct(
        private readonly BoardService $board
    ) {
    }

    public function boot(): void
    {
        add_action(self::HOOK, [$this, 'onBoardStale']);
    }

    public function onBoardStale(): void
    {
        $this->board->refresh();
    }
}

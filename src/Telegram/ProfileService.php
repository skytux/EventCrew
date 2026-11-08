<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CheckinRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\Dates;
use EventCrew\Support\StandingBar;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\TicketList;
use EventCrew\Support\TicketTransferService;

/**
 * /me (My info), /tasks, /tickets, /settings and /me history: a person's own
 * standing, what they're signed up for next, everything they've done and
 * their tickets - sent to their private chat. My info is only the facts, with
 * a button for each of the other screens (MenuButtons::me(), Owner 2026-11-07).
 *
 * The one self-service window into the reputation and credits the organizer
 * sees on the People list - read-only, so it exposes what someone has earned
 * without letting them change it. Both commands answer in a DM even when they
 * were typed in the group, so a person's history is never spilled in public.
 */
final class ProfileService
{
    use GroupBreadcrumb;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly AssignmentRepository $assignments,
        private readonly TaskRepository $tasks,
        private readonly StandingCalculator $standing,
        private readonly TelegramClient $telegram,
        private readonly ?TicketTransferService $transfers = null
    ) {
    }

    /**
     * @param bool $isPrivate Whether /me arrived in a private chat. When it came
     *                        from the group the summary still goes to the DM and
     *                        a short public nudge points the person at it.
     */
    public function onMe(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isEmailVerified()) {
            // Guidance goes back to wherever they asked, so it is visible even
            // when they typed /me in the group and we cannot yet DM them.
            $this->telegram->sendMessage($chatId, SetupNudge::text(! $isPrivate));

            return;
        }

        $standing = $this->standing->for($person->id);

        // Only the facts, no explanations (Owner 2026-11-07): name and standing,
        // the counts, and the coming tasks. A title line, blocks apart and a
        // few sparkles (Owner, Q344 2026-11-07).
        $lines = [
            '✨ ' . __('My info', 'eventcrew') . ' ✨',
            '',
            '🌟 ' . $person->name(),
            $standing->ratedSummary(),
            // The bar once rated, else how many tasks until rated (Owner 2026-11-07).
            $standing->isRated() ? StandingBar::forStanding($standing) : StandingBar::untilRated($standing),
            '',
            sprintf(
                /* translators: %d: completed tasks */
                __('Completed tasks: %d', 'eventcrew'),
                $standing->completedCount
            ),
            sprintf(
                /* translators: %d: free-entry credits */
                __('Free-entry credits: %d', 'eventcrew'),
                $standing->creditBalance
            ),
            '',
        ];

        $lines = array_merge($lines, $this->comingBlock($this->upcoming($person->id)[0], '💫 '));

        $this->deliver(
            $telegramUserId,
            $chatId,
            $isPrivate,
            implode("\n", $lines),
            MenuButtons::me($person->isOrganizer)
        );
    }

    /**
     * /tasks and the 📋 Tasks button under /me: the coming tasks, each with a
     * cancel button (the board has none for a full task), then Take over a
     * task and Past tasks (Owner 2026-11-07).
     *
     * @param bool $isPrivate See onMe(); the answer is a DM either way.
     */
    public function onMyTasks(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isEmailVerified()) {
            $this->telegram->sendMessage($chatId, SetupNudge::text(! $isPrivate));

            return;
        }

        [$coming, $cancelRows] = $this->upcoming($person->id);
        $lines = array_merge(['📋 ' . __('Tasks', 'eventcrew')], $this->comingBlock($coming));

        $this->deliver(
            $telegramUserId,
            $chatId,
            $isPrivate,
            implode("\n", $lines),
            ['inline_keyboard' => array_merge($cancelRows, MenuButtons::tasksRows())]
        );
    }

    /**
     * /settings and the ⚙️ Settings button under /me: notifications, language
     * and web sign-in (Owner 2026-11-07).
     *
     * @param bool $isPrivate See onMe(); the answer is a DM either way.
     */
    public function onSettings(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isEmailVerified()) {
            $this->telegram->sendMessage($chatId, SetupNudge::text(! $isPrivate));

            return;
        }

        $this->deliver($telegramUserId, $chatId, $isPrivate, '⚙️ ' . __('Settings', 'eventcrew'), MenuButtons::settings());
    }

    /**
     * @param bool $isPrivate See onMe(); the answer is a DM either way.
     */
    public function onMyHistory(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isEmailVerified()) {
            $this->telegram->sendMessage($chatId, SetupNudge::text(! $isPrivate));

            return;
        }

        $past = $this->pastLines($person->id);

        $body = [] === $past
            ? __('No past tasks yet — your history starts once you’ve worked one.', 'eventcrew')
            : implode("\n", array_merge([__('Your past tasks:', 'eventcrew')], $past));

        // Past tasks is a button under Tasks, so Back goes there.
        $this->deliver($telegramUserId, $chatId, $isPrivate, $body, MenuButtons::back(MenuButtons::MY_TASKS));
    }

    /**
     * /tickets, /me tickets and the 🎟 Tickets button under /me: the person's
     * tickets, each upcoming one a button, then 🎟 Free-entry ticket.
     *
     * @param bool $isPrivate See onMe(); the answer is a DM either way.
     */
    public function onMyTickets(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isEmailVerified()) {
            $this->telegram->sendMessage($chatId, SetupNudge::text(! $isPrivate));

            return;
        }

        $tickets = (new TicketList(
            $this->assignments,
            $this->tasks,
            new RedemptionRepository(),
            new CheckinRepository(),
            $this->transfers
        ))->forPerson($person->id);
        $lines = ['🎟 ' . __('Your tickets', 'eventcrew')];
        // Each upcoming ticket is a named button; a past one is only listed,
        // since there is no door left to show it at.
        $buttons = [];

        if ([] === $tickets['upcoming'] && [] === $tickets['past']) {
            $lines[] = __('No tickets yet — sign up for a task or spend a credit.', 'eventcrew');
        } else {
            if ([] !== $tickets['upcoming']) {
                $lines[] = '';
                $lines[] = __('Upcoming:', 'eventcrew');
                $lines = array_merge($lines, array_map([$this, 'ticketLine'], $tickets['upcoming']));

                foreach ($tickets['upcoming'] as $ticket) {
                    $buttons[] = [[
                        'text' => '🎟 ' . $ticket['label'] . ' — ' . $ticket['when'],
                        'url' => $ticket['url'],
                    ]];
                    $lines = array_merge($lines, $this->giveAway($ticket, $person->id, $buttons));
                }
            }

            if ([] !== $tickets['past']) {
                $lines[] = '';
                $lines[] = __('Past:', 'eventcrew');
                $lines = array_merge($lines, array_map([$this, 'ticketLine'], $tickets['past']));
            }
        }

        $this->deliver(
            $telegramUserId,
            $chatId,
            $isPrivate,
            implode("\n", $lines),
            ['inline_keyboard' => array_merge($buttons, MenuButtons::ticketsRows())]
        );
    }

    /**
     * The 🎁 Give away button under a credit ticket that can be given, or,
     * while an email offer waits, ✖️ Cancel offer and the line naming the
     * address (returned). Nothing when giving is off.
     *
     * @param array<string, mixed> $ticket
     * @param array<int, mixed> $buttons
     * @return list<string>
     */
    private function giveAway(array $ticket, int $personId, array &$buttons): array
    {
        $redemptionId = (int) ($ticket['redemption_id'] ?? 0);

        if (null === $this->transfers || $redemptionId <= 0 || ! TicketTransferService::isOn()) {
            return [];
        }

        $offer = $this->transfers->pendingOffer($redemptionId);

        if (null !== $offer) {
            $buttons[] = TransferBot::cancelRow($redemptionId, (string) $ticket['when']);

            return ['⏳ ' . sprintf(
                /* translators: %s: the email address the ticket was offered to */
                __('Waiting for %s to accept', 'eventcrew'),
                $offer->email
            )];
        }

        if ($this->transfers->canGive($this->transfers->redemption($redemptionId), $personId)) {
            $buttons[] = TransferBot::giveRow($redemptionId, (string) $ticket['when']);
        }

        return [];
    }

    /**
     * DMs the person a one-time link that signs them in to the web board -
     * asked with the 🔑 Web sign-in button under /settings. It is the same single-use, 30-minute magic link the web
     * sign-in form and the email footer issue (purpose 'web_login', consumed on
     * the signup page via ?eventcrew_login), so a Telegram member can hop to the
     * web without typing their email. The link is personal, so it only ever goes
     * to the DM, never into a group.
     */
    public function onWeb(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isEmailVerified()) {
            $this->telegram->sendMessage($chatId, SetupNudge::text(! $isPrivate));

            return;
        }

        // The web sign-in lands on the public signup page. Option name mirrors
        // Web\PwaController::PAGE_OPTION, kept literal so this Telegram service
        // does not reach into the Web layer.
        $pageId = (int) get_option('eventcrew_signup_page_id', 0);
        $pageUrl = $pageId > 0 ? get_permalink($pageId) : false;

        if (! is_string($pageUrl) || '' === $pageUrl) {
            $this->telegram->sendMessage(
                $telegramUserId,
                __('There’s no public sign-up page set up yet, so there’s nothing to sign in to on the web.', 'eventcrew'),
                MenuButtons::back(MenuButtons::SETTINGS)
            );
            $this->sentDmNote($chatId, $isPrivate);

            return;
        }

        // A single-use, 30-minute token - the same magic link the web sign-in
        // form and the email footer issue (SignupController LOGIN_PURPOSE /
        // LOGIN_TTL), consumed on the signup page via ?eventcrew_login.
        $token = (new AuthTokenRepository())->issue($person->id, 'web_login', 30 * MINUTE_IN_SECONDS);
        $url = add_query_arg(['eventcrew_login' => $token], $pageUrl);

        $this->deliver(
            $telegramUserId,
            $chatId,
            $isPrivate,
            __('Here’s your sign-in link for the web board — good for 30 minutes, one use.', 'eventcrew'),
            ['inline_keyboard' => [
                [['text' => __('🔑 Sign in on the web', 'eventcrew'), 'url' => $url]],
                MenuButtons::backRow(MenuButtons::SETTINGS),
            ]]
        );
    }

    /**
     * One ticket, with "🟠 Already used · 21:05" once the door marked it in
     * (Telegram text has no colours; the orange circle stands for the
     * ticket page's orange band).
     *
     * @param array{label: string, when: string, url: string, used: ?string} $ticket
     */
    private function ticketLine(array $ticket): string
    {
        $line = sprintf('• %s — %s', $ticket['label'], $ticket['when']);

        return null === $ticket['used'] ? $line : $line . ' — 🟠 ' . TicketList::usedText($ticket['used']);
    }

    /**
     * Sends the personal summary to the DM. When the command came from the
     * group a short public line points the asker at it, so nobody's standing
     * or history is printed where the whole crew can read it.
     *
     * @param array<string, mixed>|null $replyMarkup
     */
    private function deliver(
        int $telegramUserId,
        int $chatId,
        bool $isPrivate,
        string $body,
        ?array $replyMarkup = null
    ): void {
        $this->telegram->sendMessage($telegramUserId, $body, $replyMarkup);
        $this->sentDmNote($chatId, $isPrivate);
    }

    /**
     * The person's coming tasks, soonest first: a line for each, and a cancel
     * button row for each task that still exists. The button sends
     * BoardService's leave tap (l:<id>), which asks "Cancel …?" with what it
     * costs before anything changes.
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, array{text: string, callback_data: string}>>}
     */
    private function upcoming(int $personId): array
    {
        $today = current_time('Y-m-d');
        $entries = [];

        foreach ($this->assignments->historyFor($personId) as $entry) {
            if ($entry['task_date'] >= $today && $entry['assignment']->isOccupying()) {
                $entries[] = $entry;
            }
        }

        $lines = [];
        $rows = [];

        // historyFor is newest-first; upcoming reads better soonest-first.
        foreach (array_reverse($entries) as $entry) {
            $task = $this->tasks->find($entry['assignment']->taskId);
            // "Signed up" goes without saying; tonight's "arrived" does not.
            $lines[] = $this->line($entry, $task, AssignmentStatus::SIGNED_UP !== $entry['assignment']->status);

            if (null !== $task) {
                $rows[] = [[
                    'text' => '✖️ ' . sprintf(
                        /* translators: 1: role, 2: day */
                        __('Cancel %1$s, %2$s', 'eventcrew'),
                        $task->roleLabel(),
                        Dates::dayLabel((string) $entry['task_date'])
                    ),
                    'callback_data' => 'l:' . $task->id,
                ]];
            }
        }

        return [$lines, $rows];
    }

    /**
     * "Coming up:" and the coming task lines, or "No coming tasks.", with
     * $mark (an emoji and a space, My info's) before the first line.
     *
     * @param array<int, string> $coming
     * @return array<int, string>
     */
    private function comingBlock(array $coming, string $mark = ''): array
    {
        return [] === $coming
            ? [$mark . __('No coming tasks.', 'eventcrew')]
            : array_merge([$mark . __('Coming up:', 'eventcrew')], $coming);
    }

    /**
     * Everything filed on a past date, whatever the outcome, newest first.
     *
     * @return array<int, string>
     */
    private function pastLines(int $personId): array
    {
        $today = current_time('Y-m-d');
        $lines = [];

        foreach ($this->assignments->historyFor($personId) as $entry) {
            if ($entry['task_date'] < $today) {
                $lines[] = $this->line($entry);
            }
        }

        return $lines;
    }

    /**
     * One assignment as a "• date — role (event): status" line; a coming
     * task leaves out a plain "signed up".
     *
     * @param array{assignment: \EventCrew\Models\Assignment, task_date: string} $entry
     * @param Task|false|null $task The task when already looked up; false = look it up.
     */
    private function line(array $entry, Task|false|null $task = false, bool $withStatus = true): string
    {
        $assignment = $entry['assignment'];
        $task = false === $task ? $this->tasks->find($assignment->taskId) : $task;

        $what = null === $task
            ? __('a task', 'eventcrew')
            : sprintf('%s (%s)', $task->roleLabel(), $task->eventName());

        $line = sprintf('• %s — %s', Dates::dayLabel((string) $entry['task_date']), $what);

        return $withStatus ? $line . ': ' . $assignment->statusLabel() : $line;
    }
}

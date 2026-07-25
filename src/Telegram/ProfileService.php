<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\StandingExplainer;
use EventCrew\Support\TicketList;

/**
 * /me and /myhistory: a person's own standing, what they're signed up for next,
 * and everything they've done - sent to their private chat.
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
        private readonly TelegramClient $telegram
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
            $this->telegram->sendMessage(
                $chatId,
                __('Set yourself up first with /start, then /me shows how you’re doing.', 'eventcrew')
            );

            return;
        }

        $standing = $this->standing->for($person->id);

        $lines = [
            sprintf(
                /* translators: 1: name, 2: standing level and score */
                __('%1$s — %2$s', 'eventcrew'),
                $person->name(),
                $standing->ratedSummary()
            ),
            sprintf(
                /* translators: 1: completed count, 2: credit balance */
                __('Completed tasks: %1$d · Free-entry credits: %2$d', 'eventcrew'),
                $standing->completedCount,
                $standing->creditBalance
            ),
        ];

        if ($standing->creditBalance > 0) {
            $lines[] = sprintf(
                /* translators: %d: number of free-entry credits the person holds */
                _n(
                    'You have %d free-entry credit — send /ticket to spend it on a free ticket.',
                    'You have %d free-entry credits — send /ticket to spend one on a free ticket.',
                    $standing->creditBalance,
                    'eventcrew'
                ),
                $standing->creditBalance
            );
        }

        $upcoming = $this->upcomingLines($person->id);

        $lines[] = '';

        if ([] !== $upcoming) {
            $lines[] = __('Coming up:', 'eventcrew');
            $lines = array_merge($lines, $upcoming);
        } else {
            $lines[] = __('Nothing on your calendar yet — tap a task on the board to sign up.', 'eventcrew');
        }

        $lines[] = '';
        $lines = array_merge($lines, StandingExplainer::lines());

        $lines[] = '';
        $lines[] = __('Send /myhistory to see everything you’ve done.', 'eventcrew');

        $this->deliver($telegramUserId, $chatId, $isPrivate, implode("\n", $lines));
    }

    /**
     * @param bool $isPrivate See onMe(); the answer is a DM either way.
     */
    public function onMyHistory(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isEmailVerified()) {
            $this->telegram->sendMessage(
                $chatId,
                __('Set yourself up first with /start, then /myhistory shows your past tasks.', 'eventcrew')
            );

            return;
        }

        $past = $this->pastLines($person->id);

        $body = [] === $past
            ? __('No past tasks yet — your history starts once you’ve worked one.', 'eventcrew')
            : implode("\n", array_merge([__('Your past tasks:', 'eventcrew')], $past));

        $this->deliver($telegramUserId, $chatId, $isPrivate, $body);
    }

    /**
     * @param bool $isPrivate See onMe(); the answer is a DM either way.
     */
    public function onMyTickets(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isEmailVerified()) {
            $this->telegram->sendMessage(
                $chatId,
                __('Set yourself up first with /start, then /mytickets shows your tickets.', 'eventcrew')
            );

            return;
        }

        $tickets = (new TicketList($this->assignments, $this->tasks, new RedemptionRepository()))->forPerson($person->id);
        $lines = ['🎟 ' . __('Your tickets', 'eventcrew')];

        if ([] === $tickets['upcoming'] && [] === $tickets['past']) {
            $lines[] = __('No tickets yet — sign up for a task or spend a credit.', 'eventcrew');
        } else {
            if ([] !== $tickets['upcoming']) {
                $lines[] = '';
                $lines[] = __('Upcoming:', 'eventcrew');
                $lines = array_merge($lines, array_map([$this, 'ticketLine'], $tickets['upcoming']));
            }

            if ([] !== $tickets['past']) {
                $lines[] = '';
                $lines[] = __('Past:', 'eventcrew');
                $lines = array_merge($lines, array_map([$this, 'ticketLine'], $tickets['past']));
            }
        }

        $this->deliver($telegramUserId, $chatId, $isPrivate, implode("\n", $lines));
    }

    /**
     * DMs the person a one-time link that signs them in to the web board -
     * asked with /web. It is the same single-use, 30-minute magic link the web
     * sign-in form and the email footer issue (purpose 'web_login', consumed on
     * the signup page via ?eventcrew_login), so a Telegram member can hop to the
     * web without typing their email. The link is personal, so it only ever goes
     * to the DM, never into a group.
     */
    public function onWeb(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isEmailVerified()) {
            $this->telegram->sendMessage(
                $chatId,
                __('Set yourself up first with /start, then /web sends you a sign-in link for the web.', 'eventcrew')
            );

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
                __('There’s no public sign-up page set up yet, so there’s nothing to sign in to on the web.', 'eventcrew')
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
            sprintf(
                /* translators: %s: a one-time web sign-in link */
                __("Here’s your sign-in link for the web board — good for 30 minutes, one use:\n\n%s", 'eventcrew'),
                $url
            )
        );
    }

    /**
     * @param array{label: string, when: string, url: string} $ticket
     */
    private function ticketLine(array $ticket): string
    {
        return sprintf("• %s — %s\n%s", $ticket['label'], $ticket['when'], $ticket['url']);
    }

    /**
     * Sends the personal summary to the DM. When the command came from the
     * group a short public line points the asker at it, so nobody's standing
     * or history is printed where the whole crew can read it.
     */
    private function deliver(int $telegramUserId, int $chatId, bool $isPrivate, string $body): void
    {
        $this->telegram->sendMessage($telegramUserId, $body);
        $this->sentDmNote($chatId, $isPrivate);
    }

    /**
     * Tasks the person still holds a slot in, today onward, soonest first.
     *
     * @return array<int, string>
     */
    private function upcomingLines(int $personId): array
    {
        $today = current_time('Y-m-d');
        $entries = [];

        foreach ($this->assignments->historyFor($personId) as $entry) {
            if ($entry['task_date'] >= $today && $entry['assignment']->isOccupying()) {
                $entries[] = $entry;
            }
        }

        // historyFor is newest-first; upcoming reads better soonest-first.
        return array_map([$this, 'line'], array_reverse($entries));
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
     * One assignment as a "• date — role (event): status" line.
     *
     * @param array{assignment: \EventCrew\Models\Assignment, task_date: string} $entry
     */
    private function line(array $entry): string
    {
        $assignment = $entry['assignment'];
        $task = $this->tasks->find($assignment->taskId);

        $what = null === $task
            ? __('a task', 'eventcrew')
            : sprintf('%s (%s)', $task->roleLabel(), $task->eventName());

        return sprintf(
            '• %s — %s: %s',
            (string) $entry['task_date'],
            $what,
            $assignment->statusLabel()
        );
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The crew FAQ: the short questions and answers a crew member needs, written
 * once for the bot's Help menu (HelpService) and the web Help tab
 * (templates/public/signup.php). Owner 2026-11-08: "a generated FAQ reachable
 * from Help", the same in Telegram and on the web.
 *
 * Every number comes from the live settings (notice hours, outcome weights,
 * tasks per credit, the second thoughts and sign-out windows), and the lines
 * for features that can be switched off follow their switches the way
 * TicketRules does: the standing gate, giving tickets away and the credit for
 * an extra task on the same night. The screens are named as both sides name
 * them: Tasks, Tickets, Settings, My info / the Summary card.
 */
final class Faq
{
    /**
     * The questions and answers in order, in the current language.
     *
     * @return list<array{q: string, a: string}>
     */
    public static function items(): array
    {
        $settings = new ReputationSettings();
        $weights = $settings->weightPercents();
        $noticeHours = max(0, (int) get_option(SignupService::NOTICE_HOURS_OPTION, 48));
        $threshold = (int) round($settings->threshold() * 100);
        $gate = (bool) get_option(SignupService::GATE_OPTION, true);
        $transfer = TicketTransferService::isOn();
        $extra = ExtraTaskCredits::isOn();

        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literals; splitting them breaks extraction.
        $items = [];

        $items[] = self::item(
            __('How do I sign up for a task?', 'eventcrew'),
            __('Open the board (See open tasks in the bot, or the Board tab on the web page) and tap the task. You get a confirmation and a ticket for that night. If you are new, the team first says OK; we message you when it comes.', 'eventcrew')
        );

        $cancel = [__('Open Tasks and tap Cancel next to the task.', 'eventcrew')];
        $thoughts = SecondThoughts::minutes();

        if ($thoughts > 0) {
            $cancel[] = sprintf(
                /* translators: %d: minutes */
                __('Changed your mind? Within %d minutes of taking a task, cancelling it never counts against you.', 'eventcrew'),
                $thoughts
            );
        }

        $lock = SignupService::signoutLockMinutes();

        if ($lock > 0) {
            $cancel[] = sprintf(
                /* translators: %d: minutes */
                __('Cancelling closes %d minutes before the task starts; after that, tell an organizer.', 'eventcrew'),
                $lock
            );
        }

        $items[] = self::item(__('How do I cancel a task?', 'eventcrew'), implode(' ', $cancel));

        $items[] = self::item(
            __('What if I cancel late?', 'eventcrew'),
            sprintf(
                /* translators: 1: hours of notice, 2: standing weight of a late cancellation in percent, 3: standing weight of finding a replacement in percent */
                __('Cancelling %1$d hours or more before has no effect. Later, it counts as a late cancellation in your standing (%2$d%%). Better: find someone to take your task. When they take it, it counts as Found a replacement (%3$d%%).', 'eventcrew'),
                $noticeHours,
                $weights[AssignmentStatus::LATE_CANCEL] ?? 0,
                $weights[AssignmentStatus::REPLACED] ?? 0
            )
        );

        $items[] = self::item(
            __('A task is full. Can I still get it?', 'eventcrew'),
            __('Tap Take someone’s task and choose whose task you want. They are asked and decide; nothing changes until they say yes. If they do not answer in time, they keep the task.', 'eventcrew')
        );

        $items[] = self::item(
            __('What happens if I do not show up?', 'eventcrew'),
            sprintf(
                /* translators: %d: standing weight of a no-show in percent */
                __('A no-show counts %d%% in your standing, the worst outcome. If you cannot come, cancel or find a replacement instead. If it was marked by mistake, tell an organizer or the bot.', 'eventcrew'),
                $weights[AssignmentStatus::NO_SHOW] ?? 0
            )
        );

        $standing = sprintf(
            /* translators: 1: finished tasks before a rating, 2: good-standing percentage */
            __('It comes from how your past tasks went, and recent tasks count more. You get a rating after %1$d finished tasks; %2$d%% or higher is good standing.', 'eventcrew'),
            $settings->minRatedTasks(),
            $threshold
        );

        if ($gate) {
            $standing .= ' ' . __('Below that, you cannot sign up for new tasks until you talk to an organizer.', 'eventcrew');
        }

        $items[] = self::item(__('How does standing work?', 'eventcrew'), $standing);

        $items[] = self::item(
            __('How do I get free entry?', 'eventcrew'),
            sprintf(
                /* translators: %d: completed tasks needed for one credit */
                __('Every %d completed tasks earn 1 free-entry credit. To use one, open Tickets, tap Get a free-entry ticket and choose the night. Some nights (sold out, or special events) do not accept credits.', 'eventcrew'),
                $settings->tasksPerCredit()
            )
        );

        $items[] = self::item(
            __('Do I need a ticket for a night I work?', 'eventcrew'),
            __('Signing up gives you a working ticket for that night. It covers all your tasks that night; show it at the door. Its code never changes, so a screenshot works. It is personal and cannot be given away.', 'eventcrew')
        );

        if ($extra) {
            $items[] = self::item(
                __('Why is a credit pending?', 'eventcrew'),
                __('Each extra task on the same night earns one more credit. It shows as pending and is added after the event, if you did the task. A cancelled or missed task gives nothing.', 'eventcrew')
            );
        }

        $items[] = self::item(
            __('Can I cancel a free-entry ticket?', 'eventcrew'),
            $transfer
                ? __('No, and the credit stays spent. But you can give the ticket to someone else: open Tickets and tap Give away. A ticket given to you cannot be given on.', 'eventcrew')
                : __('No, and the credit stays spent.', 'eventcrew')
        );

        $items[] = self::item(
            __('I already have a ticket for that night. Can I still take a task?', 'eventcrew'),
            __('Yes. Your ticket stays as it is. A bought ticket never turns into a credit.', 'eventcrew')
                . ($transfer ? ' ' . __('You can give away a free-entry ticket you no longer need.', 'eventcrew') : '')
        );

        $items[] = self::item(
            __('Where do I see my credits and tasks?', 'eventcrew'),
            __('In My info in the bot, and in the Summary at the top of the web page.', 'eventcrew')
        );

        $items[] = self::item(
            __('How do I choose which messages I get?', 'eventcrew'),
            __('Under Settings, choose notifications and switch off what you do not want. Sign-up confirmations and reminders always come.', 'eventcrew')
        );

        $items[] = self::item(
            __('How do I sign in on the web page?', 'eventcrew'),
            __('Enter your email on the web page and open the sign-in link we send. In the bot, Settings also has a web sign-in link.', 'eventcrew')
        );

        $items[] = self::item(
            __('How do I change the language?', 'eventcrew'),
            __('Under Settings: Change language. Messages to you then come in that language.', 'eventcrew')
        );

        $items[] = self::item(
            __('How do I delete my data?', 'eventcrew'),
            __('Under Settings: Delete my data. It erases your account and history and cannot be undone. To stop only some messages, choose notifications instead.', 'eventcrew')
        );
        // phpcs:enable Generic.Files.LineLength.TooLong

        return $items;
    }

    /**
     * @return array{q: string, a: string}
     */
    private static function item(string $question, string $answer): array
    {
        return ['q' => $question, 'a' => $answer];
    }
}

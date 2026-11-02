<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Support\Language;

/**
 * Reads one Telegram update and sends it to the right place.
 *
 * Button taps go to the service that owns their prefix. Commands go to their
 * service; /help lists them for the asker and /cancel drops any question the
 * bot is waiting on. A plain private message is read only as the answer to
 * such a question. Anything else is ignored - the webhook still answers 200,
 * it just does nothing.
 *
 * Every update is handled in the language its sender chose, so their DMs and
 * popups read in it. A private command from someone who has not chosen is held
 * back behind a "Suomi / English" question and run once they answer.
 */
final class UpdateRouter
{
    /** Highest Telegram update_id already processed, for idempotency. */
    public const LAST_UPDATE_OPTION = 'eventcrew_telegram_last_update_id';

    public function __construct(
        private readonly OnboardingService $onboarding,
        private readonly BoardService $board,
        private readonly RosterService $roster,
        private readonly ReplacementService $replacement,
        private readonly ProfileService $profile,
        private readonly TicketRedemptionService $tickets,
        private readonly GiftService $gift,
        private readonly PermissionService $permissions,
        private readonly NotificationSettingsService $notifications,
        private readonly DoorListService $doorList,
        private readonly GuestListService $guestList,
        private readonly SalesLinkService $salesLink,
        private readonly ?LanguageService $language = null,
        private readonly ?RoleTaskBot $roleTasks = null,
        private readonly ?HelpService $help = null
    ) {
    }

    /**
     * @param array<string, mixed> $update
     */
    public function dispatch(array $update): void
    {
        // Telegram redelivers any update it didn't get a 200 for, so a timeout
        // mid-processing would otherwise run the same action twice. The
        // conditional join is already safe against that, but a cancel is not -
        // so drop anything at or below the highest update_id already handled.
        if (! $this->isFreshUpdate($update)) {
            return;
        }

        if (null === $this->language) {
            $this->route($update, null);

            return;
        }

        $sender = $this->senderId($update);
        $chosen = $this->language->languageFor($sender);

        Language::run($chosen, function () use ($update, $chosen): void {
            $this->route($update, $chosen);
        });
    }

    /**
     * @param array<string, mixed> $update
     * @param string|null $chosen The sender's language, null if not chosen yet.
     */
    private function route(array $update, ?string $chosen): void
    {
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $callbackQuery = $update['callback_query'];

            $data = (string) ($callbackQuery['data'] ?? '');

            if (null !== $this->language && str_starts_with($data, LanguageService::PREFIX)) {
                $this->onLanguageChosen($callbackQuery);
            } elseif (str_starts_with($data, 'rep:')) {
                $this->replacement->onSelect($callbackQuery);
            } elseif (str_starts_with($data, 'rm:')) {
                $this->roster->onMark($callbackQuery);
            } elseif (str_starts_with($data, 'tkt:')) {
                $this->tickets->onSelect($callbackQuery);
            } elseif (str_starts_with($data, 'gift:')) {
                $this->gift->onSelect($callbackQuery);
            } elseif (null !== $this->roleTasks && str_starts_with($data, RoleTaskBot::PREFIX)) {
                $this->roleTasks->onSelect($callbackQuery);
            } elseif (str_starts_with($data, 'perm:')) {
                $this->permissions->onSelect($callbackQuery);
            } elseif (str_starts_with($data, 'np:')) {
                $this->notifications->onToggle($callbackQuery);
            } elseif (str_starts_with($data, 'dl:')) {
                $this->doorList->onSelect($callbackQuery);
            } elseif (str_starts_with($data, 'gl:')) {
                $this->guestList->onSelect($callbackQuery);
            } else {
                $this->board->onJoinLeave($callbackQuery);
            }

            return;
        }

        // The bot's own being-added/removed notice - delivered even under group
        // privacy mode, so it captures the board's group without an admin.
        if (isset($update['my_chat_member']) && is_array($update['my_chat_member'])) {
            $this->board->onBotMembershipChange($update['my_chat_member']);

            return;
        }

        $message = $update['message'] ?? null;

        if (! is_array($message)) {
            return;
        }

        $this->routeMessage($message, $chosen);
    }

    /**
     * @param array<string, mixed> $message
     * @param string|null $chosen The sender's language, null if not chosen yet.
     */
    private function routeMessage(array $message, ?string $chosen): void
    {
        $text = trim((string) ($message['text'] ?? ''));
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $fromId = (int) ($message['from']['id'] ?? 0);
        $isPrivate = 'private' === ($message['chat']['type'] ?? '');

        if (null !== $this->language && 0 !== $fromId && $this->isCommand($text, 'language')) {
            // Asked in the group, the question still goes to the DM: the
            // buttons are personal, and the group sees English either way.
            $this->language->ask($fromId, $isPrivate ? $chatId : $fromId);

            return;
        }

        // Someone who has not chosen a language yet is asked before their first
        // private command runs; the command waits behind the question and runs
        // as soon as they tap an answer. Group commands and plain replies are
        // never held back - the group is English, and a reply answers a
        // question the bot already asked.
        if (
            null !== $this->language
            && null === $chosen
            && $isPrivate
            && 0 !== $fromId
            && str_starts_with($text, '/')
        ) {
            $this->language->ask($fromId, $chatId, $message);

            return;
        }

        // /help: the commands this person may use.
        if (null !== $this->help && $this->isCommand($text, 'help')) {
            $this->help->onHelp($fromId, $chatId, $isPrivate);

            return;
        }

        // /cancel: drop every question the bot is waiting on for this person.
        // Each one is asked to stop, so none is left behind by a short-circuit.
        if (null !== $this->help && 0 !== $fromId && $this->isCommand($text, 'cancel')) {
            $stopped = [
                $this->replacement->cancelPending($fromId),
                $this->gift->cancelPending($fromId),
                $this->permissions->cancelPending($fromId),
                null !== $this->roleTasks && $this->roleTasks->cancelPending($fromId),
                $this->onboarding->cancelPending($fromId),
            ];
            $this->help->onCancel($chatId, $isPrivate, in_array(true, $stopped, true));

            return;
        }

        if ($this->isCommand($text, 'start')) {
            // The board's deep-link buttons both land here as /start with a
            // payload: "onboard" (or none) is the sign-up flow, "me" is the
            // returning member asking for their summary in the DM.
            if ('me' === $this->commandPayload($text, 'start')) {
                $this->profile->onMe($fromId, $chatId, $isPrivate);

                return;
            }

            $from = is_array($message['from'] ?? null) ? $message['from'] : [];
            $this->onboarding->start($fromId, $chatId, $this->displayName($from), $isPrivate);

            return;
        }

        // /roster works from either the group or a private chat; the service
        // itself refuses anyone who is not an organizer or on that night's
        // tasks. An optional date picks the night.
        if ($this->isCommand($text, 'roster')) {
            $this->roster->onRosterCommand($fromId, $chatId, $isPrivate, $this->commandPayload($text, 'roster'));

            return;
        }

        if ($this->isCommand($text, 'replace')) {
            $this->replacement->start($fromId, $chatId, $isPrivate);

            return;
        }

        // /gift is organizers-only and answers in the DM, like /ticket; the
        // service refuses anyone who is not a linked organizer.
        if ($this->isCommand($text, 'gift')) {
            $this->gift->start($fromId, $chatId, $isPrivate);

            return;
        }

        // /roles (organizers only) gives and takes away roles: the one-time
        // pass, organizer, and the restricted roles (DJ, VJ, Crew leader, ...).
        // /allow is its old name and still runs it.
        if ($this->isCommand($text, 'roles') || $this->isCommand($text, 'allow')) {
            $this->permissions->start($fromId, $chatId, $isPrivate);

            return;
        }

        // /tasks: the restricted-role tasks this person may take, which are
        // never on the group board; organizers can also add someone there.
        // /roletasks is its old name and still runs it.
        if (
            null !== $this->roleTasks
            && ($this->isCommand($text, 'tasks') || $this->isCommand($text, 'roletasks'))
        ) {
            $this->roleTasks->start($fromId, $chatId, $isPrivate);

            return;
        }

        // /eligible (organizers only): who meets each role's conditions.
        // /leaders is its old name and still runs it.
        if ($this->isCommand($text, 'eligible') || $this->isCommand($text, 'leaders')) {
            $this->permissions->onEligible($fromId, $chatId, $isPrivate);

            return;
        }

        // /notifications: the per-person channel preferences menu.
        if ($this->isCommand($text, 'notifications')) {
            $this->notifications->start($fromId, $chatId, $isPrivate);

            return;
        }

        // /ticket is a personal, credit-spending action, so its answer - the
        // spendable buttons - always goes to the DM, never into the group. Asked
        // in the group it still works: the person gets the DM and a breadcrumb
        // points them at it, the same way /me does.
        if ($this->isCommand($text, 'ticket')) {
            $this->tickets->onTicketCommand($fromId, $chatId, $isPrivate);

            return;
        }

        // /tickets: who has a ticket for a night, for organizers and that
        // night's welcome crew. Answered in the DM; an optional date picks the night.
        if ($this->isCommand($text, 'tickets')) {
            $this->doorList->onTicketsCommand($fromId, $chatId, $isPrivate, $this->commandPayload($text, 'tickets'));

            return;
        }

        // /sales: a fresh login link for the sales desk, in the DM. Organizers,
        // and people on the night's task of a sales-desk role once arrived.
        if ($this->isCommand($text, 'sales')) {
            $this->salesLink->onSalesCommand($fromId, $chatId, $isPrivate);

            return;
        }

        // /guest: show, add, edit or remove a night's guest list. Organizers
        // change it; the night's welcome crew can look. Answered in the DM.
        if ($this->isCommand($text, 'guest')) {
            $this->guestList->onGuestCommand($fromId, $chatId, $isPrivate, $this->commandArguments($text, 'guest'));

            return;
        }

        // /me, /me history and /me tickets work from either the group or a
        // private chat; the service always answers in the asker's DM so a
        // personal summary is never printed in front of the whole crew.
        // /myhistory and /mytickets are their old names and still run them.
        if ($this->isCommand($text, 'myhistory')) {
            $this->profile->onMyHistory($fromId, $chatId, $isPrivate);

            return;
        }

        if ($this->isCommand($text, 'mytickets')) {
            $this->profile->onMyTickets($fromId, $chatId, $isPrivate);

            return;
        }

        // /web DMs a one-time link that signs the person in to the web board, so
        // a Telegram member can hop to the web without typing their email.
        if ($this->isCommand($text, 'web')) {
            $this->profile->onWeb($fromId, $chatId, $isPrivate);

            return;
        }

        if ($this->isCommand($text, 'me')) {
            $part = strtolower($this->commandPayload($text, 'me'));

            if ('history' === $part) {
                $this->profile->onMyHistory($fromId, $chatId, $isPrivate);
            } elseif ('tickets' === $part) {
                $this->profile->onMyTickets($fromId, $chatId, $isPrivate);
            } else {
                $this->profile->onMe($fromId, $chatId, $isPrivate);
            }

            return;
        }

        if (! $isPrivate && $this->isCommand($text, 'board')) {
            // Re-post fresh and delete the old board, rather than edit in place,
            // so a re-run of /board brings the board back to the foot of the
            // chat without leaving the previous copy stacked above it.
            $this->board->repostInto($chatId);

            return;
        }

        if ($isPrivate && $this->isCommand($text, 'board')) {
            // Asked in a private chat: the board comes back in that chat. The
            // group's board stays where it is.
            $this->board->sendPrivate($chatId);

            return;
        }

        // A plain private message is only meaningful as the answer to a question
        // the bot just asked - who the cover is standing in for, who to gift a
        // credit, or the onboarding email.
        if ($isPrivate && 0 !== $fromId) {
            $entities = is_array($message['entities'] ?? null) ? $message['entities'] : [];

            if ($this->replacement->isAwaitingTarget($fromId)) {
                $this->replacement->captureTarget($fromId, $chatId, $text, $entities);
            } elseif ($this->gift->isAwaitingTarget($fromId)) {
                $this->gift->captureTarget($fromId, $chatId, $text, $entities);
            } elseif ($this->permissions->isAwaitingTarget($fromId)) {
                $this->permissions->captureTarget($fromId, $chatId, $text, $entities);
            } elseif (null !== $this->roleTasks && $this->roleTasks->isAwaitingPerson($fromId)) {
                $this->roleTasks->capturePerson($fromId, $chatId, $text);
            } elseif ($this->onboarding->isAwaitingEmail($fromId)) {
                $this->onboarding->captureEmail($fromId, $chatId, $text);
            }
        }
    }

    /**
     * A Suomi / English tap: save it, then run the command that was waiting
     * behind the question, in the language just chosen.
     *
     * @param array<string, mixed> $callbackQuery
     */
    private function onLanguageChosen(array $callbackQuery): void
    {
        if (null === $this->language) {
            return;
        }

        $result = $this->language->onSelect($callbackQuery);

        if (null === $result['language'] || null === $result['pending']) {
            return;
        }

        $pending = $result['pending'];
        $language = $result['language'];

        Language::run($language, function () use ($pending, $language): void {
            $this->routeMessage($pending, $language);
        });
    }

    /**
     * The Telegram user an update comes from: the tapper of a button or the
     * sender of a message. 0 for anything else, such as the bot being added to
     * a group.
     *
     * @param array<string, mixed> $update
     */
    private function senderId(array $update): int
    {
        foreach (['callback_query', 'message'] as $key) {
            if (isset($update[$key]['from']['id'])) {
                return (int) $update[$key]['from']['id'];
            }
        }

        return 0;
    }

    /**
     * True the first time an update_id is seen, recording it as the high-water
     * mark. An update with no id (which shouldn't happen) is let through rather
     * than blocked.
     *
     * The check and the write are one UPDATE, so only one request can claim an
     * id. A read followed by a write was not enough: on a slow host Telegram
     * re-sends before the first request has written, every copy reads the old
     * value, and the member gets the same answer two or three times.
     *
     * @param array<string, mixed> $update
     */
    private function isFreshUpdate(array $update): bool
    {
        global $wpdb;

        $id = (int) ($update['update_id'] ?? 0);

        if (0 === $id) {
            return true;
        }

        // Make sure the row exists, so the UPDATE below has something to claim.
        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)"
                . " VALUES (%s, '0', 'yes')",
                self::LAST_UPDATE_OPTION
            )
        );

        $claimed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %d"
                . ' WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d',
                $id,
                self::LAST_UPDATE_OPTION,
                $id
            )
        );

        // The value was written behind WordPress's back, so drop the cached copy.
        wp_cache_delete(self::LAST_UPDATE_OPTION, 'options');
        wp_cache_delete('alloptions', 'options');

        return 1 === $claimed;
    }

    /**
     * Matches "/start", "/start payload" and the "/board@thisbot" form
     * Telegram uses for commands in groups.
     */
    private function isCommand(string $text, string $command): bool
    {
        return 1 === preg_match('#^/' . preg_quote($command, '#') . '(@\w+)?($|\s)#i', $text);
    }

    /**
     * The first token after a command, e.g. "me" from "/start me" - the deep
     * link payload Telegram appends when a t.me/bot?start=me button is tapped.
     * Empty when the command carries no argument.
     */
    private function commandPayload(string $text, string $command): string
    {
        if (1 === preg_match('#^/' . preg_quote($command, '#') . '(?:@\w+)?\s+(\S+)#i', $text, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Everything after a command, e.g. "add Anna +1 ; DJ" from
     * "/guest add Anna +1 ; DJ". Empty when the command carries no argument.
     */
    private function commandArguments(string $text, string $command): string
    {
        if (1 === preg_match('#^/' . preg_quote($command, '#') . '(?:@\w+)?\s+(.+)$#is', $text, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $from
     */
    private function displayName(array $from): string
    {
        return trim(
            (string) ($from['first_name'] ?? '') . ' ' . (string) ($from['last_name'] ?? '')
        );
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\GuestCommand;
use EventCrew\Support\Language;
use EventCrew\Support\MessageActions;
use EventCrew\Support\NewcomerApproval;
use EventCrew\Support\TakeoverService;
use EventCrew\Support\TelegramLogin;

/**
 * Reads one Telegram update and sends it to the right place.
 *
 * Button taps go to the service that owns their prefix. The commands are
 * /board, /me, /tasks, /tickets, /settings, /help, and /roster, /door and
 * /people for the welcome crew and organizers; everything else is a button under them (MenuButtons), including
 * the Cancel button under each question. A plain private message is first the
 * answer to such a question; any other plain message goes to the
 * language-model helper (AssistantService), which does nothing while it is
 * switched off. Anything else is ignored - the webhook still answers 200, it
 * just does nothing.
 *
 * A blocked (banned) person gets nothing from the bot: a button tap answers
 * "Your account is blocked", a private message gets the same line, and a group
 * message is ignored.
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
        private readonly ?HelpService $help = null,
        private readonly ?AssistantService $assistant = null,
        private readonly ?NewsletterCommand $newsletter = null,
        private readonly ?SayCommand $say = null,
        private readonly ?EventsCommand $events = null,
        private readonly ?WebLoginBot $webLogin = null,
        private readonly ?BotLog $botLog = null,
        private readonly ?FollowupCommand $followup = null,
        private readonly ?PersonRepository $people = null,
        private readonly ?TelegramClient $telegram = null,
        private readonly ?BuyerListReset $buyerListReset = null,
        private readonly ?NewcomerApproval $newcomers = null,
        private readonly ?PrivateChatGate $gate = null
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
     * Routes one update and records it in the command log (BotLog): every
     * typed command and button tap, and with $source "helper" every action the
     * language-model helper runs, since those come through here too. A
     * handler that throws marks its row as an error.
     *
     * @param array<string, mixed> $update
     * @param string|null $chosen The sender's language, null if not chosen yet.
     * @param string $source BotLog::SOURCE_HELPER for the helper's actions, else ''.
     */
    private function route(array $update, ?string $chosen, string $source = ''): void
    {
        $logged = null !== $this->botLog ? $this->botLog->record($update, $source) : 0;

        try {
            $this->routeUpdate($update, $chosen);
        } catch (\Throwable $e) {
            $this->botLog?->failed($logged);

            throw $e;
        }
    }

    /**
     * In a private chat, a button tap on the bot's last message answers in
     * that message instead of a new one (TelegramClient::replaceNextIn()); a
     * message the person writes ends that, since the bot's message is no longer
     * the last one.
     *
     * @param array<string, mixed> $update
     */
    private function replaceInPlace(array $update): void
    {
        $callback = is_array($update['callback_query'] ?? null) ? $update['callback_query'] : null;

        if (null !== $callback) {
            $message = is_array($callback['message'] ?? null) ? $callback['message'] : [];

            if ('private' === ($message['chat']['type'] ?? '')) {
                $this->telegram?->replaceNextIn((int) ($message['chat']['id'] ?? 0), (int) ($message['message_id'] ?? 0));
            }

            return;
        }

        $message = is_array($update['message'] ?? null) ? $update['message'] : null;

        if (null !== $message && 'private' === ($message['chat']['type'] ?? '')) {
            $this->telegram?->forgetLast((int) ($message['chat']['id'] ?? 0));
        }
    }

    /**
     * @param array<string, mixed> $update
     * @param string|null $chosen The sender's language, null if not chosen yet.
     */
    private function routeUpdate(array $update, ?string $chosen): void
    {
        // The crew's group, from any group update: a move to a supergroup, or
        // the group itself when the site knows none (GroupChat::learn()).
        GroupChat::learn($update);

        if ($this->refusedAsBlocked($update)) {
            return;
        }

        // A private chat with someone who is neither linked nor in the crew
        // group goes no further (PrivateChatGate).
        if (null !== $this->gate && ! $this->gate->admits($update)) {
            return;
        }

        $this->replaceInPlace($update);

        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $callbackQuery = $update['callback_query'];

            $data = (string) ($callbackQuery['data'] ?? '');

            if (null !== $this->language && str_starts_with($data, LanguageService::PREFIX)) {
                $this->onLanguageChosen($callbackQuery);
            } elseif (str_starts_with($data, MenuButtons::PREFIX)) {
                $this->onMenuTap($callbackQuery);
            } elseif (str_starts_with($data, 'rep:')) {
                $this->replacement->onSelect($callbackQuery);
            } elseif (str_starts_with($data, TakeoverService::CALLBACK_PREFIX)) {
                $this->replacement->onAnswer($callbackQuery);
            } elseif (str_starts_with($data, 'rm:')) {
                $this->roster->onMark($callbackQuery);
            } elseif (str_starts_with($data, RosterService::SELECT_PREFIX)) {
                $this->roster->onSelect($callbackQuery);
            } elseif (str_starts_with($data, BoardService::PICK_PREFIX)) {
                $this->board->onPickDate($callbackQuery);
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
            } elseif (null !== $this->say && str_starts_with($data, SayCommand::PREFIX)) {
                $this->say->onConfirm($callbackQuery);
            } elseif (null !== $this->events && str_starts_with($data, EventsCommand::PREFIX)) {
                $this->events->onConfirm($callbackQuery);
            } elseif (null !== $this->webLogin && str_starts_with($data, TelegramLogin::CALLBACK_PREFIX)) {
                $this->webLogin->onConfirm($callbackQuery);
            } elseif (null !== $this->buyerListReset && str_starts_with($data, BuyerListReset::PREFIX)) {
                $this->buyerListReset->onTap($callbackQuery);
            } elseif (null !== $this->newcomers && str_starts_with($data, NewcomerApproval::PREFIX)) {
                $this->newcomers->onTap($callbackQuery);
            } elseif (str_starts_with($data, OnboardingService::JOIN_PREFIX)) {
                $this->onboarding->onJoinTap($callbackQuery);
            } elseif (null !== $this->assistant && str_starts_with($data, AssistantService::PREFIX)) {
                $this->assistant->onConfirm($callbackQuery, $this->runner($chosen));
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

        if ($this->isCommand($text, 'start')) {
            // The board's deep-link buttons both land here as /start with a
            // payload: "onboard" (or none) is the sign-up flow, "me" is the
            // returning member asking for their summary in the DM.
            // "login-<code>" is the crew app's Telegram sign-in button.
            // The weekly code on the end of a deep link (StartCode) only
            // mattered to the gate.
            [$payload] = StartCode::split($this->commandPayload($text, 'start'));

            if ('me' === $payload) {
                $this->profile->onMe($fromId, $chatId, $isPrivate);

                return;
            }

            // An email's "Open in Telegram" link: t.me/<bot>?start=<verb>
            // runs the same thing as that verb's button in a DM.
            if ($isPrivate && 0 !== $fromId && '' !== MenuButtons::forVerb($payload)) {
                $this->runMenu(MenuButtons::forVerb($payload), $fromId, $chatId, $isPrivate);

                return;
            }

            // A board tap that could not reach the private chat:
            // t.me/<bot>?start=cancel-<id> asks the cancel question here.
            if ($isPrivate && 0 !== $fromId && $this->board->openCancel($fromId, $chatId, $payload)) {
                return;
            }

            // An email's "Open in Telegram" link to one thing:
            // t.me/<bot>?start=<verb>-<id> (MessageActions::startUrl()) shows
            // it with its buttons. Opening it never does anything.
            $screen = MessageActions::screenPayload($payload);

            if (
                $isPrivate
                && 0 !== $fromId
                && null !== $screen
                && $this->openScreen($screen[0], $screen[1], $fromId, $chatId)
            ) {
                return;
            }

            $from = is_array($message['from'] ?? null) ? $message['from'] : [];

            if (null !== $this->webLogin && $isPrivate && WebLoginBot::isLoginPayload($payload)) {
                $this->webLogin->onStart($fromId, $chatId, $this->displayName($from), $payload);

                return;
            }

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

        // /door: buttons for the door list, the guest list and the sales desk
        // (each checks who may open it). An optional date picks the night.
        // "/door add|edit|remove ..." changes the guest list directly.
        if ($this->isCommand($text, 'door')) {
            $arguments = $this->commandArguments($text, 'door');

            if (GuestCommand::SHOW !== GuestCommand::parse($arguments, current_time('Y-m-d'))['action']) {
                $this->guestList->onGuestCommand($fromId, $chatId, $isPrivate, $arguments);
            } elseif (null !== $this->help) {
                $this->help->onDoor($fromId, $chatId, $isPrivate, $arguments);
            }

            return;
        }

        // /people (organizers only): buttons for credits, roles and who may
        // hold each role.
        if (null !== $this->help && $this->isCommand($text, 'people')) {
            $this->help->onPeople($fromId, $chatId, $isPrivate);

            return;
        }

        // /tasks, /tickets and /settings: the screens under /me's buttons
        // (Owner 2026-11-07). Like /me they answer in the asker's DM.
        if ($this->isCommand($text, 'tasks')) {
            $this->profile->onMyTasks($fromId, $chatId, $isPrivate);

            return;
        }

        if ($this->isCommand($text, 'tickets')) {
            $this->profile->onMyTickets($fromId, $chatId, $isPrivate);

            return;
        }

        if ($this->isCommand($text, 'settings')) {
            $this->profile->onSettings($fromId, $chatId, $isPrivate);

            return;
        }

        // /me, /me history and /me tickets work from either the group or a
        // private chat; the service always answers in the asker's DM so a
        // personal summary is never printed in front of the whole crew.
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
            $this->privateBoard($chatId);

            return;
        }

        if (null !== $this->newsletter && $this->isCommand($text, 'newsletter')) {
            $this->newsletter->onCommand($fromId, $chatId, $isPrivate, $this->commandArguments($text, 'newsletter'));

            return;
        }

        if (null !== $this->followup && $this->isCommand($text, 'followup')) {
            $this->followup->onCommand($fromId, $chatId, $isPrivate, $this->commandArguments($text, 'followup'));

            return;
        }

        // /say (organizers, private chat): a message for the group, shown
        // first with Confirm and Cancel.
        if (null !== $this->say && $this->isCommand($text, 'say')) {
            $this->say->onCommand($message, $isPrivate);

            return;
        }

        // /events (organizers, private chat): the coming events as picture
        // cards, shown first with Confirm and Cancel, then posted in the group.
        if (null !== $this->events && $this->isCommand($text, 'events')) {
            $this->events->onCommand($fromId, $chatId, $isPrivate);

            return;
        }

        // Any other command - mistyped, or one the bot no longer has - gets the
        // /help answer, so the person sees what they can type. In a group, a
        // command addressed to another bot (/x@otherbot) is left alone.
        if (null !== $this->help && str_starts_with($text, '/') && ! $this->isForAnotherBot($text)) {
            $this->help->onHelp($fromId, $chatId, $isPrivate);

            return;
        }

        // A plain private message is only meaningful as the answer to a question
        // the bot just asked - who the cover is standing in for, who to gift a
        // credit, the onboarding email, or a newcomer's number.
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
            } elseif ($this->onboarding->isAwaitingJoinNumber($fromId)) {
                $this->onboarding->captureJoinNumber($fromId, $chatId, $text);
            } else {
                $this->assistant?->onMessage($message, $chosen, $this->runner($chosen));
            }

            return;
        }

        // A plain group message: only the language-model helper reads it, and
        // only when it is switched on; it stays silent unless the message was
        // written to the bot.
        $this->assistant?->onMessage($message, $chosen, $this->runner($chosen));
    }

    /**
     * How the language-model helper runs an action: a made-up update sent
     * through route(), exactly like a typed command or a tapped button.
     *
     * @return callable(array<string, mixed>): void
     */
    private function runner(?string $chosen): callable
    {
        return function (array $update) use ($chosen): void {
            $this->route($update, $chosen, BotLog::SOURCE_HELPER);
        };
    }

    /**
     * Shows the one thing a <verb>-<id> start payload names, in the private
     * chat: the task with its Sign up button (task-<id>), a takeover request
     * with its two answers, for its holder (tov-<id>), one day of the board
     * (board-<Ymd>). False for a verb nothing here opens, so the payload goes
     * on to the sign-up flow.
     */
    private function openScreen(string $verb, string $id, int $fromId, int $chatId): bool
    {
        switch ($verb) {
            case 'task':
                $this->board->sendTask($chatId, (int) $id);

                return true;
            case TakeoverService::START_VERB:
                $this->replacement->onRequestLink($fromId, $chatId, (int) $id);

                return true;
            case TicketRedemptionService::START_VERB:
                $this->tickets->onNightLink($fromId, $chatId, $id);

                return true;
            case PermissionService::WHO_VERB:
                $this->permissions->showPerson($fromId, $chatId, (int) $id);

                return true;
            case DoorListService::START_VERB:
                $date = 1 === preg_match('/^(\d{4})(\d{2})(\d{2})$/', $id, $m) ? $m[1] . '-' . $m[2] . '-' . $m[3] : '';
                $this->doorList->onTicketsCommand($fromId, $chatId, true, $date);

                return true;
            case BoardService::START_VERB:
                return $this->board->openDay($chatId, $id);
            case NewcomerApproval::START_VERB:
                $this->newcomers?->showRequest($fromId, $chatId, (int) $id);

                return null !== $this->newcomers;
        }

        return false;
    }

    /**
     * A tap on a MenuButtons button: opens what the old command opened, in the
     * chat the button is in (always the private chat).
     *
     * @param array<string, mixed> $callbackQuery
     */
    private function onMenuTap(array $callbackQuery): void
    {
        $data = (string) ($callbackQuery['data'] ?? '');
        $fromId = (int) ($callbackQuery['from']['id'] ?? 0);
        $chat = is_array($callbackQuery['message']['chat'] ?? null) ? $callbackQuery['message']['chat'] : [];
        $chatId = (int) ($chat['id'] ?? $fromId);
        $isPrivate = 'private' === ($chat['type'] ?? 'private');

        if (null !== $this->help) {
            $this->help->answerTap((string) ($callbackQuery['id'] ?? ''));
        }

        if (0 === $fromId) {
            return;
        }

        $this->runMenu($data, $fromId, $chatId, $isPrivate);
    }

    /**
     * The board in a private chat. One text message (after the event's
     * card): the tasks of the roles this person holds, never on the group's
     * board, are in it (and for organizers, ➕ to put someone on a task;
     * BoardRenderer::crewBoard()), not in a message of their own.
     */
    private function privateBoard(int $chatId): void
    {
        $this->board->sendPrivate($chatId);
    }

    /**
     * What a menu button does: a button tap, or a bot verb from an email's
     * "Open in Telegram" link (MenuButtons::VERBS).
     */
    private function runMenu(string $data, int $fromId, int $chatId, bool $isPrivate): void
    {
        if (str_starts_with($data, MenuButtons::DOOR_LIST)) {
            $date = substr($data, strlen(MenuButtons::DOOR_LIST));
            $this->doorList->onTicketsCommand($fromId, $chatId, $isPrivate, $date);

            return;
        }

        if (str_starts_with($data, MenuButtons::GUEST_LIST)) {
            $date = substr($data, strlen(MenuButtons::GUEST_LIST));
            $this->guestList->onGuestCommand($fromId, $chatId, $isPrivate, $date);

            return;
        }

        match ($data) {
            MenuButtons::TICKET => $this->tickets->onTicketCommand($fromId, $chatId, $isPrivate),
            MenuButtons::REPLACE => $this->replacement->start($fromId, $chatId, $isPrivate),
            MenuButtons::WEB => $this->profile->onWeb($fromId, $chatId, $isPrivate),
            MenuButtons::NOTIFICATIONS => $this->notifications->start($fromId, $chatId, $isPrivate),
            MenuButtons::LANGUAGE => $this->language?->ask($fromId, $chatId, null, true),
            MenuButtons::SALES => $this->salesLink->onSalesCommand($fromId, $chatId, $isPrivate),
            MenuButtons::GIFT => $this->gift->start($fromId, $chatId, $isPrivate),
            MenuButtons::ROLES => $this->permissions->start($fromId, $chatId, $isPrivate),
            MenuButtons::ELIGIBLE => $this->permissions->onEligible($fromId, $chatId, $isPrivate),
            MenuButtons::CANCEL => $this->cancelQuestions($fromId, $chatId, $isPrivate),
            MenuButtons::ME => $this->profile->onMe($fromId, $chatId, $isPrivate),
            MenuButtons::BOARD => $this->privateBoard($chatId),
            MenuButtons::PEOPLE => $this->help?->onPeople($fromId, $chatId, $isPrivate),
            MenuButtons::TELL => $this->askWhatIsWrong($fromId, $chatId, $isPrivate),
            MenuButtons::TASK_INFO => $this->board->sendTaskInfo($fromId),
            MenuButtons::TICKETS => $this->profile->onMyTickets($fromId, $chatId, $isPrivate),
            MenuButtons::MY_TASKS => $this->profile->onMyTasks($fromId, $chatId, $isPrivate),
            MenuButtons::HISTORY => $this->profile->onMyHistory($fromId, $chatId, $isPrivate),
            MenuButtons::SETTINGS => $this->profile->onSettings($fromId, $chatId, $isPrivate),
            MenuButtons::HELP => $this->help?->onHelp($fromId, $chatId, $isPrivate, true),
            MenuButtons::ADMIN => $this->help?->onAdmin($fromId, $chatId, $isPrivate),
            MenuButtons::ROSTER => $this->roster->onRosterCommand($fromId, $chatId, $isPrivate, ''),
            MenuButtons::DOOR => $this->help?->onDoor($fromId, $chatId, $isPrivate, ''),
            default => null,
        };
    }

    /**
     * "Not right? Tell us" (MenuButtons::TELL): asks the person to write what
     * is wrong. The question goes into the chat's log (ConversationLog), so
     * the bot's helper reads their answer as a reply to it and sends the chat
     * to the organizers (ChatForwarder). With the helper off, it says to tell
     * an organizer.
     */
    private function askWhatIsWrong(int $fromId, int $chatId, bool $isPrivate): void
    {
        if (! $isPrivate || 0 === $fromId || null === $this->telegram) {
            return;
        }

        if (! AssistantSettings::isActive() || ! AssistantSettings::forwardEnabled()) {
            $this->telegram->sendMessage(
                $chatId,
                __('Please tell an organizer what is not right: they can correct it.', 'eventcrew')
            );

            return;
        }

        $text = '✍️ ' . __(
            'Write here in a few words what is not right. The organizers get it and can correct it.',
            'eventcrew'
        );
        $this->telegram->sendMessage($chatId, $text);
        ConversationLog::add($fromId, ConversationLog::BOT, $text);
    }

    /**
     * The Cancel button: drops every question the bot is waiting on for this
     * person. Each one is asked to stop, so none is left behind by a
     * short-circuit.
     */
    private function cancelQuestions(int $fromId, int $chatId, bool $isPrivate): void
    {
        $stopped = [
            $this->replacement->cancelPending($fromId),
            $this->gift->cancelPending($fromId),
            $this->permissions->cancelPending($fromId),
            null !== $this->roleTasks && $this->roleTasks->cancelPending($fromId),
            $this->onboarding->cancelPending($fromId),
        ];

        $this->help?->onCancel($chatId, $isPrivate, in_array(true, $stopped, true));
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
     * True when the sender of a button tap or a message is a blocked person.
     * Then the bot answers only "Your account is blocked" - as a popup for a
     * tap, as a message in a private chat, and not at all in a group - and
     * nothing else runs.
     *
     * @param array<string, mixed> $update
     */
    private function refusedAsBlocked(array $update): bool
    {
        if (null === $this->people || null === $this->telegram) {
            return false;
        }

        $sender = $this->senderId($update);

        if ($sender <= 0) {
            return false;
        }

        $person = $this->people->findByTelegramUserId($sender);

        if (null === $person || ! $person->isBanned()) {
            return false;
        }

        $text = WebLoginBot::blockedText();

        if (isset($update['callback_query']['id'])) {
            $this->telegram->answerCallbackQuery((string) $update['callback_query']['id'], $text, true);
        } elseif ('private' === ($update['message']['chat']['type'] ?? '')) {
            $this->telegram->sendMessage((int) ($update['message']['chat']['id'] ?? 0), $text);
        }

        return true;
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
     * True for "/cmd@name" when name is not this bot's username. When the
     * username is not known, any addressed command counts as another bot's.
     */
    private function isForAnotherBot(string $text): bool
    {
        if (1 !== preg_match('#^/\w+@(\w+)#', $text, $m)) {
            return false;
        }

        $own = ltrim(trim((string) get_option(BoardService::USERNAME_OPTION, '')), '@');

        return '' === $own || 0 !== strcasecmp($own, $m[1]);
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
     * "/door add Anna +1 ; DJ". Empty when the command carries no argument.
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

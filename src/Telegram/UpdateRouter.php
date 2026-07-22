<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

/**
 * Reads one Telegram update and sends it to the right place.
 *
 * The bot understands a deliberately small vocabulary: a button tap (join or
 * leave), /start in its private chat (onboarding), the email that follows it,
 * and /board in the group (post or refresh the board). Anything else is
 * ignored - the webhook still answers 200, it just does nothing.
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
        private readonly ProfileService $profile
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

        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $callbackQuery = $update['callback_query'];

            if (str_starts_with((string) ($callbackQuery['data'] ?? ''), 'rep:')) {
                $this->replacement->onSelect($callbackQuery);
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

        $text = trim((string) ($message['text'] ?? ''));
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $fromId = (int) ($message['from']['id'] ?? 0);
        $isPrivate = 'private' === ($message['chat']['type'] ?? '');

        if ($isPrivate && $this->isCommand($text, 'start')) {
            $from = is_array($message['from'] ?? null) ? $message['from'] : [];
            $this->onboarding->start($fromId, $chatId, $this->displayName($from));

            return;
        }

        // /roster works from either the group or a private chat; the service
        // itself refuses anyone who is not a linked organizer.
        if ($this->isCommand($text, 'roster')) {
            $this->roster->onRosterCommand($fromId, $chatId);

            return;
        }

        if ($isPrivate && $this->isCommand($text, 'stop')) {
            $this->onboarding->stop($fromId, $chatId);

            return;
        }

        if ($isPrivate && $this->isCommand($text, 'replace')) {
            $this->replacement->start($fromId, $chatId);

            return;
        }

        if ($isPrivate && $this->isCommand($text, 'me')) {
            $this->profile->onMe($fromId, $chatId);

            return;
        }

        if (! $isPrivate && $this->isCommand($text, 'board')) {
            $this->board->setBoardChat($chatId);
            $this->board->refresh();

            return;
        }

        // A plain private message is only meaningful as the answer to a question
        // the bot just asked - who the cover is standing in for, or the
        // onboarding email.
        if ($isPrivate && 0 !== $fromId) {
            if ($this->replacement->isAwaitingTarget($fromId)) {
                $entities = is_array($message['entities'] ?? null) ? $message['entities'] : [];
                $this->replacement->captureTarget($fromId, $chatId, $text, $entities);
            } elseif ($this->onboarding->isAwaitingEmail($fromId)) {
                $this->onboarding->captureEmail($fromId, $chatId, $text);
            }
        }
    }

    /**
     * True the first time an update_id is seen, recording it as the high-water
     * mark. An update with no id (which shouldn't happen) is let through rather
     * than blocked.
     *
     * @param array<string, mixed> $update
     */
    private function isFreshUpdate(array $update): bool
    {
        $id = (int) ($update['update_id'] ?? 0);

        if (0 === $id) {
            return true;
        }

        if ($id <= (int) get_option(self::LAST_UPDATE_OPTION, 0)) {
            return false;
        }

        update_option(self::LAST_UPDATE_OPTION, $id);

        return true;
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
     * @param array<string, mixed> $from
     */
    private function displayName(array $from): string
    {
        return trim(
            (string) ($from['first_name'] ?? '') . ' ' . (string) ($from['last_name'] ?? '')
        );
    }
}

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
    public function __construct(
        private readonly OnboardingService $onboarding,
        private readonly BoardService $board
    ) {
    }

    /**
     * @param array<string, mixed> $update
     */
    public function dispatch(array $update): void
    {
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $this->board->onJoinLeave($update['callback_query']);

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

        if (! $isPrivate && $this->isCommand($text, 'board')) {
            $this->board->setBoardChat($chatId);
            $this->board->refresh();

            return;
        }

        // A plain private message is only meaningful as the answer to the
        // "what's your email?" the bot just asked; otherwise it is ignored.
        if ($isPrivate && 0 !== $fromId && $this->onboarding->isAwaitingEmail($fromId)) {
            $this->onboarding->captureEmail($fromId, $chatId, $text);
        }
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

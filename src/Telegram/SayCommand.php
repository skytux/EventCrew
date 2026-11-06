<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\PersonRepository;

/**
 * /say: an organizer has the bot post a message in the crew group.
 *
 * - "/say <text>" in a private chat with the bot shows the message exactly as
 *   the group will see it, with Confirm and Cancel buttons. Bold, italics,
 *   links, quotes and line breaks are kept.
 * - Confirm posts it in the group the board lives in; Cancel drops it.
 * - "/say" alone says how to use it.
 *
 * In a group the command is refused, so a draft is never shown to the whole
 * crew. Anyone who is not an organizer is refused, at the command and again at
 * the Confirm tap.
 */
final class SayCommand
{
    /** Callback data prefix of the Confirm and Cancel buttons. */
    public const PREFIX = 'say:';

    /** Transient key prefix of a draft waiting for Confirm. */
    public const DRAFT_PREFIX = 'eventcrew_say_';

    /** How long a draft waits for Confirm. */
    public const DRAFT_TTL = DAY_IN_SECONDS;

    /** Telegram's limit for one message is 4096 characters. */
    public const MAX_LENGTH = 4000;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram
    ) {
    }

    /**
     * @param array<string, mixed> $message The whole message, for its formatting.
     */
    public function onCommand(array $message, bool $isPrivate): void
    {
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $fromId = (int) ($message['from']['id'] ?? 0);

        if (! $isPrivate) {
            $this->telegram->sendMessage($chatId, __('Use /say in a private chat with me.', 'eventcrew'));

            return;
        }

        if (! $this->isOrganizer($fromId)) {
            $this->telegram->sendMessage($chatId, __('Only organizers can use /say.', 'eventcrew'));

            return;
        }

        $html = self::body($message);

        if ('' === $html) {
            $this->telegram->sendMessage(
                $chatId,
                __('Send /say followed by the message for the group. I show it to you first.', 'eventcrew')
            );

            return;
        }

        if (mb_strlen(trim(strip_tags($html))) > self::MAX_LENGTH) {
            $this->telegram->sendMessage($chatId, __('This message is too long for Telegram.', 'eventcrew'));

            return;
        }

        if (0 === $this->groupChatId()) {
            $this->telegram->sendMessage($chatId, __('I am not in the crew group yet.', 'eventcrew'));

            return;
        }

        $id = bin2hex(random_bytes(8));
        set_transient(self::DRAFT_PREFIX . $id, ['user' => $fromId, 'html' => $html], self::DRAFT_TTL);

        $sent = $this->telegram->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => htmlspecialchars(__('This goes to the group:', 'eventcrew'), ENT_NOQUOTES, 'UTF-8')
                . "\n\n" . $html,
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => [[
                ['text' => '✅ ' . __('Confirm', 'eventcrew'), 'callback_data' => self::PREFIX . 'ok:' . $id],
                ['text' => '✖️ ' . __('Cancel', 'eventcrew'), 'callback_data' => self::PREFIX . 'no:' . $id],
            ]]],
        ]);

        if (null === $sent) {
            delete_transient(self::DRAFT_PREFIX . $id);
            $this->telegram->sendMessage(
                $chatId,
                __('Telegram did not accept this message. Please try again.', 'eventcrew')
            );
        }
    }

    /**
     * A Confirm or Cancel tap under a draft.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onConfirm(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $fromId = (int) ($callbackQuery['from']['id'] ?? 0);
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chatId = (int) ($message['chat']['id'] ?? $fromId);
        $messageId = (int) ($message['message_id'] ?? 0);

        if (1 !== preg_match('/^say:(ok|no):([0-9a-f]{16})$/', (string) ($callbackQuery['data'] ?? ''), $m)) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $key = self::DRAFT_PREFIX . $m[2];
        $draft = get_transient($key);

        if (! is_array($draft) || 0 === $fromId || (int) ($draft['user'] ?? 0) !== $fromId) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('This draft has expired. Please send /say again.', 'eventcrew'),
                true
            );

            return;
        }

        // Used once, whichever button it was.
        delete_transient($key);
        $this->removeButtons($chatId, $messageId);

        if ('no' === $m[1]) {
            $this->telegram->answerCallbackQuery($callbackId, __('Cancelled.', 'eventcrew'));
            $this->telegram->sendMessage($chatId, '✖️ ' . __('Cancelled. Nothing was sent to the group.', 'eventcrew'));

            return;
        }

        if (! $this->isOrganizer($fromId)) {
            $this->telegram->answerCallbackQuery($callbackId, __('Only organizers can use /say.', 'eventcrew'), true);

            return;
        }

        $group = $this->groupChatId();
        $sent = 0 === $group ? null : $this->telegram->call('sendMessage', [
            'chat_id' => $group,
            'text' => (string) ($draft['html'] ?? ''),
            'parse_mode' => 'HTML',
        ]);

        if (null === $sent) {
            $this->telegram->answerCallbackQuery($callbackId);
            $this->telegram->sendMessage(
                $chatId,
                __('The message could not be posted in the group. Please send /say again.', 'eventcrew')
            );

            return;
        }

        $this->telegram->answerCallbackQuery($callbackId, __('Sent.', 'eventcrew'));
        $this->telegram->sendMessage($chatId, '✅ ' . __('Sent to the group.', 'eventcrew'));
    }

    /**
     * The formatting of the original message, moved onto the text of a
     * "/say <text>" line the language-model helper made from it. Only when
     * <text> appears word for word in the original; otherwise none.
     *
     * @param array<string, mixed> $original
     * @return array<int, array<string, mixed>>
     */
    public static function carryEntities(string $line, array $original): array
    {
        if (1 !== preg_match('#^/say\s+#u', $line, $m)) {
            return [];
        }

        $body = substr($line, strlen($m[0]));
        $text = (string) ($original['text'] ?? '');
        $entities = is_array($original['entities'] ?? null) ? $original['entities'] : [];
        $at = '' === $body ? false : strpos($text, $body);

        if (false === $at || [] === $entities) {
            return [];
        }

        $from = EntityHtml::units(substr($text, 0, $at));
        $to = $from + EntityHtml::units($body);
        $moveBy = EntityHtml::units($m[0]) - $from;
        $carried = [];

        foreach ($entities as $entity) {
            if (! is_array($entity)) {
                continue;
            }

            $offset = (int) ($entity['offset'] ?? 0);
            $end = $offset + (int) ($entity['length'] ?? 0);

            if ($offset >= $from && $end <= $to) {
                $entity['offset'] = $offset + $moveBy;
                $carried[] = $entity;
            }
        }

        return $carried;
    }

    /**
     * The text after "/say" as Telegram HTML; '' when there is none.
     *
     * @param array<string, mixed> $message
     */
    private static function body(array $message): string
    {
        $text = (string) ($message['text'] ?? '');

        if (1 !== preg_match('#^\s*/say(?:@\w+)?(?=\s|$)\s*#u', $text, $m)) {
            return '';
        }

        $skip = EntityHtml::units($m[0]);
        $body = rtrim(substr($text, strlen($m[0])));

        if ('' === $body) {
            return '';
        }

        $entities = [];

        foreach (is_array($message['entities'] ?? null) ? $message['entities'] : [] as $entity) {
            if (! is_array($entity) || (int) ($entity['offset'] ?? 0) < $skip) {
                continue;
            }

            $entity['offset'] = (int) $entity['offset'] - $skip;
            $entities[] = $entity;
        }

        return EntityHtml::render($body, $entities);
    }

    private function isOrganizer(int $telegramUserId): bool
    {
        if (0 === $telegramUserId) {
            return false;
        }

        $person = $this->people->findByTelegramUserId($telegramUserId);

        return null !== $person && $person->isOrganizer;
    }

    /** The crew group: the chat the board lives in. 0 when there is none. */
    private function groupChatId(): int
    {
        return GroupChat::current();
    }

    private function removeButtons(int $chatId, int $messageId): void
    {
        if (0 === $chatId || 0 === $messageId) {
            return;
        }

        $this->telegram->call('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => ['inline_keyboard' => []],
        ]);
    }
}

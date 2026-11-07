<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\NewsletterText;
use EventCrew\Support\OwnTextNotice;

/**
 * /newsletter: an organizer sets the text for the next newsletter, in a
 * private chat with the bot.
 *
 * - "/newsletter" alone shows the current text in every site language and
 *   how to change it or reset it to the default, with a "Reset to default"
 *   button when there is a text.
 * - "/newsletter reset" resets it to the default: the text is removed, so the
 *   announcement goes out without it. "clear" does the same.
 * - "/newsletter <text>" saves the text. It is translated (NewsletterText) and
 *   the answer shows every site language's version, with a note when the
 *   translation could not be made.
 * - Setting a text over an earlier one first shows the earlier text, who set
 *   it and when, and asks to replace it (Replace, Keep). The helper's action
 *   asks once, in its own confirmation ($confirmed).
 * - Setting or resetting the text tells the other organizers (OwnTextNotice).
 *
 * In a group the command is refused, so the text is not written where the
 * whole crew reads it. Anyone who is not an organizer is refused.
 */
final class NewsletterCommand
{
    public const CLEAR = 'clear';

    public const RESET = 'reset';

    /** Callback data: nl:ok:<id> replaces, nl:no:<id> keeps, nl:reset. */
    public const PREFIX = 'nl:';

    private const PENDING_PREFIX = 'eventcrew_nl_pending_';

    private const PENDING_TTL = 3600;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly NewsletterText $text,
        private readonly TelegramClient $telegram,
        private readonly ?OwnTextNotice $notice = null
    ) {
    }

    /**
     * $confirmed: the person already agreed to replace an earlier text (the
     * helper's Confirm, which showed it), so it is not asked again.
     */
    public function onCommand(
        int $telegramUserId,
        int $chatId,
        bool $isPrivate,
        string $arguments,
        bool $confirmed = false
    ): void {
        if (! $isPrivate) {
            $this->telegram->sendMessage($chatId, __('Use /newsletter in a private chat with me.', 'eventcrew'));

            return;
        }

        $person = 0 !== $telegramUserId ? $this->people->findByTelegramUserId($telegramUserId) : null;

        if (null === $person || ! $person->isOrganizer) {
            $this->telegram->sendMessage($chatId, __('Only organizers can use /newsletter.', 'eventcrew'));

            return;
        }

        $arguments = trim($arguments);

        if ('' === $arguments) {
            $this->telegram->sendMessage(
                $chatId,
                $this->current(),
                null !== $this->text->get() ? self::resetButton() : null
            );

            return;
        }

        if (self::isReset($arguments)) {
            $this->reset($person, $chatId);

            return;
        }

        if (! $confirmed && null !== $this->text->get()) {
            $this->askReplace($telegramUserId, $chatId, $arguments);

            return;
        }

        $this->save($person, $chatId, $arguments);
    }

    /**
     * A tap on Replace or Keep (asked before a text replaces an earlier one),
     * or on Reset to default.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onCallback(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $fromId = (int) ($callbackQuery['from']['id'] ?? 0);
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chatId = (int) ($message['chat']['id'] ?? $fromId);
        $data = (string) ($callbackQuery['data'] ?? '');
        $person = 0 !== $fromId ? $this->people->findByTelegramUserId($fromId) : null;

        if (null === $person || ! $person->isOrganizer) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('Only organizers can use /newsletter.', 'eventcrew'),
                true
            );

            return;
        }

        if (self::PREFIX . 'reset' === $data) {
            $this->telegram->answerCallbackQuery($callbackId);
            $this->reset($person, $chatId);

            return;
        }

        if (1 !== preg_match('/^nl:(ok|no):([0-9a-f]{16})$/', $data, $m)) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $key = self::PENDING_PREFIX . $m[2];
        $pending = get_transient($key);

        if (! is_array($pending) || (int) ($pending['user'] ?? 0) !== $fromId) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('This has expired. Please send it again.', 'eventcrew'),
                true
            );

            return;
        }

        // Used once, whichever button it was.
        delete_transient($key);
        $this->telegram->answerCallbackQuery($callbackId);

        if ('no' === $m[1]) {
            $this->telegram->sendMessage($chatId, __('Kept the current text.', 'eventcrew'));

            return;
        }

        $this->save($person, $chatId, (string) ($pending['text'] ?? ''));
    }

    /**
     * For the helper's confirmation of "/newsletter <text>": the text it
     * replaces, who set it and when. '' when it replaces none.
     */
    public function replaceWarning(string $arguments): string
    {
        $arguments = trim($arguments);
        $stored = $this->text->get();

        if ('' === $arguments || self::isReset($arguments) || null === $stored) {
            return '';
        }

        return $this->earlier($stored) . "\n\n" . __('Confirm replaces it.', 'eventcrew');
    }

    private static function isReset(string $word): bool
    {
        return in_array(strtolower(trim($word)), [self::RESET, self::CLEAR], true);
    }

    /** Resets the text to the default and tells the other organizers. */
    private function reset(Person $person, int $chatId): void
    {
        $had = null !== $this->text->get();
        $this->text->clear();

        if ($had) {
            $this->notice?->changed(OwnTextNotice::NEWSLETTER, $person->id, '');
        }

        $this->telegram->sendMessage($chatId, __('The text for the next announcement is removed.', 'eventcrew'));
    }

    /** Asks before $text replaces the earlier text. */
    private function askReplace(int $telegramUserId, int $chatId, string $text): void
    {
        $id = bin2hex(random_bytes(8));
        set_transient(self::PENDING_PREFIX . $id, ['user' => $telegramUserId, 'text' => $text], self::PENDING_TTL);

        $this->telegram->sendMessage(
            $chatId,
            $this->earlier((array) $this->text->get()) . "\n\n" . __('Replace it with your text?', 'eventcrew'),
            ['inline_keyboard' => [[
                ['text' => '✅ ' . __('Replace', 'eventcrew'), 'callback_data' => self::PREFIX . 'ok:' . $id],
                ['text' => '✖️ ' . __('Keep', 'eventcrew'), 'callback_data' => self::PREFIX . 'no:' . $id],
            ]]]
        );
    }

    /**
     * "There is already a text for the next announcement:", the text and who
     * set it when.
     *
     * @param array<string, mixed> $stored
     */
    private function earlier(array $stored): string
    {
        $setBy = null !== $this->notice ? $this->notice->setBy($stored) : '';

        return __('There is already a text for the next announcement:', 'eventcrew')
            . "\n\n" . (string) ($stored['written'] ?? '')
            . ('' !== $setBy ? "\n\n" . $setBy : '');
    }

    /** Saves $text, answers and tells the other organizers. */
    private function save(Person $person, int $chatId, string $text): void
    {
        $result = $this->text->set($text, $person->id);
        $this->notice?->changed(OwnTextNotice::NEWSLETTER, $person->id, $text);
        $reply = __('Saved. The next announcement carries this text:', 'eventcrew')
            . "\n\n" . NewsletterText::listed($result['texts']);

        if (! $result['translated']) {
            $reply .= "\n\n"
                . __('The text could not be translated, so every language shows it as you wrote it.', 'eventcrew');
        }

        $this->telegram->sendMessage($chatId, $reply, self::resetButton());
    }

    /**
     * The "Reset to default" button.
     *
     * @return array<string, mixed>
     */
    private static function resetButton(): array
    {
        return ['inline_keyboard' => [[
            ['text' => '↩️ ' . OwnTextNotice::resetLabel(), 'callback_data' => self::PREFIX . 'reset'],
        ]]];
    }

    /** The answer to "/newsletter" alone. */
    private function current(): string
    {
        $stored = $this->text->get();

        if (null === $stored) {
            return __('There is no text for the next announcement.', 'eventcrew') . "\n\n"
                . __('To add one, send /newsletter followed by the text, for example:', 'eventcrew') . "\n"
                . __('/newsletter See you on the dance floor!', 'eventcrew');
        }

        $reply = __('The text for the next announcement:', 'eventcrew') . "\n\n"
            . NewsletterText::listed($stored['texts']);

        if (! $stored['translated']) {
            $reply .= "\n\n"
                . __('This text is not translated: every language shows it as it was written.', 'eventcrew');
        }

        $setBy = null !== $this->notice ? $this->notice->setBy($stored) : '';

        return $reply . ('' !== $setBy ? "\n\n" . $setBy : '') . "\n\n" . __(
            'To change it, send /newsletter followed by the new text. To reset it to the default, send /newsletter reset.',
            'eventcrew'
        );
    }
}

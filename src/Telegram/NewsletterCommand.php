<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\NewsletterText;

/**
 * /newsletter: an organizer sets the text for the next newsletter, in a
 * private chat with the bot.
 *
 * - "/newsletter" alone shows the current text in both languages and how to
 *   change or remove it.
 * - "/newsletter clear" removes the text.
 * - "/newsletter <text>" saves the text. It is translated (NewsletterText) and
 *   the answer shows both versions, with a note when the translation could
 *   not be made.
 *
 * In a group the command is refused, so the text is not written where the
 * whole crew reads it. Anyone who is not an organizer is refused.
 */
final class NewsletterCommand
{
    public const CLEAR = 'clear';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly NewsletterText $text,
        private readonly TelegramClient $telegram
    ) {
    }

    public function onCommand(int $telegramUserId, int $chatId, bool $isPrivate, string $arguments): void
    {
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
            $this->telegram->sendMessage($chatId, $this->current());

            return;
        }

        if (self::CLEAR === strtolower($arguments)) {
            $this->text->clear();
            $this->telegram->sendMessage($chatId, __('The text for the next announcement is removed.', 'eventcrew'));

            return;
        }

        $result = $this->text->set($arguments, $person->id);
        $reply = __('Saved. The next announcement carries this text:', 'eventcrew')
            . "\n\n" . self::both($result['fi'], $result['en']);

        if (! $result['translated']) {
            $reply .= "\n\n"
                . __('The text could not be translated, so both languages show it as you wrote it.', 'eventcrew');
        }

        $this->telegram->sendMessage($chatId, $reply);
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
            . self::both($stored['fi'], $stored['en']);

        if (! $stored['translated']) {
            $reply .= "\n\n"
                . __('This text is not translated: both languages show it as it was written.', 'eventcrew');
        }

        return $reply . "\n\n" . __(
            'To change it, send /newsletter followed by the new text. To remove it, send /newsletter clear.',
            'eventcrew'
        );
    }

    private static function both(string $fi, string $en): string
    {
        return __('In Finnish:', 'eventcrew') . "\n" . $fi . "\n\n" . __('In English:', 'eventcrew') . "\n" . $en;
    }
}

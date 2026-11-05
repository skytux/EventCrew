<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\FollowupText;
use EventCrew\Support\Language;

/**
 * /followup: an organizer sets a one-time text for the next post-event email
 * (FollowupMail), in a private chat with the bot. It works like /newsletter.
 *
 * - "/followup" alone shows both texts the next email uses (the thank-you to
 *   those who came, the sorry text to those who did not), in both languages,
 *   and whether each is a one-time text or the one on the Messages page.
 * - "/followup came <text>" or "/followup missed <text>" saves a one-time text
 *   for that version. It is translated (FollowupText, the same way as the
 *   newsletter's text) and the answer shows both languages.
 * - "/followup came clear" or "/followup missed clear" removes that one-time
 *   text; "/followup clear" removes both.
 *
 * A one-time text is used by the next night's post-event email only, then
 * removed. In a group the command is refused, so the text is not written
 * where the whole crew reads it. Anyone who is not an organizer is refused.
 */
final class FollowupCommand
{
    public const CLEAR = 'clear';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly FollowupText $text,
        private readonly TelegramClient $telegram
    ) {
    }

    public function onCommand(int $telegramUserId, int $chatId, bool $isPrivate, string $arguments): void
    {
        if (! $isPrivate) {
            $this->telegram->sendMessage($chatId, __('Use /followup in a private chat with me.', 'eventcrew'));

            return;
        }

        $person = 0 !== $telegramUserId ? $this->people->findByTelegramUserId($telegramUserId) : null;

        if (null === $person || ! $person->isOrganizer) {
            $this->telegram->sendMessage($chatId, __('Only organizers can use /followup.', 'eventcrew'));

            return;
        }

        $arguments = trim($arguments);

        if ('' === $arguments) {
            $this->telegram->sendMessage($chatId, $this->current());

            return;
        }

        if (self::CLEAR === strtolower($arguments)) {
            $this->text->clearNext();
            $this->telegram->sendMessage(
                $chatId,
                __(
                    'Both one-time texts are removed. The next post-event email uses the texts on the Messages page.',
                    'eventcrew'
                )
            );

            return;
        }

        $parts = preg_split('/\s+/u', $arguments, 2);
        $variant = strtolower((string) ($parts[0] ?? ''));
        $text = trim((string) ($parts[1] ?? ''));

        if (! FollowupText::isVariant($variant) || '' === $text) {
            $this->telegram->sendMessage($chatId, self::usage());

            return;
        }

        if (self::CLEAR === strtolower($text)) {
            $this->text->clearNext($variant);
            $this->telegram->sendMessage(
                $chatId,
                self::label($variant) . "\n"
                    . __(
                        'The one-time text is removed. The next post-event email uses the text on the Messages page.',
                        'eventcrew'
                    )
            );

            return;
        }

        $result = $this->text->setNext($variant, $text, $person->id);
        $reply = self::label($variant) . "\n"
            . __('Saved for the next post-event email only:', 'eventcrew')
            . "\n\n" . self::both($result['fi'], $result['en']);

        if (! $result['translated']) {
            $reply .= "\n\n"
                . __('The text could not be translated, so both languages show it as you wrote it.', 'eventcrew');
        }

        $this->telegram->sendMessage($chatId, $reply);
    }

    /** The answer to "/followup" alone. */
    private function current(): string
    {
        $parts = [];

        foreach (FollowupText::VARIANTS as $variant) {
            $next = $this->text->next($variant);
            $part = self::label($variant) . "\n"
                . (null === $next
                    ? __('The next email uses the text on the Messages page.', 'eventcrew')
                    : __('The next email uses a one-time text.', 'eventcrew'))
                . "\n\n" . self::both(
                    $this->text->text($variant, Language::FINNISH)['text'],
                    $this->text->text($variant, Language::ENGLISH)['text']
                );

            if (null !== $next && ! $next['translated']) {
                $part .= "\n\n"
                    . __('This text is not translated: both languages show it as it was written.', 'eventcrew');
            }

            $parts[] = $part;
        }

        return implode("\n\n————————\n\n", $parts) . "\n\n" . self::usage();
    }

    private static function usage(): string
    {
        return __('To set a text for the next post-event email only, send /followup came followed by the thank-you text, or /followup missed followed by the sorry text. {event} becomes the event’s name and {date} its date. To remove a one-time text, send /followup came clear or /followup missed clear; /followup clear removes both.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
    }

    private static function label(string $variant): string
    {
        return FollowupText::CAME === $variant
            ? __('Thank-you text (came)', 'eventcrew')
            : __('Sorry text (missed)', 'eventcrew');
    }

    private static function both(string $fi, string $en): string
    {
        return __('In Finnish:', 'eventcrew') . "\n" . $fi . "\n\n" . __('In English:', 'eventcrew') . "\n" . $en;
    }
}

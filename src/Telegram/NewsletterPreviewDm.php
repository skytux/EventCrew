<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Support\Language;
use EventCrew\Support\SiteLanguages;
use EventCrew\Support\TicketMail;

/**
 * The announcement's preview as Telegram messages, for one organizer, in
 * their own language (Owner, 2026-11-08). Sent next to the preview email
 * (Newsletter::sendPreview()):
 *
 * 1. "Preview of the next announcement" in bold, then the text above the
 *    cards (the organizers' own text, or the language model's opening).
 * 2. One card per event, from the group's card template (EventCard) in the
 *    organizer's language: the picture, the caption and the link buttons.
 * 3. When it goes out and to how many, with the "Do Not Send This" button,
 *    which opens the same stop page as the email's.
 *
 * Nothing is sent to an organizer the bot cannot write to (no Telegram).
 */
final class NewsletterPreviewDm
{
    /** The text above the cards is cut to this, so the first message fits. */
    private const INTRO_MAX = 3500;

    public function __construct(private readonly TelegramClient $telegram)
    {
    }

    /**
     * Returns true when the cards and the stop button were sent.
     *
     * @param list<int> $eventIds The events of the announcement, in order.
     * @param array<string, string>|null $intro The text above the cards, keyed by language.
     */
    public function send(
        Person $person,
        array $eventIds,
        ?array $intro,
        string $sendAt,
        int $subscribers,
        string $stopUrl
    ): bool {
        $chatId = $person->telegramChatId ?? $person->telegramUserId;

        if (null === $chatId || 0 === $chatId) {
            return false;
        }

        $lang = self::language($person);
        $words = Language::run($lang, static fn (): array => [
            'title' => __('Preview of the next announcement', 'eventcrew'),
            'stop' => __('Do Not Send This', 'eventcrew'),
            'line' => sprintf(
                /* translators: 1: date, like 14.11.2026, 2: time, like 10:00, 3: number of subscribers */
                _n(
                    'Goes out on %1$s at %2$s to %3$d subscriber.',
                    'Goes out on %1$s at %2$s to %3$d subscribers.',
                    $subscribers,
                    'eventcrew'
                ),
                TicketMail::shortDate(substr($sendAt, 0, 10)),
                substr($sendAt, 11, 5),
                $subscribers
            ),
        ]);

        $head = '<b>' . self::escape($words['title']) . '</b>';
        $text = self::introIn($intro, $lang);

        if ('' !== $text) {
            $head .= "\n\n" . self::escape($text);
        }

        $this->telegram->call('sendMessage', ['chat_id' => $chatId, 'text' => $head, 'parse_mode' => 'HTML']);

        foreach ($eventIds as $postId) {
            $card = EventCard::forEvent((int) $postId, null, $lang);

            if (null !== $card) {
                EventCard::send($this->telegram, $chatId, $card);
            }
        }

        $markup = ['inline_keyboard' => [[['text' => '🛑 ' . $words['stop'], 'url' => $stopUrl]]]];

        return null !== $this->telegram->sendMessage($chatId, $words['line'], $markup);
    }

    /** The organizer's language when the site has it, else the site's main language. */
    private static function language(Person $person): string
    {
        $lang = Language::normalize($person->language);

        return null === $lang ? SiteLanguages::main() : $lang;
    }

    /**
     * The text above the cards in $lang, else in the main language, else in
     * any language; cut to INTRO_MAX characters.
     *
     * @param array<string, string>|null $intro
     */
    private static function introIn(?array $intro, string $lang): string
    {
        if (null === $intro) {
            return '';
        }

        $text = '';

        foreach ([$lang, SiteLanguages::main(), ...array_keys($intro)] as $code) {
            $text = trim((string) ($intro[$code] ?? ''));

            if ('' !== $text) {
                break;
            }
        }

        return mb_strlen($text) > self::INTRO_MAX ? rtrim(mb_substr($text, 0, self::INTRO_MAX - 1)) . '…' : $text;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
    }
}

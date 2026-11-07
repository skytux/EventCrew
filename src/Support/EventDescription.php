<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Telegram\BoardDjText;

/**
 * The short text about an event for the person-facing messages about it
 * ({event_description}): only a curated text, never the event's own
 * description. The raw description (the EventMesh post's content or
 * excerpt, EventSource::card()) is often a block of details (the title in
 * capitals, the date, venue, address, door code, schedule lines) and belongs
 * in no message; it is only input for the language model (Owner, 2026-11-07).
 *
 * The curated text is the group board's short text about the event's DJ or
 * host (BoardDjText::stored()): two sentences the language model wrote from
 * the event's DJs and description, under a prompt that forbids door codes.
 * The model writes it in English; the same background run translates it into
 * every other site language (BoardDjText::translate()), so a reader gets it
 * in their own language. Sending never asks the model: a reader whose
 * language has no current translation gets '' (not the English text), and
 * so does everybody when there is no current text, so the line is left out.
 */
final class EventDescription
{
    public const MAX_SENTENCES = 2;

    public const MAX_LENGTH = 300;

    /**
     * The curated short text of event $postId for a reader in $lang (the
     * language being composed in when null), or ''.
     */
    public static function short(?int $postId, ?string $lang = null): string
    {
        if (null === $postId || $postId <= 0) {
            return '';
        }

        $lang ??= Language::current() ?? Language::ENGLISH;

        return self::shorten(BoardDjText::stored($postId, $lang));
    }

    /**
     * The first MAX_SENTENCES sentences of $text on one line, cut at a word
     * with "…" when they are longer than MAX_LENGTH. A sentence ends at ".",
     * "!", "?" or "…" followed by a space and a capital letter or a digit, so
     * a date like "14.11. klo 19" stays whole.
     */
    public static function shorten(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ('' === $text) {
            return '';
        }

        $sentences = preg_split('/(?<=[.!?…])\s+(?=[\p{Lu}\p{N}"“«])/u', $text) ?: [$text];
        $short = implode(' ', array_slice($sentences, 0, self::MAX_SENTENCES));

        if (self::length($short) <= self::MAX_LENGTH) {
            return $short;
        }

        $cut = function_exists('mb_substr')
            ? mb_substr($short, 0, self::MAX_LENGTH - 1)
            : substr($short, 0, self::MAX_LENGTH - 1);
        $space = strrpos($cut, ' ');

        if (false !== $space && $space > 0) {
            $cut = substr($cut, 0, $space);
        }

        return (string) preg_replace('/[\s,;:.\-–—]+$/u', '', $cut) . '…';
    }

    private static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The short description of an event for the person-facing messages about it
 * ({event_description}): the first sentences of the event's description, at
 * most MAX_SENTENCES of them and at most MAX_LENGTH characters.
 *
 * The description is EventMesh's (EventSource::description()): the event's
 * own text, as the organizers may have edited it on the event screen, or
 * EventMesh's AI translation of it in the reader's language when there is
 * one. '' when EventMesh is off or the event has no text, so the line is
 * left out.
 */
final class EventDescription
{
    public const MAX_SENTENCES = 2;

    public const MAX_LENGTH = 300;

    /**
     * The short description of event $postId in $lang (the language being
     * composed in when null), or ''.
     */
    public static function short(?int $postId, ?string $lang = null): string
    {
        if (null === $postId || $postId <= 0) {
            return '';
        }

        $lang ??= Language::current() ?? Language::ENGLISH;

        return self::shorten(EventSource::description($postId, $lang));
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

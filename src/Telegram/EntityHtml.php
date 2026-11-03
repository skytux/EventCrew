<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

/**
 * Turns a Telegram message's text and its formatting (the "entities" list)
 * into Telegram HTML, so the bot can send the same formatting again.
 *
 * Telegram counts entity offsets and lengths in UTF-16 code units, so the
 * text is cut in UTF-16 and each piece turned back into UTF-8.
 *
 * Kept: bold, italic, underline, strikethrough, spoiler, code, pre (with its
 * language), text links, mentions of a user without a username, block quotes
 * and custom emoji. Everything else (plain links, @usernames, hashtags) is
 * plain text, which Telegram recognises again by itself.
 */
final class EntityHtml
{
    /**
     * @param array<int, mixed> $entities
     */
    public static function render(string $text, array $entities): string
    {
        $utf16 = (string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $units = intdiv(strlen($utf16), 2);

        $spans = [];

        foreach ($entities as $entity) {
            if (! is_array($entity)) {
                continue;
            }

            $tags = self::tags($entity);

            if (null === $tags) {
                continue;
            }

            $start = max(0, (int) ($entity['offset'] ?? 0));
            $end = min($units, $start + max(0, (int) ($entity['length'] ?? 0)));

            if ($end <= $start) {
                continue;
            }

            $spans[] = ['start' => $start, 'end' => $end, 'open' => $tags[0], 'close' => $tags[1]];
        }

        // Outer spans first: earlier start, then longer.
        usort(
            $spans,
            static fn (array $a, array $b): int => [$a['start'], $b['end']] <=> [$b['start'], $a['end']]
        );

        $cuts = [0, $units];

        foreach ($spans as $span) {
            $cuts[] = $span['start'];
            $cuts[] = $span['end'];
        }

        $cuts = array_values(array_unique($cuts));
        sort($cuts);

        $html = '';
        $open = [];

        foreach ($cuts as $i => $at) {
            // Close what ends here. A span still running that sits above one
            // that ends is closed too and opened again, so tags always nest.
            $reopen = [];

            while ([] !== $open && self::anyEndsAt($open, $at)) {
                $top = array_pop($open);
                $html .= $top['close'];

                if ($top['end'] !== $at) {
                    $reopen[] = $top;
                }
            }

            foreach (array_reverse($reopen) as $span) {
                $html .= $span['open'];
                $open[] = $span;
            }

            foreach ($spans as $span) {
                if ($span['start'] === $at) {
                    $html .= $span['open'];
                    $open[] = $span;
                }
            }

            $next = $cuts[$i + 1] ?? null;

            if (null !== $next) {
                $piece = (string) mb_convert_encoding(substr($utf16, $at * 2, ($next - $at) * 2), 'UTF-8', 'UTF-16LE');
                $html .= htmlspecialchars($piece, ENT_NOQUOTES, 'UTF-8');
            }
        }

        while ([] !== $open) {
            $html .= array_pop($open)['close'];
        }

        return $html;
    }

    /** Length of $text in UTF-16 code units, the unit Telegram offsets use. */
    public static function units(string $text): int
    {
        return intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * @param array<int, array{start: int, end: int, open: string, close: string}> $open
     */
    private static function anyEndsAt(array $open, int $at): bool
    {
        foreach ($open as $span) {
            if ($span['end'] === $at) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $entity
     * @return array{0: string, 1: string}|null
     */
    private static function tags(array $entity): ?array
    {
        $attr = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        switch ((string) ($entity['type'] ?? '')) {
            case 'bold':
                return ['<b>', '</b>'];
            case 'italic':
                return ['<i>', '</i>'];
            case 'underline':
                return ['<u>', '</u>'];
            case 'strikethrough':
                return ['<s>', '</s>'];
            case 'spoiler':
                return ['<tg-spoiler>', '</tg-spoiler>'];
            case 'code':
                return ['<code>', '</code>'];
            case 'pre':
                $language = (string) ($entity['language'] ?? '');

                return '' === $language
                    ? ['<pre>', '</pre>']
                    : ['<pre><code class="language-' . $attr($language) . '">', '</code></pre>'];
            case 'text_link':
                $url = (string) ($entity['url'] ?? '');

                return '' === $url ? null : ['<a href="' . $attr($url) . '">', '</a>'];
            case 'text_mention':
                $id = (int) ($entity['user']['id'] ?? 0);

                return 0 === $id ? null : ['<a href="tg://user?id=' . $id . '">', '</a>'];
            case 'blockquote':
                return ['<blockquote>', '</blockquote>'];
            case 'expandable_blockquote':
                return ['<blockquote expandable>', '</blockquote>'];
            case 'custom_emoji':
                $emoji = (string) ($entity['custom_emoji_id'] ?? '');

                return '' === $emoji ? null : ['<tg-emoji emoji-id="' . $attr($emoji) . '">', '</tg-emoji>'];
            default:
                return null;
        }
    }
}

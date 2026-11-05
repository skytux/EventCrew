<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

/**
 * Removes links from the language model's answers in the group, except
 * links to this site and to Telegram.
 *
 * In the group a member can ask the bot to "reply exactly" with some text.
 * The bot then posts that text under its own name, so a link in it looks as
 * if the crew sent it. Group answers are sent as plain text, and Telegram
 * turns a web address in plain text into a link with or without "https://",
 * so every web address is checked: one with "https://", one starting with
 * "www." and a bare one like "example.com/page". An email address is not
 * touched.
 */
final class GroupLinkFilter
{
    /** Telegram's own hosts; their subdomains count too. */
    private const TELEGRAM_HOSTS = ['t.me', 'telegram.me', 'telegram.org', 'telegram.dog'];

    /**
     * A web address: an optional scheme, a host of at least two labels with
     * a letters-only last label, an optional port, an optional path. Not
     * preceded by "@" (an email address) or by a letter, digit, "." or "-"
     * (the middle of a longer word).
     */
    private const ADDRESS = '~(?<![@\p{L}\p{N}._-])'
        . '(?:[a-z][a-z0-9+.-]*://)?'
        . '((?:[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?\.)+\p{L}{2,63})'
        . '(?::\d{1,5})?'
        . '(?:[/?#][^\s<>"]*)?~iu';

    /**
     * $text with every web address removed whose host is not $siteHost (or
     * a subdomain of it) or a Telegram host.
     */
    public static function strip(string $text, string $siteHost): string
    {
        $allowed = self::TELEGRAM_HOSTS;
        $site = self::bareHost($siteHost);

        if ('' !== $site) {
            $allowed[] = $site;
        }

        $result = preg_replace_callback(
            self::ADDRESS,
            static function (array $match) use ($allowed): string {
                $host = self::bareHost($match[1]);

                foreach ($allowed as $ok) {
                    if ($host === $ok || str_ends_with($host, '.' . $ok)) {
                        return $match[0];
                    }
                }

                return '';
            },
            $text
        );

        if (! is_string($result)) {
            // Not valid UTF-8: nothing can be checked, so nothing is sent.
            return '';
        }

        // Tidy what a removed address leaves: double spaces and spaces before
        // a line end.
        $result = (string) preg_replace('/[ \t]{2,}/', ' ', $result);
        $result = (string) preg_replace('/[ \t]+$/m', '', $result);

        return trim($result);
    }

    /** Lower case, without a leading "www.". */
    private static function bareHost(string $host): string
    {
        $host = mb_strtolower(trim($host));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}

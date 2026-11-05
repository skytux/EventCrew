<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Whether an address may carry a key or a password: https, or plain http
 * only to this same machine (localhost or 127.0.0.1), where nothing travels
 * over a network. Used for the language model's address and the JMAP
 * mailbox, which receive the API key, the chat text and the mailbox login.
 *
 * Plain PHP, no WordPress functions: the live mailbox test runs it outside
 * WordPress.
 */
final class SecureUrl
{
    /** Hosts plain http is allowed to. */
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1'];

    public static function isAllowed(string $url): bool
    {
        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ('' === $host) {
            return false;
        }

        if ('https' === $scheme) {
            return true;
        }

        return 'http' === $scheme && in_array($host, self::LOCAL_HOSTS, true);
    }
}

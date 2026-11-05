<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * How a stored secret is shown on the Settings page: four dots and its last 4
 * characters, so an administrator can tell which secret is saved without the
 * page (or a screenshot of it) carrying the secret itself.
 */
final class SecretMask
{
    /** A secret this short shows no characters at all: 4 of 8 would be half of it. */
    private const MIN_LENGTH_FOR_TAIL = 9;

    /** '' for no secret, else the dots and the last 4 characters. */
    public static function mask(string $secret): string
    {
        $secret = trim($secret);

        if ('' === $secret) {
            return '';
        }

        if (strlen($secret) < self::MIN_LENGTH_FOR_TAIL) {
            return '••••';
        }

        return '••••' . substr($secret, -4);
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Encrypts a secret for storing in the database: sodium secretbox with a
 * random nonce, stored as base64(nonce . box).
 *
 * The key is derived from this site's auth salt (wp-config.php, not the
 * database) and a context string, so each kind of secret has its own key.
 * A box sealed under other salts, or under another context, does not open:
 * decrypt() then returns null and never throws.
 */
final class SecretBox
{
    /** base64(nonce . secretbox(plain)). */
    public static function encrypt(string $plain, string $context): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key($context)));
    }

    /** The plain text, or null when $stored is not a box this site can open. */
    public static function decrypt(string $stored, string $context): ?string
    {
        $raw = base64_decode($stored, true);

        if (false === $raw || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        try {
            $plain = sodium_crypto_secretbox_open($box, $nonce, self::key($context));
        } catch (\SodiumException) {
            return null;
        }

        return false === $plain ? null : $plain;
    }

    private static function key(string $context): string
    {
        // The same fallback as SignedLink: wp_salt() always exists in
        // WordPress; only the unit tests run without it.
        $salt = function_exists('wp_salt') ? wp_salt('auth') : 'eventcrew-test-key';

        return sodium_crypto_generichash($salt . $context, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}

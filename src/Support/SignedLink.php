<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Stateless, signed tokens for the links EventCrew emails out - a person's door
 * ticket, and the manage/disable/delete page.
 *
 * Unlike the single-use verification token in `auth_tokens`, these have to keep
 * working in a year-old email, so a stored-and-expiring row is the wrong shape.
 * Instead the token is `base64url(id) . '.' . HMAC(purpose|id)` signed with the
 * site's own secret: nothing to store, valid until the referenced row is gone,
 * and impossible to forge without the WordPress secret keys. The purpose is
 * part of what's signed, so a ticket link can never be replayed as a delete
 * link.
 */
final class SignedLink
{
    public static function sign(string $purpose, int $id): string
    {
        $body = self::encode((string) $id);

        return $body . '.' . self::mac($purpose, $body);
    }

    /**
     * The id a token carries, or null when it is malformed, for the wrong
     * purpose, or the signature does not verify.
     */
    public static function verify(string $purpose, string $token): ?int
    {
        $token = trim($token);
        $dot = strrpos($token, '.');

        if (false === $dot) {
            return null;
        }

        $body = substr($token, 0, $dot);
        $signature = substr($token, $dot + 1);

        if (! hash_equals(self::mac($purpose, $body), $signature)) {
            return null;
        }

        $id = self::decode($body);

        return ctype_digit($id) ? (int) $id : null;
    }

    /**
     * The id written in a token, NOT verified: for a purpose that includes a
     * value of the record itself (a buyer ticket signs its ticket code), the
     * record must be read before verify() can be called. Null when malformed.
     */
    public static function idOf(string $token): ?int
    {
        $token = trim($token);
        $dot = strrpos($token, '.');

        if (false === $dot) {
            return null;
        }

        $id = self::decode(substr($token, 0, $dot));

        return ctype_digit($id) ? (int) $id : null;
    }

    /**
     * A short signature for a code that has to fit in a small QR code: the
     * first 16 hex characters of the HMAC of "$purpose|$id". Check one with
     * hash_equals() against a freshly made one.
     */
    public static function shortSignature(string $purpose, int $id): string
    {
        return substr(self::mac($purpose, (string) $id), 0, 16);
    }

    /**
     * A token that carries a short text instead of an id, signed the same
     * way: base64url($payload) . '.' . HMAC(purpose|body). The text is
     * readable by anyone who has the token, so it must hold nothing secret.
     */
    public static function signPayload(string $purpose, string $payload): string
    {
        $body = self::encode($payload);

        return $body . '.' . self::mac($purpose, $body);
    }

    /**
     * The text a signPayload() token carries, or null when the token is
     * malformed, for the wrong purpose, or the signature does not verify.
     */
    public static function verifyPayload(string $purpose, string $token): ?string
    {
        $token = trim($token);
        $dot = strrpos($token, '.');

        if (false === $dot || 0 === $dot) {
            return null;
        }

        $body = substr($token, 0, $dot);

        if (! hash_equals(self::mac($purpose, $body), substr($token, $dot + 1))) {
            return null;
        }

        $payload = base64_decode(strtr($body, '-_', '+/'), true);

        return false === $payload ? null : $payload;
    }

    private static function mac(string $purpose, string $body): string
    {
        $key = function_exists('wp_salt') ? wp_salt('auth') : 'eventcrew-test-key';

        return hash_hmac('sha256', $purpose . '|' . $body, $key);
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}

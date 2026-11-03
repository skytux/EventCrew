<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

/**
 * A stand-in for Telegram's login service in tests: one RSA key pair, the
 * JWKS that publishes its public half, and ID tokens signed with it. The key
 * is made once per test run; making a 2048-bit key takes a moment.
 */
final class TelegramTokens
{
    public const BOT_ID = '123456789';
    public const BOT_TOKEN = '123456789:AAtest-secret-part-never-shown';
    public const KID = 'test-key-1';

    private static mixed $key = null;

    private static function key(): mixed
    {
        if (null === self::$key) {
            self::$key = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
        }

        return self::$key;
    }

    /**
     * The JSON body of the JWKS endpoint, with the test key under $kid.
     */
    public static function jwksBody(string $kid = self::KID): string
    {
        $details = openssl_pkey_get_details(self::key());

        return (string) json_encode([
            'keys' => [[
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => $kid,
                'n' => self::b64($details['rsa']['n']),
                'e' => self::b64($details['rsa']['e']),
            ]],
        ]);
    }

    /**
     * The claims of a token that should be accepted, for $nonce; $overrides
     * replace or (with null) remove claims.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function claims(string $nonce, array $overrides = []): array
    {
        $now = time();
        $claims = [
            'iss' => 'https://oauth.telegram.org',
            'aud' => self::BOT_ID,
            'sub' => 'opaque-subject',
            'iat' => $now - 10,
            'exp' => $now + 3600,
            'nonce' => $nonce,
            'id' => 4242,
            'name' => 'Sam',
            'preferred_username' => 'sam',
            'picture' => 'https://t.me/i/userpic/sam.jpg',
        ];

        foreach ($overrides as $name => $value) {
            if (null === $value) {
                unset($claims[$name]);
            } else {
                $claims[$name] = $value;
            }
        }

        return $claims;
    }

    /**
     * A token signed with the test key.
     *
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $header
     */
    public static function token(array $claims, array $header = []): string
    {
        $header += ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => self::KID];
        $signingInput = self::b64((string) json_encode($header)) . '.' . self::b64((string) json_encode($claims));
        openssl_sign($signingInput, $signature, self::key(), OPENSSL_ALGO_SHA256);

        return $signingInput . '.' . self::b64($signature);
    }

    /**
     * A token signed with the test key over a payload written by hand, for
     * JSON that json_encode() would not produce (a number too large for PHP).
     */
    public static function tokenFromJson(string $payloadJson): string
    {
        $header = (string) json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => self::KID]);
        $signingInput = self::b64($header) . '.' . self::b64($payloadJson);
        openssl_sign($signingInput, $signature, self::key(), OPENSSL_ALGO_SHA256);

        return $signingInput . '.' . self::b64($signature);
    }

    /**
     * A token whose header and payload are as given and whose signature part
     * is $signature verbatim - for alg "none" and HS256 forgeries.
     *
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     */
    public static function unsigned(array $header, array $claims, string $signature): string
    {
        return self::b64((string) json_encode($header))
            . '.' . self::b64((string) json_encode($claims))
            . '.' . $signature;
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

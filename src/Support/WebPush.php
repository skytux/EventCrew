<?php

declare(strict_types=1);

namespace EventCrew\Support;

use OpenSSLAsymmetricKey;

/**
 * Web Push by hand, with PHP's own openssl and hash: the plugin ships no
 * libraries, and the host has no gmp.
 *
 * - encrypt(): the payload, encrypted to one browser (RFC 8291, aes128gcm
 *   content coding, RFC 8188): an ECDH P-256 key pair made for this message,
 *   HKDF-SHA-256 for the key and nonce, AES-128-GCM for the record.
 * - vapidHeader(): the Authorization header that says which server sends
 *   (RFC 8292): an ES256 JWT for the push service's origin, signed with the
 *   site's VAPID key, plus that key's public half.
 *
 * Pure functions: no WordPress, no storage, so the RFC test vectors can run
 * against them. Sending and the keys' storage are PushNotifier's and
 * VapidKeys'.
 */
final class WebPush
{
    /** The record size written in the header; one record holds the whole payload. */
    public const RECORD_SIZE = 4096;

    /** The largest payload one record holds: rs - 16 (tag) - 1 (delimiter). */
    public const MAX_PAYLOAD = self::RECORD_SIZE - 17;

    /** DER prefix of a P-256 SubjectPublicKeyInfo, before the 65-byte point. */
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /**
     * The request body for one browser: salt (16) | rs (4) | idlen (1) |
     * the server's public key (65) | AES-128-GCM of payload + 0x02 + tag.
     *
     * $uaPublic is the browser's p256dh key and $authSecret its auth secret,
     * both raw bytes. $localKey and $salt are for the test vectors only; a
     * real send makes a new key pair and salt for every message.
     *
     * @throws \RuntimeException when a key is unusable or openssl fails.
     */
    public static function encrypt(
        string $payload,
        string $uaPublic,
        string $authSecret,
        ?OpenSSLAsymmetricKey $localKey = null,
        ?string $salt = null
    ): string {
        if (65 !== strlen($uaPublic) || "\x04" !== $uaPublic[0]) {
            throw new \RuntimeException('Bad browser key.');
        }

        if (16 !== strlen($authSecret)) {
            throw new \RuntimeException('Bad auth secret.');
        }

        if (strlen($payload) > self::MAX_PAYLOAD) {
            throw new \RuntimeException('Payload too long.');
        }

        $localKey ??= self::newKey();
        $salt ??= random_bytes(16);
        $asPublic = self::rawPublicKey($localKey);

        $shared = openssl_pkey_derive(self::publicKeyFromRaw($uaPublic), $localKey, 32);

        if (false === $shared || '' === $shared) {
            throw new \RuntimeException('ECDH failed.');
        }

        // RFC 8291 section 3.4: the auth secret and both public keys are mixed
        // in first, then the salt (RFC 8188 section 2.2).
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\x00" . $uaPublic . $asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        $tag = '';
        // One record, so it is the last: the 0x02 delimiter, no padding.
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

        if (false === $cipher) {
            throw new \RuntimeException('Encryption failed.');
        }

        return $salt . pack('N', self::RECORD_SIZE) . chr(65) . $asPublic . $cipher . $tag;
    }

    /**
     * The Authorization header of a send to $endpoint: "vapid t=<JWT>,
     * k=<public key>" (RFC 8292). The JWT's audience is the endpoint's
     * origin; $subject is a mailto: or https: address for the push service
     * to reach the site's owner.
     */
    public static function vapidHeader(
        string $endpoint,
        OpenSSLAsymmetricKey $key,
        string $subject,
        int $expires
    ): string {
        return 'vapid t=' . self::vapidJwt($endpoint, $key, $subject, $expires)
            . ', k=' . self::b64(self::rawPublicKey($key));
    }

    public static function vapidJwt(string $endpoint, OpenSSLAsymmetricKey $key, string $subject, int $expires): string
    {
        // Plain json_encode: no WordPress here, so the test vectors run bare.
        // phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode
        $header = self::b64((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64((string) json_encode([
            'aud' => self::origin($endpoint),
            'exp' => $expires,
            'sub' => $subject,
        ], JSON_UNESCAPED_SLASHES));
        // phpcs:enable WordPress.WP.AlternativeFunctions.json_encode_json_encode
        $input = $header . '.' . $claims;

        $der = '';

        if (! openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Signing failed.');
        }

        return $input . '.' . self::b64(self::derToRaw((string) $der));
    }

    /** scheme://host[:port] of a URL. */
    public static function origin(string $url): string
    {
        $parts = parse_url($url); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- no WordPress here.
        $scheme = is_array($parts) ? (string) ($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $port = is_array($parts) && isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    /**
     * An ECDSA signature as openssl gives it (DER: SEQUENCE of two
     * INTEGERs) turned into the JWS form: r and s, 32 bytes each.
     */
    public static function derToRaw(string $der): string
    {
        $offset = 0;

        if ("\x30" !== ($der[$offset++] ?? '')) {
            throw new \RuntimeException('Bad signature.');
        }

        $length = ord($der[$offset++] ?? "\x00");

        if ($length & 0x80) {
            $offset += $length & 0x7f;
        }

        $out = '';

        for ($i = 0; $i < 2; $i++) {
            if ("\x02" !== ($der[$offset++] ?? '')) {
                throw new \RuntimeException('Bad signature.');
            }

            $intLength = ord($der[$offset++] ?? "\x00");
            $int = ltrim(substr($der, $offset, $intLength), "\x00");
            $offset += $intLength;

            if (strlen($int) > 32) {
                throw new \RuntimeException('Bad signature.');
            }

            $out .= str_pad($int, 32, "\x00", STR_PAD_LEFT);
        }

        return $out;
    }

    /** A new P-256 key pair. */
    public static function newKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        if (false === $key) {
            throw new \RuntimeException('Key generation failed.');
        }

        return $key;
    }

    /** The uncompressed public point (0x04 | x | y, 65 bytes) of a P-256 key. */
    public static function rawPublicKey(OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);

        if (! is_array($details) || ! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new \RuntimeException('Not an EC key.');
        }

        return "\x04" . str_pad((string) $details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            . str_pad((string) $details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    }

    /** A public key object from an uncompressed P-256 point. */
    public static function publicKeyFromRaw(string $point): OpenSSLAsymmetricKey
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode((string) hex2bin(self::SPKI_PREFIX) . $point), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);

        if (false === $key) {
            throw new \RuntimeException('Bad public key.');
        }

        return $key;
    }

    /**
     * A private key object from its raw scalar (32 bytes) and public point:
     * an SEC1 ECPrivateKey in DER, as PEM. Used for the RFC test vectors.
     */
    public static function privateKeyFromRaw(string $scalar, string $point): OpenSSLAsymmetricKey
    {
        // SEQUENCE { INTEGER 1, OCTET STRING d, [0] OID prime256v1, [1] BIT STRING point }
        $body = "\x02\x01\x01"
            . "\x04\x20" . str_pad($scalar, 32, "\x00", STR_PAD_LEFT)
            . "\xa0\x0a\x06\x08" . (string) hex2bin('2a8648ce3d030107')
            . "\xa1\x44\x03\x42\x00" . $point;
        $der = "\x30" . chr(strlen($body)) . $body;
        $pem = "-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END EC PRIVATE KEY-----\n";
        $key = openssl_pkey_get_private($pem);

        if (false === $key) {
            throw new \RuntimeException('Bad private key.');
        }

        return $key;
    }

    /** base64url without padding. */
    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Decodes base64url (or plain base64), padded or not; '' when not valid. */
    public static function unb64(string $text): string
    {
        $text = strtr(trim($text), '-_', '+/');
        $decoded = base64_decode($text . str_repeat('=', (4 - strlen($text) % 4) % 4), true);

        return false === $decoded ? '' : $decoded;
    }
}

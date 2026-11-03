<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\DohResolver;
use EventCrew\Telegram\TelegramClient;

/**
 * Signing in to the Crew app with Telegram: the server half of Telegram's
 * OpenID Connect popup login (https://core.telegram.org/bots/telegram-login).
 *
 * The page opens Telegram's popup with telegram-login.js, and the popup hands
 * the page an ID token: a JWT signed by Telegram. The page posts that token
 * here. verify() accepts it only when Telegram's own key signed it, it was
 * made for this bot, it is fresh, and it carries the one-time nonce this
 * browser was given. The answer is the person's numeric Telegram user id,
 * which the crew account already stores when the person linked Telegram in
 * the bot.
 *
 * The plugin has no runtime libraries, so the JWT and the RSA key are read by
 * the few lines of code below rather than by a JWT package.
 */
final class TelegramLogin
{
    /** The wp-admin switch; off until an organizer turns it on. */
    public const ENABLED_OPTION = 'eventcrew_telegram_web_login';

    /** The cookie that holds this browser's one-time nonce. */
    public const NONCE_COOKIE = 'eventcrew_tg_nonce';

    /** How long a nonce from the page is good for, in seconds. */
    public const NONCE_TTL = 10 * MINUTE_IN_SECONDS;

    /** Telegram's login library, loaded by the page only when the login is available. */
    public const SCRIPT_URL = 'https://oauth.telegram.org/js/telegram-login.js?6';

    private const ISSUER = 'https://oauth.telegram.org';
    private const HOST = 'oauth.telegram.org';
    private const JWKS_URL = 'https://oauth.telegram.org/.well-known/jwks.json';

    /** Telegram's public keys, cached so a sign-in does not fetch them every time. */
    private const JWKS_TRANSIENT = 'eventcrew_tg_jwks';
    private const JWKS_TTL = 12 * HOUR_IN_SECONDS;

    /** A nonce that has been used once, kept until its token would expire anyway. */
    private const USED_PREFIX = 'eventcrew_tg_used_';

    /** The oldest token accepted, counted from its iat claim, in seconds. */
    private const MAX_AGE = 5 * MINUTE_IN_SECONDS;

    public function __construct(
        private readonly Logger $logger,
        private readonly DohResolver $resolver
    ) {
    }

    /**
     * Whether the page offers the Telegram button: the switch is on, a bot
     * token is stored, and the bot's username is known (the "not linked yet"
     * notice links to the bot by it).
     */
    public static function isAvailable(): bool
    {
        return (bool) get_option(self::ENABLED_OPTION, false)
            && '' !== self::clientId()
            && '' !== self::botUsername();
    }

    /**
     * The bot's ID, which Telegram's login uses as the client ID: the digits
     * before the ":" in the bot token. Only those digits ever leave this
     * method - never the rest of the token. '' when there is no usable token.
     */
    public static function clientId(): string
    {
        $token = TelegramClient::token();
        $colon = strpos($token, ':');

        if (false === $colon) {
            return '';
        }

        $id = substr($token, 0, $colon);

        return '' !== $id && ctype_digit($id) ? $id : '';
    }

    /** The bot's username without the "@", or ''. */
    public static function botUsername(): string
    {
        return ltrim(trim((string) get_option(BoardService::USERNAME_OPTION, '')), '@');
    }

    /**
     * The link that opens the bot's private chat at sign-up, the same
     * ?start=onboard link the group's "Set me up" button uses. '' without a
     * known username.
     */
    public static function botStartUrl(): string
    {
        $username = self::botUsername();

        return '' === $username ? '' : 'https://t.me/' . $username . '?start=onboard';
    }

    /** A fresh nonce for the page: 32 random bytes, as hex. */
    public static function newNonce(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * The Telegram user id in $idToken, or null when any check fails. $nonce
     * is the one from this browser's cookie. On success the nonce is marked
     * used, so the same token or nonce never signs in twice.
     */
    public function verify(string $idToken, string $nonce): ?int
    {
        $parts = explode('.', $idToken);

        if (3 !== count($parts)) {
            return $this->refuse('the token does not have three parts');
        }

        [$headerPart, $payloadPart, $signaturePart] = $parts;
        $header = json_decode(self::base64UrlDecode($headerPart), true);

        if (! is_array($header) || 'RS256' !== ($header['alg'] ?? null)) {
            return $this->refuse('the token is not signed with RS256');
        }

        $kid = $header['kid'] ?? null;

        if (! is_string($kid) || '' === $kid) {
            return $this->refuse('the token names no key');
        }

        $jwk = $this->keyFor($kid);

        if (null === $jwk) {
            return $this->refuse('the token\'s key is not one of Telegram\'s keys');
        }

        $pem = self::pemFromJwk($jwk);
        $signature = self::base64UrlDecode($signaturePart);

        if (null === $pem || '' === $signature) {
            return $this->refuse('Telegram\'s key or the signature could not be read');
        }

        if (1 !== openssl_verify($headerPart . '.' . $payloadPart, $signature, $pem, OPENSSL_ALGO_SHA256)) {
            return $this->refuse('the signature does not match');
        }

        $claims = json_decode(self::base64UrlDecode($payloadPart), true, 512, JSON_BIGINT_AS_STRING);

        if (! is_array($claims)) {
            return $this->refuse('the token\'s claims could not be read');
        }

        if (self::ISSUER !== ($claims['iss'] ?? null)) {
            return $this->refuse('the issuer is not Telegram');
        }

        if (! $this->audienceMatches($claims['aud'] ?? null)) {
            return $this->refuse('the token was made for another bot');
        }

        $now = time();
        $exp = $claims['exp'] ?? null;
        $iat = $claims['iat'] ?? null;

        if (! is_int($exp) || $exp <= $now) {
            return $this->refuse('the token has expired');
        }

        if (! is_int($iat) || $now - $iat > self::MAX_AGE) {
            return $this->refuse('the token is older than five minutes');
        }

        $claimed = $claims['nonce'] ?? null;

        if ('' === $nonce || ! is_string($claimed) || ! hash_equals($nonce, $claimed)) {
            return $this->refuse('the nonce does not match this browser');
        }

        $usedKey = self::USED_PREFIX . substr(hash('sha256', $nonce), 0, 32);

        if (false !== get_transient($usedKey)) {
            return $this->refuse('the nonce was used before');
        }

        set_transient($usedKey, 1, max(1, $exp - $now));

        $id = self::userId($claims['id'] ?? null);

        if (null === $id) {
            return $this->refuse('the token has no valid Telegram user id');
        }

        return $id;
    }

    /**
     * The id claim as a positive integer, or null. Decoded with
     * JSON_BIGINT_AS_STRING, so a number too large for PHP arrives as a string
     * and is refused here rather than rounded into somebody else's id.
     */
    private static function userId(mixed $id): ?int
    {
        if (is_int($id)) {
            return $id > 0 ? $id : null;
        }

        if (! is_string($id) || '' === $id || ! ctype_digit($id)) {
            return null;
        }

        $value = filter_var($id, FILTER_VALIDATE_INT);

        return is_int($value) && $value > 0 ? $value : null;
    }

    private function audienceMatches(mixed $aud): bool
    {
        $clientId = self::clientId();

        if ('' === $clientId) {
            return false;
        }

        if (is_array($aud)) {
            return in_array($clientId, array_map('strval', $aud), true);
        }

        return (is_string($aud) || is_int($aud)) && $clientId === (string) $aud;
    }

    /** Logs why a token was refused - never the token - and returns null. */
    private function refuse(string $reason): null
    {
        $this->logger->warning(sprintf('Telegram sign-in refused: %s.', $reason));

        return null;
    }

    /**
     * The JWK with this kid, from the cache or from Telegram. A kid the cached
     * set does not have fetches the set again, once, because Telegram may have
     * added a key since the cache was filled.
     *
     * @return array<string, mixed>|null
     */
    private function keyFor(string $kid): ?array
    {
        $cached = get_transient(self::JWKS_TRANSIENT);

        if (is_array($cached)) {
            $key = self::findKey($cached, $kid);

            if (null !== $key) {
                return $key;
            }
        }

        return self::findKey($this->fetchKeys(), $kid);
    }

    /**
     * @param array<int|string, mixed> $keys
     * @return array<string, mixed>|null
     */
    private static function findKey(array $keys, string $kid): ?array
    {
        foreach ($keys as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $kid) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Telegram's current keys, stored in the cache on success. [] when the
     * fetch fails.
     *
     * @return array<int, mixed>
     */
    private function fetchKeys(): array
    {
        $response = $this->get(self::JWKS_URL);

        if (is_wp_error($response)) {
            $this->logger->warning(
                sprintf('Telegram sign-in keys could not be fetched: %s', $response->get_error_message())
            );

            return [];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        if (200 !== $code || ! is_array($decoded) || ! is_array($decoded['keys'] ?? null)) {
            $this->logger->warning(sprintf('Telegram sign-in keys could not be fetched: HTTP %d.', $code));

            return [];
        }

        $keys = array_values($decoded['keys']);
        set_transient(self::JWKS_TRANSIENT, $keys, self::JWKS_TTL);

        return $keys;
    }

    /**
     * A GET to oauth.telegram.org, pinned to a Cloudflare-resolved address
     * when the DNS workaround is on - the same pin TelegramClient uses for
     * api.telegram.org, scoped to this one host and removed straight after.
     */
    private function get(string $url): mixed
    {
        $args = ['timeout' => 10];

        if (! (bool) get_option(TelegramClient::DNS_BYPASS_OPTION, false)) {
            return wp_remote_get($url, $args);
        }

        $ip = $this->resolver->resolve(self::HOST);

        if (null === $ip) {
            return wp_remote_get($url, $args);
        }

        $filter = static function ($handle, $args2, $requestUrl) use ($ip): void {
            if (self::HOST === wp_parse_url((string) $requestUrl, PHP_URL_HOST) && function_exists('curl_setopt')) {
                curl_setopt($handle, CURLOPT_RESOLVE, [self::HOST . ':443:' . $ip]);
            }
        };

        add_action('http_api_curl', $filter, 10, 3);

        try {
            return wp_remote_get($url, $args);
        } finally {
            remove_action('http_api_curl', $filter, 10);
        }
    }

    private static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return false === $decoded ? '' : $decoded;
    }

    /**
     * A PEM public key built from an RSA JWK's modulus (n) and exponent (e):
     * the DER SubjectPublicKeyInfo openssl_verify() reads, written out by
     * hand. null for a key that is not RSA or is missing either number.
     *
     * @param array<string, mixed> $jwk
     */
    private static function pemFromJwk(array $jwk): ?string
    {
        if ('RSA' !== ($jwk['kty'] ?? null) || ! is_string($jwk['n'] ?? null) || ! is_string($jwk['e'] ?? null)) {
            return null;
        }

        $n = self::base64UrlDecode($jwk['n']);
        $e = self::base64UrlDecode($jwk['e']);

        if ('' === $n || '' === $e) {
            return null;
        }

        $rsaKey = self::der(0x30, self::derInteger($n) . self::derInteger($e));
        // AlgorithmIdentifier: OID 1.2.840.113549.1.1.1 (rsaEncryption), NULL.
        $algorithm = self::der(0x30, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $info = self::der(0x30, $algorithm . self::der(0x03, "\x00" . $rsaKey));

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($info), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /** A DER INTEGER: leading zero bytes dropped, one added back when the top bit is set. */
    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ('' === $bytes || ord($bytes[0]) > 0x7f) {
            $bytes = "\x00" . $bytes;
        }

        return self::der(0x02, $bytes);
    }

    /** One DER element: tag, length, content. */
    private static function der(int $tag, string $content): string
    {
        $length = strlen($content);

        if ($length < 0x80) {
            return chr($tag) . chr($length) . $content;
        }

        $lengthBytes = ltrim(pack('N', $length), "\x00");

        return chr($tag) . chr(0x80 | strlen($lengthBytes)) . $lengthBytes . $content;
    }
}

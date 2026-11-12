<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\WebPush;
use EventCrew\Tests\TestCase;

/**
 * The hand-rolled Web Push crypto: RFC 8291's own example (Appendix A) and
 * the VAPID JWT of RFC 8292.
 */
final class WebPushTest extends TestCase
{
    private const AS_PRIVATE = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
    private const AS_PUBLIC = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
    private const UA_PRIVATE = 'q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94';
    private const UA_PUBLIC = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    private const AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';
    private const SALT = 'DGv6ra1nlYgDCS1FRnbzlw';
    // phpcs:ignore Generic.Files.LineLength.TooLong -- one test vector.
    private const BODY = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

    public function testTheRfc8291ExampleEncryptsToThePublishedBody(): void
    {
        $asKey = WebPush::privateKeyFromRaw(WebPush::unb64(self::AS_PRIVATE), WebPush::unb64(self::AS_PUBLIC));

        $body = WebPush::encrypt(
            'When I grow up, I want to be a watermelon',
            WebPush::unb64(self::UA_PUBLIC),
            WebPush::unb64(self::AUTH),
            $asKey,
            WebPush::unb64(self::SALT)
        );

        self::assertSame(self::BODY, WebPush::b64($body));
    }

    public function testTheBrowserCanDecryptARealMessage(): void
    {
        // The receiving side of RFC 8291, done here with the example's
        // browser key, for a message with a fresh key and salt.
        $uaPublic = WebPush::unb64(self::UA_PUBLIC);
        $uaKey = WebPush::privateKeyFromRaw(WebPush::unb64(self::UA_PRIVATE), $uaPublic);
        $auth = WebPush::unb64(self::AUTH);

        $body = WebPush::encrypt('{"title":"Crew","body":"Hei"}', $uaPublic, $auth);

        $salt = substr($body, 0, 16);
        self::assertSame(4096, unpack('N', substr($body, 16, 4))[1]);
        self::assertSame(65, ord($body[20]));
        $asPublic = substr($body, 21, 65);
        $cipher = substr($body, 86, -16);
        $tag = substr($body, -16);

        $shared = openssl_pkey_derive(WebPush::publicKeyFromRaw($asPublic), $uaKey, 32);
        $ikm = hash_hkdf('sha256', (string) $shared, 32, "WebPush: info\x00" . $uaPublic . $asPublic, $auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);
        $plain = openssl_decrypt($cipher, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

        self::assertSame("{\"title\":\"Crew\",\"body\":\"Hei\"}\x02", $plain);
    }

    public function testABadBrowserKeyIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);

        WebPush::encrypt('x', str_repeat("\x04", 10), str_repeat('a', 16));
    }

    public function testTheVapidJwtIsAnEs256TokenForTheEndpointsOrigin(): void
    {
        $key = WebPush::newKey();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/abc:def';

        $jwt = WebPush::vapidJwt($endpoint, $key, 'mailto:owner@example.com', 1_800_000_000);
        [$header, $claims, $signature] = explode('.', $jwt);

        self::assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(WebPush::unb64($header), true));
        self::assertSame(
            ['aud' => 'https://fcm.googleapis.com', 'exp' => 1_800_000_000, 'sub' => 'mailto:owner@example.com'],
            json_decode(WebPush::unb64($claims), true)
        );

        // JWS form: r and s, 32 bytes each, no DER.
        $raw = WebPush::unb64($signature);
        self::assertSame(64, strlen($raw));

        // Back to DER, it verifies against the public key.
        self::assertSame(
            1,
            openssl_verify($header . '.' . $claims, self::rawToDer($raw), WebPush::publicKeyFromRaw(WebPush::rawPublicKey($key)), OPENSSL_ALGO_SHA256)
        );

        $auth = WebPush::vapidHeader($endpoint, $key, 'mailto:owner@example.com', 1_800_000_000);
        self::assertMatchesRegularExpression('/^vapid t=[\w-]+\.[\w-]+\.[\w-]+, k=[\w-]{87}$/', $auth);
    }

    public function testDerSignaturesWithShortAndPaddedIntegersBecome64Bytes(): void
    {
        $r = "\x00" . str_repeat("\xff", 32);
        $s = str_repeat("\x01", 31);
        $der = "\x30" . chr(4 + 33 + 31) . "\x02\x21" . $r . "\x02\x1f" . $s;

        self::assertSame(str_repeat("\xff", 32) . "\x00" . $s, WebPush::derToRaw($der));
    }

    private static function rawToDer(string $raw): string
    {
        $int = static function (string $bytes): string {
            $bytes = ltrim($bytes, "\x00");

            if ('' === $bytes || ord($bytes[0]) >= 0x80) {
                $bytes = "\x00" . $bytes;
            }

            return "\x02" . chr(strlen($bytes)) . $bytes;
        };
        $body = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));

        return "\x30" . chr(strlen($body)) . $body;
    }
}

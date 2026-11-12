<?php

declare(strict_types=1);

namespace EventCrew\Support;

use OpenSSLAsymmetricKey;

/**
 * The site's VAPID key pair (RFC 8292): the browser is given its public half
 * when it turns app notifications on, and every push is signed with it, so a
 * push service accepts pushes to that browser from this site only.
 *
 * Made once, on first use, and kept in an option WordPress does not load on
 * every page. Changing it would stop every device's notifications until each
 * turns them on again, so nothing here ever replaces it.
 */
final class VapidKeys
{
    public const OPTION = 'eventcrew_push_vapid';

    /**
     * The stored pair, made now when there is none: private key as PEM,
     * public key as base64url of the 65-byte point. Null when openssl cannot
     * make one.
     *
     * @return array{private: string, public: string}|null
     */
    public static function pair(): ?array
    {
        $stored = get_option(self::OPTION, []);

        if (is_array($stored) && '' !== (string) ($stored['private'] ?? '') && '' !== (string) ($stored['public'] ?? '')) {
            return ['private' => (string) $stored['private'], 'public' => (string) $stored['public']];
        }

        try {
            $key = WebPush::newKey();
            $pem = '';

            if (! openssl_pkey_export($key, $pem)) {
                return null;
            }

            $pair = ['private' => (string) $pem, 'public' => WebPush::b64(WebPush::rawPublicKey($key))];
        } catch (\Throwable) {
            return null;
        }

        // add_option() is the claim: of two requests making a pair at once,
        // only the first is stored, and both read that one back.
        add_option(self::OPTION, $pair, '', false);
        $stored = get_option(self::OPTION, []);

        return is_array($stored) && isset($stored['private'], $stored['public'])
            ? ['private' => (string) $stored['private'], 'public' => (string) $stored['public']]
            : null;
    }

    /** The public key for the browser's subscribe(), '' when there is none. */
    public static function publicKey(): string
    {
        return self::pair()['public'] ?? '';
    }

    public static function privateKey(): ?OpenSSLAsymmetricKey
    {
        $pair = self::pair();
        $key = null === $pair ? false : openssl_pkey_get_private($pair['private']);

        return false === $key ? null : $key;
    }

    /**
     * How a push service can reach the site's owner: mailto: the site's
     * admin email (Settings > General), else the site address.
     */
    public static function subject(): string
    {
        $email = trim((string) get_option('admin_email', ''));

        if ('' !== $email && false !== filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'mailto:' . $email;
        }

        return home_url('/');
    }
}

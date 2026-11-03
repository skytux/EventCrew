<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The settings of the bot's language-model helper (AssistantService): on or
 * off, the server's address, the model, the API key and the hourly limits.
 *
 * There is no default server, model or key anywhere: until an admin enters
 * them the helper is off and the bot behaves exactly as before.
 *
 * The API key is stored encrypted (sodium secretbox, the nonce in front of the
 * box, base64) under a key derived from this site's auth salt. The option is
 * not autoloaded, and no page ever prints it back. A key that can no longer be
 * decrypted - the salts were changed, or the row was copied from another site
 * - reads as no key, and Admin shows a notice asking for it again.
 *
 * To set the connection from a one-off script, without the settings page:
 *
 *     require 'wp-load.php';
 *     \EventCrew\Support\AssistantSettings::saveConnection($url, $model, $key);
 */
final class AssistantSettings
{
    public const ENABLED_OPTION = 'eventcrew_assistant_enabled';
    public const URL_OPTION = 'eventcrew_assistant_url';
    public const MODEL_OPTION = 'eventcrew_assistant_model';
    public const KEY_OPTION = 'eventcrew_assistant_key';
    public const PRIVATE_LIMIT_OPTION = 'eventcrew_assistant_private_limit';
    public const GROUP_LIMIT_OPTION = 'eventcrew_assistant_group_limit';
    public const PAGES_OPTION = 'eventcrew_assistant_pages';
    public const FORWARD_OPTION = 'eventcrew_assistant_forward';

    public const DEFAULT_PRIVATE_LIMIT = 20;
    public const DEFAULT_GROUP_LIMIT = 60;

    /** keyStatus(): no key stored. */
    public const KEY_NONE = 'none';

    /** keyStatus(): a key is stored and decrypts. */
    public const KEY_OK = 'ok';

    /** keyStatus(): a key is stored but cannot be decrypted on this site. */
    public const KEY_UNREADABLE = 'unreadable';

    /** Mixed into the salt, so this key is not shared with any other use of it. */
    private const KEY_CONTEXT = '|eventcrew-assistant-api-key-v1';

    public static function isEnabled(): bool
    {
        return (bool) get_option(self::ENABLED_OPTION, false);
    }

    /** The OpenAI-compatible base address, e.g. https://example.test/v1, or ''. */
    public static function url(): string
    {
        return self::normalizeUrl((string) get_option(self::URL_OPTION, ''));
    }

    public static function model(): string
    {
        return trim((string) get_option(self::MODEL_OPTION, ''));
    }

    /**
     * Whether there is a server and a model to send to. The key is optional:
     * a server on a private network may not ask for one.
     */
    public static function isConfigured(): bool
    {
        return '' !== self::url() && '' !== self::model();
    }

    /** Switched on and configured: the only state in which a message is sent out. */
    public static function isActive(): bool
    {
        return self::isEnabled() && self::isConfigured();
    }

    /** The API key in plain text, or '' when there is none or it cannot be read. */
    public static function apiKey(): string
    {
        $stored = (string) get_option(self::KEY_OPTION, '');

        if ('' === $stored) {
            return '';
        }

        return self::decrypt($stored) ?? '';
    }

    /** One of the KEY_* constants. */
    public static function keyStatus(): string
    {
        $stored = (string) get_option(self::KEY_OPTION, '');

        if ('' === $stored) {
            return self::KEY_NONE;
        }

        return null === self::decrypt($stored) ? self::KEY_UNREADABLE : self::KEY_OK;
    }

    /** At most this many messages per person per hour in a private chat. */
    public static function privateLimit(): int
    {
        return max(0, (int) get_option(self::PRIVATE_LIMIT_OPTION, self::DEFAULT_PRIVATE_LIMIT));
    }

    /** At most this many messages per group per hour. */
    public static function groupLimit(): int
    {
        return max(0, (int) get_option(self::GROUP_LIMIT_OPTION, self::DEFAULT_GROUP_LIMIT));
    }

    /**
     * Whether a private chat is sent to the organizers when the person gives
     * feedback, is unhappy or very happy, or asks for a human. On by default.
     */
    public static function forwardEnabled(): bool
    {
        return (bool) get_option(self::FORWARD_OPTION, true);
    }

    /**
     * The pages chosen in Settings whose text the helper may answer from.
     * Empty means "not chosen": AssistantKnowledge then uses its default.
     *
     * @return array<int, int>
     */
    public static function pageIds(): array
    {
        $ids = get_option(self::PAGES_OPTION, []);

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
    }

    /**
     * Saves the connection: server address, model and API key. An empty $apiKey
     * keeps the stored one. $enabled switches the helper on or off; null leaves
     * the switch as it is.
     *
     * This is the method to call from a one-off script after
     * require 'wp-load.php'.
     *
     * @throws \InvalidArgumentException When $url is not an http(s) address.
     */
    public static function saveConnection(string $url, string $model, string $apiKey = '', ?bool $enabled = null): void
    {
        $normalized = self::normalizeUrl($url);

        if ('' !== trim($url) && '' === $normalized) {
            throw new \InvalidArgumentException('The LLM API URL must start with http:// or https://.');
        }

        update_option(self::URL_OPTION, $normalized, false);
        update_option(self::MODEL_OPTION, sanitize_text_field($model), false);

        if ('' !== trim($apiKey)) {
            self::saveKey($apiKey);
        }

        if (null !== $enabled) {
            update_option(self::ENABLED_OPTION, $enabled, false);
        }

        AssistantKnowledge::flush();
    }

    /** Encrypts and stores the API key. */
    public static function saveKey(string $apiKey): void
    {
        update_option(self::KEY_OPTION, self::encrypt(trim($apiKey)), false);
    }

    public static function removeKey(): void
    {
        delete_option(self::KEY_OPTION);
    }

    /**
     * The address as the client uses it: trimmed, no trailing slash, and no
     * "/chat/completions" if it was pasted with one. '' for anything that is
     * not an http(s) address.
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $url = (string) preg_replace('#/chat/completions/?$#i', '', $url);
        $url = rtrim($url, '/');

        if (1 !== preg_match('#^https?://[^\s/?\#]+[^\s]*$#i', $url)) {
            return '';
        }

        return $url;
    }

    /** base64(nonce . secretbox(plain)). */
    public static function encrypt(string $plain): string
    {
        return SecretBox::encrypt($plain, self::KEY_CONTEXT);
    }

    /** The plain text, or null when $stored is not a box this site can open. */
    public static function decrypt(string $stored): ?string
    {
        return SecretBox::decrypt($stored, self::KEY_CONTEXT);
    }
}

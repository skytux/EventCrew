<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\TelegramClient;

/**
 * Signing in to the Crew app through the bot.
 *
 * The page asks for a one-time login code. The code goes into a deep link,
 * t.me/<bot>?start=login-<code>, which opens the bot's private chat. The bot
 * asks the person to press a button, and pressing it approves the code for
 * their crew account. The page asks every few seconds whether the code is
 * approved, and when it is, the browser is signed in.
 *
 * Each code is stored as a transient keyed by a hash of the code, holding:
 * - a hash of a random secret that only this browser has, in an HttpOnly
 *   cookie, so a code seen by someone else cannot sign in their browser;
 * - when it was made (a code lives 30 minutes);
 * - the Telegram user who opened it in the bot (only that user can approve);
 * - its state: pending, approved (with the person id) or used;
 * - a 4-digit match code. The page shows it and the bot shows it next to the
 *   sign-in button. A person who got the bot link from someone else sees no
 *   such code on their own screen, so they know not to press the button
 *   (that would sign in the other person's browser as them).
 *
 * A code is approved once and used once.
 */
final class TelegramLogin
{
    /** The wp-admin switch; off until an organizer turns it on. */
    public const ENABLED_OPTION = 'eventcrew_telegram_web_login';

    /** The cookie that holds this browser's secret for its code. */
    public const COOKIE = 'eventcrew_tg_login';

    /** How long a code is good for, in seconds. */
    public const TTL = 30 * MINUTE_IN_SECONDS;

    /** The /start payload is this prefix and the code. */
    public const PAYLOAD_PREFIX = 'login-';

    /** The bot's "Sign in to the crew app" button carries this prefix and the code. */
    public const CALLBACK_PREFIX = 'tgl:';

    public const STATE_PENDING = 'pending';
    public const STATE_APPROVED = 'approved';
    public const STATE_USED = 'used';

    /** What a poll answers when the code is gone, used or not this browser's. */
    public const STATE_EXPIRED = 'expired';

    /** Random bytes in a code: 18 bytes are 24 URL-safe characters. */
    private const CODE_BYTES = 18;

    private const CODE_PREFIX = 'eventcrew_tgl_';
    private const WAIT_PREFIX = 'eventcrew_tgl_wait_';
    private const RATE_PREFIX = 'eventcrew_tgl_rate_';

    /** At most this many codes per address in RATE_WINDOW. */
    private const RATE_LIMIT = 10;
    private const RATE_WINDOW = 15 * MINUTE_IN_SECONDS;

    public function __construct(private readonly Logger $logger)
    {
    }

    /**
     * Whether the page offers the Telegram button: the switch is on, a bot
     * token is stored, and the bot's username is known (the deep link needs it).
     */
    public static function isAvailable(): bool
    {
        return (bool) get_option(self::ENABLED_OPTION, false)
            && '' !== TelegramClient::token()
            && '' !== self::botUsername();
    }

    /** The bot's username without the "@", or ''. */
    public static function botUsername(): string
    {
        return ltrim(trim((string) get_option(BoardService::USERNAME_OPTION, '')), '@');
    }

    /**
     * The admin-ajax action behind the bot's "Back to the crew app" button:
     * it signs in the browser that holds this code's cookie and opens the
     * crew app.
     */
    public const RETURN_ACTION = 'eventcrew_web_tg_return';

    /**
     * The address of the bot's "Back to the crew app" button. It carries the
     * code, which is useless without the browser's secret cookie.
     */
    public static function returnUrl(string $code): string
    {
        return add_query_arg(
            ['action' => self::RETURN_ACTION, 'login' => $code],
            admin_url('admin-ajax.php')
        );
    }

    /** The deep link that opens the bot's private chat with this code. */
    public static function startUrl(string $code): string
    {
        return 'https://t.me/' . self::botUsername() . '?start=' . self::PAYLOAD_PREFIX . $code;
    }

    /** True for a string shaped like a code: 24 characters of A-Z, a-z, 0-9, _ and -. */
    public static function isCode(string $code): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_-]{24}$/', $code);
    }

    /**
     * A new pending code for the browser at $clientIp. Returns the code, the
     * browser's secret (for the cookie) and the 4-digit match code (for the
     * page), or null when this address asked for too many codes recently.
     *
     * @return array{code: string, secret: string, match: string}|null
     */
    public function create(string $clientIp): ?array
    {
        if (! $this->allowFrom($clientIp)) {
            return $this->refuse('too many codes from one address');
        }

        $code = rtrim(strtr(base64_encode(random_bytes(self::CODE_BYTES)), '+/', '-_'), '=');
        $secret = bin2hex(random_bytes(32));
        $match = (string) random_int(1000, 9999);

        $this->save($code, [
            'secret_hash' => hash('sha256', $secret),
            'created' => time(),
            'state' => self::STATE_PENDING,
            'person_id' => 0,
            'telegram_user_id' => 0,
            'match' => $match,
        ]);

        return ['code' => $code, 'secret' => $secret, 'match' => $match];
    }

    /**
     * The 4-digit match code the page shows for this code, for the bot to show
     * next to its button. '' for a code that is unknown or expired.
     */
    public function matchCode(string $code): string
    {
        $record = $this->load($code);

        return null === $record ? '' : $record['match'];
    }

    /**
     * The page's poll. 'pending' while waiting; 'expired' for a code that is
     * unknown, too old, already used, or not this browser's. An approved code
     * with this browser's secret is marked used and answers 'approved' with
     * the person id - once.
     *
     * @return array{state: string, person_id: int}
     */
    public function poll(string $code, string $secret): array
    {
        $expired = ['state' => self::STATE_EXPIRED, 'person_id' => 0];
        $record = $this->load($code);

        if (null === $record) {
            return $expired;
        }

        if ('' === $secret || ! hash_equals((string) $record['secret_hash'], hash('sha256', $secret))) {
            $this->refuse('the browser cookie does not match the code');

            return $expired;
        }

        if (self::STATE_PENDING === $record['state']) {
            return ['state' => self::STATE_PENDING, 'person_id' => 0];
        }

        if (self::STATE_APPROVED !== $record['state'] || $record['person_id'] <= 0) {
            return $expired;
        }

        $record['state'] = self::STATE_USED;
        $this->save($code, $record);

        return ['state' => self::STATE_APPROVED, 'person_id' => $record['person_id']];
    }

    /**
     * The bot saw /start login-<code> from this Telegram user. True when the
     * code is pending and this user may approve it: the first user to open it
     * is remembered, and any other user is refused.
     */
    public function claimForTelegramUser(string $code, int $telegramUserId): bool
    {
        $record = $this->load($code);

        if (null === $record || self::STATE_PENDING !== $record['state'] || $telegramUserId <= 0) {
            return $this->deny('the code is unknown, expired or used');
        }

        if (0 === $record['telegram_user_id']) {
            $record['telegram_user_id'] = $telegramUserId;
            $this->save($code, $record);

            return true;
        }

        if ($record['telegram_user_id'] !== $telegramUserId) {
            return $this->deny('another Telegram account opened the code first');
        }

        return true;
    }

    /**
     * Approves the code for $personId, when it is pending and the Telegram user
     * pressing the button is the one who opened it.
     */
    public function approve(string $code, int $telegramUserId, int $personId): bool
    {
        $record = $this->load($code);

        if (null === $record || self::STATE_PENDING !== $record['state'] || $personId <= 0) {
            return $this->deny('the code is unknown, expired or used');
        }

        if ($telegramUserId <= 0 || $record['telegram_user_id'] !== $telegramUserId) {
            return $this->deny('the approval came from another Telegram account');
        }

        $record['state'] = self::STATE_APPROVED;
        $record['person_id'] = $personId;
        $this->save($code, $record);

        return true;
    }

    /**
     * A Telegram user with no crew account yet is signing up in the bot.
     * Remembers the code against them, for as long as the code lives, so the
     * click on their confirmation link can approve it.
     */
    public function rememberForSignUp(string $code, int $telegramUserId): void
    {
        $record = $this->load($code);

        if (null === $record) {
            return;
        }

        set_transient(self::WAIT_PREFIX . $telegramUserId, $code, $this->remaining($record));
    }

    /**
     * The confirmation link linked $telegramUserId to $personId: approves the
     * code they were remembered with, if it is still pending and within its
     * lifetime. True when a code was approved.
     */
    public function approveWaiting(int $telegramUserId, int $personId): bool
    {
        $key = self::WAIT_PREFIX . $telegramUserId;
        $code = get_transient($key);

        if (! is_string($code) || '' === $code) {
            return false;
        }

        delete_transient($key);

        return $this->approve($code, $telegramUserId, $personId);
    }

    /**
     * Counts a code request from this address. False once the address has
     * asked RATE_LIMIT times within RATE_WINDOW. The address is stored only as
     * a hash, and only for the window.
     */
    private function allowFrom(string $clientIp): bool
    {
        $key = self::RATE_PREFIX . substr(hash('sha256', 'eventcrew|' . $clientIp), 0, 32);
        $seen = get_transient($key);
        $now = time();

        if (! is_array($seen) || (int) ($seen['since'] ?? 0) + self::RATE_WINDOW <= $now) {
            $seen = ['since' => $now, 'count' => 0];
        }

        if ((int) $seen['count'] >= self::RATE_LIMIT) {
            return false;
        }

        $seen['count'] = (int) $seen['count'] + 1;
        set_transient($key, $seen, max(1, (int) $seen['since'] + self::RATE_WINDOW - $now));

        return true;
    }

    /**
     * The stored record for a code, or null when the code is malformed,
     * unknown or older than TTL. One transient read.
     *
     * @return array{
     *     secret_hash: string, created: int, state: string, person_id: int, telegram_user_id: int, match: string
     * }|null
     */
    private function load(string $code): ?array
    {
        if (! self::isCode($code)) {
            return null;
        }

        $record = get_transient(self::key($code));

        if (! is_array($record) || ! is_string($record['secret_hash'] ?? null)) {
            return null;
        }

        $record = [
            'secret_hash' => $record['secret_hash'],
            'created' => (int) ($record['created'] ?? 0),
            'state' => (string) ($record['state'] ?? ''),
            'person_id' => (int) ($record['person_id'] ?? 0),
            'telegram_user_id' => (int) ($record['telegram_user_id'] ?? 0),
            'match' => (string) ($record['match'] ?? ''),
        ];

        // Checked here too: an object cache may keep a transient past its expiry.
        return $this->remaining($record) > 0 ? $record : null;
    }

    /**
     * @param array{
     *     secret_hash: string, created: int, state: string, person_id: int, telegram_user_id: int, match: string
     * } $record
     */
    private function save(string $code, array $record): void
    {
        set_transient(self::key($code), $record, max(1, $this->remaining($record)));
    }

    /**
     * Seconds the code has left.
     *
     * @param array{created: int} $record
     */
    private function remaining(array $record): int
    {
        return $record['created'] + self::TTL - time();
    }

    /** Keyed by the code's hash, so the code itself is never written down. */
    private static function key(string $code): string
    {
        return self::CODE_PREFIX . hash('sha256', $code);
    }

    /** Logs why a request was refused - never the code, the secret or who asked - and returns null. */
    private function refuse(string $reason): null
    {
        $this->logger->warning(sprintf('Telegram sign-in refused: %s.', $reason));

        return null;
    }

    /** refuse(), answering false. */
    private function deny(string $reason): bool
    {
        $this->refuse($reason);

        return false;
    }
}

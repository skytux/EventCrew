<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Support\Logger;

/**
 * The single seam through which EventCrew talks to the Telegram Bot API.
 *
 * Deliberately thin - it builds the request, sends it with wp_remote_post
 * (the plugin ships no HTTP library), and hands back the decoded `result` or
 * null. Every failure is logged and swallowed here rather than thrown, because
 * a bot message that does not send must never take down the webhook request or
 * the admin page that triggered it.
 *
 * With no token configured every call is a logged no-op, so an install that
 * has never set one up behaves exactly like one where Telegram is simply
 * unreachable - nothing errors, nothing sends.
 */
final class TelegramClient
{
    public const TOKEN_OPTION = 'eventcrew_telegram_bot_token';

    /** Opt-in: resolve api.telegram.org via Cloudflare DoH when the host can't. */
    public const DNS_BYPASS_OPTION = 'eventcrew_telegram_dns_bypass';

    private const API_HOST = 'api.telegram.org';
    private const API_BASE = 'https://api.telegram.org/bot';

    /**
     * Bot API responses that read as errors but are normal operation, not
     * faults. Editing the board to content it already shows is a no-op (the
     * board is already correct), and answering a callback whose tap is older
     * than Telegram's answer window - a redelivered or slow update - is beyond
     * our control and only cosmetic (the join already committed). Neither should
     * set lastError (the Settings page reads it) or log a warning (the
     * Diagnostics activity panel reads warnings as problems). Matched loosely,
     * since Telegram pads the core phrase with extra detail.
     */
    private const BENIGN_ERRORS = [
        'message is not modified',
        'query is too old',
        'query id is invalid',
    ];

    /** The description from the most recent failed call, for the admin to see. */
    private string $lastError = '';

    /** Telegram's retry_after seconds from the most recent failed call; 0 when it gave none. */
    private int $lastRetryAfter = 0;

    /** Set while a call is sent again to a migrated chat, so it is sent again only once. */
    private bool $followingMigration = false;

    /**
     * Where the bot's last message in each private chat is kept: its message
     * id, or 0 when it is a record (a ticket, a confirmation, a request) that a
     * button tap must not replace. Cleared when the person writes.
     */
    public const LAST_PREFIX = 'eventcrew_last_dm_';

    /** Private chat id => message id the next sendMessage there replaces (replaceNextIn()). */
    private array $replace = [];

    /** Set by record(): the next send is kept as its own message. */
    private bool $record = false;

    public function __construct(
        private readonly Logger $logger,
        private readonly DohResolver $resolver
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== self::token();
    }

    /**
     * Why the last call returned null - Telegram's own error text, or the
     * transport error. Empty when the last call succeeded. The setup handler
     * shows this so "refused" is never a dead end.
     */
    public function lastError(): string
    {
        return $this->lastError;
    }

    /**
     * How many seconds Telegram asked to wait before trying the last call again
     * ("Too Many Requests: retry after N"). 0 when the last call succeeded or
     * failed for any other reason.
     */
    public function lastRetryAfter(): int
    {
        return $this->lastRetryAfter;
    }

    /**
     * Calls one Bot API method. Returns the `result` payload on success (an
     * empty array when the result is a bare `true`, as setWebhook and
     * answerCallbackQuery return), or null on any transport or API error.
     *
     * With $quiet a failure is logged as info instead of a warning or an
     * error: for calls whose failure is expected now and then and changes
     * nothing, such as a personal command menu for someone who never opened a
     * private chat with the bot.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function call(string $method, array $params = [], bool $quiet = false): ?array
    {
        $this->lastError = '';
        $this->lastRetryAfter = 0;
        $record = $this->record;

        if (str_starts_with($method, 'send')) {
            $this->record = false;
        }

        $token = self::token();

        if ('' === $token) {
            $this->lastError = 'no bot token configured';
            $this->logger->warning(sprintf('Telegram %s skipped: %s.', $method, $this->lastError));

            return null;
        }

        $response = $this->post(
            self::API_BASE . $token . '/' . $method,
            [
                'timeout' => 15,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode($params),
            ]
        );

        if (is_wp_error($response)) {
            $this->lastError = (string) $response->get_error_message();
            $message = sprintf('Telegram %s failed: %s', $method, $this->lastError);
            $quiet ? $this->logger->info($message) : $this->logger->error($message);

            return null;
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        if (! is_array($decoded) || empty($decoded['ok'])) {
            $description = is_array($decoded)
                ? (string) ($decoded['description'] ?? 'unknown error')
                : 'unparseable response';

            // A benign response is treated as success: the board is already
            // current, or the callback answer arrived too late to matter. Log a
            // quiet breadcrumb, leave lastError clear, and return the same
            // empty-array "ok" sentinel a bare `true` result gives.
            if ($this->isBenign($description)) {
                $this->logger->info(sprintf('Telegram %s: %s (ignored).', $method, $description));

                return [];
            }

            $this->lastError = $description;
            $this->lastRetryAfter = is_array($decoded) ? max(0, (int) ($decoded['parameters']['retry_after'] ?? 0)) : 0;
            $message = sprintf('Telegram %s returned an error: %s', $method, $this->lastError);
            $quiet ? $this->logger->info($message) : $this->logger->warning($message);

            $migrateTo = is_array($decoded) ? (int) ($decoded['parameters']['migrate_to_chat_id'] ?? 0) : 0;

            if (0 !== $migrateTo) {
                return $this->followMigration($method, $params, $quiet, $migrateTo);
            }

            return null;
        }

        $result = is_array($decoded['result'] ?? null) ? $decoded['result'] : [];
        $this->track($method, $params, $result, $record);

        return $result;
    }

    /**
     * Keeps the bot's last message per private chat (LAST_PREFIX) after a
     * send, and drops a pending replacement whose message the handler edits
     * or deletes itself.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $result
     */
    private function track(string $method, array $params, array $result, bool $record): void
    {
        $chatId = (int) ($params['chat_id'] ?? 0);

        if ($chatId <= 0) {
            return;
        }

        $messageId = (int) ($params['message_id'] ?? 0);

        $touchesTarget = 0 !== $messageId && ($this->replace[$chatId] ?? 0) === $messageId;

        if ($touchesTarget && (str_starts_with($method, 'edit') || 'deleteMessage' === $method)) {
            unset($this->replace[$chatId]);
        }

        if (! str_starts_with($method, 'send') || 'sendChatAction' === $method) {
            return;
        }

        // A new message in the chat: the tapped one is no longer the last.
        unset($this->replace[$chatId]);

        $sent = (int) ($result['message_id'] ?? 0);

        if (0 === $sent || ! function_exists('set_transient')) {
            return;
        }

        set_transient(self::LAST_PREFIX . $chatId, $record ? 0 : $sent, 2 * DAY_IN_SECONDS);
    }

    /**
     * Telegram said the chat of $params moved to $migrateTo: the group was
     * upgraded to a supergroup, and its old id no longer works. The crew's
     * group follows (GroupChat::migrated()), and a call that names no message
     * is sent once more to the new chat. A call on a message (an edit, a
     * delete) is not: the message ids of the old chat do not carry over.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function followMigration(string $method, array $params, bool $quiet, int $migrateTo): ?array
    {
        $from = (int) ($params['chat_id'] ?? 0);

        if (0 === $from || $from === $migrateTo || $this->followingMigration) {
            return null;
        }

        GroupChat::migrated($from, $migrateTo);
        $this->logger->info(sprintf('Telegram chat %d moved to %d.', $from, $migrateTo));

        if (isset($params['message_id'])) {
            return null;
        }

        $params['chat_id'] = $migrateTo;
        $this->followingMigration = true;

        try {
            return $this->call($method, $params, $quiet);
        } finally {
            $this->followingMigration = false;
        }
    }

    /**
     * A button tap on $messageId in a private chat: when that message is the
     * bot's last one there and the person has not written since, the next
     * sendMessage to the chat replaces it instead of posting a new message.
     * Only for this request; an edit or delete of that message by the handler
     * itself cancels it, so a handler that edits the tapped message keeps
     * doing what it did.
     */
    public function replaceNextIn(int $chatId, int $messageId): void
    {
        if ($chatId <= 0 || $messageId <= 0 || ! function_exists('get_transient')) {
            return;
        }

        if ((int) get_transient(self::LAST_PREFIX . $chatId) === $messageId) {
            $this->replace[$chatId] = $messageId;
        }
    }

    /** The person wrote in a private chat: the bot's last message there is no longer the last. */
    public function forgetLast(int $chatId): void
    {
        unset($this->replace[$chatId]);

        if ($chatId > 0 && function_exists('delete_transient')) {
            delete_transient(self::LAST_PREFIX . $chatId);
        }
    }

    /**
     * The next send is a record the person keeps in the chat (a ticket, a
     * confirmation, a request): it never replaces the tapped message, and a
     * later tap on it does not replace it either.
     */
    public function record(): static
    {
        $this->record = true;

        return $this;
    }

    /**
     * $extra: more sendMessage fields, like link_preview_options (the
     * board's banner); they go to the in-place edit too.
     *
     * @param array<string, mixed>|null $replyMarkup
     * @param array<string, mixed> $extra
     * @return array<string, mixed>|null
     */
    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null, array $extra = []): ?array
    {
        $params = ['chat_id' => $chatId, 'text' => $text] + $extra;

        if (null !== $replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }

        $target = $this->record ? 0 : ($this->replace[(int) $chatId] ?? 0);

        if (0 !== $target) {
            unset($this->replace[(int) $chatId]);
            $edited = $this->call('editMessageText', $params + ['message_id' => $target], true);

            if (null !== $edited) {
                return [] === $edited ? ['message_id' => $target] : $edited;
            }
        }

        return $this->call('sendMessage', $params);
    }

    /**
     * A photo message: Telegram fetches the picture from $photoUrl itself. The
     * caption is Telegram HTML. Null when Telegram refuses it, for example
     * because it cannot fetch or read the picture.
     *
     * @param array<string, mixed>|null $replyMarkup
     * @return array<string, mixed>|null
     */
    public function sendPhoto(int|string $chatId, string $photoUrl, string $caption, ?array $replyMarkup = null): ?array
    {
        $params = [
            'chat_id' => $chatId,
            'photo' => $photoUrl,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ];

        if (null !== $replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }

        return $this->call('sendPhoto', $params);
    }

    /**
     * $extra: more fields, like link_preview_options (the board's banner).
     *
     * @param array<string, mixed>|null $replyMarkup
     * @param array<string, mixed> $extra
     * @return array<string, mixed>|null
     */
    public function editMessageText(
        int|string $chatId,
        int $messageId,
        string $text,
        ?array $replyMarkup = null,
        array $extra = []
    ): ?array {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ] + $extra;

        if (null !== $replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }

        return $this->call('editMessageText', $params);
    }

    /**
     * A new caption (Telegram HTML) and buttons for a photo message.
     *
     * @param array<string, mixed>|null $replyMarkup
     * @return array<string, mixed>|null
     */
    public function editMessageCaption(
        int|string $chatId,
        int $messageId,
        string $caption,
        ?array $replyMarkup = null
    ): ?array {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ];

        if (null !== $replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }

        return $this->call('editMessageCaption', $params);
    }

    /**
     * A new picture for a photo message, with its caption (Telegram HTML) and
     * buttons. Telegram fetches the picture from $photoUrl itself.
     *
     * @param array<string, mixed>|null $replyMarkup
     * @return array<string, mixed>|null
     */
    public function editMessageMedia(
        int|string $chatId,
        int $messageId,
        string $photoUrl,
        string $caption,
        ?array $replyMarkup = null
    ): ?array {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'media' => [
                'type' => 'photo',
                'media' => $photoUrl,
                'caption' => $caption,
                'parse_mode' => 'HTML',
            ],
        ];

        if (null !== $replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }

        return $this->call('editMessageMedia', $params);
    }

    /**
     * New buttons for a message, leaving its text or caption as it is. With
     * null the buttons are removed.
     *
     * @param array<string, mixed>|null $replyMarkup
     * @return array<string, mixed>|null
     */
    public function editMessageReplyMarkup(int|string $chatId, int $messageId, ?array $replyMarkup): ?array
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ];

        if (null !== $replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }

        return $this->call('editMessageReplyMarkup', $params);
    }

    /**
     * Removes a message the bot posted. Used when the board is re-posted, so the
     * old board is cleared rather than left behind. A message too old to delete
     * (Telegram limits bots to 48h) or already gone comes back as an error the
     * caller can safely ignore - the new board is what matters.
     *
     * @return array<string, mixed>|null
     */
    public function deleteMessage(int|string $chatId, int $messageId): ?array
    {
        return $this->call('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    /**
     * $url: a t.me/<bot>?start=... link the tapping person's app opens
     * instead of showing $text.
     *
     * @return array<string, mixed>|null
     */
    public function answerCallbackQuery(
        string $callbackQueryId,
        string $text = '',
        bool $showAlert = false,
        string $url = ''
    ): ?array {
        $body = [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $showAlert,
        ];

        if ('' !== $url) {
            $body['url'] = $url;
        }

        return $this->call('answerCallbackQuery', $body);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function setWebhook(string $url, string $secretToken): ?array
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
            // Just what the bot acts on. my_chat_member is the bot's own
            // being-added/removed notice, which Telegram delivers even under
            // group privacy mode - that is what lets the board's group be
            // captured without making the bot an admin.
            'allowed_updates' => ['message', 'callback_query', 'my_chat_member'],
        ]);
    }

    /**
     * Registers the bot's slash commands so they show in Telegram's command
     * menu. Under group privacy mode a registered command is the reliable way
     * for a bare /command to reach the bot.
     *
     * $scope is a BotCommandScope (['type' => 'all_private_chats'], or
     * ['type' => 'chat', 'chat_id' => ...]); null is Telegram's default scope.
     * $languageCode is a two-letter code such as 'fi'; '' is the list for
     * every language without its own.
     *
     * @param array<int, array{command: string, description: string}> $commands
     * @param array<string, mixed>|null $scope
     * @return array<string, mixed>|null
     */
    public function setMyCommands(
        array $commands,
        ?array $scope = null,
        string $languageCode = '',
        bool $quiet = false
    ): ?array {
        return $this->call('setMyCommands', ['commands' => $commands] + $this->scopeParams($scope, $languageCode), $quiet);
    }

    /**
     * Removes the command list of one scope and language, so the next broader
     * scope applies again. Arguments as for setMyCommands().
     *
     * @param array<string, mixed>|null $scope
     * @return array<string, mixed>|null
     */
    public function deleteMyCommands(?array $scope = null, string $languageCode = '', bool $quiet = false): ?array
    {
        return $this->call('deleteMyCommands', $this->scopeParams($scope, $languageCode), $quiet);
    }

    /**
     * Sets the bot's short description ("About"), shown on the bot's profile
     * and in shared links. Max 120 characters. A null or '' $languageCode is
     * the text for every language without its own; '' as $text clears it.
     *
     * @return array<string, mixed>|null
     */
    public function setMyShortDescription(string $text, ?string $languageCode = null): ?array
    {
        return $this->call(
            'setMyShortDescription',
            ['short_description' => $text] + $this->scopeParams(null, (string) $languageCode)
        );
    }

    /**
     * Sets the bot's description, shown in an empty private chat with the bot.
     * Max 512 characters. Arguments as for setMyShortDescription().
     *
     * @return array<string, mixed>|null
     */
    public function setMyDescription(string $text, ?string $languageCode = null): ?array
    {
        return $this->call(
            'setMyDescription',
            ['description' => $text] + $this->scopeParams(null, (string) $languageCode)
        );
    }

    /**
     * @param array<string, mixed>|null $scope
     * @return array<string, mixed>
     */
    private function scopeParams(?array $scope, string $languageCode): array
    {
        $params = [];

        if (null !== $scope) {
            $params['scope'] = $scope;
        }

        if ('' !== $languageCode) {
            $params['language_code'] = $languageCode;
        }

        return $params;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getWebhookInfo(): ?array
    {
        return $this->call('getWebhookInfo');
    }

    /**
     * Removes the webhook. Used before a re-install to force Telegram to reset
     * the webhook's state - notably the sticky last_error_message, which a
     * setWebhook to the same URL leaves untouched. Pending updates are kept
     * (drop_pending_updates defaults to false), so a healthy queue survives.
     *
     * @return array<string, mixed>|null
     */
    public function deleteWebhook(): ?array
    {
        return $this->call('deleteWebhook');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMe(): ?array
    {
        return $this->call('getMe');
    }

    /**
     * The chat's own record. Carries `username` for a public group and, when the
     * bot is an administrator, the primary `invite_link` - between them, the two
     * ways to turn a chat id into something a person can actually tap.
     *
     * @return array<string, mixed>|null
     */
    public function getChat(int|string $chatId): ?array
    {
        return $this->call('getChat', ['chat_id' => $chatId]);
    }

    /**
     * Makes the bot leave a group.
     *
     * @return array<string, mixed>|null
     */
    public function leaveChat(int $chatId): ?array
    {
        return $this->call('leaveChat', ['chat_id' => $chatId]);
    }

    /**
     * Creates an additional invite link for a private group.
     *
     * Deliberately not exportChatInviteLink, which the Bot API documents as
     * *revoking* the previous primary link: that would silently break whatever
     * link the crew has already been given. This one adds a link and leaves the
     * existing ones working. Needs the bot to be an admin with can_invite_users;
     * without it Telegram answers with an error and this returns null.
     *
     * @return array<string, mixed>|null
     */
    public function createChatInviteLink(int|string $chatId, string $name = '', int $memberLimit = 0): ?array
    {
        $params = ['chat_id' => $chatId];

        if ($memberLimit > 0) {
            // The link stops working once this many people have joined by it.
            $params['member_limit'] = $memberLimit;
        }

        if ('' !== $name) {
            // Shown to admins in Telegram's invite-link list, so it is obvious
            // later which link the plugin made and why.
            $params['name'] = $name;
        }

        return $this->call('createChatInviteLink', $params);
    }

    /**
     * Whether a Bot API error description is one of the expected, benign ones
     * (see BENIGN_ERRORS). Case-insensitive substring match, because Telegram's
     * wording carries extra detail around the core phrase.
     */
    private function isBenign(string $description): bool
    {
        foreach (self::BENIGN_ERRORS as $fragment) {
            if (false !== stripos($description, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The bot token, read from the EVENTCREW_TELEGRAM_TOKEN constant first so it
     * can live in wp-config.php and stay out of the database (and its backups and
     * exports), falling back to the option the Settings page writes.
     *
     * Static and public because "is the bot set up at all" is a question asked
     * from outside a configured client - the privacy notice only lists Telegram
     * as a recipient on installs that actually use it - and the constant-first
     * rule is the sort of thing that goes wrong the moment it exists twice.
     */
    public static function token(): string
    {
        if (defined('EVENTCREW_TELEGRAM_TOKEN')) {
            $constant = trim((string) EVENTCREW_TELEGRAM_TOKEN);

            if ('' !== $constant) {
                return $constant;
            }
        }

        return trim((string) get_option(self::TOKEN_OPTION, ''));
    }

    /**
     * Sends the request, pinning api.telegram.org to a Cloudflare-resolved IP
     * when the DNS-bypass toggle is on - for hosts that allow outbound HTTPS
     * but cannot resolve the name themselves. The pin is scoped to the one host
     * and torn down straight after, so it never touches any other request.
     *
     * @param array<string, mixed> $args
     */
    private function post(string $url, array $args): mixed
    {
        if (! (bool) get_option(self::DNS_BYPASS_OPTION, false)) {
            return wp_remote_post($url, $args);
        }

        $ip = $this->resolver->resolve(self::API_HOST);

        if (null === $ip) {
            // DoH itself failed; let the normal path run and report its error.
            return wp_remote_post($url, $args);
        }

        $pin = static function ($handle) use ($ip): void {
            if (function_exists('curl_setopt')) {
                curl_setopt($handle, CURLOPT_RESOLVE, [self::API_HOST . ':443:' . $ip]);
            }
        };

        // http_api_curl hands over the cURL handle before the request runs.
        // Matched on the host, not on the URL containing the host's name
        // anywhere: https://example.test/?to=api.telegram.org is not a request
        // to Telegram. The pin only ever supplies an address for API_HOST, so a
        // loose match was harmless - but a filter that fires on other people's
        // requests is not a thing to leave lying around.
        $filter = static function ($handle, $args2, $requestUrl) use ($pin): void {
            if (self::API_HOST === wp_parse_url((string) $requestUrl, PHP_URL_HOST)) {
                $pin($handle);
            }
        };

        add_action('http_api_curl', $filter, 10, 3);

        try {
            return wp_remote_post($url, $args);
        } finally {
            remove_action('http_api_curl', $filter, 10);
        }
    }
}

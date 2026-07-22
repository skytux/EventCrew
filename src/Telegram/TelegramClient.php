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

    /** The description from the most recent failed call, for the admin to see. */
    private string $lastError = '';

    public function __construct(
        private readonly Logger $logger,
        private readonly DohResolver $resolver
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->token();
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
     * Calls one Bot API method. Returns the `result` payload on success (an
     * empty array when the result is a bare `true`, as setWebhook and
     * answerCallbackQuery return), or null on any transport or API error.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function call(string $method, array $params = []): ?array
    {
        $this->lastError = '';
        $token = $this->token();

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
            $this->logger->error(sprintf('Telegram %s failed: %s', $method, $this->lastError));

            return null;
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        if (! is_array($decoded) || empty($decoded['ok'])) {
            $this->lastError = is_array($decoded)
                ? (string) ($decoded['description'] ?? 'unknown error')
                : 'unparseable response';

            $this->logger->warning(
                sprintf('Telegram %s returned an error: %s', $method, $this->lastError)
            );

            return null;
        }

        return is_array($decoded['result'] ?? null) ? $decoded['result'] : [];
    }

    /**
     * @param array<string, mixed>|null $replyMarkup
     * @return array<string, mixed>|null
     */
    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null): ?array
    {
        $params = ['chat_id' => $chatId, 'text' => $text];

        if (null !== $replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }

        return $this->call('sendMessage', $params);
    }

    /**
     * @param array<string, mixed>|null $replyMarkup
     * @return array<string, mixed>|null
     */
    public function editMessageText(
        int|string $chatId,
        int $messageId,
        string $text,
        ?array $replyMarkup = null
    ): ?array {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ];

        if (null !== $replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }

        return $this->call('editMessageText', $params);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): ?array
    {
        return $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $showAlert,
        ]);
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
     * @param array<int, array{command: string, description: string}> $commands
     * @return array<string, mixed>|null
     */
    public function setMyCommands(array $commands): ?array
    {
        return $this->call('setMyCommands', ['commands' => $commands]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getWebhookInfo(): ?array
    {
        return $this->call('getWebhookInfo');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMe(): ?array
    {
        return $this->call('getMe');
    }

    /**
     * The bot token, read from the EVENTCREW_TELEGRAM_TOKEN constant first so it
     * can live in wp-config.php and stay out of the database (and its backups and
     * exports), falling back to the option the Settings page writes.
     */
    private function token(): string
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
        $filter = static function ($handle, $args2, $requestUrl) use ($pin): void {
            if (str_contains((string) $requestUrl, self::API_HOST)) {
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

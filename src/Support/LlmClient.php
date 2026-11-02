<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * One chat request to the OpenAI-compatible server set in AssistantSettings.
 *
 * POST {url}/chat/completions with the key as a Bearer token, a low
 * temperature, and thinking switched off through chat_template_kwargs (always
 * sent: a server that does not know the field ignores it). The timeout is far
 * below PHP's usual 60 seconds, so a slow server cannot hold the webhook.
 *
 * Nothing about the request is logged except what went wrong, as a short
 * kind: never the key and never the text of a message.
 */
final class LlmClient
{
    /** Seconds to wait for the whole answer. */
    public const TIMEOUT = 20;

    public const ERROR_NOT_CONFIGURED = 'not_configured';
    public const ERROR_TIMEOUT = 'timeout';
    public const ERROR_TRANSPORT = 'transport';
    public const ERROR_BAD_RESPONSE = 'bad_response';
    public const ERROR_EMPTY = 'empty';

    public function __construct(
        private readonly Logger $logger
    ) {
    }

    /**
     * Sends $messages and returns the answer's text.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{ok: bool, content: string, error: string}
     *     error is '' on success, else one of the ERROR_* kinds or "http_<status>".
     */
    public function chat(array $messages, int $maxTokens = 700, int $timeout = self::TIMEOUT): array
    {
        if (! AssistantSettings::isConfigured()) {
            return $this->failure(self::ERROR_NOT_CONFIGURED);
        }

        $headers = ['Content-Type' => 'application/json'];
        $key = AssistantSettings::apiKey();

        if ('' !== $key) {
            $headers['Authorization'] = 'Bearer ' . $key;
        }

        $response = wp_remote_post(
            AssistantSettings::url() . '/chat/completions',
            [
                'timeout' => $timeout,
                'headers' => $headers,
                'body' => wp_json_encode([
                    'model' => AssistantSettings::model(),
                    'messages' => $messages,
                    'temperature' => 0.2,
                    'max_tokens' => $maxTokens,
                    'stream' => false,
                    'chat_template_kwargs' => ['enable_thinking' => false],
                ]),
                'data_format' => 'body',
            ]
        );

        if (is_wp_error($response)) {
            $message = strtolower((string) $response->get_error_message());
            $timedOut = str_contains($message, 'timed out') || str_contains($message, 'curl error 28');

            return $this->failure($timedOut ? self::ERROR_TIMEOUT : self::ERROR_TRANSPORT);
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status < 200 || $status >= 300) {
            return $this->failure('http_' . $status);
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        $choice = is_array($decoded) ? ($decoded['choices'][0]['message'] ?? null) : null;

        if (! is_array($choice)) {
            return $this->failure(self::ERROR_BAD_RESPONSE);
        }

        $content = $choice['content'] ?? '';

        if (! is_string($content) || '' === trim($content)) {
            return $this->failure(self::ERROR_EMPTY);
        }

        return ['ok' => true, 'content' => $content, 'error' => ''];
    }

    /**
     * The "Test connection" request: one tiny question. Returns '' when the
     * server answered, else the error kind.
     */
    public function test(): string
    {
        $result = $this->chat([['role' => 'user', 'content' => 'Reply with the word OK.']], 5);

        return $result['ok'] ? '' : $result['error'];
    }

    /**
     * @return array{ok: bool, content: string, error: string}
     */
    private function failure(string $kind): array
    {
        if (self::ERROR_NOT_CONFIGURED !== $kind) {
            $this->logger->warning(sprintf('Assistant: the language model request failed (%s).', $kind));
        }

        return ['ok' => false, 'content' => '', 'error' => $kind];
    }
}

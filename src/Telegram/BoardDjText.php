<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Support\AiPrompts;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\EventName;
use EventCrew\Support\EventSource;
use EventCrew\Support\Language;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\NewsletterWriter;

/**
 * The short text about the DJ or host under the next event's picture on the
 * group board (BoardService::CARDS_OPTION): 2 sentences in English, written
 * by the language model (LlmClient) from the event's DJs (EventMesh's
 * NewsletterWriter::DJ_FILTER, with their descriptions) and the event's
 * description.
 *
 * The model is never asked while the board is built or a Telegram update is
 * answered. When the board finds no text for its card, it calls schedule(),
 * which asks WP-Cron for one single run of HOOK; run() then writes the text
 * for the board's next event in the background and refreshes the board, so
 * the caption gets the text. Until then the caption has only the name, date
 * and price lines.
 *
 * Texts are kept in OPTION by event id, with a hash of what the model was
 * given (source()). A text whose source changed since (other DJs, a new
 * description) is not shown and is written again. A failed request stores no
 * text; TRIED_OPTION only keeps the same source from being asked again
 * within RETRY_AFTER.
 */
final class BoardDjText
{
    /** The WP-Cron single event that writes the text. */
    public const HOOK = 'eventcrew_board_dj_text';

    /** The texts: [event id => ['hash' => md5 of source(), 'text' => the text]]. */
    public const OPTION = 'eventcrew_board_dj_texts';

    /** The last source asked for: ['key' => "<event id>:<hash>", 'at' => time]. */
    public const TRIED_OPTION = 'eventcrew_board_dj_text_tried';

    /** How many events' texts are kept. */
    public const KEEP = 10;

    /** The longest text accepted, in characters. */
    public const MAX_TEXT = 400;

    /** The longest description sent, in characters. */
    private const MAX_DESCRIPTION = 4000;

    /** The longest DJ description sent, in characters. */
    private const MAX_DJ_DESCRIPTION = 1200;

    /** Seconds before a failed source is asked again. */
    private const RETRY_AFTER = 3600;

    /** Tokens for one answer. */
    private const MAX_TOKENS = 300;

    // The instruction is AiPrompts::BOARD_DJ: editable in wp-admin, with the
    // former text built in.

    public function __construct(
        private readonly LlmClient $llm,
        private readonly Logger $logger,
        private readonly BoardService $board
    ) {
    }

    public function boot(): void
    {
        add_action(self::HOOK, [$this, 'run']);
    }

    /**
     * The stored text for $postId, '' when there is none or its source has
     * changed since it was written. No request is made.
     */
    public static function stored(int $postId): string
    {
        $texts = self::texts();
        $entry = $texts[$postId] ?? null;

        if (! is_array($entry) || ! is_string($entry['text'] ?? null) || '' === $entry['text']) {
            return '';
        }

        $source = self::source($postId);

        return '' !== $source && md5($source) === ($entry['hash'] ?? '') ? $entry['text'] : '';
    }

    /**
     * Asks WP-Cron for one run that writes the text for $postId, unless the
     * model is not set up, there is nothing to write from, the same source
     * failed less than RETRY_AFTER ago, or a run is already waiting.
     */
    public static function schedule(int $postId): void
    {
        if (! AssistantSettings::isConfigured() || ! function_exists('wp_schedule_single_event')) {
            return;
        }

        $source = self::source($postId);

        if ('' === $source) {
            return;
        }

        $tried = get_option(self::TRIED_OPTION, []);

        if (
            is_array($tried)
            && ($tried['key'] ?? '') === $postId . ':' . md5($source)
            && time() - (int) ($tried['at'] ?? 0) < self::RETRY_AFTER
        ) {
            return;
        }

        if (false === wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time(), self::HOOK);
        }
    }

    /**
     * The WP-Cron run: writes the text for the board's next event when it is
     * missing, then refreshes the board so the caption shows it. Does nothing
     * outside a background run (WP-Cron or WP-CLI).
     */
    public function run(): void
    {
        if (! self::inBackground() || ! BoardService::cardsOn()) {
            return;
        }

        $postId = $this->board->nextCardEvent();

        if (null === $postId || '' !== self::stored($postId)) {
            return;
        }

        if ($this->write($postId)) {
            $this->board->refresh();
        }
    }

    /**
     * Asks the model for $postId's text and stores it. Returns whether a text
     * was stored; on any failure nothing is stored.
     */
    public function write(int $postId): bool
    {
        $source = self::source($postId);

        if ('' === $source || ! AssistantSettings::isConfigured()) {
            return false;
        }

        $hash = md5($source);
        update_option(self::TRIED_OPTION, ['key' => $postId . ':' . $hash, 'at' => time()], false);

        try {
            $answer = $this->llm->chat(
                [
                    [
                        'role' => 'system',
                        'content' => AiPrompts::get(AiPrompts::BOARD_DJ) . "\n\nAnswer with JSON only, with no other text: "
                            . '{"text": "the English text"}',
                    ],
                    ['role' => 'user', 'content' => $source],
                ],
                self::MAX_TOKENS,
                LlmClient::TIMEOUT
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Board DJ text: writing the text failed (' . get_class($e) . ').');

            return false;
        }

        if (! $answer['ok']) {
            return false;
        }

        $text = NewsletterWriter::parse((string) $answer['content'], 'text');

        if (null === $text || mb_strlen($text) > self::MAX_TEXT || self::banned($text)) {
            $this->logger->warning('Board DJ text: the language model answer could not be used.');

            return false;
        }

        $texts = self::texts();
        unset($texts[$postId]);
        $texts[$postId] = ['hash' => $hash, 'text' => $text];

        update_option(self::OPTION, array_slice($texts, -self::KEEP, null, true), false);

        return true;
    }

    /**
     * What the model is told about $postId: the event's name, its DJs with
     * their descriptions, and its description. '' when the event has neither
     * DJs nor a description, so there is nothing to write about.
     */
    public static function source(int $postId): string
    {
        $event = EventSource::describe($postId);

        if (null === $event) {
            return '';
        }

        $lines = ['Event: ' . EventName::name($postId, Language::ENGLISH, $event['title'])];
        $djs = [];
        $listed = apply_filters(NewsletterWriter::DJ_FILTER, [], $postId);

        foreach (is_array($listed) ? $listed : [] as $dj) {
            if (! is_array($dj) || ! is_string($dj['name'] ?? null) || '' === trim($dj['name'])) {
                continue;
            }

            $about = is_string($dj['description'] ?? null) ? trim($dj['description']) : '';
            $about = '' === $about ? '' : ': ' . mb_substr($about, 0, self::MAX_DJ_DESCRIPTION);
            $djs[] = '- ' . trim($dj['name']) . $about;
        }

        if ([] !== $djs) {
            $lines[] = '';
            $lines[] = 'DJs (people who play the music, not places):';
            array_push($lines, ...$djs);
        }

        $card = EventSource::card($postId);
        $described = false;

        foreach (
            [
                'Description (English)' => $card['excerpt_en'],
                'Description (Finnish)' => $card['excerpt_fi'],
                'Description' => $card['excerpt'],
            ] as $label => $text
        ) {
            $text = trim($text);

            if ('' !== $text) {
                $lines[] = '';
                $lines[] = $label . ":\n" . mb_substr($text, 0, self::MAX_DESCRIPTION);
                $described = true;
            }
        }

        return [] === $djs && ! $described ? '' : implode("\n", $lines);
    }

    /** Whether $text has one of the words the prompt forbids. */
    private static function banned(string $text): bool
    {
        return 1 === preg_match('/vol' . 'unteer|\bsh' . 'ift|vu' . 'oro|vapaa' . 'ehto/iu', $text);
    }

    /** @return array<int, mixed> */
    private static function texts(): array
    {
        $texts = get_option(self::OPTION, []);

        return is_array($texts) ? $texts : [];
    }

    /** Whether this run is in the background: WP-Cron or WP-CLI, not a visitor's request. */
    private static function inBackground(): bool
    {
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        return function_exists('wp_doing_cron') && wp_doing_cron();
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The texts the language model writes for the next events announcement.
 *
 * When the switch (ENABLED_OPTION) is on and the model is set up, each event
 * card gets 2–3 sentences written by the model in place of the event's full
 * description, and, when the organizers wrote no opening text, the email gets
 * a 2–3 sentence opening written by the model too.
 *
 * Each text is written in English first and then translated into Finnish with
 * a second request. The model gets the event (titles, date, time, the whole
 * description), its DJs from EventMesh (the DJ_FILTER filter), the site's own
 * words (WORDS_OPTION) and the texts it wrote for the last MEMORY_SIZE
 * announcements that were sent (MEMORY_OPTION), so it does not repeat them.
 *
 * The texts are written by Newsletter when it makes the plan for the preview,
 * and kept in the plan under 'written', so the preview shows what the
 * subscribers get and nothing is asked again at send time. Only the heartbeat
 * in the background (WP-Cron or WP-CLI) writes; a heartbeat run inline on a
 * visitor's request (the cron fallback) writes nothing.
 *
 * Any failure (the model is not set up, an error, an answer that cannot be
 * used) leaves that text out, and the card shows the full description as
 * before. A text that failed is not asked for again in the same plan.
 */
final class NewsletterWriter
{
    /** The switch: '1' when the model writes the texts. On by default. */
    public const ENABLED_OPTION = 'eventcrew_newsletter_write';

    /** The site's words and style for the model, as the organizers wrote them. */
    public const WORDS_OPTION = 'eventcrew_newsletter_words';

    /** The English texts of the last MEMORY_SIZE announcements sent. */
    public const MEMORY_OPTION = 'eventcrew_newsletter_written';

    /** EventMesh's list of an event's DJs: [['name' => …, 'description' => …], …]. */
    public const DJ_FILTER = 'eventmesh/event_djs';

    /** How many sent announcements the model is shown. */
    public const MEMORY_SIZE = 4;

    /** The longest DJ description sent, in characters. */
    public const MAX_DJ_DESCRIPTION = 1200;

    /** The longest event description sent, in characters, per language. */
    public const MAX_DESCRIPTION = 4000;

    /** The longest site words kept, in characters. */
    public const MAX_WORDS = 4000;

    /** The longest written text accepted, in characters, per language. */
    public const MAX_TEXT = 600;

    /** Seconds one run may spend writing; what is left waits for the next run. */
    public const BUDGET = 120;

    /** Tokens for one answer. */
    private const MAX_TOKENS = 600;

    /** The rules every written text follows. */
    private const RULES = 'Use only facts that are in the input. '
        . 'Do not invent times, prices, places, names or anything else. '
        . 'A DJ name is a person, never a place. Name a place only when the input says it is the place. '
        . 'Do not write hashtags or links. '
        . 'Do not use emoji, unless the site\'s words and style below use them. '
        // Spelled in two parts: the word itself is kept out of the source.
        . 'Do not use the word "vol' . 'unteer". '
        . 'Keep the text under 450 characters.';

    /** The instruction for one event's text. */
    private const EVENT_PROMPT = 'You write the text for one event in the email newsletter of a dance event. '
        . 'Write 2 to 3 sentences in English that invite people to come and dance at this event. '
        . 'Be warm and concrete. '
        . 'Use the descriptive words of the DJ descriptions and the facts about the event. '
        . self::RULES;

    /** The instruction for the opening text of the whole email. */
    private const OPENING_PROMPT = 'You write the opening text of the email newsletter of a dance event. '
        . 'The newsletter lists the coming events below the opening, each with its own text. '
        . 'Write 2 to 3 sentences in English that welcome the readers '
        . 'and invite them to come and dance at these events. '
        . 'Be warm and concrete. Do not repeat the details of every event. '
        . self::RULES;

    /** The instruction for the Finnish translation. */
    private const TRANSLATE_PROMPT = 'You translate a short newsletter text from English into Finnish. '
        . 'Write natural, warm Finnish, about as long as the English text and under 500 characters. '
        . 'Keep names, dates, times and emoji exactly as they are. '
        . 'Do not add anything and do not leave anything out.';

    public function __construct(
        private readonly LlmClient $llm,
        private readonly Logger $logger
    ) {
    }

    public static function enabled(): bool
    {
        return '1' === (string) get_option(self::ENABLED_OPTION, '1');
    }

    /** The site's words and style, or ''. */
    public static function words(): string
    {
        return self::cleanWords((string) get_option(self::WORDS_OPTION, ''));
    }

    /** $words as stored: trimmed, line endings unified, cut to MAX_WORDS. */
    public static function cleanWords(string $words): string
    {
        return self::cut(trim(str_replace(["\r\n", "\r"], "\n", $words)), self::MAX_WORDS);
    }

    /**
     * The texts of the last announcements sent, oldest first.
     *
     * @return list<array{opening: string, events: list<string>}>
     */
    public static function memory(): array
    {
        $stored = get_option(self::MEMORY_OPTION, []);
        $memory = [];

        foreach (is_array($stored) ? $stored : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $memory[] = [
                'opening' => is_string($entry['opening'] ?? null) ? $entry['opening'] : '',
                'events' => array_values(array_filter(
                    is_array($entry['events'] ?? null) ? $entry['events'] : [],
                    'is_string'
                )),
            ];
        }

        return array_slice($memory, -self::MEMORY_SIZE);
    }

    /**
     * $plan with the texts that are still missing written into
     * $plan['written']: one per event that has none yet, and the opening when
     * $wantOpening and it was not asked for yet. Unchanged when the switch is
     * off, the model is not set up, or this is not a background run.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public function fill(array $plan, bool $wantOpening): array
    {
        if (! self::enabled() || ! AssistantSettings::isConfigured() || ! self::inBackground()) {
            return $plan;
        }

        $written = self::written($plan);
        $events = is_array($plan['events'] ?? null) ? $plan['events'] : [];
        $started = microtime(true);
        $inTime = static fn (): bool => microtime(true) - $started < self::BUDGET;

        foreach ($events as $event) {
            $id = (int) ($event['id'] ?? 0);

            if ($id <= 0 || array_key_exists($id, $written['events'])) {
                continue;
            }

            if (! $inTime()) {
                break;
            }

            $written['events'][$id] = $this->safely(fn (): ?array => $this->writeEvent($event)) ?? [];
        }

        if ($wantOpening && null === $written['opening'] && [] !== $events && $inTime()) {
            $written['opening'] = $this->safely(
                fn (): ?array => $this->writeOpening($events, $written['events'])
            ) ?? [];
        }

        $plan['written'] = $written;

        return $plan;
    }

    /**
     * Whether fill() has anything left to write for $plan.
     *
     * @param array<string, mixed> $plan
     */
    public static function missing(array $plan, bool $wantOpening): bool
    {
        $written = self::written($plan);
        $events = is_array($plan['events'] ?? null) ? $plan['events'] : [];

        if ([] === $events) {
            return false;
        }

        foreach ($events as $event) {
            if (! array_key_exists((int) ($event['id'] ?? 0), $written['events'])) {
                return true;
            }
        }

        return $wantOpening && null === $written['opening'];
    }

    /**
     * The written texts of $plan that can be shown: event id => text, and the
     * opening (null when there is none).
     *
     * @param array<string, mixed> $plan
     * @return array{events: array<int, array{fi: string, en: string}>, opening: array{fi: string, en: string}|null}
     */
    public static function shown(array $plan): array
    {
        $written = self::written($plan);
        $events = [];

        foreach ($written['events'] as $id => $text) {
            $pair = self::pair($text);

            if (null !== $pair) {
                $events[(int) $id] = $pair;
            }
        }

        return ['events' => $events, 'opening' => self::pair($written['opening'])];
    }

    /**
     * $cards with each written text as written_fi and written_en, which the
     * layout shows in place of the description. Unchanged when the switch is
     * off.
     *
     * @param array<int, array<string, mixed>> $cards
     * @param array<string, mixed>|null $written A plan's 'written'.
     * @return array<int, array<string, mixed>>
     */
    public static function apply(array $cards, ?array $written): array
    {
        if (null === $written || ! self::enabled()) {
            return $cards;
        }

        $texts = is_array($written['events'] ?? null) ? $written['events'] : [];

        foreach ($cards as $index => $card) {
            $text = self::pair($texts[(int) ($card['id'] ?? 0)] ?? null);

            if (null !== $text) {
                $cards[$index]['written_fi'] = $text['fi'];
                $cards[$index]['written_en'] = $text['en'];
            }
        }

        return $cards;
    }

    /**
     * The written opening text, or null when there is none or the switch is off.
     *
     * @param array<string, mixed>|null $written A plan's 'written'.
     * @return array{fi: string, en: string}|null
     */
    public static function opening(?array $written): ?array
    {
        return null === $written || ! self::enabled() ? null : self::pair($written['opening'] ?? null);
    }

    /**
     * After $plan was sent: keeps its English texts in the memory of the last
     * MEMORY_SIZE announcements. The opening counts only when it was shown
     * (the plan carried no organizers' text).
     *
     * @param array<string, mixed> $plan
     */
    public function remember(array $plan): void
    {
        if (! self::enabled() || ! is_array($plan['written'] ?? null)) {
            return;
        }

        $written = self::written($plan);
        $texts = [];

        foreach (is_array($plan['events'] ?? null) ? $plan['events'] : [] as $event) {
            $text = self::pair($written['events'][(int) ($event['id'] ?? 0)] ?? null);

            if (null !== $text) {
                $texts[] = $text['en'];
            }
        }

        $opening = null === ($plan['text'] ?? null) ? self::pair($written['opening']) : null;

        if ([] === $texts && null === $opening) {
            return;
        }

        $memory = self::memory();
        $memory[] = ['opening' => null === $opening ? '' : $opening['en'], 'events' => $texts];

        update_option(self::MEMORY_OPTION, array_slice($memory, -self::MEMORY_SIZE), false);
    }

    /**
     * Reads a JSON answer and returns the string under $key, or null when the
     * answer is not that shape or the text is empty, too long or has a link.
     */
    public static function parse(string $content, string $key): ?string
    {
        $content = trim($content);

        // Some models wrap JSON in a Markdown code fence.
        if (1 === preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $content, $matches)) {
            $content = $matches[1];
        }

        $decoded = json_decode($content, true);

        // Or put a sentence before or after it.
        if (! is_array($decoded) && 1 === preg_match('/\{.*\}/s', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
        }

        if (! is_array($decoded) || ! is_string($decoded[$key] ?? null)) {
            return null;
        }

        $text = trim(str_replace(["\r\n", "\r"], "\n", $decoded[$key]));
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);

        if ('' === $text || $length > self::MAX_TEXT || 1 === preg_match('~https?://|www\.~i', $text)) {
            return null;
        }

        return $text;
    }

    /**
     * The event's text in both languages, or null.
     *
     * @param array<string, mixed> $event A plan event.
     * @return array{fi: string, en: string}|null
     */
    private function writeEvent(array $event): ?array
    {
        $en = $this->ask(
            self::system(self::EVENT_PROMPT, true, '{"text": "the English text"}'),
            self::eventInput($event),
            'text'
        );

        return null === $en ? null : $this->withFinnish($en);
    }

    /**
     * The opening text in both languages, or null.
     *
     * Each event's own written text goes with it, so the model takes the
     * place from there and not from a DJ name in the title (Lou, 2026-11-03).
     *
     * @param array<int, array<string, mixed>> $events The plan's events.
     * @param array<int, array<string, string>> $texts Event id => its written text ([] = failed).
     * @return array{fi: string, en: string}|null
     */
    private function writeOpening(array $events, array $texts = []): ?array
    {
        $lines = ['The events in this newsletter:'];

        foreach ($events as $event) {
            $id = (int) ($event['id'] ?? 0);
            $names = array_column(self::djs($id), 'name');
            $lines[] = '- ' . self::when($event) . ': ' . self::titles($event)
                . ([] === $names ? '' : '. DJs (people who play the music, not places): ' . implode(', ', $names));
            $text = trim((string) ($texts[$id]['en'] ?? ''));

            if ('' !== $text) {
                $lines[] = '  Text about this event: ' . $text;
            }
        }

        $en = $this->ask(
            self::system(self::OPENING_PROMPT, true, '{"text": "the English text"}'),
            implode("\n", $lines),
            'text'
        );

        return null === $en ? null : $this->withFinnish($en);
    }

    /**
     * $en with its Finnish translation, or null.
     *
     * @return array{fi: string, en: string}|null
     */
    private function withFinnish(string $en): ?array
    {
        $fi = $this->ask(self::system(self::TRANSLATE_PROMPT, false, '{"fi": "the Finnish text"}'), $en, 'fi');

        return null === $fi ? null : ['fi' => $fi, 'en' => $en];
    }

    /** One request; the text under $key of its answer, or null. */
    private function ask(string $system, string $input, string $key): ?string
    {
        $answer = $this->llm->chat(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $input],
            ],
            self::MAX_TOKENS,
            LlmClient::TIMEOUT
        );

        if (! $answer['ok']) {
            return null;
        }

        $text = self::parse($answer['content'], $key);

        if (null === $text) {
            $this->logger->warning('Newsletter texts: the language model answer could not be used.');
        }

        return $text;
    }

    /**
     * The system message: $prompt, the site's words, the earlier texts when
     * $withMemory, and the answer's JSON shape.
     */
    private static function system(string $prompt, bool $withMemory, string $shape): string
    {
        $parts = [$prompt];
        $words = self::words();

        if ('' !== $words) {
            $parts[] = "The site's words and style. Prefer these words and phrases where they fit:\n" . $words;
        }

        if ($withMemory) {
            $earlier = [];

            foreach (self::memory() as $number => $entry) {
                $lines = ['Newsletter ' . ($number + 1) . ':'];

                if ('' !== $entry['opening']) {
                    $lines[] = 'Opening: ' . $entry['opening'];
                }

                foreach ($entry['events'] as $text) {
                    $lines[] = 'Event text: ' . $text;
                }

                $earlier[] = implode("\n", $lines);
            }

            if ([] !== $earlier) {
                $parts[] = 'Texts written for earlier newsletters. '
                    . "Do not reuse their openings, phrases or sentence structure:\n"
                    . implode("\n\n", $earlier);
            }
        }

        $parts[] = 'Answer with JSON only, with no other text: ' . $shape;

        return implode("\n\n", $parts);
    }

    /**
     * What the model is told about one event.
     *
     * @param array<string, mixed> $event A plan event.
     */
    private static function eventInput(array $event): string
    {
        $id = (int) ($event['id'] ?? 0);
        $card = EventSource::card($id);
        $lines = ['Event: ' . self::titles($event), 'Date: ' . self::when($event)];
        $descriptions = [
            'Description (Finnish)' => (string) ($card['excerpt_fi'] ?? ''),
            'Description (English)' => (string) ($card['excerpt_en'] ?? ''),
            'Description' => (string) ($card['excerpt'] ?? ''),
        ];

        foreach ($descriptions as $label => $text) {
            $text = trim($text);

            if ('' !== $text) {
                $lines[] = '';
                $lines[] = $label . ":\n" . self::cut($text, self::MAX_DESCRIPTION);
            }
        }

        $djs = self::djs($id);

        if ([] !== $djs) {
            $lines[] = '';
            $lines[] = 'DJs (people who play the music, not places):';

            foreach ($djs as $dj) {
                $lines[] = '- ' . $dj['name'] . ('' === $dj['description'] ? '' : ': ' . $dj['description']);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The event's DJs from EventMesh, each with a name; [] when EventMesh does
     * not answer. Anything that is not a string is left out.
     *
     * @return list<array{name: string, description: string}>
     */
    private static function djs(int $postId): array
    {
        $listed = $postId > 0 ? apply_filters(self::DJ_FILTER, [], $postId) : [];
        $djs = [];

        foreach (is_array($listed) ? $listed : [] as $dj) {
            if (! is_array($dj) || ! is_string($dj['name'] ?? null) || '' === trim($dj['name'])) {
                continue;
            }

            $description = is_string($dj['description'] ?? null) ? trim($dj['description']) : '';
            $djs[] = [
                'name' => trim($dj['name']),
                'description' => self::cut($description, self::MAX_DJ_DESCRIPTION),
            ];
        }

        return $djs;
    }

    /**
     * "Summer Social (Finnish title: Kesätanssit)".
     *
     * @param array<string, mixed> $event
     */
    private static function titles(array $event): string
    {
        $title = self::plain((string) ($event['title'] ?? ''));
        $titleFi = self::plain((string) ($event['title_fi'] ?? ''));

        return $title . ('' !== $titleFi && $titleFi !== $title ? ' (Finnish title: ' . $titleFi . ')' : '');
    }

    /**
     * "Monday 27 July 2026, 21:00".
     *
     * @param array<string, mixed> $event
     */
    private static function when(array $event): string
    {
        $date = (string) ($event['date'] ?? '');
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $time = (string) ($event['time'] ?? '');

        return (false === $day ? $date : $day->format('l j F Y')) . ('' === $time ? '' : ', ' . $time);
    }

    /**
     * The plan's 'written' in a known shape: event id => text ([] = failed),
     * and the opening (null = not asked yet, [] = failed).
     *
     * @param array<string, mixed> $plan
     * @return array{events: array<int, array<string, string>>, opening: array<string, string>|null}
     */
    private static function written(array $plan): array
    {
        $written = is_array($plan['written'] ?? null) ? $plan['written'] : [];

        return [
            'events' => is_array($written['events'] ?? null) ? $written['events'] : [],
            'opening' => is_array($written['opening'] ?? null) ? $written['opening'] : null,
        ];
    }

    /**
     * A stored text when both languages are there, else null.
     *
     * @return array{fi: string, en: string}|null
     */
    private static function pair(mixed $text): ?array
    {
        if (! is_array($text)) {
            return null;
        }

        $fi = trim((string) ($text['fi'] ?? ''));
        $en = trim((string) ($text['en'] ?? ''));

        return '' === $fi || '' === $en ? null : ['fi' => $fi, 'en' => $en];
    }

    /**
     * Runs $work; a throwable is logged and read as a failure, so the
     * newsletter never stops because of the model.
     */
    private function safely(callable $work): ?array
    {
        try {
            return $work();
        } catch (\Throwable $e) {
            $this->logger->warning('Newsletter texts: writing a text failed (' . get_class($e) . ').');

            return null;
        }
    }

    /** Whether this run is in the background: WP-Cron or WP-CLI, not a visitor's request. */
    private static function inBackground(): bool
    {
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        return function_exists('wp_doing_cron') && wp_doing_cron();
    }

    private static function plain(string $text): string
    {
        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function cut(string $text, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $length) : substr($text, 0, $length);
    }
}

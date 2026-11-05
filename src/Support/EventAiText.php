<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Telegram\BoardDjText;

/**
 * Texts the language model writes for one event: a message template's own
 * text ("Write a new text with the AI for each event") and the organizers' own
 * placeholders, such as {dj_intro}.
 *
 * Templates. A message template (an email or Telegram message kind) has a
 * settings array; two keys belong to this class:
 * - SETTING_AI_EACH_EVENT (bool): write a new text with the AI for each event.
 * - SETTING_AI_PROMPT (string): what the AI is asked to write.
 * SETTING_LABEL (string, optional) is the name shown in the lists. The
 * templates are listed by the TEMPLATES_FILTER filter: [kind => settings].
 * generate() also takes one template's settings directly. A sender asks
 * text($kind, $eventId, $lang, $settings) for the text to send.
 *
 * Placeholders. PLACEHOLDERS_OPTION keeps the organizers' own placeholders:
 * [['name' => 'dj_intro', 'prompt' => …, 'fallback' => …], …]. Each is
 * written for each event, and fill() puts it into a text in place of {name}.
 *
 * When (Q291 = preview). The texts for an event are written the day before
 * the event by the hourly heartbeat (Scheduler::HOOK, in the background only),
 * and kept in TEXTS_OPTION by event and by template or placeholder. The
 * organizers see them in wp-admin (EventCrew > AI prompts) and in the crew
 * app's Admin tab, and can change them there. A text changed by hand is kept
 * apart from the AI's text and wins over it. A text nobody changed is sent as
 * the AI wrote it. Nothing asks the model while a message is sent: text()
 * only reads what is stored.
 *
 * Failure. When the model is not set up, fails or answers with something
 * that cannot be used, nothing is stored for that text and text() gives null,
 * so the sender uses the template's normal text; a placeholder becomes its
 * fallback text. A failed text is asked again on later runs, at most
 * MAX_TRIES times.
 *
 * Languages. The model writes each text in every site language
 * (TranslatedText::LANGUAGES) in one request.
 */
final class EventAiText
{
    /** The filter that lists the message templates: [kind => settings array]. */
    public const TEMPLATES_FILTER = 'eventcrew/ai_templates';

    /** Template setting: true to write a new text with the AI for each event. */
    public const SETTING_AI_EACH_EVENT = 'ai_each_event';

    /** Template setting: the prompt for the AI. */
    public const SETTING_AI_PROMPT = 'ai_prompt';

    /** Template setting: the template's name in the lists (optional). */
    public const SETTING_LABEL = 'label';

    /** The organizers' own placeholders: list of {name, prompt, fallback}. */
    public const PLACEHOLDERS_OPTION = 'eventcrew_ai_placeholders';

    /**
     * The written texts: [event id => ['date' => Y-m-d, 'title' => …,
     * 'items' => [key => entry]]]. The key is the template kind, or
     * PLACEHOLDER_PREFIX plus the placeholder's name. An entry is
     * ['ai' => [lang => text], 'edited' => [lang => text], 'tries' => int,
     * 'at' => when the AI wrote it, 'edited_by' => person id].
     */
    public const TEXTS_OPTION = 'eventcrew_event_ai_texts';

    /** The key prefix of a placeholder's text. */
    public const PLACEHOLDER_PREFIX = 'placeholder:';

    /** How many days before the event the texts are written. */
    public const DAYS_BEFORE = 1;

    /** How many times a failed text is asked for. */
    public const MAX_TRIES = 3;

    /** How many events' texts are kept. */
    public const KEEP = 20;

    /** How many placeholders an organizer can add. */
    public const MAX_PLACEHOLDERS = 20;

    /** The longest text accepted, in characters, per language. */
    public const MAX_TEXT = 2000;

    /** The longest prompt or fallback kept, in characters. */
    public const MAX_PROMPT = 4000;

    /** Seconds one run may spend writing; what is left waits for the next run. */
    public const BUDGET = 120;

    /** saveForm(): the texts are kept as changed by hand. */
    public const SAVED = 'saved';

    /** saveForm(): the changes are removed; the AI's text is used again. */
    public const BACK_TO_AI = 'back_to_ai';

    /** saveForm(): no such event or text. */
    public const NOT_FOUND = 'not_found';

    /** Tokens for one answer. */
    private const MAX_TOKENS = 1500;

    /** The language names the model is told. */
    private const NAMES = [
        Language::FINNISH => 'Finnish',
        Language::ENGLISH => 'English',
    ];

    public function __construct(
        private readonly LlmClient $llm,
        private readonly Logger $logger
    ) {
    }

    public function boot(): void
    {
        // After the heartbeat's own work: writing can take a while.
        add_action(Scheduler::HOOK, [$this, 'run'], 20);
    }

    /**
     * The heartbeat: writes the missing texts for the events of tomorrow (the
     * day before) and, when a run was missed, of today. Does nothing outside
     * a background run or when the model is not set up.
     */
    public function run(): void
    {
        if (! AssistantSettings::isConfigured() || ! self::inBackground()) {
            return;
        }

        if ([] === self::activeTemplates() && [] === self::placeholders()) {
            return;
        }

        $started = microtime(true);

        foreach (self::dueEvents() as $event) {
            if (microtime(true) - $started >= self::BUDGET) {
                break;
            }

            $this->writeFor($event['id'], $started);
        }
    }

    /**
     * The events whose texts are written now: those on today's date or on
     * the date DAYS_BEFORE days ahead, earliest first.
     *
     * @return list<array{id: int, title: string, starts_at: string, ends_at: string, date: string}>
     */
    public static function dueEvents(): array
    {
        $today = (string) current_time('Y-m-d');
        $last = gmdate('Y-m-d', (int) strtotime($today . ' +' . self::DAYS_BEFORE . ' day'));
        $due = [];

        foreach (EventSource::upcoming() as $event) {
            if ('' !== $event['date'] && $event['date'] >= $today && $event['date'] <= $last) {
                $due[] = $event;
            }
        }

        return $due;
    }

    /**
     * Writes every missing text of $eventId: one per template with the AI
     * tick on, and one per placeholder. A text that is stored (written or
     * changed by hand) is not asked again. Returns how many were written.
     */
    public function writeFor(int $eventId, ?float $started = null): int
    {
        $event = EventSource::describe($eventId);

        if (null === $event) {
            return 0;
        }

        $started ??= microtime(true);
        $jobs = [];

        foreach (self::activeTemplates() as $kind => $settings) {
            $jobs[(string) $kind] = (string) $settings[self::SETTING_AI_PROMPT];
        }

        foreach (self::placeholders() as $placeholder) {
            $jobs[self::PLACEHOLDER_PREFIX . $placeholder['name']] = $placeholder['prompt'];
        }

        $written = 0;

        foreach ($jobs as $key => $prompt) {
            $entry = self::entry($eventId, $key);

            if ([] !== $entry['ai'] || [] !== $entry['edited'] || $entry['tries'] >= self::MAX_TRIES) {
                continue;
            }

            if (microtime(true) - $started >= self::BUDGET) {
                break;
            }

            $texts = $this->ask($prompt, $event);
            $entry['tries']++;

            if (null !== $texts) {
                $entry['ai'] = $texts;
                $entry['at'] = (string) current_time('mysql');
                $written++;
            }

            self::store($event, $key, $entry);
        }

        return $written;
    }

    /**
     * One template's text for $eventId, written now: [lang => text], or null
     * when the template's AI tick is off, its prompt is empty, the event is
     * not known or the model failed. Without $settings, the template's
     * settings come from TEMPLATES_FILTER. Nothing is stored; writeFor() is
     * what stores the texts under each template's kind.
     *
     * @param array<string, mixed>|null $settings The template's settings.
     * @return array<string, string>|null
     */
    public function generate(string $kind, int $eventId, ?array $settings = null): ?array
    {
        $settings ??= self::templates()[$kind] ?? [];

        if (! self::wantsAi($settings)) {
            return null;
        }

        $event = EventSource::describe($eventId);

        return null === $event ? null : $this->ask((string) $settings[self::SETTING_AI_PROMPT], $event);
    }

    /**
     * The text to send for template $kind and $eventId in $lang: the text
     * changed by hand, else the AI's text. Null when there is neither (the AI
     * failed, or did not write yet) or, with $settings, when the template's AI
     * tick is off; the sender then uses the template's normal text.
     *
     * @param array<string, mixed>|null $settings The template's settings.
     */
    public static function text(string $kind, int $eventId, string $lang, ?array $settings = null): ?string
    {
        if (null !== $settings && ! self::wantsAi($settings)) {
            return null;
        }

        return self::current($eventId, $kind, $lang);
    }

    /**
     * $text with each organizer placeholder ({name}) replaced by its text for
     * $eventId in $lang; when there is none, by the placeholder's fallback.
     */
    public static function fill(string $text, int $eventId, string $lang): string
    {
        $pairs = [];

        foreach (self::placeholders() as $placeholder) {
            $tag = '{' . $placeholder['name'] . '}';

            if (! str_contains($text, $tag)) {
                continue;
            }

            $pairs[$tag] = self::current($eventId, self::PLACEHOLDER_PREFIX . $placeholder['name'], $lang)
                ?? $placeholder['fallback'];
        }

        return [] === $pairs ? $text : strtr($text, $pairs);
    }

    /**
     * Keeps $text as the hand-changed text of $key for $eventId in $lang. An
     * empty text removes the change, so the AI's text is used again. Returns
     * false when the event or the key is not known.
     */
    public static function saveEdit(int $eventId, string $key, string $lang, string $text, int $personId = 0): bool
    {
        $event = EventSource::describe($eventId);

        if (null === $event || ! in_array($lang, TranslatedText::LANGUAGES, true) || ! self::knownKey($key)) {
            return false;
        }

        $entry = self::entry($eventId, $key);
        $text = self::clean($text, self::MAX_TEXT);

        // The AI's own text saved back unchanged is not a change by hand.
        if ('' === $text || $text === trim($entry['ai'][$lang] ?? '')) {
            unset($entry['edited'][$lang]);
        } else {
            $entry['edited'][$lang] = $text;
            $entry['edited_by'] = $personId;
        }

        self::store($event, $key, $entry);

        return true;
    }

    /**
     * The edit form of one text, in wp-admin and in the crew app: $texts by
     * language are kept as the texts changed by hand; with $backToAi, the
     * changes are removed and the AI's text is used again. Returns SAVED,
     * BACK_TO_AI or NOT_FOUND.
     *
     * @param array<string, string> $texts
     */
    public static function saveForm(int $eventId, string $key, bool $backToAi, array $texts, int $personId = 0): string
    {
        $saved = false;

        foreach (TranslatedText::LANGUAGES as $lang) {
            if (! $backToAi && ! isset($texts[$lang])) {
                continue;
            }

            $saved = self::saveEdit($eventId, $key, $lang, $backToAi ? '' : $texts[$lang], $personId) || $saved;
        }

        if (! $saved) {
            return self::NOT_FOUND;
        }

        return $backToAi ? self::BACK_TO_AI : self::SAVED;
    }

    /**
     * The organizers' placeholders.
     *
     * @return list<array{name: string, prompt: string, fallback: string}>
     */
    public static function placeholders(): array
    {
        $stored = get_option(self::PLACEHOLDERS_OPTION, []);
        $list = [];

        foreach (is_array($stored) ? $stored : [] as $row) {
            $clean = is_array($row) ? self::cleanPlaceholder($row) : null;

            if (null !== $clean) {
                $list[] = $clean;
            }
        }

        return $list;
    }

    /**
     * Keeps $rows as the placeholders. A row with no valid name or no prompt
     * is left out, and so is a second row with the same name.
     *
     * @param array<int|string, mixed> $rows
     * @return list<array{name: string, prompt: string, fallback: string}> What was kept.
     */
    public static function savePlaceholders(array $rows): array
    {
        $kept = [];

        foreach ($rows as $row) {
            $clean = is_array($row) ? self::cleanPlaceholder($row) : null;

            if (null !== $clean && ! isset($kept[$clean['name']]) && count($kept) < self::MAX_PLACEHOLDERS) {
                $kept[$clean['name']] = $clean;
            }
        }

        update_option(self::PLACEHOLDERS_OPTION, array_values($kept), false);

        return array_values($kept);
    }

    /**
     * The templates with the AI tick on and a prompt, by kind.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function activeTemplates(): array
    {
        $active = [];

        foreach (self::templates() as $kind => $settings) {
            if (self::wantsAi($settings)) {
                $active[$kind] = $settings;
            }
        }

        return $active;
    }

    /**
     * Every template the TEMPLATES_FILTER lists, by kind.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function templates(): array
    {
        $listed = apply_filters(self::TEMPLATES_FILTER, []);
        $templates = [];

        foreach (is_array($listed) ? $listed : [] as $kind => $settings) {
            if (is_string($kind) && '' !== $kind && is_array($settings)) {
                $templates[$kind] = $settings;
            }
        }

        return $templates;
    }

    /**
     * What the organizers see: the coming events with stored texts, earliest
     * first, each with its texts. Each text has its key, its label, the AI's
     * text and the text to send in each language, and whether it was changed
     * by hand or failed.
     *
     * @return list<array{
     *     event_id: int,
     *     date: string,
     *     title: string,
     *     items: list<array{
     *         key: string,
     *         label: string,
     *         ai: array<string, string>,
     *         texts: array<string, string>,
     *         edited: array<string, bool>,
     *         failed: bool
     *     }>
     * }>
     */
    public static function overview(): array
    {
        $today = (string) current_time('Y-m-d');
        $labels = self::labels();
        $list = [];

        foreach (self::stored() as $eventId => $row) {
            if ($row['date'] < $today) {
                continue;
            }

            $items = [];

            foreach ($row['items'] as $key => $entry) {
                $texts = [];
                $edited = [];

                foreach (TranslatedText::LANGUAGES as $lang) {
                    $edited[$lang] = isset($entry['edited'][$lang]);
                    $texts[$lang] = $entry['edited'][$lang] ?? $entry['ai'][$lang] ?? '';
                }

                $items[] = [
                    'key' => $key,
                    'label' => $labels[$key] ?? $key,
                    'ai' => $entry['ai'],
                    'texts' => $texts,
                    'edited' => $edited,
                    'failed' => [] === $entry['ai'],
                ];
            }

            if ([] !== $items) {
                $list[] = ['event_id' => $eventId, 'date' => $row['date'], 'title' => $row['title'], 'items' => $items];
            }
        }

        usort($list, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return $list;
    }

    /**
     * The name of each key in the lists: the template's label (or kind) and
     * "{name}" for a placeholder.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::templates() as $kind => $settings) {
            $label = is_string($settings[self::SETTING_LABEL] ?? null) ? trim($settings[self::SETTING_LABEL]) : '';
            $labels[$kind] = '' === $label ? $kind : $label;
        }

        foreach (self::placeholders() as $placeholder) {
            $labels[self::PLACEHOLDER_PREFIX . $placeholder['name']] = '{' . $placeholder['name'] . '}';
        }

        return $labels;
    }

    /**
     * Reads a JSON answer with one text per site language. Null when the
     * answer is not that shape, or a text is missing, empty, too long, has a
     * link or a word the crew does not use.
     *
     * @return array<string, string>|null
     */
    public static function parse(string $content): ?array
    {
        $content = trim($content);

        // Some models wrap JSON in a Markdown code fence, or put a sentence around it.
        if (1 === preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $content, $matches)) {
            $content = $matches[1];
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded) && 1 === preg_match('/\{.*\}/s', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
        }

        if (! is_array($decoded)) {
            return null;
        }

        $texts = [];

        foreach (TranslatedText::LANGUAGES as $lang) {
            $text = is_string($decoded[$lang] ?? null) ? trim(str_replace(["\r\n", "\r"], "\n", $decoded[$lang])) : '';
            $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);

            if (
                '' === $text
                || $length > self::MAX_TEXT
                || 1 === preg_match('~https?://|www\.~i', $text)
                || 1 === preg_match('/vol' . 'unteer|\bsh' . 'ift|vu' . 'oro|vapaa' . 'ehto/iu', $text)
            ) {
                return null;
            }

            $texts[$lang] = $text;
        }

        return $texts;
    }

    /**
     * One request for $prompt about $event: [lang => text], or null.
     *
     * @param array{id: int, title: string, starts_at: string, ends_at: string, date: string} $event
     * @return array<string, string>|null
     */
    private function ask(string $prompt, array $event): ?array
    {
        $prompt = trim(AiPrompts::expand($prompt));

        if ('' === $prompt) {
            return null;
        }

        try {
            $answer = $this->llm->chat(
                [
                    ['role' => 'system', 'content' => self::system($prompt)],
                    ['role' => 'user', 'content' => self::input($event)],
                ],
                self::MAX_TOKENS,
                LlmClient::TIMEOUT
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Event AI text: writing a text failed (' . get_class($e) . ').');

            return null;
        }

        if (! $answer['ok']) {
            return null;
        }

        $texts = self::parse((string) $answer['content']);

        if (null === $texts) {
            $this->logger->warning('Event AI text: the language model answer could not be used.');
        }

        return $texts;
    }

    /** The system message: the prompt, the site's words, the languages and the answer's JSON shape. */
    private static function system(string $prompt): string
    {
        $parts = [$prompt];
        $words = NewsletterWriter::words();

        if ('' !== $words) {
            $parts[] = "The site's words and style. Prefer these words and phrases where they fit:\n" . $words;
        }

        $names = [];
        $shape = [];

        foreach (TranslatedText::LANGUAGES as $lang) {
            $name = self::NAMES[$lang] ?? $lang;
            $names[] = $name . ' (' . $lang . ')';
            $shape[$lang] = 'the ' . $name . ' text';
        }

        $parts[] = 'Write the text in each of these languages, with the same meaning: ' . implode(', ', $names) . '.';
        $parts[] = 'Answer with JSON only, with no other text: ' . (string) json_encode($shape, JSON_UNESCAPED_UNICODE);

        return implode("\n\n", $parts);
    }

    /**
     * What the model is told about $event: its date and time, then its name,
     * DJs and description (BoardDjText::source()).
     *
     * @param array{id: int, title: string, starts_at: string, ends_at: string, date: string} $event
     */
    private static function input(array $event): string
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $event['date']);
        $time = '' === $event['starts_at'] ? '' : substr($event['starts_at'], 11, 5);
        $when = (false === $day ? $event['date'] : $day->format('l j F Y')) . ('' === $time ? '' : ', ' . $time);
        $source = BoardDjText::source($event['id']);

        return 'Date: ' . $when . "\n" . ('' === $source ? 'Event: ' . trim($event['title']) : $source);
    }

    /** The text to send for $key: changed by hand, else the AI's; null when neither. */
    private static function current(int $eventId, string $key, string $lang): ?string
    {
        $entry = self::entry($eventId, $key);
        $text = $entry['edited'][$lang] ?? $entry['ai'][$lang] ?? '';

        return '' === $text ? null : $text;
    }

    /** @param array<string, mixed> $settings */
    private static function wantsAi(array $settings): bool
    {
        return (bool) ($settings[self::SETTING_AI_EACH_EVENT] ?? false)
            && is_string($settings[self::SETTING_AI_PROMPT] ?? null)
            && '' !== trim($settings[self::SETTING_AI_PROMPT]);
    }

    private static function knownKey(string $key): bool
    {
        return isset(self::labels()[$key]);
    }

    /**
     * The stored entry of $key for $eventId, in a known shape.
     *
     * @return array{ai: array<string, string>, edited: array<string, string>, tries: int, at: string, edited_by: int}
     */
    private static function entry(int $eventId, string $key): array
    {
        $stored = self::stored();

        return $stored[$eventId]['items'][$key] ?? self::blank();
    }

    /** @return array{ai: array<string, string>, edited: array<string, string>, tries: int, at: string, edited_by: int} */
    private static function blank(): array
    {
        return ['ai' => [], 'edited' => [], 'tries' => 0, 'at' => '', 'edited_by' => 0];
    }

    /**
     * Keeps $entry as $key's for $event, and the last KEEP events.
     *
     * @param array{id: int, title: string, date: string} $event
     * @param array{ai: array<string, string>, edited: array<string, string>, tries: int, at: string, edited_by: int} $entry
     */
    private static function store(array $event, string $key, array $entry): void
    {
        $stored = self::stored();
        $row = $stored[$event['id']] ?? ['date' => '', 'title' => '', 'items' => []];
        $row['date'] = $event['date'];
        $row['title'] = $event['title'];
        $row['items'][$key] = $entry;
        $stored[$event['id']] = $row;

        uasort($stored, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        update_option(self::TEXTS_OPTION, array_slice($stored, -self::KEEP, null, true), false);
    }

    /**
     * TEXTS_OPTION in a known shape.
     *
     * @return array<int, array{date: string, title: string, items: array<string, array{ai: array<string, string>, edited: array<string, string>, tries: int, at: string, edited_by: int}>}>
     */
    private static function stored(): array
    {
        $stored = get_option(self::TEXTS_OPTION, []);
        $clean = [];

        foreach (is_array($stored) ? $stored : [] as $eventId => $row) {
            if ((int) $eventId <= 0 || ! is_array($row)) {
                continue;
            }

            $items = [];

            foreach (is_array($row['items'] ?? null) ? $row['items'] : [] as $key => $entry) {
                if (! is_string($key) || ! is_array($entry)) {
                    continue;
                }

                $items[$key] = [
                    'ai' => self::strings($entry['ai'] ?? null),
                    'edited' => self::strings($entry['edited'] ?? null),
                    'tries' => (int) ($entry['tries'] ?? 0),
                    'at' => is_string($entry['at'] ?? null) ? $entry['at'] : '',
                    'edited_by' => (int) ($entry['edited_by'] ?? 0),
                ];
            }

            $clean[(int) $eventId] = [
                'date' => is_string($row['date'] ?? null) ? $row['date'] : '',
                'title' => is_string($row['title'] ?? null) ? $row['title'] : '',
                'items' => $items,
            ];
        }

        return $clean;
    }

    /** @return array<string, string> The non-empty strings of $value by language. */
    private static function strings(mixed $value): array
    {
        $strings = [];

        foreach (is_array($value) ? $value : [] as $lang => $text) {
            if (is_string($lang) && is_string($text) && '' !== trim($text)) {
                $strings[$lang] = $text;
            }
        }

        return $strings;
    }

    /**
     * $row as a placeholder, or null when its name is not lower-case letters,
     * digits and _ (starting with a letter, at most 40) or it has no prompt.
     *
     * @param array<mixed> $row
     * @return array{name: string, prompt: string, fallback: string}|null
     */
    private static function cleanPlaceholder(array $row): ?array
    {
        $name = strtolower(trim(is_string($row['name'] ?? null) ? $row['name'] : '', " \t{}"));
        $prompt = self::clean(is_string($row['prompt'] ?? null) ? $row['prompt'] : '', self::MAX_PROMPT);
        $fallback = self::clean(is_string($row['fallback'] ?? null) ? $row['fallback'] : '', self::MAX_TEXT);

        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name) || '' === $prompt) {
            return null;
        }

        return ['name' => $name, 'prompt' => $prompt, 'fallback' => $fallback];
    }

    private static function clean(string $text, int $length): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        return function_exists('mb_substr') ? mb_substr($text, 0, $length) : substr($text, 0, $length);
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

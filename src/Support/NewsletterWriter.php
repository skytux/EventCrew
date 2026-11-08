<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Telegram\BoardDjText;

/**
 * The texts the language model writes for the next events announcement.
 *
 * When the switch (ENABLED_OPTION) is on and the model is set up, the model
 * writes 2–3 sentences for each event and, when the organizers wrote no
 * opening text, a 2–3 sentence opening for the email, shown at the top above
 * the event cards. The cards do not show the event texts (Owner, 2026-11-03);
 * the opening is written from them, so the place is taken from the event's
 * own text and not from a DJ name.
 *
 * The opening builds on the first coming event's short text about its DJ or
 * host (BoardDjText, as EventDescription::short() gives it: the text the
 * post-event email shows under that event's picture), so the two emails
 * speak of the DJ the same way (Owner, 2026-11-08). The model gets that text
 * in Finnish (when there is one) and in English, with the instruction
 * (OPENING_DJ) to include its phrasing and keep its names exactly; the
 * translation of the opening gets the DJ text in each target language
 * (TRANSLATE_DJ), so the translated opening uses the same wording. The DJ
 * text and its translations must exist first: while they are missing the
 * opening is not written in that run (fill() asks for them,
 * BoardDjText::schedule(), and notes when it started waiting in the plan's
 * 'written' as 'dj_wait'), and a later heartbeat run writes it. It is
 * written without the DJ text (or with what there is of it) when there is
 * nothing to write that text from, or after DJ_WAIT seconds of waiting.
 *
 * The model also picks each event's practical information out of its
 * description: the venue, the street address and the times the doors open and
 * close. A value is kept only when it is written in the description as it is
 * (practical()), so nothing is invented; a DJ name is never the venue. The
 * card shows these as short lines under the title.
 *
 * Each announcement text is written in Finnish first (WRITES_IN), because
 * Finnish translates into English better than the other way round (Owner,
 * 2026-11-03), and then translated with a second request into every other
 * site language (SiteLanguages) at once: one JSON answer keyed by the
 * language codes. The post-event email's text (writeFollowup()) is written
 * in English first (FOLLOWUP_WRITES_IN) and translated the same way. A
 * written text is stored as language code => text: the language it was
 * written in and every other site language. A text stored before, with only
 * 'fi' and 'en', reads the same way. The model gets the
 * event (titles, date, time, the whole description), its DJs from EventMesh
 * (the DJ_FILTER filter), the site's own words (WORDS_OPTION) and the Finnish
 * texts it wrote for the last MEMORY_SIZE
 * announcements that were sent (MEMORY_OPTION), so it does not repeat them.
 *
 * The texts are written by Newsletter when it makes the plan for the preview,
 * and kept in the plan under 'written', so the preview shows what the
 * subscribers get and nothing is asked again at send time. Only the heartbeat
 * in the background (WP-Cron or WP-CLI) writes; a heartbeat run inline on a
 * visitor's request (the cron fallback) writes nothing.
 *
 * Any failure (the model is not set up, an error, an answer that cannot be
 * used) leaves that text or that practical information out. Nothing that
 * failed is asked for again in the same plan.
 */
final class NewsletterWriter
{
    /** The switch: '1' when the model writes the texts. On by default. */
    public const ENABLED_OPTION = 'eventcrew_newsletter_write';

    /** The site's words and style for the model, as the organizers wrote them. */
    public const WORDS_OPTION = 'eventcrew_newsletter_words';

    /** The Finnish texts (the language the model writes in) of the last MEMORY_SIZE announcements sent. */
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

    /** The longest venue or address accepted, in characters. */
    public const MAX_PLACE = 120;

    /** The longest English text accepted for the post-event email, in characters ("about 300"). */
    public const MAX_FOLLOWUP_TEXT = 360;

    /** The language the announcement texts are written in before they are translated. */
    public const WRITES_IN = Language::FINNISH;

    /** The language the post-event email's text is written in before it is translated. */
    public const FOLLOWUP_WRITES_IN = Language::ENGLISH;

    /** The keys of an event's practical information, in the order the card shows them. */
    public const PRACTICAL_KEYS = ['venue', 'address', 'doors_open', 'doors_close'];

    /** Seconds the opening waits for the first event's DJ or host text before it is written without it. */
    public const DJ_WAIT = 3 * 3600;

    /** Added to the opening's prompt when the first event has a DJ or host text. */
    public const OPENING_DJ = 'The first event has a short text about its DJ or host, under "DJ text" in the input. '
        . 'Build the opening on that text: include its phrasing in the opening, in Finnish the phrasing of the '
        . 'Finnish DJ text when there is one, and keep every name exactly as it is written there.';

    /** Added to the opening's translation prompt, followed by the DJ text in each language. */
    public const TRANSLATE_DJ = 'The text builds on a short text about the DJ or host of an event, '
        . 'which already has the translations below. Where the text uses the phrasing of that DJ text, '
        . 'use the same wording as its translation in each language, and keep every name exactly as it is.';

    /** Tokens for one answer. */
    private const MAX_TOKENS = 600;

    // The instructions (the rules, each text's prompt and the translations)
    // are AiPrompts: editable in wp-admin, with the former texts built in.

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
     * $plan['written']: one per event that has none yet, the opening when
     * $wantOpening and it was not asked for yet, and then the practical
     * information of each event that has none yet. Unchanged when the switch
     * is off, the model is not set up, or this is not a background run.
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
            $first = (int) (reset($events)['id'] ?? 0);
            $dj = self::djTexts($first, $written['dj_wait']);

            if (null === $dj) {
                // The DJ text comes first: asked for now, the opening on a later run.
                $written['dj_wait'] ??= time();
                BoardDjText::schedule($first);
            } else {
                $written['opening'] = $this->safely(
                    fn (): ?array => $this->writeOpening($events, $written['events'], $dj)
                ) ?? [];
            }
        }

        foreach ($events as $event) {
            $id = (int) ($event['id'] ?? 0);

            if ($id <= 0 || array_key_exists($id, $written['practical'])) {
                continue;
            }

            if (! $inTime()) {
                break;
            }

            $written['practical'][$id] = $this->safely(fn (): ?array => $this->writePractical($event)) ?? [];
        }

        if (null === $written['dj_wait']) {
            unset($written['dj_wait']);
        }

        $plan['written'] = $written;

        return $plan;
    }

    /**
     * The post-event email's text about $event (the first coming event) in
     * every site language, and its practical information: ['text' =>
     * language code => text, or [] when it failed, 'practical' => the four values or [] when that
     * failed]. Null when nothing may be written now: the switch is off, the
     * model is not set up, or this is not a background run. The text is 2
     * short sentences in the style of the event's DJs, with what is useful
     * from the description, under about 300 characters.
     *
     * @param array<string, mixed> $event A card of the event (Newsletter::withCard()).
     * @return array{text: array<string, string>, practical: array<string, string>}|null
     */
    public function writeFollowup(array $event): ?array
    {
        if (! self::enabled() || ! AssistantSettings::isConfigured() || ! self::inBackground()) {
            return null;
        }

        $text = $this->safely(function () use ($event): ?array {
            $en = $this->ask(
                self::system(AiPrompts::get(AiPrompts::FOLLOWUP), false, '{"text": "the English text"}'),
                self::eventInput($event),
                'text'
            );
            $length = null === $en ? 0 : (function_exists('mb_strlen') ? mb_strlen($en) : strlen($en));

            if (null !== $en && $length > self::MAX_FOLLOWUP_TEXT) {
                $this->logger->warning('Post-event email text: the language model answer was too long.');
                $en = null;
            }

            return null === $en ? null : $this->translated($en, self::FOLLOWUP_WRITES_IN);
        });

        return [
            'text' => $text ?? [],
            'practical' => $this->safely(fn (): ?array => $this->writePractical($event)) ?? [],
        ];
    }

    /**
     * The post-event email's stored text (language code => text), or null
     * when there is none or the switch is off.
     *
     * @return array<string, string>|null
     */
    public static function followupText(mixed $text): ?array
    {
        return self::enabled() ? self::texts($text) : null;
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
            $id = (int) ($event['id'] ?? 0);

            if (! array_key_exists($id, $written['events']) || ! array_key_exists($id, $written['practical'])) {
                return true;
            }
        }

        return $wantOpening && null === $written['opening'];
    }

    /**
     * The written texts of $plan that can be shown: event id => text, and the
     * opening (null when there is none). The practical information is not
     * part of it, so when only that is written, the organizers get no new
     * preview (Newsletter::runLocked()).
     *
     * @param array<string, mixed> $plan
     * @return array{events: array<int, array<string, string>>, opening: array<string, string>|null}
     */
    public static function shown(array $plan): array
    {
        $written = self::written($plan);
        $events = [];

        foreach ($written['events'] as $id => $text) {
            $texts = self::texts($text);

            if (null !== $texts) {
                $events[(int) $id] = $texts;
            }
        }

        return ['events' => $events, 'opening' => self::texts($written['opening'])];
    }

    /**
     * $cards with each written text as written_{language code}, such as
     * written_fi and written_en (the layout does not show them), and each event's practical information as
     * 'practical' (venue, address, doors_open, doors_close; '' when not
     * known), which the layout shows. Unchanged when the switch is off.
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
        $practical = is_array($written['practical'] ?? null) ? $written['practical'] : [];

        foreach ($cards as $index => $card) {
            $id = (int) ($card['id'] ?? 0);
            foreach (self::texts($texts[$id] ?? null) ?? [] as $lang => $text) {
                $cards[$index]['written_' . $lang] = $text;
            }

            $info = self::practicalOf($practical[$id] ?? null);

            if (null !== $info) {
                $cards[$index]['practical'] = $info;
            }
        }

        return $cards;
    }

    /**
     * The written opening text, or null when there is none or the switch is off.
     *
     * @param array<string, mixed>|null $written A plan's 'written'.
     * @return array<string, string>|null Language code => text.
     */
    public static function opening(?array $written): ?array
    {
        return null === $written || ! self::enabled() ? null : self::texts($written['opening'] ?? null);
    }

    /**
     * After $plan was sent: keeps its texts in the language the model writes
     * them in (WRITES_IN), in the memory of the last MEMORY_SIZE announcements.
     * The opening counts only when it was shown (the plan carried no
     * organizers' text).
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
            $text = self::texts($written['events'][(int) ($event['id'] ?? 0)] ?? null);

            if (null !== $text) {
                $texts[] = self::writtenIn($text);
            }
        }

        $opening = null === ($plan['text'] ?? null) ? self::texts($written['opening']) : null;

        if ([] === $texts && null === $opening) {
            return;
        }

        $memory = self::memory();
        $memory[] = ['opening' => null === $opening ? '' : self::writtenIn($opening), 'events' => $texts];

        update_option(self::MEMORY_OPTION, array_slice($memory, -self::MEMORY_SIZE), false);
    }

    /**
     * Reads a JSON answer and returns the string under $key, or null when the
     * answer is not that shape or the text is empty, too long or has a link.
     */
    public static function parse(string $content, string $key): ?string
    {
        $decoded = self::decode($content);

        if (null === $decoded || ! is_string($decoded[$key] ?? null)) {
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
     * Reads the practical information from a JSON answer
     * ({"venue": …, "address": …, "doors_open": …, "doors_close": …}). A
     * value is kept only when one of $descriptions has it as it is written
     * (case and spacing aside), so the model cannot invent one. The venue and
     * the address must not be a DJ name in $djNames or have a code or a link
     * in them; a time must look like 18:30 or 18.30. Anything else becomes ''.
     * Null when the answer is not JSON.
     *
     * @param list<string> $descriptions
     * @param list<string> $djNames
     * @return array{venue: string, address: string, doors_open: string, doors_close: string}|null
     */
    public static function practical(string $content, array $descriptions, array $djNames): ?array
    {
        $decoded = self::decode($content);

        if (null === $decoded) {
            return null;
        }

        $haystack = self::normal(implode("\n", $descriptions));
        $djs = array_map([self::class, 'normal'], $djNames);
        $info = [];

        foreach (self::PRACTICAL_KEYS as $key) {
            $value = is_string($decoded[$key] ?? null) ? trim($decoded[$key]) : '';
            $normal = self::normal($value);

            if ('' === $normal || '' === $haystack) {
                $info[$key] = '';
            } elseif ('venue' === $key || 'address' === $key) {
                $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
                $kept = $length <= self::MAX_PLACE
                    && str_contains($haystack, $normal)
                    && ! in_array($normal, $djs, true)
                    && 1 !== preg_match('~https?://|www\.|code|koodi~iu', $value);
                $info[$key] = $kept ? (string) preg_replace('/\s+/u', ' ', $value) : '';
            } else {
                $info[$key] = self::time($value, $haystack);
            }
        }

        return $info;
    }

    /**
     * The event's text in every site language, or null.
     *
     * @param array<string, mixed> $event A plan event.
     * @return array<string, string>|null Language code => text.
     */
    private function writeEvent(array $event): ?array
    {
        $fi = $this->ask(
            self::system(AiPrompts::get(AiPrompts::NEWSLETTER_EVENT), true, '{"text": "the Finnish text"}'),
            self::eventInput($event),
            'text'
        );

        return null === $fi ? null : $this->translated($fi, self::WRITES_IN);
    }

    /**
     * The first event's DJ or host text (EventDescription::short()) by
     * language, for the opening; null while the opening should wait for it:
     * BoardDjText has something to write it from, its English text or a
     * translation is missing, and the opening has waited less than DJ_WAIT
     * ($waitingSince, null = not yet). A missing translation that the
     * translator cannot make (no model for it) is not waited for.
     *
     * @return array<string, string>|null
     */
    private static function djTexts(int $postId, ?int $waitingSince): ?array
    {
        if ($postId <= 0) {
            return [];
        }

        $texts = [];

        foreach (array_unique([self::WRITES_IN, Language::ENGLISH, ...SiteLanguages::codes()]) as $lang) {
            $text = EventDescription::short($postId, $lang);

            if ('' !== $text) {
                $texts[$lang] = $text;
            }
        }

        $english = '' !== BoardDjText::stored($postId);

        if (
            ($english && ([] === BoardDjText::untranslated($postId) || ! TranslatedText::modelReady()))
            || '' === BoardDjText::source($postId)
            || (null !== $waitingSince && time() - $waitingSince >= self::DJ_WAIT)
        ) {
            return $texts;
        }

        return null;
    }

    /**
     * The opening text in every site language, or null.
     *
     * Each event's own written text goes with it, so the model takes the
     * place from there and not from a DJ name in the title (Owner, 2026-11-03).
     * The first event's DJ or host text ($dj, djTexts()) goes with it in
     * Finnish and English, and the opening builds on its phrasing
     * (OPENING_DJ); its translation keeps that phrasing (TRANSLATE_DJ, Owner,
     * 2026-11-08).
     *
     * @param array<int, array<string, mixed>> $events The plan's events.
     * @param array<int, array<string, string>> $texts Event id => its written text ([] = failed).
     * @param array<string, string> $dj Language code => the first event's DJ or host text.
     * @return array<string, string>|null Language code => text.
     */
    private function writeOpening(array $events, array $texts = [], array $dj = []): ?array
    {
        $lines = ['The events in this newsletter:'];

        foreach ($events as $event) {
            $id = (int) ($event['id'] ?? 0);
            $names = array_column(self::djs($id), 'name');
            $lines[] = '- ' . self::when($event) . ': ' . self::titles($event)
                . ([] === $names ? '' : '. DJs (people who play the music, not places): ' . implode(', ', $names));
            $text = trim((string) ($texts[$id][self::WRITES_IN] ?? ''));

            if ('' !== $text) {
                $lines[] = '  Text about this event: ' . $text;
            }
        }

        $prompt = AiPrompts::get(AiPrompts::NEWSLETTER_OPENING);
        $djLines = [];

        foreach ([self::WRITES_IN, Language::ENGLISH] as $lang) {
            if ('' !== trim($dj[$lang] ?? '')) {
                $djLines[] = '- ' . SiteLanguages::name($lang) . ': ' . trim($dj[$lang]);
            }
        }

        if ([] !== $djLines) {
            $prompt .= "\n\n" . self::OPENING_DJ;
            $lines[] = '';
            $lines[] = 'DJ text (the short text about the DJ or host of the first event):';
            array_push($lines, ...$djLines);
        }

        $fi = $this->ask(
            self::system($prompt, true, '{"text": "the Finnish text"}'),
            implode("\n", $lines),
            'text'
        );

        return null === $fi ? null : $this->translated($fi, self::WRITES_IN, [] === $djLines ? [] : $dj);
    }

    /**
     * The event's practical information, or null when the answer could not
     * be used. The input is the same as for the event's text, with the DJ
     * names once more, so the model does not take one for the venue.
     *
     * @param array<string, mixed> $event A plan event.
     * @return array{venue: string, address: string, doors_open: string, doors_close: string}|null
     */
    private function writePractical(array $event): ?array
    {
        $id = (int) ($event['id'] ?? 0);
        $names = array_column(self::djs($id), 'name');
        $input = self::eventInput($event)
            . ([] === $names ? '' : "\n\nDJ names (people, never the venue): " . implode(', ', $names));
        $content = $this->answer(
            AiPrompts::get(AiPrompts::PRACTICAL) . "\n\nAnswer with JSON only, with no other text: "
                . '{"venue": "", "address": "", "doors_open": "HH:MM", "doors_close": "HH:MM"}',
            $input
        );

        if (null === $content) {
            return null;
        }

        $info = self::practical($content, array_values(self::descriptions($id)), $names);

        if (null === $info) {
            $this->logger->warning('Newsletter texts: the language model answer could not be used.');
        }

        return $info;
    }

    /**
     * The venue the model reads from the description of event $postId, as
     * practical() keeps it (written in the description, never a DJ name):
     * '' when the description names none, null when the event is not found
     * or the model failed. Asks the model once; the caller runs it in the
     * background only (VenueReader).
     */
    public function venueOf(int $postId): ?string
    {
        $event = EventSource::describe($postId);

        if (null === $event) {
            return null;
        }

        $plan = [
            'id' => $postId,
            'title' => $event['title'],
            'date' => $event['date'],
            'time' => '' === $event['starts_at'] ? '' : substr($event['starts_at'], 11, 5),
        ];
        $info = $this->safely(fn (): ?array => $this->writePractical($plan));

        return null === $info ? null : (string) ($info['venue'] ?? '');
    }

    /**
     * $text (written in $lang) with its translation into every other site
     * language, from one request whose JSON answer is keyed by the language
     * codes; null when that answer cannot be used. $text is kept under $lang
     * even when $lang is not a site language, because the memory of earlier
     * texts is kept in that language. With no other site language, nothing
     * is asked. $reference (language code => text) is the DJ or host text the
     * text builds on: the request gets it in every language it has, with
     * TRANSLATE_DJ, so the translations use the same wording.
     *
     * @param array<string, string> $reference
     * @return array<string, string>|null Language code => text.
     */
    private function translated(string $text, string $lang, array $reference = []): ?array
    {
        $targets = array_values(array_diff(SiteLanguages::codes(), [$lang]));

        if ([] === $targets) {
            return [$lang => $text];
        }

        $names = [];
        $shape = [];

        foreach ($targets as $code) {
            $name = SiteLanguages::name($code);
            $names[] = $name . ' (' . $code . ')';
            $shape[] = '"' . $code . '": "the text in ' . $name . '"';
        }

        $prompt = strtr(AiPrompts::get(AiPrompts::TRANSLATE), [
            '{from}' => SiteLanguages::name($lang),
            '{to}' => implode(', ', $names),
        ]);
        $known = [];

        foreach ($reference as $code => $about) {
            if ('' !== trim($about)) {
                $known[] = '- ' . SiteLanguages::name((string) $code) . ' (' . $code . '): ' . trim($about);
            }
        }

        if ([] !== $known) {
            $prompt .= "\n\n" . self::TRANSLATE_DJ . "\n" . implode("\n", $known);
        }
        $content = $this->answer(
            self::system($prompt, false, '{' . implode(', ', $shape) . '}'),
            $text,
            self::MAX_TOKENS * count($targets)
        );

        if (null === $content) {
            return null;
        }

        $texts = [$lang => $text];

        foreach ($targets as $code) {
            $translation = self::parse($content, $code);

            if (null === $translation) {
                $this->logger->warning('Newsletter texts: the language model answer could not be used.');

                return null;
            }

            $texts[$code] = $translation;
        }

        // In the site's language order (main language first), as stored before.
        $ordered = [];

        foreach (SiteLanguages::codes() as $code) {
            if (isset($texts[$code])) {
                $ordered[$code] = $texts[$code];
            }
        }

        return $ordered + $texts;
    }

    /** One request; the text under $key of its answer, or null. */
    private function ask(string $system, string $input, string $key): ?string
    {
        $content = $this->answer($system, $input);

        if (null === $content) {
            return null;
        }

        $text = self::parse($content, $key);

        if (null === $text) {
            $this->logger->warning('Newsletter texts: the language model answer could not be used.');
        }

        return $text;
    }

    /** One request; its answer as it came, or null when the request failed. */
    private function answer(string $system, string $input, int $maxTokens = self::MAX_TOKENS): ?string
    {
        $answer = $this->llm->chat(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $input],
            ],
            $maxTokens,
            LlmClient::TIMEOUT
        );

        return $answer['ok'] ? (string) $answer['content'] : null;
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
        $lines = ['Event: ' . self::titles($event), 'Date: ' . self::when($event)];

        foreach (self::descriptions($id) as $label => $text) {
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
     * The event's descriptions from EventMesh, by their label for the model.
     *
     * @return array<string, string>
     */
    private static function descriptions(int $postId): array
    {
        $card = EventSource::card($postId);

        return [
            'Description (Finnish)' => (string) ($card['excerpt_fi'] ?? ''),
            'Description (English)' => (string) ($card['excerpt_en'] ?? ''),
            'Description' => (string) ($card['excerpt'] ?? ''),
        ];
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
     * the opening (null = not asked yet, [] = failed), event id => its
     * practical information ([] = failed), and when the opening started
     * waiting for the first event's DJ text (null = it did not wait).
     *
     * @param array<string, mixed> $plan
     * @return array{
     *     events: array<int, array<string, string>>,
     *     opening: array<string, string>|null,
     *     practical: array<int, array<string, string>>,
     *     dj_wait: int|null
     * }
     */
    private static function written(array $plan): array
    {
        $written = is_array($plan['written'] ?? null) ? $plan['written'] : [];

        return [
            'events' => is_array($written['events'] ?? null) ? $written['events'] : [],
            'opening' => is_array($written['opening'] ?? null) ? $written['opening'] : null,
            'practical' => is_array($written['practical'] ?? null) ? $written['practical'] : [],
            'dj_wait' => is_int($written['dj_wait'] ?? null) ? $written['dj_wait'] : null,
        ];
    }

    /**
     * A stored practical information with every key a string, or null when
     * it failed or knows nothing.
     *
     * @return array{venue: string, address: string, doors_open: string, doors_close: string}|null
     */
    public static function practicalOf(mixed $info): ?array
    {
        if (! is_array($info)) {
            return null;
        }

        $clean = [];

        foreach (self::PRACTICAL_KEYS as $key) {
            $clean[$key] = is_string($info[$key] ?? null) ? trim($info[$key]) : '';
        }

        return '' === implode('', $clean) ? null : $clean;
    }

    /**
     * A stored text as language code => text, each text trimmed and not
     * empty; null when it has none ([] is a text that failed). A text stored
     * with only 'fi' and 'en' reads the same way.
     *
     * @return array<string, string>|null
     */
    public static function texts(mixed $text): ?array
    {
        if (! is_array($text)) {
            return null;
        }

        $texts = [];

        foreach ($text as $lang => $value) {
            if (is_string($lang) && SiteLanguages::isKnown($lang) && is_string($value) && '' !== trim($value)) {
                $texts[$lang] = trim($value);
            }
        }

        return [] === $texts ? null : $texts;
    }

    /**
     * The text of $texts in the language the announcement texts are written
     * in (WRITES_IN), else its first text.
     *
     * @param array<string, string> $texts
     */
    private static function writtenIn(array $texts): string
    {
        return $texts[self::WRITES_IN] ?? (string) reset($texts);
    }

    /** A JSON answer as an array, or null; a code fence or a sentence around it is allowed. */
    private static function decode(string $content): ?array
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

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * $time as "18:30" when it is a time of day that $haystack has (written
     * 18:30, 18.30 or with a leading zero, not part of a longer number),
     * else ''.
     */
    private static function time(string $time, string $haystack): string
    {
        if (1 !== preg_match('/^(\d{1,2})[:.](\d{2})$/', $time, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return '';
        }

        $pattern = '/(?<![\d.:])0?' . (int) $m[1] . '[:.]' . $m[2] . '(?!\d|[.:]\d)/';

        return 1 === preg_match($pattern, $haystack) ? (int) $m[1] . ':' . $m[2] : '';
    }

    /** $text in lower case with every run of white space as one space, for comparing. */
    private static function normal(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
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

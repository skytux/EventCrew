<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Task;

/**
 * An event's name as EventCrew shows it: the clean name EventMesh saved
 * ("Ecstatic Dance", without the date and the DJs that the shop's title
 * carries) and, on a line of its own under it, the DJ line ("DJ
 * Esimerkki", "DJs Aino Testinen & Bo Kokeilu", "DJs A, B & C").
 *
 * EventMesh (1.7.0 and later) gives both through two filters. When it has
 * not written a clean name yet, is older or is absent, the filters answer
 * '' and [], and every place shows the event's title exactly as before,
 * with no DJ line.
 *
 * The language model (NewsletterWriter), image alt texts and stored data
 * keep the original title; only what people read on screen changes.
 */
final class EventName
{
    /** apply_filters(NAME_FILTER, '', int $postId, string $lang): the clean name, '' when none. */
    public const NAME_FILTER = 'eventmesh/event_name';

    /** apply_filters(DJ_FILTER, [], int $postId): the DJs' names, [] when none. */
    public const DJ_FILTER = 'eventmesh/event_dj_names';

    /**
     * The clean name in $lang ('en', 'fi'); the clean name in the other
     * language when $lang has none; $fallback when there is neither.
     */
    public static function name(int $postId, string $lang, string $fallback): string
    {
        $clean = self::clean($postId, $lang);

        if ('' === $clean) {
            $clean = self::clean($postId, Language::FINNISH === $lang ? Language::ENGLISH : Language::FINNISH);
        }

        return '' === $clean ? $fallback : $clean;
    }

    /**
     * "DJ A", "DJs A & B" or "DJs A, B & C"; '' when the event has no DJs
     * or no clean name (the title as written already names its DJs).
     */
    public static function djLine(int $postId): string
    {
        $names = self::djNames($postId);

        if ([] === $names || '' === self::name($postId, Language::ENGLISH, '')) {
            return '';
        }

        if (1 === count($names)) {
            return sprintf(
                /* translators: %s: the name of the event's one DJ. The same in every language. */
                __('DJ %s', 'eventcrew'),
                $names[0]
            );
        }

        $last = array_pop($names);

        return sprintf(
            /* translators: %s: the DJs' names, like "Aino Testinen & Bo Kokeilu". The same in every language. */
            __('DJs %s', 'eventcrew'),
            implode(', ', $names) . ' & ' . $last
        );
    }

    /**
     * The name and the DJ line on one line, for a place that cannot break a
     * line (a Telegram button, a select option): "Ecstatic Dance · DJ
     * Esimerkki". The name alone when the two together are longer than
     * $max characters ($max 0: no limit).
     */
    public static function oneLine(int $postId, string $lang, string $fallback, int $max = 0): string
    {
        $name = self::name($postId, $lang, $fallback);
        $dj = self::djLine($postId);

        if ('' === $dj) {
            return $name;
        }

        $line = $name . ' · ' . $dj;

        return $max > 0 && mb_strlen($line) > $max ? $name : $line;
    }

    /**
     * A task's event: its name in $lang and its DJ line. A task with no
     * linked event keeps its stored name (Task::eventName()) and has no DJ
     * line.
     *
     * @return array{name: string, dj: string}
     */
    public static function ofTask(Task $task, string $lang): array
    {
        if (null === $task->eventPostId) {
            return ['name' => $task->eventName(), 'dj' => ''];
        }

        return [
            'name' => self::name($task->eventPostId, $lang, $task->eventName()),
            'dj' => self::djLine($task->eventPostId),
        ];
    }

    /**
     * The language a page or message is being written in: the one run() set,
     * else WordPress's own, as 'en' or 'fi'.
     */
    public static function lang(): string
    {
        $current = Language::current();

        if (null !== $current) {
            return $current;
        }

        $locale = function_exists('determine_locale') ? (string) determine_locale() : '';

        return str_starts_with(strtolower($locale), Language::FINNISH) ? Language::FINNISH : Language::ENGLISH;
    }

    /** @return list<string> */
    private static function djNames(int $postId): array
    {
        if ($postId <= 0) {
            return [];
        }

        $names = apply_filters(self::DJ_FILTER, [], $postId);

        if (! is_array($names)) {
            return [];
        }

        $clean = [];

        foreach ($names as $name) {
            if (is_string($name) && '' !== trim($name)) {
                $clean[] = trim($name);
            }
        }

        return $clean;
    }

    private static function clean(int $postId, string $lang): string
    {
        if ($postId <= 0) {
            return '';
        }

        $name = apply_filters(self::NAME_FILTER, '', $postId, $lang);

        return is_string($name) ? trim($name) : '';
    }
}

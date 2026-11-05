<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Task;

/**
 * An event's name as EventCrew shows it: the clean name EventMesh saved
 * ("Ecstatic Dance", without the date and the DJs that the shop's title
 * carries) and, on a line of its own under it, the DJ line: the DJs'
 * names alone ("Esimerkki", "Aino Testinen & Bo Kokeilu", "A, B & C").
 *
 * EventMesh (1.7.0 and later) gives both through two filters. When the
 * filters have no clean name, EventMesh's public eventmesh_display_title()
 * (defined only while EventMesh is active) gives the title without its date.
 * When EventMesh is older or absent, every place shows the event's title
 * exactly as before, with no DJ line.
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
     * EventMesh's public function: eventmesh_display_title(int|WP_Post $post,
     * string $lang = ''): string, the clean title in $lang ('' = the current
     * language). Called only when function_exists().
     */
    public const DISPLAY_FUNCTION = 'eventmesh_display_title';

    /**
     * The clean name in $lang ('en', 'fi'); the clean name in the other
     * language when $lang has none; else EventMesh's display title
     * (DISPLAY_FUNCTION) in $lang; $fallback when there is none of these.
     */
    public static function name(int $postId, string $lang, string $fallback): string
    {
        $clean = self::filtered($postId, $lang);

        if ('' === $clean) {
            $clean = self::display($postId, $lang);
        }

        return '' === $clean ? $fallback : $clean;
    }

    /**
     * The DJs' names alone, with no "DJ" before them: "A", "A & B" or
     * "A, B & C"; '' when the event has no DJs or no clean name (the title
     * as written already names its DJs).
     */
    public static function djLine(int $postId): string
    {
        $names = self::djNames($postId);

        if ([] === $names || '' === self::filtered($postId, Language::ENGLISH)) {
            return '';
        }

        if (1 === count($names)) {
            return $names[0];
        }

        $last = array_pop($names);

        return implode(', ', $names) . ' & ' . $last;
    }

    /**
     * The name and the DJ line on one line, for a place that cannot break a
     * line (a Telegram button, a select option): "Ecstatic Dance ·
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

    /** The clean name from the filters in $lang, else in the other language; '' when none. */
    private static function filtered(int $postId, string $lang): string
    {
        $clean = self::clean($postId, $lang);

        return '' !== $clean
            ? $clean
            : self::clean($postId, Language::FINNISH === $lang ? Language::ENGLISH : Language::FINNISH);
    }

    /** EventMesh's display title in $lang, '' when EventMesh does not give one. */
    private static function display(int $postId, string $lang): string
    {
        if ($postId <= 0 || ! function_exists(self::DISPLAY_FUNCTION)) {
            return '';
        }

        $name = call_user_func(self::DISPLAY_FUNCTION, $postId, $lang);

        return is_string($name) ? trim($name) : '';
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

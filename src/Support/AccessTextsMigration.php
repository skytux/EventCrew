<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * One-time changes to the stored texts (the Messages page) of the messages
 * that tell a person what they were given: a role, a pass, the team's OK,
 * organizer rights; and of the organizers' "wants to join" notice, which
 * gets the newcomer's answers ({join_answers}). A built-in text has the
 * change already; a stored one gets it once.
 *
 * Each step runs once and is remembered by name in OPTION, so a later
 * release can add a step without running the earlier ones again. Runs on
 * init, after the plugin's translations are loaded.
 */
final class AccessTextsMigration
{
    /** The names of the steps done, as a list. */
    public const OPTION = 'eventcrew_migr_fric_b2';

    /** The kinds whose stored texts get {open_tasks} at their end. */
    public const OPEN_TASKS_KINDS = [
        'access_role',
        'access_role_dm',
        'access_pass',
        'access_pass_dm',
        'newcomer_ok',
        'newcomer_ok_dm',
    ];

    /** The "wants to join" notices, whose stored texts get {join_answers}: kind => what goes before it. */
    public const JOIN_ANSWERS_KINDS = [
        'newcomer_waiting' => "\n\n",
        'newcomer_waiting_dm' => "\n",
    ];

    /** The organizer DM, which gets the line on what the Admin tab is for. */
    public const ORGANIZER_KIND = 'access_organizer_dm';

    /** Runs the steps not done yet. Called on every boot; cheap once done. */
    public static function migrate(): void
    {
        $done = get_option(self::OPTION, []);
        $done = is_array($done) ? $done : [];
        $steps = [
            'open_tasks' => static function (): void {
                foreach (self::OPEN_TASKS_KINDS as $id) {
                    self::appendToStored($id, static fn (string $text): string => self::append($text, '{open_tasks}'));
                }
            },
            'organizer_line' => static function (): void {
                self::appendToStored(self::ORGANIZER_KIND, static function (string $text, string $lang): string {
                    $line = Language::run($lang, static fn (): string => MessageTemplates::adminTabLine());

                    // A language with no translation keeps its text as it is.
                    return Language::ENGLISH !== $lang && $line === MessageTemplates::adminTabLine()
                        ? $text
                        : self::append($text, ' ' . $line);
                });
            },
            'join_answers' => static function (): void {
                foreach (self::JOIN_ANSWERS_KINDS as $id => $gap) {
                    self::appendToStored(
                        $id,
                        static fn (string $text): string => str_contains($text, '{join_answers}')
                            ? $text
                            : self::append($text, $gap . '{join_answers}')
                    );
                }
            },
        ];
        $changed = false;

        foreach ($steps as $name => $step) {
            if (in_array($name, $done, true)) {
                continue;
            }

            $step();
            $done[] = $name;
            $changed = true;
        }

        if ($changed) {
            update_option(self::OPTION, $done);
        }
    }

    /**
     * Runs $change over the stored body of $id: its source and each language
     * text. Nothing when the kind has no stored body.
     *
     * @param \Closure(string, string): string $change given the text and its language
     */
    private static function appendToStored(string $id, \Closure $change): void
    {
        $option = MessageTemplates::OPTION_PREFIX . $id;
        $stored = get_option($option, []);
        $body = is_array($stored) ? ($stored[MessageTemplates::BODY] ?? null) : null;

        if (! is_array($body)) {
            return;
        }

        $body['source'] = $change((string) ($body['source'] ?? ''), (string) ($body['lang'] ?? Language::ENGLISH));

        foreach (is_array($body['texts'] ?? null) ? $body['texts'] : [] as $lang => $text) {
            $body['texts'][$lang] = $change((string) $text, (string) $lang);
        }

        $stored[MessageTemplates::BODY] = $body;
        update_option($option, $stored);
    }

    /** $text with $tail at its end when it lacks it; an empty text stays empty. */
    private static function append(string $text, string $tail): string
    {
        return '' === trim($text) || str_contains($text, $tail) ? $text : rtrim($text) . $tail;
    }
}

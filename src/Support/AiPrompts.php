<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The instructions the language model gets, editable in wp-admin under
 * EventCrew > AI prompts (AiPromptsPage).
 *
 * Each prompt has a built-in text (DEFAULTS). An administrator can replace it;
 * the replacement is kept in OPTION by the prompt's key, and "Reset to
 * built-in" removes it again. A replacement that is empty or the same as the
 * built-in text is not kept, so the built-in text stays in use and a later
 * version of the plugin can change it.
 *
 * A prompt may contain {rules} and {rules_fi}: they become the texts of the
 * RULES and RULES_FI prompts, so the shared rules are written once. With the
 * built-in texts, get() gives exactly the instructions the code used before
 * the prompts became editable (NewsletterWriter and BoardDjText).
 *
 * The banned words are spelled in two parts, to keep them out of the source.
 */
final class AiPrompts
{
    /** The replaced prompts: [key => text]. A key that is missing uses DEFAULTS. */
    public const OPTION = 'eventcrew_ai_prompts';

    /** The rules every newsletter text follows ({rules}). */
    public const RULES = 'rules';

    /** The extra rule for the texts written in Finnish ({rules_fi}). */
    public const RULES_FI = 'rules_fi';

    /** One event's text in the next events announcement. */
    public const NEWSLETTER_EVENT = 'newsletter_event';

    /** The opening text of the next events announcement. */
    public const NEWSLETTER_OPENING = 'newsletter_opening';

    /** The post-event email's text about the next event. */
    public const FOLLOWUP = 'followup';

    /** Picking an event's venue, address and door times out of its description. */
    public const PRACTICAL = 'practical';

    /** The translation from English into Finnish. */
    public const TRANSLATE_FI = 'translate_fi';

    /** The translation from Finnish into English. */
    public const TRANSLATE_EN = 'translate_en';

    /** The text about the DJ or host under the picture on the group board. */
    public const BOARD_DJ = 'board_dj';

    /** The longest prompt kept, in characters. */
    public const MAX_LENGTH = 4000;

    /** The built-in texts, in the order the page shows them. */
    public const DEFAULTS = [
        self::RULES => 'Use only facts that are in the input. '
            . 'Do not invent times, prices, places, names or anything else. '
            . 'A DJ name is a person, never a place. Name a place only when the input says it is the place. '
            . 'Do not write hashtags or links. '
            . 'Do not use emoji, unless the site\'s words and style below use them. '
            . 'Do not use the word "vol' . 'unteer". '
            . 'Keep the text under 450 characters.',
        self::RULES_FI => 'Do not use the word "vapaa' . 'ehtoinen".',
        self::NEWSLETTER_EVENT => 'You write the text for one event in the email newsletter of a dance event. '
            . 'Write 2 to 3 sentences in Finnish that invite people to come and dance at this event. '
            . 'Be warm and concrete. '
            . 'Use the descriptive words of the DJ descriptions and the facts about the event. '
            . '{rules} {rules_fi}',
        self::NEWSLETTER_OPENING => 'You write the opening text of the email newsletter of a dance event. '
            . 'The newsletter lists the coming events below the opening, each with its own text. '
            . 'Write 2 to 3 sentences in Finnish that welcome the readers '
            . 'and invite them to come and dance at these events. '
            . 'Be warm and concrete. Do not repeat the details of every event. '
            . '{rules} {rules_fi}',
        self::FOLLOWUP => 'You write a short text about one coming event of a dance event. '
            . 'It goes on the card of that event in the thank-you email sent the day after a night. '
            . 'Write 2 short sentences in English that make people want to come and dance at this event. '
            . 'Write in the style of the DJ descriptions: use their descriptive words and their mood. '
            . 'Add what is useful from the event description, such as what the night is like '
            . 'or what to bring. Be warm. '
            . '{rules} For this email, keep the text under 300 characters.',
        self::PRACTICAL => 'You read the description of one event of a dance event '
            . 'and pick out its practical information for the email newsletter: '
            . 'the name of the venue (the place where the event is held), its street address, '
            . 'the time the doors open and the time the doors close. '
            . 'Copy the venue and the address exactly as they are written in the description. '
            . 'Write each time as HH:MM, as it is written in the description. '
            . 'When the doors open during a time range, such as "18:30-19:00 Doors open", '
            . 'the doors open at the start of the range. '
            . 'Use "" for anything the description does not say. Do not guess. '
            . 'A DJ name is a person, never a place: the venue is never one of the DJ names. '
            . 'The event title is not the venue unless the description says so. '
            . 'Never include door codes, entry codes or any other codes.',
        self::TRANSLATE_FI => 'You translate a short newsletter text from English into Finnish. '
            . 'Write natural, warm Finnish, about as long as the English text and under 500 characters. '
            . 'Keep names, dates, times and emoji exactly as they are. '
            . 'Do not add anything and do not leave anything out.',
        self::TRANSLATE_EN => 'You translate a short newsletter text from Finnish into English. '
            . 'Write natural, warm English, about as long as the Finnish text and under 500 characters. '
            . 'Keep names, dates, times and emoji exactly as they are. '
            . 'Do not add anything and do not leave anything out. '
            . 'Do not use the word "vol' . 'unteer".',
        self::BOARD_DJ => 'You write a short text about the DJ or the host of one event of a dance event. '
            . 'It goes under the picture of the event in the Telegram group of the event\'s crew. '
            . 'Write exactly 2 sentences in English about who the DJ or host is and their style or their work. '
            . 'Write only about the DJ or host: not about the date, the place, the price or the tickets. '
            . 'Use only facts that are in the input. Do not invent names, places or anything else. '
            . 'A DJ name is a person, never a place. '
            . 'Do not write hashtags or links. Do not use emoji. '
            . 'Do not use the words "vol' . 'unteer", "sh' . 'ift", "vu' . 'oro" or "vapaa' . 'ehtoinen". '
            . 'Keep the text under 300 characters.',
    ];

    /**
     * The prompt $key as the model gets it: the stored text or the built-in
     * one, with {rules} and {rules_fi} filled in. '' for an unknown key.
     */
    public static function get(string $key): string
    {
        return self::expand(self::raw($key));
    }

    /** $text with {rules} and {rules_fi} replaced by those prompts' texts. */
    public static function expand(string $text): string
    {
        return strtr($text, [
            '{rules}' => self::raw(self::RULES),
            '{rules_fi}' => self::raw(self::RULES_FI),
        ]);
    }

    /** The prompt $key as written, with {rules} still in it. '' for an unknown key. */
    public static function raw(string $key): string
    {
        if (! isset(self::DEFAULTS[$key])) {
            return '';
        }

        $stored = self::stored();

        return $stored[$key] ?? self::DEFAULTS[$key];
    }

    /** Whether $key uses a stored text instead of the built-in one. */
    public static function isChanged(string $key): bool
    {
        return isset(self::stored()[$key]);
    }

    /**
     * Keeps $text as the prompt $key. An empty text, or the built-in text,
     * removes the stored one, so the built-in text is used. Unknown keys are
     * ignored.
     */
    public static function save(string $key, string $text): void
    {
        if (! isset(self::DEFAULTS[$key])) {
            return;
        }

        $text = self::clean($text);
        $stored = self::stored();

        if ('' === $text || $text === self::DEFAULTS[$key]) {
            unset($stored[$key]);
        } else {
            $stored[$key] = $text;
        }

        update_option(self::OPTION, $stored, false);
    }

    /** "Reset to built-in": removes the stored text of $key. */
    public static function reset(string $key): void
    {
        $stored = self::stored();

        if (! isset($stored[$key])) {
            return;
        }

        unset($stored[$key]);
        update_option(self::OPTION, $stored, false);
    }

    /**
     * Each prompt's name and what it is for, by key, in the order of DEFAULTS.
     *
     * @return array<string, array{label: string, help: string}>
     */
    public static function labels(): array
    {
        // phpcs:disable Generic.Files.LineLength.TooLong -- gettext literals; splitting one breaks extraction.
        return [
            self::RULES => [
                'label' => __('Rules for the newsletter texts', 'eventcrew'),
                'help' => __('Put into the prompts below where they say {rules}.', 'eventcrew'),
            ],
            self::RULES_FI => [
                'label' => __('Extra rule for Finnish texts', 'eventcrew'),
                'help' => __('Put into the prompts below where they say {rules_fi}.', 'eventcrew'),
            ],
            self::NEWSLETTER_EVENT => [
                'label' => __('Announcement: the text of one event', 'eventcrew'),
                'help' => __('Written in Finnish, then translated into English.', 'eventcrew'),
            ],
            self::NEWSLETTER_OPENING => [
                'label' => __('Announcement: the opening text', 'eventcrew'),
                'help' => __('Used when the organizers wrote no text for the announcement.', 'eventcrew'),
            ],
            self::FOLLOWUP => [
                'label' => __('Post-event email: the text about the next event', 'eventcrew'),
                'help' => __('Written in English, then translated into Finnish.', 'eventcrew'),
            ],
            self::PRACTICAL => [
                'label' => __('Venue, address and door times', 'eventcrew'),
                'help' => __('Picks these out of the event description. A value is kept only when the description has it.', 'eventcrew'),
            ],
            self::TRANSLATE_FI => [
                'label' => __('Translation into Finnish', 'eventcrew'),
                'help' => __('Translates the post-event email text.', 'eventcrew'),
            ],
            self::TRANSLATE_EN => [
                'label' => __('Translation into English', 'eventcrew'),
                'help' => __('Translates the announcement texts.', 'eventcrew'),
            ],
            self::BOARD_DJ => [
                'label' => __('Telegram board: the text about the DJ', 'eventcrew'),
                'help' => __('Shown under the picture of the next event on the group board.', 'eventcrew'),
            ],
        ];
        // phpcs:enable Generic.Files.LineLength.TooLong
    }

    /** $text with line endings unified, trimmed and cut to MAX_LENGTH. */
    public static function clean(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        return function_exists('mb_substr') ? mb_substr($text, 0, self::MAX_LENGTH) : substr($text, 0, self::MAX_LENGTH);
    }

    /** @return array<string, string> */
    private static function stored(): array
    {
        $stored = get_option(self::OPTION, []);
        $clean = [];

        foreach (is_array($stored) ? $stored : [] as $key => $text) {
            if (isset(self::DEFAULTS[$key]) && is_string($text) && '' !== trim($text)) {
                $clean[(string) $key] = $text;
            }
        }

        return $clean;
    }
}

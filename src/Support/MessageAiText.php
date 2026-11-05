<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Connects the Messages page (MessageTemplates) to the AI texts per event
 * (EventAiText).
 *
 * - On the Messages page, each kind in KINDS gets the tick "Write a new text
 *   with the AI for each event" and a prompt box (FIELDS_ACTION). They are
 *   stored in that kind's settings (SETTINGS_FILTER).
 * - EventAiText reads those settings through its TEMPLATES_FILTER, and
 *   writes the texts the day before each event.
 * - The code that sends a message calls body() with the event's id. It
 *   answers with the AI text of that event when there is one, else with the
 *   template's text. Both get the organizers' own placeholders
 *   (EventAiText::fill()) and the template's placeholders filled.
 *
 * KINDS are the wired kinds that belong to one event. The free-entry
 * credit messages are not about an event, and the ticket, after-event and
 * newsletter emails do not read their template yet.
 */
final class MessageAiText
{
    /** The kinds that can have an AI text per event. */
    public const KINDS = [
        'reminder',
        'reminder_dm',
        'slot_freed',
        'slot_freed_dm',
        'task_cancelled',
        'no_show',
        'no_show_dm',
        'late_cancel',
        'late_cancel_dm',
        'free_ticket_dm',
    ];

    public function boot(): void
    {
        add_action(MessageTemplates::FIELDS_ACTION, [self::class, 'fields'], 10, 2);
        add_filter(MessageTemplates::SETTINGS_FILTER, [self::class, 'readSettings'], 10, 3);
        add_filter(EventAiText::TEMPLATES_FILTER, [self::class, 'templates']);
    }

    /** Whether kind $id can have an AI text per event. */
    public static function offers(string $id): bool
    {
        $template = MessageTemplates::find($id);

        return null !== $template && $template->wired && in_array($id, self::KINDS, true);
    }

    /**
     * The tick and the prompt box, inside the kind's form on the Messages page.
     *
     * @param array<string, mixed> $settings
     */
    public static function fields(MessageTemplate $template, array $settings): void
    {
        if (! self::offers($template->id)) {
            return;
        }

        $id = 'ec-message-' . $template->id . '-ai';
        $prompt = is_string($settings[EventAiText::SETTING_AI_PROMPT] ?? null)
            ? $settings[EventAiText::SETTING_AI_PROMPT]
            : '';

        echo '<h3>' . esc_html__('AI text for each event', 'eventcrew') . '</h3>';
        printf(
            '<p><label><input type="checkbox" name="%s" value="1" %s> %s</label></p>',
            esc_attr(MessageTemplates::POST_NAME . '[' . EventAiText::SETTING_AI_EACH_EVENT . ']'),
            ! empty($settings[EventAiText::SETTING_AI_EACH_EVENT]) ? 'checked' : '',
            esc_html__('Write a new text with the AI for each event', 'eventcrew')
        );
        printf(
            '<p><label for="%s">%s</label></p>',
            esc_attr($id),
            esc_html__('What the AI is asked to write', 'eventcrew')
        );
        printf(
            '<textarea id="%s" name="%s" rows="4" maxlength="%d" class="large-text" style="max-width:48em">'
                . '%s</textarea>',
            esc_attr($id),
            esc_attr(MessageTemplates::POST_NAME . '[' . EventAiText::SETTING_AI_PROMPT . ']'),
            (int) EventAiText::MAX_PROMPT,
            esc_textarea($prompt)
        );
        echo '<p class="description" style="max-width:48em">'
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            . esc_html__('With the tick on, the AI writes this message\'s text the day before each event, from this prompt. You can read and change the text on the Coming events tab of this page and in the crew app before it is sent. When the AI fails, the text above is sent. The subject stays the one above.', 'eventcrew')
            . '</p>';
    }

    /**
     * SETTINGS_FILTER: the tick and the prompt from the posted form of $id.
     *
     * @param mixed $settings The kind's settings.
     * @param mixed $posted The raw $_POST[MessageTemplates::POST_NAME].
     * @return mixed
     */
    public static function readSettings(mixed $settings, string $id, mixed $posted): mixed
    {
        if (! is_array($settings) || ! self::offers($id)) {
            return $settings;
        }

        $posted = is_array($posted) ? $posted : [];
        $prompt = $posted[EventAiText::SETTING_AI_PROMPT] ?? '';
        $prompt = is_string($prompt) ? sanitize_textarea_field(wp_unslash($prompt)) : '';

        $settings[EventAiText::SETTING_AI_EACH_EVENT] = ! empty($posted[EventAiText::SETTING_AI_EACH_EVENT]);
        $settings[EventAiText::SETTING_AI_PROMPT] = function_exists('mb_substr')
            ? mb_substr($prompt, 0, EventAiText::MAX_PROMPT)
            : substr($prompt, 0, EventAiText::MAX_PROMPT);

        return $settings;
    }

    /**
     * EventAiText::TEMPLATES_FILTER: every kind in KINDS with its settings
     * and its label.
     *
     * @param mixed $list
     * @return array<string, array<string, mixed>>
     */
    public static function templates(mixed $list): array
    {
        $list = is_array($list) ? $list : [];

        foreach (self::KINDS as $id) {
            $template = MessageTemplates::find($id);

            if (null === $template || ! $template->wired) {
                continue;
            }

            $list[$id] = [EventAiText::SETTING_LABEL => $template->label] + MessageTemplates::settings($id);
        }

        return $list;
    }

    /**
     * The text of kind $id for event $eventId, in the language being composed
     * in: the event's AI text when the kind's tick is on and the text exists,
     * else the template's text. The organizers' placeholders and the
     * template's placeholders are filled from $context. Without an event
     * ($eventId null or 0) or for a kind not in KINDS, this is
     * MessageTemplates::body().
     *
     * @param array<string, mixed> $context
     */
    public static function body(string $id, array $context, ?int $eventId): string
    {
        $template = MessageTemplates::find($id);

        if (null === $template || null === $eventId || $eventId <= 0 || ! self::offers($id)) {
            return MessageTemplates::body($id, $context);
        }

        $lang = Language::current() ?? Language::ENGLISH;
        $text = EventAiText::text($id, $eventId, $lang, MessageTemplates::settings($id))
            ?? MessageTemplates::raw($id, MessageTemplates::BODY, $context);

        return $template->fill(EventAiText::fill($text, $eventId, $lang), $context);
    }
}

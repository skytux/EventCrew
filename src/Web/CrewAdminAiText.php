<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Support\EventAiText;

/**
 * The AI texts screen of the crew app's Admin tab: the texts the AI wrote the
 * day before each coming event (EventAiText), each with an edit box. The same
 * texts and the same saving as wp-admin's Messages page (Coming events tab). A text nobody
 * changes is sent as the AI wrote it; a changed text is sent as changed.
 */
final class CrewAdminAiText
{
    /**
     * What the screen shows: EventAiText::overview().
     *
     * @return list<array<string, mixed>>
     */
    public function view(): array
    {
        return EventAiText::overview();
    }

    /**
     * Saves one text from the screen's form: the text in each language
     * (texts[lang]), or "Use the AI text again" (op=ai). Returns the notice
     * code.
     *
     * @param array<string, mixed> $post
     */
    public function save(Person $organizer, array $post): string
    {
        $texts = [];

        foreach (is_array($post['texts'] ?? null) ? $post['texts'] : [] as $lang => $text) {
            $texts[(string) $lang] = sanitize_textarea_field((string) $text);
        }

        $result = EventAiText::saveForm(
            (int) ($post['event_id'] ?? 0),
            sanitize_text_field((string) ($post['key'] ?? '')),
            'ai' === sanitize_key((string) ($post['op'] ?? '')),
            $texts,
            $organizer->id
        );

        return match ($result) {
            EventAiText::SAVED => 'admin_aitext_saved',
            EventAiText::BACK_TO_AI => 'admin_aitext_back',
            default => 'admin_aitext_missing',
        };
    }

    public static function noticeText(string $code): string
    {
        $map = [
            'admin_aitext_saved' => __('Saved. This text is sent as you wrote it.', 'eventcrew'),
            'admin_aitext_back' => __('The AI text is used again.', 'eventcrew'),
            'admin_aitext_missing' => __('Nothing was saved: that text was not found.', 'eventcrew'),
        ];

        return $map[$code] ?? '';
    }
}

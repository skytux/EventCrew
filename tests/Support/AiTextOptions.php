<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\EventAiText;
use EventCrew\Support\MessageTemplates;

/**
 * The stored options that give a message kind an AI text for one event
 * (MessageAiText): the kind's AI tick on with a prompt, and, when $texts is
 * given, the event's stored AI text for that kind.
 */
trait AiTextOptions
{
    /**
     * @param array<string, array<string, string>> $texts kind => [lang => text]
     * @return array<string, mixed>
     */
    private static function aiTextOptions(array $kinds, int $eventId, array $texts): array
    {
        $options = [];

        foreach ($kinds as $kind) {
            $options[MessageTemplates::OPTION_PREFIX . $kind] = [
                'settings' => [
                    EventAiText::SETTING_AI_EACH_EVENT => true,
                    EventAiText::SETTING_AI_PROMPT => 'Write the message.',
                ],
            ];
        }

        $items = [];

        foreach ($texts as $kind => $byLang) {
            $items[$kind] = ['ai' => $byLang, 'edited' => [], 'tries' => 1, 'at' => '', 'edited_by' => 0];
        }

        if ([] !== $items) {
            $options[EventAiText::TEXTS_OPTION] = [
                $eventId => ['date' => '2026-08-01', 'title' => 'Party', 'items' => $items],
            ];
        }

        return $options;
    }
}

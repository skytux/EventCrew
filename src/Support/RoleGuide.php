<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Task;

/**
 * What a task involves, in the words the crew sees: the role's short
 * description, and the one shared link to the full crew guide.
 *
 * The guide link is always shown by name - a Telegram button, an email action,
 * a named link on the web page - never as a raw address, so every place that
 * offers it builds it here and they cannot drift apart.
 */
final class RoleGuide
{
    /**
     * The "About the task" paragraph for a message, or '' when the role has no
     * description, so a caller can append it unconditionally.
     */
    public static function aboutLine(Task $task): string
    {
        $description = $task->roleDescription();

        if ('' === $description) {
            return '';
        }

        return sprintf(
            /* translators: %s: a sentence or two on what the role involves */
            __('📝 About the task: %s', 'eventcrew'),
            $description
        );
    }

    /**
     * The guide as a Telegram url button, or null when no guide link is set.
     *
     * @return array{text: string, url: string}|null
     */
    public static function button(): ?array
    {
        $url = Roles::guideUrl();

        return '' === $url ? null : ['text' => self::label(), 'url' => $url];
    }

    /**
     * The guide as an email action, or null when no guide link is set.
     *
     * @return array{label: string, url: string}|null
     */
    public static function emailAction(): ?array
    {
        $url = Roles::guideUrl();

        return '' === $url ? null : ['label' => self::label(), 'url' => $url];
    }

    public static function label(): string
    {
        return __('📋 Crew guide', 'eventcrew');
    }
}

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
     * The description of the task: its own text (the task's notes, which the
     * calendar file also uses as its description), else its role's
     * description, both in the language being composed in; '' when neither
     * has one.
     */
    public static function taskDescription(Task $task): string
    {
        $own = TranslatedText::forReader($task->notes);

        return '' !== $own ? $own : $task->roleDescription();
    }

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

    /** The id of the task-descriptions section on the web sign-up board. */
    public const DESCRIPTIONS_ANCHOR = 'eventcrew-tasks-info';

    /**
     * The task-descriptions section of the web sign-up page, or '' when no
     * sign-up page is set.
     */
    public static function descriptionsUrl(): string
    {
        $pageId = (int) get_option('eventcrew_signup_page_id', 0);
        $url = $pageId > 0 ? get_permalink($pageId) : false;

        return is_string($url) && '' !== $url ? $url . '#' . self::DESCRIPTIONS_ANCHOR : '';
    }

    /**
     * Every role on offer with what it involves. The one list the welcome
     * message and the web board's descriptions section both print, so they
     * cannot disagree.
     *
     * @return array<int, array{name: string, description: string}>
     */
    public static function descriptions(): array
    {
        $list = [];

        foreach (Roles::active() as $role) {
            $list[] = [
                'name' => '' === $role['emoji'] ? $role['label'] : $role['emoji'] . ' ' . $role['label'],
                'description' => $role['description'],
            ];
        }

        return $list;
    }

    /**
     * One "- " line per role on offer, with its description. The role's own
     * emoji is the line's bullet; a role without one gets 🔹.
     *
     * @return array<int, string>
     */
    public static function lines(): array
    {
        $lines = [];

        foreach (self::descriptions() as $role) {
            $line = '- ' . $role['name'];

            if (! EmailBody::isEmojiLine($line)) {
                $line = '- 🔹 ' . $role['name'];
            }

            $lines[] = '' === $role['description'] ? $line : $line . ': ' . $role['description'];
        }

        return $lines;
    }

    public static function descriptionsLabel(): string
    {
        return __('📝 What each task involves', 'eventcrew');
    }

    /**
     * The task-descriptions link as a Telegram url button, or null when no
     * sign-up page is set.
     *
     * @return array{text: string, url: string}|null
     */
    public static function descriptionsButton(): ?array
    {
        $url = self::descriptionsUrl();

        return '' === $url ? null : ['text' => self::descriptionsLabel(), 'url' => $url];
    }
}

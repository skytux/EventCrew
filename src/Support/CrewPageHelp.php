<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;

/**
 * Where a crew email sends a person to act: the Telegram bot, or the crew
 * page. A person with no linked Telegram chat (web-only crew; they signed in
 * with their email) cannot use the bot, so their emails name the crew page
 * instead, with one "Manage my tasks" button that signs them in there
 * (Mailer::manageUrl()).
 *
 * The texts that differ are placeholders of the Messages page templates
 * (MessageTemplates), so an edited text keeps working for both kinds of
 * person. When no crew page is set (a Telegram-only install), everyone gets
 * the bot text.
 */
final class CrewPageHelp
{
    /** Whether $person has no linked Telegram chat and a crew page is set. */
    public static function webOnly(mixed $person): bool
    {
        return $person instanceof Person && null === $person->telegramChatId && '' !== self::pageUrl();
    }

    /** The crew page's address (the sign-up page), or '' when none is set. */
    public static function pageUrl(): string
    {
        $pageId = (int) get_option('eventcrew_signup_page_id', 0);
        $url = $pageId > 0 ? get_permalink($pageId) : false;

        return is_string($url) ? $url : '';
    }

    /**
     * The "Manage my tasks" email button for a web-only person, or null for
     * everyone else.
     *
     * @return array{label: string, url: string}|null
     */
    public static function manageAction(Mailer $mailer, Person $person): ?array
    {
        if (! self::webOnly($person)) {
            return null;
        }

        return ['label' => __('Manage my tasks', 'eventcrew'), 'url' => $mailer->manageUrl($person->id)];
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The organization's name, as members see it: on the ticket, in emails,
 * Telegram messages and the bot, and on the public pages, where the plugin
 * once said "EventCrew". Set in wp-admin Settings > Web page; empty means the
 * site name. wp-admin itself keeps saying EventCrew.
 *
 * In message texts and the ticket note it is the placeholder {organization}.
 */
final class Organization
{
    public const OPTION = 'eventcrew_organization_name';

    /** The longest name kept, in characters. */
    public const MAX_LENGTH = 100;

    public const TAG = '{organization}';

    /** The name set in Settings, else the site name. */
    public static function name(): string
    {
        $name = trim((string) get_option(self::OPTION, ''));

        return '' !== $name ? $name : trim((string) get_bloginfo('name'));
    }

    /** The name as typed in Settings ('' when the site name is used). */
    public static function stored(): string
    {
        return trim((string) get_option(self::OPTION, ''));
    }

    /**
     * Saves the posted name (already unslashed): one line, at most
     * MAX_LENGTH characters. Empty goes back to the site name.
     */
    public static function save(mixed $posted): void
    {
        $name = is_string($posted) ? trim(sanitize_text_field($posted)) : '';
        $name = mb_substr($name, 0, self::MAX_LENGTH);

        if ('' === $name) {
            delete_option(self::OPTION);

            return;
        }

        update_option(self::OPTION, $name, false);
    }
}

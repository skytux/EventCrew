<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;

/**
 * Where a person wants a notice that would reach them both as a Telegram
 * DM and as an app notification (Owner 2026-11-13): App only, Telegram
 * only, or Both. Unset is Both, as before the choice existed.
 *
 * It only ever drops one of the two copies of the same message
 * (NotificationPreferences::pushAllowed() and dmWanted()): App only skips
 * the DM when the app notification reached a device, and sends it after all
 * when it reached none, so nothing is lost; Telegram only skips the app
 * notification. Email and the Telegram group are never touched.
 *
 * Asked once, with three buttons, of a person who has Telegram linked and a
 * device with app notifications on: on the web right after they turn app
 * notifications on, in the bot when they link Telegram while a device
 * already has them, or else on their next visit to the signed-in Crew app
 * page. Changed later in both notification settings.
 *
 * Kept in the person's role_notices record, beside the "Get the app"
 * entries (AppInstallPrompt), so no schema change.
 */
final class ChannelChoice
{
    public const APP = 'app';
    public const TELEGRAM = 'telegram';
    public const BOTH = 'both';

    /** The three answers, in the order the buttons show them. */
    public const CHOICES = [self::APP, self::TELEGRAM, self::BOTH];

    /** role_notices: the answer. */
    public const KEY = 'app:channels';

    /** role_notices: when the question was asked (site time), so it is asked once. */
    public const KEY_ASKED = 'app:channels_asked';

    public const KEYS = [self::KEY, self::KEY_ASKED];

    /** The ops the Crew app page posts (ChannelChoiceController). */
    public const OP_ASKED = 'asked';
    public const OP_CHOOSE = 'choose';

    /** The person's answer; Both when they never chose. */
    public static function of(Person $person): string
    {
        $choice = (string) ($person->roleNotices[self::KEY] ?? '');

        return in_array($choice, self::CHOICES, true) ? $choice : self::BOTH;
    }

    public static function wasAsked(Person $person): bool
    {
        return '' !== (string) ($person->roleNotices[self::KEY_ASKED] ?? '')
            || '' !== (string) ($person->roleNotices[self::KEY] ?? '');
    }

    /**
     * Whether the choice means anything for this person: Telegram linked and
     * at least one device with app notifications on. Only then is it asked,
     * and only then does the settings row show.
     */
    public static function applies(Person $person): bool
    {
        return $person->hasTelegram() && NotificationPreferences::hasDevice($person->id);
    }

    /** Whether the question is still to be asked of this person. */
    public static function due(Person $person): bool
    {
        return ! self::wasAsked($person) && self::applies($person);
    }

    /** A known answer, or null. */
    public static function parse(string $raw): ?string
    {
        return in_array($raw, self::CHOICES, true) ? $raw : null;
    }

    /**
     * The role_notices entries that store an answer (and that it was asked).
     *
     * @return array<string, string>
     */
    public static function entriesFor(string $choice, string $siteNow): array
    {
        return [self::KEY => $choice, self::KEY_ASKED => $siteNow];
    }

    /**
     * The role_notices entry that records the question as asked, or [] when
     * it was asked before (the first time counts).
     *
     * @return array<string, string>
     */
    public static function askedEntries(Person $person, string $siteNow): array
    {
        return self::wasAsked($person) ? [] : [self::KEY_ASKED => $siteNow];
    }

    public static function question(): string
    {
        return __('You get notifications in the app and in Telegram. Where do you want them?', 'eventcrew');
    }

    /** The settings row's name. */
    public static function rowLabel(): string
    {
        return __('When both are on', 'eventcrew');
    }

    /** @return array<string, string> choice => label, in button order */
    public static function labels(): array
    {
        return [
            self::APP => __('App only', 'eventcrew'),
            self::TELEGRAM => __('Telegram only', 'eventcrew'),
            self::BOTH => __('Both', 'eventcrew'),
        ];
    }

    /** What is said after a choice. */
    public static function confirmation(string $choice): string
    {
        return match ($choice) {
            self::APP => __('Saved. Notifications come to the app.', 'eventcrew'),
            self::TELEGRAM => __('Saved. Notifications come to Telegram.', 'eventcrew'),
            default => __('Saved. Notifications come to both.', 'eventcrew'),
        };
    }
}

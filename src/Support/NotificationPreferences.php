<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;

/**
 * Decides, per person and per notification type, whether a Telegram DM or an
 * email may be sent. The one place the coarse account switches (a disabled
 * account gets no email; the legacy "mute all DMs" flag) and the fine per-type
 * preferences are combined, so every notifier gates its two sends the same way:
 *
 *     if ($prefs->dmAllowed($person, self::TYPE))    { ...DM... }
 *     if ($prefs->emailAllowed($person, self::TYPE)) { ...email... }
 *
 * Dependency-free and newed inline like ReputationSettings - its methods take a
 * Person and read no storage of their own, so it threads through no constructor.
 */
final class NotificationPreferences
{
    /** Toggleable, convenience types the two UIs expose. */
    public const STANDING = 'standing';
    public const CREDIT = 'credit';
    public const TICKET = 'ticket';
    public const PERMISSION = 'permission';
    public const COVER = 'cover';
    public const OPEN_TASK = 'open_task';

    /**
     * Locked commitment types the confirmation and reminder notifiers use: never
     * toggleable, so a person always gets word of what they signed up for.
     */
    public const SIGNUP = 'signup';
    public const REMINDER = 'reminder';

    /** @var array<int, string> */
    private const TOGGLEABLE = [
        self::STANDING,
        self::CREDIT,
        self::TICKET,
        self::PERMISSION,
        self::COVER,
        self::OPEN_TASK,
    ];

    /**
     * Human labels for the toggleable types, in display order - the rows of both
     * the web tab and the /notifications keyboard.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::STANDING => __('Standing (no-show / late cancel)', 'eventcrew'),
            self::CREDIT => __('Free-entry credit granted', 'eventcrew'),
            self::TICKET => __('Free-entry ticket ready', 'eventcrew'),
            self::PERMISSION => __('Permission changes', 'eventcrew'),
            self::COVER => __('Cover arranged', 'eventcrew'),
            self::OPEN_TASK => __('Open-task alerts', 'eventcrew'),
        ];
    }

    public function dmAllowed(Person $person, string $type): bool
    {
        // No linked chat, nowhere to DM.
        return null !== $person->telegramChatId && $this->dmPref($person, $type);
    }

    public function emailAllowed(Person $person, string $type): bool
    {
        // Locked (transactional) types always send; toggleable ones honour the
        // person's per-type email preference, defaulting on. This is the only
        // email off-switch now - the old blanket "disabled account" flag is gone.
        if (! in_array($type, self::TOGGLEABLE, true)) {
            return true;
        }

        return (bool) ($person->notifyPrefs[$type]['email'] ?? true);
    }

    /**
     * The toggle positions for the UIs: each toggleable type's DM and email
     * preference (independent of whether a channel could actually reach them).
     *
     * @return array<string, array{label: string, dm: bool, email: bool}>
     */
    public function matrix(Person $person): array
    {
        $out = [];

        foreach (self::labels() as $type => $label) {
            $out[$type] = [
                'label' => $label,
                'dm' => $this->dmPref($person, $type),
                'email' => (bool) ($person->notifyPrefs[$type]['email'] ?? true),
            ];
        }

        return $out;
    }

    /**
     * The full explicit map for a person, so a single toggle can be flipped and
     * saved without leaving the other types on their (possibly legacy-seeded)
     * defaults.
     *
     * @return array<string, array{dm: bool, email: bool}>
     */
    public function asMap(Person $person): array
    {
        $map = [];

        foreach ($this->matrix($person) as $type => $row) {
            $map[$type] = ['dm' => $row['dm'], 'email' => $row['email']];
        }

        return $map;
    }

    /**
     * The DM preference alone (no chat-linked check): the per-type value, or -
     * for a locked type - always on, defaulting a set-but-unmigrated "mute all
     * DMs" account to off.
     */
    private function dmPref(Person $person, string $type): bool
    {
        if (! in_array($type, self::TOGGLEABLE, true)) {
            return true;
        }

        return (bool) ($person->notifyPrefs[$type]['dm'] ?? ! $person->notifyMuted);
    }
}

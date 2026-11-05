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
    /** Toggleable, convenience types the two UIs expose to everybody. */
    public const STANDING = 'standing';
    public const CREDIT = 'credit';
    public const TICKET = 'ticket';
    public const PERMISSION = 'permission';
    public const OPEN_TASK = 'open_task';

    /**
     * Toggleable types only an organizer receives, so only an organizer sees
     * their rows: a person given a role or made an organizer (RoleNotifier),
     * a person newly meeting a role's conditions (RoleNotifier), a bot
     * chat passed on to the organizers (ChatForwarder), and what came of a
     * ticket or guest list mailed to the import mailbox (MailedListImport).
     */
    public const ROLE_NEWS = 'role_news';
    public const ROLE_MET = 'role_met';
    public const CHAT_FORWARD = 'chat_forward';
    public const LIST_IMPORT = 'list_import';
    public const NEWCOMER = 'newcomer';

    /**
     * Locked commitment types the confirmation and reminder notifiers use: never
     * toggleable, so a person always gets word of what they signed up for.
     */
    public const SIGNUP = 'signup';
    public const REMINDER = 'reminder';

    /**
     * Human labels for the toggleable types, in display order - the rows of both
     * the web tab and the bot's 🔔 Notifications keyboard. The organizer-only
     * rows follow the others when $organizer is true; a new type is a constant
     * plus a line in memberLabels() or organizerLabels(), nothing else.
     *
     * @return array<string, string>
     */
    public static function labels(bool $organizer = false): array
    {
        return $organizer ? self::memberLabels() + self::organizerLabels() : self::memberLabels();
    }

    /**
     * The rows this person sees and may set.
     *
     * @return array<string, string>
     */
    public static function labelsFor(Person $person): array
    {
        return self::labels($person->isOrganizer);
    }

    /** @return array<string, string> */
    private static function memberLabels(): array
    {
        return [
            self::STANDING => __('Standing (no-show / late cancel)', 'eventcrew'),
            self::CREDIT => __('Free-entry credit granted', 'eventcrew'),
            self::TICKET => __('Free-entry ticket ready', 'eventcrew'),
            self::PERMISSION => __('Permission changes', 'eventcrew'),
            self::OPEN_TASK => __('Open-task alerts', 'eventcrew'),
        ];
    }

    /** @return array<string, string> */
    private static function organizerLabels(): array
    {
        return [
            self::ROLE_NEWS => __('Roles given and new organizers', 'eventcrew'),
            self::ROLE_MET => __('Someone meets a role’s conditions', 'eventcrew'),
            self::CHAT_FORWARD => __('Bot chats sent to organizers', 'eventcrew'),
            self::LIST_IMPORT => __('Ticket and guest list imports', 'eventcrew'),
            self::NEWCOMER => __('Newcomers waiting for an OK', 'eventcrew'),
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
        if (! self::isToggleable($type)) {
            return true;
        }

        return (bool) ($person->notifyPrefs[$type]['email'] ?? true);
    }

    /**
     * The toggle positions for the UIs: the DM and email preference of each
     * type this person sees (independent of whether a channel could actually
     * reach them). The organizer-only rows are left out for everybody else.
     *
     * @return array<string, array{label: string, dm: bool, email: bool}>
     */
    public function matrix(Person $person): array
    {
        $out = [];

        foreach (self::labelsFor($person) as $type => $label) {
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
     * The map to save from the web Notifications form: a ticked box is on, an
     * unticked one off. Only the rows this person sees, so a non-organizer
     * cannot set the organizer-only ones.
     *
     * @param array<mixed> $posted prefs[<type>][dm|email] from the form
     * @return array<string, array{dm: bool, email: bool}>
     */
    public static function fromForm(Person $person, array $posted): array
    {
        $map = [];

        foreach (array_keys(self::labelsFor($person)) as $type) {
            $row = is_array($posted[$type] ?? null) ? $posted[$type] : [];
            $map[$type] = ['dm' => isset($row['dm']), 'email' => isset($row['email'])];
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
        if (! self::isToggleable($type)) {
            return true;
        }

        return (bool) ($person->notifyPrefs[$type]['dm'] ?? ! $person->notifyMuted);
    }

    /** Any type with a row, the organizer-only ones included. */
    private static function isToggleable(string $type): bool
    {
        return array_key_exists($type, self::labels(true));
    }
}

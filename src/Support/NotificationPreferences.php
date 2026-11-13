<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Telegram\GroupRelay;

/**
 * Decides, per person and per notification type, whether a Telegram DM, an
 * email or an app notification (Web Push to a device that turned it on) may
 * be sent. The one place the coarse account switches (a disabled
 * account gets no email; the legacy "mute all DMs" flag) and the fine per-type
 * preferences are combined, so every notifier gates its sends the same way:
 *
 *     $pushed = PushNotifier::notify($person, self::TYPE, $text); // checks pushAllowed()
 *     if ($prefs->dmWanted($person, self::TYPE, $pushed)) { ...DM... }
 *     if ($prefs->emailAllowed($person, self::TYPE))      { ...email... }
 *
 * The app notification goes first, so the DM can follow the person's
 * ChannelChoice for a message that would reach them on both: App only
 * skips the DM when the app reached a device, and sends it when it reached
 * none; Telegram only skips the app notification (pushAllowed()).
 *
 * Dependency-free and newed inline like ReputationSettings - its methods take a
 * Person and read no storage of their own, so it threads through no constructor.
 * The one exception is pushAllowed()'s "has a device" question, answered by
 * a lookup the kernel sets once (useDeviceLookup()).
 */
final class NotificationPreferences
{
    /** The three channels of every row: Telegram, email, app (Web Push). */
    public const CHANNELS = ['dm', 'email', 'push'];

    /**
     * Whether a person has at least one device with app notifications on
     * (person id => bool). Set by the kernel to the push subscriptions table;
     * unset (tests, or before boot) no one has a device, so nothing is pushed
     * and no query runs.
     *
     * @var (\Closure(int): bool)|null
     */
    private static ?\Closure $hasDevice = null;

    /** @param (\Closure(int): bool)|null $lookup */
    public static function useDeviceLookup(?\Closure $lookup): void
    {
        self::$hasDevice = $lookup;
    }

    /** Whether the person has a device with app notifications on (false before boot and in tests). */
    public static function hasDevice(int $personId): bool
    {
        return null !== self::$hasDevice && (self::$hasDevice)($personId);
    }

    /** Toggleable, convenience types the two UIs expose to everybody. */
    public const STANDING = 'standing';
    public const CREDIT = 'credit';
    public const TICKET = 'ticket';
    public const PERMISSION = 'permission';
    public const OPEN_TASK = 'open_task';

    /**
     * New messages in the group chat on the web (Telegram\GroupRelay): an
     * app notification only, and off until the person turns it on. A
     * Telegram DM would repeat what Telegram users already see in the group
     * itself, and an email per group message is far too many; the app
     * notification is for the crew who read the group on the web. Shown
     * only while the group chat on the web is on.
     */
    public const GROUP_CHAT = 'group_chat';

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
        ] + (GroupRelay::isEnabled() ? [self::GROUP_CHAT => __('Group chat messages', 'eventcrew')] : []);
    }

    /**
     * The channels a type can be sent on: every channel, except the group
     * chat, which is the app alone (see GROUP_CHAT).
     *
     * @return list<string>
     */
    public static function channelsFor(string $type): array
    {
        return self::GROUP_CHAT === $type ? ['push'] : self::CHANNELS;
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

    /**
     * Whether the DM of a message goes out, once its app notification was
     * tried and reached $pushed devices: dmAllowed(), except that a person
     * who chose App only (ChannelChoice) gets no DM when the app notification
     * reached a device. When it reached none (no device left, the push
     * service refused it, an error) the DM goes after all, so nothing is lost.
     */
    public function dmWanted(Person $person, string $type, int $pushed): bool
    {
        if (! $this->dmAllowed($person, $type)) {
            return false;
        }

        return $pushed <= 0 || ChannelChoice::APP !== ChannelChoice::of($person);
    }

    /**
     * An app notification: only to a person with a device that turned them
     * on (nothing is ever pushed before that), and only when the row's app
     * switch is on. Locked types always send, like email. The preference is
     * read first, so a person with it off costs no query.
     *
     * A person who chose Telegram only (ChannelChoice) gets no app
     * notification of a message that also goes to them as a DM.
     */
    public function pushAllowed(Person $person, string $type): bool
    {
        if (! $this->pushPref($person, $type) || null === self::$hasDevice) {
            return false;
        }

        if (ChannelChoice::TELEGRAM === ChannelChoice::of($person) && $this->dmAllowed($person, $type)) {
            return false;
        }

        return (self::$hasDevice)($person->id);
    }

    public function emailAllowed(Person $person, string $type): bool
    {
        // Locked (transactional) types always send; toggleable ones honour the
        // person's per-type email preference, defaulting on. This is the only
        // email off-switch now - the old blanket "disabled account" flag is gone.
        return $this->emailPref($person, $type);
    }

    /**
     * The toggle positions for the UIs: the DM, email and app preference of each
     * type this person sees (independent of whether a channel could actually
     * reach them). The organizer-only rows are left out for everybody else.
     *
     * channels lists the channels the row offers (channelsFor()).
     *
     * @return array<string, array{label: string, dm: bool, email: bool, push: bool, channels: list<string>}>
     */
    public function matrix(Person $person): array
    {
        $out = [];

        foreach (self::labelsFor($person) as $type => $label) {
            $out[$type] = [
                'label' => $label,
                'dm' => $this->dmPref($person, $type),
                'email' => $this->emailPref($person, $type),
                'push' => $this->pushPref($person, $type),
                'channels' => self::channelsFor($type),
            ];
        }

        return $out;
    }

    /**
     * The full explicit map for a person, so a single toggle can be flipped and
     * saved without leaving the other types on their (possibly legacy-seeded)
     * defaults.
     *
     * @return array<string, array{dm: bool, email: bool, push: bool}>
     */
    public function asMap(Person $person): array
    {
        $map = [];

        foreach ($this->matrix($person) as $type => $row) {
            $map[$type] = ['dm' => $row['dm'], 'email' => $row['email'], 'push' => $row['push']];
        }

        return $map;
    }

    /**
     * The map to save from the web Notifications form: a ticked box is on, an
     * unticked one off. Only the rows this person sees, so a non-organizer
     * cannot set the organizer-only ones.
     *
     * @param array<mixed> $posted prefs[<type>][dm|email|push] from the form
     * @return array<string, array{dm: bool, email: bool, push: bool}>
     */
    public static function fromForm(Person $person, array $posted): array
    {
        $map = [];

        foreach (array_keys(self::labelsFor($person)) as $type) {
            $row = is_array($posted[$type] ?? null) ? $posted[$type] : [];
            $map[$type] = ['dm' => isset($row['dm']), 'email' => isset($row['email']), 'push' => isset($row['push'])];
        }

        return $map;
    }

    /**
     * The stored prefs with the app switch written into every row, organizer
     * rows included, for the one-time update that added the app channel
     * (schema 38). Owner 2026-11-12: an existing account starts on the most
     * restrictive choice it already made, so app is on only where both the
     * Telegram and the email switch are on - read as matrix() reads them
     * today, legacy "mute all DMs" seeding and defaults included. A row that
     * already has an app value is left alone. New accounts get no stored
     * value and so the default, on, like the other channels.
     *
     * @return array<string, array<string, bool>>
     */
    public function withPushSeeded(Person $person): array
    {
        $prefs = $person->notifyPrefs;

        foreach (self::types() as $type) {
            $row = is_array($prefs[$type] ?? null) ? $prefs[$type] : [];

            if (! array_key_exists('push', $row)) {
                $row['push'] = $this->dmPref($person, $type) && $this->emailPref($person, $type);
            }

            $prefs[$type] = $row;
        }

        return $prefs;
    }

    /**
     * Every toggleable type, organizer-only ones included, without loading
     * the translations (the schema update runs before init). The keys of
     * labels(true), in the same order.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            self::STANDING,
            self::CREDIT,
            self::TICKET,
            self::PERMISSION,
            self::OPEN_TASK,
            self::GROUP_CHAT,
            self::ROLE_NEWS,
            self::ROLE_MET,
            self::CHAT_FORWARD,
            self::LIST_IMPORT,
            self::NEWCOMER,
        ];
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

        if (! in_array('dm', self::channelsFor($type), true)) {
            return false;
        }

        return (bool) ($person->notifyPrefs[$type]['dm'] ?? ! $person->notifyMuted);
    }

    private function emailPref(Person $person, string $type): bool
    {
        if (! self::isToggleable($type)) {
            return true;
        }

        if (! in_array('email', self::channelsFor($type), true)) {
            return false;
        }

        return (bool) ($person->notifyPrefs[$type]['email'] ?? true);
    }

    /**
     * The app preference alone (no device check): locked types always on; a
     * toggleable one on unless switched off. Every account from before the
     * app channel has it stored (withPushSeeded()), so the default here is
     * a new account's.
     */
    private function pushPref(Person $person, string $type): bool
    {
        if (! self::isToggleable($type)) {
            return true;
        }

        // The group chat starts off: a notice per group message is opt-in.
        return (bool) ($person->notifyPrefs[$type]['push'] ?? self::GROUP_CHAT !== $type);
    }

    /** Any type with a row, the organizer-only ones included. */
    private static function isToggleable(string $type): bool
    {
        return in_array($type, self::types(), true);
    }
}

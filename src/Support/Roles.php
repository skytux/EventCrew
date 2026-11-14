<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Task;

/**
 * The kinds of job a task can be - the role someone signs up to fill.
 *
 * Ships opinionated - Decorate, Welcome, Clean, with Decorate capped at two
 * people - because that is the actual shape of a dance event, and a fresh
 * install should be usable without visiting Settings first. The list is
 * editable, but nothing else in the plugin branches on which roles exist.
 *
 * A role also carries an optional schedule: an anchor (the event's start or
 * its end) and two offsets in minutes from it. That is what lets a whole
 * evening's tasks be created from one event with their times already filled
 * in - decorating runs up to the doors opening, cleaning runs after the last
 * guest leaves. Roles whose timing genuinely varies leave the offsets unset
 * and produce tasks with no times, which is the same thing as before.
 *
 * A role can also carry a short description of what the job involves, shown
 * wherever someone decides whether to take it or is reminded of it, next to
 * one shared link to the crew's full guide.
 *
 * The name and the description are translated texts (TranslatedText), kept in
 * label_text and description_text. label and description are those texts in
 * the reader's language (Language::current(), else the main language), so
 * every reader gets its own language without asking. In the stored option,
 * label and description hold the source text, which is also what a role
 * stored before label_text existed is read from.
 *
 * The shape is declared once here and imported by everything that handles a
 * role, so adding a key is a one-line change rather than a hunt through
 * eight identical docblocks.
 *
 * @phpstan-type Role array{
 *     slug: string,
 *     label: string,
 *     emoji: string,
 *     capacity: int,
 *     archived: bool,
 *     anchor: string,
 *     start_offset: int|null,
 *     end_offset: int|null,
 *     description: string,
 *     label_text: array<string, mixed>,
 *     description_text: array<string, mixed>,
 *     restricted: bool,
 *     every_night: bool,
 *     sales_desk: bool,
 *     on_board: bool,
 *     guest_signup: bool,
 *     anonymous: bool,
 *     members_display: bool,
 *     title_contains: string,
 *     plus_ones: int,
 *     conditions: array{counts: array<string, int>, min_roles: int, times_each: int}
 * }
 *
 * A role can be restricted ("Only people with this role"): DJ, VJ, ceremony
 * leader and the like. Only a person given that role may take its tasks (and
 * see them, unless Members display is on: then every signed-in member sees
 * them, without a sign-up button), there is no limit on how many people take one, and such a task is
 * never counted as a place that needs filling - the same rules the reserved
 * Organize role has always had, with "is an organizer" replaced by "has this
 * role". A restricted role with every_night on gets one task on every night.
 *
 * The display ticks (on_board, members_display, guest_signup, anonymous)
 * decide who else sees a role's tasks and may sign up; BoardVisibility
 * explains them and applies them to every board and list.
 *
 * Two more settings ride on every role. sales_desk: people on that night's
 * task of this role may open the sales desk and get its link (Welcome ships
 * with it on). conditions: how much someone has done before the organizers
 * are told they could be given this role - a count of completed tasks in named
 * roles, and/or a number of different roles done a number of times each. The
 * conditions never let anyone sign up; they only drive a notice to the
 * organizers and a list in wp-admin and the bot's /people. See RoleConditions.
 */
final class Roles
{
    public const OPTION_NAME = 'eventcrew_roles';

    /**
     * The reserved role for organizing the night. It is not in the editable
     * role list: the plugin adds one per event. Only
     * organizers see it or may take it, and it has no limit on how many people
     * take it, so it is never full and never counted as a place that needs
     * filling. See Task::isOrganizersOnly() and Task::hasNoLimit().
     */
    public const ORGANIZE_SLUG = 'organize';

    /**
     * The reserved Organize role is not in the role list, so its "+1 allowed"
     * default is an option of its own. 1 until it is saved.
     */
    public const ORGANIZE_PLUS_ONES_OPTION = 'eventcrew_organize_plus_ones';

    /** The shipped door role: its crew checks tickets, so it ships with sales_desk on. */
    public const WELCOME_SLUG = 'welcome';

    /** One link to the crew's full guide, shared by every role. */
    public const GUIDE_URL_OPTION = 'eventcrew_guide_url';

    /** Offsets are measured from when the event starts. */
    public const ANCHOR_START = 'start';

    /** Offsets are measured from when the event ends. */
    public const ANCHOR_END = 'end';

    /**
     * @return array<int, Role>
     */
    public static function defaults(): array
    {
        return [
            // Decorating finishes as the doors open, so it hangs off the start.
            [
                'slug' => 'decorate',
                'label' => __('Decorate', 'eventcrew'),
                'emoji' => '🎈',
                'capacity' => 2,
                'archived' => false,
                'restricted' => false,
                'every_night' => true,
                'sales_desk' => false,
                'title_contains' => '',
                'plus_ones' => 0,
                'conditions' => self::noConditions(),
                'anchor' => self::ANCHOR_START,
                'start_offset' => -120,
                'end_offset' => 0,
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                'description' => __('Set up before the dance: close the curtains, check the bathrooms, place the LED candles, decorate the DJ table, prepare the water and fruit, and open the movable walls.', 'eventcrew'),
            ],
            // Welcome straddles the doors opening: a little before, an hour in.
            [
                'slug' => 'welcome',
                'label' => __('Welcome', 'eventcrew'),
                'emoji' => '🙋',
                'capacity' => 3,
                'archived' => false,
                'restricted' => false,
                'every_night' => true,
                'sales_desk' => true,
                'title_contains' => '',
                'plus_ones' => 0,
                'conditions' => self::noConditions(),
                'anchor' => self::ANCHOR_START,
                'start_offset' => -30,
                'end_offset' => 60,
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                'description' => __('Welcome people at the door, check and sell tickets, and count how many people come in.', 'eventcrew'),
            ],
            // Cleaning starts when the event ends, which is the whole reason
            // an end anchor exists - it is the task that crosses midnight.
            [
                'slug' => 'clean',
                'label' => __('Clean', 'eventcrew'),
                'emoji' => '🧹',
                'capacity' => 3,
                'archived' => false,
                'restricted' => false,
                'every_night' => true,
                'sales_desk' => false,
                'title_contains' => '',
                'plus_ones' => 0,
                'conditions' => self::noConditions(),
                'anchor' => self::ANCHOR_END,
                'start_offset' => 0,
                'end_offset' => 60,
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                'description' => __('Pack up after the dance: collect the candles and decorations, clear away the water and snacks, empty full bins, close the movable walls, open the curtains, and do the final check.', 'eventcrew'),
            ],
        ];
    }

    /**
     * Every role, archived ones included.
     *
     * Archived roles have to stay readable: tasks store a role slug, so a
     * roster or a credit history from three months ago still needs to resolve
     * "decorate" to "Decorate" long after the role stopped being offered.
     *
     * @return array<int, Role>
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION_NAME, null);

        if (! is_array($stored) || [] === $stored) {
            $stored = self::defaults();
        }

        $roles = [];

        foreach ($stored as $role) {
            if (! is_array($role)) {
                continue;
            }

            $slug = sanitize_key((string) ($role['slug'] ?? ''));

            if ('' === $slug) {
                continue;
            }

            $roles[] = self::normalize($role, $slug);
        }

        if ([] === $roles) {
            foreach (self::defaults() as $role) {
                $roles[] = self::normalize($role, $role['slug']);
            }
        }

        return $roles;
    }

    /**
     * The roles currently on offer - what a task form or a template should
     * show. Everything that presents a choice uses this; everything that
     * resolves a stored slug uses all().
     *
     * @return array<int, Role>
     */
    public static function active(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (array $role): bool => false === $role['archived']
        ));
    }

    /**
     * @return array<int, Role>
     */
    public static function archived(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (array $role): bool => true === $role['archived']
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $roles
     */
    public static function save(array $roles): void
    {
        $clean = [];
        $seen = [];

        foreach ($roles as $role) {
            $slug = sanitize_key((string) ($role['slug'] ?? ''));
            $label = trim((string) ($role['label'] ?? ''));

            // A row with neither a slug nor a label is an empty "add another"
            // line the organizer left untouched, not an error worth reporting.
            if ('' === $slug && '' === $label) {
                continue;
            }

            if ('' === $slug) {
                $slug = sanitize_key($label);
            }

            // The organize slug is reserved and auto-managed; never let a
            // hand-typed role take it over.
            if ('' === $slug || self::ORGANIZE_SLUG === $slug || isset($seen[$slug])) {
                continue;
            }

            $seen[$slug] = true;

            $clean[] = self::forStorage(self::normalize($role, $slug));
        }

        $before = self::everyNightSlugs();
        update_option(self::OPTION_NAME, [] === $clean ? self::defaults() : $clean);
        self::queueNewEveryNight($before);
    }

    /**
     * A role as it is stored: label and description hold the source texts,
     * not the reader's language.
     *
     * @param Role $role
     * @return Role
     */
    private static function forStorage(array $role): array
    {
        $label = trim((string) ($role['label_text']['source'] ?? ''));
        $role['label'] = '' === $label ? $role['slug'] : $label;
        $role['description'] = trim((string) ($role['description_text']['source'] ?? ''));

        return $role;
    }

    /**
     * @return array<int, string>
     */
    private static function everyNightSlugs(): array
    {
        return array_map(static fn (array $role): string => $role['slug'], self::everyNight());
    }

    /**
     * Hands every role that has just become an every-night restricted role
     * (newly restricted, switched to every night, or restored from the archive)
     * to the backfill, which adds its task to the upcoming nights.
     *
     * @param array<int, string> $before
     */
    private static function queueNewEveryNight(array $before): void
    {
        OrganizeTaskBackfill::queue(array_values(array_diff(self::everyNightSlugs(), $before)));
    }

    /**
     * Takes a role out of circulation without destroying it.
     *
     * Deleting instead would leave every task ever created with that slug
     * pointing at nothing: label() falls back to the raw slug, so a roster
     * would quietly start reading "decorate" instead of "🎈 Decorate", and
     * the v0.5 credit history would lose the meaning of what was earned.
     * Nothing about a role that has been used is safe to throw away.
     */
    public static function archive(string $slug): void
    {
        self::setArchived($slug, true);
    }

    public static function restore(string $slug): void
    {
        self::setArchived($slug, false);
    }

    private static function setArchived(string $slug, bool $archived): void
    {
        $before = self::everyNightSlugs();
        $roles = self::all();

        foreach ($roles as $index => $role) {
            if ($role['slug'] === $slug) {
                $roles[$index]['archived'] = $archived;
            }
        }

        update_option(self::OPTION_NAME, array_map(static fn (array $role): array => self::forStorage($role), $roles));
        self::queueNewEveryNight($before);
    }

    /** Ways a role moves in the order (move()). */
    public const MOVES = ['up', 'down', 'first', 'last'];

    /**
     * Moves a role one place up or down, or to the top or the bottom, of the
     * role list. The list's order is the order every night's tasks are
     * listed in (TaskOrder), so this is what reorders the board, the open
     * tasks call and every other list of a night's tasks. Archived roles keep
     * their place too, so restoring one puts it back where it was.
     *
     * @return bool Whether the order changed: false for an unknown role or
     *              way, or a role already at that end.
     */
    public static function move(string $slug, string $where): bool
    {
        $roles = self::all();
        $from = null;

        foreach ($roles as $index => $role) {
            if ($role['slug'] === $slug) {
                $from = $index;
            }
        }

        if (null === $from || ! in_array($where, self::MOVES, true)) {
            return false;
        }

        $last = count($roles) - 1;
        $to = match ($where) {
            'up' => max(0, $from - 1),
            'down' => min($last, $from + 1),
            'first' => 0,
            default => $last,
        };

        if ($to === $from) {
            return false;
        }

        $moved = array_splice($roles, $from, 1);
        array_splice($roles, $to, 0, $moved);

        update_option(self::OPTION_NAME, array_map(static fn (array $role): array => self::forStorage($role), $roles));

        return true;
    }

    /**
     * The role order as one line per role, for the bot's /roleorder and the
     * helper: "1. 🎈 Decorate (decorate)", archived ones marked.
     */
    public static function orderText(): string
    {
        $lines = [];

        foreach (self::all() as $index => $role) {
            $lines[] = ($index + 1) . '. ' . self::display($role['slug']) . ' (' . $role['slug'] . ')'
                . ($role['archived'] ? ' · ' . __('Archived', 'eventcrew') : '');
        }

        return implode("\n", $lines);
    }

    /**
     * Every role's name and description as rows of the wp-admin Translations
     * page (TranslationRegistry).
     *
     * @return list<TranslationRow>
     */
    public static function translationRows(): array
    {
        $rows = [];

        foreach (self::all() as $role) {
            if (self::ORGANIZE_SLUG === $role['slug']) {
                continue;
            }

            $slug = $role['slug'];
            $rows[] = new TranslationRow(
                'role.' . $slug . '.label',
                /* translators: %s: the role's name, e.g. Decorate */
                sprintf(__('Role name: %s', 'eventcrew'), $role['label']),
                TranslationRow::GROUP_TEXTS,
                $role['label_text'],
                static function (array $value) use ($slug): void {
                    self::storeText($slug, 'label_text', $value);
                },
                0,
                false
            );
            $rows[] = new TranslationRow(
                'role.' . $slug . '.description',
                /* translators: %s: the role's name, e.g. Decorate */
                sprintf(__('Role description: %s', 'eventcrew'), $role['label']),
                TranslationRow::GROUP_TEXTS,
                $role['description_text'],
                static function (array $value) use ($slug): void {
                    self::storeText($slug, 'description_text', $value);
                },
                0,
                true
            );
        }

        return $rows;
    }

    /**
     * Stores one role's name or description (label_text or
     * description_text), as edited on the Translations page.
     *
     * @param array<string, mixed> $value A TranslatedText value.
     */
    private static function storeText(string $slug, string $key, array $value): void
    {
        $roles = self::all();

        foreach ($roles as $index => $role) {
            if ($role['slug'] === $slug) {
                $roles[$index][$key] = TranslatedText::normalize($value);
            }
        }

        update_option(self::OPTION_NAME, array_map(static fn (array $role): array => self::forStorage($role), $roles));
    }

    /**
     * @return Role|null
     */
    public static function find(string $slug): ?array
    {
        foreach (self::all() as $role) {
            if ($role['slug'] === $slug) {
                return $role;
            }
        }

        return null;
    }

    public static function label(string $slug): string
    {
        if (self::ORGANIZE_SLUG === $slug) {
            return __('Organize', 'eventcrew');
        }

        return self::find($slug)['label'] ?? $slug;
    }

    /**
     * Label with its emoji, for the Telegram board and the roster heading.
     */
    public static function display(string $slug): string
    {
        if (self::ORGANIZE_SLUG === $slug) {
            return __('⭐ Organize', 'eventcrew');
        }

        $role = self::find($slug);

        if (null === $role) {
            return $slug;
        }

        return '' === $role['emoji']
            ? $role['label']
            : $role['emoji'] . ' ' . $role['label'];
    }

    /**
     * What the job involves, in a sentence or two; '' when none was written.
     */
    public static function description(string $slug): string
    {
        return self::find($slug)['description'] ?? '';
    }

    /**
     * The crew's full guide, or '' when no link has been set.
     */
    public static function guideUrl(): string
    {
        return trim((string) get_option(self::GUIDE_URL_OPTION, ''));
    }

    public static function defaultCapacity(string $slug): int
    {
        return self::find($slug)['capacity'] ?? 1;
    }

    /**
     * How many guests a new task of this role allows, before anyone edits the
     * task: 0 for a role that does not exist.
     */
    public static function defaultPlusOnes(string $slug): int
    {
        if (self::ORGANIZE_SLUG === $slug) {
            $stored = get_option(self::ORGANIZE_PLUS_ONES_OPTION, '');

            return Task::clampPlusOnes(is_numeric($stored) ? (int) $stored : 1);
        }

        return self::find($slug)['plus_ones'] ?? 0;
    }

    /**
     * Whether the role exists at all, archived or not.
     *
     * Editing a task that was created under a since-archived role must keep
     * working, so validation asks this rather than checking active().
     */
    public static function exists(string $slug): bool
    {
        return self::ORGANIZE_SLUG === $slug || null !== self::find($slug);
    }

    public static function isArchived(string $slug): bool
    {
        return self::find($slug)['archived'] ?? false;
    }

    /**
     * Whether only some people may see and take this role's tasks: Organize
     * (organizers) and every role set to "Only people with this role".
     * Archived restricted roles stay restricted, so their old tasks never
     * open up to everyone.
     */
    public static function isRestricted(string $slug): bool
    {
        if (self::ORGANIZE_SLUG === $slug) {
            return true;
        }

        return self::find($slug)['restricted'] ?? false;
    }

    /**
     * The slugs of every restricted role, Organize first. Never empty.
     *
     * @return array<int, string>
     */
    public static function restrictedSlugs(): array
    {
        $slugs = [self::ORGANIZE_SLUG];

        foreach (self::all() as $role) {
            if ($role['restricted']) {
                $slugs[] = $role['slug'];
            }
        }

        return $slugs;
    }

    /**
     * The roles a person can be given (restricted, editable ones; Organize is
     * not here because it follows the organizer permission). Archived ones
     * are left out: nobody new should be handed a role no longer offered.
     *
     * @return array<int, Role>
     */
    public static function assignable(): array
    {
        return array_values(array_filter(
            self::active(),
            static fn (array $role): bool => $role['restricted']
        ));
    }

    /**
     * The active roles a night's tasks are built from by the template: every
     * active role except the restricted ones, which are added on their own
     * (see everyNight()).
     *
     * @return array<int, Role>
     */
    public static function forTemplate(): array
    {
        return array_values(array_filter(
            self::active(),
            static fn (array $role): bool => ! $role['restricted']
        ));
    }

    /**
     * The active restricted roles set to "Add to every night".
     *
     * @return array<int, Role>
     */
    public static function everyNight(): array
    {
        return array_values(array_filter(
            self::active(),
            static fn (array $role): bool => $role['restricted'] && $role['every_night']
        ));
    }

    /**
     * Whether people on a night's task of this role may open the sales desk.
     * Archived roles keep their setting, so an old night's crew is judged the
     * same way it was on the night.
     */
    public static function opensSalesDesk(string $slug): bool
    {
        return self::find($slug)['sales_desk'] ?? false;
    }

    /**
     * Whether this role's tasks are listed on the Telegram group board. On
     * unless switched off; a task of an unknown role is listed.
     */
    public static function onBoard(string $slug): bool
    {
        return self::find($slug)['on_board'] ?? true;
    }

    /**
     * Guest sign up: whether people without this role may sign up for its
     * tasks. Only means something while Guest display (onBoard()) or Members
     * display (membersDisplay()) is on; a task of an unknown role is open as
     * before.
     */
    public static function guestSignup(string $slug): bool
    {
        return self::find($slug)['guest_signup'] ?? true;
    }

    /**
     * Anonymous display: whether the boards hide who is signed up for this
     * role's tasks from people without the role. Off unless switched on.
     */
    public static function anonymous(string $slug): bool
    {
        return self::find($slug)['anonymous'] ?? false;
    }

    /**
     * Members display: whether every signed-in member (a known person: signed
     * in on the web, or reading their own copy of the board or a list in a
     * private chat) sees this role's tasks, also without the role. The
     * Telegram group board and the web page signed out still follow Guest
     * display only. Off unless switched on.
     */
    public static function membersDisplay(string $slug): bool
    {
        return self::find($slug)['members_display'] ?? false;
    }

    /**
     * The conditions set on a role; all zero when none are set or the role
     * does not exist.
     *
     * @return array{counts: array<string, int>, min_roles: int, times_each: int}
     */
    public static function conditions(string $slug): array
    {
        return self::find($slug)['conditions'] ?? self::noConditions();
    }

    /**
     * Whether a conditions array asks for anything at all.
     *
     * @param array{counts: array<string, int>, min_roles: int, times_each: int} $conditions
     */
    public static function hasConditions(array $conditions): bool
    {
        return [] !== $conditions['counts'] || $conditions['min_roles'] > 0;
    }

    /**
     * @return array{counts: array<string, int>, min_roles: int, times_each: int}
     */
    public static function noConditions(): array
    {
        return ['counts' => [], 'min_roles' => 0, 'times_each' => 0];
    }

    /**
     * Fills in every key with a sane value, so no consumer has to guard
     * against a role stored before these fields existed.
     *
     * @param array<string, mixed> $role
     * @return Role
     */
    private static function normalize(array $role, string $slug): array
    {
        // The translated name and description. A role stored before these
        // existed has plain strings, which become the source texts.
        $labelText = TranslatedText::normalize(
            is_array($role['label_text'] ?? null) ? $role['label_text'] : trim((string) ($role['label'] ?? ''))
        );
        $descriptionText = TranslatedText::normalize(match (true) {
            is_array($role['description_text'] ?? null) => $role['description_text'],
            array_key_exists('description', $role) => trim((string) $role['description']),
            default => self::defaultDescription($slug),
        });
        $label = TranslatedText::forReader($labelText);
        $anchor = (string) ($role['anchor'] ?? self::ANCHOR_START);

        return [
            'slug' => $slug,
            'label' => '' === $label ? $slug : $label,
            'label_text' => $labelText,
            'description_text' => $descriptionText,
            'emoji' => trim((string) ($role['emoji'] ?? '')),
            // Zero is allowed and meaningful: a role can be defined but generate
            // no active slots, so its tasks stay on the books yet drop off the
            // boards (capacity 0 is filtered out of the open-task lists), the
            // same way a hand-edited task set to 0 does. Negative is nonsense.
            'capacity' => max(0, (int) ($role['capacity'] ?? 1)),
            'archived' => ! empty($role['archived']),
            'anchor' => self::ANCHOR_END === $anchor ? self::ANCHOR_END : self::ANCHOR_START,
            'start_offset' => self::nullableOffset($role['start_offset'] ?? null),
            'end_offset' => self::nullableOffset($role['end_offset'] ?? null),
            'description' => TranslatedText::forReader($descriptionText),
            'restricted' => ! empty($role['restricted']),
            // On unless switched off: a role stored before this key existed
            // was never restricted, so the default only matters once it is.
            'every_night' => ! array_key_exists('every_night', $role) || ! empty($role['every_night']),
            // A role stored before this key existed: only Welcome opened the
            // sales desk then, so it keeps doing so until the setting is saved.
            'sales_desk' => array_key_exists('sales_desk', $role)
                ? ! empty($role['sales_desk'])
                : self::WELCOME_SLUG === $slug,
            'conditions' => self::normalizeConditions($role['conditions'] ?? null, $slug),
            // On unless switched off: every role stored before this key
            // existed was on the board.
            'on_board' => ! array_key_exists('on_board', $role) || ! empty($role['on_board']),
            // On unless switched off: before this key existed, anyone could
            // sign up for a task shown on the guest board.
            'guest_signup' => ! array_key_exists('guest_signup', $role) || ! empty($role['guest_signup']),
            // Off unless switched on: the boards showed names before.
            'anonymous' => ! empty($role['anonymous']),
            // Off unless switched on: members without the role saw only the
            // guest board's tasks before.
            'members_display' => ! empty($role['members_display']),
            // Empty means every event, which is what a role stored before
            // this key existed was added to.
            'title_contains' => trim((string) ($role['title_contains'] ?? '')),
            // How many guests someone on a task of this role may bring: the
            // number new tasks of this role start with. 0 for a role stored
            // before this key existed.
            'plus_ones' => Task::clampPlusOnes((int) ($role['plus_ones'] ?? 0)),
        ];
    }

    /**
     * Whether new events get a task of this role, judged by the event's
     * title: a role with title text only goes to events whose title contains
     * that text, ignoring upper and lower case. A role with none goes to
     * every event.
     *
     * @param Role $role
     */
    public static function fitsEvent(array $role, string $eventTitle): bool
    {
        $needle = $role['title_contains'];

        return '' === $needle || false !== mb_stripos($eventTitle, $needle);
    }

    /**
     * Counts are whole numbers above zero; a count for the role itself or for
     * an empty slug is dropped. "At least n roles, x times each" needs both
     * numbers, so either one at zero clears both.
     *
     * @return array{counts: array<string, int>, min_roles: int, times_each: int}
     */
    private static function normalizeConditions(mixed $conditions, string $slug): array
    {
        if (! is_array($conditions)) {
            return self::noConditions();
        }

        $counts = [];

        foreach ((array) ($conditions['counts'] ?? []) as $countSlug => $count) {
            $countSlug = sanitize_key((string) $countSlug);
            $count = (int) $count;

            if ('' !== $countSlug && $countSlug !== $slug && $count > 0) {
                $counts[$countSlug] = $count;
            }
        }

        $minRoles = max(0, (int) ($conditions['min_roles'] ?? 0));
        $timesEach = max(0, (int) ($conditions['times_each'] ?? 0));

        if (0 === $minRoles || 0 === $timesEach) {
            $minRoles = 0;
            $timesEach = 0;
        }

        return ['counts' => $counts, 'min_roles' => $minRoles, 'times_each' => $timesEach];
    }

    /**
     * A role saved before descriptions existed has no description key at all,
     * which is not the same as one an organizer cleared: the first picks up the
     * shipped text for its slug, the second stays blank.
     */
    private static function defaultDescription(string $slug): string
    {
        foreach (self::defaults() as $role) {
            if ($role['slug'] === $slug) {
                return $role['description'];
            }
        }

        return '';
    }

    /**
     * An offset of zero is meaningful - "starts exactly when the event ends" -
     * so only null and the empty string count as "no offset set", never 0.
     */
    private static function nullableOffset(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (int) $value;
    }
}

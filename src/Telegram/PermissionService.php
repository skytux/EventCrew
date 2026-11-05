<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\AccessNotifier;
use EventCrew\Support\RoleConditions;
use EventCrew\Support\RoleNotifier;
use EventCrew\Support\Roles;
use EventCrew\Support\Organization;

/**
 * The organizer's permission tools from Telegram, the 🏷 and ✅ buttons under /people.
 *
 * 🏷 Roles, organizers and passes manages one person's roles: a
 * one-time at-risk pass (sign up once despite being at risk), organizer, and
 * each role set to "Only people with this role" in Settings > Roles (DJ, VJ,
 * Crew leader, ...). The flow mirrors 🎁 Give credits and 🔁 Take over a task: ask who, send a name
 * or @mention, then one message per match with a button per role; a check mark
 * shows what the person has, and a tap switches it on or off. ✅ Who may hold
 * each role lists, for each role with conditions, who
 * meets them and whether they have the role. Both are admin-only, answered in
 * the DM with a breadcrumb when asked in a group.
 */
final class PermissionService
{
    use GroupBreadcrumb;

    private const AWAIT_PREFIX = 'eventcrew_tg_await_allow_target_';

    /** Callback data prefix for a permission tap: perm:<action>:<person_id>. */
    private const PICK_PREFIX = 'perm:';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram,
        private readonly RoleConditions $conditions
    ) {
    }

    public function start(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        $organizer = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $organizer || ! $organizer->isOrganizer) {
            $this->telegram->sendMessage($chatId, __('Only organizers can change roles.', 'eventcrew'));

            return;
        }

        set_transient(self::AWAIT_PREFIX . $telegramUserId, true, 15 * MINUTE_IN_SECONDS);
        $this->telegram->sendMessage(
            $telegramUserId,
            __('Whose roles do you want to change? Send their name, or @mention them.', 'eventcrew'),
            MenuButtons::cancel()
        );
        $this->sentDmNote($chatId, $isPrivate);
    }

    public function isAwaitingTarget(int $telegramUserId): bool
    {
        return false !== get_transient(self::AWAIT_PREFIX . $telegramUserId);
    }

    /**
     * The Cancel button: forgets the "whose roles?" question. True when one was waiting.
     */
    public function cancelPending(int $telegramUserId): bool
    {
        $waiting = $this->isAwaitingTarget($telegramUserId);
        delete_transient(self::AWAIT_PREFIX . $telegramUserId);

        return $waiting;
    }

    /**
     * ✅ Who may hold each role: for each role with conditions, who meets them, with ✅ on
     * those who already have the role. Answered privately, admin-only.
     */
    public function onEligible(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        $organizer = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $organizer || ! $organizer->isOrganizer) {
            $this->telegram->sendMessage(
                $chatId,
                __('Only organizers can see who meets the role conditions.', 'eventcrew')
            );

            return;
        }

        $report = $this->conditions->report();

        if ([] === $report) {
            $lines = [
                __('No role has conditions set. Organizers set them in wp-admin, under Settings, Roles.', 'eventcrew'),
            ];
        } else {
            $lines = [
                __('Who meets the conditions for each role. ✅ = they have the role. Give a role with /people.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            ];

            foreach ($report as $slug => $rows) {
                $slug = (string) $slug;
                $lines[] = '';
                $lines[] = Roles::display($slug) . ' (' . RoleConditions::describe(Roles::conditions($slug)) . '):';

                if ([] === $rows) {
                    $lines[] = __('— nobody yet', 'eventcrew');
                }

                foreach ($rows as $row) {
                    $lines[] = '• ' . $row['person']->name() . ($row['holds'] ? ' ✅' : '');
                }
            }
        }

        $this->telegram->sendMessage($telegramUserId, implode("\n", $lines));
        $this->sentDmNote($chatId, $isPrivate);
    }

    /**
     * The reply naming whose roles to change. Each match gets its own message:
     * their name, and a button per role with ✅ on the ones they have.
     *
     * @param array<int, array<string, mixed>> $entities
     */
    public function captureTarget(int $telegramUserId, int $chatId, string $text, array $entities): void
    {
        delete_transient(self::AWAIT_PREFIX . $telegramUserId);

        $matches = PersonResolver::matching($this->people, $text, $entities);

        if ([] === $matches) {
            $this->telegram->sendMessage(
                $chatId,
                __('I couldn’t find anyone by that name. Try their exact name or @mention them.', 'eventcrew')
            );

            return;
        }

        foreach ($matches as $person) {
            $this->telegram->sendMessage($chatId, $this->menuText($person), $this->menuKeyboard($person));
        }
    }

    /**
     * The text over one person's role buttons.
     */
    private function menuText(Person $person): string
    {
        return sprintf(
            /* translators: %s: person's name */
            __("Roles for %s. Tap a role to give it or take it away. ✅ = they have it.\n🎟 gives a one-time pass to sign up while at risk. It only gives a pass; it cannot take one away.", 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            $person->name()
        );
    }

    /**
     * One person's role buttons, two to a row: the one-time pass, organizer,
     * then every active restricted role.
     *
     * @return array<string, mixed>
     */
    private function menuKeyboard(Person $person): array
    {
        $mark = static fn (bool $on): string => $on ? '✅ ' : '';
        $prefix = self::PICK_PREFIX;
        $buttons = [
            [
                'text' => $mark($person->hasAtRiskPass) . '🎟 ' . __('Give a one-time pass', 'eventcrew'),
                'callback_data' => $prefix . 'pass:' . $person->id,
            ],
            [
                'text' => $mark($person->isOrganizer) . '⭐ ' . __('Organizer', 'eventcrew'),
                'callback_data' => $prefix . 'admin:' . $person->id,
            ],
        ];

        foreach (Roles::assignable() as $role) {
            $buttons[] = [
                'text' => $mark($person->hasRole($role['slug'])) . Roles::display($role['slug']),
                'callback_data' => $prefix . 'role:' . $role['slug'] . ':' . $person->id,
            ];
        }

        return ['inline_keyboard' => array_chunk($buttons, 2)];
    }

    /**
     * A permission tap (perm:<action>:<person_id>): re-check the organizer,
     * apply it, and tell the person.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onSelect(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $organizerTelegramId = (int) ($callbackQuery['from']['id'] ?? 0);
        [$action, $personId, $slug] = $this->parse((string) ($callbackQuery['data'] ?? ''));

        $organizer = 0 === $organizerTelegramId
            ? null
            : $this->people->findByTelegramUserId($organizerTelegramId);

        if (null === $organizer || ! $organizer->isOrganizer) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('Only organizers can change roles.', 'eventcrew'),
                true
            );

            return;
        }

        $person = 0 === $personId ? null : $this->people->find($personId);

        if ('' === $action) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('That button no longer works. Send /people again.', 'eventcrew'),
                true
            );

            return;
        }

        if (null === $person) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                sprintf(
                    /* translators: %s: the organization's name */
                    __('That person is no longer in %s. Send /people to pick someone else.', 'eventcrew'),
                    Organization::name()
                ),
                true
            );

            return;
        }

        // What is given fires its hook, and AccessNotifier tells the person.
        $answer = 'role' === $action
            ? $this->toggleRole($person, $slug, $organizer->name())
            : $this->apply($action, $person->id, $person->name(), $person->isOrganizer, $organizer->name());
        $this->telegram->answerCallbackQuery($callbackId, $answer);
        $this->refreshMenu($callbackQuery, $person->id);
    }

    /**
     * Redraws the tapped message's buttons, so the ✅ marks show the change.
     *
     * @param array<string, mixed> $callbackQuery
     */
    private function refreshMenu(array $callbackQuery, int $personId): void
    {
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $messageId = (int) ($callbackQuery['message']['message_id'] ?? 0);

        if (null === $chatId || 0 === $messageId) {
            return;
        }

        $person = $this->people->find($personId);

        if (null === $person) {
            return;
        }

        $this->telegram->editMessageText($chatId, $messageId, $this->menuText($person), $this->menuKeyboard($person));
    }

    /**
     * Gives or takes away one restricted role (DJ, VJ, ...). Only active
     * restricted roles are accepted, the ones the buttons offer. Returns the
     * organizer's confirmation.
     */
    private function toggleRole(Person $person, string $slug, string $giverName): string
    {
        if (! in_array($slug, array_column(Roles::assignable(), 'slug'), true)) {
            return __('That role is no longer offered.', 'eventcrew');
        }

        $label = Roles::label($slug);

        if ($person->hasRole($slug)) {
            $this->people->setRoles($person->id, array_values(array_diff($person->roleSlugs, [$slug])));

            /* translators: 1: person's name, 2: role name */
            return sprintf(__('%1$s no longer has the role %2$s.', 'eventcrew'), $person->name(), $label);
        }

        $this->people->setRoles($person->id, array_merge($person->roleSlugs, [$slug]));
        RoleNotifier::rolesChanged($person->id, $person->roleSlugs, [$slug], $giverName);

        /* translators: 1: person's name, 2: role name */
        return sprintf(__('%1$s now has the role %2$s.', 'eventcrew'), $person->name(), $label);
    }

    /**
     * Applies one permission action, returning the organizer's confirmation.
     */
    private function apply(string $action, int $personId, string $name, bool $isOrganizer, string $giverName): string
    {
        switch ($action) {
            case 'pass':
                $this->people->grantAtRiskPass($personId);
                AccessNotifier::passGranted($personId);

                /* translators: %s: person's name */
                return sprintf(__('%s has a one-time sign-up pass.', 'eventcrew'), $name);
            case 'admin':
                $this->people->setOrganizer($personId, ! $isOrganizer);
                do_action(CommandMenu::ORGANIZER_CHANGED_HOOK, $personId);

                if (! $isOrganizer) {
                    RoleNotifier::madeOrganizer($personId, $giverName);
                }

                /* translators: %s: person's name */
                $gone = __('%s is no longer an organizer.', 'eventcrew');
                /* translators: %s: person's name */
                $made = __('%s is now an organizer.', 'eventcrew');

                return sprintf($isOrganizer ? $gone : $made, $name);
            default:
                return __('That button no longer works. Send /people again.', 'eventcrew');
        }
    }

    /**
     * Splits "perm:<action>:<person_id>" or "perm:role:<slug>:<person_id>"
     * into the action, the person id and the role slug ('' for the others).
     *
     * @return array{0: string, 1: int, 2: string}
     */
    private function parse(string $data): array
    {
        $prefix = preg_quote(self::PICK_PREFIX, '#');

        if (1 === preg_match('#^' . $prefix . 'role:([a-z0-9_\-]+):(\d+)$#', $data, $m)) {
            return ['role', (int) $m[2], $m[1]];
        }

        if (1 !== preg_match('#^' . $prefix . '(pass|admin):(\d+)$#', $data, $m)) {
            return ['', 0, ''];
        }

        return [$m[1], (int) $m[2], ''];
    }
}

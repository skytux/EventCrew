<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\LeaderEligibility;
use EventCrew\Support\Mailer;
use EventCrew\Support\DmBody;
use EventCrew\Support\Language;
use EventCrew\Support\NotificationPreferences;
use EventCrew\Support\Roles;

/**
 * /roles and /leaders - the organizer's permission tools from Telegram.
 *
 * /roles (its old name /allow still works) manages one person's roles: leader
 * (may take the reserved leader slot), a one-time at-risk pass (sign up once
 * despite being at risk), organizer, and each role set to "Only people with
 * this role" in Settings > Roles (DJ, VJ, ...). The flow mirrors /gift and
 * /replace: ask who, send a name or @mention, then one message per match with
 * a button per role; a check mark shows what the person has, and a tap
 * switches it on or off. /leaders lists who is eligible and who has been
 * allowed. Both are admin-only, answered in the DM with a breadcrumb when
 * asked in a group.
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
        private readonly LeaderEligibility $eligibility,
        private readonly Mailer $mailer
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
            __('Whose roles do you want to change? Send their name, or @mention them.', 'eventcrew')
        );
        $this->sentDmNote($chatId, $isPrivate);
    }

    public function isAwaitingTarget(int $telegramUserId): bool
    {
        return false !== get_transient(self::AWAIT_PREFIX . $telegramUserId);
    }

    /**
     * /leaders: the organizer's read-out of who is eligible to lead and who has
     * been allowed. Answered privately, admin-only.
     */
    public function onLeaders(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        $organizer = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $organizer || ! $organizer->isOrganizer) {
            $this->telegram->sendMessage($chatId, __('Only organizers can see the leaders list.', 'eventcrew'));

            return;
        }

        $eligible = array_map(
            static fn ($person): string => '• ' . $person->name(),
            $this->eligibility->eligiblePeople()
        );

        $allowed = [];

        foreach ($this->people->all(['per_page' => 1000]) as $person) {
            if ($person->canLead()) {
                $allowed[] = '• ' . $person->name();
            }
        }

        $none = __('— none yet', 'eventcrew');
        $lines = ['🧭 ' . __('Crew leaders', 'eventcrew'), ''];
        $lines[] = __('Eligible (give them 👑 Leader with /roles):', 'eventcrew');
        $lines = array_merge($lines, [] === $eligible ? [$none] : $eligible);
        $lines[] = '';
        $lines[] = __('Allowed to lead:', 'eventcrew');
        $lines = array_merge($lines, [] === $allowed ? [$none] : $allowed);

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
            __("Roles for %s. Tap a role to give it or take it away. ✅ = they have it.\n🎟 gives a one-time pass to sign up while at risk.", 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            $person->name()
        );
    }

    /**
     * One person's role buttons, two to a row: leader, the one-time pass,
     * organizer, then every active restricted role.
     *
     * @return array<string, mixed>
     */
    private function menuKeyboard(Person $person): array
    {
        $mark = static fn (bool $on): string => $on ? '✅ ' : '';
        $prefix = self::PICK_PREFIX;
        $buttons = [
            [
                'text' => $mark($person->canLead()) . '👑 ' . __('Leader', 'eventcrew'),
                'callback_data' => $prefix . 'leader:' . $person->id,
            ],
            [
                'text' => $mark($person->hasAtRiskPass) . '🎟 ' . __('One-time pass', 'eventcrew'),
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

        if (null === $person || '' === $action) {
            $this->telegram->answerCallbackQuery($callbackId, __('That person is gone.', 'eventcrew'), true);

            return;
        }

        [$answer, $dmKind] = 'role' === $action
            ? $this->toggleRole($person, $slug)
            : $this->apply($action, $person->id, $person->name(), $person->canLead(), $person->isOrganizer);
        $this->telegram->answerCallbackQuery($callbackId, $answer);
        $this->refreshMenu($callbackQuery, $person->id);

        // Tell the affected person on both channels they allow, in their own
        // language rather than the organizer's.
        if ('' !== $dmKind) {
            Language::forPerson($person, fn () => $this->tellPerson($person, $dmKind));
        }
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
     * restricted roles are accepted, the ones the buttons offer.
     *
     * @return array{0: string, 1: string}
     */
    private function toggleRole(Person $person, string $slug): array
    {
        if (! in_array($slug, array_column(Roles::assignable(), 'slug'), true)) {
            return [__('That role is no longer offered.', 'eventcrew'), ''];
        }

        $label = Roles::label($slug);

        if ($person->hasRole($slug)) {
            $this->people->setRoles($person->id, array_values(array_diff($person->roleSlugs, [$slug])));

            /* translators: 1: person's name, 2: role name */
            return [sprintf(__('%1$s no longer has the role %2$s.', 'eventcrew'), $person->name(), $label), ''];
        }

        $this->people->setRoles($person->id, array_merge($person->roleSlugs, [$slug]));

        /* translators: 1: person's name, 2: role name */
        return [sprintf(__('%1$s now has the role %2$s.', 'eventcrew'), $person->name(), $label), 'role:' . $slug];
    }

    private function tellPerson(Person $person, string $dmKind): void
    {
        $dm = $this->dmText($dmKind);
        $prefs = new NotificationPreferences();

        if ($prefs->dmAllowed($person, NotificationPreferences::PERMISSION)) {
            // Unprompted from the affected person's side: an organizer, not
            // they, pressed the button that caused this.
            $this->telegram->sendMessage($person->telegramChatId, DmBody::frame($person, $dm));
        }

        if ($prefs->emailAllowed($person, NotificationPreferences::PERMISSION)) {
            $this->mailer->toPerson(
                $person->id,
                $person->email,
                __('Your EventCrew access changed', 'eventcrew'),
                sprintf(
                    /* translators: 1: name, 2: what changed */
                    __("Hi %1\$s,\n\n%2\$s", 'eventcrew'),
                    $person->name(),
                    $dm
                )
            );
        }
    }

    /**
     * Applies one permission action, returning the organizer's confirmation and
     * which message the affected person gets ('leader', 'pass', 'admin', or ''
     * for none; dmText() words it).
     *
     * @return array{0: string, 1: string}
     */
    private function apply(string $action, int $personId, string $name, bool $canLead, bool $isOrganizer): array
    {
        switch ($action) {
            case 'leader':
                $this->people->setCanLead($personId, ! $canLead);

                return $canLead
                    ? [sprintf(__('%s is no longer a leader.', 'eventcrew'), $name), '']
                    : [sprintf(__('%s can now lead an event.', 'eventcrew'), $name), 'leader'];
            case 'pass':
                $this->people->grantAtRiskPass($personId);

                return [sprintf(__('%s has a one-time sign-up pass.', 'eventcrew'), $name), 'pass'];
            case 'admin':
                $this->people->setOrganizer($personId, ! $isOrganizer);

                return $isOrganizer
                    ? [sprintf(__('%s is no longer an organizer.', 'eventcrew'), $name), '']
                    : [sprintf(__('%s is now an organizer.', 'eventcrew'), $name), 'admin'];
            default:
                return [__('Nothing to do.', 'eventcrew'), ''];
        }
    }

    /**
     * The message to the affected person for a permission they were given, in
     * whatever language is current; '' for none.
     */
    private function dmText(string $dmKind): string
    {
        if (str_starts_with($dmKind, 'role:')) {
            return sprintf(
                /* translators: %s: role name, such as DJ */
                __('You now have the role %s. Its tasks are shown to you with /roletasks in the bot and on the crew page, and you can sign up for them.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                Roles::label(substr($dmKind, strlen('role:')))
            );
        }

        return match ($dmKind) {
            'leader' => __('👑 You can now lead an event — tap the 🧭 Leader slot on the board to take it.', 'eventcrew'),
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            'pass' => __('🎟 You’ve a one-time pass to sign up for your next task, even while your standing is low.', 'eventcrew'),
            'admin' => __('⭐ You’re now an EventCrew organizer.', 'eventcrew'),
            default => '',
        };
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

        if (1 !== preg_match('#^' . $prefix . '(leader|pass|admin):(\d+)$#', $data, $m)) {
            return ['', 0, ''];
        }

        return [$m[1], (int) $m[2], ''];
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\LeaderEligibility;

/**
 * /allow and /leaders - the organizer's permission tools from Telegram.
 *
 * /allow grants leader (may take the reserved leader slot), a one-time at-risk
 * pass (sign up once despite being at risk), or admin (full organizer). The flow
 * mirrors /gift and /replace: ask who, send a name or @mention, tap a toggle.
 * /leaders lists who is eligible and who has been allowed. Both are admin-only,
 * answered in the DM with a breadcrumb when asked in a group.
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
        private readonly LeaderEligibility $eligibility
    ) {
    }

    public function start(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        $organizer = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $organizer || ! $organizer->isOrganizer) {
            $this->telegram->sendMessage($chatId, __('Only organizers can set permissions.', 'eventcrew'));

            return;
        }

        set_transient(self::AWAIT_PREFIX . $telegramUserId, true, 15 * MINUTE_IN_SECONDS);
        $this->telegram->sendMessage(
            $telegramUserId,
            __('Who should this apply to? Send their name, or @mention them.', 'eventcrew')
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
        $lines[] = __('Eligible (you can /allow them):', 'eventcrew');
        $lines = array_merge($lines, [] === $eligible ? [$none] : $eligible);
        $lines[] = '';
        $lines[] = __('Allowed to lead:', 'eventcrew');
        $lines = array_merge($lines, [] === $allowed ? [$none] : $allowed);

        $this->telegram->sendMessage($telegramUserId, implode("\n", $lines));
        $this->sentDmNote($chatId, $isPrivate);
    }

    /**
     * The reply naming who to allow. Offers each match a row of toggle buttons.
     *
     * @param array<int, array<string, mixed>> $entities
     */
    public function captureTarget(int $telegramUserId, int $chatId, string $text, array $entities): void
    {
        delete_transient(self::AWAIT_PREFIX . $telegramUserId);

        $buttons = [];

        foreach (PersonResolver::matching($this->people, $text, $entities) as $person) {
            $buttons[] = [
                ['text' => $person->name() . ' 👑', 'callback_data' => self::PICK_PREFIX . 'leader:' . $person->id],
                ['text' => $person->name() . ' 🎟', 'callback_data' => self::PICK_PREFIX . 'pass:' . $person->id],
                ['text' => $person->name() . ' ⭐', 'callback_data' => self::PICK_PREFIX . 'admin:' . $person->id],
            ];
        }

        if ([] === $buttons) {
            $this->telegram->sendMessage(
                $chatId,
                __('I couldn’t find anyone by that name. Try their exact name or @mention them.', 'eventcrew')
            );

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            __('Tap to toggle: 👑 leader · 🎟 one-time at-risk pass · ⭐ admin.', 'eventcrew'),
            ['inline_keyboard' => $buttons]
        );
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
        [$action, $personId] = $this->parse((string) ($callbackQuery['data'] ?? ''));

        $organizer = 0 === $organizerTelegramId
            ? null
            : $this->people->findByTelegramUserId($organizerTelegramId);

        if (null === $organizer || ! $organizer->isOrganizer) {
            $this->telegram->answerCallbackQuery($callbackId, __('Only organizers can set permissions.', 'eventcrew'), true);

            return;
        }

        $person = 0 === $personId ? null : $this->people->find($personId);

        if (null === $person || '' === $action) {
            $this->telegram->answerCallbackQuery($callbackId, __('That person is gone.', 'eventcrew'), true);

            return;
        }

        [$answer, $dm] = $this->apply($action, $person->id, $person->name(), $person->canLead(), $person->isOrganizer);
        $this->telegram->answerCallbackQuery($callbackId, $answer);

        if ('' !== $dm && null !== $person->telegramChatId && $person->wantsBotDms()) {
            $this->telegram->sendMessage($person->telegramChatId, $dm);
        }
    }

    /**
     * Applies one permission action, returning the organizer's confirmation and
     * the (optional) DM to the affected person.
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
                    : [
                        sprintf(__('%s can now lead an event.', 'eventcrew'), $name),
                        __('👑 You can now lead an event — tap the 🧭 Leader slot on the board to take it.', 'eventcrew'),
                    ];
            case 'pass':
                $this->people->grantAtRiskPass($personId);

                return [
                    sprintf(__('%s has a one-time sign-up pass.', 'eventcrew'), $name),
                    __('🎟 You’ve a one-time pass to sign up for your next task, even while your standing is low.', 'eventcrew'),
                ];
            case 'admin':
                $this->people->setOrganizer($personId, ! $isOrganizer);

                return $isOrganizer
                    ? [sprintf(__('%s is no longer an organizer.', 'eventcrew'), $name), '']
                    : [
                        sprintf(__('%s is now an organizer.', 'eventcrew'), $name),
                        __('⭐ You’re now an EventCrew organizer.', 'eventcrew'),
                    ];
            default:
                return [__('Nothing to do.', 'eventcrew'), ''];
        }
    }

    /**
     * Splits "perm:<action>:<person_id>" into the action and person id.
     *
     * @return array{0: string, 1: int}
     */
    private function parse(string $data): array
    {
        if (1 !== preg_match('#^' . preg_quote(self::PICK_PREFIX, '#') . '(leader|pass|admin):(\d+)$#', $data, $m)) {
            return ['', 0];
        }

        return [$m[1], (int) $m[2]];
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\Logger;
use EventCrew\Support\TelegramLogin;

/**
 * Who may talk to the bot in a private chat (Owner, 2026-11-07, Q354-Q358).
 *
 * A person who is not in the crew's Telegram group must not be able to open
 * the bot and sign up. Before a private message or a button tap in a private
 * chat is handled:
 *
 * - someone already linked to a crew account passes;
 * - someone who is in the crew group passes (getChatMember: creator,
 *   administrator, member, or restricted while still a member). The pass is
 *   kept for 24 hours per Telegram user, so the onboarding steps after
 *   /start do not ask Telegram again on every message;
 * - someone not in the group who opens the bot with a valid weekly code
 *   (StartCode) or a live crew-app sign-in code gets one message with a
 *   button to the group, and nothing else;
 * - anyone else gets no answer at all. A button tap is answered with no
 *   text, so the app stops its spinner.
 *
 * Group chats are not affected. The gate is off when the organizer turns it
 * off in Settings or when the site does not know the crew's group yet.
 * Nothing is stored about a person the gate stops.
 */
final class PrivateChatGate
{
    /** The wp-admin switch; on unless an organizer turns it off. */
    public const ENABLED_OPTION = 'eventcrew_telegram_group_gate';

    /** Transient per Telegram user: their group membership check passed. */
    public const PASS_PREFIX = 'eventcrew_gate_pass_';

    /** How long a passed check lets a person continue without a new one. */
    public const PASS_TTL = DAY_IN_SECONDS;

    /** Transients that keep each warning to one log line a day. */
    private const NO_GROUP_WARNED = 'eventcrew_gate_warn_nogroup';
    private const CHECK_FAILED_WARNED = 'eventcrew_gate_warn_check';
    private const NO_LINK_WARNED = 'eventcrew_gate_warn_nolink';

    /** The getChatMember statuses that count as being in the group. */
    private const IN_GROUP = ['creator', 'administrator', 'member', 'restricted'];

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly PersonRepository $people,
        private readonly Logger $logger,
        private readonly ?BoardService $board = null,
        private readonly ?TelegramLogin $login = null
    ) {
    }

    /** True when the organizer has the gate on (the default). */
    public static function isOn(): bool
    {
        return (bool) get_option(self::ENABLED_OPTION, true);
    }

    /** True when the gate is on and the site knows the crew's group. */
    public static function isActive(): bool
    {
        return self::isOn() && 0 !== GroupChat::current();
    }

    /**
     * True when the update may be handled. False when the gate stopped it;
     * then it has already given the only answer there is (none, an empty
     * callback answer, or the group button).
     *
     * @param array<string, mixed> $update
     */
    public function admits(array $update): bool
    {
        $callback = is_array($update['callback_query'] ?? null) ? $update['callback_query'] : null;
        $message = is_array($update['message'] ?? null) ? $update['message'] : null;

        if (null !== $callback) {
            $chatType = (string) ($callback['message']['chat']['type'] ?? 'private');
            $sender = (int) ($callback['from']['id'] ?? 0);
        } elseif (null !== $message) {
            $chatType = (string) ($message['chat']['type'] ?? '');
            $sender = (int) ($message['from']['id'] ?? 0);
        } else {
            return true;
        }

        if ('private' !== $chatType || $sender <= 0 || ! self::isOn()) {
            return true;
        }

        $groupId = GroupChat::current();

        if (0 === $groupId) {
            $this->warnOnce(
                self::NO_GROUP_WARNED,
                'Private chat gate is off: the crew group is not known yet.'
            );

            return true;
        }

        if (null !== $this->people->findByTelegramUserId($sender)) {
            return true;
        }

        if (false !== get_transient(self::PASS_PREFIX . $sender)) {
            return true;
        }

        if ($this->inGroup($groupId, $sender)) {
            set_transient(self::PASS_PREFIX . $sender, 1, self::PASS_TTL);

            return true;
        }

        if (null !== $callback) {
            $this->telegram->answerCallbackQuery((string) ($callback['id'] ?? ''));

            return false;
        }

        if (null !== $message && $this->carriesValidCode((string) ($message['text'] ?? ''))) {
            $this->sendGroupButton((int) ($message['chat']['id'] ?? $sender));
        }

        return false;
    }

    /** The text of the one message a person with a code but not in the group gets. */
    public static function joinGroupText(): string
    {
        return __('To join the crew, open the crew group first:', 'eventcrew');
    }

    /** The button under it. */
    public static function joinGroupButton(): string
    {
        return __('👥 Open the crew group', 'eventcrew');
    }

    /**
     * Whether $userId is in the group. A failed check (the bot cannot see
     * the group, a network error) counts as not in the group, with one log
     * line a day.
     */
    private function inGroup(int $groupId, int $userId): bool
    {
        $member = $this->telegram->call('getChatMember', ['chat_id' => $groupId, 'user_id' => $userId], true);

        if (null === $member) {
            $this->warnOnce(
                self::CHECK_FAILED_WARNED,
                sprintf(
                    'Private chat gate: the group membership check failed (%s);'
                    . ' the person was treated as not in the group.',
                    $this->telegram->lastError()
                )
            );

            return false;
        }

        $status = (string) ($member['status'] ?? '');

        if ('restricted' === $status && false === ($member['is_member'] ?? true)) {
            return false;
        }

        return in_array($status, self::IN_GROUP, true);
    }

    /**
     * True for "/start <payload>" whose payload ends in a valid weekly code,
     * or is a crew-app sign-in code that is still waiting.
     */
    private function carriesValidCode(string $text): bool
    {
        if (1 !== preg_match('#^/start(?:@\w+)?\s+(\S+)#i', trim($text), $m)) {
            return false;
        }

        $payload = $m[1];

        if (str_starts_with($payload, TelegramLogin::PAYLOAD_PREFIX)) {
            $code = substr($payload, strlen(TelegramLogin::PAYLOAD_PREFIX));

            return null !== $this->login && TelegramLogin::isAvailable() && [] !== $this->login->choices($code);
        }

        [, $code] = StartCode::split($payload);

        return '' !== $code && StartCode::isValid($code);
    }

    private function sendGroupButton(int $chatId): void
    {
        $link = null !== $this->board ? $this->board->refreshGroupLink() : BoardService::groupLink();

        if ('' === $link) {
            $this->warnOnce(
                self::NO_LINK_WARNED,
                'Private chat gate: someone with a valid code is not in the group, and there is no group link to send.'
            );

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            self::joinGroupText(),
            ['inline_keyboard' => [[['text' => self::joinGroupButton(), 'url' => $link]]]]
        );
    }

    private function warnOnce(string $key, string $message): void
    {
        if (false !== get_transient($key)) {
            return;
        }

        set_transient($key, 1, DAY_IN_SECONDS);
        $this->logger->warning($message);
    }
}

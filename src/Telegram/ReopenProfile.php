<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\History;
use EventCrew\Support\Language;
use EventCrew\Support\TakeoverService;

/**
 * My info again after an action (Owner 2026-11-11: "Reopen the profile once
 * an action has been done, also in general"). When a person finishes, in the
 * private chat, an action they started from their /me screens (sign up,
 * cancel, a takeover request or answer, a free-entry ticket, giving a
 * ticket, notifications, language), their updated /me follows as a new
 * message under the confirmation, so the next action is one tap away. An
 * organizer acting as them (ActingAs) gets the person's /me, with the
 * banner.
 *
 * Started from /me: every message the bot sends while a /me flow runs is
 * kept per chat (TAGGED_PREFIX). An update is part of a /me flow when it
 * opens one of the /me screens, taps a kept message, answers a takeover
 * request, or is a plain message answering the bot's last message there
 * when that one was kept (who to take over from, who gets the ticket). The
 * group's board, its DMs and notices are never kept, so a flow from there
 * never shows /me.
 *
 * Done: the update wrote a history row about the person (sign up, cancel,
 * takeover, credit, ticket), changed their notifications, or a handler said
 * so (done(): a takeover request, a language change).
 *
 * The tapped message loses its buttons when the handler left it as it was
 * (now stale). When the /me shown after the last action is still the last
 * message in the chat and untouched, it is redrawn in place instead of
 * posting another one, so a row of toggles or sign-ups leaves one /me.
 */
final class ReopenProfile
{
    /** The kept /me flow message ids of a private chat. */
    public const TAGGED_PREFIX = 'eventcrew_tg_me_msgs_';

    /** The /me last shown after an action in a private chat. */
    public const SHOWN_PREFIX = 'eventcrew_tg_me_again_';

    /** How many message ids are kept per chat, newest last. */
    private const KEEP = 40;

    /** As long as the bot's last message is kept (TelegramClient::LAST_PREFIX). */
    private const TTL = 2 * DAY_IN_SECONDS;

    /** The /me screens (MenuButtons) that open a /me flow. */
    private const ENTRIES = [
        MenuButtons::ME,
        MenuButtons::MY_TASKS,
        MenuButtons::HISTORY,
        MenuButtons::TICKETS,
        MenuButtons::SETTINGS,
        MenuButtons::BOARD,
        MenuButtons::TICKET,
        MenuButtons::REPLACE,
        MenuButtons::NOTIFICATIONS,
        MenuButtons::LANGUAGE,
    ];

    /** The commands of the /me screens. */
    private const COMMANDS = ['me', 'tasks', 'tickets', 'settings', 'board'];

    /** null: not done; true: done; a language code: done, show /me in it. */
    private static bool|string|null $done = null;

    /**
     * The update being handled: its private chat and sender, the tapped
     * message, whether it is part of a /me flow, the history rows written,
     * and whether /me was shown.
     *
     * @var array{chat: int, sender: int, tapped: int, data: string, fromMe: bool,
     *     rows: list<array<string, mixed>>, shown: bool}|null
     */
    private ?array $current = null;

    /** Nested routing (the helper's actions) runs inside the outer update. */
    private int $depth = 0;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly ProfileService $profile,
        private readonly PersonRepository $people
    ) {
    }

    /**
     * A handler finished an action that writes no history row: a takeover
     * request, a language change ($language: /me is shown in it).
     */
    public static function done(?string $language = null): void
    {
        self::$done = $language ?? true;
    }

    /**
     * Before the update is handled (and before the router forgets the bot's
     * last message for a written one).
     *
     * @param array<string, mixed> $update
     */
    public function begin(array $update): void
    {
        if (++$this->depth > 1) {
            return;
        }

        $this->current = null;
        self::$done = null;
        $callback = is_array($update['callback_query'] ?? null) ? $update['callback_query'] : null;
        $message = null === $callback ? ($update['message'] ?? null) : ($callback['message'] ?? null);
        $chat = is_array($message) && is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $chatId = (int) ($chat['id'] ?? 0);
        $sender = (int) (null === $callback ? ($update['message']['from']['id'] ?? 0) : ($callback['from']['id'] ?? 0));

        if ('private' !== ($chat['type'] ?? '') || $chatId <= 0 || $sender <= 0) {
            return;
        }

        $tapped = null === $callback ? 0 : (int) ($message['message_id'] ?? 0);
        $data = null === $callback ? '' : (string) ($callback['data'] ?? '');
        $this->current = [
            'chat' => $chatId,
            'sender' => $sender,
            'tapped' => $tapped,
            'data' => $data,
            'fromMe' => null === $callback
                ? $this->messageFromMe($chatId, trim((string) ($update['message']['text'] ?? '')))
                : self::tapFromMe($data, $tapped, self::tagged($chatId)),
            'rows' => [],
            'shown' => false,
        ];
        TelegramClient::watch($chatId);

        if ($this->current['fromMe']) {
            History::observe(function (array $row): void {
                if (null !== $this->current) {
                    $this->current['rows'][] = $row;
                }
            }, 'reopen');
        }
    }

    /**
     * After the handler, inside an organizer's Act as (so /me is the
     * person's): shows /me again when the update finished an action of a
     * /me flow.
     */
    public function finish(): void
    {
        if (1 !== $this->depth || null === $this->current || ! $this->current['fromMe'] || $this->current['shown']) {
            return;
        }

        $chatId = $this->current['chat'];

        if (str_starts_with($this->current['data'], 'np:')) {
            self::$done ??= true;
        }

        if (null === self::$done && [] === $this->current['rows']) {
            return;
        }

        $person = $this->people->findByTelegramUserId($this->current['sender']);

        if (null === $person || ! $person->isEmailVerified() || $person->isAnonymized()) {
            return;
        }

        if (null === self::$done && ! $this->rowAbout($person->id)) {
            return;
        }

        $this->current['shown'] = true;
        $watched = TelegramClient::watched();
        $tapped = $this->current['tapped'];

        // The tapped message, left as it was, now offers what was done.
        if (0 !== $tapped && ! in_array($tapped, $watched['touched'], true)) {
            $this->telegram->editMessageReplyMarkup($chatId, $tapped, null);
        }

        $shown = (int) get_transient(self::SHOWN_PREFIX . $chatId);
        $last = (int) get_transient(TelegramClient::LAST_PREFIX . $chatId);

        if (0 !== $shown && $shown === $last && ! in_array($shown, $watched['touched'], true)) {
            $this->telegram->replaceNextIn($chatId, $shown);
        } else {
            $this->telegram->postNextAnew($chatId);
        }

        $sender = $this->current['sender'];
        $show = function () use ($sender, $chatId): void {
            $this->profile->onMe($sender, $chatId);
        };
        // After a language change, /me in the new language; else in the
        // update's own.
        is_string(self::$done) ? Language::run(self::$done, $show) : $show();

        $now = (int) get_transient(TelegramClient::LAST_PREFIX . $chatId);

        if ($now > 0) {
            set_transient(self::SHOWN_PREFIX . $chatId, $now, self::TTL);
        }
    }

    /** After the update: keeps the messages a /me flow sent. */
    public function end(): void
    {
        if (--$this->depth > 0) {
            return;
        }

        $this->depth = 0;
        $current = $this->current;
        $this->current = null;
        self::$done = null;

        if (null === $current) {
            return;
        }

        $watched = TelegramClient::unwatch();
        $chatId = $current['chat'];

        if ($current['fromMe']) {
            History::observe(null, 'reopen');
            $this->keep($chatId, $watched['sent']);
        }

        // The /me shown after an action was changed by this update (a tap on
        // it opened another screen there): it is no longer /me.
        $shown = (int) get_transient(self::SHOWN_PREFIX . $chatId);

        if (0 !== $shown && ! $current['shown'] && in_array($shown, $watched['touched'], true)) {
            delete_transient(self::SHOWN_PREFIX . $chatId);
        }
    }

    /**
     * A tap: a /me screen, Act as (it opens the person's /me), a takeover
     * request's answer, or a button on a kept message.
     *
     * @param list<int> $tagged
     */
    private static function tapFromMe(string $data, int $tapped, array $tagged): bool
    {
        return in_array($data, self::ENTRIES, true)
            || null !== MenuButtons::parsePage($data)
            || 1 === preg_match('/^' . preg_quote(ActingAs::PREFIX, '/') . '\d+$/', $data)
            || str_starts_with($data, TakeoverService::CALLBACK_PREFIX)
            || (0 !== $tapped && in_array($tapped, $tagged, true));
    }

    /**
     * A written message: a /me screen's command (or /start me), or a plain
     * answer to the bot's last message when that one is kept.
     */
    private function messageFromMe(int $chatId, string $text): bool
    {
        if ('' === $text) {
            return false;
        }

        if (str_starts_with($text, '/')) {
            return 1 === preg_match('#^/(' . implode('|', self::COMMANDS) . ')(@\w+)?(\s|$)#i', $text)
                || 1 === preg_match('#^/start(@\w+)?\s+me(\s|$)#i', $text);
        }

        $last = (int) get_transient(TelegramClient::LAST_PREFIX . $chatId);

        return 0 !== $last && in_array($last, self::tagged($chatId), true);
    }

    private function rowAbout(int $personId): bool
    {
        foreach ($this->current['rows'] ?? [] as $row) {
            if ($personId === (int) ($row['person_id'] ?? 0) || $personId === (int) ($row['other_person_id'] ?? 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The kept message ids of $chatId.
     *
     * @return list<int>
     */
    private static function tagged(int $chatId): array
    {
        $ids = get_transient(self::TAGGED_PREFIX . $chatId);

        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    /**
     * @param list<int> $ids
     */
    private function keep(int $chatId, array $ids): void
    {
        if ([] === $ids) {
            return;
        }

        $all = array_values(array_unique(array_merge(self::tagged($chatId), $ids)));
        set_transient(self::TAGGED_PREFIX . $chatId, array_slice($all, -self::KEEP), self::TTL);
    }
}

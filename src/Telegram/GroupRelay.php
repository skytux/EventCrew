<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\GroupMessageRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\PushSubscriptionRepository;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\NotificationPreferences;
use EventCrew\Support\PushNotifier;
use EventCrew\Web\PwaController;

/**
 * The group chat on the web: the crew's Telegram group mirrored both ways,
 * so crew members without Telegram can read it and write to it.
 *
 * Telegram to web: with the bot's group privacy mode off (BotFather
 * /setprivacy, Disable) every message of the group reaches the webhook, and
 * record() stores the ones of the crew's group (GroupChat::id()), edits
 * included. Commands and service messages are not stored.
 *
 * Web to Telegram: post() sends "<b>Name</b> (web): text" to the group as
 * the bot and stores it with the message id Telegram answers. An organizer
 * can hide a message on the web (hide()); a message sent from the web is
 * then deleted in the group as well.
 *
 * The bot's own messages to the group (the board, the next event's
 * picture, open task calls, its answers) never come back as updates, so
 * observe() stores them from TelegramClient::DONE_ACTION, and follows the
 * bot's edits and deletes of them.
 *
 * Off until an organizer turns it on in Settings (ENABLED_OPTION): nothing
 * is stored and the Crew app shows no Group chat tab. Messages are kept
 * KEEP_DAYS days.
 */
final class GroupRelay
{
    /** Settings switch: '1' stores the group's messages and shows the tab. */
    public const ENABLED_OPTION = 'eventcrew_group_chat_web';

    /**
     * Whether Telegram says the bot reads every group message (getMe's
     * can_read_all_group_messages), cached when the webhook is installed:
     * '1', '0', or '' when not known yet.
     */
    public const READS_ALL_OPTION = 'eventcrew_telegram_reads_all';

    public const KEEP_DAYS = 30;

    public const PURGED_OPTION = 'eventcrew_group_messages_purged_at';

    /** The messages the web shows. */
    public const SHOWN = 100;

    /** Longest message from the web, in characters. */
    public const MAX_LENGTH = 1000;

    /** At most RATE_COUNT messages from the web per person in RATE_MINUTES minutes. */
    public const RATE_COUNT = 10;
    public const RATE_MINUTES = 5;

    /** At most one app notification per person this often, except replies to them. */
    public const PUSH_GAP = 30 * MINUTE_IN_SECONDS;
    public const PUSH_PREFIX = 'eventcrew_gc_push_';

    /** The quote of an answered message, in characters. */
    private const QUOTE_LENGTH = 120;

    /** What a message can carry instead of text, in the order Telegram's fields are read. */
    private const KINDS = [
        'photo', 'video', 'animation', 'sticker', 'voice', 'video_note', 'audio',
        'document', 'poll', 'location', 'venue', 'contact', 'dice',
    ];

    /** Set while post() sends, so observe() leaves that message to post(). */
    private bool $posting = false;

    public function __construct(
        private readonly GroupMessageRepository $messages,
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram,
        private readonly ?PushSubscriptionRepository $devices = null,
        private readonly bool $defer = true
    ) {
    }

    public static function isEnabled(): bool
    {
        return '1' === (string) get_option(self::ENABLED_OPTION, '0');
    }

    /**
     * Whether the person may read and write the group chat on the web: it is
     * on and the group is known, and they are signed in with a verified
     * address, not blocked, not waiting for the team's OK and not turned down.
     */
    public static function visibleTo(?Person $person): bool
    {
        return null !== $person
            && self::isEnabled()
            && 0 !== GroupChat::id()
            && $person->isEmailVerified()
            && ! $person->isBanned()
            && ! $person->isAnonymized()
            && ! $person->isWaitingForOk()
            && ! $person->isTurnedDown();
    }

    /**
     * Stores a message of the crew's group, or the new text of an edited
     * one. Anything else - another chat, a command, a service message - is
     * left alone. Returns the stored row id (0 when nothing new was stored).
     *
     * @param array<string, mixed> $update
     */
    public function record(array $update): int
    {
        if (! self::isEnabled()) {
            return 0;
        }

        $edited = is_array($update['edited_message'] ?? null);
        $message = $edited ? $update['edited_message'] : ($update['message'] ?? null);

        if (! is_array($message)) {
            return 0;
        }

        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);

        if (0 === $chatId || $chatId !== GroupChat::id() || $messageId <= 0) {
            return 0;
        }

        [$text, $kind] = self::content($message);

        if (('' === $text && '' === $kind) || str_starts_with($text, '/')) {
            return 0;
        }

        if ($edited) {
            $this->messages->edit($chatId, $messageId, $text, (string) current_time('mysql'));

            return 0;
        }

        [$personId, $name] = $this->sender($message);
        $row = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'person_id' => $personId,
            'sender_name' => $name,
            'text' => $text,
            'kind' => $kind,
            'source' => 'telegram',
            'created_at' => (string) current_time('mysql'),
        ] + $this->replyOf($chatId, $message);

        $id = $this->messages->add($row);

        if ($id > 0) {
            $this->notifyLater($row);
        }

        return $id;
    }

    /**
     * Sends a message from the web to the group as the bot, and stores it.
     * $replyTo is the row id of the message answered, 0 for none.
     *
     * @return array{ok: bool, error: string}
     */
    public function post(Person $person, string $text, int $replyTo = 0): array
    {
        $chatId = GroupChat::id();
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        if (! self::visibleTo($person)) {
            return ['ok' => false, 'error' => 'access'];
        }

        if ('' === trim($person->displayName)) {
            return ['ok' => false, 'error' => 'name'];
        }

        if ('' === $text) {
            return ['ok' => false, 'error' => 'empty'];
        }

        if (mb_strlen($text) > self::MAX_LENGTH) {
            return ['ok' => false, 'error' => 'long'];
        }

        $since = gmdate(
            'Y-m-d H:i:s',
            (int) strtotime((string) current_time('mysql') . ' UTC') - self::RATE_MINUTES * MINUTE_IN_SECONDS
        );

        if ($this->messages->countFromWebSince($person->id, $since) >= self::RATE_COUNT) {
            return ['ok' => false, 'error' => 'rate'];
        }

        $answered = $replyTo > 0 ? $this->messages->find($replyTo) : null;

        if (null !== $answered && ((int) $answered['chat_id'] !== $chatId || ! empty($answered['hidden']))) {
            $answered = null;
        }

        $extra = ['parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true]];

        if (null !== $answered) {
            $extra['reply_parameters'] = [
                'message_id' => (int) $answered['message_id'],
                'allow_sending_without_reply' => true,
            ];
        }

        $this->posting = true;

        try {
            $sent = $this->telegram->sendMessage(
                $chatId,
                '<b>' . esc_html($person->displayName) . '</b> (web): ' . esc_html($text),
                null,
                $extra
            );
        } finally {
            $this->posting = false;
        }

        $messageId = (int) ($sent['message_id'] ?? 0);

        if ($messageId <= 0) {
            return ['ok' => false, 'error' => 'telegram'];
        }

        $row = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'person_id' => $person->id,
            'sender_name' => $person->displayName,
            'text' => $text,
            'kind' => '',
            'source' => 'web',
            'created_at' => (string) current_time('mysql'),
        ];

        if (null !== $answered) {
            $row += [
                'reply_to' => (int) $answered['message_id'],
                'reply_person_id' => (int) ($answered['person_id'] ?? 0),
                'reply_name' => (string) $answered['sender_name'],
                'reply_text' => self::quote((string) $answered['text'], (string) $answered['kind']),
            ];
        }

        $this->messages->add($row);
        $this->notifyLater($row);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * A Bot API call that succeeded (TelegramClient::DONE_ACTION): a message
     * the bot sent to the crew's group is stored as the bot's, with the
     * bot's name and its full text (a board is longer than MAX_LENGTH, which
     * is for the web's own messages), and notified like any new message. An
     * edit of a stored one changes its text without a notification; a
     * delete hides it. A message from the web is left to post().
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $result
     */
    public function observe(string $method, array $params, array $result): void
    {
        $chatId = GroupChat::id();

        if ($this->posting || ! self::isEnabled() || 0 === $chatId || 'sendChatAction' === $method) {
            return;
        }

        if ('deleteMessage' === $method) {
            $row = (int) ($params['chat_id'] ?? 0) === $chatId
                ? $this->messages->findByMessage($chatId, (int) ($params['message_id'] ?? 0))
                : null;

            if (null !== $row && empty($row['hidden'])) {
                $this->messages->hide((int) $row['id']);
            }

            return;
        }

        $isEdit = in_array($method, ['editMessageText', 'editMessageCaption', 'editMessageMedia'], true);

        if (! $isEdit && ! str_starts_with($method, 'send') && 'forwardMessage' !== $method) {
            return;
        }

        // sendMediaGroup answers with a list of messages.
        foreach (array_is_list($result) ? $result : [$result] as $message) {
            if (! is_array($message)) {
                continue;
            }

            $messageId = (int) ($message['message_id'] ?? 0);
            $inChat = (int) ($message['chat']['id'] ?? $params['chat_id'] ?? 0);

            if ($inChat !== $chatId || $messageId <= 0) {
                continue;
            }

            [$text, $kind] = self::content($message);

            if ($isEdit) {
                $this->messages->edit($chatId, $messageId, $text, (string) current_time('mysql'));

                continue;
            }

            // The "Sent you a DM." note is deleted again in a minute: not kept, not notified.
            if (('' === $text && '' === $kind) || DmNote::text() === $text) {
                continue;
            }

            $row = [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'person_id' => 0,
                'sender_name' => self::botName($message),
                'text' => $text,
                'kind' => $kind,
                'source' => 'bot',
                'created_at' => (string) current_time('mysql'),
            ] + $this->replyOf($chatId, $message);

            if ($this->messages->add($row) > 0) {
                $this->notifyLater($row);
            }
        }
    }

    /**
     * The name the bot's messages go by: the helper's name, else the bot's
     * own name in Settings, else the name Telegram gives it.
     *
     * @param array<string, mixed> $message
     */
    private static function botName(array $message): string
    {
        $name = AssistantSettings::name();

        return '' !== $name ? $name : trim((string) ($message['from']['first_name'] ?? ''));
    }

    /**
     * An organizer hides a message on the web. One sent from the web is
     * also deleted in the group (the bot sent it, so it may).
     */
    public function hide(Person $organizer, int $id): bool
    {
        if (! $organizer->isOrganizer || ! self::visibleTo($organizer)) {
            return false;
        }

        $row = $this->messages->find($id);

        if (null === $row || (int) $row['chat_id'] !== GroupChat::id()) {
            return false;
        }

        $this->messages->hide($id);

        if ('web' === (string) $row['source']) {
            $this->telegram->deleteMessage((int) $row['chat_id'], (int) $row['message_id'], true);
        }

        return true;
    }

    /**
     * The newest SHOWN messages for the web, oldest first, as the page
     * shows them: name, time (j.n. H:i, site time), its day (Y-m-d) and
     * clock (H:i) apart for the day separators, text, what was sent instead
     * of text (kind the word, type Telegram's own, for the chip's icon), the
     * quote of the answered message, whether it came from the web, whether
     * it is the viewer's own.
     *
     * @return list<array{id: int, name: string, time: string, day: string, clock: string, text: string,
     *     kind: string, type: string, edited: bool, web: bool, mine: bool,
     *     reply: array{name: string, text: string}|null}>
     */
    public function view(Person $viewer): array
    {
        $out = [];

        foreach ($this->messages->recent(GroupChat::id(), self::SHOWN) as $row) {
            $replyName = (string) ($row['reply_name'] ?? '');
            $replyText = (string) ($row['reply_text'] ?? '');
            $name = (string) $row['sender_name'];
            $at = (string) $row['created_at'];

            $out[] = [
                'id' => (int) $row['id'],
                'name' => '' !== $name ? $name : __('Someone', 'eventcrew'),
                'time' => self::time($at),
                'day' => self::time($at, 'Y-m-d'),
                'clock' => self::time($at, 'H:i'),
                'text' => (string) $row['text'],
                'kind' => self::kindLabel((string) $row['kind']),
                'type' => (string) $row['kind'],
                'edited' => ! empty($row['edited_at']),
                'web' => 'web' === (string) $row['source'],
                'mine' => (int) ($row['person_id'] ?? 0) === $viewer->id,
                'reply' => ! empty($row['reply_to']) && ('' !== $replyName || '' !== $replyText)
                    ? ['name' => $replyName, 'text' => $replyText]
                    : null,
            ];
        }

        return $out;
    }

    /**
     * Deletes the messages older than KEEP_DAYS, at most once a day (the
     * heartbeat calls it every hour). Returns how many were deleted, -1 when
     * it was not due.
     */
    public static function purgeIfDue(
        GroupMessageRepository $repository = new GroupMessageRepository(),
        ?int $now = null
    ): int {
        $now ??= time();

        if (($now - (int) get_option(self::PURGED_OPTION, 0)) < DAY_IN_SECONDS) {
            return -1;
        }

        update_option(self::PURGED_OPTION, $now, false);

        $at = strtotime((string) current_time('mysql') . ' UTC');

        return $repository->deleteBefore(
            gmdate('Y-m-d H:i:s', (false === $at ? time() : $at) - self::KEEP_DAYS * DAY_IN_SECONDS)
        );
    }

    /** The word the web shows for what was sent instead of text; '' for text. */
    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            '' => '',
            'photo' => __('Photo', 'eventcrew'),
            'video', 'video_note' => __('Video', 'eventcrew'),
            'animation' => __('GIF', 'eventcrew'),
            'sticker' => __('Sticker', 'eventcrew'),
            'voice', 'audio' => __('Audio', 'eventcrew'),
            'document' => __('File', 'eventcrew'),
            'poll' => __('Poll', 'eventcrew'),
            'location', 'venue' => __('Location', 'eventcrew'),
            'contact' => __('Contact', 'eventcrew'),
            default => __('Something else', 'eventcrew'),
        };
    }

    /**
     * The text of a message and what it carries instead of text: a caption,
     * a poll's question, a sticker's emoji. ['', ''] for a service message.
     *
     * @param array<string, mixed> $message
     * @return array{0: string, 1: string}
     */
    public static function content(array $message): array
    {
        if (isset($message['text'])) {
            return [trim((string) $message['text']), ''];
        }

        foreach (self::KINDS as $kind) {
            if (! isset($message[$kind])) {
                continue;
            }

            $text = match ($kind) {
                'poll' => (string) ($message['poll']['question'] ?? ''),
                'sticker' => (string) ($message['sticker']['emoji'] ?? ''),
                'venue' => (string) ($message['venue']['title'] ?? ''),
                'dice' => (string) ($message['dice']['emoji'] ?? ''),
                default => (string) ($message['caption'] ?? ''),
            };

            return [trim($text), $kind];
        }

        return ['', ''];
    }

    /**
     * The sender: their person id when they are known, and the name the
     * group shows (an anonymous admin or a channel by its title).
     *
     * @param array<string, mixed> $message
     * @return array{0: int, 1: string}
     */
    private function sender(array $message): array
    {
        if (is_array($message['sender_chat'] ?? null)) {
            return [0, (string) ($message['sender_chat']['title'] ?? '')];
        }

        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $name = trim((string) ($from['first_name'] ?? '') . ' ' . (string) ($from['last_name'] ?? ''));
        $telegramId = (int) ($from['id'] ?? 0);
        $person = $telegramId > 0 && empty($from['is_bot']) ? $this->people->findByTelegramUserId($telegramId) : null;

        return [null === $person ? 0 : $person->id, $name];
    }

    /**
     * The answered message: its id, and its sender and a short quote, from
     * the stored row when there is one (a message from the web reads by its
     * writer, not by the bot), else from the update itself.
     *
     * @param array<string, mixed> $message
     * @return array<string, int|string>
     */
    private function replyOf(int $chatId, array $message): array
    {
        $answered = is_array($message['reply_to_message'] ?? null) ? $message['reply_to_message'] : null;

        // In a group with topics every message "answers" the topic's first
        // message; that is no reply.
        if (null === $answered || ! empty($answered['forum_topic_created'])) {
            return [];
        }

        $answeredId = (int) ($answered['message_id'] ?? 0);
        $stored = $this->messages->findByMessage($chatId, $answeredId);

        if (null !== $stored) {
            return [
                'reply_to' => $answeredId,
                'reply_person_id' => (int) ($stored['person_id'] ?? 0),
                'reply_name' => (string) $stored['sender_name'],
                'reply_text' => self::quote((string) $stored['text'], (string) $stored['kind']),
            ];
        }

        [$text, $kind] = self::content($answered);
        [$personId, $name] = $this->sender($answered);

        return [
            'reply_to' => $answeredId,
            'reply_person_id' => $personId,
            'reply_name' => $name,
            'reply_text' => self::quote($text, $kind),
        ];
    }

    private static function quote(string $text, string $kind): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ('' === $text) {
            return self::kindLabel($kind);
        }

        return mb_strlen($text) > self::QUOTE_LENGTH ? rtrim(mb_substr($text, 0, self::QUOTE_LENGTH - 1)) . '…' : $text;
    }

    /** A stored site time in $format, "j.n. H:i" by default; '' when unreadable. */
    private static function time(string $at, string $format = 'j.n. H:i'): string
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $at);

        return false === $date ? '' : $date->format($format);
    }

    /**
     * App notifications for a new message, after the response is out (they
     * are one POST per device): see notify().
     *
     * @param array<string, mixed> $row
     */
    private function notifyLater(array $row): void
    {
        if (null === $this->devices) {
            return;
        }

        if (! $this->defer) {
            $this->notify($row);

            return;
        }

        add_action('shutdown', function () use ($row): void {
            $this->notify($row);
        }, 30);
    }

    /**
     * Sends an app notification of a new group message to everyone with a
     * device who turned "Group chat messages" on (off by default), except
     * the writer: a reply to their own message at once, anything else at
     * most once per PUSH_GAP, so a busy evening is not a buzz per message.
     * Returns how many people were notified.
     *
     * @param array<string, mixed> $row
     */
    public function notify(array $row): int
    {
        if (null === $this->devices) {
            return 0;
        }

        $writer = (int) ($row['person_id'] ?? 0);
        $answered = (int) ($row['reply_person_id'] ?? 0);
        $text = '' !== (string) $row['text'] ? (string) $row['text'] : self::kindLabel((string) $row['kind']);
        $body = esc_html((string) $row['sender_name']) . ': ' . esc_html($text);
        $url = (string) ((new PwaController())->manifestConfig()['start_url'] ?? '');
        $url = '' === $url ? '' : $url . '#eventcrew-panel-chat';
        $prefs = new NotificationPreferences();
        $count = 0;

        $ids = array_values(array_diff($this->devices->personIds(), [$writer]));

        foreach ([] === $ids ? [] : $this->people->findMany($ids) as $personId => $person) {
            if (! self::visibleTo($person) || ! $prefs->pushAllowed($person, NotificationPreferences::GROUP_CHAT)) {
                continue;
            }

            $key = self::PUSH_PREFIX . $personId;

            if ($answered !== $personId && false !== get_transient($key)) {
                continue;
            }

            set_transient($key, 1, self::PUSH_GAP);

            if (PushNotifier::notify($person, NotificationPreferences::GROUP_CHAT, $body, $url) > 0) {
                ++$count;
            }
        }

        return $count;
    }
}

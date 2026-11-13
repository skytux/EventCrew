<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\BotLogRepository;
use EventCrew\Support\Dates;

/**
 * The record of which commands the bot ran: one row per typed command, button
 * tap or action the language-model helper ran (see UpdateRouter::route()).
 *
 * A row holds when, who (the person, or the Telegram user when not linked),
 * the kind of chat, the command's name, where it came from, the prefix of a
 * button's callback data, a date the command named and how it ended. Never the
 * message text, the arguments, names, emails, codes or the full callback data.
 *
 * Recording never breaks the handling of an update: every database error is
 * caught and ignored. Rows older than KEEP_DAYS are deleted once a day by the
 * heartbeat (purgeIfDue()).
 */
final class BotLog
{
    /** Rows older than this are deleted (Owner, Q212: 90 days). */
    public const KEEP_DAYS = 90;

    /** When purgeIfDue() last deleted old rows, as a Unix time. */
    public const PURGED_OPTION = 'eventcrew_bot_log_purged_at';

    public const SOURCE_TYPED = 'typed';
    public const SOURCE_BUTTON = 'button';
    public const SOURCE_HELPER = 'helper';

    public const OUTCOME_OK = 'ok';
    public const OUTCOME_REFUSED = 'refused';
    public const OUTCOME_ERROR = 'error';

    /** The name stored for a command the bot does not have. */
    public const UNKNOWN_COMMAND = 'other';

    /** The commands UpdateRouter answers. */
    private const COMMANDS = [
        'help', 'start', 'roster', 'door', 'people', 'me', 'board', 'newsletter', 'say', 'assign', 'unassign',
        'addtask', 'edittask', 'deletetask', 'maketasks', 'mailtasks', 'events',
        'mark', 'standing', 'newcomer', 'ticket', 'unticket', 'unban', 'freeentry', 'stop', 'newboard', 'sendtickets',
        'tell', 'crew', 'name', 'email', 'subscribe', 'appinvite',
        // parityA: AdminChangeCommands.
        'gift', 'role', 'organizer', 'deleteperson', 'oncemore', 'addperson', 'editperson', 'editrole', 'optout',
        'aitext',
    ];

    /** Menu button callback data, stored whole: it is a fixed word, not data. */
    private const MENU_KINDS = [
        MenuButtons::TICKET,
        MenuButtons::REPLACE,
        MenuButtons::WEB,
        MenuButtons::APP,
        MenuButtons::NOTIFICATIONS,
        MenuButtons::LANGUAGE,
        MenuButtons::SALES,
        MenuButtons::GIFT,
        MenuButtons::ROLES,
        MenuButtons::ELIGIBLE,
        MenuButtons::CANCEL,
        MenuButtons::TASK_INFO,
    ];

    public function __construct(
        private readonly BotLogRepository $repository = new BotLogRepository()
    ) {
    }

    /**
     * Records one update before it is handled. Returns the row id, for
     * failed(), or 0 when nothing was recorded: not a command or button, or
     * the database refused.
     *
     * @param array<string, mixed> $update
     */
    public function record(array $update, string $source): int
    {
        try {
            $row = self::describe($update, $source, (string) current_time('Y-m-d'));

            if (null === $row) {
                return 0;
            }

            $row['created_at'] = gmdate('Y-m-d H:i:s');

            return $this->repository->insert($row);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Marks a recorded row as ended in an error. */
    public function failed(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        try {
            $this->repository->setOutcome($id, self::OUTCOME_ERROR);
        } catch (\Throwable) {
            // The log never breaks handling.
        }
    }

    /**
     * Deletes the rows older than KEEP_DAYS, at most once a day: the
     * heartbeat runs every hour and calls this each time. Returns how many
     * rows were deleted, or -1 when it was not due.
     */
    public static function purgeIfDue(
        BotLogRepository $repository = new BotLogRepository(),
        ?int $now = null
    ): int {
        $now ??= time();

        if (($now - (int) get_option(self::PURGED_OPTION, 0)) < DAY_IN_SECONDS) {
            return -1;
        }

        update_option(self::PURGED_OPTION, $now, false);

        return $repository->deleteBefore(self::cutoff($now));
    }

    /** The UTC time before which rows are deleted: KEEP_DAYS ago. */
    public static function cutoff(?int $now = null): string
    {
        return gmdate('Y-m-d H:i:s', ($now ?? time()) - self::KEEP_DAYS * DAY_IN_SECONDS);
    }

    /**
     * Records an action the helper was refused (it did not run): the
     * command's name and date only.
     */
    public function refusedAction(string $line, int $telegramUserId, bool $isPrivate): void
    {
        try {
            $command = self::commandOf($line, (string) current_time('Y-m-d'));

            $this->repository->insert([
                'created_at' => gmdate('Y-m-d H:i:s'),
                'telegram_user_id' => $telegramUserId,
                'chat_kind' => $isPrivate ? 'private' : 'group',
                'command' => $command['command'],
                'source' => self::SOURCE_HELPER,
                'button_kind' => null,
                'date_arg' => $command['date'],
                'outcome' => self::OUTCOME_REFUSED,
            ]);
        } catch (\Throwable) {
            // The log never breaks handling.
        }
    }

    /**
     * What one update is recorded as, or null when it is not recorded (not a
     * command or a button, or a command for another bot).
     *
     * @param array<string, mixed> $update
     * @return array{telegram_user_id: int, chat_kind: string, command: string, source: string,
     *     button_kind: ?string, date_arg: ?string, outcome: string}|null
     */
    public static function describe(array $update, string $source, string $today): ?array
    {
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $query = $update['callback_query'];
            $data = (string) ($query['data'] ?? '');
            $chatType = (string) ($query['message']['chat']['type'] ?? 'private');

            return [
                'telegram_user_id' => (int) ($query['from']['id'] ?? 0),
                'chat_kind' => 'private' === $chatType ? 'private' : 'group',
                'command' => '',
                'source' => self::SOURCE_HELPER === $source ? self::SOURCE_HELPER : self::SOURCE_BUTTON,
                'button_kind' => self::buttonKind($data),
                'date_arg' => self::dateIn($data),
                'outcome' => self::OUTCOME_OK,
            ];
        }

        $message = $update['message'] ?? null;

        if (! is_array($message)) {
            return null;
        }

        $text = trim((string) ($message['text'] ?? ''));

        if (! str_starts_with($text, '/')) {
            return null;
        }

        $command = self::commandOf($text, $today);

        if (self::UNKNOWN_COMMAND === $command['command'] && self::isForAnotherBot($text)) {
            return null;
        }

        return [
            'telegram_user_id' => (int) ($message['from']['id'] ?? 0),
            'chat_kind' => 'private' === ($message['chat']['type'] ?? '') ? 'private' : 'group',
            'command' => $command['command'],
            'source' => self::SOURCE_HELPER === $source ? self::SOURCE_HELPER : self::SOURCE_TYPED,
            'button_kind' => null,
            'date_arg' => $command['date'],
            'outcome' => self::UNKNOWN_COMMAND === $command['command'] ? self::OUTCOME_REFUSED : self::OUTCOME_OK,
        ];
    }

    /**
     * The command's name ("/roster", or "menu:ticket" for a helper's menu
     * action; "other" for one the bot does not have) and the first date its
     * arguments name, as Y-m-d or null. Nothing else of the line is kept.
     *
     * @return array{command: string, date: ?string}
     */
    public static function commandOf(string $line, string $today): array
    {
        $words = preg_split('/\s+/u', trim($line)) ?: [];
        $head = strtolower((string) array_shift($words));
        $name = (string) preg_replace('/@\w*$/', '', $head);

        if (str_starts_with($name, '/') && in_array(substr($name, 1), self::COMMANDS, true)) {
            $command = $name;
        } elseif (1 === preg_match('/^menu:[a-z]{1,16}$/', $name)) {
            $command = $name;
        } else {
            $command = self::UNKNOWN_COMMAND;
        }

        $date = null;

        foreach ($words as $word) {
            $parsed = Dates::parseUserDate($word, $today);

            if ('' !== $parsed) {
                $date = $parsed;
                break;
            }
        }

        return ['command' => $command, 'date' => $date];
    }

    /**
     * The line the helper's conversation record gets when it runs an action,
     * like "[showed /roster 14.11.]": the command and the date only.
     */
    public static function marker(string $line, bool $changed, string $today): string
    {
        $command = self::commandOf($line, $today);
        $date = null === $command['date']
            ? ''
            : ' ' . (int) substr($command['date'], 8, 2) . '.' . (int) substr($command['date'], 5, 2) . '.';

        return '[' . ($changed ? 'ran ' : 'showed ') . $command['command'] . $date . ']';
    }

    /**
     * The prefix of a button's callback data ("rep:", "tkt:"); for a menu
     * button its fixed name ("mn:ticket", "mn:dl:"). Never what follows.
     */
    private static function buttonKind(string $data): string
    {
        if (in_array($data, self::MENU_KINDS, true)) {
            return $data;
        }

        foreach ([MenuButtons::DOOR_LIST, MenuButtons::GUEST_LIST] as $prefix) {
            if (str_starts_with($data, $prefix)) {
                return $prefix;
            }
        }

        if (1 === preg_match('/^([a-z]{1,12}:)/', $data, $m)) {
            return $m[1];
        }

        return '?';
    }

    /** The first real Y-m-d date in callback data, or null. */
    private static function dateIn(string $data): ?string
    {
        if (1 !== preg_match('/(\d{4})-(\d{2})-(\d{2})/', $data, $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $m[0] : null;
    }

    /** True for "/cmd@name" when name is not this bot's username. */
    private static function isForAnotherBot(string $text): bool
    {
        if (1 !== preg_match('#^/\w+@(\w+)#', $text, $m)) {
            return false;
        }

        $own = ltrim(trim((string) get_option(BoardService::USERNAME_OPTION, '')), '@');

        return '' === $own || 0 !== strcasecmp($own, $m[1]);
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\Dates;

/**
 * The steps every typed command of PersonCommands, NightCommands,
 * TellCommand and MemberCommands shares, the way AssignCommand does them:
 *
 * - a request waiting for a choice or ✅ is a transient, bound to the
 *   Telegram user who asked and kept for 15 minutes;
 * - its buttons are "<prefix><step>:<16 hex id>[:<number>]", step "x" being
 *   ✖️ Cancel;
 * - a tap removes the buttons it came with, so nothing is pressed twice;
 * - names are found with AssignCommand::matchPeople(), and two or more
 *   matches become one button each, never a guess.
 */
final class CommandFlow
{
    public const STATE_TTL = 15 * MINUTE_IN_SECONDS;

    /** More matching members than this: ask for more of the name. */
    public const MAX_CHOICES = 6;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram,
        private readonly string $prefix,
        private readonly string $statePrefix
    ) {
    }

    public function telegram(): TelegramClient
    {
        return $this->telegram;
    }

    /**
     * False (and the reason sent) outside a private chat.
     */
    public function inPrivate(int $chatId, bool $isPrivate, string $command): bool
    {
        if (! $isPrivate) {
            /* translators: %s: a bot command, /assign or /unassign. */
            $this->telegram->sendMessage($chatId, sprintf(__('Use %s in a private chat with me.', 'eventcrew'), $command));
        }

        return $isPrivate;
    }

    /**
     * The organizer who sent the command, or null with "organizers only" sent.
     */
    public function organizerOrSay(int $chatId, int $fromId, string $command): ?Person
    {
        $organizer = $this->organizer($fromId);

        if (null === $organizer) {
            /* translators: %s: a bot command, like /mark. */
            $this->telegram->sendMessage($chatId, sprintf(__('Only organizers can use %s.', 'eventcrew'), $command));
        }

        return $organizer;
    }

    /**
     * The organizer who tapped, or null with the request dropped and the tap
     * answered: someone who is no longer an organizer changes nothing.
     *
     * @param array{callback: string, from: int, id: string} $tap
     */
    public function organizerTap(array $tap): ?Person
    {
        $organizer = $this->organizer($tap['from']);

        if (null === $organizer) {
            $this->drop($tap['id']);
            $this->telegram->answerCallbackQuery($tap['callback'], __('Only organizers can answer this.', 'eventcrew'), true);
        }

        return $organizer;
    }

    public function organizer(int $telegramUserId): ?Person
    {
        $person = $this->member($telegramUserId);

        return null !== $person && $person->isOrganizer ? $person : null;
    }

    /**
     * The member linked to this Telegram user: verified, not anonymized.
     */
    public function member(int $telegramUserId): ?Person
    {
        if (0 === $telegramUserId) {
            return null;
        }

        $person = $this->people->findByTelegramUserId($telegramUserId);

        return null !== $person && ! $person->isAnonymized() && $person->isEmailVerified() ? $person : null;
    }

    /**
     * What follows the command word, line breaks kept.
     */
    public static function args(string $text): string
    {
        return trim((string) preg_replace('#^/\S+[ \t]*#u', '', trim($text)));
    }

    /**
     * A leading date ("21.11.", "2026-11-21") and the rest.
     *
     * @return array{0: string, 1: string}
     */
    public static function takeDate(string $args): array
    {
        $parts = preg_split('/\s+/u', $args, 2);
        $date = Dates::parseUserDate((string) ($parts[0] ?? ''), current_time('Y-m-d'));

        return '' === $date ? ['', $args] : [$date, trim((string) ($parts[1] ?? ''))];
    }

    /**
     * Stores a new waiting request and returns its id.
     *
     * @param array<string, mixed> $state Must hold 'user', the asking Telegram user.
     */
    public function start(array $state): string
    {
        $id = bin2hex(random_bytes(8));
        set_transient($this->statePrefix . $id, $state, self::STATE_TTL);

        return $id;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function save(string $id, array $state): void
    {
        set_transient($this->statePrefix . $id, $state, self::STATE_TTL);
    }

    public function drop(string $id): void
    {
        delete_transient($this->statePrefix . $id);
    }

    /**
     * Reads a tap on one of this flow's buttons. Answers it and returns null
     * when there is nothing more to do: unknown data, a request expired or
     * someone else's, or ✖️ Cancel. Otherwise the buttons are removed and
     * the caller answers the tap.
     *
     * @param array<string, mixed> $callbackQuery
     * @return array{callback: string, from: int, chat: int, step: string, id: string, choice: int, state: array<string, mixed>}|null
     */
    public function tap(array $callbackQuery): ?array
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $fromId = (int) ($callbackQuery['from']['id'] ?? 0);
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chatId = (int) ($message['chat']['id'] ?? $fromId);
        $messageId = (int) ($message['message_id'] ?? 0);
        $data = (string) ($callbackQuery['data'] ?? '');
        $pattern = '/^' . preg_quote($this->prefix, '/') . '([a-z]):([0-9a-f]{16})(?::(\d+))?$/';

        if (1 !== preg_match($pattern, $data, $m)) {
            $this->telegram->answerCallbackQuery($callbackId);

            return null;
        }

        $state = get_transient($this->statePrefix . $m[2]);

        if (! is_array($state) || 0 === $fromId || (int) ($state['user'] ?? 0) !== $fromId) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('This request has expired. Please send it again.', 'eventcrew'),
                true
            );

            return null;
        }

        $this->removeButtons($chatId, $messageId);

        if ('x' === $m[1]) {
            $this->drop($m[2]);
            $this->telegram->answerCallbackQuery($callbackId, __('Cancelled.', 'eventcrew'));
            $this->telegram->sendMessage($chatId, '✖️ ' . __('Cancelled. Nothing was changed.', 'eventcrew'));

            return null;
        }

        return [
            'callback' => $callbackId,
            'from' => $fromId,
            'chat' => $chatId,
            'step' => $m[1],
            'id' => $m[2],
            'choice' => (int) ($m[3] ?? 0),
            'state' => $state,
        ];
    }

    /**
     * Whether $choice is one of the ids the request offered under $key.
     *
     * @param array<string, mixed> $state
     */
    public static function offered(array $state, string $key, int $choice): bool
    {
        return in_array($choice, array_map('intval', (array) ($state[$key] ?? [])), true);
    }

    /**
     * A question with one button per option and a ✖️ Cancel row.
     *
     * @param array<int|string, string> $options value => button text
     * @param int $perRow Buttons per row.
     */
    public function choose(int $chatId, string $id, string $step, string $question, array $options, int $perRow = 1): void
    {
        $buttons = [];

        foreach ($options as $value => $label) {
            $buttons[] = ['text' => $label, 'callback_data' => $this->prefix . $step . ':' . $id . ':' . $value];
        }

        $rows = array_chunk($buttons, max(1, $perRow));
        $rows[] = [$this->cancelButton($id)];
        $this->telegram->sendMessage($chatId, $question, ['inline_keyboard' => $rows]);
    }

    /**
     * $text with ✅ $ok and ✖️ Cancel side by side. ✅ is step "y".
     */
    public function confirm(int $chatId, string $id, string $text, string $ok): void
    {
        $this->telegram->sendMessage($chatId, $text, ['inline_keyboard' => [[
            ['text' => '✅ ' . $ok, 'callback_data' => $this->prefix . 'y:' . $id],
            $this->cancelButton($id),
        ]]]);
    }

    /**
     * The members $query names, or null with the reason sent: none, or too
     * many to show as buttons.
     *
     * @param list<Person> $pool
     * @return list<Person>|null
     */
    public function findPeople(int $chatId, string $query, array $pool, ?string $none = null): ?array
    {
        $found = AssignCommand::matchPeople($query, $pool);

        if ([] === $found) {
            $this->telegram->sendMessage(
                $chatId,
                /* translators: %s: the name the organizer typed. */
                $none ?? sprintf(__('No member called “%s” was found.', 'eventcrew'), $query)
            );

            return null;
        }

        if (count($found) > self::MAX_CHOICES) {
            $this->telegram->sendMessage(
                $chatId,
                /* translators: %s: the name the organizer typed. */
                sprintf(__('Many members match “%s”. Write more of the name.', 'eventcrew'), $query)
            );

            return null;
        }

        return $found;
    }

    /**
     * Everyone who is a member: not anonymized.
     *
     * @return list<Person>
     */
    public function members(): array
    {
        return array_values(array_filter(
            $this->people->all(['per_page' => 2000, 'orderby' => 'display_name']),
            static fn (Person $p): bool => ! $p->isAnonymized()
        ));
    }

    /**
     * Buttons for a choice of members: the name, plus the start of the email
     * when two of them share the name.
     *
     * @param list<Person> $people
     * @return array<int, string>
     */
    public static function personOptions(array $people): array
    {
        $options = [];

        foreach ($people as $person) {
            $same = array_filter($people, static fn (Person $p): bool => mb_strtolower($p->name()) === mb_strtolower($person->name()));
            $options[$person->id] = count($same) < 2
                ? $person->name()
                : $person->name() . ' · ' . mb_substr((string) strstr($person->email, '@', true), 0, 4) . '…';
        }

        return $options;
    }

    public static function taskLabel(Task $task): string
    {
        return trim($task->roleLabel() . ' · ' . Dates::dayLabel($task->taskDate) . ' ' . $task->timeRange());
    }

    /**
     * "✅ Name · what" over the sentence, or ⚠️ when it did not happen.
     */
    public static function result(bool $done, string $head, string $sentence): string
    {
        return ($done ? '✅ ' : '⚠️ ') . ('' === $head ? '' : $head . "\n") . $sentence;
    }

    /**
     * @return array{text: string, callback_data: string}
     */
    private function cancelButton(string $id): array
    {
        return ['text' => '✖️ ' . __('Cancel', 'eventcrew'), 'callback_data' => $this->prefix . 'x:' . $id];
    }

    private function removeButtons(int $chatId, int $messageId): void
    {
        if (0 === $chatId || 0 === $messageId) {
            return;
        }

        $this->telegram->call('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => ['inline_keyboard' => []],
        ]);
    }
}

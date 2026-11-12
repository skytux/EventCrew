<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;

/**
 * All reads and writes of the group messages table: the crew's Telegram
 * group mirrored for the group chat on the web (Telegram\GroupRelay).
 *
 * A row is one message of the group, found by its chat and Telegram
 * message id. Times are site time, like the other crew tables.
 */
class GroupMessageRepository
{
    private function table(): string
    {
        return Schema::table(Schema::GROUP_MESSAGES);
    }

    /**
     * Stores a new message. A message already stored (Telegram sent the
     * update twice) is left as it is. Returns the row id, 0 when nothing was
     * stored.
     *
     * @param array{chat_id: int, message_id: int, person_id?: int|null, sender_name?: string, text?: string,
     *     kind?: string, reply_to?: int|null, reply_person_id?: int|null, reply_name?: string,
     *     reply_text?: string, source?: string, created_at?: string} $row
     */
    public function add(array $row): int
    {
        global $wpdb;

        $chatId = (int) $row['chat_id'];
        $messageId = (int) $row['message_id'];

        if (0 === $chatId || $messageId <= 0) {
            return 0;
        }

        // NULL for an unknown sender or no reply: built as columns and
        // placeholders, since prepare() has no placeholder for NULL.
        $values = [
            'chat_id' => ['%d', $chatId],
            'message_id' => ['%d', $messageId],
            'sender_name' => ['%s', mb_substr((string) ($row['sender_name'] ?? ''), 0, 191)],
            'text' => ['%s', (string) ($row['text'] ?? '')],
            'kind' => ['%s', mb_substr((string) ($row['kind'] ?? ''), 0, 16)],
            'reply_name' => ['%s', mb_substr((string) ($row['reply_name'] ?? ''), 0, 191)],
            'reply_text' => ['%s', mb_substr((string) ($row['reply_text'] ?? ''), 0, 255)],
            'source' => ['%s', (string) ($row['source'] ?? 'telegram')],
            'created_at' => ['%s', (string) ($row['created_at'] ?? current_time('mysql'))],
        ];

        foreach (['person_id', 'reply_to', 'reply_person_id'] as $column) {
            if ((int) ($row[$column] ?? 0) > 0) {
                $values[$column] = ['%d', (int) $row[$column]];
            }
        }

        // The column names and placeholders are this method's own, not input.
        $columns = implode(', ', array_keys($values));
        $placeholders = implode(', ', array_column($values, 0));

        $inserted = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- columns and placeholders built above.
                "INSERT IGNORE INTO {$this->table()} ({$columns}) VALUES ({$placeholders})",
                ...array_column($values, 1)
            )
        );

        return is_int($inserted) && $inserted > 0 ? (int) $wpdb->insert_id : 0;
    }

    /** A message edited in the group: its new text, and when. */
    public function edit(int $chatId, int $messageId, string $text, string $at): bool
    {
        global $wpdb;

        return false !== $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table()} SET text = %s, edited_at = %s WHERE chat_id = %d AND message_id = %d",
                $text,
                $at,
                $chatId,
                $messageId
            )
        );
    }

    /**
     * The newest $limit messages of the chat that are not hidden, oldest
     * first (the way a chat reads).
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $chatId, int $limit = 100): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE chat_id = %d AND hidden = 0 ORDER BY id DESC LIMIT %d",
                $chatId,
                max(1, $limit)
            ),
            ARRAY_A
        );

        return array_reverse(is_array($rows) ? array_values($rows) : []);
    }

    /**
     * One message by row id.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * One message by its chat and Telegram message id.
     *
     * @return array<string, mixed>|null
     */
    public function findByMessage(int $chatId, int $messageId): ?array
    {
        global $wpdb;

        if (0 === $chatId || $messageId <= 0) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE chat_id = %d AND message_id = %d",
                $chatId,
                $messageId
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /** Whether the Telegram message is one the bot posted for someone on the web. */
    public function isFromWeb(int $chatId, int $messageId): bool
    {
        global $wpdb;

        if (0 === $chatId || $messageId <= 0) {
            return false;
        }

        $source = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT source FROM {$this->table()} WHERE chat_id = %d AND message_id = %d",
                $chatId,
                $messageId
            )
        );

        return 'web' === $source;
    }

    public function hide(int $id): bool
    {
        global $wpdb;

        return $id > 0 && false !== $wpdb->update($this->table(), ['hidden' => 1], ['id' => $id]);
    }

    /** How many messages the person sent from the web since $since (site time). */
    public function countFromWebSince(int $personId, string $since): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table()} WHERE person_id = %d AND source = 'web' AND created_at >= %s",
                $personId,
                $since
            )
        );
    }

    /** Deletes the messages stored before $cutoff (site time); returns how many. */
    public function deleteBefore(string $cutoff): int
    {
        global $wpdb;

        $deleted = $wpdb->query(
            $wpdb->prepare("DELETE FROM {$this->table()} WHERE created_at < %s", $cutoff)
        );

        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * Erasure: the person's messages go, and the quotes of their messages
     * in replies to them are emptied.
     */
    public function forgetPerson(int $personId): void
    {
        global $wpdb;

        if ($personId <= 0) {
            return;
        }

        $wpdb->delete($this->table(), ['person_id' => $personId]);
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table()} SET reply_name = '', reply_text = '', reply_person_id = NULL
                WHERE reply_person_id = %d",
                $personId
            )
        );
    }
}

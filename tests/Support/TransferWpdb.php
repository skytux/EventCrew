<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- wpdb's own method names.

/**
 * A database double for the ticket transfer tests: people, credit tickets
 * (redemptions), transfers and check-ins kept in arrays, so a test states
 * who holds what rather than the order of the queries. The guarded UPDATEs
 * the transfer code relies on (markTransferred, markIssued, end, eraseEmail)
 * are applied to the arrays the way MySQL would, rows changed and all.
 */
final class TransferWpdb extends \FakeWpdb
{
    /** @var array<int, array<string, mixed>> */
    public array $people = [];

    /** @var array<int, array<string, mixed>> */
    public array $redemptions = [];

    /** @var array<int, array<string, mixed>> */
    public array $transfers = [];

    /** @var list<array{event_date: string, kind: string, ref_id: int, checked_in_at: string}> */
    public array $checkins = [];

    public function addPerson(int $id, string $name, string $email, ?int $telegramId = null): void
    {
        $this->people[$id] = [
            'id' => $id,
            'email' => $email,
            'display_name' => $name,
            'email_verified_at' => '2026-01-01 00:00:00',
            'telegram_user_id' => $telegramId,
            'telegram_chat_id' => $telegramId,
            'language' => null,
            'is_organizer' => 0,
            'notify_prefs' => null,
            'anonymized_at' => null,
        ];
    }

    public function addRedemption(int $id, int $personId, string $date): void
    {
        $this->redemptions[$id] = [
            'id' => $id,
            'person_id' => $personId,
            'redeemed_for' => $date,
            'event_post_id' => null,
            'event_label' => 'Dance Night',
            'redeemed_at' => '2026-07-10 10:00:00',
            'note' => '',
            'cancelled_at' => null,
            'ticket_mailed_at' => null,
            'transferred_to' => null,
        ];
    }

    public function insert(string $table, array $data, array|string|null $format = null): int
    {
        $result = parent::insert($table, $data, $format);

        if (str_ends_with($table, 'eventcrew_ticket_transfers')) {
            $id = count($this->transfers) + 1;
            $this->transfers[$id] = array_merge(['id' => $id, 'ended_at' => null, 'ticket_mailed_at' => null], $data);
            $this->insert_id = $id;
        }

        return $result;
    }

    public function update(string $table, array $data, array $where, array|string|null $format = null, array|string|null $whereFormat = null): int
    {
        $id = (int) ($where['id'] ?? 0);

        if (str_ends_with($table, 'eventcrew_ticket_transfers') && isset($this->transfers[$id])) {
            $this->transfers[$id] = array_merge($this->transfers[$id], $data);
        } elseif (str_ends_with($table, 'eventcrew_redemptions') && isset($this->redemptions[$id])) {
            $this->redemptions[$id] = array_merge($this->redemptions[$id], $data);
        }

        return parent::update($table, $data, $where);
    }

    public function delete(string $table, array $where, array|string|null $whereFormat = null): int
    {
        if (str_ends_with($table, 'eventcrew_ticket_transfers') && isset($where['receiver_person_id'])) {
            foreach ($this->transfers as $id => $row) {
                if ((int) $row['receiver_person_id'] === (int) $where['receiver_person_id']) {
                    unset($this->transfers[$id]);
                }
            }
        }

        return parent::delete($table, $where, $whereFormat);
    }

    public function query(string $sql): int|bool
    {
        if (str_contains($sql, 'eventcrew_redemptions') && str_contains($sql, 'transferred_to = ')) {
            $this->queries[] = $sql;
            preg_match('/transferred_to = (\d+)\s+WHERE id = (\d+)/', $sql, $m);
            $row = $this->redemptions[(int) ($m[2] ?? 0)] ?? null;

            if (null === $row || null !== $row['cancelled_at'] || null !== $row['transferred_to']) {
                return 0;
            }

            $this->redemptions[(int) $m[2]]['cancelled_at'] = '2026-07-20 12:00:00';
            $this->redemptions[(int) $m[2]]['transferred_to'] = (int) $m[1];

            return 1;
        }

        if (str_contains($sql, 'eventcrew_ticket_transfers') && str_starts_with(trim($sql), 'UPDATE')) {
            $this->queries[] = $sql;

            return $this->updateTransfers($sql);
        }

        return parent::query($sql);
    }

    public function get_row(string $sql, string $output = 'OBJECT'): mixed
    {
        if (str_contains($sql, 'eventcrew_ticket_transfers')) {
            $this->queries[] = $sql;
            $rows = $this->match($this->transfers, $sql);

            return [] === $rows ? null : end($rows);
        }

        if (str_contains($sql, 'eventcrew_redemptions') && 1 === preg_match('/WHERE id = (\d+)/', $sql, $m)) {
            $this->queries[] = $sql;

            return $this->redemptions[(int) $m[1]] ?? null;
        }

        if (str_contains($sql, 'eventcrew_people')) {
            $this->queries[] = $sql;
            $rows = $this->match($this->people, $sql);

            return [] === $rows ? null : reset($rows);
        }

        return parent::get_row($sql, $output);
    }

    public function get_results(string $sql, string $output = 'OBJECT'): mixed
    {
        if (str_contains($sql, 'eventcrew_ticket_transfers')) {
            $this->queries[] = $sql;

            return array_values($this->match($this->transfers, $sql));
        }

        if (str_contains($sql, 'eventcrew_checkins')) {
            $this->queries[] = $sql;
            preg_match("/event_date = '([0-9-]+)' AND kind = '(\w+)' AND ref_id IN \(([0-9, ]+)\)/", $sql, $m);
            $refs = array_map('intval', explode(',', $m[3] ?? ''));

            return array_values(array_filter(
                $this->checkins,
                static fn (array $row): bool => $row['event_date'] === ($m[1] ?? '')
                    && $row['kind'] === ($m[2] ?? '')
                    && in_array($row['ref_id'], $refs, true)
            ));
        }

        if (str_contains($sql, 'eventcrew_people') && str_contains($sql, 'display_name LIKE')) {
            $this->queries[] = $sql;
            preg_match("/display_name LIKE '%([^%']*)%'/", $sql, $m);
            $needle = strtolower(stripslashes($m[1] ?? ''));

            return array_values(array_filter(
                $this->people,
                static fn (array $row): bool => '' !== $needle
                    && str_contains(strtolower((string) $row['display_name']), $needle)
            ));
        }

        if (str_contains($sql, 'eventcrew_tasks') && [] === $this->nextResults) {
            $this->queries[] = $sql;

            return [];
        }

        return parent::get_results($sql, $output);
    }

    /**
     * The rows of $rows that every "col = value" / "col IS NULL" condition
     * in the WHERE clause of $sql holds for.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function match(array $rows, string $sql): array
    {
        $where = preg_replace('/\s+ORDER BY.*$/s', '', (string) substr($sql, (int) strpos($sql, 'WHERE') + 5)) ?? '';
        preg_match_all("/(\w+) = ('(?:[^'\\\\]|\\\\.)*'|\d+)/", $where, $equal, PREG_SET_ORDER);
        preg_match_all('/(\w+) IS NULL/', $where, $null, PREG_SET_ORDER);

        return array_filter($rows, static function (array $row) use ($equal, $null): bool {
            foreach ($equal as [, $column, $value]) {
                $want = str_starts_with($value, "'") ? stripslashes(substr($value, 1, -1)) : (int) $value;

                if (! array_key_exists($column, $row) || (string) $row[$column] !== (string) $want) {
                    return false;
                }
            }

            foreach ($null as [, $column]) {
                if (null !== ($row[$column] ?? null)) {
                    return false;
                }
            }

            return true;
        });
    }

    private function updateTransfers(string $sql): int
    {
        // eraseEmail(): blank the address and name; a waiting offer ends.
        if (1 === preg_match("/SET email = NULL, name = '', status = IF\(status = '(\w+)', '(\w+)', status\) WHERE email = '([^']*)'/", $sql, $m)) {
            $changed = 0;

            foreach ($this->transfers as $id => $row) {
                if ($row['email'] === $m[3]) {
                    $this->transfers[$id]['email'] = null;
                    $this->transfers[$id]['name'] = '';
                    $this->transfers[$id]['status'] = $row['status'] === $m[1] ? $m[2] : $row['status'];
                    $changed++;
                }
            }

            return $changed;
        }

        // anonymizeEmailsBefore().
        if (1 === preg_match("/SET email = NULL WHERE event_date < '([0-9-]+)'/", $sql, $m)) {
            $changed = 0;

            foreach ($this->transfers as $id => $row) {
                if (null !== $row['email'] && $row['event_date'] < $m[1]) {
                    $this->transfers[$id]['email'] = null;
                    $changed++;
                }
            }

            return $changed;
        }

        // markIssued() and end(): guarded on the current status.
        if (1 === preg_match("/SET status = '(\w+)', (name = '((?:[^'\\\\]|\\\\.)*)', issued_at|ended_at) = '([^']+)' WHERE id = (\d+) AND status = '(\w+)'/", $sql, $m)) {
            $id = (int) $m[5];

            if (! isset($this->transfers[$id]) || $this->transfers[$id]['status'] !== $m[6]) {
                return 0;
            }

            $this->transfers[$id]['status'] = $m[1];

            if (str_starts_with($m[2], 'name')) {
                $this->transfers[$id]['name'] = stripslashes($m[3]);
                $this->transfers[$id]['issued_at'] = $m[4];
            } else {
                $this->transfers[$id]['ended_at'] = $m[4];
            }

            return 1;
        }

        return 0;
    }
}

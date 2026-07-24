<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;
use EventCrew\Models\Person;

/**
 * All reads and writes of the people table.
 *
 * Every lookup is by verified email or Telegram id, because those are the two
 * identities a person can arrive with - the bot knows only the latter, the
 * web form only the former, and both must land on the same row.
 */
final class PersonRepository
{
    private function table(): string
    {
        return Schema::table(Schema::PEOPLE);
    }

    public function find(int $id): ?Person
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? Person::fromRow($row) : null;
    }

    /**
     * Email is compared lowercased because addresses are case-insensitive in
     * practice, and two rows differing only in case would split one person's
     * history - and therefore their earned credits - across two records.
     */
    public function findByEmail(string $email): ?Person
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE email = %s",
                $this->normalizeEmail($email)
            ),
            ARRAY_A
        );

        return is_array($row) ? Person::fromRow($row) : null;
    }

    public function findByTelegramUserId(int $telegramUserId): ?Person
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE telegram_user_id = %d",
                $telegramUserId
            ),
            ARRAY_A
        );

        return is_array($row) ? Person::fromRow($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        global $wpdb;

        $now = current_time('mysql');

        $wpdb->insert(
            $this->table(),
            [
                'email' => $this->normalizeEmail((string) ($data['email'] ?? '')),
                'email_verified_at' => $data['email_verified_at'] ?? null,
                'display_name' => (string) ($data['display_name'] ?? ''),
                'telegram_user_id' => $data['telegram_user_id'] ?? null,
                'telegram_chat_id' => $data['telegram_chat_id'] ?? null,
                'is_organizer' => ! empty($data['is_organizer']) ? 1 : 0,
                'can_lead' => ! empty($data['can_lead']) ? 1 : 0,
                'at_risk_pass' => ! empty($data['at_risk_pass']) ? 1 : 0,
                'notify_muted' => ! empty($data['notify_muted']) ? 1 : 0,
                'email_opt_in_at' => $data['email_opt_in_at'] ?? null,
                'email_opt_in_source' => (string) ($data['email_opt_in_source'] ?? ''),
                'notes' => (string) ($data['notes'] ?? ''),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        global $wpdb;

        if (isset($data['email'])) {
            $data['email'] = $this->normalizeEmail((string) $data['email']);
        }

        $data['updated_at'] = current_time('mysql');

        $wpdb->update($this->table(), $data, ['id' => $id]);
    }

    public function delete(int $id): void
    {
        global $wpdb;

        $wpdb->delete($this->table(), ['id' => $id]);
    }

    /**
     * Records affirmative consent to the open-task mail, together with where
     * it was given. The source is kept because under GDPR the burden of
     * showing consent was given falls on us, and "the column was set" is a
     * weaker answer than "set on this date, from the bot".
     */
    public function recordEmailOptIn(int $id, string $source): void
    {
        $this->update($id, [
            'email_opt_in_at' => current_time('mysql'),
            'email_opt_in_source' => $source,
        ]);
    }

    public function withdrawEmailOptIn(int $id): void
    {
        $this->update($id, [
            'email_opt_in_at' => null,
            'email_opt_in_source' => '',
        ]);
    }

    public function markEmailVerified(int $id): void
    {
        $this->update($id, ['email_verified_at' => current_time('mysql')]);
    }

    /**
     * Switches an account off: no email, off the boards and rosters, until the
     * person turns it back on. The row and its history are kept.
     */
    public function disable(int $id): void
    {
        $this->update($id, ['disabled_at' => current_time('mysql')]);
    }

    public function enable(int $id): void
    {
        $this->update($id, ['disabled_at' => null]);
    }

    public function setOrganizer(int $id, bool $isOrganizer): void
    {
        $this->update($id, ['is_organizer' => $isOrganizer ? 1 : 0]);
    }

    public function setCanLead(int $id, bool $canLead): void
    {
        $this->update($id, ['can_lead' => $canLead ? 1 : 0]);
    }

    /** Gives a one-time pass to sign up despite being at risk. */
    public function grantAtRiskPass(int $id): void
    {
        $this->update($id, ['at_risk_pass' => 1]);
    }

    /** Spends the pass - called once a gated signup it waved through succeeds. */
    public function clearAtRiskPass(int $id): void
    {
        $this->update($id, ['at_risk_pass' => 0]);
    }

    /** Records that the organizers have been told this person is leader-eligible. */
    public function markLeaderNotified(int $id): void
    {
        $this->update($id, ['leader_eligible_notified_at' => current_time('mysql')]);
    }

    /**
     * Every organizer - who gets the eligibility alerts and may run the
     * admin-only bot commands.
     *
     * @return array<int, Person>
     */
    public function organizers(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->table()} WHERE is_organizer = 1 ORDER BY id ASC",
            ARRAY_A
        );

        return array_map(
            static fn (array $row): Person => Person::fromRow($row),
            is_array($rows) ? $rows : []
        );
    }

    /**
     * Everyone the open-task email may go to: a verified address on an account
     * that is switched on. This is the whole recipient policy in one query -
     * the failure mode of getting it wrong is mailing people who shouldn't be
     * mailed, so it is default-deny (both columns must be right).
     *
     * @return array<int, Person>
     */
    public function activeEmailRecipients(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->table()}
            WHERE email_verified_at IS NOT NULL AND disabled_at IS NULL
            ORDER BY id ASC",
            ARRAY_A
        );

        return array_map(
            static fn (array $row): Person => Person::fromRow($row),
            is_array($rows) ? $rows : []
        );
    }

    /**
     * Attaches a Telegram identity to an existing person. Its own method so
     * the bot's onboarding never has to name the columns, and so the intent -
     * "this row is now reachable in Telegram" - reads at the call site. The
     * unique index on telegram_user_id is what stops two people claiming the
     * same account; the caller checks for that before reaching here.
     */
    public function linkTelegram(int $id, int $telegramUserId, ?int $telegramChatId): void
    {
        $this->update($id, [
            'telegram_user_id' => $telegramUserId,
            'telegram_chat_id' => $telegramChatId,
        ]);
    }

    /**
     * @param array{search?: string, orderby?: string, order?: string, per_page?: int, page?: int} $args
     * @return array<int, Person>
     */
    public function all(array $args = []): array
    {
        global $wpdb;

        $where = '1=1';
        $params = [];

        $search = trim((string) ($args['search'] ?? ''));

        if ('' !== $search) {
            $where .= ' AND (email LIKE %s OR display_name LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $orderBy = $this->safeOrderBy((string) ($args['orderby'] ?? 'display_name'));
        $order = 'DESC' === strtoupper((string) ($args['order'] ?? 'ASC')) ? 'DESC' : 'ASC';

        $perPage = max(1, (int) ($args['per_page'] ?? 50));
        $page = max(1, (int) ($args['page'] ?? 1));

        $sql = "SELECT * FROM {$this->table()} WHERE {$where} ORDER BY {$orderBy} {$order} LIMIT %d OFFSET %d";
        $params[] = $perPage;
        $params[] = ($page - 1) * $perPage;

        // Built from a whitelisted ORDER BY column and placeholders, then
        // bound through prepare(); the sniff cannot follow a query held in a
        // variable.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        return array_map(
            static fn (array $row): Person => Person::fromRow($row),
            is_array($rows) ? $rows : []
        );
    }

    public function count(string $search = ''): int
    {
        global $wpdb;

        $search = trim($search);

        if ('' === $search) {
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table()}");
        }

        $like = '%' . $wpdb->esc_like($search) . '%';

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table()} WHERE email LIKE %s OR display_name LIKE %s",
                $like,
                $like
            )
        );
    }

    /**
     * How many verified people have opted in to the open-task mail.
     * Surfaced on the dashboard so the organizer can tell whether that channel
     * is worth anything yet, rather than discovering it reaches nobody on the
     * night it matters.
     *
     * @return array{opted_in: int, verified: int}
     */
    public function optInStats(): array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            "SELECT
                SUM(CASE WHEN email_opt_in_at IS NOT NULL THEN 1 ELSE 0 END) AS opted_in,
                SUM(CASE WHEN email_verified_at IS NOT NULL THEN 1 ELSE 0 END) AS verified
            FROM {$this->table()}",
            ARRAY_A
        );

        return [
            'opted_in' => (int) ($row['opted_in'] ?? 0),
            'verified' => (int) ($row['verified'] ?? 0),
        ];
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Column names cannot be passed as query placeholders, so an ORDER BY
     * coming from a sortable table header is checked against a whitelist
     * rather than escaped.
     */
    private function safeOrderBy(string $column): string
    {
        $allowed = ['id', 'email', 'display_name', 'created_at', 'email_opt_in_at'];

        return in_array($column, $allowed, true) ? $column : 'display_name';
    }
}

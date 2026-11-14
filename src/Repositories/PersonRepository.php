<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;
use EventCrew\Models\Person;
use EventCrew\Support\History;
use EventCrew\Support\Language;
use EventCrew\Support\OrganizerSync;

/**
 * All reads and writes of the people table.
 *
 * Every lookup is by verified email or Telegram id, because those are the two
 * identities a person can arrive with - the bot knows only the latter, the
 * web form only the former, and both must land on the same row.
 */
final class PersonRepository
{
    /**
     * Telegram user id => person id that findByTelegramUserId() answers
     * with instead, while an organizer acts as someone (Telegram\ActingAs).
     *
     * @var array<int, int>
     */
    private static array $standIns = [];

    /**
     * From now on findByTelegramUserId($telegramUserId) finds person
     * $personId; null ends it. Only Telegram\ActingAs sets it, for one
     * update at a time.
     */
    public static function standIn(int $telegramUserId, ?int $personId): void
    {
        if (null === $personId) {
            unset(self::$standIns[$telegramUserId]);

            return;
        }

        self::$standIns[$telegramUserId] = $personId;
    }

    /** The person findByTelegramUserId($telegramUserId) finds in place of its owner, if any. */
    public static function standInFor(int $telegramUserId): ?int
    {
        return self::$standIns[$telegramUserId] ?? null;
    }

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
     * Several people by id in one query, keyed by id. An id with no person
     * is left out.
     *
     * @param array<int, int> $ids
     * @return array<int, Person>
     */
    public function findMany(array $ids): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));

        if ([] === $ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        // Placeholders generated for the ids; every value is bound.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$this->table()} WHERE id IN ({$placeholders})", ...$ids),
            ARRAY_A
        );
        $people = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $person = Person::fromRow($row);
            $people[$person->id] = $person;
        }

        return $people;
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

        if (isset(self::$standIns[$telegramUserId])) {
            return $this->find(self::$standIns[$telegramUserId]);
        }

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
     * is_organizer is not taken as given: it follows the roles
     * (OrganizerSync). 'is_organizer' => true in $data means "make them an
     * organizer", which gives them Organize. $by is who added them, for the
     * notice to a new organizer.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data, string $by = ''): int
    {
        global $wpdb;

        $roles = array_values((array) ($data['role_slugs'] ?? []));

        if (! empty($data['is_organizer'])) {
            $roles = OrganizerSync::withAdmin(Person::decodeRoles(self::encodeRoles($roles)));
        }

        $encoded = self::encodeRoles($roles);
        $organizer = OrganizerSync::organizerFor(Person::decodeRoles($encoded));

        $now = current_time('mysql');

        // Only a newcomer who signed themselves up is created waiting for the
        // team's OK; every other create path leaves it '' (OK).
        $approval = Person::APPROVAL_WAITING === ($data['approval'] ?? '')
            ? ['approval' => Person::APPROVAL_WAITING]
            : [];

        $wpdb->insert(
            $this->table(),
            $approval + [
                'email' => $this->normalizeEmail((string) ($data['email'] ?? '')),
                'email_verified_at' => $data['email_verified_at'] ?? null,
                'display_name' => (string) ($data['display_name'] ?? ''),
                'telegram_user_id' => $data['telegram_user_id'] ?? null,
                'telegram_chat_id' => $data['telegram_chat_id'] ?? null,
                'is_organizer' => $organizer,
                'at_risk_pass' => ! empty($data['at_risk_pass']) ? 1 : 0,
                'notify_muted' => ! empty($data['notify_muted']) ? 1 : 0,
                'role_slugs' => $encoded,
                'notes' => (string) ($data['notes'] ?? ''),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $id = (int) $wpdb->insert_id;

        if ($id > 0) {
            History::record(History::PERSON_ADDED, ['person_id' => $id]);

            if (1 === $organizer) {
                OrganizerSync::changed($id, true, $by);
            }
        }

        return $id;
    }

    /**
     * Writes the given columns. is_organizer is never taken from $data: it
     * follows role_slugs (OrganizerSync), written with them whenever they
     * are. When that changes the person's organizer status, OrganizerSync
     * hears of it; $by is who made the change, for the notice. $wasOrganizer
     * is their status before, when the caller has the person at hand (else
     * it is read).
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data, string $by = '', ?bool $wasOrganizer = null): void
    {
        global $wpdb;

        unset($data['is_organizer']);

        if (isset($data['email'])) {
            $data['email'] = $this->normalizeEmail((string) $data['email']);
        }

        $was = null;

        if (array_key_exists('role_slugs', $data)) {
            $data['role_slugs'] = (string) $data['role_slugs'];
            $data['is_organizer'] = OrganizerSync::organizerFor(Person::decodeRoles($data['role_slugs']));
            $was = null === $wasOrganizer ? $this->storedOrganizer($id) : ($wasOrganizer ? 1 : 0);
        }

        $this->write($id, $data);

        if (null !== $was && $was !== $data['is_organizer']) {
            OrganizerSync::changed($id, 1 === $data['is_organizer'], $by);
        }
    }

    /**
     * Writes the derived organizer copy alone. Only OrganizerSync calls it,
     * after a role's Admin access changed.
     */
    public function writeOrganizer(int $id, bool $isOrganizer): void
    {
        $this->write($id, ['is_organizer' => $isOrganizer ? 1 : 0]);
        OrganizerSync::changed($id, $isOrganizer);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(int $id, array $data): void
    {
        global $wpdb;

        $data['updated_at'] = current_time('mysql');

        // Recorded before the write: the recorder compares the organizer flag
        // and the roles with the stored ones and keeps only what changes.
        $roles = array_intersect_key($data, ['is_organizer' => true, 'role_slugs' => true]);

        if ([] !== $roles) {
            History::record(History::PERSON_CHANGED, ['person_id' => $id] + $roles);
        }

        $wpdb->update($this->table(), $data, ['id' => $id]);
    }

    /** The stored organizer copy (1 or 0); 0 for no such person. */
    private function storedOrganizer(int $id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT is_organizer FROM {$this->table()} WHERE id = %d", $id)
        ) > 0 ? 1 : 0;
    }

    public function delete(int $id): void
    {
        global $wpdb;

        $wpdb->delete($this->table(), ['id' => $id]);
    }

    /**
     * Ids of people whose last trace of activity is older than the cutoff -
     * the retention purge's candidate list.
     *
     * "Activity" is exactly what the published notice says it is: the last task
     * they were on, the last credit they were given, the last one they spent,
     * or failing all three the day they joined. The three subqueries are folded
     * into one GREATEST rather than checked in turn, so a person active in any
     * one of them is safe from the other two.
     *
     * COALESCE guards every branch because GREATEST() is NULL if any argument
     * is - a person with no assignments at all would otherwise compare as NULL,
     * fail the < test, and quietly never be purged.
     *
     * Organizers are held back regardless of age: their record
     * is what a running event is administered through, and anonymising the
     * person who holds the door list is not a retention policy, it is an
     * outage. Rows already stripped are skipped, which is what makes this
     * idempotent on an hourly heartbeat.
     *
     * @return array<int, int>
     */
    public function idsInactiveSince(string $cutoff, int $limit): array
    {
        global $wpdb;

        $assignments = Schema::table(Schema::ASSIGNMENTS);
        $redemptions = Schema::table(Schema::REDEMPTIONS);
        $grants = Schema::table(Schema::CREDIT_GRANTS);

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.id
                 FROM {$this->table()} p
                 LEFT JOIN (
                     SELECT person_id, MAX(GREATEST(signed_up_at, COALESCE(status_changed_at, signed_up_at))) AS last_at
                     FROM {$assignments} GROUP BY person_id
                 ) a ON a.person_id = p.id
                 LEFT JOIN (
                     SELECT person_id, MAX(redeemed_at) AS last_at FROM {$redemptions} GROUP BY person_id
                 ) r ON r.person_id = p.id
                 LEFT JOIN (
                     SELECT person_id, MAX(granted_at) AS last_at FROM {$grants} GROUP BY person_id
                 ) g ON g.person_id = p.id
                 WHERE p.anonymized_at IS NULL
                   AND p.is_organizer = 0
                   AND GREATEST(
                       p.created_at,
                       COALESCE(a.last_at, p.created_at),
                       COALESCE(r.last_at, p.created_at),
                       COALESCE(g.last_at, p.created_at)
                   ) < %s
                 ORDER BY p.id
                 LIMIT %d",
                $cutoff,
                $limit
            )
        );

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /**
     * Also fires `eventcrew/email_verified`, which the welcome message listens
     * to. It fires on every confirmation, including each web sign-in, so a
     * listener must not assume it is the first.
     */
    public function markEmailVerified(int $id): void
    {
        $this->update($id, ['email_verified_at' => current_time('mysql')]);

        do_action('eventcrew/email_verified', $id);
    }

    /**
     * Retires every web session this person currently holds, by moving the line
     * a session's issue time has to be after.
     *
     * Covers the sessions we cannot see - a phone left behind, a shared
     * computer, a cookie copied off a machine - which is the whole point: the
     * cookie is stateless, so there is no list of sessions to revoke one by
     * one. The person's own session goes with them; the web's Sign out
     * everywhere else then hands that browser a fresh cookie
     * (OtherSessions, flow1114), the bot keeps none.
     */
    public function revokeSessions(int $id): void
    {
        $this->update($id, ['sessions_valid_from' => current_time('mysql')]);
    }

    /**
     * Blocks a person: stamps banned_at with the reason, and moves the session
     * line in the same write, so every web session they hold stops working at
     * once. SignupService refuses their sign-ups and the crew page refuses
     * their sign-in while banned_at is set.
     */
    public function ban(int $id, string $reason): void
    {
        $now = current_time('mysql');

        $this->update($id, [
            'banned_at' => $now,
            'ban_reason' => $reason,
            'sessions_valid_from' => $now,
        ]);
    }

    /** Lifts a block. The person signs in again with a fresh link. */
    public function unban(int $id): void
    {
        $this->update($id, ['banned_at' => null, 'ban_reason' => null]);
    }

    /**
     * Makes the person an organizer, by giving them Organize
     * (OrganizerSync::withAdmin()), or takes it away, by taking every role
     * with Admin access they hold. The caller checks the lockout guard
     * (OrganizerSync::personWouldLeaveNone()) first. $by is who did it.
     */
    public function setOrganizer(int $id, bool $isOrganizer, string $by = ''): void
    {
        $person = $this->find($id);

        if (null !== $person) {
            $this->setOrganizerFor($person, $isOrganizer, $by);
        }
    }

    /** setOrganizer() for a person the caller has already read. */
    public function setOrganizerFor(Person $person, bool $isOrganizer, string $by = ''): void
    {
        $this->setRoles(
            $person->id,
            $isOrganizer
                ? OrganizerSync::withAdmin($person->roleSlugs)
                : OrganizerSync::withoutAdmin($person->roleSlugs),
            $by,
            $person->isOrganizer
        );
    }

    /**
     * Replaces the roles a person holds (DJ, VJ, Organize, ...); the
     * organizer copy follows (update()). $by is who did it; $wasOrganizer
     * as for update().
     *
     * @param array<int, string> $slugs
     */
    public function setRoles(int $id, array $slugs, string $by = '', ?bool $wasOrganizer = null): void
    {
        $this->update($id, ['role_slugs' => self::encodeRoles($slugs)], $by, $wasOrganizer);
    }

    /**
     * The comma-separated column value for a list of role slugs.
     *
     * @param array<int, mixed> $slugs
     */
    public static function encodeRoles(array $slugs): string
    {
        $clean = [];

        foreach ($slugs as $slug) {
            $slug = sanitize_key((string) $slug);

            if ('' === $slug) {
                continue;
            }

            $clean[$slug] = $slug;
        }

        return implode(',', array_values($clean));
    }

    /**
     * Stores a person's per-type notification channel preferences as JSON.
     *
     * @param array<string, array{dm?: bool, email?: bool}> $prefs
     */
    public function setNotifyPrefs(int $id, array $prefs): void
    {
        $this->update($id, ['notify_prefs' => wp_json_encode($prefs)]);
    }

    /**
     * Stores the language crew-facing text reaches this person in: 'en' or
     * 'fi', or null to go back to "not chosen".
     */
    public function setLanguage(int $id, ?string $language): void
    {
        $this->update($id, ['language' => Language::normalize($language)]);
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

    /**
     * Records that the organizers have been sent a role notice about this
     * person ("met:<slug>" or "given:<slug>"), so it is never sent twice. The
     * row is read fresh, so two notices in one run both stay recorded.
     */
    public function markRoleNotice(int $id, string $key): void
    {
        $person = $this->find($id);
        $notices = null === $person ? [] : $person->roleNotices;
        $notices[$key] = (string) current_time('mysql');

        $this->update($id, ['role_notices' => (string) wp_json_encode($notices)]);
    }

    /**
     * Drops the $remove keys from the person's role_notices record and adds
     * $entries, read fresh and written once: the newcomer's answers
     * (JoinAnswers) are replaced as a whole, and erased with no $entries.
     * Nothing is written when the person is not found or nothing changes.
     *
     * @param list<string> $remove
     * @param array<string, string> $entries
     */
    public function replaceRoleNotices(int $id, array $remove, array $entries = []): void
    {
        $person = $this->find($id);

        if (null === $person) {
            return;
        }

        $notices = array_merge(array_diff_key($person->roleNotices, array_flip($remove)), $entries);

        if ($notices === $person->roleNotices) {
            return;
        }

        $this->update($id, ['role_notices' => [] === $notices ? null : (string) wp_json_encode($notices)]);
    }

    /**
     * Adds key => value entries to the person's role_notices record, read
     * fresh and written once. The newcomer request keeps its Telegram copies,
     * emails and outcome there (NewcomerApproval). Nothing is written when
     * the person is not found, so a failed read never wipes the record.
     *
     * @param array<string, string> $entries
     */
    public function addRoleNotices(int $id, array $entries): void
    {
        $person = [] === $entries ? null : $this->find($id);

        if (null === $person) {
            return;
        }

        $this->update($id, ['role_notices' => (string) wp_json_encode(array_merge($person->roleNotices, $entries))]);
    }

    /**
     * Records an organizer's OK or No for a newcomer, only while they are
     * still waiting. True when this call made the decision, false when
     * someone else had already decided: the first organizer to press wins.
     */
    public function decideNewcomer(int $id, bool $ok, string $by): bool
    {
        global $wpdb;

        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table()} SET approval = %s, approval_by = %s, updated_at = %s
                WHERE id = %d AND approval = %s",
                $ok ? Person::APPROVAL_OK : Person::APPROVAL_NO,
                $by,
                current_time('mysql'),
                $id,
                Person::APPROVAL_WAITING
            )
        );

        return is_int($affected) && $affected > 0;
    }

    /**
     * Makes a waiting or turned-down person OK: they got a role or a pass,
     * which only an organizer gives. Nothing changes for anyone already OK.
     */
    public function approve(int $id): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table()} SET approval = %s, updated_at = %s
                WHERE id = %d AND approval IN (%s, %s)",
                Person::APPROVAL_OK,
                current_time('mysql'),
                $id,
                Person::APPROVAL_WAITING,
                Person::APPROVAL_NO
            )
        );
    }

    /**
     * Gives the OK to a newcomer who was turned down (or whose request timed
     * out), from the list of turned-down requests in wp-admin. True when this
     * call changed them, false when they were not turned down (any more).
     */
    public function acceptTurnedDown(int $id, string $by): bool
    {
        global $wpdb;

        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table()} SET approval = %s, approval_by = %s, updated_at = %s
                WHERE id = %d AND approval = %s",
                Person::APPROVAL_OK,
                $by,
                current_time('mysql'),
                $id,
                Person::APPROVAL_NO
            )
        );

        return is_int($affected) && $affected > 0;
    }

    /**
     * Newcomers who were turned down or whose request timed out, newest
     * first. Erased people are left out.
     *
     * @return array<int, Person>
     */
    public function turnedDownNewcomers(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()}
                WHERE approval = %s AND anonymized_at IS NULL
                ORDER BY updated_at DESC, id DESC",
                Person::APPROVAL_NO
            ),
            ARRAY_A
        );

        return array_map(
            static fn (array $row): Person => Person::fromRow($row),
            is_array($rows) ? $rows : []
        );
    }

    /**
     * Newcomers with a confirmed email who wait for the team's OK, oldest
     * first.
     *
     * @return array<int, Person>
     */
    public function waitingNewcomers(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()}
                WHERE approval = %s AND email_verified_at IS NOT NULL AND anonymized_at IS NULL
                ORDER BY created_at ASC, id ASC",
                Person::APPROVAL_WAITING
            ),
            ARRAY_A
        );

        return array_map(
            static fn (array $row): Person => Person::fromRow($row),
            is_array($rows) ? $rows : []
        );
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
     * Everyone the open-task email may go to: any verified address. We never
     * mail an unverified one, since that is someone else's inbox until they
     * prove it is theirs; per-type opt-outs are enforced later, by the
     * NotificationPreferences gate.
     *
     * @return array<int, Person>
     */
    public function activeEmailRecipients(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->table()}
            WHERE email_verified_at IS NOT NULL
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
        $allowed = ['id', 'email', 'display_name', 'created_at'];

        return in_array($column, $allowed, true) ? $column : 'display_name';
    }
}

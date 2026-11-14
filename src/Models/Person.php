<?php

declare(strict_types=1);

namespace EventCrew\Models;

use EventCrew\Support\Language;
use EventCrew\Support\Roles;

/**
 * One person who people. Never a WordPress user - WP accounts belong to
 * organizers only, and a person's identity here is their verified email.
 */
final class Person
{
    /** approval: a newcomer who signed in by themselves, waiting for the team's OK. */
    public const APPROVAL_WAITING = 'waiting';

    /** approval: an organizer gave the OK. */
    public const APPROVAL_OK = 'ok';

    /** approval: an organizer said No. */
    public const APPROVAL_NO = 'no';

    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $displayName,
        public readonly ?string $emailVerifiedAt = null,
        public readonly ?int $telegramUserId = null,
        public readonly ?int $telegramChatId = null,
        public readonly bool $isOrganizer = false,
        public readonly string $notes = '',
        public readonly string $createdAt = '',
        public readonly string $updatedAt = '',
        public readonly bool $notifyMuted = false,
        public readonly bool $hasAtRiskPass = false,
        /** @var array<string, array{dm?: bool, email?: bool, push?: bool}> per-type channel prefs */
        public readonly array $notifyPrefs = [],
        public readonly ?string $anonymizedAt = null,
        public readonly ?string $sessionsValidFrom = null,
        public readonly ?string $bannedAt = null,
        public readonly string $banReason = '',
        /** 'en' or 'fi'; null until the person chooses, which reads as English */
        public readonly ?string $language = null,
        /** @var array<int, string> slugs of the restricted roles given to this person */
        public readonly array $roleSlugs = [],
        /**
         * @var array<string, string> the role notices already sent to the
         * organizers about this person: "met:<slug>" or "given:<slug>" => when
         */
        public readonly array $roleNotices = [],
        /**
         * The team's OK: '' (OK: added by an organizer, or from before the
         * OK existed), or one of the APPROVAL_* values.
         */
        public readonly string $approval = '',
        /** The name of the organizer who gave the OK or said No; '' when not known. */
        public readonly string $approvalBy = ''
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['email'] ?? ''),
            (string) ($row['display_name'] ?? ''),
            self::nullableString($row['email_verified_at'] ?? null),
            self::nullableInt($row['telegram_user_id'] ?? null),
            self::nullableInt($row['telegram_chat_id'] ?? null),
            1 === (int) ($row['is_organizer'] ?? 0),
            (string) ($row['notes'] ?? ''),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
            1 === (int) ($row['notify_muted'] ?? 0),
            1 === (int) ($row['at_risk_pass'] ?? 0),
            self::decodePrefs($row['notify_prefs'] ?? null),
            self::nullableString($row['anonymized_at'] ?? null),
            self::nullableString($row['sessions_valid_from'] ?? null),
            self::nullableString($row['banned_at'] ?? null),
            (string) ($row['ban_reason'] ?? ''),
            Language::normalize($row['language'] ?? null),
            self::decodeRoles($row['role_slugs'] ?? null),
            self::decodeNotices($row['role_notices'] ?? null),
            (string) ($row['approval'] ?? ''),
            (string) ($row['approval_by'] ?? '')
        );
    }

    /**
     * Whether a web session issued at this time is still this person's.
     *
     * Sessions are stateless - nothing about them is stored - so revoking one
     * cannot mean deleting a row. It means moving a line: every cookie issued
     * before sessions_valid_from stops being accepted, which retires all of
     * them at once without knowing how many there were or where.
     */
    public function acceptsSessionIssuedAt(int $issuedAt): bool
    {
        if (null === $this->sessionsValidFrom) {
            return true;
        }

        /*
         * The two sides are on different clocks and have to be brought onto one
         * before they can be compared.
         *
         * A cookie's issue time is a real epoch, from time(). This column is a
         * naive site-local wall clock, from current_time('mysql'), like every
         * other datetime here. WordPress pins PHP's timezone to UTC, so reading
         * the column with a bare strtotime() interprets local time as though it
         * were UTC and lands the site's offset in the future - which refused
         * every freshly minted cookie for hours after a revocation, on any site
         * not actually running on UTC.
         *
         * get_gmt_from_date() converts the stored local string to GMT first, so
         * strtotime() then reads it correctly. The column keeps its local
         * convention, which is what the admin screens display.
         */
        $revokedAt = strtotime(get_gmt_from_date($this->sessionsValidFrom));

        return false === $revokedAt || $issuedAt >= $revokedAt;
    }

    /**
     * Whether the retention purge has already stripped this record. Such a row
     * is no longer a person - it is an attendance count with a placeholder
     * address - so nothing should mail it, gate on it, or offer it a slot.
     */
    public function isAnonymized(): bool
    {
        return null !== $this->anonymizedAt;
    }

    /**
     * Whether this person is blocked: no task sign-up and no web sign-in until
     * an organizer lifts it on the People page.
     */
    public function isBanned(): bool
    {
        return null !== $this->bannedAt;
    }

    /** A newcomer still waiting for the team's OK: they see the board but cannot take a task. */
    public function isWaitingForOk(): bool
    {
        return self::APPROVAL_WAITING === $this->approval;
    }

    /** A newcomer the team said No to: they cannot take a task. */
    public function isTurnedDown(): bool
    {
        return self::APPROVAL_NO === $this->approval;
    }

    /**
     * @return array<string, array{dm?: bool, email?: bool}>
     */
    private static function decodePrefs(mixed $value): array
    {
        if (! is_string($value) || '' === $value) {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Whether this person holds a one-time pass to sign up despite being at
     * risk. Consumed on their next successful signup.
     */
    public function hasAtRiskPass(): bool
    {
        return $this->hasAtRiskPass;
    }

    /**
     * Whether the organizers have already been sent this role notice about
     * this person. $key is "met:<slug>" or "given:<slug>".
     */
    public function hasRoleNotice(string $key): bool
    {
        return isset($this->roleNotices[$key]);
    }

    /**
     * The stored JSON of sent role notices, as key => when.
     *
     * @return array<string, string>
     */
    public static function decodeNotices(mixed $value): array
    {
        if (! is_string($value) || '' === $value) {
            return [];
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            return [];
        }

        $notices = [];

        foreach ($decoded as $key => $when) {
            if (is_string($key) && '' !== $key) {
                $notices[$key] = (string) $when;
            }
        }

        return $notices;
    }

    /**
     * Whether this person was given a restricted role (DJ, VJ, ...) and so may
     * see and take its tasks. Every organizer may take Organize tasks, also
     * one who is an organizer through another role with Admin access.
     */
    public function hasRole(string $slug): bool
    {
        if (Roles::ORGANIZE_SLUG === $slug && $this->isOrganizer) {
            return true;
        }

        return in_array($slug, $this->roleSlugs, true);
    }

    /**
     * Whether the address on file is a real one to write to. A person an
     * organizer added without an email carries a placeholder ending in
     * ".invalid" (the unique column needs something), which must never be
     * mailed.
     */
    public function hasRealEmail(): bool
    {
        return ! self::isPlaceholderEmail($this->email);
    }

    public static function isPlaceholderEmail(string $email): bool
    {
        return '' === $email || str_ends_with(strtolower($email), '.invalid');
    }

    /**
     * The stored comma-separated list, as slugs.
     *
     * @return array<int, string>
     */
    public static function decodeRoles(mixed $value): array
    {
        if (! is_string($value) || '' === $value) {
            return [];
        }

        $slugs = [];

        foreach (explode(',', $value) as $slug) {
            $slug = sanitize_key($slug);

            if ('' !== $slug && ! in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    public function isEmailVerified(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    public function hasTelegram(): bool
    {
        return null !== $this->telegramUserId;
    }

    /**
     * Whether the bot may DM this person the convenience messages - signup and
     * cancellation confirmations, and the standing notices. Muted from the
     * People page for anyone who finds them noise; the group board push is not
     * a DM and so is unaffected. Notices tied to an active commitment (the task
     * reminder) ignore this, as they are not the convenience kind.
     */
    public function wantsBotDms(): bool
    {
        return ! $this->notifyMuted;
    }


    /**
     * Falls back to the local part of the email so a person who never gave
     * a name still shows as something human on the roster.
     */
    public function name(): string
    {
        if ('' !== $this->displayName) {
            return $this->displayName;
        }

        $localPart = strstr($this->email, '@', true);

        return false === $localPart ? $this->email : $localPart;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (string) $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (int) $value;
    }
}

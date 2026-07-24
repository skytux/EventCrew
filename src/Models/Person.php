<?php

declare(strict_types=1);

namespace EventCrew\Models;

/**
 * One person who people. Never a WordPress user - WP accounts belong to
 * organizers only, and a person's identity here is their verified email.
 */
final class Person
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $displayName,
        public readonly ?string $emailVerifiedAt = null,
        public readonly ?int $telegramUserId = null,
        public readonly ?int $telegramChatId = null,
        public readonly bool $isOrganizer = false,
        public readonly ?string $emailOptInAt = null,
        public readonly string $emailOptInSource = '',
        public readonly ?string $disabledAt = null,
        public readonly string $notes = '',
        public readonly string $createdAt = '',
        public readonly string $updatedAt = '',
        public readonly bool $notifyMuted = false,
        public readonly bool $canLead = false,
        public readonly bool $hasAtRiskPass = false,
        public readonly ?string $leaderEligibleNotifiedAt = null
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
            self::nullableString($row['email_opt_in_at'] ?? null),
            (string) ($row['email_opt_in_source'] ?? ''),
            self::nullableString($row['disabled_at'] ?? null),
            (string) ($row['notes'] ?? ''),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
            1 === (int) ($row['notify_muted'] ?? 0),
            1 === (int) ($row['can_lead'] ?? 0),
            1 === (int) ($row['at_risk_pass'] ?? 0),
            self::nullableString($row['leader_eligible_notified_at'] ?? null)
        );
    }

    /**
     * Whether this person may hold the reserved Leader slot - trusted to run a
     * night and mark attendance from Telegram, without being a full organizer.
     */
    public function canLead(): bool
    {
        return $this->canLead;
    }

    /**
     * Whether this person holds a one-time pass to sign up despite being at
     * risk. Consumed on their next successful signup.
     */
    public function hasAtRiskPass(): bool
    {
        return $this->hasAtRiskPass;
    }

    /** Whether the organizers have already been told this person is leader-eligible. */
    public function leaderNotified(): bool
    {
        return null !== $this->leaderEligibleNotifiedAt;
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
     * Whether the account is switched off. A disabled person receives no email
     * and is left off the boards and rosters until they turn it back on.
     */
    public function isDisabled(): bool
    {
        return null !== $this->disabledAt;
    }

    /**
     * Whether this person may be sent the open-task email. The model is
     * transactional now, not opt-in: an active account with a verified address
     * gets it, and disabling the account (self-service, one tap in every mail)
     * is the off switch. We still never mail an unverified address, since that
     * is someone else's inbox until they prove it is theirs.
     */
    public function acceptsOpenTaskEmail(): bool
    {
        return $this->isEmailVerified() && ! $this->isDisabled();
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

<?php

declare(strict_types=1);

namespace EventCrew\Models;

/**
 * One person who volunteers. Never a WordPress user - WP accounts belong to
 * organizers only, and a volunteer's identity here is their verified email.
 */
final class Volunteer
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
        public readonly string $notes = '',
        public readonly string $createdAt = '',
        public readonly string $updatedAt = ''
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
            (string) ($row['notes'] ?? ''),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? '')
        );
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
     * Whether this volunteer may be sent the 48h open-shift call. Deliberately
     * phrased as a positive check against a timestamp being present: opt-in is
     * an affirmative act, and the absence of any record means no.
     */
    public function acceptsOpenShiftEmail(): bool
    {
        return null !== $this->emailOptInAt && $this->isEmailVerified();
    }

    /**
     * Falls back to the local part of the email so a volunteer who never gave
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

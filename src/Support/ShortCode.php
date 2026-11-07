<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\CheckinRepository;

/**
 * The short code written under a ticket's QR code, like "K7M4X", and on the
 * ticket's row in the door app, so the door crew can check by eye that the
 * row they tick by hand is the ticket on the screen (Owner, 2026-11-07).
 *
 * It names one door row, not one code: a ticket buyer's row (one per ticket,
 * so two tickets of one buyer show two codes), a guest's row, or a person's
 * crew row on one night, or a person's free-entry ticket on one night (its
 * own door row since 2026-11-07, so its own code). Every ticket of a kind therefore gets the same rule, also those
 * with no written code of their own, and the code on the page, in the email
 * and on the door row always agree.
 *
 * It is LENGTH characters of a keyed hash (SignedLink) of the row, written
 * with ALPHABET, which leaves out 0, O, 1, I and L so it reads back right.
 * It is a check by eye, not a key: nothing is let in by its short code.
 */
final class ShortCode
{
    public const LENGTH = 5;

    /** 31 characters, without 0, O, 1, I and L. */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /** A ticket buyer's row: one ticket. */
    public static function forBuyer(int $buyerId): string
    {
        return self::make('short_code|buyer', $buyerId);
    }

    /** A guest's row. */
    public static function forGuest(int $guestId): string
    {
        return self::make('short_code|guest', $guestId);
    }

    /** A person's crew row on one night. */
    public static function forPerson(int $personId, string $date): string
    {
        return self::make('short_code|person|' . $date, $personId);
    }

    /**
     * A person's free-entry ticket on one night: its own door row, apart
     * from their crew row when they also work (Owner 2026-11-07).
     */
    public static function forCredit(int $personId, string $date): string
    {
        return self::make('short_code|credit|' . $date, $personId);
    }

    /** The code of the door row "kind:ref" on $date. */
    public static function forRow(string $kind, int $ref, string $date): string
    {
        return match ($kind) {
            CheckinRepository::KIND_BUYER => self::forBuyer($ref),
            CheckinRepository::KIND_GUEST => self::forGuest($ref),
            CheckinRepository::KIND_CREDIT => self::forCredit($ref, $date),
            default => self::forPerson($ref, $date),
        };
    }

    private static function make(string $purpose, int $id): string
    {
        if ($id <= 0) {
            return '';
        }

        // 12 hex digits are 48 bits: an int on any 64-bit PHP, and far more
        // than the 25 bits five characters of 31 hold.
        $number = (int) hexdec(substr(SignedLink::shortSignature($purpose, $id), 0, 12));
        $base = strlen(self::ALPHABET);
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[$number % $base];
            $number = intdiv($number, $base);
        }

        return $code;
    }
}

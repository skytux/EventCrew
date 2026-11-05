<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\AuthTokenRepository;

/**
 * The sales desk's "Other device" button: login links made on the page itself,
 * shown as a QR code for a second phone to scan.
 *
 * "Open on other device" makes a link for the person holding the session,
 * and only for them: a session cannot make a link for anyone else.
 *
 * Each link is an ordinary door login link (DoorSession::loginUrl, spent by
 * DoorDesk::login) over an auth token of its own purpose, stored hashed, that
 * works once and only for 15 minutes. Spending it gives that device the
 * person's normal door cookie. Only a valid, open door session may make one:
 * every method here reads the session from the cookie first.
 */
final class DoorHandoff
{
    /** link() answers. */
    public const OK = 'ok';
    public const NO_SESSION = 'no_session';
    public const NOT_ALLOWED = 'not_allowed';

    public function __construct(
        private readonly DoorDesk $desk,
        private readonly AuthTokenRepository $tokens,
        private readonly SalesDeskGate $gate
    ) {
    }

    /**
     * A one-time login link for the person holding the session - never for
     * anyone else. 'code' is one of the class constants; 'url' is '' unless it
     * is OK.
     *
     * @return array{code: string, url: string, name: string}
     */
    public function link(string $cookie): array
    {
        $session = $this->openSession($cookie);

        if (null === $session) {
            return ['code' => self::NO_SESSION, 'url' => '', 'name' => ''];
        }

        $date = $session['date'];
        $person = $session['person'];

        $seconds = min(DoorSession::HANDOFF_SECONDS, $this->gate->secondsLeft($person, $date));

        if ($seconds <= 0) {
            return ['code' => self::NOT_ALLOWED, 'url' => '', 'name' => ''];
        }

        $raw = $this->tokens->issue($person->id, DoorSession::HANDOFF_PURPOSE, $seconds);

        return ['code' => self::OK, 'url' => DoorSession::loginUrl($raw, $date), 'name' => $person->name()];
    }

    /**
     * The plain sentence for a link() answer that is not OK.
     */
    public static function message(string $code): string
    {
        return match ($code) {
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            self::NO_SESSION => __('This device is not logged in to the sales desk. Ask an organizer for a new link (/door in Telegram, then 💳 Sales desk).', 'eventcrew'),
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            default => __('This person cannot run the sales desk tonight. Only organizers, and the welcome crew once marked arrived tonight, can.', 'eventcrew'),
        };
    }

    /**
     * @return array{person: Person, date: string, open: bool}|null
     */
    private function openSession(string $cookie): ?array
    {
        $session = $this->desk->session($cookie);

        return null === $session || ! $session['open'] ? null : $session;
    }
}

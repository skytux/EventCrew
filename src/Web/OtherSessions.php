<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\AppInstallPrompt;
use EventCrew\Support\WebSession;

/**
 * "Sign out everywhere else" on the web (flow1114): every web session of
 * the person ends, except the one that asked.
 *
 * The cookie is stateless, so there is no list of sessions to pick from:
 * the person's line (PersonRepository::revokeSessions()) moves to now,
 * which ends every cookie issued before it, and this browser gets a fresh
 * cookie issued after it, so it stays signed in. Used by the Settings
 * button (SignupController) and the helper box (AssistantWebParity).
 *
 * The bot and the Telegram helper have no web session to keep: they call
 * revokeSessions() alone, which ends every web sign-in.
 */
final class OtherSessions
{
    /**
     * Ends the person's other web sessions and keeps this one. Returns the
     * fresh cookie value, also set on the response and in $_COOKIE.
     */
    public static function signOut(PersonRepository $people, Person $person, ?int $now = null): string
    {
        $old = isset($_COOKIE[WebSession::COOKIE]) ? (string) $_COOKIE[WebSession::COOKIE] : '';

        $people->revokeSessions($person->id);

        // Read after the line moved, so the fresh cookie is never before it.
        $now ??= time();
        $fresh = WebSession::mint($person->id, $now);

        // A "Get the app" pop-in this browser closed stays closed.
        $carried = AppInstallPrompt::carryOver($person, (int) WebSession::issuedAt($old, $now), $now);

        if (null !== $carried) {
            $people->addRoleNotices($person->id, $carried);
        }

        if (! headers_sent()) {
            setcookie(
                WebSession::COOKIE,
                $fresh,
                [
                    'expires' => $now + WebSession::TTL,
                    'path' => '/',
                    'secure' => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]
            );
        }

        $_COOKIE[WebSession::COOKIE] = $fresh;

        return $fresh;
    }
}

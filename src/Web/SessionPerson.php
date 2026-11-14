<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\History;
use EventCrew\Support\WebSession;

/**
 * The person the crew page's session cookie belongs to, read fresh from the
 * people table on every call.
 *
 * Shared by the crew page and its Admin tab so both answer "who is this" the
 * same way: a valid signature, a session not revoked by "Sign out everywhere else",
 * and a person who is not blocked. Nothing about roles is taken from the
 * request; isOrganizer comes from the row read here.
 */
final class SessionPerson
{
    public function __construct(private readonly PersonRepository $people)
    {
    }

    /**
     * The revocation check rides on a row this already had to read, so "sign
     * out everywhere" costs nothing per request - which is what made it worth
     * having at all for a cookie whose whole design is to touch no storage.
     */
    public function current(): ?Person
    {
        $cookie = isset($_COOKIE[WebSession::COOKIE])
            ? (string) $_COOKIE[WebSession::COOKIE]
            : '';

        $personId = WebSession::read($cookie);

        if (null === $personId) {
            return null;
        }

        $person = $this->people->find($personId);

        if (null === $person || $person->isBanned()) {
            return null;
        }

        $issuedAt = WebSession::issuedAt($cookie);

        if (null === $issuedAt || ! $person->acceptsSessionIssuedAt($issuedAt)) {
            return null;
        }

        // What this request changes is done by this person (the history).
        History::actAs(History::CHANNEL_WEB, $person->id);

        return $person;
    }
}

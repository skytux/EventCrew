<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;

/**
 * Signing up for a task and cancelling one from the Crew app page, with the
 * confirmation emails: what the page's helper box runs after Confirm.
 * SignupController is the one in use. Both return the page's notice code
 * (SignupController::noticeText()).
 */
interface WebTaskSignup
{
    public function claimAndNotify(Person $person, int $taskId, ?bool $countsCredit = null): string;

    public function dropAndNotify(Person $person, int $taskId): string;
}

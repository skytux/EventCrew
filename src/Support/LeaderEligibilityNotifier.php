<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Telegram\TelegramClient;

/**
 * Tells the organizers, once, when someone has earned crew-leader eligibility,
 * so a good candidate does not go unnoticed. Runs on the hourly tick.
 *
 * The one-shot guard is the person's own leader_eligible_notified_at column, so
 * a candidate is announced the first hour they qualify and never again - the
 * organizer decides whether to /allow them.
 */
final class LeaderEligibilityNotifier
{
    public function __construct(
        private readonly LeaderEligibility $eligibility,
        private readonly PersonRepository $people,
        private readonly Mailer $mailer,
        private readonly TelegramClient $telegram
    ) {
    }

    public function run(): void
    {
        $organizers = $this->people->organizers();

        if ([] === $organizers) {
            return;
        }

        foreach ($this->eligibility->eligiblePeople() as $person) {
            if ($person->leaderNotified()) {
                continue;
            }

            $this->announce($organizers, $person);
            $this->people->markLeaderNotified($person->id);
        }
    }

    /**
     * @param array<int, Person> $organizers
     */
    private function announce(array $organizers, Person $person): void
    {
        $line = sprintf(
            /* translators: %s: the candidate's name */
            __('%s has earned crew-leader eligibility — /allow them to lead.', 'eventcrew'),
            $person->name()
        );

        foreach ($organizers as $organizer) {
            if (null !== $organizer->telegramChatId && $organizer->wantsBotDms()) {
                $this->telegram->sendMessage(
                    $organizer->telegramChatId,
                    DmBody::frame($organizer, '🧭 ' . $line)
                );
            }

            $this->mailer->toPerson(
                $organizer->id,
                $organizer->email,
                __('New crew-leader candidate', 'eventcrew'),
                $line
            );
        }
    }
}

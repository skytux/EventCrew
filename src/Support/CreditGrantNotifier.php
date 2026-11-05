<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Telegram\TelegramClient;

/**
 * Tells a person an organizer has handed them a free-entry credit, on both
 * channels they have - a Telegram DM and an email - so a gift from the People
 * page and a gift from the bot's /people both land the same way.
 *
 * Best-effort, like every other confirmation: the grant is already recorded by
 * the time this runs, so a DM or email that doesn't send must never be treated
 * as a failure of the gift. The DM honours a muted account and the email a
 * disabled one, matching ClaimNotifier's rules.
 */
final class CreditGrantNotifier
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly TelegramClient $telegram
    ) {
    }

    public function notify(Person $person, int $credits = 1): void
    {
        Language::forPerson($person, fn () => $this->send($person, $credits));
    }

    private function send(Person $person, int $credits): void
    {
        $credits = max(1, $credits);
        $prefs = new NotificationPreferences();
        // The texts are the Messages page templates (MessageTemplates).
        $context = ['person' => $person, 'credits' => $credits];

        if ($prefs->dmAllowed($person, NotificationPreferences::CREDIT)) {
            $this->telegram->sendMessage(
                $person->telegramChatId,
                DmBody::frame($person, MessageTemplates::body('credit_granted_dm', $context))
            );
        }

        if (! $prefs->emailAllowed($person, NotificationPreferences::CREDIT)) {
            return;
        }

        $this->mailer->toPerson(
            $person->id,
            $person->email,
            MessageTemplates::subject('credit_granted', $context),
            MessageTemplates::body('credit_granted', $context),
            [['label' => __('Get your free ticket', 'eventcrew'), 'url' => $this->mailer->boardUrl()]]
        );
    }
}

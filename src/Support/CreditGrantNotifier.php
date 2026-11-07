<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Telegram\TicketRedemptionService;

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
        private readonly TelegramClient $telegram,
        private readonly ?StandingCalculator $standing = null,
        private readonly ?TicketRedemptionService $tickets = null
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
        // With the credits they hold now and the next nights to spend one on.
        $context = [
            'person' => $person,
            'credits' => $credits,
            'balance' => $this->standing?->for($person->id)->creditBalance,
            'nights' => $this->tickets?->nextNights($person->id) ?? [],
        ];

        if ($prefs->dmAllowed($person, NotificationPreferences::CREDIT)) {
            $this->telegram->record()->sendMessage(
                $person->telegramChatId,
                DmBody::frame($person, MessageTemplates::body('credit_granted_dm', $context)),
                MessageActions::replyMarkup(MessageActions::of('credit_granted_dm', $context, $this->mailer))
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
            MessageActions::email(MessageActions::of('credit_granted', $context, $this->mailer))
        );
    }
}

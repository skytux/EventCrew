<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Telegram\TelegramClient;

/**
 * Tells a person an organizer has handed them a free-entry credit, on both
 * channels they have - a Telegram DM and an email - so a gift from the People
 * page and a gift from the bot's /gift both land the same way.
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
        $credits = max(1, $credits);

        if (null !== $person->telegramChatId && $person->wantsBotDms()) {
            $this->telegram->sendMessage(
                $person->telegramChatId,
                sprintf(
                    /* translators: %d: number of free-entry credits granted */
                    // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                    _n(
                        '🎁 You’ve been given %d free-entry credit. Send /ticket to spend it on an upcoming event.',
                        '🎁 You’ve been given %d free-entry credits. Send /ticket to spend them on upcoming events.',
                        $credits,
                        'eventcrew'
                    ),
                    $credits
                )
            );
        }

        if ($person->isDisabled()) {
            return;
        }

        $this->mailer->toPerson(
            $person->id,
            $person->email,
            __('You’ve been given free entry', 'eventcrew'),
            sprintf(
                /* translators: 1: the person's name, 2: number of credits */
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                _n(
                    "Hi %1\$s,\n\nAn organizer has given you a free-entry credit. You can spend it on any upcoming event — open the EventCrew bot and send /ticket, or use your sign-up page, to get your free ticket.",
                    "Hi %1\$s,\n\nAn organizer has given you %2\$d free-entry credits. You can spend them on upcoming events — open the EventCrew bot and send /ticket, or use your sign-up page, to get your free ticket.",
                    $credits,
                    'eventcrew'
                ),
                $person->name(),
                $credits
            )
        );
    }
}

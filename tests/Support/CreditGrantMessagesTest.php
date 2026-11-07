<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MessageActions;
use EventCrew\Support\MessageTemplates;
use EventCrew\Telegram\BoardService;
use EventCrew\Tests\Telegram\TelegramTestCase;

/**
 * "Free entry given": the credits held now, the next nights, and a button per
 * night that is the 🎟 picker's own button (it spends nothing by itself in an
 * email: the link only shows that button in the bot).
 */
final class CreditGrantMessagesTest extends TelegramTestCase
{
    /** @return array<string, mixed> */
    private static function context(): array
    {
        return [
            'credits' => 1,
            'balance' => 3,
            'nights' => ['2026-11-14' => 'Sat 14 Nov · Party', '2026-11-21' => 'Sat 21 Nov'],
        ];
    }

    public function testTheMessagesSayTheCreditsNowAndTheNextNights(): void
    {
        $dm = MessageTemplates::body('credit_granted_dm', self::context());
        self::assertStringEndsWith("\nYou now have 3 free-entry credits.", $dm);

        $email = MessageTemplates::body('credit_granted', ['person' => null] + self::context());
        self::assertStringContainsString(
            "You now have 3 free-entry credits.\n\nNext nights for free entry:\n- 🎟 Sat 14 Nov · Party\n- 🎟 Sat 21 Nov",
            $email
        );

        // Unknown balance, no nights: no empty lines left behind.
        self::assertStringEndsWith('upcoming event.', MessageTemplates::body('credit_granted_dm', ['credits' => 1]));
    }

    public function testEachNightIsThePickersButtonAndAnEmailLinkThatOnlyShowsIt(): void
    {
        $this->options[BoardService::USERNAME_OPTION] = 'crewbot';
        $mailer = new Mailer(new Logger(), new EmailTemplate(new Logger()));
        $actions = MessageActions::of('credit_granted_dm', self::context(), $mailer);

        $keyboard = MessageActions::keyboard($actions);
        self::assertSame('tkt:2026-11-14', $keyboard[0][0]['callback_data']);
        self::assertSame('🎟 Sat 14 Nov · Party', $keyboard[0][0]['text']);
        self::assertSame('tkt:2026-11-21', $keyboard[1][0]['callback_data']);
        self::assertSame('🎟 Free-entry ticket', $keyboard[2][0]['text']);

        $urls = array_column(MessageActions::email($actions), 'url');
        self::assertContains('https://t.me/crewbot?start=tkt-20261114', $urls);
        self::assertContains('https://t.me/crewbot?start=tkt-20261121', $urls);
        self::assertSame(['tkt', '20261114'], MessageActions::screenPayload('tkt-20261114'));
    }

    /** Owner, Q344 2026-11-07: a title, then the event, the day, the venue and the times. */
    public function testTheTicketDmNamesTheEventFirst(): void
    {
        self::assertSame(
            "✨ Your free-entry ticket ✨\n\nParty\nSat 1 Aug\n\n💫 Show it at the door.",
            MessageTemplates::body('free_ticket_dm', ['date' => 'Sat 1 Aug', 'event' => 'Party'])
        );
        self::assertSame(
            "✨ Your free-entry ticket ✨\nSat 1 Aug\n\n💫 Show it at the door.",
            MessageTemplates::body('free_ticket_dm', ['date' => 'Sat 1 Aug'])
        );
    }
}

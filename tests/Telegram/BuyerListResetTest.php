<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\Language;
use EventCrew\Telegram\BuyerListReset;

/**
 * The "🗑 Reset the list" button under the bot's "tickets were imported"
 * message: it asks first, then deletes the night's imported ticket buyers.
 */
final class BuyerListResetTest extends TelegramTestCase
{
    private function reset(): BuyerListReset
    {
        return new BuyerListReset(new PersonRepository(), new TicketBuyerRepository(), $this->client());
    }

    private function queuePerson(bool $organizer): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 9,
            'email' => 'org@example.test',
            'display_name' => 'Org',
            'telegram_user_id' => 555,
            'telegram_chat_id' => 555,
            'is_organizer' => $organizer ? 1 : 0,
        ];
    }

    /** @return array<string, mixed> */
    private static function tap(string $data, int $from = 555): array
    {
        return [
            'id' => 'cb1',
            'from' => ['id' => $from],
            'message' => ['message_id' => 71, 'chat' => ['id' => $from, 'type' => 'private']],
            'data' => $data,
        ];
    }

    /** @return list<string> */
    private function deletes(): array
    {
        return array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_starts_with(ltrim($sql), 'DELETE')
        ));
    }

    public function testTheButtonAsksFirstWithTheNightsCurrentCount(): void
    {
        $this->queuePerson(true);
        $this->wpdb->nextVars[] = '7'; // imported buyers on the night now

        $this->reset()->onTap(self::tap(BuyerListReset::data(BuyerListReset::ASK, '2026-08-01')));

        $sent = $this->lastCallTo('sendMessage');
        self::assertNotNull($sent);
        self::assertSame(
            'Delete all 7 imported ticket buyers for Sat 1 Aug? Their tickets stop working.',
            $sent['text']
        );
        self::assertStringContainsString(
            "event_date = '2026-08-01' AND source NOT IN ('door', 'desk')",
            $this->wpdb->lastQuery()
        );
        $buttons = $sent['reply_markup']['inline_keyboard'][0];
        self::assertSame('🗑 Yes, delete', $buttons[0]['text']);
        self::assertSame('tbr:yes:2026-08-01', $buttons[0]['callback_data']);
        self::assertSame('tbr:no:2026-08-01', $buttons[1]['callback_data']);
        self::assertSame([], $this->deletes());
    }

    public function testANightWithNoImportedBuyersSaysSoAndOffersNoButtons(): void
    {
        $this->queuePerson(true);
        $this->wpdb->nextVars[] = '0';

        $this->reset()->onTap(self::tap('tbr:ask:2026-08-01'));

        $sent = $this->lastCallTo('sendMessage');
        self::assertSame('There are no imported ticket buyers for Sat 1 Aug.', $sent['text']);
        self::assertArrayNotHasKey('reply_markup', $sent);
    }

    public function testYesDeletesTheNightsImportedBuyersAndSaysHowMany(): void
    {
        $this->queuePerson(true);
        $this->wpdb->nextQueryResults[] = 7;

        $this->reset()->onTap(self::tap('tbr:yes:2026-08-01'));

        $deletes = $this->deletes();
        self::assertCount(1, $deletes);
        self::assertStringContainsString(
            "event_date = '2026-08-01' AND source NOT IN ('door', 'desk')",
            $deletes[0]
        );

        $edit = $this->lastCallTo('editMessageText');
        self::assertSame(71, $edit['message_id']);
        self::assertSame('🗑 Deleted 7 ticket buyers for Sat 1 Aug.', $edit['text']);
        self::assertSame([], $edit['reply_markup']['inline_keyboard']);
    }

    public function testCancelChangesNothing(): void
    {
        $this->queuePerson(true);

        $this->reset()->onTap(self::tap('tbr:no:2026-08-01'));

        self::assertSame([], $this->deletes());
        self::assertSame(
            '✖️ Cancelled. The ticket list was not changed.',
            $this->lastCallTo('editMessageText')['text']
        );
    }

    public function testAForwardedButtonDoesNothingForSomeoneWhoIsNotAnOrganizer(): void
    {
        foreach (['ask', 'yes'] as $step) {
            $this->telegramCalls = [];
            $this->queuePerson(false);
            $this->wpdb->nextQueryResults[] = 7;

            $this->reset()->onTap(self::tap('tbr:' . $step . ':2026-08-01'));

            self::assertSame([], $this->deletes());
            self::assertNull($this->lastCallTo('sendMessage'));
            self::assertNull($this->lastCallTo('editMessageText'));
            $answer = $this->lastCallTo('answerCallbackQuery');
            self::assertSame('Only organizers can reset the ticket list.', $answer['text']);
            self::assertTrue($answer['show_alert']);
        }

        // Nobody known by that Telegram account at all.
        $this->reset()->onTap(self::tap('tbr:yes:2026-08-01', 777));
        self::assertSame([], $this->deletes());
    }

    public function testABadDateOrStepIsIgnored(): void
    {
        $bad = ['tbr:yes:2026-02-30', 'tbr:yes:2026-8-1', 'tbr:yes:', 'tbr:wipe:2026-08-01', 'tbr:yes:2026-08-01x'];

        foreach ($bad as $data) {
            $this->queuePerson(true);
            $this->reset()->onTap(self::tap($data));
        }

        self::assertSame([], $this->deletes());
        self::assertNull($this->lastCallTo('sendMessage'));
        self::assertNull($this->lastCallTo('editMessageText'));
    }

    public function testTheCallbackDataFitsTelegramsLimit(): void
    {
        foreach ([BuyerListReset::ASK, BuyerListReset::YES, BuyerListReset::NO] as $step) {
            self::assertLessThanOrEqual(64, strlen(BuyerListReset::data($step, '2026-12-31')));
        }

        $button = BuyerListReset::markup('2026-08-01')['inline_keyboard'][0][0];
        self::assertSame('🗑 Reset the list', $button['text']);
        self::assertSame('tbr:ask:2026-08-01', $button['callback_data']);
    }

    public function testTheTextsAreFinnishForAFinnishOrganizer(): void
    {
        $this->queuePerson(true);
        $this->wpdb->nextVars[] = '7';

        Language::run(Language::FINNISH, function (): void {
            $this->reset()->onTap(self::tap('tbr:ask:2026-11-14'));
        });

        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(
            'Poistetaanko päivän la 14.11. kaikki 7 tuotua lipunostajaa? Heidän lippunsa lakkaavat toimimasta.',
            $sent['text']
        );

        $this->queuePerson(true);
        $this->wpdb->nextQueryResults[] = 7;

        Language::run(Language::FINNISH, function (): void {
            $this->reset()->onTap(self::tap('tbr:yes:2026-11-14'));
        });

        self::assertSame('🗑 Poistettu 7 lipunostajaa päivältä la 14.11.', $this->lastCallTo('editMessageText')['text']);
        self::assertSame('🗑 Nollaa lista', Language::run(
            Language::FINNISH,
            static fn (): string => BuyerListReset::markup('2026-11-14')['inline_keyboard'][0][0]['text']
        ));
    }

    public function testTheRouterSendsTheTapsHere(): void
    {
        $router = $this->updateRouter(buyerListReset: $this->reset());
        $this->queuePerson(true);

        $router->dispatch(['update_id' => 1, 'callback_query' => self::tap('tbr:no:2026-08-01')]);

        self::assertSame(
            '✖️ Cancelled. The ticket list was not changed.',
            $this->lastCallTo('editMessageText')['text']
        );
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\EventsCommand;

/**
 * /events: the coming events as picture cards, shown to an organizer first
 * with Confirm and Cancel, then posted in the group.
 */
final class EventsCommandTest extends TelegramTestCase
{
    use FakeEvents;

    private const GROUP = -100777;

    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeEvents();
        $this->transients = [];
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => self::GROUP, 'message_id' => 5];

        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;

            return true;
        });
        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);

            return true;
        });

        $this->event(7, '2026-07-25', 'Summer Night', [
            '_eventmesh_image_url' => 'https://holvi-cdn.example.test/a.jpg',
            '_eventmesh_url' => 'https://holvi.example.test/shop/x/product/1/',
        ]);
        $this->event(8, '2026-08-01', 'August Night', [
            '_eventmesh_url' => 'https://holvi.example.test/shop/x/product/2/',
        ]);
    }

    private function command(): EventsCommand
    {
        return new EventsCommand(new PersonRepository(), $this->client());
    }

    private function queuePerson(bool $organizer): void
    {
        $this->wpdb->nextRows[] = [
            'id' => 9,
            'email' => 'org@example.test',
            'display_name' => 'Org',
            'telegram_user_id' => 555,
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

    private function draftId(): string
    {
        $markup = $this->lastCallTo('sendMessage')['reply_markup'] ?? [];
        $data = (string) ($markup['inline_keyboard'][0][0]['callback_data'] ?? '');

        return substr($data, strlen(EventsCommand::PREFIX . 'ok:'));
    }

    /** @return array<int, array{method: string, body: array<string, mixed>}> */
    private function callsTo(int $chatId): array
    {
        return array_values(array_filter(
            $this->telegramCalls,
            static fn (array $call): bool => $chatId === ($call['body']['chat_id'] ?? null)
        ));
    }

    public function testItIsRefusedInAGroup(): void
    {
        $this->command()->onCommand(555, self::GROUP, false);

        self::assertStringContainsString('private chat', (string) $this->lastCallTo('sendMessage')['text']);
        self::assertSame(['sendMessage'], $this->calledMethods());
    }

    public function testItIsRefusedToSomeoneWhoIsNotAnOrganizer(): void
    {
        $this->queuePerson(false);

        $this->command()->onCommand(555, 555, true);

        self::assertStringContainsString('Only organizers', (string) $this->lastCallTo('sendMessage')['text']);
        self::assertSame(['sendMessage'], $this->calledMethods());
    }

    public function testWithoutComingEventsItSaysSo(): void
    {
        $this->events = [];
        $this->queuePerson(true);

        $this->command()->onCommand(555, 555, true);

        self::assertSame('There are no coming events.', $this->lastCallTo('sendMessage')['text']);
        self::assertSame([], $this->transients);
    }

    public function testThePreviewShowsTheCardsThenConfirmAndNothingGoesToTheGroupYet(): void
    {
        $this->queuePerson(true);

        $this->command()->onCommand(555, 555, true);

        self::assertSame(['sendPhoto', 'sendMessage', 'sendMessage'], $this->calledMethods());
        self::assertStringStartsWith('<b>Summer Night</b>', (string) $this->lastCallTo('sendPhoto')['caption']);
        self::assertSame('<b>August Night</b>', strtok((string) $this->telegramCalls[1]['body']['text'], "\n"));
        self::assertSame(
            'The 2 events above go to the group when you tap Confirm.',
            $this->lastCallTo('sendMessage')['text']
        );
        self::assertSame(16, strlen($this->draftId()));
        self::assertSame([], $this->callsTo(self::GROUP));
    }

    public function testConfirmPostsTheSameCardsInTheGroup(): void
    {
        $this->queuePerson(true);
        $this->command()->onCommand(555, 555, true);
        $preview = array_slice($this->telegramCalls, 0, 2);
        $id = $this->draftId();

        // Changed after the preview: the group still gets what was shown.
        $this->events[7]['post']->post_title = 'Renamed';
        $this->queuePerson(true);
        $this->command()->onConfirm(self::tap(EventsCommand::PREFIX . 'ok:' . $id));

        $posted = $this->callsTo(self::GROUP);

        self::assertCount(2, $posted);
        self::assertSame('sendPhoto', $posted[0]['method']);
        self::assertSame($preview[0]['body']['caption'], $posted[0]['body']['caption']);
        self::assertSame($preview[0]['body']['reply_markup'], $posted[0]['body']['reply_markup']);
        self::assertSame('sendMessage', $posted[1]['method']);
        self::assertSame($preview[1]['body']['text'], $posted[1]['body']['text']);
        self::assertStringContainsString('Sent to the group', (string) $this->lastCallTo('sendMessage')['text']);
        self::assertContains('editMessageReplyMarkup', $this->calledMethods());
        self::assertSame([], $this->transients);
    }

    public function testCancelPostsNothing(): void
    {
        $this->queuePerson(true);
        $this->command()->onCommand(555, 555, true);
        $id = $this->draftId();

        $this->command()->onConfirm(self::tap(EventsCommand::PREFIX . 'no:' . $id));

        self::assertStringContainsString('Nothing was sent', (string) $this->lastCallTo('sendMessage')['text']);
        self::assertSame([], $this->callsTo(self::GROUP));
        self::assertSame([], $this->transients);
    }

    public function testSomeoneElseCannotConfirm(): void
    {
        $this->queuePerson(true);
        $this->command()->onCommand(555, 555, true);
        $id = $this->draftId();

        $this->command()->onConfirm(self::tap(EventsCommand::PREFIX . 'ok:' . $id, 666));

        self::assertStringContainsString('expired', (string) $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertSame([], $this->callsTo(self::GROUP));
        self::assertCount(1, $this->transients);
    }

    public function testAFormerOrganizerCannotConfirm(): void
    {
        $this->queuePerson(true);
        $this->command()->onCommand(555, 555, true);
        $id = $this->draftId();

        $this->queuePerson(false);
        $this->command()->onConfirm(self::tap(EventsCommand::PREFIX . 'ok:' . $id));

        self::assertSame([], $this->callsTo(self::GROUP));
    }

    public function testAFailedPreviewOffersNoConfirm(): void
    {
        $this->telegramErrors['sendPhoto'] = 'Bad Request: failed to get HTTP URL content';
        $this->telegramErrors['sendMessage'] = 'Bad Request: can\'t parse entities';
        $this->queuePerson(true);

        $this->command()->onCommand(555, 555, true);

        self::assertStringContainsString('did not accept', (string) $this->lastCallTo('sendMessage')['text']);
        self::assertSame([], $this->transients);
    }

    public function testTheRouterSendsTheCommandAndTheTapsHere(): void
    {
        $router = $this->updateRouter(events: $this->command());
        $this->queuePerson(true);

        $router->dispatch([
            'update_id' => 1,
            'message' => [
                'message_id' => 70,
                'from' => ['id' => 555],
                'chat' => ['id' => 555, 'type' => 'private'],
                'text' => '/events',
            ],
        ]);
        $id = $this->draftId();
        $router->dispatch(['update_id' => 2, 'callback_query' => self::tap(EventsCommand::PREFIX . 'no:' . $id)]);

        self::assertSame(16, strlen($id));
        self::assertStringContainsString('Nothing was sent', (string) $this->lastCallTo('sendMessage')['text']);
    }
}

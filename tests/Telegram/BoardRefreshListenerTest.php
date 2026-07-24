<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Telegram\BoardRefreshListener;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\TelegramClient;

final class BoardRefreshListenerTest extends TelegramTestCase
{
    private function listener(): BoardRefreshListener
    {
        return new BoardRefreshListener(
            new BoardService(
                new TaskRepository(),
                new AssignmentRepository(),
                new PersonRepository(),
                $this->client(),
                new Logger(),
                new ClaimNotifier(new TaskRepository(), new AssignmentRepository(), new Mailer(new Logger()), $this->client(), $this->standing()),
                $this->signup()
            )
        );
    }

    public function testBootRegistersTheStaleHook(): void
    {
        $listener = $this->listener();

        Functions\expect('add_action')
            ->once()
            ->with(BoardRefreshListener::HOOK, [$listener, 'onBoardStale']);

        $listener->boot();

        self::assertTrue(true);
    }

    public function testRefreshesTheBoardWhenConfigured(): void
    {
        $this->options[BoardService::BOARD_OPTION] = ['chat_id' => 100, 'message_id' => 0];
        $this->wpdb->nextResults[] = []; // render: no tasks

        $this->listener()->onBoardStale();

        self::assertContains('sendMessage', $this->calledMethods());
    }

    public function testNoOpsWhenTheBotIsNotConfigured(): void
    {
        $this->options[TelegramClient::TOKEN_OPTION] = '';

        $this->listener()->onBoardStale();

        self::assertSame([], $this->telegramCalls);
    }
}

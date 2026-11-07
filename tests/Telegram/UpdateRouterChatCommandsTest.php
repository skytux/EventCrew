<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Telegram\ChatCommands;
use EventCrew\Telegram\MemberCommands;
use EventCrew\Telegram\NightCommands;
use EventCrew\Telegram\PersonCommands;
use EventCrew\Telegram\TellCommand;

/**
 * UpdateRouter hands the private-chat commands and their button taps to
 * ChatCommands: typed before /say, tapped after AssignCommand. TellCommand
 * is real; the other three are never reached here, so they are bare.
 */
final class UpdateRouterChatCommandsTest extends TelegramTestCase
{
    use CommandTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommandTest();
    }

    private function chatCommands(): ChatCommands
    {
        $tell = new TellCommand(
            new PersonRepository(),
            new TaskRepository(),
            new AssignmentRepository(),
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            $this->client()
        );

        return new ChatCommands(
            (new \ReflectionClass(PersonCommands::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(NightCommands::class))->newInstanceWithoutConstructor(),
            $tell,
            (new \ReflectionClass(MemberCommands::class))->newInstanceWithoutConstructor()
        );
    }

    public function testATypedTellReachesTellCommand(): void
    {
        $this->updateRouter(null, null, null, null, null, false, null, null, $this->chatCommands())->dispatch([
            'message' => [
                'text' => '/tell bar ; Please come early.',
                'chat' => ['id' => -100123, 'type' => 'supergroup'],
                'from' => ['id' => 555],
            ],
        ]);

        self::assertSame('Use /tell in a private chat with me.', $this->lastText());
    }

    public function testATellButtonTapReachesTellCommand(): void
    {
        $this->updateRouter(null, null, null, null, null, false, null, null, $this->chatCommands())->dispatch([
            'callback_query' => $this->tap('tl:y:0123456789abcdef'),
        ]);

        self::assertSame(
            'This request has expired. Please send it again.',
            $this->lastCallTo('answerCallbackQuery')['text'] ?? ''
        );
    }

    public function testAnAssignTapIsNotClaimed(): void
    {
        $this->updateRouter(null, null, null, null, null, false, null, null, $this->chatCommands())->dispatch([
            'callback_query' => $this->tap('as:y:0123456789abcdef'),
        ]);

        self::assertNotSame(
            'This request has expired. Please send it again.',
            $this->lastCallTo('answerCallbackQuery')['text'] ?? ''
        );
    }
}

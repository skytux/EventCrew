<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use EventCrew\Telegram\ChatCommands;
use PHPUnit\Framework\TestCase;

final class ChatCommandsTest extends TestCase
{
    public function testOurCommandWordsAreFound(): void
    {
        self::assertSame('mark', ChatCommands::commandOf('/mark Milla late'));
        self::assertSame('tell', ChatCommands::commandOf('/Tell@EventCrewBot bar ; Hi'));
        self::assertSame('crew', ChatCommands::commandOf('/crew'));
    }

    public function testOtherCommandsAndLongerWordsAreLeftAlone(): void
    {
        self::assertSame('', ChatCommands::commandOf('/board'));
        self::assertSame('', ChatCommands::commandOf('/marker'));
        self::assertSame('', ChatCommands::commandOf('mark Milla'));
    }

    public function testOnlyOurButtonsAreClaimed(): void
    {
        foreach (['pc:y:a', 'ng:x:a', 'tl:y:a', 'mc:y:a'] as $data) {
            self::assertTrue(ChatCommands::handlesCallback($data), $data);
        }
        self::assertFalse(ChatCommands::handlesCallback('as:y:a'));
    }
}

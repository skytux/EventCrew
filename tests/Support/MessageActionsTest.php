<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\EmailBody;
use EventCrew\Support\MessageAction;
use EventCrew\Support\MessageActions;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\MenuButtons;
use EventCrew\Tests\TestCase;

final class MessageActionsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [BoardService::USERNAME_OPTION => '@crewbot'];
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
    }

    /** @return list<MessageAction> */
    private function actions(): array
    {
        return [
            new MessageAction('ticket', 'Show your ticket', '🎟 Show your ticket', static fn (): string => 'https://site.test/t?sig=abc'),
            new MessageAction('cancel', 'Cancel', '', static fn (): string => 'https://site.test/crew/', 'l:12', 'board'),
            new MessageAction('board', 'See open tasks', '', static fn (): string => 'https://site.test/crew/', '', 'board'),
            new MessageAction('nothing', 'Nothing'),
        ];
    }

    public function testTheKeyboardUsesTheCallbackThenTheVerbThenTheLink(): void
    {
        self::assertSame(
            [
                [['text' => '🎟 Show your ticket', 'url' => 'https://site.test/t?sig=abc']],
                [['text' => 'Cancel', 'callback_data' => 'l:12']],
                [['text' => 'See open tasks', 'callback_data' => MenuButtons::BOARD]],
            ],
            MessageActions::keyboard($this->actions())
        );
        self::assertNull(MessageActions::replyMarkup([new MessageAction('x', 'X')]));
    }

    public function testTheEmailHasEachWebLinkThenOneTelegramLinkPerVerb(): void
    {
        self::assertSame(
            [
                ['label' => 'Show your ticket', 'url' => 'https://site.test/t?sig=abc'],
                ['label' => 'Cancel', 'url' => 'https://site.test/crew/'],
                ['label' => 'See open tasks', 'url' => 'https://site.test/crew/'],
                ['label' => 'Cancel', 'url' => 'https://t.me/crewbot?start=board', 'style' => EmailBody::STYLE_TELEGRAM],
            ],
            MessageActions::email($this->actions())
        );
    }

    public function testNoTelegramLinkWithoutABot(): void
    {
        $this->options[BoardService::USERNAME_OPTION] = '';

        self::assertSame('', MessageActions::startUrl('board'));
        self::assertCount(3, MessageActions::email($this->actions()));
    }

    public function testTheStartPayloadIsOnlyAKnownVerb(): void
    {
        self::assertSame('https://t.me/crewbot?start=ticket', MessageActions::startUrl('ticket'));
        self::assertSame('', MessageActions::startUrl('login-abc'));
        self::assertSame('', MessageActions::startUrl(''));

        foreach (array_keys(MenuButtons::VERBS) as $verb) {
            self::assertTrue(MessageActions::isPayload($verb), $verb);
            self::assertSame(MenuButtons::VERBS[$verb], MenuButtons::forVerb($verb));
        }

        self::assertFalse(MessageActions::isPayload(str_repeat('a', 65)));
        self::assertFalse(MessageActions::isPayload('a b'));
        self::assertFalse(MessageActions::isPayload('a=b'));
    }

    public function testTheUrlIsMadeOnlyWhenDrawn(): void
    {
        $made = 0;
        $action = new MessageAction('m', 'Manage', '', function () use (&$made): string {
            $made++;

            return 'https://site.test/login?token=x';
        }, 'l:3');

        MessageActions::keyboard([$action]);
        self::assertSame(0, $made);

        MessageActions::email([$action]);
        self::assertSame(1, $made);
    }

    public function testTheEmailDrawsTelegramLinksAsOneLineUnderTheButtons(): void
    {
        $actions = MessageActions::email($this->actions());
        $html = EmailBody::toHtml('Hello', $actions);
        $text = EmailBody::toText('Hello', $actions);

        // Three big buttons, not four: the Telegram link is a small link.
        self::assertSame(3, substr_count($html, 'border-radius:28px;background'));
        self::assertStringContainsString('Open in Telegram:', $html);
        self::assertStringContainsString('href="https://t.me/crewbot?start=board"', $html);
        self::assertGreaterThan(strpos($html, 'See open tasks'), strpos($html, 'Open in Telegram:'));
        self::assertStringContainsString('Cancel in Telegram: https://t.me/crewbot?start=board', $text);
        self::assertStringContainsString('Show your ticket: https://site.test/t?sig=abc', $text);
    }
}

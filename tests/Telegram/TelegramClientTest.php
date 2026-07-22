<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Telegram\TelegramClient;

final class TelegramClientTest extends TelegramTestCase
{
    public function testSendMessagePostsToTheMethodUrlWithAJsonBody(): void
    {
        $result = $this->client()->sendMessage(42, 'hello', ['inline_keyboard' => []]);

        self::assertSame(['sendMessage'], $this->calledMethods());
        $body = $this->lastCallTo('sendMessage');
        self::assertSame(42, $body['chat_id']);
        self::assertSame('hello', $body['text']);
        self::assertSame(['inline_keyboard' => []], $body['reply_markup']);
        // A bare `true` result comes back as an empty array meaning "ok".
        self::assertIsArray($result);
    }

    public function testOmitsReplyMarkupWhenNoneIsGiven(): void
    {
        $this->client()->sendMessage(42, 'hello');

        self::assertArrayNotHasKey('reply_markup', $this->lastCallTo('sendMessage'));
    }

    public function testReturnsTheResultPayloadForSendMessage(): void
    {
        $this->telegramResults['sendMessage'] = ['message_id' => 7];

        $result = $this->client()->sendMessage(42, 'hello');

        self::assertSame(7, $result['message_id']);
    }

    public function testMakesNoHttpCallWhenNoTokenIsConfigured(): void
    {
        $this->options[TelegramClient::TOKEN_OPTION] = '';

        $result = $this->client()->sendMessage(42, 'hello');

        self::assertNull($result);
        self::assertSame([], $this->telegramCalls);
    }

    public function testReturnsNullOnATransportError(): void
    {
        Functions\when('is_wp_error')->justReturn(true);
        $error = new class {
            public function get_error_message(): string
            {
                return 'timeout';
            }
        };
        Functions\when('wp_remote_post')->justReturn($error);

        self::assertNull($this->client()->getMe());
    }

    public function testReturnsNullWhenTelegramReportsNotOk(): void
    {
        Functions\when('wp_remote_post')->justReturn(
            (string) json_encode(['ok' => false, 'description' => 'Unauthorized'])
        );

        self::assertNull($this->client()->getMe());
    }

    public function testLastErrorSurfacesTelegramsDescription(): void
    {
        Functions\when('wp_remote_post')->justReturn(
            (string) json_encode(['ok' => false, 'description' => 'HTTPS url must be provided'])
        );

        $client = $this->client();
        $client->setWebhook('http://example.test/hook', 'sekret');

        self::assertSame('HTTPS url must be provided', $client->lastError());
    }

    public function testLastErrorClearsAfterASuccessfulCall(): void
    {
        $client = $this->client();

        Functions\when('wp_remote_post')->justReturn((string) json_encode(['ok' => false, 'description' => 'boom']));
        $client->getMe();
        self::assertSame('boom', $client->lastError());

        Functions\when('wp_remote_post')->justReturn((string) json_encode(['ok' => true, 'result' => true]));
        $client->getMe();
        self::assertSame('', $client->lastError());
    }

    public function testBenignEditAndCallbackErrorsAreTreatedAsSuccess(): void
    {
        $client = $this->client();

        // A no-op board edit: the board already shows this content.
        Functions\when('wp_remote_post')->justReturn((string) json_encode([
            'ok' => false,
            'description' => 'Bad Request: message is not modified',
        ]));
        $result = $client->editMessageText(42, 7, 'same text');
        self::assertIsArray($result);      // the "ok" sentinel, not null
        self::assertSame('', $client->lastError());

        // A callback answered after Telegram's window: nothing we can fix.
        Functions\when('wp_remote_post')->justReturn((string) json_encode([
            'ok' => false,
            'description' => 'Bad Request: query is too old and response timeout expired or query ID is invalid',
        ]));
        $result = $client->answerCallbackQuery('cb-1', 'you’re in');
        self::assertIsArray($result);
        self::assertSame('', $client->lastError());
    }

    public function testSetWebhookSendsTheSecretTokenAndNarrowedUpdates(): void
    {
        $this->client()->setWebhook('https://example.test/hook', 'sekret');

        $body = $this->lastCallTo('setWebhook');
        self::assertSame('https://example.test/hook', $body['url']);
        self::assertSame('sekret', $body['secret_token']);
        self::assertSame(['message', 'callback_query', 'my_chat_member'], $body['allowed_updates']);
    }

    public function testDeleteWebhookCallsTheMethod(): void
    {
        $this->client()->deleteWebhook();

        self::assertContains('deleteWebhook', $this->calledMethods());
    }

    public function testSetMyCommandsSendsTheCommandList(): void
    {
        $this->client()->setMyCommands([
            ['command' => 'board', 'description' => 'Show the board'],
        ]);

        $body = $this->lastCallTo('setMyCommands');
        self::assertSame('board', $body['commands'][0]['command']);
    }

    public function testDnsBypassResolvesViaDohThenStillCalls(): void
    {
        $this->options[TelegramClient::DNS_BYPASS_OPTION] = true;

        $lookups = 0;
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('add_action')->justReturn(true);
        Functions\when('remove_action')->justReturn(true);
        Functions\when('wp_remote_get')->alias(function () use (&$lookups): string {
            ++$lookups;

            return (string) json_encode(['Answer' => [['type' => 1, 'data' => '149.154.167.220']]]);
        });

        $this->client()->getMe();

        // A DoH lookup happened, and the API call still went out.
        self::assertSame(1, $lookups);
        self::assertContains('getMe', $this->calledMethods());
    }

    public function testDnsBypassStillCallsWhenTheLookupFails(): void
    {
        $this->options[TelegramClient::DNS_BYPASS_OPTION] = true;

        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        // DoH yields no usable answer -> resolve() returns null -> fall back.
        Functions\when('wp_remote_get')->justReturn((string) json_encode(['Answer' => []]));

        $this->client()->getMe();

        self::assertContains('getMe', $this->calledMethods());
    }
}

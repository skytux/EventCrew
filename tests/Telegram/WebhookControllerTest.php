<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Logger;
use EventCrew\Support\RosterAssembler;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\OnboardingService;
use EventCrew\Telegram\RosterService;
use EventCrew\Telegram\UpdateRouter;
use Brain\Monkey\Functions;
use EventCrew\Telegram\WebhookController;
use WP_REST_Request;

final class WebhookControllerTest extends TelegramTestCase
{
    private function controller(): WebhookController
    {
        $router = new UpdateRouter(
            new OnboardingService(new PersonRepository(), new AuthTokenRepository(), $this->client(), new Logger()),
            new BoardService(
                new TaskRepository(),
                new AssignmentRepository(),
                new PersonRepository(),
                $this->client(),
                new Logger()
            ),
            new RosterService(
                new RosterAssembler(new TaskRepository(), new AssignmentRepository(), new PersonRepository()),
                new TaskRepository(),
                new PersonRepository(),
                $this->client()
            )
        );

        return new WebhookController($router);
    }

    private function request(string $secretHeader): WP_REST_Request
    {
        $request = new WP_REST_Request();

        if ('' !== $secretHeader) {
            $request->set_header('X-Telegram-Bot-Api-Secret-Token', $secretHeader);
        }

        return $request;
    }

    public function testRefusesWhenNoSecretIsStored(): void
    {
        // TelegramTestCase seeds only the token option, not the secret.
        self::assertFalse($this->controller()->authorize($this->request('anything')));
    }

    public function testRefusesAMismatchedSecret(): void
    {
        $this->options[WebhookController::SECRET_OPTION] = 'correct-secret';

        self::assertFalse($this->controller()->authorize($this->request('wrong-secret')));
    }

    public function testRefusesAMissingHeader(): void
    {
        $this->options[WebhookController::SECRET_OPTION] = 'correct-secret';

        self::assertFalse($this->controller()->authorize($this->request('')));
    }

    public function testAcceptsTheMatchingSecret(): void
    {
        $this->options[WebhookController::SECRET_OPTION] = 'correct-secret';

        self::assertTrue($this->controller()->authorize($this->request('correct-secret')));
    }

    public function testHandleDispatchesAnUpdateAndAlwaysReturns200(): void
    {
        $request = new WP_REST_Request();
        $request->body_params = ['callback_query' => ['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'j:5']];

        $response = $this->controller()->handle($request);

        self::assertSame(200, $response->get_status());
        // The callback reached the board, which answered it.
        self::assertContains('answerCallbackQuery', $this->calledMethods());
    }

    public function testHandleReturns200EvenForAnEmptyBody(): void
    {
        $response = $this->controller()->handle(new WP_REST_Request());

        self::assertSame(200, $response->get_status());
        self::assertSame([], $this->telegramCalls);
    }

    // --- fallback door (admin-ajax.php) -------------------------------------

    public function testFallbackDispatchesWithTheMatchingSecret(): void
    {
        $this->options[WebhookController::SECRET_OPTION] = 'correct-secret';
        $body = (string) json_encode(['callback_query' => ['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'j:5']]);

        $status = $this->controller()->processFallback('correct-secret', $body);

        self::assertSame(200, $status);
        self::assertContains('answerCallbackQuery', $this->calledMethods());
    }

    public function testFallbackRefusesAWrongSecret(): void
    {
        $this->options[WebhookController::SECRET_OPTION] = 'correct-secret';
        $body = (string) json_encode(['callback_query' => ['id' => 'cbq', 'from' => ['id' => 555], 'data' => 'j:5']]);

        $status = $this->controller()->processFallback('wrong-secret', $body);

        self::assertSame(403, $status);
        self::assertSame([], $this->telegramCalls);
    }

    public function testFallbackRefusesWhenNoSecretIsStored(): void
    {
        self::assertSame(403, $this->controller()->processFallback('anything', '{}'));
    }

    public function testFallbackReturns200ButIgnoresAnUnparseableBody(): void
    {
        $this->options[WebhookController::SECRET_OPTION] = 'correct-secret';

        $status = $this->controller()->processFallback('correct-secret', 'not json at all');

        self::assertSame(200, $status);
        self::assertSame([], $this->telegramCalls);
    }

    public function testWebhookUrlUsesRestByDefault(): void
    {
        Functions\when('rest_url')->alias(static fn (string $path): string => 'https://example.test/wp-json/' . $path);

        $url = WebhookController::webhookUrl('sekret');

        self::assertStringContainsString('wp-json/eventcrew/v1/telegram/webhook', $url);
        self::assertStringNotContainsString('sekret', $url);
    }

    public function testWebhookUrlUsesAdminAjaxWithTheSecretWhenToggled(): void
    {
        $this->options[WebhookController::USE_FALLBACK_OPTION] = true;
        Functions\when('admin_url')->alias(static fn (string $path): string => 'https://example.test/wp-admin/' . $path);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );

        $url = WebhookController::webhookUrl('sekret');

        self::assertStringContainsString('admin-ajax.php', $url);
        self::assertStringContainsString('action=eventcrew_telegram_webhook', $url);
        self::assertStringContainsString('token=sekret', $url);
    }
}

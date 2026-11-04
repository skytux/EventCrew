<?php

declare(strict_types=1);

namespace EventCrew\Tests\Admin;

use EventCrew\Admin\SettingsPage;
use EventCrew\Admin\View;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\TelegramLogin;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Tests\Telegram\TelegramTestCase;

/**
 * The automatic on-update webhook refresh. The full install path makes live
 * Telegram calls and is exercised by hand; here we only pin the two gates that
 * decide whether it runs at all.
 */
final class SettingsPageTest extends TelegramTestCase
{
    private const WEBHOOK_VERSION_OPTION = 'eventcrew_webhook_version';

    protected function tearDown(): void
    {
        $_POST = [];

        parent::tearDown();
    }

    /** Runs an admin-post handler, which ends in a redirect, and returns the notice it set. */
    private function save(array $post): string
    {
        $notice = '';
        \Brain\Monkey\Functions\when('current_user_can')->justReturn(true);
        \Brain\Monkey\Functions\when('check_admin_referer')->justReturn(1);
        \Brain\Monkey\Functions\when('wp_unslash')->returnArg(1);
        \Brain\Monkey\Functions\when('sanitize_text_field')->returnArg(1);
        \Brain\Monkey\Functions\when('get_current_user_id')->justReturn(3);
        \Brain\Monkey\Functions\when('delete_option')->alias(function (string $key): bool {
            unset($this->options[$key]);
            return true;
        });
        \Brain\Monkey\Functions\when('set_transient')->alias(static function (string $key, $value) use (&$notice): bool {
            $notice = is_array($value) ? (string) ($value['message'] ?? reset($value)) : (string) $value;
            return true;
        });
        \Brain\Monkey\Functions\when('admin_url')->alias(static fn (string $path = ''): string => 'https://site.test/wp-admin/' . $path);
        \Brain\Monkey\Functions\when('add_query_arg')->justReturn('https://site.test/wp-admin/admin.php');
        \Brain\Monkey\Functions\when('wp_safe_redirect')->alias(static function (): void {
            throw new \RuntimeException('redirected');
        });

        $_POST = $post;

        try {
            $this->page()->saveImportMailbox();
            self::fail('Expected the redirect.');
        } catch (\RuntimeException $e) {
            self::assertSame('redirected', $e->getMessage());
        }

        return $notice;
    }

    public function testTheImportMailboxIsSaved(): void
    {
        $this->save(['import_mailbox' => ' lists@example.org ']);

        self::assertSame('lists@example.org', $this->options[\EventCrew\Web\BuyerImportController::MAILBOX_OPTION]);
        self::assertSame('lists@example.org', \EventCrew\Web\BuyerImportController::mailbox());
    }

    public function testAnEmptyImportMailboxRemovesIt(): void
    {
        $this->options[\EventCrew\Web\BuyerImportController::MAILBOX_OPTION] = 'lists@example.org';

        $this->save(['import_mailbox' => '']);

        self::assertArrayNotHasKey(\EventCrew\Web\BuyerImportController::MAILBOX_OPTION, $this->options);
        self::assertSame('', \EventCrew\Web\BuyerImportController::mailbox());
    }

    public function testANonAddressLeavesTheImportMailboxAsItWas(): void
    {
        $this->options[\EventCrew\Web\BuyerImportController::MAILBOX_OPTION] = 'lists@example.org';

        $notice = $this->save(['import_mailbox' => 'not an address']);

        self::assertSame('lists@example.org', $this->options[\EventCrew\Web\BuyerImportController::MAILBOX_OPTION]);
        self::assertStringContainsString('not an email address', $notice);
    }

    public function testTheExtraSenderAddressesAreSavedOnePerLine(): void
    {
        $this->save([
            'import_mailbox' => 'lists@example.org',
            'import_senders' => "Shop@Example.com\r\nnot an address\n\nshop@example.com, other@example.net",
        ]);

        self::assertSame(
            ['shop@example.com', 'other@example.net'],
            $this->options[\EventCrew\Web\BuyerImportController::SENDERS_OPTION]
        );
    }

    public function testTicketFormatsAreSavedRemovedAndAddedInOneForm(): void
    {
        $decoder = "if (code < 2) {\r\n  return '<b>' + code;\r\n}";

        $this->save([
            'import_mailbox' => 'lists@example.org',
            'ticket_formats' => [
                [
                    'id' => 'holvi',
                    'name' => 'Holvi',
                    'col_name' => 'Etunimi + Sukunimi',
                    'col_email' => 'Sähköposti',
                    'col_code' => 'Tilauskoodi',
                    'decoder_js' => $decoder,
                ],
                ['id' => 'old', 'name' => 'Old', 'col_name' => 'Name', 'remove' => '1'],
                ['id' => '', 'name' => 'Shop', 'col_name' => 'Name|Buyer', 'col_email' => '', 'col_code' => 'Order'],
                ['id' => '', 'name' => '', 'col_name' => '', 'col_email' => '', 'col_code' => '', 'decoder_js' => ''],
            ],
        ]);

        $stored = $this->options[\EventCrew\Support\TicketFormats::OPTION];

        self::assertSame(['holvi', 'shop'], array_column($stored, 'id'));
        self::assertSame("if (code < 2) {\n  return '<b>' + code;\n}", $stored[0]['decoder_js']);
        self::assertSame(['Name', 'Buyer'], $stored[1]['columns']['name']);
        self::assertSame('Order', $stored[1]['columns']['code']);
    }

    public function testAFormWithoutTheNewFieldsLeavesThemAsTheyWere(): void
    {
        $this->options[\EventCrew\Web\BuyerImportController::SENDERS_OPTION] = ['shop@example.com'];

        $this->save(['import_mailbox' => 'lists@example.org']);

        self::assertSame(['shop@example.com'], $this->options[\EventCrew\Web\BuyerImportController::SENDERS_OPTION]);
        self::assertArrayNotHasKey(\EventCrew\Support\TicketFormats::OPTION, $this->options);
    }

    private function page(): SettingsPage
    {
        $logger = new Logger();

        return new SettingsPage(
            new View(),
            $this->client(),
            new Mailer($logger, new EmailTemplate($logger)),
            new EmailTemplate($logger),
            $this->boardService(),
            $this->welcomeSender(),
            new \EventCrew\Repositories\PersonRepository(),
            $this->commandMenu()
        );
    }

    public function testInstallOnUpdateDoesNothingWhenAlreadyCurrent(): void
    {
        $this->options[self::WEBHOOK_VERSION_OPTION] = EVENTCREW_VERSION;

        $this->page()->installOnUpdate();

        // Already installed for this version: no Telegram calls at all.
        self::assertSame([], $this->telegramCalls);
    }

    public function testInstallingTheWebhookKeepsTheBotsNameAndUsername(): void
    {
        $this->options[\EventCrew\Telegram\WebhookController::SECRET_OPTION] = 'secret';
        \Brain\Monkey\Functions\when('rest_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-json/' . $path
        );
        $saved = [];
        \Brain\Monkey\Functions\when('update_option')->alias(
            static function (string $name, mixed $value) use (&$saved): bool {
                $saved[$name] = $value;

                return true;
            }
        );
        $this->telegramResults['getMe'] = ['username' => 'crewbot', 'first_name' => 'Flow Keeper'];

        $this->page()->installWebhook();

        self::assertSame('crewbot', $saved[\EventCrew\Telegram\BoardService::USERNAME_OPTION] ?? null);
        self::assertSame('Flow Keeper', $saved[\EventCrew\Telegram\BoardService::NAME_OPTION] ?? null);
    }

    public function testInstallingTheWebhookSendsTheCommandMenus(): void
    {
        $this->options[\EventCrew\Telegram\WebhookController::SECRET_OPTION] = 'secret';
        \Brain\Monkey\Functions\when('rest_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-json/' . $path
        );
        $this->telegramResults['getMe'] = ['username' => 'crewbot'];

        $result = $this->page()->installWebhook();

        self::assertTrue($result['ok']);

        $scopes = [];

        foreach ($this->telegramCalls as $call) {
            if ('setMyCommands' === $call['method']) {
                $scopes[] = $call['body']['scope']['type'] . '/' . ($call['body']['language_code'] ?? '');
            }
        }

        self::assertSame(
            ['all_group_chats/', 'all_group_chats/fi', 'all_private_chats/', 'all_private_chats/fi'],
            $scopes
        );
    }

    private function readyToInstall(): void
    {
        $this->options[\EventCrew\Telegram\WebhookController::SECRET_OPTION] = 'secret';
        \Brain\Monkey\Functions\when('rest_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-json/' . $path
        );
    }

    /** @return list<string> */
    private function webhookCalls(): array
    {
        return array_values(array_filter(
            array_map(static fn (array $call): string => $call['method'], $this->telegramCalls),
            static fn (string $method): bool => str_ends_with($method, 'Webhook')
        ));
    }

    public function testARateLimitedSetWebhookIsTriedAgain(): void
    {
        $this->readyToInstall();
        // Telegram's answer to a setWebhook sent right after deleteWebhook.
        $this->telegramRateLimits['setWebhook'] = 1;

        $result = $this->page()->installWebhook();

        self::assertTrue($result['ok']);
        self::assertSame(['deleteWebhook', 'setWebhook', 'setWebhook'], $this->webhookCalls());
    }

    public function testInstallOnUpdateStampsTheVersionWhenTheInstallWorks(): void
    {
        $this->readyToInstall();

        $this->page()->installOnUpdate();

        self::assertSame(EVENTCREW_VERSION, $this->options[self::WEBHOOK_VERSION_OPTION] ?? null);
    }

    public function testInstallOnUpdateNeverDeletesTheWorkingWebhook(): void
    {
        $this->readyToInstall();
        // Refused every time: the update must not leave the bot without a webhook.
        $this->telegramErrors['setWebhook'] = 'Too Many Requests: retry after 1';

        $this->page()->installOnUpdate();

        self::assertNotContains('deleteWebhook', $this->webhookCalls());
    }

    public function testTheButtonStillDeletesFirstToClearTheStoredError(): void
    {
        $this->readyToInstall();

        $this->page()->installWebhook();

        self::assertSame(['deleteWebhook', 'setWebhook'], $this->webhookCalls());
    }

    public function testAFailedInstallIsNotStampedAndIsTriedAgainLater(): void
    {
        $this->readyToInstall();
        $this->telegramErrors['setWebhook'] = 'Bad Request: bad webhook';

        $this->page()->installOnUpdate();

        // Not stamped, so a later run tries again. Never deleted first, so the
        // old webhook still works while the new one is refused.
        self::assertArrayNotHasKey(self::WEBHOOK_VERSION_OPTION, $this->options);
        self::assertSame(['setWebhook'], $this->webhookCalls());

        // But not on the very next admin page load.
        $this->telegramCalls = [];
        $this->page()->installOnUpdate();
        self::assertSame([], $this->telegramCalls);

        // Once the gap has passed, it is tried again and, working now, stamped.
        $this->options['eventcrew_webhook_failed_at'] = time() - 11 * 60;
        unset($this->telegramErrors['setWebhook']);
        $this->page()->installOnUpdate();
        self::assertSame(['setWebhook'], $this->webhookCalls());
        self::assertSame(EVENTCREW_VERSION, $this->options[self::WEBHOOK_VERSION_OPTION] ?? null);
    }

    public function testInstallOnUpdateSkipsAndDoesNotStampWhenUnconfigured(): void
    {
        // Version behind (the option is unset), but no bot token is configured.
        unset($this->options[TelegramClient::TOKEN_OPTION]);

        $this->page()->installOnUpdate();

        self::assertSame([], $this->telegramCalls);
        // Left unstamped, so configuring a token later still triggers the install.
        self::assertArrayNotHasKey(self::WEBHOOK_VERSION_OPTION, $this->options);
    }

    /**
     * The main settings form, run until it has written the Telegram sign-in
     * switch; the rest of the form is not this test's business.
     *
     * @param array<string, mixed> $post
     */
    private function saveTelegramSection(array $post): void
    {
        \Brain\Monkey\Functions\when('current_user_can')->justReturn(true);
        \Brain\Monkey\Functions\when('check_admin_referer')->justReturn(1);
        \Brain\Monkey\Functions\when('wp_unslash')->returnArg(1);
        \Brain\Monkey\Functions\when('sanitize_text_field')->returnArg(1);
        \Brain\Monkey\Functions\when('esc_url_raw')->returnArg(1);
        \Brain\Monkey\Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            if (TelegramLogin::ENABLED_OPTION === $name) {
                throw new \RuntimeException('saved');
            }

            return true;
        });

        $_POST = $post;

        try {
            $this->page()->save();
            self::fail('The Telegram sign-in switch was never saved.');
        } catch (\RuntimeException $e) {
            self::assertSame('saved', $e->getMessage());
        }
    }

    public function testTheTelegramSignInSwitchSaves(): void
    {
        $this->saveTelegramSection(['telegram_web_login' => '1']);
        self::assertTrue($this->options[TelegramLogin::ENABLED_OPTION]);

        $this->saveTelegramSection([]);
        self::assertFalse($this->options[TelegramLogin::ENABLED_OPTION]);
    }

    public function testTheBoardCardsSwitchSavesAndIsOffByDefault(): void
    {
        self::assertFalse(\EventCrew\Telegram\BoardService::cardsOn());

        $this->saveTelegramSection(['telegram_board_cards' => '1']);
        self::assertTrue($this->options[\EventCrew\Telegram\BoardService::CARDS_OPTION]);

        $this->saveTelegramSection([]);
        self::assertFalse($this->options[\EventCrew\Telegram\BoardService::CARDS_OPTION]);
    }

    public function testTheSignInRowIsTheSwitchAndABotSignInHintWithoutTheToken(): void
    {
        $token = '123456789:AAsecret-part-of-the-token';
        $this->options[TelegramClient::TOKEN_OPTION] = $token;
        $this->options[TelegramLogin::ENABLED_OPTION] = true;

        $this->options[\EventCrew\Telegram\BoardService::USERNAME_OPTION] = 'crewbot';

        $view = SettingsPage::webLoginView();
        self::assertSame(['enabled' => true, 'needs_username' => false], $view);

        $html = $this->renderSignInRow($view);

        self::assertStringContainsString('name="telegram_web_login"', $html);
        self::assertStringContainsString("checked='checked'", $html);
        self::assertStringContainsString('the bot sends a button that signs the person in', $html);
        self::assertStringNotContainsString('BotFather', $html);
        self::assertStringNotContainsString('<code>', $html);
        self::assertStringNotContainsString('AAsecret', $html);
        self::assertStringNotContainsString($token, $html);
        self::assertStringNotContainsString('stays hidden', $html);
    }

    /**
     * The switch is on but the bot username is empty: the button stays
     * hidden, and one line says so and how the username gets filled in.
     */
    public function testTheSignInRowSaysTheButtonIsHiddenWhileTheBotUsernameIsEmpty(): void
    {
        $this->options[TelegramLogin::ENABLED_OPTION] = true;
        $this->options[\EventCrew\Telegram\BoardService::USERNAME_OPTION] = '';

        $view = SettingsPage::webLoginView();
        self::assertSame(['enabled' => true, 'needs_username' => true], $view);
        self::assertStringContainsString(
            'The button stays hidden until the bot username is filled in. Installing the webhook fills it in.',
            $this->renderSignInRow($view)
        );

        // With the switch off there is no button to hide, so no line.
        $this->options[TelegramLogin::ENABLED_OPTION] = false;

        $view = SettingsPage::webLoginView();
        self::assertSame(['enabled' => false, 'needs_username' => false], $view);
        self::assertStringNotContainsString('stays hidden', $this->renderSignInRow($view));
    }

    /**
     * @param array{enabled: bool, needs_username: bool} $view
     */
    private function renderSignInRow(array $view): string
    {
        \Brain\Monkey\Functions\when('checked')->alias(static function (mixed $value): void {
            echo $value ? ' checked=\'checked\'' : '';
        });
        \Brain\Monkey\Functions\when('esc_html')->alias(
            static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES)
        );
        \Brain\Monkey\Functions\when('esc_html_e')->alias(static function (string $text): void {
            echo esc_html($text);
        });

        ob_start();

        try {
            (new View())->render('settings-telegram-login', ['telegram' => ['web_login' => $view]]);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }
}

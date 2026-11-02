<?php

declare(strict_types=1);

namespace EventCrew\Tests\Admin;

use EventCrew\Admin\SettingsPage;
use EventCrew\Admin\View;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
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

    public function testInstallOnUpdateSkipsAndDoesNotStampWhenUnconfigured(): void
    {
        // Version behind (the option is unset), but no bot token is configured.
        unset($this->options[TelegramClient::TOKEN_OPTION]);

        $this->page()->installOnUpdate();

        self::assertSame([], $this->telegramCalls);
        // Left unstamped, so configuring a token later still triggers the install.
        self::assertArrayNotHasKey(self::WEBHOOK_VERSION_OPTION, $this->options);
    }
}

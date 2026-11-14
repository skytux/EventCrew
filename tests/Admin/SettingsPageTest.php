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
    private function save(array $post, string $handler = 'saveImportMailbox'): string
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
            $this->page()->{$handler}();
            self::fail('Expected the redirect.');
        } catch (\RuntimeException $e) {
            self::assertSame('redirected', $e->getMessage());
        }

        return $notice;
    }

    public function testPostTheBoardQueuesARepostAndTheJobPostsIt(): void
    {
        $this->options[\EventCrew\Telegram\BoardService::BOARD_OPTION] = ['chat_id' => -100, 'message_id' => 5];

        $notice = $this->save([], 'postBoard');

        // The button only queues the job; Telegram is not called yet.
        self::assertSame('The board is being posted in the group. This page shows when it is done.', $notice);
        self::assertNotContains('sendMessage', $this->calledMethods());
        $job = \EventCrew\Support\BackgroundJobs::latest(SettingsPage::BOARD_TARGET);
        self::assertSame(SettingsPage::BOARD_JOB, $job['kind'] ?? null);

        // The job posts the board in the group.
        $this->telegramResults['sendMessage'] = ['message_id' => 6];
        $this->wpdb->nextResults[] = []; // render: no upcoming tasks
        self::assertSame(['ok' => true, 'error' => ''], $this->page()->boardJob([]));
        self::assertContains('sendMessage', $this->calledMethods());
        self::assertSame(6, $this->options[\EventCrew\Telegram\BoardService::BOARD_OPTION]['message_id']);
    }

    public function testPostTheBoardUsesTheStoredGroupWhenTheBoardOptionIsGone(): void
    {
        $this->options[\EventCrew\Telegram\GroupChat::OPTION] = -100;
        $this->telegramResults['sendMessage'] = ['message_id' => 6];
        $this->wpdb->nextResults[] = []; // render: no upcoming tasks

        self::assertSame(['ok' => true, 'error' => ''], $this->page()->boardJob([]));
        self::assertSame(-100, $this->telegramCalls[0]['body']['chat_id']);
    }

    public function testAFailedBoardJobSaysSo(): void
    {
        $this->options[\EventCrew\Telegram\GroupChat::OPTION] = -100;
        $this->telegramErrors['sendMessage'] = 'Forbidden: bot was kicked from the supergroup chat';
        $this->wpdb->nextResults[] = []; // render: no upcoming tasks

        $result = $this->page()->boardJob([]);

        self::assertFalse($result['ok']);
        self::assertStringStartsWith('The board could not be posted in the group.', $result['error']);
    }

    public function testPostTheBoardWithoutAGroupSaysHowToFixIt(): void
    {
        $notice = $this->save([], 'postBoard');

        self::assertSame(SettingsPage::noGroupText(), $notice);
        self::assertStringContainsString('add it again', $notice);
        self::assertNull(\EventCrew\Support\BackgroundJobs::latest(SettingsPage::BOARD_TARGET));
        self::assertNotContains('sendMessage', $this->calledMethods());
    }

    public function testTheImportMailboxIsSaved(): void
    {
        $this->save(['import_mailbox' => ' lists@example.org ']);

        self::assertSame('lists@example.org', $this->options[\EventCrew\MailImport\MailedListImport::MAILBOX_OPTION]);
        self::assertSame('lists@example.org', \EventCrew\MailImport\MailedListImport::mailbox());
    }

    public function testAnEmptyImportMailboxRemovesIt(): void
    {
        $this->options[\EventCrew\MailImport\MailedListImport::MAILBOX_OPTION] = 'lists@example.org';

        $this->save(['import_mailbox' => '']);

        self::assertArrayNotHasKey(\EventCrew\MailImport\MailedListImport::MAILBOX_OPTION, $this->options);
        self::assertSame('', \EventCrew\MailImport\MailedListImport::mailbox());
    }

    public function testANonAddressLeavesTheImportMailboxAsItWas(): void
    {
        $this->options[\EventCrew\MailImport\MailedListImport::MAILBOX_OPTION] = 'lists@example.org';

        $notice = $this->save(['import_mailbox' => 'not an address']);

        self::assertSame('lists@example.org', $this->options[\EventCrew\MailImport\MailedListImport::MAILBOX_OPTION]);
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
            $this->options[\EventCrew\MailImport\MailedListImport::SENDERS_OPTION]
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
        $this->options[\EventCrew\MailImport\MailedListImport::SENDERS_OPTION] = ['shop@example.com'];

        $this->save(['import_mailbox' => 'lists@example.org']);

        self::assertSame(['shop@example.com'], $this->options[\EventCrew\MailImport\MailedListImport::SENDERS_OPTION]);
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
            $this->commandMenu(),
            null,
            function (int $seconds): void {
                $this->slept[] = $seconds;
            }
        );
    }

    /** @var array<int, int> The waits between webhook tries, instead of sleeping. */
    private array $slept = [];

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
        $this->telegramResults['getMe'] = ['username' => 'crewbot', 'first_name' => 'Crew Bot'];

        $this->page()->installWebhook();

        self::assertSame('crewbot', $saved[\EventCrew\Telegram\BoardService::USERNAME_OPTION] ?? null);
        self::assertSame('Crew Bot', $saved[\EventCrew\Telegram\BoardService::NAME_OPTION] ?? null);
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
        // Waited as long as Telegram asked before trying again.
        self::assertSame([1], $this->slept);
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

    /**
     * The main settings form, run until it has written $stopOption.
     *
     * @param array<string, mixed> $post
     */
    private function saveUntil(array $post, string $stopOption): void
    {
        \Brain\Monkey\Functions\when('current_user_can')->justReturn(true);
        \Brain\Monkey\Functions\when('check_admin_referer')->justReturn(1);
        \Brain\Monkey\Functions\when('wp_unslash')->returnArg(1);
        \Brain\Monkey\Functions\when('sanitize_text_field')->returnArg(1);
        \Brain\Monkey\Functions\when('sanitize_textarea_field')->returnArg(1);
        \Brain\Monkey\Functions\when('sanitize_key')->returnArg(1);
        \Brain\Monkey\Functions\when('esc_url_raw')->returnArg(1);
        \Brain\Monkey\Functions\when('rest_url')->alias(static fn (string $path = ''): string => 'https://site.test/wp-json/' . $path);
        \Brain\Monkey\Functions\when('admin_url')->alias(static fn (string $path = ''): string => 'https://site.test/wp-admin/' . $path);
        \Brain\Monkey\Functions\when('add_query_arg')->justReturn('https://site.test/wp-admin/admin-ajax.php');
        \Brain\Monkey\Functions\when('delete_option')->alias(function (string $key): bool {
            unset($this->options[$key]);
            return true;
        });
        \Brain\Monkey\Functions\when('update_option')->alias(function (string $name, mixed $value) use ($stopOption): bool {
            $this->options[$name] = $value;

            if ($stopOption === $name) {
                throw new \RuntimeException('saved');
            }

            return true;
        });

        $_POST = $post;

        try {
            $this->page()->save();
            self::fail($stopOption . ' was never saved.');
        } catch (\RuntimeException $e) {
            self::assertSame('saved', $e->getMessage());
        }
    }

    /**
     * Q333: a new role's name and description are not translated while the
     * page waits. The role is stored with its source, and the translation is
     * a background job for its Translations page row.
     */
    public function testAMovedWebhookAddressIsReinstalledInTheBackground(): void
    {
        $this->readyToInstall();

        // Switching to the fallback address moves the door; the save stops at
        // the queue write and has not called Telegram.
        $this->saveUntil(
            ['telegram_use_fallback' => '1'],
            \EventCrew\Support\BackgroundJobs::OPTION
        );

        self::assertSame([], $this->webhookCalls());
        $job = \EventCrew\Support\BackgroundJobs::latest(SettingsPage::WEBHOOK_TARGET);
        self::assertSame(SettingsPage::WEBHOOK_JOB, $job['kind'] ?? null);

        // The job installs the webhook.
        $this->telegramResults['getMe'] = ['username' => 'crewbot'];
        self::assertSame(['ok' => true, 'error' => ''], $this->page()->webhookJob([]));
        self::assertSame(['deleteWebhook', 'setWebhook'], $this->webhookCalls());
    }

    public function testAFailedWebhookJobSaysWhy(): void
    {
        $this->options[\EventCrew\Telegram\WebhookController::SECRET_OPTION] = 'secret';
        \Brain\Monkey\Functions\when('rest_url')->alias(
            static fn (string $path = ''): string => 'http://site.test/wp-json/' . $path
        );

        $result = $this->page()->webhookJob([]);

        self::assertFalse($result['ok']);
        self::assertStringStartsWith('The webhook could not be re-installed: WordPress generated', $result['error']);
        self::assertSame([], $this->webhookCalls());
    }

    public function testANewRoleIsTranslatedInTheBackground(): void
    {
        $this->options[\EventCrew\Support\AssistantSettings::URL_OPTION] = 'https://llm.example.test/v1';
        $this->options[\EventCrew\Support\AssistantSettings::MODEL_OPTION] = 'test-model';
        $this->options[\EventCrew\Support\Roles::OPTION_NAME] = [
            ['slug' => 'decorate', 'label' => 'Decorate', 'description' => '', 'capacity' => 2],
        ];
        $asked = 0;
        \Brain\Monkey\Functions\when('wp_remote_post')->alias(static function () use (&$asked): array {
            $asked++;

            return [];
        });

        $this->saveUntil([
            'role_slug' => ['decorate', '', ''],
            'role_label' => [
                ['source' => 'Decorate'],
                // saveUntil() leaves sanitize_key() as it is, so the name is
                // written as its own slug here.
                ['source' => 'soundcheck'],
                // A name that gives the slug of a role already there is
                // dropped on save, so it gets no job of its own.
                ['source' => 'decorate'],
            ],
            'role_description' => [['source' => ''], ['source' => 'Test the speakers.'], ['source' => '']],
        ], \EventCrew\Support\Roles::GUIDE_URL_OPTION);

        self::assertSame(0, $asked, 'the model is not asked while the page waits');

        $role = \EventCrew\Support\Roles::find('soundcheck');
        self::assertNotNull($role);
        self::assertSame('soundcheck', $role['label_text']['source']);
        self::assertSame('', $role['label_text']['texts']['fi'] ?? '');

        $label = \EventCrew\Support\BackgroundJobs::latest(
            \EventCrew\Support\TranslatedText::jobTarget('role.soundcheck.label')
        );
        $description = \EventCrew\Support\BackgroundJobs::latest(
            \EventCrew\Support\TranslatedText::jobTarget('role.soundcheck.description')
        );
        self::assertSame(\EventCrew\Support\BackgroundJobs::QUEUED, $label['status'] ?? null);
        self::assertSame('soundcheck', $label['args']['source'] ?? null);
        self::assertSame(\EventCrew\Support\BackgroundJobs::QUEUED, $description['status'] ?? null);
        // The role saved before (no translation yet) is queued as before;
        // the dropped name gets nothing.
        self::assertSame(
            ['row:role.decorate.label', 'row:role.soundcheck.label', 'row:role.soundcheck.description'],
            array_column(\EventCrew\Support\BackgroundJobs::withPrefix('row:role.'), 'target')
        );
    }

    /** The Members display tick (Owner 2026-11-08) saves per row, off unless ticked. */
    public function testTheMembersDisplayTickSavesPerRole(): void
    {
        $this->options[\EventCrew\Support\Roles::OPTION_NAME] = [
            ['slug' => 'decorate', 'label' => 'Decorate', 'capacity' => 2],
            ['slug' => 'clean', 'label' => 'Clean', 'capacity' => 2],
        ];

        $this->saveUntil([
            'role_slug' => ['decorate', 'clean'],
            'role_label' => [['source' => 'Decorate'], ['source' => 'Clean']],
            'role_members_display' => [1 => '1'],
        ], \EventCrew\Support\Roles::GUIDE_URL_OPTION);

        self::assertFalse(\EventCrew\Support\Roles::membersDisplay('decorate'));
        self::assertTrue(\EventCrew\Support\Roles::membersDisplay('clean'));
    }

    /**
     * The Admin access tick (Owner 2026-11-08, Q371) saves per row; Organize
     * is always for the people holding it only.
     */
    public function testTheAdminAccessTickSavesPerRole(): void
    {
        $this->options[\EventCrew\Support\Roles::OPTION_NAME] = [
            ['slug' => 'decorate', 'label' => 'Decorate', 'capacity' => 2],
            ['slug' => 'organize', 'label' => 'Organize', 'capacity' => 1, 'admin_access' => true],
        ];

        $this->saveUntil([
            'role_slug' => ['decorate', 'organize'],
            'role_label' => [['source' => 'Decorate'], ['source' => 'Organize']],
            'role_admin_access' => [0 => '1', 1 => '1'],
        ], \EventCrew\Support\Roles::GUIDE_URL_OPTION);

        self::assertSame(['decorate', 'organize'], \EventCrew\Support\Roles::adminSlugs());
        self::assertTrue(\EventCrew\Support\Roles::isRestricted('organize'));
    }

    /**
     * Unticking Admin access on the only role the organizers hold would
     * leave none: the roles are not saved.
     */
    public function testTheRolesAreNotSavedWhenTheyWouldLeaveNoOrganizer(): void
    {
        $before = [
            ['slug' => 'decorate', 'label' => 'Decorate', 'capacity' => 2],
            ['slug' => 'organize', 'label' => 'Organize', 'capacity' => 1, 'admin_access' => true],
        ];
        $this->options[\EventCrew\Support\Roles::OPTION_NAME] = $before;
        $this->wpdb->nextResults[] = [['id' => 1, 'role_slugs' => 'organize', 'is_organizer' => 1, 'anonymized_at' => null]];

        $this->saveUntil([
            'role_slug' => ['decorate', 'organize'],
            'role_label' => [['source' => 'Decorate'], ['source' => 'Organize']],
        ], \EventCrew\Support\Roles::GUIDE_URL_OPTION);

        self::assertSame($before, $this->options[\EventCrew\Support\Roles::OPTION_NAME]);
        self::assertSame(['organize'], \EventCrew\Support\Roles::adminSlugs());
    }

    public function testASecretIsShownOnlyByItsLastFourCharacters(): void
    {
        self::assertSame('', \EventCrew\Support\SecretMask::mask(''));
        self::assertSame('••••', \EventCrew\Support\SecretMask::mask('short'));
        self::assertSame('••••wxyz', \EventCrew\Support\SecretMask::mask('123456789:AAsecretwxyz'));
    }

    public function testAnEmptyBotTokenFieldKeepsTheSavedToken(): void
    {
        $this->options[TelegramClient::TOKEN_OPTION] = '123456789:AAold';

        $this->saveTelegramSection(['telegram_bot_token' => '']);
        self::assertSame('123456789:AAold', $this->options[TelegramClient::TOKEN_OPTION]);

        $this->saveTelegramSection(['telegram_bot_token' => '987654321:AAnew']);
        self::assertSame('987654321:AAnew', $this->options[TelegramClient::TOKEN_OPTION]);

        $this->saveTelegramSection(['telegram_bot_token' => '', 'telegram_bot_token_remove' => '1']);
        self::assertSame('', $this->options[TelegramClient::TOKEN_OPTION]);
    }

    public function testAnEmptyTurnstileSecretFieldKeepsTheSavedSecret(): void
    {
        $secret = \EventCrew\Support\Turnstile::SECRET_KEY_OPTION;
        $stop = \EventCrew\Support\CronFallbackTrigger::OPTION;
        $this->options[$secret] = '0x4AAAold';

        $this->saveUntil(['turnstile_secret' => ''], $stop);
        self::assertSame('0x4AAAold', $this->options[$secret]);

        $this->saveUntil(['turnstile_secret' => '0x4AAAnew'], $stop);
        self::assertSame('0x4AAAnew', $this->options[$secret]);

        $this->saveUntil(['turnstile_secret' => '', 'turnstile_secret_remove' => '1'], $stop);
        self::assertSame('', $this->options[$secret]);
    }

    /**
     * The URL copy of the webhook secret is off unless it was saved on. A
     * value saved earlier (live saved '0'; another site may have saved '1')
     * stays as it is.
     */
    public function testTheWebhookSecretIsNotInTheUrlByDefault(): void
    {
        $this->options[\EventCrew\Telegram\WebhookController::USE_FALLBACK_OPTION] = true;
        unset($this->options[\EventCrew\Telegram\WebhookController::SECRET_IN_URL_OPTION]);
        self::assertFalse(\EventCrew\Telegram\WebhookController::secretTravelsInUrl());

        $this->options[\EventCrew\Telegram\WebhookController::SECRET_IN_URL_OPTION] = '1';
        self::assertTrue(\EventCrew\Telegram\WebhookController::secretTravelsInUrl());

        $this->options[\EventCrew\Telegram\WebhookController::SECRET_IN_URL_OPTION] = '0';
        self::assertFalse(\EventCrew\Telegram\WebhookController::secretTravelsInUrl());
    }

    /** The welcome message's maintainer contact saves as typed (Owner 2026-11-07). */
    public function testTheMaintainerContactSaves(): void
    {
        $this->saveUntil(['maintainer_contact' => 'Kim, kim@example.com'], \EventCrew\Support\WelcomeSender::MAINTAINER_OPTION);

        self::assertSame('Kim, kim@example.com', $this->options[\EventCrew\Support\WelcomeSender::MAINTAINER_OPTION]);
    }

    public function testTheGivingTicketsAwaySwitchSavesAndIsOnByDefault(): void
    {
        self::assertTrue(\EventCrew\Support\TicketTransferService::isOn());

        $this->saveUntil([], \EventCrew\Support\TicketTransferService::OPTION);
        self::assertSame('0', $this->options[\EventCrew\Support\TicketTransferService::OPTION]);

        $this->saveUntil(['ticket_giving' => '1'], \EventCrew\Support\TicketTransferService::OPTION);
        self::assertSame('1', $this->options[\EventCrew\Support\TicketTransferService::OPTION]);

        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.php');
        self::assertStringContainsString('name="ticket_giving"', $template);
        self::assertStringContainsString('Members can give away their ticket', $template);
    }

    public function testTheExtraTaskCreditSwitchSavesAndIsOnByDefault(): void
    {
        self::assertTrue(\EventCrew\Support\ExtraTaskCredits::isOn());

        $this->saveUntil([], \EventCrew\Support\ExtraTaskCredits::OPTION);
        self::assertSame('0', $this->options[\EventCrew\Support\ExtraTaskCredits::OPTION]);

        $this->saveUntil(['extra_task_credit' => '1'], \EventCrew\Support\ExtraTaskCredits::OPTION);
        self::assertSame('1', $this->options[\EventCrew\Support\ExtraTaskCredits::OPTION]);

        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.php');
        self::assertStringContainsString('name="extra_task_credit"', $template);
        self::assertStringContainsString('Credit for extra tasks on the same night', $template);
    }

    public function testTheOrganizerMailAnyHourSwitchSavesAndIsOnByDefault(): void
    {
        $option = \EventCrew\Support\SendWindow::ORGANIZER_ANY_HOUR_OPTION;
        self::assertTrue(\EventCrew\Support\SendWindow::organizersAnyHour());

        $this->saveUntil([], $option);
        self::assertSame('0', $this->options[$option]);
        self::assertFalse(\EventCrew\Support\SendWindow::organizersAnyHour());

        $this->saveUntil(['organizer_mail_any_hour' => '1'], $option);
        self::assertSame('1', $this->options[$option]);

        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.php');
        self::assertStringContainsString('name="organizer_mail_any_hour"', $template);
        self::assertStringContainsString('Send emails to organizers at any hour', $template);
    }

    public function testTheSeveralTicketsSwitchSavesAndIsOnByDefault(): void
    {
        self::assertTrue(\EventCrew\Support\SeveralTickets::isOn());

        $this->saveUntil([], \EventCrew\Support\SeveralTickets::OPTION);
        self::assertSame('0', $this->options[\EventCrew\Support\SeveralTickets::OPTION]);
        self::assertFalse(\EventCrew\Support\SeveralTickets::isOn());

        $this->saveUntil(['several_tickets' => '1'], \EventCrew\Support\SeveralTickets::OPTION);
        self::assertSame('1', $this->options[\EventCrew\Support\SeveralTickets::OPTION]);

        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.php');
        self::assertStringContainsString('name="several_tickets"', $template);
        self::assertStringContainsString('Allow several free-entry tickets per night', $template);
    }

    public function testTheTicketClockSwitchSavesAndIsOffByDefault(): void
    {
        self::assertFalse(\EventCrew\Support\TicketClock::isOn());

        $this->saveUntil(['ticket_clock' => '1'], \EventCrew\Support\TicketClock::OPTION);
        self::assertSame('1', $this->options[\EventCrew\Support\TicketClock::OPTION]);
        self::assertTrue(\EventCrew\Support\TicketClock::isOn());

        $this->saveUntil([], \EventCrew\Support\TicketClock::OPTION);
        self::assertSame('0', $this->options[\EventCrew\Support\TicketClock::OPTION]);

        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.php');
        self::assertStringContainsString('name="ticket_clock"', $template);
        self::assertStringContainsString('Show a ticking clock on the ticket', $template);
    }

    public function testTheTelegramSignInSwitchSaves(): void
    {
        $this->saveTelegramSection(['telegram_web_login' => '1']);
        self::assertTrue($this->options[TelegramLogin::ENABLED_OPTION]);

        $this->saveTelegramSection([]);
        self::assertFalse($this->options[TelegramLogin::ENABLED_OPTION]);
    }

    /**
     * Q301: the AI translation switch lives only on the Translations page.
     * The Settings form has no field for it, and saving Settings, even with
     * the old form's fields posted, leaves the switch as it was.
     */
    public function testSavingSettingsNeverTouchesTheTranslationSwitch(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.php');
        self::assertStringNotContainsString('translate_auto', $template);

        $this->options[\EventCrew\Support\TranslatedText::AUTO_OPTION] = '1';
        $this->saveTelegramSection(['translate_auto_present' => '1']);
        self::assertSame('1', $this->options[\EventCrew\Support\TranslatedText::AUTO_OPTION]);

        $this->options[\EventCrew\Support\TranslatedText::AUTO_OPTION] = '0';
        $this->saveTelegramSection(['translate_auto_present' => '1', 'translate_auto' => '1']);
        self::assertSame('0', $this->options[\EventCrew\Support\TranslatedText::AUTO_OPTION]);
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

    private const WEBHOOK_SECRET = 'whsecret-0123456789abcdefWXYZ';

    /**
     * Runs the "Test the webhook" button. $response is what wp_remote_post
     * answers. Returns the notice it set and every wp_remote_post call.
     *
     * @return array{
     *     notice: array{type: string, message: string}|null,
     *     requests: list<array{url: string, args: array<string, mixed>}>
     * }
     */
    private function testWebhook(mixed $response, bool $isError = false): array
    {
        $notice = null;
        $requests = [];
        \Brain\Monkey\Functions\when('current_user_can')->justReturn(true);
        \Brain\Monkey\Functions\when('check_admin_referer')->justReturn(1);
        \Brain\Monkey\Functions\when('get_current_user_id')->justReturn(3);
        \Brain\Monkey\Functions\when('set_transient')->alias(
            static function (string $key, $value) use (&$notice): bool {
                $notice = $value;
                return true;
            }
        );
        \Brain\Monkey\Functions\when('rest_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-json/' . $path
        );
        \Brain\Monkey\Functions\when('admin_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-admin/' . $path
        );
        \Brain\Monkey\Functions\when('add_query_arg')->justReturn('https://site.test/wp-admin/admin.php');
        \Brain\Monkey\Functions\when('wp_safe_redirect')->alias(static function (): void {
            throw new \RuntimeException('redirected');
        });
        \Brain\Monkey\Functions\when('wp_remote_post')->alias(
            static function (string $url, array $args) use (&$requests, $response): mixed {
                $requests[] = ['url' => $url, 'args' => $args];
                return $response;
            }
        );
        \Brain\Monkey\Functions\when('is_wp_error')->justReturn($isError);
        \Brain\Monkey\Functions\when('wp_remote_retrieve_response_code')->alias(
            static fn (mixed $r): int => (int) ($r['response']['code'] ?? 0)
        );

        try {
            $this->page()->testWebhook();
            self::fail('Expected the redirect.');
        } catch (\RuntimeException $e) {
            self::assertSame('redirected', $e->getMessage());
        }

        return ['notice' => $notice, 'requests' => $requests];
    }

    public function testTheWebhookTestSendsAnEmptyUpdateWithTheRealSecretAndShowsTheStatus(): void
    {
        $this->options[\EventCrew\Telegram\WebhookController::SECRET_OPTION] = self::WEBHOOK_SECRET;

        $result = $this->testWebhook(['response' => ['code' => 200], 'body' => '']);

        self::assertCount(1, $result['requests']);
        $request = $result['requests'][0];
        self::assertSame('https://site.test/wp-json/eventcrew/v1/telegram/webhook', $request['url']);
        self::assertSame(self::WEBHOOK_SECRET, $request['args']['headers']['X-Telegram-Bot-Api-Secret-Token']);
        self::assertSame('{}', $request['args']['body']);
        self::assertSame(0, $request['args']['redirection']);

        self::assertSame('success', $result['notice']['type']);
        self::assertStringContainsString('HTTP 200', $result['notice']['message']);
        self::assertStringNotContainsString(self::WEBHOOK_SECRET, $result['notice']['message']);
    }

    public function testTheByHandExampleSendsTheSameEmptyUpdateAsTheButton(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.php');

        // A /start body made the bot answer; the example now shows the button's empty update.
        self::assertStringContainsString('esc_html(\EventCrew\Telegram\WebhookController::TEST_BODY)', $template);
        self::assertStringNotContainsString('"text":"/start"', $template);
    }

    public function testAFailedWebhookTestShowsTheStatusButNeverTheBody(): void
    {
        $this->options[\EventCrew\Telegram\WebhookController::SECRET_OPTION] = self::WEBHOOK_SECRET;

        $result = $this->testWebhook(['response' => ['code' => 403], 'body' => 'echo: ' . self::WEBHOOK_SECRET]);

        self::assertSame('error', $result['notice']['type']);
        self::assertStringContainsString('HTTP 403', $result['notice']['message']);
        self::assertStringContainsString('did not accept the secret', $result['notice']['message']);
        self::assertStringNotContainsString(self::WEBHOOK_SECRET, $result['notice']['message']);
        self::assertStringNotContainsString('echo', $result['notice']['message']);
    }

    public function testAWebhookTestThatCouldNotBeSentMasksTheSecretInTheReason(): void
    {
        $this->options[\EventCrew\Telegram\WebhookController::SECRET_OPTION] = self::WEBHOOK_SECRET;
        $error = new class (self::WEBHOOK_SECRET) {
            public function __construct(private readonly string $secret)
            {
            }

            public function get_error_message(): string // phpcs:ignore PSR1.Methods.CamelCapsMethodName
            {
                return 'cURL error 28: timed out on ?token=' . $this->secret;
            }
        };

        $result = $this->testWebhook($error, true);

        self::assertSame('error', $result['notice']['type']);
        self::assertStringContainsString('could not be sent', $result['notice']['message']);
        self::assertStringContainsString('••••WXYZ', $result['notice']['message']);
        self::assertStringNotContainsString(self::WEBHOOK_SECRET, $result['notice']['message']);
    }

    public function testTheWebhookTestNeedsAStoredSecret(): void
    {
        $result = $this->testWebhook(['response' => ['code' => 200]]);

        self::assertSame([], $result['requests']);
        self::assertSame('error', $result['notice']['type']);
        self::assertSame('Install the webhook first, then test it.', $result['notice']['message']);
    }

    public function testTheWebhookTestChecksTheNonceAndTheCapabilityBeforeSending(): void
    {
        $this->options[\EventCrew\Telegram\WebhookController::SECRET_OPTION] = self::WEBHOOK_SECRET;
        $nonceAction = null;
        $sent = 0;
        \Brain\Monkey\Functions\when('wp_remote_post')->alias(static function () use (&$sent): array {
            $sent++;
            return [];
        });
        \Brain\Monkey\Functions\when('wp_die')->alias(static function (): void {
            throw new \RuntimeException('died');
        });

        // Not an administrator.
        \Brain\Monkey\Functions\when('current_user_can')->justReturn(false);
        \Brain\Monkey\Functions\when('check_admin_referer')->justReturn(1);

        try {
            $this->page()->testWebhook();
            self::fail('Expected wp_die.');
        } catch (\RuntimeException $e) {
            self::assertSame('died', $e->getMessage());
        }

        // An administrator without a valid nonce (check_admin_referer dies).
        \Brain\Monkey\Functions\when('current_user_can')->justReturn(true);
        \Brain\Monkey\Functions\when('check_admin_referer')->alias(
            static function (string $action) use (&$nonceAction): void {
                $nonceAction = $action;
                throw new \RuntimeException('bad nonce');
            }
        );

        try {
            $this->page()->testWebhook();
            self::fail('Expected the nonce check to stop it.');
        } catch (\RuntimeException $e) {
            self::assertSame('bad nonce', $e->getMessage());
        }

        self::assertSame('eventcrew_telegram_setup', $nonceAction);
        self::assertSame(0, $sent, 'nothing was sent');
    }

    /**
     * The whole Settings page renders, and the Telegram part comes out with
     * its key elements. Any PHP error, warning or notice while rendering
     * fails the test instead of reaching wp-admin. Without a bot token: the
     * token field is empty and the webhook part says to add a token first.
     */
    /** Q342: next to the post-event email and announcement switches, a link to each of their Messages entries. */
    public function testTheEmailSwitchesLinkToTheirMessages(): void
    {
        $html = $this->renderSettingsPage();

        foreach (['followup_came', 'followup_missed', 'newsletter'] as $id) {
            self::assertStringContainsString('kind=' . $id . '#ec-message-' . $id, $html);
        }
    }

    /** The Crew app install numbers at the top of the Mobile app section (Owner 2026-11-13). */
    public function testTheMobileAppSectionStartsWithTheCrewAppNumbers(): void
    {
        $html = $this->renderSettingsPage();
        $box = (string) strstr((string) strstr($html, 'class="eventcrew-app-stats"'), '</div>', true);

        self::assertGreaterThan(
            (int) strpos($html, '>Mobile app</h2>'),
            (int) strpos($html, 'class="eventcrew-app-stats"')
        );

        foreach (['Tried to install', 'Installed', 'Success rate', 'This month', 'Last month'] as $label) {
            self::assertStringContainsString($label, $box);
        }

        // Nobody tried yet: no rate; no devices.
        self::assertStringContainsString('>–</td>', $box);
        self::assertMatchesRegularExpression('#>\s*0 / 0\s*</td>#', $box);
    }

    public function testTheTelegramPartRendersWithoutABotToken(): void
    {
        unset($this->options[TelegramClient::TOKEN_OPTION]);

        $telegram = $this->telegramPanels($this->renderSettingsPage());

        self::assertStringContainsString('name="telegram_bot_token"', $telegram);
        self::assertStringContainsString('placeholder=""', $telegram);
        self::assertStringNotContainsString('telegram_bot_token_remove', $telegram);
        self::assertStringContainsString('Add a bot token above and save before installing the webhook.', $telegram);
        // No webhook secret yet, so no webhook test.
        self::assertStringNotContainsString('eventcrew_test_webhook', $telegram);
        self::assertStringNotContainsString('Test the webhook', $telegram);
        self::assertSame([], $this->telegramCalls, 'no token, no Telegram call');
    }

    /**
     * With a bot token and an installed webhook: the token and the secret are
     * shown only by their last 4 characters, the "Test the webhook" button and
     * the by-hand example with its {} body are there, and so are the webhook
     * status and the install button.
     */
    public function testTheTelegramPartRendersWithABotTokenAndAWebhook(): void
    {
        $token = '123456789:AAsecretTokenWXYZ';
        $this->options[TelegramClient::TOKEN_OPTION] = $token;
        $this->options[\EventCrew\Telegram\WebhookController::SECRET_OPTION] = self::WEBHOOK_SECRET;
        $this->options[\EventCrew\Telegram\BoardService::USERNAME_OPTION] = 'crew_test_bot';
        $this->telegramResults['getWebhookInfo'] = [
            'url' => 'https://site.test/wp-json/eventcrew/v1/telegram/webhook',
            'pending_update_count' => 0,
        ];

        $html = $this->renderSettingsPage();
        $telegram = $this->telegramPanels($html);

        self::assertStringContainsString('name="telegram_bot_token"', $telegram);
        self::assertStringContainsString('placeholder="••••WXYZ"', $telegram);
        self::assertStringContainsString('telegram_bot_token_remove', $telegram);
        self::assertStringNotContainsString($token, $html, 'the token is never printed');
        self::assertStringNotContainsString(self::WEBHOOK_SECRET, $html, 'the secret is never printed');

        self::assertStringContainsString('value="eventcrew_test_webhook"', $telegram);
        self::assertStringContainsString('Test the webhook', $telegram);
        self::assertStringContainsString('Test the endpoint by hand', $telegram);
        self::assertStringContainsString('X-Telegram-Bot-Api-Secret-Token: ••••WXYZ', $telegram);
        self::assertStringContainsString('<code style="word-break:break-all">{}</code>', $telegram);

        self::assertStringContainsString('Installed', $telegram);
        self::assertStringContainsString('@crew_test_bot', $telegram);
        self::assertStringContainsString('value="eventcrew_telegram_setup"', $telegram);
        self::assertStringContainsString('Install / refresh webhook', $telegram);
        self::assertSame(['getWebhookInfo'], array_column($this->telegramCalls, 'method'));
    }

    /** Everything inside the page's Telegram tab panels, joined. */
    private function telegramPanels(string $html): string
    {
        $parts = explode('<div class="ec-tab-panel" data-ec-tab="telegram">', $html);
        self::assertGreaterThan(1, count($parts), 'the page has a Telegram tab panel');
        array_shift($parts);

        return implode("\n", array_map(
            static fn (string $part): string => preg_split('/<div class="ec-tab-panel" data-ec-tab="/', $part)[0],
            $parts
        ));
    }

    /**
     * Runs SettingsPage::render() with the WordPress functions it touches
     * stubbed, and returns the page. Every PHP error, warning or notice
     * raised while it renders is turned into an exception.
     */
    private function renderSettingsPage(): string
    {
        $esc = static fn (mixed $text): string => htmlspecialchars((string) $text, ENT_QUOTES);
        \Brain\Monkey\Functions\when('esc_html')->alias($esc);
        \Brain\Monkey\Functions\when('esc_attr')->alias($esc);
        \Brain\Monkey\Functions\when('esc_textarea')->alias($esc);
        \Brain\Monkey\Functions\when('esc_js')->alias($esc);
        \Brain\Monkey\Functions\when('esc_url')->returnArg(1);
        \Brain\Monkey\Functions\when('esc_html_e')->alias(static function (string $text) use ($esc): void {
            echo $esc(__($text));
        });
        \Brain\Monkey\Functions\when('esc_attr_e')->alias(static function (string $text) use ($esc): void {
            echo $esc(__($text));
        });
        \Brain\Monkey\Functions\when('checked')->alias(static function (mixed $a, mixed $b = true): void {
            echo (string) $a === (string) $b ? " checked='checked'" : '';
        });
        \Brain\Monkey\Functions\when('selected')->alias(static function (mixed $a, mixed $b = true): void {
            echo (string) $a === (string) $b ? " selected='selected'" : '';
        });
        \Brain\Monkey\Functions\when('disabled')->alias(static function (mixed $a, mixed $b = true): void {
            echo (string) $a === (string) $b ? " disabled='disabled'" : '';
        });
        \Brain\Monkey\Functions\when('wp_nonce_field')->alias(static function (string $action): void {
            echo '<input type="hidden" name="_wpnonce" value="' . md5($action) . '">';
        });
        \Brain\Monkey\Functions\when('submit_button')->alias(static function (string $text = 'Save') use ($esc): void {
            echo '<button type="submit" class="button">' . $esc($text) . '</button>';
        });
        \Brain\Monkey\Functions\when('admin_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-admin/' . $path
        );
        \Brain\Monkey\Functions\when('rest_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-json/' . $path
        );
        // Both forms: add_query_arg(['k' => 'v'], $url) and add_query_arg('k', 'v', $url).
        \Brain\Monkey\Functions\when('add_query_arg')->alias(static function (mixed ...$args): string {
            $query = is_array($args[0]) ? $args[0] : [(string) $args[0] => (string) ($args[1] ?? '')];
            $url = (string) (is_array($args[0]) ? ($args[1] ?? '') : ($args[2] ?? ''));

            return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        });
        \Brain\Monkey\Functions\when('wp_nonce_url')->alias(
            static fn (string $url): string => $url . '&_wpnonce=nonce'
        );
        \Brain\Monkey\Functions\when('post_type_exists')->justReturn(false);
        // wp_date and wp_enqueue_media stay undefined: code elsewhere asks
        // function_exists() for them, and a stub here would leak into later tests.
        \Brain\Monkey\Functions\when('get_pages')->justReturn([]);
        \Brain\Monkey\Functions\when('get_posts')->justReturn([]);
        \Brain\Monkey\Functions\when('wp_generate_password')->justReturn('importsecret0123456789');
        \Brain\Monkey\Functions\when('get_transient')->justReturn(false);
        \Brain\Monkey\Functions\when('delete_transient')->justReturn(true);
        \Brain\Monkey\Functions\when('human_time_diff')->justReturn('2 hours');
        \Brain\Monkey\Functions\when('wp_timezone_string')->justReturn('Europe/Helsinki');
        \Brain\Monkey\Functions\when('wp_dropdown_pages')->alias(static function (array $args): void {
            echo '<select name="' . $args['name'] . '"></select>';
        });
        \Brain\Monkey\Functions\when('wp_get_current_user')->justReturn(
            (object) ['ID' => 3, 'user_email' => 'organizer@example.com']
        );

        set_error_handler(static function (int $errno, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $errno, $file, $line);
        });
        ob_start();

        try {
            $this->page()->render();
        } finally {
            $html = (string) ob_get_clean();
            restore_error_handler();
        }

        return $html;
    }
}

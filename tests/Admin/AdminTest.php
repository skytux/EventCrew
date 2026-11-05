<?php

declare(strict_types=1);

namespace EventCrew\Tests\Admin;

use EventCrew\Admin\Admin;
use EventCrew\Admin\AiPromptsPage;
use EventCrew\Admin\MessagesPage;
use EventCrew\Admin\NewsletterPage;
use EventCrew\Admin\PeoplePage;
use EventCrew\Admin\RosterPage;
use EventCrew\Admin\SettingsPage;
use EventCrew\Admin\TasksPage;
use EventCrew\Admin\TranslationsPage;
use EventCrew\Core\Container;
use EventCrew\MailImport\MailImporter;
use EventCrew\Support\Scheduler;
use EventCrew\Tests\TestCase;

final class AdminTest extends TestCase
{
    public function testTheWebhookIsRefreshedOnAdminRequestsAndOnTheHourlyRun(): void
    {
        // boot() only fetches objects and registers callbacks on them, so a
        // plain object stands in for each page.
        $settings = self::anyPage();
        $container = new Container();
        $container->singleton(SettingsPage::class, static fn (): object => $settings);

        $pages = [
            PeoplePage::class,
            RosterPage::class,
            TasksPage::class,
            MailImporter::class,
            NewsletterPage::class,
            TranslationsPage::class,
            MessagesPage::class,
            AiPromptsPage::class,
        ];

        foreach ($pages as $id) {
            $container->singleton($id, static fn (): object => self::anyPage());
        }

        (new Admin($container))->boot();

        self::assertNotFalse(has_action('admin_init', [$settings, 'installOnUpdate']));
        self::assertNotFalse(has_action(Scheduler::HOOK, [$settings, 'installOnUpdate']));
    }

    /** S-B12: a notice shows to the administrator who saved, not to another one. */
    public function testANoticeIsKeptPerAdministrator(): void
    {
        $transients = [];
        $user = 3;
        \Brain\Monkey\Functions\when('get_current_user_id')->alias(static function () use (&$user): int {
            return $user;
        });
        \Brain\Monkey\Functions\when('set_transient')->alias(
            static function (string $key, mixed $value) use (&$transients): bool {
                $transients[$key] = $value;

                return true;
            }
        );
        \Brain\Monkey\Functions\when('get_transient')->alias(
            static function (string $key) use (&$transients): mixed {
                return $transients[$key] ?? false;
            }
        );
        \Brain\Monkey\Functions\when('delete_transient')->alias(
            static function (string $key) use (&$transients): bool {
                unset($transients[$key]);

                return true;
            }
        );
        \Brain\Monkey\Functions\when('esc_attr')->returnArg(1);
        \Brain\Monkey\Functions\when('esc_html')->returnArg(1);

        Admin::notice('success', 'Saved by three.');
        $admin = new Admin(new Container());

        $user = 4;
        ob_start();
        $admin->renderNotice();
        self::assertSame('', ob_get_clean(), 'Another administrator does not see it.');

        $user = 3;
        ob_start();
        $admin->renderNotice();
        self::assertStringContainsString('Saved by three.', (string) ob_get_clean());
        self::assertSame([], $transients, 'Shown once, then gone.');
    }

    /** An object on which every method name is callable, as hooks require. */
    private static function anyPage(): object
    {
        return new AnyPage();
    }
}

/** Stands in for an admin page: hooks need a named class with callable methods. */
final class AnyPage
{
    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): void
    {
    }
}

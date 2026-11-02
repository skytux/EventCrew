<?php

declare(strict_types=1);

namespace EventCrew\Tests\Admin;

use EventCrew\Admin\Admin;
use EventCrew\Admin\PeoplePage;
use EventCrew\Admin\RosterPage;
use EventCrew\Admin\SettingsPage;
use EventCrew\Admin\TasksPage;
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

        foreach ([PeoplePage::class, RosterPage::class, TasksPage::class, MailImporter::class] as $id) {
            $container->singleton($id, static fn (): object => self::anyPage());
        }

        (new Admin($container))->boot();

        self::assertNotFalse(has_action('admin_init', [$settings, 'installOnUpdate']));
        self::assertNotFalse(has_action(Scheduler::HOOK, [$settings, 'installOnUpdate']));
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

<?php

declare(strict_types=1);

namespace EventCrew\Core;

use EventCrew\Admin\Admin;
use EventCrew\Admin\SettingsPage;
use EventCrew\Admin\ShiftsPage;
use EventCrew\Admin\View;
use EventCrew\Admin\VolunteersPage;
use EventCrew\Database\Schema;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\ShiftRepository;
use EventCrew\Repositories\VolunteerRepository;
use EventCrew\Support\Logger;
use Throwable;

final class Kernel
{
    private Container $container;

    public function __construct()
    {
        $this->container = new Container();
    }

    public function boot(): void
    {
        Schema::maybeMigrate();

        $this->registerServices();
        $this->validateServicesWhenDebugging();

        $admin = $this->container->get(Admin::class);
        $admin->boot();

        do_action('eventcrew/boot', $this->container);
    }

    /**
     * A missed or misordered binding among manually-wired singletons would
     * otherwise only fail lazily, on whatever admin page happens to be the
     * first to touch it, in production. Eagerly resolving every registered
     * service right after wiring converts that into an immediate, logged
     * failure at plugins_loaded - but only under WP_DEBUG, since constructing
     * every service on a request that needs none of them is pure waste.
     */
    private function validateServicesWhenDebugging(): void
    {
        if (! defined('WP_DEBUG') || ! WP_DEBUG) {
            return;
        }

        foreach ($this->container->registeredIds() as $id) {
            try {
                $this->container->get($id);
            } catch (Throwable $exception) {
                $this->logBootFailure($id, $exception);
            }
        }
    }

    private function logBootFailure(string $id, Throwable $exception): void
    {
        $message = sprintf(
            'EventCrew: service "%s" failed to resolve during boot: %s',
            $id,
            $exception->getMessage()
        );

        try {
            $this->container->get(Logger::class)->error($message);
        } catch (Throwable) {
            error_log($message);
        }
    }

    private function registerServices(): void
    {
        $this->container->singleton(
            Logger::class,
            fn () => new Logger()
        );

        $this->container->singleton(
            View::class,
            fn () => new View()
        );

        $this->container->singleton(
            VolunteerRepository::class,
            fn () => new VolunteerRepository()
        );

        $this->container->singleton(
            ShiftRepository::class,
            fn () => new ShiftRepository()
        );

        $this->container->singleton(
            AssignmentRepository::class,
            fn () => new AssignmentRepository()
        );

        $this->container->singleton(
            SettingsPage::class,
            fn (Container $container) => new SettingsPage(
                $container->get(View::class),
                $container->get(VolunteerRepository::class)
            )
        );

        $this->container->singleton(
            ShiftsPage::class,
            fn (Container $container) => new ShiftsPage(
                $container->get(View::class),
                $container->get(ShiftRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(VolunteerRepository::class)
            )
        );

        $this->container->singleton(
            VolunteersPage::class,
            fn (Container $container) => new VolunteersPage(
                $container->get(View::class),
                $container->get(VolunteerRepository::class),
                $container->get(AssignmentRepository::class)
            )
        );

        $this->container->singleton(
            Admin::class,
            fn (Container $container) => new Admin($container)
        );
    }
}

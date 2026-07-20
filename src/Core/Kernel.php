<?php

declare(strict_types=1);

namespace EventCrew\Core;

use EventCrew\Admin\Admin;
use EventCrew\Admin\SettingsPage;
use EventCrew\Admin\TasksPage;
use EventCrew\Admin\View;
use EventCrew\Admin\PeoplePage;
use EventCrew\Database\Schema;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\EventMeshSyncListener;
use EventCrew\Support\Logger;
use EventCrew\Support\TaskTemplateApplier;
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

        // Registered unconditionally - not gated to admin - because a sync
        // triggered by WP-Cron never touches wp-admin, and that's exactly
        // the case this exists to handle: a new event's tasks appearing
        // with nobody watching.
        $this->container->get(EventMeshSyncListener::class)->boot();

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
            PersonRepository::class,
            fn () => new PersonRepository()
        );

        $this->container->singleton(
            TaskRepository::class,
            fn () => new TaskRepository()
        );

        $this->container->singleton(
            AssignmentRepository::class,
            fn () => new AssignmentRepository()
        );

        $this->container->singleton(
            SettingsPage::class,
            fn (Container $container) => new SettingsPage(
                $container->get(View::class),
                $container->get(PersonRepository::class)
            )
        );

        $this->container->singleton(
            TaskTemplateApplier::class,
            fn (Container $container) => new TaskTemplateApplier(
                $container->get(TaskRepository::class)
            )
        );

        $this->container->singleton(
            EventMeshSyncListener::class,
            fn (Container $container) => new EventMeshSyncListener(
                $container->get(TaskTemplateApplier::class)
            )
        );

        $this->container->singleton(
            TasksPage::class,
            fn (Container $container) => new TasksPage(
                $container->get(View::class),
                $container->get(TaskRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(PersonRepository::class),
                $container->get(TaskTemplateApplier::class)
            )
        );

        $this->container->singleton(
            PeoplePage::class,
            fn (Container $container) => new PeoplePage(
                $container->get(View::class),
                $container->get(PersonRepository::class),
                $container->get(AssignmentRepository::class)
            )
        );

        $this->container->singleton(
            Admin::class,
            fn (Container $container) => new Admin($container)
        );
    }
}

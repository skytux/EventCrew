<?php

declare(strict_types=1);

namespace EventCrew\Core;

use EventCrew\Admin\Admin;
use EventCrew\Admin\DiagnosticsPage;
use EventCrew\Admin\RosterPage;
use EventCrew\Admin\SettingsPage;
use EventCrew\Admin\TasksPage;
use EventCrew\Admin\View;
use EventCrew\Admin\PeoplePage;
use EventCrew\Database\Schema;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\BoardPush;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\CronFallbackTrigger;
use EventCrew\Support\DoorList;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\LeaderEligibility;
use EventCrew\Support\LeaderEligibilityNotifier;
use EventCrew\Support\LeaderGate;
use EventCrew\Support\HealthReport;
use EventCrew\Support\EventMeshSyncListener;
use EventCrew\Support\Logger;
use EventCrew\Support\CreditGrantNotifier;
use EventCrew\Support\Mailer;
use EventCrew\Support\OpenTaskCall;
use EventCrew\Support\ReminderCall;
use EventCrew\Support\RosterAssembler;
use EventCrew\Support\Scheduler;
use EventCrew\Support\SlotFreedNotice;
use EventCrew\Support\SignupService;
use EventCrew\Support\StandingNotice;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\TaskTemplateApplier;
use EventCrew\Support\Turnstile;
use EventCrew\Telegram\BoardRefreshListener;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\DohResolver;
use EventCrew\Telegram\ManageController;
use EventCrew\Telegram\OnboardingService;
use EventCrew\Telegram\GiftService;
use EventCrew\Telegram\NotificationSettingsService;
use EventCrew\Telegram\PermissionService;
use EventCrew\Telegram\ProfileService;
use EventCrew\Telegram\ReplacementService;
use EventCrew\Telegram\RosterService;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Telegram\CalendarController;
use EventCrew\Telegram\TicketController;
use EventCrew\Telegram\TicketRedemptionService;
use EventCrew\Telegram\UpdateRouter;
use EventCrew\Telegram\VerificationController;
use EventCrew\Telegram\WebhookController;
use EventCrew\Web\PwaController;
use EventCrew\Web\SignupController;
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

        // The email template's post type. Registered unconditionally, and not
        // only in wp-admin, because the notification cron renders the template
        // with nobody logged in.
        $this->container->get(EmailTemplate::class)->boot();

        // The bot's endpoints and board refresh live on the front/cron path
        // for the same reason: Telegram posts updates to a REST route with
        // nobody logged in, and a cron-driven task creation must still be able
        // to refresh the board. All three no-op until the bot is configured.
        $this->container->get(WebhookController::class)->boot();
        $this->container->get(VerificationController::class)->boot();
        $this->container->get(TicketController::class)->boot();
        $this->container->get(CalendarController::class)->boot();
        $this->container->get(ManageController::class)->boot();
        $this->container->get(BoardRefreshListener::class)->boot();
        $this->container->get(SlotFreedNotice::class)->boot();

        // The notifications heartbeat: the hourly cron event self-schedules and
        // registers its run action here (front/cron path, since WP-Cron fires
        // with nobody logged in), and the opt-in fallback hooks an ordinary
        // request when a host's WP-Cron never fires.
        $this->container->get(Scheduler::class)->boot();
        $this->container->get(CronFallbackTrigger::class)->boot();

        // The public signup page: its shortcode, block and admin-ajax handlers
        // must exist for logged-out visitors, so it boots on the front path too.
        $this->container->get(SignupController::class)->boot();

        // The PWA layer over the signup page: serves the manifest, service worker
        // and icons, and injects the install tags. Front path, logged-out.
        $this->container->get(PwaController::class)->boot();

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
            AuthTokenRepository::class,
            fn () => new AuthTokenRepository()
        );

        $this->container->singleton(
            RedemptionRepository::class,
            fn () => new RedemptionRepository()
        );

        $this->container->singleton(
            CreditGrantRepository::class,
            fn () => new CreditGrantRepository()
        );

        $this->container->singleton(
            FreeEntryGate::class,
            fn () => new FreeEntryGate()
        );

        $this->container->singleton(
            LeaderGate::class,
            fn () => new LeaderGate()
        );

        $this->container->singleton(
            StandingCalculator::class,
            fn (Container $container) => new StandingCalculator(
                $container->get(AssignmentRepository::class),
                $container->get(RedemptionRepository::class),
                $container->get(CreditGrantRepository::class)
            )
        );

        $this->container->singleton(
            SignupService::class,
            fn (Container $container) => new SignupService(
                $container->get(AssignmentRepository::class),
                $container->get(StandingCalculator::class),
                $container->get(PersonRepository::class),
                $container->get(TaskRepository::class)
            )
        );

        $this->container->singleton(
            DohResolver::class,
            fn (Container $container) => new DohResolver(
                $container->get(Logger::class)
            )
        );

        $this->container->singleton(
            TelegramClient::class,
            fn (Container $container) => new TelegramClient(
                $container->get(Logger::class),
                $container->get(DohResolver::class)
            )
        );

        $this->container->singleton(
            OnboardingService::class,
            fn (Container $container) => new OnboardingService(
                $container->get(PersonRepository::class),
                $container->get(AuthTokenRepository::class),
                $container->get(TelegramClient::class),
                $container->get(Logger::class)
            )
        );

        $this->container->singleton(
            EmailTemplate::class,
            fn (Container $container) => new EmailTemplate(
                $container->get(Logger::class)
            )
        );

        $this->container->singleton(
            Mailer::class,
            fn (Container $container) => new Mailer(
                $container->get(Logger::class),
                $container->get(EmailTemplate::class)
            )
        );

        $this->container->singleton(
            ClaimNotifier::class,
            fn (Container $container) => new ClaimNotifier(
                $container->get(TaskRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(Mailer::class),
                $container->get(TelegramClient::class),
                $container->get(StandingCalculator::class)
            )
        );

        $this->container->singleton(
            NotificationsRepository::class,
            fn () => new NotificationsRepository()
        );

        $this->container->singleton(
            OpenTaskCall::class,
            fn (Container $container) => new OpenTaskCall(
                $container->get(TaskRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(PersonRepository::class),
                $container->get(NotificationsRepository::class),
                $container->get(Mailer::class),
                $container->get(TelegramClient::class)
            )
        );

        $this->container->singleton(
            ReminderCall::class,
            fn (Container $container) => new ReminderCall(
                $container->get(TaskRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(PersonRepository::class),
                $container->get(TelegramClient::class),
                $container->get(Mailer::class)
            )
        );

        $this->container->singleton(
            StandingNotice::class,
            fn (Container $container) => new StandingNotice(
                $container->get(TaskRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(PersonRepository::class),
                $container->get(NotificationsRepository::class),
                $container->get(TelegramClient::class),
                $container->get(Mailer::class)
            )
        );

        $this->container->singleton(
            BoardPush::class,
            fn (Container $container) => new BoardPush(
                $container->get(TaskRepository::class),
                $container->get(NotificationsRepository::class),
                $container->get(BoardService::class)
            )
        );

        $this->container->singleton(
            LeaderEligibility::class,
            fn (Container $container) => new LeaderEligibility(
                $container->get(AssignmentRepository::class),
                $container->get(PersonRepository::class)
            )
        );

        $this->container->singleton(
            LeaderEligibilityNotifier::class,
            fn (Container $container) => new LeaderEligibilityNotifier(
                $container->get(LeaderEligibility::class),
                $container->get(PersonRepository::class),
                $container->get(Mailer::class),
                $container->get(TelegramClient::class)
            )
        );

        $this->container->singleton(
            Scheduler::class,
            fn (Container $container) => new Scheduler(
                $container->get(ReminderCall::class),
                $container->get(OpenTaskCall::class),
                $container->get(StandingNotice::class),
                $container->get(BoardPush::class),
                $container->get(BoardService::class),
                $container->get(LeaderEligibilityNotifier::class)
            )
        );

        $this->container->singleton(
            CronFallbackTrigger::class,
            fn (Container $container) => new CronFallbackTrigger(
                $container->get(Scheduler::class)
            )
        );

        $this->container->singleton(
            Turnstile::class,
            fn (Container $container) => new Turnstile(
                $container->get(Logger::class)
            )
        );

        $this->container->singleton(
            SignupController::class,
            fn (Container $container) => new SignupController(
                $container->get(PersonRepository::class),
                $container->get(AuthTokenRepository::class),
                $container->get(TaskRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(SignupService::class),
                $container->get(StandingCalculator::class),
                $container->get(Mailer::class),
                $container->get(ClaimNotifier::class),
                $container->get(Turnstile::class),
                $container->get(TicketRedemptionService::class)
            )
        );

        $this->container->singleton(
            PwaController::class,
            fn () => new PwaController()
        );

        $this->container->singleton(
            BoardService::class,
            fn (Container $container) => new BoardService(
                $container->get(TaskRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(PersonRepository::class),
                $container->get(TelegramClient::class),
                $container->get(Logger::class),
                $container->get(ClaimNotifier::class),
                $container->get(SignupService::class)
            )
        );

        $this->container->singleton(
            DoorList::class,
            fn (Container $container) => new DoorList(
                $container->get(TaskRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(PersonRepository::class),
                $container->get(RedemptionRepository::class),
                $container->get(StandingCalculator::class)
            )
        );

        $this->container->singleton(
            RosterAssembler::class,
            fn (Container $container) => new RosterAssembler(
                $container->get(TaskRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(PersonRepository::class),
                $container->get(StandingCalculator::class)
            )
        );

        $this->container->singleton(
            RosterService::class,
            fn (Container $container) => new RosterService(
                $container->get(RosterAssembler::class),
                $container->get(TaskRepository::class),
                $container->get(PersonRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(TelegramClient::class)
            )
        );

        $this->container->singleton(
            ReplacementService::class,
            fn (Container $container) => new ReplacementService(
                $container->get(AssignmentRepository::class),
                $container->get(TaskRepository::class),
                $container->get(PersonRepository::class),
                $container->get(BoardService::class),
                $container->get(TelegramClient::class),
                $container->get(Mailer::class)
            )
        );

        $this->container->singleton(
            ProfileService::class,
            fn (Container $container) => new ProfileService(
                $container->get(PersonRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(TaskRepository::class),
                $container->get(StandingCalculator::class),
                $container->get(TelegramClient::class)
            )
        );

        $this->container->singleton(
            TicketRedemptionService::class,
            fn (Container $container) => new TicketRedemptionService(
                $container->get(PersonRepository::class),
                $container->get(TaskRepository::class),
                $container->get(RedemptionRepository::class),
                $container->get(StandingCalculator::class),
                $container->get(FreeEntryGate::class),
                $container->get(TelegramClient::class),
                $container->get(Mailer::class)
            )
        );

        $this->container->singleton(
            CreditGrantNotifier::class,
            fn (Container $container) => new CreditGrantNotifier(
                $container->get(Mailer::class),
                $container->get(TelegramClient::class)
            )
        );

        $this->container->singleton(
            GiftService::class,
            fn (Container $container) => new GiftService(
                $container->get(PersonRepository::class),
                $container->get(CreditGrantRepository::class),
                $container->get(StandingCalculator::class),
                $container->get(TelegramClient::class),
                $container->get(CreditGrantNotifier::class)
            )
        );

        $this->container->singleton(
            PermissionService::class,
            fn (Container $container) => new PermissionService(
                $container->get(PersonRepository::class),
                $container->get(TelegramClient::class),
                $container->get(LeaderEligibility::class),
                $container->get(Mailer::class)
            )
        );

        $this->container->singleton(
            NotificationSettingsService::class,
            fn (Container $container) => new NotificationSettingsService(
                $container->get(PersonRepository::class),
                $container->get(TelegramClient::class)
            )
        );

        $this->container->singleton(
            UpdateRouter::class,
            fn (Container $container) => new UpdateRouter(
                $container->get(OnboardingService::class),
                $container->get(BoardService::class),
                $container->get(RosterService::class),
                $container->get(ReplacementService::class),
                $container->get(ProfileService::class),
                $container->get(TicketRedemptionService::class),
                $container->get(GiftService::class),
                $container->get(PermissionService::class),
                $container->get(NotificationSettingsService::class)
            )
        );

        $this->container->singleton(
            WebhookController::class,
            fn (Container $container) => new WebhookController(
                $container->get(UpdateRouter::class)
            )
        );

        $this->container->singleton(
            VerificationController::class,
            fn (Container $container) => new VerificationController(
                $container->get(AuthTokenRepository::class),
                $container->get(PersonRepository::class),
                $container->get(TelegramClient::class)
            )
        );

        $this->container->singleton(
            TicketController::class,
            fn (Container $container) => new TicketController(
                $container->get(AssignmentRepository::class),
                $container->get(TaskRepository::class),
                $container->get(PersonRepository::class),
                $container->get(RedemptionRepository::class)
            )
        );

        $this->container->singleton(
            CalendarController::class,
            fn (Container $container) => new CalendarController(
                $container->get(TaskRepository::class)
            )
        );

        $this->container->singleton(
            ManageController::class,
            fn (Container $container) => new ManageController(
                $container->get(PersonRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(RedemptionRepository::class),
                $container->get(CreditGrantRepository::class)
            )
        );

        $this->container->singleton(
            BoardRefreshListener::class,
            fn (Container $container) => new BoardRefreshListener(
                $container->get(BoardService::class)
            )
        );

        $this->container->singleton(
            SlotFreedNotice::class,
            fn (Container $container) => new SlotFreedNotice(
                $container->get(TaskRepository::class),
                $container->get(PersonRepository::class),
                $container->get(Mailer::class),
                $container->get(TelegramClient::class)
            )
        );

        $this->container->singleton(
            SettingsPage::class,
            fn (Container $container) => new SettingsPage(
                $container->get(View::class),
                $container->get(TelegramClient::class),
                $container->get(Mailer::class),
                $container->get(EmailTemplate::class),
                $container->get(BoardService::class)
            )
        );

        $this->container->singleton(
            TaskTemplateApplier::class,
            fn (Container $container) => new TaskTemplateApplier(
                $container->get(TaskRepository::class),
                $container->get(LeaderGate::class)
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
                $container->get(TaskTemplateApplier::class),
                $container->get(OpenTaskCall::class)
            )
        );

        $this->container->singleton(
            PeoplePage::class,
            fn (Container $container) => new PeoplePage(
                $container->get(View::class),
                $container->get(PersonRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(RedemptionRepository::class),
                $container->get(StandingCalculator::class),
                $container->get(CreditGrantRepository::class),
                $container->get(CreditGrantNotifier::class),
                $container->get(LeaderEligibility::class),
                $container->get(LeaderGate::class)
            )
        );

        $this->container->singleton(
            RosterPage::class,
            fn (Container $container) => new RosterPage(
                $container->get(View::class),
                $container->get(RosterAssembler::class),
                $container->get(AssignmentRepository::class),
                $container->get(TaskRepository::class),
                $container->get(DoorList::class),
                $container->get(RedemptionRepository::class),
                $container->get(StandingCalculator::class),
                $container->get(FreeEntryGate::class),
                $container->get(LeaderGate::class),
                $container->get(TicketRedemptionService::class)
            )
        );

        $this->container->singleton(
            HealthReport::class,
            fn (Container $container) => new HealthReport(
                $container->get(Logger::class)
            )
        );

        $this->container->singleton(
            DiagnosticsPage::class,
            fn (Container $container) => new DiagnosticsPage(
                $container->get(View::class),
                $container->get(HealthReport::class)
            )
        );

        $this->container->singleton(
            Admin::class,
            fn (Container $container) => new Admin($container)
        );
    }
}

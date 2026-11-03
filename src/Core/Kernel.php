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
use EventCrew\Repositories\CheckinRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\AssistantKnowledge;
use EventCrew\Support\BoardPush;
use EventCrew\Support\BuyerImport;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\RoleTaskService;
use EventCrew\Support\CronFallbackTrigger;
use EventCrew\Support\DoorAccess;
use EventCrew\Support\DoorDesk;
use EventCrew\Support\DoorHandoff;
use EventCrew\Support\DoorNewMember;
use EventCrew\Support\DoorLinkSender;
use EventCrew\Support\DoorList;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\HealthReport;
use EventCrew\Support\EventMeshSyncListener;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\CreditGrantNotifier;
use EventCrew\Support\Mailer;
use EventCrew\Support\Newsletter;
use EventCrew\Support\NewsletterText;
use EventCrew\Support\OpenTaskCall;
use EventCrew\Support\OrganizeTaskBackfill;
use EventCrew\Support\PageCacheFlush;
use EventCrew\Support\PersonEraser;
use EventCrew\Support\PrivacyEraser;
use EventCrew\Support\PrivacyExporter;
use EventCrew\Support\ReminderCall;
use EventCrew\Support\RetentionPurge;
use EventCrew\Support\RoleConditions;
use EventCrew\Support\RoleNotifier;
use EventCrew\Support\RosterAssembler;
use EventCrew\Support\SalesDeskGate;
use EventCrew\Support\Scheduler;
use EventCrew\Support\WelcomeSender;
use EventCrew\Support\SlotFreedNotice;
use EventCrew\Support\SignupService;
use EventCrew\Support\StandingNotice;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\TaskTemplateApplier;
use EventCrew\Support\TelegramLogin;
use EventCrew\Support\TicketMail;
use EventCrew\Support\Turnstile;
use EventCrew\Telegram\AssistantService;
use EventCrew\Telegram\BoardRefreshListener;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\ChatForwarder;
use EventCrew\Telegram\DohResolver;
use EventCrew\Telegram\ManageController;
use EventCrew\Telegram\NewsletterCommand;
use EventCrew\Telegram\SayCommand;
use EventCrew\Telegram\OnboardingService;
use EventCrew\Telegram\GiftService;
use EventCrew\Telegram\NotificationSettingsService;
use EventCrew\Telegram\PermissionService;
use EventCrew\Telegram\RoleTaskBot;
use EventCrew\Telegram\ProfileService;
use EventCrew\Telegram\ReplacementService;
use EventCrew\Telegram\SalesLinkService;
use EventCrew\Telegram\RosterService;
use EventCrew\Telegram\DoorListService;
use EventCrew\Telegram\GuestListService;
use EventCrew\Telegram\HelpService;
use EventCrew\Telegram\CommandMenu;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Telegram\CalendarController;
use EventCrew\Telegram\TicketController;
use EventCrew\Telegram\TicketRedemptionService;
use EventCrew\Telegram\UpdateRouter;
use EventCrew\Telegram\LanguageService;
use EventCrew\Telegram\VerificationController;
use EventCrew\Telegram\WebhookController;
use EventCrew\Web\BuyerImportController;
use EventCrew\MailImport\MailImporter;
use EventCrew\Web\CrewAdminController;
use EventCrew\Web\CrewAdminNewsletter;
use EventCrew\Web\CrewAdminPeople;
use EventCrew\Web\CrewAdminRoster;
use EventCrew\Web\CrewAdminTasks;
use EventCrew\Web\CrewAppPage;
use EventCrew\Web\DoorController;
use EventCrew\Web\NewsletterController;
use EventCrew\Web\PrivacyController;
use EventCrew\Web\PwaController;
use EventCrew\Web\SessionPerson;
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

        // Adds the organize task to nights created before it existed. Runs
        // once per site, on the first request of any kind after the update.
        $this->container->get(OrganizeTaskBackfill::class)->maybeRun();

        // Empties the page cache once after each update, so no visitor gets a
        // page built by the old version.
        PageCacheFlush::boot();

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

        // The language-model helper's cached page text is dropped whenever a
        // post or a menu is saved, from wp-admin or anywhere else.
        $this->container->get(AssistantKnowledge::class)->boot();

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

        // The import-by-email endpoint: the mail poller posts to it with nobody
        // logged in, so it boots on the front path with the bot's endpoints.
        $this->container->get(BuyerImportController::class)->boot();

        // The site's own reader for the import mailbox (IMAP or JMAP): a
        // five-minute cron event, a no-op while switched off in Settings.
        $this->container->get(MailImporter::class)->boot();

        // The notifications heartbeat: the hourly cron event self-schedules and
        // registers its run action here (front/cron path, since WP-Cron fires
        // with nobody logged in), and the opt-in fallback hooks an ordinary
        // request when a host's WP-Cron never fires.
        $this->container->get(Scheduler::class)->boot();
        $this->container->get(CronFallbackTrigger::class)->boot();

        // The ticket emails after an import: a single cron event that sends
        // one batch and schedules the next while rows remain.
        $this->container->get(TicketMail::class)->boot();

        // The newsletter: its sending batches run as a cron event of their
        // own, and its sign-up shortcode, form handler and link endpoint
        // must exist for logged-out visitors, so they boot on every path.
        $this->container->get(Newsletter::class)->boot();
        $this->container->get(NewsletterController::class)->boot();

        // The public signup page: its shortcode, block and admin-ajax handlers
        // must exist for logged-out visitors, so it boots on the front path too.
        $this->container->get(SignupController::class)->boot();
        $this->container->get(CrewAdminController::class)->boot();

        // The sales desk (door page) and the links that open it: admin-ajax
        // handlers for logged-out door crew, and a five-minute cron plus an
        // on-request check that send tonight's links without anyone asking.
        $this->container->get(DoorController::class)->boot();
        $this->container->get(DoorLinkSender::class)->boot();

        // The welcome message: sent the moment a member confirms their email,
        // which happens on the front path (web sign-in, the bot's webhook).
        $this->container->get(WelcomeSender::class)->boot();

        // Each person's bot command menu: changed when their organizer status
        // changes (bot, wp-admin) or they are marked arrived at the sales desk
        // (bot, crew page, wp-admin), so it listens everywhere.
        $this->container->get(CommandMenu::class)->boot();

        // The organizers' notice when someone is given a role. Roles are given
        // from the bot, the crew page and wp-admin, so it listens everywhere.
        add_action(
            RoleNotifier::GIVEN_HOOK,
            fn (int $personId, string $slug, string $giver = '')
                => $this->container->get(RoleNotifier::class)->roleGiven($personId, $slug, $giver),
            10,
            3
        );
        add_action(
            RoleNotifier::ORGANIZER_HOOK,
            fn (int $personId, string $giver = '')
                => $this->container->get(RoleNotifier::class)->organizerGiven($personId, $giver),
            10,
            2
        );

        // The PWA layer over the signup page: serves the manifest, service worker
        // and icons, and injects the install tags. Front path, logged-out.
        $this->container->get(PwaController::class)->boot();

        // The Crew app as a page of its own, and old page addresses redirected.
        $this->container->get(CrewAppPage::class)->boot();

        // The public privacy notice: its shortcode and block must exist for
        // logged-out visitors, which is the entire audience for a privacy
        // notice, so it boots on the front path with the signup page.
        $this->container->get(PrivacyController::class)->boot();

        // The personal-data export and erase hooks. Registered on every request
        // rather than gated to admin, because the filters they add to are also
        // read by the privacy request emails WordPress sends from the front end.
        $this->container->get(PrivacyExporter::class)->boot();
        $this->container->get(PrivacyEraser::class)->boot();

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
            GuestRepository::class,
            fn () => new GuestRepository()
        );

        $this->container->singleton(
            CreditGrantRepository::class,
            fn () => new CreditGrantRepository()
        );

        $this->container->singleton(
            CheckinRepository::class,
            fn () => new CheckinRepository()
        );

        $this->container->singleton(
            TicketBuyerRepository::class,
            fn () => new TicketBuyerRepository()
        );

        $this->container->singleton(
            NewsletterSubscriberRepository::class,
            fn () => new NewsletterSubscriberRepository()
        );

        $this->container->singleton(
            NewsletterText::class,
            fn (Container $container) => new NewsletterText(
                $container->get(LlmClient::class),
                $container->get(Logger::class)
            )
        );

        $this->container->singleton(
            Newsletter::class,
            fn (Container $container) => new Newsletter(
                $container->get(NewsletterSubscriberRepository::class),
                $container->get(PersonRepository::class),
                $container->get(NewsletterText::class),
                $container->get(Mailer::class),
                $container->get(Logger::class)
            )
        );

        $this->container->singleton(
            NewsletterController::class,
            fn (Container $container) => new NewsletterController(
                $container->get(NewsletterSubscriberRepository::class),
                $container->get(Mailer::class),
                $container->get(Newsletter::class),
                $container->get(Turnstile::class)
            )
        );

        $this->container->singleton(
            CrewAdminNewsletter::class,
            fn (Container $container) => new CrewAdminNewsletter(
                $container->get(Newsletter::class),
                $container->get(NewsletterText::class)
            )
        );

        $this->container->singleton(
            SayCommand::class,
            fn (Container $container) => new SayCommand(
                $container->get(PersonRepository::class),
                $container->get(TelegramClient::class)
            )
        );

        $this->container->singleton(
            NewsletterCommand::class,
            fn (Container $container) => new NewsletterCommand(
                $container->get(PersonRepository::class),
                $container->get(NewsletterText::class),
                $container->get(TelegramClient::class)
            )
        );

        $this->container->singleton(
            FreeEntryGate::class,
            fn (Container $container) => new FreeEntryGate($container->get(TaskRepository::class))
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
                $container->get(Logger::class),
                $container->get(Mailer::class)
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
            RoleConditions::class,
            fn (Container $container) => new RoleConditions(
                $container->get(AssignmentRepository::class),
                $container->get(PersonRepository::class)
            )
        );

        $this->container->singleton(
            RoleNotifier::class,
            fn (Container $container) => new RoleNotifier(
                $container->get(RoleConditions::class),
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
                $container->get(RoleNotifier::class),
                $container->get(RetentionPurge::class),
                $container->get(WelcomeSender::class),
                $container->get(CommandMenu::class),
                $container->get(Newsletter::class)
            )
        );

        $this->container->singleton(
            WelcomeSender::class,
            fn (Container $container) => new WelcomeSender(
                $container->get(TaskRepository::class),
                $container->get(PersonRepository::class),
                $container->get(NotificationsRepository::class),
                $container->get(Mailer::class),
                $container->get(TelegramClient::class)
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
                $container->get(TicketRedemptionService::class),
                $container->get(SalesDeskGate::class),
                $container->get(DoorLinkSender::class),
                $container->get(CrewAdminController::class),
                $container->get(TelegramLogin::class)
            )
        );

        $this->container->singleton(
            TelegramLogin::class,
            fn (Container $container) => new TelegramLogin(
                $container->get(Logger::class),
                $container->get(DohResolver::class)
            )
        );

        $this->container->singleton(
            CrewAdminController::class,
            fn (Container $container) => new CrewAdminController(
                $container->get(PersonRepository::class),
                new SessionPerson($container->get(PersonRepository::class)),
                $container->get(SalesDeskGate::class),
                $container->get(DoorLinkSender::class),
                $container->get(BoardService::class),
                $container->get(CreditGrantRepository::class),
                $container->get(CreditGrantNotifier::class),
                $container->get(RosterAssembler::class),
                $container->get(AssignmentRepository::class),
                $container->get(TelegramClient::class),
                $container->get(Mailer::class),
                $container->get(Logger::class),
                $container->get(RoleTaskService::class),
                $container->get(CrewAdminRoster::class),
                $container->get(CrewAdminPeople::class),
                $container->get(CrewAdminTasks::class),
                $container->get(CrewAdminNewsletter::class)
            )
        );

        $this->container->singleton(
            CrewAdminTasks::class,
            fn (Container $container) => new CrewAdminTasks(
                $container->get(TaskRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(PersonRepository::class),
                $container->get(TaskTemplateApplier::class),
                $container->get(OpenTaskCall::class)
            )
        );

        $this->container->singleton(
            CrewAdminPeople::class,
            fn (Container $container) => new CrewAdminPeople(
                $container->get(PersonRepository::class),
                $container->get(CreditGrantRepository::class),
                $container->get(RedemptionRepository::class),
                $container->get(StandingCalculator::class)
            )
        );

        $this->container->singleton(
            CrewAdminRoster::class,
            fn (Container $container) => new CrewAdminRoster(
                $container->get(TaskRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(DoorList::class),
                $container->get(RedemptionRepository::class),
                $container->get(StandingCalculator::class),
                $container->get(FreeEntryGate::class),
                $container->get(TicketRedemptionService::class),
                $container->get(GuestRepository::class),
                $container->get(TicketBuyerRepository::class),
                $container->get(BuyerImport::class),
                $container->get(MailImporter::class),
                $container->get(TicketMail::class)
            )
        );

        $this->container->singleton(
            TicketMail::class,
            fn (Container $container) => new TicketMail(
                $container->get(TicketBuyerRepository::class),
                $container->get(GuestRepository::class),
                $container->get(TaskRepository::class),
                $container->get(Mailer::class)
            )
        );

        $this->container->singleton(
            RoleTaskService::class,
            fn (Container $container) => new RoleTaskService(
                $container->get(PersonRepository::class),
                $container->get(TaskRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(SignupService::class),
                $container->get(ClaimNotifier::class)
            )
        );

        $this->container->singleton(
            SalesDeskGate::class,
            fn (Container $container) => new SalesDeskGate(
                $container->get(TaskRepository::class),
                $container->get(AssignmentRepository::class)
            )
        );

        $this->container->singleton(
            DoorLinkSender::class,
            fn (Container $container) => new DoorLinkSender(
                $container->get(TaskRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(PersonRepository::class),
                $container->get(NotificationsRepository::class),
                $container->get(AuthTokenRepository::class),
                $container->get(SalesDeskGate::class),
                $container->get(TelegramClient::class),
                $container->get(Mailer::class)
            )
        );

        $this->container->singleton(
            DoorDesk::class,
            fn (Container $container) => new DoorDesk(
                $container->get(TaskRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(PersonRepository::class),
                $container->get(RedemptionRepository::class),
                $container->get(GuestRepository::class),
                $container->get(TicketBuyerRepository::class),
                $container->get(CheckinRepository::class),
                $container->get(AuthTokenRepository::class),
                $container->get(StandingCalculator::class),
                $container->get(TicketRedemptionService::class),
                $container->get(SalesDeskGate::class),
                $container->get(CreditGrantRepository::class),
                $container->get(ClaimNotifier::class)
            )
        );

        $this->container->singleton(
            DoorController::class,
            fn (Container $container) => new DoorController(
                $container->get(DoorDesk::class),
                $container->get(DoorNewMember::class),
                $container->get(DoorHandoff::class)
            )
        );

        $this->container->singleton(
            DoorHandoff::class,
            fn (Container $container) => new DoorHandoff(
                $container->get(DoorDesk::class),
                $container->get(TaskRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(PersonRepository::class),
                $container->get(AuthTokenRepository::class),
                $container->get(SalesDeskGate::class)
            )
        );

        $this->container->singleton(
            DoorNewMember::class,
            fn (Container $container) => new DoorNewMember(
                $container->get(PersonRepository::class),
                $container->get(AuthTokenRepository::class),
                $container->get(Mailer::class),
                $container->get(TelegramClient::class)
            )
        );

        $this->container->singleton(
            SalesLinkService::class,
            fn (Container $container) => new SalesLinkService(
                $container->get(SalesDeskGate::class),
                $container->get(PersonRepository::class),
                $container->get(DoorLinkSender::class),
                $container->get(TelegramClient::class)
            )
        );

        $this->container->singleton(
            PwaController::class,
            fn () => new PwaController()
        );

        $this->container->singleton(
            CrewAppPage::class,
            fn (Container $container) => new CrewAppPage(
                $container->get(SignupController::class),
                $container->get(PwaController::class)
            )
        );

        $this->container->singleton(
            PrivacyController::class,
            fn (Container $container) => new PrivacyController(
                $container->get(SignupController::class)
            )
        );

        $this->container->singleton(
            PersonEraser::class,
            fn (Container $container) => new PersonEraser(
                $container->get(PersonRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(RedemptionRepository::class),
                $container->get(CreditGrantRepository::class),
                $container->get(AuthTokenRepository::class),
                $container->get(NotificationsRepository::class)
            )
        );

        $this->container->singleton(
            RetentionPurge::class,
            fn (Container $container) => new RetentionPurge(
                $container->get(PersonRepository::class),
                $container->get(PersonEraser::class),
                $container->get(TicketBuyerRepository::class),
                $container->get(GuestRepository::class),
                $container->get(NewsletterSubscriberRepository::class)
            )
        );

        $this->container->singleton(
            PrivacyExporter::class,
            fn (Container $container) => new PrivacyExporter(
                $container->get(PersonRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(TaskRepository::class),
                $container->get(RedemptionRepository::class),
                $container->get(CreditGrantRepository::class),
                $container->get(TicketBuyerRepository::class),
                $container->get(GuestRepository::class)
            )
        );

        $this->container->singleton(
            PrivacyEraser::class,
            fn (Container $container) => new PrivacyEraser(
                $container->get(PersonRepository::class),
                $container->get(PersonEraser::class),
                $container->get(TicketBuyerRepository::class),
                $container->get(GuestRepository::class)
            )
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
            DoorAccess::class,
            fn (Container $container) => new DoorAccess(
                $container->get(TaskRepository::class),
                $container->get(AssignmentRepository::class)
            )
        );

        $this->container->singleton(
            BuyerImport::class,
            fn (Container $container) => new BuyerImport(
                $container->get(TicketBuyerRepository::class),
                $container->get(DoorAccess::class)
            )
        );

        $this->container->singleton(
            MailImporter::class,
            fn (Container $container) => new MailImporter(
                $container->get(BuyerImportController::class),
                $container->get(Logger::class)
            )
        );

        $this->container->singleton(
            BuyerImportController::class,
            fn (Container $container) => new BuyerImportController(
                $container->get(PersonRepository::class),
                $container->get(BuyerImport::class),
                $container->get(Mailer::class),
                $container->get(GuestRepository::class)
            )
        );

        $this->container->singleton(
            DoorListService::class,
            fn (Container $container) => new DoorListService(
                $container->get(DoorList::class),
                $container->get(GuestRepository::class),
                $container->get(DoorAccess::class),
                $container->get(PersonRepository::class),
                $container->get(TelegramClient::class)
            )
        );

        $this->container->singleton(
            GuestListService::class,
            fn (Container $container) => new GuestListService(
                $container->get(GuestRepository::class),
                $container->get(DoorAccess::class),
                $container->get(PersonRepository::class),
                $container->get(TelegramClient::class)
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
                $container->get(RoleConditions::class),
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
            LanguageService::class,
            fn (Container $container) => new LanguageService(
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
                $container->get(NotificationSettingsService::class),
                $container->get(DoorListService::class),
                $container->get(GuestListService::class),
                $container->get(SalesLinkService::class),
                $container->get(LanguageService::class),
                $container->get(RoleTaskBot::class),
                $container->get(HelpService::class),
                $container->get(AssistantService::class),
                $container->get(NewsletterCommand::class),
                $container->get(SayCommand::class)
            )
        );

        $this->container->singleton(
            LlmClient::class,
            fn (Container $container) => new LlmClient($container->get(Logger::class))
        );

        $this->container->singleton(
            AssistantKnowledge::class,
            fn (Container $container) => new AssistantKnowledge($container->get(TaskRepository::class))
        );

        $this->container->singleton(
            AssistantService::class,
            fn (Container $container) => new AssistantService(
                $container->get(TelegramClient::class),
                $container->get(LlmClient::class),
                $container->get(AssistantKnowledge::class),
                $container->get(HelpService::class),
                $container->get(Logger::class),
                true,
                $container->get(ChatForwarder::class)
            )
        );

        $this->container->singleton(
            ChatForwarder::class,
            fn (Container $container) => new ChatForwarder(
                $container->get(PersonRepository::class),
                $container->get(Mailer::class),
                $container->get(TelegramClient::class),
                $container->get(Logger::class)
            )
        );

        $this->container->singleton(
            CommandMenu::class,
            fn (Container $container) => new CommandMenu(
                $container->get(PersonRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(TaskRepository::class),
                $container->get(DoorAccess::class),
                $container->get(TelegramClient::class)
            )
        );

        $this->container->singleton(
            HelpService::class,
            fn (Container $container) => new HelpService(
                $container->get(PersonRepository::class),
                $container->get(DoorAccess::class),
                $container->get(TelegramClient::class)
            )
        );

        $this->container->singleton(
            RoleTaskBot::class,
            fn (Container $container) => new RoleTaskBot(
                $container->get(PersonRepository::class),
                $container->get(TaskRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(SignupService::class),
                $container->get(RoleTaskService::class),
                $container->get(ClaimNotifier::class),
                $container->get(TelegramClient::class)
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
                $container->get(RedemptionRepository::class),
                $container->get(TicketBuyerRepository::class),
                $container->get(GuestRepository::class)
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
                $container->get(PersonEraser::class)
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
                $container->get(BoardService::class),
                $container->get(WelcomeSender::class),
                $container->get(PersonRepository::class),
                $container->get(CommandMenu::class),
                $container->get(LlmClient::class)
            )
        );

        $this->container->singleton(
            TaskTemplateApplier::class,
            fn (Container $container) => new TaskTemplateApplier(
                $container->get(TaskRepository::class)
            )
        );

        $this->container->singleton(
            OrganizeTaskBackfill::class,
            fn (Container $container) => new OrganizeTaskBackfill(
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
                $container->get(StandingCalculator::class),
                $container->get(CreditGrantRepository::class),
                $container->get(CreditGrantNotifier::class),
                $container->get(RoleConditions::class),
                $container->get(PersonEraser::class)
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
                $container->get(TicketRedemptionService::class),
                $container->get(GuestRepository::class),
                $container->get(TicketBuyerRepository::class),
                $container->get(BuyerImport::class),
                $container->get(TicketMail::class)
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

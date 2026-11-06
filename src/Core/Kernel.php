<?php

declare(strict_types=1);

namespace EventCrew\Core;

use EventCrew\Admin\Admin;
use EventCrew\Admin\AiPromptsPage;
use EventCrew\Admin\BotLogPage;
use EventCrew\Admin\BotProfileSettings;
use EventCrew\Admin\DiagnosticsPage;
use EventCrew\Admin\JobsStatus;
use EventCrew\Admin\MessagesPage;
use EventCrew\Admin\NewsletterPage;
use EventCrew\Admin\RosterPage;
use EventCrew\Admin\SettingsPage;
use EventCrew\Admin\TasksPage;
use EventCrew\Admin\TranslationsPage;
use EventCrew\Admin\View;
use EventCrew\Admin\PeoplePage;
use EventCrew\Database\Schema;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\CheckinRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\FollowupRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\NewsletterHistoryRepository;
use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Repositories\NotificationsRepository;
use EventCrew\Repositories\RedemptionRepository;
use EventCrew\Repositories\TakeoverRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TicketBuyerRepository;
use EventCrew\Support\AccessNotifier;
use EventCrew\Support\AssistantKnowledge;
use EventCrew\Support\BackgroundJobs;
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
use EventCrew\Support\FollowupMail;
use EventCrew\Support\FollowupText;
use EventCrew\Support\FreeEntryGate;
use EventCrew\Support\FrictionB1Migration;
use EventCrew\Support\HealthReport;
use EventCrew\Support\EventAiText;
use EventCrew\Support\EventMeshSyncListener;
use EventCrew\Support\EventTaskMover;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\CreditGrantNotifier;
use EventCrew\Support\Mailer;
use EventCrew\Support\MessagePreview;
use EventCrew\Support\MessageAiText;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\NewcomerApproval;
use EventCrew\Support\Newsletter;
use EventCrew\Support\NewsletterCrew;
use EventCrew\Support\NewsletterText;
use EventCrew\Support\NewsletterWriter;
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
use EventCrew\Support\Roles;
use EventCrew\Support\RoleTranslations;
use EventCrew\Support\RosterAssembler;
use EventCrew\Support\SalesDeskGate;
use EventCrew\Support\Scheduler;
use EventCrew\Support\WelcomeSender;
use EventCrew\Support\SlotFreedNotice;
use EventCrew\Support\SignupService;
use EventCrew\Support\SiteTexts;
use EventCrew\Support\StandingNotice;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\TakeoverService;
use EventCrew\Support\TaskCancelledNotice;
use EventCrew\Support\TaskTemplateApplier;
use EventCrew\Support\TelegramLogin;
use EventCrew\Support\TicketMail;
use EventCrew\Support\TicketQr;
use EventCrew\Support\TicketReasons;
use EventCrew\Support\TicketText;
use EventCrew\Support\TranslatedText;
use EventCrew\Support\TranslationJob;
use EventCrew\Support\TranslationRegistry;
use EventCrew\Support\Turnstile;
use EventCrew\Telegram\AssistantService;
use EventCrew\Telegram\BoardDjText;
use EventCrew\Telegram\BoardRefreshListener;
use EventCrew\Telegram\BotLog;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\GroupChat;
use EventCrew\Telegram\BotTexts;
use EventCrew\Telegram\BuyerListReset;
use EventCrew\Telegram\ChatForwarder;
use EventCrew\Telegram\DohResolver;
use EventCrew\Telegram\ManageController;
use EventCrew\Telegram\NewsletterCommand;
use EventCrew\Telegram\SayCommand;
use EventCrew\Telegram\EventsCommand;
use EventCrew\Telegram\FollowupCommand;
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
use EventCrew\Telegram\WebLoginBot;
use EventCrew\MailImport\MailImporter;
use EventCrew\MailImport\MailedListImport;
use EventCrew\Web\CrewAdminAiText;
use EventCrew\Web\CrewAdminController;
use EventCrew\Web\CrewAdminFollowup;
use EventCrew\Web\CrewAdminNewsletter;
use EventCrew\Web\CrewAdminPeople;
use EventCrew\Web\CrewAdminRoster;
use EventCrew\Web\CrewAdminTasks;
use EventCrew\Web\CrewAppPage;
use EventCrew\Web\DoorController;
use EventCrew\Web\FollowupController;
use EventCrew\Web\NewsletterController;
use EventCrew\Web\PrivacyController;
use EventCrew\Web\PwaController;
use EventCrew\Web\SessionPerson;
use EventCrew\Web\SignupController;
use EventCrew\Web\TakeoverController;
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

        // Adds the new placeholders to the message texts organizers saved
        // before they existed (late cancel, no-show, ...). Each step runs once.
        FrictionB1Migration::run();

        // Adds crew members with a verified email to the newsletter. Runs once
        // per site, on the first request after the update, while the setting
        // is on.
        $this->container->get(NewsletterCrew::class)->maybeRun();

        // Turns the bot's four old About/Description fields into the two
        // written-once texts. Runs once per site.
        BotProfileSettings::migrate();

        // Copies the group's chat id out of the board option into its own
        // option (GroupChat). Does nothing once it is set.
        GroupChat::migrate();

        // Adds {description} to the stored reminder texts (email and
        // Telegram). Runs once per site.
        MessageTemplates::migrateReminderDescription();

        // The bot's answers and buttons as edited on the Translations page.
        // Registered on every request: the bot answers on the webhook path.
        BotTexts::boot();

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

        // "Move the tasks too" on an EventMesh event's edit screen.
        $this->container->get(EventTaskMover::class)->boot();

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
        // The end of a second thoughts window, a WP-Cron event.
        $this->container->get(ClaimNotifier::class)->boot();
        $this->container->get(TaskCancelledNotice::class)->boot();

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

        // The post-event email: its sending batches are a cron event, and its
        // counted links and Stop page are opened by logged-out people.
        $this->container->get(FollowupMail::class)->boot();
        $this->container->get(FollowupController::class)->boot();

        // The short DJ or host text under the group board's picture: written
        // by the language model in a single WP-Cron run the board asks for.
        $this->container->get(BoardDjText::class)->boot();

        // "Translate missing" on the Translations page: its batches are a
        // WP-Cron event, so they run with the page closed.
        $this->container->get(TranslationJob::class)->boot();

        // The role names and descriptions in every site language: filled by
        // the language model on the hourly run and right after a save.
        $this->container->get(RoleTranslations::class)->boot();

        // The slow part of a wp-admin save (translations, AI texts, the bot
        // profile on Telegram): queued, then run by the open page's poll or
        // by a WP-Cron run each minute.
        $this->container->get(BackgroundJobs::class)->boot();

        // The AI texts for each event (message templates with the AI tick,
        // and the organizers' own placeholders): written the day before, on
        // the hourly notifications run.
        $this->container->get(EventAiText::class)->boot();

        // The Messages page's AI tick and prompt per kind, and the AI texts
        // they give to EventAiText.
        $this->container->get(MessageAiText::class)->boot();

        // Takeover requests: closed when the holder's place goes away, and
        // answered from an email link that logged-out people open.
        $this->container->get(TakeoverService::class)->boot();
        $this->container->get(TakeoverController::class)->boot();

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

        // The team's OK for a newcomer: asks the organizers when one confirms
        // their email, and makes a person OK when they get a role or a pass.
        $this->container->get(NewcomerApproval::class)->boot();

        // A member who confirms their email joins the newsletter, with no
        // email sent, unless they already have a subscriber row.
        $this->container->get(NewsletterCrew::class)->boot();

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

        // The person's own message when an organizer gives them a role, a
        // one-time pass or organizer rights, from wherever it was given.
        add_action(
            RoleNotifier::GIVEN_HOOK,
            fn (int $personId, string $slug)
                => $this->container->get(AccessNotifier::class)->roleGiven($personId, $slug),
            10,
            2
        );
        add_action(
            RoleNotifier::ORGANIZER_HOOK,
            fn (int $personId) => $this->container->get(AccessNotifier::class)->organizerGiven($personId)
        );
        add_action(
            AccessNotifier::PASS_HOOK,
            fn (int $personId) => $this->container->get(AccessNotifier::class)->passGiven($personId)
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
            NewsletterHistoryRepository::class,
            fn () => new NewsletterHistoryRepository()
        );

        $this->container->singleton(
            NewsletterSubscriberRepository::class,
            fn (Container $container) => new NewsletterSubscriberRepository(
                $container->get(NewsletterHistoryRepository::class)
            )
        );

        $this->container->singleton(
            NewsletterText::class,
            fn (Container $container) => new NewsletterText(
                $container->get(LlmClient::class),
                $container->get(Logger::class)
            )
        );

        $this->container->singleton(
            NewsletterWriter::class,
            fn (Container $container) => new NewsletterWriter(
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
                $container->get(Logger::class),
                $container->get(NewsletterWriter::class),
                $container->get(NewsletterHistoryRepository::class)
            )
        );

        $this->container->singleton(
            FollowupRepository::class,
            fn () => new FollowupRepository()
        );

        $this->container->singleton(
            FollowupMail::class,
            fn (Container $container) => new FollowupMail(
                $container->get(FollowupRepository::class),
                $container->get(TaskRepository::class),
                $container->get(PersonRepository::class),
                $container->get(Mailer::class),
                $container->get(Logger::class),
                $container->get(FollowupText::class),
                $container->get(NewsletterWriter::class),
                $container->get(NewsletterSubscriberRepository::class)
            )
        );

        $this->container->singleton(
            FollowupText::class,
            fn (Container $container) => new FollowupText(
                $container->get(LlmClient::class),
                $container->get(Logger::class)
            )
        );

        $this->container->singleton(
            BoardDjText::class,
            fn (Container $container) => new BoardDjText(
                $container->get(LlmClient::class),
                $container->get(Logger::class),
                $container->get(BoardService::class)
            )
        );

        $this->container->singleton(
            CrewAdminFollowup::class,
            fn (Container $container) => new CrewAdminFollowup($container->get(FollowupText::class))
        );

        $this->container->singleton(
            EventAiText::class,
            fn (Container $container) => new EventAiText(
                $container->get(LlmClient::class),
                $container->get(Logger::class)
            )
        );

        $this->container->singleton(
            MessageAiText::class,
            fn () => new MessageAiText()
        );

        $this->container->singleton(
            CrewAdminAiText::class,
            fn () => new CrewAdminAiText()
        );

        $this->container->singleton(
            FollowupCommand::class,
            fn (Container $container) => new FollowupCommand(
                $container->get(PersonRepository::class),
                $container->get(FollowupText::class),
                $container->get(TelegramClient::class)
            )
        );

        $this->container->singleton(
            FollowupController::class,
            fn (Container $container) => new FollowupController($container->get(FollowupMail::class))
        );

        $this->container->singleton(
            NewsletterCrew::class,
            fn (Container $container) => new NewsletterCrew(
                $container->get(NewsletterSubscriberRepository::class),
                $container->get(PersonRepository::class)
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
            EventsCommand::class,
            fn (Container $container) => new EventsCommand(
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
                $container->get(StandingCalculator::class),
                $container->get(TicketMail::class),
                $container->get(PersonRepository::class)
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
                $container->get(Mailer::class),
                $container->get(StandingCalculator::class)
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
            AccessNotifier::class,
            fn (Container $container) => new AccessNotifier(
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
                $container->get(Newsletter::class),
                $container->get(FollowupMail::class),
                $container->get(TakeoverService::class),
                $container->get(SlotFreedNotice::class)
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
                $container->get(TelegramLogin::class),
                $container->get(TelegramClient::class)
            )
        );

        $this->container->singleton(
            TelegramLogin::class,
            fn (Container $container) => new TelegramLogin(
                $container->get(Logger::class)
            )
        );

        $this->container->singleton(
            WebLoginBot::class,
            fn (Container $container) => new WebLoginBot(
                $container->get(TelegramLogin::class),
                $container->get(PersonRepository::class),
                $container->get(OnboardingService::class),
                $container->get(TelegramClient::class),
                $container->get(Logger::class)
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
                $container->get(CrewAdminNewsletter::class),
                $container->get(CrewAdminFollowup::class),
                $container->get(CrewAdminAiText::class),
                $container->get(NewcomerApproval::class),
                $container->get(BackgroundJobs::class)
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
                $container->get(TicketMail::class),
                $container->get(FollowupMail::class)
            )
        );

        $this->container->singleton(
            TicketMail::class,
            fn (Container $container) => new TicketMail(
                $container->get(TicketBuyerRepository::class),
                $container->get(GuestRepository::class),
                $container->get(TaskRepository::class),
                $container->get(Mailer::class),
                new TicketQr(),
                $container->get(AssignmentRepository::class),
                $container->get(RedemptionRepository::class)
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
                $container->get(ClaimNotifier::class),
                $container->get(TicketMail::class)
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
                $container->get(NotificationsRepository::class),
                $container->get(TakeoverRepository::class)
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
                $container->get(GuestRepository::class),
                $container->get(NewsletterSubscriberRepository::class)
            )
        );

        $this->container->singleton(
            PrivacyEraser::class,
            fn (Container $container) => new PrivacyEraser(
                $container->get(PersonRepository::class),
                $container->get(PersonEraser::class),
                $container->get(TicketBuyerRepository::class),
                $container->get(GuestRepository::class),
                $container->get(NewsletterSubscriberRepository::class)
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
                $container->get(MailedListImport::class),
                $container->get(Logger::class)
            )
        );

        $this->container->singleton(
            MailedListImport::class,
            fn (Container $container) => new MailedListImport(
                $container->get(PersonRepository::class),
                $container->get(BuyerImport::class),
                $container->get(Mailer::class),
                $container->get(GuestRepository::class),
                $container->get(TelegramClient::class)
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
                $container->get(TelegramClient::class),
                $container->get(TicketMail::class)
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
                $container->get(TelegramClient::class),
                $container->get(TakeoverService::class)
            )
        );

        $this->container->singleton(TakeoverRepository::class, fn () => new TakeoverRepository());

        $this->container->singleton(
            TakeoverService::class,
            fn (Container $container) => new TakeoverService(
                $container->get(TakeoverRepository::class),
                $container->get(AssignmentRepository::class),
                $container->get(TaskRepository::class),
                $container->get(PersonRepository::class),
                $container->get(ClaimNotifier::class),
                $container->get(BoardService::class),
                $container->get(TelegramClient::class),
                $container->get(Mailer::class)
            )
        );

        $this->container->singleton(
            TakeoverController::class,
            fn (Container $container) => new TakeoverController($container->get(TakeoverService::class))
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
                $container->get(Mailer::class),
                $container->get(TicketMail::class)
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
                $container->get(RoleConditions::class)
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
                $container->get(SayCommand::class),
                $container->get(EventsCommand::class),
                $container->get(WebLoginBot::class),
                $container->get(BotLog::class),
                $container->get(FollowupCommand::class),
                $container->get(PersonRepository::class),
                $container->get(TelegramClient::class),
                $container->get(BuyerListReset::class),
                $container->get(NewcomerApproval::class)
            )
        );

        $this->container->singleton(
            NewcomerApproval::class,
            fn (Container $container) => new NewcomerApproval(
                $container->get(PersonRepository::class),
                $container->get(Mailer::class),
                $container->get(TelegramClient::class)
            )
        );

        $this->container->singleton(
            BuyerListReset::class,
            fn (Container $container) => new BuyerListReset(
                $container->get(PersonRepository::class),
                $container->get(TicketBuyerRepository::class),
                $container->get(TelegramClient::class)
            )
        );

        $this->container->singleton(
            BotLog::class,
            fn () => new BotLog()
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
                $container->get(ChatForwarder::class),
                $container->get(BotLog::class)
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
                $container->get(TelegramClient::class),
                $container->get(TelegramLogin::class)
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
                $container->get(PersonEraser::class),
                $container->get(AuthTokenRepository::class),
                $container->get(Mailer::class)
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
            TaskCancelledNotice::class,
            fn (Container $container) => new TaskCancelledNotice(
                $container->get(TaskRepository::class),
                $container->get(PersonRepository::class),
                $container->get(Mailer::class),
                $container->get(TicketMail::class)
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
            EventTaskMover::class,
            fn (Container $container) => new EventTaskMover(
                $container->get(TaskRepository::class)
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
                $container->get(TicketMail::class),
                $container->get(FollowupMail::class)
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
            BotLogPage::class,
            fn (Container $container) => new BotLogPage($container->get(View::class))
        );

        $this->container->singleton(
            NewsletterPage::class,
            fn (Container $container) => new NewsletterPage(
                $container->get(View::class),
                $container->get(NewsletterSubscriberRepository::class),
                $container->get(NewsletterHistoryRepository::class)
            )
        );

        $this->container->singleton(
            TranslatedText::class,
            fn (Container $container) => new TranslatedText(
                $container->get(LlmClient::class),
                $container->get(Logger::class)
            )
        );

        // Every text on the Translations page. A part that adds translatable
        // texts adds its provider here.
        $this->container->singleton(
            TranslationRegistry::class,
            function (Container $container): TranslationRegistry {
                $registry = new TranslationRegistry($container->get(TranslatedText::class));
                $botProfile = new BotProfileSettings(
                    $container->get(TelegramClient::class),
                    $container->get(TranslatedText::class)
                );
                $registry->add([$botProfile, 'translationRows']);
                $registry->add([TicketText::class, 'translationRows']);
                $registry->add([TicketReasons::class, 'translationRows']);
                $registry->add([BotTexts::class, 'translationRows']);
                $registry->add([SiteTexts::class, 'translationRows']);
                $registry->add([Roles::class, 'translationRows']);
                $registry->add([MessageTemplates::class, 'translationRows']);

                return $registry;
            }
        );

        $this->container->singleton(
            TranslationJob::class,
            fn (Container $container) => new TranslationJob($container->get(TranslationRegistry::class))
        );

        // The background jobs and what runs each kind.
        $this->container->singleton(
            BackgroundJobs::class,
            function (Container $container): BackgroundJobs {
                $jobs = new BackgroundJobs();
                $jobs->handle(
                    TranslatedText::JOB,
                    fn (array $args): array => $container->get(TranslationRegistry::class)->runJob($args)
                );
                $jobs->handle(
                    EventAiText::JOB,
                    fn (array $args): array => $container->get(AiPromptsPage::class)->rewriteJob($args)
                );
                $jobs->handle(
                    AiPromptsPage::FOLLOWUP_JOB,
                    fn (array $args): array => $container->get(AiPromptsPage::class)->followupJob($args)
                );
                $jobs->handle(
                    AiPromptsPage::NEWSLETTER_JOB,
                    fn (array $args): array => $container->get(AiPromptsPage::class)->newsletterJob($args)
                );
                $jobs->handle(
                    BotProfileSettings::JOB,
                    fn (array $args): array => (new BotProfileSettings(
                        $container->get(TelegramClient::class),
                        $container->get(TranslatedText::class)
                    ))->pushJob($args)
                );
                $jobs->handle(
                    SettingsPage::WEBHOOK_JOB,
                    fn (array $args): array => $container->get(SettingsPage::class)->webhookJob($args)
                );
                $jobs->handle(
                    SettingsPage::BOARD_JOB,
                    fn (array $args): array => $container->get(SettingsPage::class)->boardJob($args)
                );

                return $jobs;
            }
        );

        $this->container->singleton(
            JobsStatus::class,
            fn (Container $container) => new JobsStatus(
                $container->get(BackgroundJobs::class),
                $container->get(TranslationRegistry::class)
            )
        );

        $this->container->singleton(
            RoleTranslations::class,
            fn (Container $container) => new RoleTranslations($container->get(TranslationRegistry::class))
        );

        $this->container->singleton(
            TranslationsPage::class,
            fn (Container $container) => new TranslationsPage(
                $container->get(View::class),
                $container->get(TranslationRegistry::class),
                $container->get(TranslationJob::class)
            )
        );

        $this->container->singleton(
            MessagesPage::class,
            fn (Container $container) => new MessagesPage(
                $container->get(View::class),
                new MessageTemplates($container->get(TranslatedText::class)->inBackground()),
                $container->get(AiPromptsPage::class),
                $container->get(EmailTemplate::class),
                $container->get(LlmClient::class),
                new MessagePreview(
                    $container->get(Logger::class),
                    $container->get(TaskRepository::class),
                    $container->get(TicketMail::class),
                    $container->get(FollowupMail::class),
                    $container->get(Newsletter::class)
                )
            )
        );

        $this->container->singleton(
            AiPromptsPage::class,
            fn (Container $container) => new AiPromptsPage(
                $container->get(EventAiText::class),
                $container->get(CrewAdminFollowup::class),
                $container->get(CrewAdminNewsletter::class),
                $container->get(FollowupMail::class)
            )
        );

        $this->container->singleton(
            Admin::class,
            fn (Container $container) => new Admin($container)
        );
    }
}

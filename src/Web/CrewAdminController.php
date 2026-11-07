<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\BackgroundJobs;
use EventCrew\Support\CreditGrantNotifier;
use EventCrew\Support\Dates;
use EventCrew\Support\DoorLinkSender;
use EventCrew\Support\EventName;
use EventCrew\Support\JoinAnswers;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MarkedBy;
use EventCrew\Support\MessageActions;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\NewcomerApproval;
use EventCrew\Support\RoleNotifier;
use EventCrew\Support\RoleTaskService;
use EventCrew\Support\Roles;
use EventCrew\Support\RosterAssembler;
use EventCrew\Support\SalesDeskGate;
use EventCrew\Support\WebSession;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\TelegramClient;

/**
 * The Admin tab on the crew page: organizer actions that the bot also offers,
 * done from the web.
 *
 * Every action goes through guard() first. guard() reads the signed-in person
 * fresh from the people table and checks isOrganizer and the CSRF token there;
 * nothing in the request says who may do what. A signed-in person who is not an
 * organizer and still reaches an admin action has sent a request the page never
 * offered them, so the action is not run, their account is blocked, and every
 * organizer is told. A request with no valid session is refused and nothing
 * else happens: there is nobody to block, and alerting on it would only let
 * strangers send the organizers mail.
 *
 * The actions reuse the services behind the bot's commands: the sales desk link
 * (the sales desk button), the board repost (/board), the credit grant and its notice (🎁 Give credits),
 * and the roster with its status write (/roster), and the bot's roles for the restricted
 * roles. "Add a person to a task" is the bot's "Add someone" button: it puts
 * anyone on any upcoming task, even someone who is not a crew member yet.
 * The People screen (CrewAdminPeople) is the wp-admin People page without the
 * organizer flag and without deleting a person. The Tasks screen
 * (CrewAdminTasks) is the wp-admin Tasks page, plus editing one role with the
 * fields of wp-admin Settings > Roles.
 */
final class CrewAdminController
{
    public const SALES_ACTION = 'eventcrew_web_admin_sales';
    public const BOARD_ACTION = 'eventcrew_web_admin_board';
    public const GIFT_ACTION = 'eventcrew_web_admin_gift';
    public const MARK_ACTION = 'eventcrew_web_admin_mark';
    public const ROLES_ACTION = 'eventcrew_web_admin_roles';
    public const ADD_ACTION = 'eventcrew_web_admin_add';
    public const ROSTER_ACTION = 'eventcrew_web_admin_roster';
    public const DOOR_ACTION = 'eventcrew_web_admin_door';
    public const GUEST_ACTION = 'eventcrew_web_admin_guest';
    public const BUYERS_ACTION = 'eventcrew_web_admin_buyers';
    public const MAIL_ACTION = 'eventcrew_web_admin_mail';
    public const PERSON_ACTION = 'eventcrew_web_admin_person';
    public const PASS_ACTION = 'eventcrew_web_admin_pass';
    public const UNBAN_ACTION = 'eventcrew_web_admin_unban';
    public const TASK_ACTION = 'eventcrew_web_admin_task';
    public const TASK_DELETE_ACTION = 'eventcrew_web_admin_task_delete';
    public const TEMPLATE_ACTION = 'eventcrew_web_admin_template';
    public const OPEN_TASK_ACTION = 'eventcrew_web_admin_open_task';
    public const ROLE_ACTION = 'eventcrew_web_admin_role';
    public const NEWSLETTER_TEXT_ACTION = 'eventcrew_web_admin_newsletter_text';
    public const NEWSLETTER_STOP_ACTION = 'eventcrew_web_admin_newsletter_stop';
    public const FOLLOWUP_TEXT_ACTION = 'eventcrew_web_admin_followup_text';
    public const AI_TEXT_ACTION = 'eventcrew_web_admin_ai_text';
    public const NEWCOMER_ACTION = 'eventcrew_web_admin_newcomer';
    public const TEXT_STATUS_ACTION = 'eventcrew_web_admin_text_status';

    /**
     * The action names boot() hooks. Kept apart from actionLabels(), because
     * boot() runs on plugins_loaded and the labels call __(), which WordPress
     * 6.7+ warns about before init.
     */
    private const ACTIONS = [
        self::SALES_ACTION,
        self::BOARD_ACTION,
        self::GIFT_ACTION,
        self::MARK_ACTION,
        self::ROLES_ACTION,
        self::ADD_ACTION,
        self::ROSTER_ACTION,
        self::DOOR_ACTION,
        self::GUEST_ACTION,
        self::BUYERS_ACTION,
        self::MAIL_ACTION,
        self::PERSON_ACTION,
        self::PASS_ACTION,
        self::UNBAN_ACTION,
        self::TASK_ACTION,
        self::TASK_DELETE_ACTION,
        self::TEMPLATE_ACTION,
        self::OPEN_TASK_ACTION,
        self::ROLE_ACTION,
        self::NEWSLETTER_TEXT_ACTION,
        self::NEWSLETTER_STOP_ACTION,
        self::FOLLOWUP_TEXT_ACTION,
        self::AI_TEXT_ACTION,
        self::NEWCOMER_ACTION,
        self::TEXT_STATUS_ACTION,
    ];

    /** guard(): an organizer with a valid CSRF token. Run the action. */
    public const OK = 'ok';

    /** guard(): no valid session, an organizer with a bad token, or not a POST. */
    public const REFUSED = 'refused';

    /** guard(): a signed-in non-organizer. Blocked, and the organizers told. */
    public const FORGED = 'forged';

    /** The statuses the roster buttons write, by the value the form posts. */
    private const MARKS = [
        'arrived' => AssignmentStatus::ARRIVED,
        'completed' => AssignmentStatus::COMPLETED,
        'no_show' => AssignmentStatus::NO_SHOW,
    ];

    /** The roster rows that get buttons: the same ones /roster offers them for. */
    private const MARKABLE = [AssignmentStatus::SIGNED_UP, AssignmentStatus::ARRIVED];

    public function __construct(
        private readonly PersonRepository $people,
        private readonly SessionPerson $session,
        private readonly SalesDeskGate $salesDesk,
        private readonly DoorLinkSender $doorLinks,
        private readonly BoardService $board,
        private readonly CreditGrantRepository $grants,
        private readonly CreditGrantNotifier $grantNotifier,
        private readonly RosterAssembler $roster,
        private readonly AssignmentRepository $assignments,
        private readonly TelegramClient $telegram,
        private readonly Mailer $mailer,
        private readonly Logger $logger,
        private readonly RoleTaskService $roleTasks,
        private readonly CrewAdminRoster $rosterScreen,
        private readonly CrewAdminPeople $peopleScreen,
        private readonly CrewAdminTasks $tasksScreen,
        private readonly ?CrewAdminNewsletter $newsletterScreen = null,
        private readonly ?CrewAdminFollowup $followupScreen = null,
        private readonly ?CrewAdminAiText $aiTextScreen = null,
        private readonly ?NewcomerApproval $newcomers = null,
        private readonly ?BackgroundJobs $jobs = null
    ) {
    }

    public function boot(): void
    {
        // The nopriv hooks too: organizers sign in to the crew page by email,
        // not as WordPress users. guard() is what decides, not the hook.
        foreach (self::ACTIONS as $action) {
            add_action('wp_ajax_' . $action, [$this, 'dispatch']);
            add_action('wp_ajax_nopriv_' . $action, [$this, 'dispatch']);
        }
    }

    /**
     * Each admin action and its plain name, for the alert the organizers get.
     *
     * @return array<string, string>
     */
    public static function actionLabels(): array
    {
        return [
            self::SALES_ACTION => __('Send me the sales desk link', 'eventcrew'),
            self::BOARD_ACTION => __('Post a new board in the Telegram group', 'eventcrew'),
            self::GIFT_ACTION => __('Give a credit', 'eventcrew'),
            self::MARK_ACTION => __('Mark attendance', 'eventcrew'),
            self::ROLES_ACTION => __('Change a member’s roles', 'eventcrew'),
            self::ADD_ACTION => __('Add a person to a task', 'eventcrew'),
            self::ROSTER_ACTION => __('Update attendance', 'eventcrew'),
            self::DOOR_ACTION => __('Door list', 'eventcrew'),
            self::GUEST_ACTION => __('Guest list', 'eventcrew'),
            self::BUYERS_ACTION => __('Holvi ticket buyers', 'eventcrew'),
            self::MAIL_ACTION => __('Check the mailbox now', 'eventcrew'),
            self::PERSON_ACTION => __('Add or edit a person', 'eventcrew'),
            self::PASS_ACTION => __('Grant a one-time pass', 'eventcrew'),
            self::UNBAN_ACTION => __('Unban', 'eventcrew'),
            self::TASK_ACTION => __('Add or edit a task', 'eventcrew'),
            self::TASK_DELETE_ACTION => __('Delete a task', 'eventcrew'),
            self::TEMPLATE_ACTION => __('Create an event\'s tasks', 'eventcrew'),
            self::OPEN_TASK_ACTION => __('Send open-task email now', 'eventcrew'),
            self::ROLE_ACTION => __('Edit a role', 'eventcrew'),
            self::NEWSLETTER_TEXT_ACTION => __('Text for the next announcement', 'eventcrew'),
            self::NEWSLETTER_STOP_ACTION => __('Do not send this announcement', 'eventcrew'),
            self::FOLLOWUP_TEXT_ACTION => __('Text for the next post-event email', 'eventcrew'),
            self::AI_TEXT_ACTION => __('Change an AI text for an event', 'eventcrew'),
            self::NEWCOMER_ACTION => __('Say OK or No to a newcomer', 'eventcrew'),
            self::TEXT_STATUS_ACTION => __('Check whether a text is translated', 'eventcrew'),
        ];
    }

    /**
     * The one permission check every admin action passes through.
     *
     * Order matters. No valid session: refused, nothing else, since there is no
     * person to block. A signed-in person who is not an organizer: forged,
     * whatever the token says - the page never gave them these forms, so a
     * request here is not an accident. They are blocked and the organizers are
     * told. An organizer with a bad token: refused, never blocked; a stale page
     * is the likely cause, and organizers are not blocked by this path.
     *
     * @return array{code: string, person: ?Person}
     */
    public function guard(string $action, string $csrf, string $ip): array
    {
        $person = $this->session->current();

        if (null === $person) {
            return ['code' => self::REFUSED, 'person' => null];
        }

        if (! $person->isOrganizer) {
            $this->banForgedRequest($person, $action, $ip);

            return ['code' => self::FORGED, 'person' => $person];
        }

        if (! WebSession::verifyCsrf($person->id, $csrf)) {
            return ['code' => self::REFUSED, 'person' => $person];
        }

        return ['code' => self::OK, 'person' => $person];
    }

    /**
     * Guards, then runs one admin action. Returns the guard code and, when the
     * action ran, its notice code for the page.
     *
     * Only a POST is acted on. The session cookie is SameSite=Lax, which a
     * browser still sends on a plain link from another site; a GET is refused
     * before the guard so a link somebody else planted cannot get a member
     * blocked.
     *
     * @param array<string, mixed> $post The request body, unslashed.
     * @return array{code: string, notice: string}
     */
    public function handle(string $action, string $method, array $post, string $ip): array
    {
        if ('POST' !== strtoupper($method) || ! isset(self::actionLabels()[$action])) {
            return ['code' => self::REFUSED, 'notice' => ''];
        }

        $csrf = isset($post['csrf']) ? sanitize_text_field((string) $post['csrf']) : '';
        $guard = $this->guard($action, $csrf, $ip);

        if (self::OK !== $guard['code'] || null === $guard['person']) {
            return ['code' => $guard['code'], 'notice' => ''];
        }

        $organizer = $guard['person'];

        $notice = match ($action) {
            self::TEXT_STATUS_ACTION => '',
            self::SALES_ACTION => $this->sendSalesLink($organizer),
            self::BOARD_ACTION => $this->postBoard(),
            self::GIFT_ACTION => $this->giveCredit(
                $organizer,
                (int) ($post['person_id'] ?? 0),
                self::wholeNumber($post['credits'] ?? ''),
                sanitize_text_field((string) ($post['reason'] ?? ''))
            ),
            self::PERSON_ACTION => $this->peopleScreen->save(
                (int) ($post['person_id'] ?? 0),
                sanitize_email((string) ($post['email'] ?? '')),
                sanitize_text_field((string) ($post['display_name'] ?? '')),
                sanitize_textarea_field((string) ($post['notes'] ?? '')),
                is_array($post['roles'] ?? null) ? array_map('sanitize_key', array_map('strval', $post['roles'])) : [],
                $organizer->name()
            ),
            self::PASS_ACTION => $this->peopleScreen->grantPass((int) ($post['person_id'] ?? 0)),
            self::UNBAN_ACTION => $this->peopleScreen->unban((int) ($post['person_id'] ?? 0)),
            self::TASK_ACTION => $this->tasksScreen->save($post),
            self::TASK_DELETE_ACTION => $this->tasksScreen->delete((int) ($post['task_id'] ?? 0)),
            self::TEMPLATE_ACTION => $this->tasksScreen->applyTemplate((int) ($post['template_event'] ?? 0)),
            self::OPEN_TASK_ACTION => $this->tasksScreen->sendOpenTaskEmail(),
            self::ROLE_ACTION => $this->tasksScreen->saveRole($post),
            self::NEWSLETTER_TEXT_ACTION => null === $this->newsletterScreen ? '' : $this->newsletterScreen->saveText(
                $organizer->id,
                sanitize_key((string) ($post['op'] ?? '')),
                sanitize_textarea_field((string) ($post['newsletter_text'] ?? '')),
                true,
                ! empty($post['replace'])
            ),
            self::NEWSLETTER_STOP_ACTION => null === $this->newsletterScreen
                ? ''
                : $this->newsletterScreen->stop((int) ($post['plan_id'] ?? 0)),
            self::FOLLOWUP_TEXT_ACTION => null === $this->followupScreen ? '' : $this->followupScreen->saveText(
                $organizer->id,
                sanitize_key((string) ($post['variant'] ?? '')),
                sanitize_key((string) ($post['op'] ?? '')),
                sanitize_textarea_field((string) ($post['followup_text'] ?? '')),
                true,
                ! empty($post['replace'])
            ),
            self::AI_TEXT_ACTION => null === $this->aiTextScreen ? '' : $this->aiTextScreen->save($organizer, $post),
            self::NEWCOMER_ACTION => $this->decideNewcomer(
                $organizer,
                (int) ($post['person_id'] ?? 0),
                sanitize_key((string) ($post['decision'] ?? ''))
            ),
            self::ROLES_ACTION => $this->saveRoles(
                (int) ($post['person_id'] ?? 0),
                isset($post['add_role']) ? [sanitize_key((string) $post['add_role'])] : null,
                is_array($post['roles'] ?? null) ? array_map('sanitize_key', array_map('strval', $post['roles'])) : [],
                $organizer->name()
            ),
            self::ADD_ACTION => $this->addPerson(
                $organizer,
                is_array($post['task_ids'] ?? null) ? array_map('intval', $post['task_ids']) : [],
                (string) ($post['name'] ?? ''),
                (string) ($post['email'] ?? ''),
                '1' === (string) ($post['counts_credit'] ?? ''),
                (int) ($post['person_id'] ?? 0)
            ),
            self::ROSTER_ACTION, self::DOOR_ACTION, self::GUEST_ACTION, self::BUYERS_ACTION, self::MAIL_ACTION
                => $this->rosterScreenAction($organizer, $action, $post),
            default => $this->markAttendance(
                $organizer,
                (int) ($post['assignment_id'] ?? 0),
                sanitize_key((string) ($post['mark'] ?? ''))
            ),
        };

        return ['code' => self::OK, 'notice' => $notice];
    }

    /**
     * The admin-ajax entry point: reads the request, hands it to handle(), and
     * answers. The untested boundary, as in SignupController: it ends in a
     * redirect or wp_die().
     */
    public function dispatch(): void
    {
        // Guarded by the session cookie and the CSRF token in guard(), not by a
        // WordPress nonce; an organizer here is not a WordPress user.
        // phpcs:disable WordPress.Security.NonceVerification
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        $post = wp_unslash($_POST);
        // phpcs:enable WordPress.Security.NonceVerification

        $result = $this->handle($action, $method, is_array($post) ? $post : [], $this->clientIp());

        if (self::FORGED === $result['code']) {
            wp_die(
                esc_html__('You are not allowed to do this. Your account is blocked. Talk to an organizer.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                '',
                ['response' => 403]
            );
        }

        if (self::OK !== $result['code']) {
            wp_die(esc_html__('You are not allowed to do this.', 'eventcrew'), '', ['response' => 403]);
        }

        if (self::TEXT_STATUS_ACTION === $action) {
            wp_send_json_success($this->textStatus());
        }

        wp_safe_redirect(add_query_arg('eventcrew_notice', $result['notice'], $this->safeRedirect()));

        exit;
    }

    /**
     * The crew app's poll while a text shows "Translating…": runs one
     * queued background job (as the wp-admin poll does, so the text does
     * not wait for WP-Cron) and answers which of the Admin tab's texts are
     * still being translated. The page fetches the stored texts again for
     * each one that is no longer in the list.
     *
     * The job runs on even when the page is left halfway, as in
     * JobsStatus::answer().
     *
     * @return array{active: list<string>}
     */
    public function textStatus(): array
    {
        if (null !== $this->jobs && BackgroundJobs::activeCount() > 0) {
            ignore_user_abort(true);
            $this->jobs->runOne();
        }

        $active = array_filter(
            CrewAdminFollowup::jobTargets(),
            static fn (string $target): bool => BackgroundJobs::isActive(BackgroundJobs::latest($target))
        );

        return ['active' => array_values($active)];
    }

    /**
     * "Send me the sales desk link": the link for the next event night, sent to
     * the organizer by email and, when they use the bot, by Telegram - the same
     * send the automatic links use.
     */
    public function sendSalesLink(Person $organizer): string
    {
        $date = $this->salesDesk->nextNight();
        $code = $this->salesDesk->check($organizer, $date);

        if (SalesDeskGate::OK !== $code) {
            return 'admin_sales_' . $code;
        }

        return $this->doorLinks->send($organizer, $date) ? 'admin_sales_sent' : 'admin_sales_' . SalesDeskGate::CLOSED;
    }

    /** "Post a new board in the Telegram group": the /board repost. */
    /**
     * "OK" or "No" for a newcomer in the Admin tab's waiting list. The first
     * organizer to answer decides (NewcomerApproval::decide()).
     */
    public function decideNewcomer(Person $organizer, int $personId, string $decision): string
    {
        if (null === $this->newcomers || ! in_array($decision, ['ok', 'no'], true)) {
            return 'admin_no_person';
        }

        return match ($this->newcomers->decide($personId, 'ok' === $decision, $organizer)) {
            NewcomerApproval::DECIDED => 'ok' === $decision ? 'admin_newcomer_ok' : 'admin_newcomer_no',
            NewcomerApproval::ALREADY => 'admin_newcomer_already',
            default => 'admin_no_person',
        };
    }

    /**
     * The newcomers waiting for an OK, for the Admin tab, each with what
     * they said when joining (JoinAnswers::line()), the same line the
     * organizers' "wants to join" email and DM carry.
     *
     * @return list<array{id: int, name: string, email: string, since: string, answers: string}>
     */
    private function newcomerRows(): array
    {
        return array_map(
            static fn (Person $person): array => [
                'id' => $person->id,
                'name' => $person->name(),
                'email' => $person->email,
                'since' => '' === $person->createdAt ? '' : Dates::dayLabel(substr($person->createdAt, 0, 10)),
                'answers' => JoinAnswers::line($person),
            ],
            $this->people->waitingNewcomers()
        );
    }

    public function postBoard(): string
    {
        if (! $this->telegram->isConfigured() || ! $this->board->hasHome()) {
            return 'admin_board_no_group';
        }

        $this->board->repost();

        return 'admin_board_posted';
    }

    /**
     * "Give a credit": the 🎁 Give credits grant, recorded with the organizer's person id
     * in the credit log, and the member told the way the bot's 🎁 Give credits tells them. Any
     * whole number from 1 up, as on the wp-admin People page.
     */
    public function giveCredit(Person $organizer, int $personId, int $credits, string $reason): string
    {
        if ($credits < 1) {
            return 'admin_bad_credits';
        }

        $member = $personId > 0 ? $this->people->find($personId) : null;

        if (null === $member || $member->isAnonymized()) {
            return 'admin_no_person';
        }

        $note = '' !== $reason ? $reason : 'gift via crew page';

        $this->grants->record($member->id, $credits, $note, 0, $organizer->id);
        $this->grantNotifier->notify($member, $credits);

        return 'admin_credit_given';
    }

    /**
     * A posted whole number, or 0 when the value is anything else ("1.5",
     * "2e3", "abc", an array), so giveCredit() refuses it.
     */
    private static function wholeNumber(mixed $value): int
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return '' !== $value && ctype_digit($value) && strlen($value) <= 9 ? (int) $value : 0;
    }

    /**
     * One roster button: Arrived, Completed or No-show, written through the
     * same status write the bot's /roster buttons and the wp-admin roster use,
     * recorded with the organizer's person id.
     */
    public function markAttendance(Person $organizer, int $assignmentId, string $mark): string
    {
        if (! isset(self::MARKS[$mark])) {
            return 'admin_no_entry';
        }

        $assignment = $assignmentId > 0 ? $this->assignments->find($assignmentId) : null;

        if (null === $assignment) {
            return 'admin_no_entry';
        }

        $this->assignments->setStatus($assignment->id, self::MARKS[$mark], $organizer->id, MarkedBy::PERSON);

        return 'admin_marked';
    }

    /**
     * The Roster screen's forms (attendance, door list, guests, ticket buyers,
     * mailbox), handed to CrewAdminRoster. Each form posts the date it was
     * drawn for as roster_date and the part it wants as op.
     *
     * @param array<string, mixed> $post The request body, unslashed.
     */
    private function rosterScreenAction(Person $organizer, string $action, array $post): string
    {
        $date = sanitize_text_field((string) ($post['roster_date'] ?? ''));
        $op = sanitize_key((string) ($post['op'] ?? ''));

        return match ($action) {
            self::ROSTER_ACTION => isset($post['mark_all'])
                ? $this->rosterScreen->markAll(
                    $organizer,
                    $date,
                    (int) ($post['task_id'] ?? 0),
                    sanitize_key((string) $post['mark_all'])
                )
                : $this->rosterScreen->saveRoster(
                    $organizer,
                    $date,
                    is_array($post['status'] ?? null) ? $post['status'] : [],
                    is_array($post['shown'] ?? null) ? $post['shown'] : []
                ),
            self::DOOR_ACTION => match ($op) {
                'toggle' => $this->rosterScreen->toggleFreeEntry($date),
                'redeem' => $this->rosterScreen->redeem($date, (int) ($post['person_id'] ?? 0)),
                'unredeem' => $this->rosterScreen->unredeem((int) ($post['redemption_id'] ?? 0)),
                default => 'admin_change_failed',
            },
            self::GUEST_ACTION => match ($op) {
                'save' => $this->rosterScreen->saveGuest(
                    $organizer,
                    $date,
                    (int) ($post['guest_id'] ?? 0),
                    sanitize_text_field((string) ($post['guest_name'] ?? '')),
                    (int) ($post['guest_plus_ones'] ?? 0),
                    sanitize_text_field((string) ($post['guest_note'] ?? '')),
                    // Null when the form had no email field: the address stays.
                    isset($post['guest_email'])
                        ? trim(sanitize_text_field((string) $post['guest_email']))
                        : null
                ),
                'remove' => $this->rosterScreen->removeGuest((int) ($post['guest_id'] ?? 0)),
                'mail_rest' => $this->rosterScreen->sendTicketsRest($date, 'guests'),
                default => 'admin_change_failed',
            },
            self::BUYERS_ACTION => match ($op) {
                // The textarea variant keeps the line breaks: one name per line.
                'import' => $this->rosterScreen->importBuyers(
                    $date,
                    sanitize_textarea_field((string) ($post['buyer_names'] ?? ''))
                ),
                'reset' => $this->rosterScreen->resetBuyers($date),
                'mail_test' => $this->rosterScreen->sendTicketTest($date, $organizer),
                'mail_rest' => $this->rosterScreen->sendTicketsRest($date, 'buyers'),
                'mail_cancel' => $this->rosterScreen->cancelTicketSend(),
                'followup_test' => $this->rosterScreen->sendFollowupTest($date, $organizer),
                'followup_stop' => $this->rosterScreen->stopFollowup($date),
                default => 'admin_change_failed',
            },
            default => $this->rosterScreen->checkMail(),
        };
    }

    /**
     * "Roles": $add holds one role to add to the member's roles (the "Give a
     * role" form); null means $roles is the member's full new set of
     * restricted roles (the checkboxes). Only active restricted roles are
     * written; a role the member holds that is archived or no longer
     * restricted is kept as it is, since the page did not show it.
     *
     * @param list<string>|null $add
     * @param list<string> $roles
     */
    public function saveRoles(int $personId, ?array $add, array $roles, string $giverName = ''): string
    {
        $member = $personId > 0 ? $this->people->find($personId) : null;

        if (null === $member || $member->isAnonymized()) {
            return 'admin_no_person';
        }

        $shown = array_keys(self::assignableRoles());
        $kept = array_values(array_diff($member->roleSlugs, $shown));
        $chosen = null === $add ? $roles : array_merge($member->roleSlugs, $add);

        $roles = array_merge($kept, array_intersect($chosen, $shown));
        $this->people->setRoles($member->id, $roles);
        RoleNotifier::rolesChanged($member->id, $member->roleSlugs, $roles, $giverName);

        return 'admin_roles_saved';
    }

    /**
     * "Add a person to a task", for each ticked task: see
     * RoleTaskService::addPerson(). The person is found or created on the
     * first task; every later task reuses that same person. One task answers
     * with that task's own notice; several answer with "added to N of M".
     *
     * @param list<int> $taskIds
     */
    public function addPerson(
        Person $organizer,
        array $taskIds,
        string $name,
        string $email,
        bool $countsCredit,
        int $personId = 0
    ): string {
        $taskIds = array_values(array_unique(array_filter($taskIds, static fn (int $id): bool => $id > 0)));

        if ([] === $taskIds) {
            return 'admin_add_no_task';
        }

        $added = 0;
        $emailed = false;
        $last = null;

        foreach ($taskIds as $taskId) {
            $last = $this->roleTasks->addPerson($organizer, $taskId, $name, $email, $countsCredit, $personId);

            if (null === $last['person'] && RoleTaskService::NOT_RESTRICTED !== $last['code']) {
                // Nobody to add (no name, bad email, member not found): no
                // other task would go differently.
                return 'admin_add_' . $last['code'];
            }

            if (null !== $last['person']) {
                $personId = $last['person']->id;
            }

            if (RoleTaskService::ADDED === $last['code']) {
                ++$added;
                $emailed = $emailed || $last['emailed'];
            }
        }

        if (1 === count($taskIds)) {
            if (RoleTaskService::ADDED === $last['code']) {
                return $emailed ? 'admin_add_added_mail' : 'admin_add_added';
            }

            return 'admin_add_' . $last['code'];
        }

        return sprintf('admin_add_many_%s%d_%d', $emailed ? 'mail_' : '', $added, count($taskIds));
    }

    /**
     * The text for an 'admin_add_many_[mail_]<added>_<ticked>' notice, or ''
     * when the code is not one.
     */
    private static function addManyText(string $code): string
    {
        if (1 !== preg_match('/^admin_add_many_(mail_)?(\d+)_(\d+)$/', $code, $m)) {
            return '';
        }

        $added = (int) $m[2];
        $ticked = (int) $m[3];
        $parts = [
            sprintf(
                /* translators: 1: tasks the person was added to, 2: tasks ticked */
                __('Added to %1$d of %2$d tasks.', 'eventcrew'),
                $added,
                $ticked
            ),
        ];

        if ($added < $ticked) {
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            $parts[] = __('The others were skipped: they were already on the task, the task was full, or it is no longer available.', 'eventcrew');
        }

        if ('' !== $m[1]) {
            $parts[] = __('They got an email with the ticket for each task they were added to.', 'eventcrew');
        } elseif ($added > 0) {
            $parts[] = __('They have no email address here, so nothing was sent.', 'eventcrew');
        }

        return implode(' ', $parts);
    }

    /**
     * What the Admin tab shows, or null for anyone who is not an organizer -
     * the template draws no tab and no panel then.
     *
     * The Roster screen shows $rosterDate when it has tasks; null reads the
     * date from the page address (?roster_date=), which the date picker sets.
     *
     * @return array<string, mixed>|null
     */
    public function viewFor(?Person $person, ?string $rosterDate = null): ?array
    {
        if (null === $person || ! $person->isOrganizer) {
            return null;
        }

        if (null === $rosterDate) {
            // A read-only choice of which date to show; nothing is written.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $rosterDate = isset($_GET['roster_date']) ? sanitize_text_field(wp_unslash($_GET['roster_date'])) : '';
        }

        $dates = $this->rosterScreen->dates((string) $rosterDate);
        $date = $dates['date'];

        return [
            'sales_action' => self::SALES_ACTION,
            'board_action' => self::BOARD_ACTION,
            'gift_action' => self::GIFT_ACTION,
            'mark_action' => self::MARK_ACTION,
            'members' => $this->memberChoices(),
            'roster_label' => '' === $date ? '' : Dates::dayLabel($date),
            'roster' => '' === $date ? [] : $this->rosterRows($date),
            'roster_date' => $date,
            'roster_upcoming' => $dates['upcoming'],
            'roster_past' => $dates['past'],
            'roster_prev' => $dates['prev'],
            'roster_next' => $dates['next'],
            'roster_picker_label' => $dates['label'],
            'roster_action' => self::ROSTER_ACTION,
            'door_action' => self::DOOR_ACTION,
            'guest_action' => self::GUEST_ACTION,
            'buyers_action' => self::BUYERS_ACTION,
            'mail_action' => self::MAIL_ACTION,
            'statuses' => CrewAdminRoster::statusChoices(),
            'max_plus_ones' => GuestRepository::MAX_PLUS_ONES,
            'night' => $this->rosterScreen->view($date),
            'roles_action' => self::ROLES_ACTION,
            'add_action' => self::ADD_ACTION,
            'roles' => self::assignableRoles(),
            'add_tasks' => $this->addTaskChoices(),
            // Last, so its reads come after everything above.
            'person_action' => self::PERSON_ACTION,
            'pass_action' => self::PASS_ACTION,
            'unban_action' => self::UNBAN_ACTION,
            'people' => $this->peopleScreen->view(...self::peopleQuery()),
            // After People, so the reads the existing screens make come first.
            'task_action' => self::TASK_ACTION,
            'task_delete_action' => self::TASK_DELETE_ACTION,
            'template_action' => self::TEMPLATE_ACTION,
            'open_task_action' => self::OPEN_TASK_ACTION,
            'role_action' => self::ROLE_ACTION,
            'tasks' => $this->tasksScreen->view(...self::tasksQuery()),
            'text_status_action' => self::TEXT_STATUS_ACTION,
        ] + (null === $this->newsletterScreen ? [] : [
            'newsletter_text_action' => self::NEWSLETTER_TEXT_ACTION,
            'newsletter_stop_action' => self::NEWSLETTER_STOP_ACTION,
            'newsletter' => $this->newsletterScreen->view(),
        ]) + (null === $this->followupScreen ? [] : [
            'followup_text_action' => self::FOLLOWUP_TEXT_ACTION,
            'followup' => $this->followupScreen->view(),
        ]) + (null === $this->aiTextScreen ? [] : [
            'ai_text_action' => self::AI_TEXT_ACTION,
            'ai_texts' => $this->aiTextScreen->view(),
        ]) + (null === $this->newcomers ? [] : [
            'newcomer_action' => self::NEWCOMER_ACTION,
            'newcomers' => $this->newcomerRows(),
        ]);
    }

    /**
     * The Tasks screen's date, open task and open role from the page address
     * (?tasks_date=, ?tasks_task=, ?tasks_role=). Read-only choices of what to
     * show; nothing is written.
     *
     * @return array{0: string, 1: int, 2: string}
     */
    private static function tasksQuery(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $date = isset($_GET['tasks_date']) ? sanitize_text_field(wp_unslash($_GET['tasks_date'])) : '';
        $taskId = isset($_GET['tasks_task']) ? absint($_GET['tasks_task']) : 0;
        $role = isset($_GET['tasks_role']) ? sanitize_key(wp_unslash($_GET['tasks_role'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return [(string) $date, $taskId, (string) $role];
    }

    /**
     * The People screen's search, list page and open person from the page
     * address (?people_q=, ?people_page=, ?person_id=). Read-only choices of
     * what to show; nothing is written.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private static function peopleQuery(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $search = isset($_GET['people_q']) ? sanitize_text_field(wp_unslash($_GET['people_q'])) : '';
        $page = isset($_GET['people_page']) ? absint($_GET['people_page']) : 1;
        $personId = isset($_GET['person_id']) ? absint($_GET['person_id']) : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return [(string) $search, max(1, $page), $personId];
    }

    /**
     * The restricted roles a member can be given here, slug => label. Organize
     * is not one: it is the organizer flag, set in wp-admin or with /people in the bot.
     *
     * @return array<string, string>
     */
    private static function assignableRoles(): array
    {
        $roles = [];

        foreach (Roles::assignable() as $role) {
            $roles[$role['slug']] = $role['label'];
        }

        return $roles;
    }

    /**
     * The upcoming tasks a person can be added to, id => "Role — event, day".
     *
     * @return array<int, string>
     */
    private function addTaskChoices(): array
    {
        $choices = [];

        foreach ($this->roleTasks->addableTasks() as $task) {
            $choices[$task->id] = sprintf(
                '%s — %s, %s',
                $task->roleDisplay(),
                null === $task->eventPostId
                    ? $task->eventName()
                    : EventName::oneLine($task->eventPostId, EventName::lang(), $task->eventName()),
                Dates::dayLabel($task->taskDate)
            );
        }

        return $choices;
    }

    /**
     * The human text for an admin notice code, or '' if unknown. Reached from
     * SignupController::noticeText() for any code starting "admin_".
     */
    public static function noticeText(string $code): string
    {
        $map = [
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            'admin_sales_sent' => __('Sent. Check your email, or Telegram if you use the bot.', 'eventcrew'),
            'admin_board_posted' => __('A new board is posted in the Telegram group.', 'eventcrew'),
            'admin_board_no_group' => __('No Telegram group is set up for the board yet.', 'eventcrew'),
            'admin_credit_given' => __('Credit given. The member is told.', 'eventcrew'),
            'admin_bad_credits' => __('Pick a number of credits, 1 or more.', 'eventcrew'),
            'admin_no_person' => __('That member was not found.', 'eventcrew'),
            'admin_marked' => __('Attendance saved.', 'eventcrew'),
            'admin_no_entry' => __('That roster entry was not found.', 'eventcrew'),
            'admin_roles_saved' => __('Roles saved.', 'eventcrew'),
            'admin_add_no_task' => __('Tick at least one task.', 'eventcrew'),
            'admin_newcomer_ok' => __('OK given. They can take tasks now, and they are told.', 'eventcrew'),
            'admin_newcomer_no' => __('No given. They are not told.', 'eventcrew'),
            'admin_newcomer_already' => __('Another organizer already answered for this person.', 'eventcrew'),
        ];

        if (! isset($map[$code]) && '' !== CrewAdminRoster::noticeText($code)) {
            return CrewAdminRoster::noticeText($code);
        }

        if (! isset($map[$code]) && '' !== CrewAdminPeople::noticeText($code)) {
            return CrewAdminPeople::noticeText($code);
        }

        if (! isset($map[$code]) && '' !== CrewAdminNewsletter::noticeText($code)) {
            return CrewAdminNewsletter::noticeText($code);
        }

        if (! isset($map[$code]) && '' !== CrewAdminFollowup::noticeText($code)) {
            return CrewAdminFollowup::noticeText($code);
        }

        if (! isset($map[$code]) && '' !== CrewAdminAiText::noticeText($code)) {
            return CrewAdminAiText::noticeText($code);
        }

        if (! isset($map[$code]) && '' !== CrewAdminTasks::noticeText($code)) {
            return CrewAdminTasks::noticeText($code);
        }

        if (! isset($map[$code]) && str_starts_with($code, 'admin_add_many_')) {
            return self::addManyText($code);
        }

        if (! isset($map[$code]) && str_starts_with($code, 'admin_add_')) {
            $add = substr($code, strlen('admin_add_'));

            return 'added_mail' === $add
                ? RoleTaskService::text(RoleTaskService::ADDED, true)
                : RoleTaskService::text($add);
        }

        if (! isset($map[$code]) && str_starts_with($code, 'admin_sales_')) {
            return SalesDeskGate::message(substr($code, strlen('admin_sales_')));
        }

        return $map[$code] ?? '';
    }

    /**
     * Blocks a signed-in non-organizer who sent an admin request, and tells
     * every organizer: by Telegram to those with a chat, and by email to those
     * with a verified address. The alert ignores notification settings; it is a
     * security notice, not a convenience one.
     */
    private function banForgedRequest(Person $offender, string $action, string $ip): void
    {
        $label = self::actionLabels()[$action] ?? $action;
        $when = (string) current_time('mysql');

        $this->people->ban(
            $offender->id,
            sprintf(
                /* translators: 1: the admin action's name, 2: date and time */
                __('Sent an admin request from the Crew app without being an organizer (%1$s, %2$s).', 'eventcrew'),
                $label,
                $when
            )
        );

        // The texts are the Messages page templates (MessageTemplates).
        $context = ['member' => $offender, 'when' => $when, 'action' => $label, 'ip' => $ip];
        $subject = MessageTemplates::subject('forged_request', $context);
        $body = MessageTemplates::body('forged_request', $context);
        $dm = MessageTemplates::body('forged_request_dm', $context);
        // The person: on the People page in wp-admin (Unban), in the bot their role buttons.
        $markup = MessageActions::replyMarkup(MessageActions::of('forged_request_dm', $context, $this->mailer));
        $actions = MessageActions::email(MessageActions::of('forged_request', $context, $this->mailer));

        $this->logger->warning(sprintf(
            'Blocked person %d after a forged admin request (%s) from %s.',
            $offender->id,
            $action,
            '' !== $ip ? $ip : 'unknown IP'
        ));

        foreach ($this->people->organizers() as $organizer) {
            if (null !== $organizer->telegramChatId) {
                $this->telegram->sendMessage($organizer->telegramChatId, $dm, $markup);
            }

            if ($organizer->isEmailVerified() && ! $organizer->isAnonymized()) {
                $this->mailer->toPerson($organizer->id, $organizer->email, $subject, $body, $actions);
            }
        }
    }

    /**
     * Everyone a credit can go to, as id => "Name (email)", by name.
     *
     * @return array<int, string>
     */
    private function memberChoices(): array
    {
        $choices = [];

        foreach ($this->people->all(['per_page' => 1000, 'orderby' => 'display_name']) as $member) {
            if ($member->isAnonymized()) {
                continue;
            }

            $choices[$member->id] = sprintf('%s (%s)', $member->name(), $member->email);
        }

        return $choices;
    }

    /**
     * The roster of $date for the template: each task with its people, and
     * whether each person still has buttons - only those signed up or arrived,
     * the same rows the bot's /roster offers buttons for.
     *
     * Each person also carries their standing ('' for a deleted person),
     * whether it is at risk, and when they signed up ('' when not known); each
     * task its id, for "Mark all", and its heading: "DJ · 19:00–22:00 · 2 of 3".
     *
     * @return array<int, array{task_id: int, title: string, people: array<int, array{
     *     assignment_id: int, name: string, status: string,
     *     status_label: string, marked_by: string, markable: bool,
     *     standing: string, at_risk: bool, signed_up: string}>}>
     */
    private function rosterRows(string $date): array
    {
        $rows = [];

        foreach ($this->roster->forDate($date) as $row) {
            $task = $row['task'];
            $people = [];
            $taken = 0;

            foreach ($row['people'] as $entry) {
                if ($entry['occupying']) {
                    ++$taken;
                }

                $people[] = [
                    'assignment_id' => $entry['assignment_id'],
                    'name' => $entry['name'],
                    'status' => $entry['status'],
                    'status_label' => $entry['status_label'],
                    'marked_by' => $entry['marked_by'],
                    'markable' => in_array($entry['status'], self::MARKABLE, true),
                    'standing' => null === $entry['standing'] ? '' : $entry['standing']->ratedSummary(),
                    'at_risk' => null !== $entry['standing'] && $entry['standing']->isAtRisk(),
                    'signed_up' => Dates::signedUpLabel($entry['signed_up_at']),
                ];
            }

            $title = [$task->roleDisplay()];

            if ('' !== $task->timeRange()) {
                $title[] = $task->timeRange();
            }

            $title[] = $task->hasNoLimit()
                /* translators: %d: people signed up so far, for a task with no limit on people */
                ? sprintf(__('%d signed up', 'eventcrew'), $taken)
                /* translators: 1: people signed up so far, 2: people needed in total */
                : sprintf(__('%1$d of %2$d', 'eventcrew'), $taken, $task->capacity);

            $rows[] = [
                'task_id' => $task->id,
                'title' => implode(' · ', $title),
                'people' => $people,
            ];
        }

        return $rows;
    }

    /**
     * Back to the page the form was on, kept on this site. The same rule as
     * SignupController's redirect.
     */
    private function safeRedirect(): string
    {
        // phpcs:disable WordPress.Security.NonceVerification
        $requested = '';

        if (isset($_REQUEST['redirect_to'])) {
            $requested = rawurldecode((string) wp_unslash($_REQUEST['redirect_to']));
        } elseif (isset($_SERVER['HTTP_REFERER'])) {
            $requested = (string) wp_unslash($_SERVER['HTTP_REFERER']);
        }
        // phpcs:enable WordPress.Security.NonceVerification

        return wp_validate_redirect($requested, home_url('/'));
    }

    /**
     * REMOTE_ADDR only, for the alert. A forwarded header is whatever the
     * sender wants it to be.
     */
    private function clientIp(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : '';

        return false !== filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\CreditGrantRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\AssignmentStatus;
use EventCrew\Support\CreditGrantNotifier;
use EventCrew\Support\Dates;
use EventCrew\Support\DoorLinkSender;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MarkedBy;
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
 * (/sales), the board repost (/board), the credit grant and its notice (/gift),
 * and the roster with its status write (/roster), and /roles for the restricted
 * roles. "Add a person to a role task" is the bot's "Add someone" button on a
 * role task: it can add someone who is not a crew member yet.
 */
final class CrewAdminController
{
    public const SALES_ACTION = 'eventcrew_web_admin_sales';
    public const BOARD_ACTION = 'eventcrew_web_admin_board';
    public const GIFT_ACTION = 'eventcrew_web_admin_gift';
    public const MARK_ACTION = 'eventcrew_web_admin_mark';
    public const ROLES_ACTION = 'eventcrew_web_admin_roles';
    public const ADD_ACTION = 'eventcrew_web_admin_add';

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
    ];

    /** guard(): an organizer with a valid CSRF token. Run the action. */
    public const OK = 'ok';

    /** guard(): no valid session, an organizer with a bad token, or not a POST. */
    public const REFUSED = 'refused';

    /** guard(): a signed-in non-organizer. Blocked, and the organizers told. */
    public const FORGED = 'forged';

    /** The most credits one press of "Give a credit" hands out. */
    public const MAX_CREDITS = 5;

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
        private readonly RoleTaskService $roleTasks
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
            self::ADD_ACTION => __('Add a person to a role task', 'eventcrew'),
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
            self::SALES_ACTION => $this->sendSalesLink($organizer),
            self::BOARD_ACTION => $this->postBoard(),
            self::GIFT_ACTION => $this->giveCredit(
                $organizer,
                (int) ($post['person_id'] ?? 0),
                (int) ($post['credits'] ?? 0),
                sanitize_text_field((string) ($post['reason'] ?? ''))
            ),
            self::ROLES_ACTION => $this->saveRoles(
                (int) ($post['person_id'] ?? 0),
                isset($post['add_role']) ? [sanitize_key((string) $post['add_role'])] : null,
                is_array($post['roles'] ?? null) ? array_map('sanitize_key', array_map('strval', $post['roles'])) : []
            ),
            self::ADD_ACTION => $this->addPerson(
                $organizer,
                (int) ($post['task_id'] ?? 0),
                (string) ($post['name'] ?? ''),
                (string) ($post['email'] ?? ''),
                '1' === (string) ($post['counts_credit'] ?? '')
            ),
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

        wp_safe_redirect(add_query_arg('eventcrew_notice', $result['notice'], $this->safeRedirect()));

        exit;
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
    public function postBoard(): string
    {
        if (! $this->telegram->isConfigured() || ! $this->board->hasHome()) {
            return 'admin_board_no_group';
        }

        $this->board->repost();

        return 'admin_board_posted';
    }

    /**
     * "Give a credit": the /gift grant, recorded with the organizer's person id
     * in the credit log, and the member told the way /gift tells them.
     */
    public function giveCredit(Person $organizer, int $personId, int $credits, string $reason): string
    {
        if ($credits < 1 || $credits > self::MAX_CREDITS) {
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
     * "Roles": $add holds one role to add to the member's roles (the "Give a
     * role" form); null means $roles is the member's full new set of
     * restricted roles (the checkboxes). Only active restricted roles are
     * written; a role the member holds that is archived or no longer
     * restricted is kept as it is, since the page did not show it.
     *
     * @param list<string>|null $add
     * @param list<string> $roles
     */
    public function saveRoles(int $personId, ?array $add, array $roles): string
    {
        $member = $personId > 0 ? $this->people->find($personId) : null;

        if (null === $member || $member->isAnonymized()) {
            return 'admin_no_person';
        }

        $shown = array_keys(self::assignableRoles());
        $kept = array_values(array_diff($member->roleSlugs, $shown));
        $chosen = null === $add ? $roles : array_merge($member->roleSlugs, $add);

        $this->people->setRoles($member->id, array_merge($kept, array_intersect($chosen, $shown)));

        return 'admin_roles_saved';
    }

    /**
     * "Add a person to a role task": see RoleTaskService::addPerson().
     */
    public function addPerson(Person $organizer, int $taskId, string $name, string $email, bool $countsCredit): string
    {
        $result = $this->roleTasks->addPerson($organizer, $taskId, $name, $email, $countsCredit);

        if (RoleTaskService::ADDED === $result['code']) {
            return $result['emailed'] ? 'admin_add_added_mail' : 'admin_add_added';
        }

        return 'admin_add_' . $result['code'];
    }

    /**
     * What the Admin tab shows, or null for anyone who is not an organizer -
     * the template draws no tab and no panel then.
     *
     * @return array<string, mixed>|null
     */
    public function viewFor(?Person $person): ?array
    {
        if (null === $person || ! $person->isOrganizer) {
            return null;
        }

        $date = $this->salesDesk->nextNight();

        return [
            'sales_action' => self::SALES_ACTION,
            'board_action' => self::BOARD_ACTION,
            'gift_action' => self::GIFT_ACTION,
            'mark_action' => self::MARK_ACTION,
            'max_credits' => self::MAX_CREDITS,
            'members' => $this->memberChoices(),
            'roster_label' => '' === $date ? '' : Dates::dayLabel($date),
            'roster' => '' === $date ? [] : $this->rosterRows($date),
            'roles_action' => self::ROLES_ACTION,
            'add_action' => self::ADD_ACTION,
            'roles' => self::assignableRoles(),
            'role_holders' => $this->roleHolders(),
            'add_tasks' => $this->addTaskChoices(),
        ];
    }

    /**
     * The restricted roles a member can be given here, slug => label. Organize
     * is not one: it is the organizer flag, set in wp-admin or with /roles.
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
     * Members who hold at least one restricted role, with those roles, by name.
     *
     * @return list<array{id: int, name: string, roles: list<string>}>
     */
    private function roleHolders(): array
    {
        $holders = [];

        foreach ($this->people->all(['per_page' => 1000, 'orderby' => 'display_name']) as $member) {
            if ([] === $member->roleSlugs || $member->isAnonymized()) {
                continue;
            }

            $holders[] = ['id' => $member->id, 'name' => $member->name(), 'roles' => $member->roleSlugs];
        }

        return $holders;
    }

    /**
     * The upcoming role tasks a person can be added to, id => "Role — event, day".
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
                $task->eventName(),
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
            'admin_sales_sent' => __('The sales desk link is sent to your email, and to Telegram if you use the bot.', 'eventcrew'),
            'admin_board_posted' => __('A new board is posted in the Telegram group.', 'eventcrew'),
            'admin_board_no_group' => __('No Telegram group is set up for the board yet.', 'eventcrew'),
            'admin_credit_given' => __('Credit given. The member is told.', 'eventcrew'),
            'admin_bad_credits' => __('Pick a number of credits from 1 to 5.', 'eventcrew'),
            'admin_no_person' => __('That member was not found.', 'eventcrew'),
            'admin_marked' => __('Attendance saved.', 'eventcrew'),
            'admin_no_entry' => __('That roster entry was not found.', 'eventcrew'),
            'admin_roles_saved' => __('Roles saved.', 'eventcrew'),
        ];

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
                __('Sent an admin request from the crew page without being an organizer (%1$s, %2$s).', 'eventcrew'),
                $label,
                $when
            )
        );

        $body = sprintf(
            /*
             * translators: 1: person's name, 2: their email, 3: date and time,
             * 4: the admin action's name, 5: IP address
             */
            __(
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                "A member who is not an organizer sent an admin request from the crew page. The request was not carried out and their account is now blocked: they cannot sign up for tasks or sign in on the web.\n\nWho: %1\$s (%2\$s)\nWhen: %3\$s\nAction: %4\$s\nIP address: %5\$s\n\nTo lift the block, open EventCrew > People in wp-admin, edit the person and press Unban.",
                'eventcrew'
            ),
            $offender->name(),
            $offender->email,
            $when,
            $label,
            '' !== $ip ? $ip : __('unknown', 'eventcrew')
        );

        $this->logger->warning(sprintf(
            'Blocked person %d after a forged admin request (%s) from %s.',
            $offender->id,
            $action,
            '' !== $ip ? $ip : 'unknown IP'
        ));

        foreach ($this->people->organizers() as $organizer) {
            if (null !== $organizer->telegramChatId) {
                $this->telegram->sendMessage($organizer->telegramChatId, $body);
            }

            if ($organizer->isEmailVerified() && ! $organizer->isAnonymized()) {
                $this->mailer->toPerson(
                    $organizer->id,
                    $organizer->email,
                    __('Crew page: a member is blocked after a forged admin request', 'eventcrew'),
                    $body
                );
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
     * The next night's roster for the template: each task with its people, and
     * whether each person still has buttons - only those signed up or arrived,
     * the same rows the bot's /roster offers buttons for.
     *
     * @return array<int, array{title: string, people: array<int, array{
     *     assignment_id: int, name: string, status: string,
     *     status_label: string, marked_by: string, markable: bool}>}>
     */
    private function rosterRows(string $date): array
    {
        $rows = [];

        foreach ($this->roster->forDate($date) as $row) {
            $task = $row['task'];
            $people = [];

            foreach ($row['people'] as $entry) {
                $people[] = [
                    'assignment_id' => $entry['assignment_id'],
                    'name' => $entry['name'],
                    'status' => $entry['status'],
                    'status_label' => $entry['status_label'],
                    'marked_by' => $entry['marked_by'],
                    'markable' => in_array($entry['status'], self::MARKABLE, true),
                ];
            }

            $rows[] = [
                'title' => sprintf('%s — %s', $task->roleDisplay(), $task->eventName()),
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

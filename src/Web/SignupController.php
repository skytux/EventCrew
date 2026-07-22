<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\ClaimNotifier;
use EventCrew\Support\Mailer;
use EventCrew\Support\SignupService;
use EventCrew\Support\StandingCalculator;
use EventCrew\Support\WebSession;
use EventCrew\Telegram\BoardService;

/**
 * The public web front door: a shortcode/block showing the open-task board, with
 * email magic-link sign-in and claim/drop, for people who are not in the
 * Telegram group.
 *
 * Identity stays a verified email - clicking the magic link both signs the
 * person in (a stateless WebSession cookie) and verifies their address, so the
 * same person can move between the web and the bot. Claiming goes through the
 * shared SignupService, so the reputation gate, overlap and capacity rules are
 * exactly the bot's.
 *
 * The actions run over admin-ajax rather than the REST API, because the target
 * host serves a JS challenge on /wp-json that a plain form post cannot pass -
 * the same reason the bot webhook has an admin-ajax fallback door.
 */
final class SignupController
{
    public const LOGIN_ACTION = 'eventcrew_web_login';
    public const CLAIM_ACTION = 'eventcrew_web_claim';
    public const DROP_ACTION = 'eventcrew_web_drop';
    public const LOGOUT_ACTION = 'eventcrew_web_logout';

    private const LOGIN_PURPOSE = 'web_login';
    private const LOGIN_TTL = 30 * MINUTE_IN_SECONDS;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly AuthTokenRepository $tokens,
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly SignupService $signup,
        private readonly StandingCalculator $standing,
        private readonly Mailer $mailer,
        private readonly ClaimNotifier $notifier
    ) {
    }

    public function boot(): void
    {
        add_shortcode('eventcrew_signup', [$this, 'renderShortcode']);
        add_action('init', [$this, 'registerBlock']);

        foreach ([self::LOGIN_ACTION, self::CLAIM_ACTION, self::DROP_ACTION, self::LOGOUT_ACTION] as $action) {
            add_action('wp_ajax_' . $action, [$this, 'dispatch']);
            add_action('wp_ajax_nopriv_' . $action, [$this, 'dispatch']);
        }
    }

    public function registerBlock(): void
    {
        if (! function_exists('register_block_type')) {
            return;
        }

        wp_register_script(
            'eventcrew-signup-editor',
            plugins_url('blocks/signup/editor.js', EVENTCREW_PLUGIN_FILE),
            ['wp-blocks', 'wp-element', 'wp-server-side-render'],
            EVENTCREW_VERSION,
            true
        );

        register_block_type('eventcrew/signup', [
            'api_version' => 2,
            'editor_script' => 'eventcrew-signup-editor',
            'render_callback' => [$this, 'renderShortcode'],
        ]);
    }

    /**
     * The shortcode/block render: buffers the public template and returns it,
     * since a shortcode must return its markup, never echo it.
     */
    public function renderShortcode(): string
    {
        $view = $this->viewModel();

        ob_start();
        include EVENTCREW_PLUGIN_DIR . 'templates/public/signup.php';

        return (string) ob_get_clean();
    }

    /**
     * Everything the template needs, resolved from the session cookie and the
     * board. Kept separate from rendering so the composition is testable
     * without a template or an output buffer.
     *
     * @return array<string, mixed>
     */
    public function viewModel(): array
    {
        $person = $this->currentPerson();
        $tasks = $this->tasks->upcoming();
        $occupancy = $this->tasks->occupancyFor(array_map(static fn (Task $t): int => $t->id, $tasks));
        $mine = null === $person ? [] : $this->occupiedTaskIds($person->id);

        return [
            'person' => $person,
            'standing' => null === $person ? null : $this->standing->for($person->id),
            'csrf' => null === $person ? '' : WebSession::csrfToken($person->id),
            'groups' => $this->groupByEvent($tasks, $occupancy, $mine),
            'telegram_group_link' => (string) get_option(BoardService::GROUP_LINK_OPTION, ''),
            'login_action' => self::LOGIN_ACTION,
            'claim_action' => self::CLAIM_ACTION,
            'drop_action' => self::DROP_ACTION,
            'logout_action' => self::LOGOUT_ACTION,
        ];
    }

    /**
     * POST email → issue a magic link and mail it. Returns a notice code for
     * the redirect. Creating the person if new mirrors the bot's onboarding: an
     * unverified row that the magic link will verify.
     */
    public function loginByEmail(string $email): string
    {
        $email = sanitize_email($email);

        if ('' === $email || ! is_email($email)) {
            return 'bad_email';
        }

        $person = $this->people->findByEmail($email);
        $personId = null !== $person ? $person->id : $this->people->create(['email' => $email]);

        $raw = $this->tokens->issue($personId, self::LOGIN_PURPOSE, self::LOGIN_TTL);

        $this->mailer->toPerson(
            $personId,
            $email,
            __('Your EventCrew sign-in link', 'eventcrew'),
            sprintf(
                /* translators: %s: sign-in link */
                __("Click to sign in and manage your tasks:\n\n%s\n\nThe link is good for 30 minutes.", 'eventcrew'),
                $this->loginUrl($raw)
            )
        );

        return 'check_email';
    }

    /**
     * The magic-link target: consume the token, verify the email, and return
     * the person id to start a session for (or null when the link is bad).
     */
    public function consumeMagicLink(string $token): ?int
    {
        $personId = $this->tokens->consume($token, self::LOGIN_PURPOSE);

        if (null === $personId) {
            return null;
        }

        $this->people->markEmailVerified($personId);

        return $personId;
    }

    /**
     * Claim for the signed-in person, returning a notice code. The rules are
     * SignupService's, shared with the bot.
     */
    public function claimFor(int $personId, int $taskId): string
    {
        return match ($this->signup->claim($personId, $taskId)) {
            SignupService::GATED => 'gated',
            SignupService::OVERLAP => 'overlap',
            AssignmentRepository::JOIN_OK, AssignmentRepository::JOIN_REJOINED => 'claimed',
            AssignmentRepository::JOIN_DUPLICATE => 'already',
            AssignmentRepository::JOIN_FULL => 'full',
            default => 'unavailable',
        };
    }

    public function dropFor(int $personId, int $taskId): string
    {
        return '' === $this->signup->drop($personId, $taskId) ? 'not_on' : 'dropped';
    }

    /**
     * The single admin-ajax entry point, branching on the action. This is the
     * untested-by-design boundary: it reads the request, calls the testable
     * methods above, and redirects.
     */
    public function dispatch(): void
    {
        // A logged-out visitor has no WordPress nonce to send; the admin-ajax
        // actions are guarded by the signed session cookie and the CSRF token
        // instead, so the nonce sniff does not apply across this handler.
        // phpcs:disable WordPress.Security.NonceVerification
        // The action arrives in the POST body for the forms and in the query
        // string for the magic link, so read it from either - the same
        // $_REQUEST admin-ajax itself routed on to reach this handler.
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        $redirect = $this->safeRedirect();

        // Set by the board's fetch() call; when present, claim/drop answer with
        // the re-rendered board as JSON instead of a redirect, so the page can
        // update in place. Login/logout never carry it and stay full-page.
        $isAjax = isset($_POST['eventcrew_ajax']);

        if (self::LOGIN_ACTION === $action && isset($_GET['token'])) {
            $personId = $this->consumeMagicLink(sanitize_text_field(wp_unslash($_GET['token'])));

            if (null !== $personId) {
                $this->setSessionCookie(WebSession::mint($personId));
            }

            $this->redirect($redirect, null !== $personId ? 'signed_in' : 'bad_link');
        }

        if (self::LOGIN_ACTION === $action) {
            $email = isset($_POST['email']) ? sanitize_text_field(wp_unslash($_POST['email'])) : '';
            $this->redirect($redirect, $this->loginByEmail($email));
        }

        if (self::LOGOUT_ACTION === $action) {
            $this->setSessionCookie('', time() - DAY_IN_SECONDS);
            $this->redirect($redirect, 'signed_out');
        }

        // Claim and drop need an authenticated, CSRF-checked person.
        $person = $this->currentPerson();
        $csrf = isset($_POST['csrf']) ? sanitize_text_field(wp_unslash($_POST['csrf'])) : '';
        $taskId = isset($_POST['task_id']) ? (int) $_POST['task_id'] : 0;

        if (null === $person || ! WebSession::verifyCsrf($person->id, $csrf)) {
            $this->finish($redirect, 'please_sign_in', $isAjax);
        }

        if (self::CLAIM_ACTION === $action) {
            $notice = $this->claimFor($person->id, $taskId);

            // The same confirmation the bot sends on a join, so a slot claimed
            // from the web arrives with its door ticket too.
            if ('claimed' === $notice) {
                $this->notifier->confirmSignup($person, $taskId);
            }

            $this->finish($redirect, $notice, $isAjax);
        }

        $notice = $this->dropFor($person->id, $taskId);

        if ('dropped' === $notice) {
            // The drop has resolved the row to late_cancel or cancelled; read it
            // back so the cancellation email carries the right standing note.
            $assignment = $this->assignments->findFor($taskId, $person->id);

            if (null !== $assignment) {
                $this->notifier->confirmCancellation($person, $taskId, $assignment->status);
            }
        }

        $this->finish($redirect, $notice, $isAjax);
        // phpcs:enable WordPress.Security.NonceVerification
    }

    /**
     * Ends a claim/drop request: on an AJAX call, the fresh board plus the
     * notice code as JSON; otherwise the usual redirect back to the page.
     */
    private function finish(string $url, string $notice, bool $ajax): never
    {
        if ($ajax) {
            wp_send_json([
                'notice' => $notice,
                'board' => $this->renderBoard($notice, $url),
            ]);
        }

        $this->redirect($url, $notice);
    }

    /**
     * Renders just the board partial - the notice and the grouped task list -
     * against a fresh view model, for the AJAX in-place refresh. $here is the
     * page URL the request came from, since get_permalink() has no queried page
     * to read during admin-ajax.
     */
    public function renderBoard(string $noticeCode, string $here): string
    {
        $view = $this->viewModel();
        $eventcrew_notice_code = $noticeCode;
        $eventcrew_here = $here;

        ob_start();
        require EVENTCREW_PLUGIN_DIR . 'templates/public/signup-board.php';

        return (string) ob_get_clean();
    }

    /**
     * The person the session cookie identifies, or null.
     */
    private function currentPerson(): ?Person
    {
        $cookie = isset($_COOKIE[WebSession::COOKIE])
            ? (string) $_COOKIE[WebSession::COOKIE]
            : '';

        $personId = WebSession::read($cookie);

        return null === $personId ? null : $this->people->find($personId);
    }

    /**
     * Task ids this person still occupies, so the board can show Drop rather
     * than Claim for them.
     *
     * @return array<int, bool> task id => true
     */
    private function occupiedTaskIds(int $personId): array
    {
        $ids = [];

        foreach ($this->assignments->forPerson($personId) as $assignment) {
            if ($assignment->isOccupying()) {
                $ids[$assignment->taskId] = true;
            }
        }

        return $ids;
    }

    /**
     * @param array<int, Task> $tasks
     * @param array<int, int> $occupancy
     * @param array<int, bool> $mine
     * @return array<int, array{title: string, tasks: array<int, array{task: Task, taken: int, mine: bool}>}>
     */
    private function groupByEvent(array $tasks, array $occupancy, array $mine): array
    {
        $groups = [];

        foreach ($tasks as $task) {
            $key = null !== $task->eventPostId
                ? 'e:' . $task->eventPostId
                : 'l:' . $task->eventLabel . '|' . $task->taskDate;

            if (! isset($groups[$key])) {
                $groups[$key] = ['title' => $task->eventName(), 'tasks' => []];
            }

            $groups[$key]['tasks'][] = [
                'task' => $task,
                'taken' => $occupancy[$task->id] ?? 0,
                'mine' => isset($mine[$task->id]),
            ];
        }

        return array_values($groups);
    }

    private function loginUrl(string $rawToken): string
    {
        return add_query_arg(
            [
                'action' => self::LOGIN_ACTION,
                'token' => $rawToken,
                'redirect_to' => rawurlencode($this->safeRedirect()),
            ],
            admin_url('admin-ajax.php')
        );
    }

    /**
     * A same-site URL to return to after an action, from the form's redirect_to
     * or the referer, defaulting to home. wp_validate_redirect keeps it on-site.
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

    private function redirect(string $url, string $notice): never
    {
        wp_safe_redirect(add_query_arg('eventcrew_notice', $notice, $url));

        exit;
    }

    private function setSessionCookie(string $value, ?int $expires = null): void
    {
        setcookie(
            WebSession::COOKIE,
            $value,
            [
                'expires' => $expires ?? (time() + WebSession::TTL),
                'path' => '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }
}

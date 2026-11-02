<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Repositories\CheckinRepository;
use EventCrew\Support\DoorDesk;
use EventCrew\Support\DoorHandoff;
use EventCrew\Support\DoorNewMember;
use EventCrew\Support\DoorQuota;
use EventCrew\Support\DoorSession;
use EventCrew\Support\Language;
use EventCrew\Telegram\TicketRedemptionService;

/**
 * The sales desk (the door page) over HTTP: the login link, the page itself, its JSON state
 * and the actions the page posts.
 *
 * Everything goes through admin-ajax.php rather than a REST route or a page on
 * the site. admin-ajax is never cached by W3 Total Cache, and InfinityFree
 * puts a JavaScript challenge in front of /wp-json that a fetch() cannot pass.
 * Each response still sends DONOTCACHEPAGE and no-cache headers, for any proxy
 * in between.
 *
 * The door crew has no WordPress account, so there is no nonce: the signed
 * door cookie says who is asking, and a CSRF token derived from that cookie,
 * embedded in the page, guards every POST. The logic lives in DoorDesk; this
 * class only reads the request and writes the response.
 */
final class DoorController
{
    public const STATE_ACTION = 'eventcrew_door_state';
    public const MARK_ACTION = 'eventcrew_door_mark';
    public const PLUS_ACTION = 'eventcrew_door_plus';
    public const SEARCH_ACTION = 'eventcrew_door_search';
    public const GIVE_ACTION = 'eventcrew_door_give';
    public const TICKET_ACTION = 'eventcrew_door_ticket';
    public const GUEST_ACTION = 'eventcrew_door_guest';
    public const TASKS_ACTION = 'eventcrew_door_tasks';
    public const WORK_ACTION = 'eventcrew_door_work';
    public const NEW_MEMBER_ACTION = 'eventcrew_door_new_member';
    public const HOLVI_ACTION = 'eventcrew_door_holvi';
    public const COUNT_ACTION = 'eventcrew_door_count';
    public const PEOPLE_ACTION = 'eventcrew_door_people';
    public const HANDOFF_ACTION = 'eventcrew_door_handoff';
    public const SCAN_ACTION = 'eventcrew_door_scan';

    /** The actions that can add people, with DoorQuota::adding()'s name for each. */
    private const QUOTA_ACTIONS = [
        self::MARK_ACTION => 'mark',
        self::PLUS_ACTION => 'plus',
        self::GIVE_ACTION => 'give',
        self::TICKET_ACTION => 'ticket',
        self::HOLVI_ACTION => 'holvi',
        self::COUNT_ACTION => 'count',
        self::GUEST_ACTION => 'guest',
        self::WORK_ACTION => 'work',
    ];

    /** Set by checkQuota(): the answer to this request carries the over-the-max warning. */
    private bool $warnOverMax = false;

    /** How often the page asks for fresh state, in milliseconds. */
    public const POLL_MS = 4000;

    public function __construct(
        private readonly DoorDesk $desk,
        private readonly DoorNewMember $newMember,
        private readonly DoorHandoff $handoff
    ) {
    }

    public function boot(): void
    {
        $actions = [
            DoorSession::PAGE_ACTION,
            self::STATE_ACTION,
            self::MARK_ACTION,
            self::PLUS_ACTION,
            self::SEARCH_ACTION,
            self::GIVE_ACTION,
            self::TICKET_ACTION,
            self::GUEST_ACTION,
            self::TASKS_ACTION,
            self::WORK_ACTION,
            self::NEW_MEMBER_ACTION,
            self::HOLVI_ACTION,
            self::COUNT_ACTION,
            self::PEOPLE_ACTION,
            self::HANDOFF_ACTION,
            self::SCAN_ACTION,
        ];

        foreach ($actions as $action) {
            add_action('wp_ajax_' . $action, [$this, 'dispatch']);
            add_action('wp_ajax_nopriv_' . $action, [$this, 'dispatch']);
        }
    }

    /**
     * The single admin-ajax entry point. The untested-by-design boundary, the
     * same as SignupController::dispatch(): it reads the request, calls
     * DoorDesk, and answers.
     */
    public function dispatch(): void
    {
        if (! defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        nocache_headers();

        // The door crew has no WordPress nonce; the door cookie and its CSRF
        // token stand in for it, checked below for every POST.
        // phpcs:disable WordPress.Security.NonceVerification
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';

        if (DoorSession::PAGE_ACTION === $action && isset($_GET['t'])) {
            $this->login(
                sanitize_text_field(wp_unslash($_GET['t'])),
                isset($_GET['d']) ? sanitize_text_field(wp_unslash($_GET['d'])) : '',
                isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : ''
            );
        }

        $cookie = isset($_COOKIE[DoorSession::COOKIE])
            ? sanitize_text_field(wp_unslash($_COOKIE[DoorSession::COOKIE]))
            : '';
        $session = $this->desk->session($cookie);

        // The desk, and every answer it polls for, is in the language of the
        // person this device is logged in as. Every branch below exits, which
        // is where the language is left.
        if (null !== $session) {
            Language::enter($session['person']->language);
        }

        if (DoorSession::PAGE_ACTION === $action) {
            if (null === $session) {
                $this->plainPage(
                    // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                    __('This device is not logged in to the sales desk. Ask an organizer for a new link (/door in Telegram, then 💳 Sales desk).', 'eventcrew')
                );
            }

            if (! $session['open']) {
                $this->plainPage($this->desk->closedText());
            }

            $this->page($session['date'], DoorSession::csrfToken($cookie), $session['person']->name());
        }

        // The page reloads itself on a 401, which lands on one of the two
        // plain pages above and says why.
        if (null === $session || ! $session['open']) {
            $this->json(['error' => 'session'], 401);
        }

        $date = $session['date'];

        if (self::STATE_ACTION === $action) {
            $this->json($this->desk->state($date));
        }

        $csrf = isset($_POST['csrf']) ? sanitize_text_field(wp_unslash($_POST['csrf'])) : '';

        if ('POST' !== $this->method() || ! DoorSession::verifyCsrf($cookie, $csrf)) {
            $this->json(['error' => 'csrf'], 403);
        }

        $kind = isset($_POST['kind']) ? sanitize_key(wp_unslash($_POST['kind'])) : '';
        $ref = isset($_POST['ref']) ? (int) $_POST['ref'] : 0;
        $plus = isset($_POST['plus']) ? (int) $_POST['plus'] : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $personId = isset($_POST['person']) ? (int) $_POST['person'] : 0;
        $doorPersonId = $session['person']->id;

        $this->checkQuota($action, $date, [
            'kind' => $kind,
            'ref' => $ref,
            'plus' => $plus,
            'in' => isset($_POST['in']) && '1' === $_POST['in'],
            'step' => isset($_POST['step']) && '-1' === $_POST['step'] ? -1 : 1,
            'person' => $personId,
        ]);

        switch ($action) {
            case self::MARK_ACTION:
                $in = isset($_POST['in']) && '1' === $_POST['in'];
                $ok = $this->desk->mark($date, $session['person']->id, $kind, $ref, $in, $plus);
                $this->json(['ok' => $ok, 'state' => $this->desk->state($date)], $ok ? 200 : 404);
                // No break: json() exits.

            case self::PLUS_ACTION:
                $ok = $this->desk->setPlusOnes($date, $kind, $ref, $plus);
                $this->json(['ok' => $ok, 'state' => $this->desk->state($date)], $ok ? 200 : 404);
                // No break: json() exits.

            case self::SEARCH_ACTION:
                $query = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
                $this->json(['members' => $this->desk->searchMembers($date, $query)]);
                // No break: json() exits.

            case self::GIVE_ACTION:
                $code = $this->desk->giveTicket($date, $personId);
                $this->json([
                    'ok' => TicketRedemptionService::TICKET_READY === $code,
                    'message' => $this->desk->giveTicketText($code),
                    'state' => $this->desk->state($date),
                ]);
                // No break: json() exits.

            case self::TICKET_ACTION:
                $ok = $this->desk->addDoorSale($date, $doorPersonId, $name);
                $this->json([
                    'ok' => $ok,
                    'message' => $ok
                        ? __('Door ticket added and checked in.', 'eventcrew')
                        : __('The ticket could not be added. Try again.', 'eventcrew'),
                    'state' => $this->desk->state($date),
                ]);
                // No break: json() exits.

            case self::HOLVI_ACTION:
                // Read as plain text, not through sanitize_email(): that would
                // turn a mistyped address into an empty one, which DoorDesk
                // would then store as "no email" instead of refusing it.
                $email = isset($_POST['email']) ? sanitize_text_field(wp_unslash($_POST['email'])) : '';
                $code = $this->desk->addHolviBuyer($date, $doorPersonId, $name, $email);
                $this->json([
                    'ok' => DoorDesk::HOLVI_OK === $code,
                    'message' => $this->desk->holviBuyerText($code),
                    'state' => $this->desk->state($date),
                ]);
                // No break: json() exits.

            case self::COUNT_ACTION:
                $step = isset($_POST['step']) && '-1' === $_POST['step'] ? -1 : 1;
                $this->desk->countAnonymous($date, $step);
                $this->json(['ok' => true, 'state' => $this->desk->state($date)]);
                // No break: json() exits.

            case self::GUEST_ACTION:
                $ok = $this->desk->addGuest($date, $doorPersonId, $name, $plus);
                $this->json([
                    'ok' => $ok,
                    'message' => $ok
                        ? __('Guest added and checked in.', 'eventcrew')
                        : __('Give the guest’s name.', 'eventcrew'),
                    'state' => $this->desk->state($date),
                ]);
                // No break: json() exits.

            case self::TASKS_ACTION:
                $this->json(['tasks' => $this->desk->openTasks($date, $personId, $doorPersonId)]);
                // No break: json() exits.

            case self::WORK_ACTION:
                $task = isset($_POST['task']) ? (int) $_POST['task'] : 0;
                // Only sent for a restricted task, after the desk asked "Count
                // toward free-entry credit?".
                $countsCredit = isset($_POST['counts_credit'])
                    ? '1' === sanitize_key(wp_unslash($_POST['counts_credit']))
                    : null;
                $code = $this->desk->workTonight($date, $doorPersonId, $personId, $task, $countsCredit);
                $this->json([
                    'ok' => in_array($code, [DoorDesk::WORK_OK, DoorDesk::WORK_SWAPPED], true),
                    'message' => $this->desk->workTonightText($code),
                    'state' => $this->desk->state($date),
                ]);
                // No break: json() exits.

            case self::NEW_MEMBER_ACTION:
                $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
                $created = $this->newMember->create($name, $email);
                $member = null === $created['person'] ? null : $this->desk->member($created['person']->id);
                $this->json([
                    'ok' => null !== $member,
                    'message' => $this->newMember->text($created['code']),
                    'member' => $member,
                ]);
                // No break: json() exits.

            case self::SCAN_ACTION:
                // Only looks the ticket up: the desk then shows the person
                // and marks them arrived through MARK_ACTION.
                $text = isset($_POST['text']) ? sanitize_text_field(wp_unslash($_POST['text'])) : '';
                $scan = $this->desk->scan($date, $text);
                $this->json([
                    'ok' => DoorDesk::SCAN_OK === $scan['code'],
                    'key' => $scan['key'],
                    'name' => $scan['name'],
                    'message' => $this->desk->scanText($scan['code'], $scan['name'], $scan['date'], $scan['at']),
                    'state' => $this->desk->state($date),
                ]);
                // No break: json() exits.

            case self::PEOPLE_ACTION:
                $this->json(['people' => $this->handoff->people($cookie) ?? []]);
                // No break: json() exits.

            case self::HANDOFF_ACTION:
                $link = $this->handoff->link($cookie, $personId);
                $ok = DoorHandoff::OK === $link['code'];
                $this->json([
                    'ok' => $ok,
                    'url' => $link['url'],
                    'name' => $link['name'],
                    'message' => $ok ? '' : DoorHandoff::message($link['code']),
                ], $ok ? 200 : 403);
                // No break: json() exits.
        }
        // phpcs:enable WordPress.Security.NonceVerification

        $this->json(['error' => 'action'], 400);
    }

    /**
     * The max people check (DoorQuota), made before an action runs. An action
     * that would pass the max is answered with a refusal here, unless going
     * over is allowed: then it runs and its answer carries a warning.
     *
     * @param array{kind: string, ref: int, plus: int, in: bool, step: int, person: int} $args
     */
    private function checkQuota(string $action, string $date, array $args): void
    {
        $short = self::QUOTA_ACTIONS[$action] ?? null;

        if (null === $short || 0 === DoorQuota::max()) {
            return;
        }

        $state = $this->desk->state($date);
        $verdict = DoorQuota::verdict($state['expected'], DoorQuota::adding($state, $short, $args));

        if (DoorQuota::REFUSED === $verdict) {
            $this->json(['ok' => false, 'refused' => true, 'message' => DoorQuota::refusedText(), 'state' => $state]);
        }

        $this->warnOverMax = DoorQuota::WARN === $verdict;
    }

    /**
     * Spends a login link. On success the cookie is set and the browser is
     * sent to the page's clean address, so the token is not left in the
     * address bar or the history. On failure, a plain page says why.
     */
    private function login(string $token, string $date, string $signature): never
    {
        $login = $this->desk->login($token, $date, $signature);

        if (null === $login['cookie']) {
            $this->plainPage($login['message']);
        }

        setcookie(
            DoorSession::COOKIE,
            $login['cookie'],
            [
                'expires' => time() + $login['seconds'],
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        wp_safe_redirect(DoorSession::pageUrl());

        exit;
    }

    /**
     * @param string $who The name of the person this device is logged in as,
     *                    shown next to the date.
     */
    private function page(string $date, string $csrf, string $who): never
    {
        $eventcrew_state = $this->desk->state($date);
        $eventcrew_config = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'csrf' => $csrf,
            'who' => $who,
            'pollMs' => self::POLL_MS,
            'searchMin' => DoorDesk::SEARCH_MIN,
            'maxPlus' => CheckinRepository::MAX_PLUS_ONES,
            'actions' => [
                'state' => self::STATE_ACTION,
                'mark' => self::MARK_ACTION,
                'plus' => self::PLUS_ACTION,
                'search' => self::SEARCH_ACTION,
                'give' => self::GIVE_ACTION,
                'ticket' => self::TICKET_ACTION,
                'guest' => self::GUEST_ACTION,
                'tasks' => self::TASKS_ACTION,
                'work' => self::WORK_ACTION,
                'newMember' => self::NEW_MEMBER_ACTION,
                'holvi' => self::HOLVI_ACTION,
                'count' => self::COUNT_ACTION,
                'people' => self::PEOPLE_ACTION,
                'handoff' => self::HANDOFF_ACTION,
                'scan' => self::SCAN_ACTION,
            ],
            // The QR decoder for phones without BarcodeDetector, loaded only
            // when Scan is tapped.
            'qrDecodeUrl' => EVENTCREW_PLUGIN_URL . 'assets/vendor/qr-decode/decode.js?ver=' . EVENTCREW_VERSION,
        ];

        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');

        include EVENTCREW_PLUGIN_DIR . 'templates/public/door.php';

        exit;
    }

    private function plainPage(string $message): never
    {
        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');

        echo '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . esc_html__('Sales desk', 'eventcrew') . '</title>'
            . '<style>body{font:18px/1.5 system-ui,sans-serif;margin:0;padding:24px 16px;color:#1d1d1f;background:#fff}'
            . '@media (prefers-color-scheme:dark){body{color:#eee;background:#111}}</style>'
            . '</head><body><p>' . esc_html($message) . '</p></body></html>';

        exit;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): never
    {
        status_header($status);
        header('Content-Type: application/json; charset=utf-8');

        if ($this->warnOverMax && false !== ($data['ok'] ?? null) && isset($data['state']['expected'])) {
            $data['warning'] = DoorQuota::warningText((int) $data['state']['expected']);
        }

        echo wp_json_encode($data);

        exit;
    }

    private function method(): string
    {
        return isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'])))
            : '';
    }
}

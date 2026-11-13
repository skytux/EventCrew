<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Support\Dates;
use EventCrew\Support\HelpPage;

/**
 * How an action the helper chose (AssistantActions, the bot's own list)
 * runs on the Crew app page instead of in Telegram. The page is the
 * alternative to Telegram (Owner 2026-11-13): it never sends anyone there.
 *
 * - confirm: signing up for a task or cancelling one. The page asks with
 *   Confirm and Cancel first, then runs the page's own sign-up or cancel
 *   (SignupController), with the same checks. Cancelling asks too: on the
 *   page there is no second question about what the cancel costs.
 *   An organizer change the bot confirms first (op "change": CHANGES, such
 *   as /unticket) is asked the same way and runs on Confirm through the
 *   bot command's own checks and services (AssistantWebChanges), with the
 *   real outcome as the answer (Owner 2026-11-13). So is spending one's
 *   own credit on a night (menu:ticket [date], OWN_TICKET), for anyone.
 * - goto: what the bot would show or do, the page has a place for: the page
 *   opens that tab (and Admin screen) and scrolls to the card ("targets":
 *   element ids, the first one on the page wins). "query" (optional) are
 *   page-address values the screen reads, such as the Roster's date or the
 *   People search: the page then loads with them.
 * - none: the page has no place for it; the answer text stands alone.
 *
 * The Admin tab's places are for an organizer only; anyone else gets none
 * for those actions (the Admin tab is not on their page either).
 */
final class AssistantWebActions
{
    public const CONFIRM = 'confirm';
    public const GOTO = 'goto';
    public const NONE = 'none';

    public const JOIN = 'join';
    public const LEAVE = 'leave';
    public const CHANGE = 'change';

    /** A person's own free-entry ticket for a night: "menu:ticket [Y-m-d]". */
    public const OWN_TICKET = 'menu:ticket';

    /** The Admin tab's screens and cards (templates/public/signup.php). */
    public const ADMIN_PANEL = 'eventcrew-panel-admin';
    public const ADMIN_ROSTER = 'eventcrew-admin-roster';
    public const ADMIN_DOOR = 'eventcrew-admin-door';
    public const ADMIN_TASKS = 'eventcrew-admin-tasks';
    public const ADMIN_PEOPLE = 'eventcrew-admin-people';
    public const ADMIN_NEWCOMERS = 'eventcrew-admin-newcomers';
    public const ADMIN_ROLES = 'eventcrew-admin-role-form';
    public const ADMIN_NEWSLETTER = 'eventcrew-admin-newsletter-card';
    public const ADMIN_FOLLOWUP = 'eventcrew-admin-followup-card';
    public const ADMIN_BOARD = 'eventcrew-admin-board';
    public const ADMIN_OPEN_TASK = 'eventcrew-admin-open-task';
    public const ADMIN_OPTOUT = 'eventcrew-admin-optout';
    public const ADMIN_AI_TEXTS = 'eventcrew-admin-aitexts';

    /** Hosts whose links a web answer never carries. */
    private const TELEGRAM_HOSTS = ['t.me', 'telegram.me', 'telegram.dog', 'telegram.org'];

    /** @var array<string, list<string>> action line => element ids */
    private const PLACES = [
        '/board' => ['eventcrew-panel-board'],
        '/me' => ['eventcrew-my-tasks', 'eventcrew-panel-tasks'],
        '/me history' => ['eventcrew-past-tasks', 'eventcrew-panel-tasks'],
        '/me tickets' => ['eventcrew-my-tickets', 'eventcrew-panel-tickets'],
        '/tasks' => ['eventcrew-my-tasks', 'eventcrew-panel-tasks'],
        '/tickets' => ['eventcrew-my-tickets', 'eventcrew-get-ticket', 'eventcrew-panel-tickets'],
        // Taking over a task is a button on the full task, on the board.
        'menu:replace' => ['eventcrew-panel-board'],
        '/settings' => ['eventcrew-panel-settings'],
        'menu:notifications' => ['eventcrew-notifications', 'eventcrew-panel-settings'],
        'menu:language' => ['eventcrew-settings-language', 'eventcrew-panel-settings'],
        // Signed in already: the sign-in link is this page.
        'menu:web' => ['eventcrew-panel-tasks'],
        '/help' => [HelpPage::PANEL_ANCHOR],
        '/start' => [HelpPage::PANEL_ANCHOR],
    ];

    /** Changing the name or email is the Settings tab's details card. */
    private const SETTINGS_COMMANDS = ['/name', '/email'];

    /**
     * Organizer commands => the Admin screen or card, and which page-address
     * value a date in the line sets ('' = none). The changes the page runs
     * itself (CHANGES) are not here.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const ADMIN = [
        '/roster' => [self::ADMIN_ROSTER, 'roster_date'],
        '/door' => [self::ADMIN_DOOR, 'roster_date'],
        'menu:doorlist' => [self::ADMIN_DOOR, 'roster_date'],
        'menu:guestlist' => [self::ADMIN_DOOR, 'roster_date'],
        'menu:sales' => [self::ADMIN_DOOR, ''],
        '/people' => [self::ADMIN_PEOPLE, ''],
        'menu:gift' => [self::ADMIN_PEOPLE, ''],
        'menu:roles' => [self::ADMIN_PEOPLE, ''],
        'menu:eligible' => [self::ADMIN_ROLES, ''],
        '/standing' => [self::ADMIN_PEOPLE, ''],
        '/newcomer' => [self::ADMIN_NEWCOMERS, ''],
        '/newsletter' => [self::ADMIN_NEWSLETTER, ''],
        '/followup' => [self::ADMIN_FOLLOWUP, ''],
    ];

    /**
     * Organizer changes the page runs itself after Confirm (AssistantWebChanges).
     * "/door" only with add, edit or remove; "/stop" and "/sendtickets" with
     * every form the bot takes.
     */
    public const CHANGES = [
        '/assign',
        '/unassign',
        '/mark',
        '/ticket',
        '/unticket',
        '/unban',
        '/freeentry',
        '/stop',
        '/newboard',
        '/sendtickets',
        '/addtask',
        '/edittask',
        '/deletetask',
        '/maketasks',
        '/mailtasks',
        '/say',
        '/tell',
        '/subscribe',
    ];

    /**
     * Organizer changes the page runs after Confirm only with text after
     * the command: alone, each opens its Admin card (show the text, choose
     * the person there).
     */
    private const CHANGES_WITH_TEXT = [
        '/newsletter',
        '/followup',
        'menu:gift',
        'menu:roles',
        // Web-only lines (AssistantWebPeople): a person's organizer flag, deleting a person.
        '/organizer',
        '/deleteperson',
    ];

    /**
     * parityA: the Admin tab's other changes (AssistantWebAdmin), run after
     * Confirm with text after the command. Alone, /optout and /aitext open
     * their Admin card (VIEWS_PARITY_A); the others need the text.
     */
    private const CHANGES_PARITY_A = [
        '/oncemore',
        '/addperson',
        '/editperson',
        '/editrole',
        '/optout',
        '/aitext',
    ];

    /** parityA: the Admin card each of CHANGES_PARITY_A opens alone. */
    private const VIEWS_PARITY_A = [
        '/oncemore' => self::ADMIN_PEOPLE,
        '/addperson' => self::ADMIN_PEOPLE,
        '/editperson' => self::ADMIN_PEOPLE,
        '/editrole' => self::ADMIN_ROLES,
        '/optout' => self::ADMIN_OPTOUT,
        '/aitext' => self::ADMIN_AI_TEXTS,
    ];

    /** "/newcomer" runs here with OK or No; alone it opens the waiting list. */
    private const NEWCOMER_CHANGES = ['ok', 'no'];

    /**
     * A person's own changes the page runs after Confirm, for anyone, with
     * the new value after the command: alone, each opens its Settings card.
     * Not /email: its link goes to the new address, so it stays on the
     * Settings card (Owner 2026-11-14).
     */
    public const OWN_CHANGES = ['/name', 'menu:language'];

    /** The /door words that change the guest list. */
    private const DOOR_CHANGES = ['add', 'edit', 'remove'];

    /**
     * Actions that change something but that the page does not run: it only
     * opens the place where the person does it, or has no place for it.
     */
    private const LEFT_UNDONE = [
        'menu:gift',
        'menu:roles',
        'menu:replace',
        'menu:notifications',
        'menu:language',
    ];

    /** Actions that change something only with text after the command. */
    private const LEFT_UNDONE_WITH_TEXT = ['/email'];

    /** Organizer commands whose line is "[date] <person>": the person is searched on People. */
    private const PERSON_SEARCH = ['/standing'];

    /**
     * What the page does with a checked action line ("/join 12", "/me history",
     * "menu:ticket", "/roster 14.11.") for someone who is an organizer or not.
     * $today (Y-m-d) reads a date like 14.11.
     *
     * @return array{kind: string, op?: string, task?: int, command?: string, targets?: list<string>, query?: array<string, string>}
     */
    public static function plan(string $action, bool $organizer = false, string $today = ''): array
    {
        $original = trim((string) preg_replace('/\s+/u', ' ', $action));
        $line = strtolower($original);

        if (1 === preg_match('#^/(join|leave) (\d{1,10})$#', $line, $m)) {
            return [
                'kind' => self::CONFIRM,
                'op' => 'join' === $m[1] ? self::JOIN : self::LEAVE,
                'task' => (int) $m[2],
            ];
        }

        if (isset(self::PLACES[$line])) {
            return ['kind' => self::GOTO, 'targets' => self::PLACES[$line]];
        }

        $words = explode(' ', $original, 2);
        $head = strtolower($words[0]);
        $rest = trim($words[1] ?? '');

        // Spending one's own credit on a night, or one's own name, email or
        // language: anyone, asked and run here.
        if (self::OWN_TICKET === $head || ('' !== $rest && in_array($head, self::OWN_CHANGES, true))) {
            return ['kind' => self::CONFIRM, 'op' => self::CHANGE, 'command' => $head];
        }

        if ($organizer && self::isChange($head, $rest)) {
            return ['kind' => self::CONFIRM, 'op' => self::CHANGE, 'command' => $head];
        }

        if (in_array($head, self::SETTINGS_COMMANDS, true)) {
            return ['kind' => self::GOTO, 'targets' => ['eventcrew-panel-settings']];
        }

        // The sales desk of someone on tonight's sales-desk task is the Door
        // scanner button at the top of the page (Owner 2026-11-13).
        if ('menu:sales' === $head && ! $organizer) {
            return ['kind' => self::GOTO, 'targets' => ['eventcrew-door-scanner']];
        }

        if ($organizer && isset(self::ADMIN[$head])) {
            return self::adminPlan($head, $rest, $today);
        }

        // parityA: alone, the Admin card.
        if ($organizer && isset(self::VIEWS_PARITY_A[$head])) {
            return ['kind' => self::GOTO, 'targets' => [self::VIEWS_PARITY_A[$head], self::ADMIN_PANEL]];
        }

        return ['kind' => self::NONE];
    }

    /**
     * Whether the page runs this organizer action line itself after Confirm
     * (CHANGES, CHANGES_WITH_TEXT with its text, and /door add, edit or
     * remove).
     */
    public static function isChange(string $head, string $rest): bool
    {
        $head = strtolower($head);

        if ('/door' === $head) {
            return in_array(strtolower((string) strtok($rest, ' ')), self::DOOR_CHANGES, true);
        }

        if ('/newcomer' === $head) {
            return in_array(strtolower((string) strtok($rest, ' ')), self::NEWCOMER_CHANGES, true);
        }

        if (in_array($head, self::CHANGES_WITH_TEXT, true) || in_array($head, self::CHANGES_PARITY_A, true)) {
            return '' !== trim($rest);
        }

        return in_array($head, self::CHANGES, true);
    }

    /**
     * Whether $action changes something the page leaves for the person to
     * do (it opens the place, or has none): the answer then says it is not
     * done yet, whatever the model wrote.
     */
    public static function leavesUndone(string $action): bool
    {
        $words = explode(' ', trim((string) preg_replace('/\s+/u', ' ', $action)), 2);
        $head = strtolower($words[0]);
        $rest = trim($words[1] ?? '');

        // menu:gift, menu:roles and menu:language with their value run here.
        if (in_array($head, self::LEFT_UNDONE, true)) {
            return '' === $rest || ! in_array($head, [...self::CHANGES_WITH_TEXT, ...self::OWN_CHANGES], true);
        }

        return '' !== $rest && in_array($head, self::LEFT_UNDONE_WITH_TEXT, true);
    }

    /**
     * An organizer command's Admin place, with the date or person of the
     * line as the screen's page-address value where the screen reads one.
     *
     * @return array{kind: string, targets: list<string>, query?: array<string, string>}
     */
    private static function adminPlan(string $head, string $rest, string $today): array
    {
        [$target, $dateArg] = self::ADMIN[$head];
        $query = [];

        $words = '' === $rest ? [] : explode(' ', $rest);

        // "/door add 14.11. Anna": the date follows the door command's own word.
        if ('/door' === $head && in_array(strtolower($words[0] ?? ''), ['add', 'edit', 'remove'], true)) {
            array_shift($words);
        }

        if ('' !== $dateArg && [] !== $words) {
            $date = Dates::parseUserDate($words[0], '' !== $today ? $today : (string) current_time('Y-m-d'));

            if ('' !== $date) {
                $query[$dateArg] = $date;
                array_shift($words);
            }
        }

        if (in_array($head, self::PERSON_SEARCH, true)) {
            $who = trim((string) preg_replace('/[^\p{L}\p{N}\s\'.-]/u', '', implode(' ', $words)));

            if ('' !== $who) {
                $query['people_q'] = mb_substr($who, 0, 100);
            }
        }

        $plan = ['kind' => self::GOTO, 'targets' => [$target, self::ADMIN_PANEL]];

        return [] === $query ? $plan : $plan + ['query' => $query];
    }

    /**
     * The answer split into plain text and web links, for the page to put
     * together with textContent and href, never as HTML: what the model
     * wrote is never markup. Only http and https addresses become links.
     * A Telegram address (t.me and the like) is left out altogether: the
     * page never sends anyone to Telegram.
     *
     * @return list<array{t: string, u?: string}>
     */
    public static function segments(string $text): array
    {
        $parts = preg_split('#(https?://[^\s<>"\']+)#iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $segments = [];

        foreach (false === $parts ? [$text] : $parts as $part) {
            if (1 !== preg_match('#^https?://#i', $part)) {
                $segments[] = ['t' => $part];

                continue;
            }

            // A full stop or bracket after an address ends the sentence.
            $url = rtrim($part, '.,;:!?)]}');
            $tail = substr($part, strlen($url));

            if (! self::isTelegram($url)) {
                $segments[] = ['t' => $url, 'u' => $url];
            }

            if ('' !== $tail) {
                $segments[] = ['t' => $tail];
            }
        }

        return $segments;
    }

    /** Whether $url is a Telegram address (t.me, telegram.me, telegram.dog, telegram.org). */
    public static function isTelegram(string $url): bool
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure, no WordPress needed.
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach (self::TELEGRAM_HOSTS as $telegram) {
            if ($host === $telegram || str_ends_with($host, '.' . $telegram)) {
                return true;
            }
        }

        return false;
    }
}

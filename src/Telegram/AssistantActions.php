<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Support\Dates;
use EventCrew\Support\GuestCommand;
use EventCrew\Support\FollowupText;
use EventCrew\Support\NewsletterText;
use EventCrew\Support\SiteLanguages;

/**
 * The actions the language-model helper may ask for, and the check every one
 * of them passes before it runs.
 *
 * An action is one line: a command the person could type ("/me history",
 * "/door add 1.8. Anna +1") or a button they could tap ("menu:ticket"), plus
 * "/join <task>" and "/leave <task>" for a task on the board. Which actions a
 * person gets follows /help: everyone's, the welcome crew's, the organizers'.
 *
 * validate() takes nothing on trust. The command must be in the person's list;
 * a date must be a real date; a task must be one of the open tasks the helper
 * was shown; a guest's number and name must be in what the person wrote. What
 * passes runs through the router exactly as if typed or tapped, so every
 * service still checks who may use it.
 */
final class AssistantActions
{
    public const ERROR_UNKNOWN = 'unknown';
    public const ERROR_NOT_PERMITTED = 'not_permitted';
    public const ERROR_NOT_REGISTERED = 'not_registered';
    public const ERROR_INVALID = 'invalid';

    /** The action runs as a typed message. */
    public const RUN_MESSAGE = 'message';

    /** The action runs as a button tap. */
    public const RUN_TAP = 'tap';

    /** @var array<string, string> menu action => MenuButtons callback data */
    private const MENU = [
        'menu:ticket' => MenuButtons::TICKET,
        'menu:replace' => MenuButtons::REPLACE,
        'menu:web' => MenuButtons::WEB,
        'menu:notifications' => MenuButtons::NOTIFICATIONS,
        'menu:language' => MenuButtons::LANGUAGE,
        'menu:sales' => MenuButtons::SALES,
        'menu:gift' => MenuButtons::GIFT,
        'menu:roles' => MenuButtons::ROLES,
        'menu:eligible' => MenuButtons::ELIGIBLE,
    ];

    /** Menu actions for the welcome crew and organizers. */
    private const MENU_WELCOME = ['menu:sales'];

    /** Menu actions for organizers only. */
    private const MENU_ORGANIZER = ['menu:gift', 'menu:roles', 'menu:eligible'];

    /**
     * The actions this person may ask for, for the helper's instructions.
     * Written in English for the model, never shown to the person.
     *
     * @param array{organizer: bool, welcomeCrew: bool, verified: bool} $audience
     * @return array<int, array{usage: string, what: string, confirm: bool}>
     */
    public static function catalogue(array $audience): array
    {
        $list = [
            self::entry('/help', 'open the help menu: how this works, the commands this person can use, FAQ'),
            self::entry('/start', 'sign up: confirm the email address (for someone new)'),
            self::entry('/me', "the person's own tasks, credits and standing"),
            self::entry('/me history', "the person's past tasks"),
            self::entry('/me tickets', "the person's tickets for coming nights"),
            self::entry('/tasks', "the person's coming tasks, each with a cancel button; take over a task; past tasks"),
            self::entry('/tickets', "the person's tickets and the free-entry ticket button"),
            self::entry('/settings', 'the notifications, language and web sign-in buttons'),
            self::entry(
                '/board',
                'the open tasks as buttons to tap; in a private chat also the tasks of the roles the person holds'
                    . ' (✋ sign up, ❌ cancel), and for an organizer a ➕ Add someone button under every task, to put'
                    . ' anyone on it (a member, or someone new by name and email)'
            ),
            self::entry('/join <task>', 'sign the person up for a task; <task> is a task number from Open tasks', true),
            self::entry('/leave <task>', 'take the person off a task they signed up for; <task> as above', true),
            self::entry(
                'menu:ticket [date]',
                'get a free-entry ticket for a night by spending one of the person\'s own credits ("give me a'
                    . ' ticket", "give myself a ticket for Saturday"); with [date], for that night. Nothing is spent'
                    . ' until the person confirms. A member cannot cancel a free-entry ticket'
            ),
            self::entry('menu:replace', "take over someone else's task (the bot then asks whose)"),
            self::entry('menu:web', 'a sign-in link for the crew web page'),
            self::entry('menu:notifications', 'choose which notifications the person gets'),
            self::entry(
                'menu:language',
                'choose the language of the bot: ' . implode(', ', array_map([SiteLanguages::class, 'name'], SiteLanguages::codes()))
            ),
        ];

        if (! $audience['organizer'] && ! $audience['welcomeCrew']) {
            $list[] = self::entry(
                '/roster [date]',
                'who works that night, by task (names only); only for a night the person works themselves'
            );
        }

        if ($audience['organizer'] || $audience['welcomeCrew']) {
            $list[] = self::entry(
                '/roster [date]',
                'who works that night, with buttons to mark them:'
                . ' an organizer marks arrived (only people still signed up), done, late or no-show;'
                . " someone on that night's sales-desk task who is not an organizer marks only arrived,"
                . ' for people still signed up'
            );
            $list[] = self::entry('/door [date]', 'the door buttons: door list, guest list, sales desk');
            $list[] = self::entry('menu:doorlist [date]', 'the door list of a night');
            $list[] = self::entry('menu:guestlist [date]', 'the guest list of a night');
            $list[] = self::entry('menu:sales', 'the sales desk link');
        }

        if ($audience['organizer']) {
            $list[] = self::entry(
                '/door add [date] <name> [email] [+N] [; note]',
                'add a guest to the guest list; with an email address after the name (/door add Anna anna@example.com)'
                    . ' the guest gets their ticket by email',
                true
            );
            $list[] = self::entry(
                '/door edit [date] <number> [name] [+N] [; note]',
                'change a guest; <number> is the guest\'s number on the guest list',
                true
            );
            $list[] = self::entry('/door remove [date] <number>', 'take a guest off the guest list', true);
            $list[] = self::entry(
                '/assign [date] <task> ; <person>',
                'put a member on a task of the next event (or of [date]): any task; the Organize task only for'
                    . ' someone who is an organizer (the bot checks, so propose it and let the bot answer); also a'
                    . ' task for a role the person does not hold (the bot warns and gives them the role). <task> is'
                    . ' the task name'
                    . ' (Host, Door, ...), <person> the name as the organizer wrote it, even partly or misspelled:'
                    . ' the bot finds the member, asks with buttons when several match, says when the task is full,'
                    . ' and asks the organizer to confirm before anything is written; the person then gets the'
                    . ' normal sign-up confirmation and ticket. Someone who is not a member yet: /board, ➕ Add someone',
                false
            );
            $list[] = self::entry(
                '/unassign [date] <task> ; <person>',
                'take a member off a task of the next event (or of [date]): any task, Organize too; same names as'
                    . ' /assign; the bot asks the organizer to confirm first',
                false
            );
            $list[] = self::entry(
                '/addtask [date] <role> ; time <start>-<end> ; places <n> ; notes <text> ; plus <n>',
                'add one task to the next event (or to [date]); <role> is a role name (Host, Door, ...); every part'
                    . ' after the role is optional: without a time it starts when the event starts, places default'
                    . ' to the role\'s, plus is how many guests each person may bring. The bot asks to confirm first',
                false
            );
            $list[] = self::entry(
                '/edittask [date] <task> ; <field> <value> [; <field> <value>]',
                'change a task of the next event (or of [date]); fields: time 21:00-02:00 (or start 21:00, end'
                    . ' 02:00), places <n> (people needed; 0 takes it off the boards), notes <text> (notes - clears'
                    . ' them), plus <n> (guests each person may bring). The bot warns when fewer places than people'
                    . ' signed up and asks to confirm first',
                false
            );
            $list[] = self::entry(
                '/deletetask [date] <task>',
                'delete (cancel) a task of the next event (or of [date]); everyone on it is taken off and gets an'
                    . ' email. The bot asks to confirm first',
                false
            );
            $list[] = self::entry(
                '/maketasks [date]',
                'create the next event\'s tasks (or those of the event on [date]) from the roles, one per role;'
                    . ' roles that already have a task are skipped. The bot asks to confirm first',
                false
            );
            $list[] = self::entry(
                '/mailtasks',
                'send the open-task email now, for the next night with open places, to everyone who gets it and'
                    . ' has not had it yet. The bot asks to confirm first',
                false
            );
            $list[] = self::entry('/people', 'the people buttons: credits, roles, who may hold each role');
            $list[] = self::entry('menu:gift', 'give someone credits (the bot then asks who)');
            $list[] = self::entry('menu:roles', 'give or take roles, organizers and passes (the bot then asks who)');
            $list[] = self::entry('menu:eligible', 'who may hold each role');
            $list[] = self::entry('/newsletter', "show the organizers' own text for the next newsletter");
            $list[] = self::entry(
                '/newsletter <text>',
                "set the organizers' own text for the next newsletter, replacing any earlier one (the confirmation"
                    . ' shows the earlier text and who set it); it is translated'
                    . ' so the mail carries it in every site language; <text> is the text itself, as the person'
                    . ' wants it sent (line breaks allowed)',
                true
            );
            $list[] = self::entry(
                '/subscribe <email> [language code]',
                'add someone to the newsletter list; they get a confirmation email and are on the list once they'
                    . ' open it. The language code (a site language, like fi or en) is optional',
                true
            );
            $list[] = self::entry(
                '/say <text>',
                'post a message in the crew group; the bot shows it to the person first with Confirm and Cancel;'
                    . ' <text> is the message itself, word for word as the person wrote it (line breaks allowed),'
                    . ' never reworded or translated unless the person asks for that',
                false
            );
            $list[] = self::entry(
                '/events',
                'show the next coming events as picture cards (picture, date, price, description, ticket button);'
                    . ' the bot then offers to post them in the crew group with Confirm and Cancel. Use it when'
                    . ' the person asks which events come next'
            );
            $list[] = self::entry(
                '/newsletter reset',
                "reset the organizers' own text of the next newsletter to the default (removes it); also for"
                    . ' "clear" or "remove"',
                true
            );
            $list[] = self::entry(
                '/followup',
                'show the two texts the next post-event email uses (thank-you to those who came, sorry text to'
                    . ' those who did not), and whether each is a one-time text or the one on the Messages page'
            );
            $list[] = self::entry(
                '/followup <came|missed> <text>',
                'set a one-time text for the next post-event email only: came = the thank-you text, missed = the'
                    . ' sorry text; it replaces the main text between the greeting and the sign-off, is translated'
                    . ' into every site language, and is removed after that email is sent; {event} and {date} in it'
                    . ' become the event\'s name and date; <text> is the text itself (line breaks allowed); it'
                    . ' replaces an earlier one-time text of that version (the confirmation shows it and who set it)',
                true
            );
            $list[] = self::entry(
                '/followup [came|missed] reset',
                'reset the next post-event email to the default (one version, or both when no version is given):'
                    . ' removes the one-time text, so it uses the text on the Messages page; also for "clear"',
                true
            );
        }

        // Private-chat commands for organizers and members (ag-people).
        $list = array_merge($list, self::chatCommandEntries($audience));

        // parityB
        $list = array_merge($list, AssistantParityLines::entries($audience));

        if (! empty($audience['web'])) {
            $list = array_merge($list, self::webEntries($audience));
        } else {
            // parityA: the page's lines work in the chat too, through the bot commands.
            $list = array_merge($list, array_values(array_filter(
                self::webEntries($audience),
                static fn (array $entry): bool => ! str_starts_with($entry['usage'], '/newcomer')
            )));
        }

        // parityA
        return array_merge($list, self::parityAEntries($audience));
    }

    /**
     * parityA: the Admin tab's other changes, on the page (AssistantWebAdmin,
     * asked with Confirm) and in the chat (AdminChangeCommands, asked with
     * ✅). Organizers only.
     *
     * @param array<string, mixed> $audience
     * @return array<int, array{usage: string, what: string, confirm: bool}>
     */
    private static function parityAEntries(array $audience): array
    {
        if (empty($audience['organizer'])) {
            return [];
        }

        return [
            self::entry(
                '/oncemore <person>',
                'give a member at risk one more sign-up: they can sign up once past the sign-up gate; they are told'
            ),
            self::entry(
                '/addperson <name> ; <email> [; <notes>]',
                'add a new person to the crew list (not verified until they sign in themselves)'
            ),
            self::entry(
                '/editperson <person> ; name <new name> ; email <new address> ; notes <text>',
                "change another person's name, email or notes; give only the parts that change; notes - empties"
                    . ' the notes'
            ),
            self::entry(
                '/editrole <role> ; <field> <value> [; <field> <value>]',
                "change a role's settings as on the Roles screen; fields: name, emoji, people (places), plus"
                    . ' (+1 allowed), description, measured start|end, starts <minutes>, ends <minutes>, events'
                    . ' <title text>, minroles, timeseach, count <role> <number>, and on|off: archived,'
                    . ' restricted, everynight, salesdesk, guestdisplay, membersdisplay, guestsignup, anonymous;'
                    . ' - empties a text or time'
            ),
            self::entry('/optout', "show the post-event email's do-not-send list"),
            self::entry(
                '/optout <add|remove> <email>',
                'put an address on the post-event email\'s do-not-send list or take it off'
            ),
            self::entry('/aitext', "show the coming events' AI texts, with each text's name"),
            self::entry(
                '/aitext <date> <text name> <language> ; <text>',
                "write one event's AI text by hand in one language (sent as written); <language> is one of: "
                    . implode(', ', SiteLanguages::codes())
            ),
            self::entry('/aitext <date> <text name> ai', "use the AI's own text again for that event"),
        ];
    }

    /**
     * Lines only the Crew app page's helper box runs (AssistantWebChanges),
     * each asked with Confirm and Cancel there.
     *
     * @param array<string, mixed> $audience
     * @return array<int, array{usage: string, what: string, confirm: bool}>
     */
    private static function webEntries(array $audience): array
    {
        $list = [
            self::entry(
                'menu:language <code>',
                "change the person's own language; <code> is one of: "
                    . implode(', ', array_unique([...SiteLanguages::codes(), 'en']))
            ),
        ];

        if (! $audience['organizer']) {
            return $list;
        }

        $list[] = self::entry(
            'menu:gift <person> ; <credits> [; <reason>]',
            'give a member free-entry credits (a whole number, 1 or more); the member is told'
        );
        $list[] = self::entry(
            'menu:roles <add|remove> <role> ; <person>',
            'give a member a restricted role or take it away (role name as on the Roles screen); the member is told'
        );
        $list[] = self::entry(
            '/organizer <on|off> <person>',
            'make a member an organizer (on) or take the organizer role away (off); "make Anna organizer" is'
                . ' /organizer on Anna, never /assign. Never one\'s own off, never the last organizer'
        );
        $list[] = self::entry(
            '/deleteperson <person>',
            'delete a person and erase their data and history; cannot be undone; never oneself'
        );
        $list[] = self::entry(
            '/newcomer <ok|no> [person]',
            'give a waiting newcomer the OK (they can take tasks, and are told) or say No; without a name the one'
                . ' waiting'
        );

        return $list;
    }

    /**
     * The private-chat commands of ChatCommands: organizer ones first, then
     * the member ones everyone has.
     *
     * @param array{organizer: bool, welcomeCrew: bool, verified: bool} $audience
     * @return array<int, array{usage: string, what: string, confirm: bool}>
     */
    private static function chatCommandEntries(array $audience): array
    {
        $list = [];

        if ($audience['organizer']) {
            $list[] = self::entry(
                '/mark [date] <person> ; <arrived|late|noshow|done>',
                "set a member's mark for their task of the night (default: their latest task in the last days),"
                    . ' as the roster buttons do; without the word the bot shows the four marks as buttons; the'
                    . ' bot finds the person even from a partial name and confirms first'
            );
            $list[] = self::entry('/standing <person>', "a member's standing, credits and recent log");
            $list[] = self::entry(
                '/newcomer [person]',
                'a newcomer waiting for an OK, with the OK and No buttons; without a name the first one waiting'
            );
            $list[] = self::entry(
                '/ticket [date] <person>',
                "spend one of a member's credits on a free-entry ticket for the next night (or [date]); the bot"
                    . ' confirms first'
            );
            $list[] = self::entry(
                '/unticket [date] <person>',
                'cancel a free-entry ticket and give the credit back; the bot confirms first'
            );
            $list[] = self::entry('/unban <person>', 'lift a block on a member; the bot confirms first');
            $list[] = self::entry(
                '/freeentry [date] <close|open>',
                'close free entry for the next night (or [date]) so nobody can use a credit for it, or reopen it;'
                    . ' the bot confirms first'
            );
            $list[] = self::entry(
                '/stop announcement',
                'stop the next planned announcement (newsletter); the bot confirms first'
            );
            $list[] = self::entry(
                '/stop email [date]',
                'stop the post-event email of the next night that has one planned (or [date]); the bot confirms first'
            );
            $list[] = self::entry('/newboard', 'post a new board in the crew group; the bot confirms first');
            $list[] = self::entry(
                '/sendtickets [date]',
                'send the ticket emails of the next night (or [date]) that have not gone out yet; the bot'
                    . ' confirms first'
            );
            $list[] = self::entry(
                '/sendtickets cancel',
                'cancel the waiting ticket-email send; the bot confirms first'
            );
            $list[] = self::entry(
                '/tell [date] <task> ; <message>',
                'send a message to everyone on one task of the next event (or [date]): each gets a direct message'
                    . ' in their own language with a one-line header and the message, or an email when they have no'
                    . ' Telegram; <message> is the text itself, word for word as the organizer wrote it (line breaks'
                    . ' allowed), never reworded or translated unless the organizer asks; the bot shows it first'
                    . ' and confirms'
            );
        }

        $list[] = self::entry(
            '/crew',
            "the person's next nights with who works each task (names and roles only)"
        );
        $list[] = self::entry(
            '/name [new name]',
            "show or change the person's own name; the bot confirms first"
        );
        $list[] = self::entry(
            '/email [new address]',
            "show or change the person's own email address: a link goes to the new address and the address"
                . ' changes only when they open it; the bot confirms first'
        );

        return $list;
    }

    /**
     * Checks one action line from the helper.
     *
     * $userText is what the person wrote, which a guest's name and number must
     * come from; null skips that check (the action was already checked against
     * it when it was first proposed). $offeredTasks are the task ids the helper
     * was shown.
     *
     * @param array{organizer: bool, welcomeCrew: bool, verified: bool} $audience
     * @param array<int, int> $offeredTasks
     * @return array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}
     *     action is the line as it will run, payload the text or callback data.
     */
    public static function validate(
        string $action,
        array $audience,
        ?string $userText,
        string $today,
        array $offeredTasks
    ): array {
        // Checked before the spaces are squeezed: a newsletter text keeps its
        // line breaks.
        if (1 === preg_match('#^\s*/newsletter(?=\s|$)(.*)$#isu', $action, $m)) {
            return self::validateNewsletter($m[1], $audience);
        }

        // The same for a post-event email text.
        if (1 === preg_match('#^\s*/followup(?=\s|$)(.*)$#isu', $action, $m)) {
            return self::validateFollowup($m[1], $audience);
        }

        // The same for a message for the group: kept exactly as written.
        if (1 === preg_match('#^\s*/say(?=\s|$)(.*)$#isu', $action, $m)) {
            return self::validateSay($m[1], $audience);
        }

        // A message for one task's crew: kept exactly as written (ag-people).
        if (1 === preg_match('#^\s*/tell(?=\s|$)(.*)$#isu', $action, $m)) {
            return self::validateTell($m[1], $audience);
        }

        $action = trim((string) preg_replace('/\s+/u', ' ', $action));
        $parts = explode(' ', $action, 2);
        $head = strtolower($parts[0]);
        $rest = trim($parts[1] ?? '');
        $staff = $audience['organizer'] || $audience['welcomeCrew'];

        // menu:ticket <date>: that night's button of the free-entry picker
        // (TicketRedemptionService::onSelect() asks before it spends).
        if ('menu:ticket' === $head && '' !== $rest) {
            $date = Dates::parseUserDate($rest, $today);

            return '' === $date
                ? self::refuse(self::ERROR_INVALID)
                : self::accept('menu:ticket ' . $date, false, self::RUN_TAP, 'tkt:' . $date);
        }

        // parityB: the app actions that had no helper line (AssistantParityLines).
        $parity = AssistantParityLines::validate($head, $rest, $audience);

        if (null !== $parity) {
            return $parity;
        }

        // The Crew app page's own lines (webEntries()).
        if (! empty($audience['web'])) {
            $web = self::validateWeb($head, $rest, $audience);

            if (null !== $web) {
                return $web;
            }
        }

        // parityA: the Admin tab's other changes, and the page's lines in the chat.
        $parityA = self::validateParityA($head, $rest, $audience);

        if (null !== $parityA) {
            return $parityA;
        }

        if (isset(self::MENU[$head])) {
            if ('' !== $rest) {
                return self::refuse(self::ERROR_INVALID);
            }

            if (in_array($head, self::MENU_ORGANIZER, true) && ! $audience['organizer']) {
                return self::refuse(self::ERROR_NOT_PERMITTED);
            }

            if (in_array($head, self::MENU_WELCOME, true) && ! $staff) {
                return self::refuse(self::ERROR_NOT_PERMITTED);
            }

            return self::accept($head, false, self::RUN_TAP, self::MENU[$head]);
        }

        switch ($head) {
            case '/help':
            case '/start':
            case '/board':
            case '/tasks':
            case '/tickets':
            case '/settings':
                return '' === $rest
                    ? self::accept($head, false, self::RUN_MESSAGE, $head)
                    : self::refuse(self::ERROR_INVALID);

            case '/people':
            case '/events':
                if (! $audience['organizer']) {
                    return self::refuse(self::ERROR_NOT_PERMITTED);
                }

                return '' === $rest
                    ? self::accept($head, false, self::RUN_MESSAGE, $head)
                    : self::refuse(self::ERROR_INVALID);

            case '/subscribe':
                if (! $audience['organizer']) {
                    return self::refuse(self::ERROR_NOT_PERMITTED);
                }

                $words = '' === $rest ? [] : explode(' ', $rest);

                if ([] === $words || count($words) > 2 || ! is_email($words[0])) {
                    return self::refuse(self::ERROR_INVALID);
                }

                $line = '/subscribe ' . implode(' ', $words);

                return self::accept($line, true, self::RUN_MESSAGE, $line);

            case '/me':
                $rest = strtolower($rest);

                if (! in_array($rest, ['', 'history', 'tickets'], true)) {
                    return self::refuse(self::ERROR_INVALID);
                }

                $line = trim('/me ' . $rest);

                return self::accept($line, false, self::RUN_MESSAGE, $line);

            case '/join':
            case '/leave':
                return self::validateTask($head, $rest, $audience, $offeredTasks);

            case '/roster':
                return self::withOptionalDate('/roster', $rest, $today, self::RUN_MESSAGE, '/roster');

            case 'menu:doorlist':
            case 'menu:guestlist':
                if (! $staff) {
                    return self::refuse(self::ERROR_NOT_PERMITTED);
                }

                $prefix = 'menu:doorlist' === $head ? MenuButtons::DOOR_LIST : MenuButtons::GUEST_LIST;

                return self::withOptionalDate($head, $rest, $today, self::RUN_TAP, $prefix);

            case '/door':
                return self::validateDoor($rest, $audience, $userText, $today);

            case '/assign':
            case '/unassign':
                return self::validateAssign($head, $rest, $audience, $today);

            // Private-chat commands for organizers and members (ag-people).
            case '/mark':
            case '/standing':
            case '/newcomer':
            case '/ticket':
            case '/unticket':
            case '/unban':
            case '/freeentry':
            case '/stop':
            case '/newboard':
            case '/sendtickets':
            case '/crew':
            case '/name':
            case '/email':
                return self::validateChatCommand($head, $rest, $audience);

            // Task commands (ag-tasks).
            case '/addtask':
            case '/edittask':
            case '/deletetask':
            case '/maketasks':
            case '/mailtasks':
                return self::validateTaskCommand($head, $rest, $audience, $today);
        }

        return self::refuse(self::ERROR_UNKNOWN);
    }

    /**
     * A line of webEntries(), or null when $head is not one of them (or
     * has no value, as the bot's own forms are checked as usual).
     *
     * @param array<string, mixed> $audience
     * @return array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}|null
     */
    private static function validateWeb(string $head, string $rest, array $audience): ?array
    {
        $organizerOnly = ['menu:gift', 'menu:roles', '/organizer', '/deleteperson'];

        if ('menu:language' === $head) {
            if ('' === $rest) {
                return null;
            }
        } elseif ('/newcomer' === $head) {
            if (! in_array(strtolower((string) strtok($rest, ' ')), ['ok', 'no'], true)) {
                return null;
            }

            if (! $audience['organizer']) {
                return self::refuse(self::ERROR_NOT_PERMITTED);
            }
        } elseif (in_array($head, $organizerOnly, true)) {
            if ('' === $rest && str_starts_with($head, 'menu:')) {
                return null;
            }

            if (! $audience['organizer']) {
                return self::refuse(self::ERROR_NOT_PERMITTED);
            }

            if ('' === $rest) {
                return self::refuse(self::ERROR_INVALID);
            }
        } else {
            return null;
        }

        $line = $head . ' ' . $rest;

        return self::accept($line, false, self::RUN_MESSAGE, $line);
    }

    /**
     * parityA: the lines of parityAEntries() (page and chat), and in the
     * chat the page's own lines (webEntries()), each run through the bot
     * command that asks and confirms with ✅ itself (AdminChangeCommands),
     * the language with Confirm first. Null when $head is none of them.
     *
     * @param array<string, mixed> $audience
     * @return array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}|null
     */
    private static function validateParityA(string $head, string $rest, array $audience): ?array
    {
        $commands = ['/oncemore', '/addperson', '/editperson', '/editrole', '/optout', '/aitext'];

        if (in_array($head, $commands, true)) {
            if (empty($audience['organizer'])) {
                return self::refuse(self::ERROR_NOT_PERMITTED);
            }

            $optional = ['/optout', '/aitext'];

            if ('' === $rest && ! in_array($head, $optional, true)) {
                return self::refuse(self::ERROR_INVALID);
            }

            if ('/optout' === $head && '' !== $rest) {
                $words = explode(' ', $rest);

                $op = strtolower($words[0]);
                $email = $words[1] ?? '';

                if (2 !== count($words) || ! in_array($op, ['add', 'remove'], true) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return self::refuse(self::ERROR_INVALID);
                }
            }

            $line = trim($head . ' ' . $rest);

            return self::accept($line, false, self::RUN_MESSAGE, $line);
        }

        // On the page these are validateWeb()'s.
        if (! empty($audience['web']) || '' === $rest) {
            return null;
        }

        if ('menu:language' === $head) {
            $code = strtolower($rest);

            if (! in_array($code, array_unique([...SiteLanguages::codes(), 'en']), true)) {
                return self::refuse(self::ERROR_INVALID);
            }

            return self::accept('menu:language ' . $code, true, self::RUN_TAP, LanguageService::PREFIX . $code);
        }

        $commandOf = [
            'menu:gift' => '/' . AdminChangeCommands::GIFT,
            'menu:roles' => '/' . AdminChangeCommands::ROLE,
            '/organizer' => '/organizer',
            '/deleteperson' => '/deleteperson',
        ];

        if (! isset($commandOf[$head])) {
            return null;
        }

        if (empty($audience['organizer'])) {
            return self::refuse(self::ERROR_NOT_PERMITTED);
        }

        return self::accept($head . ' ' . $rest, false, self::RUN_MESSAGE, $commandOf[$head] . ' ' . $rest);
    }

    /**
     * "/assign [date] <task> ; <person>" and "/unassign ...": organizers only.
     * Not confirmed here, because the command itself finds the task and the
     * member, asks when a name is unclear, and confirms with ✅ / ✖️.
     *
     * @param array{organizer: bool, welcomeCrew: bool, verified: bool} $audience
     * @return array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}
     */
    private static function validateAssign(string $head, string $rest, array $audience, string $today): array
    {
        if (! $audience['organizer']) {
            return self::refuse(self::ERROR_NOT_PERMITTED);
        }

        $whole = $rest;
        $parts = preg_split('/\s+/u', $rest, 2) ?: [];

        if ('' !== Dates::parseUserDate((string) ($parts[0] ?? ''), $today)) {
            $rest = trim((string) ($parts[1] ?? ''));
        }

        $sides = explode(';', $rest, 2);

        if (2 !== count($sides) || '' === trim($sides[0]) || '' === trim($sides[1])) {
            return self::refuse(self::ERROR_INVALID);
        }

        $line = $head . ' ' . $whole;

        return self::accept($line, false, self::RUN_MESSAGE, $line);
    }

    /**
     * The private-chat commands of ChatCommands, other than /tell. Not
     * confirmed here: each command finds the person or night itself, asks
     * when unsure and confirms every change with ✅ / ✖️.
     *
     * @param array{organizer: bool, welcomeCrew: bool, verified: bool} $audience
     * @return array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}
     */
    private static function validateChatCommand(string $head, string $rest, array $audience): array
    {
        $member = ['/crew', '/name', '/email'];

        if (! in_array($head, $member, true) && ! $audience['organizer']) {
            return self::refuse(self::ERROR_NOT_PERMITTED);
        }

        $needsText = ['/mark', '/standing', '/ticket', '/unticket', '/unban', '/stop'];
        $noText = ['/newboard', '/crew'];

        if ((in_array($head, $needsText, true) && '' === $rest) || (in_array($head, $noText, true) && '' !== $rest)) {
            return self::refuse(self::ERROR_INVALID);
        }

        if ('/email' === $head && str_contains($rest, ' ')) {
            return self::refuse(self::ERROR_INVALID);
        }

        $line = trim($head . ' ' . $rest);

        return self::accept($line, false, self::RUN_MESSAGE, $line);
    }

    /**
     * The task commands (/addtask, /edittask, /deletetask, /maketasks,
     * /mailtasks): organizers only. Only the shape is checked here; the
     * command itself finds the task, role or event, asks when unclear, and
     * confirms with ✅ / ✖️.
     *
     * @param array{organizer: bool, welcomeCrew: bool, verified: bool} $audience
     * @return array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}
     */
    private static function validateTaskCommand(string $head, string $rest, array $audience, string $today): array
    {
        if (! $audience['organizer']) {
            return self::refuse(self::ERROR_NOT_PERMITTED);
        }

        $parts = preg_split('/\s+/u', $rest, 2) ?: [];
        $hasDate = '' !== Dates::parseUserDate((string) ($parts[0] ?? ''), $today);
        $body = $hasDate ? trim((string) ($parts[1] ?? '')) : $rest;
        $sides = explode(';', $body, 2);
        $name = trim($sides[0]);
        $fields = trim((string) ($sides[1] ?? ''));

        $ok = match ($head) {
            '/mailtasks' => '' === $rest,
            '/maketasks' => '' === $rest || ($hasDate && '' === $body),
            '/deletetask' => '' !== $name && 1 === count($sides),
            '/edittask' => '' !== $name && '' !== $fields,
            default => '' !== $name,
        };

        if (! $ok) {
            return self::refuse(self::ERROR_INVALID);
        }

        $line = trim($head . ' ' . $rest);

        return self::accept($line, false, self::RUN_MESSAGE, $line);
    }

    /**
     * "/tell [date] <task> ; <message>": organizers only, the message kept
     * with its line breaks. TellCommand shows it and confirms before sending.
     *
     * @param array{organizer: bool, welcomeCrew: bool, verified: bool} $audience
     * @return array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}
     */
    private static function validateTell(string $rest, array $audience): array
    {
        if (! $audience['organizer']) {
            return self::refuse(self::ERROR_NOT_PERMITTED);
        }

        $text = trim(str_replace("\r\n", "\n", $rest));
        $sides = explode(';', $text, 2);

        $message = trim($sides[1] ?? '');

        $tooLong = mb_strlen($message) > TellCommand::MAX_LENGTH;

        if (2 !== count($sides) || '' === trim($sides[0]) || '' === $message || $tooLong) {
            return self::refuse(self::ERROR_INVALID);
        }

        $line = '/tell ' . $text;

        return self::accept($line, false, self::RUN_MESSAGE, $line);
    }

    /**
     * @param array{organizer: bool, welcomeCrew: bool, verified: bool} $audience
     * @param array<int, int> $offeredTasks
     * @return array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}
     */
    private static function validateTask(string $head, string $rest, array $audience, array $offeredTasks): array
    {
        if (1 !== preg_match('/^#?(\d{1,10})$/', $rest, $m)) {
            return self::refuse(self::ERROR_INVALID);
        }

        $taskId = (int) $m[1];

        if (! in_array($taskId, array_map('intval', $offeredTasks), true)) {
            return self::refuse(self::ERROR_INVALID);
        }

        if (! $audience['verified']) {
            return self::refuse(self::ERROR_NOT_REGISTERED);
        }

        // The board's own buttons: j:<id> joins, l:<id> leaves. A leave
        // needs no question of its own: the board asks first, with what the
        // cancel costs (BoardService::askCancel()).
        $join = '/join' === $head;
        $data = ($join ? 'j:' : 'l:') . $taskId;

        return self::accept($head . ' ' . $taskId, $join, self::RUN_TAP, $data);
    }

    /**
     * @param array{organizer: bool, welcomeCrew: bool, verified: bool} $audience
     * @return array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}
     */
    private static function validateDoor(string $rest, array $audience, ?string $userText, string $today): array
    {
        $parsed = GuestCommand::parse($rest, $today);

        if (GuestCommand::SHOW === $parsed['action']) {
            if (! $audience['organizer'] && ! $audience['welcomeCrew']) {
                return self::refuse(self::ERROR_NOT_PERMITTED);
            }

            return self::withOptionalDate('/door', $rest, $today, self::RUN_MESSAGE, '/door');
        }

        if (! $audience['organizer']) {
            return self::refuse(self::ERROR_NOT_PERMITTED);
        }

        if ('' !== $parsed['error']) {
            return self::refuse(self::ERROR_INVALID);
        }

        if (null !== $userText) {
            $said = self::flatten($userText);

            $numberSaid = 1 === preg_match('/(?<!\d)' . $parsed['number'] . '(?!\d)/', $said);

            if (GuestCommand::ADD !== $parsed['action'] && ! $numberSaid) {
                return self::refuse(self::ERROR_INVALID);
            }

            foreach (['name', 'note'] as $field) {
                $value = $parsed[$field];

                if (null !== $value && '' !== trim($value) && false === mb_stripos($said, self::flatten($value))) {
                    return self::refuse(self::ERROR_INVALID);
                }
            }
        }

        $line = '/door ' . $rest;

        return self::accept($line, true, self::RUN_MESSAGE, $line);
    }

    /**
     * "/newsletter" shows the text for the next newsletter, "/newsletter
     * reset" (or clear) resets it to the default, and "/newsletter <text>" sets it. Organizers only.
     * Setting and removing ask to confirm, and the text shown with the
     * Confirm button is exactly the text that is saved.
     *
     * @param array{organizer: bool, welcomeCrew: bool, verified: bool} $audience
     * @return array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}
     */
    private static function validateNewsletter(string $rest, array $audience): array
    {
        if (! $audience['organizer']) {
            return self::refuse(self::ERROR_NOT_PERMITTED);
        }

        // Spaces are squeezed within each line; a run of empty lines becomes one.
        $lines = array_map(
            static fn (string $line): string => trim((string) preg_replace('/[^\S\n]+/u', ' ', $line)),
            explode("\n", str_replace("\r\n", "\n", $rest))
        );
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));

        if ('' === $text) {
            return self::accept('/newsletter', false, self::RUN_MESSAGE, '/newsletter');
        }

        if (in_array(strtolower($text), ['clear', 'reset'], true)) {
            return self::accept('/newsletter reset', true, self::RUN_MESSAGE, '/newsletter reset');
        }

        if (mb_strlen($text) > NewsletterText::MAX_LENGTH) {
            return self::refuse(self::ERROR_INVALID);
        }

        $line = '/newsletter ' . $text;

        return self::accept($line, true, self::RUN_MESSAGE, $line);
    }

    /**
     * "/followup" shows the post-event email's texts, "/followup reset"
     * (or clear) resets both one-time texts, "/followup came|missed reset" one,
     * and "/followup came|missed <text>" sets one. Organizers only. Setting
     * and removing ask to confirm, and the text shown with the Confirm button
     * is exactly the text that is saved.
     *
     * @param array{organizer: bool, welcomeCrew: bool, verified: bool} $audience
     * @return array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}
     */
    private static function validateFollowup(string $rest, array $audience): array
    {
        if (! $audience['organizer']) {
            return self::refuse(self::ERROR_NOT_PERMITTED);
        }

        // Spaces are squeezed within each line; a run of empty lines becomes one.
        $lines = array_map(
            static fn (string $line): string => trim((string) preg_replace('/[^\S\n]+/u', ' ', $line)),
            explode("\n", str_replace("\r\n", "\n", $rest))
        );
        $rest = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));

        if ('' === $rest) {
            return self::accept('/followup', false, self::RUN_MESSAGE, '/followup');
        }

        if (in_array(strtolower($rest), ['clear', 'reset'], true)) {
            return self::accept('/followup reset', true, self::RUN_MESSAGE, '/followup reset');
        }

        $parts = preg_split('/\s+/u', $rest, 2);
        $variant = strtolower((string) ($parts[0] ?? ''));
        $text = trim((string) ($parts[1] ?? ''));

        if (! FollowupText::isVariant($variant) || '' === $text) {
            return self::refuse(self::ERROR_INVALID);
        }

        if (in_array(strtolower($text), ['clear', 'reset'], true)) {
            $line = '/followup ' . $variant . ' reset';

            return self::accept($line, true, self::RUN_MESSAGE, $line);
        }

        if (mb_strlen($text) > FollowupText::MAX_LENGTH) {
            return self::refuse(self::ERROR_INVALID);
        }

        $line = '/followup ' . $variant . ' ' . $text;

        return self::accept($line, true, self::RUN_MESSAGE, $line);
    }

    /**
     * "/say <text>": a message for the group. Organizers only. It does not
     * ask to confirm here, because /say itself shows the message with Confirm
     * and Cancel. The text is kept exactly (only trimmed), so the formatting of
     * the person's own message can be carried onto it.
     *
     * @param array{organizer: bool, welcomeCrew: bool, verified: bool} $audience
     * @return array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}
     */
    private static function validateSay(string $rest, array $audience): array
    {
        if (! $audience['organizer']) {
            return self::refuse(self::ERROR_NOT_PERMITTED);
        }

        $text = trim(str_replace("\r\n", "\n", $rest));

        if ('' === $text) {
            return self::accept('/say', false, self::RUN_MESSAGE, '/say');
        }

        if (mb_strlen($text) > SayCommand::MAX_LENGTH) {
            return self::refuse(self::ERROR_INVALID);
        }

        $line = '/say ' . $text;

        return self::accept($line, false, self::RUN_MESSAGE, $line);
    }

    /**
     * @return array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}
     */
    private static function withOptionalDate(
        string $head,
        string $rest,
        string $today,
        string $run,
        string $payload
    ): array {
        if ('' === $rest) {
            return self::accept($head, false, $run, self::RUN_TAP === $run ? $payload : $head);
        }

        $date = Dates::parseUserDate($rest, $today);

        if ('' === $date) {
            return self::refuse(self::ERROR_INVALID);
        }

        $line = $head . ' ' . $date;

        return self::accept($line, false, $run, self::RUN_TAP === $run ? $payload . $date : $line);
    }

    private static function flatten(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @return array{usage: string, what: string, confirm: bool}
     */
    private static function entry(string $usage, string $what, bool $confirm = false): array
    {
        return ['usage' => $usage, 'what' => $what, 'confirm' => $confirm];
    }

    /**
     * @return array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}
     */
    private static function accept(string $action, bool $confirm, string $run, string $payload): array
    {
        return [
            'ok' => true,
            'error' => '',
            'action' => $action,
            'confirm' => $confirm,
            'run' => $run,
            'payload' => $payload,
        ];
    }

    /**
     * @return array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}
     */
    private static function refuse(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'action' => '', 'confirm' => false, 'run' => '', 'payload' => ''];
    }
}

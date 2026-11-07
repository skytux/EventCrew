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
            self::entry('/help', 'list the commands this person can use'),
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
            self::entry('menu:ticket', 'get the free-entry ticket for a night'),
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
                'put a member on a task of the next event (or of [date]): any task but Organize, also one for a role'
                    . ' the person does not hold (the bot warns and gives them the role). <task> is the task name'
                    . ' (Host, Door, ...), <person> the name as the organizer wrote it, even partly or misspelled:'
                    . ' the bot finds the member, asks with buttons when several match, says when the task is full,'
                    . ' and asks the organizer to confirm before anything is written; the person then gets the'
                    . ' normal sign-up confirmation and ticket. Someone who is not a member yet: /board, ➕ Add someone',
                false
            );
            $list[] = self::entry(
                '/unassign [date] <task> ; <person>',
                'take a member off a task of the next event (or of [date]); same names as /assign; the bot asks the'
                    . ' organizer to confirm first',
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

        $action = trim((string) preg_replace('/\s+/u', ' ', $action));
        $parts = explode(' ', $action, 2);
        $head = strtolower($parts[0]);
        $rest = trim($parts[1] ?? '');
        $staff = $audience['organizer'] || $audience['welcomeCrew'];

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
        }

        return self::refuse(self::ERROR_UNKNOWN);
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

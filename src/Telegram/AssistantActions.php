<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Support\Dates;
use EventCrew\Support\GuestCommand;
use EventCrew\Support\NewsletterText;

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
            self::entry('/board', 'the open tasks as buttons to tap'),
            self::entry('/join <task>', 'sign the person up for a task; <task> is a task number from Open tasks', true),
            self::entry('/leave <task>', 'take the person off a task they signed up for; <task> as above', true),
            self::entry('menu:ticket', 'get the free-entry ticket for a night'),
            self::entry('menu:replace', "take over someone else's task (the bot then asks whose)"),
            self::entry('menu:web', 'a sign-in link for the crew web page'),
            self::entry('menu:notifications', 'choose which notifications the person gets'),
            self::entry('menu:language', 'choose Finnish or English for the bot'),
        ];

        if ($audience['organizer'] || $audience['welcomeCrew']) {
            $list[] = self::entry('/roster [date]', 'who works that night, and marking them arrived or done');
            $list[] = self::entry('/door [date]', 'the door buttons: door list, guest list, sales desk');
            $list[] = self::entry('menu:doorlist [date]', 'the door list of a night');
            $list[] = self::entry('menu:guestlist [date]', 'the guest list of a night');
            $list[] = self::entry('menu:sales', 'the sales desk link');
        }

        if ($audience['organizer']) {
            $list[] = self::entry('/door add [date] <name> [+N] [; note]', 'add a guest to the guest list', true);
            $list[] = self::entry(
                '/door edit [date] <number> [name] [+N] [; note]',
                'change a guest; <number> is the guest\'s number on the guest list',
                true
            );
            $list[] = self::entry('/door remove [date] <number>', 'take a guest off the guest list', true);
            $list[] = self::entry('/people', 'the people buttons: credits, roles, who may hold each role');
            $list[] = self::entry('menu:gift', 'give someone credits (the bot then asks who)');
            $list[] = self::entry('menu:roles', 'give or take roles, organizers and passes (the bot then asks who)');
            $list[] = self::entry('menu:eligible', 'who may hold each role');
            $list[] = self::entry('/newsletter', "show the organizers' own text for the next newsletter");
            $list[] = self::entry(
                '/newsletter <text>',
                "set the organizers' own text for the next newsletter, replacing any earlier one; it is translated"
                    . ' so the mail carries it in Finnish and English; <text> is the text itself, as the person'
                    . ' wants it sent (line breaks allowed)',
                true
            );
            $list[] = self::entry(
                '/newsletter clear',
                "remove the organizers' own text from the next newsletter",
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
                return '' === $rest
                    ? self::accept($head, false, self::RUN_MESSAGE, $head)
                    : self::refuse(self::ERROR_INVALID);

            case '/people':
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
                if (! $staff) {
                    return self::refuse(self::ERROR_NOT_PERMITTED);
                }

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
        }

        return self::refuse(self::ERROR_UNKNOWN);
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

        // The board's own buttons: j:<id> joins, l:<id> leaves.
        $data = ('/join' === $head ? 'j:' : 'l:') . $taskId;

        return self::accept($head . ' ' . $taskId, true, self::RUN_TAP, $data);
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
     * clear" removes it, and "/newsletter <text>" sets it. Organizers only.
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

        if ('clear' === strtolower($text)) {
            return self::accept('/newsletter clear', true, self::RUN_MESSAGE, '/newsletter clear');
        }

        if (mb_strlen($text) > NewsletterText::MAX_LENGTH) {
            return self::refuse(self::ERROR_INVALID);
        }

        $line = '/newsletter ' . $text;

        return self::accept($line, true, self::RUN_MESSAGE, $line);
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

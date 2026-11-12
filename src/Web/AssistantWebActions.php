<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Support\HelpPage;

/**
 * How an action the helper chose (AssistantActions, the bot's own list)
 * runs on the Crew app page instead of in Telegram.
 *
 * - confirm: signing up for a task or cancelling one. The page asks with
 *   Confirm and Cancel first, then runs the page's own sign-up or cancel
 *   (SignupController), with the same checks. Cancelling asks too: on the
 *   page there is no second question about what the cancel costs.
 * - goto: what the bot would show, the page already has: the page opens
 *   that tab and scrolls to the card ("targets": element ids, the first one
 *   on the page wins; the last is always a tab).
 * - telegram: only the bot does it; the page offers to open the bot.
 */
final class AssistantWebActions
{
    public const CONFIRM = 'confirm';
    public const GOTO = 'goto';
    public const TELEGRAM = 'telegram';

    public const JOIN = 'join';
    public const LEAVE = 'leave';

    /** @var array<string, list<string>> action line => element ids */
    private const PLACES = [
        '/board' => ['eventcrew-panel-board'],
        '/me' => ['eventcrew-my-tasks', 'eventcrew-panel-me'],
        '/me history' => ['eventcrew-past-tasks', 'eventcrew-panel-me'],
        '/me tickets' => ['eventcrew-my-tickets', 'eventcrew-panel-me'],
        '/tasks' => ['eventcrew-my-tasks', 'eventcrew-panel-me'],
        '/tickets' => ['eventcrew-my-tickets', 'eventcrew-get-ticket', 'eventcrew-panel-me'],
        'menu:ticket' => ['eventcrew-get-ticket', 'eventcrew-my-tickets', 'eventcrew-panel-me'],
        // Taking over a task is a button on the full task, on the board.
        'menu:replace' => ['eventcrew-panel-board'],
        '/settings' => ['eventcrew-panel-settings'],
        'menu:notifications' => ['eventcrew-notifications', 'eventcrew-panel-settings'],
        'menu:language' => ['eventcrew-settings-language', 'eventcrew-panel-settings'],
        // Signed in already: the sign-in link is this page.
        'menu:web' => ['eventcrew-panel-me'],
        '/help' => [HelpPage::PANEL_ANCHOR],
        '/start' => [HelpPage::PANEL_ANCHOR],
    ];

    /** Changing the name or email is the Settings tab's details card. */
    private const SETTINGS_COMMANDS = ['/name', '/email'];

    /**
     * What the page does with a checked action line ("/join 12", "/me history",
     * "menu:ticket", "/roster 14.11.").
     *
     * @return array{kind: string, op?: string, task?: int, targets?: list<string>}
     */
    public static function plan(string $action): array
    {
        $line = strtolower(trim((string) preg_replace('/\s+/u', ' ', $action)));

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

        $head = explode(' ', $line, 2)[0];

        if (in_array($head, self::SETTINGS_COMMANDS, true)) {
            return ['kind' => self::GOTO, 'targets' => ['eventcrew-panel-settings']];
        }

        return ['kind' => self::TELEGRAM];
    }

    /**
     * The answer split into plain text and web links, for the page to put
     * together with textContent and href, never as HTML: what the model
     * wrote is never markup. Only http and https addresses become links.
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
            $segments[] = ['t' => $url, 'u' => $url];

            if ('' !== $tail) {
                $segments[] = ['t' => $tail];
            }
        }

        return $segments;
    }
}

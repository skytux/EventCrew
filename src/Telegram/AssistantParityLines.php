<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Support\ChannelChoice;
use EventCrew\Support\NotificationPreferences;

/**
 * The helper's lines for the Crew app actions that had none (parityB), in
 * Telegram and in the Crew app's helper box alike. Each runs the bot's own
 * command or button, which asks with ✅ / ✖️ before it changes anything, so
 * the helper adds no question of its own (except /notify and the
 * notification lines, whose bot command sets the switch at once).
 *
 * AssistantActions::catalogue() lists entries(), AssistantActions::validate()
 * asks validate() first.
 */
final class AssistantParityLines
{
    /** Organizer commands of ParityCommands. */
    private const ORGANIZER = ['/markall', '/buyers', '/testmail', '/checkmail'];

    /**
     * @param array<string, mixed> $audience
     * @return list<array{usage: string, what: string, confirm: bool}>
     */
    public static function entries(array $audience): array
    {
        $types = implode(', ', array_keys(NotificationPreferences::labels(! empty($audience['organizer']))));
        $list = [
            self::entry('menu:signout', 'sign the person out of the crew web page on every browser and phone (asked first)'),
            self::entry(
                'menu:replace <task>',
                'ask to take over someone else\'s place on that task (<task> as for /join); the holder decides;'
                    . ' the bot shows whose places can be asked for'
            ),
            self::entry(
                'menu:notifications <type> <telegram|email|app> <on|off>',
                'turn one notification on or off; <type> is one of: ' . $types,
                true
            ),
            self::entry(
                'menu:notifications <app|telegram|both>',
                'where notifications go when the person uses both the app and Telegram',
                true
            ),
            self::entry(
                '/giveaway [date] [<person or email>] [; <their name>]',
                "give one of the person's own free-entry tickets to another member, or offer it by email to"
                    . ' someone else (their name after ;); without a name the bot asks who; asked first'
            ),
            self::entry('/giveaway cancel [date]', "take back the person's ticket offer that is still waiting"),
        ];

        if (empty($audience['organizer'])) {
            return $list;
        }

        $list[] = self::entry(
            '/markall [date] <task> ; <arrived|done>',
            'mark everyone still signed up on a task of the night (default: the night the roster shows) as'
                . ' arrived or done; asked first'
        );
        $list[] = self::entry(
            '/buyers [date] add <name> [; <name> ...]',
            "add Holvi ticket buyers to a night's ticket list by name (default: the next night); asked first"
        );
        $list[] = self::entry(
            '/buyers [date] reset',
            "delete every imported ticket buyer of a night (emails already sent stay sent); asked first"
        );
        $list[] = self::entry(
            '/testmail <tickets|followup> [date]',
            'send a test ticket email (tickets) or a test post-event email (followup) to the organizer\'s own'
                . ' address only; asked first'
        );
        $list[] = self::entry('/checkmail', 'check the mailbox for ticket and guest lists now; asked first');

        return $list;
    }

    /**
     * A parityB line, or null when $head is none of them (the usual checks
     * follow).
     *
     * @param array<string, mixed> $audience
     * @return array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}|null
     */
    public static function validate(string $head, string $rest, array $audience): ?array
    {
        if ('menu:signout' === $head) {
            return '' === $rest
                ? self::accept($head, false, AssistantActions::RUN_TAP, MenuButtons::SIGN_OUT)
                : self::refuse(AssistantActions::ERROR_INVALID);
        }

        // Bare menu:replace and menu:notifications stay the menu buttons.
        if ('menu:replace' === $head && '' !== $rest) {
            return 1 === preg_match('/^\d{1,10}$/', $rest)
                ? self::accept($head . ' ' . $rest, false, AssistantActions::RUN_TAP, ReplacementService::TASK_PREFIX . $rest)
                : self::refuse(AssistantActions::ERROR_INVALID);
        }

        if (('menu:notifications' === $head && '' !== $rest) || '/notify' === $head) {
            return self::notifications($head, strtolower($rest), $audience);
        }

        if ('/giveaway' === $head) {
            $line = trim($head . ' ' . $rest);

            return self::accept($line, false, AssistantActions::RUN_MESSAGE, $line);
        }

        if (! in_array($head, self::ORGANIZER, true)) {
            return null;
        }

        if (empty($audience['organizer'])) {
            return self::refuse(AssistantActions::ERROR_NOT_PERMITTED);
        }

        if (('/checkmail' === $head) !== ('' === $rest)) {
            return self::refuse(AssistantActions::ERROR_INVALID);
        }

        $line = trim($head . ' ' . $rest);

        return self::accept($line, false, AssistantActions::RUN_MESSAGE, $line);
    }

    /**
     * menu:notifications (or /notify) app|telegram|both: the settings row's
     * button; <type> <channel> <on|off>: /notify, asked first here.
     *
     * @param array<string, mixed> $audience
     * @return array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}
     */
    private static function notifications(string $head, string $rest, array $audience): array
    {
        $choice = ChannelChoice::parse($rest);

        if (null !== $choice) {
            return self::accept(
                $head . ' ' . $choice,
                true,
                AssistantActions::RUN_TAP,
                NotificationSettingsService::CHOICE_PREFIX . $choice
            );
        }

        $words = explode(' ', $rest);

        if (
            3 !== count($words)
            || ! array_key_exists(str_replace('-', '_', $words[0]), NotificationPreferences::labels(! empty($audience['organizer'])))
            || ! isset(ParityCommands::CHANNELS[$words[1]])
            || ! in_array($words[2], ['on', 'off'], true)
        ) {
            return self::refuse(AssistantActions::ERROR_INVALID);
        }

        return self::accept($head . ' ' . $rest, true, AssistantActions::RUN_MESSAGE, '/notify ' . $rest);
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
        return ['ok' => true, 'error' => '', 'action' => $action, 'confirm' => $confirm, 'run' => $run, 'payload' => $payload];
    }

    /**
     * @return array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}
     */
    private static function refuse(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'action' => '', 'confirm' => false, 'run' => '', 'payload' => ''];
    }
}

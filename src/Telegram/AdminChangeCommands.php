<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use Closure;
use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Web\AssistantWebAdmin;
use EventCrew\Web\AssistantWebPeople;

/**
 * The Crew app Admin tab's changes as typed private-chat commands for
 * organizers (parityA, Owner 2026-11-14: what the app does, the bot does
 * too). Each asks the very question the helper box asks on the page
 * (AssistantWebPeople, AssistantWebAdmin), shows a button per person when
 * several match, and runs the Admin tab's own method after ✅:
 *
 * - /gift <person> ; <credits> [; <reason>]
 * - /role <add|remove> <role> ; <person>
 * - /organizer <on|off> <person>
 * - /deleteperson <person>, with a warning: the data is erased
 * - /oncemore <person>
 * - /addperson <name> ; <email> [; <notes>]
 * - /editperson <person> ; name … ; email … ; notes …
 * - /editrole <role> ; <field> <value> ; …
 * - /optout (the list), /optout add|remove <email>
 * - /aitext (the coming events' texts), /aitext <date> <text> <language> ; <text>
 * - /scheduled (the list), /scheduled <message> on|off (at once, no ✅:
 *   easy to undo, sends nothing), /scheduled <message> send
 *
 * The helper in Telegram runs its lines for these through the command
 * (AssistantActions), so the command's own ✅ is the one confirmation.
 */
final class AdminChangeCommands
{
    public const PREFIX = 'ac:';
    public const STATE_PREFIX = 'eventcrew_tg_admin_';

    public const GIFT = 'gift';
    public const ROLE = 'role';
    public const ORGANIZER = AssistantWebPeople::ORGANIZER;
    public const DELETE = AssistantWebPeople::DELETE;

    /** The commands this class answers. */
    public const COMMANDS = [
        self::GIFT,
        self::ROLE,
        self::ORGANIZER,
        self::DELETE,
        AssistantWebAdmin::AT_RISK_ONCE_MORE,
        AssistantWebAdmin::ADD_PERSON,
        AssistantWebAdmin::EDIT_PERSON,
        AssistantWebAdmin::EDIT_ROLE,
        AssistantWebAdmin::OPTOUT,
        AssistantWebAdmin::AI_TEXT,
        AssistantWebAdmin::SCHEDULED,
    ];

    /** Bot command => AssistantWebPeople command. */
    private const PEOPLE = [
        self::GIFT => AssistantWebPeople::GIFT,
        self::ROLE => AssistantWebPeople::ROLES,
        self::ORGANIZER => AssistantWebPeople::ORGANIZER,
        self::DELETE => AssistantWebPeople::DELETE,
    ];

    private CommandFlow $flow;

    /**
     * The changes come as closures in the Kernel: the Admin tab's services
     * reach ChatCommands themselves (CrewAdminTasks, OpenTaskCall), so
     * they are made at the first command, not with the router.
     *
     * @param AssistantWebPeople|Closure(): AssistantWebPeople $personChanges
     * @param AssistantWebAdmin|Closure(): AssistantWebAdmin $adminChanges
     */
    public function __construct(
        PersonRepository $people,
        private AssistantWebPeople|Closure $personChanges,
        private AssistantWebAdmin|Closure $adminChanges,
        TelegramClient $telegram
    ) {
        $this->flow = new CommandFlow($people, $telegram, self::PREFIX, self::STATE_PREFIX);
    }

    public function onCommand(int $chatId, int $fromId, string $text, bool $isPrivate, string $command): void
    {
        if (! $this->flow->inPrivate($chatId, $isPrivate, '/' . $command)) {
            return;
        }

        $organizer = $this->flow->organizerOrSay($chatId, $fromId, '/' . $command);

        if (null === $organizer) {
            return;
        }

        $args = trim((string) preg_replace('/\s+/u', ' ', CommandFlow::args($text)));
        $telegram = $this->flow->telegram();

        if ('' === $args) {
            $telegram->sendMessage($chatId, match ($command) {
                AssistantWebAdmin::OPTOUT => $this->adminChanges()->optOutList(),
                AssistantWebAdmin::AI_TEXT => AssistantWebAdmin::aiTextList(),
                AssistantWebAdmin::SCHEDULED => $this->adminChanges()->scheduledList(),
                default => self::usage($command),
            });

            return;
        }

        // An AI text keeps its line breaks.
        if (AssistantWebAdmin::AI_TEXT === $command) {
            $args = CommandFlow::args($text);
        }

        $asked = $this->prepare($organizer, $command, $args);

        if (! empty($asked['choices'])) {
            $states = array_column($asked['choices'], 'state');
            $id = $this->flow->start(['user' => $fromId, 'command' => $command, 'choices' => $states]);
            $options = [];

            foreach ($asked['choices'] as $n => $choice) {
                $options[$n] = $choice['label'];
            }

            $this->flow->choose($chatId, $id, 'p', $asked['text'], $options);

            return;
        }

        $this->ask($chatId, $fromId, $command, $asked);
    }

    /**
     * @param array<string, mixed> $callbackQuery
     */
    public function onCallback(array $callbackQuery): void
    {
        $tap = $this->flow->tap($callbackQuery);

        if (null === $tap) {
            return;
        }

        $organizer = $this->flow->organizerTap($tap);

        if (null === $organizer) {
            return;
        }

        $telegram = $this->flow->telegram();
        $telegram->answerCallbackQuery($tap['callback']);
        $state = $tap['state'];
        $command = (string) ($state['command'] ?? '');
        $this->flow->drop($tap['id']);

        if ('p' === $tap['step']) {
            $chosen = $state['choices'][$tap['choice']] ?? null;

            if (! is_array($chosen)) {
                return;
            }

            $asked = isset(self::PEOPLE[$command])
                ? $this->personChanges()->choose($organizer, $chosen)
                : $this->adminChanges()->choose($organizer, $chosen);
            $this->ask($tap['chat'], $tap['from'], $command, $asked);

            return;
        }

        if ('y' !== $tap['step'] || ! is_array($state['state'] ?? null)) {
            return;
        }

        $done = isset(self::PEOPLE[$command])
            ? $this->personChanges()->run($organizer, $state['state'])
            : $this->adminChanges()->run($organizer, $state['state']);
        $telegram->sendMessage($tap['chat'], CommandFlow::result($done['changed'], '', $done['text']));
    }

    /**
     * How to write a command, with an example.
     */
    public static function usage(string $command): string
    {
        // phpcs:disable Generic.Files.LineLength.TooLong -- gettext literals; splitting one breaks extraction.
        return match ($command) {
            self::GIFT => __("Give credits: /gift name ; credits ; reason\nExample: /gift Milla ; 1 ; Helped at the bar\nThe reason is optional.", 'eventcrew'),
            self::ROLE => __("Give or take a role: /role add role ; name, /role remove role ; name\nExample: /role add DJ ; Milla", 'eventcrew'),
            self::ORGANIZER => __("Make someone an organizer, or stop it: /organizer on name, /organizer off name\nExample: /organizer on Milla", 'eventcrew'),
            self::DELETE => __("Delete a person and erase their data: /deleteperson name\nExample: /deleteperson Milla\nThis cannot be undone.", 'eventcrew'),
            default => AssistantWebAdmin::usage($command),
        };
        // phpcs:enable Generic.Files.LineLength.TooLong
    }

    /**
     * @return array{ok: bool, text: string, state: array<string, mixed>, choices?: list<array{label: string, state: array<string, mixed>}>}
     */
    private function prepare(Person $organizer, string $command, string $args): array
    {
        $asked = isset(self::PEOPLE[$command])
            ? $this->personChanges()->prepare($organizer, self::PEOPLE[$command], $args)
            : $this->adminChanges()->prepare($organizer, $command, $args);

        // "That can no longer be done" for a line that cannot be read: the bot shows how to write it.
        if (! $asked['ok'] && empty($asked['choices']) && __('That can no longer be done.', 'eventcrew') === $asked['text']) {
            $asked['text'] = self::usage($command);
        }

        return $asked;
    }

    /**
     * The question with ✅ and ✖️, or why not.
     *
     * @param array{ok: bool, text: string, state: array<string, mixed>} $asked
     */
    private function ask(int $chatId, int $fromId, string $command, array $asked): void
    {
        if (! $asked['ok']) {
            $this->flow->telegram()->sendMessage($chatId, $asked['text']);

            return;
        }

        $id = $this->flow->start(['user' => $fromId, 'command' => $command, 'state' => $asked['state']]);
        $delete = self::DELETE === $command;
        $this->flow->confirm(
            $chatId,
            $id,
            ($delete ? '⚠️ ' : '') . $asked['text'],
            $delete ? __('Delete', 'eventcrew') : __('Confirm', 'eventcrew')
        );
    }

    private function personChanges(): AssistantWebPeople
    {
        if ($this->personChanges instanceof Closure) {
            $this->personChanges = ($this->personChanges)();
        }

        return $this->personChanges;
    }

    private function adminChanges(): AssistantWebAdmin
    {
        if ($this->adminChanges instanceof Closure) {
            $this->adminChanges = ($this->adminChanges)();
        }

        return $this->adminChanges;
    }
}

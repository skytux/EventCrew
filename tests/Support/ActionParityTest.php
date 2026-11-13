<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\ActionParity;
use EventCrew\Telegram\AssistantActions;
use EventCrew\Telegram\BotLog;
use EventCrew\Telegram\ChatCommands;
use EventCrew\Telegram\MenuButtons;
use EventCrew\Telegram\TaskCommand;
use EventCrew\Tests\TestCase;
use EventCrew\Web\AssistantWebActions;
use ReflectionClass;
use ReflectionClassConstant;

/**
 * The parity rule (Owner 2026-11-14): what a person can do in the Crew app
 * they can also do in the Telegram bot and through the helper, and on the
 * Crew app page the helper runs a change on Confirm instead of only linking
 * to it. ActionParity is the map; this test checks it against the code.
 *
 * KNOWN_GAPS are today's gaps, one line each with why. A gap that closes
 * fails the test until its line is removed, so the list only shrinks. A new
 * web action constant (a *_ACTION of a src/Web controller) fails it until it
 * is in the map or in ActionParity::EXCLUDED.
 */
final class ActionParityTest extends TestCase
{
    private const TODAY = '2026-11-14';

    /**
     * "<action id> <bot|helper|web>" => why it is still a gap.
     * bot: no bot command or button. helper: no helper action line that
     * passes validate() for that person. web: on the Crew app page the
     * helper only links (or does nothing) instead of running the change.
     * tg: the helper line works on the Crew app page only, not in Telegram.
     */
    private const KNOWN_GAPS = [
        // Missing in the bot.
        'roster_mark_all bot' => 'the roster marks one person at a time; no mark-a-whole-task button',
        'buyers_import bot' => 'buyer names come by email (MailedListImport), not by a bot message',
        'buyers_upload bot' => 'the bot takes no file',
        'buyers_mail_test bot' => 'no test ticket email command',
        'check_mail bot' => 'no check-the-mailbox command',
        'followup_test bot' => 'no test post-event email command',
        'desk_mark bot' => 'the door list has no check-in buttons; /roster marks crew only',
        'desk_plus bot' => 'no +1 count at the door',
        'desk_give bot' => '/ticket does it but is organizers only; the sales-desk crew has nothing',
        'desk_sale bot' => 'no door-sale command',
        'desk_holvi bot' => 'no add-a-Holvi-buyer command',
        'desk_count bot' => 'no head count at the door',
        'desk_guest bot' => '/door add does it but is organizers only',
        'desk_note bot' => 'no who-came-in note',
        'desk_note_remove bot' => 'no who-came-in note',
        'desk_work bot' => '/assign and Add someone do it but are organizers only',
        'desk_new_member bot' => 'Add someone creates a member only onto a task, organizers only',
        'desk_file bot' => 'the bot takes no file',

        // Missing in the helper.
        'give_ticket helper' => 'no helper line for 🎁 Give away (TransferBot)',
        'cancel_offer helper' => 'no helper line for ✖️ Cancel offer',
        'sign_out_everywhere helper' => 'no helper line for Sign out everywhere',
        'roster_mark_all helper' => 'no helper line (and no bot command)',
        'buyers_import helper' => 'no helper line',
        'buyers_upload helper' => 'a file cannot go through the helper',
        'buyers_reset helper' => 'only the button under the import message',
        'buyers_mail_test helper' => 'no helper line',
        'check_mail helper' => 'no helper line',
        'followup_test helper' => 'no helper line',
        'desk_mark helper' => 'no helper line',
        'desk_plus helper' => 'no helper line',
        'desk_give helper' => '/ticket is organizers only',
        'desk_sale helper' => 'no helper line',
        'desk_holvi helper' => 'no helper line',
        'desk_count helper' => 'no helper line',
        'desk_guest helper' => '/door add is organizers only',
        'desk_note helper' => 'no helper line',
        'desk_note_remove helper' => 'no helper line',
        'desk_work helper' => '/assign is organizers only',
        'desk_new_member helper' => 'no helper line',
        'desk_file helper' => 'a file cannot go through the helper',


        // The helper only links on the Crew app page.
        'takeover web' => 'menu:replace opens the board',
        'notifications web' => 'menu:notifications opens Settings',
        'channel_choice web' => 'menu:notifications opens Settings',
        'profile_email web' => '/email opens Settings: the link goes to the new address (Owner 2026-11-14)',
        'sales_link web' => 'menu:sales opens the Door scanner button instead of sending the link',
        'sales_link_organizer web' => 'menu:sales opens Admin > Door instead of sending the link',
        'desk_view web' => 'menu:doorlist has no place on the page for the sales-desk crew',
    ];

    /** @var array<string, array{organizer: bool, welcomeCrew: bool, verified: bool}> */
    private const AUDIENCES = [
        ActionParity::MEMBER => ['organizer' => false, 'welcomeCrew' => false, 'verified' => true],
        ActionParity::STAFF => ['organizer' => false, 'welcomeCrew' => true, 'verified' => true],
        ActionParity::ORGANIZER => ['organizer' => true, 'welcomeCrew' => true, 'verified' => true],
    ];

    public function testEveryAppActionHasABotAndAHelperTwin(): void
    {
        $gaps = [];

        foreach (ActionParity::actions() as $id => $row) {
            foreach ($this->gapsOf($row) as $kind => $why) {
                $gaps[$id . ' ' . $kind] = $why;
            }
        }

        $new = array_diff_key($gaps, self::KNOWN_GAPS);
        $closed = array_diff_key(self::KNOWN_GAPS, $gaps);

        self::assertSame(
            [],
            $new,
            "App actions with no bot or helper twin. Add the bot command and the helper line, or (for now) a KNOWN_GAPS line:\n"
                . print_r($new, true)
        );
        self::assertSame([], $closed, "Gaps now closed: remove these KNOWN_GAPS lines:\n" . print_r($closed, true));
    }

    public function testEveryWebActionIsInTheMapOrExcluded(): void
    {
        $mapped = [];

        foreach (ActionParity::actions() as $row) {
            if (null !== $row['source']) {
                $mapped[$row['source']] = true;
            }
        }

        foreach (array_keys($mapped + ActionParity::EXCLUDED) as $source) {
            self::assertTrue(defined('EventCrew\\Web\\' . $source), $source . ' is not a web action constant');
        }

        self::assertSame([], array_intersect_key($mapped, ActionParity::EXCLUDED), 'Mapped and excluded at once');

        $missing = [];
        $seen = 0;

        foreach (glob(dirname(__DIR__, 2) . '/src/Web/*.php') ?: [] as $file) {
            $short = basename($file, '.php');
            $class = 'EventCrew\\Web\\' . $short;

            if (! class_exists($class)) {
                continue;
            }

            foreach ((new ReflectionClass($class))->getReflectionConstants() as $constant) {
                if (1 !== preg_match('/(^|_)ACTION$/', $constant->getName()) || ! is_string($constant->getValue())) {
                    continue;
                }

                $key = $short . '::' . $constant->getName();
                ++$seen;

                if (! isset($mapped[$key]) && ! isset(ActionParity::EXCLUDED[$key])) {
                    $missing[] = $key;
                }
            }
        }

        self::assertGreaterThan(60, $seen, 'The web controllers were not found');
        self::assertSame(
            [],
            $missing,
            'New Crew app actions: add each to ActionParity::actions() with its bot command and helper line'
                . ' (or to EXCLUDED with why)'
        );
    }

    public function testTheMapIsWellFormed(): void
    {
        foreach (ActionParity::actions() as $id => $row) {
            self::assertArrayHasKey($row['audience'], self::AUDIENCES, $id);
            self::assertNotSame('', $row['place'], $id);

            if ($row['change']) {
                self::assertNotNull($row['source'], $id . ': a change names the web action that runs it');
            }
        }

        foreach (self::KNOWN_GAPS as $key => $why) {
            self::assertNotSame('', trim($why), $key . ' needs a reason');
        }
    }

    /**
     * What is missing for one row: 'bot', 'helper' and/or 'web', each with
     * what the check found.
     *
     * @param array{place: string, source: ?string, op: string, audience: string, change: bool, bot: list<string>, helper: ?string} $row
     * @return array<string, string>
     */
    private function gapsOf(array $row): array
    {
        $gaps = [];

        if ([] === $row['bot']) {
            $gaps['bot'] = 'none';
        }

        foreach ($row['bot'] as $entry) {
            self::assertTrue($this->botHas($entry), 'The bot has no ' . $entry . ' (' . $row['place'] . ')');
        }

        if (null === $row['helper']) {
            $gaps['helper'] = 'none';

            return $gaps;
        }

        $audience = self::AUDIENCES[$row['audience']];
        $checked = AssistantActions::validate($row['helper'], ['web' => true] + $audience, null, self::TODAY, [12]);

        if (! $checked['ok']) {
            $gaps['helper'] = $checked['error'];

            return $gaps;
        }

        $inChat = AssistantActions::validate($row['helper'], $audience, null, self::TODAY, [12]);

        if (! $inChat['ok']) {
            $gaps['tg'] = $inChat['error'];
        }

        $plan = AssistantWebActions::plan($checked['action'], ActionParity::ORGANIZER === $row['audience'], self::TODAY);

        if ($row['change'] ? AssistantWebActions::CONFIRM !== $plan['kind'] : AssistantWebActions::NONE === $plan['kind']) {
            $gaps['web'] = $plan['kind'];
        }

        return $gaps;
    }

    /** Whether the bot answers $entry: a command, a menu button, or a callback prefix constant. */
    private function botHas(string $entry): bool
    {
        if (str_starts_with($entry, '/')) {
            return in_array(substr($entry, 1), $this->commands(), true);
        }

        if (str_starts_with($entry, MenuButtons::PREFIX)) {
            return in_array($entry, (new ReflectionClass(MenuButtons::class))->getConstants(), true);
        }

        $value = $this->constantValue($entry);

        if ('' === $value) {
            return false;
        }

        foreach ($this->routedPrefixes() as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> The commands UpdateRouter routes, without the slash. */
    private function commands(): array
    {
        $logged = (new ReflectionClassConstant(BotLog::class, 'COMMANDS'))->getValue();
        preg_match_all("#isCommand\\(\\\$text, '(\\w+)'\\)#", $this->routerSource(), $m);

        return array_merge($logged, ChatCommands::commands(), TaskCommand::MODES, $m[1]);
    }

    /** @return list<string> The callback data prefixes UpdateRouter hands to a service. */
    private function routedPrefixes(): array
    {
        preg_match_all("#str_starts_with\\(\\\$data, ('[^']+'|\\w+::\\w+)\\)#", $this->routerSource(), $m);
        $prefixes = [];

        foreach ($m[1] as $found) {
            $prefixes[] = str_starts_with($found, "'") ? trim($found, "'") : $this->constantValue($found);
        }

        return array_values(array_filter($prefixes, static fn (string $p): bool => '' !== $p));
    }

    /** "Class::CONST" of EventCrew\Telegram or EventCrew\Support, private ones too; '' when there is none. */
    private function constantValue(string $reference): string
    {
        [$short, $name] = explode('::', $reference, 2) + ['', ''];

        foreach (['EventCrew\\Telegram\\', 'EventCrew\\Support\\'] as $namespace) {
            if (class_exists($namespace . $short) && (new ReflectionClass($namespace . $short))->hasConstant($name)) {
                return (string) (new ReflectionClassConstant($namespace . $short, $name))->getValue();
            }
        }

        return '';
    }

    private function routerSource(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/src/Telegram/UpdateRouter.php');
    }
}

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
 * fails the test until its line is removed, so the list only shrinks. The
 * sales desk's own actions (ActionParity::DESK_ONLY) and the helper's
 * Open sales desk button (ActionParity::WEB_SHOWS_BUTTON) are intended
 * exceptions, not gaps, and never go into KNOWN_GAPS (flow1114). A new
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
        // Missing in the helper.
        'buyers_upload helper' => 'a file cannot go through the helper',

        // The helper only links on the Crew app page.
        'profile_email web' => '/email opens Settings: the link goes to the new address (Owner 2026-11-14)',
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
        $intended = [];

        foreach (ActionParity::actions() as $id => $row) {
            foreach ($this->gapsOf($row) as $kind => $why) {
                if (self::isIntended($id, $kind)) {
                    $intended[$id . ' ' . $kind] = $why;

                    continue;
                }

                $gaps[$id . ' ' . $kind] = $why;
            }
        }

        // Each WEB_SHOWS_BUTTON row still only shows its button: when the
        // helper runs it on Confirm again, its line goes.
        foreach (array_keys(ActionParity::WEB_SHOWS_BUTTON) as $id) {
            self::assertArrayHasKey($id . ' web', $intended, $id . ' is no longer a button only: remove it from WEB_SHOWS_BUTTON');
            self::assertSame(AssistantWebActions::GOTO, $intended[$id . ' web'], $id . ' shows its button');
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

    /**
     * The sales desk's actions stay on the desk page (flow1114): a separate,
     * named list, never gaps. Every DoorController action is mapped or
     * excluded (testEveryWebActionIsInTheMapOrExcluded), so a new desk
     * action cannot slip in unmapped either.
     */
    public function testDeskActionsAreAnIntendedExceptionNotGaps(): void
    {
        $actions = ActionParity::actions();

        self::assertSame('Owner 2026-11-14: desk actions stay on the desk page', ActionParity::DESK_ONLY_WHY);
        self::assertSame(array_values(array_unique(ActionParity::DESK_ONLY)), ActionParity::DESK_ONLY);

        foreach (ActionParity::DESK_ONLY as $id) {
            self::assertArrayHasKey($id, $actions, $id . ' is not in ActionParity::actions()');
            self::assertStringStartsWith('DoorController::', (string) $actions[$id]['source'], $id . ' is not a sales desk action');
            self::assertStringStartsWith('Sales desk', $actions[$id]['place'], $id);
        }

        foreach (array_keys(self::KNOWN_GAPS) as $key) {
            $id = (string) strtok($key, ' ');
            self::assertNotContains($id, ActionParity::DESK_ONLY, $key . ': a desk action is an exception, not a gap');
            self::assertArrayNotHasKey($id, ActionParity::WEB_SHOWS_BUTTON, $key . ': listed as intended already');
        }

        foreach (array_keys(ActionParity::WEB_SHOWS_BUTTON) as $id) {
            self::assertArrayHasKey($id, $actions, $id . ' is not in ActionParity::actions()');
        }
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

        foreach (ActionParity::WEB_SHOWS_BUTTON as $id => $why) {
            self::assertNotSame('', trim($why), $id . ' needs a reason');
        }
    }

    /** Whether a gap of $kind for action $id is an intended exception (flow1114). */
    private static function isIntended(string $id, string $kind): bool
    {
        if (in_array($id, ActionParity::DESK_ONLY, true)) {
            return true;
        }

        return 'web' === $kind && isset(ActionParity::WEB_SHOWS_BUTTON[$id]);
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

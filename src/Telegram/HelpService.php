<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\DoorAccess;
use EventCrew\Support\Faq;
use EventCrew\Support\Language;
use EventCrew\Support\Roles;
use EventCrew\Support\Organization;
use EventCrew\Support\WelcomeSender;

/**
 * /help, /door, /people and the answer to the Cancel button.
 *
 * /help in a private chat, and the ❓ Help button under /me, open the Help
 * menu (Owner 2026-11-08): How this works? (the welcome guide, sent again by
 * WelcomeSender), Commands, FAQ (Faq, paged when long) and, for organizers,
 * the organizer guide. The "In your own words" lines sit on the menu itself,
 * since they are about how to talk to the bot at all.
 *
 * Commands lists the commands the asker may use: the Everyone section, the
 * Welcome crew section for organizers and for people on a nearby night's
 * sales-desk task, and the Organizers section for organizers. A mistyped or
 * removed command gets this list straight away. In the group /help is only
 * the Everyone section, in English, with a pointer to the private chat for
 * the rest.
 *
 * /door and /people answer in the private chat with MenuButtons; each button
 * opens a service that checks again who may use it.
 *
 * The Cancel button only gets its answer here; the router drops the pending
 * questions and tells this class whether there was one.
 */
final class HelpService
{
    use GroupBreadcrumb;

    /** Room left under Telegram's limit for the FAQ page's title line and the HTML tags. */
    private const FAQ_PAGE_TEXT = 3500;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly DoorAccess $doorAccess,
        private readonly TelegramClient $telegram,
        private readonly ?WelcomeSender $welcome = null
    ) {
    }

    /**
     * /help and the ❓ Help button: the Help menu in a private chat, the
     * group's command list in a group.
     */
    public function onHelp(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        if (! $isPrivate) {
            $this->sendGroupText($chatId);

            return;
        }

        $sections = ['❓ ' . __('Help', 'eventcrew')];

        if (AssistantSettings::isActive()) {
            $sections[] = $this->plainWords();
        }

        $person = 0 !== $telegramUserId ? $this->people->findByTelegramUserId($telegramUserId) : null;

        $this->telegram->sendMessage(
            $chatId,
            implode("\n\n", $sections),
            MenuButtons::help(null !== $person && $person->isOrganizer)
        );
    }

    /**
     * Commands under Help, and the answer to a command the bot does not
     * know: the commands this person may use, with Back to the Help menu.
     */
    public function onCommands(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        if (! $isPrivate) {
            $this->sendGroupText($chatId);

            return;
        }

        $audience = $this->audience($telegramUserId);
        $sections = [$this->title(), $this->everyone()];

        if ($audience['welcomeCrew']) {
            $sections[] = $this->welcomeCrew();
        }

        if ($audience['organizer']) {
            $sections[] = $this->organizers();
        }

        $this->telegram->sendMessage(
            $chatId,
            implode("\n\n", $sections),
            MenuButtons::back(MenuButtons::HELP)
        );
    }

    /**
     * How this works? under Help: the welcome DM again (WelcomeSender), or
     * with $organizer the organizer guide DM, with Back to the Help menu.
     * Someone without a confirmed email gets the setup nudge, as on /me.
     */
    public function onGuide(int $telegramUserId, int $chatId, bool $isPrivate = true, bool $organizer = false): void
    {
        $person = 0 !== $telegramUserId ? $this->people->findByTelegramUserId($telegramUserId) : null;

        if (null === $person || ! $person->isEmailVerified() || null === $this->welcome) {
            $this->telegram->sendMessage($chatId, SetupNudge::text(! $isPrivate));

            return;
        }

        if ($organizer && ! $person->isOrganizer) {
            $this->telegram->sendMessage($chatId, __('Only organizers can open this.', 'eventcrew'));

            return;
        }

        $this->welcome->sendGuide($person, $organizer, [MenuButtons::backRow(MenuButtons::HELP)]);
    }

    /**
     * FAQ under Help: the questions and answers (Faq), as many to a page as
     * fit in one message, with « Previous / Next » when there is more than
     * one page and Back to the Help menu. $page counts from 0.
     */
    public function onFaq(int $chatId, int $page = 0): void
    {
        $pages = self::faqPages();
        $count = count($pages);
        $page = max(0, min($page, $count - 1));
        $title = '💡 ' . __('FAQ', 'eventcrew') . ($count > 1 ? MessagePages::note($page, $count) : '');
        $rows = [];
        $paging = MessagePages::buttons(MenuButtons::FAQ, $page, $count);

        if ([] !== $paging) {
            $rows[] = $paging;
        }

        $rows[] = MenuButtons::backRow(MenuButtons::HELP);

        $this->telegram->sendMessage(
            $chatId,
            '<b>' . self::html($title) . "</b>\n\n" . implode("\n\n", $pages[$page]),
            ['inline_keyboard' => $rows],
            ['parse_mode' => 'HTML']
        );
    }

    /**
     * The FAQ as Telegram HTML pages: each item a bold question and its
     * answer, as many items to a page as fit under FAQ_PAGE_TEXT. Always at
     * least one page.
     *
     * @return list<list<string>>
     */
    public static function faqPages(): array
    {
        $pages = [];
        $page = [];
        $length = 0;

        foreach (Faq::items() as $item) {
            $block = '<b>' . self::html('❓ ' . $item['q']) . "</b>\n" . self::html($item['a']);
            $size = MessagePages::textLength([$block, '']);

            if ([] !== $page && $length + $size > self::FAQ_PAGE_TEXT) {
                $pages[] = $page;
                $page = [];
                $length = 0;
            }

            $page[] = $block;
            $length += $size;
        }

        $pages[] = $page;

        return $pages;
    }

    /**
     * The Everyone commands, one "/command – what it does" line each, without
     * the section title: the web Help tab lists them too (Owner 2026-11-08).
     *
     * @return list<string>
     */
    public static function everyoneLines(): array
    {
        return [
            __('/board – the open tasks; in a private chat also the tasks of your roles', 'eventcrew'),
            __('/me – your standing, credits and coming tasks', 'eventcrew'),
            __('/tasks – cancel, take over a task, past tasks', 'eventcrew'),
            __('/tickets – your tickets, free-entry ticket', 'eventcrew'),
            __('/settings – notifications, language, web sign-in', 'eventcrew'),
            // Private-chat commands for members (ag-people).
            __('/crew – who works with you on your next nights', 'eventcrew'),
            __('/name, /email – change your name or email', 'eventcrew'),
            __('/help – how this works, commands and FAQ', 'eventcrew'),
        ];
    }

    private static function html(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
    }

    private function sendGroupText(int $chatId): void
    {
        $this->telegram->sendMessage(
            $chatId,
            Language::run(Language::ENGLISH, fn (): string => $this->groupText())
        );
    }

    /**
     * Which /help sections this Telegram user gets: organizer, welcome crew
     * (every organizer is also welcome crew), and whether they have a person
     * record with a confirmed email. The language-model helper offers actions
     * by the same rule. "roles" has the names of the crew roles they were
     * given (e.g. "DJ"), for the helper to know what to leave out.
     *
     * @return array{organizer: bool, welcomeCrew: bool, verified: bool, roles: list<string>, roleSlugs: list<string>}
     */
    public function audience(int $telegramUserId): array
    {
        return $this->audienceForPerson(
            0 !== $telegramUserId ? $this->people->findByTelegramUserId($telegramUserId) : null
        );
    }

    /**
     * The same as audience(), for a person already looked up (the Crew app
     * page's helper box knows the signed-in person, Telegram or not).
     *
     * @return array{organizer: bool, welcomeCrew: bool, verified: bool, roles: list<string>, roleSlugs: list<string>}
     */
    public function audienceForPerson(?Person $person): array
    {
        $organizer = null !== $person && $person->isOrganizer;

        return [
            // Who is asking, for the helper's "This person" facts (0 = unknown).
            'personId' => null !== $person ? $person->id : 0,
            'organizer' => $organizer,
            'welcomeCrew' => $organizer || (null !== $person && $this->doorAccess->isWelcomeCrew($person)),
            'verified' => null !== $person && $person->isEmailVerified(),
            // A known person: their own board also shows the Members display roles.
            'member' => null !== $person,
            'roles' => null !== $person
                ? array_values(array_map(static fn (string $slug): string => Roles::label($slug), $person->roleSlugs))
                : [],
            // Organize is not stored: it follows the organizer permission.
            'roleSlugs' => null !== $person
                ? array_values(array_unique([...$person->roleSlugs, ...($organizer ? [Roles::ORGANIZE_SLUG] : [])]))
                : [],
        ];
    }

    /**
     * /door: the door list, guest list and sales desk buttons, in the DM.
     * $date is what was typed after /door ("" = the next night).
     */
    public function onDoor(int $telegramUserId, int $chatId, bool $isPrivate, string $date): void
    {
        $person = 0 !== $telegramUserId ? $this->people->findByTelegramUserId($telegramUserId) : null;

        if (null === $person || ! $person->isEmailVerified()) {
            $this->telegram->sendMessage($chatId, SetupNudge::text(! $isPrivate));

            return;
        }

        if (! $person->isOrganizer && ! $this->doorAccess->isWelcomeCrew($person)) {
            $this->telegram->sendMessage(
                $chatId,
                __('Only organizers and the welcome crew of a coming night can use /door.', 'eventcrew')
            );

            return;
        }

        $date = trim($date);
        $text = '' === $date
            ? __('🚪 The door: choose what to open.', 'eventcrew')
            /* translators: %s: the night as typed after /door, e.g. 1.8. */
            : sprintf(__('🚪 The door on %s: choose what to open.', 'eventcrew'), $date);

        $this->telegram->sendMessage($telegramUserId, $text, MenuButtons::door($date, $person->isOrganizer));
        $this->sentDmNote($chatId, $isPrivate);
    }

    /**
     * /people (organizers only): the credits, roles and eligibility buttons,
     * in the DM.
     */
    public function onPeople(int $telegramUserId, int $chatId, bool $isPrivate): void
    {
        $person = 0 !== $telegramUserId ? $this->people->findByTelegramUserId($telegramUserId) : null;

        if (null === $person || ! $person->isOrganizer) {
            $this->telegram->sendMessage($chatId, __('Only organizers can use /people.', 'eventcrew'));

            return;
        }

        $this->telegram->sendMessage(
            $telegramUserId,
            __('👥 People: choose what to do.', 'eventcrew'),
            MenuButtons::people()
        );
        $this->sentDmNote($chatId, $isPrivate);
    }

    /**
     * The 🛠 Admin button under /me (organizers only, Owner 2026-11-07): the
     * roster, door and people screens, in the DM.
     */
    public function onAdmin(int $telegramUserId, int $chatId, bool $isPrivate): void
    {
        $person = 0 !== $telegramUserId ? $this->people->findByTelegramUserId($telegramUserId) : null;

        if (null === $person || ! $person->isOrganizer) {
            $this->telegram->sendMessage($chatId, __('Only organizers can open this.', 'eventcrew'));

            return;
        }

        $this->telegram->sendMessage($telegramUserId, '🛠 ' . __('Admin', 'eventcrew'), MenuButtons::admin());
        $this->sentDmNote($chatId, $isPrivate);
    }

    /** Stops the spinner on a tapped MenuButtons button. */
    public function answerTap(string $callbackQueryId): void
    {
        if ('' !== $callbackQueryId) {
            $this->telegram->answerCallbackQuery($callbackQueryId);
        }
    }

    /**
     * The answer to the Cancel button. $stopped is true when at least one question was
     * waiting for an answer and has now been dropped.
     */
    public function onCancel(int $chatId, bool $isPrivate, bool $stopped): void
    {
        $text = static fn (): string => $stopped
            ? __('Stopped. Nothing is waiting for an answer.', 'eventcrew')
            : __('Nothing was waiting for an answer.', 'eventcrew');

        $this->telegram->sendMessage($chatId, $isPrivate ? $text() : Language::run(Language::ENGLISH, $text));
    }

    private function groupText(): string
    {
        return implode("\n\n", [
            $this->title(),
            $this->everyone(),
            __('Send me /help in a private chat for more.', 'eventcrew'),
        ]);
    }

    private function title(): string
    {
        /* translators: %s: the organization's name */
        return sprintf(__('%s bot – commands', 'eventcrew'), Organization::name());
    }

    /** Shown while the language-model helper is on. */
    private function plainWords(): string
    {
        return implode("\n", [
            __('In your own words', 'eventcrew'),
            __('You can also just write what you want, like "sign me up for a task on Saturday".', 'eventcrew'),
            __('I remember our private chat for 24 hours, so you can refer back to it.', 'eventcrew'),
        ]);
    }

    private function everyone(): string
    {
        return implode("\n", [__('Everyone', 'eventcrew'), ...self::everyoneLines()]);
    }

    private function welcomeCrew(): string
    {
        return implode("\n", [
            __('Welcome crew  (date: 1.8. or 2026-08-01; default: next night)', 'eventcrew'),
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __('/roster [date] – who works that night; the sales desk marks 👋 arrived, organizers 👋 arrived, ✓ done, ⏰ late, ✗ no-show', 'eventcrew'),
            __('/door [date] – buttons: 📋 door list, 👥 guest list, 💳 sales desk', 'eventcrew'),
        ]);
    }

    private function organizers(): string
    {
        return implode("\n", [
            __('Organizers', 'eventcrew'),
            __('/door add [date] Name +N ; note – add a guest (also edit, remove)', 'eventcrew'),
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __('/people – buttons: 🎁 give credits, 🏷 roles, organizers and passes, ✅ who may hold each role', 'eventcrew'),
            __('/board in a private chat – also ➕ to put a person on a task', 'eventcrew'),
            __('/assign task ; name – put a member on a task (/unassign takes them off); you confirm first', 'eventcrew'),
            __('/edittask task ; places 3 – change a task (also /addtask, /deletetask)', 'eventcrew'),
            __('/maketasks – a night\'s tasks from the roles; /mailtasks – the open-task email now', 'eventcrew'),
            __('/say text – post a message in the group; you see it first', 'eventcrew'),
            __('/events – post the coming events in the group as picture cards; you see them first', 'eventcrew'),
            // Private-chat commands for organizers (ag-people).
            __('/mark name ; late – arrived, late, noshow or done', 'eventcrew'),
            __('/standing name – standing, credits and log', 'eventcrew'),
            __('/newcomer – a newcomer waiting for your OK', 'eventcrew'),
            __('/ticket name – free entry for a credit (/unticket undoes it)', 'eventcrew'),
            __('/unban name – lift a block', 'eventcrew'),
            __('/freeentry close – close free entry for a night (or open)', 'eventcrew'),
            __('/stop announcement or /stop email – stop the next send', 'eventcrew'),
            __('/newboard – post a new board in the group', 'eventcrew'),
            __('/sendtickets – send the ticket emails now (or cancel)', 'eventcrew'),
            __('/tell task ; text – message everyone on a task', 'eventcrew'),
            __('/subscribe email – add someone to the newsletter; they confirm by email', 'eventcrew'),
        ]);
    }
}

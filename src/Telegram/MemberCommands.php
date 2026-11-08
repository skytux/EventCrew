<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\Dates;
use EventCrew\Web\SignupController;

/**
 * Member commands, in a private chat:
 *
 * - /crew: the member's coming nights on which they hold a task, each with
 *   its crew: the task names and who is on each (names only).
 * - /name <new name>: change the name, after ✅.
 * - /email <new address>: change the email, after ✅, through the crew
 *   page's own flow (SignupController::updateProfile): a link goes to the new
 *   address and nothing changes until it is opened. RATE_LIMIT an hour.
 * - Settings' 🚪 Sign out everywhere and 🗑 Delete my data (Owner
 *   2026-11-08, as on the web), each asked first: the first signs the web
 *   out on every browser (SignupController's revoke action), the second
 *   mails the one-time delete link, as the web's Delete my data does
 *   (ManageController::mailDeleteLink()), so nothing is erased from a chat.
 */
final class MemberCommands
{
    public const PREFIX = 'mc:';
    public const STATE_PREFIX = 'eventcrew_tg_member_';

    public const CREW = 'crew';
    public const NAME = 'name';
    public const EMAIL = 'email';

    /** Settings' buttons, not typed commands: their ops in a waiting request. */
    public const SIGN_OUT = 'signout';
    public const DELETE = 'delete';

    /** The commands this class answers. */
    public const COMMANDS = [self::CREW, self::NAME, self::EMAIL];

    /** Nights /crew shows at most. */
    public const NIGHTS = 3;

    /** Email-change links one member may ask for in an hour. */
    public const RATE_LIMIT = 3;

    private const RATE_PREFIX = 'eventcrew_tg_email_sent_';

    private CommandFlow $flow;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly TaskRepository $tasks,
        private readonly AssignmentRepository $assignments,
        private readonly SignupController $signup,
        TelegramClient $telegram,
        // Optional: without it Delete my data says the email could not be sent.
        private readonly ?ManageController $manage = null
    ) {
        $this->flow = new CommandFlow($people, $telegram, self::PREFIX, self::STATE_PREFIX);
    }

    public function onCommand(int $chatId, int $fromId, string $text, bool $isPrivate, string $command): void
    {
        if (! $this->flow->inPrivate($chatId, $isPrivate, '/' . $command)) {
            return;
        }

        $telegram = $this->flow->telegram();
        $member = $this->flow->member($fromId);

        if (null === $member) {
            $telegram->sendMessage($chatId, SetupNudge::text());

            return;
        }

        $args = trim((string) preg_replace('/\s+/u', ' ', CommandFlow::args($text)));

        if (self::CREW === $command) {
            $telegram->sendMessage($chatId, $this->crew($member));

            return;
        }

        if (self::NAME === $command) {
            if ('' === $args) {
                // Settings' Change name lands here too, so Back goes to Settings.
                $telegram->sendMessage(
                    $chatId,
                    /* translators: %s: the member's name. */
                    sprintf(__("Your name: %s\nChange it: /name New Name", 'eventcrew'), $member->name()),
                    MenuButtons::back(MenuButtons::SETTINGS)
                );

                return;
            }

            $args = mb_substr($args, 0, 191);

            if ($args === $member->displayName) {
                $telegram->sendMessage($chatId, __('That is already your name.', 'eventcrew'));

                return;
            }

            $id = $this->flow->start(['user' => $fromId, 'op' => self::NAME, 'value' => $args]);
            $this->flow->confirm(
                $chatId,
                $id,
                /* translators: 1: the current name, 2: the new name. */
                sprintf(__('Change your name from %1$s to %2$s?', 'eventcrew'), $member->name(), $args),
                __('Change', 'eventcrew')
            );

            return;
        }

        if ('' === $args) {
            $telegram->sendMessage(
                $chatId,
                /* translators: %s: the member's email address. */
                sprintf(__("Your email: %s\nChange it: /email new@example.com", 'eventcrew'), $member->email),
                MenuButtons::back(MenuButtons::SETTINGS)
            );

            return;
        }

        $email = sanitize_email($args);

        if ('' === $email || ! is_email($email)) {
            $telegram->sendMessage($chatId, __('That is not a valid email address.', 'eventcrew'));

            return;
        }

        if (0 === strcasecmp($email, $member->email)) {
            $telegram->sendMessage($chatId, __('That is already your email.', 'eventcrew'));

            return;
        }

        if (! $this->underLimit($member)) {
            $telegram->sendMessage($chatId, __('You asked for a link a few times already. Try again in an hour.', 'eventcrew'));

            return;
        }

        $id = $this->flow->start(['user' => $fromId, 'op' => self::EMAIL, 'value' => $email]);
        $this->flow->confirm(
            $chatId,
            $id,
            /* translators: %s: the new email address. */
            sprintf(__('Change your email to %s? A link goes there first; the email changes when you open it.', 'eventcrew'), $email),
            __('Send link', 'eventcrew')
        );
    }

    /**
     * 🚪 Sign out everywhere under Settings: asks first, like the web's
     * button does.
     */
    public function askSignOut(int $chatId, int $fromId, bool $isPrivate): void
    {
        if (null === $this->memberOrNudge($chatId, $fromId, $isPrivate)) {
            return;
        }

        $id = $this->flow->start(['user' => $fromId, 'op' => self::SIGN_OUT, 'value' => '1']);
        $this->flow->confirm($chatId, $id, self::signOutQuestion(), __('Sign out everywhere', 'eventcrew'));
    }

    /**
     * 🗑 Delete my data under Settings: says what goes and that a link comes
     * first, then asks. The ✅ mails the same one-time link the web's Delete
     * my data does; only opening it deletes.
     */
    public function askDelete(int $chatId, int $fromId, bool $isPrivate): void
    {
        if (null === $this->memberOrNudge($chatId, $fromId, $isPrivate)) {
            return;
        }

        $id = $this->flow->start(['user' => $fromId, 'op' => self::DELETE, 'value' => '1']);
        $this->flow->confirm($chatId, $id, self::deleteQuestion(), __('Delete my data', 'eventcrew'));
    }

    /** What 🚪 Sign out everywhere asks. */
    public static function signOutQuestion(): string
    {
        return __('Sign out of the web board on every browser and phone? Nothing is lost; you just sign in again.', 'eventcrew');
    }

    /** What 🗑 Delete my data asks: the web's warning, and the link that comes first. */
    public static function deleteQuestion(): string
    {
        return __('Delete your account and all your history? This cannot be undone.', 'eventcrew')
            . "\n\n"
            . __('A link goes to your email first; your data is deleted only when you open it.', 'eventcrew');
    }

    private function memberOrNudge(int $chatId, int $fromId, bool $isPrivate): ?Person
    {
        if (! $isPrivate) {
            return null;
        }

        $member = $this->flow->member($fromId);

        if (null === $member) {
            $this->flow->telegram()->sendMessage($chatId, SetupNudge::text());
        }

        return $member;
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

        $telegram = $this->flow->telegram();
        $telegram->answerCallbackQuery($tap['callback']);

        if ('y' !== $tap['step']) {
            return;
        }

        $this->flow->drop($tap['id']);
        $member = $this->flow->member($tap['from']);
        $value = (string) ($tap['state']['value'] ?? '');

        if (null === $member || '' === $value) {
            $telegram->sendMessage($tap['chat'], SetupNudge::text());

            return;
        }

        $op = (string) ($tap['state']['op'] ?? '');

        if (self::SIGN_OUT === $op) {
            // The web's revoke action: every web session cookie stops working.
            $this->people->revokeSessions($member->id);
            $telegram->sendMessage(
                $tap['chat'],
                CommandFlow::result(true, '', SignupController::noticeText('sessions_revoked')),
                MenuButtons::back(MenuButtons::SETTINGS)
            );

            return;
        }

        if (self::DELETE === $op) {
            $telegram->sendMessage(
                $tap['chat'],
                null === $this->manage
                    ? __('The email could not be sent. Try again later.', 'eventcrew')
                    : $this->manage->mailDeleteLink($member),
                MenuButtons::back(MenuButtons::SETTINGS)
            );

            return;
        }

        if (self::NAME === $op) {
            $code = $this->signup->updateProfile($member, $value, '');
            $telegram->sendMessage($tap['chat'], CommandFlow::result(
                'profile_saved' === $code,
                '',
                'profile_saved' === $code
                    /* translators: %s: the new name. */
                    ? sprintf(__('Your name is now %s.', 'eventcrew'), $value)
                    : SignupController::noticeText($code)
            ));

            return;
        }

        if (! $this->underLimit($member)) {
            $telegram->sendMessage($tap['chat'], __('You asked for a link a few times already. Try again in an hour.', 'eventcrew'));

            return;
        }

        $this->countLink($member);
        $name = '' !== trim($member->displayName) ? $member->displayName : $member->name();
        $code = $this->signup->updateProfile($member, $name, $value);
        $telegram->sendMessage($tap['chat'], CommandFlow::result(
            'email_sent' === $code || 'profile_saved_email_sent' === $code,
            '',
            'bad_email' === $code
                ? __('That is not a valid email address.', 'eventcrew')
                /* translators: %s: the new email address. */
                : sprintf(__('A link is on its way to %s. Your email changes when you open it.', 'eventcrew'), $value)
        ));
    }

    /**
     * The member's coming nights and the crew of each.
     */
    private function crew(Person $member): string
    {
        $today = (string) current_time('Y-m-d');
        $dates = [];

        foreach ($this->assignments->historyFor($member->id) as $entry) {
            if ((string) $entry['task_date'] >= $today && $entry['assignment']->isOccupying()) {
                $dates[(string) $entry['task_date']] = true;
            }
        }

        if ([] === $dates) {
            return __('You have no coming tasks. See the open ones with /board.', 'eventcrew');
        }

        ksort($dates);
        $nights = [];
        $names = [];

        foreach (array_slice(array_keys($dates), 0, self::NIGHTS) as $date) {
            $tasks = $this->tasks->forDate($date);
            $head = Dates::dayLabel($date);
            $event = [] === $tasks ? '' : $tasks[0]->eventName();
            $lines = ['' === $event ? $head : $head . ' · ' . $event];

            foreach ($tasks as $task) {
                if ($task->isOrganizersOnly() && ! $member->isOrganizer) {
                    continue;
                }

                $crew = [];

                foreach ($this->assignments->forTask($task->id) as $assignment) {
                    if (! $assignment->isOccupying()) {
                        continue;
                    }

                    $names[$assignment->personId] ??= $this->people->find($assignment->personId);
                    $person = $names[$assignment->personId];

                    if (null !== $person && ! $person->isAnonymized()) {
                        $crew[] = $person->name();
                    }
                }

                $lines[] = '• ' . $task->roleLabel() . ': ' . ([] === $crew ? '–' : implode(', ', $crew));
            }

            $nights[] = implode("\n", $lines);
        }

        return implode("\n\n", $nights);
    }

    private function underLimit(Person $member): bool
    {
        return count($this->recentLinks($member)) < self::RATE_LIMIT;
    }

    private function countLink(Person $member): void
    {
        $links = $this->recentLinks($member);
        $links[] = time();
        set_transient(self::RATE_PREFIX . $member->id, $links, HOUR_IN_SECONDS);
    }

    /**
     * @return list<int>
     */
    private function recentLinks(Person $member): array
    {
        $stored = get_transient(self::RATE_PREFIX . $member->id);
        $since = time() - HOUR_IN_SECONDS;

        return array_values(array_filter(
            array_map('intval', is_array($stored) ? $stored : []),
            static fn (int $at): bool => $at > $since
        ));
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Telegram\TelegramClient;

/**
 * The team's OK for a newcomer.
 *
 * Someone who signs themselves up (the web sign-in form, the bot's /start)
 * is created waiting (Person::APPROVAL_WAITING). They can sign in and see
 * the board, but SignupService refuses their claims until an organizer
 * says OK.
 *
 * - When they confirm their email the first time, the organizers get
 *   "New: <name> wants to join". A sign-up through the crew page's email
 *   link first sees the join questions on the crew page (JoinAnswers,
 *   Owner 2026-11-13): the request waits until they answer or skip them,
 *   or QUESTIONS_MINUTES go by (putOff(), ASK_HOOK). It goes by Telegram
 *   with OK and No buttons
 *   (organizers with Telegram) and by email with a button to the Admin
 *   tab. Each organizer's NotificationPreferences::NEWCOMER row switches
 *   either route off; both are on by default.
 * - The request DM shows the person's @username and a link to their
 *   Telegram profile when they use the bot; the email links the
 *   @username to t.me when they have one (with only the numeric id known
 *   the email has no link: mail apps do not open tg:// links).
 * - Both carry what the person said when joining ({join_answers},
 *   JoinAnswers): worked on the crew here before, about how many times,
 *   and how many free-entry tickets they think they should have.
 * - The first organizer to press OK or No decides
 *   (PersonRepository::decideNewcomer()); a later press is told who did.
 *   No first asks "Turn down <name>?" with a reason: Unknown to us (the
 *   person is told to talk to an organizer at an event) or No reason (the
 *   person is not told).
 * - On OK the newcomer is told: by Telegram if linked, else by email.
 * - Once decided, every organizer's Telegram copy of the request shows the
 *   outcome (who, OK or No, when), and the organizers who got the request
 *   email get a short email with the outcome.
 * - A request nobody answers in 48 hours is turned down by the heartbeat
 *   (expireDue()); the person is told why and what to do.
 * - A turned-down person can be accepted from wp-admin (accept()), with the
 *   same OK message.
 * - A person who is given a role or a one-time pass is OK from then on.
 *
 * What the request needs to remember lives in the person's role_notices
 * record: when the organizers were asked, each organizer's Telegram copy
 * (chat:message), who got the email, when it was decided and the reason.
 *
 * People an organizer adds are never waiting, and everyone already in the
 * list at schema 33 is OK.
 */
final class NewcomerApproval
{
    /** Callback data prefix of the buttons: "nco:<verb>:<id>". */
    public const PREFIX = 'nco:';

    /** The start payload verb nco-<person_id>: shows the OK and No buttons (showRequest()). */
    public const START_VERB = 'nco';

    /** decide(): this press made the decision. */
    public const DECIDED = 'decided';

    /** decide(): someone else had decided already. */
    public const ALREADY = 'already';

    /** decide(): no such person, or they are not a newcomer. */
    public const UNKNOWN = 'unknown';

    /** Turned down with no reason: the person is not told. */
    public const REASON_NONE = 'none';

    /** Turned down as unknown to the organizers: the person is told to talk to us at an event. */
    public const REASON_UNKNOWN = 'unknown';

    /** Nobody answered in TIMEOUT_HOURS: turned down, and the person is told. */
    public const REASON_TIMEOUT = 'timeout';

    /** How long the organizers have to answer before the request times out. */
    public const TIMEOUT_HOURS = 48;

    /**
     * The action that asks the organizers about a person whose request was
     * put off (putOff()): the WP-Cron event QUESTIONS_MINUTES after their
     * first sign-in, and the crew page once they answer or skip the join
     * questions. Arg: the person id. Asks only once, whichever comes first.
     */
    public const ASK_HOOK = 'eventcrew_newcomer_ask';

    /** How long the crew page's join questions hold the request back at most. */
    public const QUESTIONS_MINUTES = 10;

    /** The role_notices key that records the organizers were asked. */
    private const ASKED_KEY = 'newcomer:asked';

    /** role_notices: when the request was put off for the crew page's questions (putOff()). */
    private const PUT_OFF_KEY = 'newcomer:put_off';

    /** role_notices: when the request was decided. */
    private const DECIDED_KEY = 'newcomer:decided';

    /** role_notices: the REASON_* of a No ('' for an OK). */
    private const REASON_KEY = 'newcomer:reason';

    /** role_notices: DM_KEY . <organizer id> => "<chat id>:<message id>" of their Telegram copy. */
    private const DM_KEY = 'newcomer:dm:';

    /** role_notices: MAIL_KEY . <organizer id> => when they were emailed the request. */
    private const MAIL_KEY = 'newcomer:mail:';

    /** @var array<int, string> Telegram usernames looked up in this request, by person id. */
    private array $handles = [];

    /** @var list<string> The "<chat>:<message>" copies the last decision edited. */
    private array $edited = [];

    public function __construct(
        private readonly PersonRepository $people,
        private readonly Mailer $mailer,
        private readonly TelegramClient $telegram,
        private readonly OpenTaskList $openTasks = new OpenTaskList()
    ) {
    }

    public function boot(): void
    {
        add_action('eventcrew/email_verified', [$this, 'onEmailVerified']);
        add_action(self::ASK_HOOK, [$this, 'onAskDue']);

        // Before the person's own role and pass messages (priority 10).
        add_action(RoleNotifier::GIVEN_HOOK, [$this, 'approveByAccess'], 5);
        add_action(AccessNotifier::PASS_HOOK, [$this, 'approveByAccess'], 5);
    }

    /**
     * The OK and No buttons under the organizers' Telegram notice.
     *
     * @return array<string, mixed>
     */
    public static function markup(int $personId): array
    {
        return ['inline_keyboard' => [[
            ['text' => '✅ ' . __('OK', 'eventcrew'), 'callback_data' => self::PREFIX . 'ok:' . $personId],
            ['text' => '❌ ' . __('No', 'eventcrew'), 'callback_data' => self::PREFIX . 'no:' . $personId],
        ]]];
    }

    /**
     * The buttons of "Turn down <name>?": with the reason Unknown to us (the
     * person is told), with no reason (not told), or back to OK and No.
     *
     * @return array<string, mixed>
     */
    public static function confirmMarkup(int $personId): array
    {
        return ['inline_keyboard' => [
            [['text' => '📨 ' . __('Unknown to us (they are told)', 'eventcrew'), 'callback_data' => self::PREFIX . 'nu:' . $personId]],
            [['text' => '🔕 ' . __('No reason (not told)', 'eventcrew'), 'callback_data' => self::PREFIX . 'nn:' . $personId]],
            [['text' => '↩ ' . __('Back', 'eventcrew'), 'callback_data' => self::PREFIX . 'bk:' . $personId]],
        ]];
    }

    /** The question under the request after No. */
    public static function confirmText(Person $person): string
    {
        return sprintf(
            /* translators: %s: the person's name */
            __('Turn down %s?', 'eventcrew'),
            $person->name()
        ) . "\n" . __('With “Unknown to us” they get a message asking them to talk to an organizer at an event. With “No reason” they are not told.', 'eventcrew');
    }

    /**
     * A confirmed address. A waiting newcomer's first one asks the
     * organizers, unless the request was put off for the crew page's join
     * questions (putOff()); every later one (each web sign-in re-confirms)
     * does nothing.
     */
    public function onEmailVerified(int $personId): void
    {
        $person = $this->people->find($personId);

        if (null === $person || $person->hasRoleNotice(self::PUT_OFF_KEY)) {
            return;
        }

        $this->askOrganizers($person);
    }

    /**
     * ASK_HOOK: the put-off request is due (the WP-Cron event), or the
     * person answered or skipped the join questions. Asks the organizers
     * once: a request already sent is not sent again.
     */
    public function onAskDue(int|string $personId): void
    {
        $person = (int) $personId > 0 ? $this->people->find((int) $personId) : null;

        if (null !== $person) {
            $this->askOrganizers($person);
        }
    }

    /**
     * Holds back the request of a waiting newcomer at their first web
     * sign-in, so they can answer the join questions on the crew page
     * first (JoinAnswers). Records it, and schedules ASK_HOOK
     * QUESTIONS_MINUTES ahead in case they neither answer nor skip. Nothing
     * for someone already asked or put off, or who answered already (in the
     * bot): their request goes at once. True when it was put off.
     */
    public static function putOff(PersonRepository $people, Person $person): bool
    {
        if (
            ! $person->isWaitingForOk()
            || $person->isAnonymized()
            || self::wasAsked($person)
            || $person->hasRoleNotice(self::PUT_OFF_KEY)
            || null !== JoinAnswers::of($person)['before']
        ) {
            return false;
        }

        $people->markRoleNotice($person->id, self::PUT_OFF_KEY);

        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + self::QUESTIONS_MINUTES * MINUTE_IN_SECONDS, self::ASK_HOOK, [$person->id]);
        }

        return true;
    }

    /**
     * Sends "<name> wants to join" to every organizer, once: nothing for
     * someone who is not waiting or was asked already.
     */
    private function askOrganizers(Person $person): void
    {
        if (! $person->isWaitingForOk() || $person->isAnonymized() || $person->hasRoleNotice(self::ASKED_KEY)) {
            return;
        }

        // Recorded first, so a second confirmation in the same moment does
        // not ask twice.
        $this->people->markRoleNotice($person->id, self::ASKED_KEY);

        $sent = [];

        foreach ($this->people->organizers() as $organizer) {
            $sent += (array) Language::forPerson($organizer, fn (): array => $this->ask($organizer, $person));
        }

        // Each organizer's Telegram copy and email, for the outcome later.
        $this->people->addRoleNotices($person->id, $sent);
    }

    /** RoleNotifier::GIVEN_HOOK and AccessNotifier::PASS_HOOK: a role or a pass makes the person OK. */
    public function approveByAccess(int $personId): void
    {
        if ($personId > 0) {
            $this->people->approve($personId);
        }
    }

    /**
     * An organizer said OK or No (in Telegram or on the crew page).
     * Returns self::DECIDED, self::ALREADY or self::UNKNOWN. On OK the
     * newcomer is told; on No only with the reason REASON_UNKNOWN. The other
     * organizers' copies of the request then show the outcome.
     */
    public function decide(int $personId, bool $ok, Person $organizer, string $reason = self::REASON_NONE): string
    {
        $this->edited = [];
        $person = $personId > 0 ? $this->people->find($personId) : null;

        if (null === $person || $person->isAnonymized()) {
            return self::UNKNOWN;
        }

        if (! $this->people->decideNewcomer($person->id, $ok, $organizer->name())) {
            return self::ALREADY;
        }

        $reason = $ok ? '' : (self::REASON_UNKNOWN === $reason ? self::REASON_UNKNOWN : self::REASON_NONE);
        $this->finish($person, $ok, $reason, $organizer->id, true);

        return self::DECIDED;
    }

    /**
     * The heartbeat: turns down the requests nobody answered in
     * TIMEOUT_HOURS, tells each person why, and shows the outcome in the
     * organizers' copies. At most $limit per run. Returns how many.
     */
    public function expireDue(int $limit): int
    {
        $cutoff = (int) current_time('timestamp') - self::TIMEOUT_HOURS * HOUR_IN_SECONDS;
        $done = 0;

        foreach ($this->people->waitingNewcomers() as $person) {
            if ($done >= $limit) {
                break;
            }

            $asked = $person->roleNotices[self::ASKED_KEY] ?? '';
            $at = '' === $asked ? false : strtotime($asked . ' UTC');

            if (false === $at || $at > $cutoff) {
                continue;
            }

            if (! $this->people->decideNewcomer($person->id, false, '')) {
                continue;
            }

            $this->finish($person, false, self::REASON_TIMEOUT, 0, true);
            $done++;
        }

        return $done;
    }

    /**
     * An admin accepts a turned-down person (the list in wp-admin): the same
     * OK as the ✅ button, with the same message to the person. The
     * organizers' Telegram copies show the new outcome. False when the person
     * is not turned down.
     */
    public function accept(int $personId, string $by): bool
    {
        $this->edited = [];
        $person = $personId > 0 ? $this->people->find($personId) : null;

        if (null === $person || $person->isAnonymized() || ! $this->people->acceptTurnedDown($person->id, $by)) {
            return false;
        }

        $this->finish($person, true, '', 0, false);

        return true;
    }

    /**
     * A tap under the Telegram notice: OK, No (asks to confirm), a reason
     * (turns down), or Back. A message can be forwarded, so the person
     * tapping must be an organizer.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onTap(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $fromId = (int) ($callbackQuery['from']['id'] ?? 0);
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $data = (string) ($callbackQuery['data'] ?? '');

        if (1 !== preg_match('/^nco:(ok|no|nu|nn|bk):(\d+)$/', $data, $m)) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $organizer = 0 === $fromId ? null : $this->people->findByTelegramUserId($fromId);

        if (null === $organizer || ! $organizer->isOrganizer) {
            $this->telegram->answerCallbackQuery($callbackId, __('Only organizers can answer this.', 'eventcrew'), true);

            return;
        }

        $verb = $m[1];
        $personId = (int) $m[2];

        if ('no' === $verb || 'bk' === $verb) {
            $this->askAgain($callbackId, $message, $organizer, $personId, 'no' === $verb);

            return;
        }

        $outcome = $this->decide($personId, 'ok' === $verb, $organizer, 'nu' === $verb ? self::REASON_UNKNOWN : self::REASON_NONE);

        if (self::UNKNOWN === $outcome) {
            $this->telegram->answerCallbackQuery($callbackId, __('That person was not found.', 'eventcrew'), true);
            $this->replace($message, '');

            return;
        }

        $person = $this->people->find($personId);
        $line = null === $person ? '' : self::decisionLine($person);

        if (self::ALREADY === $outcome) {
            $this->telegram->answerCallbackQuery($callbackId, $line, true);
        } else {
            $this->telegram->answerCallbackQuery($callbackId);
        }

        // The copies of the request were edited already; a message that is
        // not one of them (opened from the email link) is edited here.
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);

        if (null === $person) {
            $this->replace($message, $line);

            return;
        }

        if (in_array($chatId . ':' . $messageId, $this->edited, true)) {
            return;
        }

        Language::forPerson($organizer, fn () => $this->editRequest($chatId, $messageId, $organizer, $person, $line, ['inline_keyboard' => []]));
    }

    /**
     * The organizer email's Telegram link (nco-<id>): the same notice with
     * its OK and No buttons, or who decided already. Only shows them.
     */
    public function showRequest(int $telegramUserId, int $chatId, int $personId): void
    {
        $organizer = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $organizer || ! $organizer->isOrganizer) {
            $this->telegram->sendMessage($chatId, __('Only organizers can answer this.', 'eventcrew'));

            return;
        }

        $person = $personId > 0 ? $this->people->find($personId) : null;

        if (null === $person || $person->isAnonymized()) {
            $this->telegram->sendMessage($chatId, __('That person was not found.', 'eventcrew'));

            return;
        }

        if ($person->isWaitingForOk()) {
            $this->sendRequest($chatId, $organizer, $person, '', self::markup($person->id));

            return;
        }

        $this->sendRequest($chatId, $organizer, $person, self::decisionLine($person), null);
    }

    /**
     * The outcome of the request, like "✅ OK, by Kim, 2 Nov 14:05.": for a
     * later press, under the decided notice, and in the outcome email.
     */
    public static function decisionLine(Person $person): string
    {
        return self::outcomeLine(
            $person->approval,
            $person->approvalBy,
            $person->roleNotices[self::DECIDED_KEY] ?? '',
            $person->roleNotices[self::REASON_KEY] ?? ''
        );
    }

    /** When the request was decided (current_time('mysql')), '' when not recorded. */
    public static function decidedAt(Person $person): string
    {
        return $person->roleNotices[self::DECIDED_KEY] ?? '';
    }

    /** Whether the organizers were asked about $person already (their first confirmed email). */
    public static function wasAsked(Person $person): bool
    {
        return $person->hasRoleNotice(self::ASKED_KEY);
    }

    /** The REASON_* of a turned-down request, '' when not recorded. */
    public static function reason(Person $person): string
    {
        return $person->roleNotices[self::REASON_KEY] ?? '';
    }

    /**
     * The outcome line from its parts: the approval (Person::APPROVAL_*),
     * who decided, when (a current_time('mysql') value, '' when not known)
     * and the REASON_* of a No.
     */
    public static function outcomeLine(string $approval, string $by, string $at, string $reason): string
    {
        $when = '' === $at ? '' : Dates::signedUpLabel($at);
        $turnedDown = Person::APPROVAL_NO === $approval;

        if ($turnedDown && self::REASON_TIMEOUT === $reason) {
            return '⌛ ' . ('' === $when
                ? __('No organizer answered in 48 hours, so the request timed out.', 'eventcrew')
                : sprintf(
                    /* translators: %s: when, like 2 Nov 14:05 */
                    __('No organizer answered in 48 hours, so the request timed out on %s.', 'eventcrew'),
                    $when
                )) . ' ' . __('They were told to talk to an organizer at an event.', 'eventcrew');
        }

        $by = '' !== $by ? $by : __('an organizer', 'eventcrew');

        if (! $turnedDown) {
            return '✅ ' . ('' === $when
                /* translators: %s: the name of the organizer who said OK */
                ? sprintf(__('OK, by %s.', 'eventcrew'), $by)
                /* translators: 1: the name of the organizer who said OK, 2: when, like 2 Nov 14:05 */
                : sprintf(__('OK, by %1$s, %2$s.', 'eventcrew'), $by, $when));
        }

        $line = '' === $when
            /* translators: %s: the name of the organizer who said No */
            ? sprintf(__('No, by %s.', 'eventcrew'), $by)
            /* translators: 1: the name of the organizer who said No, 2: when, like 2 Nov 14:05 */
            : sprintf(__('No, by %1$s, %2$s.', 'eventcrew'), $by, $when);

        if (self::REASON_UNKNOWN === $reason) {
            $line .= ' ' . __('They were told: unknown to the organizers.', 'eventcrew');
        } elseif (self::REASON_NONE === $reason) {
            $line .= ' ' . __('They were not told.', 'eventcrew');
        }

        return '❌ ' . $line;
    }

    /**
     * After the decision: records when and why, tells the person (OK, or a
     * No with a reason they are told), and shows the outcome in the
     * organizers' copies. $deciderId is the organizer who decided (0: the
     * timeout or wp-admin), who gets no outcome email.
     */
    private function finish(Person $person, bool $ok, string $reason, int $deciderId, bool $email): void
    {
        $this->people->addRoleNotices($person->id, [
            self::DECIDED_KEY => (string) current_time('mysql'),
            self::REASON_KEY => $reason,
        ]);

        $decided = $this->people->find($person->id) ?? $person;

        if ($ok) {
            Language::forPerson($decided, fn () => $this->tellOk($decided));
        } elseif (self::REASON_UNKNOWN === $reason) {
            Language::forPerson($decided, fn () => $this->tell($decided, 'newcomer_unknown'));
        } elseif (self::REASON_TIMEOUT === $reason && ScheduledMessages::isOn(ScheduledMessages::NEWCOMER)) {
            // Switchable on the Scheduled messages card (Owner 2026-11-14).
            Language::forPerson($decided, fn () => $this->tell($decided, 'newcomer_timeout'));
        }

        $this->fanOut($decided, $deciderId, $email);
    }

    /**
     * Every organizer's Telegram copy of the request shows the outcome and
     * loses its buttons; with $email, the organizers who were emailed the
     * request (except the one who decided) get the outcome by email.
     */
    private function fanOut(Person $person, int $deciderId, bool $email): void
    {
        foreach ($this->people->organizers() as $organizer) {
            $copy = $person->roleNotices[self::DM_KEY . $organizer->id] ?? '';
            $mail = $email && $organizer->id !== $deciderId && isset($person->roleNotices[self::MAIL_KEY . $organizer->id]);

            if ('' === $copy && ! $mail) {
                continue;
            }

            Language::forPerson($organizer, function () use ($organizer, $person, $copy, $mail): void {
                $line = self::decisionLine($person);

                if (1 === preg_match('/^(-?\d+):(\d+)$/', $copy, $m)) {
                    $this->editRequest((int) $m[1], (int) $m[2], $organizer, $person, $line, ['inline_keyboard' => []]);
                    $this->edited[] = $copy;
                }

                if ($mail) {
                    $context = ['member' => $person, 'outcome' => $line];
                    $this->mailer->toPerson(
                        $organizer->id,
                        $organizer->email,
                        MessageTemplates::subject('newcomer_decided', $context),
                        EmailBody::withGreeting($organizer->displayName, MessageTemplates::body('newcomer_decided', $context))
                    );
                }
            });
        }
    }

    /**
     * No (the confirm) or Back (OK and No again) on a request still waiting.
     * Once decided, the tap is told who decided and the message shows it.
     *
     * @param array<string, mixed> $message
     */
    private function askAgain(string $callbackId, array $message, Person $organizer, int $personId, bool $confirm): void
    {
        $person = $personId > 0 ? $this->people->find($personId) : null;

        if (null === $person || $person->isAnonymized()) {
            $this->telegram->answerCallbackQuery($callbackId, __('That person was not found.', 'eventcrew'), true);
            $this->replace($message, '');

            return;
        }

        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);

        if (! $person->isWaitingForOk()) {
            $line = self::decisionLine($person);
            $this->telegram->answerCallbackQuery($callbackId, $line, true);
            Language::forPerson($organizer, fn () => $this->editRequest($chatId, $messageId, $organizer, $person, $line, ['inline_keyboard' => []]));

            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);

        Language::forPerson($organizer, fn () => $confirm
            ? $this->editRequest($chatId, $messageId, $organizer, $person, self::confirmText($person), self::confirmMarkup($person->id))
            : $this->editRequest($chatId, $messageId, $organizer, $person, '', self::markup($person->id)));
    }

    /**
     * One organizer, in their language: by Telegram (if they have it) and by
     * email, each unless they switched that route off. Returns the
     * role_notices entries of what was sent.
     *
     * @return array<string, string>
     */
    private function ask(Person $organizer, Person $person): array
    {
        $sent = [];
        $context = ['member' => $person];
        $prefs = new NotificationPreferences();

        // The app only tells: OK and No are in the bot and the wp-admin, so
        // the DM goes even to an organizer who chose App only (Lou,
        // 2026-11-13: always send the newcomer approval DM).
        PushNotifier::notify($organizer, NotificationPreferences::NEWCOMER, MessageTemplates::body('newcomer_waiting_dm', $context));

        if ($prefs->dmAllowed($organizer, NotificationPreferences::NEWCOMER)) {
            $chatId = (int) $organizer->telegramChatId;
            $messageId = $this->sendRequest($chatId, $organizer, $person, '', self::markup($person->id));

            if ($messageId > 0) {
                $sent[self::DM_KEY . $organizer->id] = $chatId . ':' . $messageId;
            }
        }

        if (! $prefs->emailAllowed($organizer, NotificationPreferences::NEWCOMER)) {
            return $sent;
        }

        $url = HelpPage::url(DoorLinkSender::ADMIN_ANCHOR);
        $actions = '' === $url ? [] : [['label' => DoorLinkSender::adminButtonLabel(), 'url' => $url]];
        // The same OK and No in the bot: the link only shows them.
        $bot = MessageActions::startUrl(self::START_VERB, $person->id);

        if ('' !== $bot) {
            $actions[] = [
                'label' => __('Answer the request', 'eventcrew'),
                'url' => $bot,
                'style' => EmailBody::STYLE_TELEGRAM,
            ];
        }

        // The person's @username, linked to their t.me page (Owner 2026-11-07).
        $handleLink = $this->handleAction($person);

        if (null !== $handleLink) {
            $actions[] = $handleLink;
        }

        $this->mailer->toPerson(
            $organizer->id,
            $organizer->email,
            MessageTemplates::subject('newcomer_waiting', $context),
            EmailBody::withGreeting($organizer->displayName, MessageTemplates::body('newcomer_waiting', $context)),
            $actions
        );

        $sent[self::MAIL_KEY . $organizer->id] = (string) current_time('mysql');

        return $sent;
    }

    /**
     * The newcomer's own "the team said OK": Telegram if linked, else email,
     * with the open tasks they can now take.
     */
    private function tellOk(Person $person): void
    {
        $context = ['person' => $person, OpenTaskList::CONTEXT_KEY => $this->openTasks->forPerson($person)];

        if (null !== $person->telegramChatId) {
            $this->telegram->sendMessage(
                $person->telegramChatId,
                DmBody::frame($person, MessageTemplates::body('newcomer_ok_dm', $context)),
                MessageActions::replyMarkup(MessageActions::of('newcomer_ok_dm', $context, $this->mailer))
            );

            return;
        }

        $this->mailer->toPerson(
            $person->id,
            $person->email,
            MessageTemplates::subject('newcomer_ok', $context),
            EmailBody::withGreeting($person->displayName, MessageTemplates::body('newcomer_ok', $context)),
            MessageActions::email(MessageActions::of('newcomer_ok', $context, $this->mailer))
        );
    }

    /**
     * A turned-down newcomer told why ($kind: newcomer_unknown or
     * newcomer_timeout): Telegram if linked, else email.
     */
    private function tell(Person $person, string $kind): void
    {
        $context = ['person' => $person];

        if (null !== $person->telegramChatId) {
            $this->telegram->sendMessage(
                $person->telegramChatId,
                DmBody::frame($person, MessageTemplates::body($kind . '_dm', $context))
            );

            return;
        }

        $this->mailer->toPerson(
            $person->id,
            $person->email,
            MessageTemplates::subject($kind, $context),
            EmailBody::withGreeting($person->displayName, MessageTemplates::body($kind, $context))
        );
    }

    /**
     * Sends the request to an organizer, with $line under it. Returns the
     * message id, 0 when it was not sent.
     *
     * @param array<string, mixed>|null $markup
     */
    private function sendRequest(int $chatId, Person $organizer, Person $person, string $line, ?array $markup): int
    {
        if (0 === $chatId) {
            return 0;
        }

        [$html, $plain] = $this->requestText($organizer, $person, $line);
        $params = ['chat_id' => $chatId, 'text' => $html, 'parse_mode' => 'HTML'];

        if (null !== $markup) {
            $params['reply_markup'] = $markup;
        }

        // Telegram can refuse the profile link; the request then goes without it.
        // The request is a record: a button tap never replaces it with a screen.
        $result = $this->telegram->record()->call('sendMessage', $params)
            ?? $this->telegram->record()->sendMessage($chatId, $plain, $markup);

        return (int) ($result['message_id'] ?? 0);
    }

    /**
     * Rewrites an organizer's copy of the request with $line under it and
     * $markup as its buttons.
     *
     * @param array<string, mixed> $markup
     */
    private function editRequest(int $chatId, int $messageId, Person $organizer, Person $person, string $line, array $markup): void
    {
        if (0 === $chatId || 0 === $messageId) {
            return;
        }

        [$html, $plain] = $this->requestText($organizer, $person, $line);
        $done = $this->telegram->call('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'reply_markup' => $markup,
        ]);

        if (null === $done) {
            $this->telegram->editMessageText($chatId, $messageId, $plain, $markup);
        }
    }

    /**
     * The request as the organizer reads it, with $line under it: Telegram
     * HTML with the person's @username and a link to their profile (it shows
     * their picture), and the same as plain text without the link.
     *
     * @return array{0: string, 1: string}
     */
    private function requestText(Person $organizer, Person $person, string $line): array
    {
        // Framed like every DM the organizer did not ask for (DmBody), with
        // the sign-off last, under the profile and the decision lines.
        $plain = DmBody::open($organizer, MessageTemplates::body('newcomer_waiting_dm', ['member' => $person]));
        $html = self::html($plain);

        if (null !== $person->telegramUserId && $person->telegramUserId > 0) {
            $handle = $this->handle($person);
            $link = '<a href="tg://user?id=' . $person->telegramUserId . '">'
                . self::html(__('Open their Telegram profile', 'eventcrew')) . '</a>';
            $html .= "\n👤 " . ('' === $handle ? '' : self::html('@' . $handle) . ' · ') . $link;

            if ('' !== $handle) {
                $plain .= "\n👤 @" . $handle;
            }
        }

        if ('' !== $line) {
            $html .= "\n\n" . self::html($line);
            $plain .= "\n\n" . $line;
        }

        $signOff = DmBody::signOff();

        if ('' !== $signOff) {
            $html .= "\n\n" . self::html($signOff);
            $plain .= "\n\n" . $signOff;
        }

        return [$html, $plain];
    }

    /**
     * The organizer email's link to the person in Telegram: "@handle" to
     * https://t.me/<handle>, a small link under the buttons. Null when the
     * person has no Telegram or no username: a tg://user?id= link is left
     * out, as mail apps drop or block that scheme.
     *
     * @return array{label: string, url: string, style: string}|null
     */
    private function handleAction(Person $person): ?array
    {
        if (null === $person->telegramUserId || $person->telegramUserId <= 0) {
            return null;
        }

        $handle = $this->handle($person);

        // Telegram usernames are 5 to 32 letters, digits and underscores; a
        // value that is not one makes no link.
        if (1 !== preg_match('/^[A-Za-z0-9_]{5,32}$/', $handle)) {
            return null;
        }

        return ['label' => '@' . $handle, 'url' => 'https://t.me/' . $handle, 'style' => EmailBody::STYLE_LINK];
    }

    /** The person's Telegram username, asked from Telegram once per request; '' when they have none. */
    private function handle(Person $person): string
    {
        if (! isset($this->handles[$person->id])) {
            $chat = null === $person->telegramUserId ? null : $this->telegram->getChat($person->telegramUserId);
            $this->handles[$person->id] = is_array($chat) ? trim((string) ($chat['username'] ?? '')) : '';
        }

        return $this->handles[$person->id];
    }

    /** Plain text made safe for Telegram HTML. */
    private static function html(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
    }

    /**
     * The notice keeps its text, gets $line under it, and loses its buttons,
     * so it cannot be pressed again from the same message.
     *
     * @param array<string, mixed> $message
     */
    private function replace(array $message, string $line): void
    {
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);

        if (0 === $chatId || 0 === $messageId) {
            return;
        }

        $text = (string) ($message['text'] ?? '');
        $text = '' === $line ? $text : ('' === $text ? $line : $text . "\n\n" . $line);

        if ('' === $text) {
            return;
        }

        $this->telegram->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => []]);
    }
}

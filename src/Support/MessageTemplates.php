<?php

declare(strict_types=1);

namespace EventCrew\Support;

use Closure;
use EventCrew\Admin\PeoplePage;
use EventCrew\Models\Person;
use EventCrew\Models\Task;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\CalendarController;
use EventCrew\Telegram\PermissionService;
use EventCrew\Telegram\TicketRedemptionService;

/**
 * Every message template (MessageTemplate) and the texts organizers saved for
 * them on the Messages page in wp-admin.
 *
 * One option per kind, OPTION_PREFIX . id, holds:
 * - subject: a TranslatedText value, when the subject was changed;
 * - body: a TranslatedText value, when the text was changed;
 * - settings: an array for the per-template settings (the AI tick and
 *   prompt, the hybrid tick). This class keeps it and never reads it; "Reset
 *   to built-in text" leaves it as it is.
 * A subject or body that is not stored is the built-in text.
 *
 * The code that sends a message calls subject() and body() with its context
 * (the person, the task, ...), inside Language::run() for the reader's
 * language. They answer with the stored text in that language, or the
 * built-in text, with the placeholders filled.
 */
final class MessageTemplates
{
    /** The option of one kind is this followed by its id. */
    public const OPTION_PREFIX = 'eventcrew_message_template_';

    /** The crew page's board panel, where an email's See open tasks lands. */
    public const BOARD_ANCHOR = 'eventcrew-panel-board';

    /** Set to '1' once migrateReminderDescription() has run. */
    public const REMINDER_DESCRIPTION_OPTION = 'eventcrew_reminder_description_migrated';

    public const SUBJECT = 'subject';
    public const BODY = 'body';

    /** The longest subject kept, in characters. */
    public const SUBJECT_MAX_LENGTH = 200;

    /** The longest text kept, in characters. Telegram takes 4,096 at most. */
    public const BODY_MAX_LENGTH = 4000;

    /** The posted name of the Messages page form: message[subject], message[body]. */
    public const POST_NAME = 'message';

    /**
     * Runs on the posted settings of one template when the Messages page saves
     * it, so the per-template settings (the AI tick, the hybrid tick) can be
     * read from the form: apply_filters(SETTINGS_FILTER, $settings, $id, $posted).
     */
    public const SETTINGS_FILTER = 'eventcrew/message_template_settings';

    /**
     * Runs inside each template's form on the Messages page, after the text,
     * so other parts can print their fields: do_action(FIELDS_ACTION,
     * $template, $settings).
     */
    public const FIELDS_ACTION = 'eventcrew/message_template_fields';

    /**
     * What save() answers when the text lacks a required placeholder
     * (MessageTemplate::missingRequired()). Nothing was saved then.
     */
    public const ERROR_MISSING_PLACEHOLDER = 'missing_placeholder';

    public function __construct(
        private readonly TranslatedText $translator
    ) {
    }

    /**
     * Every kind, in the order the Messages page lists them. Built on each
     * call, so the labels and built-in texts are in the current language.
     *
     * @return array<string, MessageTemplate>
     */
    public static function all(): array
    {
        $list = [];

        $shared = self::sharedPlaceholders();
        $eventTimes = self::eventTimePlaceholders();
        $forTask = self::taskPlaceholders();
        $previews = self::previews();
        $actions = self::actions();

        foreach (self::definitions() as $template) {
            $list[$template->id] = new MessageTemplate(
                $template->id,
                $template->label,
                $template->channel,
                $template->description,
                $template->subject,
                $template->body,
                $template->placeholders
                    + (isset($template->placeholders['event']) ? self::eventTimesFor($template->id, $eventTimes) : [])
                    + (isset($template->placeholders['role'], $template->placeholders['event']) ? $forTask : [])
                    + $shared,
                $template->wired,
                ($previews[$template->id] ?? []) + $template->preview,
                $actions[$template->id] ?? $template->actions
            );
        }

        return $list;
    }

    /**
     * What each kind offers to do (MessageAction), by kind. The two kinds of
     * a pair (signup_confirmed and signup_confirmed_dm) share one closure, so
     * the email and the DM offer the same actions: the DM as buttons, the
     * email as web buttons plus "Open in Telegram" links (MessageActions).
     *
     * @return array<string, Closure(array<string, mixed>, Mailer): list<MessageAction>>
     */
    private static function actions(): array
    {
        $list = [];

        foreach (self::actionPairs() as $ids => $actions) {
            foreach (explode(' ', $ids) as $id) {
                $list[$id] = $actions;
            }
        }

        return $list;
    }

    /**
     * The actions, keyed by the kinds that share them, separated by a space.
     *
     * @return array<string, Closure(array<string, mixed>, Mailer): list<MessageAction>>
     */
    private static function actionPairs(): array
    {
        return [
            // The ticket first, as it is what gets them in; the calendar hold
            // next, the strongest nudge there is against a no-show; the crew
            // guide last.
            'reminder reminder_dm' => static fn (array $c, Mailer $m): array => self::present([
                self::ticketAction($c, $m),
                self::cancelAction($c, $m),
                self::guideAction(),
            ]),
            'signup_confirmed signup_confirmed_dm' => static fn (array $c, Mailer $m): array => self::present([
                self::ticketAction($c, $m),
                self::calendarAction($c),
                self::cancelAction($c, $m),
                self::guideAction(),
            ]),
            // A cancellation: undo it in one tap, or pick another task.
            'cancel_confirmed cancel_confirmed_dm' => static fn (array $c, Mailer $m): array => self::present([
                self::signUpAgainAction($c, $m),
                self::boardAction($m),
            ]),
            // A spot that opened: sign up for it at once, else the board.
            'slot_freed slot_freed_dm' => static fn (array $c, Mailer $m): array => self::present([
                self::taskSignupAction($c, $m),
                self::boardAction($m),
            ]),
            // A task the organizers deleted: another task at the event.
            'task_cancelled task_cancelled_dm' => static fn (array $c, Mailer $m): array => [self::boardAction($m)],
            // A mark against the standing: the open tasks, to win it back; for
            // a no-show, a word to the organizers in the bot when it is wrong.
            'late_cancel late_cancel_dm' => static fn (array $c, Mailer $m): array => [self::boardAction($m)],
            // A thank-you: the open tasks, for the next one.
            'task_completed task_completed_dm' => static fn (array $c, Mailer $m): array => [self::boardAction($m)],
            'no_show no_show_dm' => static fn (array $c, Mailer $m): array => self::present([
                self::boardAction($m),
                self::tellAction($c),
            ]),
            // The call for open tasks: the board (one button for the whole
            // list, Owner 2026-11-07), and the crew's group when its link is
            // known.
            'open_task_call open_task_call_dm' => static fn (array $c, Mailer $m): array => self::present([
                self::boardAction($m),
                self::groupAction(),
            ]),
            // A takeover done: nothing to do, the board for another task.
            'takeover_done takeover_done_dm' => static fn (array $c, Mailer $m): array => [self::boardAction($m)],
            // A takeover that did not happen: ask someone else to hand theirs
            // over, or the board for the open tasks listed (one button, not
            // one per task, Owner 2026-11-07).
            'takeover_refused takeover_refused_dm takeover_expired takeover_expired_dm'
                . ' takeover_failed takeover_failed_dm takeover_closed takeover_closed_dm' =>
                static fn (array $c, Mailer $m): array => [
                    new MessageAction('replace', __('Take over a task', 'eventcrew'), '🔁 ' . __('Take over a task', 'eventcrew'), null, '', 'replace'),
                    self::boardAction($m),
                ],
            // Free entry: the bot's free-entry ticket button, the crew page on
            // the web.
            // A button per next night (the 🎟 picker's own tkt: button; in an
            // email a tkt-<date> link that shows it), then the whole picker.
            'credit_granted credit_granted_dm' => static fn (array $c, Mailer $m): array => [
                ...self::nightActions((array) ($c['nights'] ?? [])),
                new MessageAction(
                    'free_ticket',
                    __('Get your free ticket', 'eventcrew'),
                    '🎟 ' . __('Free-entry ticket', 'eventcrew'),
                    static fn (): string => $m->boardUrl(),
                    '',
                    'ticket'
                ),
            ],
            // New access: the open tasks, or the Admin tab for a new organizer
            // (also under the organizer guide).
            // A role, a pass or the team's OK: the board for the open tasks
            // listed ({open_tasks}), one button (Owner 2026-11-07).
            'access_role access_role_dm access_pass access_pass_dm newcomer_ok newcomer_ok_dm' =>
                static fn (array $c, Mailer $m): array => [self::boardAction($m)],
            // The organizer guide draws its own buttons and links (WelcomeSender, Owner 2026-11-07).
            'access_organizer access_organizer_dm' => static fn (): array => self::present([
                self::adminAction(),
                new MessageAction('people', '👥 ' . __('People', 'eventcrew'), '', null, '', 'people'),
                self::newDeskLinkAction(),
            ]),
            // Organizer notices: give the role from that person's role
            // buttons in the bot (who-<id>) or the Admin tab; the night's
            // sales desk link, and a new one from the bot's 💳 Sales desk
            // button.
            'role_met role_met_dm' => static fn (array $c): array => [
                self::personAction(
                    $c,
                    'people',
                    __('Give them the role', 'eventcrew'),
                    '👥 ' . __('Give them the role', 'eventcrew'),
                    static fn (): string => HelpPage::url(DoorLinkSender::ADMIN_ANCHOR)
                ),
            ],
            // Done already: that person's role buttons, in case it was a
            // mistake.
            'role_given role_given_dm organizer_made organizer_made_dm' => static fn (array $c): array => [
                self::personAction(
                    $c,
                    'who',
                    __('Open their roles', 'eventcrew'),
                    '🏷 ' . __('Open their roles', 'eventcrew'),
                    static fn (): string => HelpPage::url(DoorLinkSender::ADMIN_ANCHOR)
                ),
            ],
            // A blocked member: the person on the People page in wp-admin,
            // where Unban is; in the bot, their role buttons.
            'forged_request forged_request_dm' => static fn (array $c): array => [
                self::personAction(
                    $c,
                    'who',
                    __('Open the person', 'eventcrew'),
                    '👤 ' . __('Open the person', 'eventcrew'),
                    static fn (): string => ($c['member'] ?? null) instanceof Person && $c['member']->id > 0
                        ? add_query_arg(
                            ['page' => PeoplePage::PAGE_SLUG, 'person' => $c['member']->id],
                            admin_url('admin.php')
                        )
                        : ''
                ),
            ],
            'sales_desk_link sales_desk_link_dm' => static fn (array $c): array => [
                self::deskAction($c),
                self::newDeskLinkAction(),
            ],
            'organizer_notice organizer_notice_dm' => static fn (array $c): array => self::present([
                self::deskAction($c),
                self::adminAction(),
                self::newDeskLinkAction(),
            ]),
        ];
    }

    /**
     * The night's one-time sales desk link, made by the sender ('desk_url').
     *
     * @param array<string, mixed> $c
     */
    private static function deskAction(array $c): MessageAction
    {
        $url = (string) ($c['desk_url'] ?? '');

        return new MessageAction('desk', DoorLinkSender::buttonLabel(), '', static fn (): string => $url);
    }

    /**
     * Shows the person of the notice ('member'): in the DM, their role
     * buttons (perm:who:<id>); in the email, $url and the Open in Telegram
     * link who-<id>. Without a known person, the bot's 👥 People.
     *
     * @param array<string, mixed> $c
     * @param Closure(): string $url
     */
    private static function personAction(array $c, string $id, string $label, string $dmLabel, Closure $url): MessageAction
    {
        $personId = ($c['member'] ?? null) instanceof Person ? $c['member']->id : 0;

        return $personId > 0
            ? new MessageAction(
                $id,
                $label,
                $dmLabel,
                $url,
                PermissionService::whoData($personId),
                '',
                PermissionService::WHO_VERB . '-' . $personId
            )
            : new MessageAction($id, $label, $dmLabel, $url, '', 'people');
    }

    /** A new sales desk link: the bot's 💳 Sales desk button. */
    private static function newDeskLinkAction(): MessageAction
    {
        return new MessageAction('new_desk_link', '💳 ' . __('Sales desk', 'eventcrew'), '', null, '', 'sales');
    }

    /** What the Admin tab is for, in the organizer DM (access_organizer_dm). */
    public static function adminTabLine(): string
    {
        return __('The Crew app has an Admin tab for you, where you manage tasks and people.', 'eventcrew');
    }

    /** The crew's Telegram group, or null when its link is not known. */
    private static function groupAction(): ?MessageAction
    {
        $url = BoardService::groupLink();

        return '' === $url
            ? null
            : new MessageAction(
                'group',
                __('Open the group in Telegram', 'eventcrew'),
                __('Open the group', 'eventcrew'),
                static fn (): string => $url
            );
    }

    /** The Admin tab of the crew page, or null when no crew page is set. */
    private static function adminAction(): ?MessageAction
    {
        $url = HelpPage::url(DoorLinkSender::ADMIN_ANCHOR);

        return '' === $url
            ? null
            : new MessageAction('admin', DoorLinkSender::adminButtonLabel(), '', static fn (): string => $url);
    }

    /**
     * @param list<MessageAction|null> $actions
     * @return list<MessageAction>
     */
    private static function present(array $actions): array
    {
        return array_values(array_filter($actions));
    }

    /** The task's ticket: a signed link, the same in the email and the DM. */
    private static function ticketAction(array $c, Mailer $m): MessageAction
    {
        return new MessageAction(
            'ticket',
            __('Show your ticket', 'eventcrew'),
            __('🎟 Show your ticket', 'eventcrew'),
            static fn (): string => $m->ticketUrl((int) ($c['assignment_id'] ?? 0))
        );
    }

    private static function calendarAction(array $c): MessageAction
    {
        $task = $c['task'] ?? null;

        return new MessageAction(
            'calendar',
            __('Add to calendar', 'eventcrew'),
            __('📅 Add to calendar', 'eventcrew'),
            static fn (): string => $task instanceof Task ? CalendarController::url($task->id) : ''
        );
    }

    /**
     * Cancel the task. In the DM, the "Cancel?" question first
     * (BoardService::CANCEL_ASK_PREFIX): it says whether the cancel would be
     * late and offers to hand the task over; only its Yes runs the board's
     * drop. In the email, the crew page: for a
     * person with no Telegram chat a sign-in link (CrewPageHelp), as before,
     * for everyone else the page itself; and the bot's board in Telegram.
     */
    private static function cancelAction(array $c, Mailer $m): MessageAction
    {
        $task = $c['task'] ?? null;
        $person = $c['person'] ?? null;

        return new MessageAction(
            'cancel',
            CrewPageHelp::webOnly($person)
                ? __('Manage my tasks', 'eventcrew')
                : __('Can’t make it? Cancel', 'eventcrew'),
            '',
            static fn (): string => CrewPageHelp::webOnly($person) && $person instanceof Person
                ? $m->manageUrl($person->id)
                : CrewPageHelp::pageUrl(),
            $task instanceof Task ? BoardService::CANCEL_ASK_PREFIX . $task->id : '',
            'board'
        );
    }

    /** The crew guide, or null when no guide link is set. */
    private static function guideAction(): ?MessageAction
    {
        $url = Roles::guideUrl();

        return '' === $url ? null : new MessageAction('guide', RoleGuide::label(), '', static fn (): string => $url);
    }

    /**
     * Sign up for the task, or null with no task. In the DM, the join-only
     * callback (j:, as the welcome DM's buttons), so the tap runs the
     * board's sign-up with all its checks and never turns into a cancel. In
     * the email, the crew page at that task's row with its Sign up button,
     * and the bot showing that task with its Sign up button
     * (SlotFreedNotice::startPayload()).
     *
     * @param array<string, mixed> $c
     */
    private static function taskSignupAction(array $c, Mailer $m): ?MessageAction
    {
        $task = $c['task'] ?? null;

        if (! $task instanceof Task) {
            return null;
        }

        return new MessageAction(
            'signup',
            __('Sign up', 'eventcrew'),
            sprintf(
                /* translators: 1: the role with its emoji, 2: the day, e.g. "Sat 7 Nov" */
                __('Sign up: %1$s · %2$s', 'eventcrew'),
                $task->roleDisplay(),
                Dates::dayLabel($task->taskDate)
            ),
            static function () use ($m, $task): string {
                $url = $m->boardUrl();

                return '' === $url ? '' : $url . '#' . SlotFreedNotice::ANCHOR_PREFIX . $task->id;
            },
            'j:' . $task->id,
            '',
            SlotFreedNotice::startPayload($task->id)
        );
    }

    /**
     * Sign up again for the task just cancelled: taskSignupAction() under
     * another label. Cheap, so one tap: the join-only callback runs the
     * board's sign-up with all its checks.
     *
     * @param array<string, mixed> $c
     */
    private static function signUpAgainAction(array $c, Mailer $m): ?MessageAction
    {
        $signup = self::taskSignupAction($c, $m);

        return null === $signup ? null : new MessageAction(
            'signup_again',
            __('Sign up again', 'eventcrew'),
            '↩️ ' . __('Sign up again', 'eventcrew'),
            $signup->url,
            $signup->callback,
            $signup->verb,
            $signup->start
        );
    }

    /**
     * The open tasks, one button for a whole list (Owner 2026-11-07): in the
     * DM the bot's board (MenuButtons::BOARD), in the email the crew page's
     * board panel and the "Open in Telegram" link to the bot's board. Both
     * open on the next date with open tasks (Q363): the board's own first
     * day, which also has the special tasks, so no date is fixed here and a
     * mail read later is not stale.
     */
    private static function boardAction(Mailer $m): MessageAction
    {
        return new MessageAction(
            'board',
            __('See open tasks', 'eventcrew'),
            '📋 ' . __('See open tasks', 'eventcrew'),
            static function () use ($m): string {
                $url = $m->boardUrl();

                return '' === $url ? '' : $url . '#' . self::BOARD_ANCHOR;
            },
            '',
            'board'
        );
    }

    /**
     * "Not right? Tell us": the bot's 'tell' verb, which asks the person to
     * write what is wrong; the bot's helper sends the chat to the organizers
     * (ChatForwarder). Null when the helper or its forwarding is off, or for
     * a person with no Telegram chat, whom the bot cannot place.
     *
     * @param array<string, mixed> $c
     */
    private static function tellAction(array $c): ?MessageAction
    {
        $person = $c['person'] ?? null;

        if (
            ! AssistantSettings::isActive()
            || ! AssistantSettings::forwardEnabled()
            || ($person instanceof Person && CrewPageHelp::webOnly($person))
        ) {
            return null;
        }

        return new MessageAction(
            'tell',
            __('Not right? Tell us', 'eventcrew'),
            '💬 ' . __('Not right? Tell us', 'eventcrew'),
            null,
            '',
            'tell'
        );
    }

    /**
     * The placeholders every kind has, listed after its own.
     *
     * @return array<string, array{description: string, resolve: Closure, required: bool}>
     */
    private static function sharedPlaceholders(): array
    {
        return [
            'organization' => self::placeholder(
                __('The organization’s name (Settings > Web page, else the site name).', 'eventcrew'),
                static fn (): string => Organization::name()
            ),
        ];
    }

    /**
     * {venue}: "📍 venue" of the event $eventId finds in the context
     * (EventVenue), on a line of its own.
     *
     * @param Closure(array<string, mixed>): ?int $eventId
     * @return array{description: string, resolve: Closure, required: bool}
     */
    private static function venuePlaceholder(Closure $eventId): array
    {
        return self::placeholder(
            __('Where the event is, like “📍 Dance hall”: the event’s own venue, else the default venue (Settings > Web page), else the venue from EventMesh. On a line of its own; the line goes when no venue is known.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal.
            static fn (array $c): string => EventVenue::line($eventId($c)),
            false,
            true
        );
    }

    /**
     * $eventTimes for kind $id: the post-event emails have no
     * {event_description}, since they show that text under the next event's
     * picture (FollowupMail), not in the text.
     *
     * @param array<string, array{description: string, resolve: Closure, required: bool}> $eventTimes
     * @return array<string, array{description: string, resolve: Closure, required: bool}>
     */
    private static function eventTimesFor(string $id, array $eventTimes): array
    {
        return in_array($id, FollowupText::TEMPLATES, true)
            ? array_diff_key($eventTimes, ['event_description' => true])
            : $eventTimes;
    }

    /**
     * The placeholders of the event's times and description, for every kind
     * that names the event ({event}). Empty when the message is not about a
     * linked event: the task's, else $context['event_id'].
     *
     * @return array<string, array{description: string, resolve: Closure, required: bool}>
     */
    private static function eventTimePlaceholders(): array
    {
        $eventId = static fn (array $c): ?int => ($c['task'] ?? null) instanceof Task
            ? $c['task']->eventPostId
            : (is_int($c['event_id'] ?? null) ? $c['event_id'] : null);

        return [
            'venue' => self::venuePlaceholder($eventId),
            'event_description' => self::placeholder(
                __('The AI’s short text about the event from the group board (at most two sentences), in the reader’s language; never the event’s own description. On a line of its own; the line goes when there is no such text in that language.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal.
                static fn (array $c): string => EventDescription::short($eventId($c)),
                false,
                true
            ),
            'start_time' => self::placeholder(
                __('The event’s start time, like 19:00. Empty when the event has none.', 'eventcrew'),
                static fn (array $c): string => EventTimes::start($eventId($c))
            ),
            'doors_close' => self::placeholder(
                __('When the doors close, like 19:30. Empty when doors close is off (Settings > Web page) and the event has no time of its own.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal.
                static fn (array $c): string => EventTimes::doorsClose($eventId($c))
            ),
        ];
    }

    /**
     * The task's description and the event's times, for every kind about a
     * task ({role} and {event}), so the text of any of them can use them.
     *
     * @return array{
     *     description: array{description: string, resolve: Closure, required: bool},
     *     event_times: array{description: string, resolve: Closure, required: bool}
     * }
     */
    private static function taskPlaceholders(): array
    {
        return [
            'description' => self::placeholder(
                __('The description of the task, or of its role when the task has none. On a line of its own; the line goes when there is no description.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal.
                static fn (array $c): string => is_string($c['description'] ?? null)
                    ? $c['description']
                    : (($c['task'] ?? null) instanceof Task ? RoleGuide::taskDescription($c['task']) : ''),
                false,
                true
            ),
            'event_times' => self::placeholder(
                __('When the event starts and its doors close, like Event starts 19:00 · doors close 19:30. On a line of its own; the line goes when neither time is known.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal.
                // The task's event, else the event_id of a message about a night (free_ticket_dm).
                static fn (array $c): string => ($c['task'] ?? null) instanceof Task
                    ? self::eventTimes($c['task']->eventPostId)
                    : (is_int($c['event_id'] ?? null) ? self::eventTimes($c['event_id']) : ''),
                false,
                true
            ),
        ];
    }

    public static function find(string $id): ?MessageTemplate
    {
        return self::all()[$id] ?? null;
    }

    /**
     * The subject of $id for the language being composed in, with the
     * placeholders filled from $context. '' for an unknown kind or a Telegram
     * message.
     *
     * @param array<string, mixed> $context
     */
    public static function subject(string $id, array $context = []): string
    {
        return self::compose($id, self::SUBJECT, $context);
    }

    /**
     * The text of $id for the language being composed in, with the
     * placeholders filled from $context. '' for an unknown kind.
     *
     * @param array<string, mixed> $context
     */
    public static function body(string $id, array $context = []): string
    {
        return self::compose($id, self::BODY, $context);
    }

    /**
     * $part of $id before the placeholders are filled: the stored text in the
     * current language (its source when that language is empty, as
     * TranslatedText::text() does), or the built-in one when none is stored
     * or both are empty.
     *
     * @param array<string, mixed> $context
     */
    public static function raw(string $id, string $part, array $context = []): string
    {
        $template = self::find($id);

        if (null === $template || (self::SUBJECT === $part && ! $template->hasSubject())) {
            return '';
        }

        $stored = self::stored($id)[$part];

        if (null !== $stored) {
            $text = TranslatedText::text($stored, Language::current() ?? Language::ENGLISH);

            if ('' !== trim($text)) {
                return $text;
            }
        }

        return self::SUBJECT === $part ? $template->defaultSubject($context) : $template->defaultBody($context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function compose(string $id, string $part, array $context): string
    {
        $template = self::find($id);

        return null === $template ? '' : $template->fill(self::raw($id, $part, $context), $context);
    }

    /**
     * What is stored for $id: the subject and body values (null when not
     * changed) and the settings.
     *
     * @return array{subject: ?array, body: ?array, settings: array<string, mixed>}
     */
    public static function stored(string $id): array
    {
        $raw = get_option(self::OPTION_PREFIX . $id, []);
        $raw = is_array($raw) ? $raw : [];
        $part = static function (mixed $value): ?array {
            if (! is_array($value)) {
                return null;
            }

            $value = TranslatedText::normalize($value);

            return '' === $value['source'] . implode('', $value['texts']) ? null : $value;
        };

        return [
            self::SUBJECT => $part($raw[self::SUBJECT] ?? null),
            self::BODY => $part($raw[self::BODY] ?? null),
            'settings' => is_array($raw['settings'] ?? null) ? $raw['settings'] : [],
        ];
    }

    /** Whether the subject or the text of $id was changed from the built-in one. */
    public static function isChanged(string $id): bool
    {
        $stored = self::stored($id);

        return null !== $stored[self::SUBJECT] || null !== $stored[self::BODY];
    }

    /**
     * $part of $id as the Messages page edits it: the stored value, or the
     * built-in text as a value (English as the source, each site language's
     * own text, nothing edited by hand).
     *
     * @return array{source: string, lang: string, detected: bool, texts: array<string, string>, manual: array<string, bool>}
     */
    public static function value(string $id, string $part): array
    {
        return self::stored($id)[$part] ?? self::defaultValue($id, $part);
    }

    /**
     * The built-in text of $part of $id as a TranslatedText value: English
     * as the source, and each site language's own built-in text. A site
     * language the plugin has no translation file for gets '', so it shows
     * as "not translated yet" on the Translations page and is translated
     * there or on the next save.
     *
     * @return array{source: string, lang: string, detected: bool, texts: array<string, string>, manual: array<string, bool>}
     */
    public static function defaultValue(string $id, string $part): array
    {
        $value = TranslatedText::blank();
        $text = static function () use ($id, $part): string {
            $template = self::find($id);

            if (null === $template) {
                return '';
            }

            return self::SUBJECT === $part ? $template->defaultSubject() : $template->defaultBody();
        };

        $value['source'] = (string) Language::run(Language::ENGLISH, $text);
        $value['lang'] = Language::ENGLISH;

        foreach (TranslatedText::languages() as $lang) {
            $value['texts'][$lang] = SiteLanguages::hasCatalog($lang) ? (string) Language::run($lang, $text) : '';
        }

        return $value;
    }

    /**
     * Stores $value as $part of $id, keeping the other part and the
     * settings. A value that is the built-in text again, or empty, is not
     * stored, so the built-in text applies in every language.
     *
     * @param array<string, mixed> $value A TranslatedText value.
     */
    public static function storePart(string $id, string $part, array $value): void
    {
        if (null === self::find($id) || ! in_array($part, [self::SUBJECT, self::BODY], true)) {
            return;
        }

        $value = TranslatedText::normalize($value);
        $stored = self::stored($id);
        $stored[$part] = $value === self::defaultValue($id, $part)
            || '' === $value['source'] . implode('', $value['texts'])
            || self::onlyMachineCopyOfBuiltIn($id, $part, $value)
            ? null
            : $value;
        self::write($id, $stored);
    }

    /**
     * Whether $value is the built-in English with nothing written by hand,
     * in languages the plugin ships translations for. Storing it would only
     * freeze today's built-in text: a Translations page save once stored
     * every message so, and later built-in changes never showed (Owner
     * 2026-11-07).
     *
     * @param array{source: string, lang: string, detected: bool, texts: array<string, string>, manual: array<string, bool>} $value
     */
    private static function onlyMachineCopyOfBuiltIn(string $id, string $part, array $value): bool
    {
        if ([] !== array_filter($value['manual']) || $value['source'] !== self::defaultValue($id, $part)['source']) {
            return false;
        }

        foreach ($value['texts'] as $lang => $text) {
            if ('' !== $text && Language::ENGLISH !== $lang && ! SiteLanguages::hasCatalog((string) $lang)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The subject (emails) and the text of every kind as rows of the
     * wp-admin Translations page (TranslationRegistry), so they are
     * translated into every site language like the other texts.
     *
     * @return list<TranslationRow>
     */
    public static function translationRows(): array
    {
        $rows = [];

        foreach (self::all() as $id => $template) {
            $group = MessageTemplate::CHANNEL_EMAIL === $template->channel
                ? TranslationRow::GROUP_EMAILS
                : TranslationRow::GROUP_TELEGRAM;
            $parts = $template->hasSubject() ? [self::SUBJECT, self::BODY] : [self::BODY];

            foreach ($parts as $part) {
                $rows[] = new TranslationRow(
                    'message.' . $id . '.' . $part,
                    sprintf(
                        self::SUBJECT === $part
                            /* translators: %s: the name of a message on the Messages page */
                            ? __('%s: subject', 'eventcrew')
                            /* translators: %s: the name of a message on the Messages page */
                            : __('%s: text', 'eventcrew'),
                        $template->label
                    ),
                    $group,
                    self::value($id, $part),
                    static function (array $value) use ($id, $part): void {
                        self::storePart($id, $part, $value);
                    },
                    self::SUBJECT === $part ? self::SUBJECT_MAX_LENGTH : self::BODY_MAX_LENGTH,
                    self::BODY === $part,
                    required: self::BODY === $part ? $template->requiredPlaceholders() : []
                );
            }
        }

        return $rows;
    }

    /** The per-template settings of $id (the AI tick, the hybrid tick, ...). */
    public static function settings(string $id): array
    {
        return self::stored($id)['settings'];
    }

    /**
     * Saves the per-template settings of $id, keeping its texts.
     *
     * @param array<string, mixed> $settings
     */
    public static function saveSettings(string $id, array $settings): void
    {
        $stored = self::stored($id);
        $stored['settings'] = $settings;
        self::write($id, $stored);
    }

    /**
     * Saves the Messages page form of one template: the subject and the text,
     * translated where needed (TranslatedText::update()), then the settings
     * through SETTINGS_FILTER. A subject or text that is the built-in one
     * again, or empty, is not stored, so the built-in text applies in every
     * language. A part that was not posted is left as it is.
     *
     * A text that lacks a required placeholder, as written or as translated,
     * is refused: nothing is saved and ERROR_MISSING_PLACEHOLDER is answered.
     *
     * With a translator in the background (TranslatedText::inBackground()),
     * a part to translate keeps its stored text and its translation is
     * queued as the Translations page row 'message.{id}.{part}'; the job
     * stores it (TranslationRegistry::runJob(), which checks the
     * placeholders of the translation).
     *
     * @param mixed $posted The raw $_POST[POST_NAME].
     * @return string The translation error kind ('' when none), for TranslatedText::errorNotice(),
     *                or ERROR_MISSING_PLACEHOLDER.
     */
    public function save(string $id, mixed $posted): string
    {
        $template = self::find($id);

        if (null === $template) {
            return '';
        }

        $posted = is_array($posted) ? $posted : [];
        $stored = self::stored($id);
        $error = '';
        $parts = $template->hasSubject() ? [self::SUBJECT, self::BODY] : [self::BODY];
        $lacksRequired = static fn (array $texts): bool => [] !== array_filter(
            $texts,
            static fn (string $text): bool => [] !== $template->missingRequired($text)
        );
        $body = TranslatedText::fromPost($posted[self::BODY] ?? null, true);

        // Checked before translating, so a refused text costs no translation.
        if (null !== $body && $lacksRequired([$body['source'], ...array_values($body['texts'])])) {
            return self::ERROR_MISSING_PLACEHOLDER;
        }

        foreach ($parts as $part) {
            $multiline = self::BODY === $part;
            $input = TranslatedText::fromPost($posted[$part] ?? null, $multiline);

            if (null === $input) {
                continue;
            }

            $result = $this->translator->update(
                self::value($id, $part),
                $input['source'],
                $input['texts'],
                $input['again'],
                $multiline ? self::BODY_MAX_LENGTH : self::SUBJECT_MAX_LENGTH,
                $multiline,
                $input['lang'],
                [],
                'message.' . $id . '.' . $part
            );
            $value = $result['value'];

            // A translation can drop a placeholder too.
            if ($multiline && $lacksRequired([$value['source'], ...array_values($value['texts'])])) {
                return self::ERROR_MISSING_PLACEHOLDER;
            }

            $stored[$part] = $value === self::defaultValue($id, $part)
                || '' === $value['source'] . implode('', $value['texts'])
                ? null
                : $value;

            if ('' === $error && '' !== $result['error']) {
                $error = $result['error'];
            }
        }

        $settings = apply_filters(self::SETTINGS_FILTER, $stored['settings'], $id, $posted);
        $stored['settings'] = is_array($settings) ? $settings : $stored['settings'];

        self::write($id, $stored);

        return $error;
    }

    /** "Reset to built-in text": forgets the saved subject and text of $id, keeps its settings. */
    public static function reset(string $id): void
    {
        $stored = self::stored($id);
        $stored[self::SUBJECT] = null;
        $stored[self::BODY] = null;
        self::write($id, $stored);
    }

    /**
     * @param array{subject: ?array, body: ?array, settings: array<string, mixed>} $stored
     */
    private static function write(string $id, array $stored): void
    {
        $option = self::OPTION_PREFIX . $id;
        $keep = array_filter(
            $stored,
            static fn (mixed $value): bool => null !== $value && [] !== $value
        );

        if ([] === $keep) {
            delete_option($option);

            return;
        }

        update_option($option, $keep, false);
    }

    /**
     * The placeholders in a gettext string written for sprintf(): each
     * %1$s / %s / %2$d in $map becomes its {name}, and %% becomes %. The
     * built-in texts are made this way, so they keep their translations.
     *
     * @param array<string, string> $map
     */
    public static function tag(string $text, array $map): string
    {
        return strtr($text, $map + ['%%' => '%']);
    }

    /**
     * What the Preview link needs to know about each kind's sender, beyond its
     * text (MessageTemplate::$preview): the buttons and how the text is
     * wrapped. A kind not listed here is previewed with the defaults (an email
     * through Mailer::toPerson() with no buttons, or a DM framed without the
     * sign-off and with no buttons). Keep it in step with the sender when a
     * sender changes its buttons.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function previews(): array
    {
        $greet = ['greeting' => true];
        $none = ['frame' => MessageTemplate::FRAME_NONE];
        $signed = ['frame' => MessageTemplate::FRAME_SIGNED];
        $privacy = static fn (): array => '' === PrivacyPolicy::noticeUrl() ? [] : [PrivacyPolicy::noticeLinkLabel()];
        // The sender makes the one-time link; the preview shows a stand-in.
        $deskLink = ['context' => static fn (): array => ['desk_url' => Mailer::PREVIEW_LINK]];
        $admin = [DoorLinkSender::adminButtonLabel()];
        // A spot opened: one free place, and made-up other open tasks.
        $sampleOthers = ['context' => static fn (): array => ['free' => 1, 'sample_other_tasks' => true]];
        // A takeover request: the asker has done a few tasks.
        $sampleRecord = ['context' => static fn (): array => [
            'cover_record' => TakeoverService::coverRecord(__('Sample Helper', 'eventcrew'), 4),
        ]];

        // A role's conditions met: what the person has done.
        $sampleMet = ['context' => static fn (): array => [
            'record' => RoleNotifier::record(__('Sample Newcomer', 'eventcrew'), new Standing(Standing::GOOD, 0.82, 5, 0)),
        ]];

        // A newcomer who has worked here 5 times and thinks 3 tickets are owed.
        $sampleJoin = ['context' => static fn (): array => [
            'join_answers' => JoinAnswers::line(new Person(
                id: 0,
                email: '',
                displayName: '',
                roleNotices: [
                    JoinAnswers::KEY_BEFORE => JoinAnswers::YES,
                    JoinAnswers::KEY_TIMES => '5',
                    JoinAnswers::KEY_TICKETS => '3',
                ]
            )),
        ]];

        // Free entry given: the credits now and two nights to come.
        $sampleNights = ['context' => static fn (): array => ['balance' => 2, 'nights' => [
            gmdate('Y-m-d', time() + 7 * DAY_IN_SECONDS) => Dates::dayLabel(gmdate('Y-m-d', time() + 7 * DAY_IN_SECONDS)),
            gmdate('Y-m-d', time() + 14 * DAY_IN_SECONDS) => Dates::dayLabel(gmdate('Y-m-d', time() + 14 * DAY_IN_SECONDS)),
        ]]];

        // {open_tasks} as the welcome draws it, for the sample task.
        $welcomeOpenTasks = static fn (array $c): array => ['open_tasks' => WelcomeSender::openTasksBlock([
            ['task' => $c['task'], 'free' => max(1, $c['task']->capacity - 1)],
        ])];

        return [
            'credit_granted' => $sampleNights,
            'credit_granted_dm' => $sampleNights,
            'reminder_dm' => $signed,
            'slot_freed' => $sampleOthers,
            'slot_freed_dm' => $signed + $sampleOthers,
            'task_cancelled' => $sampleOthers,
            'task_cancelled_dm' => $none + $sampleOthers,
            'free_ticket_dm' => $none + ['buttons' => [__('🎟 Show your ticket', 'eventcrew')]],
            'signup_confirmed_dm' => $none,
            'cancel_confirmed' => $sampleOthers,
            'cancel_confirmed_dm' => $none + $sampleOthers,
            'open_task_call_dm' => $signed,
            'email_confirm' => [
                'buttons' => static fn (): array => [__('Confirm your email', 'eventcrew'), ...$privacy()],
            ],
            'ticket' => ['send' => MessageTemplate::PREVIEW_TICKET],
            'ticket_cancelled' => ['send' => MessageTemplate::PREVIEW_TICKET_CANCELLED],
            'followup_came' => ['send' => MessageTemplate::PREVIEW_FOLLOWUP_CAME],
            'followup_missed' => ['send' => MessageTemplate::PREVIEW_FOLLOWUP_MISSED],
            'newsletter' => ['send' => MessageTemplate::PREVIEW_NEWSLETTER],
            'sign_in_link' => $greet + ['buttons' => [__('Sign in', 'eventcrew')]],
            'account_create_link' => $greet + [
                'buttons' => static fn (): array => [__('Create my account', 'eventcrew'), ...$privacy()],
            ],
            'email_change_confirm' => $greet + [
                'send' => MessageTemplate::PREVIEW_ADDRESS,
                'buttons' => [__('Confirm new email', 'eventcrew')],
            ],
            'email_changed' => $greet,
            'delete_data_confirm' => $greet + ['buttons' => [__('Delete my data', 'eventcrew')]],
            'access_role' => $greet,
            'access_pass' => $greet,
            'newcomer_ok' => $greet,
            'newcomer_unknown' => $greet,
            'newcomer_timeout' => $greet,
            'newcomer_decided' => ['context' => static fn (): array => [
                'outcome' => NewcomerApproval::outcomeLine(
                    Person::APPROVAL_NO,
                    __('Sample Organizer', 'eventcrew'),
                    (string) current_time('mysql'),
                    NewcomerApproval::REASON_NONE
                ),
            ]],
            'access_organizer' => $greet,
            'role_met' => $sampleMet,
            'role_met_dm' => $sampleMet,
            'newcomer_waiting' => $sampleJoin + ['buttons' => [...$admin, __('Answer the request', 'eventcrew')]],
            'newcomer_waiting_dm' => $sampleJoin + ['buttons' => ['✅ ' . __('OK', 'eventcrew'), '❌ ' . __('No', 'eventcrew')]],
            'forged_request_dm' => $none,
            'import_tickets' => ['buttons' => [__('Open the Roster page', 'eventcrew'), __('Door list', 'eventcrew')]],
            'import_guests' => ['buttons' => [__('Open the Roster page', 'eventcrew')]],
            'import_tickets_dm' => ['buttons' => ['🗑 ' . __('Reset the list', 'eventcrew'), __('Open the Roster page', 'eventcrew')]],
            'import_guests_dm' => ['buttons' => [__('Open the Roster page', 'eventcrew')]],
            // What WelcomeSender draws: at most three buttons (wp-admin on top
            // for organizers), then the links (Owner 2026-11-07).
            'welcome_email' => [
                'buttons' => static fn (): array => [
                    '📋 ' . __('See open tasks', 'eventcrew'),
                    '💬 ' . __('Open our group in Telegram', 'eventcrew'),
                    '📖 ' . __('Help', 'eventcrew'),
                    __('See open tasks', 'eventcrew'),
                    RoleGuide::label(),
                ],
                'context' => $welcomeOpenTasks,
            ],
            'welcome_dm' => $signed + [
                'dm_format' => true,
                'buttons' => static fn (): array => [
                    __('👤 My info', 'eventcrew'),
                    '📋 ' . __('See open tasks', 'eventcrew'),
                    '💬 ' . __('Open the group', 'eventcrew'),
                    RoleGuide::label(),
                    '📖 ' . __('Help', 'eventcrew'),
                ],
                'context' => $welcomeOpenTasks,
            ],
            'welcome_org_email' => [
                'buttons' => static fn (): array => [
                    DoorLinkSender::adminButtonLabel(),
                    '📖 ' . __('Help', 'eventcrew'),
                    '💬 ' . __('Open our group in Telegram', 'eventcrew'),
                    '🧰 ' . __('Open EventCrew in wp-admin', 'eventcrew'),
                    '👥 ' . __('People', 'eventcrew'),
                    '💳 ' . __('Sales desk', 'eventcrew'),
                ],
                'context' => static fn (): array => ['guide' => OrganizerGuide::full(false)],
            ],
            'welcome_org_dm' => [
                'dm_format' => true,
                'buttons' => static fn (): array => [
                    '🛠 ' . __('Admin', 'eventcrew'),
                    DoorLinkSender::adminButtonLabel(),
                    '🧰 ' . __('Open EventCrew in wp-admin', 'eventcrew'),
                    '📖 ' . __('Help', 'eventcrew'),
                    '💳 ' . __('Sales desk', 'eventcrew'),
                ],
                'context' => static fn (): array => ['guide' => OrganizerGuide::dm()],
            ],
            'door_new_member' => $greet + [
                'buttons' => static fn (): array => [
                    __('Confirm my email', 'eventcrew'),
                    __('Join the Telegram group', 'eventcrew'),
                    ...$privacy(),
                ],
                'context' => static fn (): array => [
                    'group_line' => "\n\n" . __('The second button opens the crew’s Telegram group, where tasks are posted.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal.
                ],
            ],
            'sales_desk_link' => $greet + $deskLink,
            'sales_desk_link_dm' => $deskLink,
            'organizer_notice' => $greet + $deskLink,
            'organizer_notice_dm' => $deskLink,
            'takeover_ask' => ['buttons' => [__('Answer the request', 'eventcrew')]] + $sampleRecord,
            'takeover_ask_dm' => $none + $sampleRecord + [
                'buttons' => [__('✅ Let them take it', 'eventcrew'), __('❌ Keep my task', 'eventcrew')],
            ],
            'takeover_done_dm' => $none,
            'takeover_refused_dm' => $none,
            'takeover_expired_dm' => $none,
            'takeover_failed_dm' => $none,
            'takeover_closed_dm' => $none,
        ];
    }

    /**
     * The kinds. Each built-in text is the wording the message had before it
     * could be changed, made from the same gettext strings.
     *
     * Not every message the plugin sends is here yet: see the Messages page
     * list in the 2026-11-05 report. TicketMail and the newsletter are
     * listed but do not read their template yet (wired false).
     *
     * @return list<MessageTemplate>
     */
    private static function definitions(): array
    {
        $email = MessageTemplate::CHANNEL_EMAIL;
        $telegram = MessageTemplate::CHANNEL_TELEGRAM;

        $name = self::placeholder(
            __('The first name of the person the message is for.', 'eventcrew'),
            // The greetings say "Hi Sam," without the last name (Owner 2026-11-07).
            static fn (array $c): string => ($c['person'] ?? null) instanceof Person
                ? DoorDesk::firstName($c['person']->name())
                : ''
        );
        $personFirstName = self::placeholder(
            __('The first name of the person the message is for, without the last name.', 'eventcrew'),
            static fn (array $c): string => ($c['person'] ?? null) instanceof Person
                ? DoorDesk::firstName($c['person']->name())
                : ''
        );
        $role = self::placeholder(
            __('The name of the task, like Door.', 'eventcrew'),
            static fn (array $c): string => ($c['task'] ?? null) instanceof Task ? $c['task']->roleLabel() : ''
        );
        $event = self::placeholder(
            __('The name of the event.', 'eventcrew'),
            static fn (array $c): string => ($c['task'] ?? null) instanceof Task
                ? $c['task']->eventName()
                : (string) ($c['event'] ?? '')
        );
        $when = self::placeholder(
            __('The day of the task and its time, like Fri 14 Nov 18:00–20:00.', 'eventcrew'),
            static fn (array $c): string => ($c['task'] ?? null) instanceof Task ? self::when($c['task']) : ''
        );
        ['description' => $description, 'event_times' => $eventTimes] = self::taskPlaceholders();
        $standingTask = self::placeholder(
            __('The task, the event and the date, like Door at Dance Night, Sat 14 Nov 18:00–20:00.', 'eventcrew'),
            static fn (array $c): string => ($c['task'] ?? null) instanceof Task ? self::standingTask($c['task']) : ''
        );
        $credits = self::placeholder(
            __('How many free-entry credits were given.', 'eventcrew'),
            static fn (array $c): string => (string) max(1, (int) ($c['credits'] ?? 1))
        );
        $date = self::placeholder(
            __('The date of the event, like 14.11.2026.', 'eventcrew'),
            static fn (array $c): string => (string) ($c['date'] ?? '')
        );
        $firstName = self::placeholder(
            __('The first name of the ticket holder. Empty when it is not known.', 'eventcrew'),
            static fn (array $c): string => (string) ($c['first_name'] ?? '')
        );
        $count = static fn (array $c): int => max(1, (int) ($c['credits'] ?? 2));
        // The credits held now, and the next nights to spend one on.
        $balance = self::placeholder(
            __('How many free-entry credits the person has now, like: You now have 3 free-entry credits. On a line of its own; the line goes when it is not known.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal.
            static fn (array $c): string => isset($c['balance']) ? self::balanceLine((int) $c['balance']) : '',
            false,
            true
        );
        $nights = self::placeholder(
            __('An empty line and the next nights a free-entry credit can be spent on, one per line. On lines of their own; they go when there are none.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal.
            static fn (array $c): string => self::nightsBlock((array) ($c['nights'] ?? [])),
            false,
            true
        );

        // Q297 group A placeholders (the kinds are further down).
        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literals; splitting them breaks extraction.
        $claimPlaceholders = [
            'standing' => self::placeholder(
                __('The person\'s standing and free-entry credits, like: Your standing: Beginner · 0 free-entry credits.', 'eventcrew'),
                static fn (array $c): string => (string) ($c['standing'] ?? '')
            ),
            'free_cancel' => self::placeholder(
                __('A space and the sentence saying until how many hours before the task a cancellation does not affect the standing (Settings). Empty when that number is 0.', 'eventcrew'),
                static fn (array $c): string => (string) ($c['free_cancel'] ?? '')
            ),
            'about' => self::placeholder(
                __('An empty line and the "About the task" text of the task\'s role. Empty when the role has none.', 'eventcrew'),
                static fn (array $c): string => (string) ($c['about'] ?? '')
            ),
        ];
        $cancelNote = self::placeholder(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __('"Thanks for the notice." and why it has no effect on the standing (an early cancellation, more than the notice hours before the task), or for a late cancellation the sentences saying it counts against the standing and that Sign up again undoes it.', 'eventcrew'),
            static fn (array $c): string => (string) ($c['note'] ?? '')
        );
        // Only a late cancellation changes the standing; else the line goes.
        $cancelStanding = self::placeholder(
            __('After a late cancellation, the person\'s standing and free-entry credits, like: Your standing: Beginner · 0 free-entry credits. On a line of its own; the line goes when the cancellation was not late.', 'eventcrew'),
            static fn (array $c): string => (string) ($c['standing'] ?? ''),
            false,
            true
        );
        $openTasks = self::placeholder(
            __('The next open tasks, under a line with each date.', 'eventcrew'),
            static fn (array $c): string => (string) ($c['open_tasks'] ?? '')
        );
        // phpcs:enable Generic.Files.LineLength.TooLong

        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literals; splitting them breaks extraction.
        $spotPlaceholders = [
            'task_details' => self::placeholder(
                __('An empty line and the task\'s details: the task, the event, the day and time, the free places, and the description of the task or its role.', 'eventcrew'),
                static fn (array $c): string => ($c['task'] ?? null) instanceof Task
                    ? "\n\n" . SlotFreedNotice::details($c['task'], (int) ($c['free'] ?? 1))
                    : ''
            ),
            'other_tasks' => self::placeholder(
                __('An empty line and the other open tasks of the event, under a line with each date, with no names. Empty when there are none.', 'eventcrew'),
                static function (array $c): string {
                    $task = $c['task'] ?? null;

                    if (! $task instanceof Task) {
                        return '';
                    }

                    $rows = true === ($c['sample_other_tasks'] ?? false)
                        ? SlotFreedNotice::sampleOthers($task)
                        : (array) ($c['other_rows'] ?? []);
                    $text = SlotFreedNotice::others($task, array_values($rows));

                    return '' === $text ? '' : "\n\n" . $text;
                }
            ),
        ];
        // phpcs:enable Generic.Files.LineLength.TooLong

        $spot = static fn (): string => self::tag(
            /* translators: 1: role, 2: event, 3: date/time */
            __('A spot just opened: %1$s at %2$s, %3$s. Can you step in? Sign up on the board.', 'eventcrew'),
            ['%1$s' => '{role}', '%2$s' => '{event}', '%3$s' => '{when}']
        );

        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literals; splitting them breaks extraction.
        $cancelHelp = self::botOrWeb(
            __('How to cancel the task or find someone to take it over: in the bot, or, for a person with no Telegram chat, on the crew page, with its link.', 'eventcrew'),
            static fn (): string => __("Can't make it? Tap Cancel below. Or find someone to take it over: they send /me to the bot and tap 🔁 Take over a task, and you accept.", 'eventcrew'),
            static fn (string $url): string => sprintf(
                /* translators: %s: link to the crew page */
                __("Can't make it? Tap Cancel on your task in the Board tab of the crew page (%s).", 'eventcrew'),
                $url
            )
        );
        $lateHelp = self::botOrWeb(
            __('What helps next time: cancelling as soon as you know, or someone found in the bot to take the task over. For a person with no Telegram chat: cancelling early on the crew page, with its link.', 'eventcrew'),
            static fn (): string => __('Next time, tap Cancel as soon as you know, or find someone to take it over: they send /me to the bot and tap 🔁 Take over a task.', 'eventcrew'),
            static fn (string $url): string => sprintf(
                /* translators: %s: link to the crew page */
                __('Next time, tap Cancel on your task in the Board tab of the crew page (%s) as soon as you know.', 'eventcrew'),
                $url
            )
        );
        // The weights from Settings, as the score uses them (ReputationSettings).
        $lateScore = self::placeholder(
            __('The sentence saying what a late cancellation counts in the score next to a completed task, with the weights from Settings (40 and 100 percent by default).', 'eventcrew'),
            static function (): string {
                $percents = (new ReputationSettings())->weightPercents();

                return sprintf(
                    /* translators: 1: score percent of a late cancellation, 2: score percent of a completed task */
                    __('A late cancellation counts %1$d%% in your score, a completed task %2$d%%.', 'eventcrew'),
                    $percents[AssignmentStatus::LATE_CANCEL] ?? 0,
                    $percents[AssignmentStatus::COMPLETED] ?? 100
                );
            }
        );

        // The standing before and after a mark, as StandingNotice puts them in
        // the context; a bar and a sentence on what it means (Owner 2026-11-07).
        $after = static fn (array $c): ?Standing => ($c['standing_after'] ?? null) instanceof Standing
            ? $c['standing_after']
            : null;
        $standingBar = self::placeholder(
            __('The standing as a bar from 0 to 100 percent, with the good-standing limit marked; in the email a coloured bar. On a line of its own; the line goes while the person has no score yet.', 'eventcrew'),
            static fn (array $c): string => StandingBar::forStanding($after($c)),
            false,
            true
        );
        $standingChange = self::placeholder(
            __('The standing before and after, like: Standing 82 → 74 percent, with one sentence on what it means. Before the person has a score: how many tasks until they get one.', 'eventcrew'),
            static fn (array $c): string => StandingBar::change(
                ($c['standing_before'] ?? null) instanceof Standing ? $c['standing_before'] : null,
                $after($c)
            ),
            false,
            true
        );
        $creditProgress = self::placeholder(
            __('The free-entry credits the person has now, and how many more completed tasks earn the next one.', 'eventcrew'),
            static function (array $c) use ($after): string {
                $standing = $after($c);

                if (null === $standing) {
                    return '';
                }

                $perCredit = max(1, (new ReputationSettings())->tasksPerCredit());
                $left = $perCredit - $standing->completedCount % $perCredit;

                return self::balanceLine($standing->creditBalance) . ' ' . sprintf(
                    /* translators: %d: completed tasks still needed for the next free-entry credit */
                    _n(
                        '%d more completed task earns the next one.',
                        '%d more completed tasks earn the next one.',
                        $left,
                        'eventcrew'
                    ),
                    $left
                );
            },
            false,
            true
        );

        return [
            new MessageTemplate(
                'reminder',
                __('Task reminder (email)', 'eventcrew'),
                $email,
                __('Sent the day before a task to each person signed up for it.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: 1: role, 2: event */
                    __('Reminder: %1$s for %2$s', 'eventcrew'),
                    ['%1$s' => '{role}', '%2$s' => '{event}']
                ),
                static fn (): string => EventDetailsText::withVenue(EventDetailsText::withEventDescription(self::withDescription(self::tag(
                    /* translators: 1: name, 2: role, 3: event, 4: date/time, 5: how to cancel */
                    __("Hi %1\$s,\n\nJust a reminder that you're on %2\$s at %3\$s, %4\$s.\n\n%5\$s", 'eventcrew'),
                    ['%1$s' => '{name}', '%2$s' => '{role}', '%3$s' => '{event}', '%4$s' => '{when}', '%5$s' => '{cancel_help}']
                )))),
                [
                    'name' => $name,
                    'role' => $role,
                    'event' => $event,
                    'when' => $when,
                    'description' => $description,
                    'cancel_help' => $cancelHelp,
                ]
            ),
            new MessageTemplate(
                'reminder_dm',
                __('Task reminder (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent the day before a task to each person signed up for it who uses the bot.', 'eventcrew'),
                null,
                static fn (): string => EventDetailsText::withVenue(EventDetailsText::withEventDescription(self::withDescription(self::tag(
                    /* translators: 1: role, 2: event, 3: date/time */
                    __('⏰ Reminder: you’re on %1$s at %2$s, %3$s. See you there!', 'eventcrew'),
                    ['%1$s' => '{role}', '%2$s' => '{event}', '%3$s' => '{when}']
                )))),
                ['role' => $role, 'event' => $event, 'when' => $when, 'description' => $description]
            ),
            new MessageTemplate(
                'slot_freed',
                __('A spot opened (email)', 'eventcrew'),
                $email,
                __('Sent to the crew when someone cancels a task late and its place is free again.', 'eventcrew'),
                static fn (): string => __('A spot just opened up', 'eventcrew'),
                static fn (): string => EventDetailsText::withVenue(EventDetailsText::withEventDescription(self::tag(
                    /* translators: 1: name, 2: the opening line */
                    __("Hi %1\$s,\n\n%2\$s", 'eventcrew'),
                    ['%1$s' => '{name}', '%2$s' => $spot()]
                ) . SlotFreedNotice::NEW_PLACEHOLDERS)),
                ['name' => $name, 'role' => $role, 'event' => $event, 'when' => $when] + $spotPlaceholders
            ),
            new MessageTemplate(
                'slot_freed_dm',
                __('A spot opened (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to the crew when someone cancels a task late and its place is free again.', 'eventcrew'),
                null,
                static fn (): string => EventDetailsText::withVenue(EventDetailsText::withEventDescription('⚡ ' . $spot() . SlotFreedNotice::NEW_PLACEHOLDERS)),
                ['role' => $role, 'event' => $event, 'when' => $when] + $spotPlaceholders
            ),
            new MessageTemplate(
                'task_cancelled',
                __('Task cancelled by the organizers (email)', 'eventcrew'),
                $email,
                __('Sent to each person signed up for a task when an organizer deletes the task. Sent in every site language, or in one language when its Hybrid tick in Settings > Email is off.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: 1: role, 2: event */
                    __('Task cancelled: %1$s for %2$s', 'eventcrew'),
                    ['%1$s' => '{role}', '%2$s' => '{event}']
                ),
                static fn (): string => self::tag(
                    /* translators: 1: name, 2: role, 3: event, 4: date/time */
                    __("Hi %1\$s,\n\nThe organizers have cancelled the task %2\$s at %3\$s, %4\$s. You are no longer signed up for it, and its ticket no longer works at the door. This does not count against your standing.", 'eventcrew'),
                    ['%1$s' => '{name}', '%2$s' => '{role}', '%3$s' => '{event}', '%4$s' => '{when}']
                ) . '{other_tasks}',
                [
                    'name' => $name,
                    'role' => $role,
                    'event' => $event,
                    'when' => $when,
                    'other_tasks' => $spotPlaceholders['other_tasks'],
                ]
            ),
            new MessageTemplate(
                'task_cancelled_dm',
                __('Task cancelled by the organizers (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to each person signed up for a task when an organizer deletes the task, in their language.', 'eventcrew'),
                null,
                static fn (): string => self::tag(
                    /* translators: 1: role, 2: event, 3: date/time */
                    __('🚫 The organizers have cancelled the task %1$s at %2$s, %3$s. You are no longer signed up for it, and its ticket no longer works at the door. This does not count against your standing.', 'eventcrew'),
                    ['%1$s' => '{role}', '%2$s' => '{event}', '%3$s' => '{when}']
                ) . '{other_tasks}',
                ['role' => $role, 'event' => $event, 'when' => $when, 'other_tasks' => $spotPlaceholders['other_tasks']]
            ),
            new MessageTemplate(
                'no_show',
                __('Marked as a no-show (email)', 'eventcrew'),
                $email,
                __('Sent a day after an organizer marks a person as a no-show.', 'eventcrew'),
                static fn (): string => __('We missed you at your task', 'eventcrew'),
                // Warm, short and useful: why it matters, what helps next
                // time, and an invitation back (Owner 2026-11-07).
                static fn (): string => "Hi {name},\n\n" . self::tag(
                    /* translators: 2: role at event, date, 3: what helps next time, 4: a space and until when a cancellation has no effect (may be empty) */
                    __("We missed you at %2\$s, so it was marked as a no-show. Unexpected things happen, and that's OK.\n\nThe night runs on its crew: a missed task falls on the others or leaves a gap. %3\$s%4\$s\n\nA no-show counts against your standing, but every task you complete lifts it again, and it weighs less over time. Conditions may be better next time, so we hope you sign up again. If this is a mistake, tell an organizer and they will correct it.", 'eventcrew'),
                    ['%2$s' => '{task}', '%3$s' => '{late_help}', '%4$s' => '{free_cancel}']
                ) . "\n\n{standing_bar}\n\n{standing_change}",
                [
                    'name' => $name,
                    'task' => $standingTask,
                    'late_help' => $lateHelp,
                    'free_cancel' => $claimPlaceholders['free_cancel'],
                    'standing' => $claimPlaceholders['standing'],
                    'standing_bar' => $standingBar,
                    'standing_change' => $standingChange,
                ]
            ),
            new MessageTemplate(
                'no_show_dm',
                __('Marked as a no-show (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent a day after an organizer marks a person as a no-show.', 'eventcrew'),
                null,
                static fn (): string => self::tag(
                    /* translators: 1: role at event, date, 2: what helps next time, 3: a space and until when a cancellation has no effect (may be empty) */
                    __("🤍 We missed you at %1\$s. Unexpected things happen, and that's OK.\n\nThe night runs on its crew: a missed task falls on others or leaves a gap. %2\$s%3\$s\n\nA no-show counts against your standing; every task you complete lifts it again. Next time may suit you better, so sign up again! If this is a mistake, tell an organizer.", 'eventcrew'),
                    ['%1$s' => '{task}', '%2$s' => '{late_help}', '%3$s' => '{free_cancel}']
                ) . "\n\n{standing_bar}\n\n{standing_change}",
                [
                    'task' => $standingTask,
                    'late_help' => $lateHelp,
                    'free_cancel' => $claimPlaceholders['free_cancel'],
                    'standing' => $claimPlaceholders['standing'],
                    'standing_bar' => $standingBar,
                    'standing_change' => $standingChange,
                ]
            ),
            new MessageTemplate(
                'late_cancel',
                __('Late cancellation recorded (email)', 'eventcrew'),
                $email,
                __('Sent a day after a late cancellation is recorded.', 'eventcrew'),
                static fn (): string => __('Thanks for letting us know', 'eventcrew'),
                static fn (): string => "Hi {name},\n\n" . self::tag(
                    /* translators: 2: role at event, date, 3: what helps next time, 4: a space and until when a cancellation has no effect (may be empty), 5: what a late cancellation counts in the score */
                    __("Thanks for letting the crew know you couldn't make it to %2\$s. Telling us is always better than not showing up.\n\nIt was a late cancellation, and early notice matters: it gives someone else time to take the place. %3\$s%4\$s\n\n%5\$s It weighs less over time. Plans change sometimes; we hope you sign up again when it suits you. If this is a mistake, tell an organizer.", 'eventcrew'),
                    ['%2$s' => '{task}', '%3$s' => '{late_help}', '%4$s' => '{free_cancel}', '%5$s' => '{late_score}']
                ) . "\n\n{standing_bar}\n\n{standing_change}",
                [
                    'name' => $name,
                    'task' => $standingTask,
                    'late_help' => $lateHelp,
                    'free_cancel' => $claimPlaceholders['free_cancel'],
                    'late_score' => $lateScore,
                    'standing' => $claimPlaceholders['standing'],
                    'standing_bar' => $standingBar,
                    'standing_change' => $standingChange,
                ]
            ),
            new MessageTemplate(
                'late_cancel_dm',
                __('Late cancellation recorded (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent a day after a late cancellation is recorded.', 'eventcrew'),
                null,
                static fn (): string => self::tag(
                    /* translators: 1: role at event, date, 2: what helps next time, 3: a space and until when a cancellation has no effect (may be empty), 4: what a late cancellation counts in the score */
                    __("🤍 Thanks for letting us know about %1\$s. Telling the crew is always better than not showing up.\n\nIt was a late cancellation: early notice gives someone else time to take the place. %2\$s%3\$s\n\n%4\$s It weighs less over time. Next time may suit you better, so sign up again! If this is a mistake, tell an organizer.", 'eventcrew'),
                    ['%1$s' => '{task}', '%2$s' => '{late_help}', '%3$s' => '{free_cancel}', '%4$s' => '{late_score}']
                ) . "\n\n{standing_bar}\n\n{standing_change}",
                [
                    'task' => $standingTask,
                    'late_help' => $lateHelp,
                    'free_cancel' => $claimPlaceholders['free_cancel'],
                    'late_score' => $lateScore,
                    'standing' => $claimPlaceholders['standing'],
                    'standing_bar' => $standingBar,
                    'standing_change' => $standingChange,
                ]
            ),
            new MessageTemplate(
                'task_completed',
                __('Thank you for a task (email)', 'eventcrew'),
                $email,
                __('Sent a day after an organizer marks a person\'s task as completed or arrived late.', 'eventcrew'),
                static fn (): string => __('Thank you for your task', 'eventcrew'),
                // The thank-you, the credits and the standing bar; sent like
                // the no-show notice, a day later (StandingNotice).
                static fn (): string => "Hi {name},\n\n" . self::tag(
                    /* translators: %1$s: role at event, date */
                    __('Thank you for %1$s! The night runs on its crew, and you helped make it happen.', 'eventcrew'),
                    ['%1$s' => '{task}']
                ) . "\n\n{credit_progress}\n\n{standing_bar}\n\n{standing_change}\n\n"
                    . __('We hope to see you again soon.', 'eventcrew'),
                [
                    'name' => $name,
                    'task' => $standingTask,
                    'credit_progress' => $creditProgress,
                    'standing_bar' => $standingBar,
                    'standing_change' => $standingChange,
                    'standing' => $claimPlaceholders['standing'],
                ]
            ),
            new MessageTemplate(
                'task_completed_dm',
                __('Thank you for a task (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent a day after an organizer marks a person\'s task as completed or arrived late.', 'eventcrew'),
                null,
                static fn (): string => '💛 ' . self::tag(
                    /* translators: %1$s: role at event, date */
                    __('Thank you for %1$s! The night runs on its crew, and you helped make it happen.', 'eventcrew'),
                    ['%1$s' => '{task}']
                ) . "\n\n{credit_progress}\n\n{standing_bar}\n\n{standing_change}\n\n"
                    . __('See you next time!', 'eventcrew'),
                [
                    'task' => $standingTask,
                    'credit_progress' => $creditProgress,
                    'standing_bar' => $standingBar,
                    'standing_change' => $standingChange,
                    'standing' => $claimPlaceholders['standing'],
                ]
            ),
            new MessageTemplate(
                'credit_granted',
                __('Free entry given (email)', 'eventcrew'),
                $email,
                __('Sent when an organizer gives a person free-entry credits.', 'eventcrew'),
                static fn (): string => __('You’ve been given free entry', 'eventcrew'),
                static fn (array $c): string => self::tag(
                    /* translators: 1: the person's name, 2: number of credits, 3: how to get the free ticket */
                    _n(
                        "Hi %1\$s,\n\nAn organizer has given you a free-entry credit. You can spend it on any upcoming event — %3\$s",
                        "Hi %1\$s,\n\nAn organizer has given you %2\$d free-entry credits. You can spend them on upcoming events — %3\$s",
                        $count($c),
                        'eventcrew'
                    ),
                    ['%1$s' => '{name}', '%2$d' => '{credits}', '%3$s' => '{credit_help}']
                ) . "\n{balance}\n{nights}",
                [
                    'name' => $name,
                    'credits' => $credits,
                    'credit_help' => self::botOrWeb(
                        __('How to get the free ticket: in the bot or the Crew app, or, for a person with no Telegram chat, on the crew page, with its link.', 'eventcrew'),
                        static fn (): string => __('tap Get your free ticket below.', 'eventcrew'),
                        static fn (string $url): string => sprintf(
                            /* translators: %s: link to the crew page */
                            __('open the Me tab of the crew page (%s) and pick a night under Tickets to get your free ticket.', 'eventcrew'),
                            $url
                        )
                    ),
                    'balance' => $balance,
                    'nights' => $nights,
                ]
            ),
            new MessageTemplate(
                'credit_granted_dm',
                __('Free entry given (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent when an organizer gives a person free-entry credits.', 'eventcrew'),
                null,
                static fn (array $c): string => self::tag(
                    /* translators: %d: number of free-entry credits granted */
                    _n(
                        '🎁 You’ve been given %d free-entry credit. Tap 🎟 Free-entry ticket below to spend it on an upcoming event.',
                        '🎁 You’ve been given %d free-entry credits. Tap 🎟 Free-entry ticket below to spend them on upcoming events.',
                        $count($c),
                        'eventcrew'
                    ),
                    ['%d' => '{credits}']
                ) . "\n{balance}",
                ['credits' => $credits, 'balance' => $balance]
            ),
            new MessageTemplate(
                'free_ticket_dm',
                __('Free-entry ticket (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent when a person spends a free-entry credit on a night. The ticket itself comes in the ticket email.', 'eventcrew'),
                null,
                // A title, then the event (with its DJs), the day, the venue
                // and the times, each on a line of its own (Owner, Q344
                // 2026-11-07; stored texts: FrictionB3Migration). Then a
                // thank-you to the crew member and a welcome to the night
                // (Owner 2026-11-07; stored copies: FrictionB3Migration).
                static fn (): string => '✨ ' . __('Your free-entry ticket', 'eventcrew') . " ✨\n\n"
                    . "{event}\n{date}\n{venue}\n{event_times}\n\n💛 "
                    . __('Thank you for being part of the crew and helping make these nights happen.', 'eventcrew')
                    . "\n💫 "
                    . __('Show it at the door, then come dance and enjoy the night as our guest.', 'eventcrew'),
                [
                    'date' => self::placeholder(
                        __('The day of the event, like Fri 14 Nov.', 'eventcrew'),
                        static fn (array $c): string => (string) ($c['date'] ?? '')
                    ),
                    'venue' => self::venuePlaceholder(
                        static fn (array $c): ?int => is_int($c['event_id'] ?? null) ? $c['event_id'] : null
                    ),
                    'event' => self::placeholder(
                        __('The name of the event and its DJs, like Ecstatic Dance · Esimerkki. On a line of its own; the line goes when it is not known.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal.
                        static fn (array $c): string => self::eventWithDjs(
                            (string) ($c['event'] ?? ''),
                            is_int($c['event_id'] ?? null) ? $c['event_id'] : null
                        ),
                        false,
                        true
                    ),
                    'event_times' => $eventTimes,
                ]
            ),
            // Q297 group A: the sign-up and cancel confirmations
            // (ClaimNotifier), the open-task call (OpenTaskCall) and the
            // email confirmation (OnboardingService).
            new MessageTemplate(
                'signup_confirmed',
                __('Signed up for a task (email)', 'eventcrew'),
                $email,
                __('Sent when a person signs up for a task, in the bot or on the web page. The ticket, calendar and crew guide buttons are added below the text.', 'eventcrew'),
                // A thank-you (Owner, 2026-11-07); the HTML email opens with a
                // "Thank you" hero above the text (EmailHero).
                static fn (): string => self::tag(
                    /* translators: 1: first name, 2: event. No comma after "Thank you" (Owner, 2026-11-07). */
                    __('Thank you %1$s! ✨ You’re on the crew for %2$s', 'eventcrew'),
                    ['%1$s' => '{first_name}', '%2$s' => '{event}']
                ),
                // The when line, the event's times and venue, then the task's
                // description ({description} takes the place of {about}). No
                // event description: the email is about the task (Owner,
                // 2026-11-07); the DM keeps it.
                static fn (): string => EventDetailsText::withVenue(FrictionB1Migration::withEventTimes(self::withDescription(self::tag(
                    /* translators: 1: name, 2: role, 3: event, 4: date/time, 5: standing line, 6: how to cancel */
                    __("Hi %1\$s,\n\nThank you for joining the crew! You’re on %2\$s at %3\$s, %4\$s.\n\nShow your ticket at the door. We can’t wait to see you there ✨\n\n%5\$s\n\n%6\$s", 'eventcrew'),
                    ['%1$s' => '{name}', '%2$s' => '{role}', '%3$s' => '{event}', '%4$s' => '{when}', '%5$s' => '{standing}', '%6$s' => '{cancel_help}']
                ) . '{free_cancel}'))),
                [
                    'name' => $name,
                    'first_name' => $personFirstName,
                    'role' => $role,
                    'event' => $event,
                    'when' => $when,
                    'event_times' => $eventTimes,
                    'description' => $description,
                    'cancel_help' => $cancelHelp,
                ] + $claimPlaceholders
            ),
            new MessageTemplate(
                'signup_confirmed_dm',
                __('Signed up for a task (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent when a person signs up for a task, in the bot or on the web page. The ticket, calendar and crew guide buttons are added below the text.', 'eventcrew'),
                null,
                // The Cancel button asks first and offers the hand-over, so the
                // text no longer explains them.
                static fn (): string => EventDetailsText::withVenue(FrictionB1Migration::withEventTimes(self::withDescription(self::tag(
                    /* translators: 1: role, 2: event, 3: date/time */
                    __("✅ Signed up: %1\$s at %2\$s, %3\$s.\n\nShow your ticket at the door - it is the first button below.", 'eventcrew'),
                    ['%1$s' => '{role}', '%2$s' => '{event}', '%3$s' => '{when}']
                ) . "{free_cancel}\n\n{standing}"))),
                [
                    'role' => $role,
                    'event' => $event,
                    'when' => $when,
                    'event_times' => $eventTimes,
                    'description' => $description,
                ] + $claimPlaceholders
            ),
            new MessageTemplate(
                'cancel_confirmed',
                __('Cancelled a task (email)', 'eventcrew'),
                $email,
                __('Sent when a person cancels their own task, in the bot or on the web page.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: 1: role, 2: event */
                    __('Cancelled: %1$s for %2$s', 'eventcrew'),
                    ['%1$s' => '{role}', '%2$s' => '{event}']
                ),
                static fn (): string => FrictionB1Migration::withOtherTasks(self::tag(
                    /* translators: 1: name, 2: role, 3: event, 4: standing note, 5: standing line */
                    __("Hi %1\$s,\n\nYou've cancelled %2\$s at %3\$s, and your ticket is now disabled.\n\n%4\$s\n\n%5\$s", 'eventcrew'),
                    ['%1$s' => '{name}', '%2$s' => '{role}', '%3$s' => '{event}', '%4$s' => '{note}', '%5$s' => '{standing}']
                )),
                [
                    'name' => $name,
                    'role' => $role,
                    'event' => $event,
                    'note' => $cancelNote,
                    'other_tasks' => $spotPlaceholders['other_tasks'],
                    'standing' => $cancelStanding,
                ]
            ),
            new MessageTemplate(
                'cancel_confirmed_dm',
                __('Cancelled a task (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent when a person cancels their own task, in the bot or on the web page.', 'eventcrew'),
                null,
                static fn (): string => FrictionB1Migration::withOtherTasks(self::tag(
                    /* translators: 1: cancellation kind, 2: role, 3: event, 4: standing note */
                    __('%1$s: %2$s at %3$s. %4$s', 'eventcrew'),
                    ['%1$s' => '{cancelled}', '%2$s' => '{role}', '%3$s' => '{event}', '%4$s' => '{note}']
                ) . "\n\n{standing}"),
                [
                    'cancelled' => self::placeholder(
                        __('"❌ Cancelled", or "⚠️ Late cancellation" when the cancellation was late.', 'eventcrew'),
                        static fn (array $c): string => (string) ($c['cancelled'] ?? '')
                    ),
                    'role' => $role,
                    'event' => $event,
                    'note' => $cancelNote,
                    'other_tasks' => $spotPlaceholders['other_tasks'],
                    'standing' => $cancelStanding,
                ]
            ),
            new MessageTemplate(
                'open_task_call',
                __('Tasks still need people (email)', 'eventcrew'),
                $email,
                __('Sent to the crew before an event that still has open tasks (the times are in Settings), or when an organizer sends it by hand.', 'eventcrew'),
                static fn (): string => __('Some tasks still need people', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: 1: name, 2: open-task list grouped by date, 3: personal recap */
                    __("Hi %1\$s,\n\nSome tasks still need people:\n\n%2\$s\n\n%3\$s", 'eventcrew'),
                    ['%1$s' => '{name}', '%2$s' => '{open_tasks}', '%3$s' => '{recap}']
                ),
                ['name' => $name, 'open_tasks' => $openTasks, 'recap' => self::placeholder(
                    __('The person\'s last three tasks and how many tasks they have completed in total.', 'eventcrew'),
                    static fn (array $c): string => (string) ($c['recap'] ?? '')
                )]
            ),
            new MessageTemplate(
                'open_task_call_dm',
                __('Tasks still need people (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to the crew before an event that still has open tasks (the times are in Settings), or when an organizer sends it by hand.', 'eventcrew'),
                null,
                static fn (): string => self::tag(
                    /* translators: %s: the open-task list, already grouped by date */
                    __("🔔 Some tasks still need people:\n\n%s", 'eventcrew'),
                    ['%s' => '{open_tasks}']
                ),
                ['open_tasks' => $openTasks]
            ),
            new MessageTemplate(
                'email_confirm',
                __('Confirm your email (email)', 'eventcrew'),
                $email,
                __('Sent when a person types their email in the Telegram bot. The confirmation link is a button below the text and is always added.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: %s: the organization's name */
                    __('Confirm your email for %s', 'eventcrew'),
                    ['%s' => Organization::TAG]
                ),
                static fn (): string => self::tag(
                    /* translators: 1: person's name, 2: the organization's name */
                    __("Hi %1\$s,\n\nConfirm your email to finish signing up for %2\$s tasks.\n\nThe link works once and expires in a day. If you didn't ask for this, you can ignore this email.", 'eventcrew'),
                    ['%1$s' => '{name}', '%2$s' => Organization::TAG]
                ) . "\n\n" . PrivacyPolicy::noticeLine(),
                [
                    'name' => self::placeholder(
                        __('The name the person has in Telegram, or their email address when there is none.', 'eventcrew'),
                        static fn (array $c): string => (string) ($c['name'] ?? '')
                    ),
                ]
            ),
            // End of Q297 group A.
            ...self::welcomeAndDoorKinds($name),
            ...self::takeoverTemplates($name, $role, $event, $when, $description),
            // TODO(messages): TicketMail does not read this template yet. It is
            // being rewritten (2026-11-05); its compose()/words() should call
            // MessageTemplates::subject('ticket', $context) and body() with
            // first_name, thanks, event and date in $context.
            new MessageTemplate(
                'ticket',
                __('Ticket (email)', 'eventcrew'),
                $email,
                __('The ticket with its QR code, to ticket buyers, guests and crew. Sent in every site language, or in one language when its Hybrid tick in Settings > Email is off.', 'eventcrew'),
                static fn (): string => __('Your ticket', 'eventcrew'),
                /* translators: %s: the ticket holder's first name */
                static fn (): string => self::tag(__('Hi %s,', 'eventcrew'), ['%s' => '{first_name}']) . "\n\n{thanks}\n\n"
                    . self::tag(
                        /* translators: 1: the event's name, 2: its date, e.g. 14.11.2026 */
                        __('Here is your ticket to %1$s on %2$s. Show the QR code below at the door.', 'eventcrew'),
                        ['%1$s' => '{event}', '%2$s' => '{date}']
                    ),
                [
                    'first_name' => $firstName,
                    'thanks' => self::placeholder(
                        __('The thank-you line: for buying a ticket, for coming as a guest, for taking a task, or for spending a credit.', 'eventcrew'),
                        static fn (array $c): string => (string) ($c['thanks'] ?? '')
                    ),
                    'event' => $event,
                    'date' => $date,
                ],
                false
            ),
            // TODO(messages): TicketMail::composeCancelled() does not read this
            // template yet (see 'ticket').
            new MessageTemplate(
                'ticket_cancelled',
                __('Ticket cancelled (email)', 'eventcrew'),
                $email,
                __('Sent when a ticket that was emailed is cancelled. Sent in every site language, or in one language when its Hybrid tick in Settings > Email is off.', 'eventcrew'),
                static fn (): string => __('Your ticket is cancelled', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: 1: the event's name, 2: its date, e.g. 14.11.2026 */
                    __('Your ticket to %1$s on %2$s is cancelled. The QR code in the ticket email no longer works at the door.', 'eventcrew'),
                    ['%1$s' => '{event}', '%2$s' => '{date}']
                ),
                ['event' => $event, 'date' => $date],
                false
            ),
            // FollowupMail reads these two. A one-time text for the next
            // post-event email (FollowupText) still comes first.
            new MessageTemplate(
                'followup_came',
                __('After the event: thank you (email)', 'eventcrew'),
                $email,
                __('Sent the day after an event to the people who came. Sent in every site language, or in one language when its Hybrid tick in Settings > Email is off.', 'eventcrew'),
                static fn (): string => __('Thank you for dancing with us', 'eventcrew'),
                static fn (): string => self::followupBody(FollowupText::defaultText(FollowupText::CAME)),
                ['first_name' => $firstName, 'event' => $event, 'date' => $date]
            ),
            new MessageTemplate(
                'followup_missed',
                __('After the event: we missed you (email)', 'eventcrew'),
                $email,
                __('Sent the day after an event to the people who had a ticket but did not come. Sent in every site language, or in one language when its Hybrid tick in Settings > Email is off.', 'eventcrew'),
                static fn (): string => __('We missed you this time', 'eventcrew'),
                static fn (): string => self::followupBody(FollowupText::defaultText(FollowupText::MISSED)),
                ['first_name' => $firstName, 'event' => $event, 'date' => $date]
            ),
            // TODO(messages): the newsletter (Newsletter::compose() and
            // NewsletterWriter) does not read this template yet. It has its
            // own writer and its own one-time text (NewsletterText).
            new MessageTemplate(
                'newsletter',
                __('Next events announcement (email)', 'eventcrew'),
                $email,
                __('The announcement of the coming events, to the subscribers.', 'eventcrew'),
                static fn (): string => __('Coming events', 'eventcrew'),
                static fn (): string => '{events}',
                [
                    'events' => self::placeholder(
                        __('The coming events, as the newsletter writes them.', 'eventcrew'),
                        static fn (array $c): string => (string) ($c['events'] ?? '')
                    ),
                ],
                false
            ),
            // The account emails. The link in each of these is a
            // button that Mailer adds under the text, not a placeholder, so no
            // saved text can remove it.
            new MessageTemplate(
                'sign_in_link',
                __('Sign-in link (email)', 'eventcrew'),
                $email,
                __('Sent when a person with an account asks for a sign-in link on the crew page. The Sign in button with the link is added under the text.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: %s: the organization's name */
                    __('Your %s sign-in link', 'eventcrew'),
                    ['%s' => Organization::TAG]
                ),
                static fn (): string => __(
                    "Use the button below to sign in and manage your tasks.\n\nThe link is good for 30 minutes, and works once.",
                    'eventcrew'
                )
            ),
            new MessageTemplate(
                'account_create_link',
                __('New account link (email)', 'eventcrew'),
                $email,
                __('Sent when someone without an account asks for a sign-in link on the crew page. The Create my account button with the link is added under the text, and for a new address the privacy line too.', 'eventcrew'),
                static fn (): string => __('Create your crew account', 'eventcrew'),
                static fn (): string => __(
                    "There is no crew account for this email address yet. The button below creates your account and signs you in.\n\nThe link is good for 30 minutes, and works once. If you did not ask for this, ignore this email.",
                    'eventcrew'
                )
            ),
            new MessageTemplate(
                'email_change_confirm',
                __('Confirm a new email address (email)', 'eventcrew'),
                $email,
                __('Sent to the new address when a person changes their email address on the crew page. The Confirm new email button with the link is added under the text.', 'eventcrew'),
                static fn (): string => __('Confirm your new email address', 'eventcrew'),
                static fn (): string => __(
                    "Use the button below to make this the email address of your crew account.\n\nThe link is good for 24 hours, and works once. Until you use it, your account keeps its old address. If you did not ask for this, ignore this email.",
                    'eventcrew'
                )
            ),
            new MessageTemplate(
                'email_changed',
                __('Email address changed (email)', 'eventcrew'),
                $email,
                __('Sent to the old address when a person has confirmed their new email address.', 'eventcrew'),
                static fn (): string => __('Your crew account email was changed', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: %s: the new email address */
                    __("The email address of your crew account is now %s. Mail from the crew goes there from now on.\n\nIf you did not do this, tell an organizer.", 'eventcrew'),
                    ['%s' => '{email}']
                ),
                [
                    'email' => self::placeholder(
                        __('The new email address.', 'eventcrew'),
                        static fn (array $c): string => (string) ($c['email'] ?? ''),
                        true
                    ),
                ]
            ),
            new MessageTemplate(
                'email_changed_dm',
                __('Email address changed (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to the linked Telegram chat when a person has confirmed their new email address. Always sent, even when the person turned other messages off.', 'eventcrew'),
                null,
                static fn (): string => self::tag(
                    /* translators: %s: the new email address */
                    __('📧 Your crew account email is now %s. If you did not do this, tell an organizer.', 'eventcrew'),
                    ['%s' => '{email}']
                ),
                [
                    'email' => self::placeholder(
                        __('The new email address.', 'eventcrew'),
                        static fn (array $c): string => (string) ($c['email'] ?? ''),
                        true
                    ),
                ]
            ),
            new MessageTemplate(
                'delete_data_confirm',
                __('Confirm deleting data (email)', 'eventcrew'),
                $email,
                __('Sent when a person presses Delete my data on the manage-account page. The Delete my data button with the link is added under the text.', 'eventcrew'),
                static fn (): string => __('Confirm deleting your data', 'eventcrew'),
                static fn (): string => __('Someone asked to delete your crew account and its history. If it was you, press the button below. The link works once and for 30 minutes. If it was not you, ignore this email.', 'eventcrew')
            ),
            ...self::accessDefinitions(),
            ...self::organizerNoticeDefinitions(),
        ];
        // phpcs:enable Generic.Files.LineLength.TooLong
    }

    /**
     * What AccessNotifier tells a person an organizer gave them: a role, a
     * one-time sign-up pass, organizer rights. An email and a Telegram text
     * for each. The context has 'person' (the reader) and, for a role,
     * 'role' (its name). The emails name the crew page and get the greeting
     * and a button in code; the Telegram texts name the bot.
     *
     * @return list<MessageTemplate>
     */
    private static function accessDefinitions(): array
    {
        $email = MessageTemplate::CHANNEL_EMAIL;
        $telegram = MessageTemplate::CHANNEL_TELEGRAM;

        $openTasks = ['open_tasks' => OpenTaskList::placeholder()];
        $givenRole = ['role' => self::placeholder(
            __('The name of the role, like DJ.', 'eventcrew'),
            static fn (array $c): string => (string) ($c['role'] ?? '')
        )] + $openTasks;

        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literals; splitting them breaks extraction.
        return [
            new MessageTemplate(
                'access_role',
                __('You were given a role (email)', 'eventcrew'),
                $email,
                __('Sent to a person when an organizer gives them a role: with /people in the bot, in the Admin tab of the crew page (also by adding them to a task of that role), or on the wp-admin People page. Not sent when a role is taken away, or to a person who turned off emails about permission changes. The greeting is added above the text, and a See open tasks button to the crew page under it.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: %s: role name, such as DJ */
                    __('You have a new role: %s', 'eventcrew'),
                    ['%s' => '{role}']
                ),
                static fn (): string => self::tag(
                    /* translators: %s: role name, such as DJ */
                    __('You now have the role %s. Its tasks are open to you on the crew page, and you can sign up for them.', 'eventcrew'),
                    ['%s' => '{role}']
                ) . '{open_tasks}',
                $givenRole
            ),
            new MessageTemplate(
                'access_role_dm',
                __('You were given a role (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to a person who uses the bot when an organizer gives them a role: with /people in the bot, in the Admin tab of the crew page (also by adding them to a task of that role), or on the wp-admin People page. Not sent when a role is taken away, or to a person who turned off Telegram messages about permission changes.', 'eventcrew'),
                null,
                static fn (): string => self::tag(
                    /* translators: %s: role name, such as DJ */
                    __('You now have the role %s, so you can sign up for its tasks.', 'eventcrew'),
                    ['%s' => '{role}']
                ) . '{open_tasks}',
                $givenRole
            ),
            new MessageTemplate(
                'access_pass',
                __('You were given a one-time pass (email)', 'eventcrew'),
                $email,
                __('Sent to a person when an organizer gives them a one-time sign-up pass: with /people in the bot, in the Admin tab of the crew page, or on the wp-admin People page. Not sent to a person who turned off emails about permission changes. The greeting is added above the text, and a See open tasks button to the crew page under it.', 'eventcrew'),
                static fn (): string => __('You have a one-time pass', 'eventcrew'),
                static fn (): string => __('You have a one-time pass to sign up for your next task, even while your standing is low. It is used up the next time you sign up.', 'eventcrew') . '{open_tasks}',
                $openTasks
            ),
            new MessageTemplate(
                'access_pass_dm',
                __('You were given a one-time pass (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to a person who uses the bot when an organizer gives them a one-time sign-up pass: with /people in the bot, in the Admin tab of the crew page, or on the wp-admin People page. Not sent to a person who turned off Telegram messages about permission changes.', 'eventcrew'),
                null,
                static fn (): string => __('🎟 You’ve a one-time pass to sign up for your next task, even while your standing is low. It is used up the next time you sign up.', 'eventcrew') . '{open_tasks}',
                $openTasks
            ),
            new MessageTemplate(
                'newcomer_ok',
                __('The team said OK to a newcomer (email)', 'eventcrew'),
                $email,
                __('Sent to a new person without Telegram when an organizer presses OK for them. The greeting is added above the text, and a See open tasks button to the crew page under it.', 'eventcrew'),
                static fn (): string => __('The team said OK', 'eventcrew'),
                static fn (): string => __('The team said OK. You can now take tasks on the crew page.', 'eventcrew') . '{open_tasks}',
                $openTasks
            ),
            new MessageTemplate(
                'newcomer_ok_dm',
                __('The team said OK to a newcomer (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to a new person who uses the bot when an organizer presses OK for them.', 'eventcrew'),
                null,
                static fn (): string => __('✅ The team said OK. You can now take tasks.', 'eventcrew') . '{open_tasks}',
                $openTasks
            ),
            new MessageTemplate(
                'newcomer_unknown',
                __('A newcomer is unknown to the organizers (email)', 'eventcrew'),
                $email,
                __('Sent to a new person without Telegram when an organizer turns down their request with the reason Unknown to us. The greeting is added above the text. With No reason the person is not told.', 'eventcrew'),
                static fn (): string => __('About your sign-up', 'eventcrew'),
                static fn (): string => __('The organizers do not know you yet, so they could not say OK to your sign-up. Please come and talk to us at an event; an organizer can then say OK.', 'eventcrew')
            ),
            new MessageTemplate(
                'newcomer_unknown_dm',
                __('A newcomer is unknown to the organizers (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to a new person who uses the bot when an organizer turns down their request with the reason Unknown to us. With No reason the person is not told.', 'eventcrew'),
                null,
                static fn (): string => __('The organizers do not know you yet, so they could not say OK to your sign-up. Please come and talk to us at an event; an organizer can then say OK.', 'eventcrew')
            ),
            new MessageTemplate(
                'newcomer_timeout',
                __('A newcomer request timed out (email)', 'eventcrew'),
                $email,
                __('Sent to a new person without Telegram when no organizer answered their request in 48 hours. The request is then turned down. The greeting is added above the text.', 'eventcrew'),
                static fn (): string => __('Your sign-up request timed out', 'eventcrew'),
                static fn (): string => __('Your request to join the crew timed out: no organizer answered it in 48 hours. This can mean that no organizer recognized you. To finish signing up, please talk to an organizer directly at an event.', 'eventcrew')
            ),
            new MessageTemplate(
                'newcomer_timeout_dm',
                __('A newcomer request timed out (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to a new person who uses the bot when no organizer answered their request in 48 hours. The request is then turned down.', 'eventcrew'),
                null,
                static fn (): string => '⌛ ' . __('Your request to join the crew timed out: no organizer answered it in 48 hours. This can mean that no organizer recognized you. To finish signing up, please talk to an organizer directly at an event.', 'eventcrew')
            ),
            new MessageTemplate(
                'access_organizer',
                __('You were made an organizer (email)', 'eventcrew'),
                $email,
                __('Sent to a person when an organizer makes them an organizer: with /people in the bot, or on the wp-admin People page. Not sent when organizer rights are taken away, or to a person who turned off emails about permission changes. The greeting is added above the text, and a button to the Admin tab of the crew page under it.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: %s: the organization's name */
                    __('You are now an organizer of %s.', 'eventcrew'),
                    ['%s' => Organization::TAG]
                ),
                static fn (): string => __('You are now an organizer. The crew page has an Admin tab for you, where you manage tasks and people.', 'eventcrew')
            ),
            new MessageTemplate(
                'access_organizer_dm',
                __('You were made an organizer (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to a person who uses the bot when an organizer makes them an organizer: with /people in the bot, or on the wp-admin People page. Not sent when organizer rights are taken away, or to a person who turned off Telegram messages about permission changes.', 'eventcrew'),
                null,
                static fn (): string => self::tag(
                    /* translators: %s: the organization's name */
                    __('⭐ You’re now an organizer of %s.', 'eventcrew'),
                    ['%s' => Organization::TAG]
                ) . ' ' . self::adminTabLine()
            ),
        ];
        // phpcs:enable Generic.Files.LineLength.TooLong
    }

    /**
     * The notices to the organizers: a person meets the conditions of a role,
     * is given a role or is made an organizer (RoleNotifier); a member is
     * blocked after a forged admin request (CrewAdminController); a mailed
     * list was imported or could not be (MailedListImport); a bot chat
     * is sent to the organizers (ChatForwarder).
     *
     * @return list<MessageTemplate>
     */
    private static function organizerNoticeDefinitions(): array
    {
        $email = MessageTemplate::CHANNEL_EMAIL;
        $telegram = MessageTemplate::CHANNEL_TELEGRAM;

        $member = self::placeholder(
            __('The name of the person the notice is about.', 'eventcrew'),
            static fn (array $c): string => ($c['member'] ?? null) instanceof Person ? $c['member']->name() : ''
        );
        $memberEmail = self::placeholder(
            __('The email address of the person the notice is about.', 'eventcrew'),
            static fn (array $c): string => ($c['member'] ?? null) instanceof Person ? $c['member']->email : ''
        );
        // What the newcomer said when joining (JoinAnswers); the preview
        // passes a sample line as 'join_answers'.
        $joinAnswers = self::placeholder(
            __('What the person said when joining: whether they have worked on the crew here before, about how many times, and how many free-entry tickets they think they should have.', 'eventcrew'),
            static fn (array $c): string => is_string($c['join_answers'] ?? null)
                ? $c['join_answers']
                : (($c['member'] ?? null) instanceof Person ? JoinAnswers::line($c['member']) : ''),
            false,
            true
        );
        $roleName = self::placeholder(
            __('The name of the role, like Crew leader.', 'eventcrew'),
            static fn (array $c): string => '' === (string) ($c['role'] ?? '') ? '' : Roles::display((string) $c['role'])
        );
        $giver = self::placeholder(
            __('The name of the organizer who did it. Empty when it is not known.', 'eventcrew'),
            static fn (array $c): string => (string) ($c['giver'] ?? '')
        );
        $from = self::placeholder(
            __('The email address the list was sent from.', 'eventcrew'),
            static fn (array $c): string => (string) ($c['from'] ?? '')
        );
        $added = self::placeholder(
            __('How many were added.', 'eventcrew'),
            static fn (array $c): string => (string) (int) ($c['added'] ?? 0)
        );
        $day = self::placeholder(
            __('The day of the event, like Sat 1 Aug.', 'eventcrew'),
            static fn (array $c): string => (string) ($c['day'] ?? '')
        );
        $sending = self::placeholder(
            __('The sentence saying when the ticket emails go out and how to stop them. Empty when no ticket emails are waiting.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            static fn (array $c): string => '' === (string) ($c['sending_at'] ?? '')
                ? ''
                : sprintf(
                    /* translators: %s: time of day, e.g. 21:40 */
                    __('The ticket emails go out at %s. To stop them, press "Cancel sending" on the Roster page before then.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                    (string) $c['sending_at']
                )
        );
        $sentFrom = static fn (): string => self::tag(
            /* translators: %s: the email address the list was sent from */
            __('The list was sent from %s.', 'eventcrew'),
            ['%s' => '{from}']
        );

        // The role and organizer notices name who did it, unless the context
        // says that is not known ('giver' => ''). The Messages page, with no
        // context, shows the text that names them.
        $byKnown = static fn (array $c): bool => '' !== (string) ($c['giver'] ?? '-');
        $met = static fn (): string => self::tag(
            /* translators: 1: the person's name, 2: the role */
            __('%1$s now meets the conditions for the role %2$s. To give it to them, tap Give them the role below.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            ['%1$s' => '{name}', '%2$s' => '{role}']
        ) . "\n{record}";
        $given = static fn (array $c): string => $byKnown($c)
            ? self::tag(
                /* translators: 1: the person's name, 2: the role, 3: the name of the organizer who gave it */
                __('%1$s was given the role %2$s by %3$s.', 'eventcrew'),
                ['%1$s' => '{name}', '%2$s' => '{role}', '%3$s' => '{giver}']
            )
            : self::tag(
                /* translators: 1: the person's name, 2: the role */
                __('%1$s was given the role %2$s.', 'eventcrew'),
                ['%1$s' => '{name}', '%2$s' => '{role}']
            );
        $made = static fn (array $c): string => $byKnown($c)
            ? self::tag(
                /* translators: 1: the person's name, 2: the name of the organizer who did it */
                __('%1$s was made an organizer by %2$s.', 'eventcrew'),
                ['%1$s' => '{name}', '%2$s' => '{giver}']
            )
            : self::tag(
                /* translators: %s: the person's name */
                __('%s was made an organizer.', 'eventcrew'),
                ['%s' => '{name}']
            );
        $roleNotice = ['name' => $member, 'role' => $roleName, 'giver' => $giver];
        // What the person has done, so the organizer can decide at once.
        $metNotice = $roleNotice + [
            'record' => self::placeholder(
                __('What the person has done: their tasks and their standing. On a line of its own; the line goes when it is not known.', 'eventcrew'),
                static fn (array $c): string => (string) ($c['record'] ?? ''),
                false,
                true
            ),
        ];

        $forged = static fn (): string => self::tag(
            /*
             * translators: 1: person's name, 2: their email, 3: date and time,
             * 4: the admin action's name, 5: IP address
             */
            __(
                "A member who is not an organizer sent an admin request from the Crew app. The request was not carried out and their account is now blocked: they cannot sign up for tasks or sign in on the web.\n\nWho: %1\$s (%2\$s)\nWhen: %3\$s\nAction: %4\$s\nIP address: %5\$s\n\nTo lift the block, open EventCrew > People in wp-admin, edit the person and press Unban.", // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                'eventcrew'
            ),
            ['%1$s' => '{name}', '%2$s' => '{email}', '%3$s' => '{when}', '%4$s' => '{action}', '%5$s' => '{ip}']
        );
        $forgedPlaceholders = [
            'name' => $member,
            'email' => $memberEmail,
            'when' => self::placeholder(
                __('The date and time of the request, like 2026-11-14 21:05:00.', 'eventcrew'),
                static fn (array $c): string => (string) ($c['when'] ?? '')
            ),
            'action' => self::placeholder(
                __('The name of the admin action that was asked for, like Give a credit.', 'eventcrew'),
                static fn (array $c): string => (string) ($c['action'] ?? '')
            ),
            'ip' => self::placeholder(
                __('The IP address the request came from.', 'eventcrew'),
                static fn (array $c): string => '' !== (string) ($c['ip'] ?? '') ? (string) $c['ip'] : __('unknown', 'eventcrew')
            ),
        ];

        $chatName = ['name' => $member];

        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literals; splitting them breaks extraction.
        $ticketsLine = static fn (): string => self::tag(
            /* translators: 1: number of tickets added, 2: the event day, e.g. "Sat 1 Aug" */
            __('%1$d tickets were imported for %2$s.', 'eventcrew'),
            ['%1$d' => '{added}', '%2$s' => '{day}']
        );
        $guestsLine = static fn (): string => self::tag(
            /* translators: 1: number of guests added, 2: the event day, e.g. "Sat 1 Aug" */
            __('%1$d guests were added to the guest list for %2$s.', 'eventcrew'),
            ['%1$d' => '{added}', '%2$s' => '{day}']
        );
        $failedTitle = static fn (): string => __('The ticket list could not be imported', 'eventcrew');
        $nothingAdded = static fn (): string => __('Nothing was added to the door list.', 'eventcrew');
        $ticketPlaceholders = [
            'added' => $added,
            'day' => $day,
            'skipped' => self::placeholder(
                __('The sentence saying how many tickets were skipped because they were already on the list. Empty when none were.', 'eventcrew'),
                static fn (array $c): string => (int) ($c['skipped'] ?? 0) > 0
                    ? sprintf(
                        /* translators: %d: number of tickets skipped */
                        __('%d tickets were skipped because they were already on the list.', 'eventcrew'),
                        (int) $c['skipped']
                    )
                    : ''
            ),
            'sending' => $sending,
            'from' => $from,
        ];
        $guestPlaceholders = [
            'added' => $added,
            'day' => $day,
            'plus_ones' => self::placeholder(
                __('The sentence saying how many plus-ones the guests bring. Empty when none.', 'eventcrew'),
                static fn (array $c): string => (int) ($c['plus_ones'] ?? 0) > 0
                    ? sprintf(
                        /* translators: %d: total number of plus-ones the added guests bring */
                        __('They bring %d plus-ones in total.', 'eventcrew'),
                        (int) $c['plus_ones']
                    )
                    : ''
            ),
            'skipped' => self::placeholder(
                __('The sentence saying how many names were skipped because they were already on the guest list. Empty when none were.', 'eventcrew'),
                static fn (array $c): string => (int) ($c['skipped'] ?? 0) > 0
                    ? sprintf(
                        /* translators: %d: number of names skipped */
                        __('%d names were skipped because they were already on the guest list.', 'eventcrew'),
                        (int) $c['skipped']
                    )
                    : ''
            ),
            'sending' => $sending,
            'from' => $from,
        ];
        $failedPlaceholders = [
            'reason' => self::placeholder(
                __('Why the list could not be imported.', 'eventcrew'),
                static fn (array $c): string => (string) ($c['reason'] ?? '')
            ),
            'from' => $from,
        ];
        return [
            new MessageTemplate(
                'role_met',
                __('A person meets the conditions for a role (email)', 'eventcrew'),
                $email,
                __('Sent to the organizers the first time a person meets the conditions of a role. Checked every hour.', 'eventcrew'),
                static fn (): string => __('Someone meets the conditions for a role', 'eventcrew'),
                $met,
                $metNotice
            ),
            new MessageTemplate(
                'role_met_dm',
                __('A person meets the conditions for a role (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to the organizers who use the bot, the first time a person meets the conditions of a role.', 'eventcrew'),
                null,
                $met,
                $metNotice
            ),
            new MessageTemplate(
                'role_given',
                __('A person was given a role (email)', 'eventcrew'),
                $email,
                __('Sent to the organizers the first time a person is given a role. The built-in text names who gave it when that is known.', 'eventcrew'),
                static fn (): string => __('Someone was given a role', 'eventcrew'),
                $given,
                $roleNotice
            ),
            new MessageTemplate(
                'role_given_dm',
                __('A person was given a role (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to the organizers who use the bot, the first time a person is given a role. The built-in text names who gave it when that is known.', 'eventcrew'),
                null,
                $given,
                $roleNotice
            ),
            new MessageTemplate(
                'organizer_made',
                __('A person was made an organizer (email)', 'eventcrew'),
                $email,
                __('Sent to the other organizers when a person is made an organizer. The built-in text names who did it when that is known.', 'eventcrew'),
                static fn (): string => __('Someone was made an organizer', 'eventcrew'),
                $made,
                ['name' => $member, 'giver' => $giver]
            ),
            new MessageTemplate(
                'organizer_made_dm',
                __('A person was made an organizer (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to the other organizers who use the bot, when a person is made an organizer. The built-in text names who did it when that is known.', 'eventcrew'),
                null,
                $made,
                ['name' => $member, 'giver' => $giver]
            ),
            new MessageTemplate(
                'forged_request',
                __('Member blocked after a forged admin request (email)', 'eventcrew'),
                $email,
                __('Sent to the organizers with a confirmed email address when a member who is not an organizer sends an admin request from the Crew app and is blocked.', 'eventcrew'),
                static fn (): string => __('Crew app: a member is blocked after a forged admin request', 'eventcrew'),
                $forged,
                $forgedPlaceholders
            ),
            new MessageTemplate(
                'forged_request_dm',
                __('Member blocked after a forged admin request (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to the organizers who have a chat with the bot when a member who is not an organizer sends an admin request from the Crew app and is blocked.', 'eventcrew'),
                null,
                $forged,
                $forgedPlaceholders
            ),
            new MessageTemplate(
                'import_tickets',
                __('Ticket list imported (email)', 'eventcrew'),
                $email,
                __('Sent to the organizers, and to the sender, after a ticket file mailed to the import mailbox is imported. A line left empty by a placeholder is removed.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: %s: the event day, e.g. "Sat 1 Aug" */
                    __('Ticket buyers imported for %s', 'eventcrew'),
                    ['%s' => '{day}']
                ),
                static fn (): string => $ticketsLine() . "\n\n{skipped}\n\n{sending}\n\n" . $sentFrom(),
                $ticketPlaceholders
            ),
            new MessageTemplate(
                'import_guests',
                __('Guest list imported (email)', 'eventcrew'),
                $email,
                __('Sent to the organizers, and to the sender, after a list of guests mailed to the import mailbox is added to the guest list. A line left empty by a placeholder is removed.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: %s: the event day, e.g. "Sat 1 Aug" */
                    __('Guests added for %s', 'eventcrew'),
                    ['%s' => '{day}']
                ),
                static fn (): string => $guestsLine() . " {plus_ones}\n\n{skipped}\n\n{sending}\n\n" . $sentFrom(),
                $guestPlaceholders
            ),
            new MessageTemplate(
                'import_failed',
                __('Ticket list not imported (email)', 'eventcrew'),
                $email,
                __('Sent to the organizers, and to the sender, when a ticket file mailed to the import mailbox cannot be imported.', 'eventcrew'),
                $failedTitle,
                static fn (): string => "{reason}\n\n" . $nothingAdded() . "\n\n" . $sentFrom(),
                $failedPlaceholders
            ),
            new MessageTemplate(
                'import_tickets_dm',
                __('Ticket list imported (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to the organizers who use the bot, after a ticket file mailed to the import mailbox is imported. Under it is a button that deletes the imported ticket buyers of that night, after asking first. A line left empty by a placeholder is removed.', 'eventcrew'),
                null,
                static fn (): string => '🎟 ' . $ticketsLine() . "\n\n{skipped}\n\n{sending}\n\n" . $sentFrom(),
                $ticketPlaceholders
            ),
            new MessageTemplate(
                'import_guests_dm',
                __('Guest list imported (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to the organizers who use the bot, after a list of guests mailed to the import mailbox is added to the guest list. A line left empty by a placeholder is removed.', 'eventcrew'),
                null,
                static fn (): string => '🎟 ' . $guestsLine() . " {plus_ones}\n\n{skipped}\n\n{sending}\n\n" . $sentFrom(),
                $guestPlaceholders
            ),
            new MessageTemplate(
                'import_failed_dm',
                __('Ticket list not imported (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to the organizers who use the bot, when a ticket file mailed to the import mailbox cannot be imported.', 'eventcrew'),
                null,
                static fn (): string => '⚠️ ' . $failedTitle() . "\n\n{reason}\n\n" . $nothingAdded() . "\n\n" . $sentFrom(),
                $failedPlaceholders
            ),
            new MessageTemplate(
                'chat_forward',
                __('Bot chat sent to the organizers (email)', 'eventcrew'),
                $email,
                __('Sent to the organizers when the bot finds that a person in a private chat gives feedback, is unhappy, is very happy or asks for a human. The built-in subject says which; a changed subject is used for all four.', 'eventcrew'),
                static fn (array $c): string => match ($c['kind'] ?? '') {
                    /* translators: %s: the person's name */
                    'unhappy' => self::tag(__('Bot chat: %s is unhappy', 'eventcrew'), ['%s' => '{name}']),
                    /* translators: %s: the person's name */
                    'very_happy' => self::tag(__('Bot chat: %s is very happy', 'eventcrew'), ['%s' => '{name}']),
                    /* translators: %s: the person's name */
                    'human' => self::tag(__('Bot chat: %s wants a human', 'eventcrew'), ['%s' => '{name}']),
                    /* translators: %s: the person's name */
                    default => self::tag(__('Bot chat: feedback from %s', 'eventcrew'), ['%s' => '{name}']),
                },
                static fn (): string => self::tag(
                    /* translators: 1: the person's name, 2: their email address */
                    __('%1$s (%2$s) wrote to the bot in a private chat. Here is that chat, oldest first.', 'eventcrew'),
                    ['%1$s' => '{name}', '%2$s' => '{email}']
                ) . "\n\n{transcript}",
                [
                    'name' => $member,
                    'email' => $memberEmail,
                    'transcript' => self::placeholder(
                        __('The chat, one message per paragraph, oldest first, each with its time.', 'eventcrew'),
                        static fn (array $c): string => (string) ($c['transcript'] ?? '')
                    ),
                ]
            ),
            new MessageTemplate(
                'chat_forward_dm',
                __('Bot chat sent to the organizers (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to the organizers who use the bot, after the bot chat email reached them.', 'eventcrew'),
                null,
                static fn (): string => self::tag(
                    /* translators: %s: the person's name */
                    __('I emailed you a transcript of my private chat with %s.', 'eventcrew'),
                    ['%s' => '{name}']
                ),
                $chatName
            ),
            new MessageTemplate(
                'newcomer_waiting',
                __('A newcomer waits for an OK (email)', 'eventcrew'),
                $email,
                __('Sent to the organizers when a new person confirms their email for the first time. Until an organizer says OK they see the board but cannot take tasks. A button to the Admin tab of the crew page, where the OK and No buttons are, is added under the text, and a link to the person\'s @username in Telegram when they have one.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: %s: the person's name */
                    __('New: %s wants to join', 'eventcrew'),
                    ['%s' => '{name}']
                ),
                static fn (): string => self::tag(
                    /* translators: 1: the person's name, 2: their email */
                    __('%1$s (%2$s) signed in for the first time and wants to join the crew. Press OK or No in the Admin tab of the crew page.', 'eventcrew'),
                    ['%1$s' => '{name}', '%2$s' => '{email}']
                ) . "\n\n{join_answers}",
                ['name' => $member, 'email' => $memberEmail, 'join_answers' => $joinAnswers]
            ),
            new MessageTemplate(
                'newcomer_waiting_dm',
                __('A newcomer waits for an OK (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to the organizers who use the bot when a new person confirms their email for the first time. The OK and No buttons are added under the text; the first organizer to press decides.', 'eventcrew'),
                null,
                static fn (): string => self::tag(
                    /* translators: 1: the person's name, 2: their email */
                    __('🆕 New: %1$s (%2$s) wants to join.', 'eventcrew'),
                    ['%1$s' => '{name}', '%2$s' => '{email}']
                ) . "\n{join_answers}",
                ['name' => $member, 'email' => $memberEmail, 'join_answers' => $joinAnswers]
            ),
            new MessageTemplate(
                'newcomer_decided',
                __('A newcomer request was answered (email)', 'eventcrew'),
                $email,
                __('Sent to the organizers who got the email about a new person, when another organizer answers the request or when it times out after 48 hours.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: %s: the person's name */
                    __('Answered: %s', 'eventcrew'),
                    ['%s' => '{name}']
                ),
                static fn (): string => self::tag(
                    /* translators: 1: the person's name, 2: their email */
                    __('The request of %1$s (%2$s) to join the crew is answered:', 'eventcrew'),
                    ['%1$s' => '{name}', '%2$s' => '{email}']
                ) . "\n{outcome}",
                [
                    'name' => $member,
                    'email' => $memberEmail,
                    'outcome' => self::placeholder(
                        __('The answer: OK or No, who gave it and when, and whether the person was told.', 'eventcrew'),
                        static fn (array $c): string => (string) ($c['outcome'] ?? '')
                    ),
                ]
            ),
        ];
        // phpcs:enable Generic.Files.LineLength.TooLong
    }

    /**
     * The welcome message (WelcomeSender), the new member email of the sales
     * desk (DoorNewMember), and the sales desk link and the organizer notice
     * (DoorLinkSender). Their sign-in links are buttons under the text, not
     * placeholders, so no text can lose them.
     *
     * @param array{description: string, resolve: Closure(array<string, mixed>): string} $name
     * @return list<MessageTemplate>
     */
    private static function welcomeAndDoorKinds(array $name): array
    {
        $email = MessageTemplate::CHANNEL_EMAIL;
        $telegram = MessageTemplate::CHANNEL_TELEGRAM;
        $given = static fn (string $key): Closure => static fn (array $c): string => (string) ($c[$key] ?? '');
        $setting = static fn (string $key): Closure => static fn (): string => WelcomeSender::sectionValues()[$key];
        /* translators: %s: the person's first name */
        $hi = static fn (): string => self::tag(__('Hi %s,', 'eventcrew'), ['%s' => '{name}']) . "\n\n";

        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literals; splitting them breaks extraction.
        $crew = self::placeholder(
            __('The organization’s name (Settings > Web page, else the site name).', 'eventcrew'),
            static fn (): string => Organization::name()
        );
        $welcome = [
            'crew' => $crew,
            // No visible address (Owner 2026-11-07): the crew page is the
            // email's See open tasks and Help buttons.
            'me_help' => self::botOrWeb(
                __('Where to see your own numbers: ask the bot, or, for a person with no Telegram chat, the Me tab of the Crew app.', 'eventcrew'),
                // One line for the numbers, tickets and credits: the bot's
                // {credit_help} and {tickets_help} are empty.
                static fn (): string => WelcomeSender::meBotLine(),
                static fn (): string => __('You can see your own numbers any time in the Me tab of the Crew app.', 'eventcrew')
            ),
            'credit_help' => self::botOrWeb(
                __('How to spend a credit: empty for a person with a Telegram chat; for a person with none, the Me tab of the Crew app.', 'eventcrew'),
                static fn (): string => '',
                static fn (): string => __('To spend a credit, pick a night under Tickets in the Me tab of the Crew app.', 'eventcrew')
            ),
            'tickets_help' => self::botOrWeb(
                __('Where all your tickets are: empty for a person with a Telegram chat; for a person with none, the Me tab of the Crew app.', 'eventcrew'),
                static fn (): string => '',
                static fn (): string => __('It is the first button in your sign-up confirmation, and the Me tab of the Crew app lists all your tickets.', 'eventcrew')
            ),
            'roles' => self::placeholder(
                __('The tasks on offer, one line each, with what each one involves.', 'eventcrew'),
                $setting('roles')
            ),
            'tasks_per_credit' => self::placeholder(
                __('How many completed tasks earn one free-entry credit (Settings).', 'eventcrew'),
                $setting('tasks_per_credit')
            ),
            'outcomes' => self::placeholder(
                __('How each way a task can go counts for standing, one line each, in percent (Settings).', 'eventcrew'),
                $setting('outcomes')
            ),
            'notice_hours' => self::placeholder(
                __('How many hours before a task a cancellation counts as late (Settings).', 'eventcrew'),
                $setting('notice_hours')
            ),
            'rated_tasks' => self::placeholder(
                __('How many finished tasks a person needs before they get a rating (Settings).', 'eventcrew'),
                $setting('rated_tasks')
            ),
            'good_standing' => self::placeholder(
                __('The percentage that is good standing (Settings).', 'eventcrew'),
                $setting('good_standing')
            ),
            'gate_rule' => self::placeholder(
                __('A space, then the sentence that people below good standing cannot sign up for new tasks. Empty when that rule is off in Settings.', 'eventcrew'),
                $setting('gate_rule')
            ),
            'open_tasks' => self::placeholder(
                __('The next open tasks by date (at most 3 dates and 8 tasks), and the line about how to sign up. When there are none, a line that says so.', 'eventcrew'),
                $given('open_tasks')
            ),
            'maintainer' => self::placeholder(
                __('Whom to contact when the bot cannot be reached (Settings > Welcome message).', 'eventcrew'),
                static fn (): string => WelcomeSender::maintainerContact()
            ),
        ];
        $day = self::placeholder(__('The day of the event, like Sat 1 Aug.', 'eventcrew'), $given('day'));

        $salesDesk = static fn (): string => self::tag(
            /* translators: 1: event day, e.g. "Sat 1 Aug", 2: how to get another link */
            __('Door list for %1$s: open it with the button below. The link works once, on one device; it logs that device in until 06:00. Forwarding it is fine. Need another one? %2$s', 'eventcrew'),
            ['%1$s' => '{day}', '%2$s' => '{door_help}']
        );
        // The DMs leave out "Need another one?": the 💳 Sales desk button is
        // right under the text.
        $salesDeskDm = static fn (): string => self::tag(
            /* translators: %s: event day, e.g. "Sat 1 Aug" */
            __('Door list for %s: open it with the button below. The link works once, on one device; it logs that device in until 06:00. Forwarding it is fine.', 'eventcrew'),
            ['%s' => '{day}']
        );
        $organizingDm = static fn (): string => self::tag(
            /* translators: %s: event day, e.g. "Sat 1 Aug" */
            __('You are organizing on %s. The sales desk button logs this device in to the door list until 06:00; the link works once. The Admin tab is in the Crew app; sign in there if it asks.', 'eventcrew'),
            ['%s' => '{day}']
        );
        $organizing = static fn (): string => self::tag(
            /* translators: 1: event day, e.g. "Sat 1 Aug", 2: how to get another link */
            __('You are organizing on %1$s. The sales desk button logs this device in to the door list until 06:00; the link works once. The Admin tab is in the Crew app; sign in there if it asks. Need another sales desk link? %2$s', 'eventcrew'),
            ['%1$s' => '{day}', '%2$s' => '{door_help}']
        );
        $doorHelp = self::botOrWeb(
            __('How to get another sales desk link: in the bot, or, for a person with no Telegram chat, on the crew page, with its link.', 'eventcrew'),
            static fn (): string => __('Tap 💳 Sales desk below.', 'eventcrew'),
            // An organizer has no Sales desk card in the Me tab; theirs is
            // under Door in the Admin tab (templates/public/signup.php).
            static fn (string $url, array $c): string => ($c['person'] ?? null) instanceof Person && $c['person']->isOrganizer
                ? sprintf(
                    /* translators: %s: link to the crew page */
                    __('The Sales desk card is under Door in the Admin tab of the crew page (%s).', 'eventcrew'),
                    $url
                )
                : sprintf(
                    /* translators: %s: link to the crew page */
                    __('Tap Sales desk in the Me tab of the crew page (%s).', 'eventcrew'),
                    $url
                )
        );

        return [
            new MessageTemplate(
                WelcomeSender::KIND_EMAIL,
                __('Welcome message (email)', 'eventcrew'),
                $email,
                __('Sent once to each member when their email address is confirmed: how tasks, tickets, credits and standing work, and the open tasks. Only when the welcome message is on in Settings.', 'eventcrew'),
                static fn (): string => WelcomeSender::subject(),
                static fn (): string => $hi() . WelcomeSender::bodyTemplate(),
                ['name' => $name] + $welcome
            ),
            new MessageTemplate(
                WelcomeSender::KIND_DM,
                __('Welcome message (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent once to each member who uses the bot, with the open tasks by date and a button to the board. Only when the welcome message is on in Settings.', 'eventcrew'),
                null,
                static fn (): string => WelcomeSender::bodyTemplate(),
                $welcome
            ),
            new MessageTemplate(
                WelcomeSender::KIND_ORG_EMAIL,
                __('Organizer guide (email)', 'eventcrew'),
                $email,
                __('Sent once to each organizer, with the whole organizer guide. Only when the welcome message is on in Settings.', 'eventcrew'),
                static fn (): string => OrganizerGuide::subject(),
                static fn (): string => $hi() . WelcomeSender::organizerIntroTemplate() . "\n\n{guide}",
                [
                    'name' => $name,
                    'crew' => $crew,
                    'guide' => self::placeholder(
                        __('The whole organizer guide. It is made from the settings when it is sent.', 'eventcrew'),
                        $given('guide')
                    ),
                ]
            ),
            new MessageTemplate(
                WelcomeSender::KIND_ORG_DM,
                __('Organizer guide (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent once to each organizer who uses the bot, with the short part of the organizer guide. Only when the welcome message is on in Settings.', 'eventcrew'),
                null,
                static fn (): string => WelcomeSender::organizerIntroTemplate() . "\n\n{guide}\n\n"
                    . __('The full guide, with the details, is in your email.', 'eventcrew'),
                [
                    'crew' => $crew,
                    'guide' => self::placeholder(
                        __('The short part of the organizer guide ("In short"). It is made from the settings when it is sent.', 'eventcrew'),
                        $given('guide')
                    ),
                ]
            ),
            new MessageTemplate(
                'door_new_member',
                __('New member added at the door (email)', 'eventcrew'),
                $email,
                __('Sent when the sales desk adds a new member. The first button under the text confirms their email address and signs them in. The greeting above the text and the privacy line under it are always added, in the member\'s language.', 'eventcrew'),
                static fn (): string => __('Welcome to the crew', 'eventcrew'),
                static fn (): string => __(
                    "You were added to the crew at the door. Welcome!\n\nThe first button confirms your email address and signs you in. It works once, for 24 hours.",
                    'eventcrew'
                ) . '{group_line}',
                [
                    'group_line' => self::placeholder(
                        __('An empty line, then the line about the button that opens the crew’s Telegram group. Empty when no group link is known; then there is no such button.', 'eventcrew'),
                        $given('group_line')
                    ),
                ]
            ),
            new MessageTemplate(
                'sales_desk_link',
                __('Sales desk link (email)', 'eventcrew'),
                $email,
                __('Sent on an event night to the people on a sales desk task, once they are marked arrived. The button under the text opens the sales desk.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: %s: event day, e.g. "Sat 1 Aug" */
                    __('Sales desk for %s', 'eventcrew'),
                    ['%s' => '{day}']
                ),
                $salesDesk,
                ['day' => $day, 'door_help' => $doorHelp]
            ),
            new MessageTemplate(
                'sales_desk_link_dm',
                __('Sales desk link (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent on an event night to the people on a sales desk task, once they are marked arrived, and to anyone who asks with /door. The button under the text opens the sales desk.', 'eventcrew'),
                null,
                $salesDeskDm,
                ['day' => $day, 'door_help' => $doorHelp]
            ),
            new MessageTemplate(
                'organizer_notice',
                __('Organizer on the night (email)', 'eventcrew'),
                $email,
                __('Sent on an event night to the organizers signed up on the Organize task, before the first task starts. The buttons under the text open the sales desk and the Admin tab.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: %s: event day, e.g. "Sat 1 Aug" */
                    __('You are organizing on %s', 'eventcrew'),
                    ['%s' => '{day}']
                ),
                $organizing,
                ['day' => $day, 'door_help' => $doorHelp]
            ),
            new MessageTemplate(
                'organizer_notice_dm',
                __('Organizer on the night (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent on an event night to the organizers signed up on the Organize task, before the first task starts. The buttons under the text open the sales desk and the Admin tab.', 'eventcrew'),
                null,
                $organizingDm,
                ['day' => $day, 'door_help' => $doorHelp]
            ),
        ];
        // phpcs:enable Generic.Files.LineLength.TooLong
    }

    /**
     * The takeover messages TakeoverService sends: the request to the person
     * whose task it is (the holder), the "done" note to them, and each outcome
     * to the person who asked (the cover). The context has 'person' (the
     * reader), 'cover', 'holder', 'task' and, for the request, 'deadline' (a
     * MySQL date). The answer link of the request email is its button, not
     * part of the text. The group message stays in TakeoverService.
     *
     * @param array{description: string, resolve: Closure(array<string, mixed>): string} $name
     * @param array{description: string, resolve: Closure(array<string, mixed>): string} $role
     * @param array{description: string, resolve: Closure(array<string, mixed>): string} $event
     * @param array{description: string, resolve: Closure(array<string, mixed>): string} $when
     * @param array{description: string, resolve: Closure(array<string, mixed>): string} $description
     * @return list<MessageTemplate>
     */
    private static function takeoverTemplates(
        array $name,
        array $role,
        array $event,
        array $when,
        array $description
    ): array {
        $email = MessageTemplate::CHANNEL_EMAIL;
        $telegram = MessageTemplate::CHANNEL_TELEGRAM;

        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literals; splitting them breaks extraction.
        $cover = self::placeholder(
            __('The first name of the person who asks to take over the task.', 'eventcrew'),
            static fn (array $c): string => ($c['cover'] ?? null) instanceof Person ? $c['cover']->name() : ''
        );
        $holder = self::placeholder(
            __('The first name of the person whose task it is.', 'eventcrew'),
            static fn (array $c): string => ($c['holder'] ?? null) instanceof Person ? $c['holder']->name() : ''
        );
        $deadline = self::placeholder(
            __('The last time to answer, like Thu 23 Jul 12:00.', 'eventcrew'),
            static fn (array $c): string => isset($c['deadline']) ? TakeoverDeadline::label((string) $c['deadline']) : ''
        );
        // Who is asking, so the holder can decide without looking it up.
        $coverRecord = self::placeholder(
            __('How many tasks the person who asks has done, like: Sam has done 5 tasks. On a line of its own; the line goes when it is not known.', 'eventcrew'),
            static fn (array $c): string => (string) ($c['cover_record'] ?? ''),
            false,
            true
        );
        $task = ['%2$s' => '{role}', '%3$s' => '{event}', '%4$s' => '{when}'];
        $hi = static fn (string $line): string => self::tag(
            /* translators: 1: name, 2: the cover line */
            __("Hi %1\$s,\n\n%2\$s", 'eventcrew'),
            ['%1$s' => '{name}', '%2$s' => $line]
        );

        $ask = static fn (): string => FrictionB3Migration::withAskDetails(self::tag(
            /* translators: 1: cover's name, 2: role, 3: event, 4: date/time, 5: answer deadline */
            __("🔁 %1\$s asks to take over your task: %2\$s at %3\$s, %4\$s.\n\nYou decide: let them take it, or keep it. Please answer by %5\$s. If you do not answer by then, you keep the task.", 'eventcrew'),
            ['%1$s' => '{cover}', '%5$s' => '{deadline}'] + $task
        ));
        $askPlaceholders = [
            'cover' => $cover,
            'role' => $role,
            'event' => $event,
            'when' => $when,
            'cover_record' => $coverRecord,
            'description' => $description,
            'deadline' => $deadline,
        ];
        $done = static fn (): string => self::tag(
            /* translators: 1: cover's name, 2: role, 3: event, 4: date/time */
            __('✅ Done: %1$s now has your task: %2$s at %3$s, %4$s.', 'eventcrew'),
            ['%1$s' => '{cover}'] + $task
        );
        $outcomes = [
            'takeover_refused' => [
                __('Takeover declined (email)', 'eventcrew'),
                __('Takeover declined (Telegram)', 'eventcrew'),
                __('Sent to the person who asked to take over a task when its holder keeps the task.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: 1: holder's name, 2: role, 3: event, 4: date/time */
                    __('%1$s keeps their task: %2$s at %3$s, %4$s. Your request to take it over was declined.', 'eventcrew'),
                    ['%1$s' => '{holder}'] + $task
                ),
            ],
            'takeover_expired' => [
                __('Takeover not answered in time (email)', 'eventcrew'),
                __('Takeover not answered in time (Telegram)', 'eventcrew'),
                __('Sent to the person who asked to take over a task when its holder did not answer in time.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: 1: holder's name, 2: role, 3: event, 4: date/time */
                    __('%1$s did not answer in time, so they keep their task: %2$s at %3$s, %4$s.', 'eventcrew'),
                    ['%1$s' => '{holder}'] + $task
                ),
            ],
            'takeover_failed' => [
                __('Takeover could not be done (email)', 'eventcrew'),
                __('Takeover could not be done (Telegram)', 'eventcrew'),
                __('Sent to the person who asked to take over a task when its holder agreed but the task could not be moved.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: 1: holder's name, 2: role, 3: event, 4: date/time */
                    __('%1$s agreed, but their task could not be moved to you: %2$s at %3$s, %4$s. Nothing changed.', 'eventcrew'),
                    ['%1$s' => '{holder}'] + $task
                ),
            ],
            'takeover_closed' => [
                __('Takeover request closed (email)', 'eventcrew'),
                __('Takeover request closed (Telegram)', 'eventcrew'),
                __('Sent to the person who asked to take over a task when the task changed or was cancelled before an answer.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: 1: holder's name, 2: role, 3: event, 4: date/time */
                    __('Your request to take over the task of %1$s is closed, because the task changed or was cancelled: %2$s at %3$s, %4$s.', 'eventcrew'),
                    ['%1$s' => '{holder}'] + $task
                ),
            ],
        ];

        // The open tasks the cover can take instead (OpenTaskList).
        $otherTasks = OpenTaskList::placeholder();
        $outcomePlaceholders = [
            'name' => $name,
            'holder' => $holder,
            'role' => $role,
            'event' => $event,
            'when' => $when,
            'other_tasks' => $otherTasks,
        ];
        $list = [
            new MessageTemplate(
                'takeover_ask',
                __('Takeover request (email)', 'eventcrew'),
                $email,
                __('Sent to a person when someone asks to take over their task. The email has a button to answer.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: %s: cover's name */
                    __('%s asks to take over your task', 'eventcrew'),
                    ['%s' => '{cover}']
                ),
                static fn (): string => $hi($ask()),
                ['name' => $name] + $askPlaceholders
            ),
            new MessageTemplate(
                'takeover_ask_dm',
                __('Takeover request (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to a person who uses the bot when someone asks to take over their task. The message has two buttons to answer.', 'eventcrew'),
                null,
                $ask,
                $askPlaceholders
            ),
            new MessageTemplate(
                'takeover_done',
                __('Takeover done (email)', 'eventcrew'),
                $email,
                __('Sent to a person who let someone take over their task, when the task has moved.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: %s: cover's name */
                    __('Your task moved to %s', 'eventcrew'),
                    ['%s' => '{cover}']
                ),
                static fn (): string => $hi($done()),
                ['name' => $name, 'cover' => $cover, 'role' => $role, 'event' => $event, 'when' => $when]
            ),
            new MessageTemplate(
                'takeover_done_dm',
                __('Takeover done (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to a person who let someone take over their task, when the task has moved. Not sent when they answered in the bot.', 'eventcrew'),
                null,
                $done,
                ['cover' => $cover, 'role' => $role, 'event' => $event, 'when' => $when]
            ),
        ];

        foreach ($outcomes as $id => [$emailLabel, $dmLabel, $description, $line]) {
            $list[] = new MessageTemplate(
                $id,
                $emailLabel,
                $email,
                $description,
                static fn (): string => __('About your takeover request', 'eventcrew'),
                static fn (): string => $hi(FrictionB1Migration::withOtherTasks($line())),
                $outcomePlaceholders
            );
            $list[] = new MessageTemplate(
                $id . '_dm',
                $dmLabel,
                $telegram,
                $description,
                null,
                static fn (): string => FrictionB1Migration::withOtherTasks($line()),
                ['holder' => $holder, 'role' => $role, 'event' => $event, 'when' => $when, 'other_tasks' => $otherTasks]
            );
        }
        // phpcs:enable Generic.Files.LineLength.TooLong

        return $list;
    }

    /**
     * @param Closure(array<string, mixed>): string $resolve
     * @param bool $required The text must keep it (MessageTemplate::missingRequired()).
     * @param bool $ownLine It stands on a line of its own, which goes when it is empty
     *                      (MessageTemplate::fill()).
     * @return array{
     *     description: string, resolve: Closure(array<string, mixed>): string, required: bool, own_line: bool
     * }
     */
    private static function placeholder(
        string $description,
        Closure $resolve,
        bool $required = false,
        bool $ownLine = false
    ): array {
        return ['description' => $description, 'resolve' => $resolve, 'required' => $required, 'own_line' => $ownLine];
    }

    /**
     * "Ecstatic Dance · Esimerkki": $name and the DJs of event $eventId
     * (EventName::djLine()); $name alone when there are none, '' when $name
     * is ''.
     */
    private static function eventWithDjs(string $name, ?int $eventId): string
    {
        $djs = '' === $name || null === $eventId ? '' : EventName::djLine($eventId);

        return '' === $djs ? $name : $name . ' · ' . $djs;
    }

    /**
     * "Event starts 19:00 · doors close 19:30" for the task's event, the
     * half that is known, or '' when neither time is (EventTimes).
     */
    private static function eventTimes(?int $eventPostId): string
    {
        $start = EventTimes::start($eventPostId);
        $doors = EventTimes::doorsClose($eventPostId);

        if ('' === $start) {
            /* translators: %s: a time like 19:30 */
            return '' === $doors ? '' : sprintf(__('Doors close %s', 'eventcrew'), $doors);
        }

        return '' === $doors
            /* translators: %s: a time like 19:00 */
            ? sprintf(__('Event starts %s', 'eventcrew'), $start)
            /* translators: 1: start time, 2: doors close time, like 19:00 and 19:30 */
            : sprintf(__('Event starts %1$s · doors close %2$s', 'eventcrew'), $start, $doors);
    }

    /**
     * $text with "\n\n{description}" put right after the first line that has
     * {when} in it. A text that has {description} already, or no {when},
     * comes back as it is. Used for the reminder's built-in texts and, once,
     * for the reminder texts stored before {description} existed
     * (migrateReminderDescription()).
     */
    public static function withDescription(string $text): string
    {
        if (str_contains($text, '{description}') || ! str_contains($text, '{when}')) {
            return $text;
        }

        $at = strpos($text, '{when}');
        $end = strpos($text, "\n", (int) $at);

        return false === $end
            ? $text . "\n\n{description}"
            : substr($text, 0, $end) . "\n\n{description}" . substr($text, $end);
    }

    /**
     * One-time migration: the reminder texts organizers saved
     * before {description} existed get it after their {when} line, in the
     * source and in every language text (withDescription()). Runs once per
     * site (REMINDER_DESCRIPTION_OPTION); a text that has {description}
     * already is left as it is.
     */
    public static function migrateReminderDescription(): void
    {
        if ('1' === (string) get_option(self::REMINDER_DESCRIPTION_OPTION, '')) {
            return;
        }

        foreach (['reminder', 'reminder_dm'] as $id) {
            $raw = get_option(self::OPTION_PREFIX . $id, null);

            if (! is_array($raw) || ! is_array($raw[self::BODY] ?? null)) {
                continue;
            }

            $body = $raw[self::BODY];

            if (is_string($body['source'] ?? null)) {
                $body['source'] = self::withDescription($body['source']);
            }

            foreach (is_array($body['texts'] ?? null) ? $body['texts'] : [] as $lang => $text) {
                if (is_string($text)) {
                    $body['texts'][$lang] = self::withDescription($text);
                }
            }

            if ($body !== $raw[self::BODY]) {
                $raw[self::BODY] = $body;
                update_option(self::OPTION_PREFIX . $id, $raw, false);
            }
        }

        update_option(self::REMINDER_DESCRIPTION_OPTION, '1');
    }

    /**
     * A placeholder with two texts: $bot for a person with a Telegram chat, $web,
     * given the crew page link, for a person with none (CrewPageHelp). A site
     * with no crew page gives everyone the bot text.
     *
     * @param Closure(array<string, mixed>): string $bot
     * @param Closure(string, array<string, mixed>): string $web
     * @return array{description: string, resolve: Closure(array<string, mixed>): string, required: bool}
     */
    private static function botOrWeb(string $description, Closure $bot, Closure $web): array
    {
        return self::placeholder(
            $description,
            static fn (array $c): string => CrewPageHelp::webOnly($c['person'] ?? null)
                ? $web(CrewPageHelp::pageUrl(), $c)
                : $bot($c)
        );
    }

    /** "You now have 3 free-entry credits." */
    private static function balanceLine(int $balance): string
    {
        return sprintf(
            /* translators: %d: free-entry credits the person has now */
            _n('You now have %d free-entry credit.', 'You now have %d free-entry credits.', $balance, 'eventcrew'),
            $balance
        );
    }

    /**
     * "Next nights for free entry:" and a line per night, after an empty
     * line (the placeholder stands on a line of its own); '' for none.
     *
     * @param array<mixed, mixed> $nights date (Y-m-d) => label
     */
    private static function nightsBlock(array $nights): string
    {
        $lines = [];

        foreach ($nights as $label) {
            $lines[] = '- 🎟 ' . (string) $label;
        }

        return [] === $lines ? '' : "\n" . __('Next nights for free entry:', 'eventcrew') . "\n" . implode("\n", $lines);
    }

    /**
     * One action per night: the DM's tkt:<date> button (the same as in the
     * 🎟 Free-entry ticket picker), in an email the tkt-<Ymd> link that
     * shows that button in the bot.
     *
     * @param array<mixed, mixed> $nights date (Y-m-d) => label
     * @return list<MessageAction>
     */
    private static function nightActions(array $nights): array
    {
        $actions = [];

        foreach ($nights as $date => $label) {
            if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
                continue;
            }

            $actions[] = new MessageAction(
                'night_' . $date,
                '🎟 ' . (string) $label,
                '',
                null,
                'tkt:' . $date,
                '',
                TicketRedemptionService::START_VERB . '-' . str_replace('-', '', (string) $date)
            );
        }

        return $actions;
    }

    /** "Fri 14 Nov 18:00–20:00": the task's day label and time, as the crew messages write it. */
    private static function when(Task $task): string
    {
        $day = Dates::dayLabel($task->taskDate);

        return '' === $task->timeRange() ? $day : $day . ' ' . $task->timeRange();
    }

    /** "Door at Dance Night, Sat 14 Nov 18:00–20:00", the day as in when(). */
    private static function standingTask(Task $task): string
    {
        return sprintf(
            /* translators: 1: role, 2: event, 3: day and time */
            __('%1$s at %2$s, %3$s', 'eventcrew'),
            $task->roleLabel(),
            $task->eventName(),
            self::when($task)
        );
    }

    /**
     * The post-event email's text as FollowupMail::words() builds it, in the
     * current language: the greeting, $main and the sign-off.
     */
    public static function followupBody(string $main): string
    {
        /* translators: %s: the person's first name */
        return self::tag(__('Hi %s,', 'eventcrew'), ['%s' => '{first_name}']) . "\n\n"
            . trim($main) . "\n\n"
            . __('Warmly,', 'eventcrew') . "\n" . Organization::name();
    }
}

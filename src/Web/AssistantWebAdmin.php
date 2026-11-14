<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\Dates;
use EventCrew\Support\EventAiText;
use EventCrew\Support\Roles;
use EventCrew\Support\ScheduledMessages;
use EventCrew\Support\TranslatedText;
use EventCrew\Telegram\AssignCommand;
use EventCrew\Telegram\CommandFlow;

/**
 * The Crew app Admin tab's remaining changes as typed lines (parityA,
 * Owner 2026-11-14: what the app does, the bot and the helper do too). The
 * helper box runs them on Confirm (AssistantWebChanges) and the bot's
 * AdminChangeCommands asks the same questions with ✅ / ✖️; both run the
 * Admin tab's own methods, so the three never differ:
 *
 * - "/oncemore <person>": CrewAdminPeople::grantPass(), one more sign-up
 *   past the gate for a member at risk; the member is told.
 * - "/addperson <name> ; <email> [; <notes>]": CrewAdminPeople::save() with
 *   no id. The new person is left unverified, as on the page.
 * - "/editperson <person> ; name <new> ; email <new> ; notes <text|->":
 *   CrewAdminPeople::save(), the roles kept.
 * - "/editrole <role> ; <field> <value> ; …": CrewAdminTasks::saveRole(),
 *   the role's other fields kept as they are (FIELDS, FLAGS).
 * - "/optout add|remove <email>": CrewAdminFollowup::saveOptOut(), the
 *   post-event email's do-not-send list.
 * - "/aitext <date> <text name> <language> ; <text>" or
 *   "/aitext <date> <text name> ai": CrewAdminAiText::save(), one event's
 *   AI text written by hand, or the AI's own text again.
 * - "/scheduled <message> on|off|send": ScheduledMessages::apply(), a
 *   scheduled message switched on or off, or sent once now (Owner
 *   2026-11-14). On and off switch at once, with no Confirm (easy to
 *   undo, nothing is sent); "send" asks, saying how many get it.
 * - "/roleorder <role> up|down|first|last": Roles::move(), the order every
 *   night's tasks are listed in (TaskOrder). Done at once, with no Confirm:
 *   easy to undo and nothing is sent (Owner 2026-11-14). Alone it lists the
 *   order.
 *
 * Emails are personal data: organizers only, as the Admin tab.
 */
final class AssistantWebAdmin
{
    public const AT_RISK_ONCE_MORE = 'oncemore';
    public const ADD_PERSON = 'addperson';
    public const EDIT_PERSON = 'editperson';
    public const EDIT_ROLE = 'editrole';
    public const OPTOUT = 'optout';
    public const AI_TEXT = 'aitext';
    public const SCHEDULED = 'scheduled';
    public const ROLE_ORDER = 'roleorder';

    /** The commands this class answers, as AssistantWebChanges splits them. */
    public const COMMANDS = [
        self::AT_RISK_ONCE_MORE,
        self::ADD_PERSON,
        self::EDIT_PERSON,
        self::EDIT_ROLE,
        self::OPTOUT,
        self::AI_TEXT,
        self::SCHEDULED,
        self::ROLE_ORDER,
    ];

    /** /editperson fields => CrewAdminPeople::save() argument. */
    public const PERSON_FIELDS = ['name', 'email', 'notes'];

    /**
     * /editrole fields with a value => the Roles screen's form field.
     *
     * @var array<string, string>
     */
    public const FIELDS = [
        'name' => 'role_label',
        'emoji' => 'role_emoji',
        'people' => 'role_capacity',
        'plus' => 'role_plus_ones',
        'description' => 'role_description',
        'measured' => 'role_anchor',
        'starts' => 'role_start_offset',
        'ends' => 'role_end_offset',
        'events' => 'role_title_contains',
        'minroles' => 'role_min_roles',
        'timeseach' => 'role_times_each',
        'count' => 'role_count',
    ];

    /**
     * /editrole on|off fields => the Roles screen's checkbox.
     *
     * @var array<string, string>
     */
    public const FLAGS = [
        'archived' => 'role_archived',
        'restricted' => 'role_restricted',
        'everynight' => 'role_every_night',
        'salesdesk' => 'role_sales_desk',
        'guestdisplay' => 'role_on_board',
        'membersdisplay' => 'role_members_display',
        'guestsignup' => 'role_guest_signup',
        'anonymous' => 'role_anonymous',
        'adminaccess' => 'role_admin_access',
    ];

    /** The page's "+1 allowed" field's highest number. */
    private const MAX_PLUS_ONES = 20;

    /** The longest list or text a bot message carries. */
    private const MAX_MESSAGE = 3800;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly CrewAdminPeople $peopleScreen,
        private readonly CrewAdminTasks $tasksScreen,
        private readonly CrewAdminFollowup $followupScreen,
        private readonly CrewAdminAiText $aiTextScreen,
        private readonly ?ScheduledMessages $scheduled = null
    ) {
    }

    /**
     * The question, the person to choose first, or (ok false) why not.
     *
     * @return array{ok: bool, text: string, state: array<string, mixed>, choices?: list<array{label: string, state: array<string, mixed>}>}
     */
    public function prepare(Person $organizer, string $command, string $rest): array
    {
        if (! $organizer->isOrganizer) {
            return self::fail(__('That can no longer be done.', 'eventcrew'));
        }

        $rest = trim($rest);

        switch ($command) {
            case self::AT_RISK_ONCE_MORE:
                return '' === $rest
                    ? self::fail(self::usage(self::AT_RISK_ONCE_MORE))
                    : $this->person($organizer, $rest, ['op' => self::AT_RISK_ONCE_MORE]);

            case self::ADD_PERSON:
                return $this->prepareAdd($rest);

            case self::EDIT_PERSON:
                return $this->prepareEdit($organizer, $rest);

            case self::EDIT_ROLE:
                return self::prepareRole($rest);

            case self::OPTOUT:
                return $this->prepareOptOut($rest);

            case self::AI_TEXT:
                return self::prepareAiText($rest);

            case self::SCHEDULED:
                return $this->prepareScheduled($rest);

            case self::ROLE_ORDER:
                return $this->prepareRoleOrder($rest);
        }

        return self::fail(__('That can no longer be done.', 'eventcrew'));
    }

    /**
     * The question once the person is chosen.
     *
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, state: array<string, mixed>}
     */
    public function choose(Person $organizer, array $state): array
    {
        $person = $this->people->find((int) ($state['person'] ?? 0));

        if (null === $person || $person->isAnonymized()) {
            return self::fail(CrewAdminPeople::noticeText('admin_no_person'));
        }

        $name = $person->name();

        switch ((string) ($state['op'] ?? '')) {
            case self::AT_RISK_ONCE_MORE:
                if ($person->hasAtRiskPass) {
                    /* translators: %s: a person's name. */
                    return self::fail(sprintf(__('%s may already sign up once more.', 'eventcrew'), $name));
                }

                /* translators: %s: a person's name. */
                return self::ask(sprintf(__('Let %s sign up once more past the sign-up gate? They are told.', 'eventcrew'), $name), $state);

            case self::EDIT_PERSON:
                $email = (string) ($state['email'] ?? '');

                if ('' !== $email) {
                    $other = $this->people->findByEmail($email);

                    if (null !== $other && $other->id !== $person->id) {
                        return self::fail(CrewAdminPeople::noticeText('admin_person_email_taken'));
                    }
                }

                $lines = [];

                foreach (self::PERSON_FIELDS as $field) {
                    if (array_key_exists($field, $state)) {
                        $lines[] = self::personFieldLabel($field) . ': ' . ('' === $state[$field] ? '–' : (string) $state[$field]);
                    }
                }

                /* translators: %s: a person's name. */
                return self::ask(sprintf(__('Change %s?', 'eventcrew'), $name) . "\n" . implode("\n", $lines), $state);
        }

        return self::fail(__('That can no longer be done.', 'eventcrew'));
    }

    /**
     * Confirm: the change, through the Admin tab's own method.
     *
     * @param array<string, mixed> $state
     * @return array{changed: bool, text: string}
     */
    public function run(Person $organizer, array $state): array
    {
        if (! $organizer->isOrganizer) {
            return ['changed' => false, 'text' => __('That can no longer be done.', 'eventcrew')];
        }

        switch ((string) ($state['op'] ?? '')) {
            case self::AT_RISK_ONCE_MORE:
                $code = $this->peopleScreen->grantPass((int) ($state['person'] ?? 0));

                $given = 'admin_pass_granted' === $code;

                return [
                    'changed' => $given,
                    'text' => $given ? __('Done. They may sign up once more.', 'eventcrew') : CrewAdminPeople::noticeText($code),
                ];

            case self::ADD_PERSON:
                $code = $this->peopleScreen->save(
                    0,
                    (string) ($state['email'] ?? ''),
                    (string) ($state['name'] ?? ''),
                    (string) ($state['notes'] ?? ''),
                    [],
                    $organizer->name()
                );

                return ['changed' => 'admin_person_added' === $code, 'text' => CrewAdminPeople::noticeText($code)];

            case self::EDIT_PERSON:
                $person = $this->people->find((int) ($state['person'] ?? 0));

                if (null === $person || $person->isAnonymized()) {
                    return ['changed' => false, 'text' => CrewAdminPeople::noticeText('admin_no_person')];
                }

                $code = $this->peopleScreen->save(
                    $person->id,
                    (string) ($state['email'] ?? $person->email),
                    (string) ($state['name'] ?? $person->displayName),
                    (string) ($state['notes'] ?? $person->notes),
                    $person->roleSlugs,
                    $organizer->name()
                );

                return ['changed' => 'admin_person_updated' === $code, 'text' => CrewAdminPeople::noticeText($code)];

            case self::EDIT_ROLE:
                $post = self::rolePost((string) ($state['role'] ?? ''), (array) ($state['changes'] ?? []));

                if (null === $post) {
                    return ['changed' => false, 'text' => CrewAdminTasks::noticeText('admin_role_gone')];
                }

                $code = $this->tasksScreen->saveRole($post);

                return ['changed' => 'admin_role_saved' === $code, 'text' => CrewAdminTasks::noticeText($code)];

            case self::OPTOUT:
                $code = $this->followupScreen->saveOptOut((string) ($state['do'] ?? ''), (string) ($state['email'] ?? ''));

                return [
                    'changed' => in_array($code, ['admin_optout_added', 'admin_optout_removed'], true),
                    'text' => CrewAdminFollowup::noticeText($code),
                ];

            case self::AI_TEXT:
                $back = ! empty($state['ai']);
                $post = ['event_id' => (int) ($state['event'] ?? 0), 'key' => (string) ($state['key'] ?? '')];
                $post += $back ? ['op' => 'ai'] : ['texts' => [(string) ($state['lang'] ?? '') => (string) ($state['text'] ?? '')]];
                $code = $this->aiTextScreen->save($organizer, $post);

                return [
                    'changed' => in_array($code, ['admin_aitext_saved', 'admin_aitext_back'], true),
                    'text' => CrewAdminAiText::noticeText($code),
                ];

            case self::SCHEDULED:
                if (null === $this->scheduled) {
                    break;
                }

                $code = $this->scheduled->apply((string) ($state['message'] ?? ''), (string) ($state['do'] ?? ''));

                return [
                    'changed' => str_starts_with($code, 'admin_sched_on_')
                        || str_starts_with($code, 'admin_sched_off_')
                        || str_starts_with($code, 'admin_sched_sent_'),
                    'text' => ScheduledMessages::noticeText($code),
                ];
        }

        return ['changed' => false, 'text' => __('That can no longer be done.', 'eventcrew')];
    }

    /**
     * The bot's /scheduled alone: every scheduled message, on or off.
     */
    public function scheduledList(): string
    {
        return self::clip(ScheduledMessages::listText());
    }

    /**
     * "/scheduled <message> on|off|send": on and off are done at once;
     * "send" asks with how many get it, or says why not.
     *
     * @return array{ok: bool, text: string, state: array<string, mixed>}
     */
    private function prepareScheduled(string $rest): array
    {
        $words = '' === $rest ? [] : (array) preg_split('/\s+/', strtolower($rest));
        $key = (string) ($words[0] ?? '');
        $do = (string) ($words[1] ?? '');

        if (null === $this->scheduled || ! ScheduledMessages::exists($key) || ! in_array($do, ['on', 'off', 'send'], true)) {
            return self::fail(self::usage(self::SCHEDULED));
        }

        $state = ['op' => self::SCHEDULED, 'message' => $key, 'do' => $do];
        $name = ScheduledMessages::name($key);

        if ('send' !== $do) {
            // Owner 2026-11-14: easy to undo and sends nothing, so it is
            // switched at once, with no Confirm, as /notify is. The answer
            // has "ran" true and no state to confirm.
            $code = $this->scheduled->apply($key, $do);

            return [
                'ok' => false,
                'text' => ScheduledMessages::noticeText($code),
                'state' => [],
                'ran' => true,
                'changed' => str_starts_with($code, 'admin_sched_' . $do . '_'),
            ];
        }

        if (! ScheduledMessages::sendable($key)) {
            /* translators: %s: a scheduled message's name. */
            return self::fail(sprintf(__('"%s" cannot be sent now; it goes when its time comes.', 'eventcrew'), $name));
        }

        if (ScheduledMessages::sendNeedsOn($key)) {
            return self::fail(ScheduledMessages::noticeText('admin_sched_needson_' . $key));
        }

        $question = $this->scheduled->question($key);

        return '' === $question ? self::fail(ScheduledMessages::sentText($key, 0)) : self::ask($question, $state);
    }

    /**
     * The bot's /roleorder alone: the roles in their order, and how to move one.
     */
    public static function roleOrderList(): string
    {
        return self::clip(
            __('Role order: every night\'s tasks are listed in this order.', 'eventcrew')
                . "\n" . Roles::orderText() . "\n\n" . self::usage(self::ROLE_ORDER)
        );
    }

    /**
     * "/roleorder <role> up|down|first|last": moved at once, with no Confirm
     * (easy to undo, sends nothing), answered with the new order. Alone: the
     * order. "/roleorder <role> <role> …" with every role once (as the Crew
     * app's drag and drop sends it) sets the whole order in one go.
     *
     * @return array{ok: bool, text: string, state: array<string, mixed>, ran?: bool, changed?: bool}
     */
    private function prepareRoleOrder(string $rest): array
    {
        if ('' === $rest) {
            return self::fail(self::roleOrderList());
        }

        $words = (array) preg_split('/\s+/u', $rest);
        $order = self::roleOrderSlugs($words);

        if (null !== $order) {
            $result = Roles::setOrder($order);

            if (Roles::ORDER_BAD === $result) {
                return self::fail(
                    __('Give every role once, by the name in brackets:', 'eventcrew') . "\n" . Roles::orderText()
                );
            }

            return [
                'ok' => false,
                'text' => (Roles::ORDER_SAVED === $result
                    ? __('Role order saved.', 'eventcrew')
                    : __('The roles are already in that order.', 'eventcrew'))
                    . "\n" . Roles::orderText(),
                'state' => [],
                'ran' => true,
                'changed' => Roles::ORDER_SAVED === $result,
            ];
        }

        $where = strtolower((string) array_pop($words));
        $role = self::role(implode(' ', $words));

        if (null === $role || ! in_array($where, Roles::MOVES, true)) {
            return self::fail(self::usage(self::ROLE_ORDER));
        }

        $moved = Roles::move((string) $role['slug'], $where);

        return [
            'ok' => false,
            'text' => ($moved ? __('Role order saved.', 'eventcrew') : __('The role is already there.', 'eventcrew'))
                . "\n" . Roles::orderText(),
            'state' => [],
            'ran' => true,
            'changed' => $moved,
        ];
    }

    /**
     * The do-not-send list for the bot's /optout: every address, newest
     * first. Organizers only: emails are personal data.
     */
    public function optOutList(): string
    {
        $view = $this->followupScreen->optOutView(1);
        $lines = [__('Post-event email: do not send', 'eventcrew') . ' (' . $view['total'] . ')'];

        if ([] === $view['list']) {
            $lines[] = __('No addresses on the list.', 'eventcrew');
        }

        for ($page = 1; $page <= $view['pages']; ++$page) {
            $rows = 1 === $page ? $view['list'] : $this->followupScreen->optOutView($page)['list'];

            foreach ($rows as $row) {
                $lines[] = '• ' . $row['email'] . ('' === $row['added'] ? '' : ' · ' . $row['added']);
            }
        }

        $lines[] = '';
        $lines[] = self::usage(self::OPTOUT);

        return self::clip(implode("\n", $lines));
    }

    /**
     * The coming events' AI texts for the bot's /aitext: each event, each
     * text's name and its text in each language.
     */
    public static function aiTextList(): string
    {
        $events = EventAiText::overview();

        if ([] === $events) {
            return __('No texts are written yet for the coming events.', 'eventcrew');
        }

        $lines = [__('AI texts', 'eventcrew')];

        foreach ($events as $event) {
            $lines[] = '';
            $lines[] = '📅 ' . Dates::dayLabel((string) $event['date']) . ' · ' . $event['title'];

            foreach ($event['items'] as $item) {
                $lines[] = '▪️ ' . $item['label'] . ' (' . $item['key'] . ')';

                foreach ($item['texts'] as $lang => $text) {
                    $mark = ! empty($item['edited'][$lang]) ? ' ✏️' : '';
                    $lines[] = '   ' . $lang . $mark . ': ' . ('' === $text ? '–' : mb_substr(self::oneLine($text), 0, 160));
                }
            }
        }

        $lines[] = '';
        $lines[] = self::usage(self::AI_TEXT);

        return self::clip(implode("\n", $lines));
    }

    /**
     * How to write one of these commands, with an example.
     */
    public static function usage(string $command): string
    {
        // phpcs:disable Generic.Files.LineLength.TooLong -- gettext literals; splitting one breaks extraction.
        return match ($command) {
            self::AT_RISK_ONCE_MORE => __("Let someone at risk sign up once more: /oncemore name\nExample: /oncemore Milla", 'eventcrew'),
            self::ADD_PERSON => __("Add a person: /addperson name ; email ; notes\nExample: /addperson Milla Virtanen ; milla@example.com\nThe notes are optional.", 'eventcrew'),
            self::EDIT_PERSON => __("Change someone’s details: /editperson name ; name new name ; email new address ; notes text\nExample: /editperson Milla ; notes Bar on Fridays\nGive only what changes. notes - empties the notes.", 'eventcrew'),
            self::EDIT_ROLE => __("Change a role: /editrole role ; field value ; field value\nExample: /editrole Bar ; people 3 ; guestsignup off\nFields: name, emoji, people, plus, description, measured start|end, starts minutes, ends minutes, events text, minroles, timeseach, count role number, and on|off: archived, restricted, everynight, salesdesk, guestdisplay, membersdisplay, guestsignup, anonymous, adminaccess. - empties a text or time.", 'eventcrew'),
            self::OPTOUT => __("Change the list: /optout add address, /optout remove address\nExample: /optout add milla@example.com", 'eventcrew'),
            self::SCHEDULED => __("Switch a scheduled message: /scheduled name on|off\nSend it once now: /scheduled name send\nExample: /scheduled reminders off\n/scheduled alone lists the names.", 'eventcrew'),
            self::ROLE_ORDER => __("Move a role in the order: /roleorder role up|down|first|last\nExample: /roleorder Clean first\n/roleorder alone shows the order.", 'eventcrew')
                . "\n" . __('The whole order at once: /roleorder role role role… (every role once)', 'eventcrew'),
            self::AI_TEXT => __("Change an AI text: /aitext date text-name language ; your text\nUse the AI text again: /aitext date text-name ai\nExample: /aitext 21.11. welcome fi ; Tervetuloa!", 'eventcrew'),
            default => __('That can no longer be done.', 'eventcrew'),
        };
        // phpcs:enable Generic.Files.LineLength.TooLong
    }

    /**
     * "/addperson <name> ; <email> [; <notes>]".
     *
     * @return array{ok: bool, text: string, state: array<string, mixed>}
     */
    private function prepareAdd(string $rest): array
    {
        $parts = array_map('trim', explode(';', $rest, 3));
        $name = sanitize_text_field((string) ($parts[0] ?? ''));
        $email = strtolower(sanitize_email((string) ($parts[1] ?? '')));
        $notes = sanitize_textarea_field((string) ($parts[2] ?? ''));

        if ('' === $name || ! isset($parts[1])) {
            return self::fail(self::usage(self::ADD_PERSON));
        }

        if ('' === $email || ! is_email($email)) {
            return self::fail(CrewAdminPeople::noticeText('admin_person_bad_email'));
        }

        if (null !== $this->people->findByEmail($email)) {
            return self::fail(CrewAdminPeople::noticeText('admin_person_email_taken'));
        }

        /* translators: 1: a person's name, 2: their email address. */
        $text = sprintf(__('Add %1$s (%2$s) as a new person?', 'eventcrew'), $name, $email);

        if ('' !== $notes) {
            $text .= "\n" . self::personFieldLabel('notes') . ': ' . $notes;
        }

        return self::ask($text, ['op' => self::ADD_PERSON, 'name' => $name, 'email' => $email, 'notes' => $notes]);
    }

    /**
     * "/editperson <person> ; name <new> ; email <new> ; notes <text|->".
     *
     * @return array{ok: bool, text: string, state: array<string, mixed>, choices?: list<array{label: string, state: array<string, mixed>}>}
     */
    private function prepareEdit(Person $organizer, string $rest): array
    {
        $parts = array_map('trim', explode(';', $rest));
        $query = (string) array_shift($parts);
        $state = ['op' => self::EDIT_PERSON];

        foreach ($parts as $part) {
            [$field, $value] = array_pad(preg_split('/\s+/u', $part, 2) ?: [], 2, '');
            $field = strtolower((string) $field);
            $value = trim((string) $value);

            if (! in_array($field, self::PERSON_FIELDS, true)) {
                return self::fail(self::usage(self::EDIT_PERSON));
            }

            if ('notes' === $field) {
                $state['notes'] = '-' === $value ? '' : sanitize_textarea_field($value);
            } elseif ('email' === $field) {
                $email = strtolower(sanitize_email($value));

                if ('' === $email || ! is_email($email)) {
                    return self::fail(CrewAdminPeople::noticeText('admin_person_bad_email'));
                }

                $state['email'] = $email;
            } else {
                $name = sanitize_text_field($value);

                if ('' === $name) {
                    return self::fail(self::usage(self::EDIT_PERSON));
                }

                $state['name'] = $name;
            }
        }

        if ('' === $query || 1 === count($state)) {
            return self::fail(self::usage(self::EDIT_PERSON));
        }

        return $this->person($organizer, $query, $state);
    }

    /**
     * "/editrole <role> ; <field> <value> ; …": the role and the checked
     * changes, asked as a list.
     *
     * @return array{ok: bool, text: string, state: array<string, mixed>}
     */
    private static function prepareRole(string $rest): array
    {
        $parts = array_map('trim', explode(';', $rest));
        $role = self::role((string) array_shift($parts));

        if ('' === $rest || [] === $parts) {
            return self::fail(self::usage(self::EDIT_ROLE));
        }

        if (null === $role) {
            return self::fail(CrewAdminTasks::noticeText('admin_role_gone'));
        }

        $changes = [];
        $lines = [];

        foreach ($parts as $part) {
            $checked = self::roleChange($part, $role['slug']);

            if (null === $checked) {
                /* translators: %1$s: what the organizer wrote. */
                return self::fail(sprintf(__('“%1$s” could not be read.', 'eventcrew'), $part) . "\n\n" . self::usage(self::EDIT_ROLE));
            }

            [$key, $value, $shown] = $checked;

            if ('role_count' === $key) {
                $changes['role_count'] = ((array) ($changes['role_count'] ?? [])) + $value;
            } else {
                $changes[$key] = $value;
            }

            $lines[] = '• ' . $shown;
        }

        return self::ask(
            /* translators: %s: a role name. */
            sprintf(__('Change the role %s?', 'eventcrew'), Roles::display($role['slug'])) . "\n" . implode("\n", $lines),
            ['op' => self::EDIT_ROLE, 'role' => $role['slug'], 'changes' => $changes]
        );
    }

    /**
     * One "<field> <value>" of /editrole: the form field, its value and the
     * line to show, or null when it cannot be read.
     *
     * @return array{0: string, 1: mixed, 2: string}|null
     */
    private static function roleChange(string $part, string $slug): ?array
    {
        [$field, $value] = array_pad(preg_split('/\s+/u', trim($part), 2) ?: [], 2, '');
        $field = strtolower((string) $field);
        $value = trim((string) $value);
        $lower = strtolower($value);

        if (isset(self::FLAGS[$field])) {
            return in_array($lower, ['on', 'off'], true) ? [self::FLAGS[$field], 'on' === $lower, $field . ' ' . $lower] : null;
        }

        if (! isset(self::FIELDS[$field])) {
            return null;
        }

        $shown = $field . ' ' . ('' === $value ? '–' : $value);

        switch ($field) {
            case 'name':
                return '' === $value ? null : [self::FIELDS[$field], $value, $shown];

            case 'emoji':
            case 'description':
            case 'events':
                return [self::FIELDS[$field], '-' === $value ? '' : $value, $shown];

            case 'people':
            case 'minroles':
            case 'timeseach':
                return ctype_digit($value) && strlen($value) <= 4 ? [self::FIELDS[$field], (int) $value, $shown] : null;

            case 'plus':
                return ctype_digit($value) && (int) $value <= self::MAX_PLUS_ONES ? [self::FIELDS[$field], (int) $value, $shown] : null;

            case 'measured':
                return in_array($lower, [Roles::ANCHOR_START, Roles::ANCHOR_END], true) ? [self::FIELDS[$field], $lower, $shown] : null;

            case 'starts':
            case 'ends':
                if ('-' === $value) {
                    return [self::FIELDS[$field], '', $shown];
                }

                return 1 === preg_match('/^[-+]?\d{1,4}$/', $value) ? [self::FIELDS[$field], (string) (int) $value, $shown] : null;

            case 'count':
                if (1 !== preg_match('/^(.+?)\s+(\d{1,4})$/u', $value, $m)) {
                    return null;
                }

                $other = self::role($m[1]);

                if (null === $other || $other['slug'] === $slug) {
                    return null;
                }

                return [self::FIELDS[$field], [$other['slug'] => (int) $m[2]], 'count ' . Roles::display($other['slug']) . ' ' . (int) $m[2]];
        }

        return null;
    }

    /**
     * The Roles screen's form for $slug as it stands, with $changes over it,
     * as CrewAdminTasks::saveRole() reads it; null when the role is gone.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>|null
     */
    private static function rolePost(string $slug, array $changes): ?array
    {
        $role = null;

        foreach (Roles::all() as $candidate) {
            if ($candidate['slug'] === $slug) {
                $role = $candidate;
            }
        }

        if (null === $role) {
            return null;
        }

        $conditions = (array) ($role['conditions'] ?? []);
        $post = [
            'role' => $slug,
            'role_label' => (string) $role['label'],
            'role_emoji' => (string) $role['emoji'],
            'role_capacity' => (string) $role['capacity'],
            'role_title_contains' => (string) ($role['title_contains'] ?? ''),
            'role_plus_ones' => (string) ($role['plus_ones'] ?? 0),
            'role_count' => array_map('intval', (array) ($conditions['counts'] ?? [])),
            'role_min_roles' => (string) ($conditions['min_roles'] ?? 0),
            'role_times_each' => (string) ($conditions['times_each'] ?? 0),
            'role_anchor' => (string) ($role['anchor'] ?? Roles::ANCHOR_START),
            'role_start_offset' => null === ($role['start_offset'] ?? null) ? '' : (string) $role['start_offset'],
            'role_end_offset' => null === ($role['end_offset'] ?? null) ? '' : (string) $role['end_offset'],
            'role_description' => (string) ($role['description'] ?? ''),
        ];

        $flags = [
            'role_archived' => (bool) ($role['archived'] ?? false),
            'role_restricted' => (bool) ($role['restricted'] ?? false),
            'role_every_night' => (bool) ($role['every_night'] ?? false),
            'role_sales_desk' => (bool) ($role['sales_desk'] ?? false),
            'role_on_board' => (bool) ($role['on_board'] ?? true),
            'role_guest_signup' => (bool) ($role['guest_signup'] ?? true),
            'role_anonymous' => (bool) ($role['anonymous'] ?? false),
            'role_members_display' => (bool) ($role['members_display'] ?? false),
            'role_admin_access' => (bool) ($role['admin_access'] ?? false),
        ];

        foreach ($changes as $key => $value) {
            if (array_key_exists($key, $flags)) {
                $flags[$key] = (bool) $value;
            } elseif ('role_count' === $key) {
                $post['role_count'] = array_filter(((array) $value) + $post['role_count'], static fn (int $n): bool => $n > 0);
            } else {
                $post[$key] = is_int($value) ? (string) $value : $value;
            }
        }

        foreach ($flags as $key => $on) {
            if ($on) {
                $post[$key] = '1';
            }
        }

        return $post;
    }

    /**
     * "/optout add|remove <email>".
     *
     * @return array{ok: bool, text: string, state: array<string, mixed>}
     */
    private function prepareOptOut(string $rest): array
    {
        [$word, $email] = array_pad(preg_split('/\s+/u', $rest, 2) ?: [], 2, '');
        $word = strtolower((string) $word);
        $email = strtolower(trim((string) $email));

        if (! in_array($word, ['add', 'remove'], true) || '' === $email || str_contains($email, ' ')) {
            return self::fail(self::usage(self::OPTOUT));
        }

        if (! is_email($email)) {
            return self::fail(CrewAdminFollowup::noticeText('admin_optout_bad_email'));
        }

        $listed = $this->followupScreen->optOutListed($email);

        if ('add' === $word) {
            if ($listed) {
                return self::fail(CrewAdminFollowup::noticeText('admin_optout_already'));
            }

            /* translators: %s: an email address. */
            $text = sprintf(__('Put %s on the do-not-send list? That address gets no post-event email.', 'eventcrew'), $email);
        } else {
            if (! $listed) {
                return self::fail(CrewAdminFollowup::noticeText('admin_optout_not_listed'));
            }

            /* translators: %s: an email address */
            $text = sprintf(__('Remove %s from the list? The post-event email can go to it again.', 'eventcrew'), $email);
        }

        return self::ask($text, ['op' => self::OPTOUT, 'do' => $word, 'email' => $email]);
    }

    /**
     * "/aitext <date> <text name> <language> ; <text>" or
     * "/aitext <date> <text name> ai".
     *
     * @return array{ok: bool, text: string, state: array<string, mixed>}
     */
    private static function prepareAiText(string $rest): array
    {
        $sides = explode(';', $rest, 2);
        $words = preg_split('/\s+/u', trim($sides[0])) ?: [];
        $text = trim((string) ($sides[1] ?? ''));

        if (count($words) < 3) {
            return self::fail(self::usage(self::AI_TEXT));
        }

        $date = Dates::parseUserDate((string) array_shift($words), (string) current_time('Y-m-d'));
        $last = strtolower((string) array_pop($words));
        $name = trim(implode(' ', $words));
        $back = 'ai' === $last;

        if ('' === $date || '' === $name || ($back && '' !== $text) || (! $back && '' === $text)) {
            return self::fail(self::usage(self::AI_TEXT));
        }

        if (! $back && ! in_array($last, TranslatedText::languages(), true)) {
            /* translators: %s: language codes, comma separated. */
            return self::fail(sprintf(__('Choose one of these languages: %s.', 'eventcrew'), implode(', ', TranslatedText::languages())));
        }

        $events = array_values(array_filter(EventAiText::overview(), static fn (array $e): bool => $e['date'] === $date));

        if ([] === $events) {
            /* translators: %s: a date. */
            return self::fail(sprintf(__('No coming event on %s has AI texts.', 'eventcrew'), Dates::dayLabel($date)));
        }

        $found = [];
        $names = [];
        $wanted = mb_strtolower(trim($name, '{} '));

        foreach ($events as $event) {
            foreach ($event['items'] as $item) {
                $names[] = (string) $item['key'];
                $label = mb_strtolower(trim((string) $item['label'], '{} '));
                $key = mb_strtolower((string) $item['key']);

                if (in_array($wanted, [$label, $key, mb_strtolower(str_replace(EventAiText::PLACEHOLDER_PREFIX, '', $key))], true)) {
                    $found[] = ['event' => $event, 'item' => $item];
                }
            }
        }

        if ([] === $found) {
            /* translators: 1: the text name the organizer typed, 2: the text names, comma separated. */
            return self::fail(sprintf(__('No AI text called “%1$s”. The texts: %2$s.', 'eventcrew'), $name, implode(', ', array_unique($names))));
        }

        if (count($found) > 1) {
            return self::fail(AssistantWebChanges::several(array_map(
                static fn (array $f): string => $f['event']['title'] . ' · ' . $f['item']['label'],
                $found
            )));
        }

        $event = $found[0]['event'];
        $item = $found[0]['item'];
        $where = '“' . $item['label'] . '” · ' . Dates::dayLabel($date) . ' · ' . $event['title'];
        $state = ['op' => self::AI_TEXT, 'event' => (int) $event['event_id'], 'key' => (string) $item['key']];

        if ($back) {
            /* translators: %s: the text's name, date and event. */
            return self::ask(sprintf(__('Use the AI text again for %s?', 'eventcrew'), $where), $state + ['ai' => true]);
        }

        $text = mb_substr(sanitize_textarea_field($text), 0, EventAiText::MAX_TEXT);

        return self::ask(
            /* translators: 1: the text's name, date and event, 2: a language code. */
            sprintf(__('Send this text for %1$s (%2$s), as you wrote it?', 'eventcrew'), $where, $last) . "\n\n" . $text,
            $state + ['lang' => $last, 'text' => $text]
        );
    }

    /**
     * The person $query names among the members: the question, the
     * choices, or why not.
     *
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, state: array<string, mixed>, choices?: list<array{label: string, state: array<string, mixed>}>}
     */
    private function person(Person $organizer, string $query, array $state): array
    {
        $members = array_values(array_filter(
            $this->people->all(['per_page' => 2000, 'orderby' => 'display_name']),
            static fn (Person $p): bool => ! $p->isAnonymized()
        ));
        $found = AssignCommand::matchPeople($query, $members);

        if ([] === $found) {
            /* translators: %s: the name the organizer typed. */
            return self::fail(sprintf(__('No member called “%s” was found.', 'eventcrew'), $query));
        }

        if (1 === count($found)) {
            return $this->choose($organizer, ['person' => $found[0]->id] + $state);
        }

        $options = CommandFlow::personOptions($found);

        if (count($found) > WebChanges::MAX_CHOICES) {
            return self::fail(AssistantWebChanges::several(array_values($options)));
        }

        $choices = [];

        foreach ($options as $id => $label) {
            $choices[] = ['label' => $label, 'state' => ['person' => $id] + $state];
        }

        return ['ok' => false, 'text' => __('Which member?', 'eventcrew'), 'state' => [], 'choices' => $choices];
    }

    /**
     * The role $query names, by name or slug, archived ones too.
     *
     * @return array<string, mixed>|null
     */
    /**
     * The words of "/roleorder <role> <role> …" as role slugs, when they are
     * a whole order: two or more words, none of them a move (up, down…),
     * each one role. Null for anything else, which is then read as one move.
     * Roles::setOrder() checks that every role is there exactly once.
     *
     * @param array<int, mixed> $words
     * @return list<string>|null
     */
    public static function roleOrderSlugs(array $words): ?array
    {
        if (count($words) < 2 || in_array(strtolower((string) end($words)), Roles::MOVES, true)) {
            return null;
        }

        $slugs = [];

        foreach ($words as $word) {
            $role = self::role((string) $word);

            if (null === $role) {
                return null;
            }

            $slugs[] = (string) $role['slug'];
        }

        return $slugs;
    }

    private static function role(string $query): ?array
    {
        $query = mb_strtolower(trim($query));

        if ('' === $query) {
            return null;
        }

        foreach (Roles::all() as $role) {
            $names = [mb_strtolower((string) $role['label']), mb_strtolower((string) $role['slug']), mb_strtolower(Roles::display($role['slug']))];

            if (in_array($query, $names, true)) {
                return $role;
            }
        }

        return null;
    }

    private static function personFieldLabel(string $field): string
    {
        return match ($field) {
            'name' => __('Name', 'eventcrew'),
            'email' => __('Email', 'eventcrew'),
            default => __('Notes', 'eventcrew'),
        };
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function clip(string $text): string
    {
        return mb_strlen($text) > self::MAX_MESSAGE ? mb_substr($text, 0, self::MAX_MESSAGE - 1) . '…' : $text;
    }

    /**
     * @param array<string, mixed> $state
     * @return array{ok: bool, text: string, state: array<string, mixed>}
     */
    private static function ask(string $text, array $state): array
    {
        return ['ok' => true, 'text' => $text, 'state' => $state];
    }

    /** @return array{ok: bool, text: string, state: array<string, mixed>} */
    private static function fail(string $text): array
    {
        return ['ok' => false, 'text' => $text, 'state' => []];
    }
}

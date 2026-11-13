<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The parity map (Owner 2026-11-14): everything a person can do in the Crew
 * app must also be possible in the Telegram bot and through the helper.
 *
 * One row per thing a person does in the app: where it is, which web action
 * (a *_ACTION constant of a src/Web controller, with its "op" when one
 * action does several things) runs it, who does it, whether it changes
 * something, the bot's way to do it, and the helper's action line for it
 * (AssistantActions). ActionParityTest checks every row against the real
 * code: the bot command or button exists, the helper line passes
 * AssistantActions::validate() for that person, and on the Crew app page
 * AssistantWebActions::plan() runs a change on Confirm (not only a link).
 * It also fails for a web action constant that is in neither actions() nor
 * EXCLUDED, so a new app feature cannot skip the bot and the helper.
 *
 * A bot entry is a command ("/door"), a MenuButtons button ("mn:gift"), or
 * a callback prefix constant ("TransferBot::PREFIX", in EventCrew\Telegram
 * or EventCrew\Support). An empty bot list or a null helper line is a gap;
 * the test lists the known ones.
 *
 * Not used at run time: the map is for the test and the parity audit.
 */
final class ActionParity
{
    /** A signed-in crew member. */
    public const MEMBER = 'member';

    /** Someone on a night's sales-desk task who is not an organizer. */
    public const STAFF = 'staff';

    /** An organizer. */
    public const ORGANIZER = 'organizer';

    /**
     * Web action constants deliberately without a bot or helper twin.
     *
     * @var array<string, string> "Class::CONST" => why
     */
    public const EXCLUDED = [
        'SignupController::LOGIN_ACTION' => 'sign-in by email link: the bot has its own sign-up (/start)',
        'SignupController::CODE_ACTION' => 'the sign-in email\'s code typed on the page: email verification',
        'SignupController::TG_START_ACTION' => 'sign-in with Telegram: the bot is where the code is approved',
        'SignupController::TG_STATUS_ACTION' => 'the sign-in page polling for that approval',
        'SignupController::LOGOUT_ACTION' => 'ends this browser\'s web session; a chat has no session to end',
        'SignupController::JOIN_ACTION' => 'the newcomer questions of sign-up; the bot asks them in /start',
        'PushController::ACTION' => 'app notifications for this browser or phone (Web Push): a device setting',
        'AppInstallController::ACTION' => 'the "Get the app" pop-in of the browser',
        'AssistantWebController::ACTION' => 'the helper itself',
        'GroupChatController::ACTION' => 'the Group chat tab mirrors the Telegram group, which is the bot side',
        'NewsletterController::JOIN_ACTION' => 'the public newsletter form, not the Crew app',
        'NewsletterController::NAME_ACTION' => 'the public newsletter form\'s second step',
        'CrewAdminController::TEXT_STATUS_ACTION' => 'the page polling for a translation, not a person\'s action',
        'DoorController::SEARCH_ACTION' => 'a lookup the sales desk makes before another action',
        'DoorController::TASKS_ACTION' => 'a lookup the sales desk makes before "works tonight"',
        'DoorController::SCAN_ACTION' => 'a QR-code lookup; the check-in itself is MARK_ACTION',
        'DoorController::TICKET_CODE_ACTION' => 'a ticket-code lookup; the check-in itself is MARK_ACTION',
        'DoorController::HANDOFF_ACTION' => 'hands the sales desk to another browser: a device action',
    ];

    /**
     * The app's actions and views and their bot and helper twins.
     *
     * @return array<string, array{place: string, source: ?string, op: string, audience: string, change: bool, bot: list<string>, helper: ?string}>
     */
    public static function actions(): array
    {
        return [
            // Member: Board, Tasks, Tickets and Settings tabs.
            'board' => self::view('Board tab', ['/board'], '/board'),
            'my_tasks' => self::view('Tasks tab: my coming tasks', ['/tasks', 'mn:mytasks'], '/tasks'),
            'my_history' => self::view('Tasks tab: past tasks', ['/me', 'mn:hist'], '/me history'),
            'my_info' => self::view('Tasks tab: credits and standing', ['/me', 'mn:me'], '/me'),
            'my_tickets' => self::view('Tickets tab', ['/tickets', 'mn:tix'], '/tickets'),
            'settings' => self::view('Settings tab', ['/settings', 'mn:set'], '/settings'),
            'help' => self::view('Help: how this works, commands, FAQ', ['/help', 'mn:help', 'mn:faq'], '/help'),
            'claim' => self::change(
                'Board tab: Sign up',
                'SignupController::CLAIM_ACTION',
                self::MEMBER,
                ['/board', 'RoleTaskBot::PREFIX'],
                '/join 12'
            ),
            'drop' => self::change(
                'Tasks tab: Cancel a task',
                'SignupController::DROP_ACTION',
                self::MEMBER,
                ['/tasks', '/board'],
                '/leave 12'
            ),
            'takeover' => self::change(
                'Board tab: Take over a task',
                'SignupController::TAKEOVER_ACTION',
                self::MEMBER,
                ['mn:replace', 'ReplacementService::TASK_PREFIX'],
                'menu:replace'
            ),
            'redeem' => self::change(
                'Tickets tab: Get my free-entry ticket',
                'SignupController::REDEEM_ACTION',
                self::MEMBER,
                ['mn:ticket'],
                'menu:ticket 21.11.'
            ),
            'give_ticket' => self::change(
                'Tickets tab: Give away a ticket',
                'SignupController::GIVE_ACTION',
                self::MEMBER,
                ['TransferBot::PREFIX'],
                null
            ),
            'cancel_offer' => self::change(
                'Tickets tab: Cancel offer',
                'SignupController::CANCEL_OFFER_ACTION',
                self::MEMBER,
                ['TransferBot::PREFIX'],
                null
            ),
            'profile_name' => self::change(
                'Settings tab: change my name',
                'SignupController::PROFILE_ACTION',
                self::MEMBER,
                ['/name', 'mn:name'],
                '/name Anna Example',
                'name'
            ),
            'profile_email' => self::change(
                'Settings tab: change my email',
                'SignupController::PROFILE_ACTION',
                self::MEMBER,
                ['/email', 'mn:email'],
                '/email anna@example.com',
                'email'
            ),
            'notifications' => self::change(
                'Settings tab: Notifications',
                'SignupController::PREFS_ACTION',
                self::MEMBER,
                ['mn:notif'],
                'menu:notifications'
            ),
            'channel_choice' => self::change(
                'Settings tab: "Where do you want them?" (app, Telegram, both)',
                'ChannelChoiceController::ACTION',
                self::MEMBER,
                ['NotificationSettingsService::CHOICE_PREFIX'],
                'menu:notifications',
                'choose'
            ),
            'language' => self::change(
                'Settings tab: Language',
                'SignupController::LANGUAGE_ACTION',
                self::MEMBER,
                ['mn:lang', 'LanguageService::PREFIX'],
                'menu:language fi'
            ),
            'sign_out_everywhere' => self::change(
                'Settings tab: Sign out everywhere',
                'SignupController::REVOKE_ACTION',
                self::MEMBER,
                ['mn:signout'],
                null
            ),
            'sales_link' => self::change(
                'Top of the page: sales desk link (sales-desk crew)',
                'SignupController::SALES_ACTION',
                self::STAFF,
                ['mn:sales'],
                'menu:sales'
            ),

            // Organizer: Admin tab, Roster screen.
            'admin_roster' => self::view('Admin > Roster', ['/roster', 'mn:roster'], '/roster 21.11.', self::ORGANIZER),
            'mark' => self::change(
                'Admin > Roster: mark one person',
                'CrewAdminController::MARK_ACTION',
                self::ORGANIZER,
                ['/mark', 'RosterService::MARK_PREFIX'],
                '/mark 21.11. Anna ; arrived'
            ),
            'roster_save' => self::change(
                'Admin > Roster: Update attendance',
                'CrewAdminController::ROSTER_ACTION',
                self::ORGANIZER,
                ['/mark', 'RosterService::MARK_PREFIX'],
                '/mark 21.11. Anna ; done',
                'save'
            ),
            'roster_mark_all' => self::change(
                'Admin > Roster: mark everyone on a task',
                'CrewAdminController::ROSTER_ACTION',
                self::ORGANIZER,
                [],
                null,
                'mark_all'
            ),
            'add_to_task' => self::change(
                'Admin > Roster: Add a person to a task',
                'CrewAdminController::ADD_ACTION',
                self::ORGANIZER,
                ['/assign', 'RoleTaskBot::PREFIX'],
                '/assign 21.11. Host ; Anna'
            ),

            // Organizer: Admin tab, Door screen.
            'admin_door' => self::view('Admin > Door', ['/door', 'mn:door'], '/door 21.11.', self::ORGANIZER),
            'admin_door_list' => self::view(
                'Admin > Door: door list',
                ['MenuButtons::DOOR_LIST'],
                'menu:doorlist 21.11.',
                self::ORGANIZER
            ),
            'admin_guest_list' => self::view(
                'Admin > Door: guest list',
                ['MenuButtons::GUEST_LIST'],
                'menu:guestlist 21.11.',
                self::ORGANIZER
            ),
            'sales_link_organizer' => self::change(
                'Admin > Door: Send me the sales desk link',
                'CrewAdminController::SALES_ACTION',
                self::ORGANIZER,
                ['mn:sales'],
                'menu:sales'
            ),
            'free_entry' => self::change(
                'Admin > Door: close or reopen free entry',
                'CrewAdminController::DOOR_ACTION',
                self::ORGANIZER,
                ['/freeentry'],
                '/freeentry 21.11. close',
                'toggle'
            ),
            'unredeem' => self::change(
                'Admin > Door: cancel a free-entry ticket',
                'CrewAdminController::DOOR_ACTION',
                self::ORGANIZER,
                ['/unticket'],
                '/unticket 21.11. Anna',
                'unredeem'
            ),
            'guest_add' => self::change(
                'Admin > Door: add a guest',
                'CrewAdminController::GUEST_ACTION',
                self::ORGANIZER,
                ['/door'],
                '/door add 21.11. Anna +1',
                'save'
            ),
            'guest_edit' => self::change(
                'Admin > Door: edit a guest',
                'CrewAdminController::GUEST_ACTION',
                self::ORGANIZER,
                ['/door'],
                '/door edit 21.11. 2 Anna +2',
                'save'
            ),
            'guest_remove' => self::change(
                'Admin > Door: remove a guest',
                'CrewAdminController::GUEST_ACTION',
                self::ORGANIZER,
                ['/door'],
                '/door remove 21.11. 2',
                'remove'
            ),
            'guest_tickets' => self::change(
                'Admin > Door: send the guests\' ticket emails',
                'CrewAdminController::GUEST_ACTION',
                self::ORGANIZER,
                ['/sendtickets'],
                '/sendtickets 21.11.',
                'mail_rest'
            ),
            'buyers_import' => self::change(
                'Admin > Door: paste Holvi ticket buyers',
                'CrewAdminController::BUYERS_ACTION',
                self::ORGANIZER,
                [],
                null,
                'import'
            ),
            'buyers_upload' => self::change(
                'Admin > Door: upload the Holvi ticket file',
                'CrewAdminController::BUYERS_ACTION',
                self::ORGANIZER,
                [],
                null,
                'upload'
            ),
            'buyers_reset' => self::change(
                'Admin > Door: Reset import',
                'CrewAdminController::BUYERS_ACTION',
                self::ORGANIZER,
                ['BuyerListReset::PREFIX'],
                null,
                'reset'
            ),
            'buyers_mail_test' => self::change(
                'Admin > Door: send me a test ticket email',
                'CrewAdminController::BUYERS_ACTION',
                self::ORGANIZER,
                [],
                null,
                'mail_test'
            ),
            'buyers_tickets' => self::change(
                'Admin > Door: send the buyers\' ticket emails',
                'CrewAdminController::BUYERS_ACTION',
                self::ORGANIZER,
                ['/sendtickets'],
                '/sendtickets 21.11.',
                'mail_rest'
            ),
            'tickets_cancel' => self::change(
                'Admin > Door: cancel the waiting ticket send',
                'CrewAdminController::BUYERS_ACTION',
                self::ORGANIZER,
                ['/sendtickets'],
                '/sendtickets cancel',
                'mail_cancel'
            ),
            'check_mail' => self::change(
                'Admin > Door: Check the mailbox now',
                'CrewAdminController::MAIL_ACTION',
                self::ORGANIZER,
                [],
                null
            ),
            'followup_test' => self::change(
                'Admin > Door: send me a test post-event email',
                'CrewAdminController::FOLLOWUP_ACTION',
                self::ORGANIZER,
                [],
                null,
                'followup_test'
            ),
            'followup_stop' => self::change(
                'Admin > Door: do not send the post-event email',
                'CrewAdminController::FOLLOWUP_ACTION',
                self::ORGANIZER,
                ['/stop'],
                '/stop email 21.11.',
                'followup_stop'
            ),

            // Organizer: Admin tab, People screen.
            'admin_people' => self::view('Admin > People', ['/people', 'mn:people'], '/people', self::ORGANIZER),
            'admin_standing' => self::view('Admin > People: one person', ['/standing'], '/standing Anna', self::ORGANIZER),
            'person_edit' => self::change(
                'Admin > People: add or edit a person (name, email, notes)',
                'CrewAdminController::PERSON_ACTION',
                self::ORGANIZER,
                ['/editperson', '/addperson'],
                '/editperson Anna ; notes Bar on Fridays'
            ),
            'roles' => self::change(
                'Admin > People: a member\'s roles',
                'CrewAdminController::ROLES_ACTION',
                self::ORGANIZER,
                ['mn:roles', 'PermissionService::PICK_PREFIX', '/role'],
                'menu:roles add Crew leader ; Anna'
            ),
            'gift' => self::change(
                'Admin > People: Give a credit',
                'CrewAdminController::GIFT_ACTION',
                self::ORGANIZER,
                ['mn:gift', 'GiftService::PICK_PREFIX', '/gift'],
                'menu:gift Anna ; 1'
            ),
            'pass' => self::change(
                'Admin > People: one-time pass',
                'CrewAdminController::PASS_ACTION',
                self::ORGANIZER,
                ['mn:roles', 'PermissionService::PICK_PREFIX', '/atriskpass'],
                '/atriskpass Anna'
            ),
            'organizer' => self::change(
                'Admin > People: make or remove an organizer',
                'CrewAdminController::ORGANIZER_ACTION',
                self::ORGANIZER,
                ['mn:roles', 'PermissionService::PICK_PREFIX', '/organizer'],
                '/organizer on Anna'
            ),
            'unban' => self::change(
                'Admin > People: Unban',
                'CrewAdminController::UNBAN_ACTION',
                self::ORGANIZER,
                ['/unban'],
                '/unban Anna'
            ),
            'person_remove' => self::change(
                'Admin > People: Delete a person',
                'CrewAdminController::PERSON_REMOVE_ACTION',
                self::ORGANIZER,
                ['/deleteperson'],
                '/deleteperson Anna'
            ),
            'admin_newcomers' => self::view('Admin > Newcomers', ['/newcomer'], '/newcomer', self::ORGANIZER),
            'newcomer' => self::change(
                'Admin > Newcomers: OK or No',
                'CrewAdminController::NEWCOMER_ACTION',
                self::ORGANIZER,
                ['/newcomer', 'NewcomerApproval::PREFIX'],
                '/newcomer ok Anna'
            ),

            // Organizer: Admin tab, Tasks screen.
            'admin_tasks' => self::view('Admin > Tasks', ['/board'], '/board', self::ORGANIZER),
            'task_add' => self::change(
                'Admin > Tasks: add a task',
                'CrewAdminController::TASK_ACTION',
                self::ORGANIZER,
                ['/addtask'],
                '/addtask 21.11. Host ; places 2'
            ),
            'task_edit' => self::change(
                'Admin > Tasks: edit a task',
                'CrewAdminController::TASK_ACTION',
                self::ORGANIZER,
                ['/edittask'],
                '/edittask 21.11. Host ; places 3'
            ),
            'task_delete' => self::change(
                'Admin > Tasks: Delete a task',
                'CrewAdminController::TASK_DELETE_ACTION',
                self::ORGANIZER,
                ['/deletetask'],
                '/deletetask 21.11. Host'
            ),
            'make_tasks' => self::change(
                'Admin > Tasks: create an event\'s tasks',
                'CrewAdminController::TEMPLATE_ACTION',
                self::ORGANIZER,
                ['/maketasks'],
                '/maketasks 21.11.'
            ),
            'admin_roles' => self::view('Admin > Tasks: who may hold each role', ['mn:elig'], 'menu:eligible', self::ORGANIZER),
            'role_edit' => self::change(
                'Admin > Tasks: Edit a role',
                'CrewAdminController::ROLE_ACTION',
                self::ORGANIZER,
                ['/editrole'],
                '/editrole Bar ; people 3'
            ),
            'new_board' => self::change(
                'Admin > Tasks: Post a new board in the group',
                'CrewAdminController::BOARD_ACTION',
                self::ORGANIZER,
                ['/newboard'],
                '/newboard'
            ),
            'open_task_mail' => self::change(
                'Admin > Tasks: Send open-task email now',
                'CrewAdminController::OPEN_TASK_ACTION',
                self::ORGANIZER,
                ['/mailtasks'],
                '/mailtasks'
            ),

            // Organizer: Admin tab, Messages screen.
            'admin_newsletter' => self::view('Admin > Messages: announcement', ['/newsletter'], '/newsletter', self::ORGANIZER),
            'newsletter_text' => self::change(
                'Admin > Messages: text for the next announcement',
                'CrewAdminController::NEWSLETTER_TEXT_ACTION',
                self::ORGANIZER,
                ['/newsletter'],
                '/newsletter Come dance with us',
                'save'
            ),
            'newsletter_reset' => self::change(
                'Admin > Messages: clear the announcement text',
                'CrewAdminController::NEWSLETTER_TEXT_ACTION',
                self::ORGANIZER,
                ['/newsletter'],
                '/newsletter reset',
                'clear'
            ),
            'newsletter_stop' => self::change(
                'Admin > Messages: do not send this announcement',
                'CrewAdminController::NEWSLETTER_STOP_ACTION',
                self::ORGANIZER,
                ['/stop'],
                '/stop announcement'
            ),
            'admin_followup' => self::view('Admin > Messages: post-event email', ['/followup'], '/followup', self::ORGANIZER),
            'followup_text' => self::change(
                'Admin > Messages: text for the next post-event email',
                'CrewAdminController::FOLLOWUP_TEXT_ACTION',
                self::ORGANIZER,
                ['/followup'],
                '/followup came Thanks for coming',
                'save'
            ),
            'followup_reset' => self::change(
                'Admin > Messages: clear the post-event email text',
                'CrewAdminController::FOLLOWUP_TEXT_ACTION',
                self::ORGANIZER,
                ['/followup'],
                '/followup came reset',
                'clear'
            ),
            'optout' => self::change(
                'Admin > Messages: post-event email do-not-send list',
                'CrewAdminController::OPTOUT_ACTION',
                self::ORGANIZER,
                ['/optout'],
                '/optout add anna@example.com'
            ),
            'ai_text' => self::change(
                'Admin > Messages: change an AI text for an event',
                'CrewAdminController::AI_TEXT_ACTION',
                self::ORGANIZER,
                ['/aitext'],
                '/aitext 21.11. welcome fi ; Tervetuloa'
            ),

            // The sales desk (door page), for the night's sales-desk crew.
            'desk_view' => self::view(
                'Sales desk: the night\'s list',
                ['MenuButtons::DOOR_LIST'],
                'menu:doorlist 21.11.',
                self::STAFF,
                'DoorController::STATE_ACTION'
            ),
            'desk_mark' => self::change('Sales desk: check someone in or out', 'DoorController::MARK_ACTION', self::STAFF, [], null),
            'desk_plus' => self::change('Sales desk: +1s that came', 'DoorController::PLUS_ACTION', self::STAFF, [], null),
            'desk_give' => self::change(
                'Sales desk: spend a member\'s credit on a ticket',
                'DoorController::GIVE_ACTION',
                self::STAFF,
                [],
                '/ticket 21.11. Anna'
            ),
            'desk_sale' => self::change('Sales desk: door ticket sold', 'DoorController::TICKET_ACTION', self::STAFF, [], null),
            'desk_holvi' => self::change('Sales desk: add a Holvi buyer', 'DoorController::HOLVI_ACTION', self::STAFF, [], null),
            'desk_count' => self::change('Sales desk: count someone in', 'DoorController::COUNT_ACTION', self::STAFF, [], null),
            'desk_guest' => self::change(
                'Sales desk: add a guest',
                'DoorController::GUEST_ACTION',
                self::STAFF,
                [],
                '/door add 21.11. Anna'
            ),
            'desk_note' => self::change(
                'Sales desk: name and email of who came in',
                'DoorController::NOTE_ACTION',
                self::STAFF,
                [],
                null
            ),
            'desk_note_remove' => self::change(
                'Sales desk: remove that note',
                'DoorController::NOTE_REMOVE_ACTION',
                self::STAFF,
                [],
                null
            ),
            'desk_work' => self::change(
                'Sales desk: works tonight (put a member on a task)',
                'DoorController::WORK_ACTION',
                self::STAFF,
                [],
                '/assign 21.11. Host ; Anna'
            ),
            'desk_new_member' => self::change(
                'Sales desk: new member',
                'DoorController::NEW_MEMBER_ACTION',
                self::STAFF,
                [],
                null
            ),
            'desk_cancel_send' => self::change(
                'Sales desk: cancel the waiting ticket send',
                'DoorController::CANCEL_SEND_ACTION',
                self::ORGANIZER,
                ['/sendtickets'],
                '/sendtickets cancel'
            ),
            'desk_free_entry' => self::change(
                'Sales desk, Organizer: close or reopen free entry',
                'DoorController::ORGANIZER_ACTION',
                self::ORGANIZER,
                ['/freeentry'],
                '/freeentry 21.11. open',
                'free_entry'
            ),
            'desk_send_tickets' => self::change(
                'Sales desk, Organizer: send the ticket emails',
                'DoorController::ORGANIZER_ACTION',
                self::ORGANIZER,
                ['/sendtickets'],
                '/sendtickets 21.11.',
                'mail_buyers'
            ),
            'desk_file' => self::change(
                'Sales desk, Organizer: upload the Holvi ticket file',
                'DoorController::FILE_ACTION',
                self::ORGANIZER,
                [],
                null
            ),
        ];
    }

    /**
     * A view: something the app shows. The helper counts when it shows or
     * opens it.
     *
     * @param list<string> $bot
     * @return array{place: string, source: ?string, op: string, audience: string, change: bool, bot: list<string>, helper: ?string}
     */
    private static function view(
        string $place,
        array $bot,
        ?string $helper,
        string $audience = self::MEMBER,
        ?string $source = null
    ): array {
        return [
            'place' => $place,
            'source' => $source,
            'op' => '',
            'audience' => $audience,
            'change' => false,
            'bot' => $bot,
            'helper' => $helper,
        ];
    }

    /**
     * A change: the helper counts only when it runs it.
     *
     * @param list<string> $bot
     * @return array{place: string, source: ?string, op: string, audience: string, change: bool, bot: list<string>, helper: ?string}
     */
    private static function change(
        string $place,
        string $source,
        string $audience,
        array $bot,
        ?string $helper,
        string $op = ''
    ): array {
        return [
            'place' => $place,
            'source' => $source,
            'op' => $op,
            'audience' => $audience,
            'change' => true,
            'bot' => $bot,
            'helper' => $helper,
        ];
    }
}

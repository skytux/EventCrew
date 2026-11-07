<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\NewcomerApproval;
use EventCrew\Support\Roles;
use EventCrew\Telegram\BoardService;
use EventCrew\Telegram\MenuButtons;

/**
 * The team's OK for a newcomer: the organizers are asked once, the first
 * organizer to press decides, and the newcomer hears about an OK.
 */
final class NewcomerApprovalTest extends TelegramTestCase
{
    /** @var array<int, array{to: string, subject: string, body: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

            return true;
        });
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('get_permalink')->justReturn('https://site.test/crew/');

        $this->options[EmailTemplate::HTML_OPTION] = false;
        $this->options['eventcrew_signup_page_id'] = 897;
    }

    private function approval(): NewcomerApproval
    {
        return new NewcomerApproval(
            new PersonRepository(),
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            $this->client()
        );
    }

    /**
     * Sam, the newcomer.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function sam(array $extra = []): array
    {
        return $extra + [
            'id' => 8,
            'display_name' => 'Sam Lee',
            'email' => 'sam@example.com',
            'email_verified_at' => '2026-11-06 10:00:00',
            'approval' => Person::APPROVAL_WAITING,
        ];
    }

    /** @return array<string, mixed> */
    private static function kim(): array
    {
        return [
            'id' => 2,
            'display_name' => 'Kim',
            'email' => 'kim@example.com',
            'telegram_user_id' => 555,
            'telegram_chat_id' => 555,
            'is_organizer' => 1,
        ];
    }

    /** @return array<string, mixed> */
    private static function tap(string $data, int $from = 555): array
    {
        return [
            'id' => 'cb1',
            'from' => ['id' => $from],
            'message' => [
                'message_id' => 71,
                'chat' => ['id' => $from, 'type' => 'private'],
                'text' => '🆕 New: Sam Lee (sam@example.com) wants to join.',
            ],
            'data' => $data,
        ];
    }

    /** @return list<string> */
    private function updatesOfApproval(): array
    {
        return array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_starts_with(ltrim($sql), 'UPDATE') && str_contains($sql, 'approval')
        ));
    }

    // ---- Asking the organizers -----------------------------------------

    public function testAWaitingNewcomerAsksOrganizersByTelegramAndByEmail(): void
    {
        $this->options[BoardService::USERNAME_OPTION] = 'crewbot';
        $this->telegramResults['sendMessage'] = ['message_id' => 71];
        $this->wpdb->nextRows[] = self::sam(); // find
        $this->wpdb->nextRows[] = self::sam(); // markRoleNotice find
        $this->wpdb->nextRows[] = self::sam(); // addRoleNotices find
        $this->wpdb->nextResults[] = [
            self::kim(),
            ['id' => 3, 'display_name' => 'Alex', 'email' => 'alex@example.com', 'is_organizer' => 1],
        ];

        $this->approval()->onEmailVerified(8);

        $dm = $this->lastCallTo('sendMessage');
        self::assertNotNull($dm);
        self::assertSame(555, $dm['chat_id']);
        self::assertStringContainsString('🆕 New: Sam Lee (sam@example.com) wants to join.', $dm['text']);
        $buttons = $dm['reply_markup']['inline_keyboard'][0];
        self::assertSame('✅ OK', $buttons[0]['text']);
        self::assertSame('nco:ok:8', $buttons[0]['callback_data']);
        self::assertSame('❌ No', $buttons[1]['text']);
        self::assertSame('nco:no:8', $buttons[1]['callback_data']);

        // Both organizers get the email, Kim's on top of her Telegram DM.
        self::assertSame(['kim@example.com', 'alex@example.com'], array_column($this->mails, 'to'));
        foreach ($this->mails as $mail) {
            self::assertSame('New: Sam Lee wants to join', $mail['subject']);
            self::assertStringContainsString('Press OK or No in the Admin tab of the crew page.', $mail['body']);
            self::assertStringContainsString('https://site.test/crew/#', $mail['body']);
            self::assertStringContainsString('t.me/crewbot?start=nco-8', $mail['body']);
        }

        // Recorded, so the next sign-in does not ask again.
        $notice = array_values(array_filter(
            $this->wpdb->updates,
            static fn (array $u): bool => isset($u['data']['role_notices'])
        ));
        self::assertCount(2, $notice);
        self::assertStringContainsString('newcomer:asked', (string) $notice[0]['data']['role_notices']);

        // Kim's Telegram copy and both emails are kept, for the outcome later.
        $sent = json_decode((string) $notice[1]['data']['role_notices'], true);
        self::assertSame('555:71', $sent['newcomer:dm:2']);
        self::assertArrayHasKey('newcomer:mail:2', $sent);
        self::assertArrayHasKey('newcomer:mail:3', $sent);
        self::assertArrayNotHasKey('newcomer:dm:3', $sent);
    }

    /** Point 5: the request shows the person's @username and a link to their Telegram profile. */
    public function testTheRequestShowsTheTelegramHandleAndAProfileLink(): void
    {
        $this->telegramResults['getChat'] = ['id' => 4242, 'username' => 'samlee'];
        $this->wpdb->nextRows[] = self::sam(['telegram_user_id' => 4242, 'telegram_chat_id' => 4242]);
        $this->wpdb->nextRows[] = self::sam();
        $this->wpdb->nextRows[] = self::sam();
        $this->wpdb->nextResults[] = [self::kim()];

        $this->approval()->onEmailVerified(8);

        $dm = $this->lastCallTo('sendMessage');
        self::assertSame('HTML', $dm['parse_mode']);
        self::assertStringContainsString(
            "\n👤 @samlee · <a href=\"tg://user?id=4242\">Open their Telegram profile</a>",
            $dm['text']
        );
    }

    public function testAPersonWithoutTelegramHasNoProfileLink(): void
    {
        $this->wpdb->nextRows[] = self::sam(['display_name' => 'Sam <b>&</b>']);
        $this->wpdb->nextRows[] = self::sam();
        $this->wpdb->nextRows[] = self::sam();
        $this->wpdb->nextResults[] = [self::kim()];

        $this->approval()->onEmailVerified(8);

        $dm = $this->lastCallTo('sendMessage');
        self::assertStringNotContainsString('tg://user', $dm['text']);
        // The name is escaped for Telegram HTML.
        self::assertStringContainsString('Sam &lt;b&gt;&amp;&lt;/b&gt;', $dm['text']);
        self::assertNull($this->lastCallTo('getChat'));
    }

    // ---- What the newcomer said when joining (Owner 2026-11-07) ------------

    /**
     * Asks the organizers (Kim) about Sam with $extra on Sam's row.
     *
     * @param array<string, mixed> $extra
     */
    private function askAbout(array $extra = []): void
    {
        $this->wpdb->nextRows[] = self::sam($extra);
        $this->wpdb->nextRows[] = self::sam($extra);
        $this->wpdb->nextRows[] = self::sam($extra);
        $this->wpdb->nextResults[] = [self::kim()];

        $this->approval()->onEmailVerified(8);
    }

    public function testTheRequestCarriesTheAnswersYesWithNumbers(): void
    {
        $this->askAbout([
            'role_notices' => '{"newcomer:before":"yes","newcomer:times":"5","newcomer:tickets":"3"}',
        ]);

        $line = 'Worked on the crew here before: yes, about 5 times. Thinks they should have 3 free-entry tickets.';
        self::assertStringContainsString("\n" . $line, $this->lastCallTo('sendMessage')['text']);
        self::assertStringContainsString("\n\n" . $line, $this->mails[0]['body']);
    }

    public function testTheRequestCarriesTheAnswersOneTimeAndOneTicket(): void
    {
        $this->askAbout([
            'role_notices' => '{"newcomer:before":"yes","newcomer:times":"1","newcomer:tickets":"1"}',
        ]);

        self::assertStringContainsString(
            'Worked on the crew here before: yes, about 1 time. Thinks they should have 1 free-entry ticket.',
            $this->mails[0]['body']
        );
    }

    public function testTheRequestCarriesTheAnswerNo(): void
    {
        $this->askAbout(['role_notices' => '{"newcomer:before":"no"}']);

        self::assertStringContainsString('First time on the crew.', $this->lastCallTo('sendMessage')['text']);
        self::assertStringContainsString('First time on the crew.', $this->mails[0]['body']);
    }

    public function testTheRequestSaysWhenTheQuestionWasNotAnswered(): void
    {
        $this->askAbout();

        $line = 'Did not say whether they have worked on the crew here before.';
        self::assertStringContainsString($line, $this->lastCallTo('sendMessage')['text']);
        self::assertStringContainsString($line, $this->mails[0]['body']);
    }

    public function testTheEmailLinksTheTelegramHandle(): void
    {
        $this->telegramResults['getChat'] = ['id' => 4242, 'username' => 'samlee'];
        $this->askAbout(['telegram_user_id' => 4242, 'telegram_chat_id' => 4242]);

        // Plain text: "label: url".
        self::assertStringContainsString('@samlee: https://t.me/samlee', $this->mails[0]['body']);
    }

    public function testTheHtmlEmailLinksTheTelegramHandle(): void
    {
        $this->options[EmailTemplate::HTML_OPTION] = true;
        $this->telegramResults['getChat'] = ['id' => 4242, 'username' => 'samlee'];
        $this->askAbout(['telegram_user_id' => 4242, 'telegram_chat_id' => 4242]);

        self::assertMatchesRegularExpression('#<a [^>]*href="https://t\.me/samlee"[^>]*>@samlee</a>#', $this->mails[0]['body']);
    }

    public function testTheEmailHasNoHandleLinkWithoutAUsername(): void
    {
        // Telegram known, but no public @username: no t.me link (and no tg://
        // link either, which mail apps do not open).
        $this->telegramResults['getChat'] = ['id' => 4242];
        $this->askAbout(['telegram_user_id' => 4242, 'telegram_chat_id' => 4242]);

        self::assertStringNotContainsString('t.me/samlee', $this->mails[0]['body']);
        self::assertStringNotContainsString('tg://', $this->mails[0]['body']);
    }

    public function testTheEmailHasNoHandleLinkForAnOddUsername(): void
    {
        $this->telegramResults['getChat'] = ['id' => 4242, 'username' => 'sam"><script>'];
        $this->askAbout(['telegram_user_id' => 4242, 'telegram_chat_id' => 4242]);

        self::assertStringNotContainsString('https://t.me/', $this->mails[0]['body']);
        self::assertStringNotContainsString('<script>', $this->mails[0]['body']);
    }

    public function testTheEmailHasNoHandleLinkWithoutTelegram(): void
    {
        $this->askAbout();

        self::assertStringNotContainsString('https://t.me/', $this->mails[0]['body']);
    }

    public function testTheOrganizersAreAskedOnlyOnce(): void
    {
        $this->wpdb->nextRows[] = self::sam(['role_notices' => '{"newcomer:asked":"2026-11-06 10:00:00"}']);

        $this->approval()->onEmailVerified(8);

        self::assertNull($this->lastCallTo('sendMessage'));
        self::assertSame([], $this->mails);
    }

    public function testSomeoneWhoIsOkAsksNobody(): void
    {
        $this->wpdb->nextRows[] = self::sam(['approval' => '']);

        $this->approval()->onEmailVerified(8);

        self::assertNull($this->lastCallTo('sendMessage'));
        self::assertSame([], $this->mails);
    }

    public function testAnOrganizerWhoTurnedTheDmOffStillGetsTheEmail(): void
    {
        $this->wpdb->nextRows[] = self::sam();
        $this->wpdb->nextRows[] = self::sam();
        $this->wpdb->nextResults[] = [
            self::kim() + ['notify_prefs' => json_encode(['newcomer' => ['dm' => false, 'email' => true]])],
        ];

        $this->approval()->onEmailVerified(8);

        self::assertNull($this->lastCallTo('sendMessage'));
        self::assertSame(['kim@example.com'], array_column($this->mails, 'to'));
    }

    public function testAStoredDmOffWithoutAnEmailChoiceStillGetsTheEmail(): void
    {
        // A preference saved before Q324 that only says the DM is off.
        $this->wpdb->nextRows[] = self::sam();
        $this->wpdb->nextRows[] = self::sam();
        $this->wpdb->nextResults[] = [
            self::kim() + ['notify_prefs' => json_encode(['newcomer' => ['dm' => false]])],
        ];

        $this->approval()->onEmailVerified(8);

        self::assertNull($this->lastCallTo('sendMessage'));
        self::assertSame(['kim@example.com'], array_column($this->mails, 'to'));
    }

    public function testAnOrganizerWhoTurnedTheEmailOffGetsOnlyTheDm(): void
    {
        $this->wpdb->nextRows[] = self::sam();
        $this->wpdb->nextRows[] = self::sam();
        $this->wpdb->nextResults[] = [
            self::kim() + ['notify_prefs' => json_encode(['newcomer' => ['dm' => true, 'email' => false]])],
        ];

        $this->approval()->onEmailVerified(8);

        self::assertNotNull($this->lastCallTo('sendMessage'));
        self::assertSame([], $this->mails);
    }

    public function testAnOrganizerWhoTurnedBothOffIsNotAsked(): void
    {
        $this->wpdb->nextRows[] = self::sam();
        $this->wpdb->nextRows[] = self::sam();
        $this->wpdb->nextResults[] = [
            self::kim() + ['notify_prefs' => json_encode(['newcomer' => ['dm' => false, 'email' => false]])],
        ];

        $this->approval()->onEmailVerified(8);

        self::assertNull($this->lastCallTo('sendMessage'));
        self::assertSame([], $this->mails);
    }

    // ---- Deciding -------------------------------------------------------

    /** The OK lists the open tasks the newcomer can take under their date, then See open tasks (Owner 2026-11-07). */
    public function testTheOkListsTheOpenTasksUnderTheirDateAndOneBoardButton(): void
    {
        $this->options[Roles::OPTION_NAME] = [['slug' => 'door', 'label' => 'Door']];
        $this->wpdb->nextRows[] = self::kim();
        $this->wpdb->nextRows[] = self::sam(['telegram_chat_id' => 999]);
        $this->wpdb->nextQueryResults[] = 1;
        $this->wpdb->nextRows[] = self::sam(['telegram_chat_id' => 999]); // addRoleNotices
        $this->wpdb->nextRows[] = self::sam(['approval' => 'ok', 'approval_by' => 'Kim', 'telegram_chat_id' => 999]);
        $this->wpdb->nextRows[] = self::sam(['approval' => 'ok', 'approval_by' => 'Kim']);
        $this->wpdb->nextResults[] = [[                             // upcoming
            'id' => 40,
            'task_date' => '2099-11-14',
            'role_slug' => 'door',
            'capacity' => 3,
            'event_label' => 'Dance Night',
            'starts_at' => '2099-11-14 18:00:00',
            'ends_at' => '2099-11-14 20:00:00',
        ]];
        $this->wpdb->nextResults[] = [];                            // their assignments
        $this->wpdb->nextResults[] = [['task_id' => 40, 'taken' => 1]];

        $this->approval()->onTap(self::tap('nco:ok:8'));

        $sent = $this->lastCallTo('sendMessage');
        self::assertNotNull($sent);
        self::assertStringEndsWith(
            "✅ The team said OK. You can now take tasks.\n\nOpen now:"
                . "\n📅 Sat 14 Nov\n\n🔹 Door 18:00–20:00 at Dance Night\n2 of 3 places free",
            $sent['text']
        );
        self::assertSame(
            [
                [['text' => '📋 See open tasks', 'callback_data' => MenuButtons::BOARD]],
            ],
            $sent['reply_markup']['inline_keyboard']
        );
    }

    public function testTheFirstOkDecidesAndTheNewcomerIsToldByTelegram(): void
    {
        $decided = self::sam([
            'approval' => 'ok',
            'approval_by' => 'Kim',
            'telegram_chat_id' => 999,
            'role_notices' => '{"newcomer:decided":"2026-07-20 12:00:00","newcomer:reason":""}',
        ]);
        $this->wpdb->nextRows[] = self::kim();                                   // tapping organizer
        $this->wpdb->nextRows[] = self::sam(['telegram_chat_id' => 999]);        // decide find
        $this->wpdb->nextQueryResults[] = 1;                                     // decideNewcomer
        $this->wpdb->nextRows[] = self::sam(['telegram_chat_id' => 999]);        // addRoleNotices
        $this->wpdb->nextRows[] = $decided;                                      // finish
        $this->wpdb->nextRows[] = $decided;                                      // after

        $this->approval()->onTap(self::tap('nco:ok:8'));

        $update = $this->updatesOfApproval();
        self::assertCount(1, $update);
        self::assertStringContainsString("SET approval = 'ok', approval_by = 'Kim'", $update[0]);
        self::assertStringContainsString("approval = 'waiting'", $update[0]);

        $sent = $this->lastCallTo('sendMessage');
        self::assertNotNull($sent);
        self::assertSame(999, $sent['chat_id']);
        self::assertStringContainsString(
            '✅ The team said OK. You can now take tasks.',
            $sent['text']
        );
        self::assertSame(
            [[['text' => '📋 See open tasks', 'callback_data' => MenuButtons::BOARD]]],
            $sent['reply_markup']['inline_keyboard']
        );

        $edit = $this->lastCallTo('editMessageText');
        self::assertNotNull($edit);
        self::assertSame(
            "📣 Test Site\n\nHi Kim,\n\n🆕 New: Sam Lee (sam@example.com) wants to join.\nDid not say whether they have worked on the crew here before.\n\n✅ OK, by Kim, 20 Jul 12:00.",
            $edit['text']
        );
        self::assertSame([], $edit['reply_markup']['inline_keyboard']);

        // When and why are recorded.
        $notices = array_values(array_filter(
            $this->wpdb->updates,
            static fn (array $u): bool => isset($u['data']['role_notices'])
        ));
        $recorded = json_decode((string) $notices[0]['data']['role_notices'], true);
        self::assertSame('2026-07-20 12:00:00', $recorded['newcomer:decided']);
        self::assertSame('', $recorded['newcomer:reason']);
    }

    public function testANewcomerWithoutTelegramIsToldByEmail(): void
    {
        $this->wpdb->nextRows[] = self::sam();
        $this->wpdb->nextQueryResults[] = 1;

        $outcome = $this->approval()->decide(8, true, Person::fromRow(self::kim()));

        self::assertSame(NewcomerApproval::DECIDED, $outcome);
        self::assertCount(1, $this->mails);
        self::assertSame('sam@example.com', $this->mails[0]['to']);
        self::assertSame('The team said OK', $this->mails[0]['subject']);
        self::assertStringContainsString('The team said OK. You can now take tasks on the crew page.', $this->mails[0]['body']);
        self::assertStringContainsString('See open tasks: https://site.test/crew/', $this->mails[0]['body']);
    }

    public function testNoIsRecordedAndTheNewcomerIsNotMessaged(): void
    {
        $this->wpdb->nextRows[] = self::sam(['telegram_chat_id' => 999]);
        $this->wpdb->nextQueryResults[] = 1;

        $outcome = $this->approval()->decide(8, false, Person::fromRow(self::kim()));

        self::assertSame(NewcomerApproval::DECIDED, $outcome);
        self::assertStringContainsString("SET approval = 'no'", $this->updatesOfApproval()[0]);
        self::assertNull($this->lastCallTo('sendMessage'));
        self::assertSame([], $this->mails);
    }

    public function testALaterPressSaysWhoAlreadyDecided(): void
    {
        $this->wpdb->nextRows[] = self::kim();
        $this->wpdb->nextRows[] = self::sam(['approval' => 'no', 'approval_by' => 'Alex', 'telegram_chat_id' => 999]);
        $this->wpdb->nextQueryResults[] = 0; // someone else decided first
        $this->wpdb->nextRows[] = self::sam(['approval' => 'no', 'approval_by' => 'Alex']);

        $this->approval()->onTap(self::tap('nco:ok:8'));

        $answer = $this->lastCallTo('answerCallbackQuery');
        self::assertSame('❌ No, by Alex.', $answer['text']);
        self::assertTrue($answer['show_alert']);
        self::assertNull($this->lastCallTo('sendMessage'));
        self::assertSame(
            "📣 Test Site\n\nHi Kim,\n\n🆕 New: Sam Lee (sam@example.com) wants to join.\nDid not say whether they have worked on the crew here before.\n\n❌ No, by Alex.",
            $this->lastCallTo('editMessageText')['text']
        );
    }

    public function testTheEmailLinkShowsTheOkAndNoAndDecidesNothing(): void
    {
        $this->wpdb->nextRows[] = self::kim();
        $this->wpdb->nextRows[] = self::sam();

        $this->approval()->showRequest(555, 555, 8);

        $dm = $this->lastCallTo('sendMessage');
        self::assertStringContainsString('🆕 New: Sam Lee (sam@example.com) wants to join.', $dm['text']);
        self::assertSame(
            ['nco:ok:8', 'nco:no:8'],
            array_column($dm['reply_markup']['inline_keyboard'][0], 'callback_data')
        );
        self::assertSame([], $this->updatesOfApproval());
        self::assertSame([], $this->wpdb->updates);
    }

    public function testTheEmailLinkSaysWhoDecidedOnceDecided(): void
    {
        $this->wpdb->nextRows[] = self::kim();
        $this->wpdb->nextRows[] = self::sam(['approval' => 'no', 'approval_by' => 'Alex']);

        $this->approval()->showRequest(555, 555, 8);

        $dm = $this->lastCallTo('sendMessage');
        self::assertStringEndsWith("\n\n❌ No, by Alex.", $dm['text']);
        self::assertArrayNotHasKey('reply_markup', $dm);
    }

    public function testTheEmailLinkShowsNothingToSomeoneWhoIsNotAnOrganizer(): void
    {
        $this->wpdb->nextRows[] = ['id' => 4, 'telegram_user_id' => 555, 'is_organizer' => 0];

        $this->approval()->showRequest(555, 555, 8);

        self::assertSame('Only organizers can answer this.', $this->lastCallTo('sendMessage')['text']);
    }

    public function testOnlyAnOrganizerCanPress(): void
    {
        $this->wpdb->nextRows[] = ['id' => 4, 'telegram_user_id' => 555, 'is_organizer' => 0];

        $this->approval()->onTap(self::tap('nco:ok:8'));

        self::assertSame('Only organizers can answer this.', $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertSame([], $this->updatesOfApproval());
        self::assertNull($this->lastCallTo('editMessageText'));
    }

    // ---- Turning down: confirm and reasons ---------------------------------

    /** Point 1 and 2: No asks "Turn down <name>?" with the reasons, and decides nothing yet. */
    public function testNoAsksToConfirmWithTheReasons(): void
    {
        $this->wpdb->nextRows[] = self::kim();
        $this->wpdb->nextRows[] = self::sam();

        $this->approval()->onTap(self::tap('nco:no:8'));

        self::assertSame([], $this->updatesOfApproval());
        $edit = $this->lastCallTo('editMessageText');
        self::assertSame(71, $edit['message_id']);
        self::assertStringContainsString("wants to join.\nDid not say whether they have worked on the crew here before.\n\nTurn down Sam Lee?\n", $edit['text']);
        self::assertStringContainsString('With “No reason” they are not told.', $edit['text']);
        self::assertSame(
            [['nco:nu:8'], ['nco:nn:8'], ['nco:bk:8']],
            array_map(
                static fn (array $row): array => array_column($row, 'callback_data'),
                $edit['reply_markup']['inline_keyboard']
            )
        );
        self::assertSame('📨 Unknown to us (they are told)', $edit['reply_markup']['inline_keyboard'][0][0]['text']);
        self::assertSame('🔕 No reason (not told)', $edit['reply_markup']['inline_keyboard'][1][0]['text']);
        self::assertNull($this->lastCallTo('sendMessage'));
    }

    public function testBackShowsOkAndNoAgain(): void
    {
        $this->wpdb->nextRows[] = self::kim();
        $this->wpdb->nextRows[] = self::sam();

        $this->approval()->onTap(self::tap('nco:bk:8'));

        $edit = $this->lastCallTo('editMessageText');
        self::assertStringEndsWith(
            "wants to join.\nDid not say whether they have worked on the crew here before.",
            $edit['text']
        );
        self::assertSame(['nco:ok:8', 'nco:no:8'], array_column($edit['reply_markup']['inline_keyboard'][0], 'callback_data'));
        self::assertSame([], $this->updatesOfApproval());
    }

    public function testNoOnARequestDecidedAlreadySaysWhoDecided(): void
    {
        $this->wpdb->nextRows[] = self::kim();
        $this->wpdb->nextRows[] = self::sam(['approval' => 'ok', 'approval_by' => 'Alex']);

        $this->approval()->onTap(self::tap('nco:no:8'));

        self::assertSame('✅ OK, by Alex.', $this->lastCallTo('answerCallbackQuery')['text']);
        $edit = $this->lastCallTo('editMessageText');
        self::assertStringEndsWith("\n\n✅ OK, by Alex.", $edit['text']);
        self::assertSame([], $edit['reply_markup']['inline_keyboard']);
    }

    /** Point 2: the reason Unknown to us turns them down and tells them, by Telegram. */
    public function testUnknownToUsTurnsDownAndTellsThePerson(): void
    {
        $decided = self::sam([
            'approval' => 'no',
            'approval_by' => 'Kim',
            'telegram_chat_id' => 999,
            'role_notices' => '{"newcomer:decided":"2026-07-20 12:00:00","newcomer:reason":"unknown"}',
        ]);
        $this->wpdb->nextRows[] = self::kim();
        $this->wpdb->nextRows[] = self::sam(['telegram_chat_id' => 999]);
        $this->wpdb->nextQueryResults[] = 1;
        $this->wpdb->nextRows[] = self::sam(['telegram_chat_id' => 999]);
        $this->wpdb->nextRows[] = $decided;
        $this->wpdb->nextRows[] = $decided;

        $this->approval()->onTap(self::tap('nco:nu:8'));

        self::assertStringContainsString("SET approval = 'no', approval_by = 'Kim'", $this->updatesOfApproval()[0]);
        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(999, $sent['chat_id']);
        self::assertStringContainsString(
            'The organizers do not know you yet, so they could not say OK to your sign-up. Please come and talk to us at an event; an organizer can then say OK.',
            $sent['text']
        );
        self::assertStringEndsWith(
            '❌ No, by Kim, 20 Jul 12:00. They were told: unknown to the organizers.',
            $this->lastCallTo('editMessageText')['text']
        );
        $notices = array_values(array_filter($this->wpdb->updates, static fn (array $u): bool => isset($u['data']['role_notices'])));
        self::assertSame('unknown', json_decode((string) $notices[0]['data']['role_notices'], true)['newcomer:reason']);
    }

    public function testUnknownToUsReachesAPersonWithoutTelegramByEmail(): void
    {
        $this->wpdb->nextRows[] = self::sam();
        $this->wpdb->nextQueryResults[] = 1;

        $this->approval()->decide(8, false, Person::fromRow(self::kim()), NewcomerApproval::REASON_UNKNOWN);

        self::assertSame(['sam@example.com'], array_column($this->mails, 'to'));
        self::assertSame('About your sign-up', $this->mails[0]['subject']);
        self::assertStringContainsString('Please come and talk to us at an event', $this->mails[0]['body']);
    }

    /** Point 1: with No reason the person is not told, and the copy says so. */
    public function testNoReasonTellsNobody(): void
    {
        $decided = self::sam([
            'approval' => 'no',
            'approval_by' => 'Kim',
            'telegram_chat_id' => 999,
            'role_notices' => '{"newcomer:decided":"2026-07-20 12:00:00","newcomer:reason":"none"}',
        ]);
        $this->wpdb->nextRows[] = self::kim();
        $this->wpdb->nextRows[] = self::sam(['telegram_chat_id' => 999]);
        $this->wpdb->nextQueryResults[] = 1;
        $this->wpdb->nextRows[] = self::sam(['telegram_chat_id' => 999]);
        $this->wpdb->nextRows[] = $decided;
        $this->wpdb->nextRows[] = $decided;

        $this->approval()->onTap(self::tap('nco:nn:8'));

        self::assertNull($this->lastCallTo('sendMessage'));
        self::assertSame([], $this->mails);
        self::assertStringEndsWith(
            '❌ No, by Kim, 20 Jul 12:00. They were not told.',
            $this->lastCallTo('editMessageText')['text']
        );
    }

    // ---- The other organizers' copies --------------------------------------

    /** Point 3: every Telegram copy shows the outcome, and the other emailed organizers get an email. */
    public function testTheOutcomeReachesEveryCopyAndTheOtherEmailedOrganizers(): void
    {
        $notices = json_encode([
            'newcomer:asked' => '2026-07-19 10:00:00',
            'newcomer:dm:2' => '555:71',
            'newcomer:dm:3' => '777:72',
            'newcomer:mail:2' => '2026-07-19 10:00:00',
            'newcomer:mail:3' => '2026-07-19 10:00:00',
            'newcomer:decided' => '2026-07-20 12:00:00',
            'newcomer:reason' => 'none',
        ]);
        $this->wpdb->nextRows[] = self::sam(['role_notices' => $notices]);
        $this->wpdb->nextQueryResults[] = 1;
        $this->wpdb->nextRows[] = self::sam(['role_notices' => $notices]);
        $this->wpdb->nextRows[] = self::sam(['approval' => 'no', 'approval_by' => 'Kim', 'role_notices' => $notices]);
        $this->wpdb->nextResults[] = [
            self::kim(),
            ['id' => 3, 'display_name' => 'Alex', 'email' => 'alex@example.com', 'telegram_chat_id' => 777, 'is_organizer' => 1],
            ['id' => 5, 'display_name' => 'Robin', 'email' => 'robin@example.com', 'is_organizer' => 1],
        ];

        $outcome = $this->approval()->decide(8, false, Person::fromRow(self::kim()));

        self::assertSame(NewcomerApproval::DECIDED, $outcome);
        $edits = array_values(array_filter($this->telegramCalls, static fn (array $c): bool => 'editMessageText' === $c['method']));
        self::assertSame([[555, 71], [777, 72]], array_map(static fn (array $c): array => [$c['body']['chat_id'], $c['body']['message_id']], $edits));
        self::assertStringContainsString('Hi Alex,', $edits[1]['body']['text']);
        foreach ($edits as $edit) {
            self::assertStringEndsWith('❌ No, by Kim, 20 Jul 12:00. They were not told.', $edit['body']['text']);
            self::assertSame([], $edit['body']['reply_markup']['inline_keyboard']);
        }

        // Kim decided and Robin was never emailed: only Alex gets the email.
        self::assertSame(['alex@example.com'], array_column($this->mails, 'to'));
        self::assertSame('Answered: Sam Lee', $this->mails[0]['subject']);
        self::assertStringContainsString(
            "The request of Sam Lee (sam@example.com) to join the crew is answered:\n❌ No, by Kim, 20 Jul 12:00. They were not told.",
            $this->mails[0]['body']
        );
    }

    // ---- 48 hours -----------------------------------------------------------

    /** Point 4: a request older than 48 hours is turned down, the person is told why, the copies show it. */
    public function testARequestNobodyAnsweredIn48HoursTimesOut(): void
    {
        $old = '{"newcomer:asked":"2026-07-18 11:00:00","newcomer:dm:2":"555:71","newcomer:mail:2":"2026-07-18 11:00:00"}';
        $fresh = '{"newcomer:asked":"2026-07-19 13:00:00"}';
        $decided = '{"newcomer:asked":"2026-07-18 11:00:00","newcomer:dm:2":"555:71","newcomer:mail:2":"2026-07-18 11:00:00",'
            . '"newcomer:decided":"2026-07-20 12:00:00","newcomer:reason":"timeout"}';
        $this->wpdb->nextResults[] = [                                   // waitingNewcomers
            self::sam(['role_notices' => $old, 'telegram_chat_id' => 999]),
            self::sam(['id' => 9, 'email' => 'jo@example.com', 'role_notices' => $fresh]),
        ];
        $this->wpdb->nextQueryResults[] = 1;
        $this->wpdb->nextRows[] = self::sam(['role_notices' => $old]);                 // addRoleNotices
        $this->wpdb->nextRows[] = self::sam(['approval' => 'no', 'role_notices' => $decided, 'telegram_chat_id' => 999]);
        $this->wpdb->nextResults[] = [self::kim()];                      // organizers

        self::assertSame(1, $this->approval()->expireDue(25));

        $update = $this->updatesOfApproval();
        self::assertCount(1, $update);
        self::assertStringContainsString("SET approval = 'no', approval_by = ''", $update[0]);
        self::assertStringContainsString('WHERE id = 8', $update[0]);

        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(999, $sent['chat_id']);
        self::assertStringContainsString(
            '⌛ Your request to join the crew timed out: no organizer answered it in 48 hours. This can mean that no organizer recognized you. To finish signing up, please talk to an organizer directly at an event.',
            $sent['text']
        );

        self::assertStringEndsWith(
            '⌛ No organizer answered in 48 hours, so the request timed out on 20 Jul 12:00. They were told to talk to an organizer at an event.',
            $this->lastCallTo('editMessageText')['text']
        );
        // Kim was emailed the request, so she gets the outcome too.
        self::assertSame(['kim@example.com'], array_column($this->mails, 'to'));
        self::assertSame('Answered: Sam Lee', $this->mails[0]['subject']);
    }

    public function testATimedOutPersonWithoutTelegramIsToldByEmail(): void
    {
        $old = '{"newcomer:asked":"2026-07-17 11:00:00"}';
        $this->wpdb->nextResults[] = [self::sam(['role_notices' => $old])];
        $this->wpdb->nextQueryResults[] = 1;

        self::assertSame(1, $this->approval()->expireDue(25));

        self::assertSame(['sam@example.com'], array_column($this->mails, 'to'));
        self::assertSame('Your sign-up request timed out', $this->mails[0]['subject']);
        self::assertStringContainsString('please talk to an organizer directly at an event', $this->mails[0]['body']);
    }

    public function testARequestWithoutAnAskedTimeDoesNotTimeOut(): void
    {
        $this->wpdb->nextResults[] = [self::sam()];

        self::assertSame(0, $this->approval()->expireDue(25));
        self::assertSame([], $this->updatesOfApproval());
    }

    // ---- Accept from the list -----------------------------------------------

    /** Point 6: Accept gives the OK and the same message as the OK button. */
    public function testAcceptGivesTheOkAndTheSameMessage(): void
    {
        $notices = '{"newcomer:dm:2":"555:71","newcomer:mail:2":"x","newcomer:decided":"2026-07-20 12:00:00","newcomer:reason":""}';
        $this->wpdb->nextRows[] = self::sam(['approval' => 'no', 'telegram_chat_id' => 999]);
        $this->wpdb->nextQueryResults[] = 1;
        $this->wpdb->nextRows[] = self::sam(['approval' => 'no']);
        $this->wpdb->nextRows[] = self::sam(['approval' => 'ok', 'approval_by' => 'Admin Ann', 'telegram_chat_id' => 999, 'role_notices' => $notices]);
        $this->wpdb->nextResults[] = [];                // open tasks
        $this->wpdb->nextResults[] = [self::kim()];     // organizers

        self::assertTrue($this->approval()->accept(8, 'Admin Ann'));

        $update = $this->updatesOfApproval();
        self::assertStringContainsString("SET approval = 'ok', approval_by = 'Admin Ann'", $update[0]);
        self::assertStringContainsString("approval = 'no'", $update[0]);
        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(999, $sent['chat_id']);
        self::assertStringContainsString('✅ The team said OK. You can now take tasks.', $sent['text']);
        self::assertStringEndsWith('✅ OK, by Admin Ann, 20 Jul 12:00.', $this->lastCallTo('editMessageText')['text']);
        // No outcome email for an accept.
        self::assertSame([], $this->mails);
    }

    public function testAcceptDoesNothingForSomeoneNotTurnedDown(): void
    {
        $this->wpdb->nextRows[] = self::sam(['approval' => 'ok']);
        $this->wpdb->nextQueryResults[] = 0;

        self::assertFalse($this->approval()->accept(8, 'Admin Ann'));
        self::assertNull($this->lastCallTo('sendMessage'));
        self::assertSame([], $this->mails);
    }

    // ---- A role or a pass -------------------------------------------------

    public function testARoleOrAPassMakesTheNewcomerOk(): void
    {
        $this->approval()->approveByAccess(8);

        $update = $this->updatesOfApproval();
        self::assertCount(1, $update);
        self::assertStringContainsString("SET approval = 'ok'", $update[0]);
        self::assertStringContainsString("approval IN ('waiting', 'no')", $update[0]);
        self::assertNull($this->lastCallTo('sendMessage'));
    }
}

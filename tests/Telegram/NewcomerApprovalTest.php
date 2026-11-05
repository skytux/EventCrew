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
        $this->wpdb->nextRows[] = self::sam(); // find
        $this->wpdb->nextRows[] = self::sam(); // markRoleNotice find
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
        }

        // Recorded, so the next sign-in does not ask again.
        $notice = array_values(array_filter(
            $this->wpdb->updates,
            static fn (array $u): bool => isset($u['data']['role_notices'])
        ));
        self::assertCount(1, $notice);
        self::assertStringContainsString('newcomer:asked', (string) $notice[0]['data']['role_notices']);
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

    public function testTheFirstOkDecidesAndTheNewcomerIsToldByTelegram(): void
    {
        $this->wpdb->nextRows[] = self::kim();                                   // tapping organizer
        $this->wpdb->nextRows[] = self::sam(['telegram_chat_id' => 999]);        // decide find
        $this->wpdb->nextQueryResults[] = 1;                                     // decideNewcomer
        $this->wpdb->nextRows[] = self::sam(['approval' => 'ok', 'approval_by' => 'Kim']); // after

        $this->approval()->onTap(self::tap('nco:ok:8'));

        $update = $this->updatesOfApproval();
        self::assertCount(1, $update);
        self::assertStringContainsString("SET approval = 'ok', approval_by = 'Kim'", $update[0]);
        self::assertStringContainsString("approval = 'waiting'", $update[0]);

        $sent = $this->lastCallTo('sendMessage');
        self::assertNotNull($sent);
        self::assertSame(999, $sent['chat_id']);
        self::assertStringContainsString('✅ The team said OK. You can now take tasks: send /board.', $sent['text']);

        $edit = $this->lastCallTo('editMessageText');
        self::assertNotNull($edit);
        self::assertSame(
            "🆕 New: Sam Lee (sam@example.com) wants to join.\n\n✅ OK, by Kim.",
            $edit['text']
        );
        self::assertSame([], $edit['reply_markup']['inline_keyboard']);
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
            "🆕 New: Sam Lee (sam@example.com) wants to join.\n\n❌ No, by Alex.",
            $this->lastCallTo('editMessageText')['text']
        );
    }

    public function testOnlyAnOrganizerCanPress(): void
    {
        $this->wpdb->nextRows[] = ['id' => 4, 'telegram_user_id' => 555, 'is_organizer' => 0];

        $this->approval()->onTap(self::tap('nco:ok:8'));

        self::assertSame('Only organizers can answer this.', $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertSame([], $this->updatesOfApproval());
        self::assertNull($this->lastCallTo('editMessageText'));
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

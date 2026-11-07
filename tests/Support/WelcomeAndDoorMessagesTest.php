<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\DoorLinkSender;
use EventCrew\Support\DoorNewMember;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\Roles;
use EventCrew\Support\SignupService;
use EventCrew\Telegram\BoardService;

/**
 * The welcome, door new member and sales desk messages read their Messages
 * page templates. With nothing stored, every email and DM is exactly what it
 * was before the templates (the fixtures in fixtures/messages were captured
 * from the code before the change, the one-time tokens masked). A stored text
 * is used instead.
 */
final class WelcomeAndDoorMessagesTest extends SalesDeskTestCase
{
    /** @var array<int, array{to: string, subject: string, body: string}> */
    private array $mails = [];

    /** memberRow() gives a person with no Telegram chat (E2). */
    private bool $webOnly = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];
        $this->webOnly = false;
        $this->options['eventcrew_email_html'] = '0';
        $this->options['eventcrew_signup_page_id'] = 7;

        Functions\when('get_permalink')->justReturn('https://site.test/crew/');
        Functions\when('get_bloginfo')->justReturn('Test Crew');
        Functions\when('sanitize_email')->alias(static fn (string $email): string => trim($email));
        Functions\when('is_email')->alias(static fn (string $email): bool => str_contains($email, '@'));
        Functions\when('wp_mail')->alias(
            function (string $to, string $subject, string $body): bool {
                $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

                return true;
            }
        );
    }

    // ---- Helpers ----------------------------------------------------------

    /**
     * Each message as one text: the subject (emails) and the body, with the
     * one-time tokens masked.
     *
     * @return list<string>
     */
    private function sent(): array
    {
        $out = [];

        foreach ($this->mails as $mail) {
            $out[] = 'EMAIL ' . $mail['subject'] . "\n\n" . $mail['body'];
        }

        foreach ($this->telegramCalls as $call) {
            if ('sendMessage' === $call['method']) {
                $out[] = 'DM ' . (string) ($call['body']['text'] ?? '');
            }
        }

        return array_map(
            static fn (string $text): string => (string) preg_replace('/[0-9a-f]{32,}/', 'TOKEN', $text),
            $out
        );
    }

    private function assertFixture(string $name, string $actual): void
    {
        $file = __DIR__ . '/fixtures/messages/' . $name . '.txt';

        self::assertFileExists($file);
        self::assertSame((string) file_get_contents($file), $actual, $name);
    }

    /**
     * @param array<string, string> $texts part => English text
     */
    private function store(string $id, array $texts): void
    {
        $value = [];

        foreach ($texts as $part => $text) {
            $value[$part] = ['source' => $text, 'texts' => ['fi' => ''], 'manual' => []];
        }

        $this->options[MessageTemplates::OPTION_PREFIX . $id] = $value;
    }

    /**
     * A verified member row; an organizer with a Telegram chat.
     *
     * @return array<string, mixed>
     */
    private function memberRow(int $id, bool $organizer, string $language = 'en'): array
    {
        $row = $this->personRow($id, $organizer);
        $row['email_verified_at'] = '2026-07-01 10:00:00';
        $row['language'] = $language;

        if ($this->webOnly) {
            $row['telegram_user_id'] = null;
            $row['telegram_chat_id'] = null;
        }

        return $row;
    }

    private function queueOpenTasks(): void
    {
        $this->wpdb->nextResults[] = [
            $this->taskRow(5, 'decorate', '13:00', '14:00'),
            $this->taskRow(6, 'clean', '23:00', '23:30'),
        ];
        $this->wpdb->nextResults[] = [
            ['task_id' => 5, 'taken' => 1],
            ['task_id' => 6, 'taken' => 3],
        ];
    }

    private function welcomeTest(string $language, bool $gate = true): void
    {
        $this->options[SignupService::GATE_OPTION] = $gate ? '1' : '0';
        $this->queueOpenTasks();

        $this->welcomeSender()->sendTest(\EventCrew\Models\Person::fromRow($this->memberRow(3, true, $language)));
    }

    private function doorNewMember(): DoorNewMember
    {
        return new DoorNewMember(
            new PersonRepository(),
            new AuthTokenRepository(),
            new Mailer(new Logger(), new EmailTemplate(new Logger())),
            $this->client()
        );
    }

    private function addAtTheDoor(bool $group, ?string $language = null): void
    {
        if ($group) {
            $this->options[BoardService::BOARD_OPTION] = ['chat_id' => -100123];
            $this->telegramResults['createChatInviteLink'] = ['invite_link' => 'https://t.me/+door'];
        }

        $row = $this->personRow(9);
        $row['telegram_user_id'] = null;
        $row['telegram_chat_id'] = null;

        if (null !== $language) {
            $row['language'] = $language;
        }
        $this->wpdb->nextRows[] = null;                                  // findByEmail: nobody
        $this->wpdb->nextRows[] = $row;                                  // find the new member

        $this->doorNewMember()->create('Person 9', 'p9@example.com');
    }

    /** Queues a run where person 8, verified, arrived on the Welcome task starting 12:10. */
    private function queueCrewRun(string $language = 'en'): void
    {
        $tasks = [$this->taskRow(6, 'welcome', '12:10', '14:00'), $this->taskRow(7, 'clean', '23:00')];

        $this->wpdb->nextResults[] = $tasks;
        $this->wpdb->nextVars[] = self::NIGHT . ' 20:00:00';
        $this->wpdb->nextResults[] = [$this->assignmentRow(30, 6, 8, 'arrived')];
        $this->wpdb->nextRows[] = $this->memberRow(8, false, $language);
        $this->wpdb->nextResults[] = $tasks;
        $this->wpdb->nextRows[] = $this->assignmentRow(30, 6, 8, 'arrived');
        $this->wpdb->nextVars[] = null;
        $this->wpdb->nextResults[] = $tasks;
    }

    /** Queues a run where organizer 1, verified, is on the Organize task and due. */
    private function queueOrganizerRun(string $language = 'en'): void
    {
        $this->wpdb->nextResults[] = [
            $this->taskRow(4, Roles::ORGANIZE_SLUG),
            $this->taskRow(5, 'decorate', '13:00', '14:00'),
        ];
        $this->wpdb->nextVars[] = self::NIGHT . ' 12:30:00';
        $this->wpdb->nextResults[] = [$this->assignmentRow(40, 4, 1, 'signed_up')];
        $this->wpdb->nextRows[] = $this->memberRow(1, true, $language);
        $this->wpdb->nextVars[] = null;
    }

    // ---- The built-in texts are what was sent before -----------------------

    public function testTheWelcomeMessagesAreUnchangedInEnglish(): void
    {
        $this->welcomeTest('en');

        $sent = $this->sent();
        self::assertCount(4, $sent);
        $this->assertFixture('welcome_email.en', $sent[0]);
        $this->assertFixture('welcome_org_email.en', $sent[1]);
        $this->assertFixture('welcome_dm.en', $sent[2]);
        $this->assertFixture('welcome_org_dm.en', $sent[3]);
    }

    public function testTheWelcomeMessagesAreUnchangedInFinnish(): void
    {
        $this->welcomeTest('fi');

        $sent = $this->sent();
        self::assertCount(4, $sent);
        $this->assertFixture('welcome_email.fi', $sent[0]);
        $this->assertFixture('welcome_org_email.fi', $sent[1]);
        $this->assertFixture('welcome_dm.fi', $sent[2]);
        $this->assertFixture('welcome_org_dm.fi', $sent[3]);
    }

    public function testTheWelcomeMessagesWithoutACrewPageOrStandingGateAreUnchanged(): void
    {
        $this->options['eventcrew_signup_page_id'] = 0;
        Functions\when('rest_url')->alias(
            static fn (string $path = ''): string => 'https://site.test/wp-json/' . $path
        );
        $this->welcomeTest('en', false);

        $sent = $this->sent();
        self::assertCount(4, $sent);
        $this->assertFixture('welcome_email.nopage', $sent[0]);
        $this->assertFixture('welcome_org_email.nopage', $sent[1]);
        $this->assertFixture('welcome_dm.nopage', $sent[2]);
        $this->assertFixture('welcome_org_dm.nopage', $sent[3]);
    }

    public function testTheHelpTabTextIsUnchanged(): void
    {
        $this->options[SignupService::GATE_OPTION] = '1';

        $this->assertFixture('help_text', \EventCrew\Support\WelcomeSender::helpText());
    }

    /** On the crew page the Help tab names the Me tab for the tickets, not /me tickets. */
    public function testTheHelpTabNamesTheMeTabForTickets(): void
    {
        $text = \EventCrew\Support\WelcomeSender::helpText();

        self::assertStringContainsString(
            'It is the first button in your sign-up confirmation, and the Me tab lists all your tickets.',
            $text
        );
        self::assertStringNotContainsString('/me tickets', $text);

        $finnish = \EventCrew\Support\Language::run(
            \EventCrew\Support\Language::FINNISH,
            static fn (): string => \EventCrew\Support\WelcomeSender::helpText()
        );
        self::assertStringContainsString(
            'Se on ilmoittautumisvahvistuksesi ensimmäinen painike, ja Minä-välilehti listaa kaikki lippusi.',
            $finnish
        );
    }

    public function testTheDoorNewMemberEmailIsUnchanged(): void
    {
        $this->addAtTheDoor(true);

        $sent = $this->sent();
        self::assertCount(1, $sent);
        $this->assertFixture('door_new_member.group', $sent[0]);
    }

    public function testTheDoorNewMemberEmailWithoutAGroupIsUnchanged(): void
    {
        $this->addAtTheDoor(false);

        $sent = $this->sent();
        self::assertCount(1, $sent);
        $this->assertFixture('door_new_member.nogroup', $sent[0]);
    }

    public function testTheSalesDeskLinkIsUnchanged(): void
    {
        $this->queueCrewRun();
        $this->doorLinkSender()->run();

        $sent = $this->sent();
        self::assertCount(2, $sent);
        $this->assertFixture('sales_desk_link.en', $sent[0]);
        $this->assertFixture('sales_desk_link_dm.en', $sent[1]);
    }

    public function testTheSalesDeskLinkIsUnchangedInFinnish(): void
    {
        $this->queueCrewRun('fi');
        $this->doorLinkSender()->run();

        $sent = $this->sent();
        self::assertCount(2, $sent);
        $this->assertFixture('sales_desk_link.fi', $sent[0]);
        $this->assertFixture('sales_desk_link_dm.fi', $sent[1]);
    }

    public function testTheOrganizerNoticeIsUnchanged(): void
    {
        $this->queueOrganizerRun();
        $this->doorLinkSender()->run();

        $sent = $this->sent();
        self::assertCount(2, $sent);
        $this->assertFixture('organizer_notice.en', $sent[0]);
        $this->assertFixture('organizer_notice_dm.en', $sent[1]);
    }

    public function testTheOrganizerNoticeIsUnchangedInFinnish(): void
    {
        $this->queueOrganizerRun('fi');
        $this->doorLinkSender()->run();

        $sent = $this->sent();
        self::assertCount(2, $sent);
        $this->assertFixture('organizer_notice.fi', $sent[0]);
        $this->assertFixture('organizer_notice_dm.fi', $sent[1]);
    }

    // ---- A stored text is used ---------------------------------------------

    public function testTheWelcomeMessagesUseTheStoredTexts(): void
    {
        $this->store(
            'welcome_email',
            ['subject' => 'Hello from {crew}', 'body' => 'Dear {name}, {tasks_per_credit} tasks.']
        );
        $this->store('welcome_dm', ['body' => 'Stored DM for {crew}.']);
        $this->store('welcome_org_email', ['subject' => 'Org guide', 'body' => 'Organizer {name} of {crew}.']);
        $this->store('welcome_org_dm', ['body' => 'Short org note.']);
        $this->welcomeTest('en');

        $sent = $this->sent();
        self::assertCount(4, $sent);
        self::assertStringStartsWith("EMAIL Hello from Test Crew\n\n", $sent[0]);
        self::assertStringContainsString('Dear Person 3, 2 tasks.', $sent[0]);
        self::assertStringStartsWith("EMAIL Org guide\n\n", $sent[1]);
        self::assertStringContainsString('Organizer Person 3 of Test Crew.', $sent[1]);
        self::assertStringContainsString('Stored DM for Test Crew.', $sent[2]);
        self::assertStringContainsString('Short org note.', $sent[3]);
    }

    public function testTheDoorNewMemberEmailUsesTheStoredText(): void
    {
        $this->store('door_new_member', ['subject' => 'Hi there', 'body' => 'Welcome, new one.{group_line}']);
        $this->addAtTheDoor(true);

        $sent = $this->sent();
        self::assertCount(1, $sent);
        self::assertStringStartsWith("EMAIL Hi there\n\n", $sent[0]);
        self::assertStringContainsString(
            "Welcome, new one.\n\nThe second button opens the crew’s Telegram group, where tasks are posted.",
            $sent[0]
        );
    }

    public function testAnEditedDoorNewMemberTextStillGetsThePrivacyLine(): void
    {
        $this->store('door_new_member', ['body' => 'Welcome, new one.']);
        $this->addAtTheDoor(false);

        $sent = $this->sent();
        self::assertCount(1, $sent);
        self::assertStringContainsString(
            "Hi Person,\n\nWelcome, new one.\n\nWe store your email to run the crew.",
            $sent[0]
        );
    }

    public function testTheDoorNewMemberPrivacyLineIsInTheMembersLanguage(): void
    {
        $this->store('door_new_member', ['body' => 'Tervetuloa.']);
        $this->addAtTheDoor(false, 'fi');

        $sent = $this->sent();
        self::assertCount(1, $sent);
        self::assertStringContainsString(
            "Tervetuloa.\n\nTallennamme sähköpostiosoitteesi crew’n pyörittämiseksi.",
            $sent[0]
        );
        self::assertStringNotContainsString('We store your email', $sent[0]);
    }

    public function testTheSalesDeskLinkUsesTheStoredTexts(): void
    {
        $this->store('sales_desk_link', ['subject' => 'Desk {day}', 'body' => 'Your desk email for {day}.']);
        $this->store('sales_desk_link_dm', ['body' => 'Your desk DM for {day}.']);
        $this->queueCrewRun();
        $this->doorLinkSender()->run();

        $sent = $this->sent();
        self::assertCount(2, $sent);
        self::assertMatchesRegularExpression('/^EMAIL Desk \S+ \d+ \S+\n\n/', $sent[0]);
        self::assertMatchesRegularExpression('/Your desk email for \S+ \d+ \S+\./', $sent[0]);
        self::assertMatchesRegularExpression('/Your desk DM for \S+ \d+ \S+\./', $sent[1]);
    }

    public function testTheOrganizerNoticeUsesTheStoredTexts(): void
    {
        $this->store('organizer_notice', ['subject' => 'Organizing {day}', 'body' => 'Organizer email.']);
        $this->store('organizer_notice_dm', ['body' => 'Organizer DM.']);
        $this->queueOrganizerRun();
        $this->doorLinkSender()->run();

        $sent = $this->sent();
        self::assertCount(2, $sent);
        self::assertMatchesRegularExpression('/^EMAIL Organizing \S+ \d+ \S+\n\n/', $sent[0]);
        self::assertStringContainsString('Organizer email.', $sent[0]);
        self::assertStringContainsString('Organizer DM.', $sent[1]);
    }

    // ---- A person with no Telegram chat gets the crew page (E2) -----------

    /** The web-only lines name the Me tab of the Crew app, with no visible address (Owner 2026-11-07). */
    public function testAWebOnlyPersonsWelcomeEmailPointsToTheCrewPage(): void
    {
        $this->webOnly = true;
        $this->welcomeTest('en');

        $sent = $this->sent();
        self::assertCount(2, $sent);
        $body = explode("\n\n—\n", $sent[0])[0];
        self::assertStringContainsString(
            'This message explains how it works. You can see your own numbers any time in the Me tab of the Crew app.',
            $body
        );
        self::assertStringContainsString(
            'It is the first button in your sign-up confirmation, and the Me tab of the Crew app lists all your tickets.',
            $body
        );
        self::assertStringContainsString(
            'tasks you complete. To spend a credit, pick a night under Tickets in the Me tab of the Crew app.'
                . ' You then get a ticket for the night you choose.',
            $body
        );
        self::assertStringNotContainsString('/me', $body);
        self::assertStringNotContainsString('(https://', $body);
    }

    public function testAWebOnlyPersonsWelcomeEmailPointsToTheCrewPageInFinnish(): void
    {
        $this->webOnly = true;
        $this->welcomeTest('fi');

        $sent = $this->sent();
        self::assertStringContainsString(
            'Omat lukusi näet milloin vain Crew-sovelluksen Minä-välilehdellä.',
            $sent[0]
        );
        self::assertStringContainsString(
            'Käytä vapaalippu valitsemalla ilta kohdasta Liput Crew-sovelluksen Minä-välilehdellä. Saat silloin lipun',
            $sent[0]
        );
        self::assertStringNotContainsString('/me', $sent[0]);
    }

    public function testAWebOnlyPersonsSalesDeskLinkPointsToTheCrewPage(): void
    {
        $this->webOnly = true;
        $this->queueCrewRun();
        $this->doorLinkSender()->run();

        $sent = $this->sent();
        self::assertCount(1, $sent);
        self::assertStringContainsString(
            'Need another one? Tap Sales desk in the Me tab of the crew page (https://site.test/crew/).',
            $sent[0]
        );
        self::assertStringNotContainsString('/door', $sent[0]);
    }

    public function testAWebOnlyOrganizersNoticePointsToTheCrewPageInFinnish(): void
    {
        $this->webOnly = true;
        $this->queueOrganizerRun('fi');
        $this->doorLinkSender()->run();

        $sent = $this->sent();
        self::assertCount(1, $sent);
        self::assertStringContainsString(
            'Tarvitsetko uuden myyntipisteen linkin? Myyntipiste-kortti on crew-sivun Ylläpito-välilehdellä'
                . ' kohdassa Ovi (https://site.test/crew/).',
            $sent[0]
        );
        self::assertStringNotContainsString('Minä-välilehdellä', $sent[0], 'an organizer has no Sales desk card in Me');
        self::assertStringNotContainsString('/door', $sent[0]);
    }
}

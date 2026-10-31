<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Support\OrganizerGuide;
use EventCrew\Support\ReputationSettings;
use EventCrew\Support\RoleGuide;
use EventCrew\Support\WelcomeSender;

/**
 * The welcome message. Off by default; once on, each verified member gets the
 * email, the DM when they have a Telegram chat, and organizers also get the
 * organizer guide by both. The ledger stops every one of the four going twice.
 * The Settings test send ignores the switch and the ledger.
 */
final class WelcomeSenderTest extends SalesDeskTestCase
{
    /** @var array<int, array{to: string, subject: string, body: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mails = [];
        $this->options['eventcrew_email_html'] = '0';
        $this->options['eventcrew_signup_page_id'] = 7;

        Functions\when('get_permalink')->justReturn('https://site.test/crew/');
        Functions\when('get_bloginfo')->justReturn('Test Crew');
        Functions\when('wp_mail')->alias(
            function (string $to, string $subject, string $body): bool {
                $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

                return true;
            }
        );
    }

    /**
     * A verified member row; Telegram chat 500 + id unless $chat is false.
     *
     * @return array<string, mixed>
     */
    private function memberRow(int $id, bool $organizer = false, bool $chat = true): array
    {
        $row = $this->personRow($id, $organizer);
        $row['email_verified_at'] = '2026-07-01 10:00:00';

        if (! $chat) {
            $row['telegram_user_id'] = null;
            $row['telegram_chat_id'] = null;
        }

        return $row;
    }

    /**
     * Queues a heartbeat run: the four ledger lists, the recipients, then the
     * open tasks (task 5 with 1 of 3 taken, task 6 full).
     *
     * @param array<int, array<string, mixed>> $people
     * @param array<string, array<int, string>> $sent Person ids per kind already in the ledger.
     */
    private function queueRun(array $people, array $sent = []): void
    {
        foreach (
            [
                WelcomeSender::KIND_EMAIL,
                WelcomeSender::KIND_DM,
                WelcomeSender::KIND_ORG_EMAIL,
                WelcomeSender::KIND_ORG_DM,
            ] as $kind
        ) {
            $this->wpdb->nextCols[] = $sent[$kind] ?? [];
        }

        $this->wpdb->nextResults[] = $people;
        $this->queueOpenTasks();
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

    /**
     * @return array<int, string> The kinds recorded in the ledger, in order.
     */
    private function recorded(): array
    {
        $kinds = [];

        foreach ($this->wpdb->queries as $sql) {
            if (str_contains($sql, 'INSERT') && 1 === preg_match("/'(welcome_[a-z_]+)'/", $sql, $m)) {
                $kinds[] = $m[1];
            }
        }

        return $kinds;
    }

    private function dmsSent(): int
    {
        return count(array_filter($this->calledMethods(), static fn (string $m): bool => 'sendMessage' === $m));
    }

    public function testNothingGoesWhileItIsOff(): void
    {
        $this->queueRun([$this->memberRow(1)]);

        self::assertSame(0, $this->welcomeSender()->sendDue(25));
        $this->welcomeSender()->onEmailVerified(1);

        self::assertSame([], $this->mails);
        self::assertSame(0, $this->dmsSent());
        self::assertSame([], $this->wpdb->queries);
    }

    public function testAMemberGetsTheEmailAndTheDmOnceEach(): void
    {
        $this->options[WelcomeSender::ENABLED_OPTION] = '1';
        $this->queueRun([$this->memberRow(1)]);

        self::assertSame(1, $this->welcomeSender()->sendDue(25));

        self::assertCount(1, $this->mails);
        self::assertSame('p1@example.com', $this->mails[0]['to']);
        self::assertSame(WelcomeSender::subject(), $this->mails[0]['subject']);
        self::assertSame(1, $this->dmsSent());
        self::assertSame(501, $this->lastCallTo('sendMessage')['chat_id']);
        self::assertSame([WelcomeSender::KIND_EMAIL, WelcomeSender::KIND_DM], $this->recorded());
    }

    public function testTheLedgerStopsASecondSend(): void
    {
        $this->options[WelcomeSender::ENABLED_OPTION] = '1';
        $this->queueRun(
            [$this->memberRow(1)],
            [WelcomeSender::KIND_EMAIL => ['1'], WelcomeSender::KIND_DM => ['1']]
        );

        self::assertSame(0, $this->welcomeSender()->sendDue(25));
        self::assertSame([], $this->mails);
        self::assertSame(0, $this->dmsSent());
        self::assertSame([], $this->recorded());
    }

    public function testSomeoneWhoLinksTelegramLaterStillGetsTheDm(): void
    {
        $this->options[WelcomeSender::ENABLED_OPTION] = '1';
        $this->queueRun([$this->memberRow(1)], [WelcomeSender::KIND_EMAIL => ['1']]);

        self::assertSame(1, $this->welcomeSender()->sendDue(25));
        self::assertSame([], $this->mails);
        self::assertSame(1, $this->dmsSent());
        self::assertSame([WelcomeSender::KIND_DM], $this->recorded());
    }

    public function testAnOrganizerAlsoGetsTheOrganizerGuide(): void
    {
        $this->options[WelcomeSender::ENABLED_OPTION] = '1';
        $this->queueRun([$this->memberRow(1, true)]);

        $this->welcomeSender()->sendDue(25);

        self::assertCount(2, $this->mails);
        self::assertSame(OrganizerGuide::subject(), $this->mails[1]['subject']);
        self::assertStringContainsString('In short', $this->mails[1]['body']);
        self::assertStringNotContainsString('## ', $this->mails[1]['body']);
        self::assertSame(2, $this->dmsSent());
        self::assertStringContainsString(
            'The full guide, with the details, is in your email.',
            $this->lastCallTo('sendMessage')['text']
        );
        self::assertSame(
            [
                WelcomeSender::KIND_EMAIL,
                WelcomeSender::KIND_DM,
                WelcomeSender::KIND_ORG_EMAIL,
                WelcomeSender::KIND_ORG_DM,
            ],
            $this->recorded()
        );
    }

    public function testAMemberWithoutTelegramGetsOnlyTheEmail(): void
    {
        $this->options[WelcomeSender::ENABLED_OPTION] = '1';
        $this->queueRun([$this->memberRow(1, false, false)]);

        $this->welcomeSender()->sendDue(25);

        self::assertCount(1, $this->mails);
        self::assertSame(0, $this->dmsSent());
        self::assertSame([WelcomeSender::KIND_EMAIL], $this->recorded());
    }

    public function testARunStopsAtTheLimit(): void
    {
        $this->options[WelcomeSender::ENABLED_OPTION] = '1';
        $this->queueRun([$this->memberRow(1, false, false), $this->memberRow(2, false, false)]);

        self::assertSame(1, $this->welcomeSender()->sendDue(1));
        self::assertCount(1, $this->mails);
        self::assertSame('p1@example.com', $this->mails[0]['to']);
    }

    public function testTheDmHasAJoinOnlyButtonPerOpenTaskAndSkipsFullOnes(): void
    {
        $this->options[WelcomeSender::ENABLED_OPTION] = '1';
        $this->queueRun([$this->memberRow(1)]);

        $this->welcomeSender()->sendDue(25);

        $rows = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'];
        $callbacks = array_values(array_filter(array_map(
            static fn (array $row): ?string => $row[0]['callback_data'] ?? null,
            $rows
        )));

        // Task 5 has places; task 6 is full. 'j' joins only, never cancels.
        self::assertSame(['j:5'], $callbacks);
        self::assertSame(
            'https://site.test/crew/#' . RoleGuide::DESCRIPTIONS_ANCHOR,
            $rows[1][0]['url']
        );
        self::assertStringContainsString('2 of 3 places free', $this->lastCallTo('sendMessage')['text']);
    }

    public function testTheNumbersComeFromTheLiveSettings(): void
    {
        $this->options[WelcomeSender::ENABLED_OPTION] = '1';
        $this->options[ReputationSettings::TASKS_PER_CREDIT_OPTION] = 7;
        $this->queueRun([$this->memberRow(1, false, false)]);

        $this->welcomeSender()->sendDue(25);

        self::assertStringContainsString('1 free-entry credit for every 7 tasks', $this->mails[0]['body']);
    }

    public function testAConfirmedEmailWelcomesThePersonAtOnce(): void
    {
        $this->options[WelcomeSender::ENABLED_OPTION] = '1';
        $this->wpdb->nextRows[] = $this->memberRow(1, false, false);
        $this->queueOpenTasks();

        $this->welcomeSender()->onEmailVerified(1);

        self::assertCount(1, $this->mails);
        self::assertSame([WelcomeSender::KIND_EMAIL], $this->recorded());
    }

    public function testTheTestSendIgnoresTheSwitchAndRecordsNothing(): void
    {
        $this->queueOpenTasks();

        $results = $this->welcomeSender()->sendTest(Person::fromRow($this->memberRow(1, true)));

        self::assertSame(
            [
                WelcomeSender::KIND_EMAIL => true,
                WelcomeSender::KIND_DM => true,
                WelcomeSender::KIND_ORG_EMAIL => true,
                WelcomeSender::KIND_ORG_DM => true,
            ],
            $results
        );
        self::assertCount(2, $this->mails);
        self::assertSame(2, $this->dmsSent());
        self::assertSame([], $this->recorded());
    }

    public function testBothEmailsLinkToTheGroupAndTheOrganizerOneToWpAdmin(): void
    {
        $this->options[\EventCrew\Telegram\BoardService::GROUP_LINK_OPTION] = 'https://t.me/+crew';
        $this->queueOpenTasks();

        $this->welcomeSender()->sendTest(Person::fromRow($this->memberRow(1, true)));

        self::assertStringContainsString('Open our group in Telegram: https://t.me/+crew', $this->mails[0]['body']);
        self::assertStringNotContainsString('wp-admin/admin.php?page=eventcrew', $this->mails[0]['body']);
        self::assertStringContainsString('Open our group in Telegram: https://t.me/+crew', $this->mails[1]['body']);
        self::assertStringContainsString(
            'Open EventCrew in wp-admin: https://site.test/wp-admin/admin.php?page=eventcrew',
            $this->mails[1]['body']
        );
    }

    public function testNoGroupLinkMeansNoGroupButton(): void
    {
        $this->queueOpenTasks();

        $this->welcomeSender()->sendTest(Person::fromRow($this->memberRow(1, true)));

        self::assertStringNotContainsString('Open our group in Telegram', $this->mails[0]['body']);
        self::assertStringNotContainsString('Open our group in Telegram', $this->mails[1]['body']);
    }

    public function testTheTestSendMarksMessagesThatDoNotApply(): void
    {
        $this->queueOpenTasks();

        $results = $this->welcomeSender()->sendTest(Person::fromRow($this->memberRow(1, false, false)));

        self::assertTrue($results[WelcomeSender::KIND_EMAIL]);
        self::assertNull($results[WelcomeSender::KIND_DM]);
        self::assertNull($results[WelcomeSender::KIND_ORG_EMAIL]);
        self::assertNull($results[WelcomeSender::KIND_ORG_DM]);
    }

    public function testTheDmHasNoHeadingMarks(): void
    {
        $this->queueOpenTasks();

        $this->welcomeSender()->sendTest(Person::fromRow($this->memberRow(1)));

        self::assertStringNotContainsString('## ', $this->lastCallTo('sendMessage')['text']);
        self::assertStringContainsString('Tickets', $this->lastCallTo('sendMessage')['text']);
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\PrivacyPolicy;
use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Support\OrganizerGuide;
use EventCrew\Support\ReputationSettings;
use EventCrew\Support\RoleGuide;
use EventCrew\Support\Roles;
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
        $this->options[PrivacyPolicy::CONTACT_OPTION] = 'maintainer@example.org';

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
        // The bot's Admin menu on top, the Admin tab, wp-admin, Help at the
        // bottom; no People (Owner 2026-11-07).
        self::assertSame(
            ['🛠 Admin', '🛠️ Open the Admin tab', '🧰 Open EventCrew in wp-admin', '📖 Help'],
            array_map(
                static fn (array $row): string => $row[0]['text'],
                $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard']
            )
        );
        self::assertSame(
            ['mn:admin'],
            array_values(array_filter(array_map(
                static fn (array $row): ?string => $row[0]['callback_data'] ?? null,
                $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard']
            )))
        );
        self::assertStringNotContainsString('https://', self::withoutLinks($this->lastCallTo('sendMessage')['text']));
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

    /**
     * One See open tasks button and the task descriptions, in the bot, and no
     * button per task (Owner 2026-11-07). The full task is not listed.
     */
    public function testTheDmHasOneSeeOpenTasksButtonAndNoButtonPerTask(): void
    {
        $this->options[WelcomeSender::ENABLED_OPTION] = '1';
        $this->queueRun([$this->memberRow(1)]);

        $this->welcomeSender()->sendDue(25);

        $dm = $this->lastCallTo('sendMessage');
        $rows = $dm['reply_markup']['inline_keyboard'];

        // My info on top, no task-info button (Owner 2026-11-07).
        self::assertSame(['👤 My info', '📋 See open tasks'], array_map(
            static fn (array $row): string => $row[0]['text'],
            $rows
        ));
        // No bot username in the test: the bot's own menu buttons.
        self::assertSame(['mn:me', 'mn:board'], array_map(
            static fn (array $row): string => $row[0]['callback_data'] ?? '',
            $rows
        ));
        self::assertStringContainsString('2 of 3 places free', $dm['text']);
        self::assertStringNotContainsString('0 of 3 places free', $dm['text']);
        self::assertStringContainsString('To sign up, tap 📋 See open tasks below.', $dm['text']);
    }

    /** Each open task is its name and time, then its free places on the next line (Owner 2026-11-07). */
    public function testTheDmPutsEachTasksFreePlacesOnALineOfItsOwn(): void
    {
        $this->options[WelcomeSender::ENABLED_OPTION] = '1';
        $this->queueRun([$this->memberRow(1)]);
        $this->wpdb->nextResults[count($this->wpdb->nextResults) - 1] = [
            ['task_id' => 5, 'taken' => 1],
            ['task_id' => 6, 'taken' => 1],
        ];

        $this->welcomeSender()->sendDue(25);

        self::assertMatchesRegularExpression(
            "/\d\d:\d\d\n2 of 3 places free\n\n[^\n]+\d\d:\d\d\n2 of 3 places free/u",
            $this->lastCallTo('sendMessage')['text']
        );
    }

    /** With the bot set up, See open tasks opens the next date's board in the bot (board-<Ymd>). */
    public function testTheDmSeeOpenTasksOpensTheNextDateInTheBot(): void
    {
        $this->options[\EventCrew\Telegram\BoardService::USERNAME_OPTION] = 'crewbot';
        $this->options[\EventCrew\Telegram\BoardService::GROUP_LINK_OPTION] = 'https://t.me/+crew';
        $this->options[WelcomeSender::ENABLED_OPTION] = '1';
        $this->queueRun([$this->memberRow(1)]);

        $this->welcomeSender()->sendDue(25);

        $rows = $this->lastCallTo('sendMessage')['reply_markup']['inline_keyboard'];

        self::assertCount(3, $rows);
        self::assertStringStartsWith('https://t.me/crewbot?start=board-20260720', $rows[1][0]['url']);
        self::assertSame('💬 Telegram group', $rows[2][0]['text']);
        self::assertSame('https://t.me/+crew', $rows[2][0]['url']);
    }

    /**
     * The organize task is for organizers only, so a newcomer's welcome never
     * lists it - not even to an organizer, since it has no places to count.
     */
    public function testTheDmNeverOffersTheOrganizeTask(): void
    {
        $this->options[WelcomeSender::ENABLED_OPTION] = '1';

        // The four ledger lists, all empty.
        array_push($this->wpdb->nextCols, [], [], [], []);

        $this->wpdb->nextResults[] = [$this->memberRow(1)];
        $this->wpdb->nextResults[] = [
            $this->taskRow(5, 'decorate', '13:00', '14:00'),
            array_merge($this->taskRow(7, 'organize', '13:00', '14:00'), ['capacity' => 1, 'starts_at' => null, 'ends_at' => null]),
        ];
        $this->wpdb->nextResults[] = [['task_id' => 5, 'taken' => 1]];

        $this->welcomeSender()->sendDue(25);

        $text = $this->lastCallTo('sendMessage')['text'];
        $open = substr($text, (int) strpos($text, '📋 Open tasks'));
        $open = substr($open, 0, (int) strpos($open, 'To sign up'));

        self::assertStringContainsString('Decorate', $open);
        self::assertSame(1, substr_count($open, 'places free'));
    }

    /** A task of an archived role is never offered, like on the board (Owner 2026-11-07). */
    public function testTheDmNeverOffersATaskOfAnArchivedRole(): void
    {
        $this->options[WelcomeSender::ENABLED_OPTION] = '1';
        $this->options[Roles::OPTION_NAME] = [
            ['slug' => 'decorate', 'label' => 'Decorate', 'emoji' => '🎈'],
            ['slug' => 'random', 'label' => 'Random', 'archived' => true],
        ];

        // The four ledger lists, all empty.
        array_push($this->wpdb->nextCols, [], [], [], []);

        $this->wpdb->nextResults[] = [$this->memberRow(1)];
        $this->wpdb->nextResults[] = [
            $this->taskRow(5, 'decorate', '13:00', '14:00'),
            $this->taskRow(6, 'random', '15:00', '16:00'),
        ];
        $this->wpdb->nextResults[] = [];

        $this->welcomeSender()->sendDue(25);

        $text = $this->lastCallTo('sendMessage')['text'];

        self::assertStringContainsString('Decorate', $text);
        self::assertStringNotContainsString('Random', $text);
        self::assertSame(1, substr_count($text, 'places free'));
    }

    /** The open tasks come grouped by date, at most 3 dates (Owner 2026-11-07). */
    public function testTheOpenTasksAreGroupedByDateAtMostThree(): void
    {
        $tasks = [];
        $counts = [];

        foreach (['2026-07-20', '2026-07-20', '2026-07-21', '2026-07-22', '2026-07-23'] as $i => $date) {
            $tasks[] = array_merge($this->taskRow(10 + $i, 'decorate', '13:00', '14:00'), [
                'task_date' => $date,
                'starts_at' => $date . ' 13:00:00',
                'ends_at' => $date . ' 14:00:00',
            ]);
            $counts[] = ['task_id' => 10 + $i, 'taken' => 0];
        }
        $this->wpdb->nextResults[] = $tasks;
        $this->wpdb->nextResults[] = $counts;

        $this->welcomeSender()->sendTest(Person::fromRow($this->memberRow(2)));

        $body = $this->mails[0]['body'];
        self::assertSame(3, substr_count($body, '📅 '));
        self::assertSame(4, substr_count($body, '3 of 3 places free'));
        self::assertStringNotContainsString('23 Jul', $body);
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

        self::assertStringContainsString('💬 Telegram group: https://t.me/+crew', $this->mails[0]['body']);
        self::assertStringContainsString('💬 Telegram group: https://t.me/+crew', $this->mails[1]['body']);
        self::assertStringContainsString(
            'Open EventCrew in wp-admin: https://site.test/wp-admin/admin.php?page=eventcrew',
            $this->mails[1]['body']
        );
    }

    /** wp-admin is only in the organizer email, not the member one (Owner 2026-11-07). */
    public function testOnlyTheOrganizerWelcomeEmailLinksToWpAdmin(): void
    {
        $this->queueOpenTasks();
        $this->welcomeSender()->sendTest(Person::fromRow($this->memberRow(1, true)));
        self::assertStringNotContainsString('wp-admin', $this->mails[0]['body']);
        self::assertStringContainsString(
            'Open EventCrew in wp-admin: https://site.test/wp-admin/admin.php?page=eventcrew',
            $this->mails[1]['body']
        );

        $this->mails = [];
        $this->queueOpenTasks();
        $this->welcomeSender()->sendTest(Person::fromRow($this->memberRow(2)));
        self::assertStringNotContainsString('wp-admin', $this->mails[0]['body']);
    }

    /**
     * No visible web address in the welcome text (Owner 2026-11-07): the email
     * has buttons and links, the DM shows its links by their label only.
     */
    public function testTheWelcomeShowsNoAddressInItsText(): void
    {
        $this->options['eventcrew_email_html'] = '1';
        $this->queueOpenTasks();

        $this->welcomeSender()->sendTest(Person::fromRow($this->memberRow(1, true)));

        foreach ($this->mails as $mail) {
            self::assertStringNotContainsString('https://', self::withoutLinks($mail['body']), $mail['subject']);
        }

        foreach ($this->telegramCalls as $call) {
            if ('sendMessage' !== $call['method']) {
                continue;
            }

            self::assertSame('HTML', $call['body']['parse_mode']);
            self::assertStringNotContainsString('https://', self::withoutLinks($call['body']['text']));
        }

        self::assertStringContainsString(
            '<a href="https://site.test/crew/#eventcrew-panel-help">📖 Help</a>',
            $this->telegramCalls[0]['body']['text'] ?? $this->lastCallTo('sendMessage')['text']
        );
        self::assertStringNotContainsString('This guide is also in the Crew app', $this->mails[0]['body']);
        self::assertStringContainsString('Contents', $this->mails[1]['body']);
    }

    /** No bot command anywhere in the member's welcome (Owner 2026-11-07): people just write to the bot. */
    public function testTheMemberWelcomeNamesNoBotCommand(): void
    {
        $this->queueOpenTasks();

        $this->welcomeSender()->sendTest(Person::fromRow($this->memberRow(2)));

        $texts = [
            self::withoutLinks($this->mails[0]['body']),
            self::withoutLinks($this->lastCallTo('sendMessage')['text']),
            WelcomeSender::helpText(),
        ];

        foreach ($texts as $text) {
            self::assertSame(0, preg_match('~(^|[\s(])/[a-z]+~m', $text), $text);
        }
    }

    /** The "Talking to the bot" section, with the maintainer from Settings or the default. */
    public function testTheWelcomeExplainsTalkingToTheBotAndNamesTheMaintainer(): void
    {
        $this->queueOpenTasks();
        $this->welcomeSender()->sendTest(Person::fromRow($this->memberRow(2)));

        $body = $this->mails[0]['body'];
        self::assertStringContainsString('Talking to the bot', $body);
        self::assertStringContainsString('write what you want in your own words', $body);
        self::assertStringContainsString('it passes it on to the organizers', $body);
        self::assertStringContainsString(
            'contact the maintainer, maintainer@example.org, or just try again a bit later.',
            $body
        );
        self::assertStringContainsString('Talking to the bot', $this->lastCallTo('sendMessage')['text']);

        $this->mails = [];
        $this->options[WelcomeSender::MAINTAINER_OPTION] = 'Kim, kim@example.com';
        $this->queueOpenTasks();
        $this->welcomeSender()->sendTest(Person::fromRow($this->memberRow(2)));

        self::assertStringContainsString('contact the maintainer, Kim, kim@example.com, or', $this->mails[0]['body']);
        self::assertStringNotContainsString('maintainer', $this->mails[0]['body']);
    }

    /**
     * One button and a line of small links that do not repeat it (Owner
     * 2026-11-08): See open tasks on the web board, then Open in Telegram
     * (the bot's board) · the group · Help · the guide.
     */
    public function testTheMemberEmailHasOneButtonAndALineOfLinks(): void
    {
        $this->options['eventcrew_email_html'] = '1';
        $this->options[\EventCrew\Telegram\BoardService::USERNAME_OPTION] = 'crewbot';
        $this->options[\EventCrew\Telegram\BoardService::GROUP_LINK_OPTION] = 'https://t.me/+crew';
        $this->queueOpenTasks();

        $this->welcomeSender()->sendTest(Person::fromRow($this->memberRow(2)));

        $body = $this->mails[0]['body'];
        $buttons = self::buttonUrls($body);

        self::assertSame(['https://site.test/crew/#eventcrew-panel-board'], $buttons);
        self::assertSame(1, preg_match_all('~See open tasks</a>~', $body));
        self::assertStringNotContainsString('Open in Telegram:', $body);

        // The links share one line, in this order.
        self::assertMatchesRegularExpression(
            '~<p[^>]*><a href="https://t\.me/crewbot\?start=board-20260720"[^>]*>Open in Telegram</a> · '
                . '<a href="https://t\.me/\+crew"[^>]*>💬 Telegram group</a> · '
                . '<a href="https://site\.test/crew/#eventcrew-panel-help"[^>]*>📖 Help</a>~u',
            $body
        );

        preg_match_all('~<a href="([^"]+)"~', $body, $all);
        $links = array_diff($all[1], $buttons);
        self::assertSame(array_values(array_unique($links)), array_values($links));
        self::assertSame([], array_intersect($links, $buttons));
    }

    /**
     * An organizer's member welcome email has the same one button; the
     * organizer email has the Admin tab and wp-admin as buttons, Help and the
     * group as small links (Owner 2026-11-08).
     */
    public function testAnOrganizersMemberWelcomeEmailHasNoWpAdminButton(): void
    {
        $this->options['eventcrew_email_html'] = '1';
        $this->options[\EventCrew\Telegram\BoardService::GROUP_LINK_OPTION] = 'https://t.me/+crew';
        $this->queueOpenTasks();

        $this->welcomeSender()->sendTest(Person::fromRow($this->memberRow(1, true)));

        self::assertSame(['https://site.test/crew/#eventcrew-panel-board'], self::buttonUrls($this->mails[0]['body']));
        self::assertSame(
            [
                'https://site.test/crew/#eventcrew-panel-admin',
                'https://site.test/wp-admin/admin.php?page=eventcrew',
            ],
            self::buttonUrls($this->mails[1]['body'])
        );
        self::assertMatchesRegularExpression(
            '~<a href="https://site\.test/crew/#eventcrew-help-organizer"[^>]*>📖 Help</a> · '
                . '<a href="https://t\.me/\+crew"[^>]*>💬 Telegram group</a>~u',
            $this->mails[1]['body']
        );
        self::assertStringNotContainsString('Open EventCrew in wp-admin:', $this->mails[1]['body']);
    }

    /** The organizer guide's People and Sales desk open in the bot, as links. */
    public function testTheOrganizerGuideLinksToPeopleAndTheSalesDeskInTheBot(): void
    {
        $this->options['eventcrew_email_html'] = '1';
        $this->options[\EventCrew\Telegram\BoardService::USERNAME_OPTION] = 'crewbot';
        $this->queueOpenTasks();

        $this->welcomeSender()->sendTest(Person::fromRow($this->memberRow(1, true)));

        self::assertStringContainsString('https://t.me/crewbot?start=people', $this->mails[1]['body']);
        self::assertStringContainsString('https://t.me/crewbot?start=sales', $this->mails[1]['body']);
        self::assertStringContainsString(
            'https://t.me/crewbot?start=sales',
            $this->lastCallTo('sendMessage')['text']
        );
    }

    /** $text without its <a> tags, their addresses with them. */
    private static function withoutLinks(string $text): string
    {
        return (string) preg_replace('~<a\s[^>]*>~', '', $text);
    }

    /**
     * The addresses of an HTML email's buttons, in order.
     *
     * @return list<string>
     */
    private static function buttonUrls(string $html): array
    {
        preg_match_all('~<td bgcolor="[^"]*"[^>]*><a href="([^"]+)"~', $html, $m);

        return $m[1];
    }

    public function testTheHelpTabTextHasNoOpenTasks(): void
    {
        $text = WelcomeSender::helpText();

        self::assertStringContainsString('## 🎟️ Tickets', $text);
        self::assertStringNotContainsString('Open tasks', $text);
    }

    public function testNoGroupLinkMeansNoGroupButton(): void
    {
        $this->queueOpenTasks();

        $this->welcomeSender()->sendTest(Person::fromRow($this->memberRow(1, true)));

        self::assertStringNotContainsString('💬 Telegram group', $this->mails[0]['body']);
        self::assertStringNotContainsString('💬 Telegram group', $this->mails[1]['body']);
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

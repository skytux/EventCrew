<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\History;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Telegram\ActingAs;
use EventCrew\Telegram\MenuButtons;
use EventCrew\Telegram\ProfileService;

/**
 * 👤 Act as: an organizer runs a person's own screens and buttons as them,
 * the changes are told to the person, and the organizer is checked again at
 * every update.
 */
final class ActingAsTest extends TelegramTestCase
{
    private const ORGANIZER_TG = 100;
    private const PERSON_TG = 555;

    /** @var list<array{0: int, 1: string}> Every wp_schedule_single_event: [time, hook]. */
    private array $scheduled = [];

    /** @var list<string> Every wp_clear_scheduled_hook: the hook. */
    private array $cleared = [];

    /** @var list<array{to: string, subject: string, body: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_the_title')->justReturn('');
        $this->scheduled = [];
        $this->cleared = [];
        $this->mails = [];
        Functions\when('wp_mail')->alias(function (string $to, string $subject, string $body): bool {
            $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

            return true;
        });
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('UTC'));
        // The plain-text mail, so the whole text can be compared.
        $this->options[EmailTemplate::HTML_OPTION] = false;
        Functions\when('wp_schedule_single_event')->alias(function (int $time, string $hook, array $args = []): bool {
            self::assertSame([self::ORGANIZER_TG], $args);
            $this->scheduled[] = [$time, $hook];

            return true;
        });
        Functions\when('wp_clear_scheduled_hook')->alias(function (string $hook): int {
            $this->cleared[] = $hook;

            return 1;
        });
    }

    protected function tearDown(): void
    {
        PersonRepository::standIn(self::ORGANIZER_TG, null);
        History::reset();

        parent::tearDown();
    }

    private function acting(): ActingAs
    {
        $people = new PersonRepository();

        return new ActingAs(
            $people,
            $this->client(),
            new ProfileService(
                $people,
                new AssignmentRepository(),
                new TaskRepository(),
                $this->standing(),
                $this->client()
            ),
            new TaskRepository(),
            new Mailer(new Logger(), new EmailTemplate(new Logger()))
        );
    }

    /** @return array<string, mixed> */
    private function organizerRow(bool $organizer = true): array
    {
        return [
            'id' => 1,
            'email' => 'lou@example.com',
            'display_name' => 'Lou',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => self::ORGANIZER_TG,
            'is_organizer' => $organizer ? 1 : 0,
        ];
    }

    /** @return array<string, mixed> */
    private function personRow(): array
    {
        return [
            'id' => 7,
            'email' => 'sam@example.com',
            'display_name' => 'Sam',
            'email_verified_at' => '2026-07-01 00:00:00',
            'telegram_user_id' => self::PERSON_TG,
        ];
    }

    /** @return array<string, mixed> */
    private function taskRow(): array
    {
        return [
            'id' => 3,
            'task_date' => '2026-07-20',
            'role_slug' => 'decorate',
            'capacity' => 2,
            'event_post_id' => null,
            'event_label' => 'Dance',
            'starts_at' => null,
            'ends_at' => null,
            'notes' => '',
            'created_at' => '2026-07-01 00:00:00',
        ];
    }

    /** @return array<string, mixed> */
    private function tap(string $data): array
    {
        return ['callback_query' => [
            'id' => 'cb1',
            'data' => $data,
            'from' => ['id' => self::ORGANIZER_TG],
            'message' => ['message_id' => 9, 'chat' => ['id' => self::ORGANIZER_TG, 'type' => 'private']],
        ]];
    }

    private function startActing(int $until, int $warning = 0): void
    {
        $state = ['person_id' => 7, 'until' => $until, 'warned' => $warning > 0, 'warning' => $warning];
        set_transient('eventcrew_tg_acting_' . self::ORGANIZER_TG, $state, DAY_IN_SECONDS);
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $state = get_transient('eventcrew_tg_acting_' . self::ORGANIZER_TG);
        self::assertIsArray($state);

        return $state;
    }

    public function testActAsSchedulesTheWarningTwoMinutesBeforeTheEnd(): void
    {
        $this->wpdb->nextRows[] = $this->organizerRow();
        $this->wpdb->nextRows[] = $this->personRow();
        $this->wpdb->nextRows[] = $this->personRow();

        $this->acting()->route($this->tap('act:7'), false, static function (): void {
        });

        $until = $this->state()['until'];
        self::assertSame([[$until - ActingAs::WARN_SECONDS, ActingAs::WARN_HOOK]], $this->scheduled);
    }

    public function testEachOfTheirActionsMovesTheEnd(): void
    {
        $this->startActing(time() + 60);
        $this->wpdb->nextRows[] = $this->organizerRow();
        $this->wpdb->nextRows[] = $this->personRow();

        $this->acting()->route($this->tap(MenuButtons::ME), false, static function (): void {
        });

        self::assertGreaterThanOrEqual(time() + ActingAs::ACTIVE_SECONDS - 1, $this->state()['until']);
        self::assertSame([], $this->scheduled, 'before the warning, the warning event moves itself');
    }

    public function testTheOrganizersOwnActionsDoNotMoveTheEnd(): void
    {
        $until = time() + 600;
        $this->startActing($until);
        $this->wpdb->nextRows[] = $this->organizerRow();
        $this->wpdb->nextRows[] = $this->personRow();

        $this->acting()->route($this->tap('perm:who:8'), false, static function (): void {
        });

        self::assertSame($until, $this->state()['until']);
    }

    public function testAnActionAfterTheWarningRemovesItsButtonsAndWarnsAgainLater(): void
    {
        $this->startActing(time() + 60, 42);
        $this->wpdb->nextRows[] = $this->organizerRow();
        $this->wpdb->nextRows[] = $this->personRow();

        $this->acting()->route($this->tap(MenuButtons::ME), false, static function (): void {
        });

        $state = $this->state();
        self::assertFalse($state['warned']);
        self::assertSame(42, $this->lastCallTo('editMessageReplyMarkup')['message_id']);
        self::assertSame([[$state['until'] - ActingAs::WARN_SECONDS, ActingAs::WARN_HOOK]], $this->scheduled);
        self::assertContains(ActingAs::END_HOOK, $this->cleared);
    }

    public function testTheWarningOffersKeepAndStopAndSchedulesTheEnd(): void
    {
        $until = time() + ActingAs::WARN_SECONDS;
        $this->startActing($until);
        $this->wpdb->nextRows[] = $this->organizerRow();
        $this->wpdb->nextRows[] = $this->personRow();
        $this->telegramResults['sendMessage'] = ['message_id' => 42];

        $this->acting()->onWarnDue(self::ORGANIZER_TG);

        $sent = $this->lastCallTo('sendMessage');
        self::assertSame(self::ORGANIZER_TG, $sent['chat_id']);
        self::assertSame('⏳ Acting as Sam ends in 2 minutes.', $sent['text']);
        self::assertSame(ActingAs::KEEP, $this->callbackOf($sent, '▶️'));
        self::assertSame(ActingAs::STOP, $this->callbackOf($sent, '⏹'));
        self::assertTrue($this->state()['warned']);
        self::assertSame(42, $this->state()['warning']);
        self::assertSame([[$until, ActingAs::END_HOOK]], $this->scheduled);
    }

    public function testTheWarningMovesWhenTheyActedSince(): void
    {
        $until = time() + 600;
        $this->startActing($until);

        $this->acting()->onWarnDue(self::ORGANIZER_TG);

        self::assertNull($this->lastCallTo('sendMessage'));
        self::assertSame([[$until - ActingAs::WARN_SECONDS, ActingAs::WARN_HOOK]], $this->scheduled);
    }

    public function testTheEndSaysSoWithBackToPeople(): void
    {
        $this->startActing(time() - 1, 42);
        $this->wpdb->nextRows[] = $this->organizerRow();
        $this->wpdb->nextRows[] = $this->personRow();

        $this->acting()->onEndDue(self::ORGANIZER_TG);

        self::assertFalse(get_transient('eventcrew_tg_acting_' . self::ORGANIZER_TG));
        self::assertSame(42, $this->lastCallTo('editMessageReplyMarkup')['message_id']);
        $sent = $this->lastCallTo('sendMessage');
        self::assertSame('Stopped acting as Sam: 15 minutes without an action.', $sent['text']);
        self::assertSame(MenuButtons::PEOPLE, $this->callbackOf($sent, '⬅'));
    }

    public function testTheEndWaitsWhenTheyActedSince(): void
    {
        $this->startActing(time() + 600);

        $this->acting()->onEndDue(self::ORGANIZER_TG);

        self::assertNotFalse(get_transient('eventcrew_tg_acting_' . self::ORGANIZER_TG));
        self::assertSame([], $this->telegramCalls);
    }

    public function testKeepActing(): void
    {
        $this->startActing(time() + 60, 42);
        $this->wpdb->nextRows[] = $this->organizerRow();
        $this->wpdb->nextRows[] = $this->personRow();

        $this->acting()->route($this->tap(ActingAs::KEEP), false, static function (): void {
            self::fail('Keep acting is not routed further');
        });

        $state = $this->state();
        self::assertGreaterThanOrEqual(time() + ActingAs::ACTIVE_SECONDS - 1, $state['until']);
        self::assertFalse($state['warned']);
        self::assertSame(42, $this->lastCallTo('editMessageReplyMarkup')['message_id']);
        self::assertSame('Still acting as Sam.', $this->lastCallTo('answerCallbackQuery')['text']);
        self::assertNull($this->lastCallTo('sendMessage'));
    }

    public function testTheSessionsChangesAreKeptAndMailedOnceAtTheEnd(): void
    {
        $this->startActing(time() + 600);
        set_transient('eventcrew_tg_acting_' . self::ORGANIZER_TG, $this->state() + [
            'organizer_id' => 1,
            'started' => time() - 300,
        ], DAY_IN_SECONDS);
        $this->wpdb->nextRows[] = $this->organizerRow(); // the organizer, checked again
        $this->wpdb->nextRows[] = $this->personRow();    // the person
        $this->wpdb->nextRows[] = $this->taskRow();      // the DM: the task

        $this->acting()->route($this->tap('j:3:2026-07-20'), false, static function (): void {
            History::observed(
                ['kind' => History::ASSIGNED, 'person_id' => 7, 'task_id' => 3, 'event_date' => '2026-07-20']
            );
        });

        $this->wpdb->nextRows[] = $this->organizerRow();
        $this->wpdb->nextRows[] = $this->personRow();

        $this->acting()->route($this->tap('np:x'), false, static function (): void {
        });

        self::assertCount(2, $this->state()['changes']);
        self::assertSame([], $this->mails, 'nothing mailed while acting');

        // Fifteen minutes on, the end.
        $state = $this->state();
        $state['until'] = time() - 1;
        set_transient('eventcrew_tg_acting_' . self::ORGANIZER_TG, $state, DAY_IN_SECONDS);
        $this->wpdb->nextRows[] = $this->personRow();    // the mail: the person
        $this->wpdb->nextRows[] = $this->organizerRow(); // who acted
        $this->wpdb->nextRows[] = $this->taskRow();      // the line: the task
        $this->wpdb->nextRows[] = $this->organizerRow(); // the stop message
        $this->wpdb->nextRows[] = $this->personRow();

        $this->acting()->onEndDue(self::ORGANIZER_TG);

        self::assertCount(1, $this->mails);
        self::assertSame('sam@example.com', $this->mails[0]['to']);
        self::assertSame('Lou made changes for you', $this->mails[0]['subject']);
        self::assertStringContainsString('Lou acted for you in the Telegram bot from ', $this->mails[0]['body']);
        self::assertStringContainsString('- Lou signed you up for ', $this->mails[0]['body']);
        self::assertStringContainsString('- Lou changed your notifications.', $this->mails[0]['body']);
    }

    public function testASessionWithoutChangesMailsNothing(): void
    {
        $this->startActing(time() + 600);
        $this->wpdb->nextRows[] = $this->organizerRow();
        $this->wpdb->nextRows[] = $this->personRow();

        $this->acting()->route($this->tap(ActingAs::STOP), false, static function (): void {
        });

        self::assertSame('Stopped acting as Sam.', $this->lastCallTo('sendMessage')['text']);
        self::assertSame([], $this->mails);
    }

    public function testActAsOnSomeoneElseEndsTheSessionWithItsEmail(): void
    {
        $this->startActing(time() + 600);
        set_transient('eventcrew_tg_acting_' . self::ORGANIZER_TG, $this->state() + [
            'organizer_id' => 1,
            'started' => time() - 300,
            'changes' => [['kind' => 'language']],
        ], DAY_IN_SECONDS);
        $other = ['id' => 8, 'display_name' => 'Kim', 'email' => 'kim@example.com', 'telegram_user_id' => 556]
            + $this->personRow();
        $this->wpdb->nextRows[] = $this->organizerRow(); // the sender
        $this->wpdb->nextRows[] = $other;                // whom they act as now
        $this->wpdb->nextRows[] = $this->personRow();    // the mail: Sam
        $this->wpdb->nextRows[] = $this->organizerRow(); // who acted

        $this->acting()->route($this->tap('act:8'), false, static function (): void {
        });

        self::assertCount(1, $this->mails);
        self::assertSame('sam@example.com', $this->mails[0]['to']);
        self::assertStringContainsString('- Lou changed your language.', $this->mails[0]['body']);
        self::assertSame(8, $this->state()['person_id']);
        self::assertSame([], $this->state()['changes']);
    }

    public function testKeepActingAfterTheEnd(): void
    {
        $this->startActing(time() - 1, 42);

        $this->acting()->route($this->tap(ActingAs::KEEP), false, static function (): void {
        });

        self::assertFalse(get_transient('eventcrew_tg_acting_' . self::ORGANIZER_TG));
        self::assertStringStartsWith('Acting as someone has ended', $this->lastCallTo('answerCallbackQuery')['text']);
    }

    public function testNothingChangesWhenNotActing(): void
    {
        $ran = false;

        $this->acting()->route($this->tap(MenuButtons::ME), false, function () use (&$ran): void {
            $ran = true;
            self::assertNull(ActingAs::subjectIn(self::ORGANIZER_TG));
        });

        self::assertTrue($ran);
        self::assertSame([], $this->wpdb->queries);
    }

    public function testActAsTapShowsTheirMeWithTheBannerAndStop(): void
    {
        $this->wpdb->nextRows[] = $this->organizerRow(); // the organizer
        $this->wpdb->nextRows[] = $this->personRow();    // the person
        $this->wpdb->nextRows[] = $this->personRow();    // onMe: the stand-in

        $this->acting()->route($this->tap('act:7'), false, static function (): void {
            self::fail('Act as is not routed further');
        });

        $state = get_transient('eventcrew_tg_acting_' . self::ORGANIZER_TG);
        self::assertSame(7, $state['person_id']);
        self::assertGreaterThan(time(), $state['until']);

        $sent = $this->lastCallTo('sendMessage');
        self::assertNotNull($sent);
        self::assertSame(self::ORGANIZER_TG, $sent['chat_id']);
        self::assertStringStartsWith('👤 Acting as Sam. Every change is logged under your name.', $sent['text']);
        self::assertSame(ActingAs::STOP, $this->callbackOf($sent, '⏹'));
        self::assertNull($this->callbackOf($sent, '🛠'), 'no Admin while acting');
        self::assertNull(PersonRepository::standInFor(self::ORGANIZER_TG));
    }

    public function testOnlyAnOrganizerCanStart(): void
    {
        $this->wpdb->nextRows[] = $this->organizerRow(false);

        $this->acting()->route($this->tap('act:7'), false, static function (): void {
        });

        self::assertFalse(get_transient('eventcrew_tg_acting_' . self::ORGANIZER_TG));
        self::assertTrue($this->lastCallTo('answerCallbackQuery')['show_alert']);
        self::assertNull($this->lastCallTo('sendMessage'));
    }

    public function testTheirButtonRunsAsThemAndTheyAreToldWhatChanged(): void
    {
        $this->startActing(time() + 600);
        $this->wpdb->nextRows[] = $this->organizerRow(); // the organizer, checked again
        $this->wpdb->nextRows[] = $this->personRow();    // the person
        $this->wpdb->nextRows[] = $this->personRow();    // the handler: the stand-in
        $this->wpdb->nextRows[] = $this->taskRow();      // the DM: the task

        $this->acting()->route($this->tap('j:3:2026-07-20'), false, function (): void {
            $found = (new PersonRepository())->findByTelegramUserId(self::ORGANIZER_TG);
            self::assertSame(7, $found?->id);
            self::assertSame(7, ActingAs::subjectIn(self::ORGANIZER_TG)?->id);

            History::observed([
                'kind' => History::ASSIGNED,
                'person_id' => 7,
                'task_id' => 3,
                'event_date' => '2026-07-20',
                'actor_person_id' => 1,
                'actor_kind' => History::ACTOR_ORGANIZER,
            ]);
            // A row about someone else is not theirs to be told.
            History::observed(['kind' => History::ASSIGNED, 'person_id' => 8, 'task_id' => 3]);
        });

        $dm = $this->lastCallTo('sendMessage');
        self::assertSame(self::PERSON_TG, $dm['chat_id']);
        self::assertStringStartsWith('Lou signed you up for ', $dm['text']);
        self::assertStringContainsString('20 Jul', $dm['text']);
        self::assertSame(1, substr_count($dm['text'], "\n") + 1, 'one line per change');

        // After the update the organizer is themselves again.
        self::assertNull(ActingAs::subjectIn(self::ORGANIZER_TG));
        self::assertNull(PersonRepository::standInFor(self::ORGANIZER_TG));
    }

    public function testLanguageChangeIsToldEvenWithoutAHistoryRow(): void
    {
        $this->startActing(time() + 600);
        $this->wpdb->nextRows[] = $this->organizerRow();
        $this->wpdb->nextRows[] = $this->personRow();

        $this->acting()->route($this->tap('lang:fi'), false, static function (): void {
        });

        $dm = $this->lastCallTo('sendMessage');
        self::assertSame(self::PERSON_TG, $dm['chat_id']);
        self::assertSame('Lou changed your language.', $dm['text']);
    }

    public function testNameEmailAndSignInAreRefused(): void
    {
        $this->startActing(time() + 600);

        $refused = [
            MenuButtons::NAME,
            MenuButtons::EMAIL,
            MenuButtons::WEB,
            MenuButtons::SIGN_OUT,
            MenuButtons::DELETE,
        ];

        foreach ($refused as $data) {
            $this->wpdb->nextRows[] = $this->organizerRow();
            $this->wpdb->nextRows[] = $this->personRow();

            $this->acting()->route($this->tap($data), false, static function () use ($data): void {
                self::fail($data . ' must not run while acting');
            });

            $answer = $this->lastCallTo('answerCallbackQuery');
            self::assertTrue($answer['show_alert']);
            self::assertStringStartsWith('Not while acting as Sam', $answer['text']);
        }
    }

    public function testAfterTheEndTheirButtonsDoNothing(): void
    {
        $this->startActing(time() - 1);

        $this->acting()->route($this->tap(MenuButtons::BOARD), false, static function (): void {
            self::fail('an ended acting runs nothing');
        });

        self::assertFalse(get_transient('eventcrew_tg_acting_' . self::ORGANIZER_TG));
        self::assertStringStartsWith('Acting as someone has ended', $this->lastCallTo('answerCallbackQuery')['text']);
    }

    public function testNoLongerAnOrganizerEndsIt(): void
    {
        $this->startActing(time() + 600);
        $this->wpdb->nextRows[] = $this->organizerRow(false);

        $this->acting()->route($this->tap(MenuButtons::ME), false, static function (): void {
            self::fail('a former organizer acts as nobody');
        });

        self::assertFalse(get_transient('eventcrew_tg_acting_' . self::ORGANIZER_TG));
    }

    public function testEverythingElseIsTheOrganizersOwn(): void
    {
        $this->startActing(time() + 600);
        $this->wpdb->nextRows[] = $this->organizerRow();
        $this->wpdb->nextRows[] = $this->personRow();
        $ran = false;

        $this->acting()->route($this->tap('perm:who:8'), false, function () use (&$ran): void {
            $ran = true;
            self::assertNull(ActingAs::subjectIn(self::ORGANIZER_TG));
        });

        self::assertTrue($ran);
        self::assertNotFalse(get_transient('eventcrew_tg_acting_' . self::ORGANIZER_TG), 'still acting after');
    }

    public function testTypedMeAndAnAwaitedAnswerRunAsThem(): void
    {
        foreach ([['/me', false], ['@sam_two', true]] as [$text, $awaiting]) {
            $this->startActing(time() + 600);
            $this->wpdb->nextRows[] = $this->organizerRow();
            $this->wpdb->nextRows[] = $this->personRow();
            $subject = null;

            $this->acting()->route(
                ['message' => [
                    'text' => $text,
                    'from' => ['id' => self::ORGANIZER_TG],
                    'chat' => ['id' => self::ORGANIZER_TG, 'type' => 'private'],
                ]],
                $awaiting,
                function () use (&$subject): void {
                    $subject = ActingAs::subjectIn(self::ORGANIZER_TG);
                }
            );

            self::assertSame(7, $subject?->id, $text);
        }
    }

    public function testStopActing(): void
    {
        $this->startActing(time() + 600);
        $this->wpdb->nextRows[] = $this->organizerRow(); // the sender
        $this->wpdb->nextRows[] = $this->personRow();    // whom they acted as

        $this->acting()->route($this->tap(ActingAs::STOP), false, static function (): void {
            self::fail('Stop acting is not routed further');
        });

        self::assertFalse(get_transient('eventcrew_tg_acting_' . self::ORGANIZER_TG));
        $sent = $this->lastCallTo('sendMessage');
        self::assertSame('Stopped acting as Sam.', $sent['text']);
        self::assertSame(MenuButtons::PEOPLE, $this->callbackOf($sent, '⬅'));
        self::assertContains(ActingAs::WARN_HOOK, $this->cleared);
        self::assertContains(ActingAs::END_HOOK, $this->cleared);
    }

    public function testSettingsWhileActingKeepOnlyNotificationsAndLanguage(): void
    {
        $this->wpdb->nextRows[] = $this->personRow(); // onSettings: the stand-in
        $organizer = Person::fromRow($this->organizerRow());
        $subject = Person::fromRow($this->personRow());
        $profile = new ProfileService(
            new PersonRepository(),
            new AssignmentRepository(),
            new TaskRepository(),
            $this->standing(),
            $this->client()
        );

        $show = static function () use ($profile): void {
            $profile->onSettings(self::ORGANIZER_TG, self::ORGANIZER_TG);
        };
        $this->acting()->run(self::ORGANIZER_TG, $organizer, $subject, MenuButtons::SETTINGS, $show);

        $sent = $this->lastCallTo('sendMessage');
        $data = array_column(array_merge([], ...$sent['reply_markup']['inline_keyboard']), 'callback_data');
        self::assertSame([MenuButtons::NOTIFICATIONS, MenuButtons::LANGUAGE, MenuButtons::ME, ActingAs::STOP], $data);
        self::assertCount(1, $this->telegramCalls, 'no DM when nothing changed');
    }

    public function testDmLinesForATakeover(): void
    {
        $this->wpdb->nextRows[] = $this->taskRow();
        $this->wpdb->nextRows[] = ['id' => 8, 'email' => 'kim@example.com', 'display_name' => 'Kim'];

        $line = $this->acting()->line(
            [
                'kind' => History::REPLACED,
                'person_id' => 7,
                'other_person_id' => 8,
                'task_id' => 3,
                'event_date' => '2026-07-20',
            ],
            'Lou',
            7
        );

        self::assertStringStartsWith('Lou gave your ', $line);
        self::assertStringEndsWith(' to Kim.', $line);
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\FollowupRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\EmailBody;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FollowupMail;
use EventCrew\Support\FollowupOptOut;
use EventCrew\Support\FollowupText;
use EventCrew\Support\HybridPolicy;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\Newsletter;
use EventCrew\Support\NewsletterWriter;
use EventCrew\Support\SignedLink;
use EventCrew\Support\SiteLanguages;
use EventCrew\Telegram\BoardDjText;
use EventCrew\Telegram\BoardService;
use EventCrew\Tests\TestCase;

/**
 * The post-event email: who gets which version, when the preview and the
 * emails go out (site time, Helsinki here), Stop, the switch, the tracked
 * links and the size of one email. Options live in an array, the ticket
 * holders and check-ins in FollowupWpdb, and wp_mail records each message.
 */
final class FollowupMailTest extends TestCase
{
    use FakeEventNames;

    private const NIGHT = '2026-11-14';

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<int, array{post: object, meta: array<string, string>}> */
    private array $events = [];

    /** @var list<array{to: string, subject: string, body: string}> */
    private array $mails = [];

    /** @var list<string> */
    private array $scheduled = [];

    /** @var list<string> Addresses whose wp_mail fails. */
    private array $failing = [];

    /** @var list<array<string, mixed>> The decoded language model requests. */
    private array $requests = [];

    /** @var list<string|int> The language model's answers in order; an int is an HTTP error status. */
    private array $answers = [];

    private FollowupWpdb $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new FollowupWpdb();
        $this->wpdb = $this->db;
        $GLOBALS['wpdb'] = $this->db;

        // On since long before the night, so it is covered.
        $this->options = [
            FollowupMail::ENABLED_OPTION => '1',
            FollowupMail::ENABLED_AT_OPTION => $this->at('2026-11-01 09:00')->getTimestamp(),
        ];
        $this->events = [];
        $this->mails = [];
        $this->scheduled = [];
        $this->failing = [];

        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('Europe/Helsinki'));
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);

            return true;
        });
        Functions\when('post_type_exists')->justReturn(true);
        Functions\when('get_posts')->alias(
            fn (): array => array_map(static fn (array $event): object => $event['post'], array_values($this->events))
        );
        Functions\when('get_post')->alias(fn (int $id): ?object => $this->events[$id]['post'] ?? null);
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key): string => $this->events[$id]['meta'][$key] ?? ''
        );
        Functions\when('get_permalink')->alias(static fn (int $id): string => 'https://site.test/event-' . $id . '/');
        Functions\when('rest_url')->alias(static fn (string $p = ''): string => 'https://site.test/wp-json/' . $p);
        Functions\when('add_query_arg')->alias(
            static fn (array $args, string $url): string => $url . '?' . http_build_query($args)
        );
        Functions\when('is_email')->alias(static fn (string $email): bool => str_contains($email, '@'));
        Functions\when('add_action')->justReturn(true);
        Functions\when('remove_action')->justReturn(true);
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->alias(function (int $time, string $hook): bool {
            $this->scheduled[] = $hook;

            return true;
        });
        Functions\when('wp_mail')->alias(
            function (string $to, string $subject, string $body, mixed $headers = []): bool {
                $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => $headers];

                return ! in_array($to, $this->failing, true);
            }
        );
    }

    // ---- The switch -------------------------------------------------------

    public function testSwitchedOffNothingIsPreviewedOrSent(): void
    {
        $this->options[FollowupMail::ENABLED_OPTION] = '0';
        $this->buyer(1, 'Anna Example', 'anna@example.test');
        $this->organizer();

        $mail = $this->followup();
        $mail->run($this->at('2026-11-13 12:00'));
        $mail->run($this->at('2026-11-15 12:00'));
        $mail->sendBatch();

        self::assertSame([], $this->mails);
        self::assertSame([], $this->scheduled);
        self::assertSame([], $this->db->updates);
        self::assertSame('off', $mail->nightStatus(self::NIGHT));
    }

    public function testSwitchingOnNotesTheMomentOnceAndOffClearsIt(): void
    {
        $this->options = [];

        FollowupMail::setEnabled(true, 1000);
        self::assertSame('1', $this->options[FollowupMail::ENABLED_OPTION]);
        self::assertSame(1000, $this->options[FollowupMail::ENABLED_AT_OPTION]);

        // Saving the settings again while it is on keeps the first moment.
        FollowupMail::setEnabled(true, 2000);
        self::assertSame(1000, $this->options[FollowupMail::ENABLED_AT_OPTION]);

        FollowupMail::setEnabled(false, 3000);
        self::assertSame('0', $this->options[FollowupMail::ENABLED_OPTION]);
        self::assertSame(0, $this->options[FollowupMail::ENABLED_AT_OPTION]);

        FollowupMail::setEnabled(true, 4000);
        self::assertSame(4000, $this->options[FollowupMail::ENABLED_AT_OPTION]);
    }

    /**
     * The operator's rule: switching the setting on never mails the people
     * of an event that is already over. The first email is for the next
     * event, the day after it.
     */
    public function testSwitchingOnAfterAnEventNeverMailsThatEventButMailsTheNext(): void
    {
        $past = '2026-11-07';
        $this->options[FollowupMail::ENABLED_OPTION] = '0';
        $this->options[FollowupMail::ENABLED_AT_OPTION] = 0;
        $this->buyer(1, 'Anna Example', 'anna@example.test', $past);
        $this->buyer(2, 'Bea Example', 'bea@example.test', self::NIGHT);
        $this->organizer();

        // Switched on the morning after the past event, before its send time.
        FollowupMail::setEnabled(true, $this->at('2026-11-08 10:00')->getTimestamp());

        $mail = $this->followup();
        // Inside the old preview window (until 12:00 the day after).
        $mail->run($this->at('2026-11-08 11:00'));
        $mail->run($this->at('2026-11-08 12:00'));
        $mail->run($this->at('2026-11-08 15:00'));
        $mail->run($this->at('2026-11-09 12:00'));
        $mail->sendBatch();

        self::assertSame([], $this->mails, 'nothing goes out for the event that ended before the switch');
        self::assertSame('', $this->db->statusOf('buyer', 1));
        self::assertSame('not_covered', $mail->nightStatus($past));
        self::assertFalse($mail->canStop($past, $this->at('2026-11-08 11:00')));
        self::assertStringContainsString(
            'not sent for this event',
            FollowupMail::statusLine(
                ['sent' => 0, 'failed' => 0, 'stopped' => 0, 'unsent' => 1, 'clicked' => 0],
                'not_covered',
                $past
            )
        );

        // The next event: preview the day before, emails the day after.
        self::assertSame('planned', $mail->nightStatus(self::NIGHT));
        $mail->run($this->at('2026-11-13 12:00'));
        self::assertCount(1, $this->mails);
        self::assertSame('org@example.test', $this->mails[0]['to']);

        $mail->run($this->at('2026-11-15 12:00'));
        self::assertCount(2, $this->mails);
        self::assertSame('bea@example.test', $this->mails[1]['to']);
        self::assertSame('', $this->db->statusOf('buyer', 1), 'still nothing for the past event');
    }

    public function testSwitchingOnDuringTheEventDayLeavesThatEventOut(): void
    {
        $this->options[FollowupMail::ENABLED_OPTION] = '0';
        $this->buyer(1, 'Anna Example', 'anna@example.test');
        $this->organizer();

        FollowupMail::setEnabled(true, $this->at(self::NIGHT . ' 18:00')->getTimestamp());

        $mail = $this->followup();
        $mail->run($this->at(self::NIGHT . ' 18:00'));
        $mail->run($this->at('2026-11-15 12:00'));

        self::assertSame([], $this->mails);
        self::assertSame('not_covered', $mail->nightStatus(self::NIGHT));
    }

    public function testSwitchedOnWithoutANotedMomentCountsFromTheFirstRun(): void
    {
        // On, but not through the settings page: no moment noted.
        unset($this->options[FollowupMail::ENABLED_AT_OPTION]);
        $this->buyer(1, 'Anna Example', 'anna@example.test');
        $this->organizer();

        $mail = $this->followup();
        $mail->run($this->at('2026-11-15 12:00'));

        self::assertSame([], $this->mails);
        self::assertSame(
            $this->at('2026-11-15 12:00')->getTimestamp(),
            $this->options[FollowupMail::ENABLED_AT_OPTION]
        );
    }

    // ---- Timing -----------------------------------------------------------

    public function testThePreviewGoesAt12TheDayBeforeInSiteTime(): void
    {
        $this->buyer(1, 'Anna Example', 'anna@example.test');
        $this->buyer(2, 'Anna again', 'ANNA@example.test');
        $this->guest(5, 'Gus Example', 'gus@example.test');
        $this->organizer();
        $mail = $this->followup();

        // 11:59 Helsinki is 09:59 UTC: not yet.
        $mail->run(new \DateTimeImmutable('2026-11-13T09:59:00Z'));
        self::assertSame([], $this->mails);

        // 12:00 Helsinki is 10:00 UTC.
        $mail->run(new \DateTimeImmutable('2026-11-13T10:00:00Z'));
        self::assertCount(1, $this->mails);
        self::assertSame('org@example.test', $this->mails[0]['to']);
        self::assertStringContainsString('Post-event email preview', $this->mails[0]['subject']);
        $body = $this->mails[0]['body'];
        self::assertStringContainsString('Thank you for dancing with us', $body);
        self::assertStringContainsString('We missed you this time', $body);
        self::assertStringContainsString('Hi Alex,', $body);
        self::assertStringContainsString('2 addresses', $body, 'one per address');
        self::assertStringContainsString('eventcrew/v1/followup', $body, 'the Stop link');

        // Once per night.
        $mail->run(new \DateTimeImmutable('2026-11-13T15:00:00Z'));
        self::assertCount(1, $this->mails);
        self::assertSame([], $this->db->updates, 'the preview marks no row');
    }

    /** The preview names the crew page's Admin tab: with a crew page set, it links to it (C4). */
    public function testThePreviewLinksTheAdminTabOnlyWithACrewPage(): void
    {
        $this->buyer(1, 'Anna Example', 'anna@example.test');
        $this->organizer();

        $this->followup()->run($this->at('2026-11-13 12:00'));
        self::assertStringNotContainsString('#eventcrew-panel-admin', $this->mails[0]['body'], 'no crew page');

        $this->mails = [];
        unset($this->options[FollowupMail::STATE_OPTION]);
        $this->options['eventcrew_signup_page_id'] = 897;
        $this->followup()->run($this->at('2026-11-13 12:00'));

        self::assertCount(1, $this->mails);
        self::assertStringContainsString(
            'href="https://site.test/event-897/#eventcrew-panel-admin"',
            $this->mails[0]['body']
        );
        self::assertStringContainsString('Open the Admin tab', $this->mails[0]['body']);
    }

    public function testTheEmailsGoAt12TheDayAfter(): void
    {
        $this->buyer(1, 'Anna Example', 'anna@example.test');
        $this->previewed();
        $mail = $this->followup();

        $mail->run(new \DateTimeImmutable('2026-11-15T09:59:00Z'));
        self::assertSame([], $this->mails);

        $mail->run(new \DateTimeImmutable('2026-11-15T10:00:00Z'));
        self::assertCount(1, $this->mails);
        self::assertSame('anna@example.test', $this->mails[0]['to']);
    }

    /**
     * Outside the send window, with the organizers' emails at any hour
     * (Lou 2026-11-11): the preview goes, the guests' emails wait.
     */
    public function testOutsideTheWindowThePreviewGoesButTheEmailsWait(): void
    {
        $this->buyer(1, 'Anna Example', 'anna@example.test');
        $this->organizer();
        $mail = $this->followup();

        $mail->run(new \DateTimeImmutable('2026-11-13T10:00:00Z'), false);
        self::assertCount(1, $this->mails);
        self::assertSame('org@example.test', $this->mails[0]['to']);

        $this->mails = [];
        $mail->run(new \DateTimeImmutable('2026-11-15T10:00:00Z'), false);
        self::assertSame([], $this->mails);

        $mail->run(new \DateTimeImmutable('2026-11-15T11:00:00Z'));
        self::assertCount(1, $this->mails);
        self::assertSame('anna@example.test', $this->mails[0]['to']);
    }

    public function testANightWhosePreviewNeverWentIsNotSent(): void
    {
        $this->buyer(1, 'Anna Example', 'anna@example.test');
        $this->organizer();

        $this->followup()->run($this->at('2026-11-15 12:00'));

        self::assertSame([], $this->mails);
    }

    public function testANightTooLateIsNotSent(): void
    {
        $this->buyer(1, 'Anna Example', 'anna@example.test');
        $this->previewed();

        $this->followup()->run($this->at('2026-11-17 12:00'));

        self::assertSame([], $this->mails);
    }

    public function testANightWithNobodyToEmailGetsNoPreview(): void
    {
        $this->buyer(1, 'Anna Example', '');
        $this->organizer();

        $this->followup()->run($this->at('2026-11-13 12:00'));

        self::assertSame([], $this->mails);
    }

    // ---- Who gets what ----------------------------------------------------

    public function testOneEmailPerAddressAndTheCameOrMissedVersion(): void
    {
        $this->buyer(1, 'Anna Example', 'anna@example.test');
        $this->buyer(2, 'Anna Plus One', 'ANNA@example.test');
        $this->buyer(3, 'Bo Example', 'bo@example.test');
        $this->guest(5, 'Gus Example', 'gus@example.test');
        $this->guest(6, 'Purged Guest', '');
        // Anna's second ticket was the one scanned; Gus came as a guest.
        $this->db->checkins = [
            ['kind' => FollowupRepository::KIND_BUYER, 'ref_id' => 2],
            ['kind' => FollowupRepository::KIND_GUEST, 'ref_id' => 5],
            // A buyer check-in with the guest's id must not count for buyer 5.
            ['kind' => FollowupRepository::KIND_BUYER, 'ref_id' => 5],
        ];
        $this->failing = ['bo@example.test'];
        $this->previewed();

        $this->followup()->run($this->at('2026-11-15 12:00'));

        $byAddress = [];

        foreach ($this->mails as $mail) {
            $byAddress[$mail['to']] = $mail;
        }

        self::assertSame(['anna@example.test', 'bo@example.test', 'gus@example.test'], array_keys($byAddress));
        self::assertStringContainsString('Thank you for dancing with us', $byAddress['anna@example.test']['subject']);
        self::assertStringContainsString('Hi Anna,', $byAddress['anna@example.test']['body']);
        // No QR code, so the mail app's own colours (dark mode too), not forced light.
        $anna = $byAddress['anna@example.test']['body'];
        self::assertStringContainsString('<meta name="color-scheme" content="light">', $anna);
        self::assertStringNotContainsString('light only', $anna);
        self::assertStringContainsString('We missed you this time', $byAddress['bo@example.test']['subject']);
        self::assertStringContainsString('Thank you for dancing with us', $byAddress['gus@example.test']['subject']);

        self::assertSame(FollowupMail::STATUS_SENT, $this->db->statusOf('buyer', 1));
        self::assertSame(FollowupMail::STATUS_DUPLICATE, $this->db->statusOf('buyer', 2));
        self::assertSame(FollowupMail::STATUS_FAILED, $this->db->statusOf('buyer', 3));
        self::assertSame(FollowupMail::STATUS_SENT, $this->db->statusOf('guest', 5));
        self::assertSame('', $this->db->statusOf('guest', 6), 'a purged row is left alone');

        foreach ($this->db->updates as $update) {
            self::assertArrayHasKey('followup_mailed_at', $update['data']);
        }

        self::assertTrue($this->state()['done'][self::NIGHT] ?? false);

        // A second run sends nothing again.
        $this->followup()->run($this->at('2026-11-15 13:00'));
        self::assertCount(3, $this->mails);
    }

    public function testRecipientsSkipRowsWithoutAnAddress(): void
    {
        (new FollowupRepository())->recipients(self::NIGHT);

        self::assertCount(4, $this->db->queries, 'buyers, guests, assignments and free-entry tickets');
        [$buyers, $guests, $assignments, $redemptions] = $this->db->queries;

        foreach ([$buyers, $guests] as $sql) {
            self::assertStringContainsString("email IS NOT NULL AND email <> ''", $sql);
        }

        foreach ([$assignments, $redemptions] as $sql) {
            self::assertStringContainsString(
                "p.anonymized_at IS NULL AND p.email_verified_at IS NOT NULL AND p.email <> ''"
                    . " AND RIGHT(p.email, 8) <> '.invalid'",
                $sql
            );
        }

        self::assertStringContainsString(
            "t.task_date = '2026-11-14' AND t.deleted_at IS NULL"
                . " AND a.status IN ('arrived', 'arrived_late', 'completed', 'no_show')",
            $assignments
        );
        self::assertStringContainsString("r.redeemed_for = '2026-11-14' AND r.cancelled_at IS NULL", $redemptions);
    }

    /**
     * Owner, 2026-11-13: not only the ticket buyers. The crew on the night's
     * tasks get it too (thank-you when marked as come, sorry when marked a
     * no-show, nothing while unmarked or cancelled), and so do the free-entry
     * tickets (thank-you when checked in at the door).
     */
    public function testTheCrewAndTheFreeEntryTicketsGetItToo(): void
    {
        $this->crew(1, 21, 'Cara Crew', 'cara@example.com', 'arrived');
        $this->crew(2, 22, 'Dan Done', 'dan@example.com', 'completed');
        $this->crew(3, 23, 'Nora Noshow', 'nora@example.com', 'no_show');
        $this->crew(4, 24, 'Una Unmarked', 'una@example.com', 'signed_up');
        $this->crew(5, 25, 'Cal Cancelled', 'cal@example.com', 'cancelled');
        $this->crew(6, 26, 'Del Deleted', 'del@example.com', 'arrived', ['task_deleted' => true]);
        $this->crew(7, 27, 'Ola Otherday', 'ola@example.com', 'arrived', ['event_date' => '2026-11-07']);
        $this->credit(31, 41, 'Ivy In', 'ivy@example.com');
        $this->credit(32, 42, 'Max Missed', 'max@example.com');
        $this->credit(33, 43, 'Xan Extra', 'xan@example.com');
        $this->credit(34, 44, 'Gil Given', 'gil@example.com', ['cancelled' => true]);
        $this->db->checkins = [
            ['kind' => 'credit', 'ref_id' => 41],
            // A second free-entry ticket of the night is checked in by its own id.
            ['kind' => 'credit_x', 'ref_id' => 33],
            // A buyer row with a person's id does not make the person come.
            ['kind' => FollowupRepository::KIND_BUYER, 'ref_id' => 42],
        ];
        $this->previewed();

        $this->followup()->run($this->at('2026-11-15 12:00'));

        $subjects = array_column($this->mails, 'subject', 'to');

        self::assertSame(
            ['cara@example.com', 'dan@example.com', 'nora@example.com', 'ivy@example.com', 'max@example.com', 'xan@example.com'],
            array_keys($subjects)
        );

        foreach (['cara@example.com', 'dan@example.com', 'ivy@example.com', 'xan@example.com'] as $to) {
            self::assertStringContainsString('Thank you for dancing with us', $subjects[$to], $to);
        }

        foreach (['nora@example.com', 'max@example.com'] as $to) {
            self::assertStringContainsString('We missed you this time', $subjects[$to], $to);
        }

        self::assertStringContainsString('Hi Cara,', array_column($this->mails, 'body', 'to')['cara@example.com']);

        foreach ([1, 2, 3] as $id) {
            self::assertSame(FollowupMail::STATUS_SENT, $this->db->statusOf('assignment', $id));
        }

        foreach ([4, 5, 6, 7] as $id) {
            self::assertSame('', $this->db->statusOf('assignment', $id));
        }

        foreach ([31, 32, 33] as $id) {
            self::assertSame(FollowupMail::STATUS_SENT, $this->db->statusOf('redemption', $id));
        }

        self::assertSame('', $this->db->statusOf('redemption', 34));

        // The crew's links carry their own kind: an assignment is 'a', a free-entry ticket 'r'.
        $cara = array_column($this->mails, 'body', 'to')['cara@example.com'];
        $optOut = $this->optOutTokenIn($cara);
        self::assertSame(["a\n1"], [$this->payloadOf($optOut)]);

        // A second run sends nothing again.
        $this->followup()->run($this->at('2026-11-15 13:00'));
        self::assertCount(6, $this->mails);
    }

    /** A night with only crew on it gets the preview: the crew are signed up the day before. */
    public function testANightWithOnlyCrewIsPreviewed(): void
    {
        $this->crew(1, 21, 'Cara Crew', 'cara@example.com', 'signed_up');
        $this->organizer();

        $this->followup()->run($this->at('2026-11-13 12:00'));

        self::assertSame(['org@example.test'], array_column($this->mails, 'to'));
    }

    /**
     * Nobody who cannot or would not want it: an address that is not
     * confirmed, an erased person, a placeholder address, a person who
     * switched every optional email off, and an address on the do-not-send
     * list. The ones with an address are marked optout; a crew member who
     * switched only some email off still gets it.
     */
    public function testCrewWhoCannotOrWouldNotWantItAreSkipped(): void
    {
        $allOff = (string) json_encode(array_fill_keys(
            ['standing', 'credit', 'ticket', 'permission', 'open_task'],
            ['email' => false]
        ));
        $someOff = (string) json_encode(['open_task' => ['email' => false], 'standing' => ['email' => false]]);
        $this->crew(1, 21, 'Una Unverified', 'una@example.com', 'arrived', ['verified' => false]);
        $this->crew(2, 22, 'Era Erased', 'era@example.com', 'arrived', ['anonymized' => true]);
        $this->crew(3, 23, 'Pia Placeholder', 'person-23@crew.invalid', 'arrived');
        $this->crew(4, 24, 'Dee Declined', 'dee@example.com', 'arrived', ['notify_prefs' => $allOff]);
        $this->credit(31, 25, 'Lis Listed', 'LIS@example.com');
        $this->crew(5, 26, 'Sam Some', 'sam@example.com', 'completed', ['notify_prefs' => $someOff]);
        $this->options[FollowupOptOut::OPTION] = ['lis@example.com' => '2026-11-01 10:00:00'];
        $this->previewed();

        $this->followup()->run($this->at('2026-11-15 12:00'));

        self::assertSame(['sam@example.com'], array_column($this->mails, 'to'));
        self::assertSame(FollowupMail::STATUS_OPTOUT, $this->db->statusOf('assignment', 4));
        self::assertSame(FollowupMail::STATUS_OPTOUT, $this->db->statusOf('redemption', 31));
        self::assertSame(FollowupMail::STATUS_SENT, $this->db->statusOf('assignment', 5));

        foreach ([1, 2, 3] as $id) {
            self::assertSame('', $this->db->statusOf('assignment', $id));
        }
    }

    /**
     * The do-not-send list holds for the crew's rows as for the buyers': an
     * assignment and a free-entry ticket on the list get nothing. Those not on
     * it get the "Don't send me these" link and the List-Unsubscribe headers
     * of their own row, and pressing it puts their address on the list.
     */
    public function testTheDoNotSendListHoldsForTheCrewRows(): void
    {
        $this->crew(1, 21, 'Ali Listed', 'Ali@example.com', 'arrived');
        $this->credit(31, 22, 'Ria Listed', 'ria@example.com');
        $this->crew(2, 23, 'Cara Crew', 'cara@example.com', 'arrived');
        $this->credit(32, 24, 'Rex Credit', 'rex@example.com');
        $this->options[FollowupOptOut::OPTION] = [
            'ali@example.com' => '2026-11-01 10:00:00',
            'ria@example.com' => '2026-11-02 10:00:00',
        ];
        $this->previewed();

        $this->followup()->run($this->at('2026-11-15 12:00'));

        self::assertSame(['cara@example.com', 'rex@example.com'], array_column($this->mails, 'to'));
        self::assertSame(FollowupMail::STATUS_OPTOUT, $this->db->statusOf('assignment', 1));
        self::assertSame(FollowupMail::STATUS_OPTOUT, $this->db->statusOf('redemption', 31));

        foreach (['cara@example.com' => ['a', 2], 'rex@example.com' => ['r', 32]] as $to => [$kind, $id]) {
            $mail = array_values(array_filter($this->mails, static fn (array $m): bool => $to === $m['to']))[0];
            self::assertSame($kind . "\n" . $id, $this->payloadOf($this->optOutTokenIn($mail['body'])), $to);
            self::assertContains(
                'List-Unsubscribe: <' . FollowupMail::optOutUrl($kind, $id) . '>',
                $mail['headers'],
                $to
            );
        }

        $this->db->nextRows[] = ['name' => 'Rex Credit', 'email' => 'rex@example.com'];
        parse_str((string) parse_url(FollowupMail::optOutUrl('r', 32), PHP_URL_QUERY), $query);
        self::assertTrue($this->followup()->optOutClick((string) $query[FollowupMail::OPTOUT_ARG]));
        self::assertTrue((new FollowupOptOut())->listed('rex@example.com'));
    }

    /**
     * One address with rows of several kinds (bought a ticket, worked a task,
     * had a free-entry ticket) gets one email, a thank-you when any row came.
     */
    public function testOneEmailForAnAddressAcrossKinds(): void
    {
        $this->buyer(1, 'Cara Buyer', 'cara@example.com');
        $this->crew(2, 21, 'Cara Crew', 'Cara@Example.com', 'arrived');
        $this->credit(3, 21, 'Cara Crew', 'cara@example.com');
        $this->previewed();

        $this->followup()->run($this->at('2026-11-15 12:00'));

        self::assertSame(['cara@example.com'], array_column($this->mails, 'to'));
        self::assertStringContainsString('Thank you for dancing with us', $this->mails[0]['subject']);
        self::assertSame(FollowupMail::STATUS_SENT, $this->db->statusOf('buyer', 1));
        self::assertSame(FollowupMail::STATUS_DUPLICATE, $this->db->statusOf('assignment', 2));
        self::assertSame(FollowupMail::STATUS_DUPLICATE, $this->db->statusOf('redemption', 3));
    }

    /** A person declining optional email keeps the whole address out, its ticket too. */
    public function testADecliningPersonsTicketRowIsSkippedToo(): void
    {
        $allOff = (string) json_encode(array_fill_keys(
            ['standing', 'credit', 'ticket', 'permission', 'open_task'],
            ['email' => false]
        ));
        $this->buyer(1, 'Dee Buyer', 'dee@example.com');
        $this->crew(2, 21, 'Dee Crew', 'dee@example.com', 'arrived', ['notify_prefs' => $allOff]);
        $this->previewed();

        $this->followup()->run($this->at('2026-11-15 12:00'));

        self::assertSame([], $this->mails);
        self::assertSame(FollowupMail::STATUS_OPTOUT, $this->db->statusOf('buyer', 1));
        self::assertSame(FollowupMail::STATUS_OPTOUT, $this->db->statusOf('assignment', 2));
    }

    /** The links of a crew row count, subscribe and opt out through the assignments and redemptions tables. */
    public function testTheCrewRowsLinksWorkOnTheirOwnTables(): void
    {
        $url = FollowupMail::trackedUrl('https://shop.test/p/1', 'a', 7);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->db->nextQueryResults = [1];

        self::assertSame('https://shop.test/p/1', $this->followup()->clickTarget((string) $query[FollowupMail::GO_ARG]));
        self::assertStringContainsString(
            'wp_eventcrew_assignments SET followup_clicked_at',
            $this->db->lastQuery()
        );

        $url = FollowupMail::optOutUrl('r', 9);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->db->nextRows[] = ['name' => 'Ivy In', 'email' => ' Ivy@Example.com '];

        self::assertTrue($this->followup()->optOutClick((string) $query[FollowupMail::OPTOUT_ARG]));
        $sql = $this->db->lastQuery();
        self::assertStringContainsString(
            'SELECT p.display_name AS name, p.email FROM wp_eventcrew_redemptions r'
                . ' INNER JOIN wp_eventcrew_people p ON p.id = r.person_id WHERE r.id = 9 AND p.anonymized_at IS NULL',
            $sql
        );
        self::assertSame(['ivy@example.com'], array_keys($this->options[FollowupOptOut::OPTION]));

        // A placeholder address is no holder.
        $this->db->nextRows[] = ['name' => 'Pia', 'email' => 'person-1@crew.invalid'];
        self::assertNull((new FollowupRepository())->holder(FollowupRepository::KIND_ASSIGNMENT, 3));
    }

    /** Stop marks the crew's and the free-entry tickets' rows too. */
    public function testStopMarksTheCrewRowsToo(): void
    {
        $this->buyer(1, 'Anna Example', 'anna@example.test');
        $this->previewed();

        self::assertSame(FollowupMail::STOPPED, $this->followup()->stop(self::NIGHT));

        $sql = implode("\n", $this->db->queries);
        self::assertStringContainsString(
            "UPDATE wp_eventcrew_assignments a INNER JOIN wp_eventcrew_tasks t ON t.id = a.task_id"
                . " SET a.followup_mail_status = 'stopped' WHERE t.task_date = '2026-11-14'",
            $sql
        );
        self::assertStringContainsString(
            "UPDATE wp_eventcrew_redemptions SET followup_mail_status = 'stopped'"
                . " WHERE redeemed_for = '2026-11-14' AND cancelled_at IS NULL",
            $sql
        );
    }

    public function testMoreThanABatchContinuesInACronEvent(): void
    {
        for ($i = 1; $i <= 26; $i++) {
            $this->buyer($i, 'Person ' . $i, 'p' . $i . '@example.test');
        }

        $this->previewed();
        $mail = $this->followup();
        $mail->run($this->at('2026-11-15 12:00'));

        self::assertCount(25, $this->mails);
        self::assertSame([FollowupMail::HOOK], $this->scheduled);
        self::assertFalse($this->state()['done'][self::NIGHT] ?? false);
        self::assertSame('sending', $mail->nightStatus(self::NIGHT));

        $mail->sendBatch();

        self::assertCount(26, $this->mails);
        self::assertSame('p26@example.test', $this->mails[25]['to']);
        self::assertTrue($this->state()['done'][self::NIGHT] ?? false);
        self::assertSame('sent', $mail->nightStatus(self::NIGHT));
    }

    // ---- Stop -------------------------------------------------------------

    public function testStopPreventsTheSend(): void
    {
        $this->buyer(1, 'Anna Example', 'anna@example.test');
        $this->previewed();
        $mail = $this->followup();

        self::assertTrue($mail->canStop(self::NIGHT, $this->at('2026-11-14 20:00')));
        self::assertSame(FollowupMail::STOPPED, $mail->stop(self::NIGHT));
        self::assertStringContainsString("SET followup_mail_status = 'stopped'", implode("\n", $this->db->queries));
        self::assertSame(FollowupMail::ALREADY, $mail->stop(self::NIGHT));
        self::assertFalse($mail->canStop(self::NIGHT, $this->at('2026-11-14 20:00')));

        $mail->run($this->at('2026-11-15 12:00'));
        $mail->sendBatch();

        self::assertSame([], $this->mails);
        self::assertSame('stopped', $mail->nightStatus(self::NIGHT));
    }

    public function testStopIsTooLateOnceSendingStarted(): void
    {
        $this->buyer(1, 'Anna Example', 'anna@example.test');
        $this->previewed();
        $mail = $this->followup();
        $mail->run($this->at('2026-11-15 12:00'));

        self::assertSame(FollowupMail::TOO_LATE, $mail->stop(self::NIGHT));
    }

    public function testTheStopTokenCarriesTheDate(): void
    {
        $token = FollowupMail::stopToken(self::NIGHT);

        self::assertSame(self::NIGHT, FollowupMail::dateOfStopToken($token));
        self::assertNull(FollowupMail::dateOfStopToken($token . 'x'));
        self::assertNull(FollowupMail::dateOfStopToken(SignedLink::sign('ticket', 20261114)));
    }

    // ---- Tracking ---------------------------------------------------------

    public function testUtmIsMergedIntoTheAddress(): void
    {
        self::assertSame(
            'https://shop.test/p/1?utm_source=eventcrew&utm_medium=email&utm_campaign=thanks-2026-11-14',
            FollowupMail::withUtm('https://shop.test/p/1', 'thanks-2026-11-14')
        );
        self::assertSame(
            'https://shop.test/p/1?lang=fi&x=1'
                . '&utm_source=eventcrew&utm_medium=email&utm_campaign=missed-2026-11-14#buy',
            FollowupMail::withUtm('https://shop.test/p/1?lang=fi&x=1#buy', 'missed-2026-11-14')
        );
        self::assertSame(
            'https://shop.test/?a=b&utm_term=x&utm_source=eventcrew&utm_medium=email&utm_campaign=thanks-2026-11-14',
            FollowupMail::withUtm(
                'https://shop.test/?utm_source=fb&a=b&UTM_MEDIUM=social&utm_campaign&utm_term=x',
                'thanks-2026-11-14'
            )
        );
        self::assertSame('thanks-2026-11-14', FollowupMail::campaign(self::NIGHT, true));
        self::assertSame('missed-2026-11-14', FollowupMail::campaign(self::NIGHT, false));
    }

    public function testACountedLinkLeadsToItsTargetAndCountsTheRow(): void
    {
        $url = FollowupMail::trackedUrl('https://shop.test/p/1?utm_source=eventcrew', 'b', 7);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->db->nextQueryResults = [1];

        $target = $this->followup()->clickTarget((string) $query[FollowupMail::GO_ARG]);

        self::assertStringStartsWith('https://site.test/', $url);
        self::assertSame('https://shop.test/p/1?utm_source=eventcrew', $target);
        $sql = $this->db->lastQuery();
        self::assertStringContainsString('eventcrew_ticket_buyers SET followup_clicked_at', $sql);
        self::assertStringContainsString('WHERE id = 7 AND followup_clicked_at IS NULL', $sql);
    }

    public function testATamperedOrForeignTokenLeadsNowhere(): void
    {
        $token = SignedLink::signPayload(FollowupMail::PURPOSE_GO, "b\n7\nhttps://shop.test/");
        [$body] = explode('.', $token);
        $forged = rtrim(strtr(base64_encode("b\n7\nhttps://evil.test/"), '+/', '-_'), '=');
        $mail = $this->followup();

        self::assertNull($mail->clickTarget($forged . substr($token, strlen($body))));
        self::assertNull($mail->clickTarget($token . 'x'));
        self::assertNull($mail->clickTarget(SignedLink::signPayload('other', "b\n7\nhttps://shop.test/")));
        self::assertNull($mail->clickTarget('garbage'));
        self::assertSame([], $this->db->queries, 'nothing counted');
    }

    public function testOnlyHttpTargetsAreFollowedEvenWhenSigned(): void
    {
        $mail = $this->followup();

        $bad = ['javascript:alert(1)', 'data:text/html,x', '//evil.test/', 'https:///nohost', "https://a.test/\nx"];

        foreach ($bad as $target) {
            $token = SignedLink::signPayload(FollowupMail::PURPOSE_GO, "b\n7\n" . $target);
            self::assertNull($mail->clickTarget($token), $target);
        }

        self::assertSame([], $this->db->queries);
    }

    public function testAPreviewOrTestLinkIsNotCounted(): void
    {
        $token = SignedLink::signPayload(FollowupMail::PURPOSE_GO, "t\n0\nhttps://shop.test/");

        self::assertSame('https://shop.test/', $this->followup()->clickTarget($token));
        self::assertSame([], $this->db->queries);
    }

    public function testOnlyTheFirstClickIsCounted(): void
    {
        $this->db->nextQueryResults = [1, 0];
        $rows = new FollowupRepository();

        self::assertTrue($rows->recordClick(FollowupRepository::KIND_GUEST, 3));
        self::assertFalse($rows->recordClick(FollowupRepository::KIND_GUEST, 3));
        self::assertFalse($rows->recordClick('person', 3));
        self::assertFalse($rows->recordClick(FollowupRepository::KIND_GUEST, 0));
        self::assertCount(2, $this->db->queries);
    }

    // ---- The subscribe button ---------------------------------------------

    /**
     * The button carries a signed id of the ticket holder's row; pressing it
     * counts the click and subscribes that address, confirmed, with the first
     * word of the ticket name and the browser's language.
     */
    public function testTheSubscribeButtonSubscribesTheTicketHolder(): void
    {
        $url = FollowupMail::joinUrl('b', 7, 'thanks-2026-11-14');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->db->nextQueryResults = [1];
        $this->db->nextRows[] = ['name' => 'Anna Virtanen', 'email' => 'Anna@Example.test']; // holder()
        $this->db->nextRows[] = null; // no subscriber row yet

        $target = $this->followup()->joinClick((string) $query[FollowupMail::JOIN_ARG], 'fi');

        self::assertStringStartsWith('https://site.test/?' . FollowupMail::JOIN_ARG . '=', $url);
        self::assertSame(
            'https://site.test/?eventcrew_newsletter=joined'
                . '&utm_source=eventcrew&utm_medium=email&utm_campaign=thanks-2026-11-14#eventcrew-newsletter',
            $target
        );
        self::assertContains(
            'UPDATE wp_eventcrew_ticket_buyers SET followup_clicked_at = \'2026-07-20 12:00:00\''
                . ' WHERE id = 7 AND followup_clicked_at IS NULL',
            array_map('trim', $this->db->queries)
        );
        self::assertContains('SELECT name, email FROM wp_eventcrew_ticket_buyers WHERE id = 7', $this->db->queries);
        self::assertSame('wp_eventcrew_newsletter_subscribers', $this->db->inserts[0]['table']);
        self::assertSame(
            [
                'email' => 'anna@example.test',
                'status' => 'confirmed',
                'created_at' => '2026-07-20 12:00:00',
                'confirmed_at' => '2026-07-20 12:00:00',
                'name' => 'Anna',
                'language' => 'fi',
            ],
            $this->db->inserts[0]['data']
        );
    }

    /** The button of a preview or a test only leads to the page: nobody is subscribed or counted. */
    public function testASampleSubscribeButtonSubscribesNobody(): void
    {
        $url = FollowupMail::joinUrl('t', 0, 'missed-2026-11-14');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $target = $this->followup()->joinClick((string) $query[FollowupMail::JOIN_ARG], 'fi');

        self::assertSame(
            'https://site.test/?utm_source=eventcrew&utm_medium=email&utm_campaign=missed-2026-11-14'
                . '#eventcrew-newsletter',
            $target
        );
        self::assertSame([], $this->db->queries);
        self::assertSame([], $this->db->inserts);
    }

    /** A row whose address was deleted since only leads to the page. */
    public function testASubscribeButtonOfAnErasedRowSubscribesNobody(): void
    {
        $this->db->nextRows[] = ['name' => '', 'email' => ''];

        $target = $this->followup()->joinClick(
            SignedLink::signPayload(FollowupMail::PURPOSE_JOIN, "g\n3\nthanks-2026-11-14")
        );

        self::assertStringNotContainsString('joined', (string) $target);
        self::assertSame([], $this->db->inserts);
        self::assertSame([], $this->db->updates);
    }

    public function testAForgedSubscribeTokenDoesNothing(): void
    {
        $token = FollowupMail::joinUrl('b', 7, 'thanks-2026-11-14');
        $mail = $this->followup();

        self::assertNull($mail->joinClick('garbage'));
        self::assertNull($mail->joinClick(SignedLink::signPayload(FollowupMail::PURPOSE_GO, "b\n7\nthanks")));
        self::assertNull($mail->joinClick(substr($token, (int) strpos($token, '=') + 1) . 'x'));
        self::assertSame([], $this->db->queries);
    }

    public function testTheHolderIsReadByKindAndId(): void
    {
        $rows = new FollowupRepository();
        $this->db->nextRows[] = ['name' => 'Cy Guest', 'email' => ' cy@example.test '];

        self::assertSame(
            ['name' => 'Cy Guest', 'email' => 'cy@example.test'],
            $rows->holder(FollowupRepository::KIND_GUEST, 3)
        );
        self::assertSame('SELECT name, email FROM wp_eventcrew_guests WHERE id = 3', $this->db->lastQuery());
        self::assertNull($rows->holder(FollowupRepository::KIND_GUEST, 4), 'no row');
        self::assertNull($rows->holder('person', 3));
        self::assertNull($rows->holder(FollowupRepository::KIND_BUYER, 0));
    }

    /** The email's subscribe button is the signed join link of that row, not a counted redirect. */
    public function testTheEmailsSubscribeButtonIsTheRowsJoinLink(): void
    {
        $this->sampleEvents();
        $this->options[Newsletter::ENABLED_OPTION] = '1';

        $mail = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 7);

        preg_match('/eventcrew_join=([A-Za-z0-9_.\-]+)/', $mail['html'], $token);
        self::assertNotEmpty($token, 'the join link is in the email');
        self::assertSame(
            "b\n7\n" . FollowupMail::campaign(self::NIGHT, true),
            SignedLink::verifyPayload(FollowupMail::PURPOSE_JOIN, $token[1])
        );
        self::assertStringContainsString('eventcrew_join=' . $token[1], $mail['text']);
    }

    // ---- "Don't send me these" (Q305) -------------------------------------

    /** The email ends with the row's signed opt-out link, in the HTML and the text. */
    public function testTheEmailEndsWithTheRowsOptOutLink(): void
    {
        $mail = $this->followup()->compose(self::NIGHT, false, 'Anna', 'g', 9);

        preg_match('/eventcrew_optout=([A-Za-z0-9_.\-]+)/', $mail['html'], $token);
        self::assertNotEmpty($token, 'the opt-out link is in the email');
        self::assertSame("g\n9", SignedLink::verifyPayload(FollowupMail::PURPOSE_OPTOUT, $token[1]));
        self::assertStringContainsString("Don't send me these", html_entity_decode($mail['html'], ENT_QUOTES));
        self::assertStringContainsString('eventcrew_optout=' . $token[1], $mail['text']);
    }

    /** Q328: the email carries List-Unsubscribe headers with the same link, for one-click unsubscribe. */
    public function testTheEmailHasListUnsubscribeHeadersWithTheOptOutLink(): void
    {
        $mail = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 4);

        self::assertSame(
            [
                'List-Unsubscribe: <' . FollowupMail::optOutUrl('b', 4) . '>',
                'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
            ],
            $mail['headers']
        );
        self::assertStringStartsWith('List-Unsubscribe: <https://', $mail['headers'][0]);
        self::assertStringNotContainsString('mailto:', $mail['headers'][0]);
    }

    public function testTheSentEmailCarriesTheListUnsubscribeHeaders(): void
    {
        $this->buyer(1, 'Anna Example', 'anna@example.test');
        $this->previewed();

        $this->followup()->run($this->at('2026-11-15 12:00'));

        $sent = array_values(array_filter(
            $this->mails,
            static fn (array $mail): bool => 'anna@example.test' === $mail['to']
        ));
        self::assertCount(1, $sent);
        self::assertContains('Content-Type: text/html; charset=UTF-8', $sent[0]['headers']);
        self::assertContains(
            'List-Unsubscribe: <' . FollowupMail::optOutUrl('b', 1) . '>',
            $sent[0]['headers']
        );
        self::assertContains('List-Unsubscribe-Post: List-Unsubscribe=One-Click', $sent[0]['headers']);
    }

    public function testNoListUnsubscribeHeadersForALinkThatIsNotHttps(): void
    {
        self::assertSame([], FollowupMail::unsubscribeHeaders('http://site.test/?eventcrew_optout=x'));
    }

    public function testTheOptOutLinkPutsTheRowsAddressOnTheList(): void
    {
        $url = FollowupMail::optOutUrl('b', 7);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->db->nextRows[] = ['name' => 'Anna Virtanen', 'email' => ' Anna@Example.test ']; // holder()

        self::assertTrue($this->followup()->optOutClick((string) $query[FollowupMail::OPTOUT_ARG]));
        self::assertContains('SELECT name, email FROM wp_eventcrew_ticket_buyers WHERE id = 7', $this->db->queries);
        self::assertSame(['anna@example.test'], array_keys($this->options[FollowupOptOut::OPTION]));
        self::assertSame('2026-07-20 12:00:00', $this->options[FollowupOptOut::OPTION]['anna@example.test']);
        self::assertTrue((new FollowupOptOut())->listed('ANNA@example.test'));
    }

    public function testASampleOrForgedOptOutLinkPutsNobodyOnTheList(): void
    {
        $mail = $this->followup();
        $sample = FollowupMail::optOutUrl('t', 0);
        parse_str((string) parse_url($sample, PHP_URL_QUERY), $query);

        self::assertTrue($mail->optOutClick((string) $query[FollowupMail::OPTOUT_ARG]));
        self::assertNull($mail->optOutClick('garbage'));
        self::assertNull($mail->optOutClick(SignedLink::signPayload(FollowupMail::PURPOSE_JOIN, "b\n7")));
        self::assertNull($mail->optOutClick((string) $query[FollowupMail::OPTOUT_ARG] . 'x'));
        self::assertSame([], $this->db->queries);
        self::assertArrayNotHasKey(FollowupOptOut::OPTION, $this->options);
    }

    /** An address on the list, or one that left the announcement, gets no email; its rows are marked optout. */
    public function testNoEmailToAnAddressOnTheListOrThatLeftTheAnnouncement(): void
    {
        $this->buyer(1, 'Anna Example', 'anna@example.test');
        $this->buyer(2, 'Bo Example', 'BO@example.test');
        $this->buyer(3, 'Bo Again', 'bo@example.test');
        $this->guest(5, 'Gus Example', 'gus@example.test');
        $this->options[FollowupOptOut::OPTION] = ['bo@example.test' => '2026-11-01 10:00:00'];
        $this->db->nextCols[] = ['Gus@Example.test']; // unsubscribedEmails()
        $this->previewed();

        $this->followup()->run($this->at('2026-11-15 12:00'));

        self::assertSame(['anna@example.test'], array_column($this->mails, 'to'));
        self::assertSame(FollowupMail::STATUS_SENT, $this->db->statusOf('buyer', 1));
        self::assertSame(FollowupMail::STATUS_OPTOUT, $this->db->statusOf('buyer', 2));
        self::assertSame(FollowupMail::STATUS_OPTOUT, $this->db->statusOf('buyer', 3));
        self::assertSame(FollowupMail::STATUS_OPTOUT, $this->db->statusOf('guest', 5));
        self::assertContains(
            "SELECT email FROM wp_eventcrew_newsletter_subscribers WHERE status = 'unsubscribed'",
            $this->db->queries
        );
        self::assertTrue($this->state()['done'][self::NIGHT] ?? false);
    }

    /**
     * The invitation to join the announcement is the email's main goal
     * (Owner, 2026-11-12): right under the text, before the coming events,
     * in both versions, with the email's strongest button; the ticket
     * buttons under it are outlined.
     */
    public function testTheInvitationToJoinComesBeforeTheEvents(): void
    {
        $this->sampleEvents();
        $this->options[Newsletter::ENABLED_OPTION] = '1';

        foreach ([true, false] as $came) {
            $mail = $this->followup()->compose(self::NIGHT, $came, 'Anna', 'b', 7);
            $html = $mail['html'];
            $text = $mail['text'];

            $invite = strpos($html, 'Stay in touch: one email before each event');
            $join = strpos($html, 'eventcrew_join=');
            $events = strpos($html, 'Coming events');
            $ticket = strpos($html, 'Buy a ticket');
            self::assertNotFalse($invite);
            self::assertGreaterThan($invite, (int) $join, 'the button under its line');
            self::assertGreaterThan((int) $join, (int) $events, 'before the events');
            self::assertGreaterThan((int) $events, (int) $ticket);
            self::assertGreaterThan((int) strpos($html, 'Warmly,'), $invite, 'after the main text');

            // The strongest button: dark, big; the ticket button outlined.
            self::assertMatchesRegularExpression('~bgcolor="#241238"[^>]*><a href="[^"]*eventcrew_join=~', $html);
            self::assertStringContainsString('font-size:19px;font-weight:700', $html);
            self::assertStringContainsString('background:#ffffff;border:1.5px solid #4a6cf7', $html);
            self::assertStringNotContainsString('padding:13px 24px', $html, 'no filled ticket button');

            $textInvite = strpos($text, 'Stay in touch: one email before each event');
            self::assertNotFalse($textInvite);
            self::assertGreaterThan($textInvite, (int) strpos($text, 'eventcrew_join='));
            self::assertGreaterThan((int) strpos($text, 'eventcrew_join='), (int) strpos($text, 'COMING EVENTS'));
        }
    }

    /** The announcement keeps its filled ticket buttons; only the post-event email outlines them. */
    public function testTheAnnouncementKeepsItsFilledTicketButtons(): void
    {
        $this->sampleEvents();
        $events = array_map([Newsletter::class, 'withCard'], Newsletter::comingEvents(self::NIGHT));

        $filled = Newsletter::content(array_slice($events, 0, 1), array_slice($events, 1), null);
        $quiet = Newsletter::content(
            array_slice($events, 0, 1),
            array_slice($events, 1),
            null,
            '',
            false,
            HybridPolicy::NEWSLETTER,
            false,
            true
        );

        self::assertStringContainsString('padding:13px 24px', $filled['html']);
        self::assertStringNotContainsString('border:1.5px solid', $filled['html']);
        self::assertStringContainsString('border:1.5px solid #4a6cf7', $quiet['html']);
        self::assertStringNotContainsString('padding:13px 24px', $quiet['html']);
        self::assertSame($filled['text'], $quiet['text'], 'the plain text is the same');
    }

    /** An address that already gets the announcement is not invited to it; the events stay. */
    public function testNoInvitationForAnAddressThatGetsTheAnnouncement(): void
    {
        $this->sampleEvents();
        $this->options[Newsletter::ENABLED_OPTION] = '1';

        foreach ([true, false] as $came) {
            $mail = $this->followup()->compose(self::NIGHT, $came, 'Anna', 'b', 7, true);

            self::assertStringNotContainsString('eventcrew_join=', $mail['html'] . $mail['text']);
            self::assertStringNotContainsString('Stay in touch', $mail['html'] . $mail['text']);
            self::assertGreaterThan(
                (int) strpos($mail['text'], 'COMING EVENTS'),
                (int) strpos($mail['text'], '————————'),
                'no line before the events'
            );
            self::assertStringContainsString('Coming events', $mail['html']);
            self::assertStringContainsString('Buy a ticket', $mail['html']);
        }
    }

    /** The sent emails leave the invitation out for a confirmed subscriber's address, in any case. */
    public function testTheSentEmailInvitesOnlyThoseWhoDoNotGetTheAnnouncement(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->buyer(1, 'Anna Example', 'anna@example.test');
        $this->buyer(2, 'Bo Example', 'bo@example.test');
        $this->db->nextCols[] = []; // unsubscribedEmails()
        $this->db->nextCols[] = ['BO@Example.test']; // confirmedEmails()
        $this->previewed();

        $this->followup()->run($this->at('2026-11-15 12:00'));

        $bodies = array_column($this->mails, 'body', 'to');
        self::assertCount(2, $bodies);
        self::assertStringContainsString('eventcrew_join=', $bodies['anna@example.test']);
        self::assertStringNotContainsString('eventcrew_join=', $bodies['bo@example.test']);
        self::assertContains(
            "SELECT email FROM wp_eventcrew_newsletter_subscribers WHERE status = 'confirmed'",
            $this->db->queries
        );
    }

    // ---- The counts line --------------------------------------------------

    public function testTheStatusLine(): void
    {
        $none = ['sent' => 0, 'failed' => 0, 'stopped' => 0, 'unsent' => 0, 'clicked' => 0];

        self::assertSame('', FollowupMail::statusLine($none, 'planned', self::NIGHT));
        self::assertSame(
            'Post-event email: 38 sent · 2 failed · 6 clicked',
            FollowupMail::statusLine(['sent' => 38, 'failed' => 2, 'clicked' => 6] + $none, 'sent', self::NIGHT)
        );
        self::assertSame(
            'Post-event email: goes out on 15.11.2026 at 12:00',
            FollowupMail::statusLine(['unsent' => 4] + $none, 'planned', self::NIGHT)
        );
        self::assertSame(
            'Post-event email: stopped',
            FollowupMail::statusLine(['stopped' => 4] + $none, 'stopped', self::NIGHT)
        );
        self::assertSame(
            'Post-event email: switched off',
            FollowupMail::statusLine(['unsent' => 4] + $none, 'off', self::NIGHT)
        );
    }

    public function testTheCountsLeaveOutDuplicates(): void
    {
        $this->db->countRows = [
            [['status' => 'sent', 'n' => 30, 'clicked' => 5], ['status' => 'duplicate', 'n' => 3, 'clicked' => 0]],
            [['status' => 'sent', 'n' => 8, 'clicked' => 1], ['status' => 'failed', 'n' => 2, 'clicked' => 0]],
        ];

        self::assertSame(
            ['sent' => 38, 'failed' => 2, 'stopped' => 0, 'unsent' => 0, 'clicked' => 6],
            (new FollowupRepository())->counts(self::NIGHT)
        );
    }

    // ---- The email itself -------------------------------------------------

    public function testOneEmailIsSmallBilingualWithTrackedCardsAndNoBannedWords(): void
    {
        $this->sampleEvents();
        $this->options[Newsletter::ENABLED_OPTION] = '1';

        self::assertSame(FollowupMail::TEST_SENT, $this->followup()->sendTest(self::NIGHT, 'me@example.test'));
        self::assertCount(2, $this->mails);

        foreach ($this->mails as $index => $mail) {
            $came = 0 === $index;
            $body = $mail['body'];

            self::assertLessThan(90 * 1024, strlen($body), 'under about 90 KB');
            self::assertStringNotContainsString('light only', $body, 'no QR code, so not forced light');
            $fi = $came ? 'Kiitos, että tanssit kanssamme' : 'Kaipasimme sinua tällä kertaa';
            $en = $came ? 'Thank you for dancing with us' : 'We missed you this time';
            self::assertStringContainsString($fi, $mail['subject']);
            self::assertStringContainsString($en, $mail['subject']);
            self::assertStringContainsString($came ? 'Kiitos, että olit mukana' : 'Olemme pahoillamme', $body);
            self::assertStringContainsString($came ? 'secure your spot' : 'We are sorry you could not make it', $body);
            self::assertLessThan(
                strpos($body, $came ? 'Thank you for coming' : 'We missed you at'),
                strpos($body, $came ? 'Kiitos, että olit mukana' : 'Kaipasimme sinua'),
                'Finnish first'
            );

            self::assertStringContainsString(
                'width="536" alt="21.11.2026 · 21:00 — Ecstatic Dance 1"',
                $body,
                'the card with its picture'
            );

            foreach ([2, 3, 4] as $id) {
                self::assertStringNotContainsString(
                    ' — Ecstatic Dance ' . $id . '"',
                    $body,
                    'one event only as a card'
                );
                self::assertSame(1, substr_count($body, 'https://cdn.test/' . $id . '.jpg'), 'a Later picture');
                self::assertStringContainsString('width="80" alt="Ecstatic Dance ' . $id . '"', $body);
            }
            self::assertStringContainsString('Tilaa<br>Subscribe', $body, 'the announcement invite');

            // Every link goes through the counted redirect, with the campaign.
            preg_match_all('/eventcrew_go=([A-Za-z0-9_.\-]+)/', $body, $tokens);
            self::assertGreaterThanOrEqual(8, count($tokens[1]));

            foreach ($tokens[1] as $token) {
                $payload = (string) SignedLink::verifyPayload(FollowupMail::PURPOSE_GO, $token);
                [$kind, , $target] = explode("\n", $payload, 3);
                self::assertSame('t', $kind, 'a test link is not counted');
                self::assertStringContainsString('utm_source=eventcrew&utm_medium=email', $target);
                self::assertStringContainsString('utm_campaign=' . FollowupMail::campaign(self::NIGHT, $came), $target);
            }
        }
    }

    /**
     * The coming events part of both versions is the newsletter's content
     * (Newsletter::content()) for the same events, byte for byte, with only
     * the links counted: the first one as a card, the rest under "Later".
     */
    public function testTheEventsPartIsTheNewslettersForTheSameEvents(): void
    {
        $this->sampleEvents();

        foreach ([true, false] as $came) {
            $mail = $this->followup()->compose(self::NIGHT, $came, 'Anna', 'b', 7);

            $campaign = FollowupMail::campaign(self::NIGHT, $came);
            $link = static fn (string $url): string => '' === $url
                ? ''
                : FollowupMail::trackedUrl(FollowupMail::withUtm($url, $campaign), 'b', 7);
            $events = array_map(
                static fn (array $event): array => array_merge($event, [
                    'url' => $link((string) $event['url']),
                    'ticket_url' => $link((string) $event['ticket_url']),
                    'listen' => $link((string) $event['listen']),
                ]),
                array_map([Newsletter::class, 'withCard'], Newsletter::comingEvents(self::NIGHT))
            );
            // Its ticket buttons outlined, under the invitation to join.
            $expected = Newsletter::content(
                array_slice($events, 0, 1),
                array_slice($events, 1),
                null,
                '',
                true,
                HybridPolicy::FOLLOWUP,
                false,
                true
            );

            self::assertCount(4, $events);
            self::assertStringContainsString('Tulevat tapahtumat', $expected['html'], 'the newsletter heading');
            self::assertStringContainsString('Myöhemmin<br>Later', $expected['html'], 'the others under Later');
            self::assertStringContainsString($expected['html'], $mail['html']);
            self::assertStringContainsString($expected['text'], $mail['text']);
            self::assertStringNotContainsString('A warm evening of free movement.', $mail['html'] . $mail['text']);
        }
    }

    /**
     * The announcement now comes in the subscriber's language (Owner,
     * 2026-11-03), but the post-event email stays in both languages: its
     * events part is the two-language content, never the Finnish-only or
     * English-only one, and its subject and texts keep both languages.
     */
    public function testThePostEventEmailStaysInBothLanguages(): void
    {
        $this->sampleEvents();

        foreach ([true, false] as $came) {
            $mail = $this->followup()->compose(self::NIGHT, $came, 'Anna', 'b', 7);
            $events = array_map([Newsletter::class, 'withCard'], Newsletter::comingEvents(self::NIGHT));
            $part = static fn (string $lang): array => Newsletter::content(
                array_slice($events, 0, 1),
                array_slice($events, 1),
                null,
                $lang
            );

            self::assertSame($part(''), Newsletter::content(array_slice($events, 0, 1), array_slice($events, 1), null));
            self::assertNotSame($part('fi')['html'], $part('')['html']);
            self::assertNotSame($part('en')['html'], $part('')['html']);

            self::assertStringContainsString('>Tulevat tapahtumat</h1>', $mail['html']);
            self::assertStringContainsString('>Coming events</p>', $mail['html']);
            self::assertStringContainsString("TULEVAT TAPAHTUMAT\nCOMING EVENTS", $mail['text']);

            foreach (['<br>' => $mail['html'], "\n" => $mail['text']] as $row => $out) {
                self::assertStringContainsString('Myöhemmin' . $row . 'Later', $out);
                self::assertStringContainsString('Osta lippu · Buy a ticket · 25 €', $out);
                self::assertStringNotContainsString('Automatically translated', $out);
            }

            self::assertStringContainsString($came ? 'Kiitos, että olit mukana' : 'Kaipasimme sinua', $mail['html']);
            self::assertStringContainsString($came ? 'Thank you for coming' : 'We missed you at', $mail['html']);
        }
    }

    /**
     * The ticket label and its price sit on one line, in the card's button,
     * the Later links and the plain text: "Liput · Tickets · 25 €", never the
     * price on a row of its own (Owner, 2026-11-07).
     */
    public function testTheTicketLabelAndPriceAreOnOneLine(): void
    {
        $this->sampleEvents();
        $mail = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 7);

        self::assertStringContainsString('>Osta lippu · Buy a ticket · 25 €</a>', $mail['html']);
        self::assertSame(3, substr_count($mail['html'], '>Liput · Tickets · 25 € →</a>'), 'each Later link');
        self::assertStringNotContainsString('<br>25 €', $mail['html']);
        self::assertStringContainsString("\nOsta lippu · Buy a ticket · 25 €: https://", $mail['text']);
        self::assertStringNotContainsString("\n25 €", $mail['text']);
    }

    /**
     * The practical information the language model found for the next events
     * announcement goes on these cards too, in the HTML and the plain text:
     * one kept from a sent announcement and one from the current plan. An
     * event with none has no such lines. The card shows the text the
     * announcement wrote for its event; a Later line shows none, no card
     * shows the description, the announcement's opening is not shown, and
     * with the switch off no card has practical information.
     */
    public function testThePracticalInformationIsAsInTheAnnouncement(): void
    {
        $this->sampleEvents();
        $this->options[Newsletter::STATE_OPTION] = [
            'ledger' => [
                1 => [
                    's' => Newsletter::LEDGER_SENT,
                    'd' => '2026-11-21',
                    'w' => ['fi' => 'Tule tanssimaan.', 'en' => 'Come and dance.'],
                    'p' => ['venue' => 'Old Hall', 'address' => '', 'doors_open' => '18:30', 'doors_close' => ''],
                ],
            ],
            'plan' => [
                'id' => 2,
                'status' => Newsletter::PLAN_PLANNED,
                'events' => [['id' => 2, 'date' => '2026-11-28']],
                'written' => [
                    'events' => [2 => ['fi' => 'Jää myöhään.', 'en' => 'Stay late.']],
                    'opening' => ['fi' => 'Tervetuloa.', 'en' => 'Welcome.'],
                    'practical' => [
                        2 => [
                            'venue' => 'Example Hall',
                            'address' => 'Main Street 1, Helsinki',
                            'doors_open' => '',
                            'doors_close' => '19:00',
                        ],
                    ],
                ],
            ],
        ];

        $mail = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 7);

        self::assertStringContainsString('margin:0 0 21px;', $mail['html'], 'an empty line after the venue');
        self::assertStringContainsString('text-align:left">Old Hall</p>', $mail['html']);
        self::assertStringContainsString('FI</span>&nbsp; Ovet aukeavat 18:30</p>', $mail['html']);
        self::assertStringContainsString('EN</span>&nbsp; Doors open 18:30</p>', $mail['html']);
        self::assertStringContainsString(
            "Ecstatic Dance 1\nFI: Tule tanssimaan.\nEN: Come and dance.\nOld Hall\n\n"
                . "FI: Ovet aukeavat 18:30\nEN: Doors open 18:30\n",
            $mail['text']
        );
        self::assertSame(1, substr_count($mail['html'], 'Ovet '), 'only the card has such lines');
        self::assertStringNotContainsString('Example Hall', $mail['html'] . $mail['text'], 'a Later line has none');

        self::assertStringContainsString('FI</span>&nbsp; Tule tanssimaan.</p>', $mail['html'], "the card's text");
        self::assertStringContainsString('EN</span>&nbsp; Come and dance.</p>', $mail['html']);

        foreach (['Stay late.', 'Welcome.', 'A warm evening of free movement.'] as $hidden) {
            self::assertStringNotContainsString($hidden, $mail['html'] . $mail['text']);
        }

        // The current plan's information, for the card's event.
        unset($this->options[Newsletter::STATE_OPTION]['ledger']);
        $this->options[Newsletter::STATE_OPTION]['plan']['written']['practical'][1]
            = $this->options[Newsletter::STATE_OPTION]['plan']['written']['practical'][2];
        $mail = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 7);

        self::assertStringContainsString('Example Hall<br>Main Street 1, Helsinki</p>', $mail['html']);
        // Doors open at the event's start (21:00) when the model read no time.
        self::assertStringContainsString('FI</span>&nbsp; Ovet aukeavat 21:00<br>Ovet sulkeutuvat 19:00</p>', $mail['html']);
        self::assertStringContainsString('EN</span>&nbsp; Doors open 21:00<br>Doors close 19:00</p>', $mail['html']);

        $this->options[NewsletterWriter::ENABLED_OPTION] = '0';
        $mail = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 7);

        self::assertStringNotContainsString('Old Hall', $mail['html'] . $mail['text']);
        self::assertStringNotContainsString('Example Hall', $mail['html'] . $mail['text']);
    }

    /**
     * Each language's text starts with its FI or EN tag: the greeting and
     * text, and the invitation to join the announcement. The plain text and
     * the join button have none.
     */
    public function testEachLanguagesTextStartsWithItsTag(): void
    {
        $this->sampleEvents();
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $mail = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 7);

        // The greeting is its own italic line, with the tag on a line above it.
        self::assertMatchesRegularExpression(
            '~<span [^>]*color:#9a7440[^>]*>FI</span>&nbsp; </p><p [^>]*font-style:italic[^>]*>Hei Anna,</p>~',
            $mail['html']
        );
        self::assertMatchesRegularExpression(
            '~<span [^>]*>EN</span>&nbsp; </p><p [^>]*font-style:italic[^>]*>Hi Anna,</p>~',
            $mail['html']
        );
        self::assertMatchesRegularExpression('~>FI</span>&nbsp; Pidetään yhteyttä~', $mail['html'], 'the invitation');
        self::assertMatchesRegularExpression('~>EN</span>&nbsp; Stay in touch~', $mail['html']);
        self::assertStringContainsString('Tilaa<br>Subscribe', $mail['html'], 'the button, one language per row');
        self::assertSame(
            6,
            substr_count($mail['html'], '</span>&nbsp; '),
            "two texts, two invitations and the card's two blocks of door times"
        );
        self::assertStringNotContainsString('<span', $mail['text']);
    }

    /**
     * The coming events part opens with a heading in the confirmation card's
     * look: the sparkle, the Finnish heading big, gold and centred, the
     * English heading on a line of its own under it, and a short gold rule.
     * The announcement keeps its plain heading.
     */
    public function testTheEventsPartOpensWithTheEpicHeading(): void
    {
        $this->sampleEvents();
        $mail = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 7);

        self::assertMatchesRegularExpression(
            '~<table [^>]*><tr><td align="center"[^>]*>'
                . '<p [^>]*>✦</p><h1 class="ec-shimmer" [^>]*font-size:28px;[^>]*color:#a8641f;text-align:center">'
                . 'Tulevat tapahtumat</h1>'
                . '<p [^>]*font-size:17px;[^>]*color:#6b5f73;text-align:center">Coming events</p>'
                . '<table [^>]*><tr><td [^>]*background:#d9b27a">&nbsp;</td></tr></table></td></tr></table>~u',
            $mail['html']
        );
        self::assertStringNotContainsString('font-size:26px', $mail['html'], 'not the plain heading');

        $plain = Newsletter::content([], [], null);
        self::assertStringContainsString('font-size:26px', $plain['html']);
        self::assertStringNotContainsString('✦', $plain['html']);
    }

    /**
     * The opening names the event by its clean name in each language
     * (EventMesh), not the title as written.
     */
    public function testTheOpeningNamesTheEventByItsCleanName(): void
    {
        $this->nightTask();
        $this->fakeEventNames([50 => ['fi' => 'Ekstaattinen tanssi', 'en' => 'Ecstatic Dance']], [50 => ['Kai']]);
        $mail = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 7);

        self::assertStringContainsString('olit mukana Ekstaattinen tanssi -illassa', $mail['html']);
        self::assertStringContainsString('Thank you for coming to Ecstatic Dance on', $mail['html']);
        self::assertStringContainsString('Thank you for coming to Ecstatic Dance on', $mail['text']);
        self::assertStringNotContainsString('ECSTATIC DANCE 14.11', $mail['html'] . $mail['text']);
    }

    /** Without a clean name the opening keeps the title as written. */
    public function testTheOpeningKeepsTheTitleWithoutACleanName(): void
    {
        $this->nightTask();
        $mail = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 7);

        self::assertStringContainsString(
            'Thank you for coming to ECSTATIC DANCE 14.11.2026 / DJ Kai on',
            $mail['html']
        );
    }

    /**
     * Without a text of this email's own, the card shows the text the
     * announcement wrote for its event ahead of time; this email's own text
     * wins when there is one. Nothing is asked of the model while the email
     * is put together.
     */
    public function testTheCardShowsTheAnnouncementsTextWhenItHasNoneOfItsOwn(): void
    {
        $this->cardNight();
        $this->model(['{"text": "never asked"}']);
        $this->options[Newsletter::STATE_OPTION] = [
            'ledger' => [1 => ['s' => Newsletter::LEDGER_SENT, 'd' => '2026-11-21',
                'w' => ['fi' => 'Kain soundi kantaa.', 'en' => "Kai's sound carries you."]]],
        ];

        $mail = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 7);

        self::assertStringContainsString('FI</span>&nbsp; Kain soundi kantaa.</p>', $mail['html']);
        self::assertStringContainsString('EN</span>&nbsp; Kai&#039;s sound carries you.</p>', $mail['html']);
        self::assertStringContainsString("FI: Kain soundi kantaa.\nEN: Kai's sound carries you.", $mail['text']);
        self::assertSame([], $this->requests);

        $this->options[FollowupMail::STATE_OPTION]['written'][self::NIGHT] = [
            'id' => 1,
            'text' => ['fi' => 'Pyöri.', 'en' => 'Spin.'],
            'practical' => [],
        ];
        $mail = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 7);

        self::assertStringContainsString('EN</span>&nbsp; Spin.</p>', $mail['html'], 'its own text wins');
        self::assertStringNotContainsString('carries you', $mail['html']);
    }

    /**
     * With no text written anywhere and no model to ask, the emails still go
     * out at the send time, the card without a text.
     */
    public function testTheEmailsGoOutWithoutAnyText(): void
    {
        $this->cardNight();
        Functions\when('wp_doing_cron')->justReturn(false);
        $followup = $this->followup();

        $followup->run($this->at('2026-11-13 12:00'));
        $followup->run($this->at('2026-11-15 12:00'));

        self::assertSame([], $this->requests);
        self::assertCount(3, $this->mails, 'the preview and both emails');
        self::assertStringContainsString('Ecstatic Dance 1', $this->mails[1]['body']);
    }

    /** No coming events: no events part at all, as the newsletter sends nothing then. */
    public function testNoComingEventsLeavesTheEventsPartOut(): void
    {
        $mail = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 1);

        self::assertStringContainsString('Thank you for coming', $mail['html']);
        self::assertStringNotContainsString('<h1', $mail['html']);
        self::assertStringNotContainsString('Tulevat tapahtumat', $mail['html']);
        self::assertStringNotContainsString('TULEVAT TAPAHTUMAT', $mail['text']);
        self::assertStringNotContainsString('Later', $mail['html'] . $mail['text']);
    }

    public function testNoAnnouncementInviteWhileTheAnnouncementIsOff(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '0';

        $mail = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 1);

        self::assertStringNotContainsString('Tilaa<br>Subscribe', $mail['html']);
    }

    /** Neither version has a "See the coming events" button, in HTML or plain text. */
    public function testNoSeeTheComingEventsButton(): void
    {
        foreach ([true, false] as $came) {
            $mail = $this->followup()->compose(self::NIGHT, $came, 'Anna', 'b', 1);

            self::assertStringNotContainsString('See the coming events', $mail['html'] . $mail['text']);
            self::assertStringNotContainsString('Katso tulevat tapahtumat', $mail['html'] . $mail['text']);
        }
    }

    // ---- The crew part of the thank-you (Owner, 2026-11-07) -----------------

    /**
     * The target of each counted link in $html, in order.
     *
     * @return list<string>
     */
    private static function linkTargets(string $html): array
    {
        preg_match_all('/eventcrew_go=([A-Za-z0-9_.\-]+)/', $html, $tokens);

        return array_map(
            static fn (string $token): string => explode("\n", (string) SignedLink::verifyPayload(FollowupMail::PURPOSE_GO, $token), 3)[2] ?? '',
            $tokens[1]
        );
    }

    /**
     * The thank-you ends with a word for the crew and an invitation to join
     * it, in both languages, with Join on the web (the crew page) and Join on
     * Telegram (the bot's sign-up), after the announcement invite and before
     * the opt-out link. Both buttons are counted links of the row.
     */
    public function testTheThankYouCreditsTheCrewAndInvitesToJoinIt(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->options['eventcrew_signup_page_id'] = 897;
        $this->options[BoardService::USERNAME_OPTION] = 'crewbot';

        $mail = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 7);
        $html = html_entity_decode($mail['html'], ENT_QUOTES);

        $en = 'The evening was made by our crew: the people who worked behind the scenes so everyone else could'
            . ' dance. Would you like to join them? It is a lovely way to get to know people, feel useful and'
            . ' save on tickets.';
        self::assertStringContainsString($en, $html);
        self::assertStringContainsString($en, $mail['text']);
        self::assertStringContainsString('Illan teki mahdolliseksi crew:', $html, 'in Finnish too');
        self::assertStringNotContainsString('Thank you, crew!', $html . $mail['text'], 'no separate thanks (Owner)');
        self::assertStringNotContainsString('Kiitos, crew!', $html . $mail['text']);
        self::assertStringContainsString("Liity verkossa\nJoin on the web: ", $mail['text']);
        self::assertStringContainsString("Liity Telegramissa\nJoin on Telegram: ", $mail['text']);

        $join = strpos($html, 'Subscribe</');
        $crew = strpos($html, 'The evening was made by our crew');
        $optOut = strpos($html, "Don't send me these");
        self::assertNotFalse($join);
        self::assertGreaterThan($join, $crew, 'after the announcement invite');
        self::assertGreaterThan($crew, $optOut, 'before the opt-out link');

        $targets = self::linkTargets(substr($mail['html'], (int) strpos($mail['html'], 'Illan teki mahdolliseksi crew')));
        self::assertCount(2, $targets);
        self::assertStringStartsWith('https://site.test/event-897/?', $targets[0]);
        self::assertStringContainsString('utm_campaign=' . FollowupMail::campaign(self::NIGHT, true), $targets[0]);
        self::assertSame('https://t.me/crewbot?start=onboard', $targets[1], 'a start link takes no utm_ arguments');

        // Counted for the row.
        preg_match_all('/eventcrew_go=([A-Za-z0-9_.\-]+)/', substr($mail['html'], (int) strpos($mail['html'], 'Illan teki mahdolliseksi crew')), $tokens);
        self::assertStringStartsWith("b\n7\n", (string) SignedLink::verifyPayload(FollowupMail::PURPOSE_GO, $tokens[1][1]));
    }

    /** A button whose target is not set is left out; with neither, the whole crew part is. */
    public function testTheCrewButtonsShowOnlyWhereTheyLead(): void
    {
        $this->options['eventcrew_signup_page_id'] = 897;
        $webOnly = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 7);
        self::assertStringContainsString('Join on the web', $webOnly['html']);
        self::assertStringNotContainsString('Join on Telegram', $webOnly['html'] . $webOnly['text']);

        unset($this->options['eventcrew_signup_page_id']);
        $this->options[BoardService::USERNAME_OPTION] = 'crewbot';
        $botOnly = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 7);
        self::assertStringContainsString('Join on Telegram', $botOnly['html']);
        self::assertStringNotContainsString('Join on the web', $botOnly['html'] . $botOnly['text']);
        self::assertStringContainsString('The evening was made by our crew', $botOnly['text']);

        unset($this->options[BoardService::USERNAME_OPTION]);
        $neither = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 7);
        self::assertStringNotContainsString('The evening was made by our crew', $neither['html'] . $neither['text']);
        self::assertStringNotContainsString('Join on', $neither['html'] . $neither['text']);
    }

    /** The sorry-we-missed-you has no crew part: it is for the thank-you. */
    public function testTheMissedEmailHasNoCrewPart(): void
    {
        $this->options['eventcrew_signup_page_id'] = 897;
        $this->options[BoardService::USERNAME_OPTION] = 'crewbot';

        $mail = $this->followup()->compose(self::NIGHT, false, 'Anna', 'b', 7);

        self::assertStringNotContainsString('The evening was made by our crew', $mail['html'] . $mail['text']);
        self::assertStringNotContainsString('Join on', $mail['html'] . $mail['text']);
    }

    public function testATestToABadAddressFails(): void
    {
        self::assertSame(FollowupMail::TEST_FAILED, $this->followup()->sendTest(self::NIGHT, 'nope'));
        self::assertSame([], $this->mails);
    }

    // ---- The main text (FollowupText) -------------------------------------

    /** With no text saved anywhere, the email's text is exactly the old one. */
    public function testWithNothingSavedTheTextIsTheOldOne(): void
    {
        $came = $this->followup()->compose(self::NIGHT, true, 'Anna Example', 'b', 1)['text'];

        self::assertStringContainsString(
            "Hi Anna,\n\n"
                . 'Thank you for coming to Test Site on 14.11.2026. It was lovely to share the evening and the dance'
                . ' floor with you, and we hope some of that warmth went home with you.'
                . "\n\n"
                . 'The next evenings are already on their way. Have a look at what is coming and secure your spot'
                . ' for your next ecstatic dance. We would love to dance with you again.'
                . "\n\nWarmly,\nTest Site",
            $came
        );
        self::assertStringContainsString("Hei Anna,\n\nKiitos, että olit mukana Test Site", $came);

        $missed = $this->followup()->compose(self::NIGHT, false, 'Anna Example', 'b', 1)['text'];

        self::assertStringContainsString(
            "Hi Anna,\n\n"
                . 'We missed you at Test Site on 14.11.2026. We are sorry you could not make it this time. We'
                . ' understand it may have been frustrating, and we hope all is well with you.'
                . "\n\n"
                . 'There are more evenings to come, and there is a place for you on the dance floor. We hope to'
                . ' see you soon.'
                . "\n\nWarmly,\nTest Site",
            $missed
        );
    }

    /** "The next ones are below" only when coming events are below it, in both languages. */
    public function testTheNextOnesAreBelowOnlyWithComingEvents(): void
    {
        $none = $this->followup()->compose(self::NIGHT, false, 'Anna', 'b', 1);

        self::assertStringNotContainsString('The next ones are below', $none['html'] . $none['text']);
        self::assertStringNotContainsString('Seuraavat näet alta', $none['html'] . $none['text']);
        self::assertStringContainsString('on the dance floor. We hope to see you soon.', $none['text']);
        self::assertStringContainsString('paikka sinulle. Toivottavasti nähdään pian.', $none['text']);

        $this->sampleEvents();
        $some = $this->followup()->compose(self::NIGHT, false, 'Anna', 'b', 1);

        self::assertStringContainsString('The next ones are below. We hope to see you soon.', $some['text']);
        self::assertStringContainsString('Seuraavat näet alta. Toivottavasti nähdään pian.', $some['text']);
    }

    /** The Messages page text is the whole text, with its own greeting; its subject is used too. */
    public function testTheMessagesPageTextIsUsedWithTheEventAndTheDate(): void
    {
        $this->options[MessageTemplates::OPTION_PREFIX . 'followup_missed'] = [
            'subject' => [
                'source' => 'Missed you',
                'texts' => ['fi' => 'Kaipasimme', 'en' => 'Missed you'],
                'manual' => ['fi' => false, 'en' => false],
            ],
            'body' => [
                'source' => 'Dear {first_name}, sorry about {event} on {date}.',
                'texts' => [
                    'fi' => 'Hyvä {first_name}, harmi, {event} {date}.',
                    'en' => 'Dear {first_name}, sorry about {event} on {date}.',
                ],
                'manual' => ['fi' => false, 'en' => false],
            ],
        ];

        $mail = $this->followup()->compose(self::NIGHT, false, 'Anna', 'b', 1);

        self::assertStringStartsWith("Hyvä Anna, harmi, Test Site 14.11.2026.\n\n", $mail['text']);
        self::assertStringContainsString("\n\nDear Anna, sorry about Test Site on 14.11.2026.", $mail['text']);
        self::assertStringNotContainsString('Warmly,', $mail['text'], 'the sign-off is part of the saved text');
        self::assertStringNotContainsString('We are sorry you could not make it', $mail['text']);
        self::assertSame('Kaipasimme / Missed you – 14.11.2026', $mail['subject']);

        $came = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 1);
        self::assertStringContainsString('Thank you for coming to Test Site', $came['text'], 'the other is unchanged');
        self::assertStringContainsString('Thank you for dancing with us – 14.11.2026', $came['subject']);
    }

    /** With no first name, the greeting line of a Messages page text is dropped, never "Hi ,". */
    public function testWithNoFirstNameTheGreetingLineIsDropped(): void
    {
        $this->options[MessageTemplates::OPTION_PREFIX . 'followup_came'] = [
            'body' => [
                'source' => "Hi {first_name},\n\nThanks for {event}.\n\nWarmly,\nTest Site",
                'texts' => [
                    'fi' => "Hei {first_name},\n\nKiitos, {event}.\n\nLämpimästi,\nTest Site",
                    'en' => "Hi {first_name},\n\nThanks for {event}.\n\nWarmly,\nTest Site",
                ],
                'manual' => ['fi' => false, 'en' => false],
            ],
        ];

        $mail = $this->followup()->compose(self::NIGHT, true, '', 'b', 1);

        self::assertStringStartsWith("Kiitos, Test Site.\n\nLämpimästi,\nTest Site", $mail['text']);
        self::assertStringContainsString("\n\nThanks for Test Site.\n\nWarmly,\nTest Site", $mail['text']);
        self::assertStringNotContainsString('Hi ,', $mail['text']);
        self::assertStringNotContainsString('Hei ,', $mail['text']);

        // With a name, the greeting stays.
        $named = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 1);
        self::assertStringStartsWith("Hei Anna,\n\nKiitos, Test Site.", $named['text']);
    }

    /** A one-time text still wins over the Messages page text, for the next send only. */
    public function testTheOneTimeTextWinsOverTheMessagesPageText(): void
    {
        $this->options[MessageTemplates::OPTION_PREFIX . 'followup_came'] = [
            'body' => [
                'source' => 'Page text for {first_name}.',
                'texts' => ['fi' => 'Sivun teksti, {first_name}.', 'en' => 'Page text for {first_name}.'],
                'manual' => ['fi' => false, 'en' => false],
            ],
        ];
        $this->nextText(FollowupText::CAME, 'Only this time at {event}.', 'Vain tällä kertaa.', 2);

        $text = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 1)['text'];

        self::assertStringContainsString("Hi Anna,\n\nOnly this time at Test Site.\n\nWarmly,\nTest Site", $text);
        self::assertStringContainsString("Hei Anna,\n\nVain tällä kertaa.", $text);
        self::assertStringNotContainsString('Page text', $text);
        self::assertStringNotContainsString('Sivun teksti', $text);
    }

    /**
     * The board's short text about the DJ or host stands right under the
     * picture of the event it was written for, in the HTML and the plain
     * text, once; never in the paragraphs above (Owner, 2026-11-07). The texts
     * of the night's own event and of a "Later" event show nowhere.
     */
    public function testTheDjTextIsUnderTheNextEventsPicture(): void
    {
        $this->describedNight('Free dance in the hall.');
        $this->sampleEvents();
        $this->boardTexts([
            1 => 'DJ Kai plays warm organic beats. Expect a slow build.',
            2 => 'The second evening has a guest from Tallinn.',
            50 => 'The past evening had a cellist.',
        ]);
        $about = 'DJ Kai plays warm organic beats. Expect a slow build.';

        $mail = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 1);

        self::assertSame(1, substr_count($mail['html'], $about));
        self::assertSame(1, substr_count($mail['text'], $about));
        self::assertMatchesRegularExpression(
            '~<img src="https://cdn\.test/1\.jpg"[^>]*>(?:</a>)?</td></tr>.*?<td[^>]*>\s*<div style="margin:0 0 14px">'
                . '<p[^>]*>' . preg_quote(EmailBody::langTag('EN'), '~') . preg_quote($about, '~') . '</p></div>~s',
            $mail['html'],
            'the first thing under the picture, with its EN tag'
        );
        self::assertMatchesRegularExpression(
            '~\nEcstatic Dance 1\nEN: ' . preg_quote($about, '~') . '\n~',
            $mail['text']
        );

        $intro = (string) strstr($mail['text'], 'Ecstatic Dance 1', true);
        self::assertStringNotContainsString('DJ Kai', $intro, 'not in the paragraphs above');
        self::assertStringContainsString("went home with you.\n\nThe next evenings are already on their way.", $intro);

        foreach (['guest from Tallinn', 'cellist', 'Free dance'] as $other) {
            self::assertStringNotContainsString($other, $mail['html']);
            self::assertStringNotContainsString($other, $mail['text']);
        }
    }

    /**
     * A saved text with {event_description}, a one-time text with it and the
     * built-in text all leave it out of the paragraphs, with no gap, and the
     * placeholder is not offered for the post-event emails.
     */
    public function testNoTextPutsTheDjTextInTheParagraphs(): void
    {
        $this->describedNight('Free dance in the hall.');
        $this->sampleEvents();
        $this->boardTexts([1 => 'DJ Kai plays warm organic beats.', 50 => 'The past evening had a cellist.']);

        $this->nextText(FollowupText::CAME, "Only this time.\n\n{event_description}", 'Vain tällä kertaa.', 2);
        $oneTime = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 1)['text'];
        self::assertStringContainsString("Hi Anna,\n\nOnly this time.\n\nWarmly,", $oneTime);

        unset($this->options[FollowupText::NEXT_OPTIONS[FollowupText::CAME]]);
        $this->options[MessageTemplates::OPTION_PREFIX . 'followup_came'] = [
            'body' => [
                'source' => "Hi {first_name},\n\nThanks.\n\n{event_description}\n\nWarmly",
                'texts' => [
                    'fi' => "Hei {first_name},\n\nKiitos.\n\n{event_description}",
                    'en' => "Hi {first_name},\n\nThanks.\n\n{event_description}\n\nWarmly",
                ],
                'manual' => ['fi' => false, 'en' => false],
            ],
        ];
        $page = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 1)['text'];
        self::assertStringContainsString("Hi Anna,\n\nThanks.\n\nWarmly", $page);

        foreach ([$oneTime, $page] as $text) {
            self::assertStringNotContainsString('{event_description}', $text);
            self::assertStringNotContainsString('cellist', $text);
            self::assertSame(1, substr_count($text, 'DJ Kai plays warm organic beats.'));
        }

        foreach (FollowupText::TEMPLATES as $id) {
            self::assertStringNotContainsString('{event_description}', MessageTemplates::find($id)->defaultBody(), $id);
            self::assertArrayNotHasKey('{event_description}', MessageTemplates::find($id)->placeholderList(), $id);
        }
    }

    /** No text for the next event: the card is as before, with no empty block. */
    public function testWithoutADjTextTheCardIsAsBefore(): void
    {
        $this->describedNight('');
        $this->sampleEvents();
        $this->boardTexts([50 => 'The past evening had a cellist.']);

        $mail = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 1);

        self::assertStringNotContainsString('cellist', $mail['html'] . $mail['text']);
        self::assertStringContainsString("Ecstatic Dance 1\nFI: Ovet aukeavat 21:00\nEN: Doors open 21:00\n", $mail['text']);
        self::assertStringContainsString("went home with you.\n\nThe next evenings are already on their way.", $mail['text']);
        self::assertStringNotContainsString('<div style="margin:0 0 14px"></div>', $mail['html']);
    }

    /**
     * The DJ text is under the picture in every language of the email: in a
     * two-language email the Finnish translation with FI and the English
     * text with EN; in a Finnish-only email the Finnish text with no tag,
     * and the English text while the Finnish translation is missing.
     */
    public function testTheDjTextIsInEveryLanguageOfTheEmail(): void
    {
        $this->describedNight('Free dance in the hall.');
        $this->sampleEvents();
        $english = 'DJ Kai plays warm organic beats.';
        $finnish = 'DJ Kai soittaa lämpimiä orgaanisia biittejä.';
        $this->options[BoardDjText::OPTION] = [1 => [
            'hash' => md5(BoardDjText::source(1)),
            'text' => $english,
            'texts' => ['fi' => $finnish],
            'text_hash' => md5($english),
        ]];

        $both = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 1)['text'];
        self::assertStringContainsString("FI: {$finnish}
EN: {$english}", $both);

        $this->options[\EventCrew\Support\HybridPolicy::OPTION] = [\EventCrew\Support\HybridPolicy::FOLLOWUP => false];
        $mail = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 1);
        self::assertStringContainsString("\nEcstatic Dance 1\n{$finnish}\n", $mail['text']);
        self::assertStringNotContainsString($english, $mail['text']);
        self::assertSame(1, substr_count($mail['html'], $finnish));

        unset($this->options[BoardDjText::OPTION][1]['texts']['fi']);
        $fallback = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 1)['text'];
        self::assertStringContainsString("\nEcstatic Dance 1\n{$english}\n", $fallback);
    }

    /**
     * A coming event without a DJ text is queued for the background run
     * (BoardDjText::schedule()) from the day before the night and while the
     * email is put together; the model is never asked then.
     */
    public function testAMissingDjTextIsAskedForInTheBackground(): void
    {
        $this->cardNight();
        $this->model([]);
        $this->options[SiteLanguages::OPTION] = ['fi', 'en'];

        // The day before the night, before the preview.
        $this->followup()->run($this->at('2026-11-13 08:00'));
        self::assertSame([BoardDjText::HOOK], $this->scheduled);
        self::assertSame([1], $this->options[BoardDjText::QUEUE_OPTION]);
        self::assertSame([], $this->mails);

        $this->scheduled = [];
        unset($this->options[BoardDjText::QUEUE_OPTION]);
        $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 1);
        self::assertSame([BoardDjText::HOOK], $this->scheduled);
        self::assertSame([1], $this->options[BoardDjText::QUEUE_OPTION]);

        self::assertSame([], $this->requests, 'nothing is written while the email is put together');
    }

    /** The one-time text goes out with the next night's emails, and is removed once they are all sent. */
    public function testTheOneTimeTextIsUsedForTheNextSendOnly(): void
    {
        $this->options[MessageTemplates::OPTION_PREFIX . 'followup_came'] = ['body' => ['source' => 'The usual text.']];
        $this->nextText(FollowupText::CAME, 'Only this time.', 'Vain tällä kertaa.', 4);
        $this->buyer(1, 'Anna Example', 'anna@example.test');
        $this->db->checkins = [['kind' => FollowupRepository::KIND_BUYER, 'ref_id' => 1]];
        $this->previewed();

        $this->followup()->run($this->at('2026-11-15 12:00'));

        self::assertCount(1, $this->mails);
        self::assertStringContainsString('Only this time.', $this->mails[0]['body']);
        self::assertStringContainsString('Vain tällä kertaa.', $this->mails[0]['body']);
        self::assertStringNotContainsString('The usual text.', $this->mails[0]['body']);
        self::assertArrayNotHasKey(FollowupText::NEXT_OPTIONS[FollowupText::CAME], $this->options);

        $after = $this->followup()->compose('2026-11-21', true, 'Bo', 'b', 2)['text'];
        self::assertStringContainsString('The usual text.', $after, 'the next night has the Messages page text again');
    }

    /** A one-time text saved while a night is still sending is kept for the next night. */
    public function testAOneTimeTextSavedDuringTheSendIsKept(): void
    {
        for ($i = 1; $i <= 26; $i++) {
            $this->buyer($i, 'Person ' . $i, 'p' . $i . '@example.test');
        }

        $this->nextText(FollowupText::MISSED, 'First text.', 'Ensimmäinen.', 1);
        $this->previewed();
        $mail = $this->followup();
        $mail->run($this->at('2026-11-15 12:00'));

        self::assertSame(
            [FollowupText::CAME => 0, FollowupText::MISSED => 1],
            $this->state()['texts'][self::NIGHT] ?? null
        );

        $this->nextText(FollowupText::MISSED, 'Second text.', 'Toinen.', 2);
        $mail->sendBatch();

        self::assertTrue($this->state()['done'][self::NIGHT] ?? false);
        self::assertSame('Second text.', $this->options[FollowupText::NEXT_OPTIONS[FollowupText::MISSED]]['en'] ?? null);
    }

    public function testStopRemovesTheOneTimeTexts(): void
    {
        $this->nextText(FollowupText::CAME, 'Only this time.', 'Vain tällä kertaa.', 1);
        $this->nextText(FollowupText::MISSED, 'Sorry this time.', 'Harmi tällä kertaa.', 1);
        $this->buyer(1, 'Anna Example', 'anna@example.test');
        $this->previewed();

        self::assertSame(FollowupMail::STOPPED, $this->followup()->stop(self::NIGHT));

        self::assertArrayNotHasKey(FollowupText::NEXT_OPTIONS[FollowupText::CAME], $this->options);
        self::assertArrayNotHasKey(FollowupText::NEXT_OPTIONS[FollowupText::MISSED], $this->options);
    }

    public function testANightLeftTooLateRemovesTheOneTimeTexts(): void
    {
        $this->nextText(FollowupText::CAME, 'Only this time.', 'Vain tällä kertaa.', 1);
        $this->buyer(1, 'Anna Example', 'anna@example.test');
        $this->previewed();

        $this->followup()->run($this->at('2026-11-17 12:00'));

        self::assertSame([], $this->mails);
        self::assertArrayNotHasKey(FollowupText::NEXT_OPTIONS[FollowupText::CAME], $this->options);
        self::assertTrue($this->state()['expired'][self::NIGHT] ?? false);
    }

    /** The preview says, for each version, which text it has. */
    public function testThePreviewSaysWhichTextIsUsed(): void
    {
        $this->nextText(FollowupText::CAME, 'Only this time.', 'Vain tällä kertaa.', 1);
        $this->buyer(1, 'Anna Example', 'anna@example.test');
        $this->organizer();

        $this->followup()->run($this->at('2026-11-13 12:00'));

        self::assertCount(1, $this->mails);
        $body = $this->mails[0]['body'];
        $next = strpos($body, 'Text: a one-time text an organizer set for this email.');
        $settings = strpos($body, 'Text: the one on the Messages page.');
        self::assertNotFalse($next);
        self::assertNotFalse($settings);
        self::assertLessThan($settings, $next, 'the thank-you comes first, with its one-time text');
        self::assertStringContainsString('Only this time.', $body);
        self::assertArrayHasKey(FollowupText::NEXT_OPTIONS[FollowupText::CAME], $this->options, 'kept for the send');
    }

    // ---- The card's own text ----------------------------------------------

    /**
     * Before the preview, a background run writes the first coming event's
     * text (English, then Finnish) and its practical information once and
     * keeps them for the night. The preview and every email show them on the
     * one card, in both languages, in the HTML and the plain text, and never
     * the description. Later runs ask the model nothing more.
     */
    public function testTheCardsTextIsWrittenOnceAndShownInBothLanguages(): void
    {
        $this->cardNight();
        $this->model([
            '{"text": "Spin with DJ Kai at Old Hall."}',
            '{"fi": "Pyöri DJ Kain kanssa Old Hallissa."}',
            '{"venue": "Old Hall", "address": "", "doors_open": "18:30", "doors_close": ""}',
        ]);
        $followup = $this->followup();

        $followup->run($this->at('2026-11-13 12:00'));

        self::assertCount(3, $this->requests, 'the text, its translation, the practical information');
        self::assertStringContainsString('2 short sentences', $this->requests[0]['messages'][0]['content']);
        self::assertStringContainsString('Ecstatic Dance 1', $this->requests[0]['messages'][1]['content']);
        self::assertSame(1, $this->state()['written'][self::NIGHT]['id']);
        self::assertSame(
            ['fi' => 'Pyöri DJ Kain kanssa Old Hallissa.', 'en' => 'Spin with DJ Kai at Old Hall.'],
            $this->state()['written'][self::NIGHT]['text']
        );
        self::assertCount(1, $this->mails);
        $preview = $this->mails[0]['body'];
        self::assertStringContainsString('Spin with DJ Kai at Old Hall.', $preview, 'the preview shows it');
        self::assertStringContainsString('Pyöri DJ Kain kanssa Old Hallissa.', $preview);
        self::assertStringContainsString('FI</span>&nbsp; Ovet aukeavat 18:30</p>', $preview);
        self::assertStringContainsString('EN</span>&nbsp; Doors open 18:30</p>', $preview);

        $mail = $followup->compose(self::NIGHT, true, 'Anna', 'b', 7);

        self::assertSame(1, substr_count($mail['html'], 'width="536"'), 'only one card');
        self::assertStringContainsString('width="80" alt="Ecstatic Dance 2"', $mail['html'], 'Later with a picture');
        self::assertStringContainsString('Pyöri DJ Kain kanssa Old Hallissa.', $mail['html']);
        self::assertStringContainsString('Spin with DJ Kai at Old Hall.', $mail['html']);
        self::assertStringContainsString(
            "Ecstatic Dance 1\nFI: Pyöri DJ Kain kanssa Old Hallissa.\nEN: Spin with DJ Kai at Old Hall.\nOld Hall\n",
            $mail['text']
        );
        self::assertStringNotContainsString('Doors open early, dance late', $mail['html'] . $mail['text']);

        // The send: the same text for everyone, nothing asked again.
        $followup->run($this->at('2026-11-15 12:00'));

        self::assertCount(3, $this->requests, 'stored once and reused');
        self::assertCount(3, $this->mails);

        foreach (array_slice($this->mails, 1) as $sent) {
            self::assertStringContainsString('Spin with DJ Kai at Old Hall.', $sent['body']);
        }
    }

    /**
     * With a third site language (sv), the card's text is written in English
     * and translated into Finnish and Swedish in one request, which asks for
     * both by their codes. The email shows the Swedish text too.
     */
    public function testAThirdSiteLanguageGetsItsOwnCardText(): void
    {
        $this->options[SiteLanguages::OPTION] = ['fi', 'en', 'sv'];
        $this->options[\EventCrew\Support\HybridPolicy::SHOWN_OPTION] = 3;
        $this->cardNight();
        $this->model([
            '{"text": "Spin with DJ Kai at Old Hall."}',
            '{"fi": "Pyöri DJ Kain kanssa Old Hallissa.", "sv": "Snurra med DJ Kai i Old Hall."}',
            '{"venue": "Old Hall", "address": "", "doors_open": "", "doors_close": ""}',
        ]);
        $followup = $this->followup();

        $followup->run($this->at('2026-11-13 12:00'));

        self::assertCount(3, $this->requests, 'the text, one translation into both languages, the practical information');
        $system = $this->requests[1]['messages'][0]['content'];
        self::assertStringContainsString('from English into each of these languages: Finnish (fi), Swedish (sv).', $system);
        self::assertStringContainsString('{"fi": "the text in Finnish", "sv": "the text in Swedish"}', $system);
        self::assertSame(
            [
                'fi' => 'Pyöri DJ Kain kanssa Old Hallissa.',
                'en' => 'Spin with DJ Kai at Old Hall.',
                'sv' => 'Snurra med DJ Kai i Old Hall.',
            ],
            $this->state()['written'][self::NIGHT]['text']
        );

        $mail = $followup->compose(self::NIGHT, true, 'Anna', 'b', 7);

        self::assertStringContainsString('Snurra med DJ Kai i Old Hall.', $mail['html']);
        self::assertStringContainsString('Snurra med DJ Kai i Old Hall.', $mail['text']);
        self::assertStringContainsString('Pyöri DJ Kain kanssa Old Hallissa.', $mail['html']);
    }

    /**
     * A card text stored when the site had only Finnish and English still
     * shows in both after a third language (sv) is added. The email has every
     * language, so the Swedish part, which has no text of its own, does not
     * repeat the Finnish one.
     */
    public function testAnOldFinnishAndEnglishCardTextStillShows(): void
    {
        $this->cardNight();
        $this->model([
            '{"text": "Spin with DJ Kai at Old Hall."}',
            '{"fi": "Pyöri DJ Kain kanssa Old Hallissa."}',
            '{"venue": "Old Hall", "address": "", "doors_open": "", "doors_close": ""}',
        ]);
        $followup = $this->followup();
        $followup->run($this->at('2026-11-13 12:00'));

        $this->options[SiteLanguages::OPTION] = ['fi', 'en', 'sv'];
        $mail = $followup->compose(self::NIGHT, true, 'Anna', 'b', 7);

        self::assertStringContainsString('Spin with DJ Kai at Old Hall.', $mail['html']);
        self::assertStringContainsString('Pyöri DJ Kain kanssa Old Hallissa.', $mail['html']);
        self::assertSame(1, substr_count($mail['text'], 'Pyöri DJ Kain kanssa Old Hallissa.'), 'not repeated');
    }

    /**
     * A failed text leaves the card without text (never the description);
     * the practical information written in the same run is still shown, and
     * the failure is not asked again for the night.
     */
    public function testAFailedTextLeavesTheCardWithoutText(): void
    {
        $this->cardNight();
        $this->model([500, '{"venue": "Old Hall", "address": "", "doors_open": "18:30", "doors_close": ""}']);
        $followup = $this->followup();

        $followup->run($this->at('2026-11-13 12:00'));

        self::assertCount(2, $this->requests);
        self::assertSame([], $this->state()['written'][self::NIGHT]['text']);
        $mail = $followup->compose(self::NIGHT, true, 'Anna', 'b', 7);
        self::assertStringContainsString('FI</span>&nbsp; Ovet aukeavat 18:30</p>', $mail['html']);
        self::assertStringContainsString('EN</span>&nbsp; Doors open 18:30</p>', $mail['html']);
        self::assertStringNotContainsString('Doors open early, dance late', $mail['html'] . $mail['text']);
        self::assertStringNotContainsString('Spin with', $mail['html'] . $mail['text']);

        $followup->run($this->at('2026-11-15 12:00'));
        self::assertCount(2, $this->requests, 'not asked again');
    }

    /**
     * Outside a background run nothing is written and the preview goes out
     * without a text; with the switch off a stored text is not shown.
     */
    public function testNoBackgroundRunOrTheSwitchOffShowsNoText(): void
    {
        $this->cardNight();
        $this->model(['{"text": "Spin with DJ Kai at Old Hall."}']);
        Functions\when('wp_doing_cron')->justReturn(false);

        $this->followup()->run($this->at('2026-11-13 12:00'));

        self::assertSame([], $this->requests);
        self::assertArrayNotHasKey(self::NIGHT, $this->state()['written'] ?? []);
        self::assertCount(1, $this->mails, 'the preview still goes out');

        $this->options[FollowupMail::STATE_OPTION]['written'][self::NIGHT] = [
            'id' => 1,
            'text' => ['fi' => 'Pyöri.', 'en' => 'Spin.'],
            'practical' => [],
        ];
        self::assertStringContainsString('Spin.', $this->followup()->compose(self::NIGHT, true, 'A', 'b', 7)['html']);

        $this->options[NewsletterWriter::ENABLED_OPTION] = '0';
        $mail = $this->followup()->compose(self::NIGHT, true, 'A', 'b', 7);
        self::assertStringNotContainsString('Spin.', $mail['html']);
    }

    /**
     * A text written for another first event (that one was removed) is not
     * shown on this card.
     */
    public function testATextForAnotherEventIsNotShown(): void
    {
        $this->cardNight();
        $this->options[FollowupMail::STATE_OPTION] = [
            'written' => [self::NIGHT => ['id' => 9, 'text' => ['fi' => 'Pyöri.', 'en' => 'Spin.'], 'practical' => []]],
        ];
        $mail = $this->followup()->compose(self::NIGHT, true, 'A', 'b', 7);

        self::assertStringNotContainsString('Spin.', $mail['html']);
    }

    // ---- Helpers ----------------------------------------------------------

    /** A task on the night for event post 50, whose title is written as "ECSTATIC DANCE 14.11.2026 / DJ Kai". */
    private function nightTask(): void
    {
        $this->sampleEvents();
        $this->db->tasks = [['id' => 1, 'task_date' => self::NIGHT, 'role_slug' => 'door', 'event_post_id' => 50]];
        Functions\when('get_the_title')->alias(
            static fn (int $id): string => 50 === $id ? 'ECSTATIC DANCE 14.11.2026 / DJ Kai' : ''
        );
    }

    /**
     * The board's DJ or host texts (BoardDjText), by event id, each stored
     * for the event's current source so stored() returns it.
     *
     * @param array<int, string> $texts
     */
    private function boardTexts(array $texts): void
    {
        $stored = [];

        foreach ($texts as $id => $text) {
            $stored[$id] = ['hash' => md5(BoardDjText::source($id)), 'text' => $text];
        }

        $this->options[BoardDjText::OPTION] = $stored;
    }

    /** A task on the night for event post 50, whose description is $content. */
    private function describedNight(string $content): void
    {
        $this->db->tasks = [['id' => 1, 'task_date' => self::NIGHT, 'role_slug' => 'door', 'event_post_id' => 50]];
        $this->events[50] = [
            'post' => (object) [
                'ID' => 50,
                'post_type' => 'eventmesh_event',
                'post_title' => 'Ecstatic Dance',
                'post_status' => 'publish',
                'post_content' => $content,
                'post_excerpt' => '',
            ],
            'meta' => ['_eventmesh_starts_at' => self::NIGHT . 'T19:00:00'],
        ];
        Functions\when('get_the_title')->justReturn('');
    }

    /** The sample events, the first with a description that names its venue and doors, and two ticket holders. */
    private function cardNight(): void
    {
        $this->sampleEvents();
        $this->events[1]['post']->post_content = 'Old Hall, doors 18:30. Doors open early, dance late.';
        $this->buyer(1, 'Anna Example', 'anna@example.test');
        $this->guest(5, 'Gus Example', 'gus@example.test');
        $this->organizer();
    }

    /**
     * A configured language model in a background run: a stub of
     * wp_remote_post that answers from $answers in order (an int is an HTTP
     * error status) and records each decoded request.
     *
     * @param list<string|int> $answers
     */
    private function model(array $answers): void
    {
        $this->answers = $answers;
        $this->options[AssistantSettings::URL_OPTION] = 'https://llm.example.test/v1';
        $this->options[AssistantSettings::MODEL_OPTION] = 'test-model';
        Functions\when('wp_doing_cron')->justReturn(true);
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string => (string) json_encode($value));
        Functions\when('wp_remote_post')->alias(function (string $url, array $args): array {
            $this->requests[] = json_decode((string) $args['body'], true);

            return ['answer' => array_shift($this->answers) ?? ''];
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(
            static fn (array $response): int => is_int($response['answer']) ? $response['answer'] : 200
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            static fn (array $response): string => (string) json_encode(
                ['choices' => [['message' => ['content' => (string) $response['answer']]]]]
            )
        );
    }

    private function nextText(string $variant, string $en, string $fi, int $rev): void
    {
        $this->options[FollowupText::NEXT_OPTIONS[$variant]] = [
            'fi' => $fi,
            'en' => $en,
            'source' => 'en',
            'translated' => true,
            'by' => 9,
            'at' => '2026-11-10 10:00:00',
            'rev' => $rev,
        ];
    }

    private function followup(): FollowupMail
    {
        $logger = new Logger();

        return new FollowupMail(
            new FollowupRepository(),
            new TaskRepository(),
            new PersonRepository(),
            new Mailer($logger, new EmailTemplate($logger)),
            $logger
        );
    }

    private function at(string $local): \DateTimeImmutable
    {
        return new \DateTimeImmutable($local, new \DateTimeZone('Europe/Helsinki'));
    }

    private function buyer(int $id, string $name, string $email, string $date = self::NIGHT): void
    {
        $this->db->buyers[$id] = self::row($id, $name, $email, $date);
    }

    private function guest(int $id, string $name, string $email, string $date = self::NIGHT): void
    {
        $this->db->guests[$id] = self::row($id, $name, $email, $date);
    }

    /**
     * A crew member's assignment on a task of the night, with a confirmed
     * address unless $extra says otherwise.
     *
     * @param array<string, mixed> $extra
     */
    private function crew(int $id, int $personId, string $name, string $email, string $status, array $extra = []): void
    {
        $this->db->assignments[$id] = $extra + self::row($id, $name, $email, self::NIGHT)
            + ['person_id' => $personId, 'status' => $status, 'verified' => true, 'notify_prefs' => null];
    }

    /**
     * A free-entry ticket for the night, with a confirmed address unless
     * $extra says otherwise.
     *
     * @param array<string, mixed> $extra
     */
    private function credit(int $id, int $personId, string $name, string $email, array $extra = []): void
    {
        $this->db->redemptions[$id] = $extra + self::row($id, $name, $email, self::NIGHT)
            + ['person_id' => $personId, 'verified' => true, 'notify_prefs' => null];
    }

    /** The "Don't send me these" token in an email's body. */
    private function optOutTokenIn(string $body): string
    {
        self::assertSame(1, preg_match('/' . FollowupMail::OPTOUT_ARG . '=([A-Za-z0-9_.\-]+)/', $body, $match));

        return $match[1];
    }

    private function payloadOf(string $token): string
    {
        return (string) SignedLink::verifyPayload(FollowupMail::PURPOSE_OPTOUT, $token);
    }

    /** @return array<string, mixed> */
    private static function row(int $id, string $name, string $email, string $date): array
    {
        return ['id' => $id, 'name' => $name, 'email' => $email, 'event_date' => $date, 'followup_mail_status' => ''];
    }

    private function organizer(): void
    {
        $this->db->organizers = [
            ['id' => 9, 'email' => 'org@example.test', 'display_name' => 'Org', 'is_organizer' => 1],
        ];
    }

    private function previewed(string $date = self::NIGHT): void
    {
        $this->options[FollowupMail::STATE_OPTION] = ['previewed' => [$date => true]];
    }

    /** @return array<string, array<string, mixed>> */
    private function state(): array
    {
        return $this->options[FollowupMail::STATE_OPTION] ?? [];
    }

    private function sampleEvents(): void
    {
        foreach ([1 => '2026-11-21', 2 => '2026-11-28', 3 => '2026-12-05', 4 => '2026-12-12'] as $id => $date) {
            $this->events[$id] = [
                'post' => (object) [
                    'ID' => $id,
                    'post_type' => 'eventmesh_event',
                    'post_title' => 'Ecstatic Dance ' . $id,
                    'post_status' => 'publish',
                    'post_content' => str_repeat('A warm evening of free movement. ', 40),
                    'post_excerpt' => '',
                ],
                'meta' => [
                    '_eventmesh_starts_at' => $date . 'T21:00:00',
                    '_eventmesh_image_url' => 'https://cdn.test/' . $id . '.jpg',
                    '_eventmesh_price' => '25 EUR',
                    '_eventmesh_url' => 'https://shop.test/p/' . $id . '?ref=list',
                    '_eventmesh_embed_source_url' => 'https://music.test/set-' . $id,
                ],
            ];
        }
    }
}

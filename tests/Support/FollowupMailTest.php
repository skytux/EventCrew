<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\FollowupRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\FollowupMail;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\Newsletter;
use EventCrew\Support\SignedLink;
use EventCrew\Tests\TestCase;

/**
 * The post-event email: who gets which version, when the preview and the
 * emails go out (site time, Helsinki here), Stop, the switch, the tracked
 * links and the size of one email. Options live in an array, the ticket
 * holders and check-ins in FollowupWpdb, and wp_mail records each message.
 */
final class FollowupMailTest extends TestCase
{
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
            function (string $to, string $subject, string $body): bool {
                $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

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

        foreach ($this->db->queries as $sql) {
            self::assertStringContainsString("email IS NOT NULL AND email <> ''", $sql);
        }

        self::assertCount(2, $this->db->queries, 'buyers and guests');
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

            foreach (['https://cdn.test/1.jpg', 'https://cdn.test/2.jpg', 'https://cdn.test/3.jpg'] as $image) {
                self::assertStringContainsString($image, $body, 'the cards with their pictures');
            }

            self::assertStringNotContainsString('https://cdn.test/4.jpg', $body, 'three events only');
            self::assertStringContainsString('Tilaa tiedote', $body, 'the announcement invite');

            foreach (['volunteer', 'shift', 'vuoro', 'vapaaehtoinen'] as $banned) {
                self::assertStringNotContainsString($banned, mb_strtolower($body . $mail['subject']));
            }

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

    public function testNoAnnouncementInviteWhileTheAnnouncementIsOff(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '0';

        $mail = $this->followup()->compose(self::NIGHT, true, 'Anna', 'b', 1);

        self::assertStringNotContainsString('Join the announcement', $mail['html']);
        self::assertStringContainsString('See the coming events', $mail['text']);
    }

    public function testATestToABadAddressFails(): void
    {
        self::assertSame(FollowupMail::TEST_FAILED, $this->followup()->sendTest(self::NIGHT, 'nope'));
        self::assertSame([], $this->mails);
    }

    // ---- Helpers ----------------------------------------------------------

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

    /** @return array<string, array<string, bool>> */
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

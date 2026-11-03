<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\Newsletter;
use EventCrew\Support\NewsletterText;
use EventCrew\Tests\TestCase;

/**
 * The newsletter's plan, preview, stop and batches. Options live in an array,
 * EventMesh events are fakes of get_posts/get_post/get_post_meta, and wp_mail
 * records each message; nothing is sent. TestCase pins today to 2026-07-20
 * 12:00, inside the default send window.
 */
final class NewsletterTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<int, array{post: object, meta: array<string, string>}> */
    private array $events = [];

    /** @var list<array{to: string, subject: string, body: string, headers: mixed}> */
    private array $mails = [];

    /** @var list<string> */
    private array $scheduled = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [Newsletter::ENABLED_OPTION => '1'];
        $this->events = [];
        $this->mails = [];
        $this->scheduled = [];

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
            function (string $to, string $subject, string $body, mixed $headers = ''): bool {
                $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => $headers];

                return true;
            }
        );
    }

    /** @param array<string, string> $meta */
    private function event(int $id, string $date, string $title, array $meta = [], string $status = 'publish'): void
    {
        $this->events[$id] = [
            'post' => (object) [
                'ID' => $id,
                'post_type' => 'eventmesh_event',
                'post_title' => $title,
                'post_status' => $status,
                'post_content' => '',
            ],
            'meta' => ['_eventmesh_starts_at' => $date . 'T21:00:00'] + $meta,
        ];
    }

    private function newsletter(): Newsletter
    {
        $logger = new Logger();

        return new Newsletter(
            new NewsletterSubscriberRepository(),
            new PersonRepository(),
            new NewsletterText(new LlmClient($logger), $logger),
            new Mailer($logger, new EmailTemplate($logger)),
            $logger
        );
    }

    private function queueOrganizer(): void
    {
        $this->wpdb->nextResults[] = [
            ['id' => 9, 'email' => 'org@example.test', 'display_name' => 'Org', 'is_organizer' => 1],
        ];
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        return $this->options[Newsletter::STATE_OPTION] ?? [];
    }

    /** @param array<string, mixed> $plan */
    private function withPlan(array $plan): void
    {
        $this->options[Newsletter::STATE_OPTION] = [
            'ledger' => [],
            'plan' => $plan + [
                'id' => 1,
                'status' => Newsletter::PLAN_PLANNED,
                'send_on' => '2026-07-21',
                'events' => [],
                'cursor' => 0,
                'sent' => 0,
                'failed' => 0,
                'text' => null,
            ],
            'last' => null,
            'next_id' => 2,
        ];
    }

    /** @return array<string, mixed> */
    private static function planEvent(int $id, string $date, string $title = 'Event'): array
    {
        return [
            'id' => $id,
            'date' => $date,
            'time' => '21:00',
            'title' => $title,
            'title_fi' => '',
            'url' => 'https://site.test/event-' . $id . '/',
            'sold_out' => false,
        ];
    }

    public function testNothingIsPlannedOrSentWhileSwitchedOff(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '0';
        $this->event(1, '2026-07-27', 'Summer Social');

        $this->newsletter()->run();

        self::assertNull($this->state()['plan']);
        self::assertSame([], $this->mails);
    }

    /**
     * Seven days before the 27th is the 20th's next day: the plan sends on
     * 2026-07-21 and carries every event up to 2026-07-28; the organizer gets
     * the preview today with the Stop link and both languages.
     */
    public function testThePreviewDayMakesAPlanAndMailsTheOrganizers(): void
    {
        $this->event(1, '2026-07-27', 'Summer Social');
        $this->event(2, '2026-07-28', 'Late Night');
        $this->event(3, '2026-07-29', 'Too Far');
        $this->queueOrganizer();

        $this->newsletter()->run();

        $plan = $this->state()['plan'];
        self::assertSame(Newsletter::PLAN_PLANNED, $plan['status']);
        self::assertSame('2026-07-21', $plan['send_on']);
        self::assertSame([1, 2], array_column($plan['events'], 'id'));

        self::assertCount(1, $this->mails);
        $body = $this->mails[0]['body'];
        self::assertSame('org@example.test', $this->mails[0]['to']);
        self::assertStringContainsString('do=stop', $body);
        self::assertStringContainsString('Tulevat tapahtumat', $body);
        self::assertStringContainsString('Coming events', $body);
        self::assertStringContainsString('Summer Social', $body);
        self::assertStringNotContainsString('Too Far', $body);
    }

    public function testAnEventFurtherAwayIsNotPlannedYet(): void
    {
        $this->event(1, '2026-07-30', 'Later');

        $this->newsletter()->run();

        self::assertNull($this->state()['plan']);
        self::assertSame([], $this->mails);
    }

    /**
     * An event tomorrow leaves no day for a preview: it is marked missed, and
     * the next event gets its plan.
     */
    public function testAnEventTooCloseIsMissedAndTheNextOneIsPlanned(): void
    {
        $this->event(1, '2026-07-21', 'Tomorrow');
        $this->event(2, '2026-07-25', 'Saturday');
        $this->queueOrganizer();

        $this->newsletter()->run();

        $state = $this->state();
        self::assertSame(Newsletter::LEDGER_MISSED, $state['ledger'][1]['s']);
        self::assertSame('2026-07-21', $state['plan']['send_on']);
        self::assertSame([2], array_column($state['plan']['events'], 'id'));
    }

    public function testHiddenAndUnpublishedEventsAreLeftOut(): void
    {
        $this->event(1, '2026-07-26', 'Hidden', ['_eventmesh_manual_hidden' => '1']);
        $this->event(2, '2026-07-26', 'Draft', [], 'draft');
        $this->event(3, '2026-07-27', 'Disabled', ['_eventmesh_manual_disabled' => '1']);
        $this->event(4, '2026-07-27', 'Shown', ['_eventmesh_sold_out' => '1']);
        $this->queueOrganizer();

        $this->newsletter()->run();

        $plan = $this->state()['plan'];
        self::assertSame([4], array_column($plan['events'], 'id'));
        self::assertTrue($plan['events'][0]['sold_out']);
    }

    public function testStopStopsOncePlannedAndNeverWhileSending(): void
    {
        $this->withPlan(['events' => [self::planEvent(1, '2026-07-27')]]);

        self::assertSame(Newsletter::STOPPED, $this->newsletter()->stop(1));
        self::assertNull($this->state()['plan']);
        self::assertSame(Newsletter::LEDGER_STOPPED, $this->state()['ledger'][1]['s']);
        self::assertSame(Newsletter::ALREADY, $this->newsletter()->stop(1));

        $this->withPlan(['status' => Newsletter::PLAN_SENDING, 'events' => [self::planEvent(1, '2026-07-27')]]);
        self::assertSame(Newsletter::TOO_LATE, $this->newsletter()->stop(1));
    }

    /** A stopped event does not come back in a later plan. */
    public function testAStoppedEventIsNotPlannedAgain(): void
    {
        $this->event(1, '2026-07-27', 'Summer Social');
        $this->withPlan(['events' => [self::planEvent(1, '2026-07-27')]]);
        $this->newsletter()->stop(1);

        $this->newsletter()->run();

        self::assertNull($this->state()['plan']);
        self::assertSame([], $this->mails);
    }

    /** On the send day the text is fixed into the plan and the batches start. */
    public function testTheSendDayStartsTheBatches(): void
    {
        $this->event(1, '2026-07-27', 'Summer Social');
        $this->withPlan(['send_on' => '2026-07-20', 'events' => [self::planEvent(1, '2026-07-27')]]);
        $this->options[NewsletterText::OPTION] = ['fi' => 'Moi', 'en' => 'Hi', 'rev' => 3];

        $this->newsletter()->run();

        $plan = $this->state()['plan'];
        self::assertSame(Newsletter::PLAN_SENDING, $plan['status']);
        self::assertSame(['fi' => 'Moi', 'en' => 'Hi', 'rev' => 3], $plan['text']);
        self::assertSame([Newsletter::HOOK], $this->scheduled);
    }

    /**
     * The last batch mails every subscriber with the unsubscribe headers,
     * records the events as sent and clears the text it carried.
     */
    public function testTheLastBatchFinishesTheNewsletter(): void
    {
        $this->withPlan([
            'status' => Newsletter::PLAN_SENDING,
            'events' => [self::planEvent(1, '2026-07-27', 'Summer Social')],
            'text' => ['fi' => 'Moi', 'en' => 'Hi', 'rev' => 3],
        ]);
        $this->options[NewsletterText::OPTION] = ['fi' => 'Moi', 'en' => 'Hi', 'rev' => 3];
        $this->wpdb->nextResults[] = [
            ['id' => 4, 'email' => 'a@example.test'],
            ['id' => 6, 'email' => 'b@example.test'],
        ];

        self::assertSame(2, $this->newsletter()->sendBatch());

        self::assertSame(['a@example.test', 'b@example.test'], array_column($this->mails, 'to'));
        $headers = implode("\n", $this->mails[0]['headers']);
        self::assertStringContainsString(
            'List-Unsubscribe: <https://site.test/wp-json/eventcrew/v1/newsletter?do=unsubscribe',
            $headers
        );
        self::assertStringContainsString('List-Unsubscribe-Post: List-Unsubscribe=One-Click', $headers);
        self::assertStringContainsString('Moi', $this->mails[0]['body']);
        self::assertStringContainsString('<hr', $this->mails[0]['body']);

        $state = $this->state();
        self::assertNull($state['plan']);
        self::assertSame(Newsletter::LEDGER_SENT, $state['ledger'][1]['s']);
        self::assertSame(2, $state['last']['sent']);
        self::assertArrayNotHasKey(NewsletterText::OPTION, $this->options);
        self::assertSame([], $this->scheduled);
    }

    public function testAFullBatchAsksForTheNextOne(): void
    {
        $this->withPlan(['status' => Newsletter::PLAN_SENDING, 'events' => [self::planEvent(1, '2026-07-27')]]);
        $rows = [];

        for ($id = 1; $id <= Newsletter::BATCH; $id++) {
            $rows[] = ['id' => $id, 'email' => 's' . $id . '@example.test'];
        }

        $this->wpdb->nextResults[] = $rows;

        self::assertSame(Newsletter::BATCH, $this->newsletter()->sendBatch());

        $plan = $this->state()['plan'];
        self::assertSame(Newsletter::PLAN_SENDING, $plan['status']);
        self::assertSame(Newsletter::BATCH, $plan['cursor']);
        self::assertSame([Newsletter::HOOK], $this->scheduled);
    }

    /** Finnish part first with the Finnish title, then the divider, then English. */
    public function testComposeIsFinnishFirstThenEnglish(): void
    {
        $event = ['title_fi' => 'Kesätanssit', 'sold_out' => true] + self::planEvent(1, '2026-07-27', 'Summer Dance');

        $mail = $this->newsletter()->compose([$event], ['fi' => 'Tervetuloa!', 'en' => 'Welcome!']);

        $parts = explode('————————', $mail['text']);
        self::assertCount(2, $parts);
        self::assertStringContainsString('Tulevat tapahtumat', $parts[0]);
        self::assertStringContainsString('Kesätanssit', $parts[0]);
        self::assertStringContainsString('loppuunmyyty', $parts[0]);
        self::assertStringContainsString('Tervetuloa!', $parts[0]);
        self::assertStringContainsString('Coming events', $parts[1]);
        self::assertStringContainsString('Summer Dance', $parts[1]);
        self::assertStringContainsString('(sold out)', $parts[1]);
        self::assertStringContainsString('Welcome!', $parts[1]);
        self::assertStringNotContainsString('Kesätanssit', $parts[1]);
        self::assertSame('Tulevat tapahtumat / Coming events – Test Site', $mail['subject']);
    }

    /** The first line, above the Finnish part, says in English that English follows. */
    public function testTheMailStartsWithTheEnglishBelowLine(): void
    {
        $mail = $this->newsletter()->compose([self::planEvent(1, '2026-07-27', 'Summer Dance')], null);

        self::assertStringStartsWith("English version below ↓\n\n", $mail['text']);
        self::assertStringStartsWith(
            '<p style="margin:0 0 16px;color:#6b6b76;font-size:14px">English version below ↓</p>',
            $mail['html']
        );
        self::assertLessThan(
            strpos($mail['html'], 'Tulevat tapahtumat'),
            strpos($mail['html'], 'English version below')
        );
    }
}

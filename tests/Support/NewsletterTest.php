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
use EventCrew\Support\NewsletterLayout;
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
     * the preview today as HTML, with the Stop link and both languages. The
     * event after the window is in the "Later" list, not a card.
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
        self::assertStringContainsString('<h2', $body);
        self::assertStringContainsString('Content-Type: text/html', implode("\n", (array) $this->mails[0]['headers']));
        self::assertGreaterThan(strpos($body, 'Myöhemmin · Later'), strpos($body, 'Too Far'));
        self::assertGreaterThan(strpos($body, 'Late Night'), strpos($body, 'Myöhemmin · Later'));
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
        self::assertStringContainsString('FI</span>&nbsp; Moi', $this->mails[0]['body']);
        self::assertStringContainsString('EN</span>&nbsp; Hi', $this->mails[0]['body']);
        self::assertStringContainsString('Summer Social', $this->mails[0]['body']);

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

    /**
     * A card per event: the picture, the date, the title with its Finnish
     * title, the description in Finnish then English, the ticket button with
     * the price and the Listen button. A sold-out event has no ticket button.
     * The later events are lines under "Later".
     */
    public function testComposeDrawsACardPerEvent(): void
    {
        $this->event(1, '2026-07-27', 'Summer Dance', [
            '_eventmesh_image_url' => 'https://cdn.test/1.jpg',
            '_eventmesh_price' => '27.50 EUR',
            '_eventmesh_url' => 'https://shop.test/p/1',
            '_eventmesh_embed_source_url' => 'https://music.test/dj',
            '_eventmesh_original_lang' => 'en',
            '_eventmesh_tr_fi_hash' => md5('Summer Dance' . "\0"),
            '_eventmesh_tr_fi_excerpt' => 'Tanssimme koko yön.',
        ]);
        $this->events[1]['post']->post_excerpt = 'We dance all night.';
        $this->event(2, '2026-07-28', 'Full House', ['_eventmesh_url' => 'https://shop.test/p/2']);
        $soldOut = ['sold_out' => true] + self::planEvent(2, '2026-07-28', 'Full House');
        $first = ['title_fi' => 'Kesätanssit'] + self::planEvent(1, '2026-07-27', 'Summer Dance');

        $mail = $this->newsletter()->compose(
            [$first, $soldOut],
            ['fi' => 'Tervetuloa!', 'en' => 'Welcome!'],
            [self::planEvent(3, '2026-08-10', 'Autumn Night')]
        );
        $html = $mail['html'];

        self::assertSame('Tulevat tapahtumat / Coming events – Test Site', $mail['subject']);
        self::assertStringContainsString('src="https://cdn.test/1.jpg"', $html);
        self::assertStringContainsString('27.7.2026 · 21:00', $html);
        self::assertStringContainsString('Kesätanssit', $html);
        self::assertLessThan(strpos($html, 'We dance all night.'), strpos($html, 'Tanssimme koko yön.'));
        self::assertLessThan(strpos($html, 'Summer Dance'), strpos($html, 'Tervetuloa!'));
        self::assertStringContainsString('href="https://shop.test/p/1"', $html);
        self::assertStringContainsString('Osta lippu · Buy a ticket · 27,50 €', $html);
        self::assertSame(1, substr_count($html, 'Buy a ticket'));
        self::assertStringContainsString('href="https://music.test/dj"', $html);
        self::assertStringContainsString('▶ Kuuntele DJ:tä<br>Listen to the DJ</a>', $html);
        self::assertStringContainsString('Loppuunmyyty · Sold out', $html);
        self::assertGreaterThan(strpos($html, 'Myöhemmin · Later'), strpos($html, 'Autumn Night'));

        $text = $mail['text'];
        self::assertStringContainsString("FI: Tanssimme koko yön.\nEN: We dance all night.", $text);
        self::assertStringContainsString('Osta lippu · Buy a ticket · 27,50 €: https://shop.test/p/1', $text);
        self::assertStringContainsString('Kuuntele DJ:tä · Listen to the DJ: https://music.test/dj', $text);
        self::assertStringContainsString('- 10.8.2026 · 21:00 · Autumn Night: https://site.test/event-3/', $text);
    }

    /**
     * A card has no "Event page" link, in the HTML or the text. The picture
     * still opens the ticket shop, or the event page when there is no ticket.
     */
    public function testACardHasNoEventPageLink(): void
    {
        $withTicket = [
            'image' => 'https://cdn.test/1.jpg',
            'ticket_url' => 'https://shop.test/p/1',
        ] + self::planEvent(1, '2026-07-27', 'Summer Dance');
        $noTicket = ['image' => 'https://cdn.test/2.jpg'] + self::planEvent(2, '2026-07-28', 'Open Floor');

        $html = NewsletterLayout::html([$withTicket, $noTicket], [], null, '#7a3cff');
        $text = NewsletterLayout::text([$withTicket, $noTicket], [], null);

        foreach ([$html, $text] as $out) {
            self::assertStringNotContainsString('Event page', $out);
            self::assertStringNotContainsString('Tapahtuman sivu', $out);
        }

        self::assertStringNotContainsString('https://site.test/event-1/', $html);
        self::assertSame(1, substr_count($html, 'href="https://site.test/event-2/"'));
        self::assertStringContainsString('<a href="https://site.test/event-2/"><img', $html);
        self::assertStringNotContainsString('site.test/event-', $text);
    }

    /**
     * A description with blank lines is one paragraph per block, the FI / EN
     * label on the first only, and single line breaks stay <br>. The text
     * keeps the lines under its label and leaves a blank line between the
     * languages.
     */
    public function testADescriptionKeepsItsParagraphsAndLines(): void
    {
        $card = [
            'excerpt_fi' => "Tanssimme koko yön.\nOvet klo 21.\n\nOta kengät mukaan.",
            'excerpt_en' => "We dance all night.\nDoors at 21.\n\nBring shoes.",
        ] + self::planEvent(1, '2026-07-27', 'Summer Dance');

        $html = NewsletterLayout::html([$card], [], null, '#7a3cff');

        self::assertSame(1, substr_count($html, '>FI</span>'));
        self::assertSame(1, substr_count($html, '>EN</span>'));
        self::assertStringContainsString('Tanssimme koko yön.<br>Ovet klo 21.</p>', $html);
        self::assertMatchesRegularExpression('~<p style="[^"]*">Ota kengät mukaan\.</p>~', $html);
        self::assertMatchesRegularExpression('~<p style="[^"]*">Bring shoes\.</p>~', $html);
        self::assertStringNotContainsString('<br><br>', $html);

        self::assertStringContainsString(
            "FI: Tanssimme koko yön.\nOvet klo 21.\n\nOta kengät mukaan.\n\n"
                . "EN: We dance all night.\nDoors at 21.\n\nBring shoes.",
            NewsletterLayout::text([$card], [], null)
        );
    }

    /** A sending plan lists the coming events it does not carry under "Later". */
    public function testTheSendDayKeepsTheLaterEvents(): void
    {
        $this->event(1, '2026-07-27', 'Summer Social');
        $this->event(2, '2026-08-20', 'Far Away');
        $this->withPlan(['events' => [self::planEvent(1, '2026-07-27', 'Summer Social')], 'send_on' => '2026-07-20']);

        $this->newsletter()->run();

        self::assertSame([2], array_column($this->state()['plan']['later'], 'id'));
    }

    public function testThePriceIsWrittenTheFinnishWay(): void
    {
        self::assertSame('22 €', NewsletterLayout::price('22.00 EUR'));
        self::assertSame('27,50 €', NewsletterLayout::price('27.50 EUR'));
        self::assertSame('5 €', NewsletterLayout::price('5 €'));
        self::assertSame('Free', NewsletterLayout::price('Free'));
        self::assertSame('', NewsletterLayout::price(''));
    }
}

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
use EventCrew\Support\NewsletterSchedule;
use EventCrew\Support\NewsletterText;
use EventCrew\Support\SignedLink;
use EventCrew\Tests\TestCase;

/**
 * The newsletter's plan, preview, stop and batches. Options live in an array,
 * EventMesh events are fakes of get_posts/get_post/get_post_meta, and wp_mail
 * records each message; nothing is sent. TestCase pins today to 2026-07-20
 * 12:00, inside the default send window.
 */
final class NewsletterTest extends TestCase
{
    use FakeEventNames;

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
        self::assertSame(
            ['fi' => 'Moi', 'en' => 'Hi', 'rev' => 3, 'source' => '', 'translated' => false],
            $plan['text']
        );
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

    /**
     * Every announcement starts with a greeting of its own, in the
     * subscriber's language: "Hei Anna!" for Finnish, "Hi Bo!" for English,
     * both for a subscriber whose language is not known; the name escaped, a
     * plain "Hei! · Hi!" without. The footer link opens that subscriber's
     * manage page.
     */
    public function testEachSubscriberIsGreetedByName(): void
    {
        $this->withPlan([
            'status' => Newsletter::PLAN_SENDING,
            'events' => [self::planEvent(1, '2026-07-27', 'Summer Social')],
        ]);
        $this->wpdb->nextResults[] = [
            ['id' => 4, 'email' => 'a@example.test', 'name' => 'Anna', 'language' => 'fi'],
            ['id' => 6, 'email' => 'b@example.test', 'name' => null, 'language' => ''],
            ['id' => 8, 'email' => 'c@example.test', 'name' => '<i>Bo</i>', 'language' => 'en'],
        ];

        self::assertSame(3, $this->newsletter()->sendBatch());

        self::assertStringContainsString('>Hei Anna!</p>', $this->mails[0]['body']);
        self::assertStringNotContainsString('Hi Anna!', $this->mails[0]['body']);
        self::assertStringContainsString('Hei! · Hi!', $this->mails[1]['body']);
        self::assertStringNotContainsString('Anna', $this->mails[1]['body'], 'one greeting per subscriber');
        self::assertStringContainsString('>Hi &lt;i&gt;Bo&lt;/i&gt;!</p>', $this->mails[2]['body']);
        self::assertStringNotContainsString('Hei &lt;i&gt;Bo', $this->mails[2]['body']);
        self::assertStringNotContainsString('<i>Bo</i>', $this->mails[2]['body']);
        self::assertLessThan(
            strpos($this->mails[0]['body'], 'Summer Social'),
            strpos($this->mails[0]['body'], 'Hei Anna!'),
            'the greeting comes first'
        );

        preg_match('/do=manage&(?:amp;)?token=([A-Za-z0-9_.\-]+)/', $this->mails[0]['body'], $token);
        self::assertNotEmpty($token, 'the footer links to the manage page');
        self::assertSame(4, SignedLink::verify(Newsletter::PURPOSE_MANAGE, $token[1]));
        self::assertStringContainsString('Muuta tietojasi tai peru tiedotteen tilaus', $this->mails[0]['body']);
        self::assertStringContainsString('Change your details or leave the announcement', $this->mails[1]['body']);
        // The one-click header still unsubscribes.
        self::assertStringContainsString('do=unsubscribe', implode("\n", $this->mails[0]['headers']));
    }

    public function testThePlainTextGreeting(): void
    {
        self::assertSame('Hei Anna! · Hi Anna!', Newsletter::greeting('Anna')['text']);
        self::assertSame('Hei! · Hi!', Newsletter::greeting(null)['text']);
        self::assertSame('Hei! · Hi!', Newsletter::greeting('')['text']);
        // A long name puts each language on its own line.
        self::assertSame(
            "Hei Maria-Kristiina Eleonoora!\nHi Maria-Kristiina Eleonoora!",
            Newsletter::greeting('Maria-Kristiina Eleonoora')['text']
        );
    }

    /** The organizers' preview starts with the greeting a subscriber gets, with the organizer's own name. */
    public function testThePreviewGreetsTheOrganizerByName(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->withPlan(['events' => [self::planEvent(1, '2026-07-27', 'Summer Social')]]);
        $this->wpdb->nextResults[] = [
            ['id' => 9, 'email' => 'org@example.test', 'display_name' => 'Olli Organizer', 'is_organizer' => 1],
        ];

        $method = new \ReflectionMethod(Newsletter::class, 'sendPreview');
        $method->setAccessible(true);

        self::assertSame(1, $method->invoke($this->newsletter(), $this->state()['plan']));
        self::assertStringContainsString('Hei Olli! · Hi Olli!', $this->mails[0]['body']);
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
     * title, the ticket button with the price and the Listen button, and no
     * description. A sold-out event has no ticket button. The later events
     * are lines under "Later".
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
        self::assertStringNotContainsString('We dance all night.', $html);
        self::assertStringNotContainsString('Tanssimme koko yön.', $html);
        self::assertLessThan(strpos($html, 'Summer Dance'), strpos($html, 'Tervetuloa!'));
        self::assertStringContainsString('href="https://shop.test/p/1"', $html);
        self::assertStringContainsString('Osta lippu · Buy a ticket · 27,50 €', $html);
        self::assertSame(1, substr_count($html, 'Buy a ticket'));
        self::assertStringContainsString('href="https://music.test/dj"', $html);
        self::assertStringContainsString('▶<br>Kuuntele DJ:tä<br>Listen to the DJ</a>', $html);
        self::assertStringContainsString('Loppuunmyyty · Sold out', $html);
        self::assertGreaterThan(strpos($html, 'Myöhemmin · Later'), strpos($html, 'Autumn Night'));

        $text = $mail['text'];
        self::assertStringNotContainsString('We dance all night.', $text);
        self::assertStringNotContainsString('Tanssimme koko yön.', $text);
        self::assertStringContainsString('Osta lippu · Buy a ticket · 27,50 €: https://shop.test/p/1', $text);
        self::assertStringContainsString("Kuuntele DJ:tä\nListen to the DJ: https://music.test/dj", $text);
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
     * An organizers' text with blank lines is one paragraph per block, the
     * FI / EN label on the first only, and single line breaks stay <br>. The
     * text keeps the lines under its label and leaves a blank line between
     * the languages. (The cards show no description since 2026-11-03.)
     */
    public function testAnOrganizersTextKeepsItsParagraphsAndLines(): void
    {
        $card = self::planEvent(1, '2026-07-27', 'Summer Dance');
        $intro = [
            'fi' => "Tanssimme koko yön.\nOvet klo 21.\n\nOta kengät mukaan.",
            'en' => "We dance all night.\nDoors at 21.\n\nBring shoes.",
        ];

        $html = NewsletterLayout::html([$card], [], $intro, '#7a3cff');

        self::assertSame(1, substr_count($html, '>FI</span>'));
        self::assertSame(1, substr_count($html, '>EN</span>'));
        self::assertStringContainsString('Tanssimme koko yön.<br>Ovet klo 21.</p>', $html);
        self::assertMatchesRegularExpression('~<p style="[^"]*">Ota kengät mukaan\.</p>~', $html);
        self::assertMatchesRegularExpression('~<p style="[^"]*">Bring shoes\.</p>~', $html);
        self::assertStringNotContainsString('<br><br>', $html);

        self::assertStringContainsString(
            "FI: Tanssimme koko yön.\nOvet klo 21.\n\nOta kengät mukaan.\n\n"
                . "EN: We dance all night.\nDoors at 21.\n\nBring shoes.",
            NewsletterLayout::text([$card], [], $intro)
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

    /**
     * compose()'s HTML and text for one fixed input are byte for byte the
     * ones saved in fixtures/, which were made before the events part was
     * shared with the post-event email (FollowupMail), and made again when
     * the cards lost their description and were centred, and again when a
     * card's picture took the date and the title into its alt text
     * (2026-11-03).
     */
    public function testComposeOutputIsUnchangedForAFixedInput(): void
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
        $this->event(3, '2026-08-10', 'Autumn Night', ['_eventmesh_price' => '22 EUR']);

        $mail = $this->newsletter()->compose(
            [
                ['title_fi' => 'Kesätanssit'] + self::planEvent(1, '2026-07-27', 'Summer Dance'),
                ['sold_out' => true] + self::planEvent(2, '2026-07-28', 'Full House'),
            ],
            ['fi' => 'Tervetuloa!', 'en' => 'Welcome!'],
            [self::planEvent(3, '2026-08-10', 'Autumn Night')]
        );

        self::assertSame('Tulevat tapahtumat / Coming events – Test Site', $mail['subject']);
        self::assertStringEqualsFile(__DIR__ . '/fixtures/newsletter-compose.html', $mail['html']);
        self::assertStringEqualsFile(__DIR__ . '/fixtures/newsletter-compose.txt', $mail['text']);
    }

    /**
     * The sample announcement of the language tests: a card with a picture,
     * a price, a ticket and a music page and door times, a sold-out card, a
     * later event, and the organizers' $text, composed in $lang.
     *
     * @param array<string, mixed>|null $text
     * @param array<string, mixed>|null $opening The language model's opening.
     * @return array{subject: string, html: string, text: string}
     */
    private function composeSample(string $lang, ?array $text, ?array $opening = null): array
    {
        $this->event(1, '2026-07-27', 'Summer Dance', [
            '_eventmesh_image_url' => 'https://cdn.test/1.jpg',
            '_eventmesh_price' => '27.50 EUR',
            '_eventmesh_url' => 'https://shop.test/p/1',
            '_eventmesh_embed_source_url' => 'https://music.test/dj',
        ]);
        $this->event(2, '2026-07-28', 'Full House', ['_eventmesh_url' => 'https://shop.test/p/2']);
        $this->event(3, '2026-08-10', 'Autumn Night', ['_eventmesh_price' => '22 EUR']);

        return $this->newsletter()->compose(
            [
                self::planEvent(1, '2026-07-27', 'Summer Dance'),
                ['sold_out' => true] + self::planEvent(2, '2026-07-28', 'Full House'),
            ],
            $text,
            [self::planEvent(3, '2026-08-10', 'Autumn Night')],
            [
                'events' => [],
                'opening' => $opening,
                'practical' => [
                    1 => [
                        'venue' => 'Old Hall',
                        'address' => 'Main St 1',
                        'doors_open' => '18:30',
                        'doors_close' => '',
                    ],
                ],
            ],
            $lang
        );
    }

    /**
     * A subscriber whose language is Finnish gets the announcement in Finnish
     * only: the subject, the heading, the organizers' text with no FI label,
     * the buttons, the door times and "Later", in the HTML and the text.
     */
    public function testAFinnishSubscriberGetsTheAnnouncementInFinnishOnly(): void
    {
        $written = ['fi' => 'Tervetuloa!', 'en' => 'Welcome!', 'source' => 'fi', 'translated' => true];
        $mail = $this->composeSample('fi', $written);
        $html = $mail['html'];
        $text = $mail['text'];

        self::assertSame('Tulevat tapahtumat – Test Site', $mail['subject']);
        self::assertStringContainsString('>Tulevat tapahtumat</h1>', $html);
        self::assertStringContainsString('>Tervetuloa!</p>', $html);
        self::assertStringContainsString('Osta lippu · 27,50 €', $html);
        self::assertStringContainsString('▶<br>Kuuntele DJ:tä</a>', $html);
        self::assertStringContainsString('Loppuunmyyty', $html);
        self::assertStringContainsString('>Myöhemmin</h2>', $html);
        self::assertStringContainsString('>Ovet aukeavat 18:30</p>', $html);
        self::assertStringContainsString('Old Hall<br>Main St 1', $html);

        self::assertStringStartsWith("TULEVAT TAPAHTUMAT\n\nTervetuloa!\n\n", $text);
        self::assertStringContainsString("Main St 1\n\nOvet aukeavat 18:30\n", $text);
        self::assertStringContainsString('Osta lippu · 27,50 €: https://shop.test/p/1', $text);
        self::assertStringContainsString('Kuuntele DJ:tä: https://music.test/dj', $text);

        foreach ([$html, $text] as $out) {
            $english = ['Coming events', 'Welcome!', 'Buy a ticket', 'Listen to the DJ'];
            $english = [...$english, 'Sold out', 'Later', 'Doors open'];
            foreach ($english as $en) {
                self::assertStringNotContainsString($en, $out);
            }

            self::assertStringNotContainsString('>FI</span>', $out);
            self::assertStringNotContainsString('FI: ', $out);
            self::assertStringNotContainsString('Automatically translated', $out);
            self::assertStringNotContainsString('Käännetty automaattisesti', $out);
        }
    }

    /**
     * A subscriber whose language is English gets the announcement in English
     * only. The organizers wrote their text in Finnish, so its English was
     * translated, and the email ends with the small "Automatically
     * translated" note, in the HTML and the text.
     */
    public function testAnEnglishSubscriberGetsTheAnnouncementInEnglishOnly(): void
    {
        $written = ['fi' => 'Tervetuloa!', 'en' => 'Welcome!', 'source' => 'fi', 'translated' => true];
        $mail = $this->composeSample('en', $written);
        $html = $mail['html'];
        $text = $mail['text'];
        $note = NewsletterLayout::translatedNote();

        self::assertSame('Coming events – Test Site', $mail['subject']);
        self::assertStringContainsString('>Coming events</h1>', $html);
        self::assertStringContainsString('>Welcome!</p>', $html);
        self::assertStringContainsString('Buy a ticket · 27,50 €', $html);
        self::assertStringContainsString('▶<br>Listen to the DJ</a>', $html);
        self::assertStringContainsString('Sold out', $html);
        self::assertStringContainsString('>Later</h2>', $html);
        self::assertStringContainsString('>Doors open 18:30</p>', $html);

        self::assertStringStartsWith("COMING EVENTS\n\nWelcome!\n\n", $text);
        self::assertStringContainsString('Buy a ticket · 27,50 €: https://shop.test/p/1', $text);
        self::assertStringContainsString('Listen to the DJ: https://music.test/dj', $text);

        self::assertStringEndsWith($note['html'], $html);
        self::assertStringEndsWith("\n\nAutomatically translated", $text);
        self::assertStringContainsString('font-size:12px', $note['html']);
        self::assertStringContainsString('>Automatically translated</p>', $note['html']);

        foreach ([$html, $text] as $out) {
            $finnish = ['Tulevat tapahtumat', 'Tervetuloa!', 'Osta lippu', 'Kuuntele'];
            $finnish = [...$finnish, 'Loppuunmyyty', 'Myöhemmin', 'Ovet aukeavat'];
            foreach ($finnish as $fi) {
                self::assertStringNotContainsString($fi, $out);
            }

            self::assertStringNotContainsString('>EN</span>', $out);
            self::assertStringNotContainsString('EN: ', $out);
        }
    }

    /**
     * A subscriber whose language is not known gets the two-language email
     * exactly as before: the same as compose() without a language, which the
     * fixture test holds byte for byte. It never has the translated note.
     */
    public function testASubscriberWithNoLanguageGetsBothLanguagesAsBefore(): void
    {
        $text = ['fi' => 'Tervetuloa!', 'en' => 'Welcome!', 'source' => 'fi', 'translated' => true];
        $both = $this->composeSample('', $text);
        $events = [self::planEvent(1, '2026-07-27', 'Summer Dance')];

        self::assertSame(
            $this->newsletter()->compose($events, $text),
            $this->newsletter()->compose($events, $text, [], null, '')
        );
        self::assertSame('Tulevat tapahtumat / Coming events – Test Site', $both['subject']);
        self::assertStringContainsString('FI</span>&nbsp; Tervetuloa!', $both['html']);
        self::assertStringContainsString('EN</span>&nbsp; Welcome!', $both['html']);
        self::assertStringContainsString('Osta lippu · Buy a ticket · 27,50 €', $both['html']);
        self::assertStringContainsString('Myöhemmin · Later', $both['html']);
        self::assertStringContainsString("FI: Ovet aukeavat 18:30\nEN: Doors open 18:30", $both['text']);
        self::assertStringNotContainsString('Automatically translated', $both['html'] . $both['text']);
    }

    /**
     * The "Automatically translated" note is on an English-only email when
     * its English text was translated by the language model: an organizers'
     * text written in another language, the language model's opening (written
     * in Finnish), or a text kept from before the source was stored whose
     * languages differ. An organizers' text written in English, or not
     * translated, has no note, and neither has a Finnish email.
     */
    public function testTheTranslatedNoteIsOnlyOnMachineTranslatedEnglish(): void
    {
        $note = 'Automatically translated';
        $opening = ['fi' => 'Tervetuloa tanssimaan.', 'en' => 'Welcome to dance.'];

        $cases = [
            'written in Finnish' => [['fi' => 'Moi', 'en' => 'Hi', 'source' => 'fi', 'translated' => true], null, true],
            'written in English' => [
                ['fi' => 'Moi', 'en' => 'Hi', 'source' => 'en', 'translated' => true],
                null,
                false,
            ],
            'not translated' => [['fi' => 'Hi', 'en' => 'Hi', 'source' => '', 'translated' => false], null, false],
            'the opening' => [null, $opening, true],
            'old plan, translated' => [['fi' => 'Moi', 'en' => 'Hi', 'rev' => 3], null, true],
            'old plan, the same' => [['fi' => 'Hi', 'en' => 'Hi', 'rev' => 3], null, false],
            'no text at all' => [null, null, false],
        ];

        foreach ($cases as $case => [$text, $written, $expected]) {
            $mail = $this->composeSample('en', $text, $written);
            self::assertSame($expected, str_contains($mail['html'], $note), $case . ' (html)');
            self::assertSame($expected, str_contains($mail['text'], $note), $case . ' (text)');

            foreach (['fi', ''] as $lang) {
                $other = $this->composeSample($lang, $text, $written);
                self::assertStringNotContainsString($note, $other['html'] . $other['text'], $case . ' in ' . $lang);
            }
        }

        $withOpening = $this->composeSample('en', null, $opening);
        self::assertStringContainsString('>Welcome to dance.</p>', $withOpening['html']);
        self::assertStringNotContainsString('Tervetuloa tanssimaan.', $withOpening['html']);
    }

    /**
     * One batch with a Finnish, an English and an unknown-language
     * subscriber: each gets the subject, the body and the footer in their own
     * language, and only the English one has the translated note.
     */
    public function testEachSubscriberGetsTheAnnouncementInTheirLanguage(): void
    {
        $this->event(1, '2026-07-27', 'Summer Social');
        $this->withPlan([
            'status' => Newsletter::PLAN_SENDING,
            'events' => [self::planEvent(1, '2026-07-27', 'Summer Social')],
            'text' => ['fi' => 'Moi', 'en' => 'Hi there', 'rev' => 3, 'source' => 'fi', 'translated' => true],
        ]);
        $this->wpdb->nextResults[] = [
            ['id' => 4, 'email' => 'fi@example.test', 'name' => 'Anna', 'language' => 'fi'],
            ['id' => 6, 'email' => 'en@example.test', 'name' => 'Bo', 'language' => 'en'],
            ['id' => 8, 'email' => 'both@example.test', 'name' => 'Cy', 'language' => ''],
        ];

        self::assertSame(3, $this->newsletter()->sendBatch());

        [$fi, $en, $both] = $this->mails;
        self::assertSame('Tulevat tapahtumat – Test Site', $fi['subject']);
        self::assertSame('Coming events – Test Site', $en['subject']);
        self::assertSame('Tulevat tapahtumat / Coming events – Test Site', $both['subject']);

        self::assertStringContainsString('>Moi</p>', $fi['body']);
        self::assertStringNotContainsString('Hi there', $fi['body']);
        self::assertStringContainsString('Muuta tietojasi tai peru tiedotteen tilaus', $fi['body']);
        self::assertStringNotContainsString('Change your details', $fi['body']);
        self::assertStringNotContainsString('Crew members get this', $fi['body']);

        self::assertStringContainsString('>Hi there</p>', $en['body']);
        self::assertStringNotContainsString('>Moi', $en['body']);
        self::assertStringContainsString('Change your details or leave the announcement', $en['body']);
        self::assertStringNotContainsString('Muuta tietojasi', $en['body']);
        self::assertStringContainsString('Automatically translated', $en['body']);
        self::assertLessThan(
            strpos($en['body'], 'Change your details'),
            strpos($en['body'], 'Automatically translated'),
            'the note is above the footer'
        );

        self::assertStringContainsString('Hei Cy! · Hi Cy!', $both['body']);
        self::assertStringContainsString('FI</span>&nbsp; Moi', $both['body']);
        self::assertStringContainsString('EN</span>&nbsp; Hi there', $both['body']);
        self::assertStringContainsString('Muuta tietojasi tai peru tiedotteen tilaus / ', $both['body']);

        foreach ([$fi, $both] as $mail) {
            self::assertStringNotContainsString('Automatically translated', $mail['body']);
        }
    }

    /**
     * The organizers get one preview per version that has a subscriber: here
     * Finnish, English and both languages, each with its greeting and a line
     * that says who gets it. With subscribers of one language only, there is
     * one preview, of that version.
     */
    public function testThePreviewShowsEachVersionThatHasSubscribers(): void
    {
        $this->options[Newsletter::ENABLED_OPTION] = '1';
        $this->withPlan(['events' => [self::planEvent(1, '2026-07-27', 'Summer Social')]]);
        $this->wpdb->nextResults[] = [
            ['id' => 9, 'email' => 'org@example.test', 'display_name' => 'Olli Organizer', 'is_organizer' => 1],
        ];
        $this->wpdb->nextResults[] = [
            ['language' => 'fi', 'n' => 2],
            ['language' => 'en', 'n' => 1],
            ['language' => '', 'n' => 3],
        ];

        $method = new \ReflectionMethod(Newsletter::class, 'sendPreview');
        $method->setAccessible(true);

        self::assertSame(3, $method->invoke($this->newsletter(), $this->state()['plan']));
        self::assertCount(3, $this->mails);
        [$fi, $en, $both] = $this->mails;

        self::assertStringEndsWith('(FI)', $fi['subject']);
        self::assertStringEndsWith('(EN)', $en['subject']);
        self::assertStringEndsWith('(FI + EN)', $both['subject']);

        self::assertStringContainsString('>Hei Olli!</p>', $fi['body']);
        self::assertStringContainsString('This is the Finnish version. Subscribers who get it: 2.', $fi['body']);
        self::assertStringContainsString('Tulevat tapahtumat – Test Site', $fi['body']);
        self::assertStringNotContainsString('Coming events', $fi['body']);

        self::assertStringContainsString('>Hi Olli!</p>', $en['body']);
        self::assertStringContainsString('This is the English version. Subscribers who get it: 1.', $en['body']);
        self::assertStringNotContainsString('Tulevat tapahtumat', $en['body']);

        self::assertStringContainsString('Hei Olli! · Hi Olli!', $both['body']);
        self::assertStringContainsString('This is the two-language version. Subscribers who get it: 3.', $both['body']);
        self::assertStringContainsString('goes out on 21.7.2026 to 6 subscribers', $both['body']);

        $this->mails = [];
        $this->wpdb->nextResults[] = [
            ['id' => 9, 'email' => 'org@example.test', 'display_name' => 'Olli Organizer', 'is_organizer' => 1],
        ];
        $this->wpdb->nextResults[] = [['language' => 'en', 'n' => 4]];

        self::assertSame(1, $method->invoke($this->newsletter(), $this->state()['plan']));
        self::assertStringEndsWith('(EN)', $this->mails[0]['subject']);
        self::assertStringContainsString('>Hi Olli!</p>', $this->mails[0]['body']);
    }

    /** With one subscriber the preview says "1 subscriber", not "1 subscribers". */
    public function testThePreviewCountsOneSubscriberInTheSingular(): void
    {
        $this->withPlan(['events' => [self::planEvent(1, '2026-07-27', 'Summer Social')]]);
        $this->wpdb->nextResults[] = [
            ['id' => 9, 'email' => 'org@example.test', 'display_name' => 'Olli Organizer', 'is_organizer' => 1],
        ];
        $this->wpdb->nextResults[] = [['language' => '', 'n' => 1]];

        $method = new \ReflectionMethod(Newsletter::class, 'sendPreview');
        $method->setAccessible(true);
        $method->invoke($this->newsletter(), $this->state()['plan']);

        self::assertStringContainsString('goes out on 21.7.2026 to 1 subscriber.', $this->mails[0]['body']);
    }

    /**
     * With EventMesh's clean names, a card with a picture has the date, the
     * name, the Finnish name when it is different and the DJ line in the
     * picture's alt text, and not as text under the picture (the poster
     * shows them). A "Later" line shows the name and the DJ line under it,
     * and its small picture's alt text keeps the title as written.
     */
    public function testTheCleanNameAndTheDjLineReplaceTheTitle(): void
    {
        $this->fakeEventNames(
            [
                1 => ['en' => 'Ecstatic Dance', 'fi' => 'Ekstaattinen tanssi'],
                2 => ['en' => 'Open Floor', 'fi' => 'Open Floor'],
            ],
            [1 => ['Ihmeidentekijä'], 2 => ['Milla Linna', 'Ty Alexander']]
        );
        $card = ['image' => 'https://cdn.test/1.jpg']
            + self::planEvent(1, '2026-07-27', 'ECSTATIC DANCE 27.7.2026 / Ihmeidentekijä');
        $card['title_fi'] = 'EKSTAATTINEN TANSSI 27.7.2026 / Ihmeidentekijä';
        $later = ['image' => 'https://cdn.test/2.jpg']
            + self::planEvent(2, '2026-08-10', 'OPEN FLOOR 10.8.2026 / Milla Linna & Ty Alexander');

        $html = NewsletterLayout::html([$card], [$later], null, '#7a3cff');

        self::assertStringContainsString(
            'width="536" alt="27.7.2026 · 21:00 — Ecstatic Dance — Ekstaattinen tanssi — Ihmeidentekijä"',
            $html
        );
        self::assertStringNotContainsString('>Ecstatic Dance<', $html);
        self::assertStringNotContainsString('>Ekstaattinen tanssi<', $html);
        self::assertStringNotContainsString('>Ihmeidentekijä<', $html);
        self::assertStringNotContainsString('>27.7.2026 · 21:00<', $html);
        self::assertStringNotContainsString('<h2 style="margin:0 0', $html, 'no title under the picture');
        self::assertStringContainsString('Open Floor<br>Milla Linna &amp; Ty Alexander', $html);
        self::assertStringContainsString('alt="Open Floor"', $html, 'the Later picture by its clean name');
        self::assertStringNotContainsString('alt="OPEN FLOOR', $html);
        self::assertStringNotContainsString('>ECSTATIC DANCE', $html);
        self::assertStringNotContainsString('EKSTAATTINEN', $html);

        $text = NewsletterLayout::text([$card], [$later], null);

        self::assertStringContainsString("27.7.2026 · 21:00\nEcstatic Dance\nIhmeidentekijä", $text);
        self::assertStringContainsString(
            "- 10.8.2026 · 21:00 · Open Floor: https://site.test/event-2/\n  Milla Linna & Ty Alexander",
            $text
        );
        self::assertStringNotContainsString('OPEN FLOOR', $text);
    }

    /** The same Finnish and English name is written once. */
    public function testTheFinnishNameIsLeftOutWhenItIsTheSame(): void
    {
        $this->fakeEventNames([1 => ['en' => 'Ecstatic Dance']], [1 => ['Ihmeidentekijä']]);
        $card = self::planEvent(1, '2026-07-27', 'ECSTATIC DANCE 27.7.2026');
        $card['title_fi'] = 'EKSTAATTINEN TANSSI 27.7.2026';

        $html = NewsletterLayout::html([$card], [], null, '#7a3cff');

        self::assertSame(1, substr_count($html, 'Ecstatic Dance'));
        self::assertStringNotContainsString('EKSTAATTINEN', $html);
        self::assertStringContainsString('Ihmeidentekijä', $html);
    }

    /** A card with no picture writes the date, the name, the Finnish name and the DJ line as text. */
    public function testACardWithNoPictureWritesTheNameAsText(): void
    {
        $this->fakeEventNames(
            [1 => ['en' => 'Ecstatic Dance', 'fi' => 'Ekstaattinen tanssi']],
            [1 => ['Ihmeidentekijä']]
        );
        $card = self::planEvent(1, '2026-07-27', 'ECSTATIC DANCE 27.7.2026 / Ihmeidentekijä');

        $html = NewsletterLayout::html([$card], [], null, '#7a3cff');

        self::assertStringNotContainsString('<img', $html);
        self::assertMatchesRegularExpression('~<p style="margin:0 0 6px;[^"]*">27\.7\.2026 · 21:00</p>~u', $html);
        self::assertMatchesRegularExpression('~<h2 style="margin:0 0 2px;[^"]*">Ecstatic Dance</h2>~', $html);
        self::assertMatchesRegularExpression('~<p style="margin:0 0 2px;[^"]*">Ekstaattinen tanssi</p>~', $html);
        self::assertMatchesRegularExpression('~<p style="margin:0 0 14px;[^"]*">Ihmeidentekijä</p>~u', $html);
    }

    /**
     * A picture's alt text falls back to the title as written, is escaped,
     * and the picture is styled so the alt text reads well when pictures are
     * not loaded. The venue, the door times and the buttons stay under it.
     */
    public function testThePictureAltTextIsEscapedAndTheRestStaysVisible(): void
    {
        $card = [
            'image' => 'https://cdn.test/1.jpg',
            'ticket_url' => 'https://shop.test/p/1',
            'listen' => 'https://music.test/dj',
            'practical' => [
                'venue' => 'Old Hall',
                'address' => 'Main Street 1, Helsinki',
                'doors_open' => '18:30',
                'doors_close' => '19:00',
            ],
        ] + self::planEvent(1, '2026-07-27', 'Rock & "Roll" <Night>');

        $html = NewsletterLayout::html([$card], [], null, '#7a3cff');

        self::assertMatchesRegularExpression(
            '~<img src="https://cdn\.test/1\.jpg" width="536" '
                . 'alt="27\.7\.2026 · 21:00 — Rock &amp; &quot;Roll&quot; &lt;Night&gt;" style="[^"]*">~u',
            $html
        );
        preg_match('~<img [^>]*style="([^"]*)"~', $html, $style);

        $rules = ['max-width:536px', 'font-size:20px', 'font-weight:700', 'text-align:center', 'background:'];

        foreach ($rules as $rule) {
            self::assertStringContainsString($rule, $style[1]);
        }

        self::assertStringNotContainsString('<h2', $html);
        self::assertStringContainsString('Old Hall<br>Main Street 1, Helsinki</p>', $html);
        self::assertStringContainsString('FI</span>&nbsp; Ovet aukeavat 18:30<br>Ovet sulkeutuvat 19:00</p>', $html);
        self::assertStringContainsString('EN</span>&nbsp; Doors open 18:30<br>Doors close 19:00</p>', $html);
        self::assertStringContainsString('Osta lippu · Buy a ticket', $html);
        self::assertStringContainsString('▶<br>Kuuntele DJ:tä<br>Listen to the DJ', $html);

        // The plain text has no picture, so it keeps the date and the title.
        self::assertStringStartsWith(
            "27.7.2026 · 21:00\nRock & \"Roll\" <Night>\nOld Hall",
            explode("\n\n", NewsletterLayout::text([$card], [], null), 2)[1]
        );
    }

    public function testThePriceIsWrittenTheFinnishWay(): void
    {
        self::assertSame('22 €', NewsletterLayout::price('22.00 EUR'));
        self::assertSame('27,50 €', NewsletterLayout::price('27.50 EUR'));
        self::assertSame('5 €', NewsletterLayout::price('5 €'));
        self::assertSame('Free', NewsletterLayout::price('Free'));
        self::assertSame('', NewsletterLayout::price(''));
    }

    /**
     * The default preview time: 0 hours after the previous event ends. The
     * previous night ended at 02:00 today, so the plan is made and the
     * preview goes now, days before the send time, which is 3 days before
     * the event at the send time setting.
     */
    public function testThePreviewGoesAfterThePreviousEventEnds(): void
    {
        $this->options[Newsletter::DAYS_OPTION] = 3;
        $this->options[NewsletterSchedule::SEND_TIME_OPTION] = '18:30';
        $this->event(1, '2026-07-19', 'Last Night', ['_eventmesh_ends_at' => '2026-07-20T02:00:00']);
        $this->event(2, '2026-07-27', 'Next Night');
        $this->queueOrganizer();

        $this->newsletter()->run();

        $plan = $this->state()['plan'];
        self::assertSame('2026-07-24', $plan['send_on']);
        self::assertSame('2026-07-24 18:30:00', $plan['send_at']);
        self::assertSame('2026-07-20 02:00:00', $plan['preview_at']);
        self::assertTrue($plan['previewed']);
        self::assertSame([2], array_column($plan['events'], 'id'));
        self::assertArrayNotHasKey('starts_at', $plan['events'][0]);
        self::assertCount(1, $this->mails);
    }

    /** X hours after the previous event: nothing happens before that moment. */
    public function testThePreviewWaitsTheSetHours(): void
    {
        $this->options[Newsletter::DAYS_OPTION] = 3;
        $this->options[NewsletterSchedule::PREVIEW_HOURS_OPTION] = 11;
        $this->event(1, '2026-07-19', 'Last Night', ['_eventmesh_ends_at' => '2026-07-20T02:00:00']);
        $this->event(2, '2026-07-27', 'Next Night');

        $this->newsletter()->run();
        self::assertNull($this->state()['plan']);

        $this->options[NewsletterSchedule::PREVIEW_HOURS_OPTION] = 10;
        $this->queueOrganizer();
        $this->newsletter()->run();

        self::assertSame('2026-07-20 12:00:00', $this->state()['plan']['preview_at']);
        self::assertCount(1, $this->mails);
    }

    /**
     * A previous event with no end time: the preview falls back to N days
     * before the event at HH:MM (by default the day before the send day, at
     * the send time).
     */
    public function testWithoutAnEndTimeThePreviewFallsBackToDaysBefore(): void
    {
        $this->options[Newsletter::DAYS_OPTION] = 3;
        $this->event(1, '2026-07-19', 'Last Night');
        $this->event(2, '2026-07-27', 'Next Night');

        $this->newsletter()->run();
        self::assertNull($this->state()['plan'], 'The default is 4 days before: 2026-07-23 09:00.');

        $this->options[NewsletterSchedule::PREVIEW_DAYS_OPTION] = 8;
        $this->options[NewsletterSchedule::PREVIEW_TIME_OPTION] = '07:15';
        $this->queueOrganizer();
        $this->newsletter()->run();

        $plan = $this->state()['plan'];
        self::assertSame('2026-07-19 07:15:00', $plan['preview_at']);
        self::assertSame('2026-07-24 09:00:00', $plan['send_at']);
        self::assertCount(1, $this->mails);
    }

    /**
     * "N days before" chosen even with a previous event: its end is not
     * used. A plan made before its preview time keeps the preview back.
     */
    public function testDaysBeforeIgnoresThePreviousEvent(): void
    {
        $this->options[Newsletter::DAYS_OPTION] = 2;
        $this->options[NewsletterSchedule::PREVIEW_MODE_OPTION] = NewsletterSchedule::PREVIEW_BEFORE_EVENT;
        $this->options[NewsletterSchedule::PREVIEW_DAYS_OPTION] = 3;
        $this->options[NewsletterSchedule::PREVIEW_TIME_OPTION] = '20:00';
        $this->event(1, '2026-07-19', 'Last Night', ['_eventmesh_ends_at' => '2026-07-20T02:00:00']);
        $this->event(2, '2026-07-23', 'Next Night');

        $this->newsletter()->run();

        // The send time is 2026-07-21 09:00, so the plan is made a day before
        // it (now), and the preview waits for 20:00.
        $plan = $this->state()['plan'];
        self::assertSame('2026-07-20 20:00:00', $plan['preview_at']);
        self::assertFalse($plan['previewed']);
        self::assertSame([], $this->mails);
    }

    /** A preview time that is not before the send time: the preview goes with the plan. */
    public function testAPreviewNotBeforeTheSendTimeGoesWithThePlan(): void
    {
        $this->options[Newsletter::DAYS_OPTION] = 3;
        $this->options[NewsletterSchedule::PREVIEW_MODE_OPTION] = NewsletterSchedule::PREVIEW_BEFORE_EVENT;
        $this->options[NewsletterSchedule::PREVIEW_DAYS_OPTION] = 2;
        $this->event(2, '2026-07-24', 'Next Night');
        $this->queueOrganizer();

        $this->newsletter()->run();

        $plan = $this->state()['plan'];
        self::assertSame('2026-07-21 09:00:00', $plan['send_at']);
        self::assertSame('2026-07-22 09:00:00', $plan['preview_at']);
        self::assertTrue($plan['previewed']);
        self::assertCount(1, $this->mails);
    }

    /** A held-back preview goes at the first run at or after its time. */
    public function testAHeldPreviewGoesAtItsTime(): void
    {
        $this->event(1, '2026-07-27', 'Summer Social');
        $plan = [
            'send_at' => '2026-07-21 09:00:00',
            'preview_at' => '2026-07-20 13:00:00',
            'previewed' => false,
            'events' => [self::planEvent(1, '2026-07-27')],
        ];
        $this->withPlan($plan);

        $this->newsletter()->run();
        self::assertSame([], $this->mails);
        self::assertFalse($this->state()['plan']['previewed']);

        $this->withPlan(['preview_at' => '2026-07-20 12:00:00'] + $plan);
        $this->queueOrganizer();
        $this->newsletter()->run();

        self::assertCount(1, $this->mails);
        self::assertTrue($this->state()['plan']['previewed']);
        self::assertSame(Newsletter::PLAN_PLANNED, $this->state()['plan']['status']);
    }

    /** The sending starts at the first run at or after the send time, not the start of its day. */
    public function testTheSendingStartsAtTheSendTime(): void
    {
        $this->event(1, '2026-07-27', 'Summer Social');
        $plan = [
            'send_on' => '2026-07-20',
            'send_at' => '2026-07-20 12:30:00',
            'preview_at' => '2026-07-19 09:00:00',
            'previewed' => true,
            'events' => [self::planEvent(1, '2026-07-27')],
        ];
        $this->withPlan($plan);

        $this->newsletter()->run();
        self::assertSame(Newsletter::PLAN_PLANNED, $this->state()['plan']['status']);

        $this->withPlan(['send_at' => '2026-07-20 12:00:00'] + $plan);
        $this->newsletter()->run();
        self::assertSame(Newsletter::PLAN_SENDING, $this->state()['plan']['status']);
    }

    /** The send time came before the preview went: the preview first, the sending on the next run. */
    public function testAPreviewNotYetSentGoesBeforeTheSending(): void
    {
        $this->event(1, '2026-07-27', 'Summer Social');
        $this->withPlan([
            'send_on' => '2026-07-20',
            'send_at' => '2026-07-20 11:00:00',
            'preview_at' => '2026-07-20 10:00:00',
            'previewed' => false,
            'events' => [self::planEvent(1, '2026-07-27')],
        ]);
        $this->queueOrganizer();

        $this->newsletter()->run();
        self::assertCount(1, $this->mails);
        self::assertSame(Newsletter::PLAN_PLANNED, $this->state()['plan']['status']);

        $this->newsletter()->run();
        self::assertSame(Newsletter::PLAN_SENDING, $this->state()['plan']['status']);
    }

    /** A text change before the preview went sends no preview; the preview carries it later. */
    public function testATextChangeBeforeThePreviewSendsNothing(): void
    {
        $this->withPlan([
            'preview_at' => '2026-07-20 20:00:00',
            'previewed' => false,
            'events' => [self::planEvent(1, '2026-07-27')],
        ]);

        $this->newsletter()->onTextChanged();

        self::assertSame([], $this->mails);
    }

    public function testScheduleSettingsReadAndClamp(): void
    {
        self::assertSame(NewsletterSchedule::PREVIEW_AFTER_PREVIOUS, NewsletterSchedule::previewMode());
        self::assertSame(0, NewsletterSchedule::previewHours());
        self::assertSame(8, NewsletterSchedule::previewDays());
        self::assertSame('09:00', NewsletterSchedule::sendTime());
        self::assertSame('09:00', NewsletterSchedule::previewTime());

        $this->options['eventcrew_notify_hour'] = 7;
        self::assertSame('07:00', NewsletterSchedule::sendTime(), 'The default follows the send window.');

        self::assertSame('07:05', NewsletterSchedule::parseTime('7:05'));
        self::assertNull(NewsletterSchedule::parseTime('24:00'));
        self::assertNull(NewsletterSchedule::parseTime('9'));
        self::assertSame(168, NewsletterSchedule::clampPreviewHours(500));
        self::assertSame(1, NewsletterSchedule::clampPreviewDays(0));
        self::assertSame('2026-03-01 01:00:00', NewsletterSchedule::addHours('2026-02-28 23:00:00', 2));
    }
}

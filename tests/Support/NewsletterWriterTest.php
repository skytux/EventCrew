<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\Newsletter;
use EventCrew\Support\NewsletterLayout;
use EventCrew\Support\NewsletterText;
use EventCrew\Support\NewsletterWriter;
use EventCrew\Tests\TestCase;

/**
 * The texts the language model writes for the newsletter. The model server is
 * a stub of wp_remote_post that answers from $answers in order and records
 * each request; nothing leaves the test. Options live in an array, EventMesh
 * events are fakes, and wp_mail records each message. TestCase pins today to
 * 2026-07-20 12:00, inside the default send window.
 */
final class NewsletterWriterTest extends TestCase
{
    private const DESCRIPTION = 'A long night of social dancing in the old hall. Doors open early.';

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<int, array{post: object, meta: array<string, string>}> */
    private array $events = [];

    /** @var list<array{to: string, subject: string, body: string}> */
    private array $mails = [];

    /** @var list<array<string, mixed>> The decoded request bodies. */
    private array $requests = [];

    /** @var list<string|int> Model answers in order; an int is an HTTP error status. */
    private array $answers = [];

    /** @var array<int, mixed> What the DJ filter returns, per event id. */
    private array $djs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [
            Newsletter::ENABLED_OPTION => '1',
            NewsletterWriter::ENABLED_OPTION => '1',
            AssistantSettings::URL_OPTION => 'https://llm.example.test/v1',
            AssistantSettings::MODEL_OPTION => 'test-model',
        ];
        $this->events = [];
        $this->mails = [];
        $this->requests = [];
        $this->answers = [];
        $this->djs = [];

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
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('wp_mail')->alias(
            function (string $to, string $subject, string $body): bool {
                $this->mails[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

                return true;
            }
        );
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
        Filters\expectApplied(NewsletterWriter::DJ_FILTER)->zeroOrMoreTimes()->andReturnUsing(
            fn (mixed $value, int $postId): mixed => $this->djs[$postId] ?? []
        );
    }

    private function event(int $id, string $date, string $title, string $content = self::DESCRIPTION): void
    {
        $this->events[$id] = [
            'post' => (object) [
                'ID' => $id,
                'post_type' => 'eventmesh_event',
                'post_title' => $title,
                'post_status' => 'publish',
                'post_content' => $content,
            ],
            'meta' => ['_eventmesh_starts_at' => $date . 'T21:00:00'],
        ];
    }

    private function newsletter(): Newsletter
    {
        $logger = new Logger();
        $llm = new LlmClient($logger);

        return new Newsletter(
            new NewsletterSubscriberRepository(),
            new PersonRepository(),
            new NewsletterText($llm, $logger),
            new Mailer($logger, new EmailTemplate($logger)),
            $logger,
            new NewsletterWriter($llm, $logger)
        );
    }

    private function queueOrganizer(): void
    {
        $this->wpdb->nextResults[] = [
            ['id' => 9, 'email' => 'org@example.test', 'display_name' => 'Org', 'is_organizer' => 1],
        ];
    }

    /** @return array<string, mixed>|null */
    private function plan(): ?array
    {
        return $this->options[Newsletter::STATE_OPTION]['plan'] ?? null;
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
    private static function planEvent(int $id, string $date, string $title): array
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

    private static function answer(string $key, string $text): string
    {
        return (string) json_encode([$key => $text]);
    }

    /** @param array<string, mixed> $request */
    private static function system(array $request): string
    {
        return (string) $request['messages'][0]['content'];
    }

    /** @param array<string, mixed> $request */
    private static function user(array $request): string
    {
        return (string) $request['messages'][1]['content'];
    }

    /**
     * The plan for the preview asks for the event's text with every input -
     * the event, its DJs from EventMesh (only well-formed ones, each
     * description cut to 1200 characters), the site's words and the last four
     * sent texts - then its Finnish translation, then the opening with the
     * events and DJ names. The preview shows the written texts, not the
     * description.
     */
    public function testThePlanWritesTheTextsFromEveryInput(): void
    {
        $this->event(1, '2026-07-27', 'Summer Social');
        $this->djs[1] = [
            ['name' => 'DJ Aurora', 'description' => 'Warm, rolling grooves. ' . str_repeat('x', 2000)],
            ['name' => 42, 'description' => 'not a name'],
            'not an array',
            ['name' => 'DJ Kuu', 'description' => ['not', 'a', 'string']],
        ];
        $this->options[NewsletterWriter::WORDS_OPTION] = "Say \"dance floor\", never \"party\".";
        $this->options[NewsletterWriter::MEMORY_OPTION] = [
            ['opening' => 'Oldest opening.', 'events' => ['Oldest text.']],
            ['opening' => 'Second opening.', 'events' => ['Second text.']],
            ['opening' => 'Third opening.', 'events' => ['Third text.']],
            ['opening' => 'Fourth opening.', 'events' => ['Fourth text.']],
            ['opening' => 'Newest opening.', 'events' => ['Newest text.']],
        ];
        $this->answers = [
            self::answer('text', 'Come and dance to the warm grooves of DJ Aurora.'),
            self::answer('fi', 'Tule tanssimaan DJ Auroran lämpimiin grooveihin.'),
            self::answer('text', 'Summer is here, and so is the dance floor.'),
            self::answer('fi', 'Kesä on täällä, ja niin on tanssilattiakin.'),
        ];
        $this->queueOrganizer();

        $this->newsletter()->run();

        self::assertCount(4, $this->requests);

        [$event, $translation, $opening, $openingFi] = $this->requests;
        $system = self::system($event);
        self::assertStringContainsString('2 to 3 sentences in English', $system);
        self::assertStringContainsString('Do not use the word "vol' . 'unteer"', $system);
        self::assertStringContainsString('Do not invent times, prices, places, names', $system);
        self::assertStringContainsString('Say "dance floor", never "party".', $system);
        self::assertStringContainsString('Do not reuse their openings, phrases or sentence structure', $system);
        self::assertStringContainsString('Newest opening.', $system);
        self::assertStringContainsString('Second text.', $system);
        self::assertStringNotContainsString('Oldest', $system);
        self::assertStringContainsString('{"text": "the English text"}', $system);

        $user = self::user($event);
        self::assertStringContainsString('Event: Summer Social', $user);
        self::assertStringContainsString('Date: Monday 27 July 2026, 21:00', $user);
        self::assertStringContainsString(self::DESCRIPTION, $user);
        self::assertStringContainsString('- DJ Aurora: Warm, rolling grooves. x', $user);
        self::assertStringContainsString('- DJ Kuu', $user);
        self::assertStringNotContainsString('not a name', $user);
        self::assertStringNotContainsString(str_repeat('x', 1200 - 23 + 1), $user);
        self::assertStringContainsString(str_repeat('x', 1200 - 23), $user);

        self::assertStringContainsString('from English into Finnish', self::system($translation));
        self::assertStringContainsString('Say "dance floor"', self::system($translation));
        self::assertSame('Come and dance to the warm grooves of DJ Aurora.', self::user($translation));

        self::assertStringContainsString('opening text', self::system($opening));
        self::assertStringContainsString('Newest opening.', self::system($opening));
        self::assertStringContainsString(
            '- Monday 27 July 2026, 21:00: Summer Social. DJs: DJ Aurora, DJ Kuu',
            self::user($opening)
        );
        self::assertSame('Summer is here, and so is the dance floor.', self::user($openingFi));

        $plan = $this->plan();
        self::assertSame(
            [
                'fi' => 'Tule tanssimaan DJ Auroran lämpimiin grooveihin.',
                'en' => 'Come and dance to the warm grooves of DJ Aurora.',
            ],
            $plan['written']['events'][1]
        );

        self::assertCount(1, $this->mails);
        $body = $this->mails[0]['body'];
        self::assertStringContainsString('FI</span>&nbsp; Kesä on täällä, ja niin on tanssilattiakin.', $body);
        self::assertStringContainsString('EN</span>&nbsp; Summer is here, and so is the dance floor.', $body);
        self::assertStringContainsString('FI</span>&nbsp; Tule tanssimaan DJ Auroran lämpimiin grooveihin.', $body);
        self::assertStringContainsString('Come and dance to the warm grooves of DJ Aurora.', $body);
        self::assertStringNotContainsString('old hall', $body);
    }

    /**
     * EventMesh missing or older: the filter answers with its default, and the
     * request simply has no DJs.
     */
    public function testNoDjsLeavesThemOut(): void
    {
        $this->event(1, '2026-07-27', 'Summer Social');
        $this->options[NewsletterText::OPTION] = ['fi' => 'Moi', 'en' => 'Hi', 'rev' => 1];
        $this->answers = [self::answer('text', 'Come and dance.'), self::answer('fi', 'Tule tanssimaan.')];
        $this->queueOrganizer();

        $this->newsletter()->run();

        self::assertCount(2, $this->requests);
        self::assertStringNotContainsString('DJs:', self::user($this->requests[0]));
        self::assertStringNotContainsString('Texts written for earlier newsletters', self::system($this->requests[0]));
        self::assertStringNotContainsString("The site's words", self::system($this->requests[0]));
    }

    /**
     * When the organizers wrote a text, the model writes no opening, and the
     * email opens with the organizers' text.
     */
    public function testTheOpeningIsWrittenOnlyWhenTheOrganizersWroteNone(): void
    {
        $this->event(1, '2026-07-27', 'Summer Social');
        $this->options[NewsletterText::OPTION] = ['fi' => 'Moi kaikki', 'en' => 'Hi all', 'rev' => 1];
        $this->answers = [self::answer('text', 'Come and dance.'), self::answer('fi', 'Tule tanssimaan.')];
        $this->queueOrganizer();

        $this->newsletter()->run();

        self::assertCount(2, $this->requests);
        self::assertStringNotContainsString('opening text', self::system($this->requests[0]));
        self::assertNull($this->plan()['written']['opening']);
        self::assertStringContainsString('EN</span>&nbsp; Hi all', $this->mails[0]['body']);
    }

    /**
     * The organizers removed their text after the preview: the next run writes
     * the opening, and only the opening, and sends the new preview.
     */
    public function testARemovedOrganizersTextGetsAnOpeningOnTheNextRun(): void
    {
        $this->event(1, '2026-07-27', 'Summer Social');
        $this->withPlan([
            'events' => [self::planEvent(1, '2026-07-27', 'Summer Social')],
            'written' => ['events' => [1 => ['fi' => 'Tule.', 'en' => 'Come.']], 'opening' => null],
        ]);
        $this->answers = [self::answer('text', 'Welcome, dancers.'), self::answer('fi', 'Tervetuloa, tanssijat.')];
        $this->queueOrganizer();

        $this->newsletter()->run();

        self::assertCount(2, $this->requests);
        self::assertStringContainsString('opening text', self::system($this->requests[0]));
        self::assertSame(
            ['fi' => 'Tervetuloa, tanssijat.', 'en' => 'Welcome, dancers.'],
            $this->plan()['written']['opening']
        );
        self::assertCount(1, $this->mails);
        self::assertStringContainsString('Welcome, dancers.', $this->mails[0]['body']);
    }

    /**
     * Every way a text can fail leaves the card with its full description,
     * and the failed text is not asked for again.
     *
     * @return array<string, array{0: list<string|int>}>
     */
    public static function failures(): array
    {
        return [
            'server error' => [[500]],
            'not JSON' => [['Sure! Come and dance.']],
            'wrong key' => [['{"message": "Come and dance."}']],
            'empty text' => [['{"text": "  "}']],
            'too long' => [[(string) json_encode(['text' => str_repeat('Dance. ', 100)])]],
            'a link' => [['{"text": "Tickets at https://shop.test now."}']],
            'translation fails' => [['{"text": "Come and dance."}', 'Tule tanssimaan.']],
        ];
    }

    /**
     * @dataProvider failures
     * @param list<string|int> $answers
     */
    public function testEveryFailureFallsBackToTheDescription(array $answers): void
    {
        $this->event(1, '2026-07-27', 'Summer Social');
        $this->options[NewsletterText::OPTION] = ['fi' => 'Moi', 'en' => 'Hi', 'rev' => 1];
        $this->answers = $answers;
        $this->queueOrganizer();

        $this->newsletter()->run();

        self::assertSame([], $this->plan()['written']['events'][1]);
        self::assertStringContainsString(self::DESCRIPTION, $this->mails[0]['body']);
        self::assertStringNotContainsString('Come and dance', $this->mails[0]['body']);

        // The next run asks for nothing more and sends no new preview.
        $asked = count($this->requests);
        $this->newsletter()->run();
        self::assertCount($asked, $this->requests);
        self::assertCount(1, $this->mails);
    }

    /**
     * No model set up, or a run on a visitor's request (the cron fallback):
     * nothing is asked, nothing is stored, and the card shows the description.
     */
    public function testNoModelOrNoBackgroundRunWritesNothing(): void
    {
        foreach (['no model', 'not cron'] as $case) {
            $this->setUp();
            $this->event(1, '2026-07-27', 'Summer Social');

            if ('no model' === $case) {
                unset($this->options[AssistantSettings::URL_OPTION]);
            } else {
                Functions\when('wp_doing_cron')->justReturn(false);
            }

            $this->queueOrganizer();
            $this->newsletter()->run();

            self::assertSame([], $this->requests, $case);
            self::assertArrayNotHasKey('written', $this->plan(), $case);
            self::assertStringContainsString(self::DESCRIPTION, $this->mails[0]['body'], $case);
        }
    }

    /** An exception inside the model call is a failure like any other. */
    public function testAThrowableIsAFailure(): void
    {
        Functions\when('wp_remote_post')->alias(static function (): never {
            throw new \RuntimeException('boom');
        });
        $this->event(1, '2026-07-27', 'Summer Social');
        $this->queueOrganizer();

        $this->newsletter()->run();

        self::assertSame([], $this->plan()['written']['events'][1]);
        self::assertSame([], $this->plan()['written']['opening']);
        self::assertStringContainsString(self::DESCRIPTION, $this->mails[0]['body']);
    }

    /**
     * On the send day the plan's texts are kept and the model is not asked
     * again; an event added to the plan since gets its own text, and only it.
     */
    public function testTheSendDayAsksOnlyForWhatIsMissing(): void
    {
        $this->event(1, '2026-07-27', 'Summer Social');
        $this->event(2, '2026-07-28', 'Late Night');
        $written = ['events' => [1 => ['fi' => 'Tule.', 'en' => 'Come.']], 'opening' => []];
        $this->withPlan([
            'send_on' => '2026-07-20',
            'events' => [self::planEvent(1, '2026-07-27', 'Summer Social')],
            'written' => $written,
        ]);

        $this->newsletter()->run();

        self::assertSame([], $this->requests);
        self::assertSame(Newsletter::PLAN_SENDING, $this->plan()['status']);
        self::assertSame($written, $this->plan()['written']);

        $this->withPlan([
            'send_on' => '2026-07-20',
            'events' => [
                self::planEvent(1, '2026-07-27', 'Summer Social'),
                self::planEvent(2, '2026-07-28', 'Late Night'),
            ],
            'written' => $written,
        ]);
        $this->answers = [self::answer('text', 'Stay late and dance.'), self::answer('fi', 'Jää tanssimaan.')];

        $this->newsletter()->run();

        self::assertCount(2, $this->requests);
        self::assertStringContainsString('Event: Late Night', self::user($this->requests[0]));
        self::assertSame(['fi' => 'Tule.', 'en' => 'Come.'], $this->plan()['written']['events'][1]);
        self::assertSame(
            ['fi' => 'Jää tanssimaan.', 'en' => 'Stay late and dance.'],
            $this->plan()['written']['events'][2]
        );
    }

    /**
     * The batches use the plan's texts and never ask the model. After the
     * last one the English texts join the memory, which keeps the last four.
     */
    public function testTheSentTextsAreRememberedKeepingTheLastFour(): void
    {
        $this->options[NewsletterWriter::MEMORY_OPTION] = [
            ['opening' => 'One.', 'events' => ['1']],
            ['opening' => 'Two.', 'events' => ['2']],
            ['opening' => 'Three.', 'events' => ['3']],
            ['opening' => 'Four.', 'events' => ['4']],
        ];
        $this->withPlan([
            'status' => Newsletter::PLAN_SENDING,
            'events' => [self::planEvent(1, '2026-07-27', 'Summer Social')],
            'written' => [
                'events' => [1 => ['fi' => 'Tule.', 'en' => 'Come.']],
                'opening' => ['fi' => 'Tervetuloa.', 'en' => 'Welcome.'],
            ],
        ]);
        $this->wpdb->nextResults[] = [['id' => 4, 'email' => 'a@example.test']];

        $this->newsletter()->sendBatch();

        self::assertSame([], $this->requests);
        self::assertStringContainsString('EN</span>&nbsp; Welcome.', $this->mails[0]['body']);
        self::assertStringContainsString('EN</span>&nbsp; Come.', $this->mails[0]['body']);
        self::assertSame(
            [
                ['opening' => 'Two.', 'events' => ['2']],
                ['opening' => 'Three.', 'events' => ['3']],
                ['opening' => 'Four.', 'events' => ['4']],
                ['opening' => 'Welcome.', 'events' => ['Come.']],
            ],
            $this->options[NewsletterWriter::MEMORY_OPTION]
        );
    }

    /** An opening that was not shown (the organizers wrote a text) is not remembered. */
    public function testAnUnshownOpeningIsNotRemembered(): void
    {
        $this->withPlan([
            'status' => Newsletter::PLAN_SENDING,
            'events' => [self::planEvent(1, '2026-07-27', 'Summer Social')],
            'text' => ['fi' => 'Moi', 'en' => 'Hi', 'rev' => 3],
            'written' => [
                'events' => [1 => ['fi' => 'Tule.', 'en' => 'Come.']],
                'opening' => ['fi' => 'Tervetuloa.', 'en' => 'Welcome.'],
            ],
        ]);
        $this->wpdb->nextResults[] = [['id' => 4, 'email' => 'a@example.test']];

        $this->newsletter()->sendBatch();

        self::assertStringNotContainsString('Welcome.', $this->mails[0]['body']);
        self::assertSame(
            [['opening' => '', 'events' => ['Come.']]],
            $this->options[NewsletterWriter::MEMORY_OPTION]
        );
    }

    /**
     * Switched off: no request, no stored texts, and the email is exactly
     * what it was without this feature, even for a plan that has texts.
     */
    public function testSwitchedOffNothingChanges(): void
    {
        $this->options[NewsletterWriter::ENABLED_OPTION] = '0';
        $this->event(1, '2026-07-27', 'Summer Social');
        $this->queueOrganizer();

        $this->newsletter()->run();

        self::assertSame([], $this->requests);
        self::assertArrayNotHasKey('written', $this->plan());

        $events = [self::planEvent(1, '2026-07-27', 'Summer Social')];
        $written = [
            'events' => [1 => ['fi' => 'Tule.', 'en' => 'Come.']],
            'opening' => ['fi' => 'Tervetuloa.', 'en' => 'Welcome.'],
        ];
        $newsletter = $this->newsletter();

        self::assertSame($newsletter->compose($events, null), $newsletter->compose($events, null, [], $written));
        self::assertStringContainsString(self::DESCRIPTION, $newsletter->compose($events, null, [], $written)['html']);

        $this->wpdb->nextResults[] = [['id' => 4, 'email' => 'a@example.test']];
        $this->withPlan(['status' => Newsletter::PLAN_SENDING, 'events' => $events, 'written' => $written]);
        $this->newsletter()->sendBatch();

        self::assertArrayNotHasKey(NewsletterWriter::MEMORY_OPTION, $this->options);
    }

    /** The switch is on when the option was never saved. */
    public function testTheSwitchIsOnByDefault(): void
    {
        unset($this->options[NewsletterWriter::ENABLED_OPTION]);

        self::assertTrue(NewsletterWriter::enabled());

        $this->options[NewsletterWriter::ENABLED_OPTION] = '0';

        self::assertFalse(NewsletterWriter::enabled());
    }

    /**
     * A plan whose preview time has not come: the texts are written on this
     * run, but the preview waits for its time. When its time comes, the
     * preview goes once and carries the texts.
     */
    public function testThePreviewWaitsForItsTimeWithTheTexts(): void
    {
        $this->event(1, '2026-07-27', 'Summer Social');
        $this->options[NewsletterText::OPTION] = ['fi' => 'Moi', 'en' => 'Hi', 'rev' => 1];
        $plan = [
            'send_at' => '2026-07-21 12:00:00',
            'preview_at' => '2026-07-20 18:00:00',
            'previewed' => false,
            'events' => [self::planEvent(1, '2026-07-27', 'Summer Social')],
        ];
        $this->withPlan($plan);
        $this->answers = [self::answer('text', 'Come and dance.'), self::answer('fi', 'Tule tanssimaan.')];
        $this->queueOrganizer();

        $this->newsletter()->run();

        self::assertCount(2, $this->requests);
        self::assertSame([], $this->mails);
        self::assertFalse($this->plan()['previewed']);
        self::assertSame(
            ['fi' => 'Tule tanssimaan.', 'en' => 'Come and dance.'],
            $this->plan()['written']['events'][1]
        );

        $this->withPlan(['preview_at' => '2026-07-20 11:00:00'] + $plan);
        $this->answers = [self::answer('text', 'Come and dance.'), self::answer('fi', 'Tule tanssimaan.')];

        $this->newsletter()->run();

        self::assertCount(1, $this->mails);
        self::assertTrue($this->plan()['previewed']);
        self::assertStringContainsString('EN</span>&nbsp; Come and dance.', $this->mails[0]['body']);
        self::assertStringNotContainsString('old hall', $this->mails[0]['body']);
    }

    /**
     * After the last batch, each event's shown text stays in the ledger, and
     * writtenTexts() gives it to the post-event email together with the texts
     * of the current plan. A text that failed is not kept.
     */
    public function testTheSentTextsStayForThePostEventEmail(): void
    {
        $this->withPlan([
            'status' => Newsletter::PLAN_SENDING,
            'events' => [
                self::planEvent(1, '2026-07-27', 'Summer Social'),
                self::planEvent(2, '2026-07-28', 'Late Night'),
            ],
            'written' => ['events' => [1 => ['fi' => 'Tule.', 'en' => 'Come.'], 2 => []], 'opening' => []],
        ]);
        $this->wpdb->nextResults[] = [['id' => 4, 'email' => 'a@example.test']];

        $this->newsletter()->sendBatch();

        $ledger = $this->options[Newsletter::STATE_OPTION]['ledger'];
        self::assertSame(
            ['s' => Newsletter::LEDGER_SENT, 'd' => '2026-07-27', 'w' => ['fi' => 'Tule.', 'en' => 'Come.']],
            $ledger[1]
        );
        self::assertSame(['s' => Newsletter::LEDGER_SENT, 'd' => '2026-07-28'], $ledger[2]);
        self::assertSame(['events' => [1 => ['fi' => 'Tule.', 'en' => 'Come.']]], Newsletter::writtenTexts());

        $this->options[Newsletter::STATE_OPTION]['plan'] = [
            'events' => [self::planEvent(3, '2026-08-03', 'Next Week')],
            'written' => ['events' => [3 => ['fi' => 'Tanssi.', 'en' => 'Dance.']], 'opening' => null],
        ];

        self::assertSame(
            ['events' => [3 => ['fi' => 'Tanssi.', 'en' => 'Dance.'], 1 => ['fi' => 'Tule.', 'en' => 'Come.']]],
            Newsletter::writtenTexts()
        );
    }

    /** The layout puts a written text in place of the description, in the HTML and the plain text. */
    public function testTheLayoutShowsTheWrittenText(): void
    {
        $card = [
            'excerpt_fi' => 'Pitkä kuvaus.',
            'excerpt_en' => 'A long description.',
            'written_fi' => 'Tule tanssimaan.',
            'written_en' => 'Come and dance.',
        ] + self::planEvent(1, '2026-07-27', 'Summer Social');

        $html = NewsletterLayout::html([$card], [], null, '#7a3cff');
        $text = NewsletterLayout::text([$card], [], null);

        self::assertStringContainsString('FI</span>&nbsp; Tule tanssimaan.', $html);
        self::assertStringContainsString('EN</span>&nbsp; Come and dance.', $html);
        self::assertStringContainsString("FI: Tule tanssimaan.\nEN: Come and dance.", $text);

        foreach ([$html, $text] as $out) {
            self::assertStringNotContainsString('description', $out);
            self::assertStringNotContainsString('kuvaus', $out);
        }
    }

    public function testParseReadsTheAnswerRobustly(): void
    {
        self::assertSame('Come.', NewsletterWriter::parse('{"text": "Come."}', 'text'));
        self::assertSame('Come.', NewsletterWriter::parse("```json\n{\"text\": \"Come.\"}\n```", 'text'));
        self::assertSame('Tule.', NewsletterWriter::parse('Here it is: {"fi": "Tule."} Enjoy!', 'fi'));
        self::assertNull(NewsletterWriter::parse('{"text": "Come."}', 'fi'));
        self::assertNull(NewsletterWriter::parse('{"text": 5}', 'text'));
        self::assertNull(NewsletterWriter::parse('{"text": ""}', 'text'));
        self::assertNull(NewsletterWriter::parse('Come and dance.', 'text'));
        self::assertNull(NewsletterWriter::parse('{"text": "See www.shop.test"}', 'text'));
        $tooLong = (string) json_encode(['text' => str_repeat('a', NewsletterWriter::MAX_TEXT + 1)]);
        $longest = (string) json_encode(['text' => str_repeat('ä', NewsletterWriter::MAX_TEXT)]);
        self::assertNull(NewsletterWriter::parse($tooLong, 'text'));
        self::assertSame(str_repeat('ä', NewsletterWriter::MAX_TEXT), NewsletterWriter::parse($longest, 'text'));
    }
}

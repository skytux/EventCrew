<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssistantKnowledge;
use EventCrew\Tests\TestCase;

final class AssistantKnowledgeTest extends TestCase
{
    /** @var array<string, string> */
    private array $meta = [];

    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->meta = ['_eventmesh_starts_at' => '2026-08-01T21:00:00'];
        $post = (object) [
            'ID' => 12,
            'post_type' => 'eventmesh_event',
            'post_status' => 'publish',
            'post_title' => 'Summer Dance',
            'post_content' => '',
            'post_excerpt' => '',
        ];

        Functions\when('post_type_exists')->justReturn(true);
        Functions\when('get_posts')->justReturn([$post]);
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_post_meta')->alias(fn (int $id, string $key): string => $this->meta[$key] ?? '');
        Functions\when('get_permalink')->justReturn('https://example.test/event/summer-dance/');
        Functions\when('get_post_field')->justReturn('');
        Functions\when('wp_strip_all_tags')->alias(static fn (string $text): string => strip_tags($text));
        Functions\when('strip_shortcodes')->returnArg();
        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
    }

    private function eventsText(): string
    {
        $method = new \ReflectionMethod(AssistantKnowledge::class, 'eventsText');

        return (string) $method->invoke(new AssistantKnowledge(new TaskRepository()));
    }

    /** The coming event's line gives the ticket price the event card shows. */
    public function testAComingEventGivesItsTicketPrice(): void
    {
        $this->meta['_eventmesh_price'] = '22.00 EUR';

        self::assertSame(
            '- 2026-08-01: Summer Dance, starts 21:00, ticket 22 €, https://example.test/event/summer-dance/',
            $this->eventsText()
        );
    }

    /**
     * A sold-out event says so in place of the price, whether an organizer
     * marked it sold out or the shop sync did.
     */
    public function testASoldOutEventSaysSoldOutInPlaceOfThePrice(): void
    {
        $this->meta['_eventmesh_price'] = '22.00 EUR';

        foreach (['_eventmesh_manual_sold_out', '_eventmesh_sold_out'] as $key) {
            $this->meta[$key] = '1';

            self::assertSame(
                '- 2026-08-01: Summer Dance, starts 21:00, tickets sold out, https://example.test/event/summer-dance/',
                $this->eventsText()
            );

            unset($this->meta[$key]);
        }
    }

    /** An event without a price says nothing about one. */
    public function testAnEventWithoutAPriceHasNoTicketWords(): void
    {
        $text = $this->eventsText();

        self::assertSame('- 2026-08-01: Summer Dance, starts 21:00, https://example.test/event/summer-dance/', $text);
        self::assertStringNotContainsString('ticket', $text);
    }

    /** @param array<int, string> $roleSlugs */
    private function facts(array $roleSlugs, bool $organizer = false): string
    {
        Functions\when('get_transient')->justReturn('');

        return (new AssistantKnowledge(new TaskRepository()))->build($roleSlugs, $organizer)['facts'];
    }

    /** @return array<string, mixed> */
    private function taskRow(int $id, string $role): array
    {
        return [
            'id' => $id,
            'task_date' => '2026-08-01',
            'role_slug' => $role,
            'capacity' => 2,
            'event_post_id' => null,
            'event_label' => 'Party',
            'starts_at' => null,
            'ends_at' => null,
            'notes' => '',
            'created_at' => '2026-07-01 00:00:00',
        ];
    }

    /**
     * Owner 2026-11-07: the helper told a VJ there were no VJ tasks. A person
     * with a restricted role gets that role's coming tasks and description,
     * signed up for through /board; the board list still leaves them out.
     */
    public function testAPersonWithARestrictedRoleGetsItsTasks(): void
    {
        $this->options['eventcrew_roles'] = [
            ['slug' => 'decorate', 'label' => 'Decorate'],
            ['slug' => 'vj', 'label' => 'VJ', 'restricted' => true, 'description' => 'Visuals on the screen.'],
        ];
        Functions\when('current_time')->justReturn('2026-07-10');
        $rows = [$this->taskRow(1, 'decorate'), $this->taskRow(2, 'vj')];
        $this->wpdb->nextResults[] = $rows; // board tasks
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = $rows; // role tasks
        $this->wpdb->nextResults[] = [['task_id' => 2, 'taken' => 1]];

        $facts = $this->facts(['vj']);

        self::assertStringContainsString('- VJ: Visuals on the screen.', $facts);
        self::assertStringContainsString("## This person's role tasks", $facts);
        self::assertStringContainsString('2026-08-01 (Saturday), Party: VJ, 1 signed up', $facts);
        self::assertStringContainsString('give the action /board, never /join', $facts);
        self::assertStringNotContainsString('task 2:', $facts);
    }

    /** Someone without the role hears nothing of it. */
    public function testSomeoneWithoutTheRoleGetsNoRoleTasks(): void
    {
        $this->options['eventcrew_roles'] = [
            ['slug' => 'vj', 'label' => 'VJ', 'restricted' => true, 'description' => 'Visuals on the screen.'],
        ];
        Functions\when('current_time')->justReturn('2026-07-10');
        $this->wpdb->nextResults[] = [$this->taskRow(2, 'vj')];

        $facts = $this->facts([]);

        self::assertStringNotContainsString('VJ', $facts);
        self::assertStringNotContainsString('role tasks', $facts);
    }

    /**
     * An organizer holds no VJ role but can put anyone on a VJ task, so they
     * see every role and every role task, and are pointed to /assign.
     */
    public function testAnOrganizerSeesEveryRoleAndEveryRoleTask(): void
    {
        $this->options['eventcrew_roles'] = [
            ['slug' => 'decorate', 'label' => 'Decorate'],
            ['slug' => 'vj', 'label' => 'VJ', 'restricted' => true, 'description' => 'Visuals on the screen.'],
        ];
        Functions\when('current_time')->justReturn('2026-07-10');
        $rows = [$this->taskRow(1, 'decorate'), $this->taskRow(2, 'vj')];
        $this->wpdb->nextResults[] = $rows; // board tasks
        $this->wpdb->nextResults[] = [];
        $this->wpdb->nextResults[] = $rows; // role tasks
        $this->wpdb->nextResults[] = [['task_id' => 2, 'taken' => 0]];

        $facts = $this->facts([], true);

        self::assertStringContainsString('- VJ: Visuals on the screen.', $facts);
        self::assertStringContainsString('## Role tasks', $facts);
        self::assertStringContainsString('2026-08-01 (Saturday), Party: VJ, 0 signed up', $facts);
        self::assertStringContainsString('/assign <task> ; <person>', $facts);
    }

    public function testOnlyAnOrganizerHearsOfTheOrganizerChatCommands(): void
    {
        $member = AssistantKnowledge::chatCommandsText(false);
        $organizer = AssistantKnowledge::chatCommandsText(true);

        self::assertStringContainsString('/crew', $member);
        self::assertStringNotContainsString('/tell', $member);
        self::assertStringContainsString('/tell task ; message', $organizer);
        self::assertStringContainsString('/crew', $organizer);
    }
}

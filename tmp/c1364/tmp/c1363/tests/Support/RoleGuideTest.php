<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\LeaderGate;
use EventCrew\Support\RoleGuide;
use EventCrew\Telegram\BoardRenderer;
use EventCrew\Tests\Telegram\TelegramTestCase;

/**
 * The "What each task involves" list and the links to it: the web board's
 * section anchor, and the button on the Telegram board.
 */
final class RoleGuideTest extends TelegramTestCase
{
    public function testTheDescriptionsLinkPointsAtTheSectionOnTheSignupPage(): void
    {
        $this->options['eventcrew_signup_page_id'] = 7;
        Functions\when('get_permalink')->justReturn('https://site.test/crew/');

        self::assertSame('https://site.test/crew/#eventcrew-tasks-info', RoleGuide::descriptionsUrl());
    }

    public function testNoSignupPageMeansNoLinkAndNoButton(): void
    {
        self::assertSame('', RoleGuide::descriptionsUrl());
        self::assertNull(RoleGuide::descriptionsButton());
    }

    public function testTheLeaderIsListedOnlyWhenEventsCarryOne(): void
    {
        $without = RoleGuide::descriptions();
        $this->options[LeaderGate::ENABLED_OPTION] = '1';
        $with = RoleGuide::descriptions();

        self::assertNotSame([], $without);
        self::assertCount(count($without) + 1, $with);
        self::assertStringContainsString('Runs the crew for the night', end($with)['description']);
    }

    public function testTheTelegramBoardLinksToTheDescriptions(): void
    {
        $this->options['eventcrew_signup_page_id'] = 7;
        Functions\when('get_permalink')->justReturn('https://site.test/crew/');
        $this->wpdb->nextResults[] = [$this->taskRow()];
        $this->wpdb->nextResults[] = [];

        $keyboard = (new BoardRenderer(new TaskRepository()))->render()['keyboard'];
        $urls = array_column(array_merge(...$keyboard), 'url');

        self::assertContains('https://site.test/crew/#eventcrew-tasks-info', $urls);
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(): array
    {
        return [
            'id' => 5,
            'task_date' => '2026-07-21',
            'role_slug' => 'decorate',
            'capacity' => 3,
            'starts_at' => '2026-07-21 13:00:00',
            'ends_at' => '2026-07-21 14:00:00',
        ];
    }
}

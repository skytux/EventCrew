<?php

declare(strict_types=1);

namespace EventCrew\Tests\Admin;

use EventCrew\Admin\HistoryListTable;
use EventCrew\Repositories\HistoryRepository;
use EventCrew\Support\History;
use EventCrew\Tests\TestCase;

/**
 * The History table on a phone: the action is the primary column (the one a
 * narrow list table keeps), carries time · done by · task · night under it,
 * and has no per-row "Show more details" button.
 */
final class HistoryListTableTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        require_once dirname(__DIR__) . '/stubs/wp-list-table.php';
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function row(array $fields = []): array
    {
        return $fields + [
            'id' => 1,
            'created_at' => '2026-11-14 21:40:00',
            'kind' => History::REPLACED,
            'person_id' => 7,
            'person_name' => 'Sam',
            'other_person_id' => 8,
            'other_name' => 'Kim',
            'actor_kind' => History::ACTOR_PERSON,
            'actor_person_id' => 9,
            'actor_name' => 'Lou',
            'actor_wp_user_id' => null,
            'channel' => History::CHANNEL_BOT,
            'task_id' => null,
            'event_date' => '2026-11-14',
            'details' => '',
            'source' => '',
        ];
    }

    private function table(): HistoryListTable
    {
        return new HistoryListTable(new HistoryRepository(), []);
    }

    private function call(HistoryListTable $table, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($table, $method))->invoke($table, ...$args);
    }

    public function testTheActionIsThePrimaryColumnAndTimeStaysFirst(): void
    {
        $table = $this->table();

        self::assertSame('action', $this->call($table, 'get_primary_column_name'));
        self::assertSame('when', array_key_first($table->get_columns()));
    }

    public function testTheActionCellCarriesTheMetaLine(): void
    {
        self::assertSame(
            'Kim took over from Sam<span class="eventcrew-history-meta">14.11. 21:40 · Lou, bot · 14.11.</span>',
            $this->table()->column_action(self::row())
        );
    }

    public function testEmptyPartsAreLeftOutOfTheMetaLine(): void
    {
        $html = $this->table()->column_action(self::row([
            'actor_kind' => '',
            'actor_person_id' => null,
            'event_date' => null,
        ]));

        self::assertStringEndsWith('<span class="eventcrew-history-meta">14.11. 21:40</span>', $html);
    }

    public function testNoRowToggleAndTheTableIsMarked(): void
    {
        $table = $this->table();

        self::assertSame('', $this->call($table, 'handle_row_actions', self::row(), 'action', 'action'));
        self::assertContains('eventcrew-history', $this->call($table, 'get_table_classes'));
    }
}

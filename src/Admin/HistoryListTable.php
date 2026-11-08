<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Repositories\HistoryRepository;
use EventCrew\Support\HistoryText;
use WP_List_Table;

/**
 * The table on the History page: one row per action, newest first.
 *
 * Extends a core class that only exists inside wp-admin, so the file that
 * instantiates this must require class-wp-list-table.php first - see
 * HistoryPage::render().
 */
final class HistoryListTable extends WP_List_Table
{
    public const PER_PAGE = 50;

    /**
     * @param array{person?: int, date?: string, kinds?: list<string>} $filter
     */
    public function __construct(
        private readonly HistoryRepository $repository,
        private readonly array $filter
    ) {
        parent::__construct([
            'singular' => 'action',
            'plural' => 'actions',
            'ajax' => false,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function get_columns(): array
    {
        return [
            'when' => __('Time', 'eventcrew'),
            'action' => __('What happened', 'eventcrew'),
            'actor' => __('Done by', 'eventcrew'),
            'task' => __('Task', 'eventcrew'),
            'night' => __('Night', 'eventcrew'),
        ];
    }

    public function prepare_items(): void
    {
        $total = $this->repository->count($this->filter);
        $page = $this->get_pagenum();

        $this->_column_headers = [$this->get_columns(), [], []];
        $this->items = $this->repository->page($this->filter, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page' => self::PER_PAGE,
            'total_pages' => (int) ceil($total / self::PER_PAGE),
        ]);
    }

    /**
     * @param array<string, mixed> $item
     * @param string $column_name
     */
    protected function column_default($item, $column_name): string
    {
        $text = match ($column_name) {
            'when' => HistoryText::when($item),
            'action' => HistoryText::action($item),
            'actor' => HistoryText::actor($item),
            'task' => HistoryText::task($item),
            'night' => HistoryText::night($item),
            default => '',
        };

        return esc_html($text);
    }

    public function no_items(): void
    {
        esc_html_e('Nothing recorded for this choice.', 'eventcrew');
    }
}

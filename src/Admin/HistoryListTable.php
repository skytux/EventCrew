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

        $this->_column_headers = [$this->get_columns(), [], [], $this->get_primary_column_name()];
        $this->items = $this->repository->page($this->filter, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page' => self::PER_PAGE,
            'total_pages' => (int) ceil($total / self::PER_PAGE),
        ]);
    }

    /**
     * The action is the primary column, so on a phone (where a list table
     * shows only its primary column) each row reads as what happened, with
     * the time, who did it, the task and the night on a small line under it.
     * Time stays the first column on wider screens.
     */
    protected function get_primary_column_name(): string
    {
        return 'action';
    }

    /**
     * @return list<string>
     */
    protected function get_table_classes(): array
    {
        return [...parent::get_table_classes(), 'eventcrew-history'];
    }

    /**
     * No "Show more details" button: on a phone the line under the action
     * already says everything the other columns would.
     *
     * @param array<string, mixed> $item
     * @param string $column_name
     * @param string $primary
     */
    protected function handle_row_actions($item, $column_name, $primary): string
    {
        return '';
    }

    /**
     * What happened, plus the line shown under it on narrow screens only
     * (eventcrew-admin.css): time · done by · task · night, the empty ones
     * left out.
     *
     * @param array<string, mixed> $item
     */
    public function column_action($item): string
    {
        $meta = array_diff(
            [
                HistoryText::when($item),
                HistoryText::actor($item),
                HistoryText::task($item),
                HistoryText::night($item),
            ],
            ['']
        );

        $html = esc_html(HistoryText::action($item));

        if ([] !== $meta) {
            $html .= '<span class="eventcrew-history-meta">' . esc_html(implode(' · ', $meta)) . '</span>';
        }

        return $html;
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

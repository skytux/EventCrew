<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Models\Shift;
use EventCrew\Repositories\ShiftRepository;
use WP_List_Table;

/**
 * The shifts table on the Shifts screen.
 *
 * Extends a core class that only exists inside wp-admin, so the file that
 * instantiates this must require class-wp-list-table.php first - see
 * ShiftsPage::render(). Autoloading this class outside admin would fatal on
 * the missing parent.
 */
final class ShiftsListTable extends WP_List_Table
{
    private const PER_PAGE = 20;

    /**
     * Slots taken per shift id, fetched once for the whole page rather than
     * per row.
     *
     * @var array<int, int>
     */
    private array $occupancy = [];

    public function __construct(
        private readonly ShiftRepository $shifts
    ) {
        parent::__construct([
            'singular' => 'shift',
            'plural' => 'shifts',
            'ajax' => false,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function get_columns(): array
    {
        return [
            'shift_date' => __('Date', 'eventcrew'),
            'event' => __('Event', 'eventcrew'),
            'task_slug' => __('Task', 'eventcrew'),
            'time' => __('Time', 'eventcrew'),
            'filled' => __('Filled', 'eventcrew'),
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function get_sortable_columns(): array
    {
        return [
            'shift_date' => ['shift_date', true],
            'task_slug' => ['task_slug', false],
        ];
    }

    public function prepare_items(): void
    {
        $page = $this->get_pagenum();

        // Sort parameters come from the table's own header links, not from a
        // form, so there is no nonce to check; both are whitelisted against
        // known columns inside the repository before reaching SQL.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $orderBy = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'shift_date';
        $order = isset($_GET['order']) ? sanitize_key(wp_unslash($_GET['order'])) : 'desc';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $this->items = $this->shifts->all([
            'orderby' => $orderBy,
            'order' => $order,
            'per_page' => self::PER_PAGE,
            'page' => $page,
        ]);

        $this->occupancy = $this->shifts->occupancyFor(
            array_map(static fn (Shift $shift): int => $shift->id, $this->items)
        );

        $total = $this->shifts->count();

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page' => self::PER_PAGE,
            'total_pages' => (int) ceil($total / self::PER_PAGE),
        ]);

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
    }

    public function no_items(): void
    {
        esc_html_e('No shifts yet. Add one to get started.', 'eventcrew');
    }

    /**
     * @param Shift $item
     */
    public function column_shift_date($item): string
    {
        $editUrl = add_query_arg(
            ['page' => ShiftsPage::PAGE_SLUG, 'shift' => $item->id],
            admin_url('admin.php')
        );

        $deleteUrl = wp_nonce_url(
            add_query_arg(
                ['action' => 'eventcrew_delete_shift', 'shift' => $item->id],
                admin_url('admin-post.php')
            ),
            'eventcrew_delete_shift_' . $item->id
        );

        $actions = [
            'edit' => sprintf(
                '<a href="%s">%s</a>',
                esc_url($editUrl),
                esc_html__('Edit', 'eventcrew')
            ),
            'delete' => sprintf(
                '<a href="%s" onclick="return confirm(%s)" class="submitdelete">%s</a>',
                esc_url($deleteUrl),
                esc_attr(wp_json_encode(
                    __('Delete this shift and everyone signed up for it?', 'eventcrew')
                )),
                esc_html__('Delete', 'eventcrew')
            ),
        ];

        return sprintf(
            '<strong><a href="%s">%s</a></strong>%s',
            esc_url($editUrl),
            esc_html($item->shiftDate),
            $this->row_actions($actions)
        );
    }

    /**
     * @param Shift $item
     */
    public function column_event($item): string
    {
        return esc_html($item->eventName());
    }

    /**
     * @param Shift $item
     */
    public function column_task_slug($item): string
    {
        return esc_html($item->taskDisplay());
    }

    /**
     * @param Shift $item
     */
    public function column_time($item): string
    {
        $range = $item->timeRange();

        return '' === $range ? '&mdash;' : esc_html($range);
    }

    /**
     * @param Shift $item
     */
    public function column_filled($item): string
    {
        $taken = $this->occupancy[$item->id] ?? 0;

        $text = sprintf('%d / %d', $taken, $item->capacity);

        if ($taken >= $item->capacity) {
            return '<span style="color:#1a7f37;font-weight:600">' . esc_html($text) . '</span>';
        }

        return esc_html($text);
    }

    /**
     * @param Shift $item
     * @param string $column_name
     */
    public function column_default($item, $column_name): string
    {
        return '';
    }
}

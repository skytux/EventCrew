<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Models\Task;
use EventCrew\Repositories\TaskRepository;
use WP_List_Table;

/**
 * The tasks table on the Tasks screen.
 *
 * Extends a core class that only exists inside wp-admin, so the file that
 * instantiates this must require class-wp-list-table.php first - see
 * TasksPage::render(). Autoloading this class outside admin would fatal on
 * the missing parent.
 */
final class TasksListTable extends WP_List_Table
{
    private const PER_PAGE = 20;

    /**
     * Slots taken per task id, fetched once for the whole page rather than
     * per row.
     *
     * @var array<int, int>
     */
    private array $occupancy = [];

    public function __construct(
        private readonly TaskRepository $tasks
    ) {
        parent::__construct([
            'singular' => 'task',
            'plural' => 'tasks',
            'ajax' => false,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function get_columns(): array
    {
        return [
            'task_date' => __('Date', 'eventcrew'),
            'event' => __('Event', 'eventcrew'),
            'role_slug' => __('Role', 'eventcrew'),
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
            'task_date' => ['task_date', true],
            'role_slug' => ['role_slug', false],
        ];
    }

    public function prepare_items(): void
    {
        $page = $this->get_pagenum();

        // Sort parameters come from the table's own header links, not from a
        // form, so there is no nonce to check; both are whitelisted against
        // known columns inside the repository before reaching SQL.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $orderBy = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'task_date';
        $order = isset($_GET['order']) ? sanitize_key(wp_unslash($_GET['order'])) : 'desc';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $this->items = $this->tasks->all([
            'orderby' => $orderBy,
            'order' => $order,
            'per_page' => self::PER_PAGE,
            'page' => $page,
        ]);

        $this->occupancy = $this->tasks->occupancyFor(
            array_map(static fn (Task $task): int => $task->id, $this->items)
        );

        $total = $this->tasks->count();

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page' => self::PER_PAGE,
            'total_pages' => (int) ceil($total / self::PER_PAGE),
        ]);

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
    }

    public function no_items(): void
    {
        esc_html_e('No tasks yet. Add one to get started.', 'eventcrew');
    }

    /**
     * @param Task $item
     */
    public function column_task_date($item): string
    {
        $editUrl = add_query_arg(
            ['page' => TasksPage::PAGE_SLUG, 'task' => $item->id],
            admin_url('admin.php')
        );

        $deleteUrl = wp_nonce_url(
            add_query_arg(
                ['action' => 'eventcrew_delete_task', 'task' => $item->id],
                admin_url('admin-post.php')
            ),
            'eventcrew_delete_task_' . $item->id
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
                    __('Delete this task and everyone signed up for it?', 'eventcrew')
                )),
                esc_html__('Delete', 'eventcrew')
            ),
        ];

        return sprintf(
            '<strong><a href="%s">%s</a></strong>%s',
            esc_url($editUrl),
            esc_html($item->taskDate),
            $this->row_actions($actions)
        );
    }

    /**
     * @param Task $item
     */
    public function column_event($item): string
    {
        return esc_html($item->eventName());
    }

    /**
     * @param Task $item
     */
    public function column_role_slug($item): string
    {
        return esc_html($item->roleDisplay());
    }

    /**
     * @param Task $item
     */
    public function column_time($item): string
    {
        $range = $item->timeRange();

        return '' === $range ? '&mdash;' : esc_html($range);
    }

    /**
     * @param Task $item
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
     * @param Task $item
     * @param string $column_name
     */
    public function column_default($item, $column_name): string
    {
        return '';
    }
}

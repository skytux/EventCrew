<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Models\Person;
use EventCrew\Repositories\AssignmentRepository;
use EventCrew\Repositories\PersonRepository;
use WP_List_Table;

/**
 * The people table on the People screen.
 *
 * As with TasksListTable, the core parent only exists inside wp-admin and
 * must be required before this class is touched.
 */
final class PeopleListTable extends WP_List_Table
{
    private const PER_PAGE = 20;

    private string $search = '';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly AssignmentRepository $assignments
    ) {
        parent::__construct([
            'singular' => 'person',
            'plural' => 'people',
            'ajax' => false,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function get_columns(): array
    {
        return [
            'display_name' => __('Name', 'eventcrew'),
            'email' => __('Email', 'eventcrew'),
            'telegram' => __('Telegram', 'eventcrew'),
            'opt_in' => __('Open-task email', 'eventcrew'),
            'completed' => __('Completed tasks', 'eventcrew'),
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function get_sortable_columns(): array
    {
        return [
            'display_name' => ['display_name', true],
            'email' => ['email', false],
        ];
    }

    public function prepare_items(): void
    {
        $page = $this->get_pagenum();

        // Search and sort come from the table's own controls rather than a
        // state-changing form, so no nonce applies; the sort column is
        // whitelisted in the repository and the search term is parameterised.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $this->search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $orderBy = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'display_name';
        $order = isset($_GET['order']) ? sanitize_key(wp_unslash($_GET['order'])) : 'asc';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $this->items = $this->people->all([
            'search' => $this->search,
            'orderby' => $orderBy,
            'order' => $order,
            'per_page' => self::PER_PAGE,
            'page' => $page,
        ]);

        $total = $this->people->count($this->search);

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page' => self::PER_PAGE,
            'total_pages' => (int) ceil($total / self::PER_PAGE),
        ]);

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
    }

    public function no_items(): void
    {
        esc_html_e('No people yet.', 'eventcrew');
    }

    /**
     * @param Person $item
     */
    public function column_display_name($item): string
    {
        $editUrl = add_query_arg(
            ['page' => PeoplePage::PAGE_SLUG, 'person' => $item->id],
            admin_url('admin.php')
        );

        $deleteUrl = wp_nonce_url(
            add_query_arg(
                ['action' => 'eventcrew_delete_person', 'person' => $item->id],
                admin_url('admin-post.php')
            ),
            'eventcrew_delete_person_' . $item->id
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
                    __('Delete this person and their whole task history?', 'eventcrew')
                )),
                esc_html__('Delete', 'eventcrew')
            ),
        ];

        $badge = $item->isOrganizer
            ? ' <span class="dashicons dashicons-star-filled" style="color:#dba617" title="' .
                esc_attr__('Organizer', 'eventcrew') . '"></span>'
            : '';

        return sprintf(
            '<strong><a href="%s">%s</a></strong>%s%s',
            esc_url($editUrl),
            esc_html($item->name()),
            $badge,
            $this->row_actions($actions)
        );
    }

    /**
     * @param Person $item
     */
    public function column_email($item): string
    {
        if ($item->isEmailVerified()) {
            return esc_html($item->email);
        }

        return sprintf(
            '%s <em style="color:#996800">(%s)</em>',
            esc_html($item->email),
            esc_html__('unverified', 'eventcrew')
        );
    }

    /**
     * @param Person $item
     */
    public function column_telegram($item): string
    {
        return $item->hasTelegram()
            ? '<span class="dashicons dashicons-yes" style="color:#1a7f37"></span>'
            : '&mdash;';
    }

    /**
     * @param Person $item
     */
    public function column_opt_in($item): string
    {
        if (! $item->acceptsOpenTaskEmail()) {
            return '&mdash;';
        }

        return sprintf(
            '<span class="dashicons dashicons-yes" style="color:#1a7f37"></span> <span title="%s">%s</span>',
            esc_attr($item->emailOptInAt ?? ''),
            esc_html($item->emailOptInSource)
        );
    }

    /**
     * @param Person $item
     */
    public function column_completed($item): string
    {
        return esc_html((string) $this->assignments->countCompletedFor($item->id));
    }

    /**
     * @param Person $item
     * @param string $column_name
     */
    public function column_default($item, $column_name): string
    {
        return '';
    }
}

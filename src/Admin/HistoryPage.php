<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Repositories\HistoryRepository;
use EventCrew\Support\History;

/**
 * The History page: who did what, and when - sign-ups, cancellations, the
 * door, credits, people and settings - newest first, filtered by person,
 * night and kind. Read-only. Rows are kept History::KEEP_DAYS days.
 */
final class HistoryPage
{
    public const PAGE_SLUG = 'eventcrew-history';

    public function __construct(
        private readonly View $view,
        private readonly HistoryRepository $repository = new HistoryRepository()
    ) {
    }

    public function render(): void
    {
        if (! class_exists('WP_List_Table')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
        $filter = self::filter($_GET);
        $table = new HistoryListTable($this->repository, $filter['query']);
        $table->prepare_items();

        $this->view->render('history', [
            'table' => $table,
            'people' => $this->repository->names(),
            'person' => $filter['person'],
            'date' => $filter['date'],
            'group' => $filter['group'],
            'groups' => array_keys(History::groups()),
            'keep_days' => History::KEEP_DAYS,
        ]);
    }

    /**
     * The filter the request asks for: a person id, a night (Y-m-d) and a
     * group of kinds, each dropped when not valid; and the repository filter
     * they make.
     *
     * @param array<string, mixed> $request
     * @return array{person: int, date: string, group: string,
     *     query: array{person?: int, date?: string, kinds?: list<string>}}
     */
    public static function filter(array $request): array
    {
        $person = max(0, (int) ($request['person'] ?? 0));
        $date = sanitize_text_field(wp_unslash((string) ($request['night'] ?? '')));
        $group = sanitize_key(wp_unslash((string) ($request['group'] ?? '')));
        $groups = History::groups();

        if (
            1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            $date = '';
        }

        if (! isset($groups[$group])) {
            $group = '';
        }

        $query = [];

        if ($person > 0) {
            $query['person'] = $person;
        }

        if ('' !== $date) {
            $query['date'] = $date;
        }

        if ('' !== $group) {
            $query['kinds'] = $groups[$group];
        }

        return ['person' => $person, 'date' => $date, 'group' => $group, 'query' => $query];
    }
}

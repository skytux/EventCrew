<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Repositories\BotLogRepository;
use EventCrew\Telegram\BotLog;

/**
 * The Bot log page: which commands and buttons the Telegram bot handled on
 * one day (the site's time zone), newest first, and how many each person used.
 * Read-only. A Telegram user who is not linked to a person is shown as
 * "unlinked user #n", numbered in the order they first appear that day; their
 * Telegram number is never shown.
 */
final class BotLogPage
{
    public const PAGE_SLUG = 'eventcrew-bot-log';

    public const PER_PAGE = 50;

    public function __construct(
        private readonly View $view,
        private readonly BotLogRepository $repository = new BotLogRepository()
    ) {
    }

    public function render(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter, changes nothing.
        $day = isset($_GET['day']) ? sanitize_text_field(wp_unslash((string) $_GET['day'])) : '';
        $page = isset($_GET['paged']) ? (int) $_GET['paged'] : 1;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $this->view->render('bot-log', $this->data($day, $page));
    }

    /**
     * Everything the page shows for one day and page. An empty or invalid day
     * means today.
     *
     * @return array{day: string, page: int, pages: int, total: int, rows: list<array<string, string>>,
     *     people: list<array{name: string, commands: int}>, keep_days: int}
     */
    public function data(string $day, int $page): array
    {
        if (
            1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m)
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            $day = (string) current_time('Y-m-d');
        }

        [$from, $to] = self::utcRange($day);

        $total = $this->repository->countBetween($from, $to);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);

        $counts = $this->repository->countsPerPerson($from, $to);
        $numbers = self::unlinkedNumbers($counts);

        $people = [];

        foreach ($counts as $count) {
            $people[] = [
                'name' => self::name(
                    $count['person_id'] ?? null,
                    $count['display_name'] ?? null,
                    $count['unlinked_id'] ?? null,
                    $numbers
                ),
                'commands' => (int) ($count['commands'] ?? 0),
            ];
        }

        $rows = [];

        foreach ($this->repository->between($from, $to, self::PER_PAGE, ($page - 1) * self::PER_PAGE) as $row) {
            $rows[] = [
                'time' => self::localTime((string) ($row['created_at'] ?? '')),
                'person' => self::name(
                    $row['person_id'] ?? null,
                    $row['display_name'] ?? null,
                    $row['telegram_user_id'] ?? null,
                    $numbers
                ),
                'chat' => 'private' === ($row['chat_kind'] ?? '')
                    ? __('private', 'eventcrew')
                    : __('group', 'eventcrew'),
                'command' => (string) ($row['command'] ?? ''),
                'source' => self::sourceLabel((string) ($row['source'] ?? '')),
                'button' => (string) ($row['button_kind'] ?? ''),
                'date' => (string) ($row['date_arg'] ?? ''),
                'outcome' => self::outcomeLabel((string) ($row['outcome'] ?? '')),
            ];
        }

        return [
            'day' => $day,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'rows' => $rows,
            'people' => $people,
            'keep_days' => BotLog::KEEP_DAYS,
        ];
    }

    /**
     * The start and end of a day in the site's time zone, as UTC datetimes.
     *
     * @return array{0: string, 1: string}
     */
    public static function utcRange(string $day): array
    {
        $utc = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable($day . ' 00:00:00', wp_timezone());

        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $start->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Unlinked Telegram users numbered 1, 2, ... in the order they first
     * appear in the day.
     *
     * @param list<array<string, mixed>> $counts
     * @return array<int, int> Telegram user id => number
     */
    private static function unlinkedNumbers(array $counts): array
    {
        $unlinked = array_filter($counts, static fn (array $c): bool => null === ($c['person_id'] ?? null));
        usort($unlinked, static fn (array $a, array $b): int => (int) $a['first_id'] <=> (int) $b['first_id']);

        $numbers = [];

        foreach ($unlinked as $entry) {
            $numbers[(int) ($entry['unlinked_id'] ?? 0)] ??= count($numbers) + 1;
        }

        return $numbers;
    }

    /**
     * @param array<int, int> $numbers
     */
    private static function name(mixed $personId, mixed $displayName, mixed $telegramId, array $numbers): string
    {
        if (null !== $personId) {
            $name = trim((string) $displayName);

            /* translators: %d: person id */
            return '' !== $name ? $name : sprintf(__('person #%d', 'eventcrew'), (int) $personId);
        }

        /* translators: %d: a number given to each Telegram user not linked to a person, 1 for the first that day */
        return sprintf(__('unlinked user #%d', 'eventcrew'), $numbers[(int) $telegramId] ?? 0);
    }

    private static function localTime(string $utc): string
    {
        $at = strtotime($utc . ' UTC');

        return false === $at
            ? ''
            : (new \DateTimeImmutable('@' . $at))->setTimezone(wp_timezone())->format('H:i');
    }

    private static function sourceLabel(string $source): string
    {
        return match ($source) {
            BotLog::SOURCE_TYPED => __('typed', 'eventcrew'),
            BotLog::SOURCE_BUTTON => __('button', 'eventcrew'),
            BotLog::SOURCE_HELPER => __('helper', 'eventcrew'),
            default => $source,
        };
    }

    private static function outcomeLabel(string $outcome): string
    {
        return match ($outcome) {
            BotLog::OUTCOME_OK => __('ok', 'eventcrew'),
            BotLog::OUTCOME_REFUSED => __('refused', 'eventcrew'),
            BotLog::OUTCOME_ERROR => __('error', 'eventcrew'),
            default => $outcome,
        };
    }
}

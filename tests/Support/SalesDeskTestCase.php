<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Tests\Telegram\TelegramTestCase;

/**
 * Shared rows and stubs for the sales desk (door page) tests. The clock the
 * suite stubs reads 2026-07-20 12:00:00, so "tonight" here is 2026-07-20.
 */
abstract class SalesDeskTestCase extends TelegramTestCase
{
    protected const NIGHT = '2026-07-20';

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('admin_url')->alias(static fn (string $path = ''): string => 'https://site.test/wp-admin/' . $path);
        // Both of WordPress's forms: (array $args, $url) and ($key, $value, $url).
        Functions\when('add_query_arg')->alias(
            static fn (array|string $args, string $url, ?string $third = null): string => is_array($args)
                ? $url . '?' . http_build_query($args)
                : (string) $third . '?' . http_build_query([$args => $url])
        );
        Functions\when('get_the_title')->justReturn('');
        Functions\when('wp_mail')->justReturn(true);
    }

    /**
     * A person row. Telegram user and chat id 555 unless the id says otherwise.
     *
     * @return array<string, mixed>
     */
    protected function personRow(int $id, bool $organizer = false): array
    {
        return [
            'id' => $id,
            'email' => 'p' . $id . '@example.com',
            'display_name' => 'Person ' . $id,
            'is_organizer' => $organizer ? 1 : 0,
            'telegram_user_id' => 500 + $id,
            'telegram_chat_id' => 500 + $id,
        ];
    }

    /**
     * A task row on tonight's date.
     *
     * @return array<string, mixed>
     */
    protected function taskRow(int $id, string $role, ?string $start = null, ?string $end = null): array
    {
        return [
            'id' => $id,
            'task_date' => self::NIGHT,
            'role_slug' => $role,
            'capacity' => 3,
            'starts_at' => null === $start ? null : self::NIGHT . ' ' . $start . ':00',
            'ends_at' => null === $end ? null : self::NIGHT . ' ' . $end . ':00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function assignmentRow(int $id, int $taskId, int $personId, string $status): array
    {
        return ['id' => $id, 'task_id' => $taskId, 'person_id' => $personId, 'status' => $status];
    }
}

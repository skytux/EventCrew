<?php

declare(strict_types=1);

namespace EventCrew\Repositories;

use EventCrew\Database\Schema;

/**
 * The newsletter's history, kept for the Newsletter page in wp-admin (schema
 * 30): when addresses joined and left (the changes table, counts only, with no
 * address, name or subscriber id in it), and each sent newsletter with a copy
 * of the email (the sends table, one row per language version, with no
 * personal data in the copy). Times are site time.
 */
final class NewsletterHistoryRepository
{
    public const SUBSCRIBED = 'subscribed';

    public const UNSUBSCRIBED = 'unsubscribed';

    private function changes(): string
    {
        return Schema::table(Schema::NEWSLETTER_CHANGES);
    }

    private function sends(): string
    {
        return Schema::table(Schema::NEWSLETTER_SENDS);
    }

    /** Records that an address joined (SUBSCRIBED) or left (UNSUBSCRIBED), now. */
    public function recordChange(string $kind): void
    {
        global $wpdb;

        if (self::SUBSCRIBED !== $kind && self::UNSUBSCRIBED !== $kind) {
            return;
        }

        $wpdb->insert(
            $this->changes(),
            ['created_at' => (string) current_time('mysql'), 'kind' => $kind],
            ['%s', '%s']
        );
    }

    /**
     * How many joined and left on each day from $fromDay to $toDay (Y-m-d,
     * both included). Days with neither are left out.
     *
     * @return array<string, array{subscribed: int, unsubscribed: int}> Y-m-d => counts, oldest first
     */
    public function changesPerDay(string $fromDay, string $toDay): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(created_at) AS day, kind, COUNT(*) AS n FROM {$this->changes()}"
                    . ' WHERE created_at >= %s AND created_at <= %s GROUP BY day, kind ORDER BY day ASC',
                $fromDay . ' 00:00:00',
                $toDay . ' 23:59:59'
            ),
            ARRAY_A
        );
        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $day = (string) ($row['day'] ?? '');
            $kind = (string) ($row['kind'] ?? '');

            if ('' === $day || (self::SUBSCRIBED !== $kind && self::UNSUBSCRIBED !== $kind)) {
                continue;
            }

            $out[$day] ??= [self::SUBSCRIBED => 0, self::UNSUBSCRIBED => 0];
            $out[$day][$kind] += (int) ($row['n'] ?? 0);
        }

        return $out;
    }

    /**
     * The day (Y-m-d) of the oldest recorded join, or null when there is
     * none. Older than the history's start day when schema 31 added the
     * joins from before it.
     */
    public function firstJoinDay(): ?string
    {
        global $wpdb;

        $first = $wpdb->get_var(
            $wpdb->prepare("SELECT MIN(created_at) FROM {$this->changes()} WHERE kind = %s", self::SUBSCRIBED)
        );

        return is_string($first) && '' !== $first ? substr($first, 0, 10) : null;
    }

    /**
     * Stores one version of a newsletter as it starts to go out and returns
     * the row's id, 0 when nothing was written. $html is the email as the
     * subscribers get it, without anything of one subscriber in it.
     */
    public function addSend(int $planId, string $lang, string $subject, string $html): int
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            $this->sends(),
            [
                'plan_id' => $planId,
                'lang' => $lang,
                'sent_at' => (string) current_time('mysql'),
                'subject' => mb_substr($subject, 0, 255),
                'sent' => 0,
                'failed' => 0,
                'html' => $html,
            ],
            ['%d', '%s', '%s', '%s', '%d', '%d', '%s']
        );

        return false !== $inserted && (int) $inserted > 0 ? (int) $wpdb->insert_id : 0;
    }

    /** Sets how many emails of one stored version went and failed. */
    public function setCounts(int $id, int $sent, int $failed): void
    {
        global $wpdb;

        if ($id <= 0) {
            return;
        }

        $wpdb->update(
            $this->sends(),
            ['sent' => max(0, $sent), 'failed' => max(0, $failed)],
            ['id' => $id],
            ['%d', '%d'],
            ['%d']
        );
    }

    /**
     * Every stored version, oldest first, without the copy itself.
     *
     * @return list<array{id: int, plan_id: int, lang: string, sent_at: string, subject: string,
     *     sent: int, failed: int}>
     */
    public function sentList(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT id, plan_id, lang, sent_at, subject, sent, failed FROM {$this->sends()}"
                . ' ORDER BY sent_at ASC, id ASC',
            ARRAY_A
        );
        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'plan_id' => (int) ($row['plan_id'] ?? 0),
                'lang' => (string) ($row['lang'] ?? ''),
                'sent_at' => (string) ($row['sent_at'] ?? ''),
                'subject' => (string) ($row['subject'] ?? ''),
                'sent' => (int) ($row['sent'] ?? 0),
                'failed' => (int) ($row['failed'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * The stored copy of one version, or null when there is no such row.
     *
     * @return array{subject: string, html: string}|null
     */
    public function copy(int $id): ?array
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT subject, html FROM {$this->sends()} WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row)
            ? ['subject' => (string) ($row['subject'] ?? ''), 'html' => (string) ($row['html'] ?? '')]
            : null;
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Support\BackgroundJobs;
use EventCrew\Support\TranslatedText;
use EventCrew\Support\TranslationRegistry;

/**
 * The status of the background jobs on the EventCrew admin pages: the bar
 * at the top while jobs run, the admin-ajax poll that runs one job at a time
 * and answers the new badges, and "Try again" for a failed job.
 */
final class JobsStatus
{
    /** The admin-ajax action of the poll, and the nonce action of the poll and "Try again". */
    public const ACTION = 'eventcrew_jobs';

    /** The admin-post action of "Try again" without JavaScript. */
    public const RETRY_ACTION = 'eventcrew_job_retry';

    /**
     * @param TranslationRegistry|null $registry The rows of the texts, for
     *        the new text of a finished translation (the page puts it in its
     *        field without a reload). Null: no texts are answered.
     */
    public function __construct(
        private readonly BackgroundJobs $jobs,
        private readonly ?TranslationRegistry $registry = null
    ) {
    }

    /**
     * admin-ajax: runs one queued job (when there is one) and answers the
     * badges of the jobs the page asked about, how many jobs are still
     * active and the line for the top bar. A posted retry id is queued
     * again first.
     */
    public function poll(): never
    {
        if (! current_user_can(Admin::CAPABILITY)) {
            wp_send_json_error(null, 403);
        }

        check_ajax_referer(self::ACTION);

        wp_send_json_success($this->answer(wp_unslash($_POST)));
    }

    /**
     * What poll() answers for the posted $request: ids (the jobs on the
     * page) and retry (a failed job to queue again).
     *
     * A finished translation (TranslatedText::JOB) also answers its row id,
     * the row's text as now stored (value: TranslatedText::fillValue()) and
     * done, the badge without the reload link, so the page shows the new
     * text in place.
     *
     * The job runs on even when the page is left halfway (the organizer
     * presses the next button): a run cut off stayed "running" and was run
     * once more minutes later, translating that text a second time.
     *
     * @param array<string, mixed> $request
     * @return array{jobs: array<string, array<string, mixed>>, active: int, bar: string}
     */
    public function answer(array $request): array
    {
        $retry = sanitize_key((string) ($request['retry'] ?? ''));

        if ('' !== $retry) {
            BackgroundJobs::retry($retry);
        }

        if (BackgroundJobs::activeCount() > 0) {
            ignore_user_abort(true);
            $this->jobs->runOne();
        }

        $ids = $request['ids'] ?? [];
        $ids = is_array($ids) ? $ids : explode(',', (string) $ids);
        $out = [];

        foreach ($ids as $id) {
            $id = sanitize_key((string) $id);
            $job = '' === $id ? null : BackgroundJobs::find($id);

            if (null !== $job) {
                $out[$id] = ['status' => (string) $job['status'], 'html' => BackgroundJobs::badge($job, true)]
                    + $this->newText($job);
            }
        }

        $active = BackgroundJobs::activeCount();

        return ['jobs' => $out, 'active' => $active, 'bar' => self::barText($active)];
    }

    /**
     * For a finished translation of a row: the row id, its stored text and
     * the "Done" badge without the reload link. Empty for any other job.
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function newText(array $job): array
    {
        if (
            null === $this->registry
            || TranslatedText::JOB !== $job['kind']
            || BackgroundJobs::DONE !== $job['status']
        ) {
            return [];
        }

        $id = (string) ($job['args']['row'] ?? '');
        $row = '' === $id ? null : $this->registry->find($id);

        if (null === $row) {
            return [];
        }

        return [
            'row' => $id,
            'value' => TranslatedText::fillValue($row->value),
            'done' => sprintf(
                '<span class="ec-job ec-job-done" role="status">%s</span>',
                esc_html__('Done ✓', 'eventcrew')
            ),
        ];
    }

    /** admin-post "Try again" (a link, for a page without JavaScript). */
    public function retry(): never
    {
        if (! current_user_can(Admin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'eventcrew'));
        }

        check_admin_referer(self::ACTION);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified just above.
        $id = isset($_GET['job']) ? sanitize_key(wp_unslash((string) $_GET['job'])) : '';
        $found = BackgroundJobs::retry($id);

        Admin::notice(
            $found ? 'success' : 'error',
            $found ? __('The job is queued again.', 'eventcrew') : __('That job was not found.', 'eventcrew')
        );

        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=' . Admin::MENU_SLUG));

        exit;
    }

    /** admin_notices: the bar at the top of the EventCrew pages while jobs run. */
    public function topBar(): void
    {
        if (! self::onOurPage() || ! current_user_can(Admin::CAPABILITY)) {
            return;
        }

        $active = BackgroundJobs::activeCount();

        if (0 === $active) {
            return;
        }

        printf(
            '<div class="notice notice-info ec-jobs-bar" data-ec-jobs-bar data-active="%d"><p role="status">%s</p></div>',
            (int) $active,
            esc_html(self::barText($active))
        );
    }

    /**
     * The top bar's line for $active jobs. When none is left, it says so
     * (the page JS adds the reload link).
     */
    public static function barText(int $active): string
    {
        if ($active > 0) {
            return sprintf(
                /* translators: %d: how many background jobs are queued or running */
                _n('%d background job is running.', '%d background jobs are running.', $active, 'eventcrew'),
                $active
            );
        }

        return __('All background jobs are done. Reload the page to see the new texts.', 'eventcrew');
    }

    /** Whether this request draws an EventCrew admin page. */
    public static function onOurPage(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which page is drawn.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';

        return str_starts_with($page, Admin::MENU_SLUG);
    }
}

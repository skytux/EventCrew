<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Admin\Admin;
use EventCrew\Admin\HistoryPage;
use EventCrew\Admin\SettingsPage;
use EventCrew\Models\Person;
use EventCrew\Repositories\CheckinRepository;
use EventCrew\Repositories\MonthlyReportRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TaskRepository;

/**
 * The organizers' monthly report (Owner, 2026-11-08): on the 1st of each
 * month every organizer gets the previous calendar month's numbers by email,
 * with the month before in brackets. A table of counts, then "Done for
 * others": what organizers and wp-admin users did for other people that
 * month (adminActions()). That list has names but never an address.
 *
 * Sent from the hourly heartbeat (Scheduler), like the newsletter and
 * post-event email previews: at any hour, or inside the send window when
 * SendWindow::organizersAnyHour() is off. The heartbeat's own WP-Cron event
 * is what is registered, self-healed on boot and cleared on deactivation.
 * SENT_OPTION holds the last month reported, so it goes once per month;
 * a site whose cron did not run on the 1st still sends it in the first
 * CATCH_UP_DAYS days, and a site that switches it on later in the month waits
 * for the next 1st.
 *
 * Where each number comes from (all for the month, site time):
 * - new people: people rows created (an erased person's row is gone);
 * - used the bot: linked people with a row in the bot's command log, or an
 *   action in the history with the bot as its channel;
 * - active on the web page: people with an action of their own in the
 *   history done on the crew page. A sign-in alone is recorded nowhere, so
 *   someone who only looked is not counted;
 * - sign-ups and cancellations: the history (signed_up + assigned, and
 *   cancelled + late_cancel), counted when they happened. The web page and
 *   bot rows under them come from the history's channel; the total also
 *   holds what organizers did in wp-admin and what the system did;
 * - no-shows and completed tasks: sign-ups on tasks dated in the month,
 *   by their status now;
 * - free entries used: door check-ins of a free-entry ticket (credit,
 *   credit_x, and a ticket given to someone else) on nights in the month;
 * - ticket buyers: ticket buyer rows per night in the month;
 * - the funnel: active (used the bot, active on the web page, or had a task
 *   in the month) -> had a task dated in the month -> completed one;
 * - email button clicks and visits from campaign links: the month's counts
 *   in SiteStats (EmailClicks, CampaignVisits; Owner, Q385 + Q386), the
 *   total with the top TOP_CLICKS email · button pairs and TOP_CAMPAIGNS
 *   campaigns under it;
 * - page views and visitors (Burst): read from the Burst Statistics plugin's
 *   table when it is there; the rows are left out when it is not;
 * - Crew app (Owner 2026-11-13): tried to install, installed and the
 *   success rate (installed / tried) of the month, people once each
 *   (AppStats::month()); and, as of the report's sending, active installs
 *   (people who opened the app from the home screen in the last 30 days)
 *   and devices with notifications (reached in the last 30 days / all).
 *   Its own block after the funnel (appBlock()), since a rate and a pair
 *   are not plain counts; the 30-day numbers have no month before;
 * - done for others (Owner, 2026-11-11): the history rows of the month where
 *   an organizer acted for someone else (the bot's Act as included) or a
 *   WordPress user acted in wp-admin, settings and the task list included;
 *   not anyone's own actions, the system's, or rows made from older data.
 *   Grouped by who acted, most actions first, each line in time order with
 *   the History page's words (HistoryText), at most ACTIONS_PER_ACTOR lines
 *   each, and a link to the History page for the rest.
 *
 * To add a number: give stats() a key and add a row for it in countRows(),
 * or hook ROWS_FILTER from elsewhere. A row's key is read from both months'
 * stats, 0 when missing.
 */
final class MonthlyReport
{
    /** The setting: '1' or '0', on when unset. */
    public const OPTION = 'eventcrew_monthly_report';

    /** The last month reported, as Y-m. */
    public const SENT_OPTION = 'eventcrew_monthly_report_sent';

    /** The admin-post action and nonce of the Settings "Send a test report to me" link. */
    public const TEST_ACTION = 'eventcrew_send_monthly_report_test';

    /**
     * Filters the count rows of the email: list<array{label: string, key:
     * string, sub: bool}>, with the two months' stats as further arguments.
     * A sub row is shown indented under the row above it.
     */
    public const ROWS_FILTER = 'eventcrew_monthly_report_rows';

    /** A report not sent on the 1st still goes until this day of the month. */
    public const CATCH_UP_DAYS = 7;

    /** The free-entry check-in kinds counted as free entries used. */
    private const FREE_ENTRY_KINDS = [
        CheckinRepository::KIND_CREDIT,
        CheckinRepository::KIND_CREDIT_EXTRA,
        CheckinRepository::KIND_TRANSFER,
    ];

    /** The most email · button rows under the clicks. */
    public const TOP_CLICKS = 8;

    /** The most campaign rows under the campaign visits. */
    public const TOP_CAMPAIGNS = 8;

    /** The stats keys of one email · button pair start with this. */
    private const CLICK = 'click:';

    /** The stats keys of one campaign start with this. */
    private const CAMPAIGN = 'campaign:';

    /** The most "Done for others" lines under one actor; the rest are counted. */
    public const ACTIONS_PER_ACTOR = 100;

    /** Before a sub row's label: two no-break spaces and a dot. */
    private const SUB = "\u{00A0}\u{00A0}· ";

    private const ROW = 'padding:6px 0;border-bottom:1px solid #e3e3e8;font-family:%s;font-size:15px;color:%s';

    /** @var \Closure(string): string */
    private readonly \Closure $nightName;

    /** The Crew app install numbers. */
    private readonly AppStats $app;

    /**
     * $nightName gives a night's event name in the current language; by
     * default the first named task of that night (as the post-event email).
     *
     * @param (\Closure(string): string)|null $nightName
     */
    public function __construct(
        private readonly MonthlyReportRepository $data,
        private readonly PersonRepository $people,
        private readonly Mailer $mailer,
        ?TaskRepository $tasks = null,
        ?\Closure $nightName = null,
        ?AppStats $app = null
    ) {
        $this->app = $app ?? new AppStats($this->data);
        $tasks ??= new TaskRepository();
        $this->nightName = $nightName ?? static function (string $date) use ($tasks): string {
            foreach ($tasks->forDate($date) as $task) {
                $name = trim($task->eventName());

                if ('' !== $name) {
                    $clean = trim(EventName::ofTask($task, EventName::lang())['name']);

                    return '' === $clean ? $name : $clean;
                }
            }

            return '';
        };
    }

    /** Whether the report goes out. */
    public static function isOn(): bool
    {
        return (bool) get_option(self::OPTION, true);
    }

    /** Saves the OPTION setting. */
    public static function save(bool $on): void
    {
        update_option(self::OPTION, $on ? '1' : '0');
    }

    /**
     * The heartbeat's call: in the first CATCH_UP_DAYS days of a month, mails
     * every organizer last month's report once. Returns how many went.
     */
    public function run(?\DateTimeImmutable $now = null): int
    {
        if (! self::isOn()) {
            return 0;
        }

        $now ??= new \DateTimeImmutable('now', self::timezone());

        if ((int) $now->format('j') > self::CATCH_UP_DAYS) {
            return 0;
        }

        $month = self::previousMonth($now);

        if ($month === (string) get_option(self::SENT_OPTION, '')) {
            return 0;
        }

        return $this->sendMonth($month, $now);
    }

    /**
     * The organizer's "send now" (Owner 2026-11-14): last month's report to
     * every organizer at once, whatever the day and the switch. Noted as
     * sent, so the heartbeat does not send it again. Returns how many went.
     */
    public function sendNow(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable('now', self::timezone());

        return $this->sendMonth(self::previousMonth($now), $now);
    }

    /** How many organizers sendNow() mails. */
    public function recipientCount(): int
    {
        return count($this->recipients());
    }

    /** @return list<Person> The organizers who get the report. */
    private function recipients(): array
    {
        return array_values(array_filter(
            $this->people->organizers(),
            static fn (Person $person): bool => ! $person->isAnonymized() && ! $person->isBanned()
        ));
    }

    private function sendMonth(string $month, \DateTimeImmutable $now): int
    {
        // Noted before sending: a run that dies halfway leaves some
        // organizers without it rather than mailing the others twice.
        update_option(self::SENT_OPTION, $month);

        $stats = $this->stats($month, $now);
        $before = $this->stats(self::previousMonth(self::firstDay($month)), $now);
        $sent = 0;

        // The click and campaign counts are kept for KEEP_MONTHS months.
        SiteStats::prune($now);

        $organizers = $this->recipients();
        $actions = $this->adminActions($month);

        foreach ($organizers as $person) {
            if ($this->sendTo($person->email, $person, $stats, $before, $actions)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Sends last month's report to $to only, in $person's language when the
     * address is a member's. For the Settings test link.
     */
    public function sendTest(string $to, ?Person $person = null, ?\DateTimeImmutable $now = null): bool
    {
        if (! is_email($to)) {
            return false;
        }

        $now ??= new \DateTimeImmutable('now', self::timezone());
        $month = self::previousMonth($now);

        return $this->sendTo(
            $to,
            $person,
            $this->stats($month, $now),
            $this->stats(self::previousMonth(self::firstDay($month)), $now),
            $this->adminActions($month)
        );
    }

    /**
     * The Settings "Send a test report to me" link: to the signed-in
     * administrator's own address and nobody else.
     */
    public function sendTestFromAdmin(): void
    {
        Admin::assertCanSave(self::TEST_ACTION);

        $to = (string) wp_get_current_user()->user_email;

        if ('' === $to) {
            Admin::redirectTo(
                SettingsPage::PAGE_SLUG,
                __('Your user account has no email address to send to.', 'eventcrew'),
                'error'
            );
        }

        $person = $this->people->findByEmail($to);

        if ($this->sendTest($to, null !== $person && ! $person->isAnonymized() ? $person : null)) {
            Admin::redirectTo(
                SettingsPage::PAGE_SLUG,
                /* translators: %s: the administrator's email address */
                sprintf(__('Test email sent to %s.', 'eventcrew'), $to)
            );
        }

        Admin::redirectTo(
            SettingsPage::PAGE_SLUG,
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __('The test email could not be sent. Check the site’s mail configuration, then look at Diagnostics for the logged reason.', 'eventcrew'),
            'error'
        );
    }

    /**
     * The numbers of one month (Y-m).
     *
     * @return array{
     *     month: string,
     *     new_people: int,
     *     bot: int,
     *     web: int,
     *     signups: int,
     *     signups_web: int,
     *     signups_bot: int,
     *     cancellations: int,
     *     cancellations_web: int,
     *     cancellations_bot: int,
     *     no_shows: int,
     *     completed: int,
     *     free_entries: int,
     *     buyers: array<string, int>,
     *     funnel: array{active: int, signed_up: int, completed: int},
     *     clicks: int,
     *     visits: int,
     *     app_tried: int,
     *     app_installed: int,
     *     app_active: int,
     *     push_recent: int,
     *     push_total: int,
     *     burst_views?: int,
     *     burst_visitors?: int
     * }
     *
     * Besides these, one int per email · button pair ("click:<email>:<button>")
     * and per campaign ("campaign:<name>"); burst_views and burst_visitors
     * only when the Burst Statistics plugin's table is there. The app_active
     * and push_ numbers are as of $now (default: now), whatever the month.
     */
    public function stats(string $month, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', self::timezone());
        $app = $this->app->month($month);
        $devices = $this->app->devices($now);
        $start = self::firstDay($month);
        $end = $start->modify('first day of next month');
        $from = $start->format('Y-m-d H:i:s');
        $to = $end->format('Y-m-d H:i:s');
        $utc = new \DateTimeZone('UTC');
        $dayFrom = $start->format('Y-m-d');
        $dayTo = $end->format('Y-m-d');

        $bot = array_unique(array_merge(
            $this->data->botPeople(
                $start->setTimezone($utc)->format('Y-m-d H:i:s'),
                $end->setTimezone($utc)->format('Y-m-d H:i:s')
            ),
            $this->data->actingPeople(History::CHANNEL_BOT, $from, $to)
        ));
        $web = $this->data->actingPeople(History::CHANNEL_WEB, $from, $to);
        $tasks = $this->data->taskPeople(AssignmentStatus::credited(), $dayFrom, $dayTo);
        $completedPeople = array_filter($tasks, static fn (array $one): bool => $one['matched'] > 0);
        $signups = $this->data->historyByChannel([History::SIGNED_UP, History::ASSIGNED], $from, $to);
        $cancellations = $this->data->historyByChannel([History::CANCELLED, History::LATE_CANCEL], $from, $to);

        $stats = [
            'month' => $month,
            'new_people' => $this->data->newPeople($from, $to),
            'bot' => count($bot),
            'web' => count($web),
            'signups' => array_sum($signups),
            'signups_web' => $signups[History::CHANNEL_WEB] ?? 0,
            'signups_bot' => $signups[History::CHANNEL_BOT] ?? 0,
            'cancellations' => array_sum($cancellations),
            'cancellations_web' => $cancellations[History::CHANNEL_WEB] ?? 0,
            'cancellations_bot' => $cancellations[History::CHANNEL_BOT] ?? 0,
            'no_shows' => $this->data->assignmentCount([AssignmentStatus::NO_SHOW], $dayFrom, $dayTo),
            'completed' => array_sum(array_column($tasks, 'matched')),
            'free_entries' => $this->data->checkinCount(self::FREE_ENTRY_KINDS, $dayFrom, $dayTo),
            'buyers' => $this->data->buyersPerNight($dayFrom, $dayTo),
            'funnel' => [
                'active' => count(array_unique(array_merge($bot, $web, array_keys($tasks)))),
                'signed_up' => count($tasks),
                'completed' => count($completedPeople),
            ],
        ];

        $stats += [
            'app_tried' => $app['tried'],
            'app_installed' => $app['installed'],
            'app_active' => $this->app->active($now),
            'push_recent' => $devices['recent'],
            'push_total' => $devices['total'],
        ];

        return $stats + $this->siteStats($month) + $this->burst($start, $end);
    }

    /**
     * The month's (Y-m) history rows where someone acted for somebody else,
     * oldest first: MonthlyReportRepository::adminActions().
     *
     * @return list<array<string, mixed>>
     */
    public function adminActions(string $month): array
    {
        $start = self::firstDay($month);

        return $this->data->adminActions(
            $start->format('Y-m-d H:i:s'),
            $start->modify('first day of next month')->format('Y-m-d H:i:s')
        );
    }

    /**
     * The month's email button clicks and campaign visits (SiteStats): the
     * two totals and one key per pair and per campaign.
     *
     * @return array<string, int>
     */
    private function siteStats(string $month): array
    {
        $site = SiteStats::month($month);
        $stats = ['clicks' => 0, 'visits' => $site['visits']];

        foreach ($site['clicks'] as $mail => $buttons) {
            foreach ($buttons as $button => $count) {
                $stats[self::CLICK . $mail . ':' . $button] = $count;
                $stats['clicks'] += $count;
            }
        }

        foreach ($site['campaigns'] as $name => $count) {
            $stats[self::CAMPAIGN . $name] = $count;
        }

        return $stats;
    }

    /**
     * Burst Statistics' page views and visitors of the month, [] when its
     * table is not there.
     *
     * @return array<string, int>
     */
    private function burst(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $burst = $this->data->burstMonth($start->getTimestamp(), $end->getTimestamp());

        return null === $burst ? [] : ['burst_views' => $burst['views'], 'burst_visitors' => $burst['visitors']];
    }

    /**
     * The email of $stats, with $before (the month before) in brackets, in
     * the current language.
     *
     * @param array<string, mixed> $stats From stats().
     * @param array<string, mixed> $before From stats().
     * @param list<array<string, mixed>> $actions From adminActions().
     * @return array{subject: string, intro: string, html: string, text: string}
     */
    public function compose(array $stats, array $before, array $actions = []): array
    {
        $month = self::monthLabel((string) $stats['month']);
        $previous = self::monthLabel((string) $before['month']);
        $buyers = (array) $stats['buyers'];
        $countRows = [];

        foreach ($this->countRows($stats, $before) as $row) {
            $countRows[] = [
                ($row['sub'] ? self::SUB : '') . $row['label'],
                (string) (int) ($stats[$row['key']] ?? 0),
                (string) (int) ($before[$row['key']] ?? 0),
            ];
        }

        $countRows[] = [
            __('Ticket buyers', 'eventcrew'),
            (string) array_sum($buyers),
            (string) array_sum((array) $before['buyers']),
        ];

        $nightRows = [];

        foreach ($buyers as $date => $count) {
            $name = ($this->nightName)((string) $date);
            $nightRows[] = [Dates::dayLabel((string) $date) . ('' === $name ? '' : ' · ' . $name), (string) $count, ''];
        }

        $funnel = (array) $stats['funnel'];
        $funnelBefore = (array) $before['funnel'];
        $funnelRows = [
            [__('Active', 'eventcrew'), 'active', null],
            [__('Signed up', 'eventcrew'), 'signed_up', 'active'],
            [__('Completed', 'eventcrew'), 'completed', 'signed_up'],
        ];
        $funnelCells = [];

        foreach ($funnelRows as [$label, $key, $of]) {
            $value = (int) $funnel[$key];
            $percent = null === $of ? '' : ' · ' . self::percent($value, (int) $funnel[$of]);
            $funnelCells[] = [$label, $value . $percent, (string) (int) $funnelBefore[$key]];
        }

        $buyersHeading = __('Ticket buyers per event', 'eventcrew');
        $funnelHeading = __('Active → signed up → completed', 'eventcrew');
        $percentNote = __('% = share of the row above', 'eventcrew');
        $noBuyers = __('No ticket buyers.', 'eventcrew');

        $done = $this->doneForOthers($actions);
        $app = self::appBlock($stats, $before);

        $html = self::table($countRows)
            . self::heading($buyersHeading)
            . ([] === $nightRows ? self::note($noBuyers) : self::table($nightRows))
            . self::heading($funnelHeading)
            . self::table($funnelCells)
            . self::note($percentNote)
            . $app['html']
            . $done['html'];

        $text = self::textRows($countRows) . "\n\n" . mb_strtoupper($buyersHeading) . "\n"
            . ([] === $nightRows ? $noBuyers : self::textRows($nightRows)) . "\n\n"
            . mb_strtoupper($funnelHeading) . "\n" . self::textRows($funnelCells) . "\n" . $percentNote
            . "\n\n" . $app['text']
            . "\n\n" . $done['text'];

        return [
            /* translators: %s: the month, like 10/2026 */
            'subject' => sprintf(__('Monthly report %s', 'eventcrew'), $month),
            'intro' => sprintf(
                /* translators: 1: the month, like 10/2026, 2: the month before, like 9/2026 */
                __('EventCrew numbers for %1$s. In brackets: %2$s.', 'eventcrew'),
                $month,
                $previous
            ),
            'html' => $html,
            'text' => $text,
        ];
    }

    /**
     * The "Crew app" section of the email, as HTML and as text: tried to
     * install, installed and the success rate with the month before in
     * brackets, then the 30-day numbers as of sending (no brackets).
     *
     * @param array<string, mixed> $stats From stats().
     * @param array<string, mixed> $before From stats().
     * @return array{html: string, text: string}
     */
    public static function appBlock(array $stats, array $before): array
    {
        $n = static fn (array $from, string $key): int => (int) ($from[$key] ?? 0);
        $rate = static fn (array $from): string
            => self::percent($n($from, 'app_installed'), $n($from, 'app_tried'));
        $rows = [
            [__('Tried to install', 'eventcrew'), (string) $n($stats, 'app_tried'), (string) $n($before, 'app_tried')],
            [__('Installed', 'eventcrew'), (string) $n($stats, 'app_installed'), (string) $n($before, 'app_installed')],
            [__('Success rate', 'eventcrew'), $rate($stats), $rate($before)],
            [__('Active installs, last 30 days', 'eventcrew'), (string) $n($stats, 'app_active'), ''],
            [
                __('Devices with notifications, reached in 30 days / all', 'eventcrew'),
                $n($stats, 'push_recent') . ' / ' . $n($stats, 'push_total'),
                '',
            ],
        ];
        $heading = __('Crew app', 'eventcrew');
        // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
        $note = __('People count once a month. Active installs: opened from the home screen. The 30 days end when this report was sent.', 'eventcrew');

        return [
            'html' => self::heading($heading) . self::table($rows) . self::note($note),
            'text' => mb_strtoupper($heading) . "\n" . self::textRows($rows) . "\n" . $note,
        ];
    }

    /**
     * The "Done for others" section of the email, as HTML and as text: one
     * group per actor, most actions first, then one line per action in time
     * order, like "Fri 14 Nov 21:03 — Sam signed up · Bar 20:00 · 14.11.
     * (bot)". At most ACTIONS_PER_ACTOR lines per actor, then "and N more";
     * a link to the History page under it all.
     *
     * @param list<array<string, mixed>> $actions From adminActions().
     * @return array{html: string, text: string}
     */
    public function doneForOthers(array $actions): array
    {
        $heading = __('Done for others', 'eventcrew');
        $groups = self::byActor($actions);

        if ([] === $groups) {
            $none = __('Nothing done for others.', 'eventcrew');

            return [
                'html' => self::heading($heading) . self::note($none),
                'text' => mb_strtoupper($heading) . "\n" . $none,
            ];
        }

        $html = self::heading($heading);
        $text = [mb_strtoupper($heading)];

        foreach ($groups as $group) {
            $title = $group['name'] . ' · ' . count($group['rows']);
            $lines = array_map(
                [self::class, 'actionLine'],
                array_slice($group['rows'], 0, self::ACTIONS_PER_ACTOR)
            );
            $more = count($group['rows']) - count($lines);

            if ($more > 0) {
                /* translators: %d: how many more actions are not listed */
                $lines[] = sprintf(__('and %d more', 'eventcrew'), $more);
            }

            $html .= sprintf(
                '<p style="margin:12px 0 4px;font-family:%s;font-size:15px;font-weight:700;color:%s">%s</p>',
                EmailBody::FONT,
                EmailBody::TEXT_COLOR,
                esc_html($title)
            );

            foreach ($lines as $line) {
                $html .= sprintf(
                    '<p style="margin:0 0 2px;font-family:%s;font-size:14px;color:%s">%s</p>',
                    EmailBody::FONT,
                    EmailBody::TEXT_COLOR,
                    esc_html($line)
                );
            }

            $text[] = '';
            $text[] = $title;
            array_push($text, ...$lines);
        }

        $link = __('Full list on the History page', 'eventcrew');
        $url = admin_url('admin.php?page=' . HistoryPage::PAGE_SLUG);

        $html .= sprintf(
            '<p style="margin:12px 0 20px;font-family:%s;font-size:13px;color:%s"><a href="%s">%s</a></p>',
            EmailBody::FONT,
            EmailBody::MUTED_COLOR,
            esc_url($url),
            esc_html($link)
        );
        $text[] = '';
        $text[] = $link . ': ' . $url;

        return ['html' => $html, 'text' => implode("\n", $text)];
    }

    /**
     * $actions grouped by who acted (a person by id, a WordPress user by id),
     * the group with the most actions first, a tie in name order; each
     * group's rows keep their order.
     *
     * @param list<array<string, mixed>> $actions
     * @return list<array{name: string, rows: list<array<string, mixed>>}>
     */
    private static function byActor(array $actions): array
    {
        $groups = [];

        foreach ($actions as $row) {
            $kind = (string) ($row['actor_kind'] ?? '');
            $id = History::ACTOR_WP_USER === $kind
                ? (int) ($row['actor_wp_user_id'] ?? 0)
                : (int) ($row['actor_person_id'] ?? 0);
            $key = $kind . ':' . $id;

            // The actor's name alone, without the channel.
            $groups[$key] ??= ['name' => HistoryText::actor(array_merge($row, ['channel' => ''])), 'rows' => []];
            $groups[$key]['rows'][] = $row;
        }

        $groups = array_values($groups);
        usort(
            $groups,
            static fn (array $a, array $b): int
                => [count($b['rows']), $a['name']] <=> [count($a['rows']), $b['name']]
        );

        return $groups;
    }

    /**
     * One action: "Fri 14 Nov 21:03 — Sam signed up · Bar 20:00 · 14.11. (bot)".
     *
     * @param array<string, mixed> $row
     */
    private static function actionLine(array $row): string
    {
        $parts = array_diff(
            [HistoryText::action($row), HistoryText::task($row), HistoryText::night($row)],
            ['']
        );
        $channel = HistoryText::channel((string) ($row['channel'] ?? ''));

        return Dates::wallClock((string) ($row['created_at'] ?? ''), _x('D j M H:i', 'date format', 'eventcrew'))
            . ' — ' . implode(' · ', $parts)
            . ('' === $channel ? '' : ' (' . $channel . ')');
    }

    /**
     * The count rows of the email, in order, through ROWS_FILTER.
     *
     * @param array<string, mixed> $stats
     * @param array<string, mixed> $before
     * @return list<array{label: string, key: string, sub: bool}>
     */
    public function countRows(array $stats, array $before): array
    {
        $row = static fn (string $label, string $key, bool $sub = false): array
            => ['label' => $label, 'key' => $key, 'sub' => $sub];

        $rows = [
            $row(__('New people', 'eventcrew'), 'new_people'),
            $row(__('Used the bot', 'eventcrew'), 'bot'),
            $row(__('Active on the web page', 'eventcrew'), 'web'),
            $row(__('Sign-ups', 'eventcrew'), 'signups'),
            $row(__('Web page', 'eventcrew'), 'signups_web', true),
            $row(__('Bot', 'eventcrew'), 'signups_bot', true),
            $row(__('Cancellations', 'eventcrew'), 'cancellations'),
            $row(__('Web page', 'eventcrew'), 'cancellations_web', true),
            $row(__('Bot', 'eventcrew'), 'cancellations_bot', true),
            $row(__('No-shows', 'eventcrew'), 'no_shows'),
            $row(__('Completed tasks', 'eventcrew'), 'completed'),
            $row(__('Free entries used', 'eventcrew'), 'free_entries'),
            $row(__('Email button clicks', 'eventcrew'), 'clicks'),
            ...$this->topRows($stats, self::CLICK, self::TOP_CLICKS),
            $row(__('Visits from campaign links', 'eventcrew'), 'visits'),
            ...$this->topRows($stats, self::CAMPAIGN, self::TOP_CAMPAIGNS),
        ];

        if (isset($stats['burst_views'])) {
            $rows[] = $row(__('Page views (Burst)', 'eventcrew'), 'burst_views');
            $rows[] = $row(__('Visitors (Burst)', 'eventcrew'), 'burst_visitors');
        }

        $filtered = apply_filters(self::ROWS_FILTER, $rows, $stats, $before);

        if (! is_array($filtered)) {
            return $rows;
        }

        $clean = [];

        foreach ($filtered as $one) {
            if (is_array($one) && isset($one['label'], $one['key'])) {
                $clean[] = [
                    'label' => (string) $one['label'],
                    'key' => (string) $one['key'],
                    'sub' => ! empty($one['sub']),
                ];
            }
        }

        return $clean;
    }

    /**
     * The sub rows of the $prefix keys of $stats, the biggest first, at
     * most $max: "signup confirmed · ticket" for click:signup_confirmed:ticket,
     * the campaign's name for campaign:<name>.
     *
     * @param array<string, mixed> $stats
     * @return list<array{label: string, key: string, sub: bool}>
     */
    private function topRows(array $stats, string $prefix, int $max): array
    {
        $counts = [];

        foreach ($stats as $key => $value) {
            if (str_starts_with((string) $key, $prefix) && (int) $value > 0) {
                $counts[(string) $key] = (int) $value;
            }
        }

        // The biggest first; a tie in name order, so the email reads the same each time.
        uksort(
            $counts,
            static fn (string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]
        );

        $rows = [];

        foreach (array_slice(array_keys($counts), 0, $max) as $key) {
            $name = substr($key, strlen($prefix));
            $label = self::CLICK === $prefix
                ? str_replace([':', '_'], [' · ', ' '], $name)
                : $name;
            $rows[] = ['label' => $label, 'key' => $key, 'sub' => true];
        }

        return $rows;
    }

    /** The month before $now's month, as Y-m. */
    public static function previousMonth(\DateTimeImmutable $now): string
    {
        return $now->modify('first day of last month')->format('Y-m');
    }

    /** "10/2026" from 2026-10. */
    public static function monthLabel(string $month): string
    {
        return self::firstDay($month)->format('n/Y');
    }

    /** $part of $whole as a whole percent, "–" with nothing to divide by. */
    public static function percent(int $part, int $whole): string
    {
        return $whole > 0 ? (string) (int) round(100 * $part / $whole) . '%' : '–';
    }

    /**
     * @param array<string, mixed> $stats
     * @param array<string, mixed> $before
     * @param list<array<string, mixed>> $actions
     */
    private function sendTo(string $to, ?Person $person, array $stats, array $before, array $actions = []): bool
    {
        $mail = Language::forPerson($person, fn (): array => $this->compose($stats, $before, $actions));

        return $this->mailer->toPersonWithContent(
            null === $person ? 0 : $person->id,
            $to,
            $mail['subject'],
            $mail['intro'],
            $mail['html'],
            $mail['text']
        );
    }

    /** @param list<array{0: string, 1: string, 2: string}> $rows label, value, value before ('' for none) */
    private static function table(array $rows): string
    {
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
            . ' style="border-collapse:collapse;margin:0 0 20px">';

        foreach ($rows as [$label, $value, $before]) {
            $html .= '<tr><td style="' . esc_attr(sprintf(self::ROW, EmailBody::FONT, EmailBody::TEXT_COLOR)) . '">'
                . esc_html($label) . '</td>'
                . '<td align="right" style="' . esc_attr(sprintf(self::ROW, EmailBody::FONT, EmailBody::TEXT_COLOR))
                . ';font-weight:700;white-space:nowrap">' . esc_html($value) . '</td>'
                . '<td align="right" width="64" style="'
                . esc_attr(sprintf(self::ROW, EmailBody::FONT, EmailBody::MUTED_COLOR)) . ';white-space:nowrap">'
                . ('' === $before ? '' : '(' . esc_html($before) . ')') . '</td></tr>';
        }

        return $html . '</table>';
    }

    private static function heading(string $text): string
    {
        return sprintf(
            '<p style="margin:8px 0 8px;font-family:%s;font-size:13px;font-weight:700;letter-spacing:.04em;'
                . 'text-transform:uppercase;color:%s">%s</p>',
            EmailBody::FONT,
            EmailBody::MUTED_COLOR,
            esc_html($text)
        );
    }

    private static function note(string $text): string
    {
        return sprintf(
            '<p style="margin:0 0 20px;font-family:%s;font-size:13px;color:%s">%s</p>',
            EmailBody::FONT,
            EmailBody::MUTED_COLOR,
            esc_html($text)
        );
    }

    /** @param list<array{0: string, 1: string, 2: string}> $rows */
    private static function textRows(array $rows): string
    {
        return implode("\n", array_map(
            static fn (array $row): string => $row[0] . ': ' . $row[1] . ('' === $row[2] ? '' : ' (' . $row[2] . ')'),
            $rows
        ));
    }

    private static function firstDay(string $month): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', self::timezone());

        return false === $day ? new \DateTimeImmutable('first day of this month midnight', self::timezone()) : $day;
    }

    private static function timezone(): \DateTimeZone
    {
        return function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Support\HealthReport;

/**
 * A read-only health page: schema, bot, cron, signup page, icons and mail at a
 * glance, so an organizer (or whoever is helping them install) can see what is
 * wired up without reading the database. Nothing here writes - every fix it
 * points at lives on the Settings page.
 */
final class DiagnosticsPage
{
    public const PAGE_SLUG = 'eventcrew-diagnostics';

    public function __construct(
        private readonly View $view,
        private readonly HealthReport $health
    ) {
    }

    public function render(): void
    {
        $this->view->render(
            'diagnostics',
            [
                'checks' => $this->health->checks(),
                'recent_logs' => $this->health->recentActivity(),
                'last_update_id' => $this->health->lastUpdateId(),
                'plugin_version' => defined('EVENTCREW_VERSION') ? EVENTCREW_VERSION : '',
                'php_version' => PHP_VERSION,
                'wp_version' => get_bloginfo('version'),
            ]
        );
    }
}

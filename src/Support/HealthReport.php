<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Database\Schema;
use EventCrew\Telegram\TelegramClient;
use EventCrew\Telegram\WebhookController;
use EventCrew\Web\PwaController;

/**
 * The read-model behind the Diagnostics page: a set of self-contained checks
 * over the plugin's live state, each returning a Diagnostic.
 *
 * Deliberately makes no outbound calls - it reads options and the database
 * only, so opening the page can never hang on a slow Telegram round-trip. The
 * live webhook status stays on the Settings page, which is where it belongs;
 * here the bot check reports only whether it is configured.
 */
final class HealthReport
{
    /**
     * Every check, in the order the page shows them - schema first, since a
     * broken migration undermines everything below it.
     *
     * @return array<int, Diagnostic>
     */
    public function checks(): array
    {
        return [
            $this->database(),
            $this->bot(),
            $this->notifications(),
            $this->signupPage(),
            $this->appIcons(),
            $this->email(),
        ];
    }

    private function database(): Diagnostic
    {
        global $wpdb;

        $label = __('Database', 'eventcrew');
        $missing = [];

        foreach (Schema::tableNames() as $name) {
            $table = Schema::table($name);
            $found = (string) $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $table)
            );

            if ('' === $found) {
                $missing[] = $name;
            }
        }

        if ([] !== $missing) {
            return Diagnostic::error(
                $label,
                sprintf(
                    /* translators: %s: comma-separated table names */
                    __('Missing tables: %s. Deactivate and reactivate the plugin to run the migration.', 'eventcrew'),
                    implode(', ', $missing)
                )
            );
        }

        $stored = (string) get_option(Schema::VERSION_OPTION, '');

        if ($stored !== Schema::DB_VERSION) {
            return Diagnostic::warn(
                $label,
                sprintf(
                    /* translators: 1: stored schema version, 2: expected version */
                    __('Stored schema version is %1$s, but %2$s is expected; it migrates on next load.', 'eventcrew'),
                    '' === $stored ? '—' : $stored,
                    Schema::DB_VERSION
                )
            );
        }

        return Diagnostic::ok(
            $label,
            sprintf(
                /* translators: %s: schema version number */
                __('All tables present at schema version %s.', 'eventcrew'),
                Schema::DB_VERSION
            )
        );
    }

    private function bot(): Diagnostic
    {
        $label = __('Telegram bot', 'eventcrew');
        $token = trim((string) get_option(TelegramClient::TOKEN_OPTION, ''));

        if ('' === $token) {
            return Diagnostic::info(
                $label,
                __('No bot token set. The bot is optional — add one in Settings to run the group board.', 'eventcrew')
            );
        }

        $secret = trim((string) get_option(WebhookController::SECRET_OPTION, ''));

        if ('' === $secret) {
            return Diagnostic::warn(
                $label,
                __('Token set, but the webhook is not installed — install it from the Settings page.', 'eventcrew')
            );
        }

        return Diagnostic::ok(
            $label,
            __('Token set and webhook installed. The live status is on the Settings page.', 'eventcrew')
        );
    }

    private function notifications(): Diagnostic
    {
        $label = __('Notifications', 'eventcrew');
        $last = (int) get_option(Scheduler::LAST_RUN_OPTION, 0);
        $fallback = (bool) get_option(CronFallbackTrigger::OPTION, false);

        if (0 === $last) {
            return Diagnostic::warn(
                $label,
                $fallback
                    ? __('The hourly job has not run yet; the on-request fallback will run it on a visit.', 'eventcrew')
                    : __('The hourly job has never run. If it stays so, enable the fallback in Settings.', 'eventcrew')
            );
        }

        // Well over the hourly cadence means WP-Cron probably is not firing on
        // this host - the exact case the fallback exists for.
        if ((time() - $last) >= 3 * HOUR_IN_SECONDS) {
            return Diagnostic::warn(
                $label,
                sprintf(
                    /* translators: %s: human-readable time span, e.g. "5 hours" */
                    __('Last run %s ago; WP-Cron may not be firing. Consider the on-request fallback.', 'eventcrew'),
                    human_time_diff($last, time())
                )
            );
        }

        return Diagnostic::ok(
            $label,
            sprintf(
                /* translators: %s: human-readable time span, e.g. "20 minutes" */
                __('Reminders and the open-task call ran %s ago.', 'eventcrew'),
                human_time_diff($last, time())
            )
        );
    }

    private function signupPage(): Diagnostic
    {
        $label = __('Signup page', 'eventcrew');
        $pageId = (int) get_option(PwaController::PAGE_OPTION, 0);

        if ($pageId <= 0) {
            return Diagnostic::info(
                $label,
                __('No page chosen for web signup / the mobile app. Set one in Settings → Mobile app.', 'eventcrew')
            );
        }

        $post = get_post($pageId);

        if (! $post instanceof \WP_Post || 'publish' !== $post->post_status) {
            return Diagnostic::warn(
                $label,
                __('The chosen signup page is missing or not published.', 'eventcrew')
            );
        }

        $content = (string) $post->post_content;
        $hasSignup = has_shortcode($content, 'eventcrew_signup') || has_block('eventcrew/signup', $post);

        if (! $hasSignup) {
            return Diagnostic::warn(
                $label,
                __('The chosen page has no [eventcrew_signup] shortcode or EventCrew signup block.', 'eventcrew')
            );
        }

        return Diagnostic::ok(
            $label,
            sprintf(
                /* translators: %s: the page title */
                __('“%s” is published and carries the signup board.', 'eventcrew'),
                (string) $post->post_title
            )
        );
    }

    private function appIcons(): Diagnostic
    {
        $label = __('App icons', 'eventcrew');

        if (function_exists('has_site_icon') && has_site_icon()) {
            return Diagnostic::ok(
                $label,
                __('Using the WordPress Site Icon for the installed app.', 'eventcrew')
            );
        }

        if (AppIcon::available()) {
            return Diagnostic::ok(
                $label,
                __('No Site Icon set; a lettered icon is generated with GD.', 'eventcrew')
            );
        }

        return Diagnostic::warn(
            $label,
            __('No Site Icon and GD unavailable, so the app has no icon. Set one under Settings → General.', 'eventcrew')
        );
    }

    private function email(): Diagnostic
    {
        $label = __('Email', 'eventcrew');
        $from = (string) get_option('admin_email', '');

        if ('' === $from) {
            return Diagnostic::warn(
                $label,
                __('No site admin email is set, so wp_mail has no default sender.', 'eventcrew')
            );
        }

        return Diagnostic::info(
            $label,
            sprintf(
                /* translators: %s: the site admin email address */
                __('Mail sends via wp_mail (admin: %s). Deliverability depends on the host and SPF/DKIM.', 'eventcrew'),
                $from
            )
        );
    }
}

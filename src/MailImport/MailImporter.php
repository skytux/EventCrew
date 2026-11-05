<?php

declare(strict_types=1);

namespace EventCrew\MailImport;

use Closure;
use EventCrew\Admin\Admin;
use EventCrew\Admin\SettingsPage;
use EventCrew\Support\CronFallbackTrigger;
use EventCrew\Support\Logger;
use EventCrew\Web\BuyerImportController;

/**
 * Reads the import mailbox from the site itself, every five minutes, and
 * feeds each list to the same code the signed endpoint uses. Does nothing
 * while the mail fetch is switched off in Settings.
 *
 * One run, the same as the separate poller's:
 *  - an unread message whose sender does not pass DMARC (or SPF without
 *    DMARC), or that holds no list, goes to Rejected;
 *  - a list the site took goes to Imported; one the site refused for good
 *    (not an organizer, no upcoming event, unreadable) goes to Rejected;
 *  - anything else stays unread and is tried again next run.
 */
final class MailImporter
{
    public const HOOK = 'eventcrew_fetch_mail';

    public const LAST_RUN_OPTION = 'eventcrew_mail_fetch_last_run';

    /** Its own cron schedule, so nothing else switching off can stop it. */
    public const SCHEDULE = 'eventcrew_mail_fetch_interval';

    /** Most messages per run, so a shared-host request can't time out. */
    private const BATCH = 10;

    private const LOCK = 'eventcrew_mail_fetch';

    private const INTERVAL_SECONDS = 300;

    /**
     * @param Closure(): Mailbox|null $mailboxFactory Tests pass a fake; defaults to the settings' mailbox.
     */
    public function __construct(
        private readonly BuyerImportController $import,
        private readonly Logger $logger,
        private readonly ?Closure $mailboxFactory = null
    ) {
    }

    public function boot(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        add_filter('cron_schedules', [$this, 'addSchedule']);

        if (false === wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time(), self::SCHEDULE, self::HOOK);
        }

        // On hosts where WP-Cron never fires, the cron fallback runs due work
        // on ordinary requests; this rides along.
        if ((bool) get_option(CronFallbackTrigger::OPTION, false)) {
            add_action('init', [$this, 'runIfDue'], 100);
        }
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public function addSchedule(array $schedules): array
    {
        // Shown only in cron tools in wp-admin; English, so it needs no
        // translation before init.
        $schedules[self::SCHEDULE] = [
            'interval' => self::INTERVAL_SECONDS,
            'display' => 'Every five minutes (EventCrew mailbox reader)',
        ];

        return $schedules;
    }

    /**
     * The "Check the mailbox now" button in Settings: one run, then back to
     * Settings with what it found.
     */
    public function runFromAdmin(): void
    {
        Admin::assertCanSave(SettingsPage::EMAIL_NONCE_ACTION);

        if (! MailFetchSettings::isOn()) {
            Admin::redirectTo(
                SettingsPage::PAGE_SLUG,
                __(
                    'Reading the mailbox is switched off, or the server, user name or password is missing.',
                    'eventcrew'
                ),
                'error'
            );
            return;
        }

        $status = $this->run();

        if (null === $status) {
            Admin::redirectTo(
                SettingsPage::PAGE_SLUG,
                __('Another check of the mailbox is running. Try again in a minute.', 'eventcrew'),
                'error'
            );
            return;
        }

        $notice = $status['ok']
            /* translators: %s: what the check found, e.g. "1 imported, 0 rejected, 0 left to try again" */
            ? __('Mailbox checked: %s.', 'eventcrew')
            /* translators: %s: the error, e.g. "Could not reach mail.example.org: timed out" */
            : __('The mailbox check failed: %s', 'eventcrew');

        Admin::redirectTo(
            SettingsPage::PAGE_SLUG,
            sprintf($notice, $status['message']),
            $status['ok'] ? 'success' : 'error'
        );
    }

    public function runIfDue(): bool
    {
        if ((time() - (int) get_option(self::LAST_RUN_OPTION, 0)) < self::INTERVAL_SECONDS) {
            return false;
        }

        $this->run();

        return true;
    }

    /**
     * One pass over the unread messages. Returns the status it stored, or
     * null when the fetch is off or another run holds the lock.
     *
     * @return array{time: int, ok: bool, message: string}|null
     */
    public function run(): ?array
    {
        global $wpdb;

        if (null === $this->mailboxFactory && ! MailFetchSettings::isOn()) {
            return null;
        }

        update_option(self::LAST_RUN_OPTION, time(), false);

        // Two requests can start a run at once (cron and a visitor). Two runs
        // would read the same unread mail; the second gives up at once.
        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::LOCK))) {
            return null;
        }

        try {
            $status = $this->runLocked();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::LOCK));
        }

        update_option(MailFetchSettings::STATUS_OPTION, $status, false);

        return $status;
    }

    /**
     * @return array{time: int, ok: bool, message: string}
     */
    private function runLocked(): array
    {
        $mailbox = null !== $this->mailboxFactory ? ($this->mailboxFactory)() : MailFetchSettings::mailbox();
        $authservId = MailFetchSettings::get()['authserv_id'];
        $counts = ['imported' => 0, 'rejected' => 0, 'retry' => 0];

        try {
            foreach ($mailbox->unread(self::BATCH) as $id => $raw) {
                [$outcome, $line] = $this->process($raw, $authservId);
                $this->logger->info('Mail import: ' . $line);
                $counts[$outcome]++;

                if ('imported' === $outcome) {
                    $mailbox->moveToImported((string) $id);
                } elseif ('rejected' === $outcome) {
                    $mailbox->moveToRejected((string) $id);
                }
            }
        } catch (MailboxException $exception) {
            $this->logger->warning('Mail import: ' . $exception->getMessage());

            return ['time' => time(), 'ok' => false, 'message' => $exception->getMessage()];
        } finally {
            $mailbox->close();
        }

        $message = sprintf(
            '%d imported, %d rejected, %d left to try again',
            $counts['imported'],
            $counts['rejected'],
            $counts['retry']
        );

        // Without our own mail server's name no sender can be checked, so
        // every message was refused; say why where the organizer looks.
        if ('' === trim($authservId)) {
            $message .= ' (no trusted mail server is set, so every message is refused)';
        }

        return ['time' => time(), 'ok' => true, 'message' => $message];
    }

    /**
     * @return array{0: string, 1: string} outcome (imported | rejected | retry) and a log line
     */
    private function process(string $raw, string $authservId): array
    {
        $message = MimeMessage::parse($raw);
        $sender = $message->from();
        [$ok, $reason] = ImportMessage::senderCheck($message, $authservId);

        if (! $ok) {
            return ['rejected', sprintf('rejected %s: %s', '' === $sender ? '?' : $sender, $reason)];
        }

        $file = ImportMessage::ticketFile($message);
        $text = null === $file ? ImportMessage::listText($message) : '';

        if (null === $file && '' === trim($text)) {
            return ['rejected', sprintf('rejected %s: no text/plain body and no .xlsx/.csv/.txt attachment', $sender)];
        }

        $answer = $this->import->importList($sender, $text, $message->messageId(), $file);
        $status = $answer['status'];

        if (200 === $status) {
            $outcome = 'imported';
        } elseif (in_array($status, [400, 403, 409], true)) {
            $outcome = 'rejected';
        } else {
            $outcome = 'retry';
        }

        $line = sprintf('%s %s: %d %s', $outcome, $sender, $status, (string) wp_json_encode($answer['body']));

        return [$outcome, $line];
    }
}

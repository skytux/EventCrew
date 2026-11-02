<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Admin\RosterPage;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\BuyerImport;
use EventCrew\Support\Dates;
use EventCrew\Support\Mailer;
use EventCrew\Support\TicketFileException;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The import-by-email endpoint: a mail poller on another server reads the
 * lists organizers mail in and posts each one here.
 *
 * The poller cannot send a WordPress nonce or log in, so the request is
 * authenticated by an HMAC of its raw body under a secret shared with the
 * poller (shown in Settings → Email). The sender is checked again here: the
 * list is only taken from an organizer's address or one of the extra sender
 * addresses. It always goes to the next event night.
 */
final class BuyerImportController
{
    public const SECRET_OPTION = 'eventcrew_import_secret';

    public const ROUTE_NAMESPACE = 'eventcrew/v1';
    public const ROUTE = '/buyers-import';

    /** The address organizers mail their lists to, set in Settings → Email. */
    public const MAILBOX_OPTION = 'eventcrew_import_mailbox';

    /**
     * Addresses besides the organizers' that may mail a list in, such as the
     * shop's own address, set in Settings → Email. A list of strings.
     */
    public const SENDERS_OPTION = 'eventcrew_import_senders';

    /** Carries "sha256=<hex HMAC-SHA256 of the raw body>". */
    private const SIGNATURE_HEADER = 'X-EventCrew-Signature';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly BuyerImport $import,
        private readonly Mailer $mailer
    ) {
    }

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * The address organizers mail their lists to; the poller reads it. Empty
     * when the site has no import mailbox, and then nothing mentions it.
     */
    public static function mailbox(): string
    {
        $address = trim((string) get_option(self::MAILBOX_OPTION, ''));

        return false === filter_var($address, FILTER_VALIDATE_EMAIL) ? '' : $address;
    }

    /**
     * The stored secret, generated the first time Settings shows it.
     */
    public static function ensureSecret(): string
    {
        $secret = trim((string) get_option(self::SECRET_OPTION, ''));

        return '' !== $secret ? $secret : self::rotateSecret();
    }

    /**
     * Replaces the secret. The poller's copy stops working until it is
     * given the new one.
     */
    public static function rotateSecret(): string
    {
        $secret = wp_generate_password(40, false);
        update_option(self::SECRET_OPTION, $secret, false);

        return $secret;
    }

    public static function endpointUrl(): string
    {
        return rest_url(self::ROUTE_NAMESPACE . self::ROUTE);
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            self::ROUTE_NAMESPACE,
            self::ROUTE,
            [
                'methods' => 'POST',
                'callback' => [$this, 'handle'],
                'permission_callback' => [$this, 'authorize'],
            ]
        );
    }

    /**
     * The signature must be the HMAC of the exact bytes received, compared in
     * constant time. With no secret stored everything is refused, and
     * WordPress answers a refused anonymous request with 401.
     */
    public function authorize(WP_REST_Request $request): bool
    {
        $secret = trim((string) get_option(self::SECRET_OPTION, ''));

        if ('' === $secret) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', (string) $request->get_body(), $secret);

        return hash_equals($expected, trim((string) $request->get_header(self::SIGNATURE_HEADER)));
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $data = json_decode((string) $request->get_body(), true);

        if (! is_array($data)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'bad_request'], 400);
        }

        $answer = $this->importList(
            (string) ($data['from'] ?? ''),
            (string) ($data['text'] ?? ''),
            (string) ($data['message_id'] ?? '')
        );

        return new WP_REST_Response($answer['body'], $answer['status']);
    }

    /**
     * The addresses, besides the organizers', that may mail a list in, set in
     * Settings → Email. Lower-case, each a valid address.
     *
     * @return array<int, string>
     */
    public static function extraSenders(): array
    {
        $stored = get_option(self::SENDERS_OPTION, []);
        $senders = [];

        foreach (is_array($stored) ? $stored : [] as $address) {
            $address = strtolower(trim((string) $address));

            if (false !== filter_var($address, FILTER_VALIDATE_EMAIL) && ! in_array($address, $senders, true)) {
                $senders[] = $address;
            }
        }

        return $senders;
    }

    /**
     * Takes one mailed list: checks the sender is an organizer or one of the
     * extra sender addresses, adds the tickets to the next event and mails
     * every organizer a notice. Shared by the signed endpoint and the site's
     * own mailbox reader (MailImporter).
     *
     * With $file (an .xlsx or .csv attachment) the tickets come from the file
     * and $text is not read; without it, $text is one name per line.
     *
     * @param array{filename: string, bytes: string}|null $file
     * @return array{status: int, body: array<string, mixed>}
     */
    public function importList(string $from, string $text, string $messageId, ?array $file = null): array
    {
        $from = strtolower(trim($from));
        $person = '' === $from ? null : $this->people->findByEmail($from);
        $isOrganizer = null !== $person && $person->isOrganizer;

        if (! $isOrganizer && ('' === $from || ! in_array($from, self::extraSenders(), true))) {
            return ['status' => 403, 'body' => ['ok' => false, 'error' => 'not_organizer']];
        }

        $date = $this->import->nextDate();

        if ('' === $date) {
            return [
                'status' => 409,
                'body' => [
                    'ok' => false,
                    'error' => 'no_upcoming_event',
                    'message' => 'There is no upcoming event to add the ticket buyers to. Create its tasks first.',
                ],
            ];
        }

        if (null === $file) {
            $result = $this->import->import($date, $text, 'email', BuyerImport::batchFor($messageId, $text));
        } else {
            try {
                $result = $this->import->importFile(
                    $date,
                    $file['filename'],
                    $file['bytes'],
                    'email',
                    BuyerImport::batchFor($messageId, $file['bytes'])
                );
            } catch (TicketFileException $exception) {
                $this->notifyFailure($from, $exception->getMessage());

                return [
                    'status' => 400,
                    'body' => ['ok' => false, 'error' => 'bad_file', 'message' => $exception->getMessage()],
                ];
            }
        }

        // The same mail delivered again was announced the first time.
        if (! $result['duplicate']) {
            $this->confirm($from, $date, $result['added'], $result['skipped'], null !== $file);
        }

        return [
            'status' => 200,
            'body' => [
                'ok' => true,
                'added' => $result['added'],
                'skipped' => $result['skipped'],
                'date' => $date,
                'duplicate' => $result['duplicate'],
            ],
        ];
    }

    /**
     * Tells every organizer, and the sender when they are not one, what came
     * of a mailed list.
     */
    private function confirm(string $from, string $date, int $added, int $skipped, bool $fromFile): void
    {
        $day = Dates::dayLabel($date);

        $body = $fromFile
            ? sprintf(
                /* translators: 1: number of tickets added, 2: the event day, e.g. "Sat 1 Aug" */
                __('%1$d tickets were imported for %2$s.', 'eventcrew'),
                $added,
                $day
            )
            : sprintf(
                /* translators: 1: number of names added, 2: the event day, e.g. "Sat 1 Aug" */
                __('%1$d ticket buyers were added to the door list for %2$s.', 'eventcrew'),
                $added,
                $day
            );

        if ($skipped > 0) {
            $body .= "\n\n" . ($fromFile
                ? sprintf(
                    /* translators: %d: number of tickets skipped */
                    __('%d tickets were skipped because they were already on the list.', 'eventcrew'),
                    $skipped
                )
                : sprintf(
                    /* translators: %d: number of names skipped */
                    __('%d names were skipped because they were already on the list.', 'eventcrew'),
                    $skipped
                ));
        }

        $body .= "\n\n" . sprintf(
            /* translators: %s: the email address the list was sent from */
            __('The list was sent from %s.', 'eventcrew'),
            $from
        );

        // The Admin tab's Roster screen on the crew page: organizers run the
        // night from their phone, not from wp-admin. wp-admin's Roster page
        // only when there is no crew page (a Telegram-only install).
        $boardUrl = $this->mailer->boardUrl();
        $rosterUrl = '' !== $boardUrl
            ? $boardUrl . '#eventcrew-admin-roster'
            : add_query_arg(
                ['page' => RosterPage::PAGE_SLUG, 'roster_date' => $date],
                admin_url('admin.php')
            );

        $this->tellOrganizers(
            $from,
            sprintf(
                /* translators: %s: the event day, e.g. "Sat 1 Aug" */
                __('Ticket buyers imported for %s', 'eventcrew'),
                $day
            ),
            $body,
            [['label' => __('Open the Roster page', 'eventcrew'), 'url' => $rosterUrl]]
        );
    }

    /**
     * Tells every organizer, and the sender when they are not one, that a
     * mailed ticket file could not be imported, and why.
     */
    private function notifyFailure(string $from, string $reason): void
    {
        $this->tellOrganizers(
            $from,
            __('The ticket list could not be imported', 'eventcrew'),
            $reason . "\n\n"
                . __('Nothing was added to the door list.', 'eventcrew') . "\n\n"
                . sprintf(
                    /* translators: %s: the email address the list was sent from */
                    __('The list was sent from %s.', 'eventcrew'),
                    $from
                )
        );
    }

    /**
     * One email to each organizer, and one to $from when it is not an
     * organizer's address (an extra sender address).
     *
     * @param array<int, array{label: string, url: string}> $actions
     */
    private function tellOrganizers(string $from, string $subject, string $body, array $actions = []): void
    {
        $sent = [];

        foreach ($this->people->organizers() as $organizer) {
            $address = strtolower(trim($organizer->email));

            if (isset($sent[$address])) {
                continue;
            }

            $sent[$address] = true;
            $this->mailer->toPerson($organizer->id, $organizer->email, $subject, $body, $actions);
        }

        if ('' !== $from && ! isset($sent[$from])) {
            $this->mailer->toUnconfirmedAddress($from, $subject, $body, $actions);
        }
    }
}

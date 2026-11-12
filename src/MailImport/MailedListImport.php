<?php

declare(strict_types=1);

namespace EventCrew\MailImport;

use EventCrew\Admin\RosterPage;
use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\BuyerImport;
use EventCrew\Support\BuyerListParser;
use EventCrew\Support\Dates;
use EventCrew\Support\DmBody;
use EventCrew\Support\EmailBody;
use EventCrew\Support\Language;
use EventCrew\Support\Mailer;
use EventCrew\Support\MessageActions;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\NotificationPreferences;
use EventCrew\Support\PushNotifier;
use EventCrew\Support\TicketFileException;
use EventCrew\Support\TicketMail;
use EventCrew\Telegram\BuyerListReset;
use EventCrew\Telegram\DoorListService;
use EventCrew\Telegram\TelegramClient;

/**
 * Takes the lists organizers mail to the import mailbox, which the site reads
 * itself (MailImporter).
 *
 * A list that arrives twice adds nothing twice: a ticket file is recognised by
 * its Message-ID (BuyerImport::batchFor), and a plain list skips the names
 * already on that night's guest list. The list is only taken from an
 * organizer's address or one of the extra sender addresses. It always goes to
 * the next event night.
 *
 * What came of a list goes to every organizer by email and, when they have a
 * private chat with the bot, as a bot message in their language; each
 * organizer turns either off with the LIST_IMPORT row of their notification
 * settings. The bot message about imported tickets has a "🗑 Reset the list"
 * button (BuyerListReset).
 */
final class MailedListImport
{
    /** The address organizers mail their lists to, set in Settings → Email. */
    public const MAILBOX_OPTION = 'eventcrew_import_mailbox';

    /**
     * Addresses besides the organizers' that may mail a list in, such as the
     * shop's own address, set in Settings → Email. A list of strings.
     */
    public const SENDERS_OPTION = 'eventcrew_import_senders';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly BuyerImport $import,
        private readonly Mailer $mailer,
        private readonly GuestRepository $guests,
        private readonly ?TelegramClient $telegram = null
    ) {
    }

    /**
     * The address organizers mail their lists to; the site reads it. Empty
     * when the site has no import mailbox, and then nothing mentions it.
     */
    public static function mailbox(): string
    {
        $address = trim((string) get_option(self::MAILBOX_OPTION, ''));

        return false === filter_var($address, FILTER_VALIDATE_EMAIL) ? '' : $address;
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
     * extra sender addresses, adds the list to the next event and mails every
     * organizer a notice. Called by the site's own mailbox reader
     * (MailImporter).
     *
     * With $file (an .xlsx or .csv attachment) the ticket buyers come from the
     * file and $text is not read; without it, $text is one guest per line,
     * "Name" or "Name +N", added to the guest list.
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

        $plusOnes = 0;

        if (null === $file) {
            $result = $this->importGuests($date, $text, $person?->id);
            $plusOnes = $result['plus_ones'];
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
            $this->confirm($from, $date, $result['added'], $result['skipped'], null !== $file, $plusOnes);
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
     * Adds the guests in a plain-text mail to $date's guest list. The guest
     * list has no batch to recognise a mail delivered twice, so a name already
     * on that date's list (or earlier in the same mail), compared trimmed and
     * without regard to case, is skipped instead.
     *
     * @return array{added: int, skipped: int, duplicate: bool, plus_ones: int}
     *     plus_ones is the total the added guests bring.
     */
    private function importGuests(string $date, string $text, ?int $addedBy): array
    {
        $seen = [];

        foreach ($this->guests->forDate($date) as $guest) {
            $seen[self::guestKey($guest->name)] = true;
        }

        $added = 0;
        $skipped = 0;
        $plusOnes = 0;
        $withEmail = 0;

        foreach (BuyerListParser::guests($text) as $guest) {
            $key = self::guestKey($guest['name']);

            if (isset($seen[$key])) {
                ++$skipped;

                continue;
            }

            $seen[$key] = true;

            if ($this->guests->add($date, $guest['name'], $guest['plus_ones'], '', $addedBy, $guest['email']) > 0) {
                ++$added;
                $plusOnes += min($guest['plus_ones'], GuestRepository::MAX_PLUS_ONES);
                $withEmail += '' === $guest['email'] ? 0 : 1;
            }
        }

        // A guest with an email address is emailed a ticket by the next cron run.
        if ($withEmail > 0) {
            TicketMail::schedule();
        }

        return ['added' => $added, 'skipped' => $skipped, 'duplicate' => false, 'plus_ones' => $plusOnes];
    }

    private static function guestKey(string $name): string
    {
        $name = trim($name);

        return function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
    }

    /**
     * Tells every organizer, and the sender when they are not one, what came
     * of a mailed list: tickets from a file, or guests from a plain-text mail.
     */
    private function confirm(
        string $from,
        string $date,
        int $added,
        int $skipped,
        bool $fromFile,
        int $plusOnes = 0
    ): void {
        // The import started the waiting ticket send: say when it goes out
        // and where to cancel it.
        $pending = $added > 0 ? TicketMail::pending() : null;

        // The texts are the Messages page templates (MessageTemplates). The
        // day is written in the language of each reader.
        $template = $fromFile ? 'import_tickets' : 'import_guests';
        $context = static fn (): array => [
            'added' => $added,
            'skipped' => $skipped,
            'plus_ones' => $plusOnes,
            'day' => Dates::dayLabel($date),
            'from' => $from,
            'sending_at' => null === $pending ? '' : TicketMail::clock($pending['at']),
        ];

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

        $actions = [['label' => __('Open the Roster page', 'eventcrew'), 'url' => $rosterUrl]];
        $doorUrl = $fromFile ? MessageActions::startUrl(DoorListService::START_VERB, str_replace('-', '', $date)) : '';

        // The tickets' door list in the bot: the link only shows it.
        if ('' !== $doorUrl) {
            $actions[] = [
                'label' => __('Door list', 'eventcrew'),
                'url' => $doorUrl,
                'style' => EmailBody::STYLE_TELEGRAM,
            ];
        }

        $this->tellOrganizers(
            $from,
            MessageTemplates::subject($template, $context()),
            self::dropEmptyLines(MessageTemplates::body($template, $context())),
            $actions,
            static fn (): string => self::dropEmptyLines(MessageTemplates::body($template . '_dm', $context())),
            static function () use ($fromFile, $date, $rosterUrl, $doorUrl): array {
                $roster = ['text' => __('Open the Roster page', 'eventcrew'), 'url' => $rosterUrl];

                // Only the tickets can be reset from the bot.
                if (! $fromFile) {
                    return ['inline_keyboard' => [[$roster]]];
                }

                // The same two links as the email, under the reset.
                $links = [$roster];

                if ('' !== $doorUrl) {
                    $links[] = ['text' => __('Door list', 'eventcrew'), 'url' => $doorUrl];
                }

                $markup = BuyerListReset::markup($date);
                $markup['inline_keyboard'][] = $links;

                return $markup;
            }
        );
    }

    /**
     * $text without what an empty placeholder leaves behind: spaces at the end
     * of a line, and more than one empty line in a row.
     */
    private static function dropEmptyLines(string $text): string
    {
        $text = implode("\n", array_map(static fn (string $line): string => rtrim($line, ' '), explode("\n", $text)));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text), "\n");
    }

    /**
     * Tells every organizer, and the sender when they are not one, that a
     * mailed ticket file could not be imported, and why.
     */
    private function notifyFailure(string $from, string $reason): void
    {
        // The texts are the Messages page templates (MessageTemplates).
        $context = ['reason' => $reason, 'from' => $from];

        $this->tellOrganizers(
            $from,
            MessageTemplates::subject('import_failed', $context),
            MessageTemplates::body('import_failed', $context),
            [],
            static fn (): string => MessageTemplates::body('import_failed_dm', $context)
        );
    }

    /**
     * One email to each organizer, greeted by name, and one to $from when it
     * is not an organizer's address (an extra sender address, greeted "Hi,").
     * Each organizer with a private chat with the bot also gets $dm(), with
     * the buttons of $markup(), built in their language. The organizer's LIST_IMPORT row
     * decides whether each of the two comes.
     *
     * @param array<int, array{label: string, url: string}> $actions
     * @param \Closure(): string $dm
     * @param (\Closure(): array<string, mixed>)|null $markup
     */
    private function tellOrganizers(
        string $from,
        string $subject,
        string $body,
        array $actions,
        \Closure $dm,
        ?\Closure $markup = null
    ): void {
        $prefs = new NotificationPreferences();
        $organizerAddresses = [];
        $sent = [];
        $messaged = [];
        $pushed = [];

        foreach ($this->people->organizers() as $organizer) {
            $address = strtolower(trim($organizer->email));
            $organizerAddresses[$address] = true;

            if (! isset($sent[$address]) && $prefs->emailAllowed($organizer, NotificationPreferences::LIST_IMPORT)) {
                $sent[$address] = true;
                $this->mailer->toPerson(
                    $organizer->id,
                    $organizer->email,
                    $subject,
                    EmailBody::withGreeting($organizer->displayName, $body),
                    $actions
                );
            }

            if (! isset($pushed[$organizer->id])) {
                $pushed[$organizer->id] = PushNotifier::notify(
                    $organizer,
                    NotificationPreferences::LIST_IMPORT,
                    (string) Language::forPerson($organizer, $dm)
                );
            }

            if (
                null === $this->telegram
                || isset($messaged[(int) $organizer->telegramChatId])
                || ! $prefs->dmWanted($organizer, NotificationPreferences::LIST_IMPORT, $pushed[$organizer->id])
            ) {
                continue;
            }

            $messaged[(int) $organizer->telegramChatId] = true;
            Language::forPerson($organizer, fn (): mixed => $this->telegram?->sendMessage(
                (int) $organizer->telegramChatId,
                DmBody::frame($organizer, $dm()),
                null === $markup ? null : $markup()
            ));
        }

        // An organizer who turned the email off gets no copy as the sender.
        if ('' !== $from && ! isset($organizerAddresses[$from])) {
            // No name for an extra sender address: "Hi,".
            $this->mailer->toUnconfirmedAddress($from, $subject, EmailBody::withGreeting('', $body), $actions);
        }
    }
}

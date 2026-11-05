<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Support\Newsletter;
use EventCrew\Support\NewsletterText;
use EventCrew\Support\TicketMail;

/**
 * The Newsletter screen of the crew app's Admin tab: whether the newsletter
 * is on, the next one (with its Stop button), the last one sent, and the field
 * "Text for the next newsletter". The text goes through NewsletterText, the
 * same as the bot's /newsletter, so it is translated the same way.
 */
final class CrewAdminNewsletter
{
    public function __construct(
        private readonly Newsletter $newsletter,
        private readonly NewsletterText $text
    ) {
    }

    /**
     * What the screen shows.
     *
     * @return array{
     *     enabled: bool,
     *     days: int,
     *     subscribers: int,
     *     plan: array{
     *         id: int,
     *         sending: bool,
     *         send_on: string,
     *         events: array<int, string>,
     *         sent: int,
     *         failed: int
     *     }|null,
     *     last: array{at: string, sent: int, failed: int}|null,
     *     text: array{texts: array<string, string>, translated: bool}|null
     * }
     */
    public function view(): array
    {
        $status = $this->newsletter->status();
        $plan = null;

        if (null !== $status['plan']) {
            $events = [];

            foreach ((array) ($status['plan']['events'] ?? []) as $event) {
                $events[] = TicketMail::shortDate((string) $event['date'])
                    . ('' === (string) $event['time'] ? '' : ' ' . $event['time'])
                    . ' · ' . $event['title'];
            }

            $plan = [
                'id' => (int) $status['plan']['id'],
                'sending' => Newsletter::PLAN_SENDING === $status['plan']['status'],
                'send_on' => TicketMail::shortDate((string) $status['plan']['send_on']),
                'events' => $events,
                'sent' => (int) ($status['plan']['sent'] ?? 0),
                'failed' => (int) ($status['plan']['failed'] ?? 0),
            ];
        }

        $last = null === $status['last'] ? null : [
            'at' => TicketMail::shortDate(substr((string) ($status['last']['at'] ?? ''), 0, 10)),
            'sent' => (int) ($status['last']['sent'] ?? 0),
            'failed' => (int) ($status['last']['failed'] ?? 0),
        ];

        $text = null === $status['text'] ? null : [
            'texts' => $status['text']['texts'],
            'translated' => $status['text']['translated'],
        ];

        return [
            'enabled' => $status['enabled'],
            'days' => $status['days'],
            'subscribers' => $status['subscribers'],
            'plan' => $plan,
            'last' => $last,
            'text' => $text,
        ];
    }

    /**
     * Saves or clears the text. $op is 'clear' to clear; anything else saves
     * $text (an empty text clears too). Returns the notice code.
     */
    public function saveText(Person $organizer, string $op, string $text): string
    {
        if ('clear' === $op || '' === trim($text)) {
            $this->text->clear();

            return 'admin_newsletter_cleared';
        }

        $result = $this->text->set($text, $organizer->id);

        return $result['translated'] ? 'admin_newsletter_saved' : 'admin_newsletter_saved_untranslated';
    }

    /** Stops the planned newsletter $planId. Returns the notice code. */
    public function stop(int $planId): string
    {
        return match ($this->newsletter->stop($planId)) {
            Newsletter::STOPPED => 'admin_newsletter_stopped',
            Newsletter::TOO_LATE => 'admin_newsletter_too_late',
            default => 'admin_newsletter_already',
        };
    }

    public static function noticeText(string $code): string
    {
        // phpcs:disable Generic.Files.LineLength.TooLong -- gettext literals; splitting one breaks extraction.
        $map = [
            'admin_newsletter_saved' => __('Saved. The text is translated; every language’s version is shown below.', 'eventcrew'),
            'admin_newsletter_saved_untranslated' => __('Saved, but the text could not be translated, so every language shows it as you wrote it.', 'eventcrew'),
            'admin_newsletter_cleared' => __('The text for the next announcement is removed.', 'eventcrew'),
            'admin_newsletter_stopped' => __('The announcement is stopped. It will not be sent.', 'eventcrew'),
            'admin_newsletter_too_late' => __('Too late: the announcement is already being sent.', 'eventcrew'),
            'admin_newsletter_already' => __('This announcement was already stopped or sent.', 'eventcrew'),
        ];
        // phpcs:enable Generic.Files.LineLength.TooLong

        return $map[$code] ?? '';
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Admin\AiPromptsPage;
use EventCrew\Support\BackgroundJobs;
use EventCrew\Support\EventName;
use EventCrew\Support\Newsletter;
use EventCrew\Support\NewsletterText;
use EventCrew\Support\NewsletterWriter;
use EventCrew\Support\OwnTextNotice;
use EventCrew\Support\TicketMail;
use EventCrew\Support\TranslatedText;

/**
 * The Newsletter screen of the crew app's Admin tab: whether the newsletter
 * is on, the next one (with its Stop button), the last one sent, and the field
 * "Text for the next newsletter". The text goes through NewsletterText, the
 * same as the bot's /newsletter, so it is translated the same way.
 *
 * As in the bot, the text is only replaced when the organizer ticks "Replace
 * the current text" (which shows who set it and when), the reset button puts
 * the default back, and the other organizers are told (OwnTextNotice).
 *
 * While there is no organizers' text, the field shows the opening the
 * language model already wrote for the planned announcement (the text the
 * announcement uses in its place), in the viewer's language. Nothing is
 * written on page load: no plan or no opening yet leaves the field empty.
 * Saving that suggestion unchanged stores nothing, so the announcement keeps
 * the model's own text in every language.
 */
final class CrewAdminNewsletter
{
    public function __construct(
        private readonly Newsletter $newsletter,
        private readonly NewsletterText $text,
        private readonly ?OwnTextNotice $notice = null
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
     *     text: array{texts: array<string, string>, translated: bool, set_by: string}|null,
     *     suggested: string,
     *     translating: bool
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
                    . ' · ' . EventName::name((int) ($event['id'] ?? 0), EventName::lang(), (string) $event['title']);
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
            'set_by' => null !== $this->notice ? $this->notice->setBy((array) $status['text']) : '',
        ];
        $translating = BackgroundJobs::isActive(BackgroundJobs::latest(AiPromptsPage::NEWSLETTER_TARGET));

        return [
            'enabled' => $status['enabled'],
            'days' => $status['days'],
            'subscribers' => $status['subscribers'],
            'plan' => $plan,
            'last' => $last,
            'text' => $text,
            'suggested' => self::inViewerLanguage($this->suggestion($status['plan'], $status['text'])),
            'translating' => $translating,
        ];
    }

    /**
     * Saves the text or resets it to the default. $op is 'clear' to reset;
     * anything else saves $text (an empty text resets too). Returns the
     * notice code.
     *
     * With $replace false (the crew app's form without "Replace the current
     * text" ticked), an earlier text is kept and 'admin_newsletter_exists' is
     * answered; null does not ask (wp-admin). $notify tells the other
     * organizers (OwnTextNotice); the background job that saves a text
     * already told them when it was queued.
     *
     * With $background (the crew app), a text to translate is saved by a
     * background job (AiPromptsPage::NEWSLETTER_JOB, the same as the
     * wp-admin save) and 'admin_newsletter_queued' is answered; until then
     * the announcement keeps the text it had.
     */
    public function saveText(
        int $byPersonId,
        string $op,
        string $text,
        bool $background = false,
        ?bool $replace = null,
        bool $notify = true
    ): string {
        $had = null !== $this->text->get();

        if ('clear' === $op || '' === trim($text)) {
            $this->text->clear();

            if ($had && $notify) {
                $this->notice?->changed(OwnTextNotice::NEWSLETTER, $byPersonId, '');
            }

            return 'admin_newsletter_cleared';
        }

        if (false === $replace && $had) {
            return 'admin_newsletter_exists';
        }

        if (! $had && $this->isSuggestion($text)) {
            return 'admin_newsletter_suggested';
        }

        if ($notify) {
            $this->notice?->changed(OwnTextNotice::NEWSLETTER, $byPersonId, $text);
        }

        if ($background && TranslatedText::modelReady()) {
            BackgroundJobs::enqueue(
                AiPromptsPage::NEWSLETTER_JOB,
                AiPromptsPage::NEWSLETTER_TARGET,
                ['text' => $text, 'by' => $byPersonId]
            );

            return 'admin_newsletter_queued';
        }

        $result = $this->text->set($text, $byPersonId);

        return $result['translated'] ? 'admin_newsletter_saved' : 'admin_newsletter_saved_untranslated';
    }

    /**
     * The language model's opening for $plan, keyed by language, while there
     * is no organizers' $text; null when there is none yet.
     *
     * @param array<string, mixed>|null $plan
     * @param array<string, mixed>|null $text
     * @return array<string, string>|null
     */
    private function suggestion(?array $plan, ?array $text): ?array
    {
        return null === $plan || null !== $text ? null : NewsletterWriter::opening($plan['written'] ?? null);
    }

    /**
     * $texts in the viewer's language, else in the language the model
     * writes in, else the first one; '' for none.
     *
     * @param array<string, string>|null $texts
     */
    private static function inViewerLanguage(?array $texts): string
    {
        if (null === $texts) {
            return '';
        }

        return $texts[EventName::lang()] ?? $texts[NewsletterWriter::WRITES_IN] ?? (string) reset($texts);
    }

    /** Whether $text is the suggested opening in one of its languages, as it was shown. */
    private function isSuggestion(string $text): bool
    {
        $suggestion = $this->suggestion($this->newsletter->currentPlan(), null);
        $normal = static fn (string $words): string => trim(str_replace("\r\n", "\n", $words));

        foreach ($suggestion ?? [] as $words) {
            if ($normal($words) === $normal($text)) {
                return true;
            }
        }

        return false;
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
            'admin_newsletter_exists' => __('Nothing was saved: there is already a text. Tick “Replace the current text” to replace it.', 'eventcrew'),
            'admin_newsletter_stopped' => __('The announcement is stopped. It will not be sent.', 'eventcrew'),
            'admin_newsletter_too_late' => __('Too late: the announcement is already being sent.', 'eventcrew'),
            'admin_newsletter_already' => __('This announcement was already stopped or sent.', 'eventcrew'),
            'admin_newsletter_queued' => CrewAdminFollowup::queuedNotice(),
            'admin_newsletter_suggested' => __('Nothing was saved: the announcement uses the suggested text as it is.', 'eventcrew'),
        ];
        // phpcs:enable Generic.Files.LineLength.TooLong

        return $map[$code] ?? '';
    }
}

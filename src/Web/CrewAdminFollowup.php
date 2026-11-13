<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Admin\AiPromptsPage;
use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Support\BackgroundJobs;
use EventCrew\Support\Dates;
use EventCrew\Support\FollowupMail;
use EventCrew\Support\FollowupOptOut;
use EventCrew\Support\FollowupText;
use EventCrew\Support\OwnTextNotice;
use EventCrew\Support\SiteLanguages;
use EventCrew\Support\TranslatedText;

/**
 * The Post-event email screen of the crew app's Admin tab: whether the email
 * is on, and for each version (the thank-you and the sorry text) the text the
 * next email uses, with a field to set a one-time text for the next email
 * only. The one-time text goes through FollowupText, the same as the bot's
 * /followup, so it is translated the same way as the newsletter's text.
 *
 * As in the bot, a one-time text is only replaced when the organizer ticks
 * "Replace the current text" (which shows who set it and when), the reset
 * button puts the default back, and the other organizers are told
 * (OwnTextNotice).
 *
 * Its own card under it manages the do-not-send list (FollowupOptOut): the
 * addresses that pressed "Don't send me these", and any an organizer adds
 * by hand, a page at a time. An organizer can add an address or take one
 * off.
 */
final class CrewAdminFollowup
{
    /** How many addresses of the do-not-send list one page of the card shows. */
    public const OPTOUT_PER_PAGE = 25;

    public function __construct(
        private readonly FollowupText $text,
        private readonly ?OwnTextNotice $notice = null,
        private readonly FollowupOptOut $optOut = new FollowupOptOut()
    ) {
    }

    /**
     * What the screen shows. For each variant: the text the next email uses
     * in every site language (language code => text, with {event} and {date}
     * still in it), whether it is a one-time text, for a one-time text
     * whether it was translated, and whether a saved text is being
     * translated in the background now (translating), and for a one-time
     * text who set it when (set_by, '' when it does not say).
     *
     * @return array{
     *     enabled: bool,
     *     texts: array<string, array{
     *         texts: array<string, string>,
     *         next: bool,
     *         translated: bool,
     *         translating: bool,
     *         set_by: string
     *     }>
     * }
     */
    public function view(): array
    {
        $texts = [];

        foreach (FollowupText::VARIANTS as $variant) {
            $next = $this->text->next($variant);
            $byLanguage = [];

            foreach (SiteLanguages::codes() as $lang) {
                $byLanguage[$lang] = $this->text->text($variant, $lang)['text'];
            }

            $texts[$variant] = [
                'texts' => $byLanguage,
                'next' => null !== $next,
                'translated' => null === $next || $next['translated'],
                'translating' => BackgroundJobs::isActive(
                    BackgroundJobs::latest(AiPromptsPage::followupTarget($variant))
                ),
                'set_by' => null !== $next && null !== $this->notice ? $this->notice->setBy($next) : '',
            ];
        }

        return [
            'enabled' => FollowupMail::enabled(),
            'texts' => $texts,
        ];
    }

    /**
     * Saves the one-time text of $variant, or resets it to the default
     * ($byPersonId: who, 0 from wp-admin). $op is 'clear' to reset; anything
     * else saves $text (an empty text resets too). Returns the notice code.
     *
     * With $replace false (the crew app's form without "Replace the current
     * text" ticked), an earlier one-time text is kept and
     * 'admin_followup_exists' is answered; null does not ask (wp-admin).
     * $notify tells the other organizers (OwnTextNotice); the background job
     * that saves a text already told them when it was queued.
     *
     * With $background (the crew app), a text to translate is saved by a
     * background job (AiPromptsPage::FOLLOWUP_JOB, the same as the wp-admin
     * save) and 'admin_followup_queued' is answered; until then the email
     * keeps the text it had.
     */
    public function saveText(
        int $byPersonId,
        string $variant,
        string $op,
        string $text,
        bool $background = false,
        ?bool $replace = null,
        bool $notify = true
    ): string {
        if (! FollowupText::isVariant($variant)) {
            return 'admin_followup_unknown';
        }

        $had = null !== $this->text->next($variant);

        if ('clear' === $op || '' === trim($text)) {
            $this->text->clearNext($variant);

            if ($had && $notify) {
                $this->notice?->changed($variant, $byPersonId, '');
            }

            return 'admin_followup_cleared';
        }

        if (false === $replace && $had) {
            return 'admin_followup_exists';
        }

        if ($notify) {
            $this->notice?->changed($variant, $byPersonId, $text);
        }

        if ($background && TranslatedText::modelReady()) {
            BackgroundJobs::enqueue(
                AiPromptsPage::FOLLOWUP_JOB,
                AiPromptsPage::followupTarget($variant),
                ['variant' => $variant, 'text' => $text, 'by' => $byPersonId]
            );

            return 'admin_followup_queued';
        }

        $result = $this->text->setNext($variant, $text, $byPersonId);

        return $result['translated'] ? 'admin_followup_saved' : 'admin_followup_saved_untranslated';
    }

    /**
     * The do-not-send list card: one page of OPTOUT_PER_PAGE addresses,
     * newest first, each with the day it was added ('' when not known). A
     * page past the end shows the last page, so removing the last address
     * of a page lands on the page before.
     *
     * @return array{list: list<array{email: string, added: string}>, page: int, pages: int, total: int}
     */
    public function optOutView(int $page = 1): array
    {
        $entries = $this->optOut->entries();
        $total = count($entries);
        $pages = max(1, (int) ceil($total / self::OPTOUT_PER_PAGE));
        $page = max(1, min($page, $pages));
        $shown = array_slice($entries, ($page - 1) * self::OPTOUT_PER_PAGE, self::OPTOUT_PER_PAGE, true);
        $list = [];

        foreach ($shown as $email => $added) {
            $list[] = [
                'email' => (string) $email,
                'added' => 1 === preg_match('/^\d{4}-\d{2}-\d{2}/', $added) ? Dates::dayLabel(substr($added, 0, 10)) : '',
            ];
        }

        return ['list' => $list, 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    /**
     * Puts $email on the do-not-send list ($op 'add') or takes it off
     * ('remove'). Returns the notice code.
     */
    public function saveOptOut(string $op, string $email): string
    {
        $email = NewsletterSubscriberRepository::normalize($email);

        if ('remove' === $op) {
            return $this->optOut->remove($email) ? 'admin_optout_removed' : 'admin_optout_not_listed';
        }

        if ('' === $email || ! is_email($email)) {
            return 'admin_optout_bad_email';
        }

        if ($this->optOut->listed($email)) {
            return 'admin_optout_already';
        }

        return $this->optOut->add($email) ? 'admin_optout_added' : 'admin_optout_failed';
    }

    /** Whether $email is on the do-not-send list (parityA: the bot and the helper ask first). */
    public function optOutListed(string $email): bool
    {
        return $this->optOut->listed(NewsletterSubscriberRepository::normalize($email));
    }

    public static function noticeText(string $code): string
    {
        // phpcs:disable Generic.Files.LineLength.TooLong -- gettext literals; splitting one breaks extraction.
        $map = [
            'admin_followup_saved' => __('Saved for the next post-event email only. The text is translated; every language’s version is shown below.', 'eventcrew'),
            'admin_followup_saved_untranslated' => __('Saved for the next post-event email only, but the text could not be translated, so every language shows it as you wrote it.', 'eventcrew'),
            'admin_followup_cleared' => __('The one-time text is removed. The next post-event email uses the text on the Messages page.', 'eventcrew'),
            'admin_followup_exists' => __('Nothing was saved: there is already a one-time text. Tick “Replace the current text” to replace it.', 'eventcrew'),
            'admin_followup_unknown' => __('Nothing was saved: choose the thank-you text or the sorry text.', 'eventcrew'),
            'admin_followup_queued' => self::queuedNotice(),
            'admin_optout_added' => __('Added. That address gets no post-event email.', 'eventcrew'),
            'admin_optout_already' => __('That address is on the list already.', 'eventcrew'),
            'admin_optout_removed' => __('Removed from the list.', 'eventcrew'),
            'admin_optout_not_listed' => __('That address is not on the list.', 'eventcrew'),
            'admin_optout_bad_email' => __('Nothing was added: that is not an email address.', 'eventcrew'),
            'admin_optout_failed' => __('Nothing was saved. Try again.', 'eventcrew'),
        ];
        // phpcs:enable Generic.Files.LineLength.TooLong

        return $map[$code] ?? '';
    }

    /**
     * The crew app's notice after a text was left to a background job. The
     * text shows "Translating…" until the job is done; then the page puts
     * every language in place by itself (CrewAdminController::textStatus()).
     */
    public static function queuedNotice(): string
    {
        return __('Saved. The text is being translated; every language appears here when that is done.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
    }

    /**
     * The BackgroundJobs targets of the texts this screen and the
     * announcement screen leave to a background job.
     *
     * @return list<string>
     */
    public static function jobTargets(): array
    {
        $targets = array_map([AiPromptsPage::class, 'followupTarget'], FollowupText::VARIANTS);
        $targets[] = AiPromptsPage::NEWSLETTER_TARGET;

        return array_values($targets);
    }
}

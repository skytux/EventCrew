<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Admin\AiPromptsPage;
use EventCrew\Support\BackgroundJobs;
use EventCrew\Support\FollowupMail;
use EventCrew\Support\FollowupText;
use EventCrew\Support\SiteLanguages;
use EventCrew\Support\TranslatedText;

/**
 * The Post-event email screen of the crew app's Admin tab: whether the email
 * is on, and for each version (the thank-you and the sorry text) the text the
 * next email uses, with a field to set a one-time text for the next email
 * only. The one-time text goes through FollowupText, the same as the bot's
 * /followup, so it is translated the same way as the newsletter's text.
 */
final class CrewAdminFollowup
{
    public function __construct(private readonly FollowupText $text)
    {
    }

    /**
     * What the screen shows. For each variant: the text the next email uses
     * in every site language (language code => text, with {event} and {date}
     * still in it), whether it is a one-time text, and, for a one-time text,
     * whether it was translated.
     *
     * @return array{
     *     enabled: bool,
     *     texts: array<string, array{texts: array<string, string>, next: bool, translated: bool}>
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
            ];
        }

        return [
            'enabled' => FollowupMail::enabled(),
            'texts' => $texts,
        ];
    }

    /**
     * Saves or clears the one-time text of $variant ($byPersonId: who, 0 from
     * wp-admin). $op is 'clear' to clear;
     * anything else saves $text (an empty text clears too). Returns the
     * notice code.
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
        bool $background = false
    ): string {
        if (! FollowupText::isVariant($variant)) {
            return 'admin_followup_unknown';
        }

        if ('clear' === $op || '' === trim($text)) {
            $this->text->clearNext($variant);

            return 'admin_followup_cleared';
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

    public static function noticeText(string $code): string
    {
        // phpcs:disable Generic.Files.LineLength.TooLong -- gettext literals; splitting one breaks extraction.
        $map = [
            'admin_followup_saved' => __('Saved for the next post-event email only. The text is translated; every language’s version is shown below.', 'eventcrew'),
            'admin_followup_saved_untranslated' => __('Saved for the next post-event email only, but the text could not be translated, so every language shows it as you wrote it.', 'eventcrew'),
            'admin_followup_cleared' => __('The one-time text is removed. The next post-event email uses the text on the Messages page.', 'eventcrew'),
            'admin_followup_unknown' => __('Nothing was saved: choose the thank-you text or the sorry text.', 'eventcrew'),
            'admin_followup_queued' => self::queuedNotice(),
        ];
        // phpcs:enable Generic.Files.LineLength.TooLong

        return $map[$code] ?? '';
    }

    /**
     * The crew app's notice after a text was left to a background job. The
     * crew app has no status bar, so it says when to look again.
     */
    public static function queuedNotice(): string
    {
        return __('The text is translated in the background and saved when that is done. Reload the page in a few minutes to see every language.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
    }
}

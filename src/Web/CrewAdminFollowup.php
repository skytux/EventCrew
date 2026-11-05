<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Support\FollowupMail;
use EventCrew\Support\FollowupText;
use EventCrew\Support\SiteLanguages;

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
     */
    public function saveText(int $byPersonId, string $variant, string $op, string $text): string
    {
        if (! FollowupText::isVariant($variant)) {
            return 'admin_followup_unknown';
        }

        if ('clear' === $op || '' === trim($text)) {
            $this->text->clearNext($variant);

            return 'admin_followup_cleared';
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
        ];
        // phpcs:enable Generic.Files.LineLength.TooLong

        return $map[$code] ?? '';
    }
}

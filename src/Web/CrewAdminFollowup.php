<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Support\FollowupMail;
use EventCrew\Support\FollowupText;
use EventCrew\Support\Language;

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
     * What the screen shows. For each variant: the Finnish and English text
     * the next email uses (with {event} and {date} still in it), whether it
     * is a one-time text, and, for a one-time text, whether it was translated.
     *
     * @return array{
     *     enabled: bool,
     *     texts: array<string, array{fi: string, en: string, next: bool, translated: bool}>
     * }
     */
    public function view(): array
    {
        $texts = [];

        foreach (FollowupText::VARIANTS as $variant) {
            $next = $this->text->next($variant);
            $texts[$variant] = [
                'fi' => $this->text->text($variant, Language::FINNISH)['text'],
                'en' => $this->text->text($variant, Language::ENGLISH)['text'],
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
     * Saves or clears the one-time text of $variant. $op is 'clear' to clear;
     * anything else saves $text (an empty text clears too). Returns the
     * notice code.
     */
    public function saveText(Person $organizer, string $variant, string $op, string $text): string
    {
        if (! FollowupText::isVariant($variant)) {
            return 'admin_followup_unknown';
        }

        if ('clear' === $op || '' === trim($text)) {
            $this->text->clearNext($variant);

            return 'admin_followup_cleared';
        }

        $result = $this->text->setNext($variant, $text, $organizer->id);

        return $result['translated'] ? 'admin_followup_saved' : 'admin_followup_saved_untranslated';
    }

    public static function noticeText(string $code): string
    {
        // phpcs:disable Generic.Files.LineLength.TooLong -- gettext literals; splitting one breaks extraction.
        $map = [
            'admin_followup_saved' => __('Saved for the next post-event email only. The text is translated; both versions are shown below.', 'eventcrew'),
            'admin_followup_saved_untranslated' => __('Saved for the next post-event email only, but the text could not be translated, so both languages show it as you wrote it.', 'eventcrew'),
            'admin_followup_cleared' => __('The one-time text is removed. The next post-event email uses the text on the Messages page.', 'eventcrew'),
            'admin_followup_unknown' => __('Nothing was saved: choose the thank-you text or the sorry text.', 'eventcrew'),
        ];
        // phpcs:enable Generic.Files.LineLength.TooLong

        return $map[$code] ?? '';
    }
}

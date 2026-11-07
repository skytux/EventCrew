<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Telegram\TelegramClient;

/**
 * The organizers' own texts (the one-time text of the next post-event email,
 * FollowupText, and the text of the next announcement, NewsletterText): who
 * set one, and a notice to the other organizers when one is set or reset.
 *
 * The notice goes to every organizer except the one who made the change: in
 * Telegram when they have a chat with the bot, else by email when their
 * address is confirmed. It names the text, who changed it and shows the new
 * text, with the command that shows or resets it. The person who made the
 * change gets their own confirmation where they made it, not this notice.
 */
final class OwnTextNotice
{
    /** The text of the next announcement ($which in changed()). */
    public const NEWSLETTER = 'newsletter';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram,
        private readonly Mailer $mailer
    ) {
    }

    /**
     * Tells the other organizers that the text $which (FollowupText::CAME,
     * FollowupText::MISSED or NEWSLETTER) was set to $text, or reset to the
     * default when $text is ''. $byPersonId is who did it (0: someone in
     * wp-admin, named by their WordPress name). Returns how many were told.
     */
    public function changed(string $which, int $byPersonId, string $text): int
    {
        $by = $byPersonId > 0 ? $this->people->find($byPersonId) : null;
        $name = null !== $by ? $by->name() : self::adminName();
        $text = trim($text);
        $told = 0;

        foreach ($this->people->organizers() as $organizer) {
            if ($organizer->id === $byPersonId || $organizer->isAnonymized()) {
                continue;
            }

            $sent = (bool) Language::forPerson(
                $organizer,
                fn (): bool => $this->tell($organizer, $which, $name, $text)
            );

            if ($sent) {
                $told++;
            }
        }

        return $told;
    }

    /**
     * "Set by Anna on 07.11.2026 at 14:05." for a stored text (as
     * NewsletterText::get() returns it), '' when it does not say.
     *
     * @param array<string, mixed> $stored
     */
    public function setBy(array $stored): string
    {
        $byId = (int) ($stored['by'] ?? 0);
        $at = (string) ($stored['at'] ?? '');
        $by = $byId > 0 ? $this->people->find($byId) : null;
        $name = null !== $by ? $by->name() : __('an administrator', 'eventcrew');
        $time = '' !== $at ? strtotime($at) : false;

        if (false === $time) {
            return '';
        }

        return sprintf(
            /* translators: 1: a person's name, 2: a date like 07.11.2026, 3: a time like 14:05 */
            __('Set by %1$s on %2$s at %3$s.', 'eventcrew'),
            $name,
            gmdate('d.m.Y', $time),
            gmdate('H:i', $time)
        );
    }

    /** The heading of the text $which: "Post-event email · Thank-you text (came)". */
    public static function heading(string $which): string
    {
        return match ($which) {
            FollowupText::CAME => __('Post-event email', 'eventcrew') . ' · ' . __('Thank-you text (came)', 'eventcrew'),
            FollowupText::MISSED => __('Post-event email', 'eventcrew') . ' · ' . __('Sorry text (missed)', 'eventcrew'),
            default => __('Next announcement', 'eventcrew'),
        };
    }

    /** The label of the buttons that reset an own text. */
    public static function resetLabel(): string
    {
        return __('Reset to default', 'eventcrew');
    }

    private function tell(Person $organizer, string $which, string $name, string $text): bool
    {
        $what = '' === $text
            /* translators: %s: a person's name */
            ? sprintf(__('%s reset it to the default.', 'eventcrew'), $name)
            /* translators: %s: a person's name */
            : sprintf(__('%s set this text:', 'eventcrew'), $name) . "\n\n" . $text;
        $command = self::NEWSLETTER === $which ? '/newsletter' : '/followup';

        if (null !== $organizer->telegramChatId) {
            $body = self::heading($which) . "\n" . $what . "\n\n"
                /* translators: %s: a bot command, /followup or /newsletter */
                . sprintf(__('To see it or reset it to the default: %s', 'eventcrew'), $command);

            return null !== $this->telegram->sendMessage((int) $organizer->telegramChatId, $body);
        }

        if (! $organizer->isEmailVerified()) {
            return false;
        }

        return $this->mailer->toPerson(
            $organizer->id,
            $organizer->email,
            self::heading($which),
            EmailBody::withGreeting($organizer->displayName, $what)
        );
    }

    /** The name of the person saving in wp-admin. */
    private static function adminName(): string
    {
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $name = is_object($user) && isset($user->display_name) ? trim((string) $user->display_name) : '';

        return '' !== $name ? $name : __('an administrator', 'eventcrew');
    }
}

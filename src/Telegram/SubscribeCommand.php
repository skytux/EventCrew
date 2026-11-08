<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\Language;
use EventCrew\Support\SiteLanguages;
use EventCrew\Web\NewsletterController;

/**
 * /subscribe <email> [language]: an organizer adds someone to the newsletter,
 * in a private chat with the bot (Owner, 2026-11-08).
 *
 * The same double opt-in as the door desk (NewsletterController::inviteFromDoor):
 * the address gets the usual confirmation email and is on the list only once
 * its owner opens it. An address already on the list gets no email. The
 * optional language (a site language code, like fi or en) is the one the
 * confirmation and the announcements go out in.
 *
 * In a group the command is refused, so no address is written where the whole
 * crew reads it. Anyone who is not an organizer is refused.
 */
final class SubscribeCommand
{
    public const COMMAND = 'subscribe';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly NewsletterController $newsletter,
        private readonly TelegramClient $telegram
    ) {
    }

    public function onCommand(int $telegramUserId, int $chatId, bool $isPrivate, string $arguments): void
    {
        if (! $isPrivate) {
            $this->telegram->sendMessage($chatId, __('Use /subscribe in a private chat with me.', 'eventcrew'));

            return;
        }

        $person = 0 !== $telegramUserId ? $this->people->findByTelegramUserId($telegramUserId) : null;

        if (null === $person || ! $person->isOrganizer) {
            $this->telegram->sendMessage($chatId, __('Only organizers can use /subscribe.', 'eventcrew'));

            return;
        }

        $words = preg_split('/\s+/u', trim($arguments), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $email = (string) ($words[0] ?? '');
        $language = '';

        if (isset($words[1])) {
            $language = (string) (Language::normalize($words[1]) ?? '');

            if ('' === $language) {
                $this->telegram->sendMessage($chatId, self::usage());

                return;
            }
        }

        if ('' === $email || count($words) > 2) {
            $this->telegram->sendMessage($chatId, self::usage());

            return;
        }

        if (! is_email($email)) {
            /* translators: %s: what was written as the email address. */
            $this->telegram->sendMessage($chatId, sprintf(__('“%s” is not an email address.', 'eventcrew'), $email));

            return;
        }

        $result = $this->newsletter->inviteFromDoor($email, $language);

        $reply = match ($result) {
            NewsletterController::DOOR_SENT => sprintf(
                /* translators: %s: email address. */
                __('A confirmation email went to %s. They are on the list once they open it.', 'eventcrew'),
                $email
            ),
            /* translators: %s: email address. */
            NewsletterController::DOOR_ALREADY => sprintf(__('%s is already on the newsletter.', 'eventcrew'), $email),
            default => __('The email could not be sent. Try again later.', 'eventcrew'),
        };

        $this->telegram->sendMessage($chatId, $reply);
    }

    /** How to use the command, with the site's language codes. */
    public static function usage(): string
    {
        return sprintf(
            /* translators: %s: language codes, like "fi, en". */
            __("Add someone to the newsletter: /subscribe name@example.com\nAdd a language code to choose their language: %s.", 'eventcrew'),
            implode(', ', SiteLanguages::codes())
        );
    }
}

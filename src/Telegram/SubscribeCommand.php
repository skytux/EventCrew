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

        $asked = self::parse($arguments);

        if ('' !== $asked['error']) {
            $this->telegram->sendMessage($chatId, $asked['error']);

            return;
        }

        $this->telegram->sendMessage($chatId, $this->invite($asked['email'], $asked['language']));
    }

    /**
     * The Crew app's helper box: "/subscribe <email> [language]" as the
     * question shown with Confirm, or (ok false) why not. The caller has
     * checked the person is an organizer.
     *
     * @return array{ok: bool, text: string, state: array<string, mixed>}
     */
    public function prepareWeb(string $arguments): array
    {
        $asked = self::parse($arguments);

        if ('' !== $asked['error']) {
            return ['ok' => false, 'text' => $asked['error'], 'state' => []];
        }

        return [
            'ok' => true,
            /* translators: %s: email address. */
            'text' => sprintf(__('Add %s to the newsletter? They get a confirmation email first.', 'eventcrew'), $asked['email']),
            'state' => ['email' => $asked['email'], 'language' => $asked['language']],
        ];
    }

    /**
     * Confirm in the helper box: the invitation, as the command sends it.
     *
     * @param array<string, mixed> $state
     * @return array{changed: bool, text: string}
     */
    public function runWeb(array $state): array
    {
        $email = (string) ($state['email'] ?? '');

        if (! is_email($email)) {
            return ['changed' => false, 'text' => __('That can no longer be done.', 'eventcrew')];
        }

        $result = $this->newsletter->inviteFromDoor($email, (string) ($state['language'] ?? ''));

        return ['changed' => NewsletterController::DOOR_SENT === $result, 'text' => self::reply($result, $email)];
    }

    /**
     * The address and language "<email> [language]" names, or the answer
     * that ends it.
     *
     * @return array{error: string, email: string, language: string}
     */
    private static function parse(string $arguments): array
    {
        $words = preg_split('/\s+/u', trim($arguments), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $email = (string) ($words[0] ?? '');
        $language = '';
        $fail = static fn (string $text): array => ['error' => $text, 'email' => '', 'language' => ''];

        if (isset($words[1])) {
            $language = (string) (Language::normalize($words[1]) ?? '');

            if ('' === $language) {
                return $fail(self::usage());
            }
        }

        if ('' === $email || count($words) > 2) {
            return $fail(self::usage());
        }

        if (! is_email($email)) {
            /* translators: %s: what was written as the email address. */
            return $fail(sprintf(__('“%s” is not an email address.', 'eventcrew'), $email));
        }

        return ['error' => '', 'email' => $email, 'language' => $language];
    }

    private function invite(string $email, string $language): string
    {
        return self::reply($this->newsletter->inviteFromDoor($email, $language), $email);
    }

    private static function reply(string $result, string $email): string
    {
        return match ($result) {
            NewsletterController::DOOR_SENT => sprintf(
                /* translators: %s: email address. */
                __('A confirmation email went to %s. They are on the list once they open it.', 'eventcrew'),
                $email
            ),
            /* translators: %s: email address. */
            NewsletterController::DOOR_ALREADY => sprintf(__('%s is already on the newsletter.', 'eventcrew'), $email),
            default => __('The email could not be sent. Try again later.', 'eventcrew'),
        };
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

<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\Language;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\PrivacyPolicy;

/**
 * Turns a Telegram account into a verified person.
 *
 * A person's identity here is a verified email, never a WordPress user, so a
 * bot user who taps Join has to give one first. The flow is: /start in the
 * bot's private chat asks for an email; the next message is taken as that
 * email; a person row is created if none has it yet, and a single-use
 * confirmation link is emailed. The slot itself stays out of reach until the
 * email is confirmed - that gate lives in BoardService.
 *
 * Typing an email links nothing. The Telegram account waits beside the token
 * and is linked only when the emailed link is clicked (VerificationController),
 * because the click is the only proof the typist owns the address. Linking on
 * the typed address alone let anyone take over another person's account -
 * their tasks, credits and free-entry tickets - by typing their email.
 */
final class OnboardingService
{
    use GroupBreadcrumb;

    /** The auth-token purpose for a bot email confirmation. */
    public const VERIFY_PURPOSE = 'email_verify';

    private const AWAIT_EMAIL_PREFIX = 'eventcrew_tg_await_email_';
    private const PENDING_LINK_PREFIX = 'eventcrew_tg_pending_link_';
    private const TOKEN_TTL = DAY_IN_SECONDS;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly AuthTokenRepository $tokens,
        private readonly TelegramClient $telegram,
        private readonly Logger $logger,
        private readonly Mailer $mailer
    ) {
    }

    /**
     * Handles /start. In a private chat an already-verified account is told it
     * is set up; anyone else is asked for their email, and we remember for a few
     * minutes that this user's next message is that answer.
     *
     * Asked in a group it can't run: Telegram forbids a bot from opening a DM to
     * someone who hasn't messaged it first, so onboarding a newcomer by DM is
     * impossible. Instead the group gets a one-tap link into the bot's private
     * chat, where /start then works normally.
     */
    public function start(int $telegramUserId, int $chatId, string $displayName, bool $isPrivate = true): void
    {
        if (! $isPrivate) {
            // Posted in the group, which reads English.
            Language::run(Language::ENGLISH, fn () => $this->nudgeToPrivateChat($chatId));

            return;
        }

        $existing = $this->people->findByTelegramUserId($telegramUserId);

        if (null !== $existing && $existing->isEmailVerified()) {
            $this->telegram->sendMessage(
                $chatId,
                __('You are already set up. Tap a task on the board to sign up.', 'eventcrew')
            );

            return;
        }

        set_transient(
            self::AWAIT_EMAIL_PREFIX . $telegramUserId,
            ['chat_id' => $chatId, 'name' => $displayName],
            15 * MINUTE_IN_SECONDS
        );

        $this->telegram->sendMessage(
            $chatId,
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __("Welcome! What's your email address? I'll send a link to confirm it, and then you can sign up for tasks.", 'eventcrew')
            . "\n\n" . PrivacyPolicy::noticeLine(),
            ['inline_keyboard' => array_merge(
                '' === PrivacyPolicy::noticeUrl() ? [] : [[[
                    'text' => PrivacyPolicy::noticeLinkLabel(),
                    'url' => PrivacyPolicy::noticeUrl(),
                ]]],
                [MenuButtons::cancelRow()]
            )]
        );
    }

    /**
     * The same privacy notice the web form carries, as a named link: a button
     * in Telegram, an action in the email.
     *
     * The bot asks for exactly what the web form asks for, so it owes the same
     * answer at the same moment - a person onboarding through Telegram should
     * not have to find the website to learn what happens to their address.
     * With no notice published the sentence stands alone rather than trailing
     * a broken link, so this is null.
     *
     * @return array{label: string, url: string}|null
     */
    private function privacyAction(): ?array
    {
        $url = PrivacyPolicy::noticeUrl();

        return '' === $url ? null : ['label' => PrivacyPolicy::noticeLinkLabel(), 'url' => $url];
    }

    public function isAwaitingEmail(int $telegramUserId): bool
    {
        return false !== get_transient(self::AWAIT_EMAIL_PREFIX . $telegramUserId);
    }

    /**
     * The Cancel button: forgets the "what's your email?" question. True when it was
     * waiting.
     */
    public function cancelPending(int $telegramUserId): bool
    {
        $waiting = $this->isAwaitingEmail($telegramUserId);
        delete_transient(self::AWAIT_EMAIL_PREFIX . $telegramUserId);

        return $waiting;
    }

    /**
     * Points a group at the bot's private chat with a one-tap deep link, for
     * /start where onboarding can only happen in the DM. Falls back to plain
     * guidance if the bot's username isn't cached yet.
     */
    private function nudgeToPrivateChat(int $chatId): void
    {
        $username = trim((string) get_option(BoardService::USERNAME_OPTION, ''));

        if ('' === $username) {
            $this->telegram->sendMessage(
                $chatId,
                __('Open the bot in a private chat and send /start there to set up your account.', 'eventcrew')
            );

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            __('Let’s set you up in a private chat — tap below to open the bot.', 'eventcrew'),
            ['inline_keyboard' => [[[
                'text' => __('Set me up →', 'eventcrew'),
                'url' => 'https://t.me/' . $username . '?start=onboard',
            ]]]]
        );
    }

    /**
     * Handles the message that follows /start: the user's email. Validates it,
     * finds or creates the person, issues a confirmation token and sends the
     * magic link. The Telegram account is only remembered next to the token;
     * the click links it.
     */
    public function captureEmail(int $telegramUserId, int $chatId, string $text): void
    {
        $pending = get_transient(self::AWAIT_EMAIL_PREFIX . $telegramUserId);

        if (false === $pending) {
            return;
        }

        $email = sanitize_email(trim($text));

        if ('' === $email || ! is_email($email)) {
            $this->telegram->sendMessage(
                $chatId,
                __("That doesn't look like an email address. Try again?", 'eventcrew')
            );

            return;
        }

        // A Telegram id already linked to a different email is left alone: the
        // column is unique, and reusing it would splice two people's histories
        // - and their earned credits - onto one account.
        $linked = $this->people->findByTelegramUserId($telegramUserId);

        if (null !== $linked && 0 !== strcasecmp($linked->email, $email)) {
            $this->telegram->sendMessage(
                $chatId,
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                __('This Telegram account is already linked to another email. Contact an organizer if that is wrong.', 'eventcrew')
            );
            delete_transient(self::AWAIT_EMAIL_PREFIX . $telegramUserId);

            return;
        }

        $displayName = is_array($pending) ? (string) ($pending['name'] ?? '') : '';
        $personId = $this->findOrCreate($email, $displayName);

        $rawToken = $this->tokens->issue($personId, self::VERIFY_PURPOSE, self::TOKEN_TTL);
        set_transient(
            self::pendingLinkKey($rawToken),
            ['telegram_user_id' => $telegramUserId, 'telegram_chat_id' => $chatId],
            self::TOKEN_TTL
        );
        delete_transient(self::AWAIT_EMAIL_PREFIX . $telegramUserId);

        $this->sendVerificationEmail($personId, $email, $displayName, $rawToken);

        $this->telegram->sendMessage(
            $chatId,
            __('Check your email and tap the link to confirm it. Then pick a task on the board.', 'eventcrew')
        );
    }

    /**
     * A new email gets its person row straight away, but without Telegram ids:
     * those are linked when the emailed link is clicked.
     */
    private function findOrCreate(string $email, string $displayName): int
    {
        $person = $this->people->findByEmail($email);

        if (null === $person) {
            return $this->people->create([
                'email' => $email,
                'display_name' => $displayName,
            ]);
        }

        return $person->id;
    }

    /**
     * Takes the Telegram account waiting on a confirmation token, and forgets
     * it so it is used once. Null when nothing is waiting.
     *
     * Called only after the token itself has been consumed, so an expired,
     * spent or unknown token never reaches a link.
     *
     * @return array{telegram_user_id: int, telegram_chat_id: int}|null
     */
    public static function takePendingLink(string $rawToken): ?array
    {
        $key = self::pendingLinkKey($rawToken);
        $pending = get_transient($key);
        delete_transient($key);

        if (! is_array($pending) || 0 === (int) ($pending['telegram_user_id'] ?? 0)) {
            return null;
        }

        return [
            'telegram_user_id' => (int) $pending['telegram_user_id'],
            'telegram_chat_id' => (int) ($pending['telegram_chat_id'] ?? 0),
        ];
    }

    /**
     * Keyed by the token's hash - the same value auth_tokens stores - so the
     * raw token is never written down, and each emailed link carries only the
     * Telegram account that asked for it.
     */
    private static function pendingLinkKey(string $rawToken): string
    {
        return self::PENDING_LINK_PREFIX . hash('sha256', trim($rawToken));
    }

    /**
     * Mails the confirmation link as a named button. Mailer logs a failed send,
     * and the plain-text part still carries the full link for a reader whose
     * mail client shows no HTML.
     */
    private function sendVerificationEmail(int $personId, string $email, string $displayName, string $rawToken): void
    {
        $link = add_query_arg(['token' => $rawToken], rest_url('eventcrew/v1/verify'));
        $greeting = '' !== $displayName ? $displayName : $email;

        $body = sprintf(
            /* translators: 1: person's name */
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __("Hi %1\$s,\n\nConfirm your email to finish signing up for EventCrew tasks.\n\nThe link works once and expires in a day. If you didn't ask for this, you can ignore this email.", 'eventcrew'),
            $greeting
        ) . "\n\n" . PrivacyPolicy::noticeLine();

        $this->mailer->toPerson(
            $personId,
            $email,
            __('Confirm your email for EventCrew', 'eventcrew'),
            $body,
            array_values(array_filter([
                ['label' => __('Confirm your email', 'eventcrew'), 'url' => $link],
                $this->privacyAction(),
            ]))
        );
    }
}

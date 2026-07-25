<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\Logger;

/**
 * Turns a Telegram account into a verified person.
 *
 * A person's identity here is a verified email, never a WordPress user, so a
 * bot user who taps Join has to give one first. The flow is: /start in the
 * bot's private chat asks for an email; the next message is taken as that
 * email; a person row is created (or an existing one linked) and a single-use
 * confirmation link is emailed. The slot itself stays out of reach until the
 * email is confirmed - that gate lives in BoardService.
 */
final class OnboardingService
{
    use GroupBreadcrumb;

    /** The auth-token purpose for a bot email confirmation. */
    public const VERIFY_PURPOSE = 'email_verify';

    private const AWAIT_EMAIL_PREFIX = 'eventcrew_tg_await_email_';
    private const TOKEN_TTL = DAY_IN_SECONDS;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly AuthTokenRepository $tokens,
        private readonly TelegramClient $telegram,
        private readonly Logger $logger
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
            $this->nudgeToPrivateChat($chatId);

            return;
        }

        $existing = $this->people->findByTelegramUserId($telegramUserId);

        if (null !== $existing) {
            // /start doubles as "turn my account back on" for anyone whose
            // account was switched off, so a disabled person is welcomed back
            // rather than sent through onboarding again.
            if ($existing->isDisabled()) {
                $this->people->enable($existing->id);
                $this->telegram->sendMessage(
                    $chatId,
                    __('Welcome back — your account is on again. Tap a task on the board to sign up.', 'eventcrew')
                );

                return;
            }

            if ($existing->isEmailVerified()) {
                $this->telegram->sendMessage(
                    $chatId,
                    __('You are already set up. Tap a task on the board to sign up.', 'eventcrew')
                );

                return;
            }
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
        );
    }

    public function isAwaitingEmail(int $telegramUserId): bool
    {
        return false !== get_transient(self::AWAIT_EMAIL_PREFIX . $telegramUserId);
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
     * creates or links the person, issues a confirmation token and sends the
     * magic link.
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
        $personId = $this->createOrLink($email, $displayName, $telegramUserId, $chatId);

        $rawToken = $this->tokens->issue($personId, self::VERIFY_PURPOSE, self::TOKEN_TTL);
        delete_transient(self::AWAIT_EMAIL_PREFIX . $telegramUserId);

        $this->sendVerificationEmail($email, $displayName, $rawToken);

        $this->telegram->sendMessage(
            $chatId,
            __('Check your email and tap the link to confirm it. Then pick a task on the board.', 'eventcrew')
        );
    }

    private function createOrLink(string $email, string $displayName, int $telegramUserId, int $chatId): int
    {
        $person = $this->people->findByEmail($email);

        if (null === $person) {
            return $this->people->create([
                'email' => $email,
                'display_name' => $displayName,
                'telegram_user_id' => $telegramUserId,
                'telegram_chat_id' => $chatId,
            ]);
        }

        $this->people->linkTelegram($person->id, $telegramUserId, $chatId);

        return $person->id;
    }

    private function sendVerificationEmail(string $email, string $displayName, string $rawToken): void
    {
        $link = add_query_arg(['token' => $rawToken], rest_url('eventcrew/v1/verify'));
        $greeting = '' !== $displayName ? $displayName : $email;

        $body = sprintf(
            /* translators: 1: person's name, 2: confirmation link */
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __("Hi %1\$s,\n\nConfirm your email to finish signing up for EventCrew tasks:\n\n%2\$s\n\nThe link works once and expires in a day. If you didn't ask for this, you can ignore this email.", 'eventcrew'),
            $greeting,
            $link
        );

        $sent = wp_mail(
            $email,
            __('Confirm your email for EventCrew', 'eventcrew'),
            $body
        );

        if (! $sent) {
            $this->logger->warning(sprintf('Verification email to %s could not be sent.', $email));
        }
    }
}

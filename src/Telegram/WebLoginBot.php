<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\Logger;
use EventCrew\Support\TelegramLogin;

/**
 * The bot's half of signing in to the Crew app through the bot.
 *
 * The page's Telegram button opens t.me/<bot>?start=login-<code>. In the
 * private chat that arrives here as "/start login-<code>":
 * - a code that is unknown, expired or used gets a short "expired" message;
 * - a Telegram account linked to a crew member with a confirmed email gets a
 *   "Sign in to the crew app" button, and pressing it approves the code;
 * - a blocked crew member is refused;
 * - anyone else goes through the normal sign-up (the bot asks for an email
 *   and mails a confirmation link). The code is remembered against their
 *   Telegram account, and VerificationController approves it when the link
 *   is clicked and links the account.
 */
final class WebLoginBot
{
    public function __construct(
        private readonly TelegramLogin $login,
        private readonly PersonRepository $people,
        private readonly OnboardingService $onboarding,
        private readonly TelegramClient $telegram,
        private readonly Logger $logger
    ) {
    }

    /** True for a /start payload this class handles. */
    public static function isLoginPayload(string $payload): bool
    {
        return str_starts_with($payload, TelegramLogin::PAYLOAD_PREFIX);
    }

    /**
     * "/start login-<code>" in a private chat.
     */
    public function onStart(int $telegramUserId, int $chatId, string $displayName, string $payload): void
    {
        $code = substr($payload, strlen(TelegramLogin::PAYLOAD_PREFIX));

        if (! TelegramLogin::isAvailable() || ! $this->login->claimForTelegramUser($code, $telegramUserId)) {
            $this->telegram->sendMessage($chatId, self::expiredText());

            return;
        }

        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null !== $person && $person->isBanned()) {
            $this->logger->warning('Telegram sign-in refused: the account is blocked.');
            $this->telegram->sendMessage($chatId, self::blockedText());

            return;
        }

        if (null !== $person && self::canSignIn($person)) {
            $this->telegram->sendMessage(
                $chatId,
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                __('Do you want to sign in to the crew app? Press the button only if you just pressed Telegram on the crew app yourself.', 'eventcrew'),
                ['inline_keyboard' => [[[
                    'text' => __('Sign in to the crew app', 'eventcrew'),
                    'callback_data' => TelegramLogin::CALLBACK_PREFIX . $code,
                ]]]]
            );

            return;
        }

        // No crew account for this Telegram account yet: the normal sign-up.
        // The browser signs in when the confirmation link links the account.
        $this->login->rememberForSignUp($code, $telegramUserId);
        $this->onboarding->start($telegramUserId, $chatId, $displayName, true);
    }

    /**
     * The "Sign in to the crew app" button. Approves the code only for the
     * Telegram account that opened it, and only for a crew member who may
     * sign in.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onConfirm(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $telegramUserId = (int) ($callbackQuery['from']['id'] ?? 0);
        $code = substr((string) ($callbackQuery['data'] ?? ''), strlen(TelegramLogin::CALLBACK_PREFIX));
        $chatId = (int) ($callbackQuery['message']['chat']['id'] ?? 0);
        $messageId = (int) ($callbackQuery['message']['message_id'] ?? 0);

        $person = 0 === $telegramUserId ? null : $this->people->findByTelegramUserId($telegramUserId);

        if (null !== $person && $person->isBanned()) {
            $this->logger->warning('Telegram sign-in refused: the account is blocked.');
            $this->telegram->answerCallbackQuery($callbackId, self::blockedText(), true);

            return;
        }

        $approved = null !== $person
            && self::canSignIn($person)
            && TelegramLogin::isAvailable()
            && $this->login->approve($code, $telegramUserId, $person->id);

        if (! $approved) {
            $this->telegram->answerCallbackQuery($callbackId, self::expiredText(), true);
            $this->edit($chatId, $messageId, self::expiredText());

            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);
        $this->edit($chatId, $messageId, __('Signed in. You can go back to the crew app.', 'eventcrew'));
    }

    /** The same rule the email link and the old Telegram sign-in used: a confirmed, real address. */
    public static function canSignIn(Person $person): bool
    {
        return ! $person->isBanned() && $person->isEmailVerified() && ! Person::isPlaceholderEmail($person->email);
    }

    private function edit(int $chatId, int $messageId, string $text): void
    {
        if (0 !== $chatId && 0 !== $messageId) {
            $this->telegram->editMessageText($chatId, $messageId, $text);
        }
    }

    private static function expiredText(): string
    {
        return __('This sign-in link has expired. Press Telegram on the crew app again.', 'eventcrew');
    }

    private static function blockedText(): string
    {
        return __('Your account is blocked. Talk to an organizer.', 'eventcrew');
    }
}

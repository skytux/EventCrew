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
 * - a blocked crew member is refused;
 * - otherwise the bot shows three 4-digit codes as buttons: the code the
 *   page shows and two wrong ones, in random order. A bot link someone else
 *   sent them would sign in that other person's browser, and they cannot
 *   know which button is right. A wrong press cancels the code;
 * - after the right press, a Telegram account linked to a crew member with a
 *   confirmed email is signed in (the code is approved);
 * - after the right press, anyone else goes through the normal sign-up (the
 *   bot asks for an email and mails a confirmation link). The code is
 *   remembered against their Telegram account, and VerificationController
 *   approves it when the link is clicked and links the account.
 *
 * The 4-digit codes go in the buttons and on the page only, never in the log.
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

        $choices = TelegramLogin::isAvailable() ? $this->login->choices($code) : [];

        if ([] === $choices || ! $this->login->claimForTelegramUser($code, $telegramUserId)) {
            $this->telegram->sendMessage($chatId, self::expiredText());

            return;
        }

        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null !== $person && $person->isBanned()) {
            $this->logger->warning('Telegram sign-in refused: the account is blocked.');
            $this->telegram->sendMessage($chatId, self::blockedText());

            return;
        }

        $buttons = [];

        foreach ($choices as $choice) {
            $buttons[] = [
                'text' => $choice,
                'callback_data' => TelegramLogin::CALLBACK_PREFIX . $code . ':' . $choice,
            ];
        }

        $this->telegram->sendMessage(
            $chatId,
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __('To sign in to the crew app, press the code that the crew app on your screen shows. If you did not just press Telegram on the crew app yourself, press nothing.', 'eventcrew'),
            ['inline_keyboard' => [$buttons]]
        );
    }

    /**
     * A code button. Only the Telegram account that opened the code may
     * press. The right code signs in a crew member who may sign in, or starts
     * the sign-up for anyone else; a wrong code cancels the sign-in. A press
     * after the right or a wrong one does nothing.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onConfirm(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $telegramUserId = (int) ($callbackQuery['from']['id'] ?? 0);
        $data = substr((string) ($callbackQuery['data'] ?? ''), strlen(TelegramLogin::CALLBACK_PREFIX));
        $chatId = (int) ($callbackQuery['message']['chat']['id'] ?? 0);
        $messageId = (int) ($callbackQuery['message']['message_id'] ?? 0);

        // "<code>:<4 digits>". A button from before the code buttons (no
        // digits) is treated as expired.
        $parts = explode(':', $data);
        $code = $parts[0];
        $choice = $parts[1] ?? '';

        $person = 0 === $telegramUserId ? null : $this->people->findByTelegramUserId($telegramUserId);

        if (null !== $person && $person->isBanned()) {
            $this->logger->warning('Telegram sign-in refused: the account is blocked.');
            $this->telegram->answerCallbackQuery($callbackId, self::blockedText(), true);

            return;
        }

        $wellFormed = 2 === count($parts) && 1 === preg_match('/^[0-9]{4}$/', $choice);
        $outcome = $wellFormed && TelegramLogin::isAvailable()
            ? $this->login->choose($code, $telegramUserId, $choice)
            : TelegramLogin::CHOICE_GONE;

        if (TelegramLogin::CHOICE_DONE === $outcome) {
            // Answered only to stop the button's spinner; nothing changes.
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        if (TelegramLogin::CHOICE_WRONG === $outcome) {
            $this->telegram->answerCallbackQuery($callbackId, self::cancelledText(), true);
            $this->edit($chatId, $messageId, self::cancelledText());

            return;
        }

        if (TelegramLogin::CHOICE_RIGHT !== $outcome) {
            $this->telegram->answerCallbackQuery($callbackId, self::expiredText(), true);
            $this->edit($chatId, $messageId, self::expiredText());

            return;
        }

        if (null === $person || ! self::canSignIn($person)) {
            // No crew account for this Telegram account yet: the normal
            // sign-up. The browser signs in when the confirmation link links
            // the account.
            $this->telegram->answerCallbackQuery($callbackId);
            $this->edit($chatId, $messageId, __('The code is right.', 'eventcrew'));
            $this->login->rememberForSignUp($code, $telegramUserId);
            $this->onboarding->start($telegramUserId, $chatId, self::displayName($callbackQuery), true);

            return;
        }

        if (! $this->login->approve($code, $telegramUserId, $person->id)) {
            $this->telegram->answerCallbackQuery($callbackId, self::expiredText(), true);
            $this->edit($chatId, $messageId, self::expiredText());

            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);
        // On a phone the crew app is in another app, so the message carries a
        // button back to it; the button also signs in that browser.
        $this->edit(
            $chatId,
            $messageId,
            __('Signed in. You can go back to the crew app.', 'eventcrew'),
            ['inline_keyboard' => [[[
                'text' => '✅ ' . __('Back to the crew app', 'eventcrew'),
                'url' => TelegramLogin::returnUrl($code),
            ]]]]
        );
    }

    /** The same rule the email link and the old Telegram sign-in used: a confirmed, real address. */
    public static function canSignIn(Person $person): bool
    {
        return ! $person->isBanned() && $person->isEmailVerified() && ! Person::isPlaceholderEmail($person->email);
    }

    /**
     * @param array<string, mixed>|null $replyMarkup
     */
    private function edit(int $chatId, int $messageId, string $text, ?array $replyMarkup = null): void
    {
        if (0 !== $chatId && 0 !== $messageId) {
            $this->telegram->editMessageText($chatId, $messageId, $text, $replyMarkup);
        }
    }

    /**
     * The name of the Telegram account that pressed, built as UpdateRouter
     * builds it for /start.
     *
     * @param array<string, mixed> $callbackQuery
     */
    private static function displayName(array $callbackQuery): string
    {
        $from = is_array($callbackQuery['from'] ?? null) ? $callbackQuery['from'] : [];

        return trim((string) ($from['first_name'] ?? '') . ' ' . (string) ($from['last_name'] ?? ''));
    }

    private static function cancelledText(): string
    {
        // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
        return __('That is not the code on the crew app, so this sign-in is cancelled. Press Telegram on the crew app to start again.', 'eventcrew');
    }

    private static function expiredText(): string
    {
        return __('This sign-in link has expired. Press Telegram on the crew app again.', 'eventcrew');
    }

    public static function blockedText(): string
    {
        return __('Your account is blocked. Talk to an organizer.', 'eventcrew');
    }
}

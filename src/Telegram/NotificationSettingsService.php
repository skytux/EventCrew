<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\NotificationPreferences;

/**
 * /notifications - a keyboard menu for a person to choose which notifications
 * reach them, and on which channel. Two toggle buttons per type (Telegram, and
 * email), tapping either flips it and edits the message in place.
 *
 * The same per-type, per-channel preferences the web Notifications tab edits, so
 * a change in one shows in the other.
 */
final class NotificationSettingsService
{
    use GroupBreadcrumb;

    /** Callback data prefix for a toggle: np:<type>:<dm|email>. */
    private const PREFIX = 'np:';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram
    ) {
    }

    public function start(int $telegramUserId, int $chatId, bool $isPrivate = true): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isEmailVerified()) {
            $this->telegram->sendMessage(
                $chatId,
                __('Set yourself up first with /start, then /notifications lets you choose what reaches you.', 'eventcrew')
            );

            return;
        }

        $map = (new NotificationPreferences())->asMap($person);
        $this->telegram->sendMessage($telegramUserId, $this->text(), ['inline_keyboard' => $this->keyboard($map)]);
        $this->sentDmNote($chatId, $isPrivate);
    }

    /**
     * A toggle tap: flip that one type+channel and edit the menu in place.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onToggle(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $telegramUserId = (int) ($callbackQuery['from']['id'] ?? 0);
        [$type, $channel] = $this->parse((string) ($callbackQuery['data'] ?? ''));

        $person = 0 === $telegramUserId ? null : $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || '' === $type) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $map = (new NotificationPreferences())->asMap($person);

        if (! isset($map[$type])) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $map[$type][$channel] = ! $map[$type][$channel];
        $this->people->setNotifyPrefs($person->id, $map);
        $this->telegram->answerCallbackQuery($callbackId, __('Saved.', 'eventcrew'));

        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);

        if (0 !== $chatId && 0 !== $messageId) {
            $this->telegram->editMessageText($chatId, $messageId, $this->text(), ['inline_keyboard' => $this->keyboard($map)]);
        }
    }

    private function text(): string
    {
        return __(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            "🔔 Your notifications — tap to toggle 📱 Telegram or ✉ email.\n\nSignup confirmations and task reminders always send on both.",
            'eventcrew'
        );
    }

    /**
     * @param array<string, array{dm: bool, email: bool}> $map
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    private function keyboard(array $map): array
    {
        $rows = [];

        foreach (NotificationPreferences::labels() as $type => $label) {
            $pref = $map[$type] ?? ['dm' => true, 'email' => true];
            $rows[] = [
                [
                    'text' => $label . ' 📱' . ($pref['dm'] ? '✅' : '❌'),
                    'callback_data' => self::PREFIX . $type . ':dm',
                ],
                [
                    'text' => '✉' . ($pref['email'] ? '✅' : '❌'),
                    'callback_data' => self::PREFIX . $type . ':email',
                ],
            ];
        }

        return $rows;
    }

    /**
     * @return array{0: string, 1: string} type, channel ('dm'|'email')
     */
    private function parse(string $data): array
    {
        if (1 !== preg_match('#^' . preg_quote(self::PREFIX, '#') . '([a-z_]+):(dm|email)$#', $data, $m)) {
            return ['', ''];
        }

        return [$m[1], $m[2]];
    }
}

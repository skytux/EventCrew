<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\NotificationPreferences;

/**
 * 🔔 Notifications (a button under /me) - a keyboard menu for a person to choose which notifications
 * reach them, and on which channel. Three columns per row, like the web tab: the
 * type's name (a no-op cell), a 📱 Telegram toggle and a ✉ email toggle; tapping
 * either toggle flips it and edits the message in place.
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

        if (null === $person) {
            // Not known yet, so the bot may not be able to DM them: the nudge
            // goes back to wherever they asked, in English in the group.
            $this->telegram->sendMessage($chatId, SetupNudge::text(! $isPrivate));

            return;
        }

        if (! $person->isEmailVerified()) {
            // Known, so the refusal goes to the DM, with a breadcrumb in the group.
            $this->telegram->sendMessage($telegramUserId, SetupNudge::text());
            $this->sentDmNote($chatId, $isPrivate);

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
        // The comment sits outside __( so tools/make-pot.php still reads the literal.
        // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
        return __("🔔 Your notifications — tap to toggle 📱 Telegram or ✉ email.\n\nSignup confirmations and task reminders always send on both.", 'eventcrew');
    }

    /**
     * @param array<string, array{dm: bool, email: bool}> $map
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    private function keyboard(array $map): array
    {
        $rows = [];

        // A header row labels the two toggle columns; the name cell above each
        // row's toggles is a no-op, so a tap on the label never changes a pref.
        $rows[] = [
            ['text' => __('Notification', 'eventcrew'), 'callback_data' => self::PREFIX . 'noop'],
            ['text' => '📱', 'callback_data' => self::PREFIX . 'noop'],
            ['text' => '✉', 'callback_data' => self::PREFIX . 'noop'],
        ];

        // The map holds only the rows this person sees (asMap), so the
        // organizer-only rows reach the organizers alone.
        $labels = NotificationPreferences::labels(true);

        foreach ($map as $type => $pref) {
            $label = $labels[$type] ?? '';

            if ('' === $label) {
                continue;
            }

            $rows[] = [
                [
                    'text' => $label,
                    'callback_data' => self::PREFIX . 'noop',
                ],
                [
                    'text' => $pref['dm'] ? '✅' : '❌',
                    'callback_data' => self::PREFIX . $type . ':dm',
                ],
                [
                    'text' => $pref['email'] ? '✅' : '❌',
                    'callback_data' => self::PREFIX . $type . ':email',
                ],
            ];
        }

        // Back to Settings, where 🔔 Notifications is.
        $rows[] = MenuButtons::backRow(MenuButtons::SETTINGS);

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

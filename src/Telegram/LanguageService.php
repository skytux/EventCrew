<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\Language;
use EventCrew\Support\SiteLanguages;

/**
 * Which language the bot writes to a Telegram user in, and the language
 * question (one button per site language) that sets it.
 *
 * The choice lives on the person record. Someone who has no record yet - a
 * newcomer who has not given an email - keeps the choice in a transient keyed
 * by their Telegram id, and it is copied onto the record the first time the
 * record exists, so they are not asked twice.
 *
 * The question itself is asked by UpdateRouter, in front of a private command
 * from someone who has not chosen. The command they sent is kept in a
 * transient and handed back by onSelect(), so the router runs it straight
 * after the choice instead of dropping it.
 */
final class LanguageService
{
    /** Callback data prefix for a choice: lang:<site language code>. */
    public const PREFIX = 'lang:';

    /** The language buttons in one row of the question. */
    private const BUTTONS_PER_ROW = 3;

    private const CHOICE_PREFIX = 'eventcrew_tg_language_';
    private const PENDING_PREFIX = 'eventcrew_tg_language_pending_';

    /** Long enough for a newcomer to get through onboarding. */
    private const CHOICE_TTL = 30 * DAY_IN_SECONDS;

    /** The command waiting behind the question; a stale one is not worth running. */
    private const PENDING_TTL = 15 * MINUTE_IN_SECONDS;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly TelegramClient $telegram
    ) {
    }

    /**
     * The language code this Telegram user chose, or null if they have not
     * chosen yet (which reads as English everywhere).
     */
    public function languageFor(int $telegramUserId): ?string
    {
        if (0 === $telegramUserId) {
            return null;
        }

        $person = $this->people->findByTelegramUserId($telegramUserId);
        $remembered = Language::normalize(get_transient(self::CHOICE_PREFIX . $telegramUserId));

        if (null !== $person && null !== $person->language) {
            return $person->language;
        }

        if (null !== $person && null !== $remembered) {
            // Chosen before the record existed: move it onto the record.
            $this->people->setLanguage($person->id, $remembered);
            delete_transient(self::CHOICE_PREFIX . $telegramUserId);
        }

        return $remembered;
    }

    /**
     * Sends the language question, keeping $pendingMessage (the
     * command the person sent) to be run once they answer. $fromSettings
     * (the 🌐 Language button under Settings) puts Back to Settings under it.
     *
     * @param array<string, mixed>|null $pendingMessage
     */
    public function ask(
        int $telegramUserId,
        int $chatId,
        ?array $pendingMessage = null,
        bool $fromSettings = false
    ): void {
        if (null !== $pendingMessage) {
            set_transient(self::PENDING_PREFIX . $telegramUserId, $pendingMessage, self::PENDING_TTL);
        } else {
            delete_transient(self::PENDING_PREFIX . $telegramUserId);
        }

        // Finnish and English at once and not through gettext: the reader has
        // not chosen yet, so the question has to make sense to either of them.
        // One button per site language, main language first, each in its own
        // name, BUTTONS_PER_ROW to a row.
        $buttons = [];

        foreach (SiteLanguages::choices() as $code => $name) {
            $buttons[] = ['text' => $name, 'callback_data' => self::PREFIX . $code];
        }

        $rows = array_chunk($buttons, self::BUTTONS_PER_ROW);

        if ($fromSettings) {
            $rows[] = MenuButtons::backRow(MenuButtons::SETTINGS);
        }

        $this->telegram->sendMessage($chatId, 'Valitse kieli · Choose your language', ['inline_keyboard' => $rows]);
    }

    /**
     * A tap on a language button: saves it, answers the tap and confirms in the
     * chosen language. Returns the chosen language and the command that was
     * waiting behind the question, if any, for the router to run next.
     *
     * @param array<string, mixed> $callbackQuery
     * @return array{language: ?string, pending: array<string, mixed>|null}
     */
    public function onSelect(array $callbackQuery): array
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $telegramUserId = (int) ($callbackQuery['from']['id'] ?? 0);
        $chatId = (int) ($callbackQuery['message']['chat']['id'] ?? $telegramUserId);
        $language = Language::normalize(substr((string) ($callbackQuery['data'] ?? ''), strlen(self::PREFIX)));

        if (0 === $telegramUserId || null === $language) {
            $this->telegram->answerCallbackQuery($callbackId);

            return ['language' => null, 'pending' => null];
        }

        $this->save($telegramUserId, $language);

        $pending = get_transient(self::PENDING_PREFIX . $telegramUserId);
        delete_transient(self::PENDING_PREFIX . $telegramUserId);

        Language::run($language, function () use ($callbackId, $chatId): void {
            $this->telegram->answerCallbackQuery($callbackId, __('Saved.', 'eventcrew'));
            $this->telegram->sendMessage(
                $chatId,
                __('I will write to you in English. To change it: /settings, 🌐 Change language.', 'eventcrew')
            );
        });

        return ['language' => $language, 'pending' => is_array($pending) ? $pending : null];
    }

    private function save(int $telegramUserId, string $language): void
    {
        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null !== $person) {
            $this->people->setLanguage($person->id, $language);
            delete_transient(self::CHOICE_PREFIX . $telegramUserId);

            return;
        }

        set_transient(self::CHOICE_PREFIX . $telegramUserId, $language, self::CHOICE_TTL);
    }
}

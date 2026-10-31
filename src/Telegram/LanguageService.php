<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\Language;

/**
 * Which language the bot writes to a Telegram user in, and the "Suomi /
 * English" question that sets it.
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
    /** Callback data prefix for a choice: lang:<en|fi>. */
    public const PREFIX = 'lang:';

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
     * 'en' or 'fi' as this Telegram user chose it, or null if they have not
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
     * Sends the "Suomi / English" question, keeping $pendingMessage (the
     * command the person sent) to be run once they answer.
     *
     * @param array<string, mixed>|null $pendingMessage
     */
    public function ask(int $telegramUserId, int $chatId, ?array $pendingMessage = null): void
    {
        if (null !== $pendingMessage) {
            set_transient(self::PENDING_PREFIX . $telegramUserId, $pendingMessage, self::PENDING_TTL);
        } else {
            delete_transient(self::PENDING_PREFIX . $telegramUserId);
        }

        // Both languages at once and not through gettext: the reader has not
        // chosen yet, so the question has to make sense to either of them.
        $this->telegram->sendMessage(
            $chatId,
            'Valitse kieli · Choose your language',
            ['inline_keyboard' => [[
                ['text' => 'Suomi', 'callback_data' => self::PREFIX . Language::FINNISH],
                ['text' => 'English', 'callback_data' => self::PREFIX . Language::ENGLISH],
            ]]]
        );
    }

    /**
     * A tap on Suomi or English: saves it, answers the tap and confirms in the
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
                __('I will write to you in English. Send /language to change it.', 'eventcrew')
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

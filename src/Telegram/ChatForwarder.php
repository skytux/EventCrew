<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\DmBody;
use EventCrew\Support\EmailBody;
use EventCrew\Support\Language;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\NotificationPreferences;

/**
 * Sends a private chat with the bot's language-model helper to the
 * organizers, when the model says the person gives feedback, is unhappy, is
 * very happy or asks for a human (AssistantService, "forward").
 *
 * Every organizer gets the chat of the last 24 hours (ConversationLog), plus
 * the bot's answer to the newest message, by email to their confirmed
 * address, and a short Telegram message saying so.
 *
 * Each organizer chooses in their notification settings (CHAT_FORWARD row)
 * whether the email and the Telegram message come; the Telegram message
 * only says the email was sent, so it needs the email.
 *
 * Nothing is sent when the setting is off, when the person has no confirmed
 * email, or when this person's chat was already sent in the last hour.
 */
final class ChatForwarder
{
    public const KINDS = ['feedback', 'unhappy', 'very_happy', 'human'];

    /** One transient per Telegram user while the hour runs. */
    public const LIMIT_PREFIX = 'eventcrew_ai_forward_';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly Mailer $mailer,
        private readonly TelegramClient $telegram,
        private readonly Logger $logger
    ) {
    }

    /**
     * Sends the chat of Telegram user $telegramUserId to the organizers.
     * $reply is the bot's answer to the newest message, not yet in the
     * ConversationLog. True when at least one organizer was emailed.
     */
    public function forward(int $telegramUserId, string $kind, string $reply): bool
    {
        if (
            ! AssistantSettings::forwardEnabled()
            || 0 === $telegramUserId
            || ! in_array($kind, self::KINDS, true)
            || false !== get_transient(self::LIMIT_PREFIX . $telegramUserId)
        ) {
            return false;
        }

        $person = $this->people->findByTelegramUserId($telegramUserId);

        if (null === $person || ! $person->isEmailVerified() || $person->isAnonymized()) {
            return false;
        }

        $entries = ConversationLog::entries($telegramUserId);
        $entries[] = ['t' => time(), 'w' => ConversationLog::BOT, 'x' => trim($reply)];

        $sent = 0;
        $done = [];

        foreach ($this->people->organizers() as $organizer) {
            if (
                isset($done[$organizer->id])
                || $organizer->isAnonymized()
                || ! $organizer->isEmailVerified()
            ) {
                continue;
            }

            $done[$organizer->id] = true;

            if (Language::forPerson($organizer, fn (): bool => $this->tell($organizer, $person, $kind, $entries))) {
                $sent++;
            }
        }

        if (0 === $sent) {
            $this->logger->warning('Assistant: a chat to forward reached no organizer.');

            return false;
        }

        set_transient(self::LIMIT_PREFIX . $telegramUserId, time(), HOUR_IN_SECONDS);

        return true;
    }

    /**
     * One organizer, in the language they chose: the email, then the
     * Telegram message when the email went out.
     *
     * @param list<array{t: int, w: string, x: string}> $entries
     */
    private function tell(Person $organizer, Person $person, string $kind, array $entries): bool
    {
        // The texts are the Messages page templates (MessageTemplates).
        $context = ['member' => $person, 'kind' => $kind, 'transcript' => $this->transcript($entries, $person->name())];
        $prefs = new NotificationPreferences();

        // The Telegram message only points at the email, so an organizer who
        // turned the email off gets neither.
        if (
            ! $prefs->emailAllowed($organizer, NotificationPreferences::CHAT_FORWARD)
            || ! $this->mailer->toPerson(
                $organizer->id,
                $organizer->email,
                MessageTemplates::subject('chat_forward', $context),
                EmailBody::withGreeting($organizer->displayName, MessageTemplates::body('chat_forward', $context))
            )
        ) {
            return false;
        }

        if ($prefs->dmAllowed($organizer, NotificationPreferences::CHAT_FORWARD)) {
            $this->telegram->sendMessage(
                $organizer->telegramChatId,
                DmBody::frame($organizer, MessageTemplates::body('chat_forward_dm', $context))
            );
        }

        return true;
    }

    /**
     * One line per message, oldest first, with its time in the site's time
     * zone.
     *
     * @param list<array{t: int, w: string, x: string}> $entries
     */
    private function transcript(array $entries, string $name): string
    {
        $lines = [];

        foreach ($entries as $entry) {
            if ('' === $entry['x']) {
                continue;
            }

            $when = function_exists('wp_date')
                ? (string) wp_date('Y-m-d H:i', $entry['t'])
                : gmdate('Y-m-d H:i', $entry['t']);
            $lines[] = sprintf(
                '[%s] %s: %s',
                $when,
                ConversationLog::BOT === $entry['w'] ? __('Bot', 'eventcrew') : $name,
                $entry['x']
            );
        }

        return implode("\n\n", $lines);
    }
}

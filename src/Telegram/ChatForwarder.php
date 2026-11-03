<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\DmBody;
use EventCrew\Support\Language;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;

/**
 * Sends a private chat with the bot's language-model helper to the
 * organizers, when the model says the person gives feedback, is unhappy, is
 * very happy or asks for a human (AssistantService, "forward").
 *
 * Every organizer gets the chat of the last 24 hours (ConversationLog), plus
 * the bot's answer to the newest message, by email to their confirmed
 * address, and a short Telegram message saying so.
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
        $name = $person->name();
        $body = sprintf(
            /* translators: 1: the person's name, 2: their email address */
            __('%1$s (%2$s) wrote to the bot in a private chat. Here is that chat, oldest first.', 'eventcrew'),
            $name,
            $person->email
        ) . "\n\n" . $this->transcript($entries, $name);

        if (! $this->mailer->toPerson($organizer->id, $organizer->email, $this->subject($kind, $name), $body)) {
            return false;
        }

        if (null !== $organizer->telegramChatId && $organizer->wantsBotDms()) {
            $this->telegram->sendMessage($organizer->telegramChatId, DmBody::frame($organizer, sprintf(
                /* translators: %s: the person's name */
                __('I emailed you a transcript of my private chat with %s.', 'eventcrew'),
                $name
            )));
        }

        return true;
    }

    private function subject(string $kind, string $name): string
    {
        return match ($kind) {
            /* translators: %s: the person's name */
            'unhappy' => sprintf(__('Bot chat: %s is unhappy', 'eventcrew'), $name),
            /* translators: %s: the person's name */
            'very_happy' => sprintf(__('Bot chat: %s is very happy', 'eventcrew'), $name),
            /* translators: %s: the person's name */
            'human' => sprintf(__('Bot chat: %s wants a human', 'eventcrew'), $name),
            /* translators: %s: the person's name */
            default => sprintf(__('Bot chat: feedback from %s', 'eventcrew'), $name),
        };
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

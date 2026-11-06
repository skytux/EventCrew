<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Telegram\MenuButtons;

/**
 * Draws the actions a message kind declares (MessageTemplates::actions(),
 * MessageAction) for its channel: the DM's inline keyboard, or the email's
 * buttons and "Open in Telegram" links. The senders and the preview both
 * call this, so the two versions of a message and its preview cannot drift.
 */
final class MessageActions
{
    /** The longest deep link start payload Telegram takes. */
    public const PAYLOAD_MAX = 64;

    /**
     * The verbs of the start payloads <verb>-<id> that open one screen in
     * the bot (startUrl() with an id): a task with its Sign up button, a
     * takeover request with its two answers, a night to spend a free-entry
     * credit on, a person's role buttons and a newcomer's OK and No
     * (organizers only).
     */
    public const SCREEN_VERBS = ['task', 'tov', 'tkt', 'who', 'nco', 'door'];

    /**
     * The actions of $id with $context, [] for a kind with none.
     *
     * @param array<string, mixed> $context
     * @return list<MessageAction>
     */
    public static function of(string $id, array $context, Mailer $mailer): array
    {
        $template = MessageTemplates::find($id);

        return null === $template ? [] : $template->actions($context, $mailer);
    }

    /**
     * The DM's inline keyboard rows, one button per row: the callback, else
     * the bot verb's button, else the web link. An action with none of them
     * is left out.
     *
     * @param list<MessageAction> $actions
     * @return list<list<array<string, string>>>
     */
    public static function keyboard(array $actions): array
    {
        $rows = [];

        foreach ($actions as $action) {
            $data = '' !== $action->callback ? $action->callback : MenuButtons::forVerb($action->verb);

            if ('' !== $data) {
                $rows[] = [['text' => $action->dmText(), 'callback_data' => $data]];
                continue;
            }

            $url = $action->webUrl();

            if ('' !== $url) {
                $rows[] = [['text' => $action->dmText(), 'url' => $url]];
            }
        }

        return $rows;
    }

    /**
     * The DM's reply markup, or null when there are no buttons.
     *
     * @param list<MessageAction> $actions
     * @return array{inline_keyboard: list<list<array<string, string>>>}|null
     */
    public static function replyMarkup(array $actions): ?array
    {
        $rows = self::keyboard($actions);

        return [] === $rows ? null : ['inline_keyboard' => $rows];
    }

    /**
     * The email's actions for Mailer::toPerson(): each web link as a button,
     * then each bot verb as an "Open in Telegram" link (style 'telegram',
     * drawn as a line of links under the buttons, EmailBody), once per verb
     * (or own start payload), when the bot is set up.
     *
     * @param list<MessageAction> $actions
     * @return list<array{label: string, url: string, style?: string}>
     */
    public static function email(array $actions): array
    {
        $buttons = [];
        $links = [];

        foreach ($actions as $action) {
            $url = $action->webUrl();

            if ('' !== $url) {
                $buttons[] = ['label' => $action->label, 'url' => $url];
            }

            $key = '' !== $action->start ? $action->start : $action->verb;
            $start = '' !== $action->start ? self::payloadUrl($action->start) : self::startUrl($action->verb);

            if ('' !== $start && ! isset($links[$key])) {
                $links[$key] = [
                    'label' => $action->label,
                    'url' => $start,
                    'style' => EmailBody::STYLE_TELEGRAM,
                ];
            }
        }

        return [...$buttons, ...array_values($links)];
    }

    /**
     * The deep link that opens the bot's private chat on $verb, or '' when
     * the bot is not set up or $verb is not a bot verb. The payload is the
     * verb alone: never a token or anything personal.
     *
     * With $id, the payload is <verb>-<id> (task-12, tov-45, tkt-20261114)
     * for one of SCREEN_VERBS: the bot shows that one thing with its
     * buttons (UpdateRouter). Opening the link never does anything.
     */
    public static function startUrl(string $verb, int|string $id = ''): string
    {
        if ('' === (string) $id) {
            return '' === MenuButtons::forVerb($verb) ? '' : self::payloadUrl($verb);
        }

        $payload = $verb . '-' . $id;

        return null === self::screenPayload($payload) ? '' : self::payloadUrl($payload);
    }

    /**
     * The verb and the id of a start payload made by startUrl() with an id,
     * like ['tov', '45'], or null for any other payload.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function screenPayload(string $payload): ?array
    {
        if (1 !== preg_match('/^([a-z]{2,8})-([1-9]\d{0,18})$/', $payload, $matches)) {
            return null;
        }

        return in_array($matches[1], self::SCREEN_VERBS, true) ? [$matches[1], $matches[2]] : null;
    }

    /**
     * The deep link that opens the bot's private chat with $payload, or ''
     * when the bot is not set up or $payload is not one Telegram takes. The
     * payload is never a token or anything personal.
     */
    public static function payloadUrl(string $payload): string
    {
        $username = TelegramLogin::botUsername();

        if ('' === $username || ! self::isPayload($payload)) {
            return '';
        }

        return 'https://t.me/' . $username . '?start=' . $payload;
    }

    /** True for a start payload Telegram takes: 1 to 64 of A-Z, a-z, 0-9, _ and -. */
    public static function isPayload(string $payload): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_-]{1,' . self::PAYLOAD_MAX . '}$/', $payload);
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Support;

use Closure;

/**
 * One kind of message the plugin sends - the "You're signed up" email, the
 * reminder DM - as the Messages page in wp-admin shows it (MessageTemplates
 * lists them all).
 *
 * - id: the kind's key, also part of its option name. Lowercase letters,
 *   digits and underscores.
 * - label: its name on the Messages page.
 * - channel: CHANNEL_EMAIL or CHANNEL_TELEGRAM. A Telegram message has no
 *   subject.
 * - description: one line saying when it is sent.
 * - subject / body: the built-in text, in the language being composed in
 *   (Language::run()), with {placeholders} in it. Each is called with the
 *   context the message is composed with, or with [] on the Messages page; a
 *   text that depends on a number (one credit, two credits) reads it from
 *   the context.
 * - placeholders: name => [description, resolver, required]. The description
 *   says what the placeholder becomes; the resolver turns the context into
 *   that text. A required placeholder (optional key, false when missing)
 *   must stay in the text: the Messages page refuses to save a text without
 *   it (missingRequired()).
 * - wired: whether the code that sends the message reads this template.
 *   False for a kind that is listed but still sends its built-in text; the
 *   Messages page says so.
 * - preview: how the kind's sender wraps the text, for the Preview link on
 *   the Messages page (MessagePreview). Every key is optional; a kind with
 *   none is an email sent by Mailer::toPerson() with no buttons, or a DM
 *   framed by DmBody::frame() without the sign-off and with no buttons.
 *   - send: PREVIEW_PERSON (default), PREVIEW_ADDRESS (an address nobody
 *     has confirmed, Mailer::toUnconfirmedAddress()), or the composer of a
 *     mail with its own layout: PREVIEW_TICKET, PREVIEW_TICKET_CANCELLED,
 *     PREVIEW_FOLLOWUP_CAME, PREVIEW_FOLLOWUP_MISSED, PREVIEW_NEWSLETTER.
 *   - greeting: true when the sender puts "Hi Name," in front of the email
 *     text (EmailBody::withGreeting()).
 *   - frame: for a DM, FRAME_PLAIN (default), FRAME_SIGNED (with the
 *     sign-off) or FRAME_NONE (sent as the text alone).
 *   - dm_format: true when the sender passes the DM text through
 *     EmailBody::forDm().
 *   - buttons: the labels of the email's buttons or the DM's buttons, in
 *     order, or a closure that makes them from the sample context. In a
 *     preview they lead nowhere.
 *   - context: a closure giving the sample values this kind needs on top of
 *     the shared sample context (MessagePreview::sampleContext()).
 * - actions: what the message offers to do (MessageAction), declared once
 *   for the email and the DM of a pair (MessageTemplates::actions()). The
 *   sender draws them with MessageActions, and so does the preview; a kind
 *   with actions has no hand-kept preview buttons.
 *
 * The stored text and the per-template settings live in MessageTemplates.
 */
final class MessageTemplate
{
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_TELEGRAM = 'telegram';

    public const PREVIEW_PERSON = 'person';
    public const PREVIEW_ADDRESS = 'address';
    public const PREVIEW_TICKET = 'ticket';
    public const PREVIEW_TICKET_CANCELLED = 'ticket_cancelled';
    public const PREVIEW_FOLLOWUP_CAME = 'followup_came';
    public const PREVIEW_FOLLOWUP_MISSED = 'followup_missed';
    public const PREVIEW_NEWSLETTER = 'newsletter';

    public const FRAME_PLAIN = 'plain';
    public const FRAME_SIGNED = 'signed';
    public const FRAME_NONE = 'none';

    /**
     * @param array<string, array{
     *     description: string, resolve: Closure(array<string, mixed>): string, required?: bool
     * }> $placeholders
     * @param (Closure(array<string, mixed>): string)|null $subject Null for a Telegram message.
     * @param Closure(array<string, mixed>): string $body
     * @param array{
     *     send?: string, greeting?: bool, frame?: string, dm_format?: bool,
     *     buttons?: list<string>|Closure(array<string, mixed>): list<string>,
     *     context?: Closure(): array<string, mixed>
     * } $preview
     * @param (Closure(array<string, mixed>, Mailer): list<MessageAction>)|null $actions
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $channel,
        public readonly string $description,
        public readonly ?Closure $subject,
        public readonly Closure $body,
        public readonly array $placeholders = [],
        public readonly bool $wired = true,
        public readonly array $preview = [],
        public readonly ?Closure $actions = null
    ) {
    }

    public function hasActions(): bool
    {
        return null !== $this->actions;
    }

    /**
     * What the message offers to do, made from the context it is sent with.
     * $mailer makes the web links (sign-in links, ticket links); in a preview
     * it is the preview mailer, whose links lead nowhere.
     *
     * @param array<string, mixed> $context
     * @return list<MessageAction>
     */
    public function actions(array $context, Mailer $mailer): array
    {
        return null === $this->actions ? [] : array_values(($this->actions)($context, $mailer));
    }

    public function hasSubject(): bool
    {
        return null !== $this->subject;
    }

    /**
     * The built-in subject in the current language, placeholders not filled.
     * '' for a Telegram message.
     *
     * @param array<string, mixed> $context
     */
    public function defaultSubject(array $context = []): string
    {
        return null === $this->subject ? '' : (string) ($this->subject)($context);
    }

    /**
     * The built-in body in the current language, placeholders not filled.
     *
     * @param array<string, mixed> $context
     */
    public function defaultBody(array $context = []): string
    {
        return (string) ($this->body)($context);
    }

    /**
     * $text with every {placeholder} of this kind replaced by what its
     * resolver makes of $context. A placeholder the text does not use is not
     * resolved. An unknown {name} stays as it is.
     *
     * @param array<string, mixed> $context
     */
    public function fill(string $text, array $context): string
    {
        $pairs = [];

        foreach ($this->placeholders as $name => $placeholder) {
            $tag = '{' . $name . '}';

            if (str_contains($text, $tag)) {
                $pairs[$tag] = (string) ($placeholder['resolve'])($context);
            }
        }

        return [] === $pairs ? $text : strtr($text, $pairs);
    }

    /**
     * What each placeholder becomes, for the Messages page: '{name}' =>
     * description.
     *
     * @return array<string, string>
     */
    public function placeholderList(): array
    {
        $list = [];

        foreach ($this->placeholders as $name => $placeholder) {
            $list['{' . $name . '}'] = $placeholder['description'];
        }

        return $list;
    }

    /**
     * The required placeholders, as '{name}'.
     *
     * @return list<string>
     */
    public function requiredPlaceholders(): array
    {
        $list = [];

        foreach ($this->placeholders as $name => $placeholder) {
            if (true === ($placeholder['required'] ?? false)) {
                $list[] = '{' . $name . '}';
            }
        }

        return $list;
    }

    /**
     * The required placeholders, as '{name}', that $text does not contain.
     * An empty text is not checked: it means the built-in text is used.
     *
     * @return list<string>
     */
    public function missingRequired(string $text): array
    {
        if ('' === trim($text)) {
            return [];
        }

        return array_values(array_filter(
            $this->requiredPlaceholders(),
            static fn (string $tag): bool => ! str_contains($text, $tag)
        ));
    }
}

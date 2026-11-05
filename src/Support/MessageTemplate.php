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
 * - placeholders: name => [description, resolver]. The description says what
 *   the placeholder becomes; the resolver turns the context into that text.
 * - wired: whether the code that sends the message reads this template.
 *   False for a kind that is listed but still sends its built-in text; the
 *   Messages page says so.
 *
 * The stored text and the per-template settings live in MessageTemplates.
 */
final class MessageTemplate
{
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_TELEGRAM = 'telegram';

    /**
     * @param array<string, array{description: string, resolve: Closure(array<string, mixed>): string}> $placeholders
     * @param (Closure(array<string, mixed>): string)|null $subject Null for a Telegram message.
     * @param Closure(array<string, mixed>): string $body
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $channel,
        public readonly string $description,
        public readonly ?Closure $subject,
        public readonly Closure $body,
        public readonly array $placeholders = [],
        public readonly bool $wired = true
    ) {
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
}

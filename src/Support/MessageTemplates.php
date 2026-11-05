<?php

declare(strict_types=1);

namespace EventCrew\Support;

use Closure;
use EventCrew\Models\Person;
use EventCrew\Models\Task;

/**
 * Every message template (MessageTemplate) and the texts organizers saved for
 * them on the Messages page in wp-admin.
 *
 * One option per kind, OPTION_PREFIX . id, holds:
 * - subject: a TranslatedText value, when the subject was changed;
 * - body: a TranslatedText value, when the text was changed;
 * - settings: an array for the per-template settings (the AI tick and
 *   prompt, the hybrid tick). This class keeps it and never reads it; "Reset
 *   to built-in text" leaves it as it is.
 * A subject or body that is not stored is the built-in text.
 *
 * The code that sends a message calls subject() and body() with its context
 * (the person, the task, ...), inside Language::run() for the reader's
 * language. They answer with the stored text in that language, or the
 * built-in text, with the placeholders filled.
 */
final class MessageTemplates
{
    /** The option of one kind is this followed by its id. */
    public const OPTION_PREFIX = 'eventcrew_message_template_';

    public const SUBJECT = 'subject';
    public const BODY = 'body';

    /** The longest subject kept, in characters. */
    public const SUBJECT_MAX_LENGTH = 200;

    /** The longest text kept, in characters. Telegram takes 4,096 at most. */
    public const BODY_MAX_LENGTH = 4000;

    /** The posted name of the Messages page form: message[subject], message[body]. */
    public const POST_NAME = 'message';

    /**
     * Runs on the posted settings of one template when the Messages page saves
     * it, so the per-template settings (the AI tick, the hybrid tick) can be
     * read from the form: apply_filters(SETTINGS_FILTER, $settings, $id, $posted).
     */
    public const SETTINGS_FILTER = 'eventcrew/message_template_settings';

    /**
     * Runs inside each template's form on the Messages page, after the text,
     * so other parts can print their fields: do_action(FIELDS_ACTION,
     * $template, $settings).
     */
    public const FIELDS_ACTION = 'eventcrew/message_template_fields';

    public function __construct(
        private readonly TranslatedText $translator
    ) {
    }

    /**
     * Every kind, in the order the Messages page lists them. Built on each
     * call, so the labels and built-in texts are in the current language.
     *
     * @return array<string, MessageTemplate>
     */
    public static function all(): array
    {
        $list = [];

        foreach (self::definitions() as $template) {
            $list[$template->id] = $template;
        }

        return $list;
    }

    public static function find(string $id): ?MessageTemplate
    {
        return self::all()[$id] ?? null;
    }

    /**
     * The subject of $id for the language being composed in, with the
     * placeholders filled from $context. '' for an unknown kind or a Telegram
     * message.
     *
     * @param array<string, mixed> $context
     */
    public static function subject(string $id, array $context = []): string
    {
        return self::compose($id, self::SUBJECT, $context);
    }

    /**
     * The text of $id for the language being composed in, with the
     * placeholders filled from $context. '' for an unknown kind.
     *
     * @param array<string, mixed> $context
     */
    public static function body(string $id, array $context = []): string
    {
        return self::compose($id, self::BODY, $context);
    }

    /**
     * $part of $id before the placeholders are filled: the stored text in the
     * current language (its source when that language is empty, as
     * TranslatedText::text() does), or the built-in one when none is stored
     * or both are empty.
     *
     * @param array<string, mixed> $context
     */
    public static function raw(string $id, string $part, array $context = []): string
    {
        $template = self::find($id);

        if (null === $template || (self::SUBJECT === $part && ! $template->hasSubject())) {
            return '';
        }

        $stored = self::stored($id)[$part];

        if (null !== $stored) {
            $text = TranslatedText::text($stored, Language::current() ?? Language::ENGLISH);

            if ('' !== trim($text)) {
                return $text;
            }
        }

        return self::SUBJECT === $part ? $template->defaultSubject($context) : $template->defaultBody($context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function compose(string $id, string $part, array $context): string
    {
        $template = self::find($id);

        return null === $template ? '' : $template->fill(self::raw($id, $part, $context), $context);
    }

    /**
     * What is stored for $id: the subject and body values (null when not
     * changed) and the settings.
     *
     * @return array{subject: ?array, body: ?array, settings: array<string, mixed>}
     */
    public static function stored(string $id): array
    {
        $raw = get_option(self::OPTION_PREFIX . $id, []);
        $raw = is_array($raw) ? $raw : [];
        $part = static function (mixed $value): ?array {
            if (! is_array($value)) {
                return null;
            }

            $value = TranslatedText::normalize($value);

            return '' === $value['source'] . implode('', $value['texts']) ? null : $value;
        };

        return [
            self::SUBJECT => $part($raw[self::SUBJECT] ?? null),
            self::BODY => $part($raw[self::BODY] ?? null),
            'settings' => is_array($raw['settings'] ?? null) ? $raw['settings'] : [],
        ];
    }

    /** Whether the subject or the text of $id was changed from the built-in one. */
    public static function isChanged(string $id): bool
    {
        $stored = self::stored($id);

        return null !== $stored[self::SUBJECT] || null !== $stored[self::BODY];
    }

    /**
     * $part of $id as the Messages page edits it: the stored value, or the
     * built-in text as a value (English as the source, each site language's
     * own text, nothing edited by hand).
     *
     * @return array{source: string, lang: string, detected: bool, texts: array<string, string>, manual: array<string, bool>}
     */
    public static function value(string $id, string $part): array
    {
        return self::stored($id)[$part] ?? self::defaultValue($id, $part);
    }

    /**
     * The built-in text of $part of $id as a TranslatedText value: English
     * as the source, and each site language's own built-in text. A site
     * language the plugin has no translation file for gets '', so it shows
     * as "not translated yet" on the Translations page and is translated
     * there or on the next save.
     *
     * @return array{source: string, lang: string, detected: bool, texts: array<string, string>, manual: array<string, bool>}
     */
    public static function defaultValue(string $id, string $part): array
    {
        $value = TranslatedText::blank();
        $text = static function () use ($id, $part): string {
            $template = self::find($id);

            if (null === $template) {
                return '';
            }

            return self::SUBJECT === $part ? $template->defaultSubject() : $template->defaultBody();
        };

        $value['source'] = (string) Language::run(Language::ENGLISH, $text);
        $value['lang'] = Language::ENGLISH;

        foreach (TranslatedText::languages() as $lang) {
            $value['texts'][$lang] = SiteLanguages::hasCatalog($lang) ? (string) Language::run($lang, $text) : '';
        }

        return $value;
    }

    /**
     * Stores $value as $part of $id, keeping the other part and the
     * settings. A value that is the built-in text again, or empty, is not
     * stored, so the built-in text applies in every language.
     *
     * @param array<string, mixed> $value A TranslatedText value.
     */
    public static function storePart(string $id, string $part, array $value): void
    {
        if (null === self::find($id) || ! in_array($part, [self::SUBJECT, self::BODY], true)) {
            return;
        }

        $value = TranslatedText::normalize($value);
        $stored = self::stored($id);
        $stored[$part] = $value === self::defaultValue($id, $part)
            || '' === $value['source'] . implode('', $value['texts'])
            ? null
            : $value;
        self::write($id, $stored);
    }

    /**
     * The subject (emails) and the text of every kind as rows of the
     * wp-admin Translations page (TranslationRegistry), so they are
     * translated into every site language like the other texts.
     *
     * @return list<TranslationRow>
     */
    public static function translationRows(): array
    {
        $rows = [];

        foreach (self::all() as $id => $template) {
            $group = MessageTemplate::CHANNEL_EMAIL === $template->channel
                ? TranslationRow::GROUP_EMAILS
                : TranslationRow::GROUP_TELEGRAM;
            $parts = $template->hasSubject() ? [self::SUBJECT, self::BODY] : [self::BODY];

            foreach ($parts as $part) {
                $rows[] = new TranslationRow(
                    'message.' . $id . '.' . $part,
                    sprintf(
                        self::SUBJECT === $part
                            /* translators: %s: the name of a message on the Messages page */
                            ? __('%s: subject', 'eventcrew')
                            /* translators: %s: the name of a message on the Messages page */
                            : __('%s: text', 'eventcrew'),
                        $template->label
                    ),
                    $group,
                    self::value($id, $part),
                    static function (array $value) use ($id, $part): void {
                        self::storePart($id, $part, $value);
                    },
                    self::SUBJECT === $part ? self::SUBJECT_MAX_LENGTH : self::BODY_MAX_LENGTH,
                    self::BODY === $part
                );
            }
        }

        return $rows;
    }

    /** The per-template settings of $id (the AI tick, the hybrid tick, ...). */
    public static function settings(string $id): array
    {
        return self::stored($id)['settings'];
    }

    /**
     * Saves the per-template settings of $id, keeping its texts.
     *
     * @param array<string, mixed> $settings
     */
    public static function saveSettings(string $id, array $settings): void
    {
        $stored = self::stored($id);
        $stored['settings'] = $settings;
        self::write($id, $stored);
    }

    /**
     * Saves the Messages page form of one template: the subject and the text,
     * translated where needed (TranslatedText::update()), then the settings
     * through SETTINGS_FILTER. A subject or text that is the built-in one
     * again, or empty, is not stored, so the built-in text applies in every
     * language. A part that was not posted is left as it is.
     *
     * @param mixed $posted The raw $_POST[POST_NAME].
     * @return string The translation error kind ('' when none), for TranslatedText::errorNotice().
     */
    public function save(string $id, mixed $posted): string
    {
        $template = self::find($id);

        if (null === $template) {
            return '';
        }

        $posted = is_array($posted) ? $posted : [];
        $stored = self::stored($id);
        $error = '';
        $parts = $template->hasSubject() ? [self::SUBJECT, self::BODY] : [self::BODY];

        foreach ($parts as $part) {
            $multiline = self::BODY === $part;
            $input = TranslatedText::fromPost($posted[$part] ?? null, $multiline);

            if (null === $input) {
                continue;
            }

            $result = $this->translator->update(
                self::value($id, $part),
                $input['source'],
                $input['texts'],
                $input['again'],
                $multiline ? self::BODY_MAX_LENGTH : self::SUBJECT_MAX_LENGTH,
                $multiline,
                $input['lang']
            );
            $value = $result['value'];

            $stored[$part] = $value === self::defaultValue($id, $part)
                || '' === $value['source'] . implode('', $value['texts'])
                ? null
                : $value;

            if ('' === $error && '' !== $result['error']) {
                $error = $result['error'];
            }
        }

        $settings = apply_filters(self::SETTINGS_FILTER, $stored['settings'], $id, $posted);
        $stored['settings'] = is_array($settings) ? $settings : $stored['settings'];

        self::write($id, $stored);

        return $error;
    }

    /** "Reset to built-in text": forgets the saved subject and text of $id, keeps its settings. */
    public static function reset(string $id): void
    {
        $stored = self::stored($id);
        $stored[self::SUBJECT] = null;
        $stored[self::BODY] = null;
        self::write($id, $stored);
    }

    /**
     * @param array{subject: ?array, body: ?array, settings: array<string, mixed>} $stored
     */
    private static function write(string $id, array $stored): void
    {
        $option = self::OPTION_PREFIX . $id;
        $keep = array_filter(
            $stored,
            static fn (mixed $value): bool => null !== $value && [] !== $value
        );

        if ([] === $keep) {
            delete_option($option);

            return;
        }

        update_option($option, $keep, false);
    }

    /**
     * The placeholders in a gettext string written for sprintf(): each
     * %1$s / %s / %2$d in $map becomes its {name}, and %% becomes %. The
     * built-in texts are made this way, so they keep their translations.
     *
     * @param array<string, string> $map
     */
    public static function tag(string $text, array $map): string
    {
        return strtr($text, $map + ['%%' => '%']);
    }

    /**
     * The kinds. Each built-in text is the wording the message had before it
     * could be changed, made from the same gettext strings.
     *
     * Not every message the plugin sends is here yet: see the Messages page
     * list in the 2026-11-05 report. TicketMail and the newsletter are
     * listed but do not read their template yet (wired false).
     *
     * @return list<MessageTemplate>
     */
    private static function definitions(): array
    {
        $email = MessageTemplate::CHANNEL_EMAIL;
        $telegram = MessageTemplate::CHANNEL_TELEGRAM;

        $name = self::placeholder(
            __('The first name of the person the message is for.', 'eventcrew'),
            static fn (array $c): string => ($c['person'] ?? null) instanceof Person ? $c['person']->name() : ''
        );
        $role = self::placeholder(
            __('The name of the task, like Door.', 'eventcrew'),
            static fn (array $c): string => ($c['task'] ?? null) instanceof Task ? $c['task']->roleLabel() : ''
        );
        $event = self::placeholder(
            __('The name of the event.', 'eventcrew'),
            static fn (array $c): string => ($c['task'] ?? null) instanceof Task
                ? $c['task']->eventName()
                : (string) ($c['event'] ?? '')
        );
        $when = self::placeholder(
            __('The day of the task and its time, like Fri 14 Nov 18:00–20:00.', 'eventcrew'),
            static fn (array $c): string => ($c['task'] ?? null) instanceof Task ? self::when($c['task']) : ''
        );
        $standingTask = self::placeholder(
            __('The task, the event and the date, like Door at Dance Night, 2026-11-14 18:00–20:00.', 'eventcrew'),
            static fn (array $c): string => ($c['task'] ?? null) instanceof Task ? self::standingTask($c['task']) : ''
        );
        $credits = self::placeholder(
            __('How many free-entry credits were given.', 'eventcrew'),
            static fn (array $c): string => (string) max(1, (int) ($c['credits'] ?? 1))
        );
        $date = self::placeholder(
            __('The date of the event, like 14.11.2026.', 'eventcrew'),
            static fn (array $c): string => (string) ($c['date'] ?? '')
        );
        $firstName = self::placeholder(
            __('The first name of the ticket holder. Empty when it is not known.', 'eventcrew'),
            static fn (array $c): string => (string) ($c['first_name'] ?? '')
        );
        $count = static fn (array $c): int => max(1, (int) ($c['credits'] ?? 2));

        $spot = static fn (): string => self::tag(
            /* translators: 1: role, 2: event, 3: date/time */
            __('A spot just opened: %1$s at %2$s, %3$s. Can you step in? Sign up on the board.', 'eventcrew'),
            ['%1$s' => '{role}', '%2$s' => '{event}', '%3$s' => '{when}']
        );

        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literals; splitting them breaks extraction.
        return [
            new MessageTemplate(
                'reminder',
                __('Task reminder (email)', 'eventcrew'),
                $email,
                __('Sent the day before a task to each person signed up for it.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: 1: role, 2: event */
                    __('Reminder: %1$s for %2$s', 'eventcrew'),
                    ['%1$s' => '{role}', '%2$s' => '{event}']
                ),
                static fn (): string => self::tag(
                    /* translators: 1: name, 2: role, 3: event, 4: date/time */
                    __("Hi %1\$s,\n\nJust a reminder that you're on %2\$s at %3\$s, %4\$s.\n\nCan't make it? Open the bot and tap the task to cancel, or ask someone to take it over with 🔁 Take over a task under /me in the bot.", 'eventcrew'),
                    ['%1$s' => '{name}', '%2$s' => '{role}', '%3$s' => '{event}', '%4$s' => '{when}']
                ),
                ['name' => $name, 'role' => $role, 'event' => $event, 'when' => $when]
            ),
            new MessageTemplate(
                'reminder_dm',
                __('Task reminder (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent the day before a task to each person signed up for it who uses the bot.', 'eventcrew'),
                null,
                static fn (): string => self::tag(
                    /* translators: 1: role, 2: event, 3: date/time */
                    __('⏰ Reminder: you’re on %1$s at %2$s, %3$s. See you there!', 'eventcrew'),
                    ['%1$s' => '{role}', '%2$s' => '{event}', '%3$s' => '{when}']
                ),
                ['role' => $role, 'event' => $event, 'when' => $when]
            ),
            new MessageTemplate(
                'slot_freed',
                __('A spot opened (email)', 'eventcrew'),
                $email,
                __('Sent to the crew when someone cancels a task late and its place is free again.', 'eventcrew'),
                static fn (): string => __('A spot just opened up', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: 1: name, 2: the opening line */
                    __("Hi %1\$s,\n\n%2\$s", 'eventcrew'),
                    ['%1$s' => '{name}', '%2$s' => $spot()]
                ),
                ['name' => $name, 'role' => $role, 'event' => $event, 'when' => $when]
            ),
            new MessageTemplate(
                'slot_freed_dm',
                __('A spot opened (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to the crew when someone cancels a task late and its place is free again.', 'eventcrew'),
                null,
                static fn (): string => '⚡ ' . $spot(),
                ['role' => $role, 'event' => $event, 'when' => $when]
            ),
            new MessageTemplate(
                'task_cancelled',
                __('Task cancelled by the organizers (email)', 'eventcrew'),
                $email,
                __('Sent to each person signed up for a task when an organizer deletes the task. Sent in Finnish and English.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: 1: role, 2: event */
                    __('Task cancelled: %1$s for %2$s', 'eventcrew'),
                    ['%1$s' => '{role}', '%2$s' => '{event}']
                ),
                static fn (): string => self::tag(
                    /* translators: 1: name, 2: role, 3: event, 4: date/time */
                    __("Hi %1\$s,\n\nThe organizers have cancelled the task %2\$s at %3\$s, %4\$s. You are no longer signed up for it, and its ticket no longer works at the door. This does not count against your standing.", 'eventcrew'),
                    ['%1$s' => '{name}', '%2$s' => '{role}', '%3$s' => '{event}', '%4$s' => '{when}']
                ),
                ['name' => $name, 'role' => $role, 'event' => $event, 'when' => $when]
            ),
            new MessageTemplate(
                'no_show',
                __('Marked as a no-show (email)', 'eventcrew'),
                $email,
                __('Sent a day after an organizer marks a person as a no-show.', 'eventcrew'),
                static fn (): string => __('You were marked as a no-show', 'eventcrew'),
                static fn (): string => "Hi {name},\n\n" . self::tag(
                    __("You were marked as a no-show for %2\$s.\n\nNo-shows count against your standing. If this is a mistake, contact an organizer and they can correct it.", 'eventcrew'),
                    ['%2$s' => '{task}']
                ),
                ['name' => $name, 'task' => $standingTask]
            ),
            new MessageTemplate(
                'no_show_dm',
                __('Marked as a no-show (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent a day after an organizer marks a person as a no-show.', 'eventcrew'),
                null,
                static fn (): string => self::tag(
                    /* translators: %s: role at event, date */
                    __('⚠️ You were marked as a no-show for %s. No-shows count against your standing. If that’s a mistake, tell an organizer.', 'eventcrew'),
                    ['%s' => '{task}']
                ),
                ['task' => $standingTask]
            ),
            new MessageTemplate(
                'late_cancel',
                __('Late cancellation recorded (email)', 'eventcrew'),
                $email,
                __('Sent a day after a late cancellation is recorded.', 'eventcrew'),
                static fn (): string => __('Your late cancellation was recorded', 'eventcrew'),
                static fn (): string => "Hi {name},\n\n" . self::tag(
                    __("Your late cancellation for %2\$s has been recorded.\n\nIt counts against your standing. More notice next time, or finding a replacement with 🔁 Take over a task under /me, keeps it clear.", 'eventcrew'),
                    ['%2$s' => '{task}']
                ),
                ['name' => $name, 'task' => $standingTask]
            ),
            new MessageTemplate(
                'late_cancel_dm',
                __('Late cancellation recorded (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent a day after a late cancellation is recorded.', 'eventcrew'),
                null,
                static fn (): string => self::tag(
                    /* translators: %s: role at event, date */
                    __('⚠️ Your late cancellation for %s is recorded. It counts against your standing; more notice keeps it clear next time.', 'eventcrew'),
                    ['%s' => '{task}']
                ),
                ['task' => $standingTask]
            ),
            new MessageTemplate(
                'credit_granted',
                __('Free entry given (email)', 'eventcrew'),
                $email,
                __('Sent when an organizer gives a person free-entry credits.', 'eventcrew'),
                static fn (): string => __('You’ve been given free entry', 'eventcrew'),
                static fn (array $c): string => self::tag(
                    /* translators: 1: the person's name, 2: number of credits */
                    _n(
                        "Hi %1\$s,\n\nAn organizer has given you a free-entry credit. You can spend it on any upcoming event — open the EventCrew bot, send /me and tap 🎟 Free-entry ticket, or use your Crew app, to get your free ticket.",
                        "Hi %1\$s,\n\nAn organizer has given you %2\$d free-entry credits. You can spend them on upcoming events — open the EventCrew bot, send /me and tap 🎟 Free-entry ticket, or use your Crew app, to get your free ticket.",
                        $count($c),
                        'eventcrew'
                    ),
                    ['%1$s' => '{name}', '%2$d' => '{credits}']
                ),
                ['name' => $name, 'credits' => $credits]
            ),
            new MessageTemplate(
                'credit_granted_dm',
                __('Free entry given (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent when an organizer gives a person free-entry credits.', 'eventcrew'),
                null,
                static fn (array $c): string => self::tag(
                    /* translators: %d: number of free-entry credits granted */
                    _n(
                        '🎁 You’ve been given %d free-entry credit. Send /me and tap 🎟 Free-entry ticket to spend it on an upcoming event.',
                        '🎁 You’ve been given %d free-entry credits. Send /me and tap 🎟 Free-entry ticket to spend them on upcoming events.',
                        $count($c),
                        'eventcrew'
                    ),
                    ['%d' => '{credits}']
                ),
                ['credits' => $credits]
            ),
            new MessageTemplate(
                'free_ticket_dm',
                __('Free-entry ticket (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent when a person spends a free-entry credit on a night. The ticket itself comes in the ticket email.', 'eventcrew'),
                null,
                static fn (): string => self::tag(
                    /* translators: %s: event date */
                    __('🎟 Your free-entry ticket for %s — show it at the door.', 'eventcrew'),
                    ['%s' => '{date}']
                ),
                [
                    'date' => self::placeholder(
                        __('The day of the event, like Fri 14 Nov.', 'eventcrew'),
                        static fn (array $c): string => (string) ($c['date'] ?? '')
                    ),
                ]
            ),
            // TODO(messages): TicketMail does not read this template yet. It is
            // being rewritten (2026-11-05); its compose()/words() should call
            // MessageTemplates::subject('ticket', $context) and body() with
            // first_name, thanks, event and date in $context.
            new MessageTemplate(
                'ticket',
                __('Ticket (email)', 'eventcrew'),
                $email,
                __('The ticket with its QR code, to ticket buyers, guests and crew. Sent in Finnish and English.', 'eventcrew'),
                static fn (): string => __('Your ticket', 'eventcrew'),
                /* translators: %s: the ticket holder's first name */
                static fn (): string => self::tag(__('Hi %s,', 'eventcrew'), ['%s' => '{first_name}']) . "\n\n{thanks}\n\n"
                    . self::tag(
                        /* translators: 1: the event's name, 2: its date, e.g. 14.11.2026 */
                        __('Here is your ticket to %1$s on %2$s. Show the QR code below at the door.', 'eventcrew'),
                        ['%1$s' => '{event}', '%2$s' => '{date}']
                    ),
                [
                    'first_name' => $firstName,
                    'thanks' => self::placeholder(
                        __('The thank-you line: for buying a ticket, for coming as a guest, for taking a task, or for spending a credit.', 'eventcrew'),
                        static fn (array $c): string => (string) ($c['thanks'] ?? '')
                    ),
                    'event' => $event,
                    'date' => $date,
                ],
                false
            ),
            // TODO(messages): TicketMail::composeCancelled() does not read this
            // template yet (see 'ticket').
            new MessageTemplate(
                'ticket_cancelled',
                __('Ticket cancelled (email)', 'eventcrew'),
                $email,
                __('Sent when a ticket that was emailed is cancelled. Sent in Finnish and English.', 'eventcrew'),
                static fn (): string => __('Your ticket is cancelled', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: 1: the event's name, 2: its date, e.g. 14.11.2026 */
                    __('Your ticket to %1$s on %2$s is cancelled. The QR code in the ticket email no longer works at the door.', 'eventcrew'),
                    ['%1$s' => '{event}', '%2$s' => '{date}']
                ),
                ['event' => $event, 'date' => $date],
                false
            ),
            // FollowupMail reads these two. A one-time text for the next
            // post-event email (FollowupText) still comes first.
            new MessageTemplate(
                'followup_came',
                __('After the event: thank you (email)', 'eventcrew'),
                $email,
                __('Sent the day after an event to the people who came. Sent in Finnish and English.', 'eventcrew'),
                static fn (): string => __('Thank you for dancing with us', 'eventcrew'),
                static fn (): string => self::followupBody(FollowupText::defaultText(FollowupText::CAME)),
                ['first_name' => $firstName, 'event' => $event, 'date' => $date]
            ),
            new MessageTemplate(
                'followup_missed',
                __('After the event: we missed you (email)', 'eventcrew'),
                $email,
                __('Sent the day after an event to the people who had a ticket but did not come. Sent in Finnish and English.', 'eventcrew'),
                static fn (): string => __('We missed you this time', 'eventcrew'),
                static fn (): string => self::followupBody(FollowupText::defaultText(FollowupText::MISSED)),
                ['first_name' => $firstName, 'event' => $event, 'date' => $date]
            ),
            // TODO(messages): the newsletter (Newsletter::compose() and
            // NewsletterWriter) does not read this template yet. It has its
            // own writer and its own one-time text (NewsletterText).
            new MessageTemplate(
                'newsletter',
                __('Next events announcement (email)', 'eventcrew'),
                $email,
                __('The announcement of the coming events, to the subscribers.', 'eventcrew'),
                static fn (): string => __('Coming events', 'eventcrew'),
                static fn (): string => '{events}',
                [
                    'events' => self::placeholder(
                        __('The coming events, as the newsletter writes them.', 'eventcrew'),
                        static fn (array $c): string => (string) ($c['events'] ?? '')
                    ),
                ],
                false
            ),
        ];
        // phpcs:enable Generic.Files.LineLength.TooLong
    }

    /**
     * @param Closure(array<string, mixed>): string $resolve
     * @return array{description: string, resolve: Closure(array<string, mixed>): string}
     */
    private static function placeholder(string $description, Closure $resolve): array
    {
        return ['description' => $description, 'resolve' => $resolve];
    }

    /** "Fri 14 Nov 18:00–20:00": the task's day label and time, as the crew messages write it. */
    private static function when(Task $task): string
    {
        $day = Dates::dayLabel($task->taskDate);

        return '' === $task->timeRange() ? $day : $day . ' ' . $task->timeRange();
    }

    /** "Door at Dance Night, 2026-11-14 18:00–20:00": as StandingNotice writes it. */
    private static function standingTask(Task $task): string
    {
        $time = $task->timeRange();

        return sprintf(
            '%s at %s, %s',
            $task->roleLabel(),
            $task->eventName(),
            '' === $time ? $task->taskDate : $task->taskDate . ' ' . $time
        );
    }

    /**
     * The post-event email's text as FollowupMail::words() builds it, in the
     * current language: the greeting, $main and the sign-off.
     */
    public static function followupBody(string $main): string
    {
        /* translators: %s: the person's first name */
        return self::tag(__('Hi %s,', 'eventcrew'), ['%s' => '{first_name}']) . "\n\n"
            . trim($main) . "\n\n"
            . __('Warmly,', 'eventcrew') . "\n" . (string) get_bloginfo('name');
    }
}

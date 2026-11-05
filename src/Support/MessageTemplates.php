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

    /**
     * What save() answers when the text lacks a required placeholder
     * (MessageTemplate::missingRequired()). Nothing was saved then.
     */
    public const ERROR_MISSING_PLACEHOLDER = 'missing_placeholder';

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
                    self::BODY === $part,
                    required: self::BODY === $part ? $template->requiredPlaceholders() : []
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
     * A text that lacks a required placeholder, as written or as translated,
     * is refused: nothing is saved and ERROR_MISSING_PLACEHOLDER is answered.
     *
     * @param mixed $posted The raw $_POST[POST_NAME].
     * @return string The translation error kind ('' when none), for TranslatedText::errorNotice(),
     *                or ERROR_MISSING_PLACEHOLDER.
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
        $lacksRequired = static fn (array $texts): bool => [] !== array_filter(
            $texts,
            static fn (string $text): bool => [] !== $template->missingRequired($text)
        );
        $body = TranslatedText::fromPost($posted[self::BODY] ?? null, true);

        // Checked before translating, so a refused text costs no translation.
        if (null !== $body && $lacksRequired([$body['source'], ...array_values($body['texts'])])) {
            return self::ERROR_MISSING_PLACEHOLDER;
        }

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

            // A translation can drop a placeholder too.
            if ($multiline && $lacksRequired([$value['source'], ...array_values($value['texts'])])) {
                return self::ERROR_MISSING_PLACEHOLDER;
            }

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
            __('The task, the event and the date, like Door at Dance Night, Sat 14 Nov 18:00–20:00.', 'eventcrew'),
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

        // Q297 group A placeholders (the kinds are further down).
        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literals; splitting them breaks extraction.
        $claimPlaceholders = [
            'standing' => self::placeholder(
                __('The person\'s standing and free-entry credits, like: Your standing: Beginner · 0 free-entry credits.', 'eventcrew'),
                static fn (array $c): string => (string) ($c['standing'] ?? '')
            ),
            'free_cancel' => self::placeholder(
                __('A space and the sentence saying until how many hours before the task a cancellation does not affect the standing (Settings). Empty when that number is 0.', 'eventcrew'),
                static fn (array $c): string => (string) ($c['free_cancel'] ?? '')
            ),
            'about' => self::placeholder(
                __('An empty line and the "About the task" text of the task\'s role. Empty when the role has none.', 'eventcrew'),
                static fn (array $c): string => (string) ($c['about'] ?? '')
            ),
        ];
        $cancelNote = self::placeholder(
            __('"Thanks for the notice.", or for a late cancellation the sentence saying it counts against the standing.', 'eventcrew'),
            static fn (array $c): string => (string) ($c['note'] ?? '')
        );
        $openTasks = self::placeholder(
            __('The next open tasks, under a line with each date.', 'eventcrew'),
            static fn (array $c): string => (string) ($c['open_tasks'] ?? '')
        );
        // phpcs:enable Generic.Files.LineLength.TooLong

        $spot = static fn (): string => self::tag(
            /* translators: 1: role, 2: event, 3: date/time */
            __('A spot just opened: %1$s at %2$s, %3$s. Can you step in? Sign up on the board.', 'eventcrew'),
            ['%1$s' => '{role}', '%2$s' => '{event}', '%3$s' => '{when}']
        );

        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literals; splitting them breaks extraction.
        $cancelHelp = self::botOrWeb(
            __('How to cancel the task or find someone to take it over: in the bot, or, for a person with no Telegram chat, on the crew page, with its link.', 'eventcrew'),
            static fn (): string => __("Can't make it? Open the bot and tap the task to cancel. Or find someone to take it over: they send /me to the bot and tap 🔁 Take over a task, and you accept.", 'eventcrew'),
            static fn (string $url): string => sprintf(
                /* translators: %s: link to the crew page */
                __("Can't make it? Cancel the task under My tasks in the Me tab of the crew page (%s).", 'eventcrew'),
                $url
            )
        );
        $lateHelp = self::botOrWeb(
            __('What keeps it clear next time: more notice, or a replacement found in the bot. For a person with no Telegram chat: more notice, and the link to the crew page.', 'eventcrew'),
            static fn (): string => __('More notice next time keeps it clear, and so does a replacement: they send /me to the bot and tap 🔁 Take over a task, and you accept.', 'eventcrew'),
            static fn (string $url): string => sprintf(
                /* translators: %s: link to the crew page */
                __('More notice next time keeps it clear. Your tasks are under My tasks in the Me tab of the crew page (%s).', 'eventcrew'),
                $url
            )
        );

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
                    /* translators: 1: name, 2: role, 3: event, 4: date/time, 5: how to cancel */
                    __("Hi %1\$s,\n\nJust a reminder that you're on %2\$s at %3\$s, %4\$s.\n\n%5\$s", 'eventcrew'),
                    ['%1$s' => '{name}', '%2$s' => '{role}', '%3$s' => '{event}', '%4$s' => '{when}', '%5$s' => '{cancel_help}']
                ),
                ['name' => $name, 'role' => $role, 'event' => $event, 'when' => $when, 'cancel_help' => $cancelHelp]
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
                __('Sent to each person signed up for a task when an organizer deletes the task. Sent in every site language, or in one language when its Hybrid tick in Settings > Email is off.', 'eventcrew'),
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
                    /* translators: 2: role at event, date, 3: what keeps it clear next time */
                    __("Your late cancellation for %2\$s has been recorded.\n\nIt counts against your standing. %3\$s", 'eventcrew'),
                    ['%2$s' => '{task}', '%3$s' => '{late_help}']
                ),
                ['name' => $name, 'task' => $standingTask, 'late_help' => $lateHelp]
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
            // Q297 group A: the sign-up and cancel confirmations
            // (ClaimNotifier), the open-task call (OpenTaskCall) and the
            // email confirmation (OnboardingService).
            new MessageTemplate(
                'signup_confirmed',
                __('Signed up for a task (email)', 'eventcrew'),
                $email,
                __('Sent when a person signs up for a task, in the bot or on the web page. The ticket, calendar and crew guide buttons are added below the text.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: 1: role, 2: event */
                    __('You’re signed up: %1$s for %2$s', 'eventcrew'),
                    ['%1$s' => '{role}', '%2$s' => '{event}']
                ),
                static fn (): string => self::tag(
                    /* translators: 1: name, 2: role, 3: event, 4: date/time, 5: standing line, 6: how to cancel */
                    __("Hi %1\$s,\n\nYou're signed up for %2\$s at %3\$s, %4\$s. Show your ticket at the door.\n\n%5\$s\n\n%6\$s", 'eventcrew'),
                    ['%1$s' => '{name}', '%2$s' => '{role}', '%3$s' => '{event}', '%4$s' => '{when}', '%5$s' => '{standing}', '%6$s' => '{cancel_help}']
                ) . '{free_cancel}{about}',
                ['name' => $name, 'role' => $role, 'event' => $event, 'when' => $when, 'cancel_help' => $cancelHelp] + $claimPlaceholders
            ),
            new MessageTemplate(
                'signup_confirmed_dm',
                __('Signed up for a task (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent when a person signs up for a task, in the bot or on the web page. The ticket, calendar and crew guide buttons are added below the text.', 'eventcrew'),
                null,
                static fn (): string => self::tag(
                    /* translators: 1: role, 2: event, 3: date/time */
                    __("✅ Signed up: %1\$s at %2\$s, %3\$s.\n\nShow your ticket at the door - it is the first button below.\n\nTo cancel, tap the task again on the group board or in /board in a private chat, or tap Cancel in your reminder. Or find someone to take it over: they send /me to the bot and tap 🔁 Take over a task, and you accept.", 'eventcrew'),
                    ['%1$s' => '{role}', '%2$s' => '{event}', '%3$s' => '{when}']
                ) . "{free_cancel}{about}\n\n{standing}",
                ['role' => $role, 'event' => $event, 'when' => $when] + $claimPlaceholders
            ),
            new MessageTemplate(
                'cancel_confirmed',
                __('Cancelled a task (email)', 'eventcrew'),
                $email,
                __('Sent when a person cancels their own task, in the bot or on the web page.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: 1: role, 2: event */
                    __('Cancelled: %1$s for %2$s', 'eventcrew'),
                    ['%1$s' => '{role}', '%2$s' => '{event}']
                ),
                static fn (): string => self::tag(
                    /* translators: 1: name, 2: role, 3: event, 4: standing note, 5: standing line */
                    __("Hi %1\$s,\n\nYou've cancelled %2\$s at %3\$s, and your ticket is now disabled.\n\n%4\$s\n\n%5\$s", 'eventcrew'),
                    ['%1$s' => '{name}', '%2$s' => '{role}', '%3$s' => '{event}', '%4$s' => '{note}', '%5$s' => '{standing}']
                ),
                ['name' => $name, 'role' => $role, 'event' => $event, 'note' => $cancelNote, 'standing' => $claimPlaceholders['standing']]
            ),
            new MessageTemplate(
                'cancel_confirmed_dm',
                __('Cancelled a task (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent when a person cancels their own task, in the bot or on the web page.', 'eventcrew'),
                null,
                static fn (): string => self::tag(
                    /* translators: 1: cancellation kind, 2: role, 3: event, 4: standing note */
                    __('%1$s: %2$s at %3$s. %4$s', 'eventcrew'),
                    ['%1$s' => '{cancelled}', '%2$s' => '{role}', '%3$s' => '{event}', '%4$s' => '{note}']
                ) . "\n\n{standing}",
                [
                    'cancelled' => self::placeholder(
                        __('"❌ Cancelled", or "⚠️ Late cancellation" when the cancellation was late.', 'eventcrew'),
                        static fn (array $c): string => (string) ($c['cancelled'] ?? '')
                    ),
                    'role' => $role,
                    'event' => $event,
                    'note' => $cancelNote,
                    'standing' => $claimPlaceholders['standing'],
                ]
            ),
            new MessageTemplate(
                'open_task_call',
                __('Tasks still need people (email)', 'eventcrew'),
                $email,
                __('Sent to the crew before an event that still has open tasks (the times are in Settings), or when an organizer sends it by hand.', 'eventcrew'),
                static fn (): string => __('Some tasks still need people', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: 1: name, 2: open-task list grouped by date, 3: personal recap */
                    __("Hi %1\$s,\n\nSome tasks still need people:\n\n%2\$s\n\nSign up on the board.\n\n%3\$s", 'eventcrew'),
                    ['%1$s' => '{name}', '%2$s' => '{open_tasks}', '%3$s' => '{recap}']
                ),
                ['name' => $name, 'open_tasks' => $openTasks, 'recap' => self::placeholder(
                    __('The person\'s last three tasks and how many tasks they have completed in total.', 'eventcrew'),
                    static fn (array $c): string => (string) ($c['recap'] ?? '')
                )]
            ),
            new MessageTemplate(
                'open_task_call_dm',
                __('Tasks still need people (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to the crew before an event that still has open tasks (the times are in Settings), or when an organizer sends it by hand.', 'eventcrew'),
                null,
                static fn (): string => self::tag(
                    /* translators: %s: the open-task list, already grouped by date */
                    __("🔔 Some tasks still need people:\n\n%s\n\nSign up on the board in the group.", 'eventcrew'),
                    ['%s' => '{open_tasks}']
                ),
                ['open_tasks' => $openTasks]
            ),
            new MessageTemplate(
                'email_confirm',
                __('Confirm your email (email)', 'eventcrew'),
                $email,
                __('Sent when a person types their email in the Telegram bot. The confirmation link is a button below the text and is always added.', 'eventcrew'),
                static fn (): string => __('Confirm your email for EventCrew', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: 1: person's name */
                    __("Hi %1\$s,\n\nConfirm your email to finish signing up for EventCrew tasks.\n\nThe link works once and expires in a day. If you didn't ask for this, you can ignore this email.", 'eventcrew'),
                    ['%1$s' => '{name}']
                ) . "\n\n" . PrivacyPolicy::noticeLine(),
                [
                    'name' => self::placeholder(
                        __('The name the person has in Telegram, or their email address when there is none.', 'eventcrew'),
                        static fn (array $c): string => (string) ($c['name'] ?? '')
                    ),
                ]
            ),
            // End of Q297 group A.
            ...self::welcomeAndDoorKinds($name),
            ...self::takeoverTemplates($name, $role, $event, $when),
            // TODO(messages): TicketMail does not read this template yet. It is
            // being rewritten (2026-11-05); its compose()/words() should call
            // MessageTemplates::subject('ticket', $context) and body() with
            // first_name, thanks, event and date in $context.
            new MessageTemplate(
                'ticket',
                __('Ticket (email)', 'eventcrew'),
                $email,
                __('The ticket with its QR code, to ticket buyers, guests and crew. Sent in every site language, or in one language when its Hybrid tick in Settings > Email is off.', 'eventcrew'),
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
                __('Sent when a ticket that was emailed is cancelled. Sent in every site language, or in one language when its Hybrid tick in Settings > Email is off.', 'eventcrew'),
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
                __('Sent the day after an event to the people who came. Sent in every site language, or in one language when its Hybrid tick in Settings > Email is off.', 'eventcrew'),
                static fn (): string => __('Thank you for dancing with us', 'eventcrew'),
                static fn (): string => self::followupBody(FollowupText::defaultText(FollowupText::CAME)),
                ['first_name' => $firstName, 'event' => $event, 'date' => $date]
            ),
            new MessageTemplate(
                'followup_missed',
                __('After the event: we missed you (email)', 'eventcrew'),
                $email,
                __('Sent the day after an event to the people who had a ticket but did not come. Sent in every site language, or in one language when its Hybrid tick in Settings > Email is off.', 'eventcrew'),
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
            // The account emails. The link in each of these is a
            // button that Mailer adds under the text, not a placeholder, so no
            // saved text can remove it.
            new MessageTemplate(
                'sign_in_link',
                __('Sign-in link (email)', 'eventcrew'),
                $email,
                __('Sent when a person with an account asks for a sign-in link on the crew page. The Sign in button with the link is added under the text.', 'eventcrew'),
                static fn (): string => __('Your EventCrew sign-in link', 'eventcrew'),
                static fn (): string => __(
                    "Use the button below to sign in and manage your tasks.\n\nThe link is good for 30 minutes, and works once.",
                    'eventcrew'
                )
            ),
            new MessageTemplate(
                'account_create_link',
                __('New account link (email)', 'eventcrew'),
                $email,
                __('Sent when someone without an account asks for a sign-in link on the crew page. The Create my account button with the link is added under the text, and for a new address the privacy line too.', 'eventcrew'),
                static fn (): string => __('Create your crew account', 'eventcrew'),
                static fn (): string => __(
                    "There is no crew account for this email address yet. The button below creates your account and signs you in.\n\nThe link is good for 30 minutes, and works once. If you did not ask for this, ignore this email.",
                    'eventcrew'
                )
            ),
            new MessageTemplate(
                'email_change_confirm',
                __('Confirm a new email address (email)', 'eventcrew'),
                $email,
                __('Sent to the new address when a person changes their email address on the crew page. The Confirm new email button with the link is added under the text.', 'eventcrew'),
                static fn (): string => __('Confirm your new email address', 'eventcrew'),
                static fn (): string => __(
                    "Use the button below to make this the email address of your crew account.\n\nThe link is good for 24 hours, and works once. Until you use it, your account keeps its old address. If you did not ask for this, ignore this email.",
                    'eventcrew'
                )
            ),
            new MessageTemplate(
                'email_changed',
                __('Email address changed (email)', 'eventcrew'),
                $email,
                __('Sent to the old address when a person has confirmed their new email address.', 'eventcrew'),
                static fn (): string => __('Your crew account email was changed', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: %s: the new email address */
                    __("The email address of your crew account is now %s. Mail from the crew goes there from now on.\n\nIf you did not do this, tell an organizer.", 'eventcrew'),
                    ['%s' => '{email}']
                ),
                [
                    'email' => self::placeholder(
                        __('The new email address.', 'eventcrew'),
                        static fn (array $c): string => (string) ($c['email'] ?? ''),
                        true
                    ),
                ]
            ),
            new MessageTemplate(
                'delete_data_confirm',
                __('Confirm deleting data (email)', 'eventcrew'),
                $email,
                __('Sent when a person presses Delete my data on the manage-account page. The Delete my data button with the link is added under the text.', 'eventcrew'),
                static fn (): string => __('Confirm deleting your data', 'eventcrew'),
                static fn (): string => __('Someone asked to delete your crew account and its history. If it was you, press the button below. The link works once and for 30 minutes. If it was not you, ignore this email.', 'eventcrew')
            ),
            new MessageTemplate(
                'access_changed',
                __('Access changed (email)', 'eventcrew'),
                $email,
                __('Sent when an organizer gives a person a role, a one-time sign-up pass, or organizer rights.', 'eventcrew'),
                static fn (): string => __('Your EventCrew access changed', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: 1: name, 2: what changed */
                    __("Hi %1\$s,\n\n%2\$s", 'eventcrew'),
                    ['%1$s' => '{name}', '%2$s' => '{change}']
                ),
                [
                    'name' => $name,
                    'change' => self::placeholder(
                        __('What changed, like "You now have the role DJ." with what it means. The same text as the Telegram message.', 'eventcrew'),
                        static fn (array $c): string => (string) ($c['change'] ?? '')
                    ),
                ]
            ),
            new MessageTemplate(
                'access_role_dm',
                __('Access changed: role (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent when an organizer gives a person a role. The email about it repeats this text.', 'eventcrew'),
                null,
                static fn (): string => self::tag(
                    /* translators: %s: role name, such as DJ */
                    __('You now have the role %s. Its tasks are shown to you with /board in a private chat with the bot and in the Crew app, and you can sign up for them.', 'eventcrew'),
                    ['%s' => '{role}']
                ),
                [
                    'role' => self::placeholder(
                        __('The name of the role, like DJ.', 'eventcrew'),
                        static fn (array $c): string => (string) ($c['role'] ?? '')
                    ),
                ]
            ),
            new MessageTemplate(
                'access_pass_dm',
                __('Access changed: one-time pass (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent when an organizer gives a person a one-time sign-up pass. The email about it repeats this text.', 'eventcrew'),
                null,
                static fn (): string => __('🎟 You’ve a one-time pass to sign up for your next task, even while your standing is low.', 'eventcrew')
            ),
            new MessageTemplate(
                'access_organizer_dm',
                __('Access changed: organizer (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent when an organizer makes a person an organizer. The email about it repeats this text.', 'eventcrew'),
                null,
                static fn (): string => __('⭐ You’re now an EventCrew organizer.', 'eventcrew')
            ),
            ...self::organizerNoticeDefinitions(),
        ];
        // phpcs:enable Generic.Files.LineLength.TooLong
    }

    /**
     * The notices to the organizers: a person meets the conditions of a role,
     * is given a role or is made an organizer (RoleNotifier); a member is
     * blocked after a forged admin request (CrewAdminController); a mailed
     * list was imported or could not be (MailedListImport); a bot chat
     * is sent to the organizers (ChatForwarder).
     *
     * @return list<MessageTemplate>
     */
    private static function organizerNoticeDefinitions(): array
    {
        $email = MessageTemplate::CHANNEL_EMAIL;
        $telegram = MessageTemplate::CHANNEL_TELEGRAM;

        $member = self::placeholder(
            __('The name of the person the notice is about.', 'eventcrew'),
            static fn (array $c): string => ($c['member'] ?? null) instanceof Person ? $c['member']->name() : ''
        );
        $memberEmail = self::placeholder(
            __('The email address of the person the notice is about.', 'eventcrew'),
            static fn (array $c): string => ($c['member'] ?? null) instanceof Person ? $c['member']->email : ''
        );
        $roleName = self::placeholder(
            __('The name of the role, like Crew leader.', 'eventcrew'),
            static fn (array $c): string => '' === (string) ($c['role'] ?? '') ? '' : Roles::display((string) $c['role'])
        );
        $giver = self::placeholder(
            __('The name of the organizer who did it. Empty when it is not known.', 'eventcrew'),
            static fn (array $c): string => (string) ($c['giver'] ?? '')
        );
        $from = self::placeholder(
            __('The email address the list was sent from.', 'eventcrew'),
            static fn (array $c): string => (string) ($c['from'] ?? '')
        );
        $added = self::placeholder(
            __('How many were added.', 'eventcrew'),
            static fn (array $c): string => (string) (int) ($c['added'] ?? 0)
        );
        $day = self::placeholder(
            __('The day of the event, like Sat 1 Aug.', 'eventcrew'),
            static fn (array $c): string => (string) ($c['day'] ?? '')
        );
        $sending = self::placeholder(
            __('The sentence saying when the ticket emails go out and how to stop them. Empty when no ticket emails are waiting.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            static fn (array $c): string => '' === (string) ($c['sending_at'] ?? '')
                ? ''
                : sprintf(
                    /* translators: %s: time of day, e.g. 21:40 */
                    __('The ticket emails go out at %s. To stop them, press "Cancel sending" on the Roster page before then.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                    (string) $c['sending_at']
                )
        );
        $sentFrom = static fn (): string => self::tag(
            /* translators: %s: the email address the list was sent from */
            __('The list was sent from %s.', 'eventcrew'),
            ['%s' => '{from}']
        );

        // The role and organizer notices name who did it, unless the context
        // says that is not known ('giver' => ''). The Messages page, with no
        // context, shows the text that names them.
        $byKnown = static fn (array $c): bool => '' !== (string) ($c['giver'] ?? '-');
        $met = static fn (): string => self::tag(
            /* translators: 1: the person's name, 2: the role */
            __('%1$s now meets the conditions for the role %2$s. You can give them the role with /people.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            ['%1$s' => '{name}', '%2$s' => '{role}']
        );
        $given = static fn (array $c): string => $byKnown($c)
            ? self::tag(
                /* translators: 1: the person's name, 2: the role, 3: the name of the organizer who gave it */
                __('%1$s was given the role %2$s by %3$s.', 'eventcrew'),
                ['%1$s' => '{name}', '%2$s' => '{role}', '%3$s' => '{giver}']
            )
            : self::tag(
                /* translators: 1: the person's name, 2: the role */
                __('%1$s was given the role %2$s.', 'eventcrew'),
                ['%1$s' => '{name}', '%2$s' => '{role}']
            );
        $made = static fn (array $c): string => $byKnown($c)
            ? self::tag(
                /* translators: 1: the person's name, 2: the name of the organizer who did it */
                __('%1$s was made an organizer by %2$s.', 'eventcrew'),
                ['%1$s' => '{name}', '%2$s' => '{giver}']
            )
            : self::tag(
                /* translators: %s: the person's name */
                __('%s was made an organizer.', 'eventcrew'),
                ['%s' => '{name}']
            );
        $roleNotice = ['name' => $member, 'role' => $roleName, 'giver' => $giver];

        $forged = static fn (): string => self::tag(
            /*
             * translators: 1: person's name, 2: their email, 3: date and time,
             * 4: the admin action's name, 5: IP address
             */
            __(
                "A member who is not an organizer sent an admin request from the Crew app. The request was not carried out and their account is now blocked: they cannot sign up for tasks or sign in on the web.\n\nWho: %1\$s (%2\$s)\nWhen: %3\$s\nAction: %4\$s\nIP address: %5\$s\n\nTo lift the block, open EventCrew > People in wp-admin, edit the person and press Unban.", // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                'eventcrew'
            ),
            ['%1$s' => '{name}', '%2$s' => '{email}', '%3$s' => '{when}', '%4$s' => '{action}', '%5$s' => '{ip}']
        );
        $forgedPlaceholders = [
            'name' => $member,
            'email' => $memberEmail,
            'when' => self::placeholder(
                __('The date and time of the request, like 2026-11-14 21:05:00.', 'eventcrew'),
                static fn (array $c): string => (string) ($c['when'] ?? '')
            ),
            'action' => self::placeholder(
                __('The name of the admin action that was asked for, like Give a credit.', 'eventcrew'),
                static fn (array $c): string => (string) ($c['action'] ?? '')
            ),
            'ip' => self::placeholder(
                __('The IP address the request came from.', 'eventcrew'),
                static fn (array $c): string => '' !== (string) ($c['ip'] ?? '') ? (string) $c['ip'] : __('unknown', 'eventcrew')
            ),
        ];

        $chatName = ['name' => $member];

        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literals; splitting them breaks extraction.
        return [
            new MessageTemplate(
                'role_met',
                __('A person meets the conditions for a role (email)', 'eventcrew'),
                $email,
                __('Sent to the organizers the first time a person meets the conditions of a role. Checked every hour.', 'eventcrew'),
                static fn (): string => __('Someone meets the conditions for a role', 'eventcrew'),
                $met,
                $roleNotice
            ),
            new MessageTemplate(
                'role_met_dm',
                __('A person meets the conditions for a role (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to the organizers who use the bot, the first time a person meets the conditions of a role.', 'eventcrew'),
                null,
                $met,
                $roleNotice
            ),
            new MessageTemplate(
                'role_given',
                __('A person was given a role (email)', 'eventcrew'),
                $email,
                __('Sent to the organizers the first time a person is given a role. The built-in text names who gave it when that is known.', 'eventcrew'),
                static fn (): string => __('Someone was given a role', 'eventcrew'),
                $given,
                $roleNotice
            ),
            new MessageTemplate(
                'role_given_dm',
                __('A person was given a role (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to the organizers who use the bot, the first time a person is given a role. The built-in text names who gave it when that is known.', 'eventcrew'),
                null,
                $given,
                $roleNotice
            ),
            new MessageTemplate(
                'organizer_made',
                __('A person was made an organizer (email)', 'eventcrew'),
                $email,
                __('Sent to the other organizers when a person is made an organizer. The built-in text names who did it when that is known.', 'eventcrew'),
                static fn (): string => __('Someone was made an organizer', 'eventcrew'),
                $made,
                ['name' => $member, 'giver' => $giver]
            ),
            new MessageTemplate(
                'organizer_made_dm',
                __('A person was made an organizer (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to the other organizers who use the bot, when a person is made an organizer. The built-in text names who did it when that is known.', 'eventcrew'),
                null,
                $made,
                ['name' => $member, 'giver' => $giver]
            ),
            new MessageTemplate(
                'forged_request',
                __('Member blocked after a forged admin request (email)', 'eventcrew'),
                $email,
                __('Sent to the organizers with a confirmed email address when a member who is not an organizer sends an admin request from the Crew app and is blocked.', 'eventcrew'),
                static fn (): string => __('Crew app: a member is blocked after a forged admin request', 'eventcrew'),
                $forged,
                $forgedPlaceholders
            ),
            new MessageTemplate(
                'forged_request_dm',
                __('Member blocked after a forged admin request (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to the organizers who have a chat with the bot when a member who is not an organizer sends an admin request from the Crew app and is blocked.', 'eventcrew'),
                null,
                $forged,
                $forgedPlaceholders
            ),
            new MessageTemplate(
                'import_tickets',
                __('Ticket list imported (email)', 'eventcrew'),
                $email,
                __('Sent to the organizers, and to the sender, after a ticket file mailed to the import mailbox is imported. A line left empty by a placeholder is removed.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: %s: the event day, e.g. "Sat 1 Aug" */
                    __('Ticket buyers imported for %s', 'eventcrew'),
                    ['%s' => '{day}']
                ),
                static fn (): string => self::tag(
                    /* translators: 1: number of tickets added, 2: the event day, e.g. "Sat 1 Aug" */
                    __('%1$d tickets were imported for %2$s.', 'eventcrew'),
                    ['%1$d' => '{added}', '%2$s' => '{day}']
                ) . "\n\n{skipped}\n\n{sending}\n\n" . $sentFrom(),
                [
                    'added' => $added,
                    'day' => $day,
                    'skipped' => self::placeholder(
                        __('The sentence saying how many tickets were skipped because they were already on the list. Empty when none were.', 'eventcrew'),
                        static fn (array $c): string => (int) ($c['skipped'] ?? 0) > 0
                            ? sprintf(
                                /* translators: %d: number of tickets skipped */
                                __('%d tickets were skipped because they were already on the list.', 'eventcrew'),
                                (int) $c['skipped']
                            )
                            : ''
                    ),
                    'sending' => $sending,
                    'from' => $from,
                ]
            ),
            new MessageTemplate(
                'import_guests',
                __('Guest list imported (email)', 'eventcrew'),
                $email,
                __('Sent to the organizers, and to the sender, after a list of guests mailed to the import mailbox is added to the guest list. A line left empty by a placeholder is removed.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: %s: the event day, e.g. "Sat 1 Aug" */
                    __('Guests added for %s', 'eventcrew'),
                    ['%s' => '{day}']
                ),
                static fn (): string => self::tag(
                    /* translators: 1: number of guests added, 2: the event day, e.g. "Sat 1 Aug" */
                    __('%1$d guests were added to the guest list for %2$s.', 'eventcrew'),
                    ['%1$d' => '{added}', '%2$s' => '{day}']
                ) . " {plus_ones}\n\n{skipped}\n\n{sending}\n\n" . $sentFrom(),
                [
                    'added' => $added,
                    'day' => $day,
                    'plus_ones' => self::placeholder(
                        __('The sentence saying how many plus-ones the guests bring. Empty when none.', 'eventcrew'),
                        static fn (array $c): string => (int) ($c['plus_ones'] ?? 0) > 0
                            ? sprintf(
                                /* translators: %d: total number of plus-ones the added guests bring */
                                __('They bring %d plus-ones in total.', 'eventcrew'),
                                (int) $c['plus_ones']
                            )
                            : ''
                    ),
                    'skipped' => self::placeholder(
                        __('The sentence saying how many names were skipped because they were already on the guest list. Empty when none were.', 'eventcrew'),
                        static fn (array $c): string => (int) ($c['skipped'] ?? 0) > 0
                            ? sprintf(
                                /* translators: %d: number of names skipped */
                                __('%d names were skipped because they were already on the guest list.', 'eventcrew'),
                                (int) $c['skipped']
                            )
                            : ''
                    ),
                    'sending' => $sending,
                    'from' => $from,
                ]
            ),
            new MessageTemplate(
                'import_failed',
                __('Ticket list not imported (email)', 'eventcrew'),
                $email,
                __('Sent to the organizers, and to the sender, when a ticket file mailed to the import mailbox cannot be imported.', 'eventcrew'),
                static fn (): string => __('The ticket list could not be imported', 'eventcrew'),
                static fn (): string => "{reason}\n\n" . __('Nothing was added to the door list.', 'eventcrew') . "\n\n" . $sentFrom(),
                [
                    'reason' => self::placeholder(
                        __('Why the list could not be imported.', 'eventcrew'),
                        static fn (array $c): string => (string) ($c['reason'] ?? '')
                    ),
                    'from' => $from,
                ]
            ),
            new MessageTemplate(
                'chat_forward',
                __('Bot chat sent to the organizers (email)', 'eventcrew'),
                $email,
                __('Sent to the organizers when the bot finds that a person in a private chat gives feedback, is unhappy, is very happy or asks for a human. The built-in subject says which; a changed subject is used for all four.', 'eventcrew'),
                static fn (array $c): string => match ($c['kind'] ?? '') {
                    /* translators: %s: the person's name */
                    'unhappy' => self::tag(__('Bot chat: %s is unhappy', 'eventcrew'), ['%s' => '{name}']),
                    /* translators: %s: the person's name */
                    'very_happy' => self::tag(__('Bot chat: %s is very happy', 'eventcrew'), ['%s' => '{name}']),
                    /* translators: %s: the person's name */
                    'human' => self::tag(__('Bot chat: %s wants a human', 'eventcrew'), ['%s' => '{name}']),
                    /* translators: %s: the person's name */
                    default => self::tag(__('Bot chat: feedback from %s', 'eventcrew'), ['%s' => '{name}']),
                },
                static fn (): string => self::tag(
                    /* translators: 1: the person's name, 2: their email address */
                    __('%1$s (%2$s) wrote to the bot in a private chat. Here is that chat, oldest first.', 'eventcrew'),
                    ['%1$s' => '{name}', '%2$s' => '{email}']
                ) . "\n\n{transcript}",
                [
                    'name' => $member,
                    'email' => $memberEmail,
                    'transcript' => self::placeholder(
                        __('The chat, one message per paragraph, oldest first, each with its time.', 'eventcrew'),
                        static fn (array $c): string => (string) ($c['transcript'] ?? '')
                    ),
                ]
            ),
            new MessageTemplate(
                'chat_forward_dm',
                __('Bot chat sent to the organizers (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to the organizers who use the bot, after the bot chat email reached them.', 'eventcrew'),
                null,
                static fn (): string => self::tag(
                    /* translators: %s: the person's name */
                    __('I emailed you a transcript of my private chat with %s.', 'eventcrew'),
                    ['%s' => '{name}']
                ),
                $chatName
            ),
        ];
        // phpcs:enable Generic.Files.LineLength.TooLong
    }

    /**
     * The welcome message (WelcomeSender), the new member email of the sales
     * desk (DoorNewMember), and the sales desk link and the organizer notice
     * (DoorLinkSender). Their sign-in links are buttons under the text, not
     * placeholders, so no text can lose them.
     *
     * @param array{description: string, resolve: Closure(array<string, mixed>): string} $name
     * @return list<MessageTemplate>
     */
    private static function welcomeAndDoorKinds(array $name): array
    {
        $email = MessageTemplate::CHANNEL_EMAIL;
        $telegram = MessageTemplate::CHANNEL_TELEGRAM;
        $given = static fn (string $key): Closure => static fn (array $c): string => (string) ($c[$key] ?? '');
        $setting = static fn (string $key): Closure => static fn (): string => WelcomeSender::sectionValues()[$key];
        /* translators: %s: the person's first name */
        $hi = static fn (): string => self::tag(__('Hi %s,', 'eventcrew'), ['%s' => '{name}']) . "\n\n";

        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literals; splitting them breaks extraction.
        $crew = self::placeholder(
            __('The crew’s name (the site name).', 'eventcrew'),
            static fn (): string => trim((string) get_bloginfo('name'))
        );
        $helpLine = self::placeholder(
            __('An empty line, then the line with the link to the Help tab of the Crew app. Empty when no crew page is set.', 'eventcrew'),
            $given('help_line')
        );
        $welcome = [
            'crew' => $crew,
            'me_help' => self::botOrWeb(
                __('Where to see your own numbers: in the bot, or, for a person with no Telegram chat, on the crew page, with its link.', 'eventcrew'),
                static fn (): string => WelcomeSender::meBotLine(),
                static fn (string $url): string => sprintf(
                    /* translators: %s: link to the crew page */
                    __('You can see your own numbers any time in the Me tab of the crew page (%s).', 'eventcrew'),
                    $url
                )
            ),
            'tickets_help' => self::botOrWeb(
                __('Where all your tickets are: in the bot, or, for a person with no Telegram chat, on the crew page, with its link.', 'eventcrew'),
                static fn (): string => WelcomeSender::ticketsBotLine(),
                static fn (string $url): string => sprintf(
                    /* translators: %s: link to the crew page */
                    __('It is the first button in your sign-up confirmation, and the Me tab of the crew page (%s) lists all your tickets.', 'eventcrew'),
                    $url
                )
            ),
            'roles' => self::placeholder(
                __('The tasks on offer, one line each, with what each one involves.', 'eventcrew'),
                $setting('roles')
            ),
            'tasks_per_credit' => self::placeholder(
                __('How many completed tasks earn one free-entry credit (Settings).', 'eventcrew'),
                $setting('tasks_per_credit')
            ),
            'outcomes' => self::placeholder(
                __('How each way a task can go counts for standing, one line each, in percent (Settings).', 'eventcrew'),
                $setting('outcomes')
            ),
            'notice_hours' => self::placeholder(
                __('How many hours before a task a cancellation counts as late (Settings).', 'eventcrew'),
                $setting('notice_hours')
            ),
            'rated_tasks' => self::placeholder(
                __('How many finished tasks a person needs before they get a rating (Settings).', 'eventcrew'),
                $setting('rated_tasks')
            ),
            'good_standing' => self::placeholder(
                __('The percentage that is good standing (Settings).', 'eventcrew'),
                $setting('good_standing')
            ),
            'gate_rule' => self::placeholder(
                __('A space, then the sentence that people below good standing cannot sign up for new tasks. Empty when that rule is off in Settings.', 'eventcrew'),
                $setting('gate_rule')
            ),
            'open_tasks' => self::placeholder(
                __('The next open tasks, one line each, and the line about how to sign up. When there are none, a line that says so.', 'eventcrew'),
                $given('open_tasks')
            ),
            'help_line' => $helpLine,
        ];
        $day = self::placeholder(__('The day of the event, like Sat 1 Aug.', 'eventcrew'), $given('day'));

        $salesDesk = static fn (): string => self::tag(
            /* translators: 1: event day, e.g. "Sat 1 Aug", 2: how to get another link */
            __('Door list for %1$s: open it with the button below. The link works once, on one device; it logs that device in until 06:00. Forwarding it is fine. Need another one? %2$s', 'eventcrew'),
            ['%1$s' => '{day}', '%2$s' => '{door_help}']
        );
        $organizing = static fn (): string => self::tag(
            /* translators: 1: event day, e.g. "Sat 1 Aug", 2: how to get another link */
            __('You are organizing on %1$s. The sales desk button logs this device in to the door list until 06:00; the link works once. The Admin tab is in the Crew app; sign in there if it asks. Need another sales desk link? %2$s', 'eventcrew'),
            ['%1$s' => '{day}', '%2$s' => '{door_help}']
        );
        $doorHelp = self::botOrWeb(
            __('How to get another sales desk link: in the bot, or, for a person with no Telegram chat, on the crew page, with its link.', 'eventcrew'),
            static fn (): string => __('Send /door to the bot and tap 💳 Sales desk.', 'eventcrew'),
            static fn (string $url): string => sprintf(
                /* translators: %s: link to the crew page */
                __('Tap Sales desk in the Me tab of the crew page (%s).', 'eventcrew'),
                $url
            )
        );

        return [
            new MessageTemplate(
                WelcomeSender::KIND_EMAIL,
                __('Welcome message (email)', 'eventcrew'),
                $email,
                __('Sent once to each member when their email address is confirmed: how tasks, tickets, credits and standing work, and the open tasks. Only when the welcome message is on in Settings.', 'eventcrew'),
                static fn (): string => WelcomeSender::subject(),
                static fn (): string => $hi() . WelcomeSender::bodyTemplate(),
                ['name' => $name] + $welcome
            ),
            new MessageTemplate(
                WelcomeSender::KIND_DM,
                __('Welcome message (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent once to each member who uses the bot, with a button for each open task. Only when the welcome message is on in Settings.', 'eventcrew'),
                null,
                static fn (): string => WelcomeSender::bodyTemplate(),
                $welcome
            ),
            new MessageTemplate(
                WelcomeSender::KIND_ORG_EMAIL,
                __('Organizer guide (email)', 'eventcrew'),
                $email,
                __('Sent once to each organizer, with the whole organizer guide. Only when the welcome message is on in Settings.', 'eventcrew'),
                static fn (): string => OrganizerGuide::subject(),
                static fn (): string => $hi() . WelcomeSender::organizerIntroTemplate() . "{help_line}\n\n{guide}",
                [
                    'name' => $name,
                    'crew' => $crew,
                    'help_line' => $helpLine,
                    'guide' => self::placeholder(
                        __('The whole organizer guide. It is made from the settings when it is sent.', 'eventcrew'),
                        $given('guide')
                    ),
                ]
            ),
            new MessageTemplate(
                WelcomeSender::KIND_ORG_DM,
                __('Organizer guide (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent once to each organizer who uses the bot, with the short part of the organizer guide. Only when the welcome message is on in Settings.', 'eventcrew'),
                null,
                static fn (): string => WelcomeSender::organizerIntroTemplate() . "\n\n{guide}\n\n"
                    . __('The full guide, with the details, is in your email.', 'eventcrew') . '{help_line}',
                [
                    'crew' => $crew,
                    'guide' => self::placeholder(
                        __('The short part of the organizer guide ("In short"). It is made from the settings when it is sent.', 'eventcrew'),
                        $given('guide')
                    ),
                    'help_line' => $helpLine,
                ]
            ),
            new MessageTemplate(
                'door_new_member',
                __('New member added at the door (email)', 'eventcrew'),
                $email,
                __('Sent when the sales desk adds a new member. The first button under the text confirms their email address and signs them in. The greeting above the text and the privacy line under it are always added, in the member\'s language.', 'eventcrew'),
                static fn (): string => __('Welcome to the crew', 'eventcrew'),
                static fn (): string => __(
                    "You were added to the crew at the door. Welcome!\n\nThe first button confirms your email address and signs you in. It works once, for 24 hours.",
                    'eventcrew'
                ) . '{group_line}',
                [
                    'group_line' => self::placeholder(
                        __('An empty line, then the line about the button that opens the crew’s Telegram group. Empty when no group link is known; then there is no such button.', 'eventcrew'),
                        $given('group_line')
                    ),
                ]
            ),
            new MessageTemplate(
                'sales_desk_link',
                __('Sales desk link (email)', 'eventcrew'),
                $email,
                __('Sent on an event night to the people on a sales desk task, once they are marked arrived. The button under the text opens the sales desk.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: %s: event day, e.g. "Sat 1 Aug" */
                    __('Sales desk for %s', 'eventcrew'),
                    ['%s' => '{day}']
                ),
                $salesDesk,
                ['day' => $day, 'door_help' => $doorHelp]
            ),
            new MessageTemplate(
                'sales_desk_link_dm',
                __('Sales desk link (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent on an event night to the people on a sales desk task, once they are marked arrived, and to anyone who asks with /door. The button under the text opens the sales desk.', 'eventcrew'),
                null,
                $salesDesk,
                ['day' => $day, 'door_help' => $doorHelp]
            ),
            new MessageTemplate(
                'organizer_notice',
                __('Organizer on the night (email)', 'eventcrew'),
                $email,
                __('Sent on an event night to the organizers signed up on the Organize task, before the first task starts. The buttons under the text open the sales desk and the Admin tab.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: %s: event day, e.g. "Sat 1 Aug" */
                    __('You are organizing on %s', 'eventcrew'),
                    ['%s' => '{day}']
                ),
                $organizing,
                ['day' => $day, 'door_help' => $doorHelp]
            ),
            new MessageTemplate(
                'organizer_notice_dm',
                __('Organizer on the night (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent on an event night to the organizers signed up on the Organize task, before the first task starts. The buttons under the text open the sales desk and the Admin tab.', 'eventcrew'),
                null,
                $organizing,
                ['day' => $day, 'door_help' => $doorHelp]
            ),
        ];
        // phpcs:enable Generic.Files.LineLength.TooLong
    }

    /**
     * The takeover messages TakeoverService sends: the request to the person
     * whose task it is (the holder), the "done" note to them, and each outcome
     * to the person who asked (the cover). The context has 'person' (the
     * reader), 'cover', 'holder', 'task' and, for the request, 'deadline' (a
     * MySQL date). The answer link of the request email is its button, not
     * part of the text. The group message stays in TakeoverService.
     *
     * @param array{description: string, resolve: Closure(array<string, mixed>): string} $name
     * @param array{description: string, resolve: Closure(array<string, mixed>): string} $role
     * @param array{description: string, resolve: Closure(array<string, mixed>): string} $event
     * @param array{description: string, resolve: Closure(array<string, mixed>): string} $when
     * @return list<MessageTemplate>
     */
    private static function takeoverTemplates(array $name, array $role, array $event, array $when): array
    {
        $email = MessageTemplate::CHANNEL_EMAIL;
        $telegram = MessageTemplate::CHANNEL_TELEGRAM;

        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literals; splitting them breaks extraction.
        $cover = self::placeholder(
            __('The first name of the person who asks to take over the task.', 'eventcrew'),
            static fn (array $c): string => ($c['cover'] ?? null) instanceof Person ? $c['cover']->name() : ''
        );
        $holder = self::placeholder(
            __('The first name of the person whose task it is.', 'eventcrew'),
            static fn (array $c): string => ($c['holder'] ?? null) instanceof Person ? $c['holder']->name() : ''
        );
        $deadline = self::placeholder(
            __('The last time to answer, like Thu 23 Jul 12:00.', 'eventcrew'),
            static fn (array $c): string => isset($c['deadline']) ? TakeoverDeadline::label((string) $c['deadline']) : ''
        );
        $task = ['%2$s' => '{role}', '%3$s' => '{event}', '%4$s' => '{when}'];
        $hi = static fn (string $line): string => self::tag(
            /* translators: 1: name, 2: the cover line */
            __("Hi %1\$s,\n\n%2\$s", 'eventcrew'),
            ['%1$s' => '{name}', '%2$s' => $line]
        );

        $ask = static fn (): string => self::tag(
            /* translators: 1: cover's name, 2: role, 3: event, 4: date/time, 5: answer deadline */
            __("🔁 %1\$s asks to take over your task: %2\$s at %3\$s, %4\$s.\n\nYou decide: let them take it, or keep it. Please answer by %5\$s. If you do not answer by then, you keep the task.", 'eventcrew'),
            ['%1$s' => '{cover}', '%5$s' => '{deadline}'] + $task
        );
        $done = static fn (): string => self::tag(
            /* translators: 1: cover's name, 2: role, 3: event, 4: date/time */
            __('✅ Done: %1$s now has your task: %2$s at %3$s, %4$s.', 'eventcrew'),
            ['%1$s' => '{cover}'] + $task
        );
        $outcomes = [
            'takeover_refused' => [
                __('Takeover declined (email)', 'eventcrew'),
                __('Takeover declined (Telegram)', 'eventcrew'),
                __('Sent to the person who asked to take over a task when its holder keeps the task.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: 1: holder's name, 2: role, 3: event, 4: date/time */
                    __('%1$s keeps their task: %2$s at %3$s, %4$s. Your request to take it over was declined.', 'eventcrew'),
                    ['%1$s' => '{holder}'] + $task
                ),
            ],
            'takeover_expired' => [
                __('Takeover not answered in time (email)', 'eventcrew'),
                __('Takeover not answered in time (Telegram)', 'eventcrew'),
                __('Sent to the person who asked to take over a task when its holder did not answer in time.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: 1: holder's name, 2: role, 3: event, 4: date/time */
                    __('%1$s did not answer in time, so they keep their task: %2$s at %3$s, %4$s.', 'eventcrew'),
                    ['%1$s' => '{holder}'] + $task
                ),
            ],
            'takeover_failed' => [
                __('Takeover could not be done (email)', 'eventcrew'),
                __('Takeover could not be done (Telegram)', 'eventcrew'),
                __('Sent to the person who asked to take over a task when its holder agreed but the task could not be moved.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: 1: holder's name, 2: role, 3: event, 4: date/time */
                    __('%1$s agreed, but their task could not be moved to you: %2$s at %3$s, %4$s. Nothing changed.', 'eventcrew'),
                    ['%1$s' => '{holder}'] + $task
                ),
            ],
            'takeover_closed' => [
                __('Takeover request closed (email)', 'eventcrew'),
                __('Takeover request closed (Telegram)', 'eventcrew'),
                __('Sent to the person who asked to take over a task when the task changed or was cancelled before an answer.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: 1: holder's name, 2: role, 3: event, 4: date/time */
                    __('Your request to take over the task of %1$s is closed, because the task changed or was cancelled: %2$s at %3$s, %4$s.', 'eventcrew'),
                    ['%1$s' => '{holder}'] + $task
                ),
            ],
        ];

        $outcomePlaceholders = ['name' => $name, 'holder' => $holder, 'role' => $role, 'event' => $event, 'when' => $when];
        $list = [
            new MessageTemplate(
                'takeover_ask',
                __('Takeover request (email)', 'eventcrew'),
                $email,
                __('Sent to a person when someone asks to take over their task. The email has a button to answer.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: %s: cover's name */
                    __('%s asks to take over your task', 'eventcrew'),
                    ['%s' => '{cover}']
                ),
                static fn (): string => $hi($ask()),
                ['name' => $name, 'cover' => $cover, 'role' => $role, 'event' => $event, 'when' => $when, 'deadline' => $deadline]
            ),
            new MessageTemplate(
                'takeover_ask_dm',
                __('Takeover request (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to a person who uses the bot when someone asks to take over their task. The message has two buttons to answer.', 'eventcrew'),
                null,
                $ask,
                ['cover' => $cover, 'role' => $role, 'event' => $event, 'when' => $when, 'deadline' => $deadline]
            ),
            new MessageTemplate(
                'takeover_done',
                __('Takeover done (email)', 'eventcrew'),
                $email,
                __('Sent to a person who let someone take over their task, when the task has moved.', 'eventcrew'),
                static fn (): string => self::tag(
                    /* translators: %s: cover's name */
                    __('Your task moved to %s', 'eventcrew'),
                    ['%s' => '{cover}']
                ),
                static fn (): string => $hi($done()),
                ['name' => $name, 'cover' => $cover, 'role' => $role, 'event' => $event, 'when' => $when]
            ),
            new MessageTemplate(
                'takeover_done_dm',
                __('Takeover done (Telegram)', 'eventcrew'),
                $telegram,
                __('Sent to a person who let someone take over their task, when the task has moved. Not sent when they answered in the bot.', 'eventcrew'),
                null,
                $done,
                ['cover' => $cover, 'role' => $role, 'event' => $event, 'when' => $when]
            ),
        ];

        foreach ($outcomes as $id => [$emailLabel, $dmLabel, $description, $line]) {
            $list[] = new MessageTemplate(
                $id,
                $emailLabel,
                $email,
                $description,
                static fn (): string => __('About your takeover request', 'eventcrew'),
                static fn (): string => $hi($line()),
                $outcomePlaceholders
            );
            $list[] = new MessageTemplate(
                $id . '_dm',
                $dmLabel,
                $telegram,
                $description,
                null,
                $line,
                ['holder' => $holder, 'role' => $role, 'event' => $event, 'when' => $when]
            );
        }
        // phpcs:enable Generic.Files.LineLength.TooLong

        return $list;
    }

    /**
     * @param Closure(array<string, mixed>): string $resolve
     * @param bool $required The text must keep it (MessageTemplate::missingRequired()).
     * @return array{description: string, resolve: Closure(array<string, mixed>): string, required: bool}
     */
    private static function placeholder(string $description, Closure $resolve, bool $required = false): array
    {
        return ['description' => $description, 'resolve' => $resolve, 'required' => $required];
    }

    /**
     * A placeholder with two texts: $bot for a person with a Telegram chat, $web,
     * given the crew page link, for a person with none (CrewPageHelp). A site
     * with no crew page gives everyone the bot text.
     *
     * @param Closure(array<string, mixed>): string $bot
     * @param Closure(string, array<string, mixed>): string $web
     * @return array{description: string, resolve: Closure(array<string, mixed>): string, required: bool}
     */
    private static function botOrWeb(string $description, Closure $bot, Closure $web): array
    {
        return self::placeholder(
            $description,
            static fn (array $c): string => CrewPageHelp::webOnly($c['person'] ?? null)
                ? $web(CrewPageHelp::pageUrl(), $c)
                : $bot($c)
        );
    }

    /** "Fri 14 Nov 18:00–20:00": the task's day label and time, as the crew messages write it. */
    private static function when(Task $task): string
    {
        $day = Dates::dayLabel($task->taskDate);

        return '' === $task->timeRange() ? $day : $day . ' ' . $task->timeRange();
    }

    /** "Door at Dance Night, Sat 14 Nov 18:00–20:00", the day as in when(). */
    private static function standingTask(Task $task): string
    {
        return sprintf(
            /* translators: 1: role, 2: event, 3: day and time */
            __('%1$s at %2$s, %3$s', 'eventcrew'),
            $task->roleLabel(),
            $task->eventName(),
            self::when($task)
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

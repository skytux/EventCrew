<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use Closure;
use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\FollowupText;
use EventCrew\Support\Language;
use EventCrew\Support\NewsletterText;
use EventCrew\Support\OwnTextNotice;
use EventCrew\Support\SiteLanguages;
use EventCrew\Support\TranslatedText;

/**
 * /followup: an organizer sets a one-time text for the next post-event email
 * (FollowupMail), in a private chat with the bot. It works like /newsletter.
 *
 * - "/followup" alone shows both texts the next email uses (the thank-you to
 *   those who came, the sorry text to those who did not), in every site
 *   language, and whether each is a one-time text or the one on the Messages
 *   page.
 * - "/followup came <text>" or "/followup missed <text>" saves a one-time text
 *   for that version. It is translated (FollowupText, the same way as the
 *   newsletter's text). The text is saved at once and the answer says
 *   "Translating…"; the model is asked after the webhook has answered
 *   (a slow model would time out inside it), and the answer is then edited
 *   to show the text each language really gets. When the translation fails,
 *   the answer says so and shows the text once: every language gets it as
 *   written, and the post-event email tries the translation again before it
 *   goes out (FollowupText::translatePending()).
 * - "/followup came reset" or "/followup missed reset" resets that version to
 *   the default (removes its one-time text, so the text on the Messages page,
 *   or the built-in one, is used); "/followup reset" resets both. "clear"
 *   does the same. The answers that show a one-time text carry a "Reset to
 *   default" button.
 * - Setting a text over an earlier one-time text first shows the earlier
 *   text, who set it and when, and asks to replace it (Replace, Keep). The
 *   helper's action asks once, in its own confirmation ($confirmed).
 * - Setting or resetting a text tells the other organizers (OwnTextNotice).
 *
 * A one-time text is used by the next night's post-event email only, then
 * removed. In a group the command is refused, so the text is not written
 * where the whole crew reads it. Anyone who is not an organizer is refused.
 */
final class FollowupCommand
{
    public const CLEAR = 'clear';

    public const RESET = 'reset';

    /** Callback data: fu:ok:<id> replaces, fu:no:<id> keeps, fu:reset:<came|missed|all>. */
    public const PREFIX = 'fu:';

    private const PENDING_PREFIX = 'eventcrew_fu_pending_';

    private const PENDING_TTL = 3600;

    private const ALL = 'all';

    public function __construct(
        private readonly PersonRepository $people,
        private readonly FollowupText $text,
        private readonly TelegramClient $telegram,
        private readonly ?Closure $defer = null,
        private readonly ?OwnTextNotice $notice = null
    ) {
    }

    /**
     * $confirmed: the person already agreed to replace an earlier text (the
     * helper's Confirm, which showed it), so it is not asked again.
     */
    public function onCommand(
        int $telegramUserId,
        int $chatId,
        bool $isPrivate,
        string $arguments,
        bool $confirmed = false
    ): void {
        if (! $isPrivate) {
            $this->telegram->sendMessage($chatId, __('Use /followup in a private chat with me.', 'eventcrew'));

            return;
        }

        $person = 0 !== $telegramUserId ? $this->people->findByTelegramUserId($telegramUserId) : null;

        if (null === $person || ! $person->isOrganizer) {
            $this->telegram->sendMessage($chatId, __('Only organizers can use /followup.', 'eventcrew'));

            return;
        }

        $arguments = trim($arguments);

        if ('' === $arguments) {
            $this->telegram->sendMessage($chatId, $this->current(), $this->resetButton($this->ownVariants()));

            return;
        }

        if (self::isReset($arguments)) {
            $this->reset($person, $chatId, null);

            return;
        }

        $parts = preg_split('/\s+/u', $arguments, 2);
        $variant = strtolower((string) ($parts[0] ?? ''));
        $text = trim((string) ($parts[1] ?? ''));

        if (! FollowupText::isVariant($variant) || '' === $text) {
            $this->telegram->sendMessage($chatId, self::usage());

            return;
        }

        if (self::isReset($text)) {
            $this->reset($person, $chatId, $variant);

            return;
        }

        if (! $confirmed && null !== $this->text->next($variant)) {
            $this->askReplace($telegramUserId, $chatId, $variant, $text);

            return;
        }

        $this->save($person, $chatId, $variant, $text);
    }

    /**
     * A tap on Replace or Keep (asked before a text replaces an earlier one),
     * or on Reset to default.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onCallback(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $fromId = (int) ($callbackQuery['from']['id'] ?? 0);
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chatId = (int) ($message['chat']['id'] ?? $fromId);
        $data = (string) ($callbackQuery['data'] ?? '');
        $person = 0 !== $fromId ? $this->people->findByTelegramUserId($fromId) : null;

        if (null === $person || ! $person->isOrganizer) {
            $this->telegram->answerCallbackQuery($callbackId, __('Only organizers can use /followup.', 'eventcrew'), true);

            return;
        }

        if (1 === preg_match('/^fu:reset:(came|missed|all)$/', $data, $m)) {
            $this->telegram->answerCallbackQuery($callbackId);
            $this->reset($person, $chatId, self::ALL === $m[1] ? null : $m[1]);

            return;
        }

        if (1 !== preg_match('/^fu:(ok|no):([0-9a-f]{16})$/', $data, $m)) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $key = self::PENDING_PREFIX . $m[2];
        $pending = get_transient($key);

        if (! is_array($pending) || (int) ($pending['user'] ?? 0) !== $fromId) {
            $this->telegram->answerCallbackQuery($callbackId, __('This has expired. Please send it again.', 'eventcrew'), true);

            return;
        }

        // Used once, whichever button it was.
        delete_transient($key);
        $this->telegram->answerCallbackQuery($callbackId);
        $variant = (string) ($pending['variant'] ?? '');

        if ('no' === $m[1] || ! FollowupText::isVariant($variant)) {
            $this->telegram->sendMessage($chatId, __('Kept the current text.', 'eventcrew'));

            return;
        }

        $this->save($person, $chatId, $variant, (string) ($pending['text'] ?? ''));
    }

    /**
     * For the helper's confirmation of "/followup <came|missed> <text>": the
     * one-time text it replaces, who set it and when. '' when it replaces none.
     */
    public function replaceWarning(string $arguments): string
    {
        $parts = preg_split('/\s+/u', trim($arguments), 2);
        $variant = strtolower((string) ($parts[0] ?? ''));
        $text = trim((string) ($parts[1] ?? ''));

        if (! FollowupText::isVariant($variant) || '' === $text || self::isReset($text)) {
            return '';
        }

        $next = $this->text->next($variant);

        return null === $next ? '' : $this->earlier($variant, $next) . "\n\n" . __('Confirm replaces it.', 'eventcrew');
    }

    /**
     * The Crew app's helper box: "/followup <came|missed> <text>", "/followup
     * <came|missed> reset" or "/followup reset" as the question shown with
     * Confirm (with the one-time text it replaces), or (ok false) why not.
     * The caller has checked the person is an organizer.
     *
     * @return array{ok: bool, text: string, state: array<string, mixed>}
     */
    public function prepareWeb(string $arguments): array
    {
        $arguments = trim($arguments);

        if ('' === $arguments) {
            return ['ok' => false, 'text' => $this->current(), 'state' => []];
        }

        if (self::isReset($arguments)) {
            return [] === $this->ownVariants()
                ? ['ok' => false, 'text' => __('There is no one-time text to remove.', 'eventcrew'), 'state' => []]
                : ['ok' => true, 'text' => __('Remove both one-time texts of the next post-event email?', 'eventcrew'), 'state' => ['variant' => self::ALL, 'reset' => true]];
        }

        $parts = preg_split('/\s+/u', $arguments, 2);
        $variant = strtolower((string) ($parts[0] ?? ''));
        $text = trim((string) ($parts[1] ?? ''));

        if (! FollowupText::isVariant($variant) || '' === $text) {
            return ['ok' => false, 'text' => self::usage(), 'state' => []];
        }

        if (self::isReset($text)) {
            return null === $this->text->next($variant)
                ? ['ok' => false, 'text' => __('There is no one-time text to remove.', 'eventcrew'), 'state' => []]
                : ['ok' => true, 'text' => self::label($variant) . "\n" . __('Remove the one-time text?', 'eventcrew'), 'state' => ['variant' => $variant, 'reset' => true]];
        }

        $warning = $this->replaceWarning($arguments);

        return [
            'ok' => true,
            'text' => self::label($variant) . "\n" . __('Use this text in the next post-event email only?', 'eventcrew')
                . "\n\n" . $text . ('' === $warning ? '' : "\n\n" . $warning),
            'state' => ['variant' => $variant, 'text' => $text],
        ];
    }

    /**
     * Confirm in the helper box: saves or removes the one-time text as the
     * command does and tells the other organizers. The translation runs
     * after the page has its answer.
     *
     * @param array<string, mixed> $state
     * @return array{changed: bool, text: string}
     */
    public function runWeb(Person $organizer, array $state): array
    {
        $variant = (string) ($state['variant'] ?? '');

        if (! empty($state['reset'])) {
            if (self::ALL !== $variant && ! FollowupText::isVariant($variant)) {
                return ['changed' => false, 'text' => __('That can no longer be done.', 'eventcrew')];
            }

            return ['changed' => true, 'text' => $this->resetText($organizer, self::ALL === $variant ? null : $variant)];
        }

        $text = trim((string) ($state['text'] ?? ''));

        if (! FollowupText::isVariant($variant) || '' === $text) {
            return ['changed' => false, 'text' => __('That can no longer be done.', 'eventcrew')];
        }

        if (! TranslatedText::modelReady()) {
            $result = $this->text->setNext($variant, $text, $organizer->id);
            $this->notice?->changed($variant, $organizer->id, $text);

            return ['changed' => true, 'text' => self::saved($variant, $result['translated'], $result['texts'])];
        }

        $this->text->setNext($variant, $text, $organizer->id, false);
        $this->notice?->changed($variant, $organizer->id, $text);
        $this->later(function () use ($variant): void {
            $this->text->translateNext($variant);
        });

        return ['changed' => true, 'text' => self::label($variant) . "\n"
            . __('Saved for the next post-event email only:', 'eventcrew') . "\n\n" . $text];
    }

    private static function isReset(string $word): bool
    {
        return in_array(strtolower(trim($word)), [self::RESET, self::CLEAR], true);
    }

    /** Resets $variant (null: both) to the default and tells the other organizers. */
    private function reset(Person $person, int $chatId, ?string $variant): void
    {
        $this->telegram->sendMessage($chatId, $this->resetText($person, $variant));
    }

    /** Resets $variant (null: both), tells the other organizers and gives the answer. */
    private function resetText(Person $person, ?string $variant): string
    {
        $had = array_values(array_filter(
            null === $variant ? FollowupText::VARIANTS : [$variant],
            fn (string $v): bool => null !== $this->text->next($v)
        ));
        $this->text->clearNext($variant);

        foreach ($had as $v) {
            $this->notice?->changed($v, $person->id, '');
        }

        if (null === $variant) {
            return __(
                'Both one-time texts are removed. The next post-event email uses the texts on the Messages page.',
                'eventcrew'
            );
        }

        return self::label($variant) . "\n"
            . __(
                'The one-time text is removed. The next post-event email uses the text on the Messages page.',
                'eventcrew'
            );
    }

    /** Asks before $text replaces the earlier one-time text of $variant. */
    private function askReplace(int $telegramUserId, int $chatId, string $variant, string $text): void
    {
        $next = $this->text->next($variant);
        $id = bin2hex(random_bytes(8));
        set_transient(
            self::PENDING_PREFIX . $id,
            ['user' => $telegramUserId, 'variant' => $variant, 'text' => $text],
            self::PENDING_TTL
        );

        $this->telegram->sendMessage(
            $chatId,
            $this->earlier($variant, (array) $next) . "\n\n" . __('Replace it with your text?', 'eventcrew'),
            ['inline_keyboard' => [[
                ['text' => '✅ ' . __('Replace', 'eventcrew'), 'callback_data' => self::PREFIX . 'ok:' . $id],
                ['text' => '✖️ ' . __('Keep', 'eventcrew'), 'callback_data' => self::PREFIX . 'no:' . $id],
            ]]]
        );
    }

    /**
     * "There is already a one-time text:", the text and who set it when.
     *
     * @param array<string, mixed> $next
     */
    private function earlier(string $variant, array $next): string
    {
        $setBy = null !== $this->notice ? $this->notice->setBy($next) : '';

        return self::label($variant) . "\n" . __('There is already a one-time text:', 'eventcrew')
            . "\n\n" . (string) ($next['written'] ?? '')
            . ('' !== $setBy ? "\n\n" . $setBy : '');
    }

    /** Saves $text for $variant, answers and tells the other organizers. */
    private function save(Person $person, int $chatId, string $variant, string $text): void
    {
        $button = $this->resetButton([$variant]);

        if (! TranslatedText::modelReady()) {
            $result = $this->text->setNext($variant, $text, $person->id);
            $this->notice?->changed($variant, $person->id, $text);
            $this->telegram->sendMessage(
                $chatId,
                self::saved($variant, $result['translated'], $result['texts']),
                $button
            );

            return;
        }

        $this->text->setNext($variant, $text, $person->id, false);
        $this->notice?->changed($variant, $person->id, $text);
        $sent = $this->telegram->sendMessage(
            $chatId,
            self::label($variant) . "\n"
                . __('Saved for the next post-event email only:', 'eventcrew')
                . "\n\n" . $text
                . "\n\n" . __('Translating… The translations will show here.', 'eventcrew'),
            $button
        );
        $messageId = (int) ($sent['message_id'] ?? 0);
        $language = Language::current();

        $this->later(function () use ($variant, $chatId, $messageId, $language, $button): void {
            $done = $this->text->translateNext($variant);

            if ([] === $done['texts']) {
                // Removed or replaced meanwhile: that answer tells the rest.
                return;
            }

            $reply = (string) Language::run(
                $language,
                static fn (): string => self::saved($variant, $done['translated'], $done['texts'])
            );

            if (0 === $messageId || null === $this->telegram->editMessageText($chatId, $messageId, $reply, $button)) {
                $this->telegram->sendMessage($chatId, $reply, $button);
            }
        });
    }

    /**
     * The one-time texts there are.
     *
     * @return array<int, string>
     */
    private function ownVariants(): array
    {
        return array_values(array_filter(
            FollowupText::VARIANTS,
            fn (string $v): bool => null !== $this->text->next($v)
        ));
    }

    /**
     * The "Reset to default" button for the one-time texts of $variants (one
     * button: both when there are two), null when there are none.
     *
     * @param array<int, string> $variants
     * @return array<string, mixed>|null
     */
    private function resetButton(array $variants): ?array
    {
        if ([] === $variants) {
            return null;
        }

        $target = 1 === count($variants) ? (string) $variants[0] : self::ALL;

        return ['inline_keyboard' => [[
            ['text' => '↩️ ' . OwnTextNotice::resetLabel(), 'callback_data' => self::PREFIX . 'reset:' . $target],
        ]]];
    }

    /**
     * The answer after a text is saved: each language's text when it is
     * translated, else the text once, with what every language gets.
     *
     * @param array<string, string> $texts
     */
    private static function saved(string $variant, bool $translated, array $texts): string
    {
        $reply = self::label($variant) . "\n" . __('Saved for the next post-event email only:', 'eventcrew') . "\n\n";

        if ($translated) {
            return $reply . NewsletterText::listed($texts);
        }

        return $reply . (string) reset($texts) . "\n\n" . self::untranslated();
    }

    /** Said under a one-time text that is not translated. */
    private static function untranslated(): string
    {
        return __('Not translated: every language gets this text as written. Translation is tried again before the email goes out.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
    }

    /**
     * Runs $work after the webhook has answered Telegram (on shutdown, after
     * the response is sent), or now when that is already past.
     */
    private function later(Closure $work): void
    {
        if (null !== $this->defer) {
            ($this->defer)($work);

            return;
        }

        if (! function_exists('add_action') || doing_action('shutdown')) {
            $work();

            return;
        }

        add_action('shutdown', static function () use ($work): void {
            ignore_user_abort(true);

            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }

            $work();
        }, 20);
    }

    /** The answer to "/followup" alone. */
    private function current(): string
    {
        $parts = [];

        foreach (FollowupText::VARIANTS as $variant) {
            $next = $this->text->next($variant);
            $part = self::label($variant) . "\n"
                . (null === $next
                    ? __('The next email uses the text on the Messages page.', 'eventcrew')
                    : __('The next email uses a one-time text.', 'eventcrew'))
                . "\n\n"
                . (null !== $next && ! $next['translated']
                    ? $next['written'] . "\n\n" . self::untranslated()
                    : NewsletterText::listed($this->texts($variant)));
            $setBy = null !== $next && null !== $this->notice ? $this->notice->setBy($next) : '';

            if ('' !== $setBy) {
                $part .= "\n\n" . $setBy;
            }

            $parts[] = $part;
        }

        return implode("\n\n————————\n\n", $parts) . "\n\n" . self::usage();
    }

    private static function usage(): string
    {
        return __('To set a text for the next post-event email only, send /followup came followed by the thank-you text, or /followup missed followed by the sorry text. {event} becomes the event’s name and {date} its date. To reset a version to the default, send /followup came reset or /followup missed reset; /followup reset resets both.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
    }

    private static function label(string $variant): string
    {
        return FollowupText::CAME === $variant
            ? __('Thank-you text (came)', 'eventcrew')
            : __('Sorry text (missed)', 'eventcrew');
    }

    /**
     * The text of $variant the next email uses, in every site language.
     *
     * @return array<string, string>
     */
    private function texts(string $variant): array
    {
        $texts = [];

        foreach (SiteLanguages::codes() as $lang) {
            $texts[$lang] = $this->text->text($variant, $lang)['text'];
        }

        return $texts;
    }
}

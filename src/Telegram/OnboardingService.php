<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\AuthTokenRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\JoinAnswers;
use EventCrew\Support\Language;
use EventCrew\Support\Logger;
use EventCrew\Support\Mailer;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\PrivacyPolicy;

/**
 * Turns a Telegram account into a verified person.
 *
 * A person's identity here is a verified email, never a WordPress user, so a
 * bot user who taps Join has to give one first. The flow is: /start in the
 * bot's private chat asks for an email; the next message is taken as that
 * email; a person row is created if none has it yet, and a single-use
 * confirmation link is emailed. The slot itself stays out of reach until the
 * email is confirmed - that gate lives in BoardService.
 *
 * Typing an email links nothing. The Telegram account waits beside the token
 * and is linked only when the emailed link is clicked (VerificationController),
 * because the click is the only proof the typist owns the address. Linking on
 * the typed address alone let anyone take over another person's account -
 * their tasks, credits and free-entry tickets - by typing their email.
 *
 * A newcomer (a new address, or one still waiting that was never confirmed)
 * is asked three questions for the organizers before the link goes out
 * (JoinAnswers): have you worked on the crew here before, about how many
 * times, and how many free-entry tickets do you think you should have. Each
 * has a Skip button, and a number can be tapped or typed. The link is
 * emailed after the last answer or a Skip, so the organizers' "wants to
 * join" notice, sent when the link is opened, has the answers.
 */
final class OnboardingService
{
    use GroupBreadcrumb;

    /** The auth-token purpose for a bot email confirmation. */
    public const VERIFY_PURPOSE = 'email_verify';

    /**
     * Callback data of the "Use another email" button under the "Check your
     * email" message: asks for the address again (start()), for someone
     * whose link never came or who typed the wrong address.
     */
    public const AGAIN_DATA = 'onb:again';

    /** Callback data prefix of the newcomer's question buttons: "nwq:<answer>". */
    public const JOIN_PREFIX = 'nwq:';

    private const AWAIT_EMAIL_PREFIX = 'eventcrew_tg_await_email_';

    /** The newcomer's questions waiting for an answer, per Telegram user. */
    private const AWAIT_JOIN_PREFIX = 'eventcrew_tg_await_join_';

    private const STEP_BEFORE = 'before';
    private const STEP_TIMES = 'times';
    private const STEP_TICKETS = 'tickets';
    private const PENDING_LINK_PREFIX = 'eventcrew_tg_pending_link_';
    private const TOKEN_TTL = DAY_IN_SECONDS;

    public function __construct(
        private readonly PersonRepository $people,
        private readonly AuthTokenRepository $tokens,
        private readonly TelegramClient $telegram,
        private readonly Logger $logger,
        private readonly Mailer $mailer
    ) {
    }

    /**
     * Handles /start. In a private chat an already-verified account is told it
     * is set up; anyone else is asked for their email, and we remember for a few
     * minutes that this user's next message is that answer.
     *
     * Asked in a group it can't run: Telegram forbids a bot from opening a DM to
     * someone who hasn't messaged it first, so onboarding a newcomer by DM is
     * impossible. Instead the group gets a one-tap link into the bot's private
     * chat, where /start then works normally.
     */
    public function start(int $telegramUserId, int $chatId, string $displayName, bool $isPrivate = true): void
    {
        if (! $isPrivate) {
            // Posted in the group, which reads English.
            Language::run(Language::ENGLISH, fn () => $this->nudgeToPrivateChat($chatId));

            return;
        }

        $existing = $this->people->findByTelegramUserId($telegramUserId);

        if (null !== $existing && $existing->isEmailVerified()) {
            $this->telegram->sendMessage(
                $chatId,
                __('You are already set up. Tap a task on the board to sign up.', 'eventcrew')
            );

            return;
        }

        // A /start sent again begins again.
        delete_transient(self::AWAIT_JOIN_PREFIX . $telegramUserId);
        set_transient(
            self::AWAIT_EMAIL_PREFIX . $telegramUserId,
            ['chat_id' => $chatId, 'name' => $displayName],
            15 * MINUTE_IN_SECONDS
        );

        $this->telegram->sendMessage(
            $chatId,
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            __("Welcome! What's your email address? I'll send a link to confirm it, and then you can sign up for tasks.", 'eventcrew')
            . "\n\n" . PrivacyPolicy::noticeLine(),
            ['inline_keyboard' => array_merge(
                '' === PrivacyPolicy::noticeUrl() ? [] : [[[
                    'text' => PrivacyPolicy::noticeLinkLabel(),
                    'url' => PrivacyPolicy::noticeUrl(),
                ]]],
                [MenuButtons::cancelRow()]
            )]
        );
    }

    /**
     * The same privacy notice the web form carries, as a named link: a button
     * in Telegram, an action in the email.
     *
     * The bot asks for exactly what the web form asks for, so it owes the same
     * answer at the same moment - a person onboarding through Telegram should
     * not have to find the website to learn what happens to their address.
     * With no notice published the sentence stands alone rather than trailing
     * a broken link, so this is null.
     *
     * @return array{label: string, url: string}|null
     */
    private function privacyAction(): ?array
    {
        $url = PrivacyPolicy::noticeUrl();

        return '' === $url ? null : ['label' => PrivacyPolicy::noticeLinkLabel(), 'url' => $url];
    }

    public function isAwaitingEmail(int $telegramUserId): bool
    {
        return false !== get_transient(self::AWAIT_EMAIL_PREFIX . $telegramUserId);
    }

    /**
     * The Cancel button: forgets the "what's your email?" question. True when it was
     * waiting.
     */
    public function cancelPending(int $telegramUserId): bool
    {
        $waiting = $this->isAwaitingEmail($telegramUserId) || $this->isAwaitingJoin($telegramUserId);
        delete_transient(self::AWAIT_EMAIL_PREFIX . $telegramUserId);
        delete_transient(self::AWAIT_JOIN_PREFIX . $telegramUserId);

        return $waiting;
    }

    /** Whether the newcomer's questions wait for this user's answer. */
    public function isAwaitingJoin(int $telegramUserId): bool
    {
        return is_array(get_transient(self::AWAIT_JOIN_PREFIX . $telegramUserId));
    }

    /** Whether a typed message from this user answers a number question (how many times, how many tickets). */
    public function isAwaitingJoinNumber(int $telegramUserId): bool
    {
        $pending = get_transient(self::AWAIT_JOIN_PREFIX . $telegramUserId);

        return is_array($pending) && in_array($pending['step'] ?? '', [self::STEP_TIMES, self::STEP_TICKETS], true);
    }

    /**
     * A tap on a question button: "nwq:y" (yes), "nwq:n" (no), "nwq:s"
     * (skip), "nwq:t:<n>" (times), "nwq:k:<n>" (tickets).
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onJoinTap(array $callbackQuery): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $fromId = (int) ($callbackQuery['from']['id'] ?? 0);
        $chatId = (int) ($callbackQuery['message']['chat']['id'] ?? 0);
        $messageId = (int) ($callbackQuery['message']['message_id'] ?? 0);
        $data = (string) ($callbackQuery['data'] ?? '');
        $pending = 0 === $fromId ? false : get_transient(self::AWAIT_JOIN_PREFIX . $fromId);

        if (! is_array($pending) || 1 !== preg_match('/^nwq:(y|n|s|[tk]:\d{1,4})$/', $data, $m)) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('This question has run out. Send /start to begin again.', 'eventcrew'),
                true
            );

            if (0 !== $chatId && 0 !== $messageId) {
                $this->telegram->editMessageReplyMarkup($chatId, $messageId, ['inline_keyboard' => []]);
            }

            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);
        $this->answerJoin($fromId, 0 !== $chatId ? $chatId : (int) ($pending['chat_id'] ?? 0), $pending, $m[1], $messageId);
    }

    /**
     * A typed answer to a number question. Not a whole number in range: the
     * person is told the range and the question stays.
     */
    public function captureJoinNumber(int $telegramUserId, int $chatId, string $text): void
    {
        $pending = get_transient(self::AWAIT_JOIN_PREFIX . $telegramUserId);

        if (! is_array($pending)) {
            return;
        }

        $times = self::STEP_TIMES === ($pending['step'] ?? '');
        [$min, $max] = $times
            ? [JoinAnswers::MIN_TIMES, JoinAnswers::MAX_TIMES]
            : [JoinAnswers::MIN_TICKETS, JoinAnswers::MAX_TICKETS];
        $number = JoinAnswers::number($text);

        if (null === $number || $number < $min || $number > $max) {
            $this->telegram->sendMessage(
                $chatId,
                sprintf(
                    /* translators: 1: the smallest number, 2: the largest number */
                    __('Send a number from %1$d to %2$d, or tap Skip.', 'eventcrew'),
                    $min,
                    $max
                ),
                $times ? self::timesMarkup() : self::ticketsMarkup()
            );

            return;
        }

        $this->answerJoin($telegramUserId, $chatId, $pending, ($times ? 't:' : 'k:') . $number, 0);
    }

    /**
     * One answer: stores it and asks the next question, or ends the
     * questions and emails the link. $messageId is the question tapped, which
     * is rewritten in place (0 for a typed answer: the next question is a new
     * message).
     *
     * @param array<string, mixed> $pending
     */
    private function answerJoin(int $telegramUserId, int $chatId, array $pending, string $answer, int $messageId): void
    {
        $personId = (int) ($pending['person_id'] ?? 0);
        $key = self::AWAIT_JOIN_PREFIX . $telegramUserId;

        if ('y' === $answer) {
            $this->storeAnswers($personId, JoinAnswers::entries(JoinAnswers::YES));
            set_transient($key, ['step' => self::STEP_TIMES] + $pending, self::TOKEN_TTL);
            $this->showQuestion($chatId, $messageId, self::timesText(), self::timesMarkup());

            return;
        }

        if ('n' === $answer) {
            $this->storeAnswers($personId, JoinAnswers::entries(JoinAnswers::NO));
        } elseif (str_starts_with($answer, 't:')) {
            $times = (int) substr($answer, 2);
            $this->storeAnswers($personId, JoinAnswers::entries(JoinAnswers::YES, $times));
            set_transient($key, ['step' => self::STEP_TICKETS, 'times' => $times] + $pending, self::TOKEN_TTL);
            $this->showQuestion($chatId, $messageId, self::ticketsText(), self::ticketsMarkup());

            return;
        } elseif (str_starts_with($answer, 'k:')) {
            $times = isset($pending['times']) ? (int) $pending['times'] : null;
            $this->storeAnswers($personId, JoinAnswers::entries(JoinAnswers::YES, $times, (int) substr($answer, 2)));
        }

        // No, the tickets, or Skip: the questions are done.
        delete_transient($key);
        $this->showQuestion($chatId, $messageId, '✅ ' . __('Thanks, the organizers will see your answers.', 'eventcrew'), null);
        $this->sendLink(
            $telegramUserId,
            $chatId,
            $personId,
            (string) ($pending['email'] ?? ''),
            (string) ($pending['name'] ?? '')
        );
    }

    /**
     * Keeps the answers on the person while the organizers have not been
     * asked yet (JoinAnswers::canAnswer()): a later answer replaces an
     * earlier one.
     *
     * @param array<string, string> $entries
     */
    private function storeAnswers(int $personId, array $entries): void
    {
        $person = $personId > 0 ? $this->people->find($personId) : null;

        if (null !== $person && JoinAnswers::canAnswer($person)) {
            $this->people->replaceRoleNotices($personId, JoinAnswers::KEYS, $entries);
        }
    }

    /**
     * Shows a question: the tapped message rewritten, or a new message.
     * $markup null: no buttons.
     *
     * @param array<string, mixed>|null $markup
     */
    private function showQuestion(int $chatId, int $messageId, string $text, ?array $markup): void
    {
        if (0 === $chatId) {
            return;
        }

        if ($messageId > 0) {
            $this->telegram->editMessageText($chatId, $messageId, $text, $markup ?? ['inline_keyboard' => []]);

            return;
        }

        if (null !== $markup) {
            $this->telegram->sendMessage($chatId, $text, $markup);
        }
    }

    /** The first question, with Yes, No and Skip. */
    private static function beforeText(): string
    {
        return __('A few questions for the organizers, then I’ll email you the link. Have you worked on the crew here before?', 'eventcrew');
    }

    /** @return array<string, mixed> */
    private static function beforeMarkup(): array
    {
        return ['inline_keyboard' => [
            [
                ['text' => __('Yes', 'eventcrew'), 'callback_data' => self::JOIN_PREFIX . 'y'],
                ['text' => __('No', 'eventcrew'), 'callback_data' => self::JOIN_PREFIX . 'n'],
            ],
            [self::skipButton()],
        ]];
    }

    private static function timesText(): string
    {
        return __('About how many times? Tap a number or type one.', 'eventcrew');
    }

    /** @return array<string, mixed> */
    private static function timesMarkup(): array
    {
        return ['inline_keyboard' => [
            self::numberRow('t', 1, 5),
            self::numberRow('t', 6, 10),
            [self::skipButton()],
        ]];
    }

    private static function ticketsText(): string
    {
        return __('How many free-entry tickets do you think you should have? Tap a number or type one.', 'eventcrew');
    }

    /** @return array<string, mixed> */
    private static function ticketsMarkup(): array
    {
        return ['inline_keyboard' => [
            self::numberRow('k', 0, 4),
            self::numberRow('k', 5, 9),
            [self::skipButton()],
        ]];
    }

    /**
     * Buttons $from to $to, each answering "nwq:<$verb>:<n>".
     *
     * @return list<array{text: string, callback_data: string}>
     */
    private static function numberRow(string $verb, int $from, int $to): array
    {
        return array_map(
            static fn (int $n): array => ['text' => (string) $n, 'callback_data' => self::JOIN_PREFIX . $verb . ':' . $n],
            range($from, $to)
        );
    }

    /** @return array{text: string, callback_data: string} */
    private static function skipButton(): array
    {
        return ['text' => __('Skip', 'eventcrew'), 'callback_data' => self::JOIN_PREFIX . 's'];
    }

    /**
     * Points a group at the bot's private chat with a one-tap deep link, for
     * /start where onboarding can only happen in the DM. Falls back to plain
     * guidance if the bot's username isn't cached yet.
     */
    private function nudgeToPrivateChat(int $chatId): void
    {
        $username = trim((string) get_option(BoardService::USERNAME_OPTION, ''));

        if ('' === $username) {
            $this->telegram->sendMessage(
                $chatId,
                __('Open the bot in a private chat and send /start there to set up your account.', 'eventcrew')
            );

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            __('Let’s set you up in a private chat — tap below to open the bot.', 'eventcrew'),
            ['inline_keyboard' => [[[
                'text' => __('Set me up →', 'eventcrew'),
                'url' => 'https://t.me/' . $username . '?start=' . StartCode::append('onboard'),
            ]]]]
        );
    }

    /**
     * Handles the message that follows /start: the user's email. Validates it,
     * finds or creates the person, issues a confirmation token and sends the
     * magic link. The Telegram account is only remembered next to the token;
     * the click links it.
     */
    public function captureEmail(int $telegramUserId, int $chatId, string $text): void
    {
        $pending = get_transient(self::AWAIT_EMAIL_PREFIX . $telegramUserId);

        if (false === $pending) {
            return;
        }

        $email = sanitize_email(trim($text));

        if ('' === $email || ! is_email($email)) {
            $this->telegram->sendMessage(
                $chatId,
                __("That doesn't look like an email address. Try again?", 'eventcrew')
            );

            return;
        }

        // A Telegram id already linked to a different email is left alone: the
        // column is unique, and reusing it would splice two people's histories
        // - and their earned credits - onto one account.
        $linked = $this->people->findByTelegramUserId($telegramUserId);

        if (null !== $linked && 0 !== strcasecmp($linked->email, $email)) {
            $this->telegram->sendMessage(
                $chatId,
                // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                __('This Telegram account is already linked to another email. Contact an organizer if that is wrong.', 'eventcrew')
            );
            delete_transient(self::AWAIT_EMAIL_PREFIX . $telegramUserId);

            return;
        }

        $displayName = is_array($pending) ? (string) ($pending['name'] ?? '') : '';
        [$personId, $askable] = $this->findOrCreate($email, $displayName);
        delete_transient(self::AWAIT_EMAIL_PREFIX . $telegramUserId);

        // A newcomer answers the organizers' questions first; the link goes
        // out after the last answer or a Skip (answerJoin()).
        if ($askable) {
            set_transient(
                self::AWAIT_JOIN_PREFIX . $telegramUserId,
                [
                    'step' => self::STEP_BEFORE,
                    'person_id' => $personId,
                    'email' => $email,
                    'name' => $displayName,
                    'chat_id' => $chatId,
                ],
                self::TOKEN_TTL
            );
            $this->telegram->sendMessage($chatId, self::beforeText(), self::beforeMarkup());

            return;
        }

        $this->sendLink($telegramUserId, $chatId, $personId, $email, $displayName);
    }

    /**
     * Issues the confirmation token, keeps the Telegram account beside it,
     * emails the link and tells the chat to look for it.
     */
    private function sendLink(int $telegramUserId, int $chatId, int $personId, string $email, string $displayName): void
    {
        if ($personId <= 0 || '' === $email) {
            return;
        }

        $rawToken = $this->tokens->issue($personId, self::VERIFY_PURPOSE, self::TOKEN_TTL);
        set_transient(
            self::pendingLinkKey($rawToken),
            ['telegram_user_id' => $telegramUserId, 'telegram_chat_id' => $chatId],
            self::TOKEN_TTL
        );

        $this->sendVerificationEmail($personId, $email, $displayName, $rawToken);

        $this->telegram->sendMessage(
            $chatId,
            __('Check your email and tap the link to confirm it. Then pick a task on the board.', 'eventcrew')
            . "\n\n"
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            . __('Nothing after a few minutes, not even in spam? Try again, with the same or another address.', 'eventcrew'),
            ['inline_keyboard' => [[[
                'text' => __('✏️ Use another email', 'eventcrew'),
                'callback_data' => self::AGAIN_DATA,
            ]]]]
        );
    }

    /**
     * A tap on "Use another email": the button goes, and the email question
     * is asked again, as a /start sent again would. The link already sent
     * stays valid until it runs out; whichever is opened links the account.
     *
     * @param array<string, mixed> $callbackQuery
     */
    public function onAgainTap(array $callbackQuery): void
    {
        $fromId = (int) ($callbackQuery['from']['id'] ?? 0);
        $chatId = (int) ($callbackQuery['message']['chat']['id'] ?? 0);
        $messageId = (int) ($callbackQuery['message']['message_id'] ?? 0);
        $this->telegram->answerCallbackQuery((string) ($callbackQuery['id'] ?? ''));

        if (0 === $fromId || 0 === $chatId) {
            return;
        }

        if (0 !== $messageId) {
            $this->telegram->editMessageReplyMarkup($chatId, $messageId, ['inline_keyboard' => []]);
        }

        $from = is_array($callbackQuery['from'] ?? null) ? $callbackQuery['from'] : [];
        $name = trim((string) ($from['first_name'] ?? '') . ' ' . (string) ($from['last_name'] ?? ''));
        $this->start($fromId, $chatId, $name);
    }

    /**
     * A new email gets its person row straight away, but without Telegram ids:
     * those are linked when the emailed link is clicked. Answers the person's
     * id and whether they are a newcomer to ask the questions
     * (JoinAnswers::canAnswer()).
     *
     * @return array{0: int, 1: bool}
     */
    private function findOrCreate(string $email, string $displayName): array
    {
        $person = $this->people->findByEmail($email);

        if (null === $person) {
            return [$this->people->create([
                'email' => $email,
                'display_name' => $displayName,
                // Waits for the team's OK before taking a task (NewcomerApproval).
                'approval' => Person::APPROVAL_WAITING,
            ]), true];
        }

        return [$person->id, JoinAnswers::canAnswer($person)];
    }

    /**
     * Takes the Telegram account waiting on a confirmation token, and forgets
     * it so it is used once. Null when nothing is waiting.
     *
     * Called only after the token itself has been consumed, so an expired,
     * spent or unknown token never reaches a link.
     *
     * @return array{telegram_user_id: int, telegram_chat_id: int}|null
     */
    public static function takePendingLink(string $rawToken): ?array
    {
        $key = self::pendingLinkKey($rawToken);
        $pending = get_transient($key);
        delete_transient($key);

        if (! is_array($pending) || 0 === (int) ($pending['telegram_user_id'] ?? 0)) {
            return null;
        }

        return [
            'telegram_user_id' => (int) $pending['telegram_user_id'],
            'telegram_chat_id' => (int) ($pending['telegram_chat_id'] ?? 0),
        ];
    }

    /**
     * Keyed by the token's hash - the same value auth_tokens stores - so the
     * raw token is never written down, and each emailed link carries only the
     * Telegram account that asked for it.
     */
    private static function pendingLinkKey(string $rawToken): string
    {
        return self::PENDING_LINK_PREFIX . hash('sha256', trim($rawToken));
    }

    /**
     * Mails the confirmation link as a named button. Mailer logs a failed send,
     * and the plain-text part still carries the full link for a reader whose
     * mail client shows no HTML.
     */
    private function sendVerificationEmail(int $personId, string $email, string $displayName, string $rawToken): void
    {
        $link = add_query_arg(['token' => $rawToken], rest_url('eventcrew/v1/verify'));
        $greeting = '' !== $displayName ? $displayName : $email;

        // The text is the Messages page template (MessageTemplates). The
        // link is the button below it, so an edited text cannot lose it.
        $context = ['name' => $greeting];

        $this->mailer->toPerson(
            $personId,
            $email,
            MessageTemplates::subject('email_confirm', $context),
            MessageTemplates::body('email_confirm', $context),
            array_values(array_filter([
                ['label' => __('Confirm your email', 'eventcrew'), 'url' => $link],
                $this->privacyAction(),
            ]))
        );
    }
}

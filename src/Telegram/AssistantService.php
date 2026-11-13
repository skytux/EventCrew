<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use Closure;
use EventCrew\Models\Person;
use EventCrew\Repositories\GroupMessageRepository;
use EventCrew\Support\AssistantKnowledge;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\HelpPage;
use EventCrew\Support\Language;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\OrganizerGuide;
use EventCrew\Web\WebAssistant;

/**
 * Plain messages to the bot, read by a language model.
 *
 * The router hands over a message only when nothing else wants it: it is not
 * a command, and in a private chat it does not answer a question the bot is
 * waiting on. When the helper is switched off or not configured, this class
 * does nothing and the bot behaves as it always has.
 *
 * Private chat: every such message gets an answer. Group: the model decides
 * whether the message was written to the bot and whether the facts answer it;
 * if not, or if anything goes wrong, the bot stays silent in the group.
 *
 * The model answers with one JSON object (see parseReply()). Its "action" is
 * checked by AssistantActions and runs through the router exactly as if the
 * person had typed the command or tapped the button. An action that changes
 * data is first shown with Confirm and Cancel; what to run is kept on the
 * server for ten minutes under a random id, bound to the person's Telegram
 * id, and checked again when Confirm is tapped.
 *
 * The message text goes to the configured server. It is not written to the
 * plugin's error log, but it is kept: in a private chat the messages and the
 * helper's answers are kept for 24 hours (ConversationLog) and sent along,
 * so the model can follow the
 * conversation. In a group, the messages written to the bot and its
 * answers are kept the same way, in a separate record per person and group.
 *
 * In a private chat the model may also set "forward" (feedback, unhappy,
 * very happy, wants a human), but only with the person's consent: the
 * prompt tells the model to first ask in its answer whether the person
 * wants this chat sent to the organizers, and to set "forward" only when the
 * person's next message says yes to that question (the question is in
 * CONVERSATION as the newest Bot line). ChatForwarder then emails that chat,
 * the question and the yes included, to the organizers, and the answer
 * tells the person so.
 *
 * The model is asked after the webhook has answered Telegram (on shutdown,
 * after fastcgi_finish_request() or litespeed_finish_request() where the
 * server has one), so a slow model never holds up the webhook.
 */
final class AssistantService implements WebAssistant
{
    /** Callback data prefix: ai:ok:<id> or ai:no:<id>. */
    public const PREFIX = 'ai:';

    public const PENDING_PREFIX = 'eventcrew_ai_pending_';
    public const PENDING_TTL = 10 * MINUTE_IN_SECONDS;

    public const RATE_PREFIX = 'eventcrew_ai_rate_';

    /** Characters of the person's message sent to the model. */
    private const MAX_INPUT_CHARS = 2000;

    /** Characters of the model's reply sent on to Telegram. */
    private const MAX_REPLY_CHARS = 3500;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LlmClient $llm,
        private readonly AssistantKnowledge $knowledge,
        private readonly HelpService $help,
        private readonly Logger $logger,
        private readonly bool $defer = true,
        private readonly ?ChatForwarder $forwarder = null,
        private readonly ?BotLog $botLog = null,
        private readonly ?Closure $replaceWarning = null
    ) {
    }

    /**
     * A plain message nothing else took. Returns true when the helper took it
     * (it may still stay silent), false when it is not for the helper.
     *
     * $run sends a made-up update through the router: it is how an action runs.
     *
     * @param array<string, mixed> $message
     * @param string|null $chosen The sender's saved language.
     * @param callable(array<string, mixed>): void $run
     */
    public function onMessage(array $message, ?string $chosen, callable $run): bool
    {
        if (! self::wants($message)) {
            return false;
        }

        $chatId = (int) $message['chat']['id'];
        $fromId = (int) $message['from']['id'];
        $isPrivate = 'private' === $message['chat']['type'];

        $limit = $this->countAgainstLimit($isPrivate, $fromId, $chatId);

        if ('notice' === $limit && $isPrivate) {
            $this->telegram->sendMessage($chatId, Language::run($chosen, static fn (): string => __(
                'That is all I can answer this hour. Commands still work: /help',
                'eventcrew'
            )));
        }

        if ('ok' !== $limit) {
            return true;
        }

        $work = function () use ($message, $chosen, $run): void {
            Language::run($chosen, function () use ($message, $chosen, $run): void {
                $this->answer($message, $chosen, $run);
            });
        };

        if (! $this->defer) {
            $work();

            return true;
        }

        // After the response is out: priority 20 runs after WordPress flushes
        // its output buffers on shutdown.
        add_action('shutdown', function () use ($work): void {
            $this->finishResponse();
            $work();
        }, 20);

        return true;
    }

    /**
     * Whether a message is one the helper reads at all: switched on, plain
     * text (no command), from a person (not a bot), in a private chat or a
     * group. In a group only a message to the bot: one that names it
     * (@username) or answers one of its own messages, not one it posted
     * for someone on the web. The bot reads every group message (privacy
     * mode off, for the group chat on the web, GroupRelay), and the helper
     * is not asked about each one.
     *
     * @param array<string, mixed> $message
     */
    public static function wants(array $message): bool
    {
        if (! AssistantSettings::isActive()) {
            return false;
        }

        $text = trim((string) ($message['text'] ?? ''));
        $type = (string) ($message['chat']['type'] ?? '');

        if (
            '' === $text
            || str_starts_with($text, '/')
            || 0 === (int) ($message['chat']['id'] ?? 0)
            || 0 === (int) ($message['from']['id'] ?? 0)
            || ! empty($message['from']['is_bot'])
            || ! in_array($type, ['private', 'group', 'supergroup'], true)
        ) {
            return false;
        }

        if ('private' === $type) {
            return true;
        }

        return self::mentionsBot($message)
            || (self::repliesToBot($message)
                && ! (new GroupMessageRepository())->isFromWeb(
                    (int) $message['chat']['id'],
                    (int) ($message['reply_to_message']['message_id'] ?? 0)
                ));
    }

    /**
     * A Confirm or Cancel tap under a proposed action.
     *
     * @param array<string, mixed> $callbackQuery
     * @param callable(array<string, mixed>): void $run
     */
    public function onConfirm(array $callbackQuery, callable $run): void
    {
        $callbackId = (string) ($callbackQuery['id'] ?? '');
        $fromId = (int) ($callbackQuery['from']['id'] ?? 0);
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chatId = (int) ($message['chat']['id'] ?? $fromId);
        $messageId = (int) ($message['message_id'] ?? 0);
        $shown = (string) ($message['text'] ?? '');

        if (1 !== preg_match('/^ai:(ok|no):([0-9a-f]{16})$/', (string) ($callbackQuery['data'] ?? ''), $m)) {
            $this->telegram->answerCallbackQuery($callbackId);

            return;
        }

        $key = self::PENDING_PREFIX . $m[2];
        $pending = get_transient($key);

        if (! is_array($pending) || (int) ($pending['user'] ?? 0) !== $fromId || 0 === $fromId) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('This question has expired. Please write again.', 'eventcrew'),
                true
            );

            return;
        }

        // Used once, whichever button it was.
        delete_transient($key);

        if ('no' === $m[1]) {
            $this->telegram->answerCallbackQuery($callbackId, __('Cancelled.', 'eventcrew'));
            $this->closeQuestion($chatId, $messageId, $shown, '✖️ ' . __('Cancelled.', 'eventcrew'));

            return;
        }

        // Checked again: who may do what can change in ten minutes. The
        // question is only ever asked in a private chat.
        $audience = $this->help->audience($fromId);
        $checked = AssistantActions::validate(
            (string) ($pending['action'] ?? ''),
            $audience,
            null,
            (string) current_time('Y-m-d'),
            $this->knowledge->offeredTaskIds((bool) ($audience['member'] ?? false), (bool) $audience['organizer'])
        );

        if (! $checked['ok']) {
            $this->telegram->answerCallbackQuery(
                $callbackId,
                __('That can no longer be done. /help lists what you can do.', 'eventcrew'),
                true
            );
            $this->closeQuestion($chatId, $messageId, $shown, '');

            return;
        }

        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : ['id' => $chatId, 'type' => 'private'];
        $from = is_array($callbackQuery['from'] ?? null) ? $callbackQuery['from'] : ['id' => $fromId];

        if (AssistantActions::RUN_TAP === $checked['run']) {
            // The board's own join/leave: it answers this tap with its usual
            // popup and redraws this message as the board.
            $run(['callback_query' => [
                'id' => $callbackId,
                'from' => $from,
                'message' => $message,
                'data' => $checked['payload'],
            ]]);
            $this->markRun($fromId, $checked['action'], true);

            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);
        $this->closeQuestion($chatId, $messageId, $shown, '✅');
        $run(['message' => [
            'message_id' => $messageId,
            'from' => $from,
            'chat' => $chat,
            'text' => $checked['payload'],
        ]]);
        $this->markRun($fromId, $checked['action'], true);
    }

    /**
     * Reads the model's answer: one JSON object, with or without text or a
     * code fence around it. Null when it is not usable. A missing or unknown
     * "forward" reads as null.
     *
     * @return array{aimed: bool, known: bool, lang: string, reply: string, action: ?string, forward: ?string}|null
     */
    public static function parseReply(string $content): ?array
    {
        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if (false === $start || false === $end || $end < $start) {
            return null;
        }

        $data = json_decode(substr($content, $start, $end - $start + 1), true);

        if (
            ! is_array($data)
            || ! is_bool($data['aimed'] ?? null)
            || ! is_bool($data['known'] ?? null)
            || ! is_string($data['reply'] ?? null)
        ) {
            return null;
        }

        $action = $data['action'] ?? null;

        if (null !== $action && ! is_string($action)) {
            return null;
        }

        $action = null === $action ? '' : trim($action);
        $forward = is_string($data['forward'] ?? null) ? strtolower(trim($data['forward'])) : '';

        return [
            'aimed' => $data['aimed'],
            'known' => $data['known'],
            'lang' => is_string($data['lang'] ?? null) ? strtolower(trim($data['lang'])) : '',
            'reply' => trim($data['reply']),
            'action' => '' === $action || 'null' === strtolower($action) ? null : $action,
            'forward' => in_array($forward, ChatForwarder::KINDS, true) ? $forward : null,
        ];
    }

    /**
     * A message typed (or spoken) into the helper box of the Crew app page,
     * by the signed-in $person. The same model, rules, facts and actions as
     * the private chat; the conversation record is the person's private one
     * (ConversationLog::keyFor()), shared with the bot when they have
     * Telegram. Nothing is sent anywhere: the page shows the answer.
     *
     * "status" is ok (an answer, maybe with an action), limited (the hourly
     * limit is reached) or error (the model did not answer). "action" is the
     * checked action (AssistantActions::validate()) or null; the page decides
     * how it runs (AssistantWebActions). "lang" is the language the person
     * wrote in, for the page's own words around the answer.
     *
     * @return array{status: string, text: string, lang: ?string, action: array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}|null}
     */
    public function answerWeb(Person $person, string $text): array
    {
        $text = mb_substr(trim($text), 0, self::MAX_INPUT_CHARS);
        $logKey = ConversationLog::keyFor($person->telegramUserId, $person->id);
        // The page's own lines too (AssistantActions::webEntries()).
        $audience = ['web' => true] + $this->help->audienceForPerson($person);
        $chosen = $person->language;

        if (! $this->withinWebLimit($logKey, (bool) $audience['organizer'])) {
            return [
                'status' => 'limited',
                'text' => $this->fixedWeb($chosen, 'limited'),
                'lang' => $chosen,
                'action' => null,
            ];
        }

        $knowledge = $this->knowledge->build(
            $audience['roleSlugs'] ?? [],
            (bool) $audience['organizer'],
            true,
            $person->id,
            $audience['organizer'] ? $text : '',
            true
        );
        // Read before this message is added: the message goes as the user turn.
        $history = ConversationLog::forPrompt($logKey);
        ConversationLog::add($logKey, ConversationLog::PERSON, $text);

        // The private-chat prompt, with the web page's own lines.
        $message = ['chat' => ['id' => 0, 'type' => 'private'], 'from' => ['id' => $logKey], 'text' => $text];
        $result = $this->llm->chat([
            ['role' => 'system', 'content' => $this->instructions($message, $audience, $knowledge['facts'], $history, true)],
            ['role' => 'user', 'content' => $text],
        ]);
        $parsed = $result['ok'] ? self::parseReply($result['content']) : null;

        if (null === $parsed) {
            if ($result['ok']) {
                // The kind only; the answer itself is never logged.
                $this->logger->warning('Assistant: the language model answer was not the expected JSON (bad_json).');
            }

            return ['status' => 'error', 'text' => $this->fixedWeb($chosen, 'error'), 'lang' => $chosen, 'action' => null];
        }

        $language = Language::normalize($parsed['lang']) ?? $chosen;
        $reply = $this->clip(trim($parsed['reply']));
        $checked = null;

        if (null !== $parsed['action']) {
            $checked = AssistantActions::validate(
                (string) $parsed['action'],
                $audience,
                $text,
                (string) current_time('Y-m-d'),
                $knowledge['tasks']
            );

            if (! $checked['ok']) {
                $reply = $this->fixedWeb(
                    $language,
                    AssistantActions::ERROR_NOT_REGISTERED === $checked['error'] ? 'register' : 'cannot'
                );
                $checked = null;
            }
        } elseif ('' === $reply) {
            $reply = $this->fixedWeb($language, 'unknown');
        }

        if ('' !== $reply) {
            ConversationLog::add($logKey, ConversationLog::BOT, $reply);
        }

        return ['status' => 'ok', 'text' => $reply, 'lang' => $language, 'action' => $checked];
    }

    /**
     * An action kept from an earlier answer, checked again for $person now
     * (Confirm on the page, up to ten minutes later): their roles, their
     * confirmed email and the tasks open to them may have changed since.
     *
     * @return array{ok: bool, error: string, action: string, confirm: bool, run: string, payload: string}
     */
    public function recheckWeb(Person $person, string $action): array
    {
        $audience = ['web' => true] + $this->help->audienceForPerson($person);

        return AssistantActions::validate(
            $action,
            $audience,
            null,
            (string) current_time('Y-m-d'),
            $this->knowledge->offeredTaskIds(true, (bool) $audience['organizer'])
        );
    }

    /**
     * Puts the "[showed /board]" or "[ran /join 12]" line in the person's
     * conversation record, after the page ran an action for them.
     */
    public function noteWeb(Person $person, string $action, bool $changed): void
    {
        $this->markRun(ConversationLog::keyFor($person->telegramUserId, $person->id), $action, $changed);
    }

    /**
     * Counts a page message against the person's hourly private limit. With
     * Telegram, the count is the bot's private chat's own (the same key), so
     * the page is no way around it.
     */
    private function withinWebLimit(int $logKey, bool $organizer): bool
    {
        $key = self::RATE_PREFIX . 'p' . $logKey . '_' . intdiv(time(), HOUR_IN_SECONDS);
        $count = (int) get_transient($key) + 1;
        set_transient($key, $count, HOUR_IN_SECONDS);

        $limit = AssistantSettings::privateLimit();

        if ($organizer) {
            $limit = max($limit, AssistantSettings::organizerLimit());
        }

        return $count <= $limit;
    }

    /** One of the page helper's own sentences, in $language. */
    private function fixedWeb(?string $language, string $which): string
    {
        return Language::run($language, static fn (): string => match ($which) {
            'unknown' => __(
                'I do not know that. Help in the top-right menu has more, or ask an organizer.',
                'eventcrew'
            ),
            'cannot' => __(
                'I cannot do that for you here. Help in the top-right menu shows what you can do.',
                'eventcrew'
            ),
            'register' => __('First confirm your email address in Settings.', 'eventcrew'),
            'limited' => __('That is all I can answer this hour. The page works as usual.', 'eventcrew'),
            default => __('Sorry, I could not answer just now. Please try again.', 'eventcrew'),
        });
    }

    /**
     * Asks the model and acts on its answer.
     *
     * @param array<string, mixed> $message
     * @param callable(array<string, mixed>): void $run
     */
    private function answer(array $message, ?string $chosen, callable $run): void
    {
        $chatId = (int) $message['chat']['id'];
        $fromId = (int) $message['from']['id'];
        $isPrivate = 'private' === $message['chat']['type'];
        $text = trim((string) $message['text']);

        if ($isPrivate) {
            $this->telegram->call('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing'], true);
        }

        $audience = $this->help->audience($fromId);
        // An organizer sees every role and role task, but only in a private
        // chat: a group answer must not list what the crew there may not see.
        // The Members display roles' tasks, and the person's own tasks,
        // tickets and credits, only in a private chat, where nobody else
        // reads the answer.
        $knowledge = $this->knowledge->build(
            $audience['roleSlugs'] ?? [],
            $isPrivate && $audience['organizer'],
            $isPrivate && (bool) ($audience['member'] ?? false),
            $isPrivate ? (int) ($audience['personId'] ?? 0) : 0,
            // The history of the people and nights the message names: only
            // for an organizer in a private chat.
            $isPrivate && $audience['organizer'] ? $text : ''
        );
        // Read before this message is added: the message itself goes as the
        // user turn. A group has its own record per person (0 = private).
        $logChat = $isPrivate ? 0 : $chatId;
        $history = ConversationLog::forPrompt($fromId, null, $logChat);

        if ($isPrivate) {
            ConversationLog::add($fromId, ConversationLog::PERSON, $text);
        }

        $result = $this->llm->chat([
            ['role' => 'system', 'content' => $this->instructions($message, $audience, $knowledge['facts'], $history)],
            ['role' => 'user', 'content' => mb_substr($text, 0, self::MAX_INPUT_CHARS)],
        ]);

        $parsed = $result['ok'] ? self::parseReply($result['content']) : null;

        if (null === $parsed) {
            if ($result['ok']) {
                // The kind only; the answer itself is never logged.
                $this->logger->warning('Assistant: the language model answer was not the expected JSON (bad_json).');
            }

            if ($isPrivate) {
                $this->tell($chatId, $fromId, $this->fixed($chosen, 'fallback'));
            }

            return;
        }

        $language = Language::normalize($parsed['lang']) ?? $chosen;

        if (! $isPrivate) {
            $this->answerGroup($message, $parsed);

            return;
        }

        if (null !== $parsed['action']) {
            $this->offerAction($message, $parsed, $language, $audience, $knowledge['tasks'], $run);

            return;
        }

        if ($parsed['known'] && '' !== $parsed['reply']) {
            $reply = $this->withForward($fromId, $parsed['forward'], $language, $this->clip($parsed['reply']));
            $this->tell($chatId, $fromId, $reply);

            return;
        }

        // Not known: the model's own words if it wrote some (in the person's
        // language), always with /help in them.
        $reply = $parsed['reply'];

        if ('' === $reply) {
            $reply = $this->fixed($language, 'unknown');
        } elseif (! str_contains($reply, '/help')) {
            $reply .= "\n\n/help";
        }

        $reply = $this->withForward($fromId, $parsed['forward'], $language, $this->clip($reply));
        $this->tell($chatId, $fromId, $reply);
    }

    /**
     * When the model marked the message to forward (which the prompt allows
     * only after the person said yes to the bot asking whether to send this
     * chat to the organizers), sends the chat to the organizers and adds to
     * $reply that it did. $reply unchanged when
     * nothing was sent (no forward, switched off, not verified, or already
     * sent this hour).
     */
    private function withForward(int $fromId, ?string $kind, ?string $language, string $reply): string
    {
        if (null === $kind || null === $this->forwarder) {
            return $reply;
        }

        $told = $reply . "\n\n" . $this->fixed($language, 'forwarded');

        return $this->forwarder->forward($fromId, $kind, $told) ? $told : $reply;
    }

    /**
     * In the group: answer only what was written to the bot and is known,
     * as a reply to that message. Never an action.
     *
     * @param array<string, mixed> $message
     * @param array{aimed: bool, known: bool, lang: string, reply: string, action: ?string, forward: ?string} $parsed
     */
    private function answerGroup(array $message, array $parsed): void
    {
        if (! $parsed['aimed']) {
            return;
        }

        // Only messages written to the bot are kept, in this person's
        // record for this group.
        $chatId = (int) $message['chat']['id'];
        $fromId = (int) $message['from']['id'];
        ConversationLog::add($fromId, ConversationLog::PERSON, trim((string) $message['text']), null, $chatId);

        // Links to other sites are removed, so a member cannot make the bot
        // post one under its own name.
        $reply = GroupLinkFilter::strip(
            $parsed['reply'],
            (string) wp_parse_url((string) home_url('/'), PHP_URL_HOST)
        );

        if (! $parsed['known'] || '' === $reply) {
            return;
        }

        $params = [
            'chat_id' => $chatId,
            'text' => $this->clip($reply),
        ];

        if (isset($message['message_id'])) {
            $params['reply_parameters'] = [
                'message_id' => (int) $message['message_id'],
                'allow_sending_without_reply' => true,
            ];
        }

        $this->telegram->call('sendMessage', $params);
        ConversationLog::add($fromId, ConversationLog::BOT, $params['text'], null, $chatId);
    }

    /**
     * An action the model chose, in a private chat: checked, then run
     * straight away when it only shows something, or put to the person with
     * Confirm and Cancel when it changes something.
     *
     * @param array<string, mixed> $message
     * @param array{aimed: bool, known: bool, lang: string, reply: string, action: ?string, forward: ?string} $parsed
     * @param array{organizer: bool, welcomeCrew: bool, verified: bool} $audience
     * @param array<int, int> $offeredTasks
     * @param callable(array<string, mixed>): void $run
     */
    private function offerAction(
        array $message,
        array $parsed,
        ?string $language,
        array $audience,
        array $offeredTasks,
        callable $run
    ): void {
        $chatId = (int) $message['chat']['id'];
        $fromId = (int) $message['from']['id'];

        $checked = AssistantActions::validate(
            (string) $parsed['action'],
            $audience,
            (string) $message['text'],
            (string) current_time('Y-m-d'),
            $offeredTasks
        );

        if (! $checked['ok']) {
            $this->botLog?->refusedAction((string) $parsed['action'], $fromId, true);
            $this->tell(
                $chatId,
                $fromId,
                $this->fixed(
                    $language,
                    AssistantActions::ERROR_NOT_REGISTERED === $checked['error'] ? 'register' : 'cannot'
                )
            );

            return;
        }

        if (! $checked['confirm']) {
            if ('' !== $parsed['reply']) {
                $this->tell($chatId, $fromId, $this->clip($parsed['reply']));
            }

            if (AssistantActions::RUN_TAP === $checked['run']) {
                $run(['callback_query' => [
                    'id' => '',
                    'from' => $message['from'],
                    'message' => ['chat' => $message['chat']],
                    'data' => $checked['payload'],
                ]]);
                $this->markRun($fromId, $checked['action'], false);

                return;
            }

            $run(['message' => [
                'message_id' => (int) ($message['message_id'] ?? 0),
                'from' => $message['from'],
                'chat' => $message['chat'],
                'text' => $checked['payload'],
                // A message for the group keeps the formatting the person wrote it with.
                'entities' => SayCommand::carryEntities($checked['payload'], $message),
            ]]);
            $this->markRun($fromId, $checked['action'], false);

            return;
        }

        $id = bin2hex(random_bytes(8));
        set_transient(
            self::PENDING_PREFIX . $id,
            ['user' => $fromId, 'action' => $checked['action']],
            self::PENDING_TTL
        );

        $text = ('' !== $parsed['reply'] ? $this->clip($parsed['reply']) . "\n\n" : '') . '➡️ ' . $checked['action'];

        // An organizer's own text that replaces an earlier one: the earlier
        // text and who set it, so Confirm replaces it knowingly.
        $warning = null === $this->replaceWarning ? '' : (string) Language::run(
            $language,
            fn (): string => (string) ($this->replaceWarning)($checked['action'])
        );

        if ('' !== $warning) {
            $text = $this->clip($text . "\n\n⚠️ " . $warning);
        }

        $buttons = Language::run($language, static fn (): array => [
            ['text' => '✅ ' . __('Confirm', 'eventcrew'), 'callback_data' => self::PREFIX . 'ok:' . $id],
            ['text' => '✖️ ' . __('Cancel', 'eventcrew'), 'callback_data' => self::PREFIX . 'no:' . $id],
        ]);

        $this->tell($chatId, $fromId, $text, ['inline_keyboard' => [$buttons]]);
    }

    /**
     * Puts a short line in the private conversation record saying which
     * command the helper ran, like "[showed /roster 14.11.]" or "[ran /door]":
     * the command and the date only, never its arguments or what it sent. So
     * the model's next turn knows what the person was shown.
     */
    private function markRun(int $userId, string $action, bool $changed): void
    {
        ConversationLog::add(
            $userId,
            ConversationLog::BOT,
            BotLog::marker($action, $changed, (string) current_time('Y-m-d'))
        );
    }

    /**
     * Sends an answer in a private chat and keeps it in the conversation.
     *
     * @param array<string, mixed>|null $markup
     */
    private function tell(int $chatId, int $userId, string $text, ?array $markup = null): void
    {
        $this->telegram->sendMessage($chatId, $text, $markup);
        ConversationLog::add($userId, ConversationLog::BOT, $text);
    }

    /**
     * The instructions for the model: the answer format, the rules, the
     * actions this person may ask for, and the facts.
     *
     * @param array<string, mixed> $message
     * @param array{organizer: bool, welcomeCrew: bool, verified: bool} $audience
     */
    private function instructions(
        array $message,
        array $audience,
        string $facts,
        string $history = '',
        bool $web = false
    ): string {
        $isPrivate = 'private' === $message['chat']['type'];
        // The page has no way to ask and forward: that stays in the bot.
        $forward = ! $web && $isPrivate && null !== $this->forwarder && AssistantSettings::forwardEnabled();
        $yes = static fn (bool $value): string => $value ? 'yes' : 'no';

        // On the page, the help, a command and the place that shows a thing
        // are the page's tabs, never the bot's commands (Owner 2026-11-13).
        $helpPlace = $web ? 'Help in the top-right menu' : '/help';
        $lines = [
            $web
                ? 'You are the helper of the crew web page (the Crew app) of EventCrew, the crew of a recurring'
                    . ' event. People earn free entry by doing tasks at the event; the page shows the tasks,'
                    . ' signs people up and gives tickets.'
                : 'You are the Telegram bot of EventCrew, the crew of a recurring event. People earn free entry'
                    . ' by doing tasks at the event; the bot shows the tasks, signs people up and gives tickets.'
                    . $this->ownName(),
            'Answer the person\'s message with ONE JSON object and nothing else:',
            '{"aimed": true or false, "known": true or false, "lang": "<two-letter code of the language'
                . ' the message is written in>", "reply": "<your answer>", "action": "<one action line>" or null'
                . ($forward ? ', "forward": null or "feedback", "unhappy", "very_happy" or "human"' : '') . '}',
            '',
            'Rules:',
            '- "reply" is in the language the message is written in. Plain text, no Markdown, at most 600 characters.',
            '- Use only the FACTS below. Never make up dates, times, prices, codes, names, links or numbers.',
            '- "known" is true when the FACTS answer the message, or when it needs no facts (a greeting, thanks).'
                . ' Otherwise "known" is false and "reply" says you do not know and points to ' . $helpPlace
                . ' or an organizer.',
            '- "This person" in the FACTS, when present, is the person\'s own coming tasks, tickets, completed'
                . ' tasks and free-entry credits, read just now: answer questions about them from it.',
            '- Never say you have no access to something that is in the FACTS, and never tell the person to look'
                . ' in the bot log, the scheduled jobs or wp-admin for it. When something truly is not in the FACTS,'
                . ' say so in one short sentence and name the ' . ($web ? 'tab of this page' : 'bot command')
                . ' that shows or changes it, if there is one.'
                . ' Never claim access you do not have, and never make up a status.',
            '- "action" is null, or exactly one line from ACTIONS with its <...> and [...] parts filled in from'
                . ' the message or the FACTS. Never use a command, task number or guest number that is not'
                . ' in ACTIONS, the FACTS or the message.',
            '- When the person asks for something an action does, give that action and keep "reply" short.'
                . ' Actions marked (asks to confirm) are shown with a Confirm button first: "reply" then says'
                . ' what will happen.',
            '- When the message does not say exactly which task, guest or night it means and more than one'
                . ' fits, "action" is null and "reply" asks which one, listing the choices. Never pick one'
                . ' for the person.',
            '- When the message is a greeting, "reply" greets back and says the person can simply write'
                . ' what they want in their own words, with one or two short examples (like "sign me up'
                . ' for a task on Saturday"), and that '
                . ($web ? 'Help in the top-right menu explains how it all works' : '/help lists the commands')
                . '. Do not answer a greeting with only a list of commands.',
            '- When the person asks how something affects their standing (a late cancel, a no-show, a missed'
                . ' or replaced task), explain it with "How standing works" in the FACTS and their own numbers in'
                . ' "This person" (standing now, and after one late cancel or one more completed task), in plain'
                . ' words and short. Never say you do not know how standing is counted.',
            '- Dates in actions are written like 1.8. or 2026-08-01.',
            '- Answer only with what fits this person: their roles, and whether they are an organizer or'
                . ' welcome crew (see "The person" below). Leave out completely every task, command and'
                . ' detail meant only for roles, organizers or welcome crew the person is not: do not'
                . ' explain it and do not say that it exists. If they ask about it, say only that an'
                . ' organizer can help. An organizer is the exception: they may hear about every role, role'
                . ' task and command.',
        ];

        if ($audience['organizer'] && ! $web) {
            $lines[] = '- This person is an organizer. When they ask to put someone on a task, or to take'
                . ' someone off one, give /assign or /unassign with the task name and the person\'s name as'
                . ' they wrote them, even when the name is partial or several people or tasks could fit: the'
                . ' bot then finds them, asks with buttons and confirms before anything changes. This works'
                . ' for every task, also for a role the person does not hold. The Organize task takes only'
                . ' someone who is an organizer: you do not know who is, so give /assign anyway and the bot'
                . ' checks and says so if they are not. Never say you cannot add someone to a task.';
        }

        if ($audience['organizer']) {
            $lines[] = '- For an organizer, "Current status" in the FACTS is live, read just now: the announcement'
                . ' email (newsletter), the post-event email, the ticket emails, the coming nights (tasks, who is on'
                . ' Organize, free-entry tickets, guest list, ticket holders, free entry open or closed, stopped'
                . ' emails), the recent cancellations (who, which task, when, whether the task is filled again)'
                . ' the organizers and the recent history (who did what, and when, in the last days). Answer'
                . ' status questions such as "has the announcement gone out?" or "who cancelled lately?" directly'
                . ' from it, with the names and times it gives.';
            $lines[] = '- For an organizer, "Recent history" in "Current status" and "History asked about" (the'
                . ' history of a person or night named in the message) list who did what, and when: sign-ups,'
                . ' cancellations, people taken off or put on tasks, take-overs, door check-ins, credits, tickets'
                . ' given away, people added or changed and settings changed. Answer "what happened", "who did'
                . ' it" and "when" questions from them, with the names and times they give. When a line says'
                . ' there are more, say the answer may not be complete and that asking about one person or one'
                . ' night by name or date shows more. Never send the organizer to the bot log, the task list or'
                . ' wp-admin for any of this.';
        }

        if ($audience['organizer'] && ! $web) {
            $lines[] = '- An organizer can also manage tasks in a private chat: /addtask, /edittask (time, places,'
                . ' notes, plus-ones), /deletetask, /maketasks (a night\'s tasks from the roles) and /mailtasks'
                . ' (the open-task email now). When they ask for one of these, give that command with the task'
                . ' or role name as they wrote it; the bot finds it, asks with buttons when unclear and confirms'
                . ' before anything changes. Never say you cannot change, add or delete a task.';
        }

        // Private-chat commands for organizers and members (ag-people).
        if ($audience['organizer'] && ! $web) {
            $lines[] = '- For an organizer, these commands do the work themselves; give the matching one instead'
                . ' of sending them to the web page: /mark (arrived, late, no-show or done), /standing,'
                . ' /newcomer, /ticket and /unticket, /unban, /freeentry close|open, /stop announcement or'
                . ' /stop email, /newboard, /sendtickets (or /sendtickets cancel) and /tell task ; message.'
                . ' Use names as the organizer wrote them: the bot finds the person, asks with buttons when'
                . ' unsure and confirms every change. For /tell keep the message word for word.';
            // parityA
            $lines[] = '- The Admin tab\'s other changes are commands too, and each asks with buttons and confirms:'
                . ' credits (menu:gift person ; credits), roles (menu:roles add|remove role ; person), the organizer'
                . ' role (/organizer on|off person), deleting a person (/deleteperson), the at-risk pass past the'
                . ' sign-up gate (/atriskpass), adding or changing a person (/addperson, /editperson), a role\'s'
                . ' settings (/editrole), the post-event email\'s do-not-send list (/optout) and the AI texts of'
                . ' the coming events (/aitext). A language change is menu:language with the code.';
        }

        if (! $web) {
            $lines[] = '- Anyone can see the crew of their own coming nights with /crew, and change their own name'
                . ' with /name new name or their email with /email new address (a link goes to the new address'
                . ' first). Give these when they ask who else works with them or want their name or email changed.';
        }

        if ($isPrivate) {
            $lines[] = '- This is a private chat with one person: "aimed" is true.';
            $lines[] = '- You remember this private chat for 24 hours: CONVERSATION below has what was said,'
                . ' oldest first. What commands showed is not in it: a Bot line like "[showed /roster 14.11.]"'
                . ' or "[ran /door]" only says that the bot ran that command for the person (and for which'
                . ' date) and sent its answer. Use it to understand what'
                . ' the person refers to, like "that one" or "and on Sunday?". When the FACTS and the'
                . ' conversation disagree, the FACTS are right: they are newer.';
            $lines[] = '- In a greeting, also say that you remember this chat for 24 hours, so the person can'
                . ' refer back to what was said.';

            if ($web) {
                $lines = [...$lines, ...self::webLines((bool) $audience['organizer'])];
            }

            if ($forward) {
                $lines[] = '- "forward" sends this chat to the organizers. It is "feedback" when the person gives'
                    . ' feedback about the event, the crew or the bot; "unhappy" when they are clearly unhappy'
                    . ' or complain; "very_happy" when they are clearly very happy or full of praise; "human"'
                    . ' when they ask to talk to a human or an organizer. Otherwise "forward" is null. When'
                    . ' "forward" is not null, "action" is null. Do not say in "reply" that the chat is sent:'
                    . ' the bot adds that itself.';
                $lines[] = '- The chat is sent only when the person agrees. When one of these four kinds fits and'
                    . ' you have not asked yet, "forward" stays null and "reply" answers the message and then asks'
                    . ' whether they want this chat sent to the organizers. Set "forward" to the kind only when your'
                    . ' previous message (the newest Bot line in CONVERSATION) asked that question and the person\'s'
                    . ' new message clearly says yes to it. When they say no, do not answer the question, or it is'
                    . ' unclear, "forward" is null, and you do not ask again in this conversation unless the person'
                    . ' brings it up again. This is the same for all four kinds, "human" too.';
            }
        } else {
            $lines[] = '- This is the crew\'s group chat. "aimed" is true only when the message is written to the'
                . ' bot: it mentions the bot, replies to the bot, or asks the bot something. Messages between'
                . ' people are not for you: "aimed" is false. When unsure, "aimed" is false. In the group'
                . ' "action" is always null.';
            $lines[] = '- The message mentions the bot: ' . $yes(self::mentionsBot($message))
                . '. It replies to a message of the bot: ' . $yes(self::repliesToBot($message)) . '.';
        }

        $lines[] = '';
        $roles = $audience['roles'] ?? [];
        $lines[] = sprintf(
            'The person: signed up with a confirmed email: %s. Organizer: %s. Welcome crew: %s. Roles: %s.',
            $yes($audience['verified']),
            $yes($audience['organizer']),
            $yes($audience['welcomeCrew']),
            [] !== $roles ? implode(', ', $roles) : 'none'
        );

        if ($isPrivate) {
            $lines[] = '';
            $lines[] = 'ACTIONS:';

            foreach (AssistantActions::catalogue($audience) as $entry) {
                $lines[] = '- ' . $entry['usage'] . ' : ' . $entry['what']
                    . ($entry['confirm'] ? ' (asks to confirm)' : '');
            }
        }

        $lines[] = '';
        $lines[] = 'FACTS:';
        $lines[] = $facts;

        if ($isPrivate && $audience['organizer']) {
            $lines[] = '';
            $lines[] = 'ORGANIZER GUIDE (how EventCrew works; this person is an organizer, so use it to answer'
                . ' how-to questions about running the crew and the plugin):';
            $lines[] = $this->organizerGuide();

            $note = HelpPage::infrastructure();

            if ('' !== $note) {
                $lines[] = '';
                $lines[] = 'INFRASTRUCTURE NOTE (written by the organizers):';
                $lines[] = $note;
            }
        }

        $lines[] = '';

        if ($isPrivate) {
            $lines[] = 'CONVERSATION (this private chat, last 24 hours):';
        } else {
            $lines[] = 'CONVERSATION (what this person wrote to you in this group, and your answers, last 24'
                . ' hours; other people\'s messages are not here):';
        }

        $lines[] = '' !== $history ? $history : '(nothing yet)';

        return implode("\n", $lines);
    }

    /**
     * The prompt lines of the Crew app page's helper box: the page is the
     * alternative to Telegram, so the answer names the page's tabs and
     * cards, never the bot, a command or a Telegram link.
     *
     * @return list<string>
     */
    private static function webLines(bool $organizer): array
    {
        $lines = [
            '- This message was written in the helper box of the crew web page (the Crew app). The page has'
                . ' the tabs Board (the open tasks, sign up), Tasks (the person\'s tasks, cancelling) and'
                . ' Tickets (the person\'s tickets)'
                . ($organizer
                    ? ', and for an organizer Admin (screens Roster, Tasks, Roles, Door, People, Messages)'
                    : '')
                . ', a Summary card with the person\'s numbers, and a menu at the top right with Settings (name'
                . ' and email, notifications, language), Help and Sign out. "Where things are on this page" in'
                . ' the FACTS says which tab or screen has what.',
            '- In "reply", never mention Telegram, the bot, a chat, a t.me link or a command starting with "/":'
                . ' name the tab or screen of this page instead ("Help in the top-right menu", "the Admin tab,'
                . ' People screen").'
                . ' When the FACTS, the guide or CONVERSATION name a bot command or a button of the bot, say'
                . ' where the same thing is on this page; when this page has no place for it, say only what it'
                . ' is, or that an organizer can help. Never suggest using Telegram or the bot.',
            '- Still give "action" lines from ACTIONS as usual. The page signs up, cancels, spends a credit on'
                . ' a ticket (menu:ticket, with the night\'s date when the person named one), changes the'
                . ' person\'s own name (/name <new name>) or language (menu:language <code>) after the person'
                . ' confirms, and then shows the real outcome; or it opens the matching tab or screen; for anything'
                . ' else it shows only your reply. So "reply" never says a sign-up, ticket or other change is done:'
                . ' the page asks first. "Give me a ticket" or "give myself a ticket" is menu:ticket, never a link'
                . ' to the Tickets tab. A free-entry ticket cannot be cancelled by its holder. A new email address'
                . ' is changed in Settings (a link goes to the new address), so /email only opens Settings.'
                . ' Bot lines in CONVERSATION may come from Telegram or from this page.',
            '- Never ask "Shall I do that?" or offer to do something without giving its action line: when you'
                . ' propose a change, "action" is that change, and the page asks Confirm or Cancel itself.'
                . ' Never send the person to a page or screen for something an action line does.',
        ];

        if ($organizer) {
            $lines[] = '- For an organizer, these action lines are asked with Confirm and Cancel and run on Confirm,'
                . ' with the real outcome shown after: putting a member on or taking them off a task (/assign,'
                . ' /unassign), marks (/mark), a ticket, a ticket removal, an unblock, free entry, stopping an'
                . ' announcement or post-event email, a new board, sending tickets, adding, changing, deleting or'
                . ' creating tasks, the open-task email, adding, changing or removing a guest, a group post (/say),'
                . ' a message to a task\'s crew (/tell), a newsletter sign-up (/subscribe), the newsletter text and'
                . ' the post-event email texts (/newsletter, /followup with the text or reset), credits'
                . ' (menu:gift with the person), roles (menu:roles add or remove), the organizer role (/organizer on'
                . ' or off), deleting a person (/deleteperson) and a newcomer\'s OK or No (/newcomer ok or no).'
                . ' When several people or tasks match, the page shows them as buttons. An organizer removes their'
                . ' own free-entry ticket with /unticket and their own name. The page shows its own question for'
                . ' these, not your reply. Any other action line only opens the matching Admin screen (with the'
                . ' date or name in it), and the page then says it is not done. So "reply" never says a change is'
                . ' done, made, sent or saved, and never says "not done yet" or "open it here" for a change it'
                . ' gives the line for: the page asks.';
            // parityA
            $lines[] = '- For an organizer, these also run on Confirm here: the at-risk pass past the sign-up gate'
                . ' (/atriskpass), adding a person (/addperson), changing a person\'s name, email or notes'
                . ' (/editperson), changing a role\'s settings (/editrole), the post-event email\'s do-not-send'
                . ' list (/optout add or remove) and an event\'s AI text (/aitext). /optout and /aitext alone'
                . ' open their Admin card.';
        }

        return $lines;
    }

    /**
     * The organizer guide without its contents list, and with each Markdown
     * link reduced to its text: the links point at headings of the email.
     */
    private function organizerGuide(): string
    {
        $text = OrganizerGuide::short() . "\n\n" . OrganizerGuide::details();

        return (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text);
    }

    /**
     * " Your name is Crew Bot (@crew_bot)." from what Telegram told
     * the webhook install (getMe), or "" when nothing is known yet.
     */
    private function ownName(): string
    {
        $name = trim((string) get_option(BoardService::NAME_OPTION, ''));
        $username = ltrim(trim((string) get_option(BoardService::USERNAME_OPTION, '')), '@');

        if ('' === $name && '' === $username) {
            return '';
        }

        if ('' === $name) {
            return ' Your name is @' . $username . '.';
        }

        return ' Your name is ' . $name . ('' !== $username ? ' (@' . $username . ')' : '') . '.';
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function mentionsBot(array $message): bool
    {
        $own = ltrim(trim((string) get_option(BoardService::USERNAME_OPTION, '')), '@');

        return '' !== $own && false !== stripos((string) ($message['text'] ?? ''), '@' . $own);
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function repliesToBot(array $message): bool
    {
        $from = $message['reply_to_message']['from'] ?? null;

        if (! is_array($from) || empty($from['is_bot'])) {
            return false;
        }

        $own = ltrim(trim((string) get_option(BoardService::USERNAME_OPTION, '')), '@');

        return '' === $own || 0 === strcasecmp($own, (string) ($from['username'] ?? ''));
    }

    /**
     * Counts this message against the hourly limit. 'ok' while under it,
     * 'notice' for the first message over it (a private chat is told once),
     * 'silent' after that.
     */
    private function countAgainstLimit(bool $isPrivate, int $fromId, int $chatId): string
    {
        $limit = $isPrivate ? AssistantSettings::privateLimit() : AssistantSettings::groupLimit();
        $who = $isPrivate ? 'p' . $fromId : 'g' . abs($chatId);
        $key = self::RATE_PREFIX . $who . '_' . intdiv(time(), HOUR_IN_SECONDS);

        $count = (int) get_transient($key) + 1;
        set_transient($key, $count, HOUR_IN_SECONDS);

        // Organizers have their own private limit. Looked up only past the
        // ordinary one, so most messages cost no extra query.
        if ($isPrivate && $count > $limit && $this->help->audience($fromId)['organizer']) {
            $limit = AssistantSettings::organizerLimit();
        }

        if ($count <= $limit) {
            return 'ok';
        }

        return $limit + 1 === $count ? 'notice' : 'silent';
    }

    /**
     * Takes the Confirm and Cancel buttons off the question, adding $note.
     */
    private function closeQuestion(int $chatId, int $messageId, string $shown, string $note): void
    {
        if (0 === $chatId || 0 === $messageId) {
            return;
        }

        $text = trim($shown . ('' !== $note ? "\n\n" . $note : ''));
        $this->telegram->editMessageText($chatId, $messageId, '' !== $text ? $text : '…');
    }

    /** One of the helper's own sentences, in $language. */
    private function fixed(?string $language, string $which): string
    {
        return Language::run($language, static fn (): string => match ($which) {
            'unknown' => __('I do not know that. /help lists the commands, or ask an organizer.', 'eventcrew'),
            'cannot' => __('I cannot do that for you. /help lists what you can do.', 'eventcrew'),
            'register' => __('First sign up with /start.', 'eventcrew'),
            'forwarded' => __('I have passed this chat on to the organizers.', 'eventcrew'),
            default => __('Right now I only understand commands: /help', 'eventcrew'),
        });
    }

    private function clip(string $text): string
    {
        return mb_substr($text, 0, self::MAX_REPLY_CHARS);
    }

    /** Sends the webhook's answer to Telegram now, so the rest runs after it. */
    private function finishResponse(): void
    {
        ignore_user_abort(true);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }
}

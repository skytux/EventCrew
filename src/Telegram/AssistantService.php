<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use Closure;
use EventCrew\Support\AssistantKnowledge;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\HelpPage;
use EventCrew\Support\Language;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\OrganizerGuide;

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
 * very happy, wants a human): ChatForwarder then emails that chat to the
 * organizers, and the answer tells the person so.
 *
 * The model is asked after the webhook has answered Telegram (on shutdown,
 * after fastcgi_finish_request() or litespeed_finish_request() where the
 * server has one), so a slow model never holds up the webhook.
 */
final class AssistantService
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
     * group.
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

        return '' !== $text
            && ! str_starts_with($text, '/')
            && 0 !== (int) ($message['chat']['id'] ?? 0)
            && 0 !== (int) ($message['from']['id'] ?? 0)
            && empty($message['from']['is_bot'])
            && in_array($type, ['private', 'group', 'supergroup'], true);
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

        // Checked again: who may do what can change in ten minutes.
        $checked = AssistantActions::validate(
            (string) ($pending['action'] ?? ''),
            $this->help->audience($fromId),
            null,
            (string) current_time('Y-m-d'),
            $this->knowledge->offeredTaskIds()
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
        $knowledge = $this->knowledge->build($audience['roleSlugs'] ?? [], $isPrivate && $audience['organizer']);
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
     * When the model marked the message to forward, sends the chat to the
     * organizers and adds to $reply that it did. $reply unchanged when
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
    private function instructions(array $message, array $audience, string $facts, string $history = ''): string
    {
        $isPrivate = 'private' === $message['chat']['type'];
        $forward = $isPrivate && null !== $this->forwarder && AssistantSettings::forwardEnabled();
        $yes = static fn (bool $value): string => $value ? 'yes' : 'no';

        $lines = [
            'You are the Telegram bot of EventCrew, the crew of a recurring event. People earn free entry'
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
                . ' Otherwise "known" is false and "reply" says you do not know and points to /help or an organizer.',
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
                . ' for a task on Saturday"), and that /help lists the commands. Do not answer a greeting'
                . ' with only a list of commands.',
            '- Dates in actions are written like 1.8. or 2026-08-01.',
            '- Answer only with what fits this person: their roles, and whether they are an organizer or'
                . ' welcome crew (see "The person" below). Leave out completely every task, command and'
                . ' detail meant only for roles, organizers or welcome crew the person is not: do not'
                . ' explain it and do not say that it exists. If they ask about it, say only that an'
                . ' organizer can help. An organizer is the exception: they may hear about every role, role'
                . ' task and command.',
        ];

        if ($audience['organizer']) {
            $lines[] = '- This person is an organizer. When they ask to put someone on a task, or to take'
                . ' someone off one, give /assign or /unassign with the task name and the person\'s name as'
                . ' they wrote them, even when the name is partial or several people or tasks could fit: the'
                . ' bot then finds them, asks with buttons and confirms before anything changes. This works'
                . ' for every task but Organize, also for a role the person does not hold. Never say you'
                . ' cannot add someone to a task.';
            $lines[] = '- An organizer can also manage tasks in a private chat: /addtask, /edittask (time, places,'
                . ' notes, plus-ones), /deletetask, /maketasks (a night\'s tasks from the roles) and /mailtasks'
                . ' (the open-task email now). When they ask for one of these, give that command with the task'
                . ' or role name as they wrote it; the bot finds it, asks with buttons when unclear and confirms'
                . ' before anything changes. Never say you cannot change, add or delete a task.';
        }

        // Private-chat commands for organizers and members (ag-people).
        if ($audience['organizer']) {
            $lines[] = '- For an organizer, these commands do the work themselves; give the matching one instead'
                . ' of sending them to the web page: /mark (arrived, late, no-show or done), /standing,'
                . ' /newcomer, /ticket and /unticket, /unban, /freeentry close|open, /stop announcement or'
                . ' /stop email, /newboard, /sendtickets (or /sendtickets cancel) and /tell task ; message.'
                . ' Use names as the organizer wrote them: the bot finds the person, asks with buttons when'
                . ' unsure and confirms every change. For /tell keep the message word for word.';
        }

        $lines[] = '- Anyone can see the crew of their own coming nights with /crew, and change their own name'
            . ' with /name new name or their email with /email new address (a link goes to the new address'
            . ' first). Give these when they ask who else works with them or want their name or email changed.';

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

            if ($forward) {
                $lines[] = '- "forward" sends this chat to the organizers. It is "feedback" when the person gives'
                    . ' feedback about the event, the crew or the bot; "unhappy" when they are clearly unhappy'
                    . ' or complain; "very_happy" when they are clearly very happy or full of praise; "human"'
                    . ' when they ask to talk to a human or an organizer. Otherwise "forward" is null. When'
                    . ' "forward" is not null, "action" is null. Do not say in "reply" that the chat is sent:'
                    . ' the bot adds that itself.';
            }
        } else {
            $lines[] = '- This is the crew\'s group chat. "aimed" is true only when the message is written to the'
                . ' bot: it mentions the bot, replies to the bot, or asks the bot something. Messages between'
                . ' people are not for you: "aimed" is false. When unsure, "aimed" is false. In the group'
                . ' "action" is always null.';
            $lines[] = '- The message mentions the bot: ' . $yes($this->mentionsBot($message))
                . '. It replies to a message of the bot: ' . $yes($this->repliesToBot($message)) . '.';
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
    private function mentionsBot(array $message): bool
    {
        $own = ltrim(trim((string) get_option(BoardService::USERNAME_OPTION, '')), '@');

        return '' !== $own && false !== stripos((string) ($message['text'] ?? ''), '@' . $own);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function repliesToBot(array $message): bool
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

<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Models\Person;
use EventCrew\Repositories\TaskRepository;
use EventCrew\Support\AssistantSettings;
use EventCrew\Support\Dates;
use EventCrew\Support\Language;
use EventCrew\Support\WebSession;
use EventCrew\Telegram\AssistantService;

/**
 * The helper box of the Crew app page: the bot's language-model helper
 * (AssistantService::answerWeb()), asked from the page by the signed-in
 * person.
 *
 * One admin-ajax action, ACTION, for the signed-in person only (the web
 * session cookie and its CSRF token, as the page's own forms):
 * - op=ask, text: the answer, and what the page can do with its action
 *   (AssistantWebActions). Signing up for or cancelling a task, and an
 *   organizer's change (WebChanges: the bot command's own question), is
 *   kept here for ten minutes under a random id, bound to the person, and
 *   comes back with Confirm or Cancel. A change the page only opens the
 *   place for, or has no place for, says it is not done;
 * - op=confirm, id: checks the kept action again and runs it once, with
 *   the real outcome as the answer;
 * - op=cancel, id: drops it.
 *
 * The message text is never logged. It goes to the configured model and
 * into the person's 24-hour conversation record (ConversationLog), as in
 * the bot's private chat.
 */
final class AssistantWebController
{
    public const ACTION = 'eventcrew_web_assistant';

    /** Characters one message from the page may have. */
    public const MAX_CHARS = 500;

    public const PENDING_PREFIX = 'eventcrew_ai_webpending_';

    public function __construct(
        private readonly WebAssistant $assistant,
        private readonly WebTaskSignup $signup,
        private readonly TaskRepository $tasks,
        private readonly SessionPerson $session,
        private readonly ?WebChanges $changes = null
    ) {
    }

    public function boot(): void
    {
        add_action('wp_ajax_' . self::ACTION, [$this, 'dispatch']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [$this, 'dispatch']);
    }

    /**
     * What the page's helper box needs, or null when the helper is off (or
     * not configured): then the page has no box.
     *
     * @return array{action: string, speech: string}|null
     */
    public static function view(Person $person): ?array
    {
        if (! AssistantSettings::isActive()) {
            return null;
        }

        $speech = match (Language::normalize($person->language) ?? Language::current()) {
            'fi' => 'fi-FI',
            'sv' => 'sv-SE',
            default => 'en-US',
        };

        return ['action' => self::ACTION, 'speech' => $speech];
    }

    /**
     * The admin-ajax entry point: reads the request, answers JSON. The
     * untested-by-design boundary; handle() is the tested part.
     */
    public function dispatch(): void
    {
        if (! defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        nocache_headers();

        $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';

        if ('post' !== $method) {
            wp_send_json(['ok' => false, 'error' => 'method'], 405);
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the web session's CSRF token is checked in handle().
        $posted = [
            'op' => isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '',
            'csrf' => isset($_POST['csrf']) ? sanitize_text_field(wp_unslash($_POST['csrf'])) : '',
            'id' => isset($_POST['id']) ? sanitize_key(wp_unslash($_POST['id'])) : '',
            'text' => isset($_POST['text']) ? sanitize_textarea_field(wp_unslash($_POST['text'])) : '',
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        [$status, $body] = $this->handle($this->session->current(), $posted);
        wp_send_json($body, $status);
    }

    /**
     * @param array<string, string> $posted op, csrf, and text (ask) or id (confirm, cancel)
     * @return array{0: int, 1: array<string, mixed>} status code and JSON body
     */
    public function handle(?Person $person, array $posted): array
    {
        if (null === $person) {
            return [401, ['ok' => false, 'error' => 'session']];
        }

        if (! WebSession::verifyCsrf($person->id, (string) ($posted['csrf'] ?? ''))) {
            return [403, ['ok' => false, 'error' => 'csrf']];
        }

        if (! AssistantSettings::isActive()) {
            return [404, ['ok' => false, 'error' => 'off']];
        }

        Language::enter($person->language);

        return match ((string) ($posted['op'] ?? '')) {
            'ask' => $this->ask($person, (string) ($posted['text'] ?? '')),
            'confirm' => $this->confirm($person, (string) ($posted['id'] ?? '')),
            'cancel' => $this->cancel($person, (string) ($posted['id'] ?? '')),
            default => [400, ['ok' => false, 'error' => 'op']],
        };
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function ask(Person $person, string $text): array
    {
        $text = trim($text);

        if ('' === $text) {
            return [400, ['ok' => false, 'error' => 'empty']];
        }

        if (mb_strlen($text) > self::MAX_CHARS) {
            return [400, ['ok' => false, 'error' => 'too_long']];
        }

        $answer = $this->assistant->answerWeb($person, $text);
        $body = ['ok' => true, 'status' => $answer['status'], 'reply' => [], 'action' => null];
        $reply = $answer['text'];
        $checked = $answer['action'];

        if (null !== $checked) {
            $plan = AssistantWebActions::plan($checked['action'], $person->isOrganizer, (string) current_time('Y-m-d'));
            $language = $answer['lang'];

            $change = AssistantWebActions::CHANGE === ($plan['op'] ?? '');

            if ($change && null === $this->changes) {
                $plan = ['kind' => AssistantWebActions::NONE];
            }

            if (AssistantWebActions::CONFIRM === $plan['kind'] && $change) {
                // The bot command's own question, or its answer why not; the
                // model's words are not shown, so they cannot claim it done.
                $changes = $this->changes;
                $asked = Language::run($language, static fn (): array => $changes->prepare($person, $checked['action']));
                $reply = (string) $asked['text'];

                if ($asked['ok']) {
                    $body['action'] = $this->confirmAction($person, $checked['action'], (array) $asked['state'], '', $language);
                }
            } elseif (AssistantWebActions::CONFIRM === $plan['kind']) {
                $body['action'] = $this->confirmAction($person, $checked['action'], [], $this->summary($plan, $language), $language);
            } elseif (AssistantWebActions::GOTO === $plan['kind']) {
                $this->assistant->noteWeb($person, $checked['action'], false);
                $body['action'] = ['kind' => AssistantWebActions::GOTO, 'targets' => $plan['targets'] ?? []];

                if (isset($plan['query'])) {
                    $body['action']['query'] = $plan['query'];
                }
            }

            if (AssistantWebActions::CONFIRM !== $plan['kind'] && ($change || AssistantWebActions::leavesUndone($checked['action']))) {
                $goto = AssistantWebActions::GOTO === $plan['kind'];
                $undone = (string) Language::run($language, static fn (): string => $goto
                    ? __('Not done yet: open it here.', 'eventcrew')
                    : __('Not done: this page cannot do that.', 'eventcrew'));
                $reply = $undone . "\n" . $reply;
            }

            // Anything else has no place on the page: no button, and never
            // an offer to go to Telegram. The answer text stands alone.
        }

        $body['reply'] = AssistantWebActions::segments($reply);

        return [200, $body];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function confirm(Person $person, string $id): array
    {
        $pending = $this->takePending($person, $id);

        if (null === $pending) {
            return [200, $this->told(__('This question has expired. Please write again.', 'eventcrew'), 'expired')];
        }

        // Checked again: the person's roles and the open tasks may have changed.
        $checked = $this->assistant->recheckWeb($person, $pending['action']);
        $plan = $checked['ok'] ? AssistantWebActions::plan($checked['action'], $person->isOrganizer) : ['kind' => ''];

        if (AssistantWebActions::CONFIRM !== $plan['kind']) {
            return [200, $this->told(__('That can no longer be done.', 'eventcrew'), 'refused')];
        }

        if (AssistantWebActions::CHANGE === ($plan['op'] ?? '')) {
            if (null === $this->changes || [] === $pending['state']) {
                return [200, $this->told(__('That can no longer be done.', 'eventcrew'), 'refused')];
            }

            $done = $this->changes->run($person, $checked['action'], $pending['state']);

            if ($done['changed']) {
                $this->assistant->noteWeb($person, $checked['action'], true);
            }

            $body = $this->told($done['text'], $done['changed'] ? 'changed' : 'unchanged', $done['changed']);
            $ticket = (string) ($done['ticket'] ?? '');

            // Lou 2026-11-14: one button that opens the ticket just spent. The
            // address is only in this answer, never in the text or a log.
            if ($done['changed'] && 1 === preg_match('#^https?://#i', $ticket)) {
                $body['action'] = ['kind' => 'ticket', 'label' => __('Open my ticket', 'eventcrew'), 'url' => $ticket];
            }

            return [200, $body];
        }

        $taskId = (int) ($plan['task'] ?? 0);
        $code = AssistantWebActions::JOIN === ($plan['op'] ?? '')
            ? $this->signup->claimAndNotify($person, $taskId)
            : $this->signup->dropAndNotify($person, $taskId);
        $changed = in_array($code, ['claimed', 'dropped', 'dropped_late', 'dropped_early'], true);

        if ($changed) {
            $this->assistant->noteWeb($person, $checked['action'], true);
        }

        $text = SignupController::noticeText($code);

        return [200, $this->told('' !== $text ? $text : __('That can no longer be done.', 'eventcrew'), $code, $changed)];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function cancel(Person $person, string $id): array
    {
        $this->takePending($person, $id);

        return [200, $this->told(__('Cancelled.', 'eventcrew'), 'cancelled')];
    }

    /**
     * Keeps $action (and a change's state) under a new id for this person,
     * and the confirm body the page shows: $summary above the buttons ('' for
     * none: a change's question is the answer itself).
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function confirmAction(Person $person, string $action, array $state, string $summary, ?string $language): array
    {
        $id = bin2hex(random_bytes(8));
        $kept = ['person' => $person->id, 'action' => $action];

        if ([] !== $state) {
            $kept['state'] = $state;
        }

        set_transient(self::PENDING_PREFIX . $id, $kept, AssistantService::PENDING_TTL);

        return [
            'kind' => AssistantWebActions::CONFIRM,
            'id' => $id,
            'summary' => $summary,
            'confirm' => (string) Language::run($language, static fn (): string => __('Confirm', 'eventcrew')),
            'cancel' => (string) Language::run($language, static fn (): string => __('Cancel', 'eventcrew')),
        ];
    }

    /**
     * The kept action under $id when it is this person's, removed so it runs
     * at most once; null when it expired, is someone else's or never was.
     *
     * @return array{action: string, state: array<string, mixed>}|null
     */
    private function takePending(Person $person, string $id): ?array
    {
        if (1 !== preg_match('/^[0-9a-f]{16}$/', $id)) {
            return null;
        }

        $key = self::PENDING_PREFIX . $id;
        $pending = get_transient($key);

        if (! is_array($pending) || (int) ($pending['person'] ?? 0) !== $person->id) {
            return null;
        }

        delete_transient($key);

        if (! is_string($pending['action'] ?? null)) {
            return null;
        }

        return ['action' => $pending['action'], 'state' => is_array($pending['state'] ?? null) ? $pending['state'] : []];
    }

    /**
     * "Sign up for DJ, Sat 14.11." or "Cancel DJ, Sat 14.11.", in $language.
     *
     * @param array{kind: string, op?: string, task?: int} $plan
     */
    private function summary(array $plan, ?string $language): string
    {
        $task = $this->tasks->find((int) ($plan['task'] ?? 0));
        $join = AssistantWebActions::JOIN === ($plan['op'] ?? '');

        return (string) Language::run($language, static function () use ($task, $join): string {
            $name = null === $task
                ? __('this task', 'eventcrew')
                : $task->roleDisplay() . ', ' . Dates::dayLabel($task->taskDate);

            return $join
                /* translators: %s: the task, like "DJ, Sat 14.11." */
                ? sprintf(__('Sign up for %s', 'eventcrew'), $name)
                /* translators: %s: the task, like "DJ, Sat 14.11." */
                : sprintf(__('Cancel %s', 'eventcrew'), $name);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function told(string $text, string $code, bool $changed = false): array
    {
        return [
            'ok' => true,
            'code' => $code,
            'changed' => $changed,
            'reply' => AssistantWebActions::segments($text),
        ];
    }
}

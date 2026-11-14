<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Support\BackgroundJobs;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\LlmClient;
use EventCrew\Support\Logger;
use EventCrew\Support\MessageAiText;
use EventCrew\Support\MessagePreview;
use EventCrew\Support\MessageTemplate;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\TicketReasons;
use EventCrew\Support\SiteLanguages;
use EventCrew\Support\TicketText;
use EventCrew\Support\TranslatedText;

/**
 * The Messages page, in tabs (&tab= in the address):
 * - Messages: every email and Telegram message kind (MessageTemplates), each
 *   with its subject, its text, the placeholders it can use and what each
 *   one becomes, the formatting that works, and "Reset to built-in text".
 *   Each kind is its own form, so saving one translates only that one;
 *   &kind= opens one. The Ticket entry also holds the text the ticket
 *   email uses now (TicketText) and its four {reason} lines
 *   (TicketReasons), saved with TICKET_ACTION.
 * - AI: the AI prompts and the organizers' own placeholders (AiPromptsPage).
 * - Coming events: the texts the AI wrote for each coming event and the
 *   one-time texts of the next post-event email and announcement
 *   (AiPromptsPage).
 * - Email look: the link to the Email template's block editor (EmailTemplate),
 *   while HTML email is on, and its reset.
 *
 * Each kind also has a Preview link, and the page a "Preview all" link
 * (previewUrl()): an admin-post page of its own that shows the message as
 * it would be sent, with sample data, in each site language
 * (MessagePreview), for the event and task picked on it. It sends nothing;
 * its "Write it now" (PREVIEW_WRITE_ACTION) has the AI write the kind's
 * text for that event.
 *
 * Each subject and text field of a kind has a "{ } Insert" link
 * (eventcrew-jobs.js) that lists the kind's placeholders with their values
 * for the preview's sample person and task (MessagePreview::placeholderSamples())
 * and puts the one picked at the cursor.
 */
final class MessagesPage
{
    public const PAGE_SLUG = 'eventcrew-messages';

    public const SAVE_ACTION = 'eventcrew_save_message';

    public const RESET_ACTION = 'eventcrew_reset_message';

    /** Saves the ticket email's text and {reason} lines (the Ticket entry). */
    public const TICKET_ACTION = 'eventcrew_save_ticket_text';

    /** The Messages tab entry that holds the ticket email's text. */
    public const TICKET_KIND = 'ticket';

    /** Opens the preview page of one kind, or of all of them (admin-post, GET). */
    public const PREVIEW_ACTION = 'eventcrew_preview_message';

    /** "Write it now" on the preview page: the AI text of one kind for one event (admin-post, POST). */
    public const PREVIEW_WRITE_ACTION = 'eventcrew_preview_write';

    public const TAB_MESSAGES = 'messages';

    public const TAB_AI = 'ai';

    public const TAB_EVENTS = 'events';

    public const TAB_EMAIL = 'email';

    public function __construct(
        private readonly View $view,
        private readonly MessageTemplates $templates,
        private readonly ?AiPromptsPage $ai = null,
        private readonly ?EmailTemplate $emailTemplate = null,
        private readonly ?LlmClient $llm = null,
        private readonly ?MessagePreview $preview = null
    ) {
    }

    /**
     * The tabs, in order: slug => label.
     *
     * @return array<string, string>
     */
    public static function tabs(): array
    {
        return [
            self::TAB_MESSAGES => __('Messages', 'eventcrew'),
            self::TAB_AI => __('AI', 'eventcrew'),
            self::TAB_EVENTS => __('Coming events', 'eventcrew'),
            self::TAB_EMAIL => __('Email look', 'eventcrew'),
        ];
    }

    /**
     * The address of $tab, with $args and a part of the page to land on.
     *
     * @param array<string, string> $args
     */
    public static function url(string $tab = self::TAB_MESSAGES, array $args = [], string $anchor = ''): string
    {
        return add_query_arg(['page' => self::PAGE_SLUG, 'tab' => $tab] + $args, admin_url('admin.php'))
            . ('' === $anchor ? '' : '#' . $anchor);
    }

    /**
     * The admin-only address of the preview of kind $kind ('' for the list
     * of every kind) in language $lang ('' for the admin's language), for
     * event $eventId and task $taskId (0: the default, the next one).
     */
    public static function previewUrl(string $kind = '', string $lang = '', int $eventId = 0, int $taskId = 0): string
    {
        $args = ['action' => self::PREVIEW_ACTION];

        if ('' !== $kind) {
            $args['kind'] = $kind;
        }

        if ('' !== $lang) {
            $args['lang'] = $lang;
        }

        if ($eventId > 0) {
            $args['event_id'] = (string) $eventId;
        }

        if ($taskId > 0) {
            $args['task_id'] = (string) $taskId;
        }

        return wp_nonce_url(add_query_arg($args, admin_url('admin-post.php')), self::PREVIEW_ACTION);
    }

    /**
     * admin-post (GET): the preview page, a page of its own outside the
     * wp-admin frame. Organizers only, nonce-checked. Sends nothing.
     */
    public function preview(): never
    {
        Admin::assertCanSave(self::PREVIEW_ACTION);

        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow');
        // The page and the emails in it have no scripts and must run none.
        header("Content-Security-Policy: script-src 'none'");

        $this->view->render('message-preview', $this->previewData());
        exit;
    }

    /**
     * What the preview page shows for the kind, language, event and task in
     * the address: the languages to switch to, and either the one kind's
     * preview with its event and task pickers or the list of every kind.
     * written is the outcome of "Write it now" ('ok', 'failed'), '' when
     * none was asked for.
     *
     * @return array{
     *     lang: string, languages: array<string, string>, kind: string,
     *     message: ?array<string, mixed>, entries: list<array{id: string, label: string, channel: string}>,
     *     events: list<array{id: int, title: string, date: string, past: bool}>,
     *     tasks: list<\EventCrew\Models\Task>, written: string
     * }
     */
    public function previewData(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- preview() checks the nonce.
        $kind = isset($_GET['kind']) ? sanitize_key(wp_unslash((string) $_GET['kind'])) : '';
        $lang = isset($_GET['lang']) ? sanitize_key(wp_unslash((string) $_GET['lang'])) : '';
        $eventId = isset($_GET['event_id']) ? absint(wp_unslash((string) $_GET['event_id'])) : 0;
        $taskId = isset($_GET['task_id']) ? absint(wp_unslash((string) $_GET['task_id'])) : 0;
        $written = isset($_GET['written']) ? sanitize_key(wp_unslash((string) $_GET['written'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $codes = SiteLanguages::codes();
        $lang = in_array($lang, $codes, true) ? $lang : '';
        $languages = [];

        foreach ($codes as $code) {
            $languages[$code] = SiteLanguages::label($code);
        }

        $entries = [];

        foreach (MessageTemplates::all() as $id => $template) {
            $entries[] = ['id' => (string) $id, 'label' => $template->label, 'channel' => $template->channel];
        }

        $message = '' === $kind || null === $this->preview
            ? null
            : $this->preview->render($kind, '' === $lang ? null : $lang, $eventId, $taskId);

        return [
            'lang' => $lang,
            'languages' => $languages,
            'kind' => null === $message ? '' : $kind,
            'message' => $message,
            'entries' => $entries,
            'events' => null === $message || null === $this->preview ? [] : $this->preview->events(),
            'tasks' => null === $message || null === $this->preview || true !== $message['about_task']
                ? []
                : $this->preview->tasksOf((int) $message['event_id']),
            'written' => in_array($written, ['ok', 'failed'], true) ? $written : '',
        ];
    }

    /**
     * admin-post (POST): "Write it now" on the preview page. Has the AI
     * write the posted kind's text for the posted event at once, the same
     * request as "Write again" on the Coming events tab, then goes back to
     * the preview of that event and task. Organizers only, nonce-checked.
     */
    public function previewWrite(): never
    {
        Admin::assertCanSave(self::PREVIEW_WRITE_ACTION);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in assertCanSave(); sanitized per field in applyPreviewWrite().
        $target = $this->applyPreviewWrite((array) wp_unslash($_POST));

        wp_safe_redirect($target);
        exit;
    }

    /**
     * Writes the AI text of $post's kind for its event_id, when the kind
     * has an AI text per event; returns the preview address to go back to,
     * with written=ok or written=failed.
     *
     * @param array<string, mixed> $post
     */
    public function applyPreviewWrite(array $post): string
    {
        $kind = sanitize_key((string) ($post['kind'] ?? ''));
        $lang = sanitize_key((string) ($post['lang'] ?? ''));
        $lang = in_array($lang, SiteLanguages::codes(), true) ? $lang : '';
        $eventId = absint($post['event_id'] ?? 0);
        $taskId = absint($post['task_id'] ?? 0);
        $type = 'error';

        if (null !== $this->ai && $eventId > 0 && MessageAiText::offers($kind)) {
            $this->ai->applyRewrite(['event_id' => $eventId, 'key' => $kind], $type);
        }

        return add_query_arg(
            ['written' => 'success' === $type ? 'ok' : 'failed'],
            self::previewUrl($kind, $lang, $eventId, $taskId)
        );
    }

    /** The tab asked for in the address; Messages when none or unknown. */
    public static function currentTab(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which tab shows.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : '';

        return isset(self::tabs()[$tab]) ? $tab : self::TAB_MESSAGES;
    }

    public function render(): void
    {
        $this->view->render('messages', $this->data(self::currentTab()));
    }

    /**
     * What the page shows on $tab: the tabs, the tab, and that tab's data.
     *
     * @return array<string, mixed>
     */
    public function data(string $tab): array
    {
        $data = ['tabs' => self::tabs(), 'tab' => $tab];

        if (self::TAB_AI === $tab) {
            return $data + (null === $this->ai ? ['prompts' => [], 'placeholders' => []] : $this->ai->promptsData());
        }

        if (self::TAB_EVENTS === $tab) {
            return $data + (null === $this->ai
                ? ['events' => [], 'can_write' => false, 'followup' => null, 'newsletter' => null]
                : $this->ai->eventsData());
        }

        if (self::TAB_EMAIL === $tab) {
            return $data + [
                'html_on' => EmailTemplate::enabled(),
                'edit_url' => null === $this->emailTemplate ? '' : $this->emailTemplate->editUrl(),
                'nonce_action' => SettingsPage::EMAIL_NONCE_ACTION,
            ];
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which entry is open.
        $open = isset($_GET['kind']) ? sanitize_key(wp_unslash((string) $_GET['kind'])) : '';

        return $data + [
            'entries' => self::entries(),
            'open' => $open,
            'ticket' => ['text' => TicketText::settings(), 'reasons' => TicketReasons::allSettings()],
            'samples' => null === $this->preview ? [] : $this->preview->placeholderSamples(),
        ];
    }

    /**
     * Everything the page shows, one entry per kind.
     *
     * @return list<array{
     *     id: string, label: string, channel: string, description: string, wired: bool, changed: bool,
     *     subject: ?array, body: array, placeholders: array<string, string>, required: list<string>,
     *     formatting: string, template: MessageTemplate, settings: array<string, mixed>
     * }>
     */
    public static function entries(): array
    {
        $entries = [];
        // The stored texts of every kind with one query, not one each.
        MessageTemplates::primeStored();

        foreach (MessageTemplates::all() as $id => $template) {
            $stored = MessageTemplates::stored($id);
            $subject = $stored[MessageTemplates::SUBJECT];
            $body = $stored[MessageTemplates::BODY];
            $entries[] = [
                'id' => $id,
                'label' => $template->label,
                'channel' => $template->channel,
                'description' => $template->description,
                'wired' => $template->wired,
                'changed' => null !== $subject || null !== $body,
                'subject' => $template->hasSubject()
                    ? $subject ?? MessageTemplates::defaultValue($id, MessageTemplates::SUBJECT)
                    : null,
                'body' => $body ?? MessageTemplates::defaultValue($id, MessageTemplates::BODY),
                'placeholders' => $template->placeholderList(),
                'required' => $template->requiredPlaceholders(),
                'formatting' => self::formatting($template->channel),
                'template' => $template,
                'settings' => $stored['settings'],
            ];
        }

        return $entries;
    }

    /** The one line saying which formatting works in a message on $channel. */
    public static function formatting(string $channel): string
    {
        if (MessageTemplate::CHANNEL_TELEGRAM === $channel) {
            return __('Formatting: none. The Telegram message is sent as plain text with its line breaks; ** and other marks are shown as they are.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
        }

        return __('Formatting: **bold**, *italic*, a line starting with "- " is a list item, a line starting with "## " is a heading, and an empty line starts a new paragraph.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
    }

    /** admin-post: saves one kind's subject, text and settings (the form without JavaScript). */
    public function save(): void
    {
        Admin::assertCanSave(self::SAVE_ACTION);

        $result = $this->applySave();

        Admin::redirectTo(
            self::PAGE_SLUG,
            $result['message'],
            $result['type'],
            null === $result['kind'] ? [] : ['tab' => self::TAB_MESSAGES, 'kind' => $result['kind']]
        );
    }

    /**
     * admin-ajax: the same save as save(), for the form posted by the page's
     * JavaScript. It only stores and queues (the translation runs later, in
     * the poll of JobsStatus), and answers at once with the notice; per
     * part whose translation waits, its job badge (jobs), and per other
     * part, its text as now stored (texts), so the page stays where it is.
     */
    public function saveAjax(): never
    {
        if (! current_user_can(Admin::CAPABILITY)) {
            wp_send_json_error(null, 403);
        }

        check_ajax_referer(self::SAVE_ACTION);

        $result = $this->applySave();

        if ('' !== $result['message'] && BackgroundJobs::addedNow() > 0) {
            $result['message'] .= ' ' . BackgroundJobs::queuedNotice();
        }

        $jobs = null === $result['kind'] ? [] : self::waitingJobs($result['kind']);
        $texts = [];

        // A refused text is not stored: the form keeps what was typed.
        foreach (null === $result['kind'] || ! $result['saved'] ? [] : self::parts($result['kind']) as $part) {
            $row = 'message.' . $result['kind'] . '.' . $part;

            if (! isset($jobs[$row])) {
                $texts[$row] = TranslatedText::fillValue(MessageTemplates::value($result['kind'], $part));
            }
        }

        wp_send_json_success([
            'type' => $result['type'],
            'message' => $result['message'],
            'jobs' => $jobs,
            'texts' => $texts,
        ]);
    }

    /** admin-post: saves the ticket email's text and {reason} lines (the form without JavaScript). */
    public function saveTicket(): void
    {
        Admin::assertCanSave(self::TICKET_ACTION);

        $result = $this->applyTicketSave();

        Admin::redirectTo(
            self::PAGE_SLUG,
            $result['message'],
            $result['type'],
            ['tab' => self::TAB_MESSAGES, 'kind' => self::TICKET_KIND],
            'ec-message-' . self::TICKET_KIND
        );
    }

    /**
     * admin-ajax: the same save as saveTicket(), answered as saveAjax()
     * answers: the notice, the badge of each text whose translation waits
     * (jobs), and each other text as now stored (texts).
     */
    public function saveTicketAjax(): never
    {
        if (! current_user_can(Admin::CAPABILITY)) {
            wp_send_json_error(null, 403);
        }

        check_ajax_referer(self::TICKET_ACTION);

        $result = $this->applyTicketSave();

        if (BackgroundJobs::addedNow() > 0) {
            $result['message'] .= ' ' . BackgroundJobs::queuedNotice();
        }

        $jobs = [];
        $texts = [];

        foreach (self::ticketValues() as $row => $value) {
            $job = BackgroundJobs::latest(TranslatedText::jobTarget($row));

            if (BackgroundJobs::isActive($job)) {
                $jobs[$row] = BackgroundJobs::badge($job);
            } else {
                $texts[$row] = TranslatedText::fillValue($value);
            }
        }

        wp_send_json_success([
            'type' => $result['type'],
            'message' => $result['message'],
            'jobs' => $jobs,
            'texts' => $texts,
        ]);
    }

    /**
     * Saves the posted ticket email text and {reason} lines; the
     * translations are queued.
     *
     * @return array{type: string, message: string}
     */
    public function applyTicketSave(): array
    {
        $llm = $this->llm ?? new LlmClient(new Logger());

        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified by the caller; sanitized per field in TranslatedText::fromPost().
        $error = (new TicketText($llm, new Logger(), true))->saveSettings($_POST[TicketText::POST_NAME] ?? null);
        $reasonError = (new TicketReasons($llm, new Logger(), true))
            ->saveSettings($_POST[TicketReasons::POST_NAME] ?? null);
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput

        $notice = TranslatedText::errorNotice('' !== $error ? $error : $reasonError);

        return '' === $notice
            ? ['type' => 'success', 'message' => __('The ticket email text is saved.', 'eventcrew')]
            : ['type' => 'error', 'message' => $notice];
    }

    /**
     * The ticket email's texts as stored, by their Translations page row
     * id (the data-ec-row of their fields).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function ticketValues(): array
    {
        $values = ['email.ticket' => TicketText::settings()];

        foreach (TicketReasons::KINDS as $kind) {
            $values['email.ticket_reason.' . $kind] = TicketReasons::settings($kind);
        }

        return $values;
    }

    /**
     * The badge of each part of $id whose translation is queued or running,
     * keyed by its Translations page row id (the data-ec-row of its field).
     *
     * @return array<string, string>
     */
    public static function waitingJobs(string $id): array
    {
        $jobs = [];

        foreach (self::parts($id) as $part) {
            $row = 'message.' . $id . '.' . $part;
            $job = BackgroundJobs::latest(TranslatedText::jobTarget($row));

            if (BackgroundJobs::isActive($job)) {
                $jobs[$row] = BackgroundJobs::badge($job);
            }
        }

        return $jobs;
    }

    /**
     * The text parts of kind $id: subject (when it has one) and body.
     *
     * @return list<string>
     */
    private static function parts(string $id): array
    {
        return true === MessageTemplates::find($id)?->hasSubject()
            ? [MessageTemplates::SUBJECT, MessageTemplates::BODY]
            : [MessageTemplates::BODY];
    }

    /**
     * Saves the posted kind: its texts (a translation is queued, see
     * MessageTemplates::save()) and settings.
     *
     * @return array{kind: ?string, saved: bool, type: string, message: string}
     *     kind is null when it does not exist; saved is false when nothing was stored.
     */
    private function applySave(): array
    {
        $id = $this->postedKind();

        if (null === $id) {
            return [
                'kind' => null,
                'saved' => false,
                'type' => 'error',
                'message' => __('That message kind does not exist.', 'eventcrew'),
            ];
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified by the caller; sanitized per field in TranslatedText::fromPost().
        $error = $this->templates->save($id, $_POST[MessageTemplates::POST_NAME] ?? null);

        if (MessageTemplates::ERROR_MISSING_PLACEHOLDER === $error) {
            return [
                'kind' => $id,
                'saved' => false,
                'type' => 'error',
                'message' => sprintf(
                    /* translators: %s: the placeholders, like {email} */
                    __('Not saved: the text must contain %s, in every language. The message keeps the text it had.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                    implode(', ', MessageTemplates::find($id)?->requiredPlaceholders() ?? [])
                ),
            ];
        }

        $notice = TranslatedText::errorNotice($error);
        $saved = __('Message saved.', 'eventcrew');

        return [
            'kind' => $id,
            'saved' => true,
            'type' => '' === $notice ? 'success' : 'error',
            'message' => '' === $notice ? $saved : $saved . ' ' . $notice,
        ];
    }

    /** admin-post: "Reset to built-in text" for one kind. */
    public function reset(): void
    {
        Admin::assertCanSave(self::RESET_ACTION);

        $id = $this->postedKind();

        if (null === $id) {
            Admin::redirectTo(self::PAGE_SLUG, __('That message kind does not exist.', 'eventcrew'), 'error');
        }

        MessageTemplates::reset($id);

        Admin::redirectTo(
            self::PAGE_SLUG,
            __('The message uses its built-in text again.', 'eventcrew'),
            'success',
            ['tab' => self::TAB_MESSAGES, 'kind' => $id]
        );
    }

    /** The posted kind, or null when it is not one of MessageTemplates::all(). */
    private function postedKind(): ?string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in assertCanSave().
        $id = isset($_POST['kind']) ? sanitize_key(wp_unslash((string) $_POST['kind'])) : '';

        return null === MessageTemplates::find($id) ? null : $id;
    }
}

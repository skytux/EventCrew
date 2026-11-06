<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Support\BackgroundJobs;
use EventCrew\Support\EmailTemplate;
use EventCrew\Support\MessageTemplate;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\TranslatedText;

/**
 * The Messages page, in tabs (&tab= in the address):
 * - Messages: every email and Telegram message kind (MessageTemplates), each
 *   with its subject, its text, the placeholders it can use and what each
 *   one becomes, the formatting that works, and "Reset to built-in text".
 *   Each kind is its own form, so saving one translates only that one;
 *   &kind= opens one.
 * - AI: the AI prompts and the organizers' own placeholders (AiPromptsPage).
 * - Coming events: the texts the AI wrote for each coming event and the
 *   one-time texts of the next post-event email and announcement
 *   (AiPromptsPage).
 * - Email look: the link to the Email template's block editor (EmailTemplate),
 *   while HTML email is on, and its reset.
 */
final class MessagesPage
{
    public const PAGE_SLUG = 'eventcrew-messages';

    public const SAVE_ACTION = 'eventcrew_save_message';

    public const RESET_ACTION = 'eventcrew_reset_message';

    public const TAB_MESSAGES = 'messages';

    public const TAB_AI = 'ai';

    public const TAB_EVENTS = 'events';

    public const TAB_EMAIL = 'email';

    public function __construct(
        private readonly View $view,
        private readonly MessageTemplates $templates,
        private readonly ?AiPromptsPage $ai = null,
        private readonly ?EmailTemplate $emailTemplate = null
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

        return $data + ['entries' => self::entries(), 'open' => $open];
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

        foreach (MessageTemplates::all() as $id => $template) {
            $entries[] = [
                'id' => $id,
                'label' => $template->label,
                'channel' => $template->channel,
                'description' => $template->description,
                'wired' => $template->wired,
                'changed' => MessageTemplates::isChanged($id),
                'subject' => $template->hasSubject() ? MessageTemplates::value($id, MessageTemplates::SUBJECT) : null,
                'body' => MessageTemplates::value($id, MessageTemplates::BODY),
                'placeholders' => $template->placeholderList(),
                'required' => $template->requiredPlaceholders(),
                'formatting' => self::formatting($template->channel),
                'template' => $template,
                'settings' => MessageTemplates::settings($id),
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
                $texts[$row] = TranslatedText::normalize(MessageTemplates::value($result['kind'], $part));
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

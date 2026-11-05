<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Support\MessageTemplate;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\TranslatedText;

/**
 * The Messages page: every email and Telegram message kind
 * (MessageTemplates), each with its subject, its text, the placeholders it
 * can use and what each one becomes, the formatting that works, and "Reset
 * to built-in text". Each kind is its own form, so saving one translates
 * only that one.
 */
final class MessagesPage
{
    public const PAGE_SLUG = 'eventcrew-messages';

    public const SAVE_ACTION = 'eventcrew_save_message';

    public const RESET_ACTION = 'eventcrew_reset_message';

    public function __construct(
        private readonly View $view,
        private readonly MessageTemplates $templates
    ) {
    }

    public function render(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which entry is open.
        $open = isset($_GET['kind']) ? sanitize_key(wp_unslash((string) $_GET['kind'])) : '';

        $this->view->render('messages', ['entries' => self::entries(), 'open' => $open]);
    }

    /**
     * Everything the page shows, one entry per kind.
     *
     * @return list<array{
     *     id: string, label: string, channel: string, description: string, wired: bool, changed: bool,
     *     subject: ?array, body: array, placeholders: array<string, string>, formatting: string,
     *     template: MessageTemplate, settings: array<string, mixed>
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

    /** admin-post: saves one kind's subject, text and settings. */
    public function save(): void
    {
        Admin::assertCanSave(self::SAVE_ACTION);

        $id = $this->postedKind();

        if (null === $id) {
            Admin::redirectTo(self::PAGE_SLUG, __('That message kind does not exist.', 'eventcrew'), 'error');
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in assertCanSave(); sanitized per field in TranslatedText::fromPost().
        $error = $this->templates->save($id, $_POST[MessageTemplates::POST_NAME] ?? null);
        $notice = TranslatedText::errorNotice($error);

        Admin::redirectTo(
            self::PAGE_SLUG,
            '' === $notice ? __('Message saved.', 'eventcrew') : __('Message saved.', 'eventcrew') . ' ' . $notice,
            '' === $notice ? 'success' : 'error',
            ['kind' => $id]
        );
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
            ['kind' => $id]
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

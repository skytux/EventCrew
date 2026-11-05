<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Support\LlmClient;
use EventCrew\Support\SiteLanguages;
use EventCrew\Support\TranslatedText;
use EventCrew\Support\TranslationJob;
use EventCrew\Support\TranslationRegistry;

/**
 * The wp-admin Translations page.
 *
 * At the top, the language settings (SiteLanguages): the site's languages in
 * order, AI translation on or off, AI language detection on or off. Below,
 * every translatable text (TranslationRegistry), one row each, with its
 * source and one editable text per language. Saving a row marks each changed
 * language text "edited by hand"; the language model never overwrites such a
 * text. "Translate missing" starts a background job (TranslationJob) that
 * fills every empty language text; the page shows how far it is.
 */
final class TranslationsPage
{
    public const PAGE_SLUG = 'eventcrew-translations';

    public const SAVE_SETTINGS_ACTION = 'eventcrew_save_translation_settings';
    public const SAVE_ROW_ACTION = 'eventcrew_save_translation_row';
    public const TRANSLATE_MISSING_ACTION = 'eventcrew_translate_missing';
    public const PROGRESS_ACTION = 'eventcrew_translate_progress';

    public function __construct(
        private readonly View $view,
        private readonly TranslationRegistry $registry,
        private readonly TranslationJob $job
    ) {
    }

    public function render(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only: the search and the filter.
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '';
        $show = isset($_GET['show']) ? sanitize_key(wp_unslash((string) $_GET['show'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $this->view->render('translations', $this->data($search, $show));
    }

    /**
     * What the template shows.
     *
     * @return array<string, mixed>
     */
    public function data(string $search, string $show): array
    {
        $shows = [
            '' => __('All texts', 'eventcrew'),
            TranslationRegistry::SHOW_MISSING => __('Not translated yet', 'eventcrew'),
            TranslationRegistry::SHOW_MANUAL => __('Edited by hand', 'eventcrew'),
            TranslationRegistry::SHOW_EMAILS => __('Emails only', 'eventcrew'),
        ];
        $show = isset($shows[$show]) ? $show : '';
        $rows = $this->registry->rows();
        $languages = TranslatedText::languages();
        $status = TranslationJob::status();
        $running = null !== $status && $status['running'];

        return [
            'languages' => $languages,
            'main' => SiteLanguages::main(),
            'no_catalog' => array_values(array_filter(
                $languages,
                static fn (string $code): bool => ! SiteLanguages::hasCatalog($code)
            )),
            'known' => SiteLanguages::KNOWN,
            'translate_auto' => TranslatedText::autoEnabled(),
            'detect' => SiteLanguages::detectEnabled(),
            'rows' => TranslationRegistry::filter($rows, $search, $show),
            'total' => count($rows),
            'search' => $search,
            'show' => $show,
            'shows' => $shows,
            'post_url' => admin_url('admin-post.php'),
            'progress' => self::progressText($status),
            'running' => $running,
            'progress_url' => $running
                ? admin_url('admin-ajax.php') . '?action=' . self::PROGRESS_ACTION
                    . '&_ajax_nonce=' . wp_create_nonce(self::PROGRESS_ACTION)
                : '',
        ];
    }

    /** admin-post handler: the language settings. */
    public function saveSettings(): never
    {
        Admin::assertCanSave(self::SAVE_SETTINGS_ACTION);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in assertCanSave().
        $notice = $this->applySettings(wp_unslash($_POST));

        Admin::redirectTo(self::PAGE_SLUG, $notice['message'], $notice['type']);
    }

    /**
     * Stores the posted settings: languages (codes typed in order),
     * translate_auto and detect (checkboxes).
     *
     * @param array<string, mixed> $post
     * @return array{type: string, message: string}
     */
    public function applySettings(array $post): array
    {
        $typed = is_scalar($post['languages'] ?? null) ? sanitize_text_field((string) $post['languages']) : '';
        $parsed = SiteLanguages::parse($typed);
        $codes = $parsed['codes'];

        if ([] === $codes && [] !== $parsed['unknown']) {
            // Nothing usable was typed: keep the languages as they are.
            $codes = SiteLanguages::codes();
        }

        SiteLanguages::save($codes, isset($post['translate_auto']), isset($post['detect']));

        if ([] !== $parsed['unknown']) {
            return [
                'type' => 'error',
                'message' => sprintf(
                    /* translators: %s: the language codes that were typed but are not known, e.g. "xx, yy" */
                    __('The settings were saved, but these language codes are not known and were left out: %s.', 'eventcrew'),
                    implode(', ', $parsed['unknown'])
                ),
            ];
        }

        return ['type' => 'success', 'message' => __('The language settings were saved.', 'eventcrew')];
    }

    /** admin-post handler: one row of the table. */
    public function saveRow(): never
    {
        Admin::assertCanSave(self::SAVE_ROW_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in assertCanSave(); sanitized in applyRow().
        $id = isset($_POST['row_id']) ? sanitize_text_field(wp_unslash((string) $_POST['row_id'])) : '';
        $notice = $this->applyRow($id, $_POST['row'] ?? null);
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput

        Admin::redirectTo(self::PAGE_SLUG, $notice['message'], $notice['type'], $this->listArgs());
    }

    /**
     * Saves the row $id from the posted fields ($raw: source and texts per
     * language, as TranslatedText::fromPost() reads them).
     *
     * @return array{type: string, message: string}
     */
    public function applyRow(string $id, mixed $raw): array
    {
        $row = $this->registry->find($id);

        if (null === $row) {
            return ['type' => 'error', 'message' => __('This text was not found. Nothing was saved.', 'eventcrew')];
        }

        $input = TranslatedText::fromPost($raw, $row->multiline);

        if (null === $input) {
            return ['type' => 'error', 'message' => __('Nothing was saved.', 'eventcrew')];
        }

        $result = $this->registry->saveRow($id, $input['source'], $input['texts']);

        if ([] !== $result['missing']) {
            return [
                'type' => 'error',
                'message' => sprintf(
                    /* translators: %s: the placeholders, like {email} */
                    __('Not saved: the text must contain %s, in every language. The message keeps the text it had.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                    implode(', ', $result['missing'])
                ),
            ];
        }

        $error = TranslatedText::errorNotice($result['error']);

        return '' === $error
            ? [
                'type' => 'success',
                'message' => sprintf(
                    /* translators: %s: the text's name, e.g. "Telegram bot: About" */
                    __('Saved: %s.', 'eventcrew'),
                    $row->label
                ),
            ]
            : ['type' => 'error', 'message' => $error];
    }

    /** admin-post handler: "Translate missing". */
    public function translateMissing(): never
    {
        Admin::assertCanSave(self::TRANSLATE_MISSING_ACTION);

        $notice = $this->applyTranslateMissing();

        Admin::redirectTo(self::PAGE_SLUG, $notice['message'], $notice['type'], $this->listArgs());
    }

    /**
     * Starts "Translate missing" in the background (TranslationJob). Nothing
     * is translated during the click.
     *
     * @return array{type: string, message: string}
     */
    public function applyTranslateMissing(): array
    {
        return match ($this->job->start()) {
            TranslationJob::OFF => [
                'type' => 'error',
                'message' => __('AI translation is off, so nothing was translated. Switch it on above first.', 'eventcrew'),
            ],
            TranslationJob::NOTHING => [
                'type' => 'success',
                'message' => __('Every text is already in every language.', 'eventcrew'),
            ],
            TranslationJob::RUNNING => [
                'type' => 'warning',
                'message' => __('Translation is already running. Nothing new was started.', 'eventcrew'),
            ],
            default => [
                'type' => 'success',
                'message' => __('Translation started. It runs in the background, so you can close this page.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            ],
        };
    }

    /** admin-ajax handler: the progress line, for the page to refresh while a job runs. */
    public function progress(): never
    {
        if (! current_user_can(Admin::CAPABILITY)) {
            wp_send_json_error(null, 403);
        }

        check_ajax_referer(self::PROGRESS_ACTION);

        $status = TranslationJob::status();

        wp_send_json_success([
            'running' => null !== $status && $status['running'],
            'text' => self::progressText($status),
        ]);
    }

    /**
     * The line that says how far "Translate missing" is, '' when it was
     * never used.
     *
     * @param array{running: bool, total: int, done: int, failed: int, error: string}|null $status
     *     TranslationJob::status().
     */
    public static function progressText(?array $status): string
    {
        if (null === $status) {
            return '';
        }

        if ($status['running']) {
            return sprintf(
                /* translators: 1: texts done, 2: texts in all, 3: texts that could not be translated */
                __('Translating: %1$d of %2$d done. Not translated: %3$d.', 'eventcrew'),
                $status['done'],
                $status['total'],
                $status['failed']
            );
        }

        $line = sprintf(
            /* translators: 1: texts done, 2: texts in all, 3: texts that could not be translated */
            __('Last translation: %1$d of %2$d done. Not translated: %3$d.', 'eventcrew'),
            $status['done'],
            $status['total'],
            $status['failed']
        );

        if (0 === $status['failed'] || '' === $status['error']) {
            return $line;
        }

        return $line . ' ' . match ($status['error']) {
            LlmClient::ERROR_NOT_CONFIGURED => __('No language model is set up.', 'eventcrew'),
            TranslatedText::ERROR_OFF => __('AI translation was switched off.', 'eventcrew'),
            default => sprintf(
                /* translators: %s: the kind of error, e.g. timeout or http_401 */
                __('The language model gave the error %s.', 'eventcrew'),
                $status['error']
            ),
        };
    }

    /**
     * The search and filter the form was posted from, to return to the same list.
     *
     * @return array<string, string>
     */
    private function listArgs(): array
    {
        $args = [];

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in assertCanSave().
        foreach (['s', 'show'] as $key) {
            if (isset($_POST[$key]) && is_scalar($_POST[$key]) && '' !== (string) $_POST[$key]) {
                $args[$key] = sanitize_text_field(wp_unslash((string) $_POST[$key]));
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        return $args;
    }
}

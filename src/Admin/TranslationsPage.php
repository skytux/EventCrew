<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Support\SiteLanguages;
use EventCrew\Support\TranslatedText;
use EventCrew\Support\TranslationRegistry;

/**
 * The wp-admin Translations page.
 *
 * At the top, the language settings (SiteLanguages): the site's languages in
 * order, AI translation on or off, AI language detection on or off. Below,
 * every translatable text (TranslationRegistry), one row each, with its
 * source and one editable text per language. Saving a row marks each changed
 * language text "edited by hand"; the language model never overwrites such a
 * text. "Translate missing" fills every empty language text.
 */
final class TranslationsPage
{
    public const PAGE_SLUG = 'eventcrew-translations';

    public const SAVE_SETTINGS_ACTION = 'eventcrew_save_translation_settings';
    public const SAVE_ROW_ACTION = 'eventcrew_save_translation_row';
    public const TRANSLATE_MISSING_ACTION = 'eventcrew_translate_missing';

    public function __construct(
        private readonly View $view,
        private readonly TranslationRegistry $registry
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
     * Fills every empty language text with the language model.
     *
     * @return array{type: string, message: string}
     */
    public function applyTranslateMissing(): array
    {
        if (! TranslatedText::autoEnabled()) {
            return [
                'type' => 'error',
                'message' => __('AI translation is off, so nothing was translated. Switch it on above first.', 'eventcrew'),
            ];
        }

        $result = $this->registry->translateMissing();
        $error = TranslatedText::errorNotice($result['error']);

        if ('' !== $error) {
            return [
                'type' => 'error',
                'message' => sprintf(
                    /* translators: 1: how many texts were translated, 2: how many were not */
                    __('Translated texts: %1$d. Not translated: %2$d.', 'eventcrew'),
                    $result['filled'],
                    $result['failed']
                ) . ' ' . $error,
            ];
        }

        if (0 === $result['filled']) {
            return ['type' => 'success', 'message' => __('Every text is already in every language.', 'eventcrew')];
        }

        return [
            'type' => 'success',
            'message' => sprintf(
                /* translators: %d: how many texts were translated */
                _n('%d text was translated.', '%d texts were translated.', $result['filled'], 'eventcrew'),
                $result['filled']
            ),
        ];
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

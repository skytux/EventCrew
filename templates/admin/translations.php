<?php

/**
 * Translations admin view: the language settings, then every translatable
 * text with one editable text per language.
 *
 * @var list<string> $languages The site's languages, main first.
 * @var string $main
 * @var list<string> $no_catalog Site languages the plugin's own strings are not translated into.
 * @var array<string, array{0: string, 1: string, 2: string}> $known
 * @var bool $translate_auto
 * @var bool $detect
 * @var list<\EventCrew\Support\TranslationRow> $rows The rows shown (after the search and filter).
 * @var int $total How many rows there are in all.
 * @var string $search
 * @var string $show
 * @var array<string, string> $shows
 * @var string $post_url
 * @var string $progress How far "Translate missing" is; '' when it was never used.
 * @var bool $running Whether "Translate missing" is running.
 * @var string $progress_url The admin-ajax URL that answers the progress; '' when not running.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

use EventCrew\Admin\TranslationsPage;
use EventCrew\Support\SiteLanguages;

$eventcrew_tr_list_fields = static function () use ($search, $show): void {
    printf('<input type="hidden" name="s" value="%s">', esc_attr($search));
    printf('<input type="hidden" name="show" value="%s">', esc_attr($show));
};
?>
<div class="wrap ec-translations">
    <h1><?php esc_html_e('Translations', 'eventcrew'); ?></h1>

    <h2><?php esc_html_e('Languages', 'eventcrew'); ?></h2>
    <form method="post" action="<?php echo esc_url($post_url); ?>">
        <input type="hidden" name="action" value="<?php echo esc_attr(TranslationsPage::SAVE_SETTINGS_ACTION); ?>">
        <?php wp_nonce_field(TranslationsPage::SAVE_SETTINGS_ACTION); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="ec-site-languages"><?php esc_html_e('Site languages', 'eventcrew'); ?></label></th>
                <td>
                    <input type="text" id="ec-site-languages" name="languages" class="regular-text"
                        value="<?php echo esc_attr(implode(', ', $languages)); ?>">
                    <p class="description">
                        <?php esc_html_e('Two-letter language codes, separated by commas. The first one is the main language. Every text below is kept in each of these languages.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal. ?>
                    </p>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: the languages, e.g. "Suomi (fi), English (en)" */
                            esc_html__('Now: %s.', 'eventcrew'),
                            esc_html(implode(', ', array_map(
                                static fn (string $code): string => SiteLanguages::label($code) . ' (' . $code . ')',
                                $languages
                            )))
                        );
                        ?>
                    </p>
                    <?php foreach ($no_catalog as $eventcrew_tr_code) : ?>
                        <p class="description" style="color:#996800">
                            <?php
                            printf(
                                /* translators: %s: a language name, e.g. Svenska */
                                esc_html__('The plugin’s own texts (buttons, menus, built-in messages) are not translated into %s. People who choose it read them in English. The texts in the table below are translated.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal.
                                esc_html(SiteLanguages::label($eventcrew_tr_code))
                            );
                            ?>
                        </p>
                    <?php endforeach; ?>
                    <details>
                        <summary><?php esc_html_e('Language codes', 'eventcrew'); ?></summary>
                        <p>
                            <?php
                            echo esc_html(implode(', ', array_map(
                                static fn (string $code, array $names): string => $code . ' = ' . $names[0],
                                array_keys($known),
                                $known
                            )));
                            ?>
                        </p>
                    </details>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('AI translation', 'eventcrew'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="translate_auto" value="1" <?php checked($translate_auto); ?>>
                        <?php esc_html_e('Translate texts automatically with the language model', 'eventcrew'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('When this is off, nothing is sent to the language model, and an empty language text gets the text as it was written.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal. ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('AI language detection', 'eventcrew'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="detect" value="1" <?php checked($detect); ?>>
                        <?php esc_html_e('Let the language model find out which language a text is written in', 'eventcrew'); ?>
                    </label>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: the main language, e.g. Finnish */
                            esc_html__('When this is off, every text is taken to be written in the main language (%s).', 'eventcrew'),
                            esc_html(SiteLanguages::label($main))
                        );
                        ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php submit_button(__('Save language settings', 'eventcrew')); ?>
    </form>

    <hr>

    <h2><?php esc_html_e('Texts', 'eventcrew'); ?></h2>

    <form method="post" action="<?php echo esc_url($post_url); ?>" style="margin:0 0 1em">
        <input type="hidden" name="action" value="<?php echo esc_attr(TranslationsPage::TRANSLATE_MISSING_ACTION); ?>">
        <?php wp_nonce_field(TranslationsPage::TRANSLATE_MISSING_ACTION); ?>
        <?php $eventcrew_tr_list_fields(); ?>
        <button type="submit" class="button button-secondary"<?php disabled(! $translate_auto || $running); ?>>
            <?php esc_html_e('Translate missing', 'eventcrew'); ?>
        </button>
        <span class="description">
            <?php
            echo $translate_auto
                ? esc_html__('Fills every empty language text with the language model. Texts edited by hand are not changed.', 'eventcrew') // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal.
                : esc_html__('AI translation is off.', 'eventcrew');
            ?>
        </span>
        <?php if ('' !== $progress) : ?>
            <p id="ec-tr-progress" data-url="<?php echo esc_url($progress_url); ?>">
                <?php echo esc_html($progress); ?>
            </p>
        <?php endif; ?>
    </form>
    <?php if ($running) : ?>
        <script>
        (function () {
            var line = document.getElementById('ec-tr-progress');
            if (!line || !line.dataset.url || !window.fetch) {
                return;
            }
            var timer = setInterval(function () {
                fetch(line.dataset.url, {credentials: 'same-origin'})
                    .then(function (response) { return response.json(); })
                    .then(function (answer) {
                        if (!answer || !answer.success) {
                            return;
                        }
                        line.textContent = answer.data.text;
                        if (!answer.data.running) {
                            clearInterval(timer);
                        }
                    })
                    .catch(function () {});
            }, 5000);
        })();
        </script>
    <?php endif; ?>

    <form method="get" class="ec-tr-search" style="margin:0 0 1em">
        <input type="hidden" name="page" value="<?php echo esc_attr(TranslationsPage::PAGE_SLUG); ?>">
        <label class="screen-reader-text" for="ec-tr-s"><?php esc_html_e('Search texts', 'eventcrew'); ?></label>
        <input type="search" id="ec-tr-s" name="s" value="<?php echo esc_attr($search); ?>"
            placeholder="<?php esc_attr_e('Search texts', 'eventcrew'); ?>">
        <label class="screen-reader-text" for="ec-tr-show"><?php esc_html_e('Show', 'eventcrew'); ?></label>
        <select id="ec-tr-show" name="show">
            <?php foreach ($shows as $eventcrew_tr_key => $eventcrew_tr_label) : ?>
                <option value="<?php echo esc_attr($eventcrew_tr_key); ?>"<?php selected($show, $eventcrew_tr_key); ?>>
                    <?php echo esc_html($eventcrew_tr_label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button"><?php esc_html_e('Filter', 'eventcrew'); ?></button>
        <span class="description">
            <?php
            printf(
                /* translators: 1: how many texts are shown, 2: how many there are */
                esc_html__('Showing %1$d of %2$d texts.', 'eventcrew'),
                count($rows),
                (int) $total
            );
            ?>
        </span>
    </form>

    <?php if ([] === $rows) : ?>
        <p><?php esc_html_e('No text matches.', 'eventcrew'); ?></p>
    <?php else : ?>
        <table class="widefat striped ec-tr-table">
            <thead>
                <tr>
                    <th scope="col" style="width:14em"><?php esc_html_e('Text', 'eventcrew'); ?></th>
                    <th scope="col"><?php esc_html_e('As written', 'eventcrew'); ?></th>
                    <?php foreach ($languages as $eventcrew_tr_code) : ?>
                        <th scope="col"><?php echo esc_html(SiteLanguages::label($eventcrew_tr_code)); ?></th>
                    <?php endforeach; ?>
                    <th scope="col" style="width:6em"><span class="screen-reader-text"><?php esc_html_e('Save', 'eventcrew'); ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $eventcrew_tr_n => $eventcrew_tr_row) : ?>
                    <?php
                    $eventcrew_tr_form = 'ec-tr-form-' . $eventcrew_tr_n;
                    $eventcrew_tr_input = static function (string $name, string $id, string $text) use ($eventcrew_tr_row, $eventcrew_tr_form): void {
                        if ($eventcrew_tr_row->multiline) {
                            printf(
                                '<textarea form="%s" id="%s" name="%s" rows="4" %s class="large-text">%s</textarea>',
                                esc_attr($eventcrew_tr_form),
                                esc_attr($id),
                                esc_attr($name),
                                $eventcrew_tr_row->maxLength > 0 ? 'maxlength="' . (int) $eventcrew_tr_row->maxLength . '"' : '',
                                esc_textarea($text)
                            );

                            return;
                        }

                        printf(
                            '<input type="text" form="%s" id="%s" name="%s" %s value="%s" class="large-text">',
                            esc_attr($eventcrew_tr_form),
                            esc_attr($id),
                            esc_attr($name),
                            $eventcrew_tr_row->maxLength > 0 ? 'maxlength="' . (int) $eventcrew_tr_row->maxLength . '"' : '',
                            esc_attr($text)
                        );
                    };
                    $eventcrew_tr_lang = $eventcrew_tr_row->value['lang'];
                    ?>
                    <tr id="<?php echo esc_attr('ec-tr-' . $eventcrew_tr_row->id); ?>">
                        <td>
                            <strong><?php echo esc_html($eventcrew_tr_row->label); ?></strong><br>
                            <span class="description"><?php echo esc_html($eventcrew_tr_row->groupLabel()); ?></span>
                        </td>
                        <td>
                            <?php if ($eventcrew_tr_row->fixedSource) : ?>
                                <p><?php echo nl2br(esc_html($eventcrew_tr_row->value['source'])); ?></p>
                                <input type="hidden" form="<?php echo esc_attr($eventcrew_tr_form); ?>" name="row[source]" value="<?php echo esc_attr($eventcrew_tr_row->value['source']); ?>">
                            <?php else : ?>
                                <?php $eventcrew_tr_input('row[source]', $eventcrew_tr_form . '-source', $eventcrew_tr_row->value['source']); ?>
                            <?php endif; ?>
                            <p class="description">
                                <?php
                                echo '' === $eventcrew_tr_lang
                                    ? esc_html__('Language: not known yet', 'eventcrew')
                                    : esc_html(sprintf(
                                        /* translators: %s: a language name, e.g. Finnish */
                                        __('Language: %s', 'eventcrew'),
                                        SiteLanguages::label($eventcrew_tr_lang)
                                    ));
                                ?>
                            </p>
                        </td>
                        <?php foreach ($languages as $eventcrew_tr_code) : ?>
                            <?php
                            $eventcrew_tr_text = $eventcrew_tr_row->value['texts'][$eventcrew_tr_code] ?? '';
                            $eventcrew_tr_manual = $eventcrew_tr_row->value['manual'][$eventcrew_tr_code] ?? false;
                            ?>
                            <td>
                                <label class="screen-reader-text" for="<?php echo esc_attr($eventcrew_tr_form . '-' . $eventcrew_tr_code); ?>">
                                    <?php echo esc_html($eventcrew_tr_row->label . ' (' . SiteLanguages::label($eventcrew_tr_code) . ')'); ?>
                                </label>
                                <?php $eventcrew_tr_input('row[texts][' . $eventcrew_tr_code . ']', $eventcrew_tr_form . '-' . $eventcrew_tr_code, $eventcrew_tr_text); ?>
                                <?php if ($eventcrew_tr_manual) : ?>
                                    <span class="ec-tr-badge ec-tr-manual" style="display:inline-block;padding:1px 8px;border-radius:10px;background:#dbe9f5;color:#0a4b78;font-size:11px">
                                        <?php esc_html_e('edited by hand', 'eventcrew'); ?>
                                    </span>
                                <?php elseif ('' === trim($eventcrew_tr_text) && '' !== trim($eventcrew_tr_row->value['source'])) : ?>
                                    <span class="ec-tr-badge ec-tr-missing" style="display:inline-block;padding:1px 8px;border-radius:10px;background:#fcf0d0;color:#6b4f00;font-size:11px">
                                        <?php esc_html_e('not translated yet', 'eventcrew'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td>
                            <button type="submit" form="<?php echo esc_attr($eventcrew_tr_form); ?>" class="button button-primary">
                                <?php esc_html_e('Save', 'eventcrew'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description">
            <?php esc_html_e('A language text you change is marked “edited by hand”, and the language model does not change it again. Empty it to let the language model fill it. When you change the text as written, the texts not edited by hand are translated again.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal. ?>
        </p>
        <?php foreach ($rows as $eventcrew_tr_n => $eventcrew_tr_row) : ?>
            <form id="<?php echo esc_attr('ec-tr-form-' . $eventcrew_tr_n); ?>" method="post" action="<?php echo esc_url($post_url); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(TranslationsPage::SAVE_ROW_ACTION); ?>">
                <input type="hidden" name="row_id" value="<?php echo esc_attr($eventcrew_tr_row->id); ?>">
                <?php wp_nonce_field(TranslationsPage::SAVE_ROW_ACTION, '_wpnonce', true, true); ?>
                <?php $eventcrew_tr_list_fields(); ?>
            </form>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

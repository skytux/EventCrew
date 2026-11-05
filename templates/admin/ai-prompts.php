<?php

/**
 * AI prompts admin view: the built-in prompts with "Reset to built-in", the
 * organizers' own placeholders, and the texts the AI wrote for the coming
 * events, each with an edit box.
 *
 * @var list<array{key: string, label: string, help: string, text: string, changed: bool}> $prompts
 * @var list<array{name: string, prompt: string, fallback: string}> $placeholders
 * @var list<array{event_id: int, date: string, title: string, items: list<array{key: string, label: string,
 *     ai: array<string, string>, texts: array<string, string>, edited: array<string, bool>, failed: bool}>}> $events
 * @var array<string, array<string, mixed>> $templates The templates with the AI tick on.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

use EventCrew\Admin\AiPromptsPage;
use EventCrew\Support\EventAiText;
use EventCrew\Support\TranslatedText;

$eventcrew_post_url = admin_url('admin-post.php');
// One empty row to add a placeholder with.
$eventcrew_rows = array_merge($placeholders, [['name' => '', 'prompt' => '', 'fallback' => '']]);
?>
<div class="wrap">
    <h1><?php esc_html_e('AI prompts', 'eventcrew'); ?></h1>
    <p><?php esc_html_e('What the AI is asked to write. Change a prompt and save, or press "Reset to built-in" to use the text that comes with the plugin.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>

    <h2><?php esc_html_e('Built-in prompts', 'eventcrew'); ?></h2>
    <form method="post" action="<?php echo esc_url($eventcrew_post_url); ?>">
        <input type="hidden" name="action" value="<?php echo esc_attr(AiPromptsPage::PROMPTS_ACTION); ?>">
        <?php wp_nonce_field(AiPromptsPage::NONCE_ACTION); ?>
        <table class="form-table" role="presentation">
            <?php foreach ($prompts as $eventcrew_prompt) : ?>
                <?php $eventcrew_id = 'eventcrew-ai-prompt-' . $eventcrew_prompt['key']; ?>
                <tr>
                    <th scope="row">
                        <label for="<?php echo esc_attr($eventcrew_id); ?>"><?php echo esc_html($eventcrew_prompt['label']); ?></label>
                    </th>
                    <td>
                        <textarea class="large-text" rows="5" id="<?php echo esc_attr($eventcrew_id); ?>"
                            name="prompts[<?php echo esc_attr($eventcrew_prompt['key']); ?>]"><?php echo esc_textarea($eventcrew_prompt['text']); ?></textarea>
                        <p class="description">
                            <?php echo esc_html($eventcrew_prompt['help']); ?>
                            <?php if ($eventcrew_prompt['changed']) : ?>
                                <strong><?php esc_html_e('Changed from the built-in text.', 'eventcrew'); ?></strong>
                            <?php endif; ?>
                        </p>
                        <?php if ($eventcrew_prompt['changed']) : ?>
                            <button type="submit" class="button" name="reset" value="<?php echo esc_attr($eventcrew_prompt['key']); ?>">
                                <?php esc_html_e('Reset to built-in', 'eventcrew'); ?>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php submit_button(__('Save the prompts', 'eventcrew')); ?>
    </form>

    <h2><?php esc_html_e('Your own placeholders', 'eventcrew'); ?></h2>
    <p><?php esc_html_e('A placeholder such as {dj_intro} in a message text is replaced by a text the AI writes for each event, the day before the event. Write what the AI should write in the prompt. The fallback text is used when the AI fails. To remove a placeholder, empty its name.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>
    <form method="post" action="<?php echo esc_url($eventcrew_post_url); ?>">
        <input type="hidden" name="action" value="<?php echo esc_attr(AiPromptsPage::PLACEHOLDERS_ACTION); ?>">
        <?php wp_nonce_field(AiPromptsPage::NONCE_ACTION); ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Name', 'eventcrew'); ?></th>
                    <th><?php esc_html_e('Prompt', 'eventcrew'); ?></th>
                    <th><?php esc_html_e('Fallback text', 'eventcrew'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($eventcrew_rows as $eventcrew_index => $eventcrew_row) : ?>
                    <?php $eventcrew_field = 'placeholders[' . (int) $eventcrew_index . ']'; ?>
                    <tr>
                        <td>
                            <input type="text" class="regular-text" maxlength="40"
                                aria-label="<?php esc_attr_e('Name', 'eventcrew'); ?>"
                                placeholder="dj_intro"
                                name="<?php echo esc_attr($eventcrew_field . '[name]'); ?>"
                                value="<?php echo esc_attr($eventcrew_row['name']); ?>">
                        </td>
                        <td>
                            <textarea class="large-text" rows="3" aria-label="<?php esc_attr_e('Prompt', 'eventcrew'); ?>"
                                name="<?php echo esc_attr($eventcrew_field . '[prompt]'); ?>"><?php echo esc_textarea($eventcrew_row['prompt']); ?></textarea>
                        </td>
                        <td>
                            <textarea class="large-text" rows="3" aria-label="<?php esc_attr_e('Fallback text', 'eventcrew'); ?>"
                                name="<?php echo esc_attr($eventcrew_field . '[fallback]'); ?>"><?php echo esc_textarea($eventcrew_row['fallback']); ?></textarea>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php submit_button(__('Save the placeholders', 'eventcrew')); ?>
    </form>

    <h2><?php esc_html_e('Texts for the coming events', 'eventcrew'); ?></h2>
    <p><?php esc_html_e('The AI writes these the day before each event. A text nobody changes is sent as the AI wrote it. A text you change is sent as you wrote it. When the AI failed, the normal message text is used.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>
    <?php if ([] === $templates && [] === $placeholders) : ?>
        <p class="description"><?php esc_html_e('No message template has the AI tick on, and there are no placeholders, so the AI writes nothing.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>
    <?php elseif ([] === $events) : ?>
        <p class="description"><?php esc_html_e('No texts are written yet for the coming events.', 'eventcrew'); ?></p>
    <?php endif; ?>
    <?php foreach ($events as $eventcrew_event) : ?>
        <h3><?php echo esc_html($eventcrew_event['date'] . ' · ' . $eventcrew_event['title']); ?></h3>
        <?php foreach ($eventcrew_event['items'] as $eventcrew_item) : ?>
            <form method="post" action="<?php echo esc_url($eventcrew_post_url); ?>" class="eventcrew-ai-text">
                <input type="hidden" name="action" value="<?php echo esc_attr(AiPromptsPage::EVENT_TEXT_ACTION); ?>">
                <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $eventcrew_event['event_id']); ?>">
                <input type="hidden" name="key" value="<?php echo esc_attr($eventcrew_item['key']); ?>">
                <?php wp_nonce_field(AiPromptsPage::NONCE_ACTION); ?>
                <h4><?php echo esc_html($eventcrew_item['label']); ?></h4>
                <?php if ($eventcrew_item['failed']) : ?>
                    <p class="description"><?php esc_html_e('The AI could not write this text. The normal message text is used, unless you write one here.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>
                <?php endif; ?>
                <?php foreach (TranslatedText::LANGUAGES as $eventcrew_lang) : ?>
                    <?php $eventcrew_id = 'eventcrew-ai-text-' . $eventcrew_event['event_id'] . '-' . sanitize_key($eventcrew_item['key']) . '-' . $eventcrew_lang; ?>
                    <p>
                        <label for="<?php echo esc_attr($eventcrew_id); ?>">
                            <?php echo esc_html(TranslatedText::label($eventcrew_lang)); ?>
                            <?php if ($eventcrew_item['edited'][$eventcrew_lang]) : ?>
                                <em><?php esc_html_e('(changed by hand)', 'eventcrew'); ?></em>
                            <?php endif; ?>
                        </label>
                        <textarea class="large-text" rows="4" id="<?php echo esc_attr($eventcrew_id); ?>"
                            maxlength="<?php echo (int) EventAiText::MAX_TEXT; ?>"
                            name="texts[<?php echo esc_attr($eventcrew_lang); ?>]"><?php echo esc_textarea($eventcrew_item['texts'][$eventcrew_lang]); ?></textarea>
                    </p>
                <?php endforeach; ?>
                <p>
                    <button type="submit" class="button button-primary" name="op" value="save"><?php esc_html_e('Save', 'eventcrew'); ?></button>
                    <?php if (in_array(true, $eventcrew_item['edited'], true) && ! $eventcrew_item['failed']) : ?>
                        <button type="submit" class="button" name="op" value="ai"><?php esc_html_e('Use the AI text again', 'eventcrew'); ?></button>
                    <?php endif; ?>
                </p>
            </form>
        <?php endforeach; ?>
    <?php endforeach; ?>
</div>

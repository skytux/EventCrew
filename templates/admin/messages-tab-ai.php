<?php

/**
 * Messages page, AI tab (included by messages.php): the built-in prompts
 * with "Reset to built-in", and the organizers' own placeholders.
 *
 * @var list<array{key: string, label: string, help: string, text: string, changed: bool}> $prompts
 * @var list<array{name: string, prompt: string, fallback: string}> $placeholders
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

use EventCrew\Admin\AiPromptsPage;

$eventcrew_post_url = admin_url('admin-post.php');
// One empty row to add a placeholder with.
$eventcrew_rows = array_merge($placeholders, [['name' => '', 'prompt' => '', 'fallback' => '']]);
?>
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
                            <button type="submit" class="button-link" name="reset" value="<?php echo esc_attr($eventcrew_prompt['key']); ?>">
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

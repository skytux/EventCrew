<?php

/**
 * Messages admin view: one entry per email and Telegram message kind, each
 * with its own form (subject, text, placeholders, formatting help, settings
 * added by other parts) and a "Reset to built-in text" form.
 *
 * @var list<array<string, mixed>> $entries MessagesPage::entries()
 * @var string $open The kind to show opened, '' for none.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

use EventCrew\Admin\MessagesPage;
use EventCrew\Support\MessageTemplate;
use EventCrew\Support\MessageTemplates;
use EventCrew\Support\TranslatedText;

?>
<div class="wrap">
    <h1><?php esc_html_e('Messages', 'eventcrew'); ?></h1>

    <p class="description" style="max-width:48em">
        <?php esc_html_e('The emails and Telegram messages EventCrew sends. Write a text in any language; it is translated into the other site languages, and each translation can be edited. A word in curly brackets, like {name}, is a placeholder: it is replaced when the message is sent. Buttons and the footer are added to every email and are not part of the text.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
    </p>

    <?php foreach ($entries as $eventcrew_entry) : ?>
        <?php
        $eventcrew_id = (string) $eventcrew_entry['id'];
        $eventcrew_field = 'ec-message-' . $eventcrew_id;
        ?>
        <details id="<?php echo esc_attr($eventcrew_field); ?>" class="ec-message"
            style="margin:1em 0;padding:0.5em 1em;background:#fff;border:1px solid #c3c4c7"
            <?php echo $open === $eventcrew_id ? 'open' : ''; ?>>
            <summary style="cursor:pointer;font-size:1.1em;padding:0.3em 0">
                <strong><?php echo esc_html((string) $eventcrew_entry['label']); ?></strong>
                <?php if ($eventcrew_entry['changed']) : ?>
                    <em>(<?php esc_html_e('changed', 'eventcrew'); ?>)</em>
                <?php endif; ?>
            </summary>

            <p><?php echo esc_html((string) $eventcrew_entry['description']); ?></p>

            <?php if (! $eventcrew_entry['wired']) : ?>
                <p class="notice notice-warning inline" style="padding:0.5em">
                    <?php esc_html_e('This message does not use the text below yet: it still sends its built-in text. A text saved here is kept for when it does.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?>
                </p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(MessagesPage::SAVE_ACTION); ?>">
                <input type="hidden" name="kind" value="<?php echo esc_attr($eventcrew_id); ?>">
                <?php wp_nonce_field(MessagesPage::SAVE_ACTION); ?>

                <?php if (null !== $eventcrew_entry['subject']) : ?>
                    <h3><?php esc_html_e('Subject', 'eventcrew'); ?></h3>
                    <?php
                    TranslatedText::field(
                        MessageTemplates::POST_NAME . '[' . MessageTemplates::SUBJECT . ']',
                        $eventcrew_field . '-subject',
                        $eventcrew_entry['subject'],
                        MessageTemplates::SUBJECT_MAX_LENGTH,
                        false
                    );
                    ?>
                <?php endif; ?>

                <h3>
                    <?php
                    echo MessageTemplate::CHANNEL_TELEGRAM === $eventcrew_entry['channel']
                        ? esc_html__('Telegram message', 'eventcrew')
                        : esc_html__('Email text', 'eventcrew');
                    ?>
                </h3>
                <?php
                TranslatedText::field(
                    MessageTemplates::POST_NAME . '[' . MessageTemplates::BODY . ']',
                    $eventcrew_field . '-body',
                    $eventcrew_entry['body'],
                    MessageTemplates::BODY_MAX_LENGTH,
                    true
                );
                ?>
                <p class="description"><?php echo esc_html((string) $eventcrew_entry['formatting']); ?></p>

                <?php if ([] !== $eventcrew_entry['placeholders']) : ?>
                    <h3><?php esc_html_e('Placeholders', 'eventcrew'); ?></h3>
                    <table class="widefat striped" style="max-width:48em">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Placeholder', 'eventcrew'); ?></th>
                                <th scope="col"><?php esc_html_e('Becomes', 'eventcrew'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($eventcrew_entry['placeholders'] as $eventcrew_tag => $eventcrew_what) : ?>
                                <tr>
                                    <td><code><?php echo esc_html((string) $eventcrew_tag); ?></code></td>
                                    <td><?php echo esc_html((string) $eventcrew_what); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php
                // Room for the per-template settings of other parts (the AI
                // tick and prompt, the hybrid tick). They post inside this
                // form and are read back through MessageTemplates::SETTINGS_FILTER.
                do_action(MessageTemplates::FIELDS_ACTION, $eventcrew_entry['template'], $eventcrew_entry['settings']);
                ?>

                <?php submit_button(__('Save this message', 'eventcrew')); ?>
            </form>

            <?php if ($eventcrew_entry['changed']) : ?>
                <?php
                $eventcrew_confirm = __(
                    'Forget the changed text of this message and use the built-in text again?',
                    'eventcrew'
                );
                ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                    onsubmit="return confirm(<?php echo esc_attr((string) wp_json_encode($eventcrew_confirm)); ?>);">
                    <input type="hidden" name="action" value="<?php echo esc_attr(MessagesPage::RESET_ACTION); ?>">
                    <input type="hidden" name="kind" value="<?php echo esc_attr($eventcrew_id); ?>">
                    <?php wp_nonce_field(MessagesPage::RESET_ACTION); ?>
                    <?php submit_button(__('Reset to built-in text', 'eventcrew'), 'secondary', 'submit', false); ?>
                </form>
            <?php endif; ?>
        </details>
    <?php endforeach; ?>

    <?php TranslatedText::script(); ?>
</div>

<?php

/**
 * Messages page, Email look tab (included by messages.php): what the Email
 * template is, the link to its block editor (while HTML email is on) and
 * its reset.
 *
 * @var bool $html_on EmailTemplate::enabled(): emails are sent as designed HTML.
 * @var string $edit_url EmailTemplate::editUrl().
 * @var string $nonce_action The nonce of the reset (SettingsPage::EMAIL_NONCE_ACTION).
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

?>
    <p style="max-width:48em"><?php esc_html_e('The email template is the frame around every email: the logo, the colours, the signature and the footer. The words of each email are on the Messages tab.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>

    <?php if (! $html_on || '' === $edit_url) : ?>
        <p class="description" style="max-width:48em"><?php esc_html_e('Emails are sent as plain text now, so the template is not used. To edit it, tick "Send notifications as HTML" in EventCrew > Settings > Email.', 'eventcrew'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction. ?></p>
    <?php else : ?>
        <p>
            <a href="<?php echo esc_url($edit_url); ?>" class="button button-primary">
                <?php esc_html_e('Edit the email template', 'eventcrew'); ?>
            </a>
        </p>
        <p class="description" style="max-width:48em">
            <?php
            printf(
                /* translators: %s: the list of merge tags, already wrapped in <code> */
                esc_html__('Opens the template in the normal editor, with revisions and undo. It is the frame around each message, not the wording: keep %s where the message should appear, and use the other tags wherever you want them.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                '<code>{{content}}</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal.
            );
            ?>
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            onsubmit="return confirm('<?php echo esc_js(__('Replace the template with the built-in design? Your version stays in the template’s revisions.', 'eventcrew')); ?>');">
            <input type="hidden" name="action" value="eventcrew_reset_email_template">
            <input type="hidden" name="back" value="messages">
            <?php wp_nonce_field($nonce_action); ?>
            <button type="submit" class="button-link"><?php esc_html_e('Reset the template', 'eventcrew'); ?></button>
        </form>
    <?php endif; ?>

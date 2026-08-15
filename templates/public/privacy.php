<?php

/**
 * The public privacy notice, rendered by the [eventcrew_privacy] shortcode and
 * the matching block.
 *
 * @var bool $eventcrew_configured Whether a controller and contact have been set.
 * @var bool $eventcrew_can_edit Whether the current viewer can fix that.
 * @var array<int, array{heading: string, paragraphs: array<int, string>}> $eventcrew_sections
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="eventcrew-privacy">
    <?php if (! $eventcrew_configured) : ?>
        <?php
        /*
         * Nothing is shown to a visitor here on purpose. An empty notice tells
         * them nothing and would suggest the question had been answered; the
         * organizer is the only one who can do anything about it, so they are
         * the only one who hears about it.
         */
        ?>
        <?php if ($eventcrew_can_edit) : ?>
            <p class="eventcrew-muted">
                <strong><?php esc_html_e('EventCrew privacy notice — not yet filled in.', 'eventcrew'); ?></strong>
                <?php
                printf(
                    /* translators: %s: link to the settings tab, already wrapped in an anchor tag */
                    esc_html__('Set who is responsible for the crew\'s data under %s, and this page will publish itself. Only you can see this message.', 'eventcrew'),
                    '<a href="' . esc_url(admin_url('admin.php?page=eventcrew-settings#privacy')) . '">'
                        . esc_html__('EventCrew → Settings → Privacy', 'eventcrew')
                        . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
                );
                ?>
            </p>
        <?php endif; ?>
    <?php else : ?>
        <?php foreach ($eventcrew_sections as $eventcrew_section) : ?>
            <h2><?php echo esc_html($eventcrew_section['heading']); ?></h2>
            <?php foreach ($eventcrew_section['paragraphs'] as $eventcrew_paragraph) : ?>
                <p><?php echo esc_html($eventcrew_paragraph); ?></p>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <?php
        $eventcrew_updated = \EventCrew\Support\PrivacyPolicy::updatedAt();

        if ($eventcrew_updated > 0) :
            ?>
            <p class="eventcrew-muted eventcrew-hint">
                <?php
                printf(
                    /* translators: %s: the date this notice was last changed */
                    esc_html__('This notice was last updated on %s.', 'eventcrew'),
                    esc_html((string) wp_date((string) get_option('date_format', 'Y-m-d'), $eventcrew_updated))
                );
                ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>

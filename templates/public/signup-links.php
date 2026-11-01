<?php

/**
 * The buttons under the board: the crew's Telegram group (the main button,
 * filled) and the crew guide (a second, outlined button beside it). Signed out
 * there is no group link, so only the guide shows. Nothing is drawn when
 * neither is set.
 *
 * @var string $eventcrew_group_link The Telegram group link, or '' to leave it out.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$eventcrew_guide = \EventCrew\Support\RoleGuide::emailAction();

if ('' === $eventcrew_group_link && null === $eventcrew_guide) {
    return;
}
?>
<p class="eventcrew-links">
    <?php if ('' !== $eventcrew_group_link) : ?>
        <a class="wp-element-button eventcrew-link-primary" href="<?php echo esc_url($eventcrew_group_link); ?>" target="_blank" rel="noopener">
            <?php esc_html_e('Open our group in Telegram', 'eventcrew'); ?> →
        </a>
    <?php endif; ?>
    <?php if (null !== $eventcrew_guide) : ?>
        <a class="wp-element-button is-style-outline eventcrew-link-secondary" href="<?php echo esc_url($eventcrew_guide['url']); ?>" target="_blank" rel="noopener">
            <?php echo esc_html($eventcrew_guide['label']); ?>
        </a>
    <?php endif; ?>
</p>

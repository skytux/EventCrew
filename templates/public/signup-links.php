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
            <svg class="eventcrew-link-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>
            <?php esc_html_e('Telegram group', 'eventcrew'); ?>
        </a>
    <?php endif; ?>
    <?php if (null !== $eventcrew_guide) : ?>
        <a class="wp-element-button is-style-outline eventcrew-link-secondary" href="<?php echo esc_url($eventcrew_guide['url']); ?>" target="_blank" rel="noopener">
            <?php echo esc_html($eventcrew_guide['label']); ?>
        </a>
    <?php endif; ?>
</p>

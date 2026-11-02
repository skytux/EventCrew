<?php

/**
 * The Crew app as a page of its own (Web\CrewAppPage): the full width, no
 * theme header, menu or footer. wp_head() and wp_footer() still run, so the
 * plugin's stylesheet, the theme's fonts and colours, the Cloudflare check
 * and the app (install) tags all load as on any page.
 */

defined('ABSPATH') || exit;

$eventcrew_page = \EventCrew\Web\CrewAppPage::current();

if (null === $eventcrew_page) {
    return;
}

// Rendered first, so the stylesheet it enqueues is printed by wp_head().
$eventcrew_content = $eventcrew_page->content();
$eventcrew_colors = $eventcrew_page->colors();
// The frame around it (lang attribute, back link) in the same language.
$eventcrew_language = $eventcrew_page->language();
?><!doctype html>
<html <?php \EventCrew\Support\Language::run($eventcrew_language, static function (): void {
    language_attributes();
}); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
<style>
    body.eventcrew-crew-app {
        margin: 0;
        background: <?php echo esc_html($eventcrew_colors['background']); ?>;
        /* The sticky tab bar paints this behind itself; see eventcrew.css. */
        --eventcrew-page-bg: <?php echo esc_html($eventcrew_colors['background']); ?>;
        color: <?php echo esc_html($eventcrew_colors['text']); ?>;
    }
    .eventcrew-app-bar {
        padding: 10px 16px;
        font-size: 15px;
    }
    .eventcrew-app-bar a {
        color: inherit;
        text-decoration: none;
        opacity: .85;
    }
    .eventcrew-app-bar a:hover,
    .eventcrew-app-bar a:focus {
        opacity: 1;
        text-decoration: underline;
    }
    .eventcrew-app-main {
        box-sizing: border-box;
        width: 100%;
        padding: 0 16px 32px;
    }
</style>
</head>
<body <?php body_class('eventcrew-crew-app'); ?>>
<?php wp_body_open(); ?>
<nav class="eventcrew-app-bar">
    <a href="<?php echo esc_url(home_url('/')); ?>">← <?php echo esc_html(\EventCrew\Support\Language::run($eventcrew_language, static fn (): string => __('Website', 'eventcrew'))); ?></a>
</nav>
<main class="eventcrew-app-main">
    <?php
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the signup template escapes its own output.
    echo $eventcrew_content;
    ?>
</main>
<?php wp_footer(); ?>
</body>
</html>

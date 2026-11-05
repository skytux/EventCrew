<?php

/**
 * Messages admin view: the tabs (MessagesPage::tabs()) and the open tab,
 * from its own file: messages-tab-messages.php, messages-tab-ai.php,
 * messages-tab-events.php or messages-tab-email.php. Each tab reads the data
 * MessagesPage::data() gives it.
 *
 * @var array<string, string> $tabs Tab slug => label.
 * @var string $tab The open tab.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

use EventCrew\Admin\MessagesPage;

?>
<div class="wrap">
    <h1><?php esc_html_e('Messages', 'eventcrew'); ?></h1>

    <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('Messages', 'eventcrew'); ?>">
        <?php foreach ($tabs as $eventcrew_tab => $eventcrew_tab_label) : ?>
            <a href="<?php echo esc_url(MessagesPage::url((string) $eventcrew_tab)); ?>"
                class="nav-tab<?php echo $tab === $eventcrew_tab ? ' nav-tab-active' : ''; ?>"
                <?php echo $tab === $eventcrew_tab ? 'aria-current="page"' : ''; ?>>
                <?php echo esc_html($eventcrew_tab_label); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php
    $eventcrew_tab_file = __DIR__ . '/messages-tab-' . (isset($tabs[$tab]) ? $tab : MessagesPage::TAB_MESSAGES) . '.php';

    include $eventcrew_tab_file;
    ?>
</div>

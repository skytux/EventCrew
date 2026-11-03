<?php

/**
 * Bot log admin view: a day picker, how many commands each person used that
 * day, and the commands themselves, newest first, paged.
 *
 * @var string $day
 * @var int $page
 * @var int $pages
 * @var int $total
 * @var array<int, array<string, string>> $rows
 * @var array<int, array{name: string, commands: int}> $people
 * @var int $keep_days
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

use EventCrew\Admin\BotLogPage;

$eventcrew_page_url = static function (int $number) use ($day): string {
    return add_query_arg(
        ['page' => BotLogPage::PAGE_SLUG, 'day' => $day, 'paged' => $number],
        admin_url('admin.php')
    );
};
?>
<div class="wrap">
    <h1><?php esc_html_e('Bot log', 'eventcrew'); ?></h1>

    <p class="description">
        <?php
        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
        printf(
            /* translators: %d: number of days */
            esc_html__('Which commands and buttons the Telegram bot handled. Message text is not stored. Rows are deleted after %d days.', 'eventcrew'),
            (int) $keep_days
        );
        // phpcs:enable Generic.Files.LineLength.TooLong
        ?>
    </p>

    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>"
        style="margin:1em 0;display:flex;gap:.4em;align-items:center">
        <input type="hidden" name="page" value="<?php echo esc_attr(BotLogPage::PAGE_SLUG); ?>">
        <label for="eventcrew-bot-log-day"><?php esc_html_e('Day', 'eventcrew'); ?></label>
        <input type="date" id="eventcrew-bot-log-day" name="day" value="<?php echo esc_attr($day); ?>">
        <button type="submit" class="button"><?php esc_html_e('Show', 'eventcrew'); ?></button>
    </form>

    <h2><?php esc_html_e('Commands per person', 'eventcrew'); ?></h2>

    <?php if ([] === $people) : ?>
        <p><?php esc_html_e('No commands on this day.', 'eventcrew'); ?></p>
    <?php else : ?>
        <table class="widefat striped" style="max-width:500px">
            <thead>
                <tr>
                    <th><?php esc_html_e('Person', 'eventcrew'); ?></th>
                    <th style="width:120px"><?php esc_html_e('Commands', 'eventcrew'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($people as $eventcrew_person) : ?>
                    <tr>
                        <td><?php echo esc_html($eventcrew_person['name']); ?></td>
                        <td><?php echo esc_html((string) $eventcrew_person['commands']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2 style="margin-top:1.5em"><?php esc_html_e('Commands', 'eventcrew'); ?></h2>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th style="width:60px"><?php esc_html_e('Time', 'eventcrew'); ?></th>
                    <th><?php esc_html_e('Person', 'eventcrew'); ?></th>
                    <th><?php esc_html_e('Chat', 'eventcrew'); ?></th>
                    <th><?php esc_html_e('Command', 'eventcrew'); ?></th>
                    <th><?php esc_html_e('Source', 'eventcrew'); ?></th>
                    <th><?php esc_html_e('Button', 'eventcrew'); ?></th>
                    <th><?php esc_html_e('Date', 'eventcrew'); ?></th>
                    <th><?php esc_html_e('Outcome', 'eventcrew'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $eventcrew_row) : ?>
                    <tr>
                        <td><?php echo esc_html($eventcrew_row['time']); ?></td>
                        <td><?php echo esc_html($eventcrew_row['person']); ?></td>
                        <td><?php echo esc_html($eventcrew_row['chat']); ?></td>
                        <td><code><?php echo esc_html($eventcrew_row['command']); ?></code></td>
                        <td><?php echo esc_html($eventcrew_row['source']); ?></td>
                        <td>
                            <?php if ('' !== $eventcrew_row['button']) : ?>
                                <code><?php echo esc_html($eventcrew_row['button']); ?></code>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($eventcrew_row['date']); ?></td>
                        <td><?php echo esc_html($eventcrew_row['outcome']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($pages > 1) : ?>
            <p style="margin-top:1em;display:flex;gap:.6em;align-items:center">
                <?php if ($page > 1) : ?>
                    <a class="button" href="<?php echo esc_url($eventcrew_page_url($page - 1)); ?>">
                        <?php esc_html_e('Newer', 'eventcrew'); ?>
                    </a>
                <?php endif; ?>
                <span>
                    <?php
                    printf(
                        /* translators: 1: page number, 2: number of pages, 3: number of rows */
                        esc_html__('Page %1$d of %2$d (%3$d rows)', 'eventcrew'),
                        (int) $page,
                        (int) $pages,
                        (int) $total
                    );
                    ?>
                </span>
                <?php if ($page < $pages) : ?>
                    <a class="button" href="<?php echo esc_url($eventcrew_page_url($page + 1)); ?>">
                        <?php esc_html_e('Older', 'eventcrew'); ?>
                    </a>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>

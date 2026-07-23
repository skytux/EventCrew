<?php

/**
 * The open-task board: the grouped task list with its Sign up / Cancel buttons.
 * Rendered both inside the full signup page and on its own for the AJAX refresh,
 * so a claim/drop updates the board in place without reloading the page. The
 * action notice is shown separately as a toast, so it stays visible regardless
 * of where the page is scrolled.
 *
 * @var array<string, mixed> $view The SignupController view model.
 */



declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/** @var \EventCrew\Models\Person|null $eventcrew_person */
$eventcrew_person = $view['person'];
$eventcrew_csrf = (string) $view['csrf'];
$eventcrew_ajax = admin_url('admin-ajax.php');
// When rendered inside the full page $eventcrew_here is already the page's
// permalink; on the AJAX path the controller sets it from the request, since
// get_permalink() has no queried page to read during admin-ajax.
if (! isset($eventcrew_here) || false === $eventcrew_here || '' === $eventcrew_here) {
    $eventcrew_here = get_permalink();
    if (false === $eventcrew_here) {
        $eventcrew_here = home_url('/');
    }
}

$eventcrew_hidden = static function (string $action, string $csrf, string $here): string {

    return sprintf('<input type="hidden" name="action" value="%s"><input type="hidden" name="csrf" value="%s"><input type="hidden" name="redirect_to" value="%s">', esc_attr($action), esc_attr($csrf), esc_attr($here));
};
?>
<?php if ([] === $view['groups']) :
    ?>
    <p><?php esc_html_e('No open tasks right now. Check back soon!', 'eventcrew'); ?></p>
    <?php
endif; ?>

<?php foreach ($view['groups'] as $eventcrew_group) :
    ?>
    <h4><?php echo esc_html($eventcrew_group['title']); ?></h4>
    <ul>
        <?php foreach ($eventcrew_group['tasks'] as $eventcrew_row) :
            ?>
            <?php
            $eventcrew_task = $eventcrew_row['task'];
            $eventcrew_time = $eventcrew_task->timeRange();
            $eventcrew_full = $eventcrew_row['taken'] >= $eventcrew_task->capacity;
            ?>
            <li>
                <span class="eventcrew-task">
                    <?php echo esc_html(date_i18n('j M', strtotime($eventcrew_task->taskDate))); ?>
                    <?php echo '' === $eventcrew_time ? '' : ' · ' . esc_html($eventcrew_time); ?>
                    <?php echo esc_html($eventcrew_task->roleDisplay()); ?>
                    <span class="eventcrew-muted">(<?php echo esc_html(sprintf('%d/%d', $eventcrew_row['taken'], $eventcrew_task->capacity)); ?>)</span>
                </span>
                <?php if (null === $eventcrew_person) :
                    ?>
                    <span class="eventcrew-muted"><?php esc_html_e('sign in first', 'eventcrew'); ?></span>
                    <?php
                elseif ($eventcrew_row['mine']) :
                    ?>
                    <form class="eventcrew-action" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                        <?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- builder escapes each field.
                        echo $eventcrew_hidden((string) $view['drop_action'], $eventcrew_csrf, $eventcrew_here);
                        ?>
                        <input type="hidden" name="task_id" value="<?php echo esc_attr((string) $eventcrew_task->id); ?>">
                        <button type="submit" class="wp-element-button eventcrew-btn-stop"><?php esc_html_e('Cancel', 'eventcrew'); ?></button>
                    </form>
                    <?php
                elseif ($eventcrew_full) :
                    ?>
                    <button type="button" class="wp-element-button eventcrew-btn-full" disabled><?php esc_html_e('Full', 'eventcrew'); ?></button>
                    <?php
                else :
                    ?>
                    <form class="eventcrew-action" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                        <?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- builder escapes each field.
                        echo $eventcrew_hidden((string) $view['claim_action'], $eventcrew_csrf, $eventcrew_here);
                        ?>
                        <input type="hidden" name="task_id" value="<?php echo esc_attr((string) $eventcrew_task->id); ?>">
                        <button type="submit" class="wp-element-button eventcrew-btn-go"><?php esc_html_e('Sign up', 'eventcrew'); ?></button>
                    </form>
                    <?php
                endif; ?>
            </li>
            <?php
        endforeach; ?>
    </ul>
    <?php
endforeach; ?>

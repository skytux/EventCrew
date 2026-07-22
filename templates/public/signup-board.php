<?php
/**
 * The open-task board: the action notice plus the grouped task list with its
 * Sign up / Cancel buttons. Rendered both inside the full signup page and on
 * its own for the AJAX refresh, so a claim/drop updates the board in place
 * without reloading the page.
 *
 * @var array<string, mixed> $view The SignupController view model.
 * @var string $eventcrew_notice_code The notice to show, or '' for none.
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

$eventcrew_notices = [
    'check_email' => __('Check your email for a sign-in link.', 'eventcrew'),
    'bad_email' => __('That doesn’t look like a valid email address.', 'eventcrew'),
    'signed_in' => __('You’re signed in.', 'eventcrew'),
    'bad_link' => __('That sign-in link is invalid or has expired.', 'eventcrew'),
    'signed_out' => __('You’re signed out.', 'eventcrew'),
    'claimed' => __('You’re signed up — thanks!', 'eventcrew'),
    'dropped' => __('You’ve cancelled that task.', 'eventcrew'),
    'already' => __('You were already signed up for that.', 'eventcrew'),
    'full' => __('That slot just filled up.', 'eventcrew'),
    'overlap' => __('That clashes with another slot you hold.', 'eventcrew'),
    'gated' => __('Sign-ups are paused on your account — please contact the organizer.', 'eventcrew'),
    'unavailable' => __('That task is no longer available.', 'eventcrew'),
    'not_on' => __('You weren’t signed up for that one.', 'eventcrew'),
    'please_sign_in' => __('Please sign in first.', 'eventcrew'),
];

$eventcrew_hidden = static function (string $action, string $csrf, string $here): string {
    return sprintf(
        '<input type="hidden" name="action" value="%s"><input type="hidden" name="csrf" value="%s"><input type="hidden" name="redirect_to" value="%s">',
        esc_attr($action),
        esc_attr($csrf),
        esc_attr($here)
    );
};
?>
<?php if (isset($eventcrew_notices[$eventcrew_notice_code])) : ?>
    <p class="eventcrew-notice"><?php echo esc_html($eventcrew_notices[$eventcrew_notice_code]); ?></p>
<?php endif; ?>

<?php if ([] === $view['groups']) : ?>
    <p><?php esc_html_e('No open tasks right now. Check back soon!', 'eventcrew'); ?></p>
<?php endif; ?>

<?php foreach ($view['groups'] as $eventcrew_group) : ?>
    <h3><?php echo esc_html($eventcrew_group['title']); ?></h3>
    <ul>
        <?php foreach ($eventcrew_group['tasks'] as $eventcrew_row) : ?>
            <?php
            $eventcrew_task = $eventcrew_row['task'];
            $eventcrew_time = $eventcrew_task->timeRange();
            $eventcrew_full = $eventcrew_row['taken'] >= $eventcrew_task->capacity;
            ?>
            <li>
                <span class="eventcrew-task">
                    <?php echo esc_html($eventcrew_task->roleDisplay()); ?>
                    <?php echo '' === $eventcrew_time ? '' : ' · ' . esc_html($eventcrew_time); ?>
                    <?php echo esc_html($eventcrew_task->taskDate); ?>
                    <span class="eventcrew-muted">(<?php echo esc_html(sprintf('%d/%d', $eventcrew_row['taken'], $eventcrew_task->capacity)); ?>)</span>
                </span>
                <?php if (null === $eventcrew_person) : ?>
                    <span class="eventcrew-muted"><?php esc_html_e('sign in first', 'eventcrew'); ?></span>
                <?php elseif ($eventcrew_row['mine']) : ?>
                    <form class="eventcrew-action" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                        <?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- builder escapes each field.
                        echo $eventcrew_hidden((string) $view['drop_action'], $eventcrew_csrf, $eventcrew_here);
                        ?>
                        <input type="hidden" name="task_id" value="<?php echo esc_attr((string) $eventcrew_task->id); ?>">
                        <button type="submit" class="wp-element-button eventcrew-btn-stop"><?php esc_html_e('Cancel', 'eventcrew'); ?></button>
                    </form>
                <?php elseif ($eventcrew_full) : ?>
                    <span class="eventcrew-muted"><?php esc_html_e('full', 'eventcrew'); ?></span>
                <?php else : ?>
                    <form class="eventcrew-action" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                        <?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- builder escapes each field.
                        echo $eventcrew_hidden((string) $view['claim_action'], $eventcrew_csrf, $eventcrew_here);
                        ?>
                        <input type="hidden" name="task_id" value="<?php echo esc_attr((string) $eventcrew_task->id); ?>">
                        <button type="submit" class="wp-element-button eventcrew-btn-go"><?php esc_html_e('Sign up', 'eventcrew'); ?></button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endforeach; ?>

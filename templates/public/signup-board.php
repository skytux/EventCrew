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

<?php
/*
 * Signed out, the board says once, above the list, that signing in comes
 * first. It used to say "sign in first" on every row, which on a full board
 * was the same three words a dozen times down the right edge.
 */
if (null === $eventcrew_person && [] !== $view['groups']) :
    ?>
    <p class="eventcrew-board-note"><?php esc_html_e('Guest view. Sign in above to take a task and see all tasks.', 'eventcrew'); ?></p>
    <?php
endif; ?>

<?php
/*
 * The event cards sit in a grid: one column on a narrow page, more where there
 * is room. The grid carries the id the Telegram "What each task involves" link
 * points at, so that link lands on the tasks, whose (i) buttons say what each
 * one involves.
 */
?>
<div class="eventcrew-board-grid" id="<?php echo esc_attr(\EventCrew\Support\RoleGuide::DESCRIPTIONS_ANCHOR); ?>">
<?php foreach ($view['groups'] as $eventcrew_group) :
    ?>
    <?php
    /*
     * Each event is a card with its date in the header. When every task in
     * the event falls on one day the rows show only their time, because the
     * date above already says which day; an event spread over several days
     * keeps the date on each row.
     */
    $eventcrew_dates = array_unique(array_map(
        static fn (array $row): string => (string) $row['task']->taskDate,
        $eventcrew_group['tasks']
    ));
    $eventcrew_one_day = 1 === count($eventcrew_dates);
    $eventcrew_group_date = $eventcrew_one_day
        ? date_i18n('l j F', (int) strtotime((string) reset($eventcrew_dates)))
        : '';
    ?>
    <?php
    // The event's picture behind its header, darkened so the white text on
    // it stays readable. No picture: the plain header.
    $eventcrew_image = (string) ($eventcrew_group['image'] ?? '');
    ?>
    <section class="eventcrew-event">
    <header class="eventcrew-event-head<?php echo '' !== $eventcrew_image ? ' has-image' : ''; ?>"<?php
    if ('' !== $eventcrew_image) {
        printf(' style="%s"', esc_attr('--eventcrew-event-image: url("' . esc_url($eventcrew_image) . '")'));
    }
    ?>>
        <h3 class="eventcrew-event-title"><?php echo esc_html($eventcrew_group['title']); ?></h3>
        <?php if ('' !== $eventcrew_group_date) : ?>
            <span class="eventcrew-event-date"><?php echo esc_html($eventcrew_group_date); ?></span>
        <?php endif; ?>
    </header>
    <ul>
        <?php foreach ($eventcrew_group['tasks'] as $eventcrew_row) :
            ?>
            <?php
            $eventcrew_task = $eventcrew_row['task'];
            $eventcrew_time = $eventcrew_task->timeRange();
            $eventcrew_no_limit = $eventcrew_task->hasNoLimit();
            $eventcrew_full = ! $eventcrew_no_limit && $eventcrew_row['taken'] >= $eventcrew_task->capacity;
            $eventcrew_when = $eventcrew_one_day
                ? $eventcrew_time
                : date_i18n('D j M', (int) strtotime($eventcrew_task->taskDate))
                    . ('' === $eventcrew_time ? '' : ' · ' . $eventcrew_time);
            /* translators: 1: people signed up so far, 2: people needed in total */
            $eventcrew_fill = sprintf(__('%1$d of %2$d', 'eventcrew'), $eventcrew_row['taken'], $eventcrew_task->capacity);

            if ($eventcrew_no_limit) {
                /* translators: %d: people signed up so far, for a task with no limit on people */
                $eventcrew_fill = sprintf(__('%d signed up', 'eventcrew'), $eventcrew_row['taken']);
            }
            ?>
            <li class="eventcrew-task-row" data-eventcrew-task="<?php echo esc_attr((string) $eventcrew_task->id); ?>">
                <?php
                $eventcrew_about = trim($eventcrew_task->roleDescription());
                $eventcrew_about_id = 'eventcrew-about-' . $eventcrew_task->id;
                ?>
                <span class="eventcrew-task-main">
                    <span class="eventcrew-task-role">
                        <?php echo esc_html($eventcrew_task->roleDisplay()); ?>
                        <?php
                        /*
                         * The (i) button opens what this task involves in a
                         * bubble (the browser's popover: a tap outside or Esc
                         * closes it). A browser without popovers ignores the
                         * attribute and shows the text under the name instead.
                         */
                        if ('' !== $eventcrew_about) :
                            ?>
                            <button type="button" class="eventcrew-about-btn" popovertarget="<?php echo esc_attr($eventcrew_about_id); ?>" aria-label="<?php esc_attr_e('About this task', 'eventcrew'); ?>" title="<?php esc_attr_e('About this task', 'eventcrew'); ?>">i</button>
                        <?php endif; ?>
                    </span>
                    <?php if ('' !== $eventcrew_when) : ?>
                        <span class="eventcrew-task-when"><?php echo esc_html($eventcrew_when); ?></span>
                    <?php endif; ?>
                    <?php if ('' !== $eventcrew_about) : ?>
                        <span class="eventcrew-about" id="<?php echo esc_attr($eventcrew_about_id); ?>" popover>
                            <strong><?php echo esc_html($eventcrew_task->roleDisplay()); ?></strong>
                            <?php echo esc_html($eventcrew_about); ?>
                        </span>
                    <?php endif; ?>
                </span>
                <?php
                /*
                 * How full, at a glance: one pip per slot, with the count in
                 * words beside it. The dots read quickly but do not say what
                 * they are counting, so the words carry the meaning the first
                 * time and the dots carry it every time after. Past six slots
                 * the dots stop being countable and only the words remain.
                 */
                ?>
                <?php
                // The count and the action travel together: when the row is too
                // narrow for one line they wrap as a pair to the right, rather
                // than one of them dropping away from the other.
                ?>
                <span class="eventcrew-task-side">
                <span class="eventcrew-capacity">
                    <?php if (! $eventcrew_no_limit && $eventcrew_task->capacity <= 6) : ?>
                        <span class="eventcrew-pips" aria-hidden="true">
                            <?php for ($eventcrew_pip = 1; $eventcrew_pip <= $eventcrew_task->capacity; $eventcrew_pip++) : ?>
                                <span class="eventcrew-pip<?php echo $eventcrew_pip <= $eventcrew_row['taken'] ? ' is-filled' : ''; ?>"></span>
                            <?php endfor; ?>
                        </span>
                    <?php endif; ?>
                    <span class="eventcrew-capacity-count"><?php echo esc_html($eventcrew_fill); ?></span>
                </span>
                <?php
                $eventcrew_blocked = (string) ($eventcrew_row['blocked'] ?? '');
                ?>
                <?php if (null === $eventcrew_person) :
                    // Nothing to press until signed in; the note above the
                    // board says so once.
                    ?>
                    <?php
                elseif (! $eventcrew_row['mine'] && '' !== $eventcrew_blocked) :
                    /*
                     * Say so here rather than after the press. The rule is
                     * SignupService::refusalFor()'s; this only words it.
                     */
                    ?>
                    <span class="eventcrew-blocked">
                        <?php
                        echo esc_html(__('On hold — talk to an organizer', 'eventcrew'));
                        ?>
                    </span>
                    <?php
                elseif (! $eventcrew_row['mine'] && ! empty($eventcrew_row['role_only'])) :
                    // An organizer sees a task of a role they do not hold.
                    ?>
                    <button type="button" class="wp-element-button eventcrew-btn-full" disabled>
                        <?php
                        /* translators: %s: a role name, e.g. DJ */
                        echo esc_html(sprintf(__('%s only', 'eventcrew'), $eventcrew_task->roleLabel()));
                        ?>
                    </button>
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
                elseif ($eventcrew_task->isRestricted()) :
                    /*
                     * A restricted task (Organize, DJ, ...) asks first whether it
                     * counts toward free-entry credit. Yes and No are two small
                     * forms, so each sends its own answer without any script.
                     */
                    ?>
                    <details class="eventcrew-credit-ask">
                        <summary class="wp-element-button eventcrew-btn-go"><?php esc_html_e('Sign up', 'eventcrew'); ?></summary>
                        <span class="eventcrew-muted"><?php esc_html_e('Count toward free-entry credit?', 'eventcrew'); ?></span>
                        <?php foreach (['1' => __('Yes', 'eventcrew'), '0' => __('No', 'eventcrew')] as $eventcrew_answer => $eventcrew_answer_label) : ?>
                            <form class="eventcrew-action" method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                                <?php
                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- builder escapes each field.
                                echo $eventcrew_hidden((string) $view['claim_action'], $eventcrew_csrf, $eventcrew_here);
                                ?>
                                <input type="hidden" name="task_id" value="<?php echo esc_attr((string) $eventcrew_task->id); ?>">
                                <input type="hidden" name="counts_credit" value="<?php echo esc_attr((string) $eventcrew_answer); ?>">
                                <button type="submit" class="wp-element-button eventcrew-btn-go"><?php echo esc_html($eventcrew_answer_label); ?></button>
                            </form>
                        <?php endforeach; ?>
                    </details>
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
                </span>
            </li>
            <?php
        endforeach; ?>
    </ul>
    </section>
    <?php
endforeach; ?>

</div>

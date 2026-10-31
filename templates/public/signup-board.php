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
    <h3><?php echo esc_html($eventcrew_group['title']); ?></h3>
    <ul>
        <?php foreach ($eventcrew_group['tasks'] as $eventcrew_row) :
            ?>
            <?php
            $eventcrew_task = $eventcrew_row['task'];
            $eventcrew_time = $eventcrew_task->timeRange();
            $eventcrew_no_limit = $eventcrew_task->hasNoLimit();
            $eventcrew_full = ! $eventcrew_no_limit && $eventcrew_row['taken'] >= $eventcrew_task->capacity;
            $eventcrew_when = date_i18n('j M', strtotime($eventcrew_task->taskDate))
                . ('' === $eventcrew_time ? '' : ' · ' . $eventcrew_time);
            /* translators: 1: people signed up so far, 2: people needed in total */
            $eventcrew_fill = sprintf(__('%1$d of %2$d', 'eventcrew'), $eventcrew_row['taken'], $eventcrew_task->capacity);

            if ($eventcrew_no_limit) {
                /* translators: %d: people signed up so far, for a task with no limit on people */
                $eventcrew_fill = sprintf(__('%d signed up', 'eventcrew'), $eventcrew_row['taken']);
            }
            ?>
            <li class="eventcrew-task-row" data-eventcrew-task="<?php echo esc_attr((string) $eventcrew_task->id); ?>">
                <span class="eventcrew-task-main">
                    <span class="eventcrew-task-role"><?php echo esc_html($eventcrew_task->roleDisplay()); ?></span>
                    <span class="eventcrew-task-when"><?php echo esc_html($eventcrew_when); ?></span>
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
                    ?>
                    <span class="eventcrew-muted"><?php esc_html_e('sign in first', 'eventcrew'); ?></span>
                    <?php
                elseif (! $eventcrew_row['mine'] && '' !== $eventcrew_blocked) :
                    /*
                     * Say so here rather than after the press. The rule is
                     * SignupService::refusalFor()'s; this only words it.
                     */
                    ?>
                    <span class="eventcrew-blocked">
                        <?php
                        echo esc_html(
                            \EventCrew\Support\SignupService::LEADER_ONLY === $eventcrew_blocked
                                ? __('Crew leaders only', 'eventcrew')
                                : __('On hold — talk to an organizer', 'eventcrew')
                        );
                        ?>
                    </span>
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
    <?php
endforeach; ?>

<?php
$eventcrew_roles = \EventCrew\Support\RoleGuide::descriptions();
if ([] !== $eventcrew_roles) :
    ?>
    <section class="eventcrew-tasks-info" id="<?php echo esc_attr(\EventCrew\Support\RoleGuide::DESCRIPTIONS_ANCHOR); ?>">
        <h3><?php esc_html_e('What each task involves', 'eventcrew'); ?></h3>
        <dl>
            <?php foreach ($eventcrew_roles as $eventcrew_role) : ?>
                <dt><?php echo esc_html($eventcrew_role['name']); ?></dt>
                <?php if ('' !== $eventcrew_role['description']) : ?>
                    <dd><?php echo esc_html($eventcrew_role['description']); ?></dd>
                <?php endif; ?>
            <?php endforeach; ?>
        </dl>
    </section>
    <?php
endif;

$eventcrew_guide = \EventCrew\Support\RoleGuide::emailAction();
if (null !== $eventcrew_guide) :
    ?>
    <p class="eventcrew-guide">
        <a href="<?php echo esc_url($eventcrew_guide['url']); ?>" target="_blank" rel="noopener">
            <?php echo esc_html($eventcrew_guide['label']); ?>
        </a>
    </p>
    <?php
endif; ?>

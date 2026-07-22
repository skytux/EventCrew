<?php
/**
 * Public signup board, rendered by the [eventcrew_signup] shortcode / block.
 *
 * @var array<string, mixed> $view The SignupController view model:
 *   person, standing, csrf, groups, and the four ajax action names.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/** @var \EventCrew\Models\Person|null $eventcrew_person */
$eventcrew_person = $view['person'];
/** @var \EventCrew\Support\Standing|null $eventcrew_standing */
$eventcrew_standing = $view['standing'];
$eventcrew_csrf = (string) $view['csrf'];
$eventcrew_ajax = admin_url('admin-ajax.php');

// The URL to return to after each action: this very page. get_permalink() gives
// the clean canonical URL of the page the shortcode sits on - unlike
// add_query_arg([], null), which yields an empty URI (so home_url() fell back to
// the site root) and sent every action to the homepage.
$eventcrew_here = get_permalink();

if (false === $eventcrew_here) {
    $eventcrew_here = home_url('/');
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect-carried status.
$eventcrew_notice_code = isset($_GET['eventcrew_notice']) ? sanitize_key(wp_unslash($_GET['eventcrew_notice'])) : '';

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
<style>
/*
 * Background-agnostic on purpose: the widget inherits the theme's text colour,
 * dims secondary text with opacity rather than a fixed grey, and tints the
 * notice with a translucent overlay - so it reads on a light or a dark theme
 * without knowing which it is on.
 */
.eventcrew-signup .eventcrew-muted { opacity: .7; }
.eventcrew-signup .eventcrew-notice {
    padding: .6em 1em; margin: 1em 0; border-radius: 4px;
    background: rgba(91, 141, 239, .14); border-left: 4px solid #5b8def; color: inherit;
}
.eventcrew-signup .eventcrew-tglink {
    display: inline-flex; align-items: center; gap: .4em;
    padding: .5em .9em; margin: .5em 0; border-radius: 6px;
    background: #229ED9; color: #fff; text-decoration: none; font-weight: 600;
}
.eventcrew-signup .eventcrew-tglink:hover { filter: brightness(1.08); color: #fff; }
</style>
<div class="eventcrew-signup">
    <?php if (isset($eventcrew_notices[$eventcrew_notice_code])) : ?>
        <p class="eventcrew-notice"><?php
            echo esc_html($eventcrew_notices[$eventcrew_notice_code]);
        ?></p>
    <?php endif; ?>

    <?php if (null === $eventcrew_person) : ?>
        <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>" style="margin:1em 0">
            <input type="hidden" name="action" value="<?php echo esc_attr((string) $view['login_action']); ?>">
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
            <label for="eventcrew-email"><?php esc_html_e('Your email to sign up:', 'eventcrew'); ?></label>
            <input type="email" id="eventcrew-email" name="email" required style="min-width:16em">
            <button type="submit"><?php esc_html_e('Email me a sign-in link', 'eventcrew'); ?></button>
        </form>
    <?php else : ?>
        <p>
            <?php
            printf(
                /* translators: %s: person's name */
                esc_html__('Signed in as %s.', 'eventcrew'),
                esc_html($eventcrew_person->name())
            );
            if (null !== $eventcrew_standing) {
                printf(
                    ' <span class="eventcrew-muted">%s · %s</span>',
                    esc_html($eventcrew_standing->levelLabel()),
                    esc_html(sprintf(
                        /* translators: %d: number of free-entry credits */
                        _n('%d credit', '%d credits', $eventcrew_standing->creditBalance, 'eventcrew'),
                        $eventcrew_standing->creditBalance
                    ))
                );
            }
            ?>
            <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>" style="display:inline">
                <input type="hidden" name="action" value="<?php echo esc_attr((string) $view['logout_action']); ?>">
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($eventcrew_here); ?>">
                <button type="submit" class="button-link"><?php esc_html_e('Sign out', 'eventcrew'); ?></button>
            </form>
        </p>
    <?php endif; ?>

    <?php if ('' !== (string) $view['telegram_group_link']) : ?>
        <p>
            <a class="eventcrew-tglink" href="<?php echo esc_url((string) $view['telegram_group_link']); ?>" target="_blank" rel="noopener">
                <?php esc_html_e('Open our group in Telegram', 'eventcrew'); ?> →
            </a>
        </p>
    <?php endif; ?>

    <?php if ([] === $view['groups']) : ?>
        <p><?php esc_html_e('No open tasks right now. Check back soon!', 'eventcrew'); ?></p>
    <?php endif; ?>

    <?php foreach ($view['groups'] as $eventcrew_group) : ?>
        <h3><?php echo esc_html($eventcrew_group['title']); ?></h3>
        <ul style="list-style:none;padding:0">
            <?php foreach ($eventcrew_group['tasks'] as $eventcrew_row) : ?>
                <?php
                $eventcrew_task = $eventcrew_row['task'];
                $eventcrew_time = $eventcrew_task->timeRange();
                $eventcrew_full = $eventcrew_row['taken'] >= $eventcrew_task->capacity;
                ?>
                <li style="margin:.4em 0;display:flex;gap:.6em;align-items:center">
                    <span style="flex:1">
                        <?php echo esc_html($eventcrew_task->roleDisplay()); ?>
                        <?php echo '' === $eventcrew_time ? '' : ' · ' . esc_html($eventcrew_time); ?>
                        <?php echo esc_html($eventcrew_task->taskDate); ?>
                        <span class="eventcrew-muted">(<?php echo esc_html(sprintf('%d/%d', $eventcrew_row['taken'], $eventcrew_task->capacity)); ?>)</span>
                    </span>
                    <?php if (null === $eventcrew_person) : ?>
                        <span class="eventcrew-muted"><?php esc_html_e('sign in first', 'eventcrew'); ?></span>
                    <?php elseif ($eventcrew_row['mine']) : ?>
                        <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                            <?php
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- builder escapes each field.
                            echo $eventcrew_hidden((string) $view['drop_action'], $eventcrew_csrf, $eventcrew_here);
                            ?>
                            <input type="hidden" name="task_id" value="<?php echo esc_attr((string) $eventcrew_task->id); ?>">
                            <button type="submit"><?php esc_html_e('Cancel', 'eventcrew'); ?></button>
                        </form>
                    <?php elseif ($eventcrew_full) : ?>
                        <span class="eventcrew-muted"><?php esc_html_e('full', 'eventcrew'); ?></span>
                    <?php else : ?>
                        <form method="post" action="<?php echo esc_url($eventcrew_ajax); ?>">
                            <?php
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- builder escapes each field.
                            echo $eventcrew_hidden((string) $view['claim_action'], $eventcrew_csrf, $eventcrew_here);
                            ?>
                            <input type="hidden" name="task_id" value="<?php echo esc_attr((string) $eventcrew_task->id); ?>">
                            <button type="submit"><?php esc_html_e('Sign up', 'eventcrew'); ?></button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
</div>

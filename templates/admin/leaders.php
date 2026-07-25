<?php

/**
 * Leadership admin view.
 *
 * @var array<int, array{slug: string, label: string}> $roles Active roles, for the columns.
 * @var array<int, array{id: int, name: string, can_lead: bool, by_role: array<string, int>}> $eligible People who meet the bar.
 * @var array<int, array{id: int, name: string}> $allowed People with leader permission.
 * @var int $threshold Completions required in each role.
 * @var bool $leader_default Whether the leader slot is on by default globally.
 * @var string $people_page The People page slug, for edit links.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$eventcrew_edit_url = static function (int $personId) use ($people_page): string {
    return esc_url(add_query_arg(
        ['page' => $people_page, 'person' => $personId],
        admin_url('admin.php')
    ));
};
?>
<div class="wrap">
    <h1><?php esc_html_e('Leadership', 'eventcrew'); ?></h1>

    <h2 class="nav-tab-wrapper" style="margin:.5em 0 1em">
        <a href="<?php echo esc_url(admin_url('admin.php?page=eventcrew-people')); ?>" class="nav-tab"><?php esc_html_e('People', 'eventcrew'); ?></a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=eventcrew-leaders')); ?>" class="nav-tab nav-tab-active"><?php esc_html_e('Leadership', 'eventcrew'); ?></a>
    </h2>

    <p class="description">
        <?php echo esc_html(sprintf(
            /* translators: %d: completions required in each role */
            __('Eligible = has completed at least %d tasks in every active role. Eligibility is only a suggestion; grant leader on the People screen or with the bot’s /allow.', 'eventcrew'),
            $threshold
        )); ?>
        <br>
        <?php echo $leader_default
            ? esc_html__('The leader slot is on by default for new events.', 'eventcrew')
            : esc_html__('The leader slot is off by default; turn it on per event on the Roster screen.', 'eventcrew'); ?>
    </p>

    <h2><?php esc_html_e('Eligible', 'eventcrew'); ?></h2>
    <?php if ([] === $eligible) : ?>
        <p><?php esc_html_e('Nobody has completed enough of every role yet.', 'eventcrew'); ?></p>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped" style="max-width:60em">
            <thead>
                <tr>
                    <th><?php esc_html_e('Person', 'eventcrew'); ?></th>
                    <?php foreach ($roles as $eventcrew_role) : ?>
                        <th><?php echo esc_html($eventcrew_role['label']); ?></th>
                    <?php endforeach; ?>
                    <th><?php esc_html_e('Leader', 'eventcrew'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($eligible as $eventcrew_person) : ?>
                    <tr>
                        <td>
                            <a href="<?php echo $eventcrew_edit_url($eventcrew_person['id']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns an esc_url'd string. ?>">
                                <?php echo esc_html($eventcrew_person['name']); ?>
                            </a>
                        </td>
                        <?php foreach ($roles as $eventcrew_role) : ?>
                            <td><?php echo esc_html((string) ($eventcrew_person['by_role'][$eventcrew_role['slug']] ?? 0)); ?></td>
                        <?php endforeach; ?>
                        <td><?php echo $eventcrew_person['can_lead']
                            ? '<span class="dashicons dashicons-yes" style="color:#1a7f37"></span>'
                            : '&mdash;'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2><?php esc_html_e('Allowed to lead', 'eventcrew'); ?></h2>
    <?php if ([] === $allowed) : ?>
        <p><?php esc_html_e('No one has been granted leader permission yet.', 'eventcrew'); ?></p>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped" style="max-width:40em">
            <tbody>
                <?php foreach ($allowed as $eventcrew_person) : ?>
                    <tr>
                        <td>
                            <a href="<?php echo $eventcrew_edit_url($eventcrew_person['id']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns an esc_url'd string. ?>">
                                <?php echo esc_html($eventcrew_person['name']); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

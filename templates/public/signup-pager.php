<?php

/**
 * Previous / Next under a paged Me-tab list (Tasks, Past tasks; Paging, 25
 * to a page, Owner 2026-11-08). Plain links, so paging works without
 * JavaScript; each ends in the card's #id, which opens the Me tab on it.
 * Nothing when the list fits on one page.
 *
 * Expects $eventcrew_pager = [arg, anchor] and $eventcrew_pages (each list's
 * page and page count, by query argument), from signup.php.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/** @var array{arg: string, anchor: string} $eventcrew_pager */
/** @var array<string, array{page: int, pages: int}> $eventcrew_pages */
/** @var string $eventcrew_here */

$eventcrew_pager_now = $eventcrew_pages[$eventcrew_pager['arg']] ?? ['page' => 1, 'pages' => 1];
$eventcrew_pager_current = array_map(static fn (array $p): int => (int) $p['page'], $eventcrew_pages);
?>
<?php if ($eventcrew_pager_now['pages'] > 1) : ?>
    <p class="eventcrew-pager eventcrew-card-body">
        <?php if ($eventcrew_pager_now['page'] > 1) : ?>
            <a class="eventcrew-textlink" rel="prev" href="<?php echo esc_url(\EventCrew\Web\SignupBoardView::pageUrl($eventcrew_here, $eventcrew_pager_current, $eventcrew_pager['arg'], $eventcrew_pager_now['page'] - 1, $eventcrew_pager['anchor'])); ?>">← <?php esc_html_e('Previous', 'eventcrew'); ?></a>
        <?php endif; ?>
        <span class="eventcrew-muted">
            <?php
            /* translators: 1: page number, 2: number of pages */
            echo esc_html(sprintf(__('page %1$d of %2$d', 'eventcrew'), $eventcrew_pager_now['page'], $eventcrew_pager_now['pages']));
            ?>
        </span>
        <?php if ($eventcrew_pager_now['page'] < $eventcrew_pager_now['pages']) : ?>
            <a class="eventcrew-textlink" rel="next" href="<?php echo esc_url(\EventCrew\Web\SignupBoardView::pageUrl($eventcrew_here, $eventcrew_pager_current, $eventcrew_pager['arg'], $eventcrew_pager_now['page'] + 1, $eventcrew_pager['anchor'])); ?>"><?php esc_html_e('Next', 'eventcrew'); ?> →</a>
        <?php endif; ?>
    </p>
<?php endif; ?>

<?php

/**
 * The top of one entry in the Tasks and Tickets tabs' Tickets, Tasks, Past tickets and Past
 * tasks cards (Owner 2026-11-08: "look nicer, not just like random links,
 * and add the DJ names"): the day in bold with the times or the status on
 * the right, then the event's name, its DJs (EventName::djLine()) and what
 * the entry is (the role, or "Free entry"), then the door's "Already used"
 * mark. Each card draws its own text-link actions after it, inside the same
 * <li class="eventcrew-me-row">. A part that is '' is left out.
 *
 * @var array{day: string, side?: string, side_class?: string, event: string, dj: string, what: string, used?: ?string} $eventcrew_row
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$eventcrew_row_side = (string) ($eventcrew_row['side'] ?? '');
?>
<span class="eventcrew-me-row-head">
    <span class="eventcrew-me-day"><?php echo esc_html((string) $eventcrew_row['day']); ?></span>
    <?php if ('' !== $eventcrew_row_side) : ?>
        <span class="<?php echo esc_attr((string) ($eventcrew_row['side_class'] ?? 'eventcrew-me-time')); ?>"><?php echo esc_html($eventcrew_row_side); ?></span>
    <?php endif; ?>
</span>
<?php if ('' !== (string) $eventcrew_row['event']) : ?>
    <span class="eventcrew-me-event"><?php echo esc_html((string) $eventcrew_row['event']); ?></span>
<?php endif; ?>
<?php if ('' !== (string) $eventcrew_row['dj']) : ?>
    <span class="eventcrew-event-dj eventcrew-me-dj"><?php echo esc_html((string) $eventcrew_row['dj']); ?></span>
<?php endif; ?>
<?php if ('' !== (string) $eventcrew_row['what']) : ?>
    <span class="eventcrew-me-what"><?php echo esc_html((string) $eventcrew_row['what']); ?></span>
<?php endif; ?>
<?php if (null !== ($eventcrew_row['used'] ?? null)) : ?>
    <span class="eventcrew-used"><?php echo esc_html(\EventCrew\Support\TicketList::usedText((string) $eventcrew_row['used'])); ?></span>
<?php endif; ?>

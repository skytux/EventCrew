<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Listens for EventMesh's `eventmesh/event_synced` action and, when opted
 * in, creates a brand-new event's tasks automatically - the same thing the
 * "Create an event's tasks" button on the Tasks page does by hand.
 *
 * Registering add_action() on a hook nobody fires is inert, so this boots
 * unconditionally whether or not EventMesh is installed - no
 * post_type_exists() guard is needed here, only in what the callback goes
 * on to read.
 */
final class EventMeshSyncListener
{
    /** Off by default: ROADMAP.md's own rule is that settings arrive with
     *  the release that uses them, and a fresh install of both plugins
     *  together should not immediately explode into tasks for every
     *  already-synced event before an organizer has reviewed the role
     *  templates. */
    public const OPTION_NAME = 'eventcrew_auto_create_tasks';

    public function __construct(
        private readonly TaskTemplateApplier $applier
    ) {
    }

    public function boot(): void
    {
        add_action('eventmesh/event_synced', [$this, 'onEventSynced'], 10, 2);
        add_filter('eventmesh/integrations', [$this, 'announce']);
    }

    /**
     * Answers EventMesh's integrations filter so its diagnostics screen can
     * show that EventCrew is connected, and whether it is auto-creating tasks
     * or leaving that to the manual button. Inert when EventMesh is absent -
     * the filter is simply never applied - so this stays a one-way link:
     * EventCrew knows the filter name, EventMesh knows nothing of EventCrew.
     *
     * @param mixed $integrations
     * @return array<int, array{id: string, label: string, status: string}>
     */
    public function announce(mixed $integrations): array
    {
        $integrations = is_array($integrations) ? $integrations : [];

        $integrations[] = [
            'id' => 'eventcrew',
            'label' => __('EventCrew', 'eventcrew'),
            'status' => get_option(self::OPTION_NAME, false)
                ? __('Connected — auto-creating tasks for new events', 'eventcrew')
                : __('Connected — auto-create off, tasks added manually', 'eventcrew'),
        ];

        return $integrations;
    }

    /**
     * Only ever acts on an event's first sync. A re-sync that corrects the
     * event's time never touches tasks that may already have signups or
     * organizer edits on them - after creation, the tasks belong to the
     * organizer, not to the sync.
     */
    public function onEventSynced(int $postId, bool $isNew): void
    {
        if (! $isNew) {
            return;
        }

        if (! get_option(self::OPTION_NAME, false)) {
            return;
        }

        $this->applier->apply($postId);
    }
}

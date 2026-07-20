<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\EventMeshSyncListener;
use EventCrew\Support\EventSource;
use EventCrew\Support\Roles;

/**
 * Settings carries only what the shipped features actually read. Options for
 * behaviour that does not exist yet - reminder lead times, reputation
 * thresholds, bot credentials - arrive with the release that uses them, so the
 * page never lists a control that does nothing.
 */
final class SettingsPage
{
    public const PAGE_SLUG = 'eventcrew-settings';
    private const NONCE_ACTION = 'eventcrew_settings';

    public function __construct(
        private readonly View $view,
        private readonly PersonRepository $people
    ) {
    }

    public function render(): void
    {
        $this->view->render(
            'settings',
            [
                'roles' => Roles::all(),
                'opt_in_stats' => $this->people->optInStats(),
                'nonce_action' => self::NONCE_ACTION,
                'eventmesh_available' => EventSource::isAvailable(),
                'auto_create_tasks' => (bool) get_option(EventMeshSyncListener::OPTION_NAME, false),
            ]
        );
    }

    public function save(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        Roles::save($this->submittedRoles());

        // Checkbox: absent means unticked, same reasoning as the role
        // archive checkboxes above - an absent field is a real "off", not
        // "leave unchanged".
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        update_option(EventMeshSyncListener::OPTION_NAME, isset($_POST['auto_create_tasks']));

        Admin::redirectTo(
            self::PAGE_SLUG,
            __('Settings saved.', 'eventcrew')
        );
    }

    /**
     * The form posts parallel arrays rather than one array of rows, because a
     * repeatable fieldset is far simpler to render that way. Reassembling them
     * here keeps that detail out of Roles, which should not know how the
     * form is laid out.
     *
     * @return array<int, array<string, mixed>>
     */
    private function submittedRoles(): array
    {
        // Nonce and capability were both verified in assertCanSave() above;
        // phpcs cannot follow that across the method boundary.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $slugs = isset($_POST['role_slug']) && is_array($_POST['role_slug'])
            ? array_map('sanitize_key', wp_unslash($_POST['role_slug']))
            : [];

        $labels = isset($_POST['role_label']) && is_array($_POST['role_label'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['role_label']))
            : [];

        $emojis = isset($_POST['role_emoji']) && is_array($_POST['role_emoji'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['role_emoji']))
            : [];

        $capacities = isset($_POST['role_capacity']) && is_array($_POST['role_capacity'])
            ? array_map('intval', wp_unslash($_POST['role_capacity']))
            : [];

        $anchors = isset($_POST['role_anchor']) && is_array($_POST['role_anchor'])
            ? array_map('sanitize_key', wp_unslash($_POST['role_anchor']))
            : [];

        $startOffsets = isset($_POST['role_start_offset']) && is_array($_POST['role_start_offset'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['role_start_offset']))
            : [];

        $endOffsets = isset($_POST['role_end_offset']) && is_array($_POST['role_end_offset'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['role_end_offset']))
            : [];

        // Checkboxes only post when ticked, so an absent index means "not
        // archived" rather than "unchanged" - which is what makes unticking
        // the box work at all.
        $archived = isset($_POST['role_archived']) && is_array($_POST['role_archived'])
            ? array_map('intval', array_keys(wp_unslash($_POST['role_archived'])))
            : [];
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $archivedIndexes = array_flip($archived);

        $types = [];

        foreach (array_keys($labels) as $index) {
            $types[] = [
                'slug' => $slugs[$index] ?? '',
                'label' => $labels[$index] ?? '',
                'emoji' => $emojis[$index] ?? '',
                'capacity' => $capacities[$index] ?? 1,
                'archived' => isset($archivedIndexes[$index]),
                'anchor' => $anchors[$index] ?? Roles::ANCHOR_START,
                // '' means "no offset", which is not the same as 0 ("exactly
                // on the anchor"), so the blank is preserved rather than
                // being cast to an integer here.
                'start_offset' => $this->offset($startOffsets[$index] ?? ''),
                'end_offset' => $this->offset($endOffsets[$index] ?? ''),
            ];
        }

        return $types;
    }

    /**
     * A blank offset field stays null so the role produces untimed tasks;
     * anything numeric becomes minutes, negative meaning "before the anchor".
     */
    private function offset(string $value): ?int
    {
        $value = trim($value);

        return '' === $value || 1 !== preg_match('/^-?\d+$/', $value) ? null : (int) $value;
    }
}

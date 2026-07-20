<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Repositories\PersonRepository;
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
            ]
        );
    }

    public function save(): void
    {
        Admin::assertCanSave(self::NONCE_ACTION);

        Roles::save($this->submittedRoles());

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
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $types = [];

        foreach (array_keys($labels) as $index) {
            $types[] = [
                'slug' => $slugs[$index] ?? '',
                'label' => $labels[$index] ?? '',
                'emoji' => $emojis[$index] ?? '',
                'capacity' => $capacities[$index] ?? 1,
            ];
        }

        return $types;
    }
}

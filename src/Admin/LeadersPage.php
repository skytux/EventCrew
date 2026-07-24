<?php

declare(strict_types=1);

namespace EventCrew\Admin;

use EventCrew\Repositories\PersonRepository;
use EventCrew\Support\LeaderEligibility;
use EventCrew\Support\LeaderGate;
use EventCrew\Support\Roles;

/**
 * Leadership: who has earned crew-leader eligibility and who has been allowed to
 * lead. Read-only - the grant itself happens on the People screen or from the
 * bot's /allow - so this is the place to spot new candidates at a glance.
 */
final class LeadersPage
{
    public const PAGE_SLUG = 'eventcrew-leaders';

    public function __construct(
        private readonly View $view,
        private readonly LeaderEligibility $eligibility,
        private readonly PersonRepository $people,
        private readonly LeaderGate $leaderGate
    ) {
    }

    public function render(): void
    {
        $roles = array_map(
            static fn (array $role): array => ['slug' => $role['slug'], 'label' => $role['label']],
            Roles::active()
        );

        $eligible = [];

        foreach ($this->eligibility->eligiblePeople() as $person) {
            $eligible[] = [
                'id' => $person->id,
                'name' => $person->name(),
                'can_lead' => $person->canLead(),
                'by_role' => $this->eligibility->byActiveRole($person->id),
            ];
        }

        $allowed = [];

        foreach ($this->people->all(['per_page' => 1000]) as $person) {
            if ($person->canLead()) {
                $allowed[] = ['id' => $person->id, 'name' => $person->name()];
            }
        }

        $this->view->render(
            'leaders',
            [
                'roles' => $roles,
                'eligible' => $eligible,
                'allowed' => $allowed,
                'threshold' => $this->eligibility->threshold(),
                'leader_default' => $this->leaderGate->enabledByDefault(),
                'people_page' => PeoplePage::PAGE_SLUG,
            ]
        );
    }
}

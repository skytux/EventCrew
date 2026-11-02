<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\GuestRepository;
use EventCrew\Repositories\PersonRepository;
use EventCrew\Repositories\TicketBuyerRepository;

/**
 * The other half of Tools -> Erase Personal Data, and the counterpart to
 * PrivacyExporter.
 *
 * A crew member could already erase themselves from their own page, which is
 * the path almost everyone takes. This is for the one who writes in instead:
 * an organizer handed a request by email can now answer it from the screen
 * WordPress already gives them for exactly this, with the confirmation mail and
 * the audit trail core provides, rather than hunting for the person in the
 * People list and hoping Delete means what they think it means.
 *
 * Crew are not WordPress users, so core's own erasers reach none of this -
 * identity here is a verified email on the plugin's own table, which is what
 * the erase request passes in.
 */
final class PrivacyEraser
{
    public function __construct(
        private readonly PersonRepository $people,
        private readonly PersonEraser $eraser,
        private readonly TicketBuyerRepository $buyers = new TicketBuyerRepository(),
        private readonly GuestRepository $guests = new GuestRepository()
    ) {
    }

    public function boot(): void
    {
        add_filter('wp_privacy_personal_data_erasers', [$this, 'register']);
    }

    /**
     * @param array<string, mixed> $erasers
     *
     * @return array<string, mixed>
     */
    public function register(array $erasers): array
    {
        $erasers['eventcrew'] = [
            'eraser_friendly_name' => __('EventCrew', 'eventcrew'),
            'callback' => [$this, 'erase'],
        ];

        return $erasers;
    }

    /**
     * Erases everything held against one email address.
     *
     * One page, like the exporter, and for the same reason: a person's whole
     * record here is a handful of rows, so there is nothing to resume.
     *
     * `items_retained` stays false because nothing is kept back. The plugin has
     * no legal obligation to hold any of it - there is no invoice and no
     * accounting record in here - so there is nothing to weigh against the
     * request. Bought tickets keep their row with no name or email, which is
     * no longer data about the person.
     *
     * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
     */
    public function erase(string $email, int $page = 1): array
    {
        if ($page > 1) {
            return [
                'items_removed' => false,
                'items_retained' => false,
                'messages' => [],
                'done' => true,
            ];
        }

        $messages = [];
        $person = $this->people->findByEmail($email);

        // phpcs:disable Generic.Files.LineLength.TooLong -- single gettext literals; splitting them breaks extraction.
        if (null !== $person) {
            $this->eraser->erase($person->id);
            $messages[] = __(
                'The EventCrew record for this address was erased, including its task history, free-entry credits and sign-in links.',
                'eventcrew'
            );
        }

        // Ticket buyers are looked up by email whether or not a crew record
        // exists. The ticket rows stay with an empty name, so the night's
        // ticket count does not change.
        if ($this->buyers->eraseEmail($email) > 0) {
            $messages[] = __(
                'The name and email address were erased from the tickets bought with this address. The tickets stay on the door lists with no name.',
                'eventcrew'
            );
        }

        // Guests from a mailed guest list: the row stays with no name, so the
        // night's head count does not change.
        if ($this->guests->eraseEmail($email) > 0) {
            $messages[] = __(
                'The name and email address were erased from the guest list places under this address. The places stay on the guest list with no name.',
                'eventcrew'
            );
        }
        // phpcs:enable Generic.Files.LineLength.TooLong

        return [
            'items_removed' => [] !== $messages,
            'items_retained' => false,
            'messages' => $messages,
            'done' => true,
        ];
    }
}

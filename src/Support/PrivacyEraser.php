<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Repositories\PersonRepository;

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
        private readonly PersonEraser $eraser
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
     * no legal obligation to hold any of it - there is no invoice, no ticket
     * sale, no accounting record in here, only who signed up for what - so
     * there is nothing to weigh against the request.
     *
     * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
     */
    public function erase(string $email, int $page = 1): array
    {
        $person = $this->people->findByEmail($email);

        if (null === $person || $page > 1) {
            return [
                'items_removed' => false,
                'items_retained' => false,
                'messages' => [],
                'done' => true,
            ];
        }

        $this->eraser->erase($person->id);

        return [
            'items_removed' => true,
            'items_retained' => false,
            'messages' => [
                __(
                    // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                    'The EventCrew record for this address was erased, including its task history, free-entry credits and sign-in links.',
                    'eventcrew'
                ),
            ],
            'done' => true,
        ];
    }
}

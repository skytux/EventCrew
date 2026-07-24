<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Models\Person;
use EventCrew\Repositories\PersonRepository;

/**
 * Resolves the person a bot reply names: a Telegram text-mention if the message
 * carries one, otherwise a name search. Shared by /replace and /gift so the two
 * pick people the same way and can't drift.
 *
 * A static helper taking the repository, rather than an injected collaborator,
 * so it adds no constructor argument to thread through the container and tests.
 *
 * @param array<int, array<string, mixed>> $entities Telegram message entities.
 * @return array<int, Person>
 */
final class PersonResolver
{
    /**
     * @param array<int, array<string, mixed>> $entities
     * @return array<int, Person>
     */
    public static function matching(PersonRepository $people, string $text, array $entities): array
    {
        foreach ($entities as $entity) {
            if ('text_mention' === ($entity['type'] ?? '') && isset($entity['user']['id'])) {
                $person = $people->findByTelegramUserId((int) $entity['user']['id']);

                if (null !== $person) {
                    return [$person];
                }
            }
        }

        $name = ltrim(trim($text), '@');

        return '' === $name ? [] : $people->all(['search' => $name, 'per_page' => 10]);
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * STUB for the sales desk branch only. The real class comes from the ticket
 * import branch and replaces this file at merge. It has the same two methods.
 */
final class TicketFormats
{
    public const OPTION = 'eventcrew_ticket_formats';

    /**
     * The stored ticket formats, or the Holvi preset when nothing is stored.
     *
     * @return array<int, array{id: string, name: string, columns: array<string, mixed>, decoder_js: string}>
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);

        if (is_array($stored) && [] !== $stored) {
            return array_values($stored);
        }

        return [
            [
                'id' => 'holvi',
                'name' => 'Holvi',
                'columns' => ['name' => 'Nimi', 'email' => 'Sähköposti', 'code' => 'Tilauskoodi'],
                'decoder_js' => '',
            ],
        ];
    }

    /**
     * @return array{id: string, name: string, columns: array<string, mixed>, decoder_js: string}|null
     */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $format) {
            if (($format['id'] ?? '') === $id) {
                return $format;
            }
        }

        return null;
    }
}

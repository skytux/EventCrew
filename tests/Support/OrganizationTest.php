<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\Organization;
use EventCrew\Support\TicketNote;
use EventCrew\Tests\TestCase;

/**
 * The organization's name members see (Settings > Web page), the site name
 * when none is set, and the ticket note's placeholders.
 */
final class OrganizationTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];

        Functions\when('get_option')->alias(
            fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);

            return true;
        });
    }

    public function testWithoutANameTheSiteNameIsUsed(): void
    {
        self::assertSame('Test Site', Organization::name());
        self::assertSame('', Organization::stored());
    }

    public function testTheNameSetInSettingsWins(): void
    {
        Organization::save('  Ecstatic Dance Helsinki ');

        self::assertSame('Ecstatic Dance Helsinki', $this->options[Organization::OPTION]);
        self::assertSame('Ecstatic Dance Helsinki', Organization::name());

        Organization::save('');

        self::assertArrayNotHasKey(Organization::OPTION, $this->options, 'blank goes back to the site name');
        self::assertSame('Test Site', Organization::name());
    }

    public function testALongNameIsCut(): void
    {
        Organization::save(str_repeat('a', Organization::MAX_LENGTH + 20));

        self::assertSame(Organization::MAX_LENGTH, mb_strlen(Organization::name()));
    }

    public function testTheTicketNoteFillsItsPlaceholdersAndDropsLinesWithAnEmptyOne(): void
    {
        $note = "Welcome to {organization}!\nDoor code 1234\nAt {venue}.";

        self::assertSame(
            "Welcome to Ecstatic Dance Helsinki!\nDoor code 1234",
            TicketNote::fill($note, ['{organization}' => 'Ecstatic Dance Helsinki', '{venue}' => ''])
        );
        self::assertSame(
            "Welcome to Test Site!\nDoor code 1234\nAt {venue}.",
            TicketNote::fill($note, TicketNote::values()),
            'a placeholder the note does not know is left as written'
        );
    }
}

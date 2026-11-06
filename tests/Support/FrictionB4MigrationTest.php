<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\FrictionB4Migration;
use EventCrew\Support\MessageTemplates;
use EventCrew\Tests\TestCase;

/**
 * The one-time B4 changes to stored message texts: the role_met notice
 * gains {record} once, and an empty text stays empty.
 */
final class FrictionB4MigrationTest extends TestCase
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
    }

    public function testTheMetNoticeGetsTheRecordOnceOnItsOwnLine(): void
    {
        $prefix = MessageTemplates::OPTION_PREFIX;
        $this->options[$prefix . 'role_met_dm'] = [
            MessageTemplates::BODY => [
                'source' => "{name} meets {role}.\n",
                'texts' => ['fi' => '{name}: {role}.', 'en' => ''],
            ],
        ];
        $this->options[$prefix . 'role_met'] = [MessageTemplates::BODY => "{name} meets {role}.\n{record}"];

        FrictionB4Migration::run();
        FrictionB4Migration::run();

        $dm = $this->options[$prefix . 'role_met_dm'][MessageTemplates::BODY];
        self::assertSame("{name} meets {role}.\n{record}", $dm['source']);
        self::assertSame("{name}: {role}.\n{record}", $dm['texts']['fi']);
        self::assertSame('', $dm['texts']['en'], 'an empty text stays empty');
        self::assertSame(
            "{name} meets {role}.\n{record}",
            $this->options[$prefix . 'role_met'][MessageTemplates::BODY],
            'a text that has it is left alone'
        );
        self::assertSame(['role_met_record' => 1], $this->options[FrictionB4Migration::OPTION]);
    }
}

<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use Brain\Monkey\Functions;
use EventCrew\Support\PageCacheFlush;
use EventCrew\Tests\TestCase;

final class PageCacheFlushTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->saved = [];
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->saved[$name] = $value;

            return true;
        });
    }

    public function testFlushesOnceAndStampsTheVersionAfterAnUpdate(): void
    {
        Functions\when('get_option')->justReturn('1.0.0');
        Functions\expect('w3tc_flush_all')->once();

        PageCacheFlush::maybeFlush();

        self::assertSame(EVENTCREW_VERSION, $this->saved[PageCacheFlush::OPTION] ?? null);
    }

    public function testDoesNothingWhenTheVersionIsAlreadyStamped(): void
    {
        Functions\when('get_option')->justReturn(EVENTCREW_VERSION);
        Functions\expect('w3tc_flush_all')->never();

        PageCacheFlush::maybeFlush();

        self::assertSame([], $this->saved);
    }
}

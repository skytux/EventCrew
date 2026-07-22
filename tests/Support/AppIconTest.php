<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\AppIcon;
use EventCrew\Tests\TestCase;

final class AppIconTest extends TestCase
{
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    protected function setUp(): void
    {
        parent::setUp();

        if (! AppIcon::available()) {
            self::markTestSkipped('GD is not available in this PHP build.');
        }
    }

    public function testDrawsAPngAtTheRequestedSize(): void
    {
        $bytes = AppIcon::png(192, 'Dance Crew', '#4a6cf7');

        self::assertStringStartsWith(self::PNG_SIGNATURE, $bytes);

        $info = getimagesizefromstring($bytes);
        self::assertIsArray($info);
        self::assertSame(192, $info[0]);
        self::assertSame(192, $info[1]);
    }

    public function testHandlesAShortNameAndABareHexColour(): void
    {
        // A single-word name and a 3-digit hex both still produce a valid PNG.
        $bytes = AppIcon::png(512, 'Crew', 'f00');

        self::assertStringStartsWith(self::PNG_SIGNATURE, $bytes);
    }
}

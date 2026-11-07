<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use Brain\Monkey\Functions;
use EventCrew\Telegram\BoardBanner;

/**
 * The banner above the Telegram board: which picture of the chosen page it
 * uses, and which 3:1 strip of it is kept.
 */
final class BoardBannerTest extends TelegramTestCase
{
    /** @var array<int, mixed> */
    private array $blocks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->blocks = [];

        Functions\when('get_post')->alias(function (int $id): ?\WP_Post {
            if (822 !== $id) {
                return null;
            }

            $post = new \WP_Post();
            $post->ID = 822;
            $post->post_status = 'publish';
            $post->post_content = 'x';

            return $post;
        });
        Functions\when('parse_blocks')->alias(fn (): array => $this->blocks);
        Functions\when('get_post_thumbnail_id')->justReturn(0);
    }

    public function testALandscapePictureKeepsItsFullWidthAroundTheFocalPoint(): void
    {
        // 1024 × 683, focal point high up: the strip is 1024 × 342 from the top part.
        $box = BoardBanner::cropBox(1024, 683, 0.71, 0.31);

        self::assertSame(1024, $box['w']);
        self::assertSame(342, $box['h']);
        self::assertSame(0, $box['x']);
        self::assertSame(41, $box['y']);
    }

    public function testTheStripStaysInsideThePicture(): void
    {
        $bottom = BoardBanner::cropBox(1000, 1000, 0.5, 1.0);
        $top = BoardBanner::cropBox(1000, 1000, 0.5, 0.0);

        self::assertSame(1000 - $bottom['h'], $bottom['y']);
        self::assertSame(0, $top['y']);
    }

    public function testAVeryWidePictureKeepsItsFullHeight(): void
    {
        $box = BoardBanner::cropBox(4000, 1000, 0.25, 0.5);

        self::assertSame(1000, $box['h']);
        self::assertSame(2998, $box['w']);
        self::assertSame(0, $box['x']);
    }

    public function testTheFirstCoverBlockIsThePicture(): void
    {
        $this->blocks = [
            ['blockName' => 'core/group', 'attrs' => [], 'innerBlocks' => [
                ['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => []],
                ['blockName' => 'core/cover', 'attrs' => ['id' => 823, 'focalPoint' => ['x' => 0.71, 'y' => 0.31]], 'innerBlocks' => []],
            ]],
            ['blockName' => 'core/cover', 'attrs' => ['id' => 99], 'innerBlocks' => []],
        ];

        self::assertSame(['id' => 823, 'x' => 0.71, 'y' => 0.31], BoardBanner::source(822));
    }

    public function testWithoutACoverTheFeaturedImageIsUsedCentred(): void
    {
        Functions\when('get_post_thumbnail_id')->justReturn(55);

        self::assertSame(['id' => 55, 'x' => 0.5, 'y' => 0.5], BoardBanner::source(822));
    }

    public function testNoPageOrNoPictureMeansNoBanner(): void
    {
        Functions\when('get_option')->justReturn(0);

        self::assertNull(BoardBanner::source(822));
        self::assertNull(BoardBanner::source(5));
        self::assertSame('', BoardBanner::url());
    }
}

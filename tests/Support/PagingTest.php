<?php

declare(strict_types=1);

namespace EventCrew\Tests\Support;

use EventCrew\Support\Paging;
use EventCrew\Telegram\MenuButtons;
use EventCrew\Tests\TestCase;

/**
 * Owner 2026-11-08: Tasks and Past tasks come 25 to a page, in the bot
 * (◀️ ▶️, "mn:hist:2") and on the web (?past_page=2) alike.
 */
final class PagingTest extends TestCase
{
    public function testExactlyTwentyFiveIsOnePage(): void
    {
        $page = Paging::slice(range(1, 25), 1);

        self::assertSame(1, $page['pages']);
        self::assertCount(25, $page['items']);
        self::assertSame([], MenuButtons::pageRows(MenuButtons::HISTORY, $page['page'], $page['pages']));
    }

    public function testTwentySixIsTwoPagesWithOneOnTheSecond(): void
    {
        $first = Paging::slice(range(1, 26), 1);
        $second = Paging::slice(range(1, 26), 2);

        self::assertSame(2, $first['pages']);
        self::assertSame(range(1, 25), $first['items']);
        self::assertSame([26], $second['items']);
        self::assertSame(2, $second['page']);
    }

    public function testAPageBeyondTheLastShowsTheLast(): void
    {
        $page = Paging::slice(range(1, 26), 9);

        self::assertSame(2, $page['page']);
        self::assertSame([26], $page['items']);
        self::assertSame(1, Paging::slice(range(1, 26), 0)['page']);
    }

    public function testNothingIsOneEmptyPage(): void
    {
        self::assertSame(['items' => [], 'page' => 1, 'pages' => 1], Paging::slice([], 3));
    }

    public function testParseTakesDigitsOnly(): void
    {
        self::assertSame(2, Paging::parse('2'));
        self::assertSame(3, Paging::parse(3));
        self::assertSame(1, Paging::parse('0'));
        self::assertSame(1, Paging::parse('-2'));
        self::assertSame(1, Paging::parse('2x'));
        self::assertSame(1, Paging::parse(['2']));
        self::assertSame(1, Paging::parse('123456'));
    }

    public function testTheButtonsCarryThePageAndPageOneIsThePlainScreen(): void
    {
        self::assertSame('mn:hist', MenuButtons::pageData(MenuButtons::HISTORY, 1));
        self::assertSame('mn:hist:2', MenuButtons::pageData(MenuButtons::HISTORY, 2));

        self::assertSame(
            [[['text' => '▶️', 'callback_data' => 'mn:mytasks:2']]],
            MenuButtons::pageRows(MenuButtons::MY_TASKS, 1, 3)
        );
        self::assertSame(
            ['mn:mytasks', 'mn:mytasks:3'],
            array_column(MenuButtons::pageRows(MenuButtons::MY_TASKS, 2, 3)[0], 'callback_data')
        );
        self::assertSame(
            [[['text' => '◀️', 'callback_data' => 'mn:mytasks:2']]],
            MenuButtons::pageRows(MenuButtons::MY_TASKS, 3, 3)
        );
        // Telegram's limit for callback data, at the largest page we parse.
        self::assertLessThanOrEqual(64, strlen(MenuButtons::pageData(MenuButtons::MY_TASKS, 99999)));
    }

    public function testParsePageReadsOnlyAPagedScreen(): void
    {
        self::assertSame(['mn:hist', 2], MenuButtons::parsePage('mn:hist:2'));
        self::assertSame(['mn:hist', 1], MenuButtons::parsePage('mn:hist'));
        self::assertSame(['mn:mytasks', 12], MenuButtons::parsePage('mn:mytasks:12'));
        self::assertSame(['mn:hist', 1], MenuButtons::parsePage('mn:hist:0'));
        self::assertNull(MenuButtons::parsePage('mn:histx'));
        self::assertNull(MenuButtons::parsePage('mn:hist:'));
        self::assertNull(MenuButtons::parsePage('mn:hist:2x'));
        self::assertNull(MenuButtons::parsePage('mn:set:2'));
        self::assertNull(MenuButtons::parsePage('mn:me'));
    }
}

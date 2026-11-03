<?php

declare(strict_types=1);

namespace EventCrew\Tests\Telegram;

use EventCrew\Telegram\EntityHtml;
use EventCrew\Tests\TestCase;

/**
 * Telegram formatting to Telegram HTML. Offsets are in UTF-16 code units, so
 * an emoji before the formatting counts as two.
 */
final class EntityHtmlTest extends TestCase
{
    public function testPlainTextIsEscaped(): void
    {
        self::assertSame("a &lt;b&gt; &amp; \"c\"\nd", EntityHtml::render("a <b> & \"c\"\nd", []));
    }

    public function testBoldItalicAndALink(): void
    {
        $html = EntityHtml::render('Hi all, see the site', [
            ['type' => 'bold', 'offset' => 0, 'length' => 6],
            ['type' => 'italic', 'offset' => 8, 'length' => 3],
            ['type' => 'text_link', 'offset' => 16, 'length' => 4, 'url' => 'https://example.test/?a=1&b=2'],
        ]);

        self::assertSame(
            '<b>Hi all</b>, <i>see</i> the <a href="https://example.test/?a=1&amp;b=2">site</a>',
            $html
        );
    }

    public function testOffsetsCountAnEmojiAsTwoUnits(): void
    {
        // 👋 is two UTF-16 units, then a space: "Hello" starts at 3.
        $html = EntityHtml::render('👋 Hello there', [['type' => 'bold', 'offset' => 3, 'length' => 5]]);

        self::assertSame('👋 <b>Hello</b> there', $html);
    }

    public function testNestedFormattingNests(): void
    {
        $html = EntityHtml::render('one two', [
            ['type' => 'italic', 'offset' => 4, 'length' => 3],
            ['type' => 'bold', 'offset' => 0, 'length' => 7],
        ]);

        self::assertSame('<b>one <i>two</i></b>', $html);
    }

    public function testOverlappingFormattingStillNests(): void
    {
        $html = EntityHtml::render('abcdef', [
            ['type' => 'bold', 'offset' => 0, 'length' => 4],
            ['type' => 'italic', 'offset' => 2, 'length' => 4],
        ]);

        self::assertSame('<b>ab<i>cd</i></b><i>ef</i>', $html);
    }

    public function testPlainLinksAndUnknownTypesStayPlain(): void
    {
        $html = EntityHtml::render('go https://x.test #tag', [
            ['type' => 'url', 'offset' => 3, 'length' => 14],
            ['type' => 'hashtag', 'offset' => 18, 'length' => 4],
        ]);

        self::assertSame('go https://x.test #tag', $html);
    }

    public function testAQuoteAndCode(): void
    {
        $html = EntityHtml::render("said\nx = 1", [
            ['type' => 'blockquote', 'offset' => 0, 'length' => 4],
            ['type' => 'pre', 'offset' => 5, 'length' => 5, 'language' => 'php'],
        ]);

        self::assertSame("<blockquote>said</blockquote>\n<pre><code class=\"language-php\">x = 1</code></pre>", $html);
    }

    public function testUnitsCountsUtf16(): void
    {
        self::assertSame(2, EntityHtml::units('👋'));
        self::assertSame(1, EntityHtml::units('ä'));
    }
}

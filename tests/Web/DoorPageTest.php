<?php

declare(strict_types=1);

namespace EventCrew\Tests\Web;

use Brain\Monkey\Functions;
use EventCrew\Tests\TestCase;

/**
 * The sales desk page itself (templates/public/door.php): the icon buttons at
 * the top, the tick that checks a person in, and the spinner that replaced
 * the "Loading…" texts.
 */
final class DoorPageTest extends TestCase
{
    private function page(): string
    {
        Functions\when('esc_html_e')->alias(static function (string $text): void {
            echo htmlspecialchars($text, ENT_QUOTES);
        });
        Functions\when('esc_attr_e')->alias(static function (string $text): void {
            echo htmlspecialchars($text, ENT_QUOTES);
        });
        Functions\when('wp_json_encode')->alias(
            static fn ($value, int $flags = 0): string => (string) json_encode($value, $flags)
        );

        $eventcrew_state = ['day' => 'Sat 14 Nov', 'in' => 0, 'expected' => 0, 'anonymous' => 0, 'sections' => []];
        $eventcrew_config = ['ajaxUrl' => 'https://site.test/wp-admin/admin-ajax.php', 'csrf' => 'x', 'actions' => []];

        ob_start();
        include EVENTCREW_PLUGIN_DIR . 'templates/public/door.php';

        return (string) ob_get_clean();
    }

    public function testTheTopButtonsAreIconsWithOneWordUnderThem(): void
    {
        $html = $this->page();

        foreach (['ec-scan-open' => 'Scan', 'ec-other-open' => 'Switch', 'ec-add-open' => 'Add'] as $id => $word) {
            self::assertMatchesRegularExpression(
                '~<button type="button" class="ec-tile[^"]*" id="' . $id . '"><svg [^>]*aria-hidden="true">.*?</svg>'
                . '<span>' . $word . '</span></button>~',
                $html,
                $id
            );
        }

        self::assertStringNotContainsString('📷', $html);
        self::assertStringNotContainsString('📱', $html);
        self::assertStringContainsString('.ec-tile span{font-size:11px;line-height:1.1;white-space:nowrap}', $html);
    }

    public function testTheSwitchPopupOffersLoginAsFirst(): void
    {
        $html = $this->page();
        $as = strpos($html, 'id="ec-pick-as"');
        $own = strpos($html, 'id="ec-pick-own"');

        self::assertNotFalse($as);
        self::assertNotFalse($own);
        self::assertLessThan($own, $as);
    }

    public function testCheckInIsATickThatTurnsGreenOnceIn(): void
    {
        $html = $this->page();

        self::assertStringContainsString('toggle.innerHTML = tickIcon;', $html);
        self::assertStringContainsString("toggle.setAttribute('aria-label', text.out);", $html);
        self::assertStringContainsString("toggle.setAttribute('aria-pressed', row.in ? 'true' : 'false');", $html);
        self::assertStringContainsString('.ec-toggle{width:48px;padding:0;color:var(--muted)}', $html);
        self::assertStringContainsString('.ec-toggle.is-in{background:var(--in);border-color:var(--in);color:var(--bg)}', $html);
        self::assertStringNotContainsString('✓ In', $html);
    }

    public function testLoadingIsASpinnerWhereTheTapWas(): void
    {
        $html = $this->page();

        self::assertStringNotContainsString('⏳', $html);
        self::assertStringContainsString('var done = busy(tapped);', $html);
        self::assertStringContainsString("source.classList.add('is-busy');", $html);
        self::assertStringContainsString("var li = node('li', 'ec-loading');", $html);
        self::assertStringNotContainsString("node('li', 'ec-muted', text.loading)", $html);
        // The words stay for screen readers only.
        self::assertStringContainsString(
            '<span class="ec-spin" aria-hidden="true"></span><span class="ec-sr">Loading…</span>',
            $html
        );
    }
}

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
    /**
     * @param array<string, mixed> $extraConfig Added to the page's config.
     */
    private function page(array $extraConfig = []): string
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
        $eventcrew_config = ['ajaxUrl' => 'https://site.test/wp-admin/admin-ajax.php', 'csrf' => 'x', 'actions' => []]
            + $extraConfig;

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

    public function testAScanErrorIsRedAndTheNameIsBigOnOneLine(): void
    {
        $html = $this->page();

        self::assertStringContainsString(
            '.ec-msg.is-error{background:var(--err-bg);border:2px solid var(--err);color:var(--err);font-weight:700}',
            $html
        );
        self::assertStringContainsString("line.classList.toggle('is-error', !!message && !!isError);", $html);
        self::assertStringContainsString('scanMessage(data ? data.message : text.failed, true);', $html);
        self::assertSame(2, substr_count($html, 'scanMessage(text.noCamera, true);'));
        self::assertStringContainsString('scanMessage(text.noDecoder, true);', $html);
        // A successful mark is not an error.
        self::assertStringContainsString("scanMessage(text.arrived.replace('%s', row.first || row.name), false, true);", $html);
        self::assertStringContainsString('drawTickets(row.first || row.name);', $html);
        self::assertStringContainsString('#ec-scan-name{font-size:32px;line-height:1.2;font-weight:700;margin:12px 0 0;white-space:nowrap;', $html);
        self::assertStringContainsString("el('ec-scan-name').textContent = scanRow.name;\n        fitScanName();", $html);
    }

    public function testAScanArrivalSaysWelcomeBigOnGreen(): void
    {
        $html = $this->page();

        self::assertStringContainsString('"arrived":"Welcome %s"', $html);
        self::assertStringNotContainsString('is marked arrived.', $html);
        self::assertStringContainsString(
            '.ec-msg.is-welcome{background:var(--in-bg);border:2px solid var(--in);color:var(--in);font-size:26px;',
            $html
        );
        self::assertStringContainsString(
            "line.classList.toggle('is-welcome', !!message && !isError && !!isWelcome);",
            $html
        );
        self::assertStringContainsString("scanMessage(text.arrived.replace('%s', row.first || row.name), false, true);", $html);
        self::assertStringContainsString('drawTickets(row.first || row.name);', $html);
    }

    public function testATicketAlreadyUsedIsSaidInOrangeWithTheTime(): void
    {
        $html = $this->page();

        // wp_json_encode writes the middle dot as ·.
        self::assertStringContainsString('"used":"%1$s: already used ' . trim((string) json_encode('·'), '"') . ' %2$s"', $html);
        self::assertStringNotContainsString('this ticket was already used tonight', $html);
        self::assertStringContainsString(
            '.ec-msg.is-used{background:#ff8c1a;border:2px solid #ff8c1a;color:#1d1d1f;font-weight:700}',
            $html
        );
        self::assertStringContainsString(
            "line.classList.toggle('is-used', !!message && !isError && !!isUsed);",
            $html
        );
        self::assertStringContainsString(
            "return text.used.replace('%1\$s', row.name).replace('%2\$s', row.at || '');",
            $html
        );
        // Not red: the scanned card and the code's ticket list both say it in orange.
        self::assertStringContainsString("scanMessage(scanRow.in ? usedText(scanRow) : '', false, false, true);", $html);
        self::assertStringContainsString(
            'scanMessage(1 === rows.length ? usedText(rows[0]) : text.allUsed, false, false, true);',
            $html
        );
        self::assertStringNotContainsString('ec-scan-already', $html);
    }

    public function testTheHeaderShowsTheMaxAndColoursTheNumbers(): void
    {
        $html = $this->page();

        // "x of n" without a max, "x of n (z max)" with one.
        self::assertStringContainsString(
            '<small id="ec-of-max" hidden>of <span class="ec-n" id="ec-expected-max">0</span> (<span id="ec-max">0</span> max)</small>',
            $html
        );
        self::assertStringContainsString("el('ec-of').hidden = max > 0;", $html);
        self::assertStringContainsString("el('ec-of-max').hidden = max <= 0;", $html);
        // x green when everyone expected is in; n orange from 90% of the max, red at it.
        self::assertStringContainsString(
            "el('ec-in').classList.toggle('is-full', state.expected > 0 && state.in === state.expected);",
            $html
        );
        self::assertStringContainsString('var near = max > 0 && !atMax && state.expected >= max * 0.9;', $html);
        self::assertStringContainsString('#ec-in.is-full{color:var(--in)}', $html);
        self::assertStringContainsString('.ec-n.is-near{color:var(--warn);font-weight:700}', $html);
        self::assertStringContainsString('.ec-n.is-max{color:var(--err);font-weight:700}', $html);
    }

    public function testAtTheMaxAddingStopsUnlessGoingOverIsAllowed(): void
    {
        $html = $this->page();

        self::assertStringContainsString('var stop = atMax && !state.over_allowed;', $html);
        self::assertStringContainsString("el('ec-add-open').disabled = stop;", $html);
        self::assertStringContainsString("el('ec-anon-more').disabled = stop;", $html);
    }

    public function testTheOverTheMaxWarningShowsAfterEveryAction(): void
    {
        $html = $this->page();

        self::assertSame(3, substr_count($html, 'class="ec-msg ec-quota"'));
        self::assertStringContainsString(
            "quotaLine(data.warning || (data.refused ? data.message : ''), !data.warning);",
            $html
        );
        self::assertStringContainsString(
            '.ec-msg.is-warn{background:var(--warn-bg);border:2px solid var(--warn);color:var(--warn);font-weight:700}',
            $html
        );
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

    public function testTheTicketDecodersArriveAsDataInTheConfig(): void
    {
        // A decoder that tries to close the script element stays data.
        $decoders = ['return text.split("/").pop(); // </script><b>', ''];
        $html = $this->page(['ticketDecoders' => $decoders]);

        self::assertStringContainsString(
            '"ticketDecoders":' . json_encode($decoders, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            $html
        );
        self::assertStringNotContainsString('</script><b>', $html);
        // Each one becomes a function on the page only when a scan needs it.
        self::assertStringContainsString("ticketDecoders = (config.ticketDecoders || []).map(function (body) {", $html);
        self::assertStringContainsString("return new Function('text', body);", $html);
        self::assertStringContainsString("post('ticketCode', { codes: JSON.stringify(codes) })", $html);
    }

    public function testTheDoorControllerPassesTheTicketDecodersToThePage(): void
    {
        $source = (string) file_get_contents(EVENTCREW_PLUGIN_DIR . 'src/Web/DoorController.php');

        self::assertStringContainsString("'ticketDecoders' => DoorDesk::ticketDecoders(),", $source);
        self::assertStringContainsString("'ticketCode' => self::TICKET_CODE_ACTION,", $source);
    }
}

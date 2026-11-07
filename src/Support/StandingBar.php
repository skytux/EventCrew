<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * A person's standing drawn as a bar from 0 to 100%: a gold fill, a mark at
 * the good-standing limit (Settings, 60% by default) and a warning zone just
 * above it (Owner 2026-11-07).
 *
 * One picture in three forms: a short text bar for Telegram and the plain-text
 * email part, an HTML table with inline styles for the HTML email (EmailBody
 * draws a paragraph that is exactly a text bar as this table), and the zone
 * and numbers the crew page draws as a CSS bar.
 */
final class StandingBar
{
    /**
     * How many points above the good-standing limit the warning zone reaches:
     * 60-70% with the default limit. Still good standing, but one more miss
     * can take the person under the limit.
     */
    public const WARNING_POINTS = 10;

    public const ZONE_DANGER = 'danger';
    public const ZONE_WARNING = 'warning';
    public const ZONE_GOOD = 'good';

    /** Cells in the text bar: one per 10%. */
    private const CELLS = 10;

    /** The good-standing limit in the text bar. */
    private const MARK = '│';

    private const FILL = [
        self::ZONE_GOOD => '🟨',
        self::ZONE_WARNING => '🟧',
        self::ZONE_DANGER => '🟥',
    ];

    private const EMPTY = '⬜';

    private const GOLD = '#d4a017';
    private const TRACK = '#ececf0';
    private const RED = '#d9534f';
    private const AMBER = '#f0ad4e';
    private const CLEAR = '#c9d8c5';
    private const TICK = '#333333';

    /** The good-standing limit as a whole percentage (Settings). */
    public static function limit(): int
    {
        return (int) round((new ReputationSettings())->threshold() * 100);
    }

    /** The top of the warning zone, capped at 100. */
    public static function warningTop(?int $limit = null): int
    {
        return min(100, ($limit ?? self::limit()) + self::WARNING_POINTS);
    }

    public static function zone(int $percent, ?int $limit = null): string
    {
        $limit ??= self::limit();

        if ($percent < $limit) {
            return self::ZONE_DANGER;
        }

        return $percent < self::warningTop($limit) ? self::ZONE_WARNING : self::ZONE_GOOD;
    }

    /**
     * "🟨🟨🟨🟨🟨🟨│🟨⬜⬜⬜ 74%": a cell per full 10%, coloured by zone, the
     * limit marked. 15 visible characters.
     */
    public static function text(int $percent, ?int $limit = null): string
    {
        $percent = self::clamp($percent);
        $limit = self::clamp($limit ?? self::limit());
        $filled = intdiv($percent, 10);
        $markAt = (int) round($limit / 10);
        $fill = self::FILL[self::zone($percent, $limit)];
        $bar = '';

        for ($i = 0; $i < self::CELLS; ++$i) {
            $bar .= ($i === $markAt ? self::MARK : '') . ($i < $filled ? $fill : self::EMPTY);
        }

        return $bar . (self::CELLS === $markAt ? self::MARK : '') . ' ' . $percent . '%';
    }

    /**
     * The percentage a text bar shows, or null when $line is not one: how
     * EmailBody spots the bar to draw it as a table.
     */
    public static function parse(string $line): ?int
    {
        $cells = implode('|', array_map('preg_quote', array_merge(array_values(self::FILL), [self::EMPTY, self::MARK])));

        return 1 === preg_match('/^(?:' . $cells . ')+ (\d{1,3})%$/u', trim($line), $match)
            ? self::clamp((int) $match[1])
            : null;
    }

    /**
     * The bar for the HTML email: a table with inline styles only, as mail
     * clients drop stylesheets. A gold fill on a grey track, a strip under it
     * with the zones (red below the limit, amber for the warning zone), a tick
     * at the limit through both, and the percentage under it.
     */
    public static function html(int $percent, ?int $limit = null): string
    {
        $percent = self::clamp($percent);
        $limit = self::clamp($limit ?? self::limit());
        $top = self::warningTop($limit);
        $edges = array_values(array_unique([0, $percent, $limit, $top, 100]));
        sort($edges);

        $fillRow = '';
        $zoneRow = '';

        for ($i = 0; $i < count($edges) - 1; ++$i) {
            [$from, $to] = [$edges[$i], $edges[$i + 1]];

            if ($to <= $from) {
                continue;
            }

            $tick = ($from === $limit && $limit > 0) ? 'border-left:2px solid ' . self::TICK . ';' : '';
            $zone = $from < $limit ? self::RED : ($from < $top ? self::AMBER : self::CLEAR);
            $cell = '<td width="%1$d%%" style="width:%1$d%%;%2$sbackground:%3$s;height:%4$dpx;'
                . 'line-height:%4$dpx;font-size:1px;padding:0">&nbsp;</td>';
            $fillRow .= sprintf($cell, $to - $from, $tick, $to <= $percent ? self::GOLD : self::TRACK, 14);
            $zoneRow .= sprintf($cell, $to - $from, $tick, $zone, 5);
        }

        $label = sprintf(
            /* translators: %d: the good-standing limit percentage */
            __('Good standing from %d%%', 'eventcrew'),
            $limit
        );

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
            . ' style="width:100%;max-width:420px;border-collapse:collapse;margin:0 0 16px">'
            . '<tr><td style="padding:0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
            . ' border="0" style="width:100%;border-collapse:collapse;border-radius:4px;overflow:hidden">'
            . '<tr>' . $fillRow . '</tr><tr>' . $zoneRow . '</tr></table></td></tr>'
            . '<tr><td style="padding:4px 0 0;font-size:13px;line-height:18px;color:#555">'
            . '<strong style="color:#9a7410;font-size:15px">' . esc_html((string) $percent) . '%</strong>'
            . ' &nbsp;·&nbsp; ' . esc_html($label)
            . '</td></tr></table>';
    }

    /** The text bar for $standing, or '' while it is not rated yet. */
    public static function forStanding(?Standing $standing): string
    {
        return null !== $standing && $standing->isRated() ? self::text($standing->scorePercent()) : '';
    }

    /**
     * "Standing 82% → 74%. Still good standing, but close to the 60% limit.":
     * the score before and after a mark, and what the new one means. While
     * the person is not rated yet, how many tasks until they are instead.
     * '' without a standing.
     */
    public static function change(?Standing $before, ?Standing $after): string
    {
        if (null === $after) {
            return '';
        }

        if (! $after->isRated()) {
            return self::untilRated($after);
        }

        $now = $after->scorePercent();
        $head = null !== $before && $before->isRated() && $before->scorePercent() !== $now
            ? sprintf(
                /* translators: 1: standing score before, 2: standing score now, both percentages */
                __('Standing %1$d%% → %2$d%%.', 'eventcrew'),
                $before->scorePercent(),
                $now
            )
            : sprintf(
                /* translators: %d: standing score percentage */
                __('Standing %d%%.', 'eventcrew'),
                $now
            );

        return $head . ' ' . self::meaning($now);
    }

    /** "You get a standing score after 2 more tasks." */
    public static function untilRated(Standing $standing): string
    {
        $left = max(1, (new ReputationSettings())->minRatedTasks() - $standing->scoredCount);

        return sprintf(
            /* translators: %d: tasks still to finish before the person gets a standing score */
            _n(
                'You get a standing score after %d more task.',
                'You get a standing score after %d more tasks.',
                $left,
                'eventcrew'
            ),
            $left
        );
    }

    /** One sentence on what a score of $percent means, by its zone. */
    public static function meaning(int $percent): string
    {
        $limit = self::limit();

        return match (self::zone($percent, $limit)) {
            self::ZONE_GOOD => sprintf(
                /* translators: %d: the good-standing limit percentage */
                __('Good standing is %d%% or more, so every task is open to you.', 'eventcrew'),
                $limit
            ),
            self::ZONE_WARNING => sprintf(
                /* translators: %d: the good-standing limit percentage */
                __('Still good standing, but close to the %d%% limit.', 'eventcrew'),
                $limit
            ),
            default => (bool) get_option(SignupService::GATE_OPTION, true)
                ? sprintf(
                    /* translators: %d: good-standing percentage */
                    __('Below %d%%, you cannot sign up for new tasks until you talk to an organizer.', 'eventcrew'),
                    $limit
                )
                : sprintf(
                    /* translators: %d: the good-standing limit percentage */
                    __('Good standing is %d%% or more.', 'eventcrew'),
                    $limit
                ),
        };
    }

    private static function clamp(int $percent): int
    {
        return max(0, min(100, $percent));
    }
}

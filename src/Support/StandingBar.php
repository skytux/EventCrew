<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * A person's standing drawn as one bar from 0 to 100%, coloured by where the
 * score is: green from ten points above the good-standing limit (Settings),
 * orange from the limit up to there, red under it. A short text follows it:
 * "85% · Good standing" (Owner, Q382 2026-11-08).
 *
 * One picture in three forms: a text bar for Telegram and the plain-text
 * email part, an HTML table with inline styles for the HTML email (EmailBody
 * draws a paragraph that is exactly a text bar as this table), and the zone
 * and numbers the crew page draws as a CSS bar. The web and email bars also
 * have a small mark at the limit.
 */
final class StandingBar
{
    /**
     * How many points above the good-standing limit the warning zone reaches:
     * 65-74% with a 65% limit. Still good standing, but one more miss can
     * take the person under the limit.
     */
    public const WARNING_POINTS = 10;

    public const ZONE_DANGER = 'danger';
    public const ZONE_WARNING = 'warning';
    public const ZONE_GOOD = 'good';

    /** Cells in the text bar: one per 10%. */
    private const CELLS = 10;

    private const FILL = [
        self::ZONE_GOOD => '🟩',
        self::ZONE_WARNING => '🟧',
        self::ZONE_DANGER => '🟥',
    ];

    private const EMPTY = '⬜';

    /** The fill colour per zone; the same as the crew page's. */
    private const COLOUR = [
        self::ZONE_GOOD => '#2e9d4a',
        self::ZONE_WARNING => '#ee8a1a',
        self::ZONE_DANGER => '#d9534f',
    ];

    /** The percentage's colour per zone in the email: darker, to read on white. */
    private const TEXT_COLOUR = [
        self::ZONE_GOOD => '#1e7b34',
        self::ZONE_WARNING => '#a85a00',
        self::ZONE_DANGER => '#b52b27',
    ];

    private const TRACK = '#e4e4e8';
    private const TICK = '#333333';

    /** The good-standing limit as a whole percentage (Settings). */
    public static function limit(): int
    {
        return (int) round((new ReputationSettings())->threshold() * 100);
    }

    /** The top of the warning zone (where green starts), capped at 100. */
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

    /** "Good standing", "Close to the 65% limit" or "Under the 65% limit". */
    public static function state(int $percent, ?int $limit = null): string
    {
        $limit ??= self::limit();

        return match (self::zone($percent, $limit)) {
            self::ZONE_GOOD => __('Good standing', 'eventcrew'),
            self::ZONE_WARNING => sprintf(
                /* translators: %d: the good-standing limit percentage */
                __('Close to the %d%% limit', 'eventcrew'),
                $limit
            ),
            default => sprintf(
                /* translators: %d: the good-standing limit percentage */
                __('Under the %d%% limit', 'eventcrew'),
                $limit
            ),
        };
    }

    /** "85% · Good standing": the text beside the bar in every form. */
    public static function label(int $percent, ?int $limit = null): string
    {
        $percent = self::clamp($percent);

        return $percent . '% · ' . self::state($percent, self::clamp($limit ?? self::limit()));
    }

    /**
     * "🟩🟩🟩🟩🟩🟩🟩🟩⬜⬜ 85% · Good standing": a cell per full 10%, all in
     * the zone's colour, then the label.
     */
    public static function text(int $percent, ?int $limit = null): string
    {
        $percent = self::clamp($percent);
        $limit = self::clamp($limit ?? self::limit());
        $filled = intdiv($percent, 10);

        return str_repeat(self::FILL[self::zone($percent, $limit)], $filled)
            . str_repeat(self::EMPTY, self::CELLS - $filled)
            . ' ' . self::label($percent, $limit);
    }

    /**
     * The percentage a text bar shows, or null when $line is not one: how
     * EmailBody spots the bar to draw it as a table.
     */
    public static function parse(string $line): ?int
    {
        $cells = implode('|', array_map('preg_quote', array_merge(array_values(self::FILL), [self::EMPTY])));
        $pattern = '/^(?:' . $cells . '){' . self::CELLS . '} (\d{1,3})%(?: · [^\n]+)?$/u';

        return 1 === preg_match($pattern, trim($line), $match)
            ? self::clamp((int) $match[1])
            : null;
    }

    /**
     * The bar for the HTML email: a table with inline styles only, as mail
     * clients drop stylesheets. The fill in the zone's colour on a grey
     * track, a small tick at the limit, and the label under it.
     */
    public static function html(int $percent, ?int $limit = null): string
    {
        $percent = self::clamp($percent);
        $limit = self::clamp($limit ?? self::limit());
        $zone = self::zone($percent, $limit);
        $edges = array_values(array_unique([0, $percent, $limit, 100]));
        sort($edges);

        $row = '';

        for ($i = 0; $i < count($edges) - 1; ++$i) {
            [$from, $to] = [$edges[$i], $edges[$i + 1]];
            $tick = ($from === $limit && $limit > 0) ? 'border-left:2px solid ' . self::TICK . ';' : '';
            $row .= sprintf(
                '<td width="%1$d%%" style="width:%1$d%%;%2$sbackground:%3$s;height:12px;'
                    . 'line-height:12px;font-size:1px;padding:0">&nbsp;</td>',
                $to - $from,
                $tick,
                $to <= $percent ? self::COLOUR[$zone] : self::TRACK
            );
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
            . ' style="width:100%;max-width:420px;border-collapse:collapse;margin:0 0 16px">'
            . '<tr><td style="padding:0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
            . ' border="0" style="width:100%;border-collapse:collapse;border-radius:4px;overflow:hidden">'
            . '<tr>' . $row . '</tr></table></td></tr>'
            . '<tr><td style="padding:4px 0 0;font-size:13px;line-height:18px;color:#555">'
            . '<strong style="color:' . self::TEXT_COLOUR[$zone] . ';font-size:15px">'
            . esc_html((string) $percent) . '%</strong>'
            . ' &nbsp;·&nbsp; ' . esc_html(self::state($percent, $limit))
            . '</td></tr></table>';
    }

    /** The text bar for $standing, or '' while it is not rated yet. */
    public static function forStanding(?Standing $standing): string
    {
        return null !== $standing && $standing->isRated() ? self::text($standing->scorePercent()) : '';
    }

    /**
     * "Standing 82% → 70%. Still good standing, but close to the 65% limit.":
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

<?php

declare(strict_types=1);

namespace EventCrew\Support;

use EventCrew\Models\Person;

/**
 * The hero at the top of an HTML email: a night-sky band with a row of
 * sparkles, a large glowing title and a line of facts under it. Only the
 * sign-up email has one (for()): "Thank you Anna!" over "Door · Fri 14 Nov
 * 18:00–20:00" (Owner, 2026-11-07). Mailer::toPerson() draws it above the
 * text; the plain-text part leaves it out, as the text itself says thank you.
 *
 * Email-safe: a table cell with inline styles, the night colour as bgcolor
 * and background-color under the gradient, so an app that draws no gradient
 * still shows the dark band with the light writing readable on it. The
 * gradient is also what keeps the mail apps that recolour in dark mode from
 * turning the band light. The sparkles are plain characters: no image, no
 * web font. The title gets the cream shimmer (ec-shimmer-light) where the
 * mail app can draw it, and the inline cream elsewhere.
 */
final class EmailHero
{
    /** The kinds that open with a hero. */
    public const KINDS = ['signup_confirmed'];

    /** The night purple of the band, as in the email's backdrop. */
    private const NIGHT = '#2b1d57';

    /** The deeper purple the band fades to. */
    private const DEEP = '#160f2e';

    /** The cream of the title. */
    private const TITLE_COLOR = '#fff4e0';

    /** The gold of the sparkles. */
    private const SPARKLE_COLOR = '#ffc98f';

    /** The soft lilac of the facts line. */
    private const LINE_COLOR = '#e4d8ff';

    public function __construct(
        public readonly string $title,
        public readonly string $line = ''
    ) {
    }

    /**
     * The hero of message kind $id, or null when that kind has none. For the
     * sign-up email: "Thank you <first name>!" (or "Thank you!" without a
     * name), and the task and its time, in the current language.
     *
     * @param array<string, mixed> $context The message's context, as for
     *        MessageTemplates::body().
     */
    public static function for(string $id, array $context): ?self
    {
        if (! in_array($id, self::KINDS, true)) {
            return null;
        }

        $person = $context['person'] ?? null;
        $first = $person instanceof Person ? DoorDesk::firstName($person->name()) : '';
        $title = '' === $first
            ? __('Thank you!', 'eventcrew')
            /* translators: %s: the person's first name */
            : sprintf(__('Thank you %s!', 'eventcrew'), $first);

        $template = MessageTemplates::find($id);
        $role = null === $template ? '' : trim($template->fill('{role}', $context));
        $when = null === $template ? '' : trim($template->fill('{when}', $context));

        return new self($title, implode(' · ', array_filter([$role, $when], static fn (string $s): bool => '' !== $s)));
    }

    /**
     * The hero as HTML for the top of the email's content slot. $accent (the
     * site's accent colour) tints the far end of the band and the glow.
     */
    public function html(string $accent): string
    {
        $accent = self::hex($accent) ?? '#4a6cf7';
        $glow = self::rgba($accent, .55);
        $tint = self::mix(self::DEEP, $accent, .35);

        $line = '' === $this->line
            ? ''
            : sprintf(
                '<p style="margin:12px 0 0;font-family:%s;font-size:15px;line-height:1.5;letter-spacing:.02em;'
                    . 'color:%s;text-align:center">%s</p>',
                EmailBody::FONT,
                self::LINE_COLOR,
                esc_html($this->line)
            );

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
            . ' style="width:100%;margin:0 0 28px;border-collapse:separate">'
            . sprintf(
                '<tr><td align="center" bgcolor="%1$s" style="padding:26px 22px 28px;text-align:center;'
                    . 'background-color:%1$s;background-image:linear-gradient(135deg,%1$s 0%%,%2$s 55%%,%3$s 100%%);'
                    . 'border-radius:22px;box-shadow:0 0 0 1px rgba(255,201,143,.45),0 0 28px %4$s">',
                self::NIGHT,
                self::DEEP,
                $tint,
                $glow
            )
            . sprintf(
                '<p style="margin:0;font-family:%s;font-size:15px;line-height:1;letter-spacing:.5em;color:%s;'
                    . 'text-align:center;padding-left:.5em">&#10023; &#10022; &#10024; &#10022; &#10023;</p>',
                EmailBody::FONT,
                self::SPARKLE_COLOR
            )
            . sprintf(
                '<h1 class="ec-shimmer-light" style="margin:14px 0 0;font-family:%s;font-size:32px;font-weight:700;'
                    . 'line-height:1.2;color:%s;text-align:center;text-shadow:0 0 18px %s">%s</h1>',
                EmailBody::HEAD_FONT,
                self::TITLE_COLOR,
                $glow,
                esc_html($this->title)
            )
            . $line
            . sprintf(
                '<p style="margin:14px 0 0;font-family:%s;font-size:12px;line-height:1;letter-spacing:.6em;'
                    . 'color:%s;text-align:center;padding-left:.6em">&#10022; &#10023; &#10022;</p>',
                EmailBody::FONT,
                self::SPARKLE_COLOR
            )
            . '</td></tr></table>';
    }

    /** $color as a lower-case #rrggbb, or null when it is not a hex colour. */
    private static function hex(string $color): ?string
    {
        $color = strtolower(trim($color));

        if (1 === preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $color, $m)) {
            return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }

        return 1 === preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : null;
    }

    /** @return array{int, int, int} */
    private static function channels(string $hex): array
    {
        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }

    private static function rgba(string $hex, float $alpha): string
    {
        [$r, $g, $b] = self::channels($hex);

        return sprintf('rgba(%d,%d,%d,%s)', $r, $g, $b, rtrim(rtrim(sprintf('%.2f', $alpha), '0'), '.'));
    }

    /** $base with $share of $other mixed in, as #rrggbb. */
    private static function mix(string $base, string $other, float $share): string
    {
        $a = self::channels($base);
        $b = self::channels($other);

        return sprintf(
            '#%02x%02x%02x',
            (int) round($a[0] + ($b[0] - $a[0]) * $share),
            (int) round($a[1] + ($b[1] - $a[1]) * $share),
            (int) round($a[2] + ($b[2] - $a[2]) * $share)
        );
    }
}

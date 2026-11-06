<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * The HTML shell every notification email is poured into: the logo header, the
 * card around the message, and the footer rule.
 *
 * The shell lives in an ordinary post - the `eventcrew_email` type - so it is
 * edited in the block editor, with revisions and the usual undo, rather than in
 * a textarea nobody dares touch. The post is seeded with the shipped default on
 * first use and can be reset to it, so a broken edit is never a dead end. Only
 * the shell is editable: each message's wording stays in the translated strings
 * the notifiers write, which is what keeps the emails translatable.
 *
 * The type is registered non-public. This is a template, not a page: it must
 * never be reachable on the front end, appear in a sitemap, or turn up in
 * search.
 */
final class EmailTemplate
{
    public const POST_TYPE = 'eventcrew_email';

    /** Whether emails go out as HTML at all; off falls back to plain text. */
    public const HTML_OPTION = 'eventcrew_email_html';

    /** An explicit logo URL, overriding the site's own logo. */
    public const LOGO_OPTION = 'eventcrew_email_logo';

    /**
     * The picture behind the whole email: a URL, best a photo already
     * darkened so the light writing on it stays readable. Blank draws the
     * purple fade alone.
     */
    public const BACKGROUND_OPTION = 'eventcrew_email_background';

    /** The night colour under everything: what shows when no picture or fade is drawn. */
    public const NIGHT = '#0e091e';

    /** The cream of the card. */
    public const CREAM = '#fbf6ec';

    /** The purple fade behind the email, under the picture or instead of it. */
    private const FADE = 'linear-gradient(180deg,#2b1d57 0%,#160f2e 60%,#0e091e 100%)';

    /** The post holding the wrapper markup. */
    public const POST_OPTION = 'eventcrew_email_template_id';

    /** The slot the message drops into; a wrapper without it is unusable. */
    private const CONTENT_TAG = '{{content}}';

    /** The widest the logo above the card is drawn. */
    private const MASTHEAD_WIDTH = 230;

    /** The tallest the banner at the top is drawn. */
    private const MASTHEAD_HEIGHT = 140;

    /**
     * The masthead links its image rather than embedding it as an inline (CID)
     * attachment. Embedding was tried and reverted in 1.10.2: attaching happens
     * on `phpmailer_init`, which never fires on an install whose mail goes out
     * through a relay or an API-based SMTP plugin instead of PHPMailer. There
     * the HTML would keep its cid: reference with no part behind it, which is a
     * guaranteed broken image - strictly worse than a link, which at least
     * works wherever the host serves the file. The same caveat applies to the
     * plain-text AltBody set in Mailer::sendHtml().
     */

    /**
     * $readOnly (the message preview, MessagePreview) never creates the
     * template post: a missing post renders with the built-in default, the
     * same markup the post would be seeded with.
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly bool $readOnly = false
    ) {
    }

    public function boot(): void
    {
        add_action('init', [$this, 'registerPostType']);
    }

    /**
     * HTML is the default. An admin who has had deliverability trouble - or who
     * simply wants the old plain text - turns it off in Settings.
     */
    public static function enabled(): bool
    {
        return (bool) get_option(self::HTML_OPTION, true);
    }

    public function registerPostType(): void
    {
        register_post_type(
            self::POST_TYPE,
            [
                'label' => __('Email template', 'eventcrew'),
                'labels' => [
                    'name' => __('Email templates', 'eventcrew'),
                    'singular_name' => __('Email template', 'eventcrew'),
                    'edit_item' => __('Edit the email template', 'eventcrew'),
                ],
                // Not public: no permalink, no archive, no search, no sitemap.
                // show_ui is what still gives it the editor screens.
                'public' => false,
                'publicly_queryable' => false,
                'exclude_from_search' => true,
                'show_ui' => true,
                'show_in_menu' => false,
                'show_in_nav_menus' => false,
                // The block editor talks to the REST API, so the type has to be
                // exposed there for the editing experience this exists to give.
                'show_in_rest' => true,
                'supports' => ['title', 'editor', 'revisions'],
                'capabilities' => self::capabilities(),
                'map_meta_cap' => false,
                'rewrite' => false,
                'query_var' => false,
            ]
        );
    }

    /**
     * Every capability collapses to the plugin's own, so editing the template is
     * exactly as restricted as the rest of EventCrew's admin - an Editor who can
     * publish pages still cannot rewrite what goes out to the whole crew.
     *
     * @return array<string, string>
     */
    private static function capabilities(): array
    {
        // The literal mirrors Admin\Admin::CAPABILITY; kept literal so this
        // Support class does not reach up into the Admin layer.
        $capability = 'manage_options';

        return [
            'edit_post' => $capability,
            'read_post' => $capability,
            'delete_post' => $capability,
            'edit_posts' => $capability,
            'edit_others_posts' => $capability,
            'delete_posts' => $capability,
            'publish_posts' => $capability,
            'read_private_posts' => $capability,
            'create_posts' => $capability,
        ];
    }

    /**
     * The id of the template post, creating it from the shipped default the
     * first time anything asks. Returns 0 if the insert fails, which leaves
     * rendering on the built-in default rather than breaking a send.
     */
    public function postId(): int
    {
        $stored = (int) get_option(self::POST_OPTION, 0);

        if ($stored > 0 && self::POST_TYPE === get_post_type($stored)) {
            return $stored;
        }

        if ($this->readOnly) {
            return 0;
        }

        $id = wp_insert_post([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => __('EventCrew email template', 'eventcrew'),
            'post_content' => self::defaultContent(),
        ]);

        if (is_wp_error($id) || 0 === (int) $id) {
            $this->logger->warning('The email template post could not be created.');

            return 0;
        }

        update_option(self::POST_OPTION, (int) $id);

        return (int) $id;
    }

    /** The admin URL that edits the template. */
    public function editUrl(): string
    {
        $id = $this->postId();

        return $id > 0
            ? admin_url('post.php?post=' . $id . '&action=edit')
            : admin_url('admin.php?page=eventcrew-settings');
    }

    /** Puts the shipped default back, discarding whatever was there. */
    public function reset(): bool
    {
        $id = $this->postId();

        if (0 === $id) {
            return false;
        }

        // Through wp_update_post, so the edit that is being discarded stays in
        // the post's revisions and can be brought back.
        $result = wp_update_post(['ID' => $id, 'post_content' => self::defaultContent()], true);

        return ! is_wp_error($result);
    }

    /**
     * The full HTML document for one email.
     *
     * $lightOnly asks mail apps not to draw the email in dark colours (the
     * ticket email: its QR code must stay black on white to scan). It is a
     * request: an app that recolours anyway still can, which is why the QR
     * cells carry their colours as background images too (TicketQr).
     *
     * $now is the moment the email is for: {{year}} is its year. Null is the
     * current time. The newsletter preview passes the newsletter's send time,
     * so it looks as it will on that day.
     *
     * $after is HTML put after the whole template, at the very bottom of the
     * email (the newsletter preview's "Do Not Send This" button).
     *
     * $bothLanguages is for an email written in Finnish and then English: the
     * closing ({{signature}}) shows the Finnish sign-off too.
     */
    public function render(
        string $subject,
        string $content,
        string $footer,
        bool $lightOnly = false,
        ?\DateTimeInterface $now = null,
        string $after = '',
        bool $bothLanguages = false
    ): string {
        $body = str_replace(
            [
                '{{footer}}',
                '{{signature}}',
                '{{logo}}',
                '{{tagline}}',
                '{{backdrop}}',
                '{{accent}}',
                '{{site_name}}',
                '{{site_url}}',
                '{{subject}}',
                '{{year}}',
            ],
            [
                $footer,
                Signature::html($bothLanguages),
                self::logoHtml(),
                self::taglineHtml(),
                self::backdrop(),
                esc_attr(self::accent()),
                esc_html(self::siteName()),
                esc_url(home_url('/')),
                esc_html($subject),
                esc_html(null === $now ? (string) gmdate('Y') : $now->format('Y')),
            ],
            $this->wrapper()
        );

        // The message goes in last, on its own pass. Done in the same pass as
        // the rest, a "{{footer}}" that happened to be part of an event name
        // would be treated as a placeholder once the content was already in
        // place.
        $body = str_replace(self::CONTENT_TAG, $content, $body);

        return '<!DOCTYPE html>' . "\n"
            . '<html lang="' . esc_attr(str_replace('_', '-', (string) get_bloginfo('language'))) . '">' . "\n"
            . '<head>' . "\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width,initial-scale=1">' . "\n"
            . ($lightOnly
                ? '<meta name="color-scheme" content="light only">' . "\n"
                    . '<meta name="supported-color-schemes" content="light only">' . "\n"
                    . '<style>:root{color-scheme:light only}</style>' . "\n"
                : '<meta name="color-scheme" content="light">' . "\n")
            . '<title>' . esc_html($subject) . '</title>' . "\n"
            . self::styles()
            . '</head>' . "\n"
            . '<body style="margin:0;padding:0;background:' . self::NIGHT . ';">' . "\n"
            . $body . "\n"
            . ('' === $after ? '' : $after . "\n")
            . '</body>' . "\n"
            . '</html>';
    }

    /**
     * The two style blocks in the head. Everything else is inline; these only
     * add what inline styles cannot say, and an app that drops them loses
     * nothing it needs.
     *
     * The first narrows the card's padding on a phone. The second is the
     * gold shimmer on headings (the ec-shimmer class): the gold is clipped to
     * the letters and moves slowly across them. The ec-shimmer-light class is
     * the same with a cream shine, for a heading on a dark band such as the
     * ticket's header. It is guarded by @supports,
     * because an app that made the letters transparent without clipping the
     * gold to them would show no heading at all, and it is left out for a
     * reader who asked for less motion. It is a block of its own because
     * Gmail throws away a whole style block it does not understand; Gmail
     * shows the inline gold, as does Outlook.
     */
    private static function styles(): string
    {
        return '<style>@media only screen and (max-width:600px){'
            . '.ec-card-pad{padding:30px 22px 24px!important}'
            . '.ec-close-pad{padding:14px 22px 26px!important}}</style>' . "\n"
            . '<style>@supports ((-webkit-background-clip:text) or (background-clip:text)){'
            . '@media (prefers-reduced-motion:no-preference){'
            . '@keyframes ec-shimmer{from{background-position:200% 0}to{background-position:-200% 0}}'
            . '.ec-shimmer{background:linear-gradient(90deg,#8a4f12 0%,#c9802f 25%,#f0b45c 50%,#c9802f 75%,'
            . '#8a4f12 100%);background-size:200% 100%;-webkit-background-clip:text;background-clip:text;'
            . '-webkit-text-fill-color:transparent;animation:ec-shimmer 6s linear infinite}'
            . '.ec-shimmer-light{background:linear-gradient(90deg,#ffe2b8 0%,#fff4e0 25%,#ffffff 50%,#fff4e0 75%,'
            . '#ffe2b8 100%);background-size:200% 100%;-webkit-background-clip:text;background-clip:text;'
            . '-webkit-text-fill-color:transparent;animation:ec-shimmer 6s linear infinite}}}</style>' . "\n";
    }

    /**
     * The attributes of the outermost table, for {{backdrop}}: the night
     * colour as bgcolor (what Outlook and an app that drops CSS backgrounds
     * show), the picture as the old background attribute (what some of those
     * still draw), and in the style the picture over the purple fade, both
     * covering the email.
     */
    public static function backdrop(): string
    {
        $photo = trim((string) get_option(self::BACKGROUND_OPTION, ''));
        $photo = '' === $photo ? '' : esc_url($photo);
        $layers = '' === $photo ? self::FADE : "url('" . $photo . "')," . self::FADE;

        return 'bgcolor="' . self::NIGHT . '"'
            . ('' === $photo ? '' : ' background="' . $photo . '"')
            . ' style="background-color:' . self::NIGHT . ';background-image:' . $layers . ';'
            . 'background-position:' . ('' === $photo ? 'center' : '50% 0,center') . ';'
            . 'background-size:' . ('' === $photo ? 'cover' : 'cover,cover') . ';background-repeat:no-repeat"';
    }

    /**
     * The site's tagline (Settings > General) in italics under the logo, for
     * {{tagline}}; '' when the site has none.
     */
    public static function taglineHtml(): string
    {
        $tagline = trim((string) get_bloginfo('description'));

        if ('' === $tagline) {
            return '';
        }

        return sprintf(
            '<p style="margin:16px 0 0;font-family:%s;font-size:16px;font-style:italic;line-height:1.4;'
                . 'letter-spacing:.05em;color:#ffe2b8;text-align:center">%s</p>',
            EmailBody::HEAD_FONT,
            esc_html($tagline)
        );
    }

    /**
     * The wrapper markup: the edited post when it has one, the shipped default
     * otherwise. A wrapper that has lost its {{content}} slot would send an
     * email with no message in it, so that falls back too rather than going out.
     */
    public function wrapper(): string
    {
        $id = $this->postId();
        // get_post(0) would read the global post: no template post means the default.
        $post = $id > 0 ? get_post($id) : null;

        if (! $post instanceof \WP_Post || 'publish' !== $post->post_status) {
            return self::defaultWrapper();
        }

        // do_blocks alone, not the_content: the default lives in an HTML block
        // and wpautop (plus whatever the active theme filters onto content)
        // would put stray paragraph tags through the table layout.
        $markup = trim((string) do_blocks($post->post_content));

        if ('' === $markup || ! str_contains($markup, self::CONTENT_TAG)) {
            $this->logger->warning(
                'The email template has no {{content}} placeholder; the built-in template was used instead.'
            );

            return self::defaultWrapper();
        }

        return $markup;
    }

    /** The default wrapper as block markup, for seeding and resetting the post. */
    public static function defaultContent(): string
    {
        return "<!-- wp:html -->\n" . self::defaultWrapper() . "\n<!-- /wp:html -->";
    }

    /**
     * The shipped template (the v4 look): the darkened photo, or the purple
     * fade, behind the whole email; the logo and the site's tagline above a
     * cream card with a soft gold glow; the closing centred at the foot of the
     * card; and "✦ SITE ✦" under it.
     *
     * Tables and inline styles throughout, and a 560px card, because that is
     * what still renders the same in Outlook, Gmail and Apple Mail. Each
     * background has a plain colour behind it (bgcolor and background-color),
     * so an app that draws no picture and no gradient still shows the night
     * colour, with the light writing readable on it. The merge tags are the
     * whole contract with the editor: {{logo}}, {{tagline}}, {{backdrop}},
     * {{content}}, {{footer}}, {{signature}}, {{accent}}, {{site_name}},
     * {{site_url}}, {{subject}} and {{year}}.
     */
    public static function defaultWrapper(): string
    {
        $head = EmailBody::HEAD_FONT;
        $cream = self::CREAM;

        return <<<HTML
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" {{backdrop}}>
                <tr>
                    <td align="center" style="padding:52px 14px 40px">
                        {{logo}}{{tagline}}
                        <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" bgcolor="{$cream}"
                            style="width:100%;max-width:560px;margin:40px auto 0;background:{$cream};border-radius:30px;border-collapse:separate;box-shadow:0 0 0 1px rgba(255,201,143,.5),0 0 40px rgba(255,190,120,.35),0 0 120px rgba(255,190,120,.18)">
                            <tr>
                                <td class="ec-card-pad" style="padding:40px 40px 30px">{{content}}</td>
                            </tr>
                            <tr>
                                <td class="ec-close-pad" align="center" style="padding:18px 40px 32px;text-align:center">{{signature}}{{footer}}</td>
                            </tr>
                        </table>
                        <p style="margin:34px 0 0;font-family:{$head};font-size:12px;line-height:1.5;letter-spacing:.24em;text-transform:uppercase;color:#ffc98f;text-align:center">&#10022;&nbsp;&nbsp;{{site_name}}&nbsp;&nbsp;&#10022;</p>
                    </td>
                </tr>
            </table>
            HTML;
    }

    /**
     * The masthead image. The site's own logo is the point - the same one on the
     * website, so the email is recognisably from the same place - with the
     * Customizer's logo first, the Site Icon behind it, and the site name set as
     * a wordmark when there is no image at all.
     */
    /**
     * The logo's image URL in the same order as the email masthead (Settings
     * logo, Customizer logo, Site Icon), or '' when there is none. The ticket
     * draws it in the middle of its QR code.
     */
    public static function logoUrl(): string
    {
        $explicit = trim((string) get_option(self::LOGO_OPTION, ''));

        if ('' !== $explicit) {
            return $explicit;
        }

        $customLogo = function_exists('get_theme_mod') ? (int) get_theme_mod('custom_logo') : 0;

        if ($customLogo > 0) {
            $source = wp_get_attachment_image_src($customLogo, 'medium');

            if (is_array($source) && isset($source[0])) {
                return (string) $source[0];
            }
        }

        if (function_exists('has_site_icon') && has_site_icon()) {
            $icon = get_site_icon_url(180);

            if (is_string($icon)) {
                return $icon;
            }
        }

        return '';
    }

    /**
     * The image for the middle of a ticket's QR code: the Site Icon first,
     * then logoUrl(), or '' when there is none. The Site Icon comes first
     * because it is square and made to be read small; the email logo is often
     * a wide wordmark drawn in white for a dark masthead, which is invisible on
     * the white square in the middle of the code.
     */
    public static function qrMarkUrl(): string
    {
        if (function_exists('has_site_icon') && has_site_icon()) {
            $icon = get_site_icon_url(180);

            if (is_string($icon) && '' !== $icon) {
                return $icon;
            }
        }

        return self::logoUrl();
    }

    public static function logoHtml(): string
    {
        $explicit = trim((string) get_option(self::LOGO_OPTION, ''));

        if ('' !== $explicit) {
            // Dimensions are unknown for a pasted URL, so the masthead falls
            // back to its default width.
            return self::image($explicit, 0, 0);
        }

        $customLogo = (int) get_theme_mod('custom_logo');

        if ($customLogo > 0) {
            $source = wp_get_attachment_image_src($customLogo, 'full');

            if (is_array($source) && isset($source[0])) {
                return self::image((string) $source[0], (int) ($source[1] ?? 0), (int) ($source[2] ?? 0));
            }
        }

        if (function_exists('has_site_icon') && has_site_icon()) {
            $icon = get_site_icon_url(180);

            if (is_string($icon) && '' !== $icon) {
                return self::image($icon, 180, 180);
            }
        }

        return self::wordmark();
    }

    /**
     * Scales the logo to fit the masthead, in an explicit width attribute rather
     * than CSS alone - Outlook sizes images from the attribute and ignores much
     * of the rest. A tall logo is bounded by height, a wide banner by width, so
     * a square icon and a letterbox banner both land at a sensible size.
     *
     * The logo may be up to MASTHEAD_WIDTH wide (above the card, on the
     * dark background) and MASTHEAD_HEIGHT tall; a picture is never drawn larger than it is. When
     * the size is known, a height attribute goes with the width, so Outlook
     * keeps the proportions; max-width:100% and height:auto shrink it on a
     * phone.
     */
    private static function image(string $url, int $width, int $height): string
    {
        $maxWidth = self::MASTHEAD_WIDTH;
        $maxHeight = self::MASTHEAD_HEIGHT;
        $rendered = $maxWidth;
        $renderedHeight = 0;

        if ($width > 0 && $height > 0) {
            $scale = min($maxWidth / $width, $maxHeight / $height, 1.0);
            $rendered = (int) round($width * $scale);
            $renderedHeight = (int) round($height * $scale);
        }

        // margin:0 auto, not the td's align="center": that attribute sets
        // text-align, which centres inline content and does nothing for a
        // display:block image - the logo would sit against the left padding.
        // The font and colour are for the alt text, which an app that blocks
        // images draws in the image's place, on the dark background.
        return sprintf(
            '<img src="%s" alt="%s" width="%d"%s style="display:block;margin:0 auto;border:0;outline:none;'
                . 'width:%dpx;max-width:100%%;height:auto;font-family:%s;font-size:22px;font-weight:700;'
                . 'color:#fff4e0;text-align:center">',
            esc_url($url),
            esc_attr(self::siteName()),
            $rendered,
            $renderedHeight > 0 ? sprintf(' height="%d"', $renderedHeight) : '',
            $rendered,
            EmailBody::HEAD_FONT
        );
    }

    /**
     * The fallback masthead: the site's name, set large in the serif. Light,
     * because it sits on the dark background above the card.
     */
    private static function wordmark(): string
    {
        return sprintf(
            '<p style="margin:0;font-family:%s;font-size:26px;font-weight:700;line-height:1.2;color:#fff4e0;'
                . 'text-align:center">%s</p>',
            EmailBody::HEAD_FONT,
            esc_html(self::siteName())
        );
    }

    /**
     * The accent buttons and the wordmark are drawn in. It is the same colour
     * the installed app uses, so one setting keeps the two in step. The option
     * name mirrors Web\PwaController::COLOR_OPTION, kept literal so this Support
     * class does not reach up into the Web layer.
     */
    public static function accent(): string
    {
        $color = sanitize_hex_color((string) get_option('eventcrew_app_theme_color', ''));

        // Deliberately not read from the theme's global-styles palette: themes
        // name their palette entries as they please, so picking one by slug is
        // a guess, and a guess that lands on the wrong colour is worse than a
        // neutral default nobody mistakes for a setting.
        return is_string($color) && '' !== $color ? $color : '#4a6cf7';
    }

    private static function siteName(): string
    {
        $name = trim((string) get_bloginfo('name'));

        return '' === $name ? 'EventCrew' : $name;
    }
}

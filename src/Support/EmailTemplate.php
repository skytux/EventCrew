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

    /** The post holding the wrapper markup. */
    public const POST_OPTION = 'eventcrew_email_template_id';

    /** The slot the message drops into; a wrapper without it is unusable. */
    private const CONTENT_TAG = '{{content}}';

    public function __construct(
        private readonly Logger $logger
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
     */
    public function render(string $subject, string $content, string $footer): string
    {
        $body = str_replace(
            ['{{footer}}', '{{logo}}', '{{site_name}}', '{{site_url}}', '{{subject}}', '{{year}}'],
            [
                $footer,
                self::logoHtml(),
                esc_html(self::siteName()),
                esc_url(home_url('/')),
                esc_html($subject),
                esc_html((string) gmdate('Y')),
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
            . '<meta name="color-scheme" content="light">' . "\n"
            . '<title>' . esc_html($subject) . '</title>' . "\n"
            . '</head>' . "\n"
            . '<body style="margin:0;padding:0;background:#f4f5f7;">' . "\n"
            . $body . "\n"
            . '</body>' . "\n"
            . '</html>';
    }

    /**
     * The wrapper markup: the edited post when it has one, the shipped default
     * otherwise. A wrapper that has lost its {{content}} slot would send an
     * email with no message in it, so that falls back too rather than going out.
     */
    public function wrapper(): string
    {
        $post = get_post($this->postId());

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
     * The shipped template: a centred card on a light background, logo on top.
     *
     * Tables and inline styles throughout, and a 600px card, because that is
     * what still renders the same in Outlook, Gmail and Apple Mail. The merge
     * tags are the whole contract with the editor: {{logo}}, {{content}},
     * {{footer}}, {{site_name}}, {{site_url}}, {{subject}} and {{year}}.
     */
    public static function defaultWrapper(): string
    {
        $font = EmailBody::FONT;

        return <<<HTML
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                style="background:#f4f5f7;margin:0;padding:24px 12px">
                <tr>
                    <td align="center">
                        <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"
                            style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e6e8eb;border-radius:14px">
                            <tr>
                                <td align="center" style="padding:32px 32px 12px">{{logo}}</td>
                            </tr>
                            <tr>
                                <td style="padding:8px 32px 28px">{{content}}</td>
                            </tr>
                            <tr>
                                <td style="padding:20px 32px;border-top:1px solid #eceef1">{{footer}}</td>
                            </tr>
                        </table>
                        <p style="margin:16px 0 0;font-family:{$font};font-size:12px;line-height:1.5;color:#9aa1ab">
                            {{site_name}}
                        </p>
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
    public static function logoHtml(): string
    {
        $explicit = trim((string) get_option(self::LOGO_OPTION, ''));

        if ('' !== $explicit) {
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
     */
    private static function image(string $url, int $width, int $height): string
    {
        $maxWidth = 260;
        $maxHeight = 72;
        $rendered = $maxWidth;

        if ($width > 0 && $height > 0) {
            $scale = min($maxWidth / $width, $maxHeight / $height, 1.0);
            $rendered = (int) round($width * $scale);
        }

        return sprintf(
            '<img src="%s" alt="%s" width="%d" style="display:block;border:0;outline:none;'
                . 'width:%dpx;max-width:100%%;height:auto">',
            esc_url($url),
            esc_attr(self::siteName()),
            $rendered,
            $rendered
        );
    }

    /** The fallback masthead: the site's name, set large. */
    private static function wordmark(): string
    {
        return sprintf(
            '<p style="margin:0;font-family:%s;font-size:22px;font-weight:700;line-height:1.2;color:%s">%s</p>',
            EmailBody::FONT,
            esc_attr(self::accent()),
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

        return is_string($color) && '' !== $color ? $color : '#4a6cf7';
    }

    private static function siteName(): string
    {
        $name = trim((string) get_bloginfo('name'));

        return '' === $name ? 'EventCrew' : $name;
    }
}

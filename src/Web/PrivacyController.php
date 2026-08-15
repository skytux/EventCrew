<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Support\PrivacyPolicy;
use WP_Post;

/**
 * Publishes the privacy notice: the [eventcrew_privacy] shortcode and its
 * matching block, plus the suggested text WordPress folds into its own privacy
 * policy draft.
 *
 * Both readers take their words from Support\PrivacyPolicy, so the page and the
 * site policy cannot say different things about the same processing. The only
 * difference is the wrapper - headings and paragraphs here, core's expected
 * `.wp-policy-help` markup there.
 *
 * Deliberately unconfigured-safe: with no controller filled in, the shortcode
 * renders an organizer-only warning rather than a notice with a hole where the
 * responsible party should be, and the suggestion is not registered at all. A
 * privacy notice that cannot say who is answerable is worse than no page.
 */
final class PrivacyController
{
    public function __construct(private readonly SignupController $signup)
    {
    }

    public function boot(): void
    {
        add_shortcode('eventcrew_privacy', [$this, 'renderShortcode']);
        add_action('init', [$this, 'registerBlock']);
        add_action('admin_init', [$this, 'registerPolicyText']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueStyle']);
    }

    public function registerBlock(): void
    {
        if (! function_exists('register_block_type')) {
            return;
        }

        wp_register_script(
            'eventcrew-privacy-editor',
            plugins_url('blocks/privacy/editor.js', EVENTCREW_PLUGIN_FILE),
            ['wp-blocks', 'wp-element', 'wp-server-side-render'],
            EVENTCREW_VERSION,
            true
        );

        register_block_type('eventcrew/privacy', [
            'api_version' => 2,
            'editor_script' => 'eventcrew-privacy-editor',
            'style' => SignupController::STYLE_HANDLE,
            'render_callback' => [$this, 'renderShortcode'],
        ]);
    }

    /**
     * The notice shares the signup page's stylesheet rather than carrying one of
     * its own - it is a handful of headings and paragraphs, and a second sheet
     * for that would be a second thing to keep in step with the site's accent.
     */
    public function enqueueStyle(): void
    {
        $post = get_post();

        if (! $post instanceof WP_Post) {
            return;
        }

        if (has_shortcode((string) $post->post_content, 'eventcrew_privacy') || has_block('eventcrew/privacy', $post)) {
            $this->signup->registerStyle();
            wp_enqueue_style(SignupController::STYLE_HANDLE);
        }
    }

    public function renderShortcode(): string
    {
        // Register before enqueuing, for the paths wp_enqueue_scripts never
        // reached - the block's REST preview above all. An enqueue of an
        // unregistered handle is a silent no-op, which is how a page ends up
        // rendering with none of its CSS and no error to explain it.
        $this->signup->registerStyle();
        wp_enqueue_style(SignupController::STYLE_HANDLE);

        $eventcrew_configured = PrivacyPolicy::isConfigured();
        $eventcrew_can_edit = current_user_can('manage_options');
        $eventcrew_sections = $eventcrew_configured ? PrivacyPolicy::sections() : [];

        ob_start();
        include EVENTCREW_PLUGIN_DIR . 'templates/public/privacy.php';

        return (string) ob_get_clean();
    }

    /**
     * Adds the same notice to Settings -> Privacy as suggested policy text.
     *
     * Core keeps a hash of what a plugin last suggested and flags the section as
     * stale when it changes, so an organizer who has already pasted this into
     * their policy is told when a new version rewords it. That only works if the
     * text is registered on every admin request, which is why this is not gated
     * to the privacy screen itself.
     */
    public function registerPolicyText(): void
    {
        if (! function_exists('wp_add_privacy_policy_content') || ! PrivacyPolicy::isConfigured()) {
            return;
        }

        $content = '';

        foreach (PrivacyPolicy::sections() as $section) {
            $content .= '<h2 class="wp-policy-help">' . esc_html($section['heading']) . "</h2>\n";

            foreach ($section['paragraphs'] as $paragraph) {
                $content .= '<p class="wp-policy-help">' . esc_html($paragraph) . "</p>\n";
            }
        }

        wp_add_privacy_policy_content('EventCrew', wp_kses_post($content));
    }
}

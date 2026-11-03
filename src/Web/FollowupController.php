<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Support\FollowupMail;
use EventCrew\Support\Language;
use WP_REST_Request;

/**
 * The two public addresses of the post-event email (Support\FollowupMail):
 *
 * - ?eventcrew_go=<token> on any page: a link in the email. Counts the row's
 *   first click and answers with a 302 to the address signed into the
 *   token. Anything else is sent to the front page.
 * - ?eventcrew_join=<token> on any page: the subscribe button. Opening it
 *   shows a page that posts the token back at once (a mail scanner that
 *   opens links must not subscribe anyone); the POST counts the row's first
 *   click, subscribes the row's address to the next events announcement,
 *   and sends the browser to the subscribe form.
 * - /wp-json/eventcrew/v1/followup?do=stop&token=…: the Stop link in the
 *   organizers' preview. Opening it shows a button (a mail scanner that
 *   opens links must not stop the email); pressing it stops that night's
 *   email.
 */
final class FollowupController
{
    public function __construct(private readonly FollowupMail $followup)
    {
    }

    public function boot(): void
    {
        add_action('init', [$this, 'handleClick'], 1);
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            'eventcrew/v1',
            '/followup',
            [
                'methods' => ['GET', 'POST'],
                'callback' => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /** A counted link: records the click and redirects. */
    public function handleClick(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- a signed link from an email; the token is the check.
        if (isset($_GET[FollowupMail::JOIN_ARG]) || isset($_POST[FollowupMail::JOIN_ARG])) {
            $this->handleJoin();
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a signed link from an email; the token is the check.
        if (! isset($_GET[FollowupMail::GO_ARG])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
        $token = (string) wp_unslash($_GET[FollowupMail::GO_ARG]);
        $target = $this->followup->clickTarget(preg_replace('/[^A-Za-z0-9_.\-]/', '', $token) ?? '');

        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);

        if (null === $target) {
            wp_safe_redirect(home_url('/'), 302);
            exit;
        }

        // Not wp_safe_redirect(): the target is the ticket shop or the DJ's
        // music page on another host. It is safe because only this site can
        // sign a token, and clickTarget() allows only http(s).
        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- signed target, see above.
        wp_redirect($target, 302, 'EventCrew');
        exit;
    }

    /**
     * The subscribe button. See respondJoin(): opening the link only shows a
     * page that posts the token back; the POST subscribes and redirects.
     */
    private function handleJoin(): void
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
        $isPost = 'POST' === strtoupper($method);
        // phpcs:disable WordPress.Security.NonceVerification -- a signed link from an email; the token is the check.
        $raw = $isPost && isset($_POST[FollowupMail::JOIN_ARG])
            ? $_POST[FollowupMail::JOIN_ARG]
            : ($_GET[FollowupMail::JOIN_ARG] ?? '');
        // phpcs:enable WordPress.Security.NonceVerification
        $token = (string) wp_unslash($raw);
        $answer = $this->respondJoin(
            $isPost ? 'POST' : 'GET',
            preg_replace('/[^A-Za-z0-9_.\-]/', '', $token) ?? '',
            $isPost ? Language::preferredInRequest() : ''
        );

        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);

        if (null !== $answer['redirect']) {
            wp_safe_redirect($answer['redirect'], 302);
            exit;
        }

        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        // Built from literal markup and esc_html()'d values in page().
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $answer['html'];
        exit;
    }

    /**
     * What the subscribe button does.
     *
     * A GET (opening the link) only answers with a page whose form posts the
     * token back, submitted at once by a small script: a real browser goes
     * straight on, a mail scanner that opens links subscribes nobody and
     * counts no click. The button is there for a browser without scripts.
     * The POST records the click, subscribes the ticket holder in $language
     * (FollowupMail::joinClick()) and redirects to the subscribe form. A
     * token this site did not sign redirects to the front page either way.
     *
     * @return array{redirect: ?string, html: string}
     */
    public function respondJoin(string $method, string $token, string $language): array
    {
        if ('POST' === strtoupper($method)) {
            return [
                'redirect' => $this->followup->joinClick($token, $language) ?? home_url('/'),
                'html' => '',
            ];
        }

        if (! FollowupMail::isJoinToken($token)) {
            return ['redirect' => home_url('/'), 'html' => ''];
        }

        $page = $this->page(
            200,
            static fn (): string => __('Press the button to join the next events announcement.', 'eventcrew'),
            $this->joinForm($token),
            static fn (): string => __('Next events announcement', 'eventcrew')
        );

        return ['redirect' => null, 'html' => $page['html']];
    }

    public function handle(WP_REST_Request $request): void
    {
        $page = $this->respond(
            (string) $request->get_param('do'),
            $request->get_method(),
            (string) $request->get_param('token')
        );

        status_header($page['status']);
        header('Content-Type: text/html; charset=utf-8');
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        // Built from literal markup and esc_html()'d values in page().
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $page['html'];
        exit;
    }

    /**
     * What the Stop link does and the page it answers with.
     *
     * @return array{status: int, html: string}
     */
    public function respond(string $do, string $method, string $token): array
    {
        $date = 'stop' === $do ? FollowupMail::dateOfStopToken($token) : null;

        if (null === $date) {
            return $this->page(404, static fn (): string => __('This link is no longer valid.', 'eventcrew'));
        }

        if ('POST' !== strtoupper($method)) {
            return $this->page(
                200,
                static fn (): string => __(
                    'Press the button to stop the post-event email of this event. It will not be sent.',
                    'eventcrew'
                ),
                $this->stopForm($token)
            );
        }

        $answer = $this->followup->stop($date);

        return $this->page(200, static fn (): string => match ($answer) {
            FollowupMail::STOPPED => __('The post-event email is stopped. It will not be sent.', 'eventcrew'),
            FollowupMail::ALREADY => __('The post-event email was already stopped.', 'eventcrew'),
            default => __('Too late: the post-event email has already gone out.', 'eventcrew'),
        });
    }

    /**
     * A page with $message in Finnish and in English, and $extra under it.
     *
     * @return array{status: int, html: string}
     */
    private function page(int $status, callable $message, string $extra = '', ?callable $title = null): array
    {
        $title = self::both($title ?? static fn (): string => __('Post-event email', 'eventcrew'));
        $fi = (string) Language::run(Language::FINNISH, $message);
        $en = (string) Language::run(Language::ENGLISH, $message);

        $inner = '<p lang="fi">' . esc_html($fi) . '</p>'
            . ($fi === $en ? '' : '<p lang="en">' . esc_html($en) . '</p>')
            . $extra;

        return [
            'status' => $status,
            'html' => sprintf(
                // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal HTML document; wrapping it just adds stray whitespace.
                '<!doctype html><html lang="fi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>%1$s</title></head><body style="font-family:sans-serif;max-width:28em;margin:3em auto;padding:0 1em"><h1 style="font-size:1.2em">%1$s</h1>%2$s</body></html>',
                esc_html($title),
                $inner
            ),
        ];
    }

    private function stopForm(string $token): string
    {
        return sprintf(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal HTML form; wrapping it just adds stray whitespace.
            '<form method="post" style="margin:1em 0"><input type="hidden" name="do" value="stop"><input type="hidden" name="token" value="%s"><button type="submit" style="padding:.6em 1em;color:#b32d2e">%s</button></form>',
            esc_attr($token),
            esc_html(self::both(static fn (): string => __('Do not send the post-event email', 'eventcrew')))
        );
    }

    /** The subscribe button's form, sent by the script as soon as the page loads. */
    private function joinForm(string $token): string
    {
        return sprintf(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal HTML form; wrapping it just adds stray whitespace.
            '<form id="eventcrew-join" method="post" action="%s" style="margin:1em 0"><input type="hidden" name="%s" value="%s"><button type="submit" style="padding:.6em 1em">%s</button></form><script>document.getElementById("eventcrew-join").submit();</script>',
            esc_url(home_url('/')),
            esc_attr(FollowupMail::JOIN_ARG),
            esc_attr($token),
            esc_html(self::both(static fn (): string => __('Join', 'eventcrew')))
        );
    }

    /** "fi / en", or one when both agree. */
    private static function both(callable $words): string
    {
        $fi = (string) Language::run(Language::FINNISH, $words);
        $en = (string) Language::run(Language::ENGLISH, $words);

        return $fi === $en ? $en : $fi . ' / ' . $en;
    }
}

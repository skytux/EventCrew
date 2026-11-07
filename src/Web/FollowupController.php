<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Support\FollowupMail;
use EventCrew\Support\HybridPolicy;
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
 * - ?eventcrew_optout=<token> on any page: "Don't send me these". Like the
 *   subscribe button, opening it shows a page that posts the token back at
 *   once; the POST puts the row's address on the do-not-send list and shows
 *   a short confirmation in the post-event email's languages. A POST straight
 *   to the link (a mail app's one-click unsubscribe, RFC 8058) does the same.
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

        // phpcs:ignore WordPress.Security.NonceVerification -- a signed link from an email; the token is the check.
        if (isset($_GET[FollowupMail::OPTOUT_ARG]) || isset($_POST[FollowupMail::OPTOUT_ARG])) {
            $this->handleOptOut();
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

    /** "Don't send me these". See respondOptOut(). */
    private function handleOptOut(): void
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
        $isPost = 'POST' === strtoupper($method);
        // phpcs:ignore WordPress.Security.NonceVerification -- a signed link from an email; the token is the check.
        $token = self::optOutToken($isPost, wp_unslash($_GET), wp_unslash($_POST));
        $page = $this->respondOptOut($isPost ? 'POST' : 'GET', $token);

        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        status_header($page['status']);
        header('Content-Type: text/html; charset=utf-8');
        // Built from literal markup and esc_html()'d values in page().
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $page['html'];
        exit;
    }

    /**
     * The "Don't send me these" token of a request: the form's posted one,
     * else the one in the link. The one-click unsubscribe of a mail app
     * (List-Unsubscribe-Post, RFC 8058) is a POST to the link itself with
     * only "List-Unsubscribe=One-Click" in the body, so its token is the
     * link's, and the POST puts the address on the list with no page between.
     *
     * @param array<mixed> $get
     * @param array<mixed> $post
     */
    public static function optOutToken(bool $isPost, array $get, array $post): string
    {
        $raw = $isPost && isset($post[FollowupMail::OPTOUT_ARG])
            ? $post[FollowupMail::OPTOUT_ARG]
            : ($get[FollowupMail::OPTOUT_ARG] ?? '');

        return is_string($raw) ? (preg_replace('/[^A-Za-z0-9_.\-]/', '', $raw) ?? '') : '';
    }

    /**
     * What "Don't send me these" does, in the post-event email's languages
     * (HybridPolicy): opening the link answers with a page whose form posts
     * the token back, submitted at once by a small script, so a mail scanner
     * that opens links puts nobody on the list. The POST puts the row's
     * address on the do-not-send list (FollowupMail::optOutClick()) and
     * answers with a short confirmation. A token this site did not sign gets
     * "This link is no longer valid."
     *
     * @return array{status: int, html: string}
     */
    public function respondOptOut(string $method, string $token): array
    {
        $languages = HybridPolicy::languagesFor(HybridPolicy::FOLLOWUP);

        if (! FollowupMail::isOptOutToken($token)) {
            return $this->page(
                404,
                static fn (): string => __('This link is no longer valid.', 'eventcrew'),
                '',
                null,
                $languages
            );
        }

        if ('POST' !== strtoupper($method)) {
            return $this->page(
                200,
                static fn (): string => __(
                    'Press the button to stop getting these emails after our events.',
                    'eventcrew'
                ),
                $this->optOutForm($token, $languages),
                null,
                $languages
            );
        }

        $this->followup->optOutClick($token);

        return $this->page(
            200,
            static fn (): string => __('Done. You will not get these emails after our events any more.', 'eventcrew'),
            '',
            null,
            $languages
        );
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
     * A page with $message in $languages (the shown site languages,
     * HybridPolicy::shown(), when null), and
     * $extra under it.
     *
     * @param list<string>|null $languages
     * @return array{status: int, html: string}
     */
    private function page(
        int $status,
        callable $message,
        string $extra = '',
        ?callable $title = null,
        ?array $languages = null
    ): array {
        $languages = $languages ?? HybridPolicy::shown();
        $title = self::both($title ?? static fn (): string => __('Post-event email', 'eventcrew'), $languages);
        $inner = '';

        foreach (array_unique(HybridPolicy::inEach($languages, $message)) as $lang => $text) {
            $inner .= '<p lang="' . esc_attr((string) $lang) . '">' . esc_html((string) $text) . '</p>';
        }

        $inner .= $extra;

        return [
            'status' => $status,
            'html' => sprintf(
                // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal HTML document; wrapping it just adds stray whitespace.
                '<!doctype html><html lang="%3$s"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>%1$s</title></head><body style="font-family:sans-serif;max-width:28em;margin:3em auto;padding:0 1em"><h1 style="font-size:1.2em">%1$s</h1>%2$s</body></html>',
                esc_html($title),
                $inner,
                esc_attr($languages[0])
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

    /**
     * The "Don't send me these" form, sent by the script as soon as the page loads.
     *
     * @param list<string> $languages
     */
    private function optOutForm(string $token, array $languages): string
    {
        return sprintf(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal HTML form; wrapping it just adds stray whitespace.
            '<form id="eventcrew-optout" method="post" action="%s" style="margin:1em 0"><input type="hidden" name="%s" value="%s"><button type="submit" style="padding:.6em 1em">%s</button></form><script>document.getElementById("eventcrew-optout").submit();</script>',
            esc_url(home_url('/')),
            esc_attr(FollowupMail::OPTOUT_ARG),
            esc_attr($token),
            esc_html(self::both(static fn (): string => __("Don't send me these", 'eventcrew'), $languages))
        );
    }

    /**
     * The texts in $languages, or in the shown site languages
     * (HybridPolicy::shown()), "fi / en", each different text once.
     *
     * @param list<string>|null $languages
     */
    private static function both(callable $words, ?array $languages = null): string
    {
        return HybridPolicy::join(
            array_map('strval', HybridPolicy::inEach($languages ?? HybridPolicy::shown(), $words))
        );
    }
}

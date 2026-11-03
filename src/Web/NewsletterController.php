<?php

declare(strict_types=1);

namespace EventCrew\Web;

use EventCrew\Repositories\NewsletterSubscriberRepository;
use EventCrew\Support\Language;
use EventCrew\Support\Mailer;
use EventCrew\Support\Newsletter;
use EventCrew\Support\SignedLink;
use EventCrew\Support\Turnstile;
use WP_REST_Request;

/**
 * The public side of the newsletter.
 *
 * - The [eventcrew_newsletter] shortcode: a form with one email field. It
 *   posts to admin-ajax.php (JOIN_ACTION). A hidden field that people do not
 *   see (HONEYPOT) catches simple bots, and Cloudflare Turnstile is used when
 *   it is set up in Settings. There is no nonce: the form is on a public page
 *   that a page cache may serve for days, and a stale nonce would refuse
 *   every real sign-up.
 * - A sign-up is a pending row and a confirmation email. Only the signed link
 *   in that email makes the address a subscriber (double opt-in). The answer
 *   on the page is the same whether or not the address was already known, so
 *   the form cannot be used to find out who subscribes.
 * - The REST route Newsletter::ROUTE answers the signed links: confirm (a
 *   GET confirms), unsubscribe (a GET or a POST unsubscribes; the POST is the
 *   one-click unsubscribe of the List-Unsubscribe-Post header), and stop (an
 *   organizer's link from the preview email: a GET shows a button, the POST
 *   stops the newsletter, so a mail client opening links in advance cannot
 *   stop it).
 */
final class NewsletterController
{
    public const SHORTCODE = 'eventcrew_newsletter';

    public const JOIN_ACTION = 'eventcrew_newsletter_join';

    /** The field people do not see; a bot that fills it in is ignored. */
    public const HONEYPOT = 'website';

    /** The query argument that carries the form's result back to the page. */
    public const RESULT_ARG = 'eventcrew_newsletter';

    public const RESULT_SENT = 'sent';
    public const RESULT_INVALID = 'invalid';
    public const RESULT_CAPTCHA = 'captcha';

    /** A pending sign-up gets a new confirmation email only after this long. */
    private const RESEND_AFTER_SECONDS = HOUR_IN_SECONDS;

    public function __construct(
        private readonly NewsletterSubscriberRepository $subscribers,
        private readonly Mailer $mailer,
        private readonly Newsletter $newsletter,
        private readonly Turnstile $turnstile
    ) {
    }

    public function boot(): void
    {
        add_shortcode(self::SHORTCODE, [$this, 'renderShortcode']);
        add_action('wp_ajax_' . self::JOIN_ACTION, [$this, 'handleJoin']);
        add_action('wp_ajax_nopriv_' . self::JOIN_ACTION, [$this, 'handleJoin']);
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            'eventcrew/v1',
            '/newsletter',
            [
                'methods' => ['GET', 'POST'],
                'callback' => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /** The sign-up form. */
    public function renderShortcode(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which message to show.
        $result = isset($_GET[self::RESULT_ARG]) ? sanitize_key(wp_unslash($_GET[self::RESULT_ARG])) : '';
        $message = match ($result) {
            self::RESULT_SENT => __('Thank you. Check your inbox: the newsletter starts only after you press the link in the email we sent.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            self::RESULT_INVALID => __('That does not look like an email address. Please check it.', 'eventcrew'),
            self::RESULT_CAPTCHA => __('The check that you are not a bot failed. Please try again.', 'eventcrew'),
            default => '',
        };

        $siteKey = $this->turnstile->isEnabled() ? $this->turnstile->siteKey() : '';
        $here = (string) get_permalink();

        $html = '<div class="eventcrew-newsletter" id="eventcrew-newsletter">';

        if ('' !== $message) {
            $html .= '<p class="eventcrew-newsletter-message" role="status">' . esc_html($message) . '</p>';
        }

        $html .= '<form method="post" action="' . esc_url(admin_url('admin-ajax.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::JOIN_ACTION) . '">'
            . '<input type="hidden" name="redirect_to" value="' . esc_attr($here) . '">'
            . '<p><label for="eventcrew-newsletter-email">'
            . esc_html__('Your email address', 'eventcrew') . '</label><br>'
            . '<input type="email" id="eventcrew-newsletter-email" name="email" required autocomplete="email"'
            . ' class="wp-element-input"></p>'
            // Hidden from people (off screen, skipped by the tab key and by
            // screen readers); a bot that fills every field fills this one too.
            . '<p style="position:absolute;left:-9999px" aria-hidden="true"><label>'
            . esc_html__('Leave this field empty', 'eventcrew')
            . ' <input type="text" name="' . esc_attr(self::HONEYPOT) . '" value="" tabindex="-1" autocomplete="off">'
            . '</label></p>';

        if ('' !== $siteKey) {
            $html .= '<div class="cf-turnstile" data-sitekey="' . esc_attr($siteKey) . '" data-theme="auto"></div>'
                // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- the widget script loads only where the form is.
                . '<script src="' . esc_url(Turnstile::SCRIPT_URL) . '" async defer></script>';
        }

        $html .= '<p><button type="submit" class="wp-element-button">'
            . esc_html__('Join the newsletter', 'eventcrew') . '</button></p>'
            . '<p class="eventcrew-muted">'
            . esc_html__('We send you an email to confirm. You can leave the newsletter at any time with the link in every newsletter.', 'eventcrew') // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            . '</p></form></div>';

        return $html;
    }

    /** The form's POST: records the sign-up and goes back to the page. */
    public function handleJoin(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- public form without a nonce; see the class comment.
        $honeypot = isset($_POST[self::HONEYPOT]) ? trim((string) wp_unslash($_POST[self::HONEYPOT])) : '';
        $email = isset($_POST['email']) ? sanitize_text_field(wp_unslash($_POST['email'])) : '';
        $token = isset($_POST[Turnstile::RESPONSE_FIELD])
            ? sanitize_text_field(wp_unslash($_POST[Turnstile::RESPONSE_FIELD]))
            : '';
        $requested = isset($_POST['redirect_to']) ? (string) wp_unslash($_POST['redirect_to']) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $result = $this->join($email, $honeypot, $token, $this->clientIp());
        $back = wp_validate_redirect($requested, home_url('/'));

        wp_safe_redirect(add_query_arg(self::RESULT_ARG, $result, $back) . '#eventcrew-newsletter');
        exit;
    }

    /**
     * A sign-up. Returns one of the RESULT_* answers.
     *
     * A filled-in honeypot answers RESULT_SENT and does nothing, so a bot
     * learns nothing. A confirmed address gets no email. A pending address
     * gets a new confirmation email only when the last one is more than an
     * hour old, so the form cannot be used to flood a mailbox.
     */
    public function join(string $email, string $honeypot, string $captchaToken, string $ip = ''): string
    {
        if ('' !== $honeypot) {
            return self::RESULT_SENT;
        }

        if (! $this->turnstile->verify($captchaToken, $ip)) {
            return self::RESULT_CAPTCHA;
        }

        $email = NewsletterSubscriberRepository::normalize($email);

        if ('' === $email || ! is_email($email) || strlen($email) > NewsletterSubscriberRepository::MAX_EMAIL_LENGTH) {
            return self::RESULT_INVALID;
        }

        $existing = $this->subscribers->findByEmail($email);

        if (null !== $existing) {
            if (NewsletterSubscriberRepository::STATUS_CONFIRMED === $existing['status']) {
                return self::RESULT_SENT;
            }

            if (
                NewsletterSubscriberRepository::STATUS_PENDING === $existing['status']
                && self::secondsSince($existing['created_at']) < self::RESEND_AFTER_SECONDS
            ) {
                return self::RESULT_SENT;
            }
        }

        $id = $this->subscribers->addPending($email);

        if ($id > 0) {
            $this->sendConfirmation($email, $id);
        }

        return self::RESULT_SENT;
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
        // Built from literal markup and esc_html()'d values in document().
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $page['html'];
        exit;
    }

    /**
     * What a signed link does and the page it answers with.
     *
     * @return array{status: int, html: string}
     */
    public function respond(string $do, string $method, string $token): array
    {
        $post = 'POST' === strtoupper($method);

        if ('confirm' === $do) {
            $id = SignedLink::verify(Newsletter::PURPOSE_CONFIRM, $token);

            if (null !== $id && $this->subscribers->confirm($id)) {
                return $this->page(
                    200,
                    static fn (): string => __('You are now subscribed to the newsletter. Thank you!', 'eventcrew')
                );
            }

            return $this->invalid();
        }

        if ('unsubscribe' === $do) {
            $id = SignedLink::verify(Newsletter::PURPOSE_UNSUBSCRIBE, $token);

            if (null !== $id && $this->subscribers->unsubscribe($id)) {
                return $this->page(
                    200,
                    static fn (): string => __(
                        'You have left the newsletter. You will not get it any more.',
                        'eventcrew'
                    )
                );
            }

            return $this->invalid();
        }

        if ('stop' === $do) {
            $planId = SignedLink::verify(Newsletter::PURPOSE_STOP, $token);

            if (null === $planId) {
                return $this->invalid();
            }

            if (! $post) {
                return $this->page(
                    200,
                    static fn (): string => __(
                        'Press the button to stop this newsletter. It will not be sent.',
                        'eventcrew'
                    ),
                    $this->stopForm($token)
                );
            }

            $answer = $this->newsletter->stop($planId);

            return $this->page(200, static fn (): string => match ($answer) {
                Newsletter::STOPPED => __('The newsletter is stopped. It will not be sent.', 'eventcrew'),
                Newsletter::TOO_LATE => __('Too late: the newsletter is already being sent.', 'eventcrew'),
                default => __('This newsletter was already stopped or sent.', 'eventcrew'),
            });
        }

        return $this->invalid();
    }

    private function sendConfirmation(string $email, int $id): void
    {
        $site = (string) get_bloginfo('name');
        $words = static fn (): array => [
            'subject' => __('Confirm your newsletter subscription', 'eventcrew'),
            'body' => sprintf(
                /* translators: %s: the site's name */
                __('Someone, probably you, asked for the %s newsletter at this email address. To start getting it, press the button below. If it was not you, you can ignore this email: the address is deleted in 7 days.', 'eventcrew'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
                $site
            ),
            'button' => __('Confirm', 'eventcrew'),
        ];
        $fi = Language::run(Language::FINNISH, $words);
        $en = Language::run(Language::ENGLISH, $words);
        $both = static fn (string $key): string => $fi[$key] === $en[$key] ? $en[$key] : $fi[$key] . ' / ' . $en[$key];

        $this->mailer->toUnconfirmedAddress(
            $email,
            $both('subject'),
            $fi['body'] . "\n\n" . $en['body'],
            [['label' => $both('button'), 'url' => Newsletter::confirmUrl($id)]]
        );
    }

    /** @return array{status: int, html: string} */
    private function invalid(): array
    {
        return $this->page(404, static fn (): string => __('This link is no longer valid.', 'eventcrew'));
    }

    /**
     * A page with $message in Finnish and in English, and $extra under it.
     *
     * @return array{status: int, html: string}
     */
    private function page(int $status, callable $message, string $extra = ''): array
    {
        $title = (string) Language::run(Language::FINNISH, static fn (): string => __('Newsletter', 'eventcrew'));
        $titleEn = (string) Language::run(Language::ENGLISH, static fn (): string => __('Newsletter', 'eventcrew'));
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
                esc_html($title === $titleEn ? $titleEn : $title . ' / ' . $titleEn),
                $inner
            ),
        ];
    }

    private function stopForm(string $token): string
    {
        $words = static fn (): string => __('Stop this newsletter', 'eventcrew');
        $label = (string) Language::run(Language::FINNISH, $words);
        $labelEn = (string) Language::run(Language::ENGLISH, $words);

        return sprintf(
            // phpcs:ignore Generic.Files.LineLength.TooLong -- one literal HTML form; wrapping it just adds stray whitespace.
            '<form method="post" style="margin:1em 0"><input type="hidden" name="do" value="stop"><input type="hidden" name="token" value="%s"><button type="submit" style="padding:.6em 1em;color:#b32d2e">%s</button></form>',
            esc_attr($token),
            esc_html($label === $labelEn ? $labelEn : $label . ' / ' . $labelEn)
        );
    }

    private static function secondsSince(string $mysqlDate): int
    {
        $then = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysqlDate);
        $now = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) current_time('mysql'));

        if (false === $then || false === $now) {
            return PHP_INT_MAX;
        }

        return $now->getTimestamp() - $then->getTimestamp();
    }

    /** REMOTE_ADDR only, as in SignupController: Turnstile treats it as advisory. */
    private function clientIp(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- read-only, not a state change.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : '';

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }
}

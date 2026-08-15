<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Cloudflare Turnstile: a privacy-friendly CAPTCHA guarding the public
 * email sign-in form against spam and abuse.
 *
 * The form mails a sign-in link to whatever address is typed, so without a
 * gate it is an open relay for sending mail to arbitrary addresses. Turnstile
 * makes each submission prove it came from a browser, not a script.
 *
 * The whole feature is opt-in: with no keys configured it is disabled and
 * verify() waves everything through, so an install that never sets it up is
 * never locked out of its own sign-in form.
 */
final class Turnstile
{
    public const SITE_KEY_OPTION = 'eventcrew_turnstile_site_key';
    public const SECRET_KEY_OPTION = 'eventcrew_turnstile_secret';

    /** The widget field the browser posts back, named by Cloudflare. */
    public const RESPONSE_FIELD = 'cf-turnstile-response';

    /** The implicit-render script; loading it turns any .cf-turnstile div live. */
    public const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(private readonly Logger $logger)
    {
    }

    public function siteKey(): string
    {
        return trim((string) get_option(self::SITE_KEY_OPTION, ''));
    }

    private function secret(): string
    {
        return trim((string) get_option(self::SECRET_KEY_OPTION, ''));
    }

    /**
     * Both keys are needed to gate anything: the site key renders the widget,
     * the secret verifies its token. With either missing the gate is off, so a
     * half-finished setup fails open rather than blocking every sign-in.
     */
    public function isEnabled(): bool
    {
        return '' !== $this->siteKey() && '' !== $this->secret();
    }

    /**
     * True when the submission may proceed. Disabled → always true. Enabled →
     * the token must be present and Cloudflare must confirm it. A network
     * failure talking to Cloudflare fails open (returns true) and is logged:
     * a transient outage on their side should not lock people out of signing
     * in, and the token itself is already single-use and short-lived.
     */
    public function verify(string $token, string $remoteIp = ''): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }

        if ('' === $token) {
            /*
             * Logged, not silent. This is the likeliest refusal of the lot -
             * the form posted before the widget had written anything into its
             * hidden field - and it was the one path that returned false
             * without a word, so an install hitting it showed nothing in
             * Diagnostics at all and looked like the check was never reached.
             * An empty Diagnostics was itself the evidence, and unreadable.
             */
            $this->logger->warning(
                // phpcs:ignore Generic.Files.LineLength.TooLong -- one log line; wrapping it just splits the message.
                'Turnstile refused a submission: the form arrived with no token, so the widget had not solved when it was sent.'
            );

            return false;
        }

        $body = [
            'secret' => $this->secret(),
            'response' => $token,
        ];

        if ('' !== $remoteIp) {
            $body['remoteip'] = $remoteIp;
        }

        $response = wp_remote_post(self::VERIFY_URL, [
            'timeout' => 10,
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            $this->logger->warning('Turnstile verification could not reach Cloudflare: ' . $response->get_error_message());

            return true;
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        if (is_array($decoded) && true === ($decoded['success'] ?? false)) {
            return true;
        }

        /*
         * Cloudflare says why it refused, and until now we threw that away -
         * which left "couldn't verify you're human" as the only evidence of a
         * refusal whose cause was in the response all along. The codes that
         * matter here are operational, not adversarial:
         *
         *   timeout-or-duplicate  the token is stale or already spent. A widget
         *                         solves on page load and its token lasts about
         *                         five minutes, so a page left open while
         *                         somebody reads it produces exactly this - the
         *                         widget still shows a tick, and the token
         *                         behind it is no longer good.
         *   invalid-input-secret  the secret key is wrong; every solve fails.
         *   invalid-input-response  malformed or foreign token.
         *
         * Logged rather than shown: the visitor can do nothing with a code, and
         * the organizer reading Diagnostics can.
         */
        $codes = is_array($decoded) && is_array($decoded['error-codes'] ?? null)
            ? implode(', ', array_map('strval', $decoded['error-codes']))
            : 'no reason given';

        $this->logger->warning('Turnstile refused a submission: ' . $codes);

        return false;
    }
}

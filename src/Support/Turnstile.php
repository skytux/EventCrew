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

        return is_array($decoded) && true === ($decoded['success'] ?? false);
    }
}

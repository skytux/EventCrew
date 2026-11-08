<?php

declare(strict_types=1);

namespace EventCrew\Support;

/**
 * Counts the clicks on the buttons of the emails EventCrew sends (Owner,
 * Q385), for the monthly report: each button's link goes through a short
 * counting link on the site, home_url('/?ecl=<token>'), which adds one to
 * the email's and the button's count (SiteStats) and sends the browser on
 * with a 302. Nothing about the person is kept: no cookie, no address, no
 * IP address, not even which message it was, only the email kind and the
 * button.
 *
 * The token is base64url("<email>|<button>|<target>") . '.' . the first 16
 * hex characters of an HMAC of it, keyed with the site's auth salt (as
 * SignedLink). A target on the site itself is written from its path, which
 * keeps the link short. Only a token whose signature checks out is followed,
 * so the counting link cannot be used to send someone to a site of a
 * stranger's choosing; a broken or changed token goes to the front page and
 * counts nothing.
 *
 * Links that sign someone in are never wrapped (isSignIn()): the one-time
 * sign-in, email change and door links (admin-ajax.php), the crew page's
 * eventcrew_login link, and the verify and manage pages. A ticket or a
 * takeover link (a signed, reusable token on a REST route) is wrapped: its
 * token stays inside the signed target, is not stored and is not written to
 * any log by this class; the browser lands on the same address it always
 * did.
 */
final class EmailClicks
{
    /** The query parameter of the counting link. */
    public const PARAM = 'ecl';

    /** The longest email or button key. */
    private const KEY_MAX = 32;

    /** The hex characters of the HMAC kept in a token. */
    private const SIGNATURE_LENGTH = 16;

    /** The REST routes whose links sign someone in or act for them. */
    private const SIGN_IN_ROUTES = ['/eventcrew/v1/verify', '/eventcrew/v1/manage'];

    /** Hooks the counting link: early, before WordPress looks up a page. */
    public static function boot(): void
    {
        add_action('init', [self::class, 'handle'], 1);
    }

    /**
     * $url as a counting link for $button of the email $mail, or $url as it
     * is when it is not wrapped: empty, not a web link, or a sign-in link.
     */
    public static function wrap(string $url, string $mail, string $button): string
    {
        $url = trim($url);
        $mail = self::key($mail);
        $button = self::key($button);

        if (
            '' === $url
            || '' === $mail
            || '' === $button
            || 1 !== preg_match('#^https?://#i', $url)
            || self::isWrapped($url)
            || self::isSignIn($url)
        ) {
            return $url;
        }

        $home = rtrim(home_url(''), '/');
        $target = str_starts_with($url, $home . '/') ? substr($url, strlen($home)) : $url;
        $body = rtrim(strtr(base64_encode($mail . '|' . $button . '|' . $target), '+/', '-_'), '=');

        return home_url('/?' . self::PARAM . '=' . $body . '.' . self::signature($body));
    }

    /**
     * $actions (EmailBody's buttons and links) with each link wrapped. An
     * action's own 'mail' and 'button' keys (MessageActions::email()) win;
     * else the email is $mail and the button comes from the link
     * (buttonKey()).
     *
     * @param array<int, array<string, mixed>> $actions
     * @return array<int, array<string, mixed>>
     */
    public static function actions(array $actions, string $mail): array
    {
        foreach ($actions as $i => $action) {
            $url = (string) ($action['url'] ?? '');

            if ('' === $url) {
                continue;
            }

            $button = (string) ($action['button'] ?? '');

            if (EmailBody::STYLE_TELEGRAM === ($action['style'] ?? '')) {
                $button = 'telegram';
            }

            $actions[$i]['url'] = self::wrap(
                $url,
                (string) ($action['mail'] ?? $mail),
                '' === $button ? self::buttonKey($url) : $button
            );
        }

        return $actions;
    }

    /**
     * What a counting link's token says, or null when it is broken or its
     * signature does not check out.
     *
     * @return array{mail: string, button: string, url: string}|null
     */
    public static function resolve(string $token): ?array
    {
        $token = trim($token);
        $dot = strrpos($token, '.');

        if (false === $dot || 0 === $dot) {
            return null;
        }

        $body = substr($token, 0, $dot);

        if (! hash_equals(self::signature($body), substr($token, $dot + 1))) {
            return null;
        }

        $payload = base64_decode(strtr($body, '-_', '+/'), true);
        $parts = false === $payload ? [] : explode('|', $payload, 3);

        if (3 !== count($parts) || '' === $parts[0] || '' === $parts[1] || '' === $parts[2]) {
            return null;
        }

        $target = $parts[2];

        if (str_starts_with($target, '/') && ! str_starts_with($target, '//')) {
            $target = rtrim(home_url(''), '/') . $target;
        } elseif (1 !== preg_match('#^https?://#i', $target)) {
            return null;
        }

        return ['mail' => $parts[0], 'button' => $parts[1], 'url' => $target];
    }

    /**
     * The init hook: a request with PARAM counts the click (a GET from a
     * browser, not a link checker), then goes on to the signed target and
     * ends. A broken token goes to the front page.
     */
    public static function handle(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a signed link, not a form.
        $raw = $_GET[self::PARAM] ?? null;

        if (! is_string($raw) || '' === $raw) {
            return;
        }

        $link = self::resolve(sanitize_text_field(wp_unslash($raw)));

        // Before any redirect: the page cache (Surge) keeps 301 and 302
        // answers unless they say Cache-Control: no-cache, and a kept one
        // would stop counting.
        nocache_headers();

        if (null === $link) {
            wp_safe_redirect(home_url('/'), 302);
            exit;
        }

        if (self::countable()) {
            SiteStats::addClick($link['mail'], $link['button']);
        }

        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- only a target this site signed.
        wp_redirect($link['url'], 302, 'EventCrew');
        exit;
    }

    /**
     * Whether $url signs someone in or acts for them, and so stays as it is.
     */
    public static function isSignIn(string $url): bool
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain PHP, as FollowupMail.
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return true;
        }

        $path = (string) ($parts['path'] ?? '');
        parse_str((string) ($parts['query'] ?? ''), $query);

        if (
            str_ends_with($path, 'admin-ajax.php')
            || str_contains($path, '/wp-admin/')
            || isset($query['eventcrew_login'])
        ) {
            return true;
        }

        $route = $path . (is_string($query['rest_route'] ?? null) ? $query['rest_route'] : '');

        foreach (self::SIGN_IN_ROUTES as $signIn) {
            if (str_contains($route, $signIn)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A button key from a link, for the actions that bring none: the last
     * part of an EventCrew REST route (ticket, calendar, takeover...),
     * 'telegram' for t.me, the page's last path part on the site, 'home'
     * for the front page, else 'link'.
     */
    public static function buttonKey(string $url): string
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain PHP, as FollowupMail.
        $parts = parse_url($url);
        $parts = is_array($parts) ? $parts : [];
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = trim((string) ($parts['path'] ?? ''), '/');
        parse_str((string) ($parts['query'] ?? ''), $query);
        $route = is_string($query['rest_route'] ?? null) ? trim($query['rest_route'], '/') : $path;

        if ('t.me' === $host || 'telegram.me' === $host) {
            return 'telegram';
        }

        if (1 === preg_match('#eventcrew/v1/([a-z0-9_-]+)#', $route, $match)) {
            return self::key($match[1]);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
        $homeHost = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));

        if ('' !== $host && $host !== $homeHost) {
            return 'link';
        }

        $last = '' === $path ? '' : self::key((string) basename($path));

        return '' === $last ? 'home' : $last;
    }

    /**
     * A key as kept: lower case a-z, 0-9 and _, without a long run of digits
     * (a date or an id: night_20261114 is night), at most KEY_MAX characters.
     */
    public static function key(string $raw): string
    {
        $key = (string) preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($raw)));
        $key = (string) preg_replace('/_?\d{4,}/', '', $key);

        return substr(trim($key, '_'), 0, self::KEY_MAX);
    }

    /** Whether $url is a counting link already. */
    private static function isWrapped(string $url): bool
    {
        return str_contains($url, '?' . self::PARAM . '=') || str_contains($url, '&' . self::PARAM . '=');
    }

    /** A GET from a browser: a HEAD, a prefetch or a link checker counts nothing. */
    private static function countable(): bool
    {
        $method = strtoupper(sanitize_text_field(wp_unslash((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))));
        $agent = sanitize_text_field(wp_unslash((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));

        return 'GET' === $method && ! self::isPrefetch() && ! SiteStats::isBot($agent);
    }

    /** Whether the browser is only fetching ahead (Sec-Purpose, Purpose, X-Moz). */
    public static function isPrefetch(): bool
    {
        foreach (['HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ'] as $header) {
            $value = strtolower((string) ($_SERVER[$header] ?? ''));

            if (str_contains($value, 'prefetch') || str_contains($value, 'preview')) {
                return true;
            }
        }

        return false;
    }

    private static function signature(string $body): string
    {
        $key = function_exists('wp_salt') ? wp_salt('auth') : 'eventcrew-test-key';

        return substr(hash_hmac('sha256', self::PARAM . '|' . $body, $key), 0, self::SIGNATURE_LENGTH);
    }
}

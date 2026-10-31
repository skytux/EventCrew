<?php

declare(strict_types=1);

namespace EventCrew\Telegram;

use EventCrew\Support\Logger;

/**
 * Resolves a hostname to an IPv4 address over DNS-over-HTTPS, for hosts whose
 * own resolver cannot reach the outside world.
 *
 * Some shared hosts allow outbound HTTPS but block or break DNS, so
 * `api.telegram.org` fails at the lookup with "could not resolve host" while
 * the network to Telegram is otherwise fine. This asks Cloudflare's resolver
 * over HTTPS instead - and because 1.1.1.1 / 1.0.0.1 are literal IPs, reaching
 * them needs no DNS in the first place, which is the whole trick. The answer is
 * cached for an hour so a lookup happens at most once per hour, not per call.
 *
 * It is opt-in (a Settings toggle): on a normal host the system resolver is
 * faster and correct, and routing lookups through a third party is not
 * something to do unless the alternative is not working at all.
 */
final class DohResolver
{
    /** Cloudflare's DoH endpoints, reached by literal IP so no DNS is needed. */
    private const ENDPOINTS = [
        'https://1.1.1.1/dns-query',
        'https://1.0.0.1/dns-query',
    ];

    private const CACHE_PREFIX = 'eventcrew_doh_';

    public function __construct(
        private readonly Logger $logger
    ) {
    }

    /**
     * The host's IPv4 address, from cache or a fresh DoH lookup, or null when
     * every endpoint failed.
     */
    public function resolve(string $host): ?string
    {
        $key = self::CACHE_PREFIX . preg_replace('/[^a-z0-9]/i', '_', $host);
        $cached = get_transient($key);

        if (is_string($cached) && '' !== $cached) {
            return $cached;
        }

        foreach (self::ENDPOINTS as $endpoint) {
            $ip = $this->query($endpoint, $host);

            if (null !== $ip) {
                set_transient($key, $ip, HOUR_IN_SECONDS);

                return $ip;
            }
        }

        $this->logger->warning(sprintf('DoH could not resolve %s via Cloudflare.', $host));

        return null;
    }

    private function query(string $endpoint, string $host): ?string
    {
        $response = wp_remote_get(
            add_query_arg(['name' => $host, 'type' => 'A'], $endpoint),
            [
                'timeout' => 10,
                'headers' => ['Accept' => 'application/dns-json'],
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        if (! is_array($data) || empty($data['Answer']) || ! is_array($data['Answer'])) {
            return null;
        }

        foreach ($data['Answer'] as $answer) {
            // Type 1 is an A record; CNAMEs and anything else are skipped.
            if (! is_array($answer) || 1 !== (int) ($answer['type'] ?? 0)) {
                continue;
            }

            $ip = (string) ($answer['data'] ?? '');

            if (false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        }

        return null;
    }
}

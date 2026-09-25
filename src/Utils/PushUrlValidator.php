<?php

declare(strict_types=1);

namespace A2A\Utils;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Screens client-supplied push-notification URLs (SSRF guard).
 *
 * A URL passes only when its scheme is http/https and EVERY address its host
 * resolves to is a public unicast address. Loopback, private, link-local,
 * unique-local, multicast, reserved, documentation and unspecified addresses
 * are rejected (e.g. 169.254.169.254 cloud metadata). A host that cannot be
 * resolved is rejected too: fail closed.
 *
 * IPv4-mapped (::ffff:a.b.c.d), NAT64 (64:ff9b::/96) and 6to4 (2002::/16)
 * IPv6 addresses are judged by the IPv4 address they embed.
 *
 * Differences from Python's ipaddress-based check, both deliberate and both
 * stricter: 100.64.0.0/10 (carrier-grade NAT) is blocked, and IPv6 outside
 * global unicast 2000::/3 is blocked outright.
 *
 * Mirrors a2a-python: validate_push_notification_url() in
 * src/a2a/utils/push_url_validator.py. Usable as a callable.
 */
final class PushUrlValidator
{
    /** Blocked IPv4 ranges: [network, prefix length]. */
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8],        // "this network", unspecified
        ['10.0.0.0', 8],       // private
        ['100.64.0.0', 10],    // carrier-grade NAT (stricter than Python)
        ['127.0.0.0', 8],      // loopback
        ['169.254.0.0', 16],   // link-local, cloud metadata
        ['172.16.0.0', 12],    // private
        ['192.0.0.0', 24],     // IETF protocol assignments
        ['192.0.2.0', 24],     // documentation
        ['192.168.0.0', 16],   // private
        ['198.18.0.0', 15],    // benchmarking
        ['198.51.100.0', 24],  // documentation
        ['203.0.113.0', 24],   // documentation
        ['224.0.0.0', 4],      // multicast
        ['240.0.0.0', 4],      // reserved, broadcast
    ];

    /** Blocked ranges inside 2000::/3. Everything outside 2000::/3 is blocked. */
    private const BLOCKED_V6 = [
        ['2001::', 23],        // IETF protocol assignments (Teredo, ORCHID, ...)
        ['2001:db8::', 32],    // documentation
    ];

    /** @var \Closure(string): list<string> */
    private readonly \Closure $resolver;

    /**
     * @param (callable(string): list<string>)|null $resolver maps a host name to its IP addresses;
     *                                                        throw or return [] when it does not resolve
     */
    public function __construct(
        ?callable $resolver = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->resolver = $resolver !== null ? \Closure::fromCallable($resolver) : self::systemResolver(...);
    }

    public function __invoke(string $url): bool
    {
        return $this->validate($url);
    }

    /**
     * True when the URL is safe to POST a push notification to.
     */
    public function validate(string $url): bool
    {
        // parse_url() returns false for out-of-range ports such as :99999.
        $parts = parse_url($url);
        if ($parts === false) {
            $this->logger->warning('Push-notification URL is unparseable: {url}', ['url' => $url]);

            return false;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            $this->logger->warning('Push-notification URL scheme {scheme} is not http/https: {url}', ['scheme' => $scheme, 'url' => $url]);

            return false;
        }
        $host = trim($parts['host'] ?? '', '[]');
        if ($host === '') {
            $this->logger->warning('Push-notification URL has no hostname: {url}', ['url' => $url]);

            return false;
        }

        try {
            $addresses = self::isIp($host) ? [$host] : ($this->resolver)($host);
        } catch (\Throwable) {
            $addresses = [];
        }
        if ($addresses === []) {
            $this->logger->warning('Push-notification host {host} could not be resolved: {url}', ['host' => $host, 'url' => $url]);

            return false;
        }

        foreach ($addresses as $address) {
            if (self::isBlocked($address)) {
                $this->logger->warning('Push-notification host {host} resolves to a non-public address: {url}', ['host' => $host, 'url' => $url]);

                return false;
            }
        }

        return true;
    }

    /**
     * Whether an address is not a public unicast destination. Anything that
     * does not parse as an IP address counts as blocked.
     */
    public static function isBlocked(string $address): bool
    {
        $address = explode('%', $address, 2)[0]; // drop an IPv6 zone id
        $packed = @inet_pton($address);
        if ($packed === false) {
            return true;
        }

        if (strlen($packed) === 4) {
            return self::inAnyRange($packed, self::BLOCKED_V4);
        }

        // IPv6 with an embedded IPv4 address: judge the IPv4 address.
        $embedded = null;
        if (substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $embedded = substr($packed, 12, 4); // ::ffff:a.b.c.d
        } elseif (substr($packed, 0, 12) === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) {
            $embedded = substr($packed, 12, 4); // 64:ff9b::a.b.c.d (NAT64)
        } elseif (substr($packed, 0, 2) === "\x20\x02") {
            $embedded = substr($packed, 2, 4);  // 2002:aabb:ccdd:: (6to4)
        }
        if ($embedded !== null) {
            return self::inAnyRange($embedded, self::BLOCKED_V4);
        }

        // Only global unicast (2000::/3) can be public.
        if ((ord($packed[0]) & 0xE0) !== 0x20) {
            return true;
        }

        return self::inAnyRange($packed, self::BLOCKED_V6);
    }

    /**
     * Default resolver: IPv4 through the system resolver (honours /etc/hosts)
     * plus AAAA records. PHP has no getaddrinfo(), so this is the closest
     * equivalent to the loop.getaddrinfo() call in Python.
     *
     * @return list<string>
     */
    public static function systemResolver(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];
        $records = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($records as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    private static function isIp(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * @param list<array{string, int}> $ranges
     */
    private static function inAnyRange(string $packed, array $ranges): bool
    {
        foreach ($ranges as [$network, $prefix]) {
            $networkPacked = (string) inet_pton($network);
            if (strlen($networkPacked) !== strlen($packed)) {
                continue;
            }
            $fullBytes = intdiv($prefix, 8);
            if (substr($packed, 0, $fullBytes) !== substr($networkPacked, 0, $fullBytes)) {
                continue;
            }
            $remainingBits = $prefix % 8;
            if ($remainingBits === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
            if ((ord($packed[$fullBytes]) & $mask) === (ord($networkPacked[$fullBytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}

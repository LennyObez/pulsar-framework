<?php

declare(strict_types=1);

namespace Pulsar\Security\Validation;

use NoDiscard;
use Pulsar\Api\Api;

use function filter_var;
use function inet_pton;
use function ip2long;
use function parse_url;
use function str_contains;
use function strlen;
use function substr;
use function unpack;

use const FILTER_VALIDATE_IP;

/**
 * Validates URL *host literals* against private/reserved IP ranges.
 *
 * Rejects URLs whose host is an IP literal in an RFC 1918 private range,
 * loopback, link-local, current-network range, or a known cloud-metadata
 * endpoint (the latter even when {@see self::validate()} is called with
 * `$allowPrivateNetworks = true`).
 *
 * IMPORTANT — this is NOT complete SSRF protection. The host is only checked
 * when it is an IP literal; a *hostname* (e.g. `internal.example.com`) is NOT
 * resolved here, so a name that resolves to a private address passes this
 * check. Even if resolution were performed, it would not defend against DNS
 * rebinding, where a name validated as public rebinds to a private address
 * before the request is made. Callers that fetch user-controlled URLs MUST
 * additionally resolve-and-pin the host at fetch time and re-check the pinned
 * IP against these ranges. Treat this validator as one defence-in-depth layer,
 * not a sufficient SSRF guard on its own.
 * @api
 */
#[Api(since: '1.0.0')]
final class UrlSafetyValidator
{
    /**
     * IPv4 ranges that are considered private/reserved.
     * Format: [network, netmask_long]
     *
     * @var list<array{int, int}>
     */
    private const array PRIVATE_IPV4_RANGES = [
        // 10.0.0.0/8: RFC 1918
        [0x0A000000, 0xFF000000],
        // 172.16.0.0/12: RFC 1918
        [0xAC100000, 0xFFF00000],
        // 192.168.0.0/16: RFC 1918
        [0xC0A80000, 0xFFFF0000],
        // 127.0.0.0/8: Loopback
        [0x7F000000, 0xFF000000],
        // 169.254.0.0/16: Link-local
        [0xA9FE0000, 0xFFFF0000],
        // 0.0.0.0/8: Current network
        [0x00000000, 0xFF000000],
    ];

    /**
     * Specific IPs that are always blocked (cloud metadata endpoints).
     *
     * @var list<string>
     */
    private const array BLOCKED_IPS = [
        '169.254.169.254', // AWS/GCP/Azure metadata
        '169.254.170.2',   // AWS ECS metadata
    ];

    /**
     * Validate that a URL is safe to access (not pointing to internal/private networks).
     *
     * @param string    $url                 The URL to validate
     * @param bool      $allowPrivateNetworks Set to true to allow private IPs (e.g. internal health checks)
     *
     * @return UrlValidationResult
     */
    #[NoDiscard]
    public static function validate(string $url, bool $allowPrivateNetworks = false): UrlValidationResult
    {
        $parsed = parse_url($url);

        if ($parsed === false || !isset($parsed['host'])) {
            return UrlValidationResult::rejected('Invalid URL: cannot parse host');
        }

        $host = $parsed['host'];
        $scheme = $parsed['scheme'] ?? '';

        // Only allow http/https schemes
        if ($scheme !== 'http' && $scheme !== 'https') {
            return UrlValidationResult::rejected('Invalid URL scheme: only http and https are allowed');
        }

        // Check for cloud metadata IPs: always blocked, even with allowPrivateNetworks
        if (self::isBlockedIp($host)) {
            return UrlValidationResult::rejected('URL points to a blocked address (cloud metadata endpoint)');
        }

        if ($allowPrivateNetworks) {
            return UrlValidationResult::allowed();
        }

        // Validate the host literal is not a private/reserved IP. Hostnames
        // are not resolved here — see the class docblock for the SSRF caveat.
        if (self::isPrivateIpv4($host)) {
            return UrlValidationResult::rejected('URL host is a private IPv4 address');
        }

        if (self::isPrivateIpv6($host)) {
            return UrlValidationResult::rejected('URL host is a private IPv6 address');
        }

        return UrlValidationResult::allowed();
    }

    /**
     * Check if the host is in the always-blocked list (cloud metadata).
     */
    private static function isBlockedIp(string $host): bool
    {
        foreach (self::BLOCKED_IPS as $blocked) {
            if ($host === $blocked) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the host is a private/reserved IPv4 address.
     */
    private static function isPrivateIpv4(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $ipLong = ip2long($host);

        if ($ipLong === false) {
            return false;
        }

        // Convert to unsigned 32-bit
        $ipUnsigned = $ipLong & 0xFFFFFFFF;

        foreach (self::PRIVATE_IPV4_RANGES as [$network, $mask]) {
            if (($ipUnsigned & $mask) === $network) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the host is a private/reserved IPv6 address.
     */
    private static function isPrivateIpv6(string $host): bool
    {
        // Strip brackets from IPv6 literals in URLs
        if (str_contains($host, '[')) {
            $host = substr($host, 1, -1);
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }

        // Loopback
        if ($host === '::1') {
            return true;
        }

        $packed = inet_pton($host);

        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }

        /** @var array{1: int} $firstByte */
        $firstByte = unpack('C', $packed[0]);
        $byte = $firstByte[1];

        // fc00::/7: unique local addresses (fc or fd)
        if ($byte === 0xFC || $byte === 0xFD) {
            return true;
        }

        // fe80::/10: link-local
        /** @var array{1: int} $secondByte */
        $secondByte = unpack('C', $packed[1]);

        if ($byte === 0xFE && ($secondByte[1] & 0xC0) === 0x80) {
            return true;
        }

        return false;
    }
}

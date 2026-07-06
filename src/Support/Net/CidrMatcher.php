<?php

declare(strict_types=1);

namespace Pulsar\Support\Net;

use Pulsar\Api\Api;

use function array_any;
use function explode;
use function inet_pton;
use function ip2long;
use function str_contains;
use function substr;
use function unpack;

/**
 * Matches an IP address against CIDR ranges (IPv4 and IPv6) or a bare address.
 *
 * A malformed address or range never matches (fail closed). Used wherever the
 * framework checks an address against a set of networks — trusted proxies,
 * crawler identity ranges, datacenter-IP risk signals.
 * @api
 */
#[Api(since: '1.0.0')]
final class CidrMatcher
{
    /**
     * @param list<string> $ranges IPs or CIDR ranges
     */
    public static function matchesAny(string $ip, array $ranges): bool
    {
        return array_any($ranges, static fn(string $range): bool => self::matches($ip, $range));
    }

    public static function matches(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        $parts = explode('/', $cidr, 2);
        $network = $parts[0];
        $prefix = isset($parts[1]) ? (int) $parts[1] : 32;

        if ($prefix < 0) {
            return false;
        }

        if (str_contains($ip, ':') || str_contains($network, ':')) {
            return self::ipv6Matches($ip, $network, $prefix);
        }

        return self::ipv4Matches($ip, $network, $prefix);
    }

    private static function ipv4Matches(string $ip, string $network, int $prefix): bool
    {
        if ($prefix > 32) {
            return false;
        }

        $ipLong = ip2long($ip);
        $networkLong = ip2long($network);

        if ($ipLong === false || $networkLong === false) {
            return false;
        }

        if ($prefix === 0) {
            return true;
        }

        $mask = -1 << (32 - $prefix);

        return ($ipLong & $mask) === ($networkLong & $mask);
    }

    private static function ipv6Matches(string $ip, string $network, int $prefix): bool
    {
        if ($prefix > 128) {
            return false;
        }

        $ipBin = inet_pton($ip);
        $networkBin = inet_pton($network);

        if ($ipBin === false || $networkBin === false) {
            return false;
        }

        $fullBytes = (int) ($prefix / 8);
        $remainingBits = $prefix % 8;

        if (substr($ipBin, 0, $fullBytes) !== substr($networkBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits > 0 && $fullBytes < 16) {
            /** @var array{byte: int} $ipByte */
            $ipByte = unpack('Cbyte', $ipBin[$fullBytes]);
            /** @var array{byte: int} $netByte */
            $netByte = unpack('Cbyte', $networkBin[$fullBytes]);
            $mask = 0xFF << (8 - $remainingBits) & 0xFF;

            return ($ipByte['byte'] & $mask) === ($netByte['byte'] & $mask);
        }

        return true;
    }
}

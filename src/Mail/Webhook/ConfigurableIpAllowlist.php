<?php

declare(strict_types=1);

namespace Pulsar\Mail\Webhook;

use Pulsar\Api\Internal;

use function explode;
use function inet_pton;
use function ip2long;
use function str_contains;
use function strtolower;
use function substr;
use function unpack;

/**
 * Configurable per-provider IP allowlist for webhook source verification.
 *
 * Supports both individual IPs and CIDR ranges (IPv4 and IPv6).
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final readonly class ConfigurableIpAllowlist implements IpAllowlistInterface
{
    /**
     * @param array<string, list<string>> $allowedRanges Provider name => list of IPs/CIDRs
     */
    public function __construct(
        private array $allowedRanges = [],
    ) {}

    public function isAllowed(string $ip, string $provider): bool
    {
        $normalizedProvider = strtolower($provider);
        $ranges = $this->allowedRanges[$normalizedProvider] ?? [];

        if ($ranges === []) {
            return true;
        }

        return array_any($ranges, static fn(string $range): bool => self::ipMatchesCidr($ip, $range));
    }

    private static function ipMatchesCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        $parts = explode('/', $cidr, 2);
        $network = $parts[0];
        $prefix = isset($parts[1]) ? (int) $parts[1] : 32;

        if (str_contains($ip, ':')) {
            return self::ipv6MatchesCidr($ip, $network, $prefix);
        }

        return self::ipv4MatchesCidr($ip, $network, $prefix);
    }

    private static function ipv4MatchesCidr(string $ip, string $network, int $prefix): bool
    {
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

    private static function ipv6MatchesCidr(string $ip, string $network, int $prefix): bool
    {
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

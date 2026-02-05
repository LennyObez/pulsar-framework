<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Security;

use Pulsar\Api\Internal;

use function explode;
use function inet_pton;
use function str_contains;
use function strlen;
use function unpack;

/**
 * Checks IP addresses against CIDR allowlists.
 */
#[Internal]
final readonly class AllowlistChecker
{
    /** @var list<string> */
    private array $cidrs;

    /**
     * @param list<string> $cidrs CIDR ranges (e.g., '127.0.0.1/8', '::1/128')
     */
    public function __construct(array $cidrs)
    {
        $this->cidrs = $cidrs;
    }

    /**
     * Check if an IP address is allowed by the CIDR list.
     */
    public function isAllowed(string $ip): bool
    {
        if ($this->cidrs === []) {
            return true;
        }

        return array_any($this->cidrs, fn(string $cidr): bool => $this->matchesCidr($ip, $cidr));
    }

    private function matchesCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        $parts = explode('/', $cidr, 2);
        $subnet = $parts[0];
        $bits = $parts[1] ?? '32';
        $bitsInt = (int) $bits;

        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $ipBytes = unpack('C*', $ipBin);
        $subnetBytes = unpack('C*', $subnetBin);

        if ($ipBytes === false || $subnetBytes === false) {
            return false;
        }

        /** @var array<int, int> $ipByteValues */
        $ipByteValues = $ipBytes;
        /** @var array<int, int> $subnetByteValues */
        $subnetByteValues = $subnetBytes;

        $fullBytes = (int) ($bitsInt / 8);
        $remainingBits = $bitsInt % 8;

        for ($i = 1; $i <= $fullBytes; $i++) {
            if ($ipByteValues[$i] !== $subnetByteValues[$i]) {
                return false;
            }
        }

        if ($remainingBits > 0 && isset($ipByteValues[$fullBytes + 1], $subnetByteValues[$fullBytes + 1])) {
            $mask = 0xFF << (8 - $remainingBits) & 0xFF;
            if (($ipByteValues[$fullBytes + 1] & $mask) !== ($subnetByteValues[$fullBytes + 1] & $mask)) {
                return false;
            }
        }

        return true;
    }
}

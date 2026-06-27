<?php

declare(strict_types=1);

namespace Pulsar\Security\Session\Validator;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\TrustedProxy;
use Pulsar\Security\Session\SessionMetadata;

use function chr;
use function inet_pton;
use function is_string;
use function str_repeat;
use function strlen;
use function substr;

/**
 * Validates that the request IP address matches the stored session metadata.
 *
 * Supports strict (exact match) and subnet (network-level match) modes.
 */
#[Internal]
final readonly class RemoteAddressValidator implements SessionValidatorInterface
{
    public function __construct(
        private string $mode = 'subnet',
        private int $ipv4Mask = 24,
        private int $ipv6Mask = 48,
        private ?TrustedProxy $trustedProxy = null,
    ) {}

    #[Override]
    public function validate(SessionMetadata $metadata, ServerRequestInterface $request): bool
    {
        $storedIp = $metadata->ipAddress;
        $currentIp = $this->resolveClientIp($request);

        if ($this->mode === 'strict') {
            return $storedIp === $currentIp;
        }

        return $this->matchSubnet($storedIp, $currentIp);
    }

    #[Override]
    public function getName(): string
    {
        return 'remote_address';
    }

    /**
     * Resolve the request's client IP the same way the session captured it, so
     * a trusted-proxy deployment compares real client IPs rather than the
     * constant proxy address (and a non-proxy deployment is unchanged).
     */
    private function resolveClientIp(ServerRequestInterface $request): string
    {
        if ($this->trustedProxy !== null) {
            return $this->trustedProxy->resolveClientIp($request);
        }

        /** @var mixed $remoteAddr */
        $remoteAddr = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        return is_string($remoteAddr) ? $remoteAddr : '';
    }

    private function matchSubnet(string $ip1, string $ip2): bool
    {
        $packed1 = inet_pton($ip1);
        $packed2 = inet_pton($ip2);

        if ($packed1 === false || $packed2 === false) {
            return false;
        }

        $length1 = strlen($packed1);
        $length2 = strlen($packed2);

        if ($length1 !== $length2) {
            return false;
        }

        $prefixBits = $length1 === 4 ? $this->ipv4Mask : $this->ipv6Mask;
        $mask = $this->buildMask($length1, $prefixBits);

        return ($packed1 & $mask) === ($packed2 & $mask);
    }

    private function buildMask(int $byteLength, int $prefixBits): string
    {
        $totalBits = $byteLength * 8;
        $prefixBits = min($prefixBits, $totalBits);

        $fullBytes = (int) ($prefixBits / 8);
        $remainingBits = $prefixBits % 8;
        $trailingBytes = $byteLength - $fullBytes - ($remainingBits > 0 ? 1 : 0);

        $mask = str_repeat("\xff", $fullBytes);

        if ($remainingBits > 0) {
            $mask .= chr(0xff << (8 - $remainingBits) & 0xff);
        }

        if ($trailingBytes > 0) {
            $mask .= str_repeat("\x00", $trailingBytes);
        }

        return substr($mask, 0, $byteLength);
    }
}

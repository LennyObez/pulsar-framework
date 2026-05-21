<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Security;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\CmsSecurityConfig;
use Pulsar\Extension\Cms\Security\ClientFingerprint;
use Pulsar\Security\Crypto\Hmac;

use function array_map;
use function chr;
use function count;
use function explode;
use function filter_var;
use function in_array;
use function inet_pton;
use function is_string;
use function ord;
use function substr;
use function trim;

use const FILTER_FLAG_IPV6;
use const FILTER_VALIDATE_IP;

/**
 * Resolves client fingerprints from PSR-7 server requests.
 *
 * IP resolution order:
 * 1. Trusted proxy check: only trust forwarded headers from configured proxies
 * 2. CF-Connecting-IP (when Cloudflare mode is enabled)
 * 3. X-Real-IP header
 * 4. X-Forwarded-For header (right-to-left, first untrusted IP)
 * 5. REMOTE_ADDR fallback
 *
 * All hashes use BLAKE2b (keyed) via Pulsar's Hmac class.
 */
#[Internal(reason: 'CMS security internals; use via service binding')]
/**
 * @psalm-api Resolved from the DI container by middleware that derives the
 *            client fingerprint; not instantiated by name.
 */
final readonly class ClientFingerprintResolver
{
    public function __construct(
        private CmsSecurityConfig $config,
        private string $hmacKey,
    ) {}

    /**
     * Resolve a client fingerprint from the request.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function resolve(ServerRequestInterface $request): ClientFingerprint
    {
        $ip = $this->resolveIpAddress($request);
        $userAgent = $request->getHeaderLine('User-Agent');

        $ipHash = Hmac::computeHex($ip, $this->hmacKey);
        $userAgentHash = Hmac::computeHex($userAgent, $this->hmacKey);
        $compositeHash = Hmac::computeHex($ip . '|' . $userAgent, $this->hmacKey);

        return new ClientFingerprint(
            ipAddress: $ip,
            ipHash: $ipHash,
            userAgentHash: $userAgentHash,
            compositeHash: $compositeHash,
        );
    }

    /**
     * Resolve the client's IP address from the request using trusted proxy headers.
     */
    private function resolveIpAddress(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        /** @var mixed $rawRemoteAddr */
        $rawRemoteAddr = $serverParams['REMOTE_ADDR'] ?? null;
        $remoteAddr = is_string($rawRemoteAddr) ? $rawRemoteAddr : '127.0.0.1';

        // Only trust forwarded headers if the request comes from a trusted proxy
        if (!$this->isTrustedProxy($remoteAddr)) {
            return $this->normalizeIp($remoteAddr);
        }

        // Priority 1: CF-Connecting-IP (Cloudflare mode)
        if ($this->config->cloudflareMode) {
            $cfIp = $request->getHeaderLine('CF-Connecting-IP');

            if ($cfIp !== '') {
                return $this->normalizeIp(trim($cfIp));
            }
        }

        // Priority 2: X-Real-IP
        $realIp = $request->getHeaderLine($this->config->realIpHeader);

        if ($realIp !== '') {
            return $this->normalizeIp(trim($realIp));
        }

        // Priority 3: X-Forwarded-For (right-to-left, first untrusted IP)
        $forwardedFor = $request->getHeaderLine($this->config->forwardedForHeader);

        if ($forwardedFor !== '') {
            $ips = array_map(trim(...), explode(',', $forwardedFor));

            // Walk right-to-left: rightmost IPs are closest to server (most trusted)
            // Find the first IP that is NOT a trusted proxy
            for ($i = count($ips) - 1; $i >= 0; $i--) {
                if (!$this->isTrustedProxy($ips[$i])) {
                    return $this->normalizeIp($ips[$i]);
                }
            }

            // All IPs are trusted proxies: use the leftmost (original client)
            if ($ips !== []) {
                return $this->normalizeIp($ips[0]);
            }
        }

        return $this->normalizeIp($remoteAddr);
    }

    /**
     * Check if an IP address is a trusted proxy.
     */
    private function isTrustedProxy(string $ip): bool
    {
        return in_array(trim($ip), $this->config->trustedProxies, true);
    }

    /**
     * Normalize an IP address.
     *
     * For IPv6: apply subnet masking per config (default /64) to group
     * addresses from the same subnet.
     */
    private function normalizeIp(string $ip): string
    {
        // For IPv6, apply subnet masking
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $this->normalizeIpv6($ip);
        }

        return $ip;
    }

    /**
     * Normalize an IPv6 address by masking to the configured subnet.
     */
    private function normalizeIpv6(string $ip): string
    {
        $binary = inet_pton($ip);

        if ($binary === false) {
            return $ip;
        }

        $maskBits = $this->config->ipv6SubnetMask;
        $fullBytes = intdiv($maskBits, 8);
        $remainingBits = $maskBits % 8;

        // Zero out bits beyond the mask
        $masked = substr($binary, 0, $fullBytes);

        if ($remainingBits > 0 && $fullBytes < 16) {
            $mask = 0xFF << (8 - $remainingBits);
            $masked .= chr((ord($binary[$fullBytes]) & $mask) & 0xFF);
            $fullBytes++;
        }

        // Zero-fill remaining bytes
        for ($i = $fullBytes; $i < 16; $i++) {
            $masked .= "\0";
        }

        $result = inet_ntop($masked);

        return $result !== false ? $result : $ip;
    }
}

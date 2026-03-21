<?php

declare(strict_types=1);

namespace Pulsar\Http;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

use function array_any;
use function array_map;
use function count;
use function explode;
use function filter_var;
use function inet_pton;
use function ip2long;
use function is_string;
use function str_contains;
use function substr;
use function trim;
use function unpack;

use const FILTER_FLAG_IPV6;
use const FILTER_VALIDATE_IP;

/**
 * Resolves the real client IP address behind trusted reverse proxies.
 *
 * When the request arrives through a trusted proxy, reads X-Forwarded-For
 * and walks right-to-left to find the first untrusted (client) IP.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TrustedProxy
{
    /**
     * @param list<string> $trustedCidrs CIDR ranges of trusted proxy networks
     */
    public function __construct(
        private array $trustedCidrs = ['127.0.0.1/32', '::1/128'],
    ) {}

    /**
     * Resolve the real client IP from the request.
     *
     * If REMOTE_ADDR is a trusted proxy, walks X-Forwarded-For right-to-left
     * and returns the first untrusted IP. Otherwise returns REMOTE_ADDR.
     *
     * Every candidate is validated against `FILTER_VALIDATE_IP` before
     * being returned, so downstream consumers (rate limiters, audit
     * loggers) cannot be poisoned with malformed values like
     * `"); DROP TABLE"` or huge user-supplied strings injected into
     * `X-Forwarded-For` (MED-1 / CWE-20).
     */
    public function resolveClientIp(ServerRequestInterface $request): string
    {
        /** @var mixed $remoteAddrRaw */
        $remoteAddrRaw = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        // Missing / non-string REMOTE_ADDR: fall back to loopback so the
        // (typically test-only) caller still gets a workable IP. A string
        // value is taken at face value; only the trust check below decides
        // whether to consult `X-Forwarded-For`.
        $remoteAddr = is_string($remoteAddrRaw) ? $remoteAddrRaw : '127.0.0.1';

        // String but malformed REMOTE_ADDR: it cannot match any trusted
        // CIDR, so there is no question of walking `X-Forwarded-For`.
        // Return the raw value so audit logs see exactly what the
        // connection presented; never let an unparsable bytes pivot us
        // into reading attacker-controlled forwarding headers.
        if (filter_var($remoteAddr, FILTER_VALIDATE_IP) === false) {
            return $remoteAddr;
        }

        if (!$this->isTrusted($remoteAddr)) {
            return $remoteAddr;
        }

        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded === '') {
            return $remoteAddr;
        }

        $ips = array_map(trim(...), explode(',', $forwarded));
        for ($i = count($ips) - 1; $i >= 0; $i--) {
            $candidate = $ips[$i];

            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                // Malformed entry: skip without leaking it. An attacker
                // who controls X-Forwarded-For cannot inject a poisoned
                // value because the lookup falls through to REMOTE_ADDR.
                continue;
            }

            if (!$this->isTrusted($candidate)) {
                return $candidate;
            }
        }

        return $remoteAddr;
    }

    /**
     * F8.7: public predicate exposing the same trust evaluation that
     * drives `resolveClientIp()`. `TracingMiddleware` consults this
     * to decide whether an inbound `traceparent` header is honoured
     * (only from a trusted upstream) or discarded (untrusted client
     * trying to spoof trace topology / sampling).
     *
     * Malformed inputs (non-IP strings) return `false` so the caller
     * fails closed: an unparseable REMOTE_ADDR cannot accidentally
     * be classified as trusted.
     */
    public function isTrustedSource(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return $this->isTrusted($ip);
    }

    private function isTrusted(string $ip): bool
    {
        return array_any($this->trustedCidrs, fn(string $cidr): bool => $this->ipInCidr($ip, $cidr));
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        $parts = explode('/', $cidr, 2);
        $subnet = $parts[0];
        $bits = (int) ($parts[1] ?? '0');

        // IPv4
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong !== false && $subnetLong !== false) {
            if ($bits === 0) {
                return true;
            }
            $mask = -1 << (32 - $bits);

            return ($ipLong & $mask) === ($subnetLong & $mask);
        }

        // IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }
        if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }

        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        // Compare bit-by-bit up to prefix length
        $fullBytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        $remainBits = $bits % 8;
        if ($remainBits > 0) {
            /** @var array{byte: int} $ipByte */
            $ipByte = unpack('Cbyte', $ipBin[$fullBytes]);
            /** @var array{byte: int} $subnetByte */
            $subnetByte = unpack('Cbyte', $subnetBin[$fullBytes]);
            $mask = 0xFF << (8 - $remainBits);

            return ($ipByte['byte'] & $mask) === ($subnetByte['byte'] & $mask);
        }

        return true;
    }
}

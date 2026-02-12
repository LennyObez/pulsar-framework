<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Security;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Config\CmsSecurityConfig;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Security\SafeHttpResponse;

use function array_merge;
use function dns_get_record;
use function explode;
use function filter_var;
use function in_array;
use function inet_pton;
use function ip2long;
use function ltrim;
use function ord;
use function parse_url;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

use const DNS_A;
use const DNS_AAAA;
use const FILTER_FLAG_IPV4;
use const FILTER_FLAG_IPV6;
use const FILTER_VALIDATE_IP;
use const PHP_URL_HOST;
use const PHP_URL_PORT;
use const PHP_URL_SCHEME;

/**
 * SSRF-safe HTTP client that validates all outbound requests against private IP ranges,
 * cloud metadata endpoints, and port restrictions before establishing connections.
 *
 * Uses DNS pre-resolution to prevent DNS rebinding attacks.
 */
#[Internal(reason: 'CMS security internals — use via service binding')]
final readonly class SafeHttpClient
{
    /** Cloud metadata endpoints explicitly blocked regardless of CIDR. */
    private const array METADATA_HOSTS = [
        '169.254.169.254',
        'metadata.google.internal',
    ];

    /** IPv4 private/reserved CIDR ranges. */
    private const array IPV4_PRIVATE_RANGES = [
        ['127.0.0.0', 8],
        ['10.0.0.0', 8],
        ['172.16.0.0', 12],
        ['192.168.0.0', 16],
        ['169.254.0.0', 16],
        ['0.0.0.0', 8],
        ['100.64.0.0', 10],
        ['198.18.0.0', 15],
    ];

    /** IPv6 private/reserved ranges. */
    private const array IPV6_PRIVATE_RANGES = [
        ['::1', 128],
        ['fc00::', 7],
        ['fe80::', 10],
    ];

    public function __construct(
        private CmsSecurityConfig $config,
        private LoggerInterface $logger,
    ) {}

    /**
     * Perform an SSRF-safe HTTP request.
     *
     * @param array<string, mixed> $options Stream context options
     *
     * @throws CmsException If the request is blocked by SSRF protection or fails
     */
    public function request(string $method, string $url, array $options = []): SafeHttpResponse
    {
        if ($this->config->ssrfEnabled) {
            $this->resolveAndValidate($url);
        }

        return $this->followRedirects($method, $url, $options, 0);
    }

    /**
     * Resolve the URL's hostname via DNS and validate all resolved IPs.
     *
     * @throws CmsException If the URL resolves to a blocked IP
     */
    private function resolveAndValidate(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);

        if ($host === null || $host === false || $host === '') {
            throw CmsException::ssrfBlocked($url, 'Cannot parse hostname');
        }

        // Strip brackets from IPv6 literals
        $host = ltrim($host, '[');
        $host = rtrim($host, ']');

        // Check explicit metadata hosts
        $lowHost = strtolower($host);

        foreach (self::METADATA_HOSTS as $metadataHost) {
            if ($lowHost === strtolower($metadataHost)) {
                throw CmsException::ssrfBlocked($url, 'Cloud metadata endpoint blocked');
            }
        }

        // Validate port
        $this->validatePort($url);

        // If the host is already an IP, validate directly
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if ($this->isBlockedIp($host)) {
                throw CmsException::ssrfBlocked($url, "IP address {$host} is in a blocked range");
            }

            return;
        }

        // DNS pre-resolution to prevent DNS rebinding
        $resolvedIps = $this->dnsResolve($host);

        if ($resolvedIps === []) {
            throw CmsException::ssrfBlocked($url, "DNS resolution failed for host: {$host}");
        }

        foreach ($resolvedIps as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw CmsException::ssrfBlocked(
                    $url,
                    "DNS for '{$host}' resolved to blocked IP: {$ip}",
                );
            }
        }
    }

    /**
     * Validate the port against the allowed outbound ports.
     *
     * @throws CmsException If the port is not allowed
     */
    private function validatePort(string $url): void
    {
        $port = parse_url($url, PHP_URL_PORT);

        if ($port === null || $port === false) {
            $scheme = parse_url($url, PHP_URL_SCHEME);
            $port = match (strtolower((string) $scheme)) {
                'https' => 443,
                'http' => 80,
                default => null,
            };
        }

        if ($port !== null && !in_array((int) $port, $this->config->allowedOutboundPorts, true)) {
            throw CmsException::ssrfBlocked(
                $url,
                sprintf('Port %d is not in the allowed outbound ports list', $port),
            );
        }
    }

    /**
     * Check if an IP address is in any blocked range.
     */
    private function isBlockedIp(string $ip): bool
    {
        // Check additional blocked IPs
        if (in_array($ip, $this->config->additionalBlockedIps, true)) {
            return true;
        }

        // Check explicit metadata IPs
        if (in_array($ip, self::METADATA_HOSTS, true)) {
            return true;
        }

        // Check IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $this->isPrivateIpv4($ip);
        }

        // Check IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // Also check ::ffff: mapped IPv4 addresses
            if (str_starts_with(strtolower($ip), '::ffff:')) {
                $mappedIpv4 = substr($ip, 7);

                if (filter_var($mappedIpv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    return $this->isPrivateIpv4($mappedIpv4);
                }
            }

            return $this->isPrivateIpv6($ip);
        }

        return false;
    }

    /**
     * Check if an IPv4 address falls within any private/reserved range.
     */
    private function isPrivateIpv4(string $ip): bool
    {
        $ipLong = ip2long($ip);

        if ($ipLong === false) {
            return false;
        }

        foreach (self::IPV4_PRIVATE_RANGES as [$rangeIp, $cidr]) {
            $rangeLong = ip2long($rangeIp);

            if ($rangeLong === false) {
                continue;
            }

            $mask = -1 << (32 - $cidr);

            if (($ipLong & $mask) === ($rangeLong & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an IPv6 address falls within any private/reserved range.
     */
    private function isPrivateIpv6(string $ip): bool
    {
        $ipBin = inet_pton($ip);

        if ($ipBin === false) {
            return false;
        }

        foreach (self::IPV6_PRIVATE_RANGES as [$rangeIp, $cidr]) {
            $rangeBin = inet_pton($rangeIp);

            if ($rangeBin === false) {
                continue;
            }

            if ($this->ipv6InCidr($ipBin, $rangeBin, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a binary IPv6 address is in a CIDR range.
     */
    private function ipv6InCidr(string $ipBin, string $rangeBin, int $cidr): bool
    {
        $fullBytes = intdiv($cidr, 8);
        $remainingBits = $cidr % 8;

        // Compare full bytes
        for ($i = 0; $i < $fullBytes; $i++) {
            if ($ipBin[$i] !== $rangeBin[$i]) {
                return false;
            }
        }

        // Compare remaining bits
        if ($remainingBits > 0 && $fullBytes < strlen($ipBin)) {
            $mask = 0xFF << (8 - $remainingBits);

            if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($rangeBin[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve DNS records for a hostname.
     *
     * @return list<string>
     */
    private function dnsResolve(string $host): array
    {
        $ips = [];

        $aRecords = @dns_get_record($host, DNS_A);

        if ($aRecords !== false) {
            foreach ($aRecords as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                }
            }
        }

        $aaaaRecords = @dns_get_record($host, DNS_AAAA);

        if ($aaaaRecords !== false) {
            foreach ($aaaaRecords as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return $ips;
    }

    /**
     * Follow redirects with SSRF validation at each hop.
     *
     * @param array<string, mixed> $options
     *
     * @throws CmsException If max redirects exceeded or redirect target is blocked
     */
    private function followRedirects(string $method, string $url, array $options, int $redirectCount): SafeHttpResponse
    {
        if ($redirectCount > $this->config->maxRedirects) {
            throw CmsException::ssrfBlocked($url, 'Maximum redirect count exceeded');
        }

        $context = stream_context_create([
            'http' => array_merge([
                'method' => $method,
                'follow_location' => 0,
                'timeout' => $this->config->totalTimeoutSeconds,
                'max_redirects' => 0,
                'ignore_errors' => true,
                'header' => "User-Agent: PulsarCMS/1.0\r\nAccept: */*\r\n",
            ], $options),
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            throw CmsException::ssrfBlocked($url, 'HTTP request failed');
        }

        // Enforce response size limit
        if (strlen($body) > $this->config->maxResponseBytes) {
            throw CmsException::ssrfBlocked($url, sprintf(
                'Response body exceeds maximum size of %d bytes',
                $this->config->maxResponseBytes,
            ));
        }

        // Parse response headers
        $statusCode = 200;
        $responseHeaders = [];
        $lastHeaders = http_get_last_response_headers();

        if ($lastHeaders !== null) {
            foreach ($lastHeaders as $header) {
                if (str_starts_with($header, 'HTTP/')) {
                    $parts = explode(' ', $header, 3);
                    $statusCode = (int) ($parts[1] ?? 200);
                } else {
                    $colonPos = strpos($header, ':');

                    if ($colonPos !== false) {
                        $name = strtolower(substr($header, 0, $colonPos));
                        $value = ltrim(substr($header, $colonPos + 1));
                        $responseHeaders[$name][] = $value;
                    }
                }
            }
        }

        // Handle redirects
        if ($statusCode >= 300 && $statusCode < 400) {
            $locationValues = $responseHeaders['location'] ?? [];

            if ($locationValues !== []) {
                $redirectUrl = $locationValues[0];

                // Validate the redirect target
                if ($this->config->ssrfEnabled) {
                    $this->resolveAndValidate($redirectUrl);
                }

                return $this->followRedirects($method, $redirectUrl, $options, $redirectCount + 1);
            }
        }

        $this->logger->debug('SafeHttpClient request completed', [
            'method' => $method,
            'url' => $url,
            'status' => $statusCode,
            'body_size' => strlen($body),
        ]);

        return new SafeHttpResponse(
            statusCode: $statusCode,
            headers: $responseHeaders,
            body: $body,
            effectiveUrl: $url,
        );
    }
}

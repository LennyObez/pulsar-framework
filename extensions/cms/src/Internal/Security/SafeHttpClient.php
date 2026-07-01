<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Security;

use Closure;
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
use function is_string;
use function ltrim;
use function ord;
use function parse_url;
use function rtrim;
use function sprintf;
use function str_contains;
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
 * DNS rebinding is prevented by *pinning*: the hostname is resolved once, every
 * resolved address is validated, and the request then connects to the validated
 * IP directly (with the original Host header and TLS verified against the
 * hostname). The transport therefore never re-resolves the name to a different,
 * private address between validation and connection.
 */
#[Internal(reason: 'CMS security internals; use via service binding')]
/**
 * @psalm-api Resolved from the DI container by webhook delivery and outbound
 *            HTTP services; not instantiated by name.
 */
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

    /** @var Closure(string): list<string> Resolves a hostname to its IP addresses. */
    private Closure $resolver;

    /** @var Closure(string, resource): (string|false) Performs the raw HTTP fetch. */
    private Closure $transport;

    /**
     * @param (Closure(string): list<string>)|null         $resolver  DNS resolver seam (defaults to A/AAAA lookup).
     * @param (Closure(string, resource): (string|false))|null $transport HTTP transport seam (defaults to the stream wrapper).
     */
    public function __construct(
        private CmsSecurityConfig $config,
        private LoggerInterface $logger,
        ?Closure $resolver = null,
        ?Closure $transport = null,
    ) {
        $this->resolver = $resolver ?? self::resolveViaDns(...);
        $this->transport = $transport ?? self::fetchViaStreamWrapper(...);
    }

    /**
     * Default transport: PHP's stream wrapper.
     *
     * @param resource $context
     */
    private static function fetchViaStreamWrapper(string $url, $context): string|false
    {
        return @file_get_contents($url, false, $context);
    }

    /**
     * Perform an SSRF-safe HTTP request.
     *
     * @param array<string, mixed> $options Stream context options
     *
     * @throws CmsException If the request is blocked by SSRF protection or fails
     */
    public function request(string $method, string $url, array $options = []): SafeHttpResponse
    {
        return $this->followRedirects($method, $url, $options, 0);
    }

    /**
     * Resolve the URL's hostname via DNS, validate all resolved IPs, and return
     * the address the request must connect to (the validated IP for a hostname,
     * or the literal itself for an IP URL).
     *
     * @throws CmsException If the URL resolves to a blocked IP
     */
    private function resolveAndValidate(string $url): string
    {
        $host = $this->parseHost($url);

        if ($host === null) {
            throw CmsException::ssrfBlocked($url, 'Cannot parse hostname');
        }

        // Check explicit metadata hosts
        if (in_array(strtolower($host), self::METADATA_HOSTS, true)) {
            throw CmsException::ssrfBlocked($url, 'Cloud metadata endpoint blocked');
        }

        // Validate port
        $this->validatePort($url);

        // If the host is already an IP, validate directly — nothing to rebind.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if ($this->isBlockedIp($host)) {
                throw CmsException::ssrfBlocked($url, "IP address $host is in a blocked range");
            }

            return $host;
        }

        // DNS pre-resolution; validate every resolved address.
        $resolvedIps = ($this->resolver)($host);

        if ($resolvedIps === []) {
            throw CmsException::ssrfBlocked($url, "DNS resolution failed for host: $host");
        }

        foreach ($resolvedIps as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw CmsException::ssrfBlocked(
                    $url,
                    "DNS for '$host' resolved to blocked IP: $ip",
                );
            }
        }

        // Pin to the first validated address.
        return $resolvedIps[0];
    }

    /**
     * The hostname of a URL with any IPv6 brackets stripped, or null if absent.
     */
    private function parseHost(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return null;
        }

        return rtrim(ltrim($host, '['), ']');
    }

    /**
     * Rebuild $url with $ip substituted for the host, preserving scheme,
     * userinfo, port, path, query and fragment. IPv6 literals are bracketed.
     */
    private function buildPinnedUrl(string $url, string $ip): string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'])) {
            return $url;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $user = $parts['user'] ?? '';
        $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $userinfo = $user !== '' ? $user . $pass . '@' : '';
        $hostPart = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . $userinfo . $hostPart . $port . $path . $query . $fragment;
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

        if ($port !== null && !in_array($port, $this->config->allowedOutboundPorts, true)) {
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

        return array_any(
            self::IPV4_PRIVATE_RANGES,
            static function (array $range) use ($ipLong): bool {
                $rangeLong = ip2long($range[0]);

                if ($rangeLong === false) {
                    return false;
                }

                $mask = -1 << (32 - $range[1]);

                return ($ipLong & $mask) === ($rangeLong & $mask);
            },
        );
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

        return array_any(
            self::IPV6_PRIVATE_RANGES,
            fn(array $range): bool => ($rangeBin = inet_pton($range[0])) !== false
                && $this->ipv6InCidr($ipBin, $rangeBin, $range[1]),
        );
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
     * Default DNS resolver: A + AAAA records.
     *
     * @return list<string>
     */
    private static function resolveViaDns(string $host): array
    {
        $ips = [];

        $aRecords = @dns_get_record($host, DNS_A);

        if ($aRecords !== false) {
            foreach ($aRecords as $record) {
                /** @var array<string, mixed> $record */
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }
            }
        }

        $aaaaRecords = @dns_get_record($host, DNS_AAAA);

        if ($aaaaRecords !== false) {
            foreach ($aaaaRecords as $record) {
                /** @var array<string, mixed> $record */
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return $ips;
    }

    /**
     * Follow redirects with SSRF validation — and connection pinning — at each hop.
     *
     * @param array<string, mixed> $options
     *
     * @throws CmsException If max redirects exceeded or a target is blocked
     */
    private function followRedirects(string $method, string $url, array $options, int $redirectCount): SafeHttpResponse
    {
        if ($redirectCount > $this->config->maxRedirects) {
            throw CmsException::ssrfBlocked($url, 'Maximum redirect count exceeded');
        }

        $requestUrl = $url;
        $pinnedHost = null;

        if ($this->config->ssrfEnabled) {
            $connectIp = $this->resolveAndValidate($url);
            $host = $this->parseHost($url);

            // Pin the connection to the validated IP so the transport cannot
            // re-resolve the hostname to a different (private) address between
            // validation and connection. An IP-literal host has nothing to rebind.
            if ($host !== null && $connectIp !== $host) {
                $requestUrl = $this->buildPinnedUrl($url, $connectIp);
                $pinnedHost = $host;
            }
        }

        $httpOptions = array_merge([
            'method' => $method,
            'timeout' => $this->config->totalTimeoutSeconds,
            'ignore_errors' => true,
            'header' => "User-Agent: PulsarCMS/1.0\r\nAccept: */*\r\n",
        ], $options);

        // Security-critical options cannot be overridden by callers: redirects
        // are followed manually so every hop is re-validated and re-pinned.
        $httpOptions['method'] = $method;
        $httpOptions['follow_location'] = 0;
        $httpOptions['max_redirects'] = 0;
        $httpOptions['ignore_errors'] = true;

        $sslOptions = [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ];

        if ($pinnedHost !== null) {
            // Connect to the pinned IP but present the real Host header and
            // verify TLS (SNI + certificate) against the original hostname.
            $existingHeader = isset($httpOptions['header']) && is_string($httpOptions['header'])
                ? $httpOptions['header']
                : '';
            $httpOptions['header'] = 'Host: ' . $pinnedHost . "\r\n" . $existingHeader;
            $sslOptions['peer_name'] = $pinnedHost;
            $sslOptions['SNI_enabled'] = true;
        }

        $context = stream_context_create([
            'http' => $httpOptions,
            'ssl' => $sslOptions,
        ]);

        $body = ($this->transport)($requestUrl, $context);

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
        /** @var list<string>|null $lastHeaders */
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

        // Handle redirects — re-validated and re-pinned by the recursive call.
        if ($statusCode >= 300 && $statusCode < 400) {
            $locationValues = $responseHeaders['location'] ?? [];

            if ($locationValues !== []) {
                return $this->followRedirects($method, $locationValues[0], $options, $redirectCount + 1);
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

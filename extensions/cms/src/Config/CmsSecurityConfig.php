<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;

use function array_map;
use function array_values;
use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;

/**
 * CMS security configuration DTO.
 *
 * Controls SSRF protection, client fingerprinting, plugin trust policy,
 * and step-up authentication settings.
 *
 * @psalm-api Public configuration DTO loaded from config/cms.php; consumed
 *            by security middleware, fingerprint resolver, and plugin loader.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CmsSecurityConfig
{
    /**
     * @param bool $ssrfEnabled Whether SSRF protection is enabled for outbound HTTP requests
     * @param list<string> $blockedIpRanges CIDR ranges blocked for outbound requests
     * @param list<string> $additionalBlockedIps Additional IP addresses to block
     * @param list<int> $allowedOutboundPorts Ports allowed for outbound HTTP requests
     * @param int $maxRedirects Maximum number of redirects to follow
     * @param int $connectTimeoutSeconds TCP connect timeout
     * @param int $totalTimeoutSeconds Total request timeout including response body
     * @param int $maxResponseBytes Maximum response body size in bytes (default 10 MB)
     * @param list<string> $oembedAllowedProviders Allowlisted oEmbed provider domains
     * @param list<string> $trustedProxies IP addresses of trusted reverse proxies
     * @param string $forwardedForHeader Header name for forwarded-for IP resolution
     * @param string $realIpHeader Header name for real IP resolution
     * @param bool $cloudflareMode Enable CF-Connecting-IP header trust
     * @param int $stepUpTtlMinutes Step-up authentication validity in minutes
     * @param list<string> $trustedPublicKeys Ed25519 public keys for plugin signature verification
     * @param bool $requireSignedPlugins Whether plugin packages must have valid signatures
     * @param bool $integrityCheckOnBoot Verify plugin file integrity on application boot
     * @param int $ipv6SubnetMask IPv6 subnet mask for fingerprinting (default /64)
     * @param bool $hotlinkProtection Whether hotlink protection is active for media delivery routes
     * @param list<string> $hotlinkAllowedDomains Domains permitted to reference media assets (supports *.example.com wildcards)
     */
    public function __construct(
        public bool $ssrfEnabled = true,
        public array $blockedIpRanges = [
            '127.0.0.0/8',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '169.254.0.0/16',
            '0.0.0.0/8',
            '100.64.0.0/10',
            '198.18.0.0/15',
            '::1/128',
            'fc00::/7',
            'fe80::/10',
        ],
        public array $additionalBlockedIps = [],
        public array $allowedOutboundPorts = [80, 443],
        public int $maxRedirects = 3,
        public int $connectTimeoutSeconds = 5,
        public int $totalTimeoutSeconds = 15,
        public int $maxResponseBytes = 10_485_760,
        public array $oembedAllowedProviders = [],
        public array $trustedProxies = [],
        public string $forwardedForHeader = 'X-Forwarded-For',
        public string $realIpHeader = 'X-Real-IP',
        public bool $cloudflareMode = false,
        public int $stepUpTtlMinutes = 15,
        public array $trustedPublicKeys = [],
        public bool $requireSignedPlugins = false,
        public bool $integrityCheckOnBoot = true,
        public int $ipv6SubnetMask = 64,
        public bool $hotlinkProtection = false,
        public array $hotlinkAllowedDomains = [],
    ) {}

    /**
     * @param array{
     *     ssrf_enabled?: bool,
     *     blocked_ip_ranges?: array<array-key, mixed>,
     *     additional_blocked_ips?: array<array-key, mixed>,
     *     allowed_outbound_ports?: array<array-key, mixed>,
     *     max_redirects?: int,
     *     connect_timeout_seconds?: int,
     *     total_timeout_seconds?: int,
     *     max_response_bytes?: int,
     *     oembed_allowed_providers?: array<array-key, mixed>,
     *     trusted_proxies?: array<array-key, mixed>,
     *     forwarded_for_header?: string,
     *     real_ip_header?: string,
     *     cloudflare_mode?: bool,
     *     step_up_ttl_minutes?: int,
     *     trusted_public_keys?: array<array-key, mixed>,
     *     require_signed_plugins?: bool,
     *     integrity_check_on_boot?: bool,
     *     ipv6_subnet_mask?: int,
     *     hotlink_protection?: bool,
     *     hotlink_allowed_domains?: array<array-key, mixed>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ssrfEnabled: $data['ssrf_enabled'] ?? true,
            blockedIpRanges: self::toStringList($data['blocked_ip_ranges'] ?? null, [
                '127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
                '169.254.0.0/16', '0.0.0.0/8', '100.64.0.0/10', '198.18.0.0/15',
                '::1/128', 'fc00::/7', 'fe80::/10',
            ]),
            additionalBlockedIps: self::toStringList($data['additional_blocked_ips'] ?? null, []),
            allowedOutboundPorts: self::toIntList($data['allowed_outbound_ports'] ?? null, [80, 443]),
            maxRedirects: $data['max_redirects'] ?? 3,
            connectTimeoutSeconds: $data['connect_timeout_seconds'] ?? 5,
            totalTimeoutSeconds: $data['total_timeout_seconds'] ?? 15,
            maxResponseBytes: $data['max_response_bytes'] ?? 10_485_760,
            oembedAllowedProviders: self::toStringList($data['oembed_allowed_providers'] ?? null, []),
            trustedProxies: self::toStringList($data['trusted_proxies'] ?? null, []),
            forwardedForHeader: $data['forwarded_for_header'] ?? 'X-Forwarded-For',
            realIpHeader: $data['real_ip_header'] ?? 'X-Real-IP',
            cloudflareMode: $data['cloudflare_mode'] ?? false,
            stepUpTtlMinutes: $data['step_up_ttl_minutes'] ?? 15,
            trustedPublicKeys: self::toStringList($data['trusted_public_keys'] ?? null, []),
            requireSignedPlugins: $data['require_signed_plugins'] ?? false,
            integrityCheckOnBoot: $data['integrity_check_on_boot'] ?? true,
            ipv6SubnetMask: $data['ipv6_subnet_mask'] ?? 64,
            hotlinkProtection: $data['hotlink_protection'] ?? false,
            hotlinkAllowedDomains: self::toStringList($data['hotlink_allowed_domains'] ?? null, []),
        );
    }

    /**
     * @param array<array-key, mixed>|null $raw
     * @param list<string>                 $default
     * @return list<string>
     */
    private static function toStringList(?array $raw, array $default): array
    {
        if ($raw === null) {
            return $default;
        }

        return array_values(array_map(
            static fn (mixed $v): string => is_string($v) ? $v : (is_scalar($v) ? (string) $v : ''),
            $raw,
        ));
    }

    /**
     * @param array<array-key, mixed>|null $raw
     * @param list<int>                    $default
     * @return list<int>
     */
    private static function toIntList(?array $raw, array $default): array
    {
        if ($raw === null) {
            return $default;
        }

        return array_values(array_map(
            static fn (mixed $v): int => is_int($v) ? $v : (is_numeric($v) ? (int) $v : 0),
            $raw,
        ));
    }
}

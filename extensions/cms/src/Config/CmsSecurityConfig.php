<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;

/**
 * CMS security configuration DTO.
 *
 * Controls SSRF protection, client fingerprinting, plugin trust policy,
 * and step-up authentication settings.
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
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ssrfEnabled: (bool) ($data['ssrf_enabled'] ?? true),
            blockedIpRanges: (array) ($data['blocked_ip_ranges'] ?? [
                '127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
                '169.254.0.0/16', '0.0.0.0/8', '100.64.0.0/10', '198.18.0.0/15',
                '::1/128', 'fc00::/7', 'fe80::/10',
            ]),
            additionalBlockedIps: (array) ($data['additional_blocked_ips'] ?? []),
            allowedOutboundPorts: (array) ($data['allowed_outbound_ports'] ?? [80, 443]),
            maxRedirects: (int) ($data['max_redirects'] ?? 3),
            connectTimeoutSeconds: (int) ($data['connect_timeout_seconds'] ?? 5),
            totalTimeoutSeconds: (int) ($data['total_timeout_seconds'] ?? 15),
            maxResponseBytes: (int) ($data['max_response_bytes'] ?? 10_485_760),
            oembedAllowedProviders: (array) ($data['oembed_allowed_providers'] ?? []),
            trustedProxies: (array) ($data['trusted_proxies'] ?? []),
            forwardedForHeader: (string) ($data['forwarded_for_header'] ?? 'X-Forwarded-For'),
            realIpHeader: (string) ($data['real_ip_header'] ?? 'X-Real-IP'),
            cloudflareMode: (bool) ($data['cloudflare_mode'] ?? false),
            stepUpTtlMinutes: (int) ($data['step_up_ttl_minutes'] ?? 15),
            trustedPublicKeys: (array) ($data['trusted_public_keys'] ?? []),
            requireSignedPlugins: (bool) ($data['require_signed_plugins'] ?? false),
            integrityCheckOnBoot: (bool) ($data['integrity_check_on_boot'] ?? true),
            ipv6SubnetMask: (int) ($data['ipv6_subnet_mask'] ?? 64),
        );
    }
}

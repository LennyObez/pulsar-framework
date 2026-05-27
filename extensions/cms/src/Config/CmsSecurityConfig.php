<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

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
            ssrfEnabled: Coerce::strictBool($data['ssrf_enabled'] ?? null, true),
            blockedIpRanges: Coerce::listOfString($data['blocked_ip_ranges'] ?? null, [
                '127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
                '169.254.0.0/16', '0.0.0.0/8', '100.64.0.0/10', '198.18.0.0/15',
                '::1/128', 'fc00::/7', 'fe80::/10',
            ]),
            additionalBlockedIps: Coerce::listOfString($data['additional_blocked_ips'] ?? null),
            allowedOutboundPorts: Coerce::listOfInt($data['allowed_outbound_ports'] ?? null, [80, 443]),
            maxRedirects: Coerce::int($data['max_redirects'] ?? null, 3),
            connectTimeoutSeconds: Coerce::int($data['connect_timeout_seconds'] ?? null, 5),
            totalTimeoutSeconds: Coerce::int($data['total_timeout_seconds'] ?? null, 15),
            maxResponseBytes: Coerce::int($data['max_response_bytes'] ?? null, 10_485_760),
            oembedAllowedProviders: Coerce::listOfString($data['oembed_allowed_providers'] ?? null),
            trustedProxies: Coerce::listOfString($data['trusted_proxies'] ?? null),
            forwardedForHeader: Coerce::string($data['forwarded_for_header'] ?? null, 'X-Forwarded-For'),
            realIpHeader: Coerce::string($data['real_ip_header'] ?? null, 'X-Real-IP'),
            cloudflareMode: Coerce::strictBool($data['cloudflare_mode'] ?? null),
            stepUpTtlMinutes: Coerce::int($data['step_up_ttl_minutes'] ?? null, 15),
            trustedPublicKeys: Coerce::listOfString($data['trusted_public_keys'] ?? null),
            requireSignedPlugins: Coerce::strictBool($data['require_signed_plugins'] ?? null),
            integrityCheckOnBoot: Coerce::strictBool($data['integrity_check_on_boot'] ?? null, true),
            ipv6SubnetMask: Coerce::int($data['ipv6_subnet_mask'] ?? null, 64),
            hotlinkProtection: Coerce::strictBool($data['hotlink_protection'] ?? null),
            hotlinkAllowedDomains: Coerce::listOfString($data['hotlink_allowed_domains'] ?? null),
        );
    }
}

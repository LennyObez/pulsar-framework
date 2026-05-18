<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;

use function is_array;
use function is_bool;
use function is_int;
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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rawSsrfEnabled = $data['ssrf_enabled'] ?? null;
        $rawMaxRedirects = $data['max_redirects'] ?? null;
        $rawConnectTimeout = $data['connect_timeout_seconds'] ?? null;
        $rawTotalTimeout = $data['total_timeout_seconds'] ?? null;
        $rawMaxResponseBytes = $data['max_response_bytes'] ?? null;
        $rawForwardedForHeader = $data['forwarded_for_header'] ?? null;
        $rawRealIpHeader = $data['real_ip_header'] ?? null;
        $rawCloudflareMode = $data['cloudflare_mode'] ?? null;
        $rawStepUpTtl = $data['step_up_ttl_minutes'] ?? null;
        $rawRequireSignedPlugins = $data['require_signed_plugins'] ?? null;
        $rawIntegrityCheckOnBoot = $data['integrity_check_on_boot'] ?? null;
        $rawIpv6SubnetMask = $data['ipv6_subnet_mask'] ?? null;
        $rawHotlinkProtection = $data['hotlink_protection'] ?? null;

        return new self(
            ssrfEnabled: is_bool($rawSsrfEnabled) ? $rawSsrfEnabled : true,
            blockedIpRanges: self::toStringList($data['blocked_ip_ranges'] ?? null, [
                '127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
                '169.254.0.0/16', '0.0.0.0/8', '100.64.0.0/10', '198.18.0.0/15',
                '::1/128', 'fc00::/7', 'fe80::/10',
            ]),
            additionalBlockedIps: self::toStringList($data['additional_blocked_ips'] ?? null, []),
            allowedOutboundPorts: self::toIntList($data['allowed_outbound_ports'] ?? null, [80, 443]),
            maxRedirects: is_int($rawMaxRedirects) ? $rawMaxRedirects : 3,
            connectTimeoutSeconds: is_int($rawConnectTimeout) ? $rawConnectTimeout : 5,
            totalTimeoutSeconds: is_int($rawTotalTimeout) ? $rawTotalTimeout : 15,
            maxResponseBytes: is_int($rawMaxResponseBytes) ? $rawMaxResponseBytes : 10_485_760,
            oembedAllowedProviders: self::toStringList($data['oembed_allowed_providers'] ?? null, []),
            trustedProxies: self::toStringList($data['trusted_proxies'] ?? null, []),
            forwardedForHeader: is_string($rawForwardedForHeader) ? $rawForwardedForHeader : 'X-Forwarded-For',
            realIpHeader: is_string($rawRealIpHeader) ? $rawRealIpHeader : 'X-Real-IP',
            cloudflareMode: is_bool($rawCloudflareMode) ? $rawCloudflareMode : false,
            stepUpTtlMinutes: is_int($rawStepUpTtl) ? $rawStepUpTtl : 15,
            trustedPublicKeys: self::toStringList($data['trusted_public_keys'] ?? null, []),
            requireSignedPlugins: is_bool($rawRequireSignedPlugins) ? $rawRequireSignedPlugins : false,
            integrityCheckOnBoot: is_bool($rawIntegrityCheckOnBoot) ? $rawIntegrityCheckOnBoot : true,
            ipv6SubnetMask: is_int($rawIpv6SubnetMask) ? $rawIpv6SubnetMask : 64,
            hotlinkProtection: is_bool($rawHotlinkProtection) ? $rawHotlinkProtection : false,
            hotlinkAllowedDomains: self::toStringList($data['hotlink_allowed_domains'] ?? null, []),
        );
    }

    /**
     * @param list<string> $default
     * @return list<string>
     */
    private static function toStringList(mixed $raw, array $default): array
    {
        if (!is_array($raw)) {
            return $default;
        }
        $result = [];
        foreach ($raw as $v) {
            $result[] = is_string($v) ? $v : (is_scalar($v) ? (string) $v : '');
        }

        return $result;
    }

    /**
     * @param list<int> $default
     * @return list<int>
     */
    private static function toIntList(mixed $raw, array $default): array
    {
        if (!is_array($raw)) {
            return $default;
        }
        $result = [];
        foreach ($raw as $v) {
            $result[] = is_int($v) ? $v : (is_numeric($v) ? (int) $v : 0);
        }

        return $result;
    }
}

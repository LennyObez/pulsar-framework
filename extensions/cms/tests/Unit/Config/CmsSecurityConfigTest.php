<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\CmsSecurityConfig;

#[CoversClass(CmsSecurityConfig::class)]
final class CmsSecurityConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreSecure(): void
    {
        $config = new CmsSecurityConfig();

        self::assertTrue($config->ssrfEnabled);
        self::assertContains('127.0.0.0/8', $config->blockedIpRanges);
        self::assertContains('10.0.0.0/8', $config->blockedIpRanges);
        self::assertContains('::1/128', $config->blockedIpRanges);
        self::assertSame([], $config->additionalBlockedIps);
        self::assertSame([80, 443], $config->allowedOutboundPorts);
        self::assertSame(3, $config->maxRedirects);
        self::assertSame(5, $config->connectTimeoutSeconds);
        self::assertSame(15, $config->totalTimeoutSeconds);
        self::assertSame(10_485_760, $config->maxResponseBytes);
        self::assertSame([], $config->oembedAllowedProviders);
        self::assertSame([], $config->trustedProxies);
        self::assertSame('X-Forwarded-For', $config->forwardedForHeader);
        self::assertSame('X-Real-IP', $config->realIpHeader);
        self::assertFalse($config->cloudflareMode);
        self::assertSame(15, $config->stepUpTtlMinutes);
        self::assertSame([], $config->trustedPublicKeys);
        self::assertFalse($config->requireSignedPlugins);
        self::assertTrue($config->integrityCheckOnBoot);
        self::assertSame(64, $config->ipv6SubnetMask);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = CmsSecurityConfig::fromArray([
            'ssrf_enabled' => false,
            'blocked_ip_ranges' => ['10.0.0.0/8'],
            'additional_blocked_ips' => ['1.2.3.4'],
            'allowed_outbound_ports' => [443, 8080],
            'max_redirects' => 5,
            'connect_timeout_seconds' => 10,
            'total_timeout_seconds' => 30,
            'max_response_bytes' => 5_242_880,
            'oembed_allowed_providers' => ['youtube.com'],
            'trusted_proxies' => ['192.168.1.1'],
            'forwarded_for_header' => 'CF-Connecting-IP',
            'real_ip_header' => 'True-Client-IP',
            'cloudflare_mode' => true,
            'step_up_ttl_minutes' => 30,
            'trusted_public_keys' => ['key1', 'key2'],
            'require_signed_plugins' => true,
            'integrity_check_on_boot' => false,
            'ipv6_subnet_mask' => 48,
        ]);

        self::assertFalse($config->ssrfEnabled);
        self::assertSame(['10.0.0.0/8'], $config->blockedIpRanges);
        self::assertSame(['1.2.3.4'], $config->additionalBlockedIps);
        self::assertSame([443, 8080], $config->allowedOutboundPorts);
        self::assertSame(5, $config->maxRedirects);
        self::assertSame(10, $config->connectTimeoutSeconds);
        self::assertSame(30, $config->totalTimeoutSeconds);
        self::assertSame(5_242_880, $config->maxResponseBytes);
        self::assertSame(['youtube.com'], $config->oembedAllowedProviders);
        self::assertSame(['192.168.1.1'], $config->trustedProxies);
        self::assertSame('CF-Connecting-IP', $config->forwardedForHeader);
        self::assertSame('True-Client-IP', $config->realIpHeader);
        self::assertTrue($config->cloudflareMode);
        self::assertSame(30, $config->stepUpTtlMinutes);
        self::assertSame(['key1', 'key2'], $config->trustedPublicKeys);
        self::assertTrue($config->requireSignedPlugins);
        self::assertFalse($config->integrityCheckOnBoot);
        self::assertSame(48, $config->ipv6SubnetMask);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = CmsSecurityConfig::fromArray([]);

        self::assertTrue($config->ssrfEnabled);
        self::assertCount(11, $config->blockedIpRanges);
        self::assertSame([80, 443], $config->allowedOutboundPorts);
        self::assertSame(3, $config->maxRedirects);
    }

    #[Test]
    public function fromArrayIgnoresWrongTypes(): void
    {
        $config = CmsSecurityConfig::fromArray([
            'ssrf_enabled' => 'yes',
            'max_redirects' => '3',
            'blocked_ip_ranges' => 'not-an-array',
            'cloudflare_mode' => 1,
        ]);

        // Non-bool for ssrf_enabled → default true
        self::assertTrue($config->ssrfEnabled);
        // Non-int for max_redirects → default 3
        self::assertSame(3, $config->maxRedirects);
        // Non-array for blocked_ip_ranges → default list
        self::assertCount(11, $config->blockedIpRanges);
        // Non-bool for cloudflare_mode → default false
        self::assertFalse($config->cloudflareMode);
    }

    #[Test]
    public function blockedIpRangesIncludesAllPrivateRanges(): void
    {
        $config = new CmsSecurityConfig();

        $expectedRanges = [
            '127.0.0.0/8',      // loopback
            '10.0.0.0/8',       // Class A private
            '172.16.0.0/12',    // Class B private
            '192.168.0.0/16',   // Class C private
            '169.254.0.0/16',   // link-local
            '0.0.0.0/8',       // "this" network
            '100.64.0.0/10',   // CGNAT
            '198.18.0.0/15',   // benchmark
            '::1/128',          // IPv6 loopback
            'fc00::/7',         // IPv6 ULA
            'fe80::/10',        // IPv6 link-local
        ];

        foreach ($expectedRanges as $range) {
            self::assertContains($range, $config->blockedIpRanges, "Missing SSRF-blocked range: {$range}");
        }
    }
}

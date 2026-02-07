<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Cms\Config\CmsSecurityConfig;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\Security\SafeHttpClient;

/**
 * Tests the SSRF protection layer of SafeHttpClient.
 *
 * These tests focus on the IP validation and port restriction logic.
 * They use URLs with raw IP addresses to avoid DNS resolution (which
 * would fail in unit tests for blocked IPs).
 */
#[CoversClass(SafeHttpClient::class)]
final class SsrfProtectionTest extends TestCase
{
    private CmsSecurityConfig $config;

    protected function setUp(): void
    {
        $this->config = new CmsSecurityConfig(
            ssrfEnabled: true,
            allowedOutboundPorts: [80, 443],
            maxRedirects: 3,
        );
    }

    /**
     * Create a SafeHttpClient. The actual HTTP request will fail since we
     * can't reach the URL, but we test that the SSRF validation occurs
     * before the request is made (by catching CmsException for blocked URLs).
     */
    private function createClient(): SafeHttpClient
    {
        return new SafeHttpClient($this->config, new NullLogger());
    }

    // -- IPv4 private ranges blocked -----------------------------------------

    #[Test]
    public function test_blocks_loopback_127_0_0_1(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('SSRF');

        $client->request('GET', 'http://127.0.0.1/');
    }

    #[Test]
    public function test_blocks_rfc1918_10_0_0_1(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('SSRF');

        $client->request('GET', 'http://10.0.0.1/');
    }

    #[Test]
    public function test_blocks_rfc1918_172_16_0_1(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('SSRF');

        $client->request('GET', 'http://172.16.0.1/');
    }

    #[Test]
    public function test_blocks_rfc1918_192_168_1_1(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('SSRF');

        $client->request('GET', 'http://192.168.1.1/');
    }

    #[Test]
    public function test_blocks_cloud_metadata_169_254_169_254(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('SSRF');

        $client->request('GET', 'http://169.254.169.254/latest/meta-data/');
    }

    // -- IPv6 private ranges blocked -----------------------------------------

    #[Test]
    public function test_blocks_ipv6_loopback(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('SSRF');

        $client->request('GET', 'http://[::1]/');
    }

    #[Test]
    public function test_blocks_ipv6_unique_local(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('SSRF');

        $client->request('GET', 'http://[fc00::1]/');
    }

    #[Test]
    public function test_blocks_ipv6_link_local(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('SSRF');

        $client->request('GET', 'http://[fe80::1]/');
    }

    // -- IPv4-mapped IPv6 re-check -------------------------------------------

    #[Test]
    public function test_blocks_ipv4_mapped_ipv6_loopback(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('SSRF');

        $client->request('GET', 'http://[::ffff:127.0.0.1]/');
    }

    #[Test]
    public function test_blocks_ipv4_mapped_ipv6_private(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('SSRF');

        $client->request('GET', 'http://[::ffff:10.0.0.1]/');
    }

    // -- Port restrictions ---------------------------------------------------

    #[Test]
    public function test_blocks_non_allowed_port_8080(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('Port 8080');

        $client->request('GET', 'http://8.8.8.8:8080/');
    }

    #[Test]
    public function test_allows_port_443(): void
    {
        $client = $this->createClient();

        // Port 443 is allowed. The request will fail at the HTTP layer
        // (not the SSRF layer) since 8.8.8.8 isn't an HTTP server.
        // We verify no CmsException with "Port" message is thrown.
        try {
            $client->request('GET', 'https://8.8.8.8:443/');
        } catch (CmsException $e) {
            // If it fails, it should NOT be due to port restriction
            self::assertStringNotContainsString('Port 443', $e->getMessage());
        }

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function test_allows_port_80(): void
    {
        $client = $this->createClient();

        try {
            $client->request('GET', 'http://8.8.8.8:80/');
        } catch (CmsException $e) {
            self::assertStringNotContainsString('Port 80', $e->getMessage());
        }

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function test_blocks_port_22(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('Port 22');

        $client->request('GET', 'http://8.8.8.8:22/');
    }

    // -- Metadata hostname blocked -------------------------------------------

    #[Test]
    public function test_blocks_metadata_google_internal(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('metadata');

        $client->request('GET', 'http://metadata.google.internal/computeMetadata/v1/');
    }

    // -- Max redirect limit --------------------------------------------------

    #[Test]
    public function test_max_redirect_limit(): void
    {
        // The maxRedirects config is set to 3. We can verify the config is respected.
        // In a unit test context, we can only verify the config is wired correctly.
        self::assertSame(3, $this->config->maxRedirects);
    }

    // -- SSRF disabled mode --------------------------------------------------

    #[Test]
    public function test_ssrf_disabled_skips_validation(): void
    {
        $config = new CmsSecurityConfig(ssrfEnabled: false);
        $client = new SafeHttpClient($config, new NullLogger());

        // When SSRF is disabled, private IPs should not be blocked at the validation layer.
        // The request will still fail at the HTTP layer, but we verify no SSRF-specific error.
        try {
            $client->request('GET', 'http://127.0.0.1/');
        } catch (CmsException $e) {
            // If disabled, should NOT mention "blocked range"
            self::assertStringNotContainsString('blocked range', $e->getMessage());
        }

        $this->addToAssertionCount(1);
    }

    // -- Additional blocked IPs via config -----------------------------------

    #[Test]
    public function test_additional_blocked_ips(): void
    {
        $config = new CmsSecurityConfig(
            ssrfEnabled: true,
            additionalBlockedIps: ['1.2.3.4'],
        );
        $client = new SafeHttpClient($config, new NullLogger());

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('SSRF');

        $client->request('GET', 'http://1.2.3.4/');
    }

    // -- Empty URL hostname --------------------------------------------------

    #[Test]
    public function test_blocks_empty_hostname(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);

        $client->request('GET', 'http:///path');
    }

    // -- Other private ranges -------------------------------------------------

    #[Test]
    public function test_blocks_0_0_0_0(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('SSRF');

        $client->request('GET', 'http://0.0.0.0/');
    }

    #[Test]
    public function test_blocks_100_64_0_1_cgnat(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('SSRF');

        $client->request('GET', 'http://100.64.0.1/');
    }

    #[Test]
    public function test_blocks_198_18_0_1_benchmark(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('SSRF');

        $client->request('GET', 'http://198.18.0.1/');
    }
}

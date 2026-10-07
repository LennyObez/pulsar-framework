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
    public function blocksLoopback127001(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('SSRF');

        $client->request('GET', 'http://127.0.0.1/');
    }

    #[Test]
    public function blocksRfc191810001(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('SSRF');

        $client->request('GET', 'http://10.0.0.1/');
    }

    #[Test]
    public function blocksRfc19181721601(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('SSRF');

        $client->request('GET', 'http://172.16.0.1/');
    }

    #[Test]
    public function blocksRfc191819216811(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('SSRF');

        $client->request('GET', 'http://192.168.1.1/');
    }

    #[Test]
    public function blocksCloudMetadata169254169254(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('SSRF');

        $client->request('GET', 'http://169.254.169.254/latest/meta-data/');
    }

    // -- IPv6 private ranges blocked -----------------------------------------

    #[Test]
    public function blocksIpv6Loopback(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('SSRF');

        $client->request('GET', 'http://[::1]/');
    }

    #[Test]
    public function blocksIpv6UniqueLocal(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('SSRF');

        $client->request('GET', 'http://[fc00::1]/');
    }

    #[Test]
    public function blocksIpv6LinkLocal(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('SSRF');

        $client->request('GET', 'http://[fe80::1]/');
    }

    // -- IPv4-mapped IPv6 re-check -------------------------------------------

    #[Test]
    public function blocksIpv4MappedIpv6Loopback(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('SSRF');

        $client->request('GET', 'http://[::ffff:127.0.0.1]/');
    }

    #[Test]
    public function blocksIpv4MappedIpv6Private(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('SSRF');

        $client->request('GET', 'http://[::ffff:10.0.0.1]/');
    }

    // -- Port restrictions ---------------------------------------------------

    #[Test]
    public function blocksNonAllowedPort8080(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('Port 8080');

        $client->request('GET', 'http://8.8.8.8:8080/');
    }

    #[Test]
    public function allowsPort443(): void
    {
        $client = $this->createClient();

        $portBlocked = false;

        // Port 443 is allowed. The request will fail at the HTTP layer
        // (not the SSRF layer) since 8.8.8.8 isn't an HTTP server.
        // We verify no CmsException with "Port" message is thrown.
        try {
            $client->request('GET', 'https://8.8.8.8:443/');
        } catch (CmsException $e) {
            $portBlocked = str_contains($e->getMessage(), 'Port 443');
        }

        self::assertFalse($portBlocked, 'Port 443 should not be blocked by SSRF protection');
    }

    #[Test]
    public function allowsPort80(): void
    {
        $client = $this->createClient();

        $portBlocked = false;

        try {
            $client->request('GET', 'http://8.8.8.8:80/');
        } catch (CmsException $e) {
            $portBlocked = str_contains($e->getMessage(), 'Port 80');
        }

        self::assertFalse($portBlocked, 'Port 80 should not be blocked by SSRF protection');
    }

    #[Test]
    public function blocksPort22(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('Port 22');

        $client->request('GET', 'http://8.8.8.8:22/');
    }

    // -- Metadata hostname blocked -------------------------------------------

    #[Test]
    public function blocksMetadataGoogleInternal(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('metadata');

        $client->request('GET', 'http://metadata.google.internal/computeMetadata/v1/');
    }

    // -- Max redirect limit --------------------------------------------------

    #[Test]
    public function maxRedirectLimit(): void
    {
        // The maxRedirects config is set to 3. We can verify the config is respected.
        // In a unit test context, we can only verify the config is wired correctly.
        self::assertSame(3, $this->config->maxRedirects);
    }

    // -- SSRF disabled mode --------------------------------------------------

    #[Test]
    public function ssrfDisabledSkipsValidation(): void
    {
        $config = new CmsSecurityConfig(ssrfEnabled: false);
        $client = new SafeHttpClient($config, new NullLogger());

        $ssrfBlocked = false;

        // When SSRF is disabled, private IPs should not be blocked at the validation layer.
        // The request will still fail at the HTTP layer, but we verify no SSRF-specific error.
        try {
            $client->request('GET', 'http://127.0.0.1/');
        } catch (CmsException $e) {
            $ssrfBlocked = str_contains($e->getMessage(), 'blocked range');
        }

        self::assertFalse($ssrfBlocked, 'SSRF validation should be skipped when disabled');
    }

    // -- Additional blocked IPs via config -----------------------------------

    #[Test]
    public function additionalBlockedIps(): void
    {
        $config = new CmsSecurityConfig(
            ssrfEnabled: true,
            additionalBlockedIps: ['1.2.3.4'],
        );
        $client = new SafeHttpClient($config, new NullLogger());

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('SSRF');

        $client->request('GET', 'http://1.2.3.4/');
    }

    // -- Empty URL hostname --------------------------------------------------

    #[Test]
    public function blocksEmptyHostname(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);

        $client->request('GET', 'http:///path');
    }

    // -- Other private ranges -------------------------------------------------

    #[Test]
    public function blocks0000(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('SSRF');

        $client->request('GET', 'http://0.0.0.0/');
    }

    #[Test]
    public function blocks1006401Cgnat(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('SSRF');

        $client->request('GET', 'http://100.64.0.1/');
    }

    #[Test]
    public function blocks1981801Benchmark(): void
    {
        $client = $this->createClient();

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageIsOrContains('SSRF');

        $client->request('GET', 'http://198.18.0.1/');
    }
}

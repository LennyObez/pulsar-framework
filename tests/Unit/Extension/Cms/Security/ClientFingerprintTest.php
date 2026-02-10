<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Cms\Config\CmsSecurityConfig;
use Pulsar\Extension\Cms\Internal\Security\ClientFingerprintResolver;
use Pulsar\Extension\Cms\Security\ClientFingerprint;
use Pulsar\Security\Crypto\Hmac;

use function random_bytes;

#[CoversClass(ClientFingerprintResolver::class)]
#[CoversClass(ClientFingerprint::class)]
final class ClientFingerprintTest extends TestCase
{
    private string $hmacKey;

    protected function setUp(): void
    {
        // Minimum key length for BLAKE2b is SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN (16 bytes)
        $this->hmacKey = random_bytes(32);
    }

    // -- Direct connection (no proxy) ----------------------------------------

    #[Test]
    public function directConnectionUsesRemoteAddr(): void
    {
        $config = new CmsSecurityConfig(trustedProxies: []);
        $resolver = new ClientFingerprintResolver($config, $this->hmacKey);

        $request = $this->createRequest('203.0.113.50', []);

        $fingerprint = $resolver->resolve($request);

        self::assertSame('203.0.113.50', $fingerprint->ipAddress);
    }

    #[Test]
    public function directConnectionIgnoresForwardedHeaders(): void
    {
        $config = new CmsSecurityConfig(trustedProxies: []);
        $resolver = new ClientFingerprintResolver($config, $this->hmacKey);

        $request = $this->createRequest('203.0.113.50', [
            'X-Forwarded-For' => '1.2.3.4, 5.6.7.8',
            'X-Real-IP' => '9.10.11.12',
        ]);

        $fingerprint = $resolver->resolve($request);

        // Should use REMOTE_ADDR, not any forwarded header
        self::assertSame('203.0.113.50', $fingerprint->ipAddress);
    }

    // -- Trusted proxy: X-Forwarded-For parsing (right-to-left) -------------

    #[Test]
    public function trustedProxyUsesXForwardedFor(): void
    {
        $config = new CmsSecurityConfig(trustedProxies: ['10.0.0.1']);
        $resolver = new ClientFingerprintResolver($config, $this->hmacKey);

        $request = $this->createRequest('10.0.0.1', [
            'X-Forwarded-For' => '203.0.113.50, 10.0.0.1',
        ]);

        $fingerprint = $resolver->resolve($request);

        // Right-to-left: 10.0.0.1 is trusted, so use 203.0.113.50
        self::assertSame('203.0.113.50', $fingerprint->ipAddress);
    }

    #[Test]
    public function trustedProxyXffMultipleIps(): void
    {
        $config = new CmsSecurityConfig(trustedProxies: ['10.0.0.1', '10.0.0.2']);
        $resolver = new ClientFingerprintResolver($config, $this->hmacKey);

        $request = $this->createRequest('10.0.0.1', [
            'X-Forwarded-For' => '203.0.113.50, 10.0.0.2, 10.0.0.1',
        ]);

        $fingerprint = $resolver->resolve($request);

        // Walk right-to-left: 10.0.0.1 (trusted), 10.0.0.2 (trusted), 203.0.113.50 (untrusted → use this)
        self::assertSame('203.0.113.50', $fingerprint->ipAddress);
    }

    // -- Untrusted REMOTE_ADDR ignores forwarded headers --------------------

    #[Test]
    public function untrustedRemoteAddrIgnoresForwardedHeaders(): void
    {
        $config = new CmsSecurityConfig(trustedProxies: ['10.0.0.1']);
        $resolver = new ClientFingerprintResolver($config, $this->hmacKey);

        // REMOTE_ADDR is NOT in the trusted proxies list
        $request = $this->createRequest('203.0.113.99', [
            'X-Forwarded-For' => '1.2.3.4',
        ]);

        $fingerprint = $resolver->resolve($request);

        // Should use REMOTE_ADDR since it's not a trusted proxy
        self::assertSame('203.0.113.99', $fingerprint->ipAddress);
    }

    // -- X-Real-IP header ---------------------------------------------------

    #[Test]
    public function trustedProxyUsesXRealIp(): void
    {
        $config = new CmsSecurityConfig(trustedProxies: ['10.0.0.1']);
        $resolver = new ClientFingerprintResolver($config, $this->hmacKey);

        $request = $this->createRequest('10.0.0.1', [
            'X-Real-IP' => '203.0.113.75',
        ]);

        $fingerprint = $resolver->resolve($request);

        self::assertSame('203.0.113.75', $fingerprint->ipAddress);
    }

    // -- Cloudflare mode: CF-Connecting-IP -----------------------------------

    #[Test]
    public function cloudflareModeUsesCfConnectingIp(): void
    {
        $config = new CmsSecurityConfig(
            trustedProxies: ['172.64.0.1'],
            cloudflareMode: true,
        );
        $resolver = new ClientFingerprintResolver($config, $this->hmacKey);

        $request = $this->createRequest('172.64.0.1', [
            'CF-Connecting-IP' => '203.0.113.42',
            'X-Forwarded-For' => '203.0.113.42, 172.64.0.1',
        ]);

        $fingerprint = $resolver->resolve($request);

        // CF-Connecting-IP takes priority over XFF when cloudflare mode is on
        self::assertSame('203.0.113.42', $fingerprint->ipAddress);
    }

    #[Test]
    public function cloudflareModeOffIgnoresCfHeader(): void
    {
        $config = new CmsSecurityConfig(
            trustedProxies: ['172.64.0.1'],
            cloudflareMode: false,
        );
        $resolver = new ClientFingerprintResolver($config, $this->hmacKey);

        $request = $this->createRequest('172.64.0.1', [
            'CF-Connecting-IP' => '203.0.113.42',
            'X-Real-IP' => '203.0.113.99',
        ]);

        $fingerprint = $resolver->resolve($request);

        // Should use X-Real-IP, not CF-Connecting-IP
        self::assertSame('203.0.113.99', $fingerprint->ipAddress);
    }

    // -- IPv6 normalization --------------------------------------------------

    #[Test]
    public function ipv6IsNormalizedToSubnet(): void
    {
        $config = new CmsSecurityConfig(
            trustedProxies: [],
            ipv6SubnetMask: 64,
        );
        $resolver = new ClientFingerprintResolver($config, $this->hmacKey);

        $request = $this->createRequest('2001:db8:1234:5678:abcd:ef01:2345:6789', []);

        $fingerprint = $resolver->resolve($request);

        // With /64 mask, the last 64 bits should be zeroed
        self::assertSame('2001:db8:1234:5678::', $fingerprint->ipAddress);
    }

    #[Test]
    public function ipv4IsNotMasked(): void
    {
        $config = new CmsSecurityConfig(trustedProxies: []);
        $resolver = new ClientFingerprintResolver($config, $this->hmacKey);

        $request = $this->createRequest('192.0.2.100', []);

        $fingerprint = $resolver->resolve($request);

        self::assertSame('192.0.2.100', $fingerprint->ipAddress);
    }

    // -- Composite hash is HMAC of IP + user agent --------------------------

    #[Test]
    public function compositeHashIsHmacOfIpPlusUserAgent(): void
    {
        $config = new CmsSecurityConfig(trustedProxies: []);
        $resolver = new ClientFingerprintResolver($config, $this->hmacKey);

        $userAgent = 'Mozilla/5.0 TestBrowser';
        $request = $this->createRequest('203.0.113.1', [], $userAgent);

        $fingerprint = $resolver->resolve($request);

        // Verify the composite hash matches what we'd expect
        $expectedComposite = Hmac::computeHex('203.0.113.1|' . $userAgent, $this->hmacKey);
        self::assertSame($expectedComposite, $fingerprint->compositeHash);
    }

    #[Test]
    public function ipHashIsHmacOfIp(): void
    {
        $config = new CmsSecurityConfig(trustedProxies: []);
        $resolver = new ClientFingerprintResolver($config, $this->hmacKey);

        $request = $this->createRequest('203.0.113.1', []);

        $fingerprint = $resolver->resolve($request);

        $expectedIpHash = Hmac::computeHex('203.0.113.1', $this->hmacKey);
        self::assertSame($expectedIpHash, $fingerprint->ipHash);
    }

    #[Test]
    public function userAgentHashIsHmacOfUserAgent(): void
    {
        $config = new CmsSecurityConfig(trustedProxies: []);
        $resolver = new ClientFingerprintResolver($config, $this->hmacKey);

        $userAgent = 'TestAgent/1.0';
        $request = $this->createRequest('203.0.113.1', [], $userAgent);

        $fingerprint = $resolver->resolve($request);

        $expectedUaHash = Hmac::computeHex($userAgent, $this->hmacKey);
        self::assertSame($expectedUaHash, $fingerprint->userAgentHash);
    }

    // -- Header priority: CF > X-Real-IP > XFF > REMOTE_ADDR ---------------

    #[Test]
    public function xRealIpTakesPriorityOverXff(): void
    {
        $config = new CmsSecurityConfig(trustedProxies: ['10.0.0.1']);
        $resolver = new ClientFingerprintResolver($config, $this->hmacKey);

        $request = $this->createRequest('10.0.0.1', [
            'X-Real-IP' => '203.0.113.10',
            'X-Forwarded-For' => '203.0.113.20, 10.0.0.1',
        ]);

        $fingerprint = $resolver->resolve($request);

        // X-Real-IP should take priority over XFF
        self::assertSame('203.0.113.10', $fingerprint->ipAddress);
    }

    // -- Fingerprint with empty user agent -----------------------------------

    #[Test]
    public function fingerprintWithEmptyUserAgent(): void
    {
        $config = new CmsSecurityConfig(trustedProxies: []);
        $resolver = new ClientFingerprintResolver($config, $this->hmacKey);

        $request = $this->createRequest('203.0.113.1', [], '');

        $fingerprint = $resolver->resolve($request);

        // Should still produce valid hashes
        self::assertNotEmpty($fingerprint->ipHash);
        self::assertNotEmpty($fingerprint->userAgentHash);
        self::assertNotEmpty($fingerprint->compositeHash);
    }

    // -- Helpers ------------------------------------------------------------

    /**
     * @param array<string, string> $headers
     */
    private function createRequest(
        string $remoteAddr,
        array $headers,
        string $userAgent = 'TestAgent/1.0',
    ): ServerRequestInterface {
        $request = $this->createStub(ServerRequestInterface::class);

        $request->method('getServerParams')
            ->willReturn(['REMOTE_ADDR' => $remoteAddr]);

        $request->method('getHeaderLine')
            ->willReturnCallback(static function (string $name) use ($headers, $userAgent): string {
                if ($name === 'User-Agent') {
                    return $userAgent;
                }

                return $headers[$name] ?? '';
            });

        return $request;
    }
}

<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AuditFix;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Extension\Analytics\Internal\Security\AnalyticsKeyManager;
use Pulsar\Extension\Analytics\Internal\Security\VisitorConsentIdentity;
use Pulsar\Security\Crypto\MasterKey;

use function bin2hex;
use function random_bytes;

/**
 * Verifies that client-IP resolution walks X-Forwarded-For right-to-left to find
 * the first untrusted IP, matching the ClientFingerprintResolver pattern for
 * multi-hop proxy chains.
 *
 * The logic lives in {@see VisitorConsentIdentity::clientIp()}, which TrackingService
 * delegates to. This exercises it directly through its public API: the previous
 * version reflected into TrackingService's private getClientIp() on an instance
 * built without a constructor, which broke the moment the method became a
 * delegation (the readonly $consentIdentity was never initialised) even though
 * production was correct throughout. Testing the owning class publicly cannot
 * decay that way.
 */
#[CoversClass(VisitorConsentIdentity::class)]
final class TrackingServiceMultiHopXffTest extends TestCase
{
    private function makeServiceWithConfig(AnalyticsConfig $config): VisitorConsentIdentity
    {
        return new VisitorConsentIdentity(
            new AnalyticsKeyManager(MasterKey::fromHex(bin2hex(random_bytes(32)))),
            $config,
        );
    }

    private function invokeGetClientIp(VisitorConsentIdentity $identity, ServerRequestInterface $request): string
    {
        return $identity->clientIp($request);
    }

    private function makeRequest(string $remoteAddr, string $xff): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => $remoteAddr]);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn(string $name): string => match ($name) {
                'X-Forwarded-For' => $xff,
                default => '',
            },
        );

        return $request;
    }

    #[Test]
    public function rightToLeftXffResolvesFirstUntrustedIp(): void
    {
        $config = new AnalyticsConfig(trustedProxies: ['10.0.0.1', '10.0.0.2']);
        $service = $this->makeServiceWithConfig($config);

        // Client 203.0.113.50 -> Proxy 10.0.0.2 -> Proxy 10.0.0.1 -> Server
        $request = $this->makeRequest('10.0.0.1', '203.0.113.50, 10.0.0.2');
        $ip = $this->invokeGetClientIp($service, $request);

        self::assertSame('203.0.113.50', $ip);
    }

    #[Test]
    public function singleHopXffStillWorks(): void
    {
        $config = new AnalyticsConfig(trustedProxies: ['10.0.0.1']);
        $service = $this->makeServiceWithConfig($config);

        $request = $this->makeRequest('10.0.0.1', '192.168.1.100');
        $ip = $this->invokeGetClientIp($service, $request);

        self::assertSame('192.168.1.100', $ip);
    }

    #[Test]
    public function untrustedRemoteAddrIgnoresXff(): void
    {
        $config = new AnalyticsConfig(trustedProxies: ['10.0.0.1']);
        $service = $this->makeServiceWithConfig($config);

        $request = $this->makeRequest('203.0.113.99', '10.10.10.10');
        $ip = $this->invokeGetClientIp($service, $request);

        self::assertSame('203.0.113.99', $ip);
    }

    #[Test]
    public function allTrustedProxiesInXffFallsBackToLeftmost(): void
    {
        $config = new AnalyticsConfig(trustedProxies: ['10.0.0.1', '10.0.0.2', '10.0.0.3']);
        $service = $this->makeServiceWithConfig($config);

        $request = $this->makeRequest('10.0.0.1', '10.0.0.3, 10.0.0.2');
        $ip = $this->invokeGetClientIp($service, $request);

        self::assertSame('10.0.0.3', $ip);
    }

    #[Test]
    public function emptyTrustedProxiesIgnoresXff(): void
    {
        $config = new AnalyticsConfig(trustedProxies: []);
        $service = $this->makeServiceWithConfig($config);

        $request = $this->makeRequest('1.2.3.4', '10.10.10.10');
        $ip = $this->invokeGetClientIp($service, $request);

        self::assertSame('1.2.3.4', $ip);
    }
}

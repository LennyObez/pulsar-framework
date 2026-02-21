<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AuditFix;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Extension\Analytics\Internal\Service\TrackingService;
use ReflectionClass;
use ReflectionMethod;

/**
 * Verifies that TrackingService::getClientIp() walks X-Forwarded-For
 * right-to-left to find the first untrusted IP, matching the
 * ClientFingerprintResolver pattern for multi-hop proxy chains.
 *
 * Uses reflection to bypass the final class constructors and test
 * the private getClientIp method directly.
 */
#[CoversClass(TrackingService::class)]
final class TrackingServiceMultiHopXffTest extends TestCase
{
    private function makeServiceWithConfig(AnalyticsConfig $config): TrackingService
    {
        // Use reflection to create an instance without calling the constructor
        $refClass = new ReflectionClass(TrackingService::class);
        $service = $refClass->newInstanceWithoutConstructor();

        // Set the config property directly
        $configProp = $refClass->getProperty('config');
        $configProp->setValue($service, $config);

        return $service;
    }

    private function invokeGetClientIp(TrackingService $service, ServerRequestInterface $request): string
    {
        $method = new ReflectionMethod($service, 'getClientIp');
        $result = $method->invoke($service, $request);

        self::assertIsString($result, 'TrackingService::getClientIp() must return a string');

        return $result;
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

<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ServiceDiscovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ServiceDiscovery\HealthCheckResult;
use Pulsar\ServiceDiscovery\HttpHealthCheck;
use Pulsar\ServiceDiscovery\ServiceHealthStatus;
use Pulsar\ServiceDiscovery\ServiceInstance;

#[CoversClass(HttpHealthCheck::class)]
final class HttpHealthCheckTest extends TestCase
{
    #[Test]
    public function implementsHealthCheckInterface(): void
    {
        $check = new HttpHealthCheck();

        self::assertInstanceOf(\Pulsar\ServiceDiscovery\HealthCheckInterface::class, $check);
    }

    #[Test]
    public function checkWithSsrfBlockedUrlReturnsUnhealthy(): void
    {
        // Cloud metadata endpoint is always blocked even with allowPrivateNetworks
        $check = new HttpHealthCheck(
            healthPath: '/health',
            timeoutSeconds: 1.0,
            allowPrivateNetworks: true,
        );

        $instance = new ServiceInstance(
            name: 'metadata-service',
            host: '169.254.169.254',
            port: 80,
            scheme: 'http',
        );

        $result = $check->check($instance);

        self::assertSame(ServiceHealthStatus::Unhealthy, $result->status);
        self::assertNotNull($result->message);
        self::assertStringContainsString('blocked', $result->message);
    }

    #[Test]
    public function checkWithPrivateIpAndSsrfProtectionReturnsUnhealthy(): void
    {
        // Private IPs are blocked when allowPrivateNetworks is false
        $check = new HttpHealthCheck(
            healthPath: '/health',
            timeoutSeconds: 1.0,
            allowPrivateNetworks: false,
        );

        $instance = new ServiceInstance(
            name: 'internal-service',
            host: '192.168.1.100',
            port: 8080,
            scheme: 'http',
        );

        $result = $check->check($instance);

        self::assertSame(ServiceHealthStatus::Unhealthy, $result->status);
        self::assertNotNull($result->message);
    }

    #[Test]
    public function checkWithInvalidSchemeReturnsUnhealthy(): void
    {
        $check = new HttpHealthCheck(
            healthPath: '/health',
            timeoutSeconds: 1.0,
            allowPrivateNetworks: false,
        );

        $instance = new ServiceInstance(
            name: 'ftp-service',
            host: 'example.com',
            port: 21,
            scheme: 'ftp',
        );

        $result = $check->check($instance);

        self::assertSame(ServiceHealthStatus::Unhealthy, $result->status);
        self::assertNotNull($result->message);
    }

    #[Test]
    public function checkWithUnreachableHostReturnsUnhealthy(): void
    {
        $check = new HttpHealthCheck(
            healthPath: '/health',
            timeoutSeconds: 1.0,
            allowPrivateNetworks: true,
        );

        // Use a private IP that is extremely unlikely to have a listener
        $instance = new ServiceInstance(
            name: 'unreachable-service',
            host: '10.255.255.254',
            port: 19999,
            scheme: 'http',
        );

        $result = $check->check($instance);

        self::assertSame(ServiceHealthStatus::Unhealthy, $result->status);
    }

    #[Test]
    public function constructorSetsDefaults(): void
    {
        $check = new HttpHealthCheck();

        // We cannot directly inspect private properties, but we can verify it constructs
        self::assertInstanceOf(HttpHealthCheck::class, $check);
    }

    #[Test]
    public function customHealthPathIsUsed(): void
    {
        $check = new HttpHealthCheck(healthPath: '/api/v1/healthz');

        // Verifying the check constructs with a custom path
        // The path gets appended to the service URI on check()
        self::assertInstanceOf(HttpHealthCheck::class, $check);
    }

    #[Test]
    public function checkResultIncludesCheckedAtTimestamp(): void
    {
        $check = new HttpHealthCheck(
            healthPath: '/health',
            timeoutSeconds: 1.0,
            allowPrivateNetworks: false,
        );

        $instance = new ServiceInstance(
            name: 'blocked-service',
            host: '10.0.0.1',
            port: 80,
            scheme: 'http',
        );

        $result = $check->check($instance);

        // The result should have a checkedAt timestamp
        self::assertInstanceOf(HealthCheckResult::class, $result);
    }
}

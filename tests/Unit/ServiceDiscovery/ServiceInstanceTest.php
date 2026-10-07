<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ServiceDiscovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ServiceDiscovery\ServiceInstance;
use ReflectionClass;

#[CoversClass(ServiceInstance::class)]
final class ServiceInstanceTest extends TestCase
{
    #[Test]
    public function constructsWithAllProperties(): void
    {
        $instance = new ServiceInstance(
            name: 'billing',
            host: 'billing.internal',
            port: 8443,
            scheme: 'https',
            healthy: true,
            metadata: ['version' => '2.1.0', 'region' => 'us-east-1'],
        );

        self::assertSame('billing', $instance->name);
        self::assertSame('billing.internal', $instance->host);
        self::assertSame(8443, $instance->port);
        self::assertSame('https', $instance->scheme);
        self::assertTrue($instance->healthy);
        self::assertSame(['version' => '2.1.0', 'region' => 'us-east-1'], $instance->metadata);
    }

    #[Test]
    public function usesDefaultValues(): void
    {
        $instance = new ServiceInstance(
            name: 'auth',
            host: 'auth.local',
            port: 443,
        );

        self::assertSame('https', $instance->scheme);
        self::assertTrue($instance->healthy);
        self::assertSame([], $instance->metadata);
    }

    #[Test]
    public function uriReturnsCorrectFormat(): void
    {
        $instance = new ServiceInstance(
            name: 'api',
            host: 'api.example.com',
            port: 8080,
            scheme: 'http',
        );

        self::assertSame('http://api.example.com:8080', $instance->uri());
    }

    #[Test]
    public function uriReturnsHttpsByDefault(): void
    {
        $instance = new ServiceInstance(
            name: 'gateway',
            host: 'gateway.internal',
            port: 443,
        );

        self::assertSame('https://gateway.internal:443', $instance->uri());
    }

    #[Test]
    public function isReadonly(): void
    {
        $reflection = new ReflectionClass(ServiceInstance::class);
        self::assertTrue($reflection->isReadOnly());
    }
}

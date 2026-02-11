<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ServiceDiscovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ServiceDiscovery\ServiceDiscoveryInterface;
use Pulsar\ServiceDiscovery\ServiceInstance;
use Pulsar\ServiceDiscovery\StaticServiceDiscovery;

#[CoversClass(StaticServiceDiscovery::class)]
final class StaticServiceDiscoveryTest extends TestCase
{
    #[Test]
    public function implementsServiceDiscoveryInterface(): void
    {
        $discovery = new StaticServiceDiscovery();

        self::assertInstanceOf(ServiceDiscoveryInterface::class, $discovery);
    }

    #[Test]
    public function fromArrayBuildsInstancesCorrectly(): void
    {
        $discovery = StaticServiceDiscovery::fromArray([
            'billing' => [
                ['host' => 'billing-1.internal', 'port' => 8443],
                ['host' => 'billing-2.internal', 'port' => 8443, 'scheme' => 'http', 'healthy' => false],
            ],
            'auth' => [
                ['host' => 'auth.internal', 'port' => 443, 'metadata' => ['version' => '3.0']],
            ],
        ]);

        $billingInstances = $discovery->instances('billing');
        self::assertCount(2, $billingInstances);

        self::assertSame('billing', $billingInstances[0]->name);
        self::assertSame('billing-1.internal', $billingInstances[0]->host);
        self::assertSame(8443, $billingInstances[0]->port);
        self::assertSame('https', $billingInstances[0]->scheme);
        self::assertTrue($billingInstances[0]->healthy);

        self::assertSame('http', $billingInstances[1]->scheme);
        self::assertFalse($billingInstances[1]->healthy);

        $authInstances = $discovery->instances('auth');
        self::assertCount(1, $authInstances);
        self::assertSame(['version' => '3.0'], $authInstances[0]->metadata);
    }

    #[Test]
    public function instancesReturnsAllInstancesForAService(): void
    {
        $discovery = new StaticServiceDiscovery();
        $discovery->register(new ServiceInstance('payments', 'pay-1.local', 8080));
        $discovery->register(new ServiceInstance('payments', 'pay-2.local', 8080));
        $discovery->register(new ServiceInstance('orders', 'orders.local', 9090));

        $instances = $discovery->instances('payments');

        self::assertCount(2, $instances);
        self::assertSame('pay-1.local', $instances[0]->host);
        self::assertSame('pay-2.local', $instances[1]->host);
    }

    #[Test]
    public function instancesReturnsEmptyArrayForUnknownService(): void
    {
        $discovery = new StaticServiceDiscovery();

        self::assertSame([], $discovery->instances('nonexistent'));
    }

    #[Test]
    public function instanceReturnsFirstHealthyInstance(): void
    {
        $discovery = new StaticServiceDiscovery();
        $discovery->register(new ServiceInstance('api', 'api-1.local', 8080, healthy: false));
        $discovery->register(new ServiceInstance('api', 'api-2.local', 8080, healthy: true));
        $discovery->register(new ServiceInstance('api', 'api-3.local', 8080, healthy: true));

        $instance = $discovery->instance('api');

        self::assertNotNull($instance);
        self::assertSame('api-2.local', $instance->host);
    }

    #[Test]
    public function instanceReturnsNullWhenNoHealthyInstances(): void
    {
        $discovery = new StaticServiceDiscovery();
        $discovery->register(new ServiceInstance('api', 'api-1.local', 8080, healthy: false));
        $discovery->register(new ServiceInstance('api', 'api-2.local', 8080, healthy: false));

        self::assertNull($discovery->instance('api'));
    }

    #[Test]
    public function instanceReturnsNullForUnknownService(): void
    {
        $discovery = new StaticServiceDiscovery();

        self::assertNull($discovery->instance('nonexistent'));
    }

    #[Test]
    public function servicesReturnsAllServiceNames(): void
    {
        $discovery = StaticServiceDiscovery::fromArray([
            'billing' => [
                ['host' => 'billing.local', 'port' => 8080],
            ],
            'auth' => [
                ['host' => 'auth.local', 'port' => 443],
            ],
            'notifications' => [
                ['host' => 'notify.local', 'port' => 9090],
            ],
        ]);

        $services = $discovery->services();

        self::assertCount(3, $services);
        self::assertContains('billing', $services);
        self::assertContains('auth', $services);
        self::assertContains('notifications', $services);
    }

    #[Test]
    public function servicesReturnsEmptyArrayWhenNoServicesRegistered(): void
    {
        $discovery = new StaticServiceDiscovery();

        self::assertSame([], $discovery->services());
    }

    #[Test]
    public function registerAddsAnInstance(): void
    {
        $discovery = new StaticServiceDiscovery();

        self::assertSame([], $discovery->instances('cache'));

        $discovery->register(new ServiceInstance('cache', 'redis.local', 6379, scheme: 'redis'));

        $instances = $discovery->instances('cache');
        self::assertCount(1, $instances);
        self::assertSame('redis.local', $instances[0]->host);
        self::assertSame(6379, $instances[0]->port);
        self::assertSame('redis', $instances[0]->scheme);
    }

    #[Test]
    public function deregisterRemovesAnInstance(): void
    {
        $discovery = new StaticServiceDiscovery();
        $discovery->register(new ServiceInstance('api', 'api-1.local', 8080));
        $discovery->register(new ServiceInstance('api', 'api-2.local', 8080));

        $discovery->deregister('api', 'api-1.local', 8080);

        $instances = $discovery->instances('api');
        self::assertCount(1, $instances);
        self::assertSame('api-2.local', $instances[0]->host);
    }

    #[Test]
    public function deregisterRemovesServiceNameWhenLastInstanceRemoved(): void
    {
        $discovery = new StaticServiceDiscovery();
        $discovery->register(new ServiceInstance('api', 'api-1.local', 8080));

        $discovery->deregister('api', 'api-1.local', 8080);

        self::assertSame([], $discovery->services());
        self::assertSame([], $discovery->instances('api'));
    }

    #[Test]
    public function deregisterIsNoopForUnknownService(): void
    {
        $discovery = new StaticServiceDiscovery();

        $discovery->deregister('nonexistent', 'host.local', 8080);

        self::assertSame([], $discovery->services());
    }

    #[Test]
    public function deregisterMatchesByHostAndPort(): void
    {
        $discovery = new StaticServiceDiscovery();
        $discovery->register(new ServiceInstance('api', 'api.local', 8080));
        $discovery->register(new ServiceInstance('api', 'api.local', 9090));

        $discovery->deregister('api', 'api.local', 8080);

        $instances = $discovery->instances('api');
        self::assertCount(1, $instances);
        self::assertSame(9090, $instances[0]->port);
    }
}

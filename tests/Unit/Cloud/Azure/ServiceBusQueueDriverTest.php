<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cloud\Azure;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\Azure\Config\AzureConfig;
use Pulsar\Cloud\Azure\ServiceBusQueueDriver;
use Pulsar\Cloud\CloudException;
use Pulsar\Queue\JobRecordStatus;
use ReflectionMethod;

#[CoversClass(ServiceBusQueueDriver::class)]
final class ServiceBusQueueDriverTest extends TestCase
{
    #[Test]
    public function queueUrlUsesNamespace(): void
    {
        $config = new AzureConfig(accessToken: 'test-token');
        $driver = new ServiceBusQueueDriver($config, 'my-namespace');

        $url = $this->callPrivateMethod($driver, 'queueUrl', 'emails');

        self::assertSame('https://my-namespace.servicebus.windows.net/emails', $url);
    }

    #[Test]
    public function queueUrlUsesCustomEndpoint(): void
    {
        $config = new AzureConfig(accessToken: 'test-token', endpoint: 'http://localhost:5672');
        $driver = new ServiceBusQueueDriver($config, 'test');

        $url = $this->callPrivateMethod($driver, 'queueUrl', 'my-queue');

        self::assertSame('http://localhost:5672/my-queue', $url);
    }

    #[Test]
    public function findByStatusReturnsEmptyWhenNoJobs(): void
    {
        $config = new AzureConfig(accessToken: 'test-token');
        $driver = new ServiceBusQueueDriver($config, 'test');

        self::assertSame([], $driver->findByStatus(JobRecordStatus::Pending));
    }

    #[Test]
    public function sizeReturnsZero(): void
    {
        $config = new AzureConfig(accessToken: 'test-token');
        $driver = new ServiceBusQueueDriver($config, 'test');

        self::assertSame(0, $driver->size('any-queue'));
    }

    #[Test]
    public function missingTokenThrowsOnPush(): void
    {
        $config = new AzureConfig();
        $driver = new ServiceBusQueueDriver($config, 'test');

        $this->expectException(CloudException::class);
        $this->expectExceptionMessageMatches('/access token not configured/i');

        $driver->push('test-queue', 'App\\Job', '{}');
    }

    #[Test]
    public function acknowledgeIgnoresUnknownJobId(): void
    {
        $config = new AzureConfig(accessToken: 'test-token');
        $driver = new ServiceBusQueueDriver($config, 'test');

        $driver->acknowledge('nonexistent-id');

        self::assertSame([], $driver->findByStatus(JobRecordStatus::Completed));
    }

    #[Test]
    public function rejectIgnoresUnknownJobId(): void
    {
        $config = new AzureConfig(accessToken: 'test-token');
        $driver = new ServiceBusQueueDriver($config, 'test');

        $driver->reject('nonexistent-id', 'test reason');

        self::assertSame([], $driver->findByStatus(JobRecordStatus::Failed));
    }

    private function callPrivateMethod(object $obj, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($obj, $method);

        return $ref->invoke($obj, ...$args);
    }
}

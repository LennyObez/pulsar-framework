<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cloud\Gcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\CloudException;
use Pulsar\Cloud\Gcp\Config\GcpConfig;
use Pulsar\Cloud\Gcp\PubSubQueueDriver;
use Pulsar\Queue\JobRecordStatus;
use ReflectionMethod;

#[CoversClass(PubSubQueueDriver::class)]
final class PubSubQueueDriverTest extends TestCase
{
    #[Test]
    public function topicPathIncludesProjectIdAndPrefix(): void
    {
        $config = new GcpConfig(projectId: 'my-project', accessToken: 'test-token');
        $driver = new PubSubQueueDriver($config, topicPrefix: 'app-');

        $path = $this->callPrivateMethod($driver, 'topicPath', 'emails');

        self::assertSame('projects/my-project/topics/app-emails', $path);
    }

    #[Test]
    public function subscriptionPathIncludesProjectIdAndPrefix(): void
    {
        $config = new GcpConfig(projectId: 'my-project', accessToken: 'test-token');
        $driver = new PubSubQueueDriver($config, subscriptionPrefix: 'worker-');

        $path = $this->callPrivateMethod($driver, 'subscriptionPath', 'emails');

        self::assertSame('projects/my-project/subscriptions/worker-emails', $path);
    }

    #[Test]
    public function findByStatusReturnsEmptyWhenNoJobs(): void
    {
        $config = new GcpConfig(projectId: 'test', accessToken: 'test-token');
        $driver = new PubSubQueueDriver($config);

        self::assertSame([], $driver->findByStatus(JobRecordStatus::Pending));
    }

    #[Test]
    public function sizeReturnsZero(): void
    {
        $config = new GcpConfig(projectId: 'test', accessToken: 'test-token');
        $driver = new PubSubQueueDriver($config);

        self::assertSame(0, $driver->size('any-queue'));
    }

    #[Test]
    public function missingTokenThrowsOnPush(): void
    {
        $config = new GcpConfig(projectId: 'test');
        $driver = new PubSubQueueDriver($config);

        $this->expectException(CloudException::class);
        $this->expectExceptionMessageMatches('/access token not configured/i');

        $driver->push('test-queue', 'App\\Job', '{}');
    }

    #[Test]
    public function acknowledgeIgnoresUnknownJobId(): void
    {
        $config = new GcpConfig(projectId: 'test', accessToken: 'test-token');
        $driver = new PubSubQueueDriver($config);

        $driver->acknowledge('nonexistent-id');

        self::assertSame([], $driver->findByStatus(JobRecordStatus::Completed));
    }

    #[Test]
    public function rejectIgnoresUnknownJobId(): void
    {
        $config = new GcpConfig(projectId: 'test', accessToken: 'test-token');
        $driver = new PubSubQueueDriver($config);

        $driver->reject('nonexistent-id', 'test reason');

        self::assertSame([], $driver->findByStatus(JobRecordStatus::Failed));
    }

    private function callPrivateMethod(object $obj, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($obj, $method);

        return $ref->invoke($obj, ...$args);
    }
}

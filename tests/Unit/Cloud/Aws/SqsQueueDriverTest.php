<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cloud\Aws;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\Aws\Config\AwsConfig;
use Pulsar\Cloud\Aws\SqsQueueDriver;
use Pulsar\Cloud\CloudException;
use Pulsar\Queue\JobRecordStatus;
use ReflectionMethod;

#[CoversClass(SqsQueueDriver::class)]
final class SqsQueueDriverTest extends TestCase
{
    #[Test]
    public function constructionWithDefaultSettings(): void
    {
        $config = new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET');
        $driver = new SqsQueueDriver($config);

        self::assertInstanceOf(SqsQueueDriver::class, $driver);
    }

    #[Test]
    public function queueUrlWithPrefix(): void
    {
        $config = new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET');
        $driver = new SqsQueueDriver($config, queuePrefix: 'https://sqs.us-east-1.amazonaws.com/123456789/');

        $url = $this->callPrivateMethod($driver, 'queueUrl', 'my-queue');

        self::assertSame('https://sqs.us-east-1.amazonaws.com/123456789/my-queue', $url);
    }

    #[Test]
    public function queueUrlWithoutPrefix(): void
    {
        $config = new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET');
        $driver = new SqsQueueDriver($config);

        $url = $this->callPrivateMethod($driver, 'queueUrl', 'my-queue');

        self::assertSame('my-queue', $url);
    }

    #[Test]
    public function fifoQueueUrlHasSuffix(): void
    {
        $config = new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET');
        $driver = new SqsQueueDriver($config, fifo: true);

        $url = $this->callPrivateMethod($driver, 'queueUrl', 'my-queue');

        self::assertSame('my-queue.fifo', $url);
    }

    #[Test]
    public function findByStatusReturnsEmptyWhenNoJobs(): void
    {
        $config = new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET');
        $driver = new SqsQueueDriver($config);

        $results = $driver->findByStatus(JobRecordStatus::Pending);

        self::assertSame([], $results);
    }

    #[Test]
    public function missingCredentialsThrowsOnPush(): void
    {
        $config = new AwsConfig(region: 'us-east-1');
        $driver = new SqsQueueDriver($config);

        $this->expectException(CloudException::class);
        $this->expectExceptionMessageMatches('/credentials not configured/i');

        $driver->push('test-queue', 'App\\Job', '{}');
    }

    #[Test]
    public function acknowledgeIgnoresUnknownJobId(): void
    {
        $config = new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET');
        $driver = new SqsQueueDriver($config);

        // Should not throw — verify no side effects by checking empty known jobs
        $driver->acknowledge('nonexistent-id');

        self::assertSame([], $driver->findByStatus(JobRecordStatus::Completed));
    }

    #[Test]
    public function rejectIgnoresUnknownJobId(): void
    {
        $config = new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET');
        $driver = new SqsQueueDriver($config);

        // Should not throw — verify no side effects
        $driver->reject('nonexistent-id', 'test reason');

        self::assertSame([], $driver->findByStatus(JobRecordStatus::Failed));
    }

    private function callPrivateMethod(object $obj, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($obj, $method);

        return $ref->invoke($obj, ...$args);
    }
}

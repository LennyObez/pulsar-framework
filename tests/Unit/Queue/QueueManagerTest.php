<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\QueueConfig;
use Pulsar\Config\QueueDriverType;
use Pulsar\Queue\Driver\InMemoryDriver;
use Pulsar\Queue\Driver\SyncDriver;
use Pulsar\Queue\Exception\QueueException;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Queue\QueueManager;

#[CoversClass(QueueManager::class)]
final class QueueManagerTest extends TestCase
{
    #[Test]
    public function it_dispatches_job_to_default_queue(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver
            ->expects(self::once())
            ->method('push')
            ->with('default', 'App\\Jobs\\SendEmail', '{"to":"a@b.com"}', 0)
            ->willReturn('job-001');

        $config = new QueueConfig(defaultQueue: 'default');
        $manager = new QueueManager($config, $driver);

        $id = $manager->dispatch('App\\Jobs\\SendEmail', '{"to":"a@b.com"}');

        self::assertSame('job-001', $id);
    }

    #[Test]
    public function it_dispatches_job_to_explicit_queue(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver
            ->expects(self::once())
            ->method('push')
            ->with('emails', 'App\\Jobs\\SendEmail', '{}', 0)
            ->willReturn('job-002');

        $config = new QueueConfig(defaultQueue: 'default');
        $manager = new QueueManager($config, $driver);

        $id = $manager->dispatch('App\\Jobs\\SendEmail', '{}', 'emails');

        self::assertSame('job-002', $id);
    }

    #[Test]
    public function it_dispatches_job_with_delay(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver
            ->expects(self::once())
            ->method('push')
            ->with('default', 'App\\Jobs\\Delayed', '{}', 60)
            ->willReturn('job-003');

        $config = new QueueConfig();
        $manager = new QueueManager($config, $driver);

        $id = $manager->dispatch('App\\Jobs\\Delayed', '{}', null, 60);

        self::assertSame('job-003', $id);
    }

    #[Test]
    public function it_returns_size_of_default_queue(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver
            ->expects(self::once())
            ->method('size')
            ->with('default')
            ->willReturn(5);

        $config = new QueueConfig(defaultQueue: 'default');
        $manager = new QueueManager($config, $driver);

        self::assertSame(5, $manager->size());
    }

    #[Test]
    public function it_returns_size_of_explicit_queue(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver
            ->expects(self::once())
            ->method('size')
            ->with('emails')
            ->willReturn(3);

        $config = new QueueConfig();
        $manager = new QueueManager($config, $driver);

        self::assertSame(3, $manager->size('emails'));
    }

    #[Test]
    public function it_returns_injected_driver(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $config = new QueueConfig();
        $manager = new QueueManager($config, $driver);

        self::assertSame($driver, $manager->driver());
    }

    #[Test]
    public function it_lazily_resolves_sync_driver(): void
    {
        $config = new QueueConfig(driver: QueueDriverType::Sync);
        $manager = new QueueManager($config);

        $resolved = $manager->driver();

        self::assertInstanceOf(SyncDriver::class, $resolved);
    }

    #[Test]
    public function it_lazily_resolves_memory_driver(): void
    {
        $config = new QueueConfig(driver: QueueDriverType::Memory);
        $manager = new QueueManager($config);

        $resolved = $manager->driver();

        self::assertInstanceOf(InMemoryDriver::class, $resolved);
    }

    #[Test]
    public function it_throws_for_database_driver_without_injection(): void
    {
        $config = new QueueConfig(driver: QueueDriverType::Database);
        $manager = new QueueManager($config);

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('not configured');

        $manager->driver();
    }

    #[Test]
    public function it_caches_resolved_driver(): void
    {
        $config = new QueueConfig(driver: QueueDriverType::Memory);
        $manager = new QueueManager($config);

        $first = $manager->driver();
        $second = $manager->driver();

        self::assertSame($first, $second);
    }

    #[Test]
    public function it_uses_config_default_queue_name(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver
            ->expects(self::once())
            ->method('push')
            ->with('my-custom-queue', 'App\\Jobs\\Noop', '{}', 0)
            ->willReturn('job-004');

        $config = new QueueConfig(defaultQueue: 'my-custom-queue');
        $manager = new QueueManager($config, $driver);

        $manager->dispatch('App\\Jobs\\Noop', '{}');
    }
}

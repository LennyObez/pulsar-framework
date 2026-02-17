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

use function is_array;
use function is_string;
use function json_decode;
use function str_contains;
use function strlen;

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
            ->with(
                'default',
                'App\\Jobs\\SendEmail',
                self::callback(static function (mixed $payload): bool {
                    if (!is_string($payload)) {
                        return false;
                    }
                    $decoded = json_decode($payload, true);
                    if (!is_array($decoded)) {
                        return false;
                    }

                    return $decoded['payload'] === '{"to":"a@b.com"}'
                        && $decoded['queue'] === 'default'
                        && $decoded['jobClass'] === 'App\\Jobs\\SendEmail';
                }),
                0,
            )
            ->willReturn('job-001');

        $config = new QueueConfig(defaultQueue: 'default');
        $manager = new QueueManager($config, $driver);

        $id = $manager->dispatch('App\\Jobs\\SendEmail', '{"to":"a@b.com"}');

        self::assertNotEmpty($id);
        self::assertSame(32, strlen($id));
    }

    #[Test]
    public function it_dispatches_job_to_explicit_queue(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver
            ->expects(self::once())
            ->method('push')
            ->with(
                'emails',
                'App\\Jobs\\SendEmail',
                self::callback(static function (mixed $payload): bool {
                    if (!is_string($payload)) {
                        return false;
                    }
                    $decoded = json_decode($payload, true);
                    if (!is_array($decoded)) {
                        return false;
                    }

                    return $decoded['queue'] === 'emails'
                        && $decoded['jobClass'] === 'App\\Jobs\\SendEmail';
                }),
                0,
            )
            ->willReturn('job-002');

        $config = new QueueConfig(defaultQueue: 'default');
        $manager = new QueueManager($config, $driver);

        $id = $manager->dispatch('App\\Jobs\\SendEmail', '{}', 'emails');

        self::assertNotEmpty($id);
    }

    #[Test]
    public function it_dispatches_job_with_delay(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver
            ->expects(self::once())
            ->method('push')
            ->with(
                'default',
                'App\\Jobs\\Delayed',
                self::callback(static function (mixed $payload): bool {
                    if (!is_string($payload)) {
                        return false;
                    }
                    $decoded = json_decode($payload, true);
                    if (!is_array($decoded)) {
                        return false;
                    }

                    return $decoded['jobClass'] === 'App\\Jobs\\Delayed'
                        && $decoded['queue'] === 'default';
                }),
                60,
            )
            ->willReturn('job-003');

        $config = new QueueConfig();
        $manager = new QueueManager($config, $driver);

        $id = $manager->dispatch('App\\Jobs\\Delayed', '{}', null, 60);

        self::assertNotEmpty($id);
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
            ->with(
                'my-custom-queue',
                'App\\Jobs\\Noop',
                self::callback(static function (mixed $payload): bool {
                    if (!is_string($payload)) {
                        return false;
                    }
                    $decoded = json_decode($payload, true);
                    if (!is_array($decoded)) {
                        return false;
                    }

                    return $decoded['queue'] === 'my-custom-queue';
                }),
                0,
            )
            ->willReturn('job-004');

        $config = new QueueConfig(defaultQueue: 'my-custom-queue');
        $manager = new QueueManager($config, $driver);

        $manager->dispatch('App\\Jobs\\Noop', '{}');
    }

    #[Test]
    public function it_includes_retry_config_in_envelope(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver
            ->expects(self::once())
            ->method('push')
            ->with(
                'default',
                'App\\Jobs\\Test',
                self::callback(static function (mixed $payload): bool {
                    if (!is_string($payload)) {
                        return false;
                    }
                    $decoded = json_decode($payload, true);
                    if (!is_array($decoded)) {
                        return false;
                    }

                    return $decoded['retryMaxAttempts'] === 5
                        && $decoded['retryDelayMs'] === 2000
                        && $decoded['attempt'] === 1
                        && $decoded['encrypted'] === false;
                }),
                0,
            )
            ->willReturn('job-005');

        $config = new QueueConfig(retryMaxAttempts: 5, retryBaseDelayMs: 2000);
        $manager = new QueueManager($config, $driver);

        $manager->dispatch('App\\Jobs\\Test', '{}');
    }

    #[Test]
    public function dispatch_returns_32_hex_character_id(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('push')->willReturn('driver-id');

        $config = new QueueConfig();
        $manager = new QueueManager($config, $driver);

        $id = $manager->dispatch('App\\Jobs\\Test', '{}');

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
    }

    #[Test]
    public function it_throws_for_new_driver_types_without_injection(): void
    {
        foreach ([QueueDriverType::Redis, QueueDriverType::Amqp, QueueDriverType::Sqs, QueueDriverType::PubSub] as $driverType) {
            $config = new QueueConfig(driver: $driverType);
            $manager = new QueueManager($config);

            try {
                $manager->driver();
                self::fail('Expected QueueException for driver type: ' . $driverType->value);
            } catch (QueueException $e) {
                self::assertTrue(str_contains($e->getMessage(), 'not configured'));
            }
        }
    }
}

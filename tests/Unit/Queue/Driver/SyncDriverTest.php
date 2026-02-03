<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Driver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Driver\SyncDriver;
use Pulsar\Queue\Exception\QueueException;
use Pulsar\Queue\JobContext;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueableInterface;

use function strlen;

#[CoversClass(SyncDriver::class)]
final class SyncDriverTest extends TestCase
{
    private SyncDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new SyncDriver();
    }

    #[Test]
    public function it_executes_job_immediately_on_push(): void
    {
        $id = $this->driver->push('default', SyncTestJob::class, '{}');

        self::assertNotEmpty($id);
        self::assertSame(32, strlen($id));
        self::assertTrue(SyncTestJob::$handled);
    }

    #[Test]
    public function it_throws_for_nonexistent_job_class(): void
    {
        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('serialize');

        $this->driver->push('default', 'NonExistent\\Job\\Class', '{}');
    }

    #[Test]
    public function it_throws_for_non_queueable_class(): void
    {
        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('serialize');

        $this->driver->push('default', SyncNonQueueableJob::class, '{}');
    }

    #[Test]
    public function pop_always_returns_null(): void
    {
        self::assertNull($this->driver->pop('default'));
    }

    #[Test]
    public function acknowledge_is_no_op(): void
    {
        $this->driver->acknowledge('any-id');

        // No exception thrown = success
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function reject_is_no_op(): void
    {
        $this->driver->reject('any-id', 'some reason');

        // No exception thrown = success
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function size_always_returns_zero(): void
    {
        self::assertSame(0, $this->driver->size('default'));
        self::assertSame(0, $this->driver->size('any-queue'));
    }

    #[Test]
    public function purge_always_returns_zero(): void
    {
        self::assertSame(0, $this->driver->purge('default'));
    }

    #[Test]
    public function find_by_status_always_returns_empty_array(): void
    {
        self::assertSame([], $this->driver->findByStatus(JobRecordStatus::Pending));
        self::assertSame([], $this->driver->findByStatus(JobRecordStatus::Failed));
        self::assertSame([], $this->driver->findByStatus(JobRecordStatus::Completed));
    }

    #[Test]
    public function it_provides_correct_job_context(): void
    {
        SyncContextCapturingJob::$capturedContext = null;

        $this->driver->push('my-queue', SyncContextCapturingJob::class, '{}');

        self::assertNotNull(SyncContextCapturingJob::$capturedContext);
        self::assertSame('my-queue', SyncContextCapturingJob::$capturedContext->queue);
        self::assertSame(1, SyncContextCapturingJob::$capturedContext->attempt);
        self::assertSame(3, SyncContextCapturingJob::$capturedContext->maxAttempts);
    }

    protected function tearDown(): void
    {
        SyncTestJob::$handled = false;
        SyncContextCapturingJob::$capturedContext = null;
    }
}

/**
 * @internal Test double
 */
final class SyncTestJob implements QueueableInterface
{
    public static bool $handled = false;

    public function handle(JobContext $context): void
    {
        self::$handled = true;
    }

    public function queue(): string
    {
        return 'default';
    }

    public function maxAttempts(): int
    {
        return 3;
    }

    public function timeout(): int
    {
        return 60;
    }
}

/**
 * @internal Test double — does not implement QueueableInterface
 */
final class SyncNonQueueableJob
{
    public function handle(): void {}
}

/**
 * @internal Test double — captures JobContext for assertion
 */
final class SyncContextCapturingJob implements QueueableInterface
{
    public static ?JobContext $capturedContext = null;

    public function handle(JobContext $context): void
    {
        self::$capturedContext = $context;
    }

    public function queue(): string
    {
        return 'default';
    }

    public function maxAttempts(): int
    {
        return 3;
    }

    public function timeout(): int
    {
        return 60;
    }
}

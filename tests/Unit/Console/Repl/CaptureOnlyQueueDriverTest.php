<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Repl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Repl\CaptureOnlyQueueDriver;
use Pulsar\Console\Repl\ReplSafeModeException;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;

#[CoversClass(CaptureOnlyQueueDriver::class)]
final class CaptureOnlyQueueDriverTest extends TestCase
{
    private QueueDriverInterface&Stub $inner;
    private CaptureOnlyQueueDriver $driver;

    protected function setUp(): void
    {
        $this->inner = $this->createStub(QueueDriverInterface::class);
        $this->driver = new CaptureOnlyQueueDriver($this->inner);
    }

    #[Test]
    public function pushCapturesWithoutDispatching(): void
    {
        $id = $this->driver->push('default', 'App\\Job', '{}', 0);

        self::assertStringStartsWith('repl_', $id);
        self::assertCount(1, $this->driver->getCaptured());
        self::assertSame('default', $this->driver->getCaptured()[0]['queue']);
        self::assertSame('App\\Job', $this->driver->getCaptured()[0]['jobClass']);
    }

    #[Test]
    public function multiplePushesAccumulate(): void
    {
        $this->driver->push('q1', 'Job1', '{}');
        $this->driver->push('q2', 'Job2', '{"key":"val"}');

        self::assertSame(2, $this->driver->getCapturedCount());
        self::assertCount(2, $this->driver->getCaptured());
    }

    #[Test]
    public function popThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);
        $this->expectExceptionMessageMatches('/queue:pop/');

        $this->driver->pop('default');
    }

    #[Test]
    public function acknowledgeThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);
        $this->expectExceptionMessageMatches('/queue:acknowledge/');

        $this->driver->acknowledge('job-1');
    }

    #[Test]
    public function rejectThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);
        $this->expectExceptionMessageMatches('/queue:reject/');

        $this->driver->reject('job-1', 'fail reason');
    }

    #[Test]
    public function sizeDelegates(): void
    {
        $this->inner->method('size')->with('default')->willReturn(42);

        self::assertSame(42, $this->driver->size('default'));
    }

    #[Test]
    public function purgeThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);
        $this->expectExceptionMessageMatches('/queue:purge/');

        $this->driver->purge('default');
    }

    #[Test]
    public function findByStatusDelegates(): void
    {
        $records = [
            new JobRecord('1', 'default', 'Job', '{}', 0, JobRecordStatus::Pending, 0, 0),
        ];
        $this->inner->method('findByStatus')->with(JobRecordStatus::Pending)->willReturn($records);

        self::assertSame($records, $this->driver->findByStatus(JobRecordStatus::Pending));
    }

    #[Test]
    public function eachPushReturnsUniqueId(): void
    {
        $id1 = $this->driver->push('q', 'Job', '{}');
        $id2 = $this->driver->push('q', 'Job', '{}');

        self::assertNotSame($id1, $id2);
    }
}

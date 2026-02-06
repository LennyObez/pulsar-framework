<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Driver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Queue\Driver\DatabaseDriver;
use Pulsar\Queue\JobRecordStatus;

use function strlen;

#[CoversClass(DatabaseDriver::class)]
final class DatabaseDriverTest extends TestCase
{
    private ConnectionManagerInterface $connectionManager;
    /** @var MockObject&ConnectionInterface */
    private ConnectionInterface $connection;
    private DatabaseDriver $driver;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connectionManager = $this->createStub(ConnectionManagerInterface::class);
        $this->connectionManager
            ->method('connection')
            ->willReturn($this->connection);

        $this->driver = new DatabaseDriver($this->connectionManager);
    }

    #[Test]
    public function it_pushes_job_by_executing_insert(): void
    {
        $this->connection
            ->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('INSERT INTO queue_jobs'),
                self::callback(static function (array $bindings): bool {
                    return $bindings['queue'] === 'default'
                        && $bindings['job_class'] === 'App\\Jobs\\SendEmail'
                        && $bindings['payload'] === '{"to":"user@test.com"}'
                        && $bindings['status'] === 'pending'
                        && $bindings['attempts'] === 0;
                }),
            );

        $id = $this->driver->push('default', 'App\\Jobs\\SendEmail', '{"to":"user@test.com"}');

        self::assertNotEmpty($id);
        self::assertSame(32, strlen($id));
    }

    #[Test]
    public function it_pushes_job_with_delay(): void
    {
        $this->connection
            ->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('INSERT INTO queue_jobs'),
                self::callback(static function (array $bindings): bool {
                    // available_at should be offset by the delay
                    return $bindings['available_at'] > $bindings['created_at'];
                }),
            );

        $this->driver->push('default', 'App\\Jobs\\Delayed', '{}', 60);
    }

    #[Test]
    public function it_pops_job_using_transaction(): void
    {
        $row = new Row([
            'id' => 'abc123',
            'queue' => 'default',
            'job_class' => 'App\\Jobs\\Noop',
            'payload' => '{}',
            'attempts' => 0,
            'status' => 'pending',
            'created_at' => 1700000000,
            'available_at' => 1700000000,
        ]);

        $result = new Result([$row]);

        $this->connection
            ->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(function (callable $callback) {
                return $callback($this->connection);
            });

        $this->connection
            ->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('FOR UPDATE SKIP LOCKED'),
                self::isArray(),
            )
            ->willReturn($result);

        $this->connection
            ->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('UPDATE queue_jobs'),
                self::callback(static function (array $bindings): bool {
                    return $bindings['status'] === 'processing'
                        && $bindings['attempts'] === 1
                        && $bindings['id'] === 'abc123';
                }),
            );

        $record = $this->driver->pop('default');

        self::assertNotNull($record);
        self::assertSame('abc123', $record->id);
        self::assertSame('default', $record->queue);
        self::assertSame('App\\Jobs\\Noop', $record->jobClass);
        self::assertSame('{}', $record->payload);
        self::assertSame(1, $record->attempts);
        self::assertSame(JobRecordStatus::Processing, $record->status);
    }

    #[Test]
    public function it_returns_null_when_pop_finds_no_job(): void
    {
        $emptyResult = new Result([]);

        $this->connection
            ->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(function (callable $callback) {
                return $callback($this->connection);
            });

        $this->connection
            ->method('query')
            ->willReturn($emptyResult);

        $record = $this->driver->pop('default');

        self::assertNull($record);
    }

    #[Test]
    public function it_acknowledges_by_deleting_row(): void
    {
        $this->connection
            ->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('DELETE FROM queue_jobs'),
                self::identicalTo(['id' => 'job-to-ack']),
            );

        $this->driver->acknowledge('job-to-ack');
    }

    #[Test]
    public function it_rejects_by_updating_status_to_failed(): void
    {
        $this->connection
            ->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('UPDATE queue_jobs SET status'),
                self::callback(static function (array $bindings): bool {
                    return $bindings['status'] === 'failed'
                        && $bindings['id'] === 'job-to-reject';
                }),
            );

        $this->driver->reject('job-to-reject', 'Connection timed out');
    }

    #[Test]
    public function it_queries_size_with_count(): void
    {
        $row = new Row(['cnt' => 5]);
        $result = new Result([$row]);

        $this->connection
            ->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('COUNT(*)'),
                self::callback(static function (array $bindings): bool {
                    return $bindings['queue'] === 'emails'
                        && $bindings['status'] === 'pending';
                }),
            )
            ->willReturn($result);

        $size = $this->driver->size('emails');

        self::assertSame(5, $size);
    }

    #[Test]
    public function it_returns_zero_size_for_empty_result(): void
    {
        $emptyResult = new Result([]);

        $this->connection
            ->expects(self::once())
            ->method('query')
            ->willReturn($emptyResult);

        $size = $this->driver->size('empty-queue');

        self::assertSame(0, $size);
    }

    #[Test]
    public function it_purges_by_deleting_all_queue_jobs(): void
    {
        $this->connection
            ->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('DELETE FROM queue_jobs'),
                self::identicalTo(['queue' => 'stale-queue']),
            )
            ->willReturn(3);

        $purged = $this->driver->purge('stale-queue');

        self::assertSame(3, $purged);
    }

    #[Test]
    public function it_finds_jobs_by_status(): void
    {
        $row1 = new Row([
            'id' => 'j1',
            'queue' => 'default',
            'job_class' => 'App\\Jobs\\One',
            'payload' => '{}',
            'attempts' => 3,
            'status' => 'failed',
            'created_at' => 1700000000,
            'available_at' => 1700000000,
        ]);

        $row2 = new Row([
            'id' => 'j2',
            'queue' => 'emails',
            'job_class' => 'App\\Jobs\\Two',
            'payload' => '{"data":1}',
            'attempts' => 1,
            'status' => 'failed',
            'created_at' => 1700000010,
            'available_at' => 1700000010,
        ]);

        $result = new Result([$row1, $row2]);

        $this->connection
            ->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('WHERE status = :status'),
                self::identicalTo(['status' => 'failed']),
            )
            ->willReturn($result);

        $records = $this->driver->findByStatus(JobRecordStatus::Failed);

        self::assertCount(2, $records);
        self::assertSame('j1', $records[0]->id);
        self::assertSame(JobRecordStatus::Failed, $records[0]->status);
        self::assertSame('j2', $records[1]->id);
        self::assertSame('emails', $records[1]->queue);
    }
}

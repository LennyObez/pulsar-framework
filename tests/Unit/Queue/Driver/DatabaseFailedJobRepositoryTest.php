<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Driver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Queue\Driver\DatabaseFailedJobRepository;
use Pulsar\Queue\FailedJob;

use function array_map;

#[CoversClass(DatabaseFailedJobRepository::class)]
final class DatabaseFailedJobRepositoryTest extends TestCase
{
    private PdoConnection $connection;
    private DatabaseFailedJobRepository $repository;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'dlq-test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $this->repository = new DatabaseFailedJobRepository($this->connection);
        $this->repository->installSchema();
    }

    #[Test]
    public function storeThenFindRoundTripsAllFields(): void
    {
        $this->repository->store($this->makeJob('j1'));

        $found = $this->repository->find('j1');

        self::assertNotNull($found);
        self::assertSame('j1', $found->id);
        self::assertSame('emails', $found->queue);
        self::assertSame('App\\Jobs\\Send', $found->jobClass);
        self::assertSame('{"to":"a@b.com"}', $found->payload);
        self::assertSame('Connection refused', $found->exception);
        self::assertSame(1700000000, $found->failedAt);
        self::assertSame(4, $found->attempts);
    }

    #[Test]
    public function findReturnsNullForUnknownId(): void
    {
        self::assertNull($this->repository->find('missing'));
    }

    #[Test]
    public function storeUpsertsByIdWithoutDuplicating(): void
    {
        $this->repository->store($this->makeJob('j1', 'first'));
        $this->repository->store($this->makeJob('j1', 'second'));

        self::assertCount(1, $this->repository->all());
        self::assertSame('second', $this->repository->find('j1')?->exception);
    }

    #[Test]
    public function forgetReportsWhetherRowExisted(): void
    {
        $this->repository->store($this->makeJob('j1'));

        self::assertTrue($this->repository->forget('j1'));
        self::assertFalse($this->repository->forget('j1'));
    }

    #[Test]
    public function flushClearsAndReturnsCount(): void
    {
        $this->repository->store($this->makeJob('j1'));
        $this->repository->store($this->makeJob('j2'));

        self::assertSame(2, $this->repository->flush());
        self::assertSame(0, $this->repository->count());
    }

    #[Test]
    public function allReturnsJobsOldestFirst(): void
    {
        $this->repository->store($this->makeJob('newer', failedAt: 2000));
        $this->repository->store($this->makeJob('older', failedAt: 1000));

        $ids = array_map(static fn(FailedJob $j): string => $j->id, $this->repository->all());

        self::assertSame(['older', 'newer'], $ids);
    }

    #[Test]
    public function dataSurvivesAcrossRepositoryInstancesOnTheSameConnection(): void
    {
        $this->repository->store($this->makeJob('persist'));

        // A new repository instance (e.g. after a worker recycle) sharing the
        // same durable connection still sees the row — the persistence guarantee
        // the original process-local array could not provide.
        $reopened = new DatabaseFailedJobRepository($this->connection);

        self::assertNotNull($reopened->find('persist'));
        self::assertSame(1, $reopened->count());
    }

    private function makeJob(string $id, string $exception = 'Connection refused', int $failedAt = 1700000000): FailedJob
    {
        return new FailedJob(
            id: $id,
            queue: 'emails',
            jobClass: 'App\\Jobs\\Send',
            payload: '{"to":"a@b.com"}',
            exception: $exception,
            failedAt: $failedAt,
            attempts: 4,
        );
    }
}

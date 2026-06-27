<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Driver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Driver\InMemoryFailedJobRepository;
use Pulsar\Queue\FailedJob;

#[CoversClass(InMemoryFailedJobRepository::class)]
final class InMemoryFailedJobRepositoryTest extends TestCase
{
    private InMemoryFailedJobRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryFailedJobRepository();
    }

    #[Test]
    public function storeThenFindReturnsTheSameJob(): void
    {
        $job = $this->makeJob('j1');
        $this->repository->store($job);

        self::assertSame($job, $this->repository->find('j1'));
    }

    #[Test]
    public function findReturnsNullForUnknownId(): void
    {
        self::assertNull($this->repository->find('missing'));
    }

    #[Test]
    public function storeIsAnIdempotentUpsertById(): void
    {
        $this->repository->store($this->makeJob('j1', 'first'));
        $this->repository->store($this->makeJob('j1', 'second'));

        self::assertCount(1, $this->repository->all());
        self::assertSame('second', $this->repository->find('j1')?->exception);
    }

    #[Test]
    public function allReturnsEveryStoredJob(): void
    {
        $this->repository->store($this->makeJob('j1'));
        $this->repository->store($this->makeJob('j2'));

        self::assertCount(2, $this->repository->all());
    }

    #[Test]
    public function forgetRemovesAndReportsWhetherPresent(): void
    {
        $this->repository->store($this->makeJob('j1'));

        self::assertTrue($this->repository->forget('j1'));
        self::assertFalse($this->repository->forget('j1'));
        self::assertNull($this->repository->find('j1'));
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
    public function countReflectsState(): void
    {
        self::assertSame(0, $this->repository->count());
        $this->repository->store($this->makeJob('j1'));
        self::assertSame(1, $this->repository->count());
    }

    private function makeJob(string $id, string $exception = 'boom'): FailedJob
    {
        return new FailedJob(
            id: $id,
            queue: 'default',
            jobClass: 'App\\Jobs\\X',
            payload: '{}',
            exception: $exception,
            failedAt: 1700000000,
            attempts: 3,
        );
    }
}

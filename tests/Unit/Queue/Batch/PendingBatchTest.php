<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Batch;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Batch\JobBatch;
use Pulsar\Queue\Batch\PendingBatch;
use Pulsar\Queue\QueueableInterface;

#[CoversClass(PendingBatch::class)]
final class PendingBatchTest extends TestCase
{
    #[Test]
    public function startsWithEmptyDefaults(): void
    {
        $pending = new PendingBatch();

        self::assertSame([], $pending->jobs);
        self::assertSame([], $pending->thenCallbacks);
        self::assertSame([], $pending->catchCallbacks);
        self::assertSame([], $pending->finallyCallbacks);
        self::assertSame('', $pending->name);
        self::assertFalse($pending->allowFailures);
    }

    #[Test]
    public function addJobReturnsSelfAndAccumulatesJobs(): void
    {
        $pending = new PendingBatch();
        $job1 = $this->createStub(QueueableInterface::class);
        $job2 = $this->createStub(QueueableInterface::class);

        $result = $pending->add($job1);
        self::assertSame($pending, $result);

        $pending->add($job2);
        self::assertCount(2, $pending->jobs);
        self::assertSame($job1, $pending->jobs[0]);
        self::assertSame($job2, $pending->jobs[1]);
    }

    #[Test]
    public function thenRegistersCallbackAndReturnsSelf(): void
    {
        $pending = new PendingBatch();
        $callback = static function (JobBatch $batch): void {};

        $result = $pending->then($callback);

        self::assertSame($pending, $result);
        self::assertCount(1, $pending->thenCallbacks);
        self::assertSame($callback, $pending->thenCallbacks[0]);
    }

    #[Test]
    public function catchRegistersCallbackAndReturnsSelf(): void
    {
        $pending = new PendingBatch();
        $callback = static function (JobBatch $batch): void {};

        $result = $pending->catch($callback);

        self::assertSame($pending, $result);
        self::assertCount(1, $pending->catchCallbacks);
        self::assertSame($callback, $pending->catchCallbacks[0]);
    }

    #[Test]
    public function finallyRegistersCallbackAndReturnsSelf(): void
    {
        $pending = new PendingBatch();
        $callback = static function (JobBatch $batch): void {};

        $result = $pending->finally($callback);

        self::assertSame($pending, $result);
        self::assertCount(1, $pending->finallyCallbacks);
        self::assertSame($callback, $pending->finallyCallbacks[0]);
    }

    #[Test]
    public function nameSetsBatchNameAndReturnsSelf(): void
    {
        $pending = new PendingBatch();

        $result = $pending->name('user-import');

        self::assertSame($pending, $result);
        self::assertSame('user-import', $pending->name);
    }

    #[Test]
    public function allowFailuresEnablesAndReturnsSelf(): void
    {
        $pending = new PendingBatch();

        $result = $pending->allowFailures();

        self::assertSame($pending, $result);
        self::assertTrue($pending->allowFailures);
    }

    #[Test]
    public function fluentChainingWorksAcrossAllMethods(): void
    {
        $pending = new PendingBatch();
        $job = $this->createStub(QueueableInterface::class);

        $pending
            ->add($job)
            ->name('full-chain')
            ->then(static function (JobBatch $b): void {})
            ->catch(static function (JobBatch $b): void {})
            ->finally(static function (JobBatch $b): void {})
            ->allowFailures();

        self::assertCount(1, $pending->jobs);
        self::assertSame('full-chain', $pending->name);
        self::assertCount(1, $pending->thenCallbacks);
        self::assertCount(1, $pending->catchCallbacks);
        self::assertCount(1, $pending->finallyCallbacks);
        self::assertTrue($pending->allowFailures);
    }
}

<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience\Repair;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\Exception\ResilienceException;
use Pulsar\Resilience\Repair\RepairDiagnosis;
use Pulsar\Resilience\Repair\RepairJobInterface;
use Pulsar\Resilience\Repair\RepairResult;
use Pulsar\Resilience\Repair\RepairRunner;
use RuntimeException;
use Throwable;

#[CoversClass(RepairRunner::class)]
final class RepairRunnerTest extends TestCase
{
    #[Test]
    public function diagnoseAllReturnsAllDiagnoses(): void
    {
        $runner = new RepairRunner();
        $runner->register($this->createJob(
            'cache-warmup',
            'Warm the cache',
            new RepairDiagnosis(repairJobName: 'cache-warmup', needsRepair: true, description: 'Cache cold'),
            new RepairResult(repairJobName: 'cache-warmup', success: true, description: 'Warmed'),
        ));
        $runner->register($this->createJob(
            'log-rotation',
            'Rotate logs',
            new RepairDiagnosis(repairJobName: 'log-rotation', needsRepair: false, description: 'Logs OK'),
            new RepairResult(repairJobName: 'log-rotation', success: true, description: 'Rotated'),
        ));

        $diagnoses = $runner->diagnoseAll();

        self::assertCount(2, $diagnoses);
        self::assertSame('cache-warmup', $diagnoses[0]->repairJobName);
        self::assertTrue($diagnoses[0]->needsRepair);
        self::assertSame('log-rotation', $diagnoses[1]->repairJobName);
        self::assertFalse($diagnoses[1]->needsRepair);
    }

    #[Test]
    public function repairAllOnlyRepairsJobsThatNeedRepair(): void
    {
        $runner = new RepairRunner();
        $runner->register($this->createJob(
            'needs-repair',
            'Fix something',
            new RepairDiagnosis(repairJobName: 'needs-repair', needsRepair: true, description: 'broken'),
            new RepairResult(repairJobName: 'needs-repair', success: true, description: 'fixed', actionsPerformed: ['did thing']),
        ));
        $runner->register($this->createJob(
            'all-good',
            'Nothing to do',
            new RepairDiagnosis(repairJobName: 'all-good', needsRepair: false, description: 'fine'),
            new RepairResult(repairJobName: 'all-good', success: true, description: 'n/a'),
        ));

        $results = $runner->repairAll();

        self::assertCount(1, $results);
        self::assertSame('needs-repair', $results[0]->repairJobName);
        self::assertTrue($results[0]->success);
        self::assertSame(['did thing'], $results[0]->actionsPerformed);
    }

    #[Test]
    public function repairAllCatchesExceptionsFromFailingRepairs(): void
    {
        $runner = new RepairRunner();
        $runner->register($this->createThrowingJob(
            'broken-repair',
            'Repair that fails',
            new RepairDiagnosis(repairJobName: 'broken-repair', needsRepair: true, description: 'needs fix'),
            new RuntimeException('repair exploded'),
        ));

        $results = $runner->repairAll();

        self::assertCount(1, $results);
        self::assertSame('broken-repair', $results[0]->repairJobName);
        self::assertFalse($results[0]->success);
        self::assertStringContainsString('repair exploded', $results[0]->description);
        self::assertNotNull($results[0]->exception);
    }

    #[Test]
    public function repairRunsSpecificJob(): void
    {
        $runner = new RepairRunner();
        $runner->register($this->createJob(
            'test-job',
            'Test repair',
            new RepairDiagnosis(repairJobName: 'test-job', needsRepair: true, description: 'test issue'),
            new RepairResult(repairJobName: 'test-job', success: true, description: 'fixed', actionsPerformed: ['did thing']),
        ));

        $result = $runner->repair('test-job');

        self::assertSame('test-job', $result->repairJobName);
        self::assertTrue($result->success);
    }

    #[Test]
    public function repairThrowsForUnregisteredJob(): void
    {
        $runner = new RepairRunner();

        $this->expectException(ResilienceException::class);
        $this->expectExceptionMessage('repair job not registered');

        $runner->repair('nonexistent');
    }

    private function createJob(
        string $name,
        string $description,
        RepairDiagnosis $diagnosis,
        RepairResult $repairResult,
    ): RepairJobInterface {
        return new class ($name, $description, $diagnosis, $repairResult) implements RepairJobInterface {
            public function __construct(
                private readonly string $name,
                private readonly string $description,
                private readonly RepairDiagnosis $diagnosis,
                private readonly RepairResult $repairResult,
            ) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getDescription(): string
            {
                return $this->description;
            }

            public function diagnose(): RepairDiagnosis
            {
                return $this->diagnosis;
            }

            public function repair(): RepairResult
            {
                return $this->repairResult;
            }
        };
    }

    private function createThrowingJob(
        string $name,
        string $description,
        RepairDiagnosis $diagnosis,
        Throwable $exception,
    ): RepairJobInterface {
        return new class ($name, $description, $diagnosis, $exception) implements RepairJobInterface {
            public function __construct(
                private readonly string $name,
                private readonly string $description,
                private readonly RepairDiagnosis $diagnosis,
                private readonly Throwable $exception,
            ) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getDescription(): string
            {
                return $this->description;
            }

            public function diagnose(): RepairDiagnosis
            {
                return $this->diagnosis;
            }

            public function repair(): RepairResult
            {
                throw $this->exception;
            }
        };
    }
}

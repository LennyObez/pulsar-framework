<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience\Repair;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\Exception\ResilienceException;
use Pulsar\Resilience\Repair\RepairDiagnosis;
use Pulsar\Resilience\Repair\RepairJobInterface;
use Pulsar\Resilience\Repair\RepairResult;
use Pulsar\Resilience\Repair\RepairRunner;
use RuntimeException;

#[CoversClass(RepairRunner::class)]
#[CoversClass(RepairDiagnosis::class)]
#[CoversClass(RepairResult::class)]
final class RepairRunnerCoverageTest extends TestCase
{
    #[Test]
    public function diagnoseAllReturnsAllDiagnoses(): void
    {
        $runner = new RepairRunner();

        $job1 = $this->createRepairJob('cache-rebuild', new RepairDiagnosis(
            repairJobName: 'cache-rebuild',
            needsRepair: true,
            description: 'Cache is stale',
            findings: ['Stale entries detected'],
        ));

        $job2 = $this->createRepairJob('index-repair', new RepairDiagnosis(
            repairJobName: 'index-repair',
            needsRepair: false,
            description: 'Index is healthy',
        ));

        $runner->register($job1);
        $runner->register($job2);

        $diagnoses = $runner->diagnoseAll();

        self::assertCount(2, $diagnoses);
        self::assertTrue($diagnoses[0]->needsRepair);
        self::assertFalse($diagnoses[1]->needsRepair);
    }

    #[Test]
    public function repairAllOnlyRepairsThoseNeedingRepair(): void
    {
        $runner = new RepairRunner();

        $job1 = $this->createRepairJobWithRepair(
            'needs-repair',
            new RepairDiagnosis('needs-repair', true, 'Broken'),
            new RepairResult('needs-repair', true, 'Fixed', ['Rebuilt cache']),
        );

        $job2 = $this->createRepairJob('ok-job', new RepairDiagnosis('ok-job', false, 'Healthy'));

        $runner->register($job1);
        $runner->register($job2);

        $results = $runner->repairAll();

        self::assertCount(1, $results);
        self::assertTrue($results[0]->success);
        self::assertSame('needs-repair', $results[0]->repairJobName);
    }

    #[Test]
    public function repairAllCatchesExceptionsDuringRepair(): void
    {
        $runner = new RepairRunner();

        $job = $this->createStub(RepairJobInterface::class);
        $job->method('getName')->willReturn('failing-job');
        $job->method('diagnose')->willReturn(new RepairDiagnosis('failing-job', true, 'Broken'));
        $job->method('repair')->willThrowException(new RuntimeException('disk full'));

        $runner->register($job);

        $results = $runner->repairAll();

        self::assertCount(1, $results);
        self::assertFalse($results[0]->success);
        self::assertStringContainsString('disk full', $results[0]->description);
        self::assertInstanceOf(RuntimeException::class, $results[0]->exception);
    }

    #[Test]
    public function repairByNameRunsSpecificJob(): void
    {
        $runner = new RepairRunner();

        $expectedResult = new RepairResult('cache-rebuild', true, 'Cache rebuilt', ['Cleared 42 entries']);
        $job = $this->createRepairJobWithRepair(
            'cache-rebuild',
            new RepairDiagnosis('cache-rebuild', true, 'Stale'),
            $expectedResult,
        );

        $runner->register($job);

        $result = $runner->repair('cache-rebuild');

        self::assertTrue($result->success);
        self::assertSame('Cache rebuilt', $result->description);
        self::assertSame(['Cleared 42 entries'], $result->actionsPerformed);
    }

    #[Test]
    public function repairByNameThrowsForUnregistered(): void
    {
        $runner = new RepairRunner();

        $this->expectException(ResilienceException::class);
        $this->expectExceptionMessageIsOrContains('not registered');

        $runner->repair('unknown-job');
    }

    #[Test]
    public function namesReturnsRegisteredJobNames(): void
    {
        $runner = new RepairRunner();

        self::assertSame([], $runner->names());

        $runner->register($this->createRepairJob('a', new RepairDiagnosis('a', false, 'ok')));
        $runner->register($this->createRepairJob('b', new RepairDiagnosis('b', false, 'ok')));

        self::assertSame(['a', 'b'], $runner->names());
    }

    #[Test]
    public function repairAllWithNoJobsReturnsEmpty(): void
    {
        $runner = new RepairRunner();

        $results = $runner->repairAll();

        self::assertSame([], $results);
    }

    #[Test]
    public function diagnoseAllWithNoJobsReturnsEmpty(): void
    {
        $runner = new RepairRunner();

        $diagnoses = $runner->diagnoseAll();

        self::assertSame([], $diagnoses);
    }

    #[Test]
    public function repairDiagnosisProperties(): void
    {
        $diagnosis = new RepairDiagnosis(
            repairJobName: 'test-job',
            needsRepair: true,
            description: 'Detected corruption',
            findings: ['finding 1', 'finding 2'],
        );

        self::assertSame('test-job', $diagnosis->repairJobName);
        self::assertTrue($diagnosis->needsRepair);
        self::assertSame('Detected corruption', $diagnosis->description);
        self::assertSame(['finding 1', 'finding 2'], $diagnosis->findings);
    }

    #[Test]
    public function repairResultProperties(): void
    {
        $exception = new RuntimeException('error');
        $result = new RepairResult(
            repairJobName: 'job',
            success: false,
            description: 'Failed',
            actionsPerformed: ['action-1'],
            exception: $exception,
        );

        self::assertSame('job', $result->repairJobName);
        self::assertFalse($result->success);
        self::assertSame('Failed', $result->description);
        self::assertSame(['action-1'], $result->actionsPerformed);
        self::assertSame($exception, $result->exception);
    }

    #[Test]
    public function repairResultWithDefaults(): void
    {
        $result = new RepairResult(
            repairJobName: 'job',
            success: true,
            description: 'Done',
        );

        self::assertSame([], $result->actionsPerformed);
        self::assertNull($result->exception);
    }

    /**
     * @return RepairJobInterface&Stub
     */
    private function createRepairJob(string $name, RepairDiagnosis $diagnosis): RepairJobInterface
    {
        $job = $this->createStub(RepairJobInterface::class);
        $job->method('getName')->willReturn($name);
        $job->method('diagnose')->willReturn($diagnosis);

        return $job;
    }

    /**
     * @return RepairJobInterface&Stub
     */
    private function createRepairJobWithRepair(
        string $name,
        RepairDiagnosis $diagnosis,
        RepairResult $result,
    ): RepairJobInterface {
        $job = $this->createStub(RepairJobInterface::class);
        $job->method('getName')->willReturn($name);
        $job->method('diagnose')->willReturn($diagnosis);
        $job->method('repair')->willReturn($result);

        return $job;
    }
}

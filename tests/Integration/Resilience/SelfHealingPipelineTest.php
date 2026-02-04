<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Resilience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\HealthCheck\HealthCheckInterface;
use Pulsar\Resilience\HealthCheck\HealthCheckResult;
use Pulsar\Resilience\HealthCheck\HealthCheckRunner;
use Pulsar\Resilience\HealthCheck\HealthReport;
use Pulsar\Resilience\HealthCheck\HealthStatus;
use Pulsar\Resilience\Repair\RepairDiagnosis;
use Pulsar\Resilience\Repair\RepairJobInterface;
use Pulsar\Resilience\Repair\RepairResult;
use Pulsar\Resilience\Repair\RepairRunner;

#[CoversClass(HealthCheckRunner::class)]
#[CoversClass(HealthCheckResult::class)]
#[CoversClass(HealthReport::class)]
#[CoversClass(RepairRunner::class)]
#[CoversClass(RepairDiagnosis::class)]
#[CoversClass(RepairResult::class)]
final class SelfHealingPipelineTest extends TestCase
{
    #[Test]
    public function healthCheckRunnerWithMultipleChecks(): void
    {
        $runner = new HealthCheckRunner();

        $runner->register(new class implements HealthCheckInterface {
            public function getName(): string
            {
                return 'database';
            }

            public function check(): HealthCheckResult
            {
                return HealthCheckResult::healthy('database', 'Connection OK');
            }
        });

        $runner->register(new class implements HealthCheckInterface {
            public function getName(): string
            {
                return 'cache';
            }

            public function check(): HealthCheckResult
            {
                return HealthCheckResult::unhealthy('cache', 'Redis connection refused');
            }
        });

        $runner->register(new class implements HealthCheckInterface {
            public function getName(): string
            {
                return 'disk';
            }

            public function check(): HealthCheckResult
            {
                return HealthCheckResult::degraded('disk', 'Disk usage at 85%');
            }
        });

        $report = $runner->runAll();

        // Overall status should be the worst (unhealthy)
        self::assertSame(HealthStatus::Unhealthy, $report->overallStatus);
        self::assertFalse($report->isHealthy());
        self::assertCount(3, $report->results);

        // Verify individual results
        $resultsByName = [];
        foreach ($report->results as $result) {
            $resultsByName[$result->name] = $result;
        }

        self::assertSame(HealthStatus::Healthy, $resultsByName['database']->status);
        self::assertSame(HealthStatus::Unhealthy, $resultsByName['cache']->status);
        self::assertSame(HealthStatus::Degraded, $resultsByName['disk']->status);
        self::assertSame('Redis connection refused', $resultsByName['cache']->message);
    }

    #[Test]
    public function repairRunnerDiagnosesIssuesAndRunsRepairs(): void
    {
        $runner = new RepairRunner();

        $runner->register(new class implements RepairJobInterface {
            public function getName(): string
            {
                return 'cache-repair';
            }

            public function getDescription(): string
            {
                return 'Repairs cache connectivity';
            }

            public function diagnose(): RepairDiagnosis
            {
                return new RepairDiagnosis(
                    repairJobName: 'cache-repair',
                    needsRepair: true,
                    description: 'Cache connection lost',
                    findings: ['Redis not responding on port 6379'],
                );
            }

            public function repair(): RepairResult
            {
                return new RepairResult(
                    repairJobName: 'cache-repair',
                    success: true,
                    description: 'Cache connection restored',
                    actionsPerformed: ['Restarted Redis connection pool'],
                );
            }
        });

        $runner->register(new class implements RepairJobInterface {
            public function getName(): string
            {
                return 'disk-cleanup';
            }

            public function getDescription(): string
            {
                return 'Cleans up temporary files';
            }

            public function diagnose(): RepairDiagnosis
            {
                return new RepairDiagnosis(
                    repairJobName: 'disk-cleanup',
                    needsRepair: false,
                    description: 'Disk space is sufficient',
                );
            }

            public function repair(): RepairResult
            {
                return new RepairResult(
                    repairJobName: 'disk-cleanup',
                    success: true,
                    description: 'Cleaned up temporary files',
                    actionsPerformed: ['Removed expired temp files'],
                );
            }
        });

        // Diagnose all
        $diagnoses = $runner->diagnoseAll();
        self::assertCount(2, $diagnoses);

        $needsRepair = array_filter($diagnoses, static fn(RepairDiagnosis $d): bool => $d->needsRepair);
        self::assertCount(1, $needsRepair);
        self::assertSame('cache-repair', array_values($needsRepair)[0]->repairJobName);

        // Repair all (only those needing repair)
        $repairs = $runner->repairAll();
        self::assertCount(1, $repairs);
        self::assertTrue($repairs[0]->success);
        self::assertSame('cache-repair', $repairs[0]->repairJobName);
        self::assertSame(['Restarted Redis connection pool'], $repairs[0]->actionsPerformed);
    }

    #[Test]
    public function fullPipelineHealthCheckFailsDiagnoseRepairHealthCheckPasses(): void
    {
        // Shared mutable state: simulate a broken system that gets repaired
        /** @var array{cacheHealthy: bool} $systemState */
        $systemState = ['cacheHealthy' => false];

        // Health check that reports based on system state
        $cacheHealthCheck = new class ($systemState) implements HealthCheckInterface {
            /** @var array{cacheHealthy: bool} */
            private array $state;

            /** @param array{cacheHealthy: bool} $state */
            public function __construct(array &$state)
            {
                $this->state = &$state;
            }

            public function getName(): string
            {
                return 'cache';
            }

            public function check(): HealthCheckResult
            {
                if ($this->state['cacheHealthy']) {
                    return HealthCheckResult::healthy('cache', 'Cache is operational');
                }

                return HealthCheckResult::unhealthy('cache', 'Cache connection failed');
            }
        };

        // Repair job that fixes the system state
        $cacheRepairJob = new class ($systemState) implements RepairJobInterface {
            /** @var array{cacheHealthy: bool} */
            private array $state;

            /** @param array{cacheHealthy: bool} $state */
            public function __construct(array &$state)
            {
                $this->state = &$state;
            }

            public function getName(): string
            {
                return 'cache-repair';
            }

            public function getDescription(): string
            {
                return 'Restores cache connectivity';
            }

            public function diagnose(): RepairDiagnosis
            {
                return new RepairDiagnosis(
                    repairJobName: 'cache-repair',
                    needsRepair: !$this->state['cacheHealthy'],
                    description: $this->state['cacheHealthy'] ? 'Cache is fine' : 'Cache needs repair',
                );
            }

            public function repair(): RepairResult
            {
                $this->state['cacheHealthy'] = true;

                return new RepairResult(
                    repairJobName: 'cache-repair',
                    success: true,
                    description: 'Cache connectivity restored',
                    actionsPerformed: ['Reconnected to cache server', 'Verified cache read/write'],
                );
            }
        };

        $healthRunner = new HealthCheckRunner();
        $healthRunner->register($cacheHealthCheck);

        $repairRunner = new RepairRunner();
        $repairRunner->register($cacheRepairJob);

        // Step 1: Health check fails
        $report = $healthRunner->runAll();
        self::assertSame(HealthStatus::Unhealthy, $report->overallStatus);
        self::assertFalse($report->isHealthy());
        self::assertSame('Cache connection failed', $report->results[0]->message);

        // Step 2: Diagnose
        $diagnoses = $repairRunner->diagnoseAll();
        self::assertCount(1, $diagnoses);
        self::assertTrue($diagnoses[0]->needsRepair);
        self::assertSame('cache-repair', $diagnoses[0]->repairJobName);

        // Step 3: Repair
        $repairs = $repairRunner->repairAll();
        self::assertCount(1, $repairs);
        self::assertTrue($repairs[0]->success);
        self::assertSame('cache-repair', $repairs[0]->repairJobName);
        self::assertContains('Reconnected to cache server', $repairs[0]->actionsPerformed);

        // Step 4: Health check passes after repair
        $reportAfterRepair = $healthRunner->runAll();
        self::assertSame(HealthStatus::Healthy, $reportAfterRepair->overallStatus);
        self::assertTrue($reportAfterRepair->isHealthy());
        self::assertSame('Cache is operational', $reportAfterRepair->results[0]->message);

        // Step 5: Diagnose again confirms no repair needed
        $postRepairDiagnoses = $repairRunner->diagnoseAll();
        self::assertFalse($postRepairDiagnoses[0]->needsRepair);
    }
}

<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Supervisor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Queue\DeadLetterQueue;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditSinkInterface;
use Pulsar\Supervisor\HealingAction;
use Pulsar\Supervisor\HealingActionType;
use Pulsar\Supervisor\StuckJobRecovery;

use function strlen;
use function time;

#[CoversClass(StuckJobRecovery::class)]
final class StuckJobRecoveryTest extends TestCase
{
    private function createStuckJob(string $id = 'job-1', string $queue = 'default'): JobRecord
    {
        return new JobRecord(
            id: $id,
            queue: $queue,
            jobClass: 'App\\Jobs\\ProcessOrder',
            payload: '{"order_id":42}',
            attempts: 3,
            status: JobRecordStatus::Processing,
            createdAt: time() - 600,
            availableAt: time() - 600,
        );
    }

    #[Test]
    public function it_returns_healing_action_on_recovery(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $deadLetterQueue = new DeadLetterQueue($driver);

        $recovery = new StuckJobRecovery($deadLetterQueue);
        $result = $recovery->recover($this->createStuckJob());

        self::assertInstanceOf(HealingAction::class, $result);
        self::assertSame(HealingActionType::StuckJobRecovery, $result->type);
        self::assertTrue($result->success);
        self::assertSame('job-1', $result->correlationId);
    }

    #[Test]
    public function it_generates_unique_healing_action_id(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $deadLetterQueue = new DeadLetterQueue($driver);

        $recovery = new StuckJobRecovery($deadLetterQueue);

        $result1 = $recovery->recover($this->createStuckJob('job-a'));
        $result2 = $recovery->recover($this->createStuckJob('job-b'));

        self::assertNotSame($result1->id, $result2->id);
        self::assertSame(32, strlen($result1->id));
        self::assertSame(32, strlen($result2->id));
    }

    #[Test]
    public function it_stores_job_in_dead_letter_queue(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $deadLetterQueue = new DeadLetterQueue($driver);

        $stuckJob = $this->createStuckJob('dlq-job');
        $recovery = new StuckJobRecovery($deadLetterQueue);
        $recovery->recover($stuckJob);

        $failedJobs = $deadLetterQueue->list();
        self::assertCount(1, $failedJobs);
        self::assertSame('dlq-job', $failedJobs[0]->id);
    }

    #[Test]
    public function it_logs_warning_when_logger_is_provided(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $deadLetterQueue = new DeadLetterQueue($driver);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('stuck in processing state'),
                self::callback(static function (array $context): bool {
                    return $context['job_id'] === 'log-job'
                        && $context['job_class'] === 'App\\Jobs\\ProcessOrder'
                        && $context['queue'] === 'default'
                        && $context['attempts'] === 3;
                }),
            );

        $recovery = new StuckJobRecovery($deadLetterQueue, $logger);
        $recovery->recover($this->createStuckJob('log-job'));
    }

    #[Test]
    public function it_does_not_fail_without_logger(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $deadLetterQueue = new DeadLetterQueue($driver);

        $recovery = new StuckJobRecovery($deadLetterQueue);
        $result = $recovery->recover($this->createStuckJob());

        self::assertTrue($result->success);
    }

    #[Test]
    public function it_logs_audit_event_when_audit_logger_is_provided(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $deadLetterQueue = new DeadLetterQueue($driver);

        $sink = $this->createMock(AuditSinkInterface::class);
        $sink->expects(self::once())->method('write');

        $auditKey = random_bytes(32);
        $auditLogger = new AuditLogger($sink, $auditKey);

        $recovery = new StuckJobRecovery($deadLetterQueue, auditLogger: $auditLogger);
        $recovery->recover($this->createStuckJob('audit-job'));
    }

    #[Test]
    public function it_does_not_fail_without_audit_logger(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $deadLetterQueue = new DeadLetterQueue($driver);

        $recovery = new StuckJobRecovery($deadLetterQueue);
        $result = $recovery->recover($this->createStuckJob());

        self::assertInstanceOf(HealingAction::class, $result);
    }

    #[Test]
    public function it_includes_job_id_and_class_in_description(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $deadLetterQueue = new DeadLetterQueue($driver);

        $recovery = new StuckJobRecovery($deadLetterQueue);
        $result = $recovery->recover($this->createStuckJob('desc-job'));

        self::assertStringContainsString('desc-job', $result->description);
        self::assertStringContainsString('App\\Jobs\\ProcessOrder', $result->description);
    }

    #[Test]
    public function it_records_performed_at_timestamp(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $deadLetterQueue = new DeadLetterQueue($driver);

        $before = time();
        $recovery = new StuckJobRecovery($deadLetterQueue);
        $result = $recovery->recover($this->createStuckJob());
        $after = time();

        self::assertGreaterThanOrEqual($before, $result->performedAt);
        self::assertLessThanOrEqual($after, $result->performedAt);
    }

    #[Test]
    public function it_recovers_jobs_from_different_queues(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $deadLetterQueue = new DeadLetterQueue($driver);

        $recovery = new StuckJobRecovery($deadLetterQueue);

        $result1 = $recovery->recover($this->createStuckJob('job-q1', 'emails'));
        $result2 = $recovery->recover($this->createStuckJob('job-q2', 'notifications'));

        self::assertSame('job-q1', $result1->correlationId);
        self::assertSame('job-q2', $result2->correlationId);

        $failedJobs = $deadLetterQueue->list();
        self::assertCount(2, $failedJobs);
    }
}

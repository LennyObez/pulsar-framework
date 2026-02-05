<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Collector;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobInterface;
use Pulsar\Scheduler\JobRegistry;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\JobStatus;
use Pulsar\Scheduler\Schedule;
use Pulsar\Scheduler\Scheduler;
use Pulsar\Scheduler\SchedulerTickResult;
use Pulsar\Studio\Console\Collector\InstrumentedScheduler;
use Pulsar\Studio\Console\Event\ConsoleEvent;
use Pulsar\Studio\Console\Event\Payload\SchedulerRunPayload;
use Pulsar\Studio\CorrelationContext;
use Pulsar\Studio\FiberScopedContextProvider;
use RuntimeException;

use function strlen;

#[CoversClass(InstrumentedScheduler::class)]
final class InstrumentedSchedulerTest extends TestCase
{
    /** @var list<array{event: ConsoleEvent, context: ?CorrelationContext}> */
    private array $emittedEvents = [];

    /** @psalm-suppress PropertyNotSetInConstructor */
    private FiberScopedContextProvider $contextProvider;

    protected function setUp(): void
    {
        $this->emittedEvents = [];
        $this->contextProvider = new FiberScopedContextProvider();
    }

    #[Test]
    public function tickRunsDueJobsAndReturnsResult(): void
    {
        $job = $this->createSuccessfulJob('test-job');
        $registry = new JobRegistry();
        $registry->register($job);

        $scheduler = new Scheduler($registry);
        $instrumented = $this->createScheduler($scheduler);

        $now = new DateTimeImmutable();
        $result = $instrumented->tick($now);

        self::assertInstanceOf(SchedulerTickResult::class, $result);
        self::assertSame(1, $result->jobsDue);
        self::assertSame(1, $result->jobsRun);
        self::assertSame(0, $result->jobsFailed);
    }

    #[Test]
    public function tickEmitsSchedulerRunEventForEachJob(): void
    {
        $job1 = $this->createSuccessfulJob('job-1');
        $job2 = $this->createSuccessfulJob('job-2');

        $registry = new JobRegistry();
        $registry->register($job1);
        $registry->register($job2);

        $scheduler = new Scheduler($registry);
        $instrumented = $this->createScheduler($scheduler);

        $instrumented->tick(new DateTimeImmutable());

        self::assertCount(2, $this->emittedEvents);
        self::assertInstanceOf(SchedulerRunPayload::class, $this->emittedEvents[0]['event']);
        self::assertInstanceOf(SchedulerRunPayload::class, $this->emittedEvents[1]['event']);
    }

    #[Test]
    public function tickRecordsJobName(): void
    {
        $job = $this->createSuccessfulJob('my-scheduled-job');
        $registry = new JobRegistry();
        $registry->register($job);

        $scheduler = new Scheduler($registry);
        $instrumented = $this->createScheduler($scheduler);

        $instrumented->tick(new DateTimeImmutable());

        /** @var SchedulerRunPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('my-scheduled-job', $payload->jobName);
    }

    #[Test]
    public function tickRecordsSuccessStatus(): void
    {
        $job = $this->createSuccessfulJob('test-job');
        $registry = new JobRegistry();
        $registry->register($job);

        $scheduler = new Scheduler($registry);
        $instrumented = $this->createScheduler($scheduler);

        $instrumented->tick(new DateTimeImmutable());

        /** @var SchedulerRunPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('success', $payload->status);
    }

    #[Test]
    public function tickRecordsFailureStatusAndErrorMessage(): void
    {
        $job = $this->createFailingJob('failing-job', 'Something went wrong');
        $registry = new JobRegistry();
        $registry->register($job);

        $scheduler = new Scheduler($registry);
        $instrumented = $this->createScheduler($scheduler);

        $result = $instrumented->tick(new DateTimeImmutable());

        self::assertSame(1, $result->jobsFailed);

        /** @var SchedulerRunPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('failure', $payload->status);
        self::assertSame('Something went wrong', $payload->errorMessage);
    }

    #[Test]
    public function tickRecordsDuration(): void
    {
        $job = $this->createJobWithDelay('slow-job', 10); // 10ms delay
        $registry = new JobRegistry();
        $registry->register($job);

        $scheduler = new Scheduler($registry);
        $instrumented = $this->createScheduler($scheduler);

        $instrumented->tick(new DateTimeImmutable());

        /** @var SchedulerRunPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertGreaterThan(5.0, $payload->durationMs);
    }

    #[Test]
    public function tickCreatesJobContextWithJobId(): void
    {
        $job = $this->createSuccessfulJob('test-job');
        $registry = new JobRegistry();
        $registry->register($job);

        $scheduler = new Scheduler($registry);
        $instrumented = $this->createScheduler($scheduler);

        $instrumented->tick(new DateTimeImmutable());

        $context = $this->emittedEvents[0]['context'];
        self::assertNotNull($context);
        self::assertNotNull($context->jobId);
        self::assertSame(32, strlen($context->jobId)); // 16 bytes = 32 hex chars
    }

    #[Test]
    public function tickInheritsExistingCorrelationContext(): void
    {
        $job = $this->createSuccessfulJob('test-job');
        $registry = new JobRegistry();
        $registry->register($job);

        $scheduler = new Scheduler($registry);
        $instrumented = $this->createScheduler($scheduler);

        $existingContext = new CorrelationContext(
            requestId: 'parent-req-id',
            traceId: 'parent-trace-id',
            spanId: 'parent-span-id',
        );
        $scope = $this->contextProvider->enter($existingContext);

        try {
            $instrumented->tick(new DateTimeImmutable());

            $emittedContext = $this->emittedEvents[0]['context'];
            self::assertNotNull($emittedContext);
            self::assertSame('parent-req-id', $emittedContext->requestId);
            self::assertSame('parent-trace-id', $emittedContext->traceId);
            self::assertSame('parent-span-id', $emittedContext->spanId);
            // jobId should be new
            self::assertNotNull($emittedContext->jobId);
        } finally {
            $scope->close();
        }
    }

    #[Test]
    public function tickSkipsInstrumentationWhenDisabled(): void
    {
        $job = $this->createSuccessfulJob('test-job');
        $registry = new JobRegistry();
        $registry->register($job);

        $scheduler = new Scheduler($registry);
        $instrumented = $this->createScheduler($scheduler);
        $instrumented->setEnabled(false);

        $result = $instrumented->tick(new DateTimeImmutable());

        // Jobs still run via inner scheduler
        self::assertSame(1, $result->jobsRun);
        // But no events emitted
        self::assertCount(0, $this->emittedEvents);
    }

    #[Test]
    public function runJobExecutesSpecificJob(): void
    {
        $job = $this->createSuccessfulJob('specific-job');
        $registry = new JobRegistry();
        $scheduler = new Scheduler($registry);
        $instrumented = $this->createScheduler($scheduler);

        $result = $instrumented->runJob($job);

        self::assertInstanceOf(JobResult::class, $result);
        self::assertSame('specific-job', $result->jobName);
        self::assertSame(JobStatus::Success, $result->status);
    }

    #[Test]
    public function runJobEmitsSchedulerRunEvent(): void
    {
        $job = $this->createSuccessfulJob('manual-job');
        $registry = new JobRegistry();
        $scheduler = new Scheduler($registry);
        $instrumented = $this->createScheduler($scheduler);

        $instrumented->runJob($job);

        self::assertCount(1, $this->emittedEvents);
        /** @var SchedulerRunPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('manual-job', $payload->jobName);
    }

    #[Test]
    public function runJobSkipsInstrumentationWhenDisabled(): void
    {
        $job = $this->createSuccessfulJob('test-job');
        $registry = new JobRegistry();
        $scheduler = new Scheduler($registry);
        $instrumented = $this->createScheduler($scheduler);
        $instrumented->setEnabled(false);

        $result = $instrumented->runJob($job);

        self::assertSame(JobStatus::Success, $result->status);
        self::assertCount(0, $this->emittedEvents);
    }

    #[Test]
    public function runJobClosesContextScopeInFinally(): void
    {
        $job = $this->createSuccessfulJob('test-job');
        $registry = new JobRegistry();
        $scheduler = new Scheduler($registry);
        $instrumented = $this->createScheduler($scheduler);

        self::assertNull($this->contextProvider->current());

        $instrumented->runJob($job);

        // Context should be cleaned up after job completes
        self::assertNull($this->contextProvider->current());
    }

    #[Test]
    public function runJobClosesContextScopeOnException(): void
    {
        $registry = new JobRegistry();
        $scheduler = new Scheduler($registry);
        $instrumented = $this->createScheduler($scheduler);

        // Create a job that returns a failure result (not throwing, just failing)
        $job = $this->createFailingJob('failing-job', 'Job failed');

        self::assertNull($this->contextProvider->current());

        $instrumented->runJob($job);

        // Context should be cleaned up even after failure
        self::assertNull($this->contextProvider->current());
    }

    #[Test]
    public function registryReturnsUnderlyingRegistry(): void
    {
        $registry = new JobRegistry();
        $job = $this->createSuccessfulJob('test-job');
        $registry->register($job);

        $scheduler = new Scheduler($registry);
        $instrumented = $this->createScheduler($scheduler);

        self::assertSame($registry, $instrumented->registry());
        self::assertTrue($instrumented->registry()->has('test-job'));
    }

    #[Test]
    public function innerReturnsUnderlyingScheduler(): void
    {
        $registry = new JobRegistry();
        $scheduler = new Scheduler($registry);
        $instrumented = $this->createScheduler($scheduler);

        self::assertSame($scheduler, $instrumented->inner());
    }

    #[Test]
    public function isEnabledReturnsTrueByDefault(): void
    {
        $registry = new JobRegistry();
        $scheduler = new Scheduler($registry);
        $instrumented = $this->createScheduler($scheduler);

        self::assertTrue($instrumented->isEnabled());
    }

    #[Test]
    public function setEnabledChangesEnabledState(): void
    {
        $registry = new JobRegistry();
        $scheduler = new Scheduler($registry);
        $instrumented = $this->createScheduler($scheduler);

        $instrumented->setEnabled(false);
        self::assertFalse($instrumented->isEnabled());

        $instrumented->setEnabled(true);
        self::assertTrue($instrumented->isEnabled());
    }

    #[Test]
    public function tickSilentlySwallowsEmitExceptions(): void
    {
        $job = $this->createSuccessfulJob('test-job');
        $registry = new JobRegistry();
        $registry->register($job);

        $scheduler = new Scheduler($registry);
        $instrumented = new InstrumentedScheduler(
            inner: $scheduler,
            contextProvider: $this->contextProvider,
            emit: function () {
                throw new RuntimeException('Emit failed');
            },
        );

        // Should not throw
        $result = $instrumented->tick(new DateTimeImmutable());

        self::assertSame(1, $result->jobsRun);
    }

    #[Test]
    public function tickWithNoJobsDueReturnsEmptyResult(): void
    {
        $registry = new JobRegistry();
        $scheduler = new Scheduler($registry);
        $instrumented = $this->createScheduler($scheduler);

        $result = $instrumented->tick(new DateTimeImmutable());

        self::assertSame(0, $result->jobsDue);
        self::assertSame(0, $result->jobsRun);
        self::assertCount(0, $this->emittedEvents);
    }

    #[Test]
    public function runJobPassesScheduledAtToInnerScheduler(): void
    {
        $job = $this->createSuccessfulJob('test-job');
        $registry = new JobRegistry();
        $scheduler = new Scheduler($registry);
        $instrumented = $this->createScheduler($scheduler);

        $scheduledAt = new DateTimeImmutable('2024-01-15 10:30:00');

        $result = $instrumented->runJob($job, $scheduledAt);

        self::assertSame(JobStatus::Success, $result->status);
    }

    #[Test]
    public function tickWithMixedSuccessAndFailureCountsCorrectly(): void
    {
        $successJob = $this->createSuccessfulJob('success-job');
        $failJob = $this->createFailingJob('fail-job', 'Error');

        $registry = new JobRegistry();
        $registry->register($successJob);
        $registry->register($failJob);

        $scheduler = new Scheduler($registry);
        $instrumented = $this->createScheduler($scheduler);

        $result = $instrumented->tick(new DateTimeImmutable());

        self::assertSame(2, $result->jobsDue);
        self::assertSame(2, $result->jobsRun);
        self::assertSame(1, $result->jobsFailed);
        self::assertTrue($result->hasFailures());

        self::assertCount(2, $this->emittedEvents);
    }

    private function createScheduler(Scheduler $inner): InstrumentedScheduler
    {
        return new InstrumentedScheduler(
            inner: $inner,
            contextProvider: $this->contextProvider,
            emit: function (ConsoleEvent $event, ?CorrelationContext $context): void {
                $this->emittedEvents[] = ['event' => $event, 'context' => $context];
            },
        );
    }

    private function createSuccessfulJob(string $name): JobInterface
    {
        return new class ($name) implements JobInterface {
            public function __construct(private readonly string $name) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getSchedule(): Schedule
            {
                return Schedule::everyMinute();
            }

            public function execute(JobContext $context): JobResult
            {
                return JobResult::success($this->name, $context->startedAt);
            }

            public function getDescription(): string
            {
                return 'Test job';
            }
        };
    }

    private function createFailingJob(string $name, string $errorMessage): JobInterface
    {
        return new class ($name, $errorMessage) implements JobInterface {
            public function __construct(
                private readonly string $name,
                private readonly string $errorMessage,
            ) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getSchedule(): Schedule
            {
                return Schedule::everyMinute();
            }

            public function execute(JobContext $context): JobResult
            {
                return JobResult::failure(
                    $this->name,
                    $context->startedAt,
                    new RuntimeException($this->errorMessage),
                );
            }

            public function getDescription(): string
            {
                return 'Failing test job';
            }
        };
    }

    private function createJobWithDelay(string $name, int $delayMs): JobInterface
    {
        return new class ($name, $delayMs) implements JobInterface {
            public function __construct(
                private readonly string $name,
                private readonly int $delayMs,
            ) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getSchedule(): Schedule
            {
                return Schedule::everyMinute();
            }

            public function execute(JobContext $context): JobResult
            {
                usleep($this->delayMs * 1000);
                return JobResult::success($this->name, $context->startedAt);
            }

            public function getDescription(): string
            {
                return 'Slow test job';
            }
        };
    }
}

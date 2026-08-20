<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue;

use Closure;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Envelope\BackoffStrategy;
use Pulsar\Queue\Envelope\EnvelopeSerializer;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\JobContext;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\Middleware\JobMiddlewareInterface;
use Pulsar\Queue\Middleware\MiddlewarePipeline;
use Pulsar\Queue\QueueableInterface;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Queue\Serialization\TypeRegistry;
use Pulsar\Queue\Worker;
use Pulsar\Queue\WorkerFactory;
use Pulsar\Queue\WorkerOptions;

/**
 * The factory exists so a worker built outside the container is not a lesser one.
 *
 * `queue:work` takes its options from command-line flags, so it cannot reuse the
 * bound Worker, and it used to build its own from a driver and a logger. That
 * dropped eight collaborators, the execution pipeline among them — the pipeline
 * that decrypts a payload before the handler sees it. The tests below assert the
 * pipeline actually reaches the worker the factory returns, rather than that the
 * constructor was called with the right arguments.
 */
#[CoversClass(WorkerFactory::class)]
final class WorkerFactoryTest extends TestCase
{
    private TypeRegistry $typeRegistry;

    protected function setUp(): void
    {
        $this->typeRegistry = new TypeRegistry();
        $this->typeRegistry->register(FactoryProbeJob::class);
        FactoryProbeJob::$ran = false;
    }

    protected function tearDown(): void
    {
        FactoryProbeJob::$ran = false;
    }

    #[Test]
    public function theExecutionPipelineReachesTheWorkerItBuilds(): void
    {
        $middleware = new RecordingJobMiddleware();

        $worker = $this->factory(new MiddlewarePipeline([$middleware]))->create(new WorkerOptions());
        $worker->processNextJob('default');

        self::assertTrue($middleware->ran, 'the pipeline must run for every job the worker takes');
        self::assertTrue(FactoryProbeJob::$ran, 'and the job must still reach its handler');
    }

    #[Test]
    public function aWorkerBuiltWithoutAPipelineStillRunsTheJob(): void
    {
        // The default pipeline is empty, not absent: a host that configures no
        // queue middleware gets a worker, not a broken one.
        $worker = $this->factory()->create(new WorkerOptions());
        $worker->processNextJob('default');

        self::assertTrue(FactoryProbeJob::$ran);
    }

    #[Test]
    public function eachCallGetsItsOwnWorkerForItsOwnOptions(): void
    {
        $factory = $this->factory();

        $first = $factory->create(new WorkerOptions(maxJobs: 1));
        $second = $factory->create(new WorkerOptions(maxJobs: 2));

        // Two callers with different options — the scheduler's and the command's —
        // must not share one worker, or the second silently inherits the first.
        self::assertNotSame($first, $second);
        self::assertInstanceOf(Worker::class, $first);
    }

    private function factory(?MiddlewarePipeline $pipeline = null): WorkerFactory
    {
        $envelope = new JobEnvelope(
            // Thirty-two hex characters here too: the envelope id becomes the
            // causation ID, under the same rule.
            id: 'fedcba9876543210fedcba9876543210',
            jobClass: FactoryProbeJob::class,
            payload: '{}',
            queue: 'default',
            idempotencyKey: 'idem-factory-1',
            // Thirty-two hex characters: the envelope rejects anything else, and a
            // rejected envelope retries silently instead of reaching the handler.
            correlationId: '0123456789abcdef0123456789abcdef',
            traceId: null,
            spanId: null,
            schemaVersion: 1,
            keyId: null,
            retryMaxAttempts: 3,
            retryBackoffStrategy: BackoffStrategy::Exponential,
            retryDelayMs: 1000,
            tenantId: null,
            subjectId: null,
            batchId: null,
            chainIndex: null,
            attempt: 1,
            dispatchedAt: 1700000000,
            encrypted: false,
        );

        $record = new JobRecord(
            id: 'factory-probe-job',
            queue: 'default',
            jobClass: FactoryProbeJob::class,
            payload: new EnvelopeSerializer()->serialize($envelope),
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: 1700000000,
            availableAt: 1700000000,
        );

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('pop')->willReturn($record);

        return new WorkerFactory(
            $driver,
            typeRegistry: $this->typeRegistry,
            executionPipeline: $pipeline ?? new MiddlewarePipeline(),
        );
    }
}

final class RecordingJobMiddleware implements JobMiddlewareInterface
{
    public bool $ran = false;

    #[Override]
    public function handle(JobEnvelope $envelope, Closure $next): mixed
    {
        $this->ran = true;

        return $next($envelope);
    }
}

final class FactoryProbeJob implements QueueableInterface
{
    public static bool $ran = false;

    #[Override]
    public function handle(JobContext $context): void
    {
        self::$ran = true;
    }

    #[Override]
    public function queue(): string
    {
        return 'default';
    }

    #[Override]
    public function maxAttempts(): int
    {
        return 3;
    }

    #[Override]
    public function timeout(): int
    {
        return 60;
    }
}

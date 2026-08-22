<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tracing\Instrumentation;

use Override;
use Pulsar\Api\Api;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\SpanProcessorInterface;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceId;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;
use Throwable;

use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Decorator that wraps a queue driver with OpenTelemetry span instrumentation.
 *
 * Creates messaging.publish spans for push() and messaging.process spans
 * for pop(). Propagates trace context in the job payload.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class InstrumentedQueueDriver implements QueueDriverInterface
{
    public function __construct(
        private QueueDriverInterface $inner,
        private SpanProcessorInterface $processor,
        private TraceContext $traceContext,
        private bool $enabled = true,
    ) {}

    #[Override]
    public function push(string $queue, string $jobClass, string $payload, int $delay = 0): string
    {
        if (!$this->enabled) {
            return $this->inner->push($queue, $jobClass, $payload, $delay);
        }

        $childContext = $this->traceContext->createChild();
        $span = new Span(
            name: $queue . ' publish',
            context: $childContext,
            parentSpanId: $this->traceContext->spanId,
        );

        $span->setAttribute('messaging.system', 'pulsar.queue');
        $span->setAttribute('messaging.destination', $queue);
        $span->setAttribute('messaging.operation', 'publish');
        $span->setAttribute('messaging.job_class', $jobClass);
        $span->setAttribute('_span_kind', 4);

        // Propagate trace context in payload
        $enrichedPayload = $this->injectTraceContext($payload, $childContext);

        try {
            $jobId = $this->inner->push($queue, $jobClass, $enrichedPayload, $delay);
            $span->setAttribute('messaging.message_id', $jobId);
            $span->status = SpanStatus::Ok;

            return $jobId;
        } catch (Throwable $e) {
            $span->status = SpanStatus::Error;
            $span->setAttribute('exception.type', $e::class);
            $span->setAttribute('exception.message', $e::class . ' (code: ' . $e->getCode() . ')');

            throw $e;
        } finally {
            $span->end();
            $this->processor->onEnd($span);
        }
    }

    #[Override]
    public function pop(string $queue): ?JobRecord
    {
        if (!$this->enabled) {
            return $this->inner->pop($queue);
        }

        $job = $this->inner->pop($queue);

        if ($job === null) {
            return null;
        }

        $propagatedContext = $this->extractTraceContext($job->payload);
        $parentContext = $propagatedContext ?? $this->traceContext;
        $childContext = $parentContext->createChild();

        $span = new Span(
            name: $queue . ' process',
            context: $childContext,
            parentSpanId: $parentContext->spanId,
        );

        $span->setAttribute('messaging.system', 'pulsar.queue');
        $span->setAttribute('messaging.destination', $queue);
        $span->setAttribute('messaging.operation', 'process');
        $span->setAttribute('messaging.message_id', $job->id);
        $span->setAttribute('messaging.job_class', $job->jobClass);
        $span->setAttribute('_span_kind', 5);
        $span->status = SpanStatus::Ok;
        $span->end();
        $this->processor->onEnd($span);

        return $job;
    }

    #[Override]
    public function acknowledge(string $jobId): void
    {
        $this->inner->acknowledge($jobId);
    }

    #[Override]
    public function reject(string $jobId, string $reason): void
    {
        $this->inner->reject($jobId, $reason);
    }

    #[Override]
    public function size(string $queue): int
    {
        return $this->inner->size($queue);
    }

    #[Override]
    public function purge(string $queue): int
    {
        return $this->inner->purge($queue);
    }

    #[Override]
    public function findByStatus(JobRecordStatus $status): array
    {
        return $this->inner->findByStatus($status);
    }

    /**
     * Extract propagated trace context from the job payload.
     */
    private function extractTraceContext(string $payload): ?TraceContext
    {
        $decoded = json_decode($payload, true);

        if (!is_array($decoded) || !isset($decoded['_trace_context'])) {
            return null;
        }

        $tc = $decoded['_trace_context'];

        if (
            !is_array($tc)
            || !isset($tc['trace_id'], $tc['span_id'], $tc['trace_flags'])
            || !is_string($tc['trace_id'])
            || !is_string($tc['span_id'])
            || !is_int($tc['trace_flags'])
        ) {
            return null;
        }

        return new TraceContext(
            traceId: new TraceId($tc['trace_id']),
            spanId: new SpanId($tc['span_id']),
            traceFlags: $tc['trace_flags'],
        );
    }

    /**
     * Inject trace context into the job payload for distributed tracing propagation.
     */
    private function injectTraceContext(string $payload, TraceContext $context): string
    {
        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            return $payload;
        }

        $decoded['_trace_context'] = [
            'trace_id' => $context->traceId->value,
            'span_id' => $context->spanId->value,
            'trace_flags' => $context->traceFlags,
        ];

        return json_encode($decoded, JSON_THROW_ON_ERROR);
    }
}

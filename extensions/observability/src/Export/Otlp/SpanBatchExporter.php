<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Export\Otlp;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Internal;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\OtlpSpan;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\ResourceInfo;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\TraceRequestBuilder;
use Pulsar\Extension\Observability\Export\Otlp\Transport\OtlpTransportInterface;

/**
 * Batch exporter for trace spans.
 *
 * Wraps BatchExporter with TraceRequestBuilder serialization and
 * the standard OTLP /v1/traces endpoint path.
 */
#[Internal(reason: 'Span-specific batch export wiring')]
final readonly class SpanBatchExporter
{
    /** @var BatchExporter<OtlpSpan> */
    private BatchExporter $batchExporter;

    public function __construct(
        OtlpTransportInterface $transport,
        ResourceInfo $resource,
        TraceRequestBuilder $builder = new TraceRequestBuilder(),
        int $maxBatchSize = 512,
        int $maxQueueSize = 2048,
        LoggerInterface $logger = new NullLogger(),
    ) {
        /** @var BatchExporter<OtlpSpan> $batchExporter */
        $batchExporter = new BatchExporter(
            transport: $transport,
            serializer: static function (array $spans) use ($builder, $resource): string {
                /** @var list<OtlpSpan> $spans */
                return $builder->build($spans, $resource);
            },
            signalPath: '/v1/traces',
            maxBatchSize: $maxBatchSize,
            maxQueueSize: $maxQueueSize,
            logger: $logger,
        );
        $this->batchExporter = $batchExporter;
    }

    public function enqueue(OtlpSpan $span): void
    {
        $this->batchExporter->enqueue($span);
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function flush(): void
    {
        $this->batchExporter->flush();
    }

    public function shutdown(): void
    {
        $this->batchExporter->shutdown();
    }

    public function queueSize(): int
    {
        return $this->batchExporter->queueSize();
    }
}

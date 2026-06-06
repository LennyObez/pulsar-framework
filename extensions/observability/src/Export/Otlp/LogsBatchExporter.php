<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Export\Otlp;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Internal;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\LogsRequestBuilder;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\OtlpLogRecord;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\ResourceInfo;
use Pulsar\Extension\Observability\Export\Otlp\Transport\OtlpTransportInterface;

/**
 * Batch exporter for log records.
 */
#[Internal(reason: 'Logs-specific batch export wiring')]
final readonly class LogsBatchExporter
{
    /** @var BatchExporter<OtlpLogRecord> */
    private BatchExporter $batchExporter;

    public function __construct(
        OtlpTransportInterface $transport,
        ResourceInfo $resource,
        LogsRequestBuilder $builder = new LogsRequestBuilder(),
        int $maxBatchSize = 512,
        int $maxQueueSize = 2048,
        LoggerInterface $logger = new NullLogger(),
    ) {
        /** @var BatchExporter<OtlpLogRecord> $batchExporter */
        $batchExporter = new BatchExporter(
            transport: $transport,
            serializer: static function (array $records) use ($builder, $resource): string {
                /** @var list<OtlpLogRecord> $records */
                return $builder->build($records, $resource);
            },
            signalPath: '/v1/logs',
            maxBatchSize: $maxBatchSize,
            maxQueueSize: $maxQueueSize,
            logger: $logger,
        );
        $this->batchExporter = $batchExporter;
    }

    public function enqueue(OtlpLogRecord $record): void
    {
        $this->batchExporter->enqueue($record);
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
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function queueSize(): int
    {
        return $this->batchExporter->queueSize();
    }
}

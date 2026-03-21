<?php

declare(strict_types=1);

namespace Pulsar\Extension\ObservabilityExport\Metrics;

use Override;
use Pulsar\Api\Api;
use Pulsar\Extension\ObservabilityExport\Internal\JsonLinesFileWriter;
use Pulsar\Extension\ObservabilityExport\Schema\MetricSchema;
use Pulsar\Observability\Metrics\MetricSnapshot;

use function count;

use const PHP_EOL;

/**
 * Exports metric snapshots as JSON Lines to a file.
 *
 * Buffers snapshots in memory and flushes to disk when the threshold is reached.
 * Uses flock(LOCK_EX) for multi-process safety.
 */
#[Api(since: '1.0.0')]
final class JsonLinesMetricsExporter implements MetricsExporterInterface
{
    /** @var list<MetricSnapshot> */
    private array $buffer = [];

    private bool $isShutdown = false;

    private readonly JsonLinesFileWriter $writer;

    public function __construct(
        string $filePath,
        private readonly int $flushThreshold = 10,
    ) {
        $this->writer = new JsonLinesFileWriter($filePath);
    }

    #[Override]
    public function export(MetricSnapshot $snapshot): void
    {
        if ($this->isShutdown) {
            return;
        }

        $this->buffer[] = $snapshot;

        if (count($this->buffer) >= $this->flushThreshold) {
            $this->flush();
        }
    }

    #[Override]
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $lines = '';

        foreach ($this->buffer as $snapshot) {
            $lines .= MetricSchema::toJson($snapshot) . PHP_EOL;
        }

        $this->buffer = [];
        $this->writer->write($lines);
    }

    #[Override]
    public function shutdown(): void
    {
        if ($this->isShutdown) {
            return;
        }

        $this->flush();
        $this->isShutdown = true;
    }

    /**
     * @return list<MetricSnapshot>
     */
    public function buffer(): array
    {
        return $this->buffer;
    }
}

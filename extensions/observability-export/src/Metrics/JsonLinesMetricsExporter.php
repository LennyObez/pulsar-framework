<?php

declare(strict_types=1);

namespace Pulsar\Extension\ObservabilityExport\Metrics;

use function count;
use function fclose;
use function fflush;
use function flock;
use function fopen;
use function fwrite;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\ObservabilityExport\Schema\MetricSchema;
use Pulsar\Observability\Metrics\MetricSnapshot;
use RuntimeException;

use const LOCK_EX;
use const LOCK_UN;
use const PHP_EOL;

/**
 * Exports metric snapshots as JSON Lines to a file.
 *
 * Buffers snapshots in memory and flushes to disk when the threshold is reached.
 * Uses flock(LOCK_EX) for multi-process safety.
 */
#[Internal(reason: 'Implementation detail; depend on MetricsExporterInterface')]
final class JsonLinesMetricsExporter implements MetricsExporterInterface
{
    /** @var list<MetricSnapshot> */
    private array $buffer = [];

    private bool $isShutdown = false;

    public function __construct(
        private readonly string $filePath,
        private readonly int $flushThreshold = 10,
    ) {}

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
        $this->writeToFile($lines);
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

    private function writeToFile(string $data): void
    {
        $handle = fopen($this->filePath, 'a');

        if ($handle === false) {
            throw new RuntimeException("Failed to open file for writing: {$this->filePath}");
        }

        try {
            flock($handle, LOCK_EX);
            fwrite($handle, $data);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }
}

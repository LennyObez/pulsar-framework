<?php

declare(strict_types=1);

namespace Pulsar\Extension\ObservabilityExport\Error;

use function count;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\ObservabilityExport\Internal\JsonLinesFileWriter;
use Pulsar\Extension\ObservabilityExport\Schema\ErrorSchema;
use Pulsar\Observability\ErrorTracking\ErrorEvent;

use const PHP_EOL;

/**
 * Exports error events as JSON Lines to a file.
 *
 * Buffers events in memory and flushes to disk when the threshold is reached.
 * Uses flock(LOCK_EX) for multi-process safety.
 */
#[Internal(reason: 'Implementation detail; depend on ErrorExporterInterface')]
final class JsonLinesErrorExporter implements ErrorExporterInterface
{
    /** @var list<ErrorEvent> */
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
    public function export(ErrorEvent $event): void
    {
        if ($this->isShutdown) {
            return;
        }

        $this->buffer[] = $event;

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

        foreach ($this->buffer as $event) {
            $lines .= ErrorSchema::toJson($event) . PHP_EOL;
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
     * @return list<ErrorEvent>
     */
    public function buffer(): array
    {
        return $this->buffer;
    }
}

<?php

declare(strict_types=1);

namespace Pulsar\Extension\ObservabilityExport\Error;

use function count;
use function fclose;
use function fflush;
use function flock;
use function fopen;
use function fwrite;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\ObservabilityExport\Schema\ErrorSchema;
use Pulsar\Observability\ErrorTracking\ErrorEvent;
use RuntimeException;

use const LOCK_EX;
use const LOCK_UN;
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

    public function __construct(
        private readonly string $filePath,
        private readonly int $flushThreshold = 10,
    ) {}

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
     * @return list<ErrorEvent>
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

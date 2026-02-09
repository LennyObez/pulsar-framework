<?php

declare(strict_types=1);

namespace Pulsar\Extension\ObservabilityExport\Span;

use function count;
use function fclose;
use function fflush;
use function flock;
use function fopen;
use function fwrite;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\ObservabilityExport\Schema\SpanSchema;
use Pulsar\Observability\Tracing\Span;
use RuntimeException;

use const LOCK_EX;
use const LOCK_UN;
use const PHP_EOL;

/**
 * Exports spans as JSON Lines to a file.
 *
 * Buffers spans in memory and flushes to disk when the threshold is reached.
 * Uses flock(LOCK_EX) for multi-process safety.
 */
#[Internal(reason: 'Implementation detail; depend on SpanExporterInterface')]
final class JsonLinesSpanExporter implements SpanExporterInterface
{
    /** @var list<Span> */
    private array $buffer = [];

    private bool $isShutdown = false;

    public function __construct(
        private readonly string $filePath,
        private readonly int $flushThreshold = 10,
    ) {}

    #[Override]
    public function export(Span $span): void
    {
        if ($this->isShutdown) {
            return;
        }

        $this->buffer[] = $span;

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

        foreach ($this->buffer as $span) {
            $lines .= SpanSchema::toJson($span) . PHP_EOL;
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
     * @return list<Span>
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

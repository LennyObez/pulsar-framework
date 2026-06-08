<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Export\JsonLines;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Observability\Export\JsonLines\Schema\SpanSchema;
use Pulsar\Observability\Tracing\Span;

use function count;

use const PHP_EOL;

/**
 * Exports spans as JSON Lines to a file.
 */
#[Internal(reason: 'Implementation detail; depend on SpanExporterInterface')]
final class JsonLinesSpanExporter implements SpanExporterInterface
{
    /** @var list<Span> */
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
     * @return list<Span>
     */
    public function buffer(): array
    {
        return $this->buffer;
    }
}

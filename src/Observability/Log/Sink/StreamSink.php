<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log\Sink;

use Override;
use Pulsar\Observability\Log\Exception\LogException;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogFormatter;
use Pulsar\Observability\Log\LogSinkInterface;

/**
 * Log sink that writes JSON lines to a PHP stream (e.g. `php://stderr`).
 */
final class StreamSink implements LogSinkInterface
{
    private readonly LogFormatter $formatter;

    /**
     * @var resource
     */
    private mixed $stream;

    /**
     * @throws LogException If the stream cannot be opened.
     */
    public function __construct(
        string $streamUri,
        ?LogFormatter $formatter = null,
    ) {
        $this->formatter = $formatter ?? new LogFormatter();

        $stream = @fopen($streamUri, 'a');

        if ($stream === false) {
            throw LogException::invalidDriver($streamUri);
        }

        $this->stream = $stream;
    }

    #[Override]
    public function write(LogEntry $entry): void
    {
        $line = $this->formatter->format($entry);
        fwrite($this->stream, $line);
    }
}

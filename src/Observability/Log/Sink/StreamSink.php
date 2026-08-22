<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log\Sink;

use Override;
use Pulsar\Observability\Log\Exception\LogException;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogFormatter;
use Pulsar\Observability\Log\LogSinkInterface;

use function fclose;
use function fopen;
use function fwrite;
use function in_array;
use function is_resource;
use function strtolower;

/**
 * Log sink that writes JSON lines to a PHP stream (e.g. `php://stderr`).
 */
final class StreamSink implements LogSinkInterface
{
    /**
     * Standard process streams that must never be closed by this sink:
     * closing them would tear down the process's stdout/stderr/stdin for
     * every other consumer.
     *
     * @var list<string>
     */
    private const array STANDARD_STREAMS = [
        'php://stdout',
        'php://stderr',
        'php://stdin',
        'php://output',
        'php://input',
    ];

    private readonly LogFormatter $formatter;

    /**
     * @var resource
     */
    private mixed $stream;

    /**
     * Whether this sink opened (and therefore owns) the underlying stream and
     * may close it on destruction. Standard process streams are never owned.
     */
    private readonly bool $ownsStream;

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
        $this->ownsStream = !in_array(strtolower($streamUri), self::STANDARD_STREAMS, true);
    }

    #[Override]
    public function write(LogEntry $entry): void
    {
        $line = $this->formatter->format($entry);
        fwrite($this->stream, $line);
    }

    /**
     * Release the underlying file descriptor when the sink is destroyed.
     *
     * In persistent workers (FrankenPHP, RoadRunner) the container is a
     * singleton, so without an explicit close each `file://` or named-pipe
     * StreamSink would leak one descriptor for the whole process lifetime,
     * eventually exhausting the fd table. Standard process streams are left
     * open intentionally — closing them would break the host process.
     */
    public function __destruct()
    {
        if ($this->ownsStream && is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}

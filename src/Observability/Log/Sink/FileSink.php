<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log\Sink;

use function dirname;
use function is_dir;

use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogFormatter;
use Pulsar\Observability\Log\LogSinkInterface;

/**
 * Log sink that appends JSON lines to a file.
 *
 * Creates the directory if missing. Uses LOCK_EX for concurrent safety.
 */
final class FileSink implements LogSinkInterface
{
    private readonly LogFormatter $formatter;

    public function __construct(
        private readonly string $path,
        ?LogFormatter $formatter = null,
    ) {
        $this->formatter = $formatter ?? new LogFormatter();
    }

    public function write(LogEntry $entry): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        $line = $this->formatter->format($entry);
        file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }
}

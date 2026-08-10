<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log\Sink;

use Override;
use Pulsar\Observability\Log\Exception\LogException;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogFormatter;
use Pulsar\Observability\Log\LogSinkInterface;

use function chmod;
use function dirname;
use function file_exists;
use function is_dir;
use function mkdir;
use function sprintf;

/**
 * Log sink that appends JSON lines to a file.
 *
 * Creates the directory if missing. Uses LOCK_EX for concurrent safety.
 *
 * Log files routinely contain accidental PII / PHI / credentials leaked
 * through error context or through request bodies surfaced in stack traces,
 * so nothing here is ever world-readable: the directory is created `0o750`
 * (owner rwx, group rx, world none) and the file `0o640` (owner rw, group r,
 * world none). Operators wanting tighter `0o600` files can enforce that via
 * the host umask; tightening further here would break shared-group log
 * collection.
 */
final readonly class FileSink implements LogSinkInterface
{
    private const int DIRECTORY_MODE = 0o750;
    private const int FILE_MODE = 0o640;

    private LogFormatter $formatter;

    public function __construct(
        private string $path,
        ?LogFormatter $formatter = null,
    ) {
        $this->formatter = $formatter ?? new LogFormatter();
    }

    /**
     * @throws LogException When the directory cannot be created or
     *                      the entry cannot be appended.
     */
    #[Override]
    public function write(LogEntry $entry): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory)) {
            // An unwritable parent directory must not silently drop
            // every entry: throw so Logger::log() reaches its
            // last-resort fallback path.
            if (!@mkdir($directory, self::DIRECTORY_MODE, true) && !is_dir($directory)) {
                throw LogException::sinkWriteFailed(
                    self::class,
                    sprintf('could not create directory "%s"', $directory),
                );
            }
        }

        $isNewFile = !file_exists($this->path);

        $line = $this->formatter->format($entry);

        // file_put_contents returns false on any I/O failure
        // (disk full, permission denied, locked by another process
        // beyond our LOCK_EX wait, etc.). Surface the failure rather
        // than silently dropping the entry.
        $bytes = @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);

        if ($bytes === false) {
            throw LogException::sinkWriteFailed(
                self::class,
                sprintf('could not write to "%s"', $this->path),
            );
        }

        // Apply restrictive permissions on first write. Subsequent
        // writes inherit them; an operator that explicitly tightens
        // the mode further is left intact.
        if ($isNewFile) {
            @chmod($this->path, self::FILE_MODE);
        }
    }
}

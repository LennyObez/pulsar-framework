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
 * F4.5: log files routinely contain accidental PII / PHI / credentials
 * leaked through error context, request bodies surfaced in stack traces,
 * etc. The previous defaults (`0o775` directory, default umask file)
 * exposed those records to every system user. We now create the
 * directory `0o750` (owner rwx, group rx, world none) and the file
 * `0o640` (owner rw, group r, world none). Operators wanting tighter
 * `0o600` files can enforce that via the host umask; we cannot easily
 * downgrade further without breaking shared-group log collection.
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
            // F4.4: previously the mkdir return was ignored, so a
            // sink configured with an unwritable parent silently
            // dropped every entry. Throw so Logger::log can hit its
            // fallback path (F4.2).
            if (!@mkdir($directory, self::DIRECTORY_MODE, true) && !is_dir($directory)) {
                throw LogException::sinkWriteFailed(
                    self::class,
                    sprintf('could not create directory "%s"', $directory),
                );
            }
        }

        $isNewFile = !file_exists($this->path);

        $line = $this->formatter->format($entry);

        // F4.4: file_put_contents returns false on any I/O failure
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
